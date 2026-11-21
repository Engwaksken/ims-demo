<?php
/*
 * IMS captive-portal agent for pfSense (CE 2.5+ / Plus 21.02+).
 *
 * Connects, disconnects and blocks registered hub devices by MAC address on
 * a captive-portal zone, as decided in the IMS (/hub-team):
 *   pass   - "Pass" MAC entry: online without the portal login
 *   block  - "Block" MAC entry, active sessions dropped
 *   remove - no entry, active sessions dropped once (back to the normal portal)
 * Entries the IMS created have a description starting with "IMS "; entries
 * you add by hand in pfSense are never touched.
 *
 * 2.3 adds: device name (DHCP), maker (MAC prefix) and interface for every
 *   device seen online, so the IMS can identify access points and others.
 *
 * 2.2 adds: per-device speed limits. "pass" entries carry the Bandwidth
 *   down/up (Kbit/s) set in the IMS; pfSense applies them with limiters.
 *
 * 2.1 adds:
 *   - the internet switch: the IMS can enforce the captive portal ("on") or
 *     switch it off for open internet ("off"); empty leaves it as configured
 *   - live clients: every check-in reports the devices seen on the portal
 *     interfaces (ARP table) and the portal sessions, so the IMS can show
 *     who is online and count connected devices over time
 *   - guest hotspot: use tools/pfsense/ims-portal-page.html as the zone's
 *     portal page and add the IMS host name under Allowed Hostnames
 *
 * ---------------------------------------------------------------------------
 * PULL MODE (recommended; required when the IMS is on shared hosting)
 * pfSense calls the IMS every minute, so nothing on the firewall is exposed.
 *
 *   1. Copy this file to /root/ims-cp-agent.php   (NOT /usr/local/www)
 *   2. Set IMS_AGENT_SECRET and IMS_FEED_URL below, e.g.
 *        IMS_FEED_URL = 'https://ims.example.org/hub-network-agent'
 *   3. Test from Diagnostics > Command Prompt (or SSH):
 *        /usr/local/bin/php -f /root/ims-cp-agent.php pull
 *   4. System > Package Manager: install "Cron". Services > Cron > Add:
 *        Minute: every minute (enter * in every time field), User root,
 *        Command: /usr/local/bin/php -f /root/ims-cp-agent.php pull
 *   5. In the IMS (Hub Team & Internet > Settings > pfSense): mode "pfSense
 *      checks in", same secret, switch control on. "Last check-in" shows
 *      when the firewall last called.
 *
 * PUSH MODE (only when the IMS server can reach the firewall directly)
 *   Copy to /usr/local/www/ims-cp-agent.php, set IMS_ALLOWED_IPS, and enter
 *   https://<pfsense>/ims-cp-agent.php as the agent URL in the IMS.
 * ---------------------------------------------------------------------------
 *
 * The zone needs "MAC filtering" enabled (Services > Captive Portal > zone)
 * for pass/block MAC entries to take effect. pfSense upgrades can remove
 * files outside /root: check after an upgrade.
 */

// ---- Settings -------------------------------------------------------------
const IMS_AGENT_SECRET = 'CHANGE-ME-to-a-long-random-string';
const IMS_FEED_URL = 'https://ims.hivecolab.com/hub-network-agent';  // pull mode
const IMS_ALLOWED_IPS = ['192.168.1.1'];                          // push mode only
const IMS_DEFAULT_ZONE = 'lan';
// ---------------------------------------------------------------------------

const IMS_AGENT_VERSION = '2.3';
const IMS_MAX_ITEMS = 5000;
const IMS_STATE_FILE = '/var/db/ims-cp-agent-state.json';
const IMS_DESCR_PREFIX = 'IMS ';

$imsCli = PHP_SAPI === 'cli';

if (IMS_AGENT_SECRET === '' || strpos(IMS_AGENT_SECRET, 'CHANGE-ME') === 0) {
    ims_fail('Agent secret is not configured in ' . __FILE__, 500);
}

/* ------------------------------------------------------------------------ */

function ims_fail(string $message, int $status = 400): void
{
    global $imsCli;

    if ($imsCli) {
        fwrite(STDERR, '[ims-cp-agent] ' . $message . "\n");
        exit(1);
    }

    ims_reply(false, $message, [], $status);
}

function ims_reply(bool $ok, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data, 'version' => IMS_AGENT_VERSION]);
    exit;
}

function ims_normalize_mac(string $mac): string
{
    $hex = preg_replace('/[^a-f0-9]/', '', strtolower($mac));

    return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
}

function ims_load_pfsense(string $zone): array
{
    require_once 'config.inc';
    require_once 'util.inc';
    require_once 'captiveportal.inc';

    global $cpzone, $cpzoneid;

    $cpzone = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $zone)) ?: IMS_DEFAULT_ZONE;
    $zoneConfig = ims_cfg_get("captiveportal/{$cpzone}");

    if (!is_array($zoneConfig)) {
        ims_fail("Captive portal zone '{$cpzone}' was not found.", 404);
    }

    $cpzoneid = $zoneConfig['zoneid'] ?? null;

    return $zoneConfig;
}

function ims_cfg_get(string $path, $default = null)
{
    global $config;

    if (function_exists('config_get_path')) {
        return config_get_path($path, $default);
    }

    $node = $config;
    foreach (explode('/', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }

    return $node;
}

function ims_cfg_set(string $path, $value): void
{
    global $config;

    if (function_exists('config_set_path')) {
        config_set_path($path, $value);
        return;
    }

    $node = &$config;
    foreach (explode('/', $path) as $key) {
        if (!isset($node[$key]) || !is_array($node[$key])) {
            $node[$key] = [];
        }
        $node = &$node[$key];
    }
    $node = $value;
}

/** Active portal sessions as [sessionid, mac, ip, username]. */
function ims_sessions(): array
{
    $sessions = [];

    foreach ((array)captiveportal_read_db() as $row) {
        // pfSense 2.5+: associative rows; older releases: numeric columns.
        $sessions[] = [
            'sessionid' => (string)($row['sessionid'] ?? $row[5] ?? ''),
            'mac' => ims_normalize_mac((string)($row['mac'] ?? $row[3] ?? '')),
            'ip' => (string)($row['ip'] ?? $row[2] ?? ''),
            'username' => (string)($row['username'] ?? $row[4] ?? ''),
            'started' => (int)($row['allow_time'] ?? $row[0] ?? 0),
        ];
    }

    return $sessions;
}

function ims_drop_sessions(string $mac, array $sessions): int
{
    $dropped = 0;

    foreach ($sessions as $session) {
        if ($session['mac'] === $mac && $session['sessionid'] !== '') {
            captiveportal_disconnect_client($session['sessionid'], 1, 'IMS_DISCONNECT');
            $dropped++;
        }
    }

    return $dropped;
}

/** Reloads the zone's MAC pass/block rules after the config changed. */
function ims_reload_mac_rules(array $zoneConfig): string
{
    global $cpzone;

    if (function_exists('captiveportal_passthrumac_configure')) {
        $rules = captiveportal_passthrumac_configure();
        if (is_string($rules) && $rules !== '') {
            $file = '/tmp/ims_cp_' . $cpzone . '_mac.rules';
            file_put_contents($file, $rules);
            mwexec('/sbin/pfctl -f ' . escapeshellarg($file));
            @unlink($file);
        }
        return 'passthrumac_configure';
    }

    captiveportal_configure_zone($zoneConfig);
    return 'configure_zone';
}

function ims_state_load(): array
{
    $state = is_file(IMS_STATE_FILE) ? json_decode((string)file_get_contents(IMS_STATE_FILE), true) : null;

    return is_array($state) && is_array($state['modes'] ?? null) ? $state : ['modes' => []];
}

function ims_state_save(array $state): void
{
    @file_put_contents(IMS_STATE_FILE, json_encode($state), LOCK_EX);
}

/**
 * Applies the desired modes to the zone's MAC entries.
 * $removeUnlisted: drop IMS-managed entries that are not in $items (pull mode
 * sends the full list, so a device deleted in the IMS disappears here too).
 */
function ims_apply(array $zoneConfig, array $items, bool $removeUnlisted, string $prefix): array
{
    global $cpzone;

    $entries = array_values((array)($zoneConfig['passthrumac'] ?? []));
    $sessions = ims_sessions();
    $state = ims_state_load();
    $results = [];
    $wanted = [];
    $changed = false;

    foreach (array_slice($items, 0, IMS_MAX_ITEMS) as $item) {
        $mac = ims_normalize_mac((string)($item['mac'] ?? ''));
        $mode = (string)($item['mode'] ?? '');

        if ($mac !== '' && in_array($mode, ['pass', 'block', 'remove'], true)) {
            $descr = substr(preg_replace('/[^\w .@()\-]/u', '', (string)($item['descr'] ?? '')), 0, 120);
            if (strpos($descr, $prefix) !== 0) {
                $descr = substr($prefix . $descr, 0, 120);
            }
            // 2.2: speed limit in Kbit/s for "pass" entries (pfSense MAC entry bw_down / bw_up).
            $bw = [];
            foreach (['bw_down', 'bw_up'] as $key) {
                $kbit = (int)($item[$key] ?? 0);
                if ($mode === 'pass' && $kbit > 0 && $kbit <= 10000000) {
                    $bw[$key] = (string)$kbit;
                }
            }
            $wanted[$mac] = ['mode' => $mode, 'descr' => $descr, 'bw' => $bw];
        }
    }

    // Existing entries: update or remove IMS-managed ones, never touch manual ones.
    $kept = [];
    $seen = [];

    foreach ($entries as $entry) {
        $mac = ims_normalize_mac((string)($entry['mac'] ?? ''));
        $managed = strpos((string)($entry['descr'] ?? ''), $prefix) === 0;

        if (!$managed) {
            if (isset($wanted[$mac])) {
                $results[$mac] = ['ok' => false, 'mode' => $wanted[$mac]['mode'],
                    'message' => 'A manual MAC entry for this device exists in pfSense; remove it there to let the IMS manage it.'];
                unset($wanted[$mac]);
            }
            $kept[] = $entry;
            continue;
        }

        if (isset($wanted[$mac]) && $wanted[$mac]['mode'] !== 'remove' && !isset($seen[$mac])) {
            $target = ['action' => $wanted[$mac]['mode'], 'mac' => $mac, 'descr' => $wanted[$mac]['descr']];
            $bw = $wanted[$mac]['bw'];
            $bwChanged = (string)($entry['bw_down'] ?? '') !== ($bw['bw_down'] ?? '')
                || (string)($entry['bw_up'] ?? '') !== ($bw['bw_up'] ?? '');
            if (($entry['action'] ?? 'pass') !== $target['action'] || ($entry['descr'] ?? '') !== $target['descr'] || $bwChanged) {
                unset($entry['bw_down'], $entry['bw_up']);
                $entry = array_merge($entry, $target, $bw);
                $changed = true;
            }
            $kept[] = $entry;
            $seen[$mac] = true;
            continue;
        }

        if (isset($wanted[$mac]) || $removeUnlisted || isset($seen[$mac])) {
            $changed = true; // remove: wanted "remove", deleted in the IMS, or a duplicate
            continue;
        }

        $kept[] = $entry;
    }

    foreach ($wanted as $mac => $want) {
        if ($want['mode'] !== 'remove' && !isset($seen[$mac])) {
            $kept[] = ['action' => $want['mode'], 'mac' => $mac, 'descr' => $want['descr']] + $want['bw'];
            $changed = true;
        }

        // Drop sessions only when a device changes to offline, so someone whose
        // membership lapsed can still sign in through the normal portal.
        $previous = $state['modes'][$mac] ?? null;
        $dropped = ($want['mode'] !== 'pass' && $previous !== $want['mode']) ? ims_drop_sessions($mac, $sessions) : 0;
        $state['modes'][$mac] = $want['mode'];
        $results[$mac] = ['ok' => true, 'mode' => $want['mode'], 'sessions_dropped' => $dropped];
    }

    if ($removeUnlisted) {
        $state['modes'] = array_intersect_key($state['modes'], $wanted);
    }

    $reload = 'unchanged';

    if ($changed) {
        ims_cfg_set("captiveportal/{$cpzone}/passthrumac", $kept);
        $zoneConfig['passthrumac'] = $kept;
        write_config('IMS hub: updated captive portal MAC entries');
        $reload = ims_reload_mac_rules($zoneConfig);
    }

    ims_state_save($state);

    return ['results' => $results, 'reload' => $reload, 'changed' => $changed];
}

/** Devices seen on the zone's interfaces (ARP table): [['mac' => .., 'ip' => ..], ...]. */
function ims_clients(array $zoneConfig): array
{
    $clients = [];
    // Zone interfaces ("lan,opt1") -> real NICs ("igb1"). get_real_interface() lives in
    // interfaces.inc, which captiveportal.inc does not always load; fall back to config.
    if (!function_exists('get_real_interface') && is_file('/etc/inc/interfaces.inc')) {
        require_once 'interfaces.inc';
    }
    $real = [];
    foreach (array_filter(array_map('trim', explode(',', (string)($zoneConfig['interface'] ?? '')))) as $iface) {
        $nic = function_exists('get_real_interface') ? (string)get_real_interface($iface) : '';
        if ($nic === '') {
            $nic = (string)ims_cfg_get("interfaces/{$iface}/if", '');
        }
        if (preg_match('/^[A-Za-z0-9_.]+$/', $nic)) {
            // NIC => the interface name shown in pfSense ("LAN", "WIFI").
            $real[$nic] = strtoupper((string)(ims_cfg_get("interfaces/{$iface}/descr", '') ?: $iface));
        }
    }
    if (!$real) {
        return [];
    }
    $names = ims_dhcp_names();
    $vendors = ims_vendor_table();

    // One ARP read for all interfaces, filtered by NIC (works on every FreeBSD arp version).
    $out = [];
    exec('/usr/sbin/arp -an 2>/dev/null', $out);
    foreach ($out as $line) {
        // ? (192.168.1.23) at 3c:22:fb:12:34:56 on igb1 expires in 1180 seconds [ethernet]
        if (!preg_match('/\(([0-9.]+)\) at ([0-9a-f:]{11,17}) on ([A-Za-z0-9_.]+)/i', $line, $m) || !isset($real[$m[3]])) {
            continue;
        }
        if (stripos($line, 'permanent') !== false) {
            continue; // the firewall's own address
        }
        $mac = ims_normalize_mac($m[2]);
        if ($mac !== '' && $mac !== 'ff:ff:ff:ff:ff:ff') {
            // 2.3: name (DHCP lease / static mapping), maker (MAC prefix) and interface.
            $oui = strtoupper(str_replace(':', '', substr($mac, 0, 8)));
            $clients[$mac] = [
                'mac' => $mac,
                'ip' => $m[1],
                'hostname' => substr((string)($names[$mac] ?? ''), 0, 120),
                'vendor' => substr(trim((string)($vendors[$oui] ?? '')), 0, 120),
                'iface' => substr((string)$real[$m[3]], 0, 30),
            ];
        }
    }

    return array_values(array_slice($clients, 0, IMS_MAX_ITEMS));
}

/**
 * Device names by MAC: DHCP static mappings (hostname, else description),
 * then the ISC dhcpd lease file, then the Kea lease file. Best effort.
 */
function ims_dhcp_names(): array
{
    $names = [];
    foreach ((array)ims_cfg_get('dhcpd', []) as $ifConf) {
        foreach ((array)($ifConf['staticmap'] ?? []) as $map) {
            $mac = ims_normalize_mac((string)($map['mac'] ?? ''));
            $name = trim((string)($map['hostname'] ?? '')) ?: trim((string)($map['descr'] ?? ''));
            if ($mac !== '' && $name !== '') {
                $names[$mac] = $name;
            }
        }
    }
    $leases = '/var/dhcpd/var/db/dhcpd.leases';
    if (is_readable($leases) && filesize($leases) < 20 * 1024 * 1024) {
        // lease 192.168.1.23 { ... hardware ethernet 3c:22:...; client-hostname "Janes-iPhone"; }
        if (preg_match_all('/lease\s+[0-9.]+\s*\{(.*?)\}/s', (string)file_get_contents($leases), $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match('/hardware ethernet ([0-9a-f:]+);/i', $block, $hw) && preg_match('/client-hostname "([^"]*)";/', $block, $hn)) {
                    $mac = ims_normalize_mac($hw[1]);
                    if ($mac !== '' && $hn[1] !== '' && !isset($names[$mac])) {
                        $names[$mac] = $hn[1];
                    }
                }
            }
        }
    }
    foreach (['/var/lib/kea/dhcp4.leases', '/var/db/kea/dhcp4.leases'] as $kea) {
        if (is_readable($kea) && filesize($kea) < 20 * 1024 * 1024 && ($fh = fopen($kea, 'r'))) {
            // address,hwaddr,client_id,valid_lifetime,expire,subnet_id,fqdn_fwd,fqdn_rev,hostname,...
            while (($row = fgetcsv($fh)) !== false) {
                $mac = ims_normalize_mac((string)($row[1] ?? ''));
                $name = rtrim(trim((string)($row[8] ?? '')), '.');
                if ($mac !== '' && $name !== '' && !isset($names[$mac])) {
                    $names[$mac] = $name;
                }
            }
            fclose($fh);
        }
    }
    return $names;
}

/** MAC prefix => maker, from pfSense's own table (the one Diagnostics > ARP Table uses). */
function ims_vendor_table(): array
{
    if (function_exists('load_mac_manufacturer_table')) {
        $table = load_mac_manufacturer_table();
        if (is_array($table) && $table) {
            return array_change_key_case($table, CASE_UPPER);
        }
    }
    $file = '/usr/local/share/nmap/nmap-mac-prefixes';
    $table = [];
    if (is_readable($file) && ($fh = fopen($file, 'r'))) {
        while (($line = fgets($fh)) !== false) {
            if (preg_match('/^([0-9A-F]{6})\s+(.+)$/i', trim($line), $m)) {
                $table[strtoupper($m[1])] = trim($m[2]);
            }
        }
        fclose($fh);
    }
    return $table;
}

/**
 * Internet switch: "on" enables the captive portal zone, "off" disables it
 * (open internet). Anything else leaves it alone. Returns what happened.
 */
function ims_set_portal(string $zone, string $want): string
{
    if ($want !== 'on' && $want !== 'off') {
        return '';
    }
    $zoneConfig = ims_cfg_get("captiveportal/{$zone}");
    if (!is_array($zoneConfig)) {
        return 'error: zone not found';
    }
    $isOn = isset($zoneConfig['enable']);
    if (($want === 'on') === $isOn) {
        return '';
    }

    if ($want === 'on') {
        $zoneConfig['enable'] = '';
    } else {
        unset($zoneConfig['enable']);
    }
    ims_cfg_set("captiveportal/{$zone}", $zoneConfig);
    write_config('IMS hub: captive portal ' . ($want === 'on' ? 'enabled' : 'disabled (open internet)'));

    // Same steps as saving the zone in the pfSense GUI.
    if (function_exists('captiveportal_configure_zone')) {
        captiveportal_configure_zone($zoneConfig);
    } elseif (function_exists('captiveportal_configure')) {
        captiveportal_configure();
    }
    if (function_exists('filter_configure')) {
        filter_configure();
    }

    return $want === 'on' ? 'portal enabled' : 'portal disabled';
}

function ims_zone_info(array $zoneConfig, string $zone): array
{
    return [
        'version' => IMS_AGENT_VERSION,
        'zone' => $zone,
        'portal_enabled' => isset($zoneConfig['enable']),
        'mac_filtering' => empty($zoneConfig['nomacfilter']),
        'sessions' => count(ims_sessions()),
        'clients' => count(ims_clients($zoneConfig)),
        'mac_entries' => count((array)($zoneConfig['passthrumac'] ?? [])),
    ];
}

/** Online devices for the IMS (portal sessions + ARP clients). */
function ims_online_payload(array $zoneConfig): array
{
    $sessions = array_map(static function (array $s): array {
        return ['mac' => $s['mac'], 'ip' => $s['ip'], 'username' => $s['username'], 'started' => $s['started'] ?? 0];
    }, array_slice(ims_sessions(), 0, IMS_MAX_ITEMS));

    return ['clients' => ims_clients($zoneConfig), 'sessions' => $sessions];
}

/* ------------------------------------------------------------------------
 * PULL MODE (CLI): php -f /root/ims-cp-agent.php pull
 * ------------------------------------------------------------------------ */

function ims_post(array $payload): array
{
    $body = json_encode($payload + ['ts' => time(), 'nonce' => bin2hex(random_bytes(12))], JSON_UNESCAPED_SLASHES);
    $responseHeaders = [];

    $ch = curl_init(IMS_FEED_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'ims-cp-agent/' . IMS_AGENT_VERSION,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-IMS-Signature: ' . hash_hmac('sha256', $body, IMS_AGENT_SECRET),
        ],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $error !== '') {
        ims_fail('Could not reach the IMS: ' . $error);
    }

    // Only act on replies signed with our secret.
    $signature = $responseHeaders['x-ims-signature'] ?? '';
    if (!hash_equals(hash_hmac('sha256', (string)$response, IMS_AGENT_SECRET), $signature)) {
        ims_fail('IMS reply (HTTP ' . $status . ') is not signed with this secret: ' . substr((string)$response, 0, 200));
    }

    $decoded = json_decode((string)$response, true);

    if (!is_array($decoded) || empty($decoded['ok'])) {
        ims_fail('IMS refused the check-in (HTTP ' . $status . '): ' . substr((string)$response, 0, 200));
    }

    return $decoded;
}

if ($imsCli) {
    if (($argv[1] ?? '') !== 'pull') {
        fwrite(STDERR, "Usage: php -f " . __FILE__ . " pull\n");
        exit(2);
    }

    $lock = fopen('/tmp/ims-cp-agent.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0); // previous run still busy
    }

    $zoneConfig = ims_load_pfsense(IMS_DEFAULT_ZONE);
    $feed = ims_post(['action' => 'feed', 'info' => ims_zone_info($zoneConfig, IMS_DEFAULT_ZONE)]);

    if (empty($feed['enabled'])) {
        // Control is off: change nothing, but still report who is online.
        $online = ims_online_payload($zoneConfig);
        ims_post(['action' => 'report', 'results' => [], 'info' => ims_zone_info($zoneConfig, IMS_DEFAULT_ZONE)] + $online);
        echo "[ims-cp-agent] checked in; control is off in the IMS, nothing changed. "
            . count($online['clients']) . ' device(s) seen on the portal network, ' . count($online['sessions']) . " portal session(s).\n";
        exit(0);
    }

    $zone = (string)($feed['zone'] ?? IMS_DEFAULT_ZONE);
    if ($zone !== IMS_DEFAULT_ZONE) {
        $zoneConfig = ims_load_pfsense($zone);
    }

    $portalChange = ims_set_portal($GLOBALS['cpzone'], (string)($feed['portal'] ?? ''));
    if ($portalChange !== '') {
        $zoneConfig = ims_cfg_get("captiveportal/{$GLOBALS['cpzone']}", $zoneConfig);
        echo '[ims-cp-agent] ' . $portalChange . ".\n";
    }

    $prefix = (string)($feed['descr_prefix'] ?? IMS_DESCR_PREFIX) ?: IMS_DESCR_PREFIX;
    $applied = ims_apply($zoneConfig, is_array($feed['items'] ?? null) ? $feed['items'] : [], true, $prefix);

    $finalZone = ims_cfg_get("captiveportal/{$GLOBALS['cpzone']}", []);
    $online = ims_online_payload($finalZone);
    ims_post([
        'action' => 'report',
        'results' => $applied['results'],
        'info' => ims_zone_info($finalZone, $zone) + ['reload' => $applied['reload'], 'portal_change' => $portalChange],
    ] + $online);

    echo '[ims-cp-agent] ' . count($applied['results']) . ' device(s) checked'
        . ($applied['changed'] ? ', rules reloaded (' . $applied['reload'] . ')' : ', no changes')
        . '; ' . count($online['clients']) . ' device(s) seen on the portal network, ' . count($online['sessions']) . " portal session(s).\n";
    exit(0);
}

/* ------------------------------------------------------------------------
 * PUSH MODE (web): the IMS calls https://<pfsense>/ims-cp-agent.php
 * ------------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ims_fail('POST only.', 405);
}

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', IMS_ALLOWED_IPS, true)) {
    ims_fail('Address not allowed.', 403);
}

$raw = (string)file_get_contents('php://input');

if ($raw === '' || !hash_equals(hash_hmac('sha256', $raw, IMS_AGENT_SECRET), (string)($_SERVER['HTTP_X_IMS_SIGNATURE'] ?? ''))) {
    ims_fail('Bad signature.', 401);
}

$request = json_decode($raw, true);

if (!is_array($request)) {
    ims_fail('Invalid JSON.', 400);
}

if (abs(time() - (int)($request['ts'] ?? 0)) > 300) {
    ims_fail('Request expired: check the clocks on pfSense and the IMS server.', 401);
}

$nonce = preg_replace('/[^a-f0-9]/', '', (string)($request['nonce'] ?? ''));
$nonceFile = '/tmp/ims-cp-agent-nonces.json';
$nonces = is_file($nonceFile) ? (json_decode((string)file_get_contents($nonceFile), true) ?: []) : [];
$nonces = array_filter($nonces, static fn($seen) => $seen > time() - 600);

if ($nonce === '' || isset($nonces[$nonce])) {
    ims_fail('Replayed request.', 401);
}

$nonces[$nonce] = time();
@file_put_contents($nonceFile, json_encode($nonces), LOCK_EX);

$zone = (string)($request['zone'] ?? IMS_DEFAULT_ZONE);
$zoneConfig = ims_load_pfsense($zone);

switch ((string)($request['action'] ?? '')) {
    case 'ping':
        $info = ims_zone_info($zoneConfig, $zone);
        ims_reply(true, "Connected to pfSense zone '{$info['zone']}'.", $info);
        break;

    case 'list':
        ims_reply(true, 'OK', [
            'mac_entries' => array_values((array)($zoneConfig['passthrumac'] ?? [])),
            'sessions' => ims_sessions(),
        ]);
        break;

    case 'apply':
        $items = is_array($request['items'] ?? null) ? $request['items'] : [];
        $applied = ims_apply($zoneConfig, $items, false, IMS_DESCR_PREFIX);
        ims_reply(true, 'Applied ' . count($applied['results']) . ' device(s).', [
            'results' => $applied['results'],
            'reload' => $applied['reload'],
        ]);
        break;

    default:
        ims_fail('Unknown action.', 400);
}
