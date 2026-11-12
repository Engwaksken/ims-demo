<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| EVENT ATTENDANCE REMINDERS AND SELF-CONFIRMATION
|--------------------------------------------------------------------------
| Every registration for an event day gets a reminder 30, 20 and 10 minutes
| before the day's start time and one at the start, each asking the person
| to confirm their attendance through a signed link (event-confirm.php):
|   - "Yes"  -> registration_status = 'Attended'
|   - "No"   -> registration_status = 'No Show'
| so the existing attendance page, counts and actual_participants pick the
| answer up; confirmation_response / confirmed_at record that the person
| answered themselves (staff can still change the status afterwards).
| Reminders stop once the person answers, checks in, is marked by staff, or
| the registration/event is cancelled.
|
| Everyone gets an email (an email is required to register); anyone who also
| has an IMS account (member, or a user with the same email) gets an in-app
| notification. evr_dispatch() runs from cron/task-reminders.php and
| cron/event-reminders.php; schedule one of them every 5 minutes or less.
|
| Times are local DATETIME in PHP's default timezone; "now" comes from PHP.
*/

require_once __DIR__ . '/event-links.php'; // ev_has_column, ev_participant_link

/** Minutes before the start at which reminders go out (descending). */
const EVR_OFFSETS = [30, 20, 10, 0];
/** How long after the start the last reminder may still go out (late cron). */
const EVR_GRACE_MINUTES = 15;

if (!function_exists('evr_ensure_schema')) {
    function evr_ensure_schema(mysqli $conn): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }

        $columns = [
            'confirmation_response' => "VARCHAR(20) NULL",
            'confirmed_at' => 'DATETIME NULL',
            'reminder_stage' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'last_reminder_at' => 'DATETIME NULL',
        ];

        $done = true;
        foreach ($columns as $column => $definition) {
            if (ev_has_column($conn, 'event_registrations', $column)) {
                continue;
            }
            try {
                $ok = $conn->query("ALTER TABLE event_registrations ADD COLUMN `{$column}` {$definition}");
            } catch (Throwable $e) {
                $ok = false;
                error_log("event-reminders: could not add event_registrations.{$column}: " . $e->getMessage());
            }
            if (!$ok || !ev_has_column($conn, 'event_registrations', $column, true)) {
                $done = false;
            }
        }

        return $done;
    }
}

if (!function_exists('evr_signature')) {
    function evr_signature(int $registrationId, int $eventId): string
    {
        $key = function_exists('ims_encryption_key') ? (string)ims_encryption_key() : '';
        if ($key === '') {
            $key = (string)(getenv('APP_KEY') ?: 'ims-event-confirm');
        }

        return substr(hash_hmac('sha256', 'confirm|' . $registrationId . '|' . $eventId, $key), 0, 32);
    }
}

if (!function_exists('evr_token')) {
    function evr_token(int $registrationId, int $eventId): string
    {
        return rtrim(strtr(base64_encode($registrationId . '.' . $eventId . '.' . evr_signature($registrationId, $eventId)), '+/', '-_'), '=');
    }
}

/** [registration_id, event_id] from a token, or null when it is forged or malformed. */
if (!function_exists('evr_parse_token')) {
    function evr_parse_token(string $token): ?array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || !preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{32})$/', $raw, $m)) {
            return null;
        }
        if (!hash_equals(evr_signature((int)$m[1], (int)$m[2]), $m[3])) {
            return null;
        }

        return [(int)$m[1], (int)$m[2]];
    }
}

if (!function_exists('evr_confirm_url')) {
    function evr_confirm_url(int $registrationId, int $eventId, string $answer = ''): string
    {
        return rtrim((string)APP_URL, '/') . '/event-confirm?token=' . rawurlencode(evr_token($registrationId, $eventId))
            . ($answer !== '' ? '&answer=' . rawurlencode($answer) : '');
    }
}

/** Start of the registration's event day as "Y-m-d H:i:s", or null. */
if (!function_exists('evr_day_start')) {
    function evr_day_start(array $row): ?string
    {
        $day = substr((string)($row['attendance_date'] ?? ''), 0, 10) ?: substr((string)($row['event_date'] ?? ''), 0, 10);
        $time = substr((string)($row['start_time'] ?? ''), 0, 8);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            return null;
        }

        return $day . ' ' . (strlen($time) === 5 ? $time . ':00' : $time);
    }
}

/** May this registration still answer? (until the end of the event day) */
if (!function_exists('evr_can_answer')) {
    function evr_can_answer(array $row): bool
    {
        if (($row['registration_status'] ?? '') === 'Cancelled' || ($row['event_status'] ?? '') === 'Cancelled') {
            return false;
        }
        $day = substr((string)($row['attendance_date'] ?? ''), 0, 10) ?: substr((string)($row['event_date'] ?? ''), 0, 10);

        return $day !== '' && date('Y-m-d') <= $day;
    }
}

/** One registration with its event, for the confirmation page and reminders. */
if (!function_exists('evr_find')) {
    function evr_find(mysqli $conn, int $registrationId, int $eventId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT r.*, e.event_title, e.event_type, e.event_date, e.start_time, e.end_time, e.venue, e.event_status'
            . (ev_has_column($conn, 'hub_events', 'meeting_link') ? ', e.meeting_link' : '') . '
             FROM event_registrations r
             JOIN hub_events e ON e.event_id = r.event_id
             WHERE r.registration_id = ? AND r.event_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $registrationId, $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

/**
 * Record the person's own answer. $answer is 'yes' or 'no'.
 * Returns ['ok' => bool, 'message' => string].
 */
if (!function_exists('evr_record_response')) {
    function evr_record_response(mysqli $conn, int $registrationId, int $eventId, string $answer): array
    {
        if (!evr_ensure_schema($conn)) {
            return ['ok' => false, 'message' => 'Attendance confirmation is unavailable right now. Please contact the organisers.'];
        }
        $row = evr_find($conn, $registrationId, $eventId);
        if (!$row) {
            return ['ok' => false, 'message' => 'Registration not found.'];
        }
        if (!evr_can_answer($row)) {
            return ['ok' => false, 'message' => ($row['registration_status'] ?? '') === 'Cancelled' || ($row['event_status'] ?? '') === 'Cancelled'
                ? 'This registration or event has been cancelled.'
                : 'This event day has passed, so attendance can no longer be confirmed here. Please contact the organisers.'];
        }
        if (!in_array($answer, ['yes', 'no'], true)) {
            return ['ok' => false, 'message' => 'Choose whether you are attending.'];
        }

        $response = $answer === 'yes' ? 'Attended' : 'Not Attending';
        // A check-in at the venue is stronger evidence than a "no" click.
        $status = $answer === 'yes' ? 'Attended' : (($row['attendance_status'] ?? '') === 'Checked In' ? 'Attended' : 'No Show');
        $now = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            "UPDATE event_registrations
             SET confirmation_response = ?, confirmed_at = ?, registration_status = ?
             WHERE registration_id = ? AND event_id = ? AND registration_status <> 'Cancelled'"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not save your answer. Please try again.'];
        }
        $stmt->bind_param('sssii', $response, $now, $status, $registrationId, $eventId);
        $stmt->execute();
        $stmt->close();

        evr_refresh_participant_count($conn, $eventId);

        return ['ok' => true, 'message' => $answer === 'yes'
            ? 'Thank you, your attendance is confirmed.'
            : 'Thank you for letting us know you cannot attend.'];
    }
}

if (!function_exists('evr_refresh_participant_count')) {
    function evr_refresh_participant_count(mysqli $conn, int $eventId): void
    {
        $stmt = $conn->prepare(
            "UPDATE hub_events
             SET actual_participants = (SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND registration_status = 'Attended')
             WHERE event_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $eventId, $eventId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/** IMS account for a registration (member's user, else an active user with the same email), or 0. */
if (!function_exists('evr_user_id')) {
    function evr_user_id(mysqli $conn, array $row): int
    {
        try {
            if (!empty($row['member_id'])) {
                $stmt = $conn->prepare('SELECT user_id FROM members WHERE member_id = ? LIMIT 1');
                $memberId = (int)$row['member_id'];
                $stmt->bind_param('i', $memberId);
                $stmt->execute();
                $userId = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();
                if ($userId > 0) {
                    return $userId;
                }
            }
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '') {
                $stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $userId = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();

                return $userId;
            }
        } catch (Throwable $e) {
            error_log('evr_user_id: ' . $e->getMessage());
        }

        return 0;
    }
}

/** Send every reminder that is due. Safe to run often and concurrently. Returns reminders sent. */
if (!function_exists('evr_dispatch')) {
    function evr_dispatch(mysqli $conn, int $limit = 500): int
    {
        if (!evr_ensure_schema($conn)) {
            return 0;
        }

        $nowTs = time();
        $now = date('Y-m-d H:i:s', $nowTs);
        // Event days that could be inside the reminder window (covers midnight starts).
        $from = date('Y-m-d', $nowTs - 86400);
        $to = date('Y-m-d', $nowTs + 86400);
        $stages = count(EVR_OFFSETS);
        $limit = max(1, min(2000, $limit));

        $stmt = $conn->prepare(
            "SELECT r.registration_id, r.event_id, r.attendance_date, r.reminder_stage, e.event_date, e.start_time
             FROM event_registrations r
             JOIN hub_events e ON e.event_id = r.event_id
             WHERE COALESCE(r.attendance_date, DATE(e.event_date)) BETWEEN ? AND ?
               AND r.registration_status = 'Registered'
               AND COALESCE(r.attendance_status, '') <> 'Checked In'
               AND r.confirmation_response IS NULL
               AND r.reminder_stage < ?
               AND COALESCE(e.event_status, '') <> 'Cancelled'
               AND e.start_time IS NOT NULL
               AND COALESCE(r.email, '') <> ''
             LIMIT " . $limit
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('ssi', $from, $to, $stages);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $sent = 0;
        foreach ($rows as $row) {
            $start = evr_day_start($row);
            if ($start === null) {
                continue;
            }
            $minutesLeft = (strtotime($start) - $nowTs) / 60;
            if ($minutesLeft > EVR_OFFSETS[0] || $minutesLeft < -EVR_GRACE_MINUTES) {
                continue;
            }

            // How many reminder times have passed; send one message for the latest.
            $due = count(array_filter(EVR_OFFSETS, static fn($offset) => $minutesLeft <= $offset));
            $stage = (int)$row['reminder_stage'];
            if ($due <= $stage) {
                continue;
            }

            // Claim first so overlapping runs never send the same reminder twice.
            $claim = $conn->prepare(
                "UPDATE event_registrations SET reminder_stage = ?, last_reminder_at = ?
                 WHERE registration_id = ? AND reminder_stage = ? AND confirmation_response IS NULL
                   AND registration_status = 'Registered'"
            );
            if (!$claim) {
                continue;
            }
            $id = (int)$row['registration_id'];
            $claim->bind_param('isii', $due, $now, $id, $stage);
            $claim->execute();
            $claimed = $claim->affected_rows === 1;
            $claim->close();
            if (!$claimed) {
                continue;
            }

            $full = evr_find($conn, $id, (int)$row['event_id']);
            if ($full && evr_send_reminder($conn, $full, (int)round($minutesLeft))) {
                $sent++;
            }
        }

        return $sent;
    }
}

if (!function_exists('evr_h')) {
    function evr_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Email (and in-app notify, when they have an account) one reminder. */
if (!function_exists('evr_send_reminder')) {
    function evr_send_reminder(mysqli $conn, array $row, int $minutesLeft): bool
    {
        $rid = (int)$row['registration_id'];
        $eid = (int)$row['event_id'];
        $title = (string)$row['event_title'];
        $when = $minutesLeft > 0 ? 'starts in ' . $minutesLeft . ' minute' . ($minutesLeft === 1 ? '' : 's') : 'is starting now';
        $start = evr_day_start($row) ?? '';
        $timeText = $start !== '' ? date('l j F Y, H:i', strtotime($start)) : '';
        $yes = evr_confirm_url($rid, $eid, 'yes');
        $no = evr_confirm_url($rid, $eid, 'no');

        $link = function_exists('ev_participant_link') ? ev_participant_link($row) : null;
        $where = '';
        if (!empty($row['venue'])) {
            $where .= '<tr><td><strong>Venue</strong></td><td>' . evr_h($row['venue']) . '</td></tr>';
        }
        if (is_array($link) && !empty($link['url']) && ev_is_meeting_type((string)($row['event_type'] ?? ''))) {
            $where .= '<tr><td><strong>Join</strong></td><td><a href="' . evr_h($link['url']) . '">' . evr_h($link['url']) . '</a></td></tr>';
        }

        $button = static fn(string $href, string $label, string $bg): string =>
            '<a href="' . evr_h($href) . '" style="display:inline-block;padding:10px 18px;margin:4px 6px 4px 0;border-radius:8px;background:' . $bg . ';color:#ffffff;text-decoration:none;font-weight:bold">' . evr_h($label) . '</a>';

        $html = '<p>Hello ' . evr_h($row['attendee_name'] ?? '') . ',</p>'
            . '<p><strong>' . evr_h($title) . '</strong> ' . evr_h($when) . '.</p>'
            . '<table cellpadding="6" style="border-collapse:collapse">'
            . ($timeText !== '' ? '<tr><td><strong>When</strong></td><td>' . evr_h($timeText) . '</td></tr>' : '')
            . $where
            . '</table>'
            . '<p>Please confirm your attendance:</p>'
            . '<p>' . $button($yes, "Yes, I'm attending", '#16a34a') . $button($no, "No, I can't attend", '#dc2626') . '</p>'
            . '<p style="color:#6b7280;font-size:12px">You will get a reminder every 10 minutes until the event starts, unless you answer.</p>';

        require_once __DIR__ . '/mail-function.php';
        $emailed = false;
        $email = trim((string)($row['email'] ?? ''));
        if (function_exists('sendEmail') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $body = function_exists('email_wrapper') ? email_wrapper($html) : $html;
            $text = $title . ' ' . $when . ".\n" . ($timeText !== '' ? 'When: ' . $timeText . "\n" : '')
                . "\nPlease confirm your attendance:\nYes: " . $yes . "\nNo: " . $no . "\n";
            try {
                $result = sendEmail($email, ucfirst($when) . ': ' . $title . ' - please confirm your attendance', $body, $text);
                $emailed = $result === true;
                if (!$emailed) {
                    error_log('Event reminder to ' . $email . ' failed: ' . (is_string($result) ? $result : 'unknown error'));
                }
            } catch (Throwable $e) {
                error_log('Event reminder to ' . $email . ' failed: ' . $e->getMessage());
            }
        }

        $userId = evr_user_id($conn, $row);
        if ($userId > 0 && function_exists('notify_user')) {
            notify_user($userId, 'Event ' . $when . ': ' . $title, ($timeText !== '' ? $timeText . "\n" : '') . 'Please confirm your attendance.', 'warning', $rid, 'event_registration');
        }

        return $emailed || $userId > 0;
    }
}

/**
 * Fallback when cron is not scheduled: at most once a minute per server,
 * dispatch reminders after the current page has been sent to the browser.
 */
if (!function_exists('evr_dispatch_lazily')) {
    function evr_dispatch_lazily(): void
    {
        static $queued = false;
        if ($queued) {
            return;
        }
        $queued = true;

        $stamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims-event-reminders-' . md5(__DIR__) . '.stamp';
        $last = @filemtime($stamp);
        if ($last !== false && time() - $last < 60) {
            return;
        }
        if (@touch($stamp) === false) {
            return;
        }

        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                if (function_exists('db_connect')) {
                    evr_dispatch(db_connect(), 100);
                }
            } catch (Throwable $e) {
                error_log('evr_dispatch_lazily: ' . $e->getMessage());
            }
        });
    }
}
