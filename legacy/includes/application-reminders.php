<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Draft application reminders
|--------------------------------------------------------------------------
| Emails applicants who have a Draft application on a Published opportunity
| whose deadline has not passed, asking them to finish and submit it.
|
| Automatic (cron/application-draft-reminders.php, once a day):
|   application_reminder_settings.days_before, e.g. "7,3,1" - one email when
|   the deadline is that many days away (0 = on the deadline day). A missed
|   day is caught up with the nearest threshold only, never several at once.
|   Drafts saved in the last 12 hours are left for the next run.
|   --dry-run on the cron script lists what would be sent, sending nothing.
| Manual (Manage Applications): remind every draft of an opportunity, the
|   selected ones, or a single draft, with an optional personal note.
|
| application_reminder_log keeps one row per email; automatic rows carry a
| unique key per application ("before-7") so each is sent at most once.
*/

require_once __DIR__ . '/mail-function.php';

if (!function_exists('apprem_ensure_schema')) {
    function apprem_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS application_reminder_settings (
                id TINYINT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                days_before VARCHAR(60) NOT NULL DEFAULT '7,3,1',
                updated_by INT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT IGNORE INTO application_reminder_settings (id) VALUES (1)",
            "CREATE TABLE IF NOT EXISTS application_reminder_log (
                log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                application_id INT NOT NULL,
                opportunity_id INT NOT NULL,
                reminder_key VARCHAR(80) NOT NULL,
                kind ENUM('auto','manual') NOT NULL DEFAULT 'auto',
                email VARCHAR(190) NULL,
                emailed TINYINT(1) NOT NULL DEFAULT 0,
                error VARCHAR(255) NULL,
                sent_by INT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (log_id),
                UNIQUE KEY uq_app_reminder_key (application_id, reminder_key),
                KEY idx_app_reminder_opp (opportunity_id, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            foreach ($statements as $sql) {
                if (!$conn->query($sql)) {
                    error_log('[application-reminders] schema: ' . $conn->error);
                    return $ready = false;
                }
            }

            return $ready = true;
        } catch (Throwable $e) {
            error_log('[application-reminders] schema check failed: ' . $e->getMessage());
            return $ready = false;
        }
    }
}

if (!function_exists('apprem_parse_days')) {
    /** "7, 3,1" -> [7,3,1] (descending, unique, 0..90). */
    function apprem_parse_days(string $raw): array
    {
        $days = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $d = (int)$part;
            if ($d >= 0 && $d <= 90) {
                $days[$d] = $d;
            }
        }
        rsort($days);

        return array_values($days);
    }
}

if (!function_exists('apprem_settings')) {
    function apprem_settings(mysqli $conn): array
    {
        $row = [];
        try {
            $result = $conn->query('SELECT * FROM application_reminder_settings WHERE id = 1 LIMIT 1');
            $row = $result ? ($result->fetch_assoc() ?: []) : [];
        } catch (Throwable $e) {
            error_log('[application-reminders] settings: ' . $e->getMessage());
        }

        return [
            'enabled' => !array_key_exists('enabled', $row) || !empty($row['enabled']),
            'days_before' => apprem_parse_days((string)($row['days_before'] ?? '7,3,1')),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}

if (!function_exists('apprem_save_settings')) {
    function apprem_save_settings(mysqli $conn, bool $enabled, string $daysRaw, int $userId): bool
    {
        $days = apprem_parse_days($daysRaw);
        if (!$days) {
            return false;
        }
        $daysStr = implode(',', $days);
        $en = $enabled ? 1 : 0;

        $stmt = $conn->prepare('UPDATE application_reminder_settings SET enabled = ?, days_before = ?, updated_by = ?, updated_at = NOW() WHERE id = 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isi', $en, $daysStr, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('apprem_days_left')) {
    function apprem_days_left(string $deadline, ?string $today = null): int
    {
        $end = new DateTimeImmutable(substr($deadline, 0, 10));
        $now = new DateTimeImmutable($today ?? date('Y-m-d'));

        return (int)$now->diff($end)->format('%r%a');
    }
}

if (!function_exists('apprem_threshold_for')) {
    /**
     * The threshold a draft with $daysLeft falls under: the smallest
     * configured day count that is >= $daysLeft, or null when the deadline
     * is further away than every threshold.
     */
    function apprem_threshold_for(array $thresholds, int $daysLeft): ?int
    {
        $match = null;
        foreach ($thresholds as $d) {
            if ($d >= $daysLeft && ($match === null || $d < $match)) {
                $match = $d;
            }
        }

        return $match;
    }
}

if (!function_exists('apprem_fetch_drafts')) {
    /**
     * Open drafts (Published opportunity, deadline today or later).
     * $opportunityId 0 = all opportunities; $ids limits to those applications.
     */
    function apprem_fetch_drafts(mysqli $conn, int $opportunityId = 0, array $ids = []): array
    {
        $where = ["a.status = 'Draft'", "o.status = 'Published'", 'o.deadline >= CURDATE()'];
        $types = '';
        $params = [];

        if ($opportunityId > 0) {
            $where[] = 'a.opportunity_id = ?';
            $types .= 'i';
            $params[] = $opportunityId;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids) {
            $where[] = 'a.application_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $types .= str_repeat('i', count($ids));
            array_push($params, ...$ids);
        }

        $sql = "
            SELECT a.application_id, a.opportunity_id, a.startup_name,
                   COALESCE(NULLIF(TRIM(a.email), ''), u.email) AS email,
                   COALESCE(NULLIF(TRIM(a.contact_person), ''), u.full_name, a.startup_name) AS contact_name,
                   COALESCE(a.last_saved_at, a.updated_at, a.created_at) AS last_saved_at,
                   o.opportunity_title, o.deadline
            FROM applications a
            JOIN application_opportunities o ON o.opportunity_id = a.opportunity_id
            LEFT JOIN users u ON u.user_id = a.submitted_by
            WHERE " . implode(' AND ', $where) . '
            ORDER BY a.application_id';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('[application-reminders] drafts query: ' . $conn->error);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('apprem_email')) {
    /** Send the reminder email. Returns true or an error string. */
    function apprem_email(array $draft, string $note = ''): bool|string
    {
        $email = trim((string)($draft['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'No valid email address on the draft.';
        }

        $brand = email_brand();
        $title = (string)($draft['opportunity_title'] ?? 'the opportunity');
        $startup = trim((string)($draft['startup_name'] ?? ''));
        $name = trim((string)($draft['contact_name'] ?? '')) ?: 'there';
        $deadline = (string)($draft['deadline'] ?? '');
        $daysLeft = $deadline !== '' ? apprem_days_left($deadline) : 0;
        $deadlineText = $deadline !== '' ? date('l, d M Y', strtotime($deadline)) : '';
        $whenText = match (true) {
            $daysLeft <= 0 => 'closes today',
            $daysLeft === 1 => 'closes tomorrow',
            default => "closes in {$daysLeft} days",
        };
        $reference = 'DRAFT-' . str_pad((string)(int)$draft['application_id'], 6, '0', STR_PAD_LEFT);
        $portalUrl = email_app_url() . 'my-applications';
        $draftUrl = email_app_url() . 'submit-application?opportunity_id=' . (int)$draft['opportunity_id'] . '&draft_id=' . (int)$draft['application_id'];

        $subject = "Reminder: your application for {$title} {$whenText}";

        $noteHtml = '';
        if (trim($note) !== '') {
            $noteHtml = "<div class='info-box'>" . nl2br(email_h(trim($note))) . '</div>';
        }

        $content = "
            <h3>Your application is not submitted yet</h3>
            <p>Hi " . email_h($name) . ",</p>
            <p>You started an application for <strong>" . email_h($title) . "</strong>"
            . ($startup !== '' ? ' (' . email_h($startup) . ')' : '') . " but it is still saved as a draft.
               Applications " . email_h($whenText) . ($deadlineText !== '' ? ' (<strong>' . email_h($deadlineText) . '</strong>)' : '') . ",
               and drafts that are not submitted by then will not be considered.</p>
            {$noteHtml}
            <div class='info-box'>
                <div><strong>Draft Reference:</strong> {$reference}</div>
                <div><strong>Opportunity:</strong> " . email_h($title) . "</div>
                " . ($deadlineText !== '' ? '<div><strong>Deadline:</strong> ' . email_h($deadlineText) . '</div>' : '') . "
            </div>
            <p>Log in, open <em>My Applications</em>, complete any remaining fields and click
               <strong>Submit Application</strong>.</p>
            <a href='" . email_h($portalUrl) . "' class='btn'>Continue My Application</a>
            <p style='font-size:13px;color:#7f8c8d;'>Already logged in? Go straight to your draft:
               <a href='" . email_h($draftUrl) . "'>" . email_h($draftUrl) . "</a></p>
            " . email_questions_line() . '
            ' . email_signoff();

        $alt = "Hi {$name},\n\n"
            . "Your application for {$title} is still a draft. Applications {$whenText}"
            . ($deadlineText !== '' ? " ({$deadlineText})" : '') . ".\n"
            . (trim($note) !== '' ? "\n" . trim($note) . "\n\n" : '')
            . "Reference: {$reference}\n"
            . "Log in and submit it from My Applications: {$portalUrl}\n\n"
            . $brand['name'];

        if (!function_exists('sendEmail')) {
            return 'Mail unavailable';
        }
        $result = sendEmail($email, $subject, email_wrapper($content, 'Application reminder'), $alt);

        return $result === true ? true : (is_string($result) && $result !== '' ? $result : 'Email could not be sent.');
    }
}

if (!function_exists('apprem_send')) {
    /**
     * Email one draft and log it. For automatic reminders the log row is
     * claimed first (unique key) so overlapping cron runs never double-send.
     * Returns 'sent', 'failed' or 'skipped'.
     */
    function apprem_send(mysqli $conn, array $draft, string $kind, string $key, int $sentBy = 0, string $note = ''): string
    {
        $appId = (int)$draft['application_id'];
        $oppId = (int)$draft['opportunity_id'];
        $email = substr(trim((string)($draft['email'] ?? '')), 0, 190);
        $by = $sentBy > 0 ? $sentBy : null;

        $ins = $conn->prepare('INSERT IGNORE INTO application_reminder_log (application_id, opportunity_id, reminder_key, kind, email, sent_by) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$ins) {
            error_log('[application-reminders] log insert: ' . $conn->error);
            return 'failed';
        }
        $ins->bind_param('iisssi', $appId, $oppId, $key, $kind, $email, $by);
        $ins->execute();
        $claimed = $ins->affected_rows > 0;
        $logId = (int)$ins->insert_id;
        $ins->close();

        if (!$claimed) {
            return 'skipped';
        }

        $result = apprem_email($draft, $note);
        $ok = $result === true ? 1 : 0;
        $error = $ok ? null : substr((string)$result, 0, 255);

        $up = $conn->prepare('UPDATE application_reminder_log SET emailed = ?, error = ?, sent_at = NOW() WHERE log_id = ?');
        if ($up) {
            $up->bind_param('isi', $ok, $error, $logId);
            $up->execute();
            $up->close();
        }

        if (!$ok && $kind === 'auto') {
            // Free the key so tomorrow's run can retry a failed automatic email.
            $del = $conn->prepare('DELETE FROM application_reminder_log WHERE log_id = ?');
            if ($del) {
                $del->bind_param('i', $logId);
                $del->execute();
                $del->close();
            }
        }

        return $ok ? 'sent' : 'failed';
    }
}

if (!function_exists('apprem_dispatch')) {
    /**
     * Automatic run. Returns ['sent','failed','skipped'=>bool,'plan'=>[]].
     * $dryRun: send and log nothing; 'plan' lists every open draft with what
     * a real run would do ('send', 'already sent', 'saved recently',
     * 'not due yet', 'no email'). A dry run ignores the on/off switch.
     */
    function apprem_dispatch(mysqli $conn, ?string $today = null, bool $dryRun = false): array
    {
        $settings = apprem_settings($conn);
        if (!$dryRun && (!$settings['enabled'] || !$settings['days_before'])) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'plan' => []];
        }

        $sent = 0;
        $failed = 0;
        $plan = [];
        $freshCutoff = time() - 12 * 3600;
        $logged = $dryRun ? apprem_auto_keys($conn) : [];

        foreach (apprem_fetch_drafts($conn) as $draft) {
            $daysLeft = apprem_days_left((string)$draft['deadline'], $today);
            $threshold = apprem_threshold_for($settings['days_before'], $daysLeft);
            $key = $threshold === null ? '' : 'before-' . $threshold;
            // Someone who saved within the last 12 hours is working on it;
            // leave them until the next run (except on the deadline day).
            $saved = !empty($draft['last_saved_at']) ? strtotime((string)$draft['last_saved_at']) : false;
            $fresh = $daysLeft > 0 && $saved !== false && $saved > $freshCutoff;

            if ($dryRun) {
                $action = match (true) {
                    $threshold === null => 'not due yet',
                    isset($logged[(int)$draft['application_id'] . '|' . $key]) => 'already sent',
                    $fresh => 'saved recently',
                    !filter_var(trim((string)($draft['email'] ?? '')), FILTER_VALIDATE_EMAIL) => 'no email',
                    default => 'send',
                };
                $plan[] = $draft + ['days_left' => $daysLeft, 'reminder_key' => $key, 'action' => $action];
                continue;
            }

            if ($threshold === null || $fresh) {
                continue;
            }

            $outcome = apprem_send($conn, $draft, 'auto', $key);
            if ($outcome === 'sent') {
                $sent++;
            } elseif ($outcome === 'failed') {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false, 'plan' => $plan];
    }
}

if (!function_exists('apprem_auto_keys')) {
    /** "application_id|reminder_key" => true for automatic reminders already logged. */
    function apprem_auto_keys(mysqli $conn): array
    {
        $out = [];
        $res = $conn->query("SELECT application_id, reminder_key FROM application_reminder_log WHERE kind = 'auto'");
        while ($res && ($row = $res->fetch_assoc())) {
            $out[(int)$row['application_id'] . '|' . $row['reminder_key']] = true;
        }

        return $out;
    }
}

if (!function_exists('apprem_send_manual')) {
    /**
     * Remind open drafts of one opportunity now ($ids empty = all of them).
     * Drafts reminded in the last hour are skipped to absorb double clicks.
     */
    function apprem_send_manual(mysqli $conn, int $opportunityId, array $ids, int $sentBy, string $note = ''): array
    {
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $recent = apprem_recent_ids($conn, $opportunityId, 3600);

        foreach (apprem_fetch_drafts($conn, $opportunityId, $ids) as $draft) {
            if (isset($recent[(int)$draft['application_id']])) {
                $skipped++;
                continue;
            }
            $key = 'manual-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $outcome = apprem_send($conn, $draft, 'manual', $key, $sentBy, $note);
            if ($outcome === 'sent') {
                $sent++;
            } elseif ($outcome === 'failed') {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }
}

if (!function_exists('apprem_recent_ids')) {
    /** application_id => true for drafts emailed within $seconds. */
    function apprem_recent_ids(mysqli $conn, int $opportunityId, int $seconds): array
    {
        $out = [];
        $stmt = $conn->prepare('SELECT DISTINCT application_id FROM application_reminder_log WHERE opportunity_id = ? AND emailed = 1 AND sent_at >= (NOW() - INTERVAL ? SECOND)');
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('ii', $opportunityId, $seconds);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $out[(int)$row['application_id']] = true;
        }
        $stmt->close();

        return $out;
    }
}

if (!function_exists('apprem_last_reminded')) {
    /** application_id => ['sent_at','count'] of successful reminders for an opportunity. */
    function apprem_last_reminded(mysqli $conn, int $opportunityId): array
    {
        $out = [];
        try {
            $stmt = $conn->prepare('SELECT application_id, MAX(sent_at) AS last_at, COUNT(*) AS n FROM application_reminder_log WHERE opportunity_id = ? AND emailed = 1 GROUP BY application_id');
            if (!$stmt) {
                return $out;
            }
            $stmt->bind_param('i', $opportunityId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $out[(int)$row['application_id']] = ['sent_at' => (string)$row['last_at'], 'count' => (int)$row['n']];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[application-reminders] last reminded: ' . $e->getMessage());
        }

        return $out;
    }
}
