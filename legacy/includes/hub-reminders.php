<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Hub member subscription reminders
|--------------------------------------------------------------------------
| Automatic (cron/hub-member-reminders.php) and manual (Operations/Admin,
| /hub-team) reminders to hub members about their subscription end date.
| Each reminder is an in-app member notification (member_notifications,
| shown on the member dashboard) plus an email to the primary member and,
| optionally, the company's team members.
|
| Automatic schedule (hub_reminder_settings):
|   before  - N days before the end date (e.g. 14,7,3,1). A missed day is
|             caught up with the nearest threshold only, never several at once.
|   expiry  - on the end date
|   overdue - every X days after it ended, at most Y times
| hub_reminder_log keeps one row per automatic reminder key, so each is
| sent once however often the cron runs.
*/

const HUBREM_PLACEHOLDERS = ['{name}', '{company}', '{membership_number}', '{plan}', '{package}', '{end_date}', '{days}', '{status_text}', '{amount}', '{org}'];

if (!function_exists('hubrem_ensure_schema')) {
    function hubrem_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS hub_reminder_settings (
                id TINYINT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                days_before VARCHAR(60) NOT NULL DEFAULT '14,7,3,1',
                on_expiry TINYINT(1) NOT NULL DEFAULT 1,
                overdue_every_days INT NOT NULL DEFAULT 7,
                overdue_max INT NOT NULL DEFAULT 3,
                send_email TINYINT(1) NOT NULL DEFAULT 1,
                include_team TINYINT(1) NOT NULL DEFAULT 0,
                subject VARCHAR(190) NOT NULL DEFAULT 'Your hub subscription {status_text}',
                message TEXT NULL,
                updated_by INT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT IGNORE INTO hub_reminder_settings (id, message) VALUES (1,
                'Hello {name},\n\nYour {plan} subscription ({membership_number}) {status_text} ({end_date}).\nPlease renew to keep your workspace and internet access for {company}.\n\nThank you,\n{org}')",
            "CREATE TABLE IF NOT EXISTS hub_reminder_log (
                log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id INT NOT NULL,
                reminder_key VARCHAR(80) NOT NULL,
                kind ENUM('auto','manual') NOT NULL DEFAULT 'auto',
                subject VARCHAR(190) NULL,
                recipients INT NOT NULL DEFAULT 0,
                emailed INT NOT NULL DEFAULT 0,
                error VARCHAR(255) NULL,
                sent_by INT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (log_id),
                UNIQUE KEY uq_hub_reminder_key (member_id, reminder_key),
                KEY idx_hub_reminder_sent (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            foreach ($statements as $sql) {
                if (!$conn->query($sql)) {
                    error_log('[hub-reminders] schema: ' . $conn->error);
                    return $ready = false;
                }
            }

            return $ready = true;
        } catch (Throwable $e) {
            error_log('[hub-reminders] schema check failed: ' . $e->getMessage());
            return $ready = false;
        }
    }
}

if (!function_exists('hubrem_settings')) {
    function hubrem_settings(mysqli $conn): array
    {
        $result = $conn->query('SELECT * FROM hub_reminder_settings WHERE id = 1 LIMIT 1');
        $row = $result ? ($result->fetch_assoc() ?: []) : [];

        $days = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string)($row['days_before'] ?? '14,7,3,1'))),
            static fn(int $d): bool => $d > 0 && $d <= 365
        )));
        sort($days);

        return [
            'enabled' => !array_key_exists('enabled', $row) || !empty($row['enabled']),
            'days_before' => $days,
            'on_expiry' => !array_key_exists('on_expiry', $row) || !empty($row['on_expiry']),
            'overdue_every_days' => max(0, (int)($row['overdue_every_days'] ?? 7)),
            'overdue_max' => max(0, (int)($row['overdue_max'] ?? 3)),
            'send_email' => !array_key_exists('send_email', $row) || !empty($row['send_email']),
            'include_team' => !empty($row['include_team']),
            'subject' => (string)($row['subject'] ?? 'Your hub subscription {status_text}'),
            'message' => (string)($row['message'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}

if (!function_exists('hubrem_days_left')) {
    /** Whole days from today to the end date (negative once it has passed). */
    function hubrem_days_left(string $endDate, ?string $today = null): int
    {
        $end = new DateTimeImmutable($endDate);
        $now = new DateTimeImmutable($today ?? date('Y-m-d'));

        return (int)$now->diff($end)->format('%r%a');
    }
}

if (!function_exists('hubrem_status_text')) {
    function hubrem_status_text(int $daysLeft): string
    {
        return match (true) {
            $daysLeft > 1 => 'expires in ' . $daysLeft . ' days',
            $daysLeft === 1 => 'expires tomorrow',
            $daysLeft === 0 => 'expires today',
            $daysLeft === -1 => 'expired yesterday',
            default => 'expired ' . abs($daysLeft) . ' days ago',
        };
    }
}

if (!function_exists('hubrem_auto_key')) {
    /**
     * The automatic reminder due today for this end date, or null.
     * $sentKeys: keys already logged for the member.
     */
    function hubrem_auto_key(array $settings, string $endDate, int $daysLeft, array $sentKeys): ?string
    {
        if ($daysLeft > 0) {
            // Nearest threshold at or above the days left (catches up a missed day once).
            foreach ($settings['days_before'] as $threshold) {
                if ($threshold >= $daysLeft) {
                    $key = 'before:' . $endDate . ':' . $threshold;
                    return isset($sentKeys[$key]) ? null : $key;
                }
            }

            return null;
        }

        if ($daysLeft === 0) {
            $key = 'expiry:' . $endDate;
            return $settings['on_expiry'] && !isset($sentKeys[$key]) ? $key : null;
        }

        $every = $settings['overdue_every_days'];

        if ($every <= 0 || $settings['overdue_max'] <= 0) {
            return null;
        }

        $round = intdiv(abs($daysLeft), $every);

        if ($round < 1 || $round > $settings['overdue_max']) {
            return null;
        }

        $key = 'overdue:' . $endDate . ':' . $round;

        return isset($sentKeys[$key]) ? null : $key;
    }
}

if (!function_exists('hubrem_member_sql')) {
    function hubrem_member_sql(string $where): string
    {
        return "SELECT m.member_id, m.membership_number, m.company_name, m.membership_status, m.subscription_plan,
                       m.subscription_amount, m.subscription_end_date, u.full_name, u.email,
                       p.name AS package_name,
                       (SELECT MAX(l.sent_at) FROM hub_reminder_log l WHERE l.member_id = m.member_id) AS last_reminder_at
                FROM members m
                LEFT JOIN users u ON u.user_id = m.user_id
                LEFT JOIN hub_space_packages p ON p.package_id = m.space_package_id
                WHERE $where";
    }
}

if (!function_exists('hubrem_candidates')) {
    /**
     * Members whose subscription ends within $aheadDays or ended within
     * $behindDays (Active or Expired), with days_left added.
     *
     * @return list<array<string,mixed>>
     */
    function hubrem_candidates(mysqli $conn, int $aheadDays, int $behindDays): array
    {
        $stmt = $conn->prepare(hubrem_member_sql(
            "m.membership_status IN ('Active', 'Expired')
             AND m.subscription_end_date IS NOT NULL
             AND m.subscription_end_date BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_ADD(CURDATE(), INTERVAL ? DAY)"
        ) . ' ORDER BY m.subscription_end_date');

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $behindDays, $aheadDays);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $row['days_left'] = hubrem_days_left((string)$row['subscription_end_date']);
        }

        return $rows;
    }
}

if (!function_exists('hubrem_render')) {
    function hubrem_render(string $template, array $member): string
    {
        $daysLeft = isset($member['subscription_end_date']) && $member['subscription_end_date']
            ? hubrem_days_left((string)$member['subscription_end_date'])
            : null;
        $org = function_exists('ims_brand') ? (string)(ims_brand()['name'] ?? '') : '';

        return str_replace(HUBREM_PLACEHOLDERS, [
            (string)($member['full_name'] ?? 'member'),
            (string)(($member['company_name'] ?? '') ?: ($member['full_name'] ?? '')),
            (string)($member['membership_number'] ?? ''),
            (string)($member['subscription_plan'] ?? ''),
            (string)($member['package_name'] ?? ''),
            $member['subscription_end_date'] ? date('d M Y', (int)strtotime((string)$member['subscription_end_date'])) : '',
            $daysLeft === null ? '' : (string)$daysLeft,
            $daysLeft === null ? 'is due for renewal' : hubrem_status_text($daysLeft),
            function_exists('format_currency') ? (string)format_currency($member['subscription_amount'] ?? 0) : number_format((float)($member['subscription_amount'] ?? 0)),
            $org,
        ], $template);
    }
}

if (!function_exists('hubrem_team_emails')) {
    /** @return list<string> */
    function hubrem_team_emails(mysqli $conn, int $memberId): array
    {
        $stmt = $conn->prepare("SELECT email FROM hub_team_members WHERE member_id = ? AND status = 'Active' AND email IS NOT NULL AND email <> ''");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $emails = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'email');
        $stmt->close();

        return array_values(array_filter($emails, static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('hubrem_send')) {
    /**
     * Sends one reminder to a member (in-app + email). For automatic
     * reminders the log row is claimed first, so a parallel cron run cannot
     * send the same key twice.
     *
     * @return array{ok:bool,emailed:int,error:string}
     */
    function hubrem_send(
        mysqli $conn,
        array $member,
        string $subject,
        string $message,
        string $kind,
        string $key,
        int $sentBy,
        bool $sendEmail,
        bool $includeTeam
    ): array {
        $memberId = (int)$member['member_id'];
        $subject = mb_substr(trim(hubrem_render($subject, $member)), 0, 190);
        $body = trim(hubrem_render($message, $member));

        if ($subject === '' || $body === '') {
            return ['ok' => false, 'emailed' => 0, 'error' => 'Subject and message are required.'];
        }

        $claim = $conn->prepare(
            'INSERT IGNORE INTO hub_reminder_log (member_id, reminder_key, kind, subject, sent_by) VALUES (?, ?, ?, ?, NULLIF(?, 0))'
        );

        if (!$claim) {
            return ['ok' => false, 'emailed' => 0, 'error' => 'Could not record the reminder.'];
        }

        $claim->bind_param('isssi', $memberId, $key, $kind, $subject, $sentBy);
        $claim->execute();
        $logId = (int)$claim->insert_id;
        $claimed = $claim->affected_rows === 1;
        $claim->close();

        if (!$claimed) {
            return ['ok' => false, 'emailed' => 0, 'error' => 'Already sent.'];
        }

        $notify = $conn->prepare(
            "INSERT INTO member_notifications (member_id, notification_type, title, message, link_url)
             VALUES (?, 'Subscription Expiry', ?, ?, 'member-dashboard')"
        );
        $recipients = 0;

        if ($notify) {
            $notify->bind_param('iss', $memberId, $subject, $body);
            $recipients = $notify->execute() ? 1 : 0;
            $notify->close();
        }

        $emailed = 0;
        $error = '';

        if ($sendEmail) {
            if (!function_exists('sendEmail')) {
                require_once __DIR__ . '/mail-function.php';
            }

            $to = [];
            if (filter_var((string)($member['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                $to[] = (string)$member['email'];
            }
            if ($includeTeam) {
                $to = array_merge($to, hubrem_team_emails($conn, $memberId));
            }

            $html = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>';
            if (function_exists('email_wrapper')) {
                $html = email_wrapper($html);
            }

            foreach (array_unique($to) as $address) {
                try {
                    $result = function_exists('sendEmail') ? sendEmail($address, $subject, $html, $body) : 'Mail unavailable';
                } catch (Throwable $e) {
                    $result = $e->getMessage();
                }

                if ($result === true) {
                    $emailed++;
                } else {
                    $error = mb_substr('Email to ' . $address . ' failed: ' . (is_string($result) ? $result : 'unknown'), 0, 255);
                    error_log('[hub-reminders] ' . $error);
                }
            }

            if ($to === []) {
                $error = 'No email address on file.';
            }
        }

        $update = $conn->prepare('UPDATE hub_reminder_log SET recipients = ?, emailed = ?, error = NULLIF(?, \'\') WHERE log_id = ?');
        if ($update) {
            $recipientCount = $recipients + $emailed;
            $update->bind_param('iisi', $recipientCount, $emailed, $error, $logId);
            $update->execute();
            $update->close();
        }

        return ['ok' => $recipients > 0 || $emailed > 0, 'emailed' => $emailed, 'error' => $error];
    }
}

if (!function_exists('hubrem_dispatch')) {
    /**
     * Sends every automatic reminder due today.
     *
     * @return array{sent:int,failed:int,skipped:bool}
     */
    function hubrem_dispatch(mysqli $conn, int $sentBy = 0, bool $ignoreEnabled = false): array
    {
        $settings = hubrem_settings($conn);

        if (!$settings['enabled'] && !$ignoreEnabled) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        $ahead = $settings['days_before'] !== [] ? max($settings['days_before']) : 0;
        $behind = $settings['overdue_every_days'] * $settings['overdue_max'];
        $sent = 0;
        $failed = 0;

        foreach (hubrem_candidates($conn, $ahead, $behind) as $member) {
            $keys = [];
            $stmt = $conn->prepare('SELECT reminder_key FROM hub_reminder_log WHERE member_id = ?');
            if ($stmt) {
                $memberId = (int)$member['member_id'];
                $stmt->bind_param('i', $memberId);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $keys[$row['reminder_key']] = true;
                }
                $stmt->close();
            }

            $key = hubrem_auto_key($settings, (string)$member['subscription_end_date'], (int)$member['days_left'], $keys);

            if ($key === null) {
                continue;
            }

            $result = hubrem_send(
                $conn, $member, $settings['subject'], $settings['message'], 'auto', $key, $sentBy,
                $settings['send_email'], $settings['include_team']
            );

            $result['ok'] ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
    }
}

if (!function_exists('hubrem_send_manual')) {
    /**
     * Operations/Admin reminder to chosen members.
     *
     * @param list<int> $memberIds
     * @return array{sent:int,failed:int,errors:list<string>}
     */
    function hubrem_send_manual(mysqli $conn, array $memberIds, string $subject, string $message, int $sentBy, bool $sendEmail, bool $includeTeam): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn(int $id): bool => $id > 0)));
        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach (array_chunk($memberIds, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $conn->prepare(hubrem_member_sql("m.member_id IN ($placeholders)"));

            if (!$stmt) {
                continue;
            }

            $stmt->bind_param(str_repeat('i', count($chunk)), ...$chunk);
            $stmt->execute();
            $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($members as $member) {
                $key = 'manual:' . date('YmdHis') . ':' . bin2hex(random_bytes(3));
                $result = hubrem_send($conn, $member, $subject, $message, 'manual', $key, $sentBy, $sendEmail, $includeTeam);

                if ($result['ok']) {
                    $sent++;
                } else {
                    $failed++;
                }

                if ($result['error'] !== '') {
                    $errors[] = ($member['company_name'] ?: $member['full_name']) . ': ' . $result['error'];
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }
}
