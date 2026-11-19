<?php
declare(strict_types=1);

/*
| Automatic hub member subscription reminders (includes/hub-reminders.php).
| Each reminder is sent once (logged per member and end date), so running
| this more often is safe. Cron, every morning at 08:00:
|   0 8 * * * php /path/to/legacy/cron/hub-member-reminders.php
*/

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/hub-network.php';
require_once __DIR__ . '/../includes/hub-reminders.php';

$conn = db_connect();

if (!$conn instanceof mysqli || !hubnet_ensure_schema($conn) || !hubrem_ensure_schema($conn)) {
    fwrite(STDERR, "Hub reminder tables unavailable.\n");
    exit(1);
}

$result = hubrem_dispatch($conn);

echo sprintf(
    "[%s] hub member reminders: %s\n",
    date('Y-m-d H:i:s'),
    $result['skipped'] ? 'automatic reminders are switched off' : 'sent=' . $result['sent'] . ' failed=' . $result['failed']
);
