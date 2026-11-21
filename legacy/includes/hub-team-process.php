<?php
declare(strict_types=1);

/*
| Hub team members, devices, internet access, packages and pfSense settings.
| POST only (CSRF is enforced globally by config.php); redirects back to
| /hub-team with a flash message.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hub-network.php';
require_once __DIR__ . '/hub-reminders.php';

function ht_back(string $query = '', ?string $error = null, ?string $success = null): never
{
    if ($error !== null) {
        $_SESSION['error'] = $error;
    }

    if ($success !== null) {
        $_SESSION['success'] = $success;
    }

    header('Location: ../hub-team' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function ht_str(string $key, int $max = 190): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function ht_int(string $key): int
{
    return (int)($_POST[$key] ?? 0);
}

function ht_member_query(int $memberId): string
{
    return $memberId > 0 ? 'member_id=' . $memberId : '';
}

/** Network result appended to a success message. */
function ht_net_note(array $result): string
{
    return $result['applied'] === 0 && $result['failed'] === 0 && $result['ok']
        ? ''
        : ' ' . $result['message'];
}

/** @return list<string> unique, normalised MACs; throws on an invalid one */
function ht_parse_macs(string $text): array
{
    $macs = [];

    foreach (preg_split('/[\s,;]+/', $text) ?: [] as $part) {
        if (trim($part) === '') {
            continue;
        }

        $mac = hubnet_normalize_mac($part);

        if ($mac === '') {
            throw new InvalidArgumentException('"' . $part . '" is not a valid MAC address (e.g. a4:5e:60:12:ab:cd).');
        }

        $macs[$mac] = true;
    }

    return array_keys($macs);
}

function ht_mac_taken(mysqli $conn, string $mac): ?array
{
    $rows = hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac]);

    return $rows[0] ?? null;
}

function ht_assert_mac_free(mysqli $conn, string $mac): void
{
    $taken = ht_mac_taken($conn, $mac);

    if ($taken) {
        throw new InvalidArgumentException(
            $mac . ' is already registered to ' . ($taken['person_name'] ?: 'another person') .
            ' (' . ($taken['company_name'] ?: $taken['membership_number']) . ').'
        );
    }
}

function ht_insert_device(mysqli $conn, int $memberId, ?int $teamMemberId, string $mac, string $label, int $userId): int
{
    ht_assert_mac_free($conn, $mac);

    $stmt = $conn->prepare(
        'INSERT INTO hub_network_devices (member_id, team_member_id, mac_address, label, created_by) VALUES (?, ?, ?, NULLIF(?, \'\'), ?)'
    );
    $stmt->bind_param('iissi', $memberId, $teamMemberId, $mac, $label, $userId);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_SESSION['user_id'])) {
    ht_back('', 'Unauthorized request.');
}

if (!isset($conn) || !($conn instanceof mysqli) || !hubnet_ensure_schema($conn) || !hubrem_ensure_schema($conn)) {
    ht_back('', 'Hub team tables are not available. Apply database/migrations/2026_11_19_hub_teams_network.sql.');
}

$conn->set_charset('utf8mb4');

$userId = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? '');
$memberId = ht_int('member_id');

$canManage = ims_allowed('hub_team.manage', ['Administrator', 'Operations/Admin'], 'Register and edit hub team members and their devices');
$canControl = ims_allowed('hub_network.control', ['Administrator', 'Operations/Admin', 'IT Officer'], 'Connect and disconnect hub members from the internet (pfSense)');
$canSettings = ims_allowed('hub_network.settings', ['Administrator', 'IT Officer'], 'Configure hub workspace packages and the pfSense connection');
$canRemind = ims_allowed('hub_reminders.send', ['Administrator', 'Operations/Admin'], 'Send subscription reminders to hub members');
$canReminderSettings = ims_allowed('hub_reminders.settings', ['Administrator', 'Operations/Admin'], 'Configure automatic hub member reminders');

/** Where to return: a settings tab, a company page, or the dashboard. */
function ht_return_query(int $memberId, string $defaultTab = ''): string
{
    $tab = preg_replace('/[^a-z]/', '', (string)($_POST['return_tab'] ?? $defaultTab)) ?? '';

    if ((string)($_POST['return_view'] ?? '') === 'settings' || ($memberId <= 0 && $tab !== '')) {
        return 'view=settings' . ($tab !== '' ? '&tab=' . $tab : '');
    }

    return ht_member_query($memberId);
}

$needs = [
    'add_team_member' => $canManage,
    'edit_team_member' => $canManage,
    'remove_team_member' => $canManage,
    'add_device' => $canManage,
    'remove_device' => $canManage,
    'set_access' => $canControl,
    'sync_now' => $canControl,
    'save_package' => $canSettings,
    'save_network_settings' => $canSettings,
    'test_connection' => $canSettings,
    'send_reminder' => $canRemind,
    'run_reminders_now' => $canRemind,
    'save_reminder_settings' => $canReminderSettings,
];

if (!array_key_exists($action, $needs)) {
    ht_back(ht_member_query($memberId), 'Unknown action.');
}

if (!$needs[$action]) {
    ht_back(ht_member_query($memberId), 'You do not have permission to do that.');
}

try {
    switch ($action) {
        /* ---------------- Team members ---------------- */
        case 'add_team_member':
            $member = hubnet_member($conn, $memberId);

            if (!$member) {
                throw new InvalidArgumentException('Company / member not found.');
            }

            $fullName = ht_str('full_name', 150);
            $email = ht_str('email');
            $phone = ht_str('phone', 40);
            $jobTitle = ht_str('job_title', 120);
            // Devices: one per line, "MAC [label]".
            $parsedDevices = hubnet_parse_device_lines(hubnet_device_rows_text($_POST, 'macs'));
            if ($parsedDevices['errors'] !== []) {
                throw new InvalidArgumentException(implode(' ', array_slice($parsedDevices['errors'], 0, 3)));
            }
            $macs = array_column($parsedDevices['devices'], 'mac');
            $macLabels = array_column($parsedDevices['devices'], 'label', 'mac');

            if ($fullName === '') {
                throw new InvalidArgumentException('Enter the team member\'s full name.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid email address or leave it blank.');
            }

            if ($member['seats_max'] === null) {
                throw new InvalidArgumentException('Set a workspace package for this member first (Hub Operations > Edit member).');
            }

            if (!hubnet_seat_available($member)) {
                throw new InvalidArgumentException(
                    'All ' . $member['seats_max'] . ' seat(s) of the ' . $member['package_name'] .
                    ' package are taken (the primary member counts as one). Upgrade the package or suspend someone first.'
                );
            }

            // "From an existing guest": their registered devices move to the new team member.
            $fromPersonId = (int)($_POST['from_person_id'] ?? 0);
            $fromPerson = null;
            if ($fromPersonId > 0) {
                require_once __DIR__ . '/hub-internet.php';
                $fromPerson = hubinet_person($conn, $fromPersonId);
                if (!$fromPerson || $fromPerson['kind'] !== 'guest') {
                    throw new InvalidArgumentException('The selected guest was not found. Pick them again or leave the guest field empty.');
                }
                // Their own devices move anyway: do not add them twice.
                $guestMacs = array_map(static fn($d) => hubnet_normalize_mac((string)$d['mac_address']),
                    hubnet_devices_where($conn, 'd.person_id = ?', 'i', [$fromPersonId]));
                $macs = array_values(array_diff($macs, $guestMacs));
            }

            foreach ($macs as $mac) {
                ht_assert_mac_free($conn, $mac);
            }

            // Package device limits: the new person's devices (typed + moved from the guest).
            $newDevices = count($macs) + ($fromPerson ? count(hubnet_devices_where($conn, 'd.person_id = ?', 'i', [$fromPersonId])) : 0);
            if ($newDevices > 0) {
                $perPersonLimit = $member['devices_per_person'] !== null ? (int)$member['devices_per_person'] : null;
                if ($perPersonLimit !== null && $newDevices > $perPersonLimit) {
                    throw new InvalidArgumentException('The ' . $member['package_name'] . ' package allows ' . $perPersonLimit . ' device' . ($perPersonLimit === 1 ? '' : 's') . ' per person; ' . $newDevices . ' were given.');
                }
                $limitError = hubnet_device_limit_error($conn, ['devices_per_person' => null] + $member, null, $newDevices);
                if ($limitError !== null) {
                    throw new InvalidArgumentException($limitError);
                }
            }

            $conn->begin_transaction();
            $stmt = $conn->prepare(
                "INSERT INTO hub_team_members (member_id, full_name, email, phone, job_title, created_by)
                 VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?)"
            );
            $stmt->bind_param('issssi', $memberId, $fullName, $email, $phone, $jobTitle, $userId);
            $stmt->execute();
            $teamMemberId = (int)$stmt->insert_id;
            $stmt->close();

            foreach ($macs as $index => $mac) {
                ht_insert_device($conn, $memberId, $teamMemberId, $mac, ($macLabels[$mac] ?? '') !== '' ? $macLabels[$mac] : 'Device ' . ($index + 1), $userId);
            }

            $movedFromGuest = 0;
            if ($fromPerson) {
                $stmt = $conn->prepare('UPDATE hub_network_devices SET member_id = ?, team_member_id = ?, person_id = NULL WHERE person_id = ?');
                $stmt->bind_param('iii', $memberId, $teamMemberId, $fromPersonId);
                $stmt->execute();
                $movedFromGuest = $stmt->affected_rows;
                $stmt->close();
                $stmt = $conn->prepare('DELETE FROM hub_network_people WHERE person_id = ?');
                $stmt->bind_param('i', $fromPersonId);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            $net = ($macs !== [] || $movedFromGuest > 0) ? hubnet_apply_member($conn, $memberId, $userId, 'team-add') : ['ok' => true, 'applied' => 0, 'failed' => 0, 'message' => ''];
            ht_back(ht_member_query($memberId), null, $fullName . ' was added to ' . ($member['company_name'] ?: $member['full_name']) . '.'
                . ($fromPerson ? ' The guest record was converted' . ($movedFromGuest ? ' and ' . $movedFromGuest . ' of their device(s) moved here' : '') . '.' : '')
                . ht_net_note($net));

        case 'edit_team_member':
            $teamMemberId = ht_int('team_member_id');
            $stmt = $conn->prepare('SELECT * FROM hub_team_members WHERE team_member_id = ? LIMIT 1');
            $stmt->bind_param('i', $teamMemberId);
            $stmt->execute();
            $person = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$person) {
                throw new InvalidArgumentException('Team member not found.');
            }

            $memberId = (int)$person['member_id'];
            $fullName = ht_str('full_name', 150);
            $email = ht_str('email');
            $phone = ht_str('phone', 40);
            $jobTitle = ht_str('job_title', 120);
            $status = (string)($_POST['status'] ?? $person['status']) === 'Suspended' ? 'Suspended' : 'Active';

            if ($fullName === '') {
                throw new InvalidArgumentException('Enter the team member\'s full name.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid email address or leave it blank.');
            }

            if ($status === 'Active' && $person['status'] !== 'Active') {
                $member = hubnet_member($conn, $memberId);

                if ($member && !hubnet_seat_available($member)) {
                    throw new InvalidArgumentException('No free seat in the ' . $member['package_name'] . ' package to reactivate this person.');
                }
            }

            $stmt = $conn->prepare(
                "UPDATE hub_team_members
                 SET full_name = ?, email = NULLIF(?, ''), phone = NULLIF(?, ''), job_title = NULLIF(?, ''), status = ?
                 WHERE team_member_id = ?"
            );
            $stmt->bind_param('sssssi', $fullName, $email, $phone, $jobTitle, $status, $teamMemberId);
            $stmt->execute();
            $stmt->close();

            $net = hubnet_apply($conn, hubnet_devices_where($conn, 'd.team_member_id = ?', 'i', [$teamMemberId]), $userId, 'team-edit');
            ht_back(ht_member_query($memberId), null, $fullName . ' was updated.' . ht_net_note($net));

        case 'remove_team_member':
            $teamMemberId = ht_int('team_member_id');
            $stmt = $conn->prepare('SELECT member_id, full_name FROM hub_team_members WHERE team_member_id = ? LIMIT 1');
            $stmt->bind_param('i', $teamMemberId);
            $stmt->execute();
            $person = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$person) {
                throw new InvalidArgumentException('Team member not found.');
            }

            $memberId = (int)$person['member_id'];
            $devices = hubnet_devices_where($conn, 'd.team_member_id = ?', 'i', [$teamMemberId]);
            $net = hubnet_apply($conn, $devices, $userId, 'team-remove', 'remove');

            $conn->begin_transaction();
            $stmt = $conn->prepare('DELETE FROM hub_network_devices WHERE team_member_id = ?');
            $stmt->bind_param('i', $teamMemberId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM hub_team_members WHERE team_member_id = ?');
            $stmt->bind_param('i', $teamMemberId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();

            ht_back(ht_member_query($memberId), null, $person['full_name'] . ' was removed and their seat freed.' . ($devices !== [] ? ht_net_note($net) : ''));

        /* ---------------- Devices ---------------- */
        case 'add_device':
            $member = hubnet_member($conn, $memberId);

            if (!$member) {
                throw new InvalidArgumentException('Company / member not found.');
            }

            $teamMemberId = ht_int('team_member_id') ?: null;

            if ($teamMemberId !== null) {
                $stmt = $conn->prepare('SELECT 1 FROM hub_team_members WHERE team_member_id = ? AND member_id = ? LIMIT 1');
                $stmt->bind_param('ii', $teamMemberId, $memberId);
                $stmt->execute();
                $belongs = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();

                if (!$belongs) {
                    throw new InvalidArgumentException('That person is not on this company\'s team.');
                }
            }

            // One or several devices: "MAC [label]" per line (the single MAC field still works).
            $lines = hubnet_device_rows_text($_POST, 'devices');
            if (trim($lines) === '' && ht_str('mac_address', 40) !== '') {
                $lines = ht_str('mac_address', 40) . ' ' . ht_str('label', 80);
            }
            $parsed = hubnet_parse_device_lines($lines);

            if ($parsed['errors'] !== []) {
                throw new InvalidArgumentException(implode(' ', array_slice($parsed['errors'], 0, 3)));
            }

            if ($parsed['devices'] === []) {
                throw new InvalidArgumentException('Enter at least one MAC address (e.g. a4:5e:60:12:ab:cd).');
            }

            // Check every address before saving any, so nothing is half-registered.
            foreach ($parsed['devices'] as $dev) {
                ht_assert_mac_free($conn, $dev['mac']);
            }

            $limitError = hubnet_device_limit_error($conn, $member, $teamMemberId, count($parsed['devices']));
            if ($limitError !== null) {
                throw new InvalidArgumentException($limitError);
            }

            $deviceIds = [];
            foreach ($parsed['devices'] as $dev) {
                $deviceIds[] = ht_insert_device($conn, $memberId, $teamMemberId, $dev['mac'], $dev['label'], $userId);
            }

            $marks = implode(',', array_fill(0, count($deviceIds), '?'));
            $net = hubnet_apply($conn, hubnet_devices_where($conn, "d.device_id IN ($marks)", str_repeat('i', count($deviceIds)), $deviceIds), $userId, 'device-add');
            $count = count($deviceIds);
            ht_back(ht_member_query($memberId), null, ($count === 1 ? 'Device ' . $parsed['devices'][0]['mac'] . ' registered.' : $count . ' devices registered.') . ht_net_note($net));

        case 'remove_device':
            $deviceId = ht_int('device_id');
            $devices = hubnet_devices_where($conn, 'd.device_id = ?', 'i', [$deviceId]);

            if ($devices === []) {
                throw new InvalidArgumentException('Device not found.');
            }

            $memberId = (int)$devices[0]['member_id'];
            $net = hubnet_apply($conn, $devices, $userId, 'device-remove', 'remove');
            $stmt = $conn->prepare('DELETE FROM hub_network_devices WHERE device_id = ?');
            $stmt->bind_param('i', $deviceId);
            $stmt->execute();
            $stmt->close();

            ht_back(ht_member_query($memberId), null, 'Device ' . $devices[0]['mac_address'] . ' removed.' . ht_net_note($net));

        /* ---------------- Internet access ---------------- */
        case 'set_access':
            $mode = (string)($_POST['mode'] ?? '');
            $scope = (string)($_POST['scope'] ?? '');
            $targetId = ht_int('target_id');
            $toDashboard = (string)($_POST['return'] ?? '') === 'dashboard';

            if (!in_array($mode, ['auto', 'allow', 'block'], true) || $targetId <= 0) {
                throw new InvalidArgumentException('Invalid internet access request.');
            }

            $where = match ($scope) {
                'device' => 'd.device_id = ?',
                'team_member' => 'd.team_member_id = ?',
                'primary' => 'd.member_id = ? AND d.team_member_id IS NULL',
                'member' => 'd.member_id = ?',
                default => throw new InvalidArgumentException('Invalid internet access scope.'),
            };

            $stmt = $conn->prepare("UPDATE hub_network_devices d SET d.access_mode = ? WHERE $where");
            $stmt->bind_param('si', $mode, $targetId);
            $stmt->execute();
            $stmt->close();

            $devices = hubnet_devices_where($conn, $where, 'i', [$targetId]);

            if ($devices === []) {
                throw new InvalidArgumentException('No registered devices to update. Add a device (MAC address) first.');
            }

            $memberId = (int)$devices[0]['member_id'];
            $net = hubnet_apply($conn, $devices, $userId, 'access-' . $mode);
            $label = ['auto' => 'set to automatic', 'allow' => 'always connected', 'block' => 'disconnected'][$mode];

            $message = count($devices) . ' device(s) ' . $label . '.' . ht_net_note($net);
            ht_back(
                $toDashboard ? 'tab=devices' : ht_member_query($memberId),
                $net['ok'] ? null : $message,
                $net['ok'] ? $message : null
            );

        case 'sync_now':
            $result = hubnet_sync_all($conn, $userId, true);
            ht_back(ht_member_query($memberId), $result['ok'] ? null : $result['message'], $result['ok'] ? $result['message'] : null);

        /* ---------------- Packages ---------------- */
        case 'save_package':
            $packageId = ht_int('package_id');
            $name = ht_str('name', 100);
            $maxSeats = max(1, min(500, ht_int('max_seats')));
            $description = ht_str('description', 255);
            $sortOrder = ht_int('sort_order');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            // Device limits: empty = no limit.
            $limit = static function (string $key, int $max): ?int {
                $raw = trim((string)($_POST[$key] ?? ''));
                if ($raw === '') {
                    return null;
                }
                if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > $max) {
                    throw new InvalidArgumentException('Device limits must be whole numbers from 1 to ' . $max . ', or empty for no limit.');
                }
                return (int)$raw;
            };
            $perPerson = $limit('devices_per_person', 50);
            $maxDevices = $limit('max_devices', 5000);

            if ($name === '') {
                throw new InvalidArgumentException('Enter the package name.');
            }
            if ($perPerson !== null && $maxDevices !== null && $maxDevices < $perPerson) {
                throw new InvalidArgumentException('The total devices for the company cannot be lower than the devices per person.');
            }

            $stmt = $conn->prepare('SELECT 1 FROM hub_space_packages WHERE name = ? AND package_id <> ? LIMIT 1');
            $stmt->bind_param('si', $name, $packageId);
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if ($duplicate) {
                throw new InvalidArgumentException('A package called "' . $name . '" already exists.');
            }

            if ($packageId > 0) {
                $stmt = $conn->prepare(
                    "UPDATE hub_space_packages SET name = ?, max_seats = ?, devices_per_person = ?, max_devices = ?,
                            description = NULLIF(?, ''), sort_order = ?, is_active = ?
                     WHERE package_id = ?"
                );
                $stmt->bind_param('siiisiii', $name, $maxSeats, $perPerson, $maxDevices, $description, $sortOrder, $isActive, $packageId);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO hub_space_packages (name, max_seats, devices_per_person, max_devices, description, sort_order, is_active)
                     VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?)"
                );
                $stmt->bind_param('siiisii', $name, $maxSeats, $perPerson, $maxDevices, $description, $sortOrder, $isActive);
            }

            $stmt->execute();
            $stmt->close();

            $limits = hubnet_device_limit_label(['devices_per_person' => $perPerson, 'max_devices' => $maxDevices]);
            ht_back('view=settings&tab=packages', null, 'Package "' . $name . '" saved: ' . $maxSeats . ' seat' . ($maxSeats === 1 ? '' : 's')
                . ', devices: ' . strtolower($limits) . '. Existing devices are kept; companies over a limit cannot add more.');

        /* ---------------- pfSense settings ---------------- */
        case 'save_network_settings':
            $url = ht_str('agent_url', 255);
            $zone = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', ht_str('cp_zone', 60)) ?? '') ?: 'lan';
            $secret = trim((string)($_POST['agent_secret'] ?? ''));
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $autoEnforce = !empty($_POST['auto_enforce']) ? 1 : 0;
            $verifyTls = !empty($_POST['verify_tls']) ? 1 : 0;
            $mode = (string)($_POST['mode'] ?? 'pull') === 'push' ? 'push' : 'pull';

            if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
                throw new InvalidArgumentException('The agent URL must start with http:// or https://.');
            }

            if ($secret !== '' && strlen($secret) < 24) {
                throw new InvalidArgumentException('Use a shared secret of at least 24 characters.');
            }

            if ($enabled && $secret === '' && !hubnet_settings($conn)['has_secret']) {
                throw new InvalidArgumentException('Enter the shared secret before switching pfSense control on.');
            }

            if ($enabled && $mode === 'push' && $url === '') {
                throw new InvalidArgumentException('Enter the agent URL (push mode) before switching pfSense control on.');
            }

            if ($secret !== '') {
                $stored = function_exists('ims_encrypt') ? ims_encrypt($secret) : $secret;
                $stmt = $conn->prepare(
                    'UPDATE hub_network_settings SET enabled = ?, agent_url = ?, agent_secret = ?, cp_zone = ?, auto_enforce = ?, verify_tls = ?,
                            updated_by = ?, updated_at = NOW() WHERE id = 1'
                );
                $stmt->bind_param('isssiii', $enabled, $url, $stored, $zone, $autoEnforce, $verifyTls, $userId);
            } else {
                $stmt = $conn->prepare(
                    'UPDATE hub_network_settings SET enabled = ?, agent_url = ?, cp_zone = ?, auto_enforce = ?, verify_tls = ?,
                            updated_by = ?, updated_at = NOW() WHERE id = 1'
                );
                $stmt->bind_param('issiii', $enabled, $url, $zone, $autoEnforce, $verifyTls, $userId);
            }

            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE hub_network_settings SET mode = ? WHERE id = 1');
            $stmt->bind_param('s', $mode);
            $stmt->execute();
            $stmt->close();

            ht_back('view=settings&tab=pfsense', null, 'pfSense settings saved.');

        case 'test_connection':
            $netSettings = hubnet_settings($conn);

            if ($netSettings['mode'] === 'pull') {
                $info = $netSettings['last_checkin_info'];

                if (!$netSettings['last_checkin_at']) {
                    ht_back('view=settings&tab=pfsense', 'pfSense has not checked in yet. Run "/usr/local/bin/php -f /root/ims-cp-agent.php pull" on the firewall and check its output.');
                }

                $ago = time() - (int)strtotime((string)$netSettings['last_checkin_at']);
                // The firewall's public IP is deliberately not shown.
                $summary = 'Last check-in ' . ($ago < 120 ? $ago . ' seconds' : round($ago / 60) . ' minutes') . ' ago' .
                    ' (zone ' . ($info['zone'] ?? '?') . ', ' . (int)($info['sessions'] ?? 0) . ' active sessions).';

                if (array_key_exists('mac_filtering', $info) && !$info['mac_filtering']) {
                    $summary .= ' Warning: MAC filtering is off on this zone, so pass/block entries will not take effect.';
                }

                ht_back(
                    'view=settings&tab=pfsense',
                    hubnet_agent_online($netSettings) ? null : 'pfSense is not checking in. ' . $summary . ' Check the Cron entry on the firewall.',
                    hubnet_agent_online($netSettings) ? 'pfSense is checking in. ' . $summary : null
                );
            }

            $result = hubnet_call($netSettings, 'ping');
            $extra = '';

            if ($result['ok'] && array_key_exists('mac_filtering', $result['data']) && !$result['data']['mac_filtering']) {
                $extra = ' Warning: MAC filtering is off on this zone, so pass/block entries will not take effect.';
            }

            ht_back(
                'view=settings&tab=pfsense',
                $result['ok'] ? null : 'Test failed: ' . $result['message'],
                $result['ok'] ? $result['message'] . ' Active sessions: ' . (int)($result['data']['sessions'] ?? 0) . '.' . $extra : null
            );

        /* ---------------- Reminders ---------------- */
        case 'send_reminder':
            $memberIds = array_map('intval', (array)($_POST['member_ids'] ?? []));
            if ($memberId > 0) {
                $memberIds[] = $memberId;
            }
            $memberIds = array_values(array_unique(array_filter($memberIds, static fn(int $id): bool => $id > 0)));

            $subject = trim((string)($_POST['subject'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));

            if ($memberIds === []) {
                throw new InvalidArgumentException('Choose at least one member to remind.');
            }

            if ($subject === '' || $message === '') {
                throw new InvalidArgumentException('Enter the reminder subject and message.');
            }

            $result = hubrem_send_manual(
                $conn,
                $memberIds,
                mb_substr($subject, 0, 190),
                mb_substr($message, 0, 5000),
                $userId,
                !empty($_POST['send_email']),
                !empty($_POST['include_team'])
            );

            $summary = 'Reminder sent to ' . $result['sent'] . ' member' . ($result['sent'] === 1 ? '' : 's') . '.';
            if ($result['errors'] !== []) {
                $summary .= ' ' . implode(' ', array_slice($result['errors'], 0, 3)) . (count($result['errors']) > 3 ? ' ...' : '');
            }

            ht_back(
                ht_return_query(count($memberIds) === 1 ? $memberIds[0] : 0, 'reminders'),
                $result['sent'] === 0 ? $summary : null,
                $result['sent'] > 0 ? $summary : null
            );

        case 'run_reminders_now':
            $result = hubrem_dispatch($conn, $userId, true);
            ht_back(
                'view=settings&tab=reminders',
                null,
                $result['sent'] === 0 && $result['failed'] === 0
                    ? 'No automatic reminders are due today (each one is sent only once).'
                    : 'Due reminders sent: ' . $result['sent'] . ($result['failed'] > 0 ? ', failed: ' . $result['failed'] : '') . '.'
            );

        case 'save_reminder_settings':
            $days = array_values(array_unique(array_filter(
                array_map('intval', preg_split('/[\s,;]+/', (string)($_POST['days_before'] ?? '')) ?: []),
                static fn(int $d): bool => $d > 0 && $d <= 365
            )));
            rsort($days);
            $daysText = implode(',', $days);
            $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 190);
            $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 5000);
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $onExpiry = !empty($_POST['on_expiry']) ? 1 : 0;
            $every = max(0, min(90, ht_int('overdue_every_days')));
            $max = max(0, min(24, ht_int('overdue_max')));
            $sendEmail = !empty($_POST['send_email']) ? 1 : 0;
            $includeTeam = !empty($_POST['include_team']) ? 1 : 0;

            if ($subject === '' || $message === '') {
                throw new InvalidArgumentException('Enter the reminder subject and message.');
            }

            $stmt = $conn->prepare(
                'UPDATE hub_reminder_settings
                 SET enabled = ?, days_before = ?, on_expiry = ?, overdue_every_days = ?, overdue_max = ?,
                     send_email = ?, include_team = ?, subject = ?, message = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = 1'
            );
            $stmt->bind_param('isiiiiissi', $enabled, $daysText, $onExpiry, $every, $max, $sendEmail, $includeTeam, $subject, $message, $userId);
            $stmt->execute();
            $stmt->close();

            ht_back('view=settings&tab=reminders', null, 'Reminder settings saved.');
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable) {
    }

    if (!$e instanceof InvalidArgumentException) {
        error_log('[hub-team-process] ' . $action . ': ' . $e->getMessage());
    }

    $message = $e instanceof InvalidArgumentException
        ? $e->getMessage()
        : (str_contains($e->getMessage(), 'Duplicate') ? 'That entry already exists.' : 'Could not complete the request. Please try again.');

    $settingsTab = match ($action) {
        'save_package' => 'packages',
        'save_network_settings', 'test_connection' => 'pfsense',
        'save_reminder_settings', 'run_reminders_now' => 'reminders',
        default => '',
    };

    ht_back(ht_return_query($memberId, $settingsTab), $message);
}
