<?php
declare(strict_types=1);

/*
 * Event attendance reminders only (includes/event-reminders.php): reminders
 * 30, 20 and 10 minutes before each event day starts and at the start, each
 * asking registrants to confirm their attendance. cron/task-reminders.php
 * sends these too; schedule this one every minute if 5-minute precision is
 * not enough, e.g.  * * * * * php /path/to/legacy/cron/event-reminders.php
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/event-reminders.php';

$conn = db_connect();
$sent = evr_dispatch($conn);
echo sprintf("[%s] event reminders: sent=%d\n", date('Y-m-d H:i:s'), $sent);
