<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| EVENT STAFF INVITES
|--------------------------------------------------------------------------
| Staff invited to a hub event are stored in hub_event_staff. They receive a
| calendar invite in one of two ways:
|   - Google Calendar connected: they are added as attendees on the Google
|     event (see gcal_sync_hub_event) and Google emails the invite.
|   - Otherwise (or if the Google sync fails / invitations are switched off):
|     an email with an .ics invite, which Gmail, Outlook and Apple Calendar
|     show with Accept/Decline and add to the calendar.
*/

if (!function_exists('ev_staff_ensure_table')) {
    function ev_staff_ensure_table(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            $ready = (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS hub_event_staff (
                    event_id INT NOT NULL,
                    user_id INT NOT NULL,
                    invited_by INT NULL,
                    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (event_id, user_id),
                    KEY idx_hub_event_staff_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('hub_event_staff table unavailable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

/** Active staff accounts that can be invited (must have an email address). */
if (!function_exists('ev_staff_options')) {
    function ev_staff_options(mysqli $conn): array
    {
        $staff = [];
        $result = $conn->query("
            SELECT user_id, full_name, email, role
            FROM users
            WHERE is_active = 1
              AND role NOT IN ('Member', 'Applicant', 'Donor/Partner')
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY full_name
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $staff[] = $row;
            }
            $result->close();
        }

        return $staff;
    }
}

/** User ids posted from the staff picker (staff_ids[]). */
if (!function_exists('ev_staff_posted_ids')) {
    function ev_staff_posted_ids(): array
    {
        $raw = $_POST['staff_ids'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $ids = array_filter(array_map('intval', $raw), static fn(int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }
}

/** Invited staff for one event: [user_id => ['user_id', 'full_name', 'email']]. */
if (!function_exists('ev_staff_for_event')) {
    function ev_staff_for_event(mysqli $conn, int $eventId): array
    {
        if (!ev_staff_ensure_table($conn)) {
            return [];
        }

        $staff = [];
        $stmt = $conn->prepare("
            SELECT u.user_id, u.full_name, u.email
            FROM hub_event_staff s
            JOIN users u ON u.user_id = s.user_id
            WHERE s.event_id = ?
            ORDER BY u.full_name
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $staff[(int)$row['user_id']] = $row;
        }

        $stmt->close();

        return $staff;
    }
}

/** Invited staff ids for many events at once: [event_id => [user_id, ...]]. */
if (!function_exists('ev_staff_map')) {
    function ev_staff_map(mysqli $conn, array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));

        if (!$eventIds || !ev_staff_ensure_table($conn)) {
            return [];
        }

        $map = [];
        $result = $conn->query(
            'SELECT event_id, user_id FROM hub_event_staff WHERE event_id IN (' . implode(',', $eventIds) . ')'
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $map[(int)$row['event_id']][] = (int)$row['user_id'];
            }
            $result->close();
        }

        return $map;
    }
}

/**
 * Replace the invited staff for an event with $userIds (only active staff are
 * kept). Returns ['added' => rows, 'removed' => rows, 'current' => rows].
 */
if (!function_exists('ev_staff_save')) {
    function ev_staff_save(mysqli $conn, int $eventId, array $userIds, int $invitedBy = 0): array
    {
        $out = ['added' => [], 'removed' => [], 'current' => []];

        if ($eventId <= 0 || !ev_staff_ensure_table($conn)) {
            return $out;
        }

        $valid = [];
        foreach (ev_staff_options($conn) as $row) {
            $valid[(int)$row['user_id']] = $row;
        }

        $wanted = [];
        foreach ($userIds as $id) {
            if (isset($valid[(int)$id])) {
                $wanted[(int)$id] = $valid[(int)$id];
            }
        }

        $existing = ev_staff_for_event($conn, $eventId);

        $removedIds = array_diff_key($existing, $wanted);
        $addedIds = array_diff_key($wanted, $existing);

        if ($removedIds) {
            $conn->query(
                'DELETE FROM hub_event_staff WHERE event_id = ' . $eventId
                . ' AND user_id IN (' . implode(',', array_map('intval', array_keys($removedIds))) . ')'
            );
        }

        if ($addedIds) {
            $stmt = $conn->prepare('INSERT IGNORE INTO hub_event_staff (event_id, user_id, invited_by) VALUES (?, ?, NULLIF(?, 0))');
            if ($stmt) {
                foreach (array_keys($addedIds) as $uid) {
                    $stmt->bind_param('iii', $eventId, $uid, $invitedBy);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }

        $out['added'] = array_values($addedIds);
        $out['removed'] = array_values($removedIds);
        $out['current'] = array_values($wanted);

        return $out;
    }
}

if (!function_exists('ev_staff_delete_all')) {
    function ev_staff_delete_all(mysqli $conn, int $eventId): void
    {
        if ($eventId > 0 && ev_staff_ensure_table($conn)) {
            $conn->query('DELETE FROM hub_event_staff WHERE event_id = ' . $eventId);
        }
    }
}

/** Google Calendar attendee list for an event. */
if (!function_exists('ev_staff_google_attendees')) {
    function ev_staff_google_attendees(mysqli $conn, int $eventId): array
    {
        $attendees = [];

        foreach (ev_staff_for_event($conn, $eventId) as $row) {
            $attendees[] = [
                'email' => (string)$row['email'],
                'displayName' => (string)$row['full_name'],
            ];
        }

        return $attendees;
    }
}

/* ---------------------------------------------------------------------
   .ics invites (used when Google Calendar does not send them)
   --------------------------------------------------------------------- */

if (!function_exists('ev_ics_escape')) {
    function ev_ics_escape(string $value): string
    {
        $value = str_replace(["\\", ';', ',', "\r\n", "\r", "\n"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $value);

        return $value;
    }
}

/** Parameter values (e.g. CN) are quoted, so only strip quotes and line breaks. */
if (!function_exists('ev_ics_param')) {
    function ev_ics_param(string $value): string
    {
        return trim(str_replace(['"', "", "
"], ["'", ' ', ' '], $value));
    }
}

/** Fold long lines at 75 octets as RFC 5545 requires. */
if (!function_exists('ev_ics_fold')) {
    function ev_ics_fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $chunk = '';

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line) as $char) {
            if (strlen($chunk . $char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $chunk;
                $chunk = '';
            }
            $chunk .= $char;
        }

        return $out . ($out === '' ? '' : "\r\n ") . $chunk;
    }
}

if (!function_exists('ev_event_days')) {
    function ev_event_days(array $event): array
    {
        $days = json_decode((string)($event['event_days'] ?? ''), true);
        $days = is_array($days) ? array_values(array_filter(array_map('strval', $days))) : [];

        if (!$days && !empty($event['event_date'])) {
            $days = [(string)$event['event_date']];
        }

        sort($days);

        return $days;
    }
}

/**
 * Build a VCALENDAR invite. $method is REQUEST (new/updated) or CANCEL.
 * Each event day becomes its own VEVENT so multi-day events land on every day.
 */
if (!function_exists('ev_build_ics')) {
    function ev_build_ics(array $event, array $staff, string $method, string $organizerEmail, string $organizerName, string $timezone): string
    {
        $eventId = (int)($event['event_id'] ?? 0);
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'ims.local')) ?: 'ims.local';
        $sequence = time() - 1700000000; // grows on every send so calendars apply updates
        $stamp = gmdate('Ymd\THis\Z');
        $cancelled = $method === 'CANCEL' || strcasecmp((string)($event['event_status'] ?? ''), 'Cancelled') === 0;

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Africa/Kampala');
        }

        $start = trim((string)($event['start_time'] ?? ''));
        $end = trim((string)($event['end_time'] ?? ''));

        $description = trim((string)($event['description'] ?? ''));
        if (!empty($event['organizer'])) {
            $description = trim('Organiser: ' . $event['organizer'] . "\n\n" . $description);
        }
        if (!empty($event['meeting_link'])) {
            $description .= "\n\nJoin: " . $event['meeting_link'];
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//IMS//Hub Events//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:' . ($cancelled ? 'CANCEL' : 'REQUEST'),
        ];

        foreach (ev_event_days($event) as $i => $day) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:hub-event-' . $eventId . '-' . $day . '@' . $host;
            $lines[] = 'SEQUENCE:' . $sequence;
            $lines[] = 'DTSTAMP:' . $stamp;

            if ($start !== '') {
                $s = new DateTimeImmutable($day . ' ' . substr($start, 0, 5), $tz);
                $e = $end !== '' ? new DateTimeImmutable($day . ' ' . substr($end, 0, 5), $tz) : $s->modify('+1 hour');
                if ($e <= $s) {
                    $e = $s->modify('+1 hour');
                }
                $lines[] = 'DTSTART:' . $s->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                $lines[] = 'DTEND:' . $e->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            } else {
                $d = new DateTimeImmutable($day);
                $lines[] = 'DTSTART;VALUE=DATE:' . $d->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:' . $d->modify('+1 day')->format('Ymd');
            }

            $lines[] = 'SUMMARY:' . ev_ics_escape((string)($event['event_title'] ?? 'Event'));
            if (!empty($event['venue'])) {
                $lines[] = 'LOCATION:' . ev_ics_escape((string)$event['venue']);
            }
            if ($description !== '') {
                $lines[] = 'DESCRIPTION:' . ev_ics_escape($description);
            }
            $lines[] = 'ORGANIZER;CN="' . ev_ics_param($organizerName) . '":mailto:' . $organizerEmail;

            foreach ($staff as $person) {
                $lines[] = 'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN='
                    . '"' . ev_ics_param((string)$person['full_name']) . '":mailto:' . $person['email'];
            }

            $lines[] = 'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'TRANSP:OPAQUE';

            if (!$cancelled) {
                $lines[] = 'BEGIN:VALARM';
                $lines[] = 'ACTION:DISPLAY';
                $lines[] = 'DESCRIPTION:Reminder';
                $lines[] = 'TRIGGER:-PT30M';
                $lines[] = 'END:VALARM';
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map('ev_ics_fold', $lines)) . "\r\n";
    }
}

/**
 * Email each person in $staff an .ics invite (or cancellation).
 * Returns the number of emails sent.
 */
if (!function_exists('ev_staff_email_invites')) {
    function ev_staff_email_invites(mysqli $conn, int $eventId, array $staff, string $method = 'REQUEST', ?array $event = null): int
    {
        if (!$staff) {
            return 0;
        }

        if ($event === null) {
            $stmt = $conn->prepare('SELECT * FROM hub_events WHERE event_id = ? LIMIT 1');
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('i', $eventId);
            $stmt->execute();
            $event = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }

        if (!$event) {
            return 0;
        }

        require_once __DIR__ . '/mail-function.php';

        $settings = function_exists('getEmailSettings') ? (getEmailSettings() ?: []) : [];
        $fromEmail = trim((string)($settings['from_email'] ?? ''));
        $fromName = trim((string)($settings['from_name'] ?? '')) ?: (string)($GLOBALS['IMS_SITE_BRANDING']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : 'IMS'));

        if ($fromEmail === '') {
            error_log('Event invites not sent: no from_email in email settings.');
            return 0;
        }

        $timezone = trim((string)($event['timezone'] ?? ''));
        if ($timezone === '' && function_exists('gcal_setting')) {
            $timezone = gcal_setting($conn, 'google_calendar_timezone', 'Africa/Kampala');
        }
        if ($timezone === '') {
            $timezone = 'Africa/Kampala';
        }

        $cancel = $method === 'CANCEL' || strcasecmp((string)($event['event_status'] ?? ''), 'Cancelled') === 0;
        $ics = ev_build_ics($event, $staff, $cancel ? 'CANCEL' : 'REQUEST', $fromEmail, $fromName, $timezone);

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims-ics-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            error_log('Event invites not sent: could not create temp directory.');
            return 0;
        }
        $file = $dir . DIRECTORY_SEPARATOR . ($cancel ? 'cancel.ics' : 'invite.ics');
        file_put_contents($file, $ics);

        $title = (string)($event['event_title'] ?? 'Event');
        $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $days = ev_event_days($event);
        $dayText = implode(', ', array_map(static fn($d) => date('D j M Y', strtotime($d)), $days));
        $timeText = !empty($event['start_time'])
            ? substr((string)$event['start_time'], 0, 5) . (!empty($event['end_time']) ? ' - ' . substr((string)$event['end_time'], 0, 5) : '')
            : 'All day';

        $subject = ($cancel ? 'Cancelled: ' : 'Invitation: ') . $title . ($days ? ' (' . date('j M Y', strtotime($days[0])) . ')' : '');

        $sent = 0;
        foreach ($staff as $person) {
            $content = '<p>Hello ' . $h($person['full_name']) . ',</p>'
                . ($cancel
                    ? '<p>The following event has been <strong>cancelled</strong> or you are no longer invited:</p>'
                    : '<p>You have been invited to the following event. Open the attached invite to add it to your calendar.</p>')
                . '<table cellpadding="6" style="border-collapse:collapse">'
                . '<tr><td><strong>Event</strong></td><td>' . $h($title) . '</td></tr>'
                . '<tr><td><strong>Date(s)</strong></td><td>' . $h($dayText) . '</td></tr>'
                . '<tr><td><strong>Time</strong></td><td>' . $h($timeText) . ' (' . $h($timezone) . ')</td></tr>'
                . (!empty($event['venue']) ? '<tr><td><strong>Venue</strong></td><td>' . $h($event['venue']) . '</td></tr>' : '')
                . (!empty($event['meeting_link']) ? '<tr><td><strong>Join</strong></td><td><a href="' . $h($event['meeting_link']) . '">' . $h($event['meeting_link']) . '</a></td></tr>' : '')
                . '</table>'
                . (!empty($event['description']) && !$cancel ? '<p>' . nl2br($h($event['description'])) . '</p>' : '');

            $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
            $result = sendEmail((string)$person['email'], $subject, $body, strip_tags(str_replace(['</p>', '</tr>'], "\n", $content)), [], [], [$file]);

            if ($result === true) {
                $sent++;
            } else {
                error_log('Event invite to ' . $person['email'] . ' failed: ' . (is_string($result) ? $result : 'unknown error'));
            }
        }

        @unlink($file);
        @rmdir($dir);

        return $sent;
    }
}

/** In-app notification for newly invited staff. */
if (!function_exists('ev_staff_notify')) {
    function ev_staff_notify(array $staff, int $eventId, string $title, string $message): void
    {
        if (!function_exists('notify_user')) {
            return;
        }

        foreach ($staff as $person) {
            notify_user((int)$person['user_id'], $title, $message, 'info', $eventId, 'hub_event');
        }
    }
}
