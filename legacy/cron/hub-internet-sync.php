<?php
declare(strict_types=1);

/*
| Automatic hub internet enforcement (pfSense).
| Disconnects devices whose membership expired / was suspended (or whose
| team member was suspended) and reconnects them when active again, and
| removes hotspot guests 30 days after their access ended. Only
| devices whose pfSense state differs from the desired state are sent.
|
| Cron (every 5 minutes):
|   *\/5 * * * * php /path/to/legacy/cron/hub-internet-sync.php
*/

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/hub-network.php';

$conn = db_connect();

if (!$conn instanceof mysqli || !hubnet_ensure_schema($conn)) {
    fwrite(STDERR, "Hub network tables unavailable.\n");
    exit(1);
}

// Hotspot guests: forget them (and their devices) 30 days after their access ended.
require_once __DIR__ . '/../includes/hub-internet.php';
$purged = hubinet_purge_hotspot_guests($conn, 30);
if ($purged > 0) {
    echo date('Y-m-d H:i:s') . " removed {$purged} expired hotspot guest(s).
";
}

$settings = hubnet_settings($conn);

if (!$settings['enabled'] || !$settings['auto_enforce']) {
    echo "pfSense control or automatic enforcement is off; nothing to do.\n";
    exit(0);
}

$result = hubnet_sync_all($conn, 0, in_array('--force', $argv ?? [], true));

echo date('Y-m-d H:i:s') . ' ' . $result['message'] . "\n";
exit($result['ok'] ? 0 : 1);
