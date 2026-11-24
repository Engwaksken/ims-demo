<?php
declare(strict_types=1);

/*
| Automatic reminders to applicants with an unsubmitted Draft application on
| a Published opportunity (includes/application-reminders.php). Each
| threshold (e.g. 7, 3, 1 days before the deadline) is sent once per draft,
| so running this more often is safe. Cron, every morning at 08:00:
|   0 8 * * * php /path/to/legacy/cron/application-draft-reminders.php
*/

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/application-reminders.php';

$conn = db_connect();

if (!$conn instanceof mysqli || !apprem_ensure_schema($conn)) {
    fwrite(STDERR, "Application reminder tables unavailable.\n");
    exit(1);
}

$result = apprem_dispatch($conn);

echo sprintf(
    "[%s] draft application reminders: %s\n",
    date('Y-m-d H:i:s'),
    $result['skipped'] ? 'automatic reminders are switched off' : 'sent=' . $result['sent'] . ' failed=' . $result['failed']
);
