<?php
declare(strict_types=1);

/*
 * Schedule this CLI script every five minutes for punctual reminders.
 * Also materializes today's weekday recurrence instances. The My Tasks page
 * invokes the same work lazily as a fallback when cron has not run yet.
 * Also sends staff appointment reminders (includes/appointments.php).
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/task-reminders.php';
require_once __DIR__ . '/../includes/appointments.php';

$conn = db_connect();
$created = task_generate_recurring_occurrences($conn);
$sent = task_dispatch_due_reminders($conn);
$appointments = appt_dispatch_reminders($conn);
echo sprintf("[%s] task reminders: recurring=%d sent=%d appointments=%d\n", date('Y-m-d H:i:s'), $created, $sent, $appointments);
