<?php
declare(strict_types=1);

/*
| Hub internet: staff & guests, internet switch, hotspot, online devices.
| POST only (CSRF enforced globally by config.php); redirects back to
| /hub-team with a flash message. Company team members and their devices
| stay in includes/hub-team-process.php.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hub-internet.php';

function hip_back(string $query, bool $ok, string $message): never
{
    $_SESSION[$ok ? 'success' : 'error'] = $message;
    header('Location: ../hub-team' . ($query !== '' ? '?' . $query : ''));
    exit;
}

$views = ['overview', 'staff', 'guests', 'devices', 'online', 'hotspot', 'vouchers'];
$returnView = in_array($_POST['return_view'] ?? '', $views, true) ? (string)$_POST['return_view'] : 'overview';
$returnQuery = 'view=' . $returnView . ((int)($_POST['return_person'] ?? 0) > 0 ? '&person=' . (int)$_POST['return_person'] : '');
// Forms used on several pages (the speed modal) say where to come back to.
if (preg_match('/\A[A-Za-z0-9_]+=[A-Za-z0-9_=&%.+-]{0,200}\z/', (string)($_POST['return_query'] ?? ''))) {
    $returnQuery = (string)$_POST['return_query'];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_SESSION['user_id'])) {
    hip_back('', false, 'Unauthorized request.');
}
if (!isset($conn) || !($conn instanceof mysqli) || !hubnet_ensure_schema($conn)) {
    hip_back($returnQuery, false, 'Hub internet tables are not available.');
}

$uid = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? '');

$canManage = ims_allowed('hub_team.manage', ['Administrator', 'Operations/Admin'], 'Register and edit hub team members and their devices');
$canControl = ims_allowed('hub_network.control', ['Administrator', 'Operations/Admin', 'IT Officer'], 'Connect and disconnect hub members from the internet (pfSense)');
$canSettings = ims_allowed('hub_network.settings', ['Administrator', 'IT Officer'], 'Configure hub workspace packages and the pfSense connection');
$canSwitch = ims_allowed('hub_network.switch', ['Administrator', 'IT Officer'], 'Switch hub internet control on/off (open internet) on pfSense');
$canVouchers = ims_allowed('hub_network.vouchers', ['Administrator', 'Operations/Admin'], 'Create, print and cancel internet vouchers for day clients');

// The online list's bulk bar posts devices_bulk; its "unknown devices" buttons carry unknown_op.
if ($action === 'devices_bulk' && in_array($_POST['unknown_op'] ?? '', ['block', 'forget', 'equipment'], true)) {
    $action = 'unknown_bulk';
}

$needs = [
    'save_person' => $canManage,
    'remove_person' => $canManage,
    'people_bulk' => $canManage,
    'person_access' => $canControl,
    'add_person_device' => $canManage,
    'remove_person_device' => $canManage,
    'devices_bulk' => $canControl,
    'portal_switch' => $canSwitch,
    'save_hotspot' => $canSettings,
    'download_pfsense_page' => $canSettings,
    'register_online' => $canManage,
    'unknown_bulk' => $canControl,
    'device_speed' => $canControl,
    'edit_device' => $canControl,
    'person_to_member' => $canManage,
    'import_pfmacs' => $canManage,
    'create_vouchers' => $canVouchers,
    'vouchers_bulk' => $canVouchers,
];
if (!array_key_exists($action, $needs)) {
    hip_back($returnQuery, false, 'Unknown action.');
}
if (!$needs[$action]) {
    hip_back($returnQuery, false, 'You do not have permission to do that.');
}

try {
    switch ($action) {
        case 'save_person':
            $r = hubinet_save_person($conn, $_POST, $uid);
            hip_back($returnQuery, $r['ok'], $r['message']);

        case 'remove_person':
            $r = hubinet_remove_person($conn, (int)($_POST['person_id'] ?? 0), $uid);
            hip_back('view=' . $returnView, $r['ok'], $r['message']);

        case 'people_bulk':
            $r = hubinet_people_bulk($conn, (array)($_POST['ids'] ?? []), (string)($_POST['op'] ?? ''), $uid, (int)($_POST['hours'] ?? 0));
            hip_back($returnQuery, $r['ok'], $r['message']);

        case 'person_access':
            $r = hubinet_person_access($conn, (int)($_POST['person_id'] ?? 0), (string)($_POST['mode'] ?? ''), $uid);
            hip_back($returnQuery, $r['ok'], $r['message']);

        case 'add_person_device':
            // One or several devices: "MAC [label]" per line (a single MAC field still works).
            $lines = hubnet_device_rows_text($_POST, 'devices');
            if (trim($lines) === '' && trim((string)($_POST['mac_address'] ?? '')) !== '') {
                $lines = trim((string)$_POST['mac_address']) . ' ' . trim((string)($_POST['label'] ?? ''));
            }
            $parsed = hubnet_parse_device_lines($lines);
            if ($parsed['errors']) {
                hip_back($returnQuery, false, implode(' ', array_slice($parsed['errors'], 0, 3)));
            }
            $r = hubinet_add_devices($conn, (int)($_POST['person_id'] ?? 0), $parsed['devices'], $uid);
            hip_back($returnQuery, $r['ok'], $r['message']);

        case 'remove_person_device':
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $devices = hubnet_devices_where($conn, 'd.device_id = ? AND d.person_id IS NOT NULL', 'i', [$deviceId]);
            if (!$devices) {
                hip_back($returnQuery, false, 'Device not found.');
            }
            hubnet_apply($conn, $devices, $uid, 'device-remove', 'remove');
            $stmt = $conn->prepare('DELETE FROM hub_network_devices WHERE device_id = ?');
            $stmt->bind_param('i', $deviceId);
            $stmt->execute();
            $stmt->close();
            hip_back($returnQuery, true, 'Device ' . $devices[0]['mac_address'] . ' removed.');

        case 'devices_bulk':
            // Internet access for selected devices (any owner): auto / allow / block.
            $mode = (string)($_POST['mode'] ?? '');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0)));
            if (!$ids || !in_array($mode, ['auto', 'allow', 'block'], true)) {
                hip_back($returnQuery, false, 'Select devices and an access mode.');
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("UPDATE hub_network_devices SET access_mode = ? WHERE device_id IN ($marks)");
            $stmt->bind_param('s' . str_repeat('i', count($ids)), $mode, ...$ids);
            $stmt->execute();
            $stmt->close();
            $net = hubnet_apply($conn, hubnet_devices_where($conn, "d.device_id IN ($marks)", str_repeat('i', count($ids)), $ids), $uid, 'bulk-' . $mode);
            $label = ['auto' => 'set to automatic', 'allow' => 'set to always on', 'block' => 'disconnected'][$mode];
            hip_back($returnQuery, true, count($ids) . ' device(s) ' . $label . '. ' . $net['message']);

        case 'portal_switch':
            $r = hubinet_set_portal($conn, (string)($_POST['portal'] ?? ''), $uid);
            hip_back($returnQuery, $r['ok'], $r['message']);

        case 'save_hotspot':
            $r = hubinet_save_hotspot($conn, $_POST, $uid);
            hip_back('view=hotspot&tab=settings', $r['ok'], $r['message']);

        case 'download_pfsense_page':
            // Branded portal / error page to upload to the pfSense captive portal zone.
            $kind = (string)($_POST['page'] ?? '') === 'error' ? 'error' : 'portal';
            $html = hubinet_pfsense_page(hubnet_settings($conn), $kind);
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . ($kind === 'error' ? 'ims-portal-error.html' : 'ims-portal-page.html') . '"');
            header('Content-Length: ' . strlen($html));
            header('Cache-Control: no-store');
            echo $html;
            exit;

        case 'unknown_bulk':
            // Unknown devices selected in Online now: block (disconnect) or forget them.
            $macs = array_values(array_unique(array_filter(array_map(
                static fn($m) => hubnet_normalize_mac((string)$m), (array)($_POST['macs'] ?? [])))));
            if (!$macs) {
                hip_back($returnQuery, false, 'Select unknown devices first.');
            }
            if ($_POST['unknown_op'] === 'equipment') {
                $r = hubinet_register_equipment($conn, $macs, (string)($_POST['equipment_type'] ?? 'access_point'), [], $uid);
                hip_back($returnQuery, $r['ok'], $r['message']);
            }
            if ($_POST['unknown_op'] === 'forget') {
                $n = hubinet_forget_online($conn, $macs);
                hip_back($returnQuery, true, $n . ' device(s) removed from the list. Devices that are still connected appear again at the next check-in.');
            }
            $blocked = 0;
            $failed = [];
            foreach ($macs as $mac) {
                if (hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac])) {
                    continue; // registered in the meantime: use the registered-device buttons
                }
                $r = hubinet_save_person($conn, [
                    'kind' => 'guest',
                    'full_name' => 'Blocked device ' . $mac,
                    'no_expiry' => 1,
                    'note' => 'Blocked from the online devices list',
                ], $uid);
                $dev = $r['ok'] ? hubinet_add_device($conn, (int)$r['person_id'], $mac, 'Blocked', $uid, 'block') : $r;
                if ($dev['ok']) {
                    $blocked++;
                } else {
                    $failed[] = $mac;
                }
            }
            hip_back($returnQuery, $failed === [], $blocked . ' device(s) blocked and disconnected.'
                . ($failed ? ' Could not block: ' . implode(', ', $failed) . '.' : '')
                . ' To let one back in, find "Blocked device" under Guests and remove it.');

        case 'device_speed':
            // Speed limit (Mbps in the form, Kbit/s stored) for one or more devices.
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0)));
            $down = hubnet_parse_mbps($_POST['bw_down'] ?? '');
            $up = hubnet_parse_mbps($_POST['bw_up'] ?? '');
            if (!$ids) {
                hip_back($returnQuery, false, 'Select devices first.');
            }
            if ($down === false || $up === false) {
                hip_back($returnQuery, false, 'Enter the speed in Mbps (at least 0.064, e.g. 5 or 0.5), or leave it empty for no limit.');
            }
            hubinet_set_device_bw($conn, $ids, $down, $up);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $net = hubnet_apply($conn, hubnet_devices_where($conn, "d.device_id IN ($marks)", str_repeat('i', count($ids)), $ids), $uid, 'speed');
            $what = $down === null && $up === null ? 'no limit' : 'down ' . ($down ? hubnet_mbps_label($down) . ' Mbps' : 'no limit') . ', up ' . ($up ? hubnet_mbps_label($up) . ' Mbps' : 'no limit');
            hip_back($returnQuery, true, 'Speed set to ' . $what . ' for ' . count($ids) . ' device(s). ' . $net['message']);

        case 'edit_device':
            // Online now > Edit: name (managers), access mode and speed of one registered device.
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $mode = (string)($_POST['access_mode'] ?? '');
            $down = hubnet_parse_mbps($_POST['bw_down'] ?? '');
            $up = hubnet_parse_mbps($_POST['bw_up'] ?? '');
            if ($deviceId <= 0 || !hubnet_devices_where($conn, 'd.device_id = ?', 'i', [$deviceId])) {
                hip_back($returnQuery, false, 'Device not found.');
            }
            if (!in_array($mode, ['auto', 'allow', 'block'], true)) {
                hip_back($returnQuery, false, 'Choose the internet access for this device.');
            }
            if ($down === false || $up === false) {
                hip_back($returnQuery, false, 'Enter the speed in Mbps (at least 0.064, e.g. 5 or 0.5), or leave it empty for no limit.');
            }
            if ($canManage && isset($_POST['label'])) {
                $label = mb_substr(trim((string)$_POST['label']), 0, 80);
                $stmt = $conn->prepare("UPDATE hub_network_devices SET label = NULLIF(?, '') WHERE device_id = ?");
                $stmt->bind_param('si', $label, $deviceId);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare('UPDATE hub_network_devices SET access_mode = ? WHERE device_id = ?');
            $stmt->bind_param('si', $mode, $deviceId);
            $stmt->execute();
            $stmt->close();
            hubinet_set_device_bw($conn, [$deviceId], $down, $up);
            if (isset($_POST['device_type'])) {
                $type = array_key_exists((string)$_POST['device_type'], HUBINET_DEVICE_TYPES) ? (string)$_POST['device_type'] : '';
                $stmt = $conn->prepare("UPDATE hub_network_devices SET device_type = NULLIF(?, '') WHERE device_id = ?");
                $stmt->bind_param('si', $type, $deviceId);
                $stmt->execute();
                $stmt->close();
            }
            $net = hubnet_apply($conn, hubnet_devices_where($conn, 'd.device_id = ?', 'i', [$deviceId]), $uid, 'edit');
            hip_back($returnQuery, true, 'Device updated. ' . $net['message']);

        case 'import_pfmacs':
            // Online now > pfSense MAC list: bring manual pfSense entries under IMS management.
            $r = hubinet_import_pf_macs($conn, (array)($_POST['macs'] ?? []), (string)($_POST['import_as'] ?? ''),
                (string)($_POST['equipment_type'] ?? ''), (int)($_POST['person_id'] ?? 0), $uid);
            hip_back($returnQuery, $r['ok'], $r['message']);

        case 'person_to_member':
            // Guests > person > Make member: move the guest and their devices to a hub member.
            $r = hubinet_person_to_member($conn, (int)($_POST['person_id'] ?? 0), (int)($_POST['member_id'] ?? 0),
                (string)($_POST['as'] ?? 'team'), (string)($_POST['job_title'] ?? ''), $uid);
            hip_back($r['ok'] ? 'member_id=' . (int)$r['member_id'] : $returnQuery, $r['ok'], $r['message']);

        case 'create_vouchers':
            $r = hubinet_create_vouchers($conn, $_POST, $uid);
            hip_back('view=vouchers' . ($r['ok'] ? '&batch=' . rawurlencode((string)$r['batch']) . '&print=1' : ''), $r['ok'], $r['message']);

        case 'vouchers_bulk':
            $r = hubinet_vouchers_bulk($conn, (array)($_POST['ids'] ?? []), (string)($_POST['op'] ?? ''), $uid);
            hip_back('view=vouchers', $r['ok'], $r['message']);

        case 'register_online':
            // A device seen online that the IMS does not know: give it to a staff member / guest, or block it.
            $mac = hubnet_normalize_mac((string)($_POST['mac_address'] ?? ''));
            $as = (string)($_POST['register_as'] ?? '');
            if ($mac === '') {
                hip_back($returnQuery, false, 'Invalid MAC address.');
            }
            if ($as === 'equipment') {
                $r = hubinet_register_equipment($conn, [$mac], (string)($_POST['equipment_type'] ?? 'access_point'), [$mac => (string)($_POST['label'] ?? '')], $uid);
                hip_back($returnQuery, $r['ok'], $r['message']);
            }
            if ($as === 'existing') {
                $r = hubinet_add_device($conn, (int)($_POST['person_id'] ?? 0), $mac, (string)($_POST['label'] ?? ''), $uid);
                hip_back($returnQuery, $r['ok'], $r['message']);
            }
            if ($as === 'block' || $as === 'guest' || $as === 'staff') {
                $post = [
                    'kind' => $as === 'staff' ? 'staff' : 'guest',
                    'full_name' => trim((string)($_POST['full_name'] ?? '')) ?: ($as === 'block' ? 'Blocked device ' . $mac : ''),
                    'phone' => (string)($_POST['phone'] ?? ''),
                    'user_id' => (int)($_POST['user_id'] ?? 0),
                    'valid_until' => (string)($_POST['valid_until'] ?? ''),
                    'no_expiry' => $as === 'block' ? 1 : (int)!empty($_POST['no_expiry']),
                    'note' => $as === 'block' ? 'Blocked from the online devices list' : '',
                ];
                $r = hubinet_save_person($conn, $post, $uid);
                if (!$r['ok']) {
                    hip_back($returnQuery, false, $r['message']);
                }
                $dev = hubinet_add_device($conn, (int)$r['person_id'], $mac, (string)($_POST['label'] ?? ''), $uid, $as === 'block' ? 'block' : 'auto');
                hip_back($returnQuery, $dev['ok'], ($as === 'block' ? 'Device ' . $mac . ' blocked. ' : $r['message'] . ' ') . $dev['message']);
            }
            hip_back($returnQuery, false, 'Choose how to register this device.');
    }
} catch (Throwable $e) {
    error_log('[hub-internet-process] ' . $action . ': ' . $e->getMessage());
    hip_back($returnQuery, false, 'Something went wrong. Please try again.');
}
