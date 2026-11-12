<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| STAFF APPOINTMENTS
|--------------------------------------------------------------------------
| One staff member (the organiser) asks one or more colleagues (invitees)
| for a time slot. Everyone involved has a row in
| staff_appointment_participants with their own response:
|   - Pending:  each invitee gets an in-app notification and an email, and
|     accepts or declines on their own.
|   - Accepted: once the organiser and at least one invitee have accepted the
|     appointment is confirmed. Everyone who accepted gets an .ics invite and
|     the slot is blocked for them: no new appointment may overlap it. Their
|     other pending requests that overlap are declined automatically.
|   - Declined: an invitee declines (or later withdraws). When every invitee
|     has declined, or the organiser declines a time someone else proposed,
|     the appointment is Declined.
|   - Cancelled: the organiser cancels it for everyone.
|   - Rescheduled: any participant proposes a new time (proposed_by). It goes
|     back to Pending and everyone else accepts or declines it again. Each
|     version of the time gets its own calendar UID (revision).
| Slots are also blocked by hub events a person is staffed on.
| Each participant picks their own reminder lead time;
| appt_dispatch_reminders() sends them from cron/task-reminders.php and
| lazily from the page.
|
| staff_appointments.invitee_id and the requester_/invitee_ reminder columns
| are from the one-to-one release; they are kept (invitee_id = first invitee)
| but the participants table is the source of truth.
|
| Times are stored as local DATETIME in PHP's default timezone, and "now" is
| always passed from PHP so PHP and MySQL clocks never disagree.
*/

require_once __DIR__ . '/event-staff.php'; // ev_ics_escape / ev_ics_param / ev_ics_fold

const APPT_MAX_HOURS = 12;
const APPT_MAX_INVITEES = 25;

if (!function_exists('appt_ensure_table')) {
    function appt_ensure_table(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            $ready = (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS staff_appointments (
                    appointment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    requester_id INT NOT NULL,
                    invitee_id INT NOT NULL,
                    proposed_by INT NULL,
                    revision INT UNSIGNED NOT NULL DEFAULT 0,
                    title VARCHAR(200) NOT NULL,
                    agenda TEXT NULL,
                    location VARCHAR(255) NULL,
                    start_at DATETIME NOT NULL,
                    end_at DATETIME NOT NULL,
                    status ENUM('Pending','Accepted','Declined','Cancelled') NOT NULL DEFAULT 'Pending',
                    response_note VARCHAR(500) NULL,
                    responded_at DATETIME NULL,
                    cancelled_by INT NULL,
                    requester_remind_minutes SMALLINT UNSIGNED NULL,
                    invitee_remind_minutes SMALLINT UNSIGNED NULL,
                    requester_remind_at DATETIME NULL,
                    invitee_remind_at DATETIME NULL,
                    requester_reminded_at DATETIME NULL,
                    invitee_reminded_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (appointment_id),
                    KEY idx_appt_requester (requester_id, status, start_at),
                    KEY idx_appt_invitee (invitee_id, status, start_at),
                    KEY idx_appt_status_start (status, start_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Columns added after the first release.
            foreach ([
                'proposed_by' => 'INT NULL AFTER invitee_id',
                'revision' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER proposed_by',
            ] as $column => $definition) {
                $check = $conn->query("SHOW COLUMNS FROM staff_appointments LIKE '" . $column . "'");
                if ($ready && $check && $check->num_rows === 0) {
                    $ready = (bool)$conn->query("ALTER TABLE staff_appointments ADD COLUMN $column $definition");
                }
            }

            $ready = $ready && (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS staff_appointment_participants (
                    appointment_id INT UNSIGNED NOT NULL,
                    user_id INT NOT NULL,
                    is_organiser TINYINT(1) NOT NULL DEFAULT 0,
                    response ENUM('Pending','Accepted','Declined') NOT NULL DEFAULT 'Pending',
                    response_note VARCHAR(500) NULL,
                    responded_at DATETIME NULL,
                    remind_minutes SMALLINT UNSIGNED NULL,
                    remind_at DATETIME NULL,
                    reminded_at DATETIME NULL,
                    PRIMARY KEY (appointment_id, user_id),
                    KEY idx_appt_part_user (user_id, response),
                    KEY idx_appt_part_remind (response, remind_at, reminded_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // One-to-one appointments from before the participants table: one row per side.
            $ready = $ready && (bool)$conn->query(appt_backfill_sql());
        } catch (Throwable $e) {
            error_log('staff_appointments tables unavailable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

/** Copies requester/invitee of appointments that have no participant rows yet (idempotent). */
if (!function_exists('appt_backfill_sql')) {
    function appt_backfill_sql(): string
    {
        return "
            INSERT IGNORE INTO staff_appointment_participants
                (appointment_id, user_id, is_organiser, response, responded_at, remind_minutes, remind_at, reminded_at)
            SELECT a.appointment_id,
                   IF(s.side = 0, a.requester_id, a.invitee_id),
                   IF(s.side = 0, 1, 0),
                   CASE
                       WHEN COALESCE(a.proposed_by, a.requester_id) = IF(s.side = 0, a.requester_id, a.invitee_id) THEN 'Accepted'
                       WHEN a.status = 'Accepted' THEN 'Accepted'
                       WHEN a.status = 'Declined' THEN 'Declined'
                       ELSE 'Pending'
                   END,
                   a.responded_at,
                   IF(s.side = 0, a.requester_remind_minutes, a.invitee_remind_minutes),
                   IF(s.side = 0, a.requester_remind_at, a.invitee_remind_at),
                   IF(s.side = 0, a.requester_reminded_at, a.invitee_reminded_at)
            FROM staff_appointments a
            CROSS JOIN (SELECT 0 AS side UNION ALL SELECT 1) s
            WHERE NOT EXISTS (
                SELECT 1 FROM staff_appointment_participants p WHERE p.appointment_id = a.appointment_id
            )
        ";
    }
}

/** Reminder choices: minutes before the start => label. 0 = no reminder. */
if (!function_exists('appt_reminder_options')) {
    function appt_reminder_options(): array
    {
        return [
            0 => 'No reminder',
            15 => '15 minutes before',
            30 => '30 minutes before',
            60 => '1 hour before',
            120 => '2 hours before',
            1440 => '1 day before',
        ];
    }
}

if (!function_exists('appt_staff_roles')) {
    function appt_staff_roles(): array
    {
        return defined('IMS_STAFF_ROLES') ? IMS_STAFF_ROLES : ['Administrator', 'Staff'];
    }
}

/** Active staff who can be invited, excluding $excludeUserId. */
if (!function_exists('appt_staff_options')) {
    function appt_staff_options(mysqli $conn, int $excludeUserId = 0): array
    {
        $roles = appt_staff_roles();
        $marks = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $conn->prepare(
            "SELECT user_id, full_name, role FROM users
             WHERE is_active = 1 AND user_id <> ? AND role IN ($marks)
             ORDER BY full_name"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i' . str_repeat('s', count($roles)), $excludeUserId, ...$roles);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}

/** The subset of $userIds that are active staff, as user_id => full_name. */
if (!function_exists('appt_active_staff')) {
    function appt_active_staff(mysqli $conn, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
        if (!$userIds) {
            return [];
        }
        $roles = appt_staff_roles();
        $stmt = $conn->prepare(
            'SELECT user_id, full_name FROM users
             WHERE is_active = 1 AND user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')
               AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param(str_repeat('i', count($userIds)) . str_repeat('s', count($roles)), ...$userIds, ...$roles);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_column($rows, 'full_name', 'user_id');
    }
}

if (!function_exists('appt_is_staff')) {
    function appt_is_staff(mysqli $conn, int $userId): bool
    {
        return isset(appt_active_staff($conn, [$userId])[$userId]);
    }
}

/** Participant rows (with name and email) for each appointment id: id => list. */
if (!function_exists('appt_participants_for')) {
    function appt_participants_for(mysqli $conn, array $appointmentIds): array
    {
        $appointmentIds = array_values(array_unique(array_map('intval', $appointmentIds)));
        if (!$appointmentIds) {
            return [];
        }
        $stmt = $conn->prepare(
            'SELECT p.*, u.full_name, u.email
             FROM staff_appointment_participants p
             JOIN users u ON u.user_id = p.user_id
             WHERE p.appointment_id IN (' . implode(',', array_fill(0, count($appointmentIds), '?')) . ')
             ORDER BY p.is_organiser DESC, u.full_name'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param(str_repeat('i', count($appointmentIds)), ...$appointmentIds);
        $stmt->execute();
        $map = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $row['user_id'] = (int)$row['user_id'];
            $row['is_organiser'] = (int)$row['is_organiser'];
            $map[(int)$row['appointment_id']][] = $row;
        }
        $stmt->close();

        return $map;
    }
}

/** One appointment with the organiser's name/email and its participants. */
if (!function_exists('appt_find')) {
    function appt_find(mysqli $conn, int $appointmentId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT a.*, r.full_name AS requester_name, r.email AS requester_email
             FROM staff_appointments a
             JOIN users r ON r.user_id = a.requester_id
             WHERE a.appointment_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if ($row) {
            $row['participants'] = appt_participants_for($conn, [$appointmentId])[$appointmentId] ?? [];
        }

        return $row;
    }
}

/** $userId's participant row in $appt, or null when they are not part of it. */
if (!function_exists('appt_participant')) {
    function appt_participant(array $appt, int $userId): ?array
    {
        foreach ($appt['participants'] ?? [] as $p) {
            if ((int)$p['user_id'] === $userId) {
                return $p;
            }
        }

        return null;
    }
}

/** Participants filtered by response (null = all), optionally leaving out $exceptUserId. */
if (!function_exists('appt_people')) {
    function appt_people(array $appt, ?array $responses = null, int $exceptUserId = 0): array
    {
        return array_values(array_filter(
            $appt['participants'] ?? [],
            static fn($p) => (int)$p['user_id'] !== $exceptUserId
                && ($responses === null || in_array($p['response'], $responses, true))
        ));
    }
}

/** "Ann, Ben and Cy" */
if (!function_exists('appt_name_list')) {
    function appt_name_list(array $people): string
    {
        $names = array_map(static fn($p) => (string)$p['full_name'], $people);
        if (count($names) <= 1) {
            return (string)($names[0] ?? '');
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }
}

/** ['name','email'] recipients for appt_email(). */
if (!function_exists('appt_recipients')) {
    function appt_recipients(array $people): array
    {
        return array_map(static fn($p) => ['name' => $p['full_name'], 'email' => $p['email']], $people);
    }
}

/** Appointment status implied by the participants' responses (Cancelled is never derived). */
if (!function_exists('appt_derive_status')) {
    function appt_derive_status(array $participants): string
    {
        $organiserResponse = 'Accepted';
        $invitees = [];
        foreach ($participants as $p) {
            if ((int)$p['is_organiser'] === 1) {
                $organiserResponse = (string)$p['response'];
            } else {
                $invitees[] = (string)$p['response'];
            }
        }

        if ($organiserResponse === 'Declined' || !$invitees || !array_diff($invitees, ['Declined'])) {
            return 'Declined';
        }
        if ($organiserResponse === 'Accepted' && in_array('Accepted', $invitees, true)) {
            return 'Accepted';
        }

        return 'Pending';
    }
}

/** Re-read participants and store the derived status. Returns the new status. */
if (!function_exists('appt_sync_status')) {
    function appt_sync_status(mysqli $conn, int $appointmentId, string $now): string
    {
        $participants = appt_participants_for($conn, [$appointmentId])[$appointmentId] ?? [];
        $status = appt_derive_status($participants);
        $stmt = $conn->prepare(
            "UPDATE staff_appointments
             SET responded_at = IF(status = ?, responded_at, ?), status = ?
             WHERE appointment_id = ? AND status IN ('Pending','Accepted')"
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        // responded_at first: MySQL applies SET assignments left to right.
        $stmt->bind_param('sssi', $status, $now, $status, $appointmentId);
        $stmt->execute();
        $stmt->close();

        return $status;
    }
}

/**
 * Appointments $userId takes part in that overlap [from, to), with their own
 * response as my_response / my_remind_minutes and the participants attached.
 */
if (!function_exists('appt_for_user')) {
    function appt_for_user(mysqli $conn, int $userId, string $from, string $to, array $statuses = ['Pending', 'Accepted'], bool $includeDeclinedByMe = false): array
    {
        $marks = implode(',', array_fill(0, count($statuses), '?'));
        $mine = $includeDeclinedByMe ? '' : " AND me.response <> 'Declined'";
        $stmt = $conn->prepare(
            "SELECT a.*, r.full_name AS requester_name,
                    me.response AS my_response, me.remind_minutes AS my_remind_minutes, me.is_organiser AS i_organise
             FROM staff_appointments a
             JOIN staff_appointment_participants me ON me.appointment_id = a.appointment_id AND me.user_id = ?
             JOIN users r ON r.user_id = a.requester_id
             WHERE a.start_at < ? AND a.end_at > ? AND a.status IN ($marks) $mine
             ORDER BY a.start_at"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss' . str_repeat('s', count($statuses)), $userId, $to, $from, ...$statuses);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return appt_attach_participants($conn, $rows);
    }
}

if (!function_exists('appt_attach_participants')) {
    function appt_attach_participants(mysqli $conn, array $rows): array
    {
        $map = appt_participants_for($conn, array_column($rows, 'appointment_id'));
        foreach ($rows as &$row) {
            $row['participants'] = $map[(int)$row['appointment_id']] ?? [];
        }
        unset($row);

        return $rows;
    }
}

/** Confirmed appointments of any of $userIds overlapping [start, end), with whose slot it is. */
if (!function_exists('appt_conflicts')) {
    function appt_conflicts(mysqli $conn, array $userIds, string $start, string $end, int $excludeId = 0): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $conn->prepare(
            "SELECT a.appointment_id, a.start_at, a.end_at, p.user_id, u.full_name
             FROM staff_appointments a
             JOIN staff_appointment_participants p ON p.appointment_id = a.appointment_id
             JOIN users u ON u.user_id = p.user_id
             WHERE a.status = 'Accepted' AND p.response = 'Accepted' AND a.appointment_id <> ?
               AND a.start_at < ? AND a.end_at > ?
               AND p.user_id IN ($marks)
             ORDER BY a.start_at"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss' . str_repeat('i', count($userIds)), $excludeId, $end, $start, ...$userIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}

/** Hub event days that any of $userIds is staffed on, overlapping [start, end). */
if (!function_exists('appt_hub_event_busy')) {
    function appt_hub_event_busy(mysqli $conn, array $userIds, string $start, string $end): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds || !ev_staff_ensure_table($conn)) {
            return [];
        }

        // event_date is the first day; multi-day events list the rest in event_days.
        $from = date('Y-m-d', strtotime($start) - 31 * 86400);
        $to = substr($end, 0, 10);
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        $busy = [];

        try {
            $stmt = $conn->prepare(
                "SELECT s.user_id, u.full_name, e.event_id, e.event_title, e.event_date, e.event_days, e.start_time, e.end_time
                 FROM hub_event_staff s
                 JOIN hub_events e ON e.event_id = s.event_id
                 LEFT JOIN users u ON u.user_id = s.user_id
                 WHERE s.user_id IN ($marks) AND e.event_status <> 'Cancelled'
                   AND e.event_date BETWEEN ? AND ?"
            );
            if (!$stmt) {
                return [];
            }
            $params = [...$userIds, $from, $to];
            $stmt->bind_param(str_repeat('i', count($userIds)) . 'ss', ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Throwable $e) {
            error_log('appt_hub_event_busy: ' . $e->getMessage());
            return [];
        }

        foreach ($rows as $row) {
            foreach (ev_event_days($row) as $day) {
                $s = $day . ' ' . substr((string)$row['start_time'], 0, 8);
                $e = $day . ' ' . substr((string)$row['end_time'], 0, 8);
                if ($e <= $s) {
                    $e = date('Y-m-d H:i:s', strtotime($s) + 3600);
                }
                if ($s < $end && $e > $start) {
                    $busy[] = [
                        'user_id' => (int)$row['user_id'],
                        'full_name' => (string)($row['full_name'] ?? ''),
                        'title' => (string)$row['event_title'],
                        'start_at' => $s,
                        'end_at' => $e,
                    ];
                }
            }
        }
        usort($busy, static fn($a, $b) => strcmp($a['start_at'], $b['start_at']));

        return $busy;
    }
}

/** Why [start, end) is not free for these users, or null when it is. */
if (!function_exists('appt_slot_problem')) {
    function appt_slot_problem(mysqli $conn, array $userIds, string $start, string $end, int $excludeId, int $viewerId): ?string
    {
        $conflicts = appt_conflicts($conn, $userIds, $start, $end, $excludeId);
        if ($conflicts) {
            return appt_conflict_message($conflicts, $viewerId);
        }

        $events = appt_hub_event_busy($conn, $userIds, $start, $end);
        if ($events) {
            $e = $events[0];
            $who = $e['user_id'] === $viewerId ? 'You are' : (($e['full_name'] !== '' ? $e['full_name'] : 'A participant') . ' is');
            return $who . ' staffing the event "' . $e['title'] . '" ' . appt_format_range($e['start_at'], $e['end_at']) . '. Pick another time.';
        }

        return null;
    }
}

/** Human-readable conflict text, from $viewerId's point of view. */
if (!function_exists('appt_conflict_message')) {
    function appt_conflict_message(array $conflicts, int $viewerId): string
    {
        $c = $conflicts[0];
        $who = (int)$c['user_id'] === $viewerId ? 'You already have' : ((string)$c['full_name'] . ' already has');

        return $who . ' an appointment ' . appt_format_range((string)$c['start_at'], (string)$c['end_at']) . '. Pick another time.';
    }
}

/** Validation message for a proposed time, or null when it is acceptable. */
if (!function_exists('appt_time_problem')) {
    function appt_time_problem(?string $start, ?string $end): ?string
    {
        if ($start === null || $end === null) {
            return 'Enter a valid start and end time.';
        }
        if ($start <= date('Y-m-d H:i:s')) {
            return 'The appointment must start in the future.';
        }
        if ($end <= $start) {
            return 'The end time must be after the start time.';
        }
        if (strtotime($end) - strtotime($start) > APPT_MAX_HOURS * 3600) {
            return 'An appointment can last at most ' . APPT_MAX_HOURS . ' hours.';
        }

        return null;
    }
}

/** Who set the current time: the organiser unless someone proposed another. */
if (!function_exists('appt_proposer_id')) {
    function appt_proposer_id(array $appt): int
    {
        return (int)($appt['proposed_by'] ?? 0) ?: (int)$appt['requester_id'];
    }
}

/** Serialise booking for these users: row locks held until commit/rollback. */
if (!function_exists('appt_lock_users')) {
    function appt_lock_users(mysqli $conn, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) {
            return;
        }
        sort($userIds);
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id IN ($marks) ORDER BY user_id FOR UPDATE");
        if (!$stmt) {
            throw new RuntimeException('Could not lock calendars.');
        }
        $stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
        $stmt->execute();
        $stmt->get_result()->free();
        $stmt->close();
    }
}

if (!function_exists('appt_format_range')) {
    function appt_format_range(string $start, string $end): string
    {
        $s = strtotime($start);
        $e = strtotime($end);
        if (!$s || !$e) {
            return $start;
        }

        return date('D j M Y, H:i', $s) . ' – ' . (date('Y-m-d', $s) === date('Y-m-d', $e) ? date('H:i', $e) : date('D j M Y, H:i', $e));
    }
}

if (!function_exists('appt_remind_at')) {
    function appt_remind_at(string $start, int $minutes): ?string
    {
        return $minutes > 0 ? date('Y-m-d H:i:s', strtotime($start) - $minutes * 60) : null;
    }
}

/** Parse a datetime-local value (or "Y-m-d H:i") into "Y-m-d H:i:s", or null. */
if (!function_exists('appt_parse_datetime')) {
    function appt_parse_datetime(string $value): ?string
    {
        $value = str_replace('T', ' ', trim($value));
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($dt && $dt->format($format) === $value) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}

if (!function_exists('appt_notify')) {
    function appt_notify(array $userIds, string $title, string $message, string $type, int $appointmentId): void
    {
        if (!function_exists('notify_user')) {
            return;
        }
        foreach (array_unique(array_map('intval', $userIds)) as $id) {
            notify_user($id, $title, $message, $type, $appointmentId, 'appointment');
        }
    }
}

/**
 * $userIds have just become busy for [start, end) because of $appointmentId.
 * Decline their response to every other overlapping appointment that is not
 * already confirmed for them. Returns the affected appointment ids.
 * Call inside the transaction, with the users locked.
 */
if (!function_exists('appt_release_overlaps')) {
    function appt_release_overlaps(mysqli $conn, array $userIds, int $appointmentId, string $start, string $end, string $now): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $conn->prepare(
            "SELECT p.appointment_id, p.user_id
             FROM staff_appointment_participants p
             JOIN staff_appointments a ON a.appointment_id = p.appointment_id
             WHERE p.user_id IN ($marks) AND a.appointment_id <> ?
               AND a.status IN ('Pending','Accepted') AND a.start_at < ? AND a.end_at > ?
               AND p.response <> 'Declined'
               AND NOT (a.status = 'Accepted' AND p.response = 'Accepted')"
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $params = [...$userIds, $appointmentId, $end, $start];
        $stmt->bind_param(str_repeat('i', count($userIds)) . 'iss', ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!$rows) {
            return [];
        }

        $note = 'Time slot no longer available.';
        $decline = $conn->prepare(
            "UPDATE staff_appointment_participants
             SET response = 'Declined', response_note = ?, responded_at = ?
             WHERE appointment_id = ? AND user_id = ?"
        );
        if (!$decline) {
            throw new RuntimeException($conn->error);
        }
        $affected = [];
        foreach ($rows as $row) {
            $otherId = (int)$row['appointment_id'];
            $userId = (int)$row['user_id'];
            $decline->bind_param('ssii', $note, $now, $otherId, $userId);
            $decline->execute();
            $affected[$otherId][] = $userId;
        }
        $decline->close();

        foreach (array_keys($affected) as $otherId) {
            appt_sync_status($conn, $otherId, $now);
        }

        return $affected;
    }
}

/** Notify the people affected by appt_release_overlaps() (after commit). */
if (!function_exists('appt_notify_released')) {
    function appt_notify_released(mysqli $conn, array $affected): void
    {
        foreach ($affected as $otherId => $userIds) {
            $other = appt_find($conn, (int)$otherId);
            if (!$other) {
                continue;
            }
            $what = $other['title'] . ' · ' . appt_format_range((string)$other['start_at'], (string)$other['end_at']);
            $names = appt_name_list(array_values(array_filter($other['participants'], static fn($p) => in_array((int)$p['user_id'], $userIds, true))));
            $audience = array_map(static fn($p) => (int)$p['user_id'], appt_people($other, ['Pending', 'Accepted']));

            if ($other['status'] === 'Declined') {
                appt_notify($audience, 'Appointment declined', $what . ' will not go ahead: the time slot is no longer available for ' . $names . '.', 'warning', (int)$otherId);
            } else {
                appt_notify($audience, 'Participant unavailable', $names . ' can no longer attend ' . $what . ': the time slot is no longer available.', 'warning', (int)$otherId);
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ACTIONS: each returns ['ok' => bool, 'message' => string]
|--------------------------------------------------------------------------
*/

if (!function_exists('appt_create')) {
    function appt_create(mysqli $conn, int $requesterId, array $input): array
    {
        $requested = $input['invitee_ids'] ?? ($input['invitee_id'] ?? []);
        $requested = array_values(array_unique(array_filter(
            array_map('intval', is_array($requested) ? $requested : [$requested]),
            static fn($id) => $id > 0 && $id !== $requesterId
        )));
        $title = trim((string)($input['title'] ?? ''));
        $agenda = trim((string)($input['agenda'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $start = appt_parse_datetime((string)($input['start_at'] ?? ''));
        $end = appt_parse_datetime((string)($input['end_at'] ?? ''));
        $remind = (int)($input['remind_minutes'] ?? 0);

        if (!$requested) {
            return ['ok' => false, 'message' => 'Choose at least one staff member to meet with.'];
        }
        if (count($requested) > APPT_MAX_INVITEES) {
            return ['ok' => false, 'message' => 'You can invite at most ' . APPT_MAX_INVITEES . ' people to one appointment.'];
        }
        $staff = appt_active_staff($conn, $requested);
        if (count($staff) !== count($requested)) {
            return ['ok' => false, 'message' => 'One of the people you chose is no longer an active staff member. Refresh and try again.'];
        }
        if ($title === '' || mb_strlen($title) > 200) {
            return ['ok' => false, 'message' => 'Give the appointment a subject (up to 200 characters).'];
        }
        if (mb_strlen($location) > 255) {
            return ['ok' => false, 'message' => 'The location is too long (up to 255 characters).'];
        }
        if ($problem = appt_time_problem($start, $end)) {
            return ['ok' => false, 'message' => $problem];
        }
        if (!array_key_exists($remind, appt_reminder_options())) {
            $remind = 0;
        }

        $remindAt = appt_remind_at($start, $remind);
        $agendaValue = $agenda !== '' ? $agenda : null;
        $locationValue = $location !== '' ? $location : null;
        $everyone = [$requesterId, ...$requested];

        $conn->begin_transaction();
        try {
            appt_lock_users($conn, $everyone);

            if ($problem = appt_slot_problem($conn, $everyone, $start, $end, 0, $requesterId)) {
                $conn->rollback();
                return ['ok' => false, 'message' => $problem];
            }

            // invitee_id keeps the first invitee for the one-to-one columns.
            $firstInvitee = $requested[0];
            $stmt = $conn->prepare(
                'INSERT INTO staff_appointments
                    (requester_id, invitee_id, proposed_by, title, agenda, location, start_at, end_at,
                     requester_remind_minutes, requester_remind_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('iiisssssis', $requesterId, $firstInvitee, $requesterId, $title, $agendaValue, $locationValue, $start, $end, $remind, $remindAt);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();

            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare(
                "INSERT INTO staff_appointment_participants
                    (appointment_id, user_id, is_organiser, response, responded_at, remind_minutes, remind_at)
                 VALUES (?, ?, 1, 'Accepted', ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('iisis', $id, $requesterId, $now, $remind, $remindAt);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO staff_appointment_participants (appointment_id, user_id, is_organiser, response)
                 VALUES (?, ?, 0, 'Pending')"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            foreach ($requested as $inviteeId) {
                $stmt->bind_param('ii', $id, $inviteeId);
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save the appointment. Please try again.'];
        }

        $appt = appt_find($conn, $id);
        if ($appt) {
            $invitees = appt_people($appt, null, $requesterId);
            $when = appt_format_range($start, $end);
            $group = count($invitees) > 1 ? ' (with ' . count($invitees) . ' invitees)' : '';
            appt_notify(array_column($invitees, 'user_id'), 'Appointment request', $appt['requester_name'] . ' asked to meet' . $group . ': ' . $title . ' · ' . $when, 'info', $id);
            appt_email(
                appt_recipients($invitees),
                'Appointment request: ' . $title,
                '<p>' . appt_h($appt['requester_name']) . ' would like to meet with you.</p>'
                    . appt_details_html($appt)
                    . '<p>Open <strong>Appointments</strong> in the IMS to accept or decline.</p>'
            );
        }

        $who = count($requested) === 1 ? reset($staff) : count($requested) . ' people';

        return ['ok' => true, 'message' => 'Appointment request sent to ' . $who . '. You will be notified as they respond.'];
    }
}

if (!function_exists('appt_accept')) {
    function appt_accept(mysqli $conn, int $userId, int $appointmentId, int $remind): array
    {
        if (!array_key_exists($remind, appt_reminder_options())) {
            $remind = 0;
        }

        $appt = appt_find($conn, $appointmentId);
        if (!$appt || appt_participant($appt, $userId) === null) {
            return ['ok' => false, 'message' => 'Appointment not found.'];
        }

        $released = [];
        $newlyBusy = [];
        $wasAccepted = false;
        $now = date('Y-m-d H:i:s');
        $conn->begin_transaction();
        try {
            appt_lock_users($conn, array_column($appt['participants'], 'user_id'));

            // Re-read under the lock: it may have been cancelled or rescheduled meanwhile.
            $appt = appt_find($conn, $appointmentId);
            $me = $appt ? appt_participant($appt, $userId) : null;
            if (!$appt || !$me || $me['response'] !== 'Pending' || !in_array($appt['status'], ['Pending', 'Accepted'], true)) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This request is no longer waiting for your response.'];
            }
            if ((string)$appt['start_at'] <= $now) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This appointment time has already passed.'];
            }

            // Who becomes busy: just me if it is already confirmed; everyone who has
            // accepted (me included) if my answer confirms it; nobody yet otherwise.
            $wasAccepted = $appt['status'] === 'Accepted';
            $after = array_map(static function ($p) use ($userId) {
                if ((int)$p['user_id'] === $userId) {
                    $p['response'] = 'Accepted';
                }
                return $p;
            }, $appt['participants']);
            if ($wasAccepted) {
                $newlyBusy = [$userId];
            } elseif (appt_derive_status($after) === 'Accepted') {
                $newlyBusy = array_map(static fn($p) => (int)$p['user_id'], array_filter($after, static fn($p) => $p['response'] === 'Accepted'));
            }

            $problem = appt_slot_problem($conn, $newlyBusy ?: [$userId], (string)$appt['start_at'], (string)$appt['end_at'], $appointmentId, $userId);
            if ($problem) {
                $conn->rollback();
                return ['ok' => false, 'message' => $problem . ' Decline this request or suggest another time.'];
            }

            $remindAt = appt_remind_at((string)$appt['start_at'], $remind);
            $stmt = $conn->prepare(
                "UPDATE staff_appointment_participants
                 SET response = 'Accepted', responded_at = ?, response_note = NULL,
                     remind_minutes = ?, remind_at = ?, reminded_at = NULL
                 WHERE appointment_id = ? AND user_id = ? AND response = 'Pending'"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('sisii', $now, $remind, $remindAt, $appointmentId, $userId);
            $stmt->execute();
            $stmt->close();

            appt_sync_status($conn, $appointmentId, $now);

            // The slot is now blocked for them: release their other overlapping requests.
            $released = appt_release_overlaps($conn, $newlyBusy, $appointmentId, (string)$appt['start_at'], (string)$appt['end_at'], $now);

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_accept: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not accept the appointment. Please try again.'];
        }

        $appt = appt_find($conn, $appointmentId) ?? $appt;
        $me = appt_participant($appt, $userId);
        $when = appt_format_range((string)$appt['start_at'], (string)$appt['end_at']);

        // Tell whoever set this time, and the organiser.
        $tell = array_diff([appt_proposer_id($appt), (int)$appt['requester_id']], [$userId]);
        appt_notify($tell, 'Appointment accepted', $me['full_name'] . ' accepted: ' . $appt['title'] . ' · ' . $when, 'success', $appointmentId);

        if ($appt['status'] === 'Accepted') {
            // Calendar invites to those who have just become busy.
            $people = array_values(array_filter($appt['participants'], static fn($p) => in_array((int)$p['user_id'], $newlyBusy, true)));
            appt_email_invite($appt, 'REQUEST', $people);
            if (!$wasAccepted) {
                $others = array_column(appt_people($appt, ['Accepted'], $userId), 'user_id');
                appt_notify($others, 'Appointment confirmed', $appt['title'] . ' · ' . $when . ' is confirmed.', 'success', $appointmentId);
            }
        }
        appt_notify_released($conn, $released);

        return ['ok' => true, 'message' => $appt['status'] === 'Accepted'
            ? 'Appointment accepted and added to your calendar.'
            : 'Accepted. It will be confirmed once the organiser agrees to the time.'];
    }
}

if (!function_exists('appt_decline')) {
    function appt_decline(mysqli $conn, int $userId, int $appointmentId, string $note): array
    {
        $appt = appt_find($conn, $appointmentId);
        $me = $appt ? appt_participant($appt, $userId) : null;
        if (!$appt || !$me || $me['response'] !== 'Pending' || !in_array($appt['status'], ['Pending', 'Accepted'], true)) {
            return ['ok' => false, 'message' => 'This request is no longer waiting for your response.'];
        }

        $note = mb_substr(trim($note), 0, 500);
        $noteValue = $note !== '' ? $note : null;
        $now = date('Y-m-d H:i:s');
        $revision = (int)$appt['revision'];

        $conn->begin_transaction();
        try {
            // Guard on the revision too, so a new time that lands meanwhile is not declined unseen.
            $stmt = $conn->prepare(
                "UPDATE staff_appointment_participants p
                 JOIN staff_appointments a ON a.appointment_id = p.appointment_id
                 SET p.response = 'Declined', p.response_note = ?, p.responded_at = ?, p.remind_at = NULL
                 WHERE p.appointment_id = ? AND p.user_id = ? AND p.response = 'Pending'
                   AND a.revision = ? AND a.status IN ('Pending','Accepted')"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('ssiii', $noteValue, $now, $appointmentId, $userId, $revision);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed !== 1) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This request has already changed. Refresh and try again.'];
            }
            $status = appt_sync_status($conn, $appointmentId, $now);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_decline: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not decline the appointment.'];
        }

        $appt = appt_find($conn, $appointmentId) ?? $appt;
        $what = $appt['title'] . ' · ' . appt_format_range((string)$appt['start_at'], (string)$appt['end_at']);
        $reason = $note !== '' ? "\nReason: " . $note : '';
        $tell = array_diff([appt_proposer_id($appt), (int)$appt['requester_id']], [$userId]);
        appt_notify($tell, 'Appointment declined', $me['full_name'] . ' declined: ' . $what . $reason, 'warning', $appointmentId);

        if ($status === 'Declined') {
            $rest = array_diff(array_column(appt_people($appt, ['Pending', 'Accepted'], $userId), 'user_id'), $tell);
            appt_notify($rest, 'Appointment declined', $what . ' will not go ahead.' . $reason, 'warning', $appointmentId);
        }

        return ['ok' => true, 'message' => 'Appointment declined. The organiser has been notified.'];
    }
}

/**
 * Any participant who has not declined proposes a new time for a pending or
 * accepted appointment. Everyone else must accept it again; a confirmed slot
 * is released (and removed from calendars) until they do.
 */
if (!function_exists('appt_reschedule')) {
    function appt_reschedule(mysqli $conn, int $userId, int $appointmentId, array $input): array
    {
        $start = appt_parse_datetime((string)($input['start_at'] ?? ''));
        $end = appt_parse_datetime((string)($input['end_at'] ?? ''));
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
        $noteValue = $note !== '' ? $note : null;

        if ($problem = appt_time_problem($start, $end)) {
            return ['ok' => false, 'message' => $problem];
        }

        $appt = appt_find($conn, $appointmentId);
        if (!$appt || appt_participant($appt, $userId) === null) {
            return ['ok' => false, 'message' => 'Appointment not found.'];
        }

        $now = date('Y-m-d H:i:s');
        $conn->begin_transaction();
        try {
            appt_lock_users($conn, array_column($appt['participants'], 'user_id'));

            $appt = appt_find($conn, $appointmentId);
            $me = $appt ? appt_participant($appt, $userId) : null;
            if (!$appt || !$me || !in_array($appt['status'], ['Pending', 'Accepted'], true)) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Only pending or accepted appointments can be rescheduled.'];
            }
            if ($me['response'] === 'Declined' && !$me['is_organiser']) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'You have declined this appointment.'];
            }
            if ((string)$appt['end_at'] <= $now) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This appointment has already taken place.'];
            }
            if ($start === (string)$appt['start_at'] && $end === (string)$appt['end_at']) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Choose a different time from the current one.'];
            }

            $stillIn = array_map(static fn($p) => (int)$p['user_id'], appt_people($appt, ['Pending', 'Accepted']));
            if ($problem = appt_slot_problem($conn, [$userId, ...$stillIn], $start, $end, $appointmentId, $userId)) {
                $conn->rollback();
                return ['ok' => false, 'message' => $problem];
            }

            $status = (string)$appt['status'];
            $revision = (int)$appt['revision'];
            $stmt = $conn->prepare(
                "UPDATE staff_appointments
                 SET start_at = ?, end_at = ?, status = 'Pending', proposed_by = ?, revision = revision + 1,
                     response_note = ?, responded_at = NULL
                 WHERE appointment_id = ? AND status = ? AND revision = ?"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('ssisisi', $start, $end, $userId, $noteValue, $appointmentId, $status, $revision);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed !== 1) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This appointment has already changed. Refresh and try again.'];
            }

            // Everyone else answers again; the proposer's reminder follows the new time.
            $stmt = $conn->prepare(
                "UPDATE staff_appointment_participants
                 SET response = 'Pending', response_note = NULL, responded_at = NULL, remind_at = NULL, reminded_at = NULL
                 WHERE appointment_id = ? AND user_id <> ?"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('ii', $appointmentId, $userId);
            $stmt->execute();
            $stmt->close();

            $remindAt = appt_remind_at($start, (int)$me['remind_minutes']);
            $stmt = $conn->prepare(
                "UPDATE staff_appointment_participants
                 SET response = 'Accepted', responded_at = ?, response_note = NULL, remind_at = ?, reminded_at = NULL
                 WHERE appointment_id = ? AND user_id = ?"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('ssii', $now, $remindAt, $appointmentId, $userId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_reschedule: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not reschedule the appointment. Please try again.'];
        }

        // Remove the old confirmed time from calendars; acceptance sends the new one.
        if ($appt['status'] === 'Accepted') {
            appt_email_invite($appt, 'CANCEL', appt_people($appt, ['Accepted']));
        }

        $fresh = appt_find($conn, $appointmentId) ?? $appt;
        $others = appt_people($fresh, null, $userId);
        appt_notify(
            array_column($others, 'user_id'),
            'New time proposed',
            $me['full_name'] . ' proposed a new time for ' . $appt['title'] . ': ' . appt_format_range($start, $end) . ($note !== '' ? "\nNote: " . $note : ''),
            'info',
            $appointmentId
        );
        appt_email(
            appt_recipients($others),
            'New time proposed: ' . $appt['title'],
            '<p>' . appt_h($me['full_name']) . ' proposed a new time for your appointment.</p>'
                . appt_details_html($fresh)
                . ($note !== '' ? '<p><strong>Note:</strong> ' . nl2br(appt_h($note)) . '</p>' : '')
                . '<p>Open <strong>Appointments</strong> in the IMS to accept or decline.</p>'
        );

        return ['ok' => true, 'message' => 'New time sent to ' . appt_name_list($others) . ' for confirmation.'];
    }
}

/**
 * The organiser cancels a pending or accepted appointment for everyone.
 * An invitee who has accepted withdraws only themselves; when nobody else is
 * still invited, the appointment is cancelled.
 */
if (!function_exists('appt_cancel')) {
    function appt_cancel(mysqli $conn, int $userId, int $appointmentId, string $note): array
    {
        $appt = appt_find($conn, $appointmentId);
        $me = $appt ? appt_participant($appt, $userId) : null;
        if (!$appt || !$me || !in_array($appt['status'], ['Pending', 'Accepted'], true)) {
            return ['ok' => false, 'message' => 'This appointment cannot be cancelled.'];
        }

        $isOrganiser = (int)$me['is_organiser'] === 1;
        if (!$isOrganiser && $me['response'] !== 'Accepted') {
            return ['ok' => false, 'message' => 'Decline the request instead.'];
        }

        $note = mb_substr(trim($note), 0, 500);
        $noteValue = $note !== '' ? $note : null;
        $reason = $note !== '' ? "\nReason: " . $note : '';
        $status = (string)$appt['status'];
        $wasAccepted = $status === 'Accepted';
        $what = $appt['title'] . ' · ' . appt_format_range((string)$appt['start_at'], (string)$appt['end_at']);
        $now = date('Y-m-d H:i:s');

        // Invitees still in apart from me: if none, withdrawing ends the appointment.
        $remaining = array_filter(appt_people($appt, ['Pending', 'Accepted'], $userId), static fn($p) => !(int)$p['is_organiser']);
        $cancelAll = $isOrganiser || !$remaining;

        $conn->begin_transaction();
        try {
            if ($cancelAll) {
                $stmt = $conn->prepare(
                    "UPDATE staff_appointments SET status = 'Cancelled', cancelled_by = ?, response_note = ?
                     WHERE appointment_id = ? AND status = ?"
                );
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }
                $stmt->bind_param('isis', $userId, $noteValue, $appointmentId, $status);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE staff_appointment_participants
                     SET response = 'Declined', response_note = ?, responded_at = ?, remind_at = NULL
                     WHERE appointment_id = ? AND user_id = ? AND response = 'Accepted'"
                );
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }
                $stmt->bind_param('ssii', $noteValue, $now, $appointmentId, $userId);
            }
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed !== 1) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This appointment has already changed. Refresh and try again.'];
            }
            if (!$cancelAll) {
                appt_sync_status($conn, $appointmentId, $now);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_cancel: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not cancel the appointment.'];
        }

        if ($cancelAll) {
            appt_notify(array_column(appt_people($appt, ['Pending', 'Accepted'], $userId), 'user_id'), 'Appointment cancelled', $me['full_name'] . ' cancelled: ' . $what . $reason, 'warning', $appointmentId);
            if ($wasAccepted) {
                appt_email_invite($appt, 'CANCEL', appt_people($appt, ['Accepted']));
            }

            return ['ok' => true, 'message' => $wasAccepted ? 'Appointment cancelled and removed from calendars.' : 'Request withdrawn.'];
        }

        $fresh = appt_find($conn, $appointmentId) ?? $appt;
        appt_notify(array_column(appt_people($fresh, ['Pending', 'Accepted']), 'user_id'), 'Participant withdrew', $me['full_name'] . ' can no longer attend ' . $what . $reason, 'warning', $appointmentId);
        if ($wasAccepted) {
            appt_email_invite($appt, 'CANCEL', [$me]);
        }

        return ['ok' => true, 'message' => 'You have withdrawn from this appointment. The organiser has been notified.'];
    }
}

/*
|--------------------------------------------------------------------------
| REMINDERS
|--------------------------------------------------------------------------
*/

if (!function_exists('appt_dispatch_reminders')) {
    /** Send each due reminder once. Safe to run from cron and lazily from pages. */
    function appt_dispatch_reminders(mysqli $conn, int $limit = 300): int
    {
        if (!appt_ensure_table($conn)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $limit = max(1, min(1000, $limit));
        $stmt = $conn->prepare(
            "SELECT p.appointment_id, p.user_id
             FROM staff_appointment_participants p
             JOIN staff_appointments a ON a.appointment_id = p.appointment_id
             WHERE a.status = 'Accepted' AND p.response = 'Accepted' AND a.start_at > ?
               AND p.remind_at <= ? AND p.reminded_at IS NULL
             ORDER BY a.start_at LIMIT " . $limit
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('ss', $now, $now);
        $stmt->execute();
        $due = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $sent = 0;
        foreach ($due as $row) {
            $id = (int)$row['appointment_id'];
            $userId = (int)$row['user_id'];

            // Claim first so overlapping runs never send twice.
            $claim = $conn->prepare(
                "UPDATE staff_appointment_participants p
                 JOIN staff_appointments a ON a.appointment_id = p.appointment_id
                 SET p.reminded_at = ?
                 WHERE p.appointment_id = ? AND p.user_id = ? AND p.reminded_at IS NULL
                   AND p.response = 'Accepted' AND p.remind_at <= ?
                   AND a.status = 'Accepted' AND a.start_at > ?"
            );
            if (!$claim) {
                continue;
            }
            $claim->bind_param('siiss', $now, $id, $userId, $now, $now);
            $claim->execute();
            $claimed = $claim->affected_rows === 1;
            $claim->close();

            if (!$claimed || !($appt = appt_find($conn, $id)) || !($me = appt_participant($appt, $userId))) {
                continue;
            }

            $with = appt_name_list(appt_people($appt, ['Accepted'], $userId));
            $when = appt_format_range((string)$appt['start_at'], (string)$appt['end_at']);
            appt_notify([$userId], 'Appointment reminder', $appt['title'] . ($with !== '' ? ' with ' . $with : '') . ' · ' . $when, 'warning', $id);
            appt_email(
                appt_recipients([$me]),
                'Reminder: ' . $appt['title'] . ' (' . date('D j M, H:i', strtotime((string)$appt['start_at'])) . ')',
                '<p>This is a reminder of your appointment' . ($with !== '' ? ' with ' . appt_h($with) : '') . '.</p>' . appt_details_html($appt)
            );
            $sent++;
        }

        return $sent;
    }
}

/*
|--------------------------------------------------------------------------
| EMAIL + CALENDAR INVITES
|--------------------------------------------------------------------------
*/

if (!function_exists('appt_h')) {
    function appt_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('appt_details_html')) {
    function appt_details_html(array $appt): string
    {
        $people = '';
        foreach ($appt['participants'] ?? [] as $p) {
            $tag = (int)$p['is_organiser'] ? 'organiser' : strtolower((string)$p['response']);
            $people .= ($people !== '' ? '<br>' : '') . appt_h($p['full_name']) . ' <span style="color:#6b7280">(' . appt_h($tag) . ')</span>';
        }

        return '<table cellpadding="6" style="border-collapse:collapse">'
            . '<tr><td><strong>Subject</strong></td><td>' . appt_h($appt['title']) . '</td></tr>'
            . '<tr><td><strong>When</strong></td><td>' . appt_h(appt_format_range((string)$appt['start_at'], (string)$appt['end_at'])) . ' (' . appt_h(date_default_timezone_get()) . ')</td></tr>'
            . '<tr><td style="vertical-align:top"><strong>Who</strong></td><td>' . $people . '</td></tr>'
            . (!empty($appt['location']) ? '<tr><td><strong>Where</strong></td><td>' . appt_h($appt['location']) . '</td></tr>' : '')
            . '</table>'
            . (!empty($appt['agenda']) ? '<p>' . nl2br(appt_h($appt['agenda'])) . '</p>' : '');
    }
}

/** Email each recipient (['name','email']); optional attachment file paths. Returns emails sent. */
if (!function_exists('appt_email')) {
    function appt_email(array $recipients, string $subject, string $content, array $attachments = []): int
    {
        require_once __DIR__ . '/mail-function.php';
        if (!function_exists('sendEmail')) {
            return 0;
        }

        $sent = 0;
        foreach ($recipients as $person) {
            $email = trim((string)($person['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $html = '<p>Hello ' . appt_h($person['name'] ?? '') . ',</p>' . $content;
            $body = function_exists('email_wrapper') ? email_wrapper($html) : $html;
            try {
                $result = sendEmail($email, $subject, $body, trim(strip_tags(str_replace(['</p>', '</tr>', '<br>'], "\n", $html))), [], [], $attachments);
            } catch (Throwable $e) {
                $result = $e->getMessage();
            }
            if ($result === true) {
                $sent++;
            } else {
                error_log('Appointment email to ' . $email . ' failed: ' . (is_string($result) ? $result : 'unknown error'));
            }
        }

        return $sent;
    }
}

if (!function_exists('appt_build_ics')) {
    function appt_build_ics(array $appt, string $method, string $organizerEmail, string $organizerName, int $alarmMinutes = 0): string
    {
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'ims.local')) ?: 'ims.local';
        $cancel = $method === 'CANCEL';
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone(date_default_timezone_get());
        $start = (new DateTimeImmutable((string)$appt['start_at'], $local))->setTimezone($utc);
        $end = (new DateTimeImmutable((string)$appt['end_at'], $local))->setTimezone($utc);
        $attendees = appt_people($appt, ['Accepted']);

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//IMS//Staff Appointments//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:' . ($cancel ? 'CANCEL' : 'REQUEST'),
            'BEGIN:VEVENT',
            // One UID per agreed time: a reschedule cancels the old UID and the new time is a new event.
            'UID:appointment-' . (int)$appt['appointment_id'] . '-' . (int)($appt['revision'] ?? 0) . '@' . $host,
            'SEQUENCE:' . ($cancel ? 1 : 0),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . ev_ics_escape((string)$appt['title']),
        ];
        if (!empty($appt['location'])) {
            $lines[] = 'LOCATION:' . ev_ics_escape((string)$appt['location']);
        }
        $description = 'Appointment organised by ' . $appt['requester_name'] . ' with ' . appt_name_list(appt_people($appt, null, (int)$appt['requester_id'])) . '.';
        if (!empty($appt['agenda'])) {
            $description .= "\n\n" . $appt['agenda'];
        }
        $lines[] = 'DESCRIPTION:' . ev_ics_escape($description);
        $lines[] = 'ORGANIZER;CN="' . ev_ics_param($organizerName) . '":mailto:' . $organizerEmail;
        foreach ($attendees as $p) {
            if (filter_var((string)$p['email'], FILTER_VALIDATE_EMAIL)) {
                $lines[] = 'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;CN="'
                    . ev_ics_param((string)$p['full_name']) . '":mailto:' . $p['email'];
            }
        }
        $lines[] = 'STATUS:' . ($cancel ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'TRANSP:OPAQUE';
        if (!$cancel && $alarmMinutes > 0) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Reminder';
            $lines[] = 'TRIGGER:-PT' . $alarmMinutes . 'M';
            $lines[] = 'END:VALARM';
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map('ev_ics_fold', $lines)) . "\r\n";
    }
}

/** Email each of $people a calendar invite (REQUEST) or cancellation (CANCEL), with their own alarm. */
if (!function_exists('appt_email_invite')) {
    function appt_email_invite(array $appt, string $method, array $people): int
    {
        if (!$people) {
            return 0;
        }
        require_once __DIR__ . '/mail-function.php';
        $settings = function_exists('getEmailSettings') ? (getEmailSettings() ?: []) : [];
        $fromEmail = trim((string)($settings['from_email'] ?? ''));
        $fromName = trim((string)($settings['from_name'] ?? '')) ?: (defined('SITE_NAME') ? SITE_NAME : 'IMS');
        $cancel = $method === 'CANCEL';
        $subject = ($cancel ? 'Cancelled: ' : 'Confirmed: ') . $appt['title'] . ' (' . date('j M Y, H:i', strtotime((string)$appt['start_at'])) . ')';

        $sent = 0;
        foreach ($people as $person) {
            $file = null;
            $dir = null;
            if ($fromEmail !== '') {
                $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims-ics-' . bin2hex(random_bytes(6));
                if (@mkdir($dir, 0700) || is_dir($dir)) {
                    $file = $dir . DIRECTORY_SEPARATOR . ($cancel ? 'cancel.ics' : 'invite.ics');
                    file_put_contents($file, appt_build_ics($appt, $method, $fromEmail, $fromName, (int)($person['remind_minutes'] ?? 0)));
                }
            }

            $intro = $cancel
                ? '<p>The following appointment has been <strong>cancelled</strong> for you' . ($file ? '; open the attachment to remove it from your calendar' : '') . '.</p>'
                : '<p>The following appointment is <strong>confirmed</strong>' . ($file ? '. Open the attached invite to add it to your calendar' : '') . '.</p>';

            $sent += appt_email(appt_recipients([$person]), $subject, $intro . appt_details_html($appt), $file ? [$file] : []);

            if ($file) {
                @unlink($file);
                @rmdir((string)$dir);
            }
        }

        return $sent;
    }
}
