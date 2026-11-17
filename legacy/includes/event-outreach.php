<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| EVENT OUTREACH: INVITATIONS AND ATTENDANCE APPRECIATION
|--------------------------------------------------------------------------
| Staff invites (hub_event_staff) only reach IMS staff. This module reaches
| everyone else:
|   - Invitations: startups (applications), members, participants
|     (beneficiaries), people who registered for earlier events, and any
|     email addresses typed in. Each person gets an email with the event
|     details, the register / join link and an .ics calendar file. Sends are
|     logged in hub_event_invites (one row per event + email) so a repeat
|     send skips people already invited unless "send again" is ticked.
|   - Appreciation: a thank-you email to everyone marked Attended (optionally
|     also Confirmed) for the event, one email per person even for
|     multi-day events. event_registrations.appreciation_sent_at records it
|     so a second run only reaches people not yet thanked.
|
| Messages accept {name}, {event} and {date} placeholders.
| Schema mirror: database/migrations/2026_11_17_event_outreach.sql
*/

require_once __DIR__ . '/event-staff.php';   // ev_build_ics, ev_event_days
require_once __DIR__ . '/event-links.php';   // ev_has_column, ev_participant_link_html

/** Largest number of emails one click sends (keeps a request inside PHP limits). */
const EVO_MAX_SEND = 500;

/** Audiences that can be invited. */
const EVO_AUDIENCES = [
    'startups' => 'Startups',
    'members' => 'Members',
    'participants' => 'Participants',
    'past' => 'Past event attendees',
    'manual' => 'Other email addresses',
];

if (!function_exists('evo_ensure_schema')) {
    function evo_ensure_schema(mysqli $conn): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }

        $done = true;
        try {
            $ok = $conn->query("
                CREATE TABLE IF NOT EXISTS hub_event_invites (
                    invite_id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id INT NOT NULL,
                    audience VARCHAR(30) NOT NULL,
                    source_id INT NULL,
                    full_name VARCHAR(255) NULL,
                    email VARCHAR(255) NOT NULL,
                    organization VARCHAR(255) NULL,
                    invited_by INT NULL,
                    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_sent_at DATETIME NULL,
                    send_count INT NOT NULL DEFAULT 0,
                    UNIQUE KEY uq_hub_event_invites (event_id, email),
                    KEY idx_hub_event_invites_event (event_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            if (!$ok) {
                $done = false;
            }
        } catch (Throwable $e) {
            error_log('event-outreach: hub_event_invites unavailable: ' . $e->getMessage());
            $done = false;
        }

        if (!ev_has_column($conn, 'event_registrations', 'appreciation_sent_at')) {
            try {
                $conn->query('ALTER TABLE event_registrations ADD COLUMN appreciation_sent_at DATETIME NULL');
            } catch (Throwable $e) {
                error_log('event-outreach: could not add event_registrations.appreciation_sent_at: ' . $e->getMessage());
            }
            if (!ev_has_column($conn, 'event_registrations', 'appreciation_sent_at', true)) {
                $done = false;
            }
        }

        return $done;
    }
}

if (!function_exists('evo_find_event')) {
    function evo_find_event(mysqli $conn, int $eventId): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM hub_events WHERE event_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $event;
    }
}

/** Run a prepared SELECT and return all rows (empty on any failure). */
if (!function_exists('evo_rows')) {
    function evo_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            return $rows;
        } catch (Throwable $e) {
            error_log('event-outreach query failed: ' . $e->getMessage());
            return [];
        }
    }
}

/* ---------------------------------------------------------------------
   Filter options for the page
   --------------------------------------------------------------------- */

if (!function_exists('evo_startup_statuses')) {
    function evo_startup_statuses(mysqli $conn): array
    {
        return array_column(evo_rows($conn, "
            SELECT DISTINCT status FROM applications
            WHERE status IS NOT NULL AND status <> '' AND status <> 'Draft'
            ORDER BY status
        "), 'status');
    }
}

if (!function_exists('evo_opportunities')) {
    function evo_opportunities(mysqli $conn): array
    {
        return evo_rows($conn, "
            SELECT o.opportunity_id, o.opportunity_title, COUNT(a.application_id) AS applications
            FROM application_opportunities o
            JOIN applications a ON a.opportunity_id = o.opportunity_id AND a.status <> 'Draft'
            GROUP BY o.opportunity_id, o.opportunity_title
            ORDER BY o.opportunity_id DESC
            LIMIT 200
        ");
    }
}

/** Other events that have registrations, newest first. */
if (!function_exists('evo_past_events')) {
    function evo_past_events(mysqli $conn, int $eventId): array
    {
        return evo_rows($conn, "
            SELECT e.event_id, e.event_title, e.event_date,
                   COUNT(r.registration_id) AS registrations,
                   SUM(r.registration_status = 'Attended') AS attended
            FROM hub_events e
            JOIN event_registrations r ON r.event_id = e.event_id
            WHERE e.event_id <> ?
            GROUP BY e.event_id, e.event_title, e.event_date
            ORDER BY e.event_date DESC
            LIMIT 200
        ", 'i', [$eventId]);
    }
}

/* ---------------------------------------------------------------------
   Invitation recipients
   --------------------------------------------------------------------- */

/** Normalised recipient row. */
if (!function_exists('evo_person')) {
    function evo_person(string $audience, int $sourceId, string $name, string $email, string $organization = ''): ?array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return [
            'audience' => $audience,
            'source_id' => $sourceId,
            'name' => $name !== '' ? mb_substr($name, 0, 255) : $email,
            'email' => mb_substr($email, 0, 255),
            'organization' => mb_substr(trim($organization), 0, 255),
        ];
    }
}

/**
 * "Name <email>", "email, Name", "Name, email" or a bare email, one per line
 * (commas or semicolons between bare emails also work).
 */
if (!function_exists('evo_parse_manual')) {
    function evo_parse_manual(string $text): array
    {
        $people = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(.*)<\s*([^>]+)\s*>\s*$/', $line, $m)) {
                if ($p = evo_person('manual', 0, trim($m[1], " \t\"',;"), $m[2])) {
                    $people[] = $p;
                }
                continue;
            }
            $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\t]/', $line) ?: []), 'strlen'));
            $emails = array_values(array_filter($parts, static fn($p) => filter_var($p, FILTER_VALIDATE_EMAIL)));
            if (count($emails) === 1) {
                $name = implode(' ', array_filter($parts, static fn($p) => !filter_var($p, FILTER_VALIDATE_EMAIL)));
                if ($p = evo_person('manual', 0, $name, $emails[0])) {
                    $people[] = $p;
                }
            } else {
                foreach ($emails as $email) {
                    if ($p = evo_person('manual', 0, '', $email)) {
                        $people[] = $p;
                    }
                }
            }
        }

        return $people;
    }
}

/**
 * Everyone selected on the invite form, de-duplicated by email.
 * $opts: audiences[], startup_status, opportunity_id, past_event_ids[],
 *        past_attended_only, manual, resend
 * Returns ['people' => [...], 'skipped_registered' => n, 'skipped_invited' => n].
 */
if (!function_exists('evo_invite_recipients')) {
    function evo_invite_recipients(mysqli $conn, int $eventId, array $opts): array
    {
        $audiences = array_intersect(array_keys(EVO_AUDIENCES), (array)($opts['audiences'] ?? []));
        $found = [];

        if (in_array('startups', $audiences, true)) {
            $sql = "SELECT application_id, startup_name, contact_person, email
                    FROM applications
                    WHERE status <> 'Draft' AND COALESCE(email, '') <> ''";
            $types = '';
            $params = [];
            if (($opts['startup_status'] ?? '') !== '') {
                $sql .= ' AND status = ?';
                $types .= 's';
                $params[] = (string)$opts['startup_status'];
            }
            if ((int)($opts['opportunity_id'] ?? 0) > 0) {
                $sql .= ' AND opportunity_id = ?';
                $types .= 'i';
                $params[] = (int)$opts['opportunity_id'];
            }
            foreach (evo_rows($conn, $sql . ' ORDER BY startup_name', $types, $params) as $r) {
                $name = trim((string)($r['contact_person'] ?? '')) ?: (string)($r['startup_name'] ?? '');
                $found[] = evo_person('startups', (int)$r['application_id'], $name, (string)$r['email'], (string)($r['startup_name'] ?? ''));
            }
        }

        if (in_array('members', $audiences, true)) {
            foreach (evo_rows($conn, "
                SELECT m.member_id, m.company_name, u.full_name,
                       COALESCE(NULLIF(m.email, ''), u.email) AS email
                FROM members m
                LEFT JOIN users u ON u.user_id = m.user_id
                WHERE m.membership_status = 'Active'
                ORDER BY m.company_name
            ") as $r) {
                $name = trim((string)($r['full_name'] ?? '')) ?: (string)($r['company_name'] ?? '');
                $found[] = evo_person('members', (int)$r['member_id'], $name, (string)($r['email'] ?? ''), (string)($r['company_name'] ?? ''));
            }
        }

        if (in_array('participants', $audiences, true)) {
            foreach (evo_rows($conn, "
                SELECT beneficiary_id, first_name, last_name, email
                FROM beneficiaries
                WHERE COALESCE(email, '') <> ''
                ORDER BY first_name, last_name
            ") as $r) {
                $found[] = evo_person('participants', (int)$r['beneficiary_id'], trim($r['first_name'] . ' ' . $r['last_name']), (string)$r['email']);
            }
        }

        if (in_array('past', $audiences, true)) {
            $ids = array_values(array_filter(array_map('intval', (array)($opts['past_event_ids'] ?? [])), static fn($id) => $id > 0 && $id !== $eventId));
            $sql = "SELECT registration_id, attendee_name, email, organization
                    FROM event_registrations
                    WHERE event_id <> ? AND COALESCE(email, '') <> '' AND registration_status <> 'Cancelled'";
            $types = 'i';
            $params = [$eventId];
            if ($ids) {
                $sql .= ' AND event_id IN (' . implode(',', $ids) . ')';
            }
            if (!empty($opts['past_attended_only'])) {
                $sql .= " AND registration_status = 'Attended'";
            }
            foreach (evo_rows($conn, $sql . ' ORDER BY registration_id DESC', $types, $params) as $r) {
                $found[] = evo_person('past', (int)$r['registration_id'], (string)$r['attendee_name'], (string)$r['email'], (string)($r['organization'] ?? ''));
            }
        }

        if (in_array('manual', $audiences, true)) {
            array_push($found, ...evo_parse_manual((string)($opts['manual'] ?? '')));
        }

        // One row per email; the first audience that found the person wins.
        $people = [];
        foreach ($found as $p) {
            if ($p && !isset($people[$p['email']])) {
                $people[$p['email']] = $p;
            }
        }

        $registered = array_flip(array_map('strtolower', array_column(evo_rows($conn,
            "SELECT DISTINCT email FROM event_registrations WHERE event_id = ? AND COALESCE(email, '') <> ''", 'i', [$eventId]), 'email')));
        $invited = array_flip(array_map('strtolower', array_column(evo_rows($conn,
            'SELECT email FROM hub_event_invites WHERE event_id = ?', 'i', [$eventId]), 'email')));

        $out = ['people' => [], 'skipped_registered' => 0, 'skipped_invited' => 0];
        foreach ($people as $email => $p) {
            if (isset($registered[$email])) {
                $out['skipped_registered']++;
                continue;
            }
            if (isset($invited[$email]) && empty($opts['resend'])) {
                $out['skipped_invited']++;
                continue;
            }
            $out['people'][] = $p;
        }

        return $out;
    }
}

/* ---------------------------------------------------------------------
   Sending
   --------------------------------------------------------------------- */

if (!function_exists('evo_from')) {
    /** [fromEmail, fromName] from the email settings. */
    function evo_from(): array
    {
        require_once __DIR__ . '/mail-function.php';
        $settings = function_exists('getEmailSettings') ? (getEmailSettings() ?: []) : [];
        $fromEmail = trim((string)($settings['from_email'] ?? ''));
        $fromName = trim((string)($settings['from_name'] ?? ''))
            ?: (function_exists('ims_brand_name') ? ims_brand_name() : (defined('SITE_NAME') ? SITE_NAME : 'IMS'));

        return [$fromEmail, $fromName];
    }
}

if (!function_exists('evo_when_text')) {
    function evo_when_text(array $event): string
    {
        $days = ev_event_days($event);
        $text = implode(', ', array_map(static fn($d) => date('D j M Y', strtotime($d)), $days));
        if (!empty($event['start_time'])) {
            $text .= ', ' . substr((string)$event['start_time'], 0, 5)
                . (!empty($event['end_time']) ? ' - ' . substr((string)$event['end_time'], 0, 5) : '');
        }

        return $text;
    }
}

/** Replace {name}, {event} and {date} in a staff-written text. */
if (!function_exists('evo_fill')) {
    function evo_fill(string $text, array $event, string $name): string
    {
        $days = ev_event_days($event);

        return strtr($text, [
            '{name}' => $name,
            '{event}' => (string)($event['event_title'] ?? ''),
            '{date}' => $days ? date('j F Y', strtotime($days[0])) : '',
        ]);
    }
}

/** Staff-written message as safe HTML paragraphs. */
if (!function_exists('evo_message_html')) {
    function evo_message_html(string $text): string
    {
        $out = '';
        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $para) {
            if (trim($para) !== '') {
                $out .= '<p>' . nl2br(htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8')) . '</p>';
            }
        }

        return $out;
    }
}

/**
 * Email invitations with a calendar file and log them.
 * Returns ['sent' => n, 'failed' => n, 'error' => ''].
 */
if (!function_exists('evo_send_invites')) {
    function evo_send_invites(mysqli $conn, array $event, array $people, string $subject, string $message, int $userId): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'error' => ''];
        [$fromEmail, $fromName] = evo_from();
        if ($fromEmail === '' || !function_exists('sendEmail')) {
            $out['error'] = 'Email is not set up: add a sender address in the email settings first.';
            return $out;
        }

        $timezone = trim((string)($event['timezone'] ?? ''));
        if ($timezone === '' && function_exists('gcal_setting')) {
            $timezone = (string)gcal_setting($conn, 'google_calendar_timezone', 'Africa/Kampala');
        }
        $timezone = $timezone !== '' ? $timezone : 'Africa/Kampala';

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims-invite-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            $dir = '';
        }

        $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $eventId = (int)$event['event_id'];
        $details = '<table cellpadding="6" style="border-collapse:collapse">'
            . '<tr><td><strong>Event</strong></td><td>' . $h($event['event_title'] ?? '') . '</td></tr>'
            . '<tr><td><strong>When</strong></td><td>' . $h(evo_when_text($event)) . '</td></tr>'
            . (!empty($event['venue']) ? '<tr><td><strong>Venue</strong></td><td>' . $h($event['venue']) . '</td></tr>' : '')
            . (!empty($event['organizer']) ? '<tr><td><strong>Organiser</strong></td><td>' . $h($event['organizer']) . '</td></tr>' : '')
            . '</table>';
        $linkHtml = ev_participant_link_html($event);
        $linkText = ev_participant_link_text($event);

        $log = $conn->prepare('
            INSERT INTO hub_event_invites (event_id, audience, source_id, full_name, email, organization, invited_by, last_sent_at, send_count)
            VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, NULLIF(?, 0), NOW(), 1)
            ON DUPLICATE KEY UPDATE last_sent_at = NOW(), send_count = send_count + 1,
                full_name = VALUES(full_name), organization = VALUES(organization), invited_by = VALUES(invited_by)
        ');

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        foreach (array_slice($people, 0, EVO_MAX_SEND) as $p) {
            $attachments = [];
            if ($dir !== '') {
                $file = $dir . DIRECTORY_SEPARATOR . 'invite.ics';
                file_put_contents($file, ev_build_ics($event, [['full_name' => $p['name'], 'email' => $p['email']]], 'REQUEST', $fromEmail, $fromName, $timezone));
                $attachments[] = $file;
            }

            $content = '<p>Dear ' . $h($p['name']) . ',</p>'
                . evo_message_html(evo_fill($message, $event, $p['name']))
                . $details . $linkHtml
                . (!empty($event['description']) ? '<p>' . nl2br($h($event['description'])) . '</p>' : '')
                . ($attachments ? '<p style="color:#6b7280;font-size:12px">The attached invite adds the event to your calendar.</p>' : '');
            $text = 'Dear ' . $p['name'] . ",\n\n" . evo_fill($message, $event, $p['name']) . "\n\n"
                . ($event['event_title'] ?? '') . "\nWhen: " . evo_when_text($event)
                . (!empty($event['venue']) ? "\nVenue: " . $event['venue'] : '') . ($linkText !== '' ? "\n\n" . $linkText : '');
            $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

            try {
                $result = sendEmail($p['email'], evo_fill($subject, $event, $p['name']), $body, $text, [], [], $attachments);
            } catch (Throwable $e) {
                $result = $e->getMessage();
            }

            if ($result === true) {
                $out['sent']++;
                if ($log) {
                    $aud = $p['audience'];
                    $src = (int)$p['source_id'];
                    $log->bind_param('isisssi', $eventId, $aud, $src, $p['name'], $p['email'], $p['organization'], $userId);
                    $log->execute();
                }
            } else {
                $out['failed']++;
                error_log('Event invitation to ' . $p['email'] . ' failed: ' . (is_string($result) ? $result : 'unknown error'));
            }
        }

        if ($log) {
            $log->close();
        }
        if ($dir !== '') {
            @unlink($dir . DIRECTORY_SEPARATOR . 'invite.ics');
            @rmdir($dir);
        }

        return $out;
    }
}

/** Invitations already sent for an event, newest first, with whether each person registered. */
if (!function_exists('evo_invite_log')) {
    function evo_invite_log(mysqli $conn, int $eventId): array
    {
        return evo_rows($conn, "
            SELECT i.*, EXISTS(
                SELECT 1 FROM event_registrations r
                WHERE r.event_id = i.event_id AND LOWER(r.email) = LOWER(i.email) AND r.registration_status <> 'Cancelled'
            ) AS registered
            FROM hub_event_invites i
            WHERE i.event_id = ?
            ORDER BY i.last_sent_at DESC, i.invite_id DESC
        ", 'i', [$eventId]);
    }
}

/* ---------------------------------------------------------------------
   Attendance appreciation
   --------------------------------------------------------------------- */

/**
 * Attendees to thank, one row per email (multi-day events register once per
 * day). $includeConfirmed adds people who said they are attending but were
 * never marked Attended; $includeThanked adds people already thanked.
 */
if (!function_exists('evo_appreciation_recipients')) {
    function evo_appreciation_recipients(mysqli $conn, int $eventId, bool $includeConfirmed, bool $includeThanked): array
    {
        $statuses = $includeConfirmed ? "('Attended', 'Confirmed')" : "('Attended')";
        $rows = evo_rows($conn, "
            SELECT registration_id, attendee_name, email, organization, registration_status, appreciation_sent_at
            FROM event_registrations
            WHERE event_id = ? AND registration_status IN {$statuses} AND COALESCE(email, '') <> ''
            ORDER BY attendee_name, registration_id
        ", 'i', [$eventId]);

        $people = [];
        foreach ($rows as $r) {
            $email = strtolower(trim((string)$r['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!isset($people[$email])) {
                $people[$email] = [
                    'name' => trim((string)$r['attendee_name']) ?: $email,
                    'email' => $email,
                    'organization' => (string)($r['organization'] ?? ''),
                    'status' => (string)$r['registration_status'],
                    'thanked_at' => (string)($r['appreciation_sent_at'] ?? ''),
                    'registration_ids' => [],
                ];
            }
            $people[$email]['registration_ids'][] = (int)$r['registration_id'];
            if (!empty($r['appreciation_sent_at'])) {
                $people[$email]['thanked_at'] = max($people[$email]['thanked_at'], (string)$r['appreciation_sent_at']);
            }
            if ($r['registration_status'] === 'Attended') {
                $people[$email]['status'] = 'Attended';
            }
        }

        if (!$includeThanked) {
            $people = array_filter($people, static fn($p) => $p['thanked_at'] === '');
        }

        return array_values($people);
    }
}

/**
 * Send thank-you emails and stamp appreciation_sent_at.
 * Returns ['sent' => n, 'failed' => n, 'error' => ''].
 */
if (!function_exists('evo_send_appreciation')) {
    function evo_send_appreciation(mysqli $conn, array $event, array $people, string $subject, string $message, string $linkUrl, string $linkLabel): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'error' => ''];
        [$fromEmail] = evo_from();
        if ($fromEmail === '' || !function_exists('sendEmail')) {
            $out['error'] = 'Email is not set up: add a sender address in the email settings first.';
            return $out;
        }

        $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $button = '';
        if ($linkUrl !== '') {
            $button = '<p style="margin:18px 0"><a href="' . $h($linkUrl) . '" style="display:inline-block;padding:10px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">'
                . $h($linkLabel !== '' ? $linkLabel : 'Open link') . '</a></p>'
                . '<p style="margin:0;font-size:13px;color:#555;word-break:break-all;"><a href="' . $h($linkUrl) . '">' . $h($linkUrl) . '</a></p>';
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        foreach (array_slice($people, 0, EVO_MAX_SEND) as $p) {
            $content = '<p>Dear ' . $h($p['name']) . ',</p>' . evo_message_html(evo_fill($message, $event, $p['name'])) . $button;
            $text = 'Dear ' . $p['name'] . ",\n\n" . evo_fill($message, $event, $p['name'])
                . ($linkUrl !== '' ? "\n\n" . ($linkLabel !== '' ? $linkLabel . ': ' : '') . $linkUrl : '');
            $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

            try {
                $result = sendEmail($p['email'], evo_fill($subject, $event, $p['name']), $body, $text);
            } catch (Throwable $e) {
                $result = $e->getMessage();
            }

            if ($result === true) {
                $out['sent']++;
                $ids = implode(',', array_map('intval', $p['registration_ids']));
                if ($ids !== '') {
                    $conn->query('UPDATE event_registrations SET appreciation_sent_at = NOW() WHERE registration_id IN (' . $ids . ')');
                }
            } else {
                $out['failed']++;
                error_log('Event appreciation to ' . $p['email'] . ' failed: ' . (is_string($result) ? $result : 'unknown error'));
            }
        }

        return $out;
    }
}
