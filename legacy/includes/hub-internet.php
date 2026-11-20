<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Hub internet: staff & guests, internet switch, guest hotspot, live clients
|--------------------------------------------------------------------------
| Builds on includes/hub-network.php (devices, pfSense agent, pull feed).
|
| People who are not hub members (hub_network_people):
|   staff  - linked to an IMS user; online while Active
|   guest  - added by staff or self-registered on the hotspot; online while
|            Active and before valid_until (hotspot guests get N hours)
| Their devices live in hub_network_devices with person_id (member_id NULL)
| and follow the same access modes (auto / allow / block).
|
| Internet switch (hub_network_settings.portal_control):
|   unmanaged - the IMS leaves the captive portal as configured in pfSense
|   on        - captive portal enforced: only registered/allowed devices
|               (and hotspot guests) get online
|   off       - captive portal disabled: open internet for everyone
| The pfSense agent (tools/pfsense/ims-cp-agent.php 2.1+) applies it.
|
| Live clients: at every check-in the agent reports the devices it sees
| (ARP table of the portal interfaces + portal sessions). They are kept in
| hub_network_online and counted per hour in hub_network_stats.
*/

require_once __DIR__ . '/hub-network.php';

const HUBINET_ONLINE_STALE_MINUTES = 5;

function hubinet_h(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hubinet_result(bool $ok, string $message, array $extra = []): array
{
    return ['ok' => $ok, 'message' => $message] + $extra;
}

/* ======================================================================
 | People (staff and guests)
 * ==================================================================== */

/** People of one kind with device counts. Filters: q, status (active|expired|suspended). */
function hubinet_people(mysqli $conn, string $kind, array $filters = []): array
{
    $kind = $kind === 'staff' ? 'staff' : 'guest';
    $where = ['p.kind = ?'];
    $types = 's';
    $params = [$kind];

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_') . '%';
        $where[] = '(p.full_name LIKE ? OR p.email LIKE ? OR p.phone LIKE ? OR p.organisation LIKE ?
                    OR EXISTS (SELECT 1 FROM hub_network_devices dd WHERE dd.person_id = p.person_id AND dd.mac_address LIKE ?))';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $status = (string)($filters['status'] ?? '');
    if ($status === 'active') {
        $where[] = "p.status = 'Active' AND (p.valid_until IS NULL OR p.valid_until >= NOW())";
    } elseif ($status === 'expired') {
        $where[] = 'p.valid_until IS NOT NULL AND p.valid_until < NOW()';
    } elseif ($status === 'suspended') {
        $where[] = "p.status = 'Suspended'";
    }
    if (($filters['source'] ?? '') === 'hotspot') {
        $where[] = "p.source = 'hotspot'";
    }

    $stmt = $conn->prepare(
        'SELECT p.*, u.role AS user_role, c.full_name AS created_by_name,
                (SELECT COUNT(*) FROM hub_network_devices d WHERE d.person_id = p.person_id) AS device_count,
                (SELECT COUNT(*) FROM hub_network_devices d JOIN hub_network_online o ON o.mac_address = d.mac_address
                  WHERE d.person_id = p.person_id AND o.last_seen >= DATE_SUB(NOW(), INTERVAL ' . HUBINET_ONLINE_STALE_MINUTES . ' MINUTE)) AS online_count
           FROM hub_network_people p
           LEFT JOIN users u ON u.user_id = p.user_id
           LEFT JOIN users c ON c.user_id = p.created_by
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY (p.status = \'Active\' AND (p.valid_until IS NULL OR p.valid_until >= NOW())) DESC, p.created_at DESC
          LIMIT 2000'
    );
    if (!$stmt) {
        error_log('[hub-internet] people: ' . $conn->error);
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function hubinet_person(mysqli $conn, int $personId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM hub_network_people WHERE person_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** Devices of one person (rows from hubnet_device_sql). */
function hubinet_person_devices(mysqli $conn, int $personId): array
{
    return hubnet_devices_where($conn, 'd.person_id = ?', 'i', [$personId]);
}

/** Active staff accounts that are not registered for internet yet. */
function hubinet_staff_candidates(mysqli $conn): array
{
    $roles = defined('IMS_STAFF_ROLES') ? IMS_STAFF_ROLES : ['Administrator', 'Staff'];
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $notRegistered = "u.is_active = 1 AND u.role IN ($marks)
            AND NOT EXISTS (SELECT 1 FROM hub_network_people p WHERE p.kind = 'staff' AND p.user_id = u.user_id)";
    // Details for filling in the form: the employee profile first, then the user account.
    $stmt = $conn->prepare(
        "SELECT u.user_id, COALESCE(NULLIF(ed.full_name, ''), u.full_name) AS full_name,
                COALESCE(NULLIF(ed.company_email, ''), u.email) AS email,
                COALESCE(NULLIF(ed.phone, ''), NULLIF(u.phone, ''), '') AS phone,
                u.role, ed.job_title, d.department_name
           FROM users u
           LEFT JOIN employee_directory ed ON ed.employee_id = (SELECT MAX(e2.employee_id) FROM employee_directory e2 WHERE e2.user_id = u.user_id)
           LEFT JOIN departments d ON d.department_id = ed.department_id
          WHERE $notRegistered
          ORDER BY full_name"
    );
    if (!$stmt) {
        // Installations without employee profiles: account details only.
        $stmt = $conn->prepare("SELECT u.user_id, u.full_name, u.email, '' AS phone, u.role, NULL AS job_title, NULL AS department_name
                                  FROM users u WHERE $notRegistered ORDER BY u.full_name");
    }
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param(str_repeat('s', count($roles)), ...$roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Parses "YYYY-MM-DD" or "YYYY-MM-DDTHH:MM" into a DATETIME string, or null. */
function hubinet_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime(strlen($value) === 10 ? $value . ' 23:59:59' : $value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/**
 * Create or update a staff member / guest. Staff can be picked from IMS
 * users (user_id) or typed in. Returns ['ok','message','person_id'].
 */
function hubinet_save_person(mysqli $conn, array $post, int $uid): array
{
    $personId = (int)($post['person_id'] ?? 0);
    $existing = $personId > 0 ? hubinet_person($conn, $personId) : null;
    if ($personId > 0 && !$existing) {
        return hubinet_result(false, 'Person not found.');
    }
    $kind = $existing['kind'] ?? (($post['kind'] ?? '') === 'staff' ? 'staff' : 'guest');

    $userId = (int)($post['user_id'] ?? ($existing['user_id'] ?? 0));
    $name = trim((string)($post['full_name'] ?? ''));
    $email = trim((string)($post['email'] ?? ''));
    if ($kind === 'staff' && $userId > 0 && ($name === '' || $email === '')) {
        $stmt = $conn->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($user) {
                $name = $name !== '' ? $name : (string)$user['full_name'];
                $email = $email !== '' ? $email : (string)$user['email'];
            }
        }
    }
    if ($name === '') {
        return hubinet_result(false, 'Enter the person\'s name.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return hubinet_result(false, 'Enter a valid email address or leave it empty.');
    }
    $phone = mb_substr(trim((string)($post['phone'] ?? '')), 0, 40);
    $org = mb_substr(trim((string)($post['organisation'] ?? '')), 0, 150);
    $note = mb_substr(trim((string)($post['note'] ?? '')), 0, 255);
    $status = ($post['status'] ?? ($existing['status'] ?? 'Active')) === 'Suspended' ? 'Suspended' : 'Active';
    $validUntil = $kind === 'guest' ? hubinet_datetime($post['valid_until'] ?? null) : null;
    if ($kind === 'guest' && $validUntil === null && !$existing && empty($post['no_expiry'])) {
        $validUntil = date('Y-m-d 23:59:59'); // guests default to end of today
    }
    $userIdOrNull = $kind === 'staff' && $userId > 0 ? $userId : null;
    $name = mb_substr($name, 0, 150);
    $email = mb_substr($email, 0, 190);

    // Devices added with a new person: "MAC [label]" per line. Checked before the person is
    // created, so a typo or an already-registered device never leaves a half-added person.
    $newDevices = [];
    if (!$existing) {
        $lines = (string)($post['devices'] ?? '');
        if (trim($lines) === '' && trim((string)($post['mac_address'] ?? '')) !== '') {
            $lines = trim((string)$post['mac_address']) . ' ' . trim((string)($post['label'] ?? ''));
        }
        if (trim($lines) !== '') {
            $parsed = hubnet_parse_device_lines($lines);
            if ($parsed['errors']) {
                return hubinet_result(false, implode(' ', array_slice($parsed['errors'], 0, 3)));
            }
            $taken = hubinet_taken_macs($conn, array_column($parsed['devices'], 'mac'));
            if ($taken) {
                $mac = (string)array_key_first($taken);
                return hubinet_result(false, $mac . ' is already registered to ' . $taken[$mac] . '. Nothing was saved.');
            }
            $newDevices = $parsed['devices'];
        }
    }

    if ($existing) {
        $stmt = $conn->prepare('UPDATE hub_network_people SET user_id = ?, full_name = ?, email = NULLIF(?, \'\'), phone = NULLIF(?, \'\'),
                                organisation = NULLIF(?, \'\'), note = NULLIF(?, \'\'), status = ?, valid_until = ? WHERE person_id = ?');
        $stmt->bind_param('isssssssi', $userIdOrNull, $name, $email, $phone, $org, $note, $status, $validUntil, $personId);
    } else {
        if ($kind === 'staff' && $userIdOrNull !== null) {
            $dup = $conn->prepare("SELECT person_id FROM hub_network_people WHERE kind = 'staff' AND user_id = ? LIMIT 1");
            $dup->bind_param('i', $userIdOrNull);
            $dup->execute();
            $found = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($found) {
                return hubinet_result(false, 'This staff member is already registered.', ['person_id' => (int)$found['person_id']]);
            }
        }
        $stmt = $conn->prepare('INSERT INTO hub_network_people (kind, user_id, full_name, email, phone, organisation, note, status, valid_until, created_by)
                                VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?)');
        $stmt->bind_param('sisssssssi', $kind, $userIdOrNull, $name, $email, $phone, $org, $note, $status, $validUntil, $uid);
    }
    if (!$stmt || !$stmt->execute()) {
        error_log('[hub-internet] save person: ' . $conn->error);
        return hubinet_result(false, 'The person could not be saved.');
    }
    $personId = $existing ? $personId : (int)$stmt->insert_id;
    $stmt->close();

    $note2 = '';
    if ($newDevices) {
        $dev = hubinet_add_devices($conn, $personId, $newDevices, $uid, (string)($post['device_mode'] ?? 'auto'));
        $note2 = ' ' . $dev['message'];
    }
    if ($existing) {
        hubnet_apply($conn, hubinet_person_devices($conn, $personId), $uid, 'person');
    }
    if (function_exists('log_action')) {
        log_action($uid, $existing ? 'Update hub internet ' . $kind : 'Add hub internet ' . $kind, 'hub_network_people', $personId, $name);
    }

    return hubinet_result(true, ($existing ? 'Saved ' : 'Added ') . $name . '.' . $note2, ['person_id' => $personId]);
}

/** Which of these MACs are already registered: [mac => "name (owner)"]. */
function hubinet_taken_macs(mysqli $conn, array $macs): array
{
    $taken = [];
    foreach ($macs as $mac) {
        $rows = hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac]);
        if ($rows) {
            $o = $rows[0];
            $taken[$mac] = ($o['person_name'] ?: 'someone else') . ' (' . ($o['owner_kind'] === 'member' ? ($o['company_name'] ?: 'hub member') : $o['owner_kind']) . ')';
        }
    }
    return $taken;
}

/**
 * Registers several devices for a staff member or guest in one go.
 * $devices: [['mac' => .., 'label' => ..], ...] (from hubnet_parse_device_lines).
 * All addresses are checked first; nothing is saved if one is already registered.
 */
function hubinet_add_devices(mysqli $conn, int $personId, array $devices, int $uid, string $mode = 'auto'): array
{
    if (!hubinet_person($conn, $personId)) {
        return hubinet_result(false, 'Person not found.');
    }
    if (!$devices) {
        return hubinet_result(false, 'Enter at least one MAC address (e.g. 3C:22:FB:12:34:56).');
    }
    $taken = hubinet_taken_macs($conn, array_column($devices, 'mac'));
    if ($taken) {
        $list = [];
        foreach ($taken as $mac => $who) {
            $list[] = $mac . ' is already registered to ' . $who;
        }
        return hubinet_result(false, implode('; ', array_slice($list, 0, 3)) . '. Nothing was saved.');
    }
    $ids = [];
    foreach ($devices as $dev) {
        $r = hubinet_add_device($conn, $personId, (string)$dev['mac'], (string)($dev['label'] ?? ''), $uid, $mode, false);
        if (!$r['ok']) {
            return hubinet_result(false, $r['message'] . ($ids ? ' ' . count($ids) . ' device(s) before it were saved.' : ''));
        }
        $ids[] = (int)$r['device_id'];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $applied = hubnet_apply($conn, hubnet_devices_where($conn, "d.device_id IN ($marks)", str_repeat('i', count($ids)), $ids), $uid, 'add');
    $n = count($ids);
    return hubinet_result(true, ($n === 1 ? 'Device ' . $devices[0]['mac'] . ' registered. ' : $n . ' devices registered. ') . $applied['message'], ['device_ids' => $ids]);
}

/** Registers a device (MAC) for a staff member or guest. $apply=false when the caller applies a batch. */
function hubinet_add_device(mysqli $conn, int $personId, string $mac, string $label, int $uid, string $mode = 'auto', bool $apply = true): array
{
    $person = hubinet_person($conn, $personId);
    if (!$person) {
        return hubinet_result(false, 'Person not found.');
    }
    $mac = hubnet_normalize_mac($mac);
    if ($mac === '') {
        return hubinet_result(false, 'Enter a valid MAC address (e.g. 3C:22:FB:12:34:56).');
    }
    $existing = hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac]);
    if ($existing) {
        $owner = $existing[0];
        return hubinet_result(false, 'This device is already registered to ' . ($owner['person_name'] ?: 'someone else')
            . ' (' . ($owner['owner_kind'] === 'member' ? ($owner['company_name'] ?: 'hub member') : $owner['owner_kind']) . ').');
    }
    $mode = in_array($mode, ['auto', 'allow', 'block'], true) ? $mode : 'auto';
    $label = mb_substr(trim($label), 0, 80);
    $stmt = $conn->prepare('INSERT INTO hub_network_devices (member_id, team_member_id, person_id, mac_address, label, access_mode, created_by)
                            VALUES (NULL, NULL, ?, ?, NULLIF(?, \'\'), ?, NULLIF(?, 0))');
    if (!$stmt) {
        return hubinet_result(false, 'The device could not be saved.');
    }
    $stmt->bind_param('isssi', $personId, $mac, $label, $mode, $uid);
    if (!$stmt->execute()) {
        $stmt->close();
        return hubinet_result(false, 'The device could not be saved (it may already be registered).');
    }
    $deviceId = (int)$stmt->insert_id;
    $stmt->close();
    if (!$apply) {
        return hubinet_result(true, 'Device ' . $mac . ' registered.', ['device_id' => $deviceId]);
    }
    $applied = hubnet_apply($conn, hubnet_devices_where($conn, 'd.device_id = ?', 'i', [$deviceId]), $uid, 'add');
    return hubinet_result(true, 'Device ' . $mac . ' registered. ' . $applied['message'], ['device_id' => $deviceId]);
}

/** Deletes a person and their devices (pfSense entries are removed first in push mode). */
function hubinet_remove_person(mysqli $conn, int $personId, int $uid): array
{
    $person = hubinet_person($conn, $personId);
    if (!$person) {
        return hubinet_result(false, 'Person not found.');
    }
    $devices = hubinet_person_devices($conn, $personId);
    if ($devices) {
        hubnet_apply($conn, $devices, $uid, 'remove', 'remove');
    }
    $stmt = $conn->prepare('DELETE FROM hub_network_devices WHERE person_id = ?');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('DELETE FROM hub_network_people WHERE person_id = ?');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $stmt->close();
    if (function_exists('log_action')) {
        log_action($uid, 'Remove hub internet ' . $person['kind'], 'hub_network_people', $personId, (string)$person['full_name']);
    }
    return hubinet_result(true, $person['full_name'] . ' and ' . count($devices) . ' device(s) removed.');
}

/** Bulk actions on people: suspend, activate, extend (guests, hours), remove. */
function hubinet_people_bulk(mysqli $conn, array $personIds, string $op, int $uid, int $hours = 0): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $personIds), fn($i) => $i > 0)));
    if (!$ids) {
        return hubinet_result(false, 'Select at least one person.');
    }
    $done = 0;
    foreach ($ids as $id) {
        if ($op === 'remove') {
            $done += hubinet_remove_person($conn, $id, $uid)['ok'] ? 1 : 0;
            continue;
        }
        $person = hubinet_person($conn, $id);
        if (!$person) {
            continue;
        }
        if ($op === 'suspend' || $op === 'activate') {
            $status = $op === 'suspend' ? 'Suspended' : 'Active';
            $stmt = $conn->prepare('UPDATE hub_network_people SET status = ? WHERE person_id = ?');
            $stmt->bind_param('si', $status, $id);
        } elseif ($op === 'extend' && $person['kind'] === 'guest') {
            $hours = max(1, min(24 * 90, $hours));
            $stmt = $conn->prepare("UPDATE hub_network_people SET status = 'Active',
                                    valid_until = DATE_ADD(GREATEST(COALESCE(valid_until, NOW()), NOW()), INTERVAL ? HOUR) WHERE person_id = ?");
            $stmt->bind_param('ii', $hours, $id);
        } else {
            continue;
        }
        if ($stmt && $stmt->execute()) {
            $done++;
            hubnet_apply($conn, hubinet_person_devices($conn, $id), $uid, 'person-' . $op);
        }
        $stmt?->close();
    }
    $verb = ['suspend' => 'Suspended', 'activate' => 'Re-activated', 'extend' => 'Extended', 'remove' => 'Removed'][$op] ?? 'Updated';
    return hubinet_result($done > 0, $done > 0 ? "{$verb} {$done} " . ($done === 1 ? 'person' : 'people') . '.' : 'Nothing changed.');
}

/** Access mode for every device of a person (auto / allow / block). */
function hubinet_person_access(mysqli $conn, int $personId, string $mode, int $uid): array
{
    if (!in_array($mode, ['auto', 'allow', 'block'], true)) {
        return hubinet_result(false, 'Unknown access mode.');
    }
    $stmt = $conn->prepare('UPDATE hub_network_devices SET access_mode = ? WHERE person_id = ?');
    $stmt->bind_param('si', $mode, $personId);
    $stmt->execute();
    $stmt->close();
    $r = hubnet_apply($conn, hubinet_person_devices($conn, $personId), $uid, 'access');
    return hubinet_result(true, ['auto' => 'Automatic access', 'allow' => 'Always on', 'block' => 'Disconnected'][$mode] . '. ' . $r['message']);
}

/** Removes expired hotspot guests (and their devices) N days after expiry. */
function hubinet_purge_hotspot_guests(mysqli $conn, int $days = 30): int
{
    $res = $conn->query("SELECT person_id FROM hub_network_people
                          WHERE kind = 'guest' AND source = 'hotspot' AND valid_until IS NOT NULL
                            AND valid_until < DATE_SUB(NOW(), INTERVAL " . max(1, $days) . ' DAY)');
    $n = 0;
    while ($res && ($row = $res->fetch_assoc())) {
        $n += hubinet_remove_person($conn, (int)$row['person_id'], 0)['ok'] ? 1 : 0;
    }
    return $n;
}

/* ======================================================================
 | Internet switch & hotspot settings
 * ==================================================================== */

function hubinet_set_portal(mysqli $conn, string $mode, int $uid): array
{
    if (!in_array($mode, ['unmanaged', 'on', 'off'], true)) {
        return hubinet_result(false, 'Unknown internet mode.');
    }
    $stmt = $conn->prepare('UPDATE hub_network_settings SET portal_control = ?, updated_by = ?, updated_at = NOW() WHERE id = 1');
    $stmt->bind_param('si', $mode, $uid);
    $ok = $stmt->execute();
    $stmt->close();
    hubnet_log($conn, null, '', 'portal:' . $mode, (bool)$ok, 'Internet switch set to ' . $mode, $uid);
    $settings = hubnet_settings($conn);
    $when = $settings['mode'] === 'pull' ? ' pfSense applies it at its next check-in (within about a minute).' : '';
    if (!$settings['enabled']) {
        $when = ' pfSense control is switched off, so nothing is sent until it is switched on (Settings > pfSense).';
    }
    $text = ['on' => 'Internet control ON: only registered devices and hotspot guests get online.',
        'off' => 'Internet is OPEN: the captive portal is switched off for everyone.',
        'unmanaged' => 'The IMS no longer changes the captive portal on/off state.'][$mode];
    return hubinet_result((bool)$ok, $text . $when);
}

function hubinet_save_hotspot(mysqli $conn, array $post, int $uid): array
{
    $enabled = !empty($post['hotspot_enabled']) ? 1 : 0;
    $hours = max(1, min(720, (int)($post['hotspot_hours'] ?? 8)));
    $requirePhone = !empty($post['hotspot_require_phone']) ? 1 : 0;
    $terms = mb_substr(trim((string)($post['hotspot_terms'] ?? '')), 0, 4000);
    $ssid = mb_substr(trim((string)($post['wifi_ssid'] ?? '')), 0, 64);
    $security = in_array($post['wifi_security'] ?? '', ['WPA', 'WEP', 'nopass'], true) ? (string)$post['wifi_security'] : 'WPA';
    $enc = static fn(string $v): string => $v === '' ? '' : (function_exists('ims_encrypt') ? ims_encrypt($v) : $v);

    $landing = trim((string)($post['hotspot_landing_url'] ?? ''));
    if ($landing !== '' && !preg_match('#\Ahttps?://[^\s<>"\']+\z#i', $landing)) {
        return hubinet_result(false, 'The landing page must be a full web address starting with https:// or http://.');
    }
    $landing = mb_substr($landing, 0, 255);
    $verify = !empty($post['hotspot_verify']) ? 1 : 0;

    $sets = ['hotspot_enabled = ?', 'hotspot_hours = ?', 'hotspot_require_phone = ?', 'hotspot_terms = ?', 'wifi_ssid = ?', 'wifi_security = ?',
        'hotspot_landing_url = NULLIF(?, \'\')', 'hotspot_verify = ?'];
    $types = 'iiissssi';
    $params = [$enabled, $hours, $requirePhone, $terms, $ssid, $security, $landing, $verify];
    // Passwords change only when a new one is typed (or cleared explicitly).
    $newPass = (string)($post['hotspot_password'] ?? '');
    if ($newPass !== '' || !empty($post['clear_hotspot_password'])) {
        if ($newPass !== '' && mb_strlen($newPass) < 4) {
            return hubinet_result(false, 'The hotspot password needs at least 4 characters.');
        }
        $sets[] = 'hotspot_password = ?';
        $types .= 's';
        $params[] = $enc($newPass);
    }
    $newKey = (string)($post['wifi_key'] ?? '');
    if ($newKey !== '' || !empty($post['clear_wifi_key'])) {
        $sets[] = 'wifi_key = ?';
        $types .= 's';
        $params[] = $enc($newKey);
    }
    $sets[] = 'updated_by = ?';
    $sets[] = 'updated_at = NOW()';
    $types .= 'i';
    $params[] = $uid;
    $stmt = $conn->prepare('UPDATE hub_network_settings SET ' . implode(', ', $sets) . ' WHERE id = 1');
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $stmt->close();
    return hubinet_result((bool)$ok, $ok ? 'Hotspot settings saved.' : 'Hotspot settings could not be saved.');
}

/** "WIFI:T:WPA;S:ssid;P:key;;" for a join-the-network QR code ('' when no SSID). */
function hubinet_wifi_qr_payload(array $settings): string
{
    if ($settings['wifi_ssid'] === '') {
        return '';
    }
    $esc = static fn(string $v): string => preg_replace('/([\\\\;,:"])/', '\\\\$1', $v);
    $type = $settings['wifi_security'];
    return 'WIFI:T:' . $type . ';S:' . $esc($settings['wifi_ssid']) . ';'
        . ($type !== 'nopass' && $settings['wifi_key'] !== '' ? 'P:' . $esc($settings['wifi_key']) . ';' : '')
        . ';';
}

function hubinet_hotspot_url(): string
{
    $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
    if ($base === '') {
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/hotspot';
}

/* ======================================================================
 | Hotspot sign-in (public page hotspot.php)
 * ==================================================================== */

/**
 * Is this MAC (and IP) a device pfSense currently sees on the hub network?
 *   yes      - reported at a recent check-in (and the IP matches when known)
 *   mismatch - reported, but with a different IP: not the requesting device
 *   pending  - not reported yet (a device that just joined appears within a
 *              minute): the page retries
 *   skip     - cannot check: verification switched off, or the agent is older
 *              than 2.1 and does not report devices
 */
function hubinet_device_on_network(mysqli $conn, array $settings, string $mac, string $ip): string
{
    $agent = (string)($settings['last_checkin_info']['version'] ?? '');
    if (!$settings['hotspot_verify'] || $agent === '' || version_compare($agent, '2.1', '<')) {
        return 'skip';
    }
    $stmt = $conn->prepare('SELECT ip_address FROM hub_network_online WHERE mac_address = ?
                             AND last_seen >= DATE_SUB(NOW(), INTERVAL ' . HUBINET_ONLINE_STALE_MINUTES . ' MINUTE) LIMIT 1');
    if (!$stmt) {
        return 'skip';
    }
    $stmt->bind_param('s', $mac);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return 'pending';
    }
    $seenIp = (string)($row['ip_address'] ?? '');
    return $ip !== '' && $seenIp !== '' && $seenIp !== $ip ? 'mismatch' : 'yes';
}

/** Where guests go after signing in: the hotspot landing page, else the organisation website. */
function hubinet_hotspot_landing(array $settings): string
{
    if ($settings['hotspot_landing_url'] !== '') {
        return $settings['hotspot_landing_url'];
    }
    $site = function_exists('ims_brand') ? (string)(ims_brand()['website'] ?? '') : '';
    return preg_match('#\Ahttps?://[^\s<>"\']+\z#i', $site) ? $site : '';
}

/**
 * Registers the visitor's device as a hotspot guest for the configured
 * number of hours. $mac comes from the captive portal redirect.
 */
function hubinet_hotspot_signin(mysqli $conn, array $post, string $mac, string $ip): array
{
    $settings = hubnet_settings($conn);
    if (!$settings['hotspot_enabled']) {
        return hubinet_result(false, 'The guest hotspot is not available right now. Please ask at reception.');
    }
    $mac = hubnet_normalize_mac($mac);
    if ($mac === '') {
        return hubinet_result(false, 'We could not identify your device. Connect to the hub Wi-Fi first, then open any website to get here.');
    }
    $name = mb_substr(trim((string)($post['full_name'] ?? '')), 0, 150);
    $phone = mb_substr(trim((string)($post['phone'] ?? '')), 0, 40);
    $email = mb_substr(trim((string)($post['email'] ?? '')), 0, 190);
    if ($name === '') {
        return hubinet_result(false, 'Please enter your name.');
    }
    if ($settings['hotspot_require_phone'] && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) {
        return hubinet_result(false, 'Please enter a valid phone number.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return hubinet_result(false, 'Please enter a valid email address or leave it empty.');
    }
    if ($settings['hotspot_password'] !== '' && !hash_equals($settings['hotspot_password'], (string)($post['hotspot_password'] ?? ''))) {
        return hubinet_result(false, 'That hotspot password is not correct. Ask at reception for today\'s password.');
    }
    if ($settings['hotspot_terms'] !== '' && empty($post['accept_terms'])) {
        return hubinet_result(false, 'Please accept the terms of use.');
    }

    // Only devices pfSense sees on the hub network can be signed in, so the
    // hotspot cannot be used from outside to whitelist an arbitrary MAC.
    $check = hubinet_device_on_network($conn, $settings, $mac, $ip);
    if ($check === 'pending') {
        return hubinet_result(false, 'Checking your device on the hub network...', ['pending' => true]);
    }
    if ($check === 'mismatch') {
        hubnet_log($conn, null, $mac, 'hotspot:refused', false, 'Device/IP not seen on the hub network (' . $ip . ')', 0);
        return hubinet_result(false, 'This sign-in must be done from the device that is connected to the hub Wi-Fi.');
    }

    $until = date('Y-m-d H:i:s', time() + $settings['hotspot_hours'] * 3600);
    $existing = hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac]);
    if ($existing) {
        $dev = $existing[0];
        if ($dev['owner_kind'] !== 'guest') {
            return hubinet_result(true, 'This device is already registered with the hub. You should be online within a minute.', ['until' => null]);
        }
        if ($dev['access_mode'] === 'block' || ($dev['person_status'] ?? '') === 'Suspended') {
            return hubinet_result(false, 'This device has been blocked from the hotspot. Please ask at reception.');
        }
        $pid = (int)$dev['person_id'];
        $stmt = $conn->prepare("UPDATE hub_network_people SET full_name = ?, phone = NULLIF(?, ''), email = NULLIF(?, ''),
                                valid_until = GREATEST(COALESCE(valid_until, NOW()), ?) WHERE person_id = ?");
        $stmt->bind_param('ssssi', $name, $phone, $email, $until, $pid);
        $stmt->execute();
        $stmt->close();
        hubnet_log($conn, (int)$dev['device_id'], $mac, 'hotspot:renew', true, $name . ' ' . $ip, 0);
        return hubinet_result(true, 'Welcome back, ' . $name . '!', ['until' => $until]);
    }

    $stmt = $conn->prepare("INSERT INTO hub_network_people (kind, full_name, email, phone, status, valid_until, source, note)
                            VALUES ('guest', ?, NULLIF(?, ''), NULLIF(?, ''), 'Active', ?, 'hotspot', ?)");
    $note = mb_substr('Hotspot sign-in from ' . $ip, 0, 255);
    $stmt->bind_param('sssss', $name, $email, $phone, $until, $note);
    if (!$stmt->execute()) {
        $stmt->close();
        return hubinet_result(false, 'We could not sign you in. Please try again.');
    }
    $personId = (int)$stmt->insert_id;
    $stmt->close();
    $dev = hubinet_add_device($conn, $personId, $mac, 'Hotspot', 0);
    if (!$dev['ok']) {
        return $dev;
    }
    hubnet_log($conn, (int)$dev['device_id'], $mac, 'hotspot:signin', true, $name . ' ' . $ip, 0);
    return hubinet_result(true, 'Welcome, ' . $name . '!', ['until' => $until]);
}

/* ======================================================================
 | Live clients and history (reported by the pfSense agent)
 * ==================================================================== */

/**
 * Stores the devices pfSense sees. $clients: [{mac, ip}] from the ARP table
 * of the portal interfaces; $sessions: [{mac, ip, username, started}] from
 * the captive portal. Rows not seen for a while drop out of "online".
 */
function hubinet_record_online(mysqli $conn, array $clients, array $sessions): int
{
    $seen = [];
    foreach (array_slice($clients, 0, 5000) as $c) {
        $mac = hubnet_normalize_mac((string)($c['mac'] ?? ''));
        if ($mac !== '') {
            $seen[$mac] = ['ip' => mb_substr((string)($c['ip'] ?? ''), 0, 45), 'user' => null, 'portal' => 0, 'started' => null];
        }
    }
    foreach (array_slice($sessions, 0, 5000) as $s) {
        $mac = hubnet_normalize_mac((string)($s['mac'] ?? ''));
        if ($mac === '') {
            continue;
        }
        $started = !empty($s['started']) && is_numeric($s['started']) ? date('Y-m-d H:i:s', (int)$s['started']) : null;
        $seen[$mac] = [
            'ip' => mb_substr((string)($s['ip'] ?? ($seen[$mac]['ip'] ?? '')), 0, 45),
            'user' => mb_substr((string)($s['username'] ?? ''), 0, 120) ?: null,
            'portal' => 1,
            'started' => $started,
        ];
    }

    $up = $conn->prepare('INSERT INTO hub_network_online (mac_address, ip_address, portal_user, in_portal, session_started, first_seen, last_seen)
                          VALUES (?, NULLIF(?, \'\'), ?, ?, ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address), portal_user = VALUES(portal_user), in_portal = VALUES(in_portal),
                                                  session_started = COALESCE(VALUES(session_started), session_started), last_seen = NOW()');
    if ($up) {
        foreach ($seen as $mac => $r) {
            $up->bind_param('sssis', $mac, $r['ip'], $r['user'], $r['portal'], $r['started']);
            $up->execute();
        }
        $up->close();
    }
    // Forget devices not seen for a day; "online" uses a few minutes' window.
    $conn->query('DELETE FROM hub_network_online WHERE last_seen < DATE_SUB(NOW(), INTERVAL 1 DAY)');

    $count = count($seen);
    $portal = count(array_filter($seen, fn($r) => $r['portal'] === 1));
    $hour = date('Y-m-d H:00:00');
    $st = $conn->prepare('INSERT INTO hub_network_stats (hour_start, max_online, sum_online, samples, max_portal) VALUES (?, ?, ?, 1, ?)
                          ON DUPLICATE KEY UPDATE max_online = GREATEST(max_online, VALUES(max_online)), sum_online = sum_online + VALUES(sum_online),
                                                  samples = samples + 1, max_portal = GREATEST(max_portal, VALUES(max_portal))');
    if ($st) {
        $st->bind_param('siii', $hour, $count, $count, $portal);
        $st->execute();
        $st->close();
    }
    $conn->query('DELETE FROM hub_network_stats WHERE hour_start < DATE_SUB(NOW(), INTERVAL 180 DAY)');

    return $count;
}

/** Devices online now, identified against registered devices. Filter: kind (member|staff|guest|unknown). */
function hubinet_online(mysqli $conn, string $kind = ''): array
{
    $rows = [];
    $res = $conn->query('SELECT o.* FROM hub_network_online o
                          WHERE o.last_seen >= DATE_SUB(NOW(), INTERVAL ' . HUBINET_ONLINE_STALE_MINUTES . ' MINUTE)
                          ORDER BY o.first_seen DESC LIMIT 3000');
    $online = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if (!$online) {
        return [];
    }
    $byMac = [];
    foreach (hubnet_devices_where($conn, '1=1') as $d) {
        $byMac[hubnet_normalize_mac((string)$d['mac_address'])] = $d;
    }
    foreach ($online as $o) {
        $d = $byMac[$o['mac_address']] ?? null;
        $o['device'] = $d;
        $o['owner_kind'] = $d ? (string)$d['owner_kind'] : 'unknown';
        if ($kind !== '' && $o['owner_kind'] !== $kind) {
            continue;
        }
        $rows[] = $o;
    }
    return $rows;
}

/** Counts of devices online now by owner kind. */
function hubinet_online_counts(mysqli $conn): array
{
    $counts = ['total' => 0, 'member' => 0, 'staff' => 0, 'guest' => 0, 'unknown' => 0, 'portal' => 0];
    foreach (hubinet_online($conn) as $o) {
        $counts['total']++;
        $counts[$o['owner_kind']] = ($counts[$o['owner_kind']] ?? 0) + 1;
        $counts['portal'] += (int)$o['in_portal'];
    }
    return $counts;
}

/** Hourly history: [['hour' => 'Y-m-d H:00', 'max' => n, 'avg' => n.n], ...] for the last $hours hours. */
function hubinet_history(mysqli $conn, int $hours = 24): array
{
    $hours = max(1, min(24 * 31, $hours));
    $stmt = $conn->prepare('SELECT hour_start, max_online, sum_online, samples FROM hub_network_stats
                             WHERE hour_start >= DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY hour_start');
    $stmt->bind_param('i', $hours);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byHour = [];
    foreach ($rows as $r) {
        $byHour[substr((string)$r['hour_start'], 0, 13)] = $r;
    }
    $out = [];
    for ($i = $hours - 1; $i >= 0; $i--) {
        $key = date('Y-m-d H', time() - $i * 3600);
        $r = $byHour[$key] ?? null;
        $out[] = [
            'hour' => $key . ':00',
            'max' => $r ? (int)$r['max_online'] : 0,
            'avg' => $r && (int)$r['samples'] > 0 ? round((int)$r['sum_online'] / (int)$r['samples'], 1) : 0,
        ];
    }
    return $out;
}

/** Peak devices online per day for the last $days days. */
function hubinet_daily_peaks(mysqli $conn, int $days = 14): array
{
    $days = max(1, min(180, $days));
    $stmt = $conn->prepare('SELECT DATE(hour_start) d, MAX(max_online) peak FROM hub_network_stats
                             WHERE hour_start >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(hour_start)');
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byDay = array_column($rows, 'peak', 'd');
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $out[] = ['day' => $d, 'peak' => (int)($byDay[$d] ?? 0)];
    }
    return $out;
}
