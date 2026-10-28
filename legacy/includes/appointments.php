<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| STAFF APPOINTMENTS
|--------------------------------------------------------------------------
| One staff member (requester) asks another (invitee) for a time slot.
|   - Pending:  the invitee gets an in-app notification and an email.
|   - Accepted: the requester is notified, both get an .ics invite so the
|     appointment lands in their own calendar app, and the slot is blocked
|     for both: no new appointment may overlap an accepted one for either
|     person. Other pending requests that overlap are declined automatically.
|   - Declined / Cancelled: the other party is notified (and, if it had been
|     accepted, both get a calendar cancellation).
|   - Rescheduled: either party proposes a new time (proposed_by); it goes
|     back to Pending and the other party accepts or declines it. Each
|     version of the time gets its own calendar UID (revision).
| Slots are also blocked by hub events a person is staffed on.
| Each party picks their own reminder lead time; appt_dispatch_reminders()
| sends them from cron/task-reminders.php and lazily from the page.
|
| Times are stored as local DATETIME in PHP's default timezone, and "now" is
| always passed from PHP so PHP and MySQL clocks never disagree.
*/

require_once __DIR__ . '/event-staff.php'; // ev_ics_escape / ev_ics_param / ev_ics_fold

const APPT_MAX_HOURS = 12;

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
        } catch (Throwable $e) {
            error_log('staff_appointments table unavailable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
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

if (!function_exists('appt_is_staff')) {
    function appt_is_staff(mysqli $conn, int $userId): bool
    {
        $stmt = $conn->prepare('SELECT role FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && in_array((string)$row['role'], appt_staff_roles(), true);
    }
}

/** One appointment with both parties' names and emails. */
if (!function_exists('appt_find')) {
    function appt_find(mysqli $conn, int $appointmentId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT a.*, r.full_name AS requester_name, r.email AS requester_email,
                    i.full_name AS invitee_name, i.email AS invitee_email
             FROM staff_appointments a
             JOIN users r ON r.user_id = a.requester_id
             JOIN users i ON i.user_id = a.invitee_id
             WHERE a.appointment_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

/** Appointments involving $userId that overlap [from, to), with the other party's name. */
if (!function_exists('appt_for_user')) {
    function appt_for_user(mysqli $conn, int $userId, string $from, string $to, array $statuses = ['Pending', 'Accepted']): array
    {
        $marks = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $conn->prepare(
            "SELECT a.*, r.full_name AS requester_name, i.full_name AS invitee_name
             FROM staff_appointments a
             JOIN users r ON r.user_id = a.requester_id
             JOIN users i ON i.user_id = a.invitee_id
             WHERE (a.requester_id = ? OR a.invitee_id = ?)
               AND a.start_at < ? AND a.end_at > ?
               AND a.status IN ($marks)
             ORDER BY a.start_at"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iiss' . str_repeat('s', count($statuses)), $userId, $userId, $to, $from, ...$statuses);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}

/** Accepted appointments of any of $userIds overlapping [start, end). */
if (!function_exists('appt_conflicts')) {
    function appt_conflicts(mysqli $conn, array $userIds, string $start, string $end, int $excludeId = 0): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $conn->prepare(
            "SELECT appointment_id, requester_id, invitee_id, start_at, end_at
             FROM staff_appointments
             WHERE status = 'Accepted' AND appointment_id <> ?
               AND start_at < ? AND end_at > ?
               AND (requester_id IN ($marks) OR invitee_id IN ($marks))"
        );
        if (!$stmt) {
            return [];
        }
        $types = 'iss' . str_repeat('i', count($userIds) * 2);
        $stmt->bind_param($types, $excludeId, $end, $start, ...$userIds, ...$userIds);
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
                "SELECT s.user_id, e.event_id, e.event_title, e.event_date, e.event_days, e.start_time, e.end_time
                 FROM hub_event_staff s
                 JOIN hub_events e ON e.event_id = s.event_id
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
                    $busy[] = ['user_id' => (int)$row['user_id'], 'title' => (string)$row['event_title'], 'start_at' => $s, 'end_at' => $e];
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
            return ($e['user_id'] === $viewerId ? 'You are' : 'They are') . ' staffing the event "' . $e['title'] . '" '
                . appt_format_range($e['start_at'], $e['end_at']) . '. Pick another time.';
        }

        return null;
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

/** The party who must accept or decline a pending appointment (not whoever proposed the time). */
if (!function_exists('appt_responder_id')) {
    function appt_responder_id(array $appt): int
    {
        $proposer = (int)($appt['proposed_by'] ?? 0) ?: (int)$appt['requester_id'];

        return $proposer === (int)$appt['requester_id'] ? (int)$appt['invitee_id'] : (int)$appt['requester_id'];
    }
}

/** 'requester' or 'invitee' for $userId, or null when they are not a party. */
if (!function_exists('appt_side')) {
    function appt_side(array $appt, int $userId): ?string
    {
        return match ($userId) {
            (int)$appt['requester_id'] => 'requester',
            (int)$appt['invitee_id'] => 'invitee',
            default => null,
        };
    }
}

/** Serialise booking for these users: row locks held until commit/rollback. */
if (!function_exists('appt_lock_users')) {
    function appt_lock_users(mysqli $conn, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
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

/** Human-readable conflict text, from $viewerId's point of view. */
if (!function_exists('appt_conflict_message')) {
    function appt_conflict_message(array $conflicts, int $viewerId): string
    {
        $c = $conflicts[0];
        $who = ((int)$c['requester_id'] === $viewerId || (int)$c['invitee_id'] === $viewerId)
            ? 'You already have'
            : 'They already have';

        return $who . ' an appointment ' . appt_format_range((string)$c['start_at'], (string)$c['end_at'])
            . '. Pick another time.';
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

/*
|--------------------------------------------------------------------------
| ACTIONS: each returns ['ok' => bool, 'message' => string]
|--------------------------------------------------------------------------
*/

if (!function_exists('appt_create')) {
    function appt_create(mysqli $conn, int $requesterId, array $input): array
    {
        $inviteeId = (int)($input['invitee_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $agenda = trim((string)($input['agenda'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $start = appt_parse_datetime((string)($input['start_at'] ?? ''));
        $end = appt_parse_datetime((string)($input['end_at'] ?? ''));
        $remind = (int)($input['remind_minutes'] ?? 0);

        if ($inviteeId <= 0 || $inviteeId === $requesterId || !appt_is_staff($conn, $inviteeId)) {
            return ['ok' => false, 'message' => 'Choose a staff member to meet with.'];
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

        $conn->begin_transaction();
        try {
            appt_lock_users($conn, [$requesterId, $inviteeId]);

            if ($problem = appt_slot_problem($conn, [$requesterId, $inviteeId], $start, $end, 0, $requesterId)) {
                $conn->rollback();
                return ['ok' => false, 'message' => $problem];
            }

            $stmt = $conn->prepare(
                'INSERT INTO staff_appointments
                    (requester_id, invitee_id, proposed_by, title, agenda, location, start_at, end_at,
                     requester_remind_minutes, requester_remind_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('iiisssssis', $requesterId, $inviteeId, $requesterId, $title, $agendaValue, $locationValue, $start, $end, $remind, $remindAt);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save the appointment. Please try again.'];
        }

        $appt = appt_find($conn, $id);
        if ($appt) {
            $when = appt_format_range($start, $end);
            if (function_exists('notify_user')) {
                notify_user($inviteeId, 'Appointment request', $appt['requester_name'] . ' asked to meet: ' . $title . ' · ' . $when, 'info', $id, 'appointment');
            }
            appt_email(
                [['name' => $appt['invitee_name'], 'email' => $appt['invitee_email']]],
                'Appointment request: ' . $title,
                '<p>' . appt_h($appt['requester_name']) . ' would like to meet with you.</p>'
                    . appt_details_html($appt)
                    . '<p>Open <strong>Appointments</strong> in the IMS to accept or decline.</p>'
            );
        }

        return ['ok' => true, 'message' => 'Appointment request sent. You will be notified when they respond.'];
    }
}

if (!function_exists('appt_accept')) {
    function appt_accept(mysqli $conn, int $userId, int $appointmentId, int $remind): array
    {
        if (!array_key_exists($remind, appt_reminder_options())) {
            $remind = 0;
        }

        $appt = appt_find($conn, $appointmentId);
        if (!$appt || appt_side($appt, $userId) === null) {
            return ['ok' => false, 'message' => 'Appointment not found.'];
        }

        $superseded = [];
        $conn->begin_transaction();
        try {
            $parties = [(int)$appt['requester_id'], (int)$appt['invitee_id']];
            appt_lock_users($conn, $parties);

            // Re-read under the lock: it may have been cancelled or rescheduled meanwhile.
            $appt = appt_find($conn, $appointmentId);
            if (!$appt || $appt['status'] !== 'Pending' || appt_responder_id($appt) !== $userId) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This request is no longer waiting for your response.'];
            }
            if ((string)$appt['start_at'] <= date('Y-m-d H:i:s')) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This appointment time has already passed.'];
            }

            $problem = appt_slot_problem($conn, $parties, (string)$appt['start_at'], (string)$appt['end_at'], $appointmentId, $userId);
            if ($problem) {
                $conn->rollback();
                return ['ok' => false, 'message' => $problem . ' Decline this request or suggest another time.'];
            }

            // The responder sets their own reminder; both are recalculated for the agreed time.
            $side = appt_side($appt, $userId);
            $appt[$side . '_remind_minutes'] = $remind;
            $requesterMinutes = (int)$appt['requester_remind_minutes'];
            $inviteeMinutes = (int)$appt['invitee_remind_minutes'];
            $requesterRemindAt = appt_remind_at((string)$appt['start_at'], $requesterMinutes);
            $inviteeRemindAt = appt_remind_at((string)$appt['start_at'], $inviteeMinutes);
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare(
                "UPDATE staff_appointments
                 SET status = 'Accepted', responded_at = ?,
                     requester_remind_minutes = ?, requester_remind_at = ?, requester_reminded_at = NULL,
                     invitee_remind_minutes = ?, invitee_remind_at = ?, invitee_reminded_at = NULL
                 WHERE appointment_id = ? AND status = 'Pending'"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('sisisi', $now, $requesterMinutes, $requesterRemindAt, $inviteeMinutes, $inviteeRemindAt, $appointmentId);
            $stmt->execute();
            $stmt->close();

            // The slot is now blocked for both: decline other pending requests that overlap it.
            $stmt = $conn->prepare(
                "SELECT appointment_id FROM staff_appointments
                 WHERE status = 'Pending' AND appointment_id <> ?
                   AND start_at < ? AND end_at > ?
                   AND (requester_id IN (?, ?) OR invitee_id IN (?, ?))"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('issiiii', $appointmentId, $appt['end_at'], $appt['start_at'], $parties[0], $parties[1], $parties[0], $parties[1]);
            $stmt->execute();
            $superseded = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'appointment_id'));
            $stmt->close();

            if ($superseded) {
                $note = 'Time slot no longer available.';
                $decline = $conn->prepare(
                    "UPDATE staff_appointments SET status = 'Declined', responded_at = ?, response_note = ?
                     WHERE appointment_id = ? AND status = 'Pending'"
                );
                if (!$decline) {
                    throw new RuntimeException($conn->error);
                }
                foreach ($superseded as $otherId) {
                    $decline->bind_param('ssi', $now, $note, $otherId);
                    $decline->execute();
                }
                $decline->close();
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_accept: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not accept the appointment. Please try again.'];
        }

        $appt = appt_find($conn, $appointmentId) ?? $appt;
        $isRequester = $userId === (int)$appt['requester_id'];
        $proposerId = $isRequester ? (int)$appt['invitee_id'] : (int)$appt['requester_id'];
        $acceptor = $isRequester ? $appt['requester_name'] : $appt['invitee_name'];
        $when = appt_format_range((string)$appt['start_at'], (string)$appt['end_at']);
        if (function_exists('notify_user')) {
            notify_user($proposerId, 'Appointment accepted', $acceptor . ' accepted: ' . $appt['title'] . ' · ' . $when, 'success', $appointmentId, 'appointment');
        }
        appt_email_invite($appt, 'REQUEST');

        foreach ($superseded as $otherId) {
            $other = appt_find($conn, $otherId);
            if ($other && function_exists('notify_user')) {
                $otherProposer = (int)($other['proposed_by'] ?? 0) ?: (int)$other['requester_id'];
                notify_user($otherProposer, 'Appointment declined', $other['title'] . ' · ' . appt_format_range((string)$other['start_at'], (string)$other['end_at']) . ' was declined automatically: the time slot is no longer available.', 'warning', $otherId, 'appointment');
            }
        }

        return ['ok' => true, 'message' => 'Appointment accepted and added to both calendars.'];
    }
}

if (!function_exists('appt_decline')) {
    function appt_decline(mysqli $conn, int $userId, int $appointmentId, string $note): array
    {
        $appt = appt_find($conn, $appointmentId);
        if (!$appt || $appt['status'] !== 'Pending' || appt_responder_id($appt) !== $userId) {
            return ['ok' => false, 'message' => 'This request is no longer waiting for your response.'];
        }

        $note = mb_substr(trim($note), 0, 500);
        $noteValue = $note !== '' ? $note : null;
        $now = date('Y-m-d H:i:s');
        $proposedBy = (int)($appt['proposed_by'] ?? 0) ?: (int)$appt['requester_id'];

        // Guard on the proposer too, so a reschedule that lands meanwhile is not declined unseen.
        $stmt = $conn->prepare(
            "UPDATE staff_appointments SET status = 'Declined', responded_at = ?, response_note = ?
             WHERE appointment_id = ? AND status = 'Pending' AND COALESCE(proposed_by, requester_id) = ?"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not decline the appointment.'];
        }
        $stmt->bind_param('ssii', $now, $noteValue, $appointmentId, $proposedBy);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed !== 1) {
            return ['ok' => false, 'message' => 'This request has already changed. Refresh and try again.'];
        }

        $isRequester = $userId === (int)$appt['requester_id'];
        $decliner = $isRequester ? $appt['requester_name'] : $appt['invitee_name'];
        $proposer = $isRequester ? $appt['invitee_name'] : $appt['requester_name'];
        if (function_exists('notify_user')) {
            notify_user(
                $proposedBy,
                'Appointment declined',
                $decliner . ' declined: ' . $appt['title'] . ' · ' . appt_format_range((string)$appt['start_at'], (string)$appt['end_at']) . ($note !== '' ? "\nReason: " . $note : ''),
                'warning',
                $appointmentId,
                'appointment'
            );
        }

        return ['ok' => true, 'message' => 'Appointment declined. ' . $proposer . ' has been notified.'];
    }
}

/**
 * Either party proposes a new time for a pending or accepted appointment. It
 * goes back to Pending for the other party to accept; an accepted slot is
 * released (and removed from calendars) until then.
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
        if (!$appt || appt_side($appt, $userId) === null) {
            return ['ok' => false, 'message' => 'Appointment not found.'];
        }

        $conn->begin_transaction();
        try {
            $parties = [(int)$appt['requester_id'], (int)$appt['invitee_id']];
            appt_lock_users($conn, $parties);

            $appt = appt_find($conn, $appointmentId);
            if (!$appt || !in_array($appt['status'], ['Pending', 'Accepted'], true)) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Only pending or accepted appointments can be rescheduled.'];
            }
            if ((string)$appt['end_at'] <= date('Y-m-d H:i:s')) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This appointment has already taken place.'];
            }
            if ($start === (string)$appt['start_at'] && $end === (string)$appt['end_at']) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Choose a different time from the current one.'];
            }

            if ($problem = appt_slot_problem($conn, $parties, $start, $end, $appointmentId, $userId)) {
                $conn->rollback();
                return ['ok' => false, 'message' => $problem];
            }

            // The proposer's reminder follows the new time; the other party picks theirs on accepting.
            $side = appt_side($appt, $userId);
            $remindAt = appt_remind_at($start, (int)$appt[$side . '_remind_minutes']);
            $status = (string)$appt['status'];
            $stmt = $conn->prepare(
                "UPDATE staff_appointments
                 SET start_at = ?, end_at = ?, status = 'Pending', proposed_by = ?, revision = revision + 1,
                     response_note = ?, responded_at = NULL,
                     requester_remind_at = NULL, invitee_remind_at = NULL,
                     requester_reminded_at = NULL, invitee_reminded_at = NULL,
                     {$side}_remind_at = ?
                 WHERE appointment_id = ? AND status = ?"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('ssissis', $start, $end, $userId, $noteValue, $remindAt, $appointmentId, $status);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed !== 1) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'This appointment has already changed. Refresh and try again.'];
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('appt_reschedule: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not reschedule the appointment. Please try again.'];
        }

        // Remove the old confirmed time from both calendars; acceptance sends the new one.
        if ($appt['status'] === 'Accepted') {
            appt_email_invite($appt, 'CANCEL');
        }

        $isRequester = $side === 'requester';
        $otherId = $isRequester ? (int)$appt['invitee_id'] : (int)$appt['requester_id'];
        $actor = $isRequester ? $appt['requester_name'] : $appt['invitee_name'];
        $other = $isRequester ? $appt['invitee_name'] : $appt['requester_name'];
        $otherEmail = $isRequester ? $appt['invitee_email'] : $appt['requester_email'];
        $fresh = appt_find($conn, $appointmentId) ?? $appt;
        if (function_exists('notify_user')) {
            notify_user($otherId, 'New time proposed', $actor . ' proposed a new time for ' . $appt['title'] . ': ' . appt_format_range($start, $end) . ($note !== '' ? "\nNote: " . $note : ''), 'info', $appointmentId, 'appointment');
        }
        appt_email(
            [['name' => $other, 'email' => $otherEmail]],
            'New time proposed: ' . $appt['title'],
            '<p>' . appt_h($actor) . ' proposed a new time for your appointment.</p>'
                . appt_details_html($fresh)
                . ($note !== '' ? '<p><strong>Note:</strong> ' . nl2br(appt_h($note)) . '</p>' : '')
                . '<p>Open <strong>Appointments</strong> in the IMS to accept or decline.</p>'
        );

        return ['ok' => true, 'message' => 'New time sent to ' . $other . ' for confirmation.'];
    }
}

/** The proposer may withdraw a pending request; either party may cancel an accepted one. */
if (!function_exists('appt_cancel')) {
    function appt_cancel(mysqli $conn, int $userId, int $appointmentId, string $note): array
    {
        $appt = appt_find($conn, $appointmentId);
        if (!$appt) {
            return ['ok' => false, 'message' => 'Appointment not found.'];
        }

        $side = appt_side($appt, $userId);
        $wasAccepted = $appt['status'] === 'Accepted';
        $isProposer = $side !== null && appt_responder_id($appt) !== $userId;
        $allowed = ($appt['status'] === 'Pending' && $isProposer) || ($wasAccepted && $side !== null);
        if (!$allowed) {
            return ['ok' => false, 'message' => 'This appointment cannot be cancelled.'];
        }

        $note = mb_substr(trim($note), 0, 500);
        $noteValue = $note !== '' ? $note : null;
        $status = (string)$appt['status'];
        $stmt = $conn->prepare(
            "UPDATE staff_appointments SET status = 'Cancelled', cancelled_by = ?, response_note = ?
             WHERE appointment_id = ? AND status = ?"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not cancel the appointment.'];
        }
        $stmt->bind_param('isis', $userId, $noteValue, $appointmentId, $status);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed !== 1) {
            return ['ok' => false, 'message' => 'This appointment has already changed. Refresh and try again.'];
        }

        $otherId = $side === 'requester' ? (int)$appt['invitee_id'] : (int)$appt['requester_id'];
        $actor = $side === 'requester' ? $appt['requester_name'] : $appt['invitee_name'];
        if (function_exists('notify_user')) {
            notify_user(
                $otherId,
                'Appointment cancelled',
                $actor . ' cancelled: ' . $appt['title'] . ' · ' . appt_format_range((string)$appt['start_at'], (string)$appt['end_at']) . ($note !== '' ? "\nReason: " . $note : ''),
                'warning',
                $appointmentId,
                'appointment'
            );
        }
        if ($wasAccepted) {
            appt_email_invite($appt, 'CANCEL');
        }

        return ['ok' => true, 'message' => $wasAccepted ? 'Appointment cancelled and removed from both calendars.' : 'Request withdrawn.'];
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
            "SELECT appointment_id FROM staff_appointments
             WHERE status = 'Accepted' AND start_at > ?
               AND ((requester_remind_at <= ? AND requester_reminded_at IS NULL)
                 OR (invitee_remind_at <= ? AND invitee_reminded_at IS NULL))
             ORDER BY start_at LIMIT " . $limit
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('sss', $now, $now, $now);
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'appointment_id'));
        $stmt->close();

        $sent = 0;
        foreach ($ids as $id) {
            foreach (['requester', 'invitee'] as $side) {
                // Claim first so overlapping runs never send twice.
                $claim = $conn->prepare(
                    "UPDATE staff_appointments SET {$side}_reminded_at = ?
                     WHERE appointment_id = ? AND status = 'Accepted' AND start_at > ?
                       AND {$side}_remind_at <= ? AND {$side}_reminded_at IS NULL"
                );
                if (!$claim) {
                    continue;
                }
                $claim->bind_param('siss', $now, $id, $now, $now);
                $claim->execute();
                $claimed = $claim->affected_rows === 1;
                $claim->close();

                if (!$claimed || !($appt = appt_find($conn, $id))) {
                    continue;
                }

                $other = $side === 'requester' ? $appt['invitee_name'] : $appt['requester_name'];
                $when = appt_format_range((string)$appt['start_at'], (string)$appt['end_at']);
                if (function_exists('notify_user')) {
                    notify_user((int)$appt[$side . '_id'], 'Appointment reminder', $appt['title'] . ' with ' . $other . ' · ' . $when, 'warning', $id, 'appointment');
                }
                appt_email(
                    [['name' => $appt[$side . '_name'], 'email' => $appt[$side . '_email']]],
                    'Reminder: ' . $appt['title'] . ' (' . date('D j M, H:i', strtotime((string)$appt['start_at'])) . ')',
                    '<p>This is a reminder of your appointment with ' . appt_h($other) . '.</p>' . appt_details_html($appt)
                );
                $sent++;
            }
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
        return '<table cellpadding="6" style="border-collapse:collapse">'
            . '<tr><td><strong>Subject</strong></td><td>' . appt_h($appt['title']) . '</td></tr>'
            . '<tr><td><strong>When</strong></td><td>' . appt_h(appt_format_range((string)$appt['start_at'], (string)$appt['end_at'])) . ' (' . appt_h(date_default_timezone_get()) . ')</td></tr>'
            . '<tr><td><strong>With</strong></td><td>' . appt_h($appt['requester_name']) . ' and ' . appt_h($appt['invitee_name']) . '</td></tr>'
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
                $result = sendEmail($email, $subject, $body, trim(strip_tags(str_replace(['</p>', '</tr>'], "\n", $html))), [], [], $attachments);
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
    function appt_build_ics(array $appt, string $method, string $organizerEmail, string $organizerName): string
    {
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'ims.local')) ?: 'ims.local';
        $cancel = $method === 'CANCEL';
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone(date_default_timezone_get());
        $start = (new DateTimeImmutable((string)$appt['start_at'], $local))->setTimezone($utc);
        $end = (new DateTimeImmutable((string)$appt['end_at'], $local))->setTimezone($utc);
        $alarm = max((int)($appt['requester_remind_minutes'] ?? 0), (int)($appt['invitee_remind_minutes'] ?? 0));

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
        $description = 'Appointment between ' . $appt['requester_name'] . ' and ' . $appt['invitee_name'] . '.';
        if (!empty($appt['agenda'])) {
            $description .= "\n\n" . $appt['agenda'];
        }
        $lines[] = 'DESCRIPTION:' . ev_ics_escape($description);
        $lines[] = 'ORGANIZER;CN="' . ev_ics_param($organizerName) . '":mailto:' . $organizerEmail;
        foreach (['requester', 'invitee'] as $side) {
            $lines[] = 'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;CN="'
                . ev_ics_param((string)$appt[$side . '_name']) . '":mailto:' . $appt[$side . '_email'];
        }
        $lines[] = 'STATUS:' . ($cancel ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'TRANSP:OPAQUE';
        if (!$cancel && $alarm > 0) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Reminder';
            $lines[] = 'TRIGGER:-PT' . $alarm . 'M';
            $lines[] = 'END:VALARM';
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map('ev_ics_fold', $lines)) . "\r\n";
    }
}

/** Email both parties a calendar invite (REQUEST) or cancellation (CANCEL). */
if (!function_exists('appt_email_invite')) {
    function appt_email_invite(array $appt, string $method): int
    {
        require_once __DIR__ . '/mail-function.php';
        $settings = function_exists('getEmailSettings') ? (getEmailSettings() ?: []) : [];
        $fromEmail = trim((string)($settings['from_email'] ?? ''));
        $fromName = trim((string)($settings['from_name'] ?? '')) ?: (defined('SITE_NAME') ? SITE_NAME : 'IMS');
        $cancel = $method === 'CANCEL';

        $file = null;
        $dir = null;
        if ($fromEmail !== '') {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims-ics-' . bin2hex(random_bytes(6));
            if (@mkdir($dir, 0700) || is_dir($dir)) {
                $file = $dir . DIRECTORY_SEPARATOR . ($cancel ? 'cancel.ics' : 'invite.ics');
                file_put_contents($file, appt_build_ics($appt, $method, $fromEmail, $fromName));
            }
        }

        $intro = $cancel
            ? '<p>The following appointment has been <strong>cancelled</strong>' . ($file ? '; open the attachment to remove it from your calendar' : '') . '.</p>'
            : '<p>The following appointment is <strong>confirmed</strong>' . ($file ? '. Open the attached invite to add it to your calendar' : '') . '.</p>';

        $sent = appt_email(
            [
                ['name' => $appt['requester_name'], 'email' => $appt['requester_email']],
                ['name' => $appt['invitee_name'], 'email' => $appt['invitee_email']],
            ],
            ($cancel ? 'Cancelled: ' : 'Confirmed: ') . $appt['title'] . ' (' . date('j M Y, H:i', strtotime((string)$appt['start_at'])) . ')',
            $intro . appt_details_html($appt),
            $file ? [$file] : []
        );

        if ($file) {
            @unlink($file);
            @rmdir((string)$dir);
        }

        return $sent;
    }
}
