<?php
declare(strict_types=1);

/*
| Automatic reminders to applicants with an unsubmitted Draft application on
| a Published opportunity (includes/application-reminders.php). Each
| threshold (e.g. 7, 3, 1 days before the deadline) is sent once per draft,
| so running this more often is safe. Cron, every morning at 08:00:
|   0 8 * * * php /path/to/legacy/cron/application-draft-reminders.php
|
| Preview without sending or logging anything (ignores the on/off switch):
|   php cron/application-draft-reminders.php --dry-run
*/

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

$dryRun = in_array('--dry-run', array_slice($argv ?? [], 1), true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/application-reminders.php';

$conn = db_connect();

if (!$conn instanceof mysqli || !apprem_ensure_schema($conn)) {
    fwrite(STDERR, "Application reminder tables unavailable.\n");
    exit(1);
}

$result = apprem_dispatch($conn, null, $dryRun);

if (!$dryRun) {
    echo sprintf(
        "[%s] draft application reminders: %s\n",
        date('Y-m-d H:i:s'),
        $result['skipped'] ? 'automatic reminders are switched off' : 'sent=' . $result['sent'] . ' failed=' . $result['failed']
    );
    exit(0);
}

$settings = apprem_settings($conn);
echo sprintf(
    "[%s] DRY RUN - nothing is sent or logged.\nAutomatic reminders: %s, days before deadline: %s\n\n",
    date('Y-m-d H:i:s'),
    $settings['enabled'] ? 'ON' : 'OFF (a real run would send nothing)',
    $settings['days_before'] ? implode(',', $settings['days_before']) : 'none'
);

$plan = $result['plan'];
if (!$plan) {
    echo "No open drafts (Published opportunity, deadline today or later).\n";
    exit(0);
}

$cut = static fn(string $v, int $n): string => mb_strlen($v) > $n ? mb_substr($v, 0, $n - 1) . '~' : $v;
$row = "%-8s %-28s %-32s %-11s %4s  %-10s %s\n";
printf($row, 'App ID', 'Opportunity', 'Email', 'Deadline', 'Days', 'Reminder', 'Action');
echo str_repeat('-', 112), "\n";

$counts = [];
foreach ($plan as $p) {
    $counts[$p['action']] = ($counts[$p['action']] ?? 0) + 1;
    printf(
        $row,
        (string)$p['application_id'],
        $cut((string)$p['opportunity_title'], 28),
        $cut((string)($p['email'] ?? ''), 32),
        substr((string)$p['deadline'], 0, 10),
        (string)$p['days_left'],
        $p['reminder_key'] !== '' ? $p['reminder_key'] : '-',
        strtoupper((string)$p['action']) === 'SEND' ? 'WOULD SEND' : $p['action']
    );
}

echo "\n";
foreach ($counts as $action => $n) {
    echo sprintf("  %-15s %d\n", $action === 'send' ? 'would send' : $action, $n);
}
