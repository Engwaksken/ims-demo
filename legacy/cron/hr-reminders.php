<?php
declare(strict_types=1);

/*
| HR automation (includes/hr.php):
|   - shares "new staff" policies with recently created staff accounts
|   - reminds staff with missing documents / unacknowledged policies, at most
|     once every N days (HR Operations > Reminders > Settings)
| Safe to run more often than daily. Cron, every morning at 08:00:
|   0 8 * * * php /path/to/legacy/cron/hr-reminders.php
*/

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/hr.php';

$conn = db_connect();

if (!$conn instanceof mysqli || !hr_ensure_schema($conn)) {
    fwrite(STDERR, "HR tables unavailable.\n");
    exit(1);
}

$shared = hr_autoshare_new_staff($conn);
$result = hr_auto_reminders($conn);

echo sprintf(
    "[%s] hr: new-staff policy shares=%d, reminders: %s\n",
    date('Y-m-d H:i:s'),
    $shared,
    $result['skipped'] ? 'automatic reminders are switched off' : 'sent=' . $result['sent']
);
