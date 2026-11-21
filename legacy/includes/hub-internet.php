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

/**
 * "Add device" rows for a form: a MAC address and a label per row, with
 * "+ Add device" to add another row and x to remove one. Posts
 * device_mac[] / device_label[] (read with hubnet_device_rows_text()).
 * $required: the first row's MAC must be filled in.
 */
function hubinet_device_rows(string $id, bool $required = false, string $legend = 'Devices', int $rows = 1, ?array $online = null): string
{
    static $assets = false;
    $h = 'hubinet_h';
    $row = static function (bool $first) use ($required): string {
        return '<div class="dr-row">'
            . '<input type="text" name="device_mac[]" class="form-control dr-mac" maxlength="17" autocomplete="off" spellcheck="false"'
            . ' placeholder="MAC address, e.g. a4:5e:60:12:ab:cd" aria-label="MAC address"'
            . ' pattern="\s*[0-9A-Fa-f]{2}([:\-.\s]?[0-9A-Fa-f]{2}){5}\s*" title="12 hexadecimal digits, e.g. a4:5e:60:12:ab:cd"'
            . ($first && $required ? ' required' : '') . '>'
            . '<input type="text" name="device_label[]" class="form-control dr-label" maxlength="80" placeholder="Label, e.g. Laptop" aria-label="Device label">'
            . '<button type="button" class="btn btn-sm btn-soft dr-remove" title="Remove this device" aria-label="Remove this device"><i class="fas fa-xmark" aria-hidden="true"></i></button>'
            . '</div>';
    };

    $html = '<fieldset class="dr-field" data-device-rows id="' . $h($id) . '">'
        . '<legend class="' . ($required ? 'required' : '') . '">' . $h($legend) . '</legend>'
        . '<div class="dr-rows">';
    for ($i = 0; $i < max(1, $rows); $i++) {
        $html .= $row($i === 0);
    }
    $html .= '</div>'
        . '<button type="button" class="btn btn-sm btn-secondary dr-add"><i class="fas fa-plus" aria-hidden="true"></i> Add device</button>'
        . ($online !== null ? hubinet_online_picker($online) : '')
        . '<p class="form-hint">Type the MAC address' . ($online !== null ? ' or pick a device that is online now' : '') . '. Add as many devices as needed. Every address is checked first; nothing is saved if one is invalid or already registered.</p>'
        . '</fieldset>';

    if (!$assets) {
        $assets = true;
        $html .= '<style>'
            . '.dr-field{border:0;padding:0;margin:0 0 12px;min-width:0}'
            . '.dr-field legend{font-weight:600;font-size:14px;margin-bottom:6px}'
            . '.dr-rows{display:flex;flex-direction:column;gap:8px;margin-bottom:8px}'
            . '.dr-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) auto;gap:8px;align-items:center}'
            . '.dr-row .form-control{margin:0}'
            . '.dr-mac{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}'
            . '.dr-pick{margin:8px 0 0;border:1px solid var(--border);border-radius:var(--radius-md,8px)}'
            . '.dr-pick>summary{cursor:pointer;padding:8px 12px;font-weight:600;font-size:13px;list-style-position:inside}'
            . '.dr-pick[open]>summary{border-bottom:1px solid var(--border)}'
            . '.dr-pick-search{margin:8px 12px;width:calc(100% - 24px)}'
            . '.dr-pick-list{max-height:220px;overflow-y:auto;border-top:1px solid var(--border)}'
            . '.dr-pick-dev{display:flex;width:100%;justify-content:space-between;align-items:center;gap:10px;padding:8px 12px;border:0;border-bottom:1px solid var(--border);background:transparent;text-align:left;cursor:pointer;font:inherit;color:inherit}'
            . '.dr-pick-dev:last-child{border-bottom:0}'
            . '.dr-pick-dev:hover,.dr-pick-dev:focus-visible{background:var(--brand-50)}'
            . '.dr-pick-dev small{display:block;color:var(--ink-400);font-size:12px}'
            . '.dr-pick-dev.is-added{opacity:.45}'
            . '.dr-pick-empty{padding:10px 12px;margin:0;color:var(--ink-400);font-size:13px}'
            . '@media(max-width:560px){.dr-row{grid-template-columns:minmax(0,1fr) auto}.dr-row .dr-label{grid-column:1}.dr-row .dr-remove{grid-row:1/span 2;grid-column:2}}'
            . '</style>'
            . '<script>(function(){'
            . 'function clear(row){row.querySelectorAll("input").forEach(function(i){i.value="";});}'
            . 'document.addEventListener("click",function(e){'
            . 'var add=e.target.closest(".dr-add");'
            . 'if(add){var f=add.closest("[data-device-rows]"),list=f.querySelector(".dr-rows"),tpl=list.querySelector(".dr-row").cloneNode(true);'
            . 'clear(tpl);tpl.querySelectorAll("[required]").forEach(function(i){i.removeAttribute("required");});'
            . 'list.appendChild(tpl);tpl.querySelector(".dr-mac").focus();return;}'
            . 'var rm=e.target.closest(".dr-remove");'
            . 'if(rm){var list2=rm.closest(".dr-rows"),r=rm.closest(".dr-row");'
            . 'if(list2.querySelectorAll(".dr-row").length>1){var req=r.querySelector("[required]");r.remove();'
            . 'if(req){var first=list2.querySelector(".dr-mac");if(first)first.required=true;}}else{clear(r);}}'
            . '});'
            // Enter in a MAC field adds the next row instead of submitting.
            // Online-now picker: fill the first empty row (else add one) with the device's MAC and name.
            . 'document.addEventListener("click",function(e){var b=e.target.closest(".dr-pick-dev");if(!b)return;'
            . 'var f=b.closest("[data-device-rows]"),norm=function(v){return v.toLowerCase().replace(/[^0-9a-f]/g,"");},dup=null,empty=null;'
            . 'f.querySelectorAll(".dr-row").forEach(function(r){var v=r.querySelector(".dr-mac").value.trim();if(!v&&!empty)empty=r;if(v&&norm(v)===norm(b.dataset.mac))dup=r;});'
            . 'if(dup){dup.querySelector(".dr-mac").focus();return;}'
            . 'if(!empty){f.querySelector(".dr-add").click();var all=f.querySelectorAll(".dr-row");empty=all[all.length-1];}'
            . 'empty.querySelector(".dr-mac").value=b.dataset.mac;var l=empty.querySelector(".dr-label");if(l&&!l.value)l.value=b.dataset.label||"";'
            . 'b.classList.add("is-added");b.setAttribute("aria-pressed","true");});'
            . 'document.addEventListener("input",function(e){if(!e.target.matches||!e.target.matches(".dr-pick-search"))return;'
            . 'var q=e.target.value.trim().toLowerCase().replace(/[:\\-.]/g,""),box=e.target.closest(".dr-pick"),n=0;'
            . 'box.querySelectorAll(".dr-pick-dev").forEach(function(b){var hit=q===""||b.dataset.search.replace(/[:\\-.]/g,"").indexOf(q)>-1;b.hidden=!hit;if(hit)n++;});'
            . 'var none=box.querySelector(".dr-pick-empty");if(none)none.hidden=n>0;});'
            . 'document.addEventListener("keydown",function(e){'
            . 'if(e.key!=="Enter"||!e.target.matches||!e.target.matches(".dr-mac,.dr-label"))return;'
            . 'var f=e.target.closest("[data-device-rows]");if(!f)return;e.preventDefault();f.querySelector(".dr-add").click();});'
            . '})();</script>';
    }

    return $html;
}

/** Devices online now that are not registered to anyone (cached per request). */
function hubinet_unregistered_online(mysqli $conn): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = array_values(array_filter(hubinet_online($conn, 'unknown'), static fn($o) => empty($o['device'])));
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

/** "Pick from devices online now" list for hubinet_device_rows(); a click fills a device row. */
function hubinet_online_picker(array $online): string
{
    $h = 'hubinet_h';
    $html = '<details class="dr-pick"><summary><i class="fas fa-wifi" aria-hidden="true"></i> Pick from devices online now'
        . ' <span class="cell-muted">(' . count($online) . ' not registered)</span></summary>';
    if (!$online) {
        return $html . '<p class="dr-pick-empty">No unregistered devices are online right now. Type the MAC address above.</p></details>';
    }
    $html .= '<input type="search" class="form-control dr-pick-search" placeholder="Search name, maker, IP or MAC…" aria-label="Search devices online now" autocomplete="off">'
        . '<div class="dr-pick-list">';
    foreach ($online as $o) {
        $mac = (string)$o['mac_address'];
        $host = trim((string)($o['hostname'] ?? ''));
        $vendor = trim((string)($o['vendor'] ?? ''));
        $pfName = trim((string)($o['pf']['descr'] ?? ''));
        $name = $host ?: $pfName ?: ($vendor !== '' ? $vendor . ' device' : 'Unnamed device');
        $ip = (string)($o['ip_address'] ?? '');
        $meta = $mac . ($ip !== '' ? ' · ' . $ip : '') . ($vendor !== '' ? ' · ' . $vendor : '') . (hubinet_is_random_mac($mac) ? ' · private MAC' : '');
        $search = mb_strtolower($name . ' ' . $vendor . ' ' . $ip . ' ' . str_replace([':', '-', '.'], '', $mac) . ' ' . $pfName);
        $html .= '<button type="button" class="dr-pick-dev" aria-pressed="false" data-mac="' . $h($mac) . '" data-label="' . $h($host ?: $pfName ?: $vendor) . '" data-search="' . $h($search) . '">'
            . '<span><strong>' . $h($name) . '</strong><small>' . $h($meta) . '</small></span>'
            . '<i class="fas fa-plus" aria-hidden="true"></i></button>';
    }
    return $html . '</div><p class="dr-pick-empty" hidden>No device matches the search.</p></details>';
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
        $lines = hubnet_device_rows_text($post, 'devices');
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
function hubinet_add_device(mysqli $conn, int $personId, string $mac, string $label, int $uid, string $mode = 'auto', bool $apply = true, ?array $bw = null): array
{
    if ($bw !== null) {
        // Speed limit for the new device ([down, up] in Kbit/s): register without applying, set it, then apply.
        $r = hubinet_add_device($conn, $personId, $mac, $label, $uid, $mode, false);
        if (!$r['ok']) {
            return $r;
        }
        hubinet_set_device_bw($conn, [(int)$r['device_id']], $bw[0] ?? null, $bw[1] ?? null);
        if ($apply) {
            hubnet_apply($conn, hubnet_devices_where($conn, 'd.device_id = ?', 'i', [(int)$r['device_id']]), $uid, 'add');
        }
        return $r;
    }
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

/** Sets the speed limit (Kbit/s, null = no limit) of devices. Returns the number changed. */
function hubinet_set_device_bw(mysqli $conn, array $deviceIds, ?int $downKbps, ?int $upKbps): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $deviceIds), fn($i) => $i > 0)));
    if (!$ids) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE hub_network_devices SET bw_down_kbps = NULLIF(?, 0), bw_up_kbps = NULLIF(?, 0) WHERE device_id IN ($marks)");
    $down = (int)$downKbps;
    $up = (int)$upKbps;
    $stmt->bind_param('ii' . str_repeat('i', count($ids)), $down, $up, ...$ids);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/** "↓ 10 / ↑ 5 Mbps", or "No limit". */
function hubinet_speed_label(array $device): string
{
    $down = hubnet_mbps_label(isset($device['bw_down_kbps']) ? (int)$device['bw_down_kbps'] : null);
    $up = hubnet_mbps_label(isset($device['bw_up_kbps']) ? (int)$device['bw_up_kbps'] : null);
    if ($down === '' && $up === '') {
        return 'No limit';
    }
    return '↓ ' . ($down !== '' ? $down : '∞') . ' / ↑ ' . ($up !== '' ? $up : '∞') . ' Mbps';
}

/**
 * Speed shown in device tables; with $canEdit a button that opens the speed
 * modal (hub-team.php, #hiSpeedModal). $returnQuery: where to come back to.
 */
function hubinet_speed_cell(array $device, bool $canEdit, string $returnQuery): string
{
    $label = hubinet_speed_label($device);
    $limited = $label !== 'No limit';
    $text = $limited ? '<small class="hi-speed-val">' . hubinet_h($label) . '</small>' : '<span class="cell-muted">No limit</span>';
    if (!$canEdit || empty($device['device_id'])) {
        return $text;
    }
    // Icon-only button; the current limit is in the tooltip and, when set, beside it.
    $tip = 'Speed limit: ' . $label . ' (click to change)';
    return '<span class="hi-speed"><button type="button" class="btn btn-sm ' . ($limited ? 'btn-info' : 'btn-soft') . ' hi-speed-btn" onclick="hiSpeed(this)"'
        . ' title="' . hubinet_h($tip) . '" aria-label="' . hubinet_h($tip) . '"'
        . ' data-ids="' . (int)$device['device_id'] . '"'
        . ' data-name="' . hubinet_h((string)$device['mac_address']) . '"'
        . ' data-down="' . hubinet_h(hubnet_mbps_label(isset($device['bw_down_kbps']) ? (int)$device['bw_down_kbps'] : null)) . '"'
        . ' data-up="' . hubinet_h(hubnet_mbps_label(isset($device['bw_up_kbps']) ? (int)$device['bw_up_kbps'] : null)) . '"'
        . ' data-return="' . hubinet_h($returnQuery) . '">'
        . '<i class="fas fa-gauge-high" aria-hidden="true"></i></button>' . ($limited ? ' ' . $text : '') . '</span>';
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

    // Help page and contacts (shown before guests are online, so plain text only).
    $help = mb_substr(trim((string)($post['hotspot_help'] ?? '')), 0, 8000);
    $helpPhone = mb_substr(trim((string)($post['hotspot_help_phone'] ?? '')), 0, 40);
    $helpEmail = mb_substr(trim((string)($post['hotspot_help_email'] ?? '')), 0, 190);
    $helpWhatsapp = mb_substr(trim((string)($post['hotspot_help_whatsapp'] ?? '')), 0, 40);
    foreach (['help phone number' => $helpPhone, 'WhatsApp number' => $helpWhatsapp] as $label => $number) {
        if ($number !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $number)) {
            return hubinet_result(false, 'The ' . $label . ' is not valid.');
        }
    }
    if ($helpEmail !== '' && !filter_var($helpEmail, FILTER_VALIDATE_EMAIL)) {
        return hubinet_result(false, 'The help email address is not valid.');
    }
    // Default speed limit for devices that sign in on the hotspot (Mbps in the form).
    $guestDown = hubnet_parse_mbps($post['hotspot_bw_down'] ?? '');
    $guestUp = hubnet_parse_mbps($post['hotspot_bw_up'] ?? '');
    if ($guestDown === false || $guestUp === false) {
        return hubinet_result(false, 'Guest speed limits must be in Mbps (e.g. 5 or 0.5), or empty for no limit.');
    }

    $sets = ['hotspot_enabled = ?', 'hotspot_hours = ?', 'hotspot_require_phone = ?', 'hotspot_terms = ?', 'wifi_ssid = ?', 'wifi_security = ?',
        'hotspot_landing_url = NULLIF(?, \'\')', 'hotspot_verify = ?',
        'hotspot_help = NULLIF(?, \'\')', 'hotspot_help_phone = NULLIF(?, \'\')', 'hotspot_help_email = NULLIF(?, \'\')', 'hotspot_help_whatsapp = NULLIF(?, \'\')',
        'hotspot_bw_down_kbps = NULLIF(?, 0)', 'hotspot_bw_up_kbps = NULLIF(?, 0)'];
    $types = 'iiissssissssii';
    $params = [$enabled, $hours, $requirePhone, $terms, $ssid, $security, $landing, $verify, $help, $helpPhone, $helpEmail, $helpWhatsapp, (int)$guestDown, (int)$guestUp];
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
 | Hotspot help page, device status and branded pfSense pages
 * ==================================================================== */

/** Help contacts: the hotspot's own, else the organisation's (Settings > General). */
function hubinet_help_contacts(array $settings): array
{
    $brand = function_exists('ims_brand') ? ims_brand() : [];
    $email = $settings['hotspot_help_email'] !== '' ? $settings['hotspot_help_email'] : (string)($brand['email'] ?? '');
    return [
        'phone' => $settings['hotspot_help_phone'] !== '' ? $settings['hotspot_help_phone'] : (string)($brand['phone'] ?? ''),
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'whatsapp' => $settings['hotspot_help_whatsapp'],
    ];
}

/** Help text used until an administrator writes their own. */
function hubinet_default_help(array $settings): string
{
    $ssid = $settings['wifi_ssid'] !== '' ? $settings['wifi_ssid'] : 'the hub Wi-Fi';
    return "## How do I get online?\n"
        . "- Join the Wi-Fi network " . $ssid . " and turn off mobile data.\n"
        . "- Tap the \"Sign in to network\" message, or open any website.\n"
        . "- Enter your name, phone number and the access code from reception, then tap Connect.\n\n"
        . "## The sign-in page does not open\n"
        . "- Open http://neverssl.com in your browser (http, not https).\n"
        . "- Forget the Wi-Fi network on your phone, join it again and wait a few seconds.\n\n"
        . "## I signed in but have no internet\n"
        . "- Wait one minute: your device is connected at the next network check.\n"
        . "- Turn off \"Private Wi-Fi address\" / \"Random MAC\" for this network, then sign in again.\n\n"
        . "## How long does access last?\n"
        . "Access lasts " . (int)$settings['hotspot_hours'] . " hours. After that, simply sign in again.";
}

/**
 * Help text to HTML. Plain text only: "## " starts a heading, "- " a bullet,
 * an empty line a new paragraph. Everything is escaped first.
 */
function hubinet_help_html(string $text): string
{
    $out = '';
    $list = false;
    $para = [];
    $flush = static function () use (&$out, &$para): void {
        if ($para) {
            $out .= '<p>' . implode('<br>', $para) . "</p>\n";
            $para = [];
        }
    };
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        $isItem = str_starts_with($line, '- ') || str_starts_with($line, '* ');
        if ($list && !$isItem) {
            $out .= "</ul>\n";
            $list = false;
        }
        if ($line === '') {
            $flush();
        } elseif (str_starts_with($line, '#')) {
            $flush();
            $out .= '<h3>' . hubinet_h(ltrim($line, '# ')) . "</h3>\n";
        } elseif ($isItem) {
            $flush();
            if (!$list) {
                $out .= "<ul>\n";
                $list = true;
            }
            $out .= '<li>' . hubinet_h(substr($line, 2)) . "</li>\n";
        } else {
            $para[] = hubinet_h($line);
        }
    }
    $flush();
    return $out . ($list ? "</ul>\n" : '');
}

/**
 * Where a signed-in device stands on pfSense:
 *   connected - pfSense confirmed the pass entry
 *   blocked   - blocked in the IMS
 *   error     - pfSense reported an error for this device
 *   waiting   - not applied yet (next check-in)
 */
function hubinet_device_status(mysqli $conn, string $mac): string
{
    $mac = hubnet_normalize_mac($mac);
    if ($mac === '') {
        return 'waiting';
    }
    $stmt = $conn->prepare('SELECT access_mode, pf_state FROM hub_network_devices WHERE mac_address = ? LIMIT 1');
    if (!$stmt) {
        return 'waiting';
    }
    $stmt->bind_param('s', $mac);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return 'waiting';
    }
    if ($row['access_mode'] === 'block' || $row['pf_state'] === 'blocked') {
        return 'blocked';
    }
    return match ($row['pf_state']) {
        'connected' => 'connected',
        'error' => 'error',
        default => 'waiting',
    };
}

/** The organisation logo as a data: URI, so pages stored on pfSense show it without internet. */
function hubinet_logo_data_uri(): string
{
    $logo = function_exists('ims_brand') ? (string)(ims_brand()['logo'] ?? '') : '';
    $public = dirname(__DIR__, 2) . '/public';
    $file = '';
    $query = [];
    parse_str((string)(parse_url($logo, PHP_URL_QUERY) ?? ''), $query);
    if (preg_match('/\A[a-f0-9]{32}\.(png|jpe?g|webp|gif|svg)\z/i', (string)($query['file'] ?? ''))) {
        $file = $public . '/uploads/site-branding/' . $query['file'];
    } else {
        $path = (string)(parse_url($logo, PHP_URL_PATH) ?? '');
        if ($path !== '' && !str_contains($path, '..') && str_starts_with($path, '/')) {
            $file = $public . $path;
        }
    }
    if ($file === '' || !is_file($file) || filesize($file) > 200 * 1024) {
        return '';
    }
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
    $type = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? '';
    return $type === '' ? '' : 'data:' . $type . ';base64,' . base64_encode((string)file_get_contents($file));
}

/**
 * Branded pages to upload to pfSense (Services > Captive Portal > zone):
 *   portal - "Portal page contents": checks the IMS can be reached, then sends
 *            the device to the sign-in page; otherwise shows "no internet"
 *   error  - "Auth error page contents": the portal refused the sign-in
 * pfSense fills in $CLIENT_MAC$, $CLIENT_IP$, $PORTAL_ZONE$, $PORTAL_REDIRURL$
 * and $PORTAL_MESSAGE$. Everything is inline: these pages are shown before
 * the device is online.
 */
function hubinet_pfsense_page(array $settings, string $kind): string
{
    $brand = function_exists('ims_brand') ? ims_brand() : [];
    $hex = static fn($v, string $d): string => preg_match('/\A#[0-9a-f]{6}\z/i', (string)$v) ? strtolower((string)$v) : $d;
    $c = $hex($brand['primary'] ?? '', '#c2410c');
    $cDark = $hex($brand['primary_dark'] ?? '', '#9a3412');
    [$r, $g, $b] = array_map('hexdec', str_split(substr($c, 1), 2));
    $onBrand = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 170 ? '#111827' : '#ffffff';
    $name = (string)($brand['name'] ?? 'Guest Wi-Fi');
    $base = preg_replace('#/hotspot$#', '', hubinet_hotspot_url());
    $signin = $base . '/hotspot?mac=$CLIENT_MAC$&amp;ip=$CLIENT_IP$&amp;zone=$PORTAL_ZONE$&amp;redirurl=$PORTAL_REDIRURL$';
    $logo = hubinet_logo_data_uri();
    $contacts = hubinet_help_contacts($settings);

    $help = [];
    if ($contacts['phone'] !== '') {
        $help[] = 'Call <a href="tel:' . hubinet_h(preg_replace('/[^0-9+]/', '', $contacts['phone'])) . '">' . hubinet_h($contacts['phone']) . '</a>';
    }
    if ($contacts['whatsapp'] !== '') {
        $help[] = 'WhatsApp <strong>' . hubinet_h($contacts['whatsapp']) . '</strong>';
    }
    if ($contacts['email'] !== '') {
        $help[] = 'Email <strong>' . hubinet_h($contacts['email']) . '</strong>';
    }
    $helpLine = 'Need help? Ask at reception' . ($help ? '<br>' . implode(' &middot; ', $help) : '') . '.';

    $icon = [
        'wifi' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5 5 0 0 1 7 0"/><path d="M2 9a15 15 0 0 1 20 0"/><circle cx="12" cy="19.5" r="1"/></svg>',
        'off' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 2l20 20"/><path d="M8.5 16a5 5 0 0 1 7 0"/><path d="M5 12.5a10 10 0 0 1 4.2-2.6M14.8 9.9A10 10 0 0 1 19 12.5"/><circle cx="12" cy="19.5" r="1"/></svg>',
        'bad' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v5.5M12 16.5v.01"/></svg>',
        'spin' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.6"/></svg>',
    ];

    if ($kind === 'error') {
        $body = '<div class="state"><div class="ic bad">' . $icon['bad'] . '</div>'
            . '<h2>We could not sign you in</h2>'
            . '<p class="muted">Please try again. If it keeps happening, ask at reception.</p>'
            . '<p class="msg">$PORTAL_MESSAGE$</p>'
            . '<a class="btn" href="' . $signin . '">Try again</a></div>';
        $script = '';
        $noscript = '';
    } else {
        $body = '<div id="checking" class="state"><div class="ic wait">' . $icon['spin'] . '</div>'
            . '<h2>Opening the Wi-Fi sign-in&hellip;</h2><p class="muted">One moment please.</p>'
            . '<a class="btn ghost" id="go" href="' . $signin . '">Continue to sign in</a></div>'
            . '<div id="offline" class="state" hidden><div class="ic off">' . $icon['off'] . '</div>'
            . '<h2>No internet connection right now</h2>'
            . '<p class="muted">You are connected to our Wi-Fi, but the internet is not reachable at the moment. This is usually temporary.</p>'
            . '<ul class="tips"><li>Wait a minute, then tap <strong>Try again</strong>.</li><li>Make sure you are on the hub Wi-Fi, not mobile data.</li><li>If it keeps happening, the hub internet line may be down: please tell reception.</li></ul>'
            . '<button type="button" class="btn" onclick="location.reload()">Try again</button></div>';
        // Probe the IMS first: any HTTP answer means it is reachable; a network error or
        // timeout means the line (or DNS) is down, so show "no internet" instead of a browser error.
        $script = "<script>\n(function () {\n"
            . "    var go = document.getElementById('go');\n"
            . "    function offline() { document.getElementById('checking').hidden = true; document.getElementById('offline').hidden = false; }\n"
            . "    if (!window.fetch) { location.replace(go.href); return; }\n"
            . "    var done = false, timer = setTimeout(function () { if (!done) { done = true; offline(); } }, 8000);\n"
            . "    fetch(" . json_encode($base . '/hotspot?ping=1', JSON_UNESCAPED_SLASHES) . " + '&t=' + Date.now(), { mode: 'no-cors', cache: 'no-store' })\n"
            . "        .then(function () { if (!done) { done = true; clearTimeout(timer); location.replace(go.href); } })\n"
            . "        .catch(function () { if (!done) { done = true; clearTimeout(timer); offline(); } });\n"
            . "})();\n</script>";
        $noscript = '<noscript><meta http-equiv="refresh" content="0;url=' . $signin . '"></noscript>';
    }

    $logoHtml = $logo !== '' ? '<div class="logo"><img src="' . $logo . '" alt="' . hubinet_h($name) . '"></div>' : '';
    $title = $kind === 'error' ? 'Sign-in problem' : 'Guest Wi-Fi';

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>' . hubinet_h($title . ' · ' . $name) . '</title>
' . $noscript . '
<style>
:root { --brand: ' . $c . '; --brand-dark: ' . $cDark . '; --on-brand: ' . $onBrand . '; --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f3f4f6; --card: #fff; --soft: #f9fafb; }
@media (prefers-color-scheme: dark) { :root { --ink: #f3f4f6; --muted: #9ca3af; --line: #2d3340; --bg: #0b0f17; --card: #161b26; --soft: #1d2330; } }
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 24px 16px; }
.card { margin: auto 0; width: 100%; max-width: 440px; background: var(--card); border-radius: 20px; box-shadow: 0 18px 48px rgba(17,24,39,.12); overflow: hidden; }
.head { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: var(--on-brand); padding: 28px 24px 24px; text-align: center; }
.logo { display: inline-flex; background: #fff; border-radius: 14px; padding: 10px 16px; box-shadow: 0 6px 18px rgba(0,0,0,.15); }
.logo img { display: block; max-height: 44px; max-width: 188px; }
.head h1 { margin: 16px 0 2px; font-size: 1.4rem; }
.pill { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 5px 12px; border-radius: 999px; background: rgba(255,255,255,.18); font-size: .82rem; font-weight: 600; }
.pill svg { width: 15px; height: 15px; }
.body { padding: 24px; }
.state { text-align: center; }
.state[hidden] { display: none; }
.ic { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; background: var(--soft); color: var(--muted); }
.ic svg { width: 36px; height: 36px; }
.ic.wait { color: var(--brand); }
.ic.wait svg { animation: spin 1.1s linear infinite; }
.ic.bad { color: #b42318; background: #fdf0ef; }
@keyframes spin { to { transform: rotate(360deg); } }
h2 { margin: 0 0 6px; font-size: 1.25rem; }
.muted { color: var(--muted); font-size: .92rem; line-height: 1.5; margin: 0; }
.msg { color: var(--muted); font-size: .82rem; margin: 10px 0 0; }
.tips { text-align: left; margin: 16px 0 0; padding: 12px 14px 12px 32px; background: var(--soft); border: 1px solid var(--line); border-radius: 12px; font-size: .88rem; line-height: 1.55; }
.btn { display: block; width: 100%; margin-top: 20px; padding: 15px; border: 0; border-radius: 12px; background: var(--brand); color: var(--on-brand); font: inherit; font-size: 1rem; font-weight: 700; text-align: center; text-decoration: none; cursor: pointer; }
.btn.ghost { background: transparent; color: var(--brand); border: 1px solid var(--line); font-weight: 600; }
.foot { border-top: 1px solid var(--line); padding: 14px 24px 18px; text-align: center; font-size: .82rem; color: var(--muted); line-height: 1.6; }
.foot a { color: inherit; font-weight: 600; }
</style>
</head>
<body>
<main class="card">
<header class="head">' . $logoHtml . '<h1>' . hubinet_h($name) . '</h1><span class="pill">' . $icon['wifi'] . ' Guest Wi-Fi</span></header>
<div class="body">' . $body . '</div>
<footer class="foot">' . $helpLine . '</footer>
</main>
' . $script . '
</body>
</html>
';
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
    // The access field takes the hotspot password or a voucher. With the hotspot
    // switched off, only vouchers work.
    $code = trim((string)($post['hotspot_password'] ?? ''));
    $voucher = $code !== '' ? hubinet_find_voucher($conn, $code) : null;
    if ($voucher) {
        $problem = hubinet_voucher_problem($voucher);
        if ($problem !== '') {
            return hubinet_result(false, $problem);
        }
    } elseif (!$settings['hotspot_enabled']) {
        return hubinet_result(false, $code === ''
            ? 'Please enter your voucher code. Vouchers are available at reception.'
            : 'That voucher code is not valid. Check it and try again, or ask at reception.');
    } elseif ($settings['hotspot_password'] !== '' && !hash_equals($settings['hotspot_password'], $code)) {
        return hubinet_result(false, 'That access code or voucher is not correct. Ask at reception for today\'s code.');
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

    if ($voucher) {
        return hubinet_redeem_voucher($conn, $voucher, $mac, $name, $phone, $email, $ip);
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
    $dev = hubinet_add_device($conn, $personId, $mac, 'Hotspot', 0, 'auto', true, [$settings['hotspot_bw_down_kbps'], $settings['hotspot_bw_up_kbps']]);
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
            $seen[$mac] = ['ip' => mb_substr((string)($c['ip'] ?? ''), 0, 45), 'user' => null, 'portal' => 0, 'started' => null,
                'hostname' => mb_substr(trim((string)($c['hostname'] ?? '')), 0, 120),
                'vendor' => mb_substr(trim((string)($c['vendor'] ?? '')), 0, 120),
                'iface' => mb_substr(trim((string)($c['iface'] ?? '')), 0, 30)];
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
            'hostname' => $seen[$mac]['hostname'] ?? '',
            'vendor' => $seen[$mac]['vendor'] ?? '',
            'iface' => $seen[$mac]['iface'] ?? '',
        ];
    }

    // Name / maker / interface (agent 2.3+) keep their last known value when a report has none.
    $up = $conn->prepare('INSERT INTO hub_network_online (mac_address, ip_address, portal_user, in_portal, session_started, hostname, vendor, iface, first_seen, last_seen)
                          VALUES (?, NULLIF(?, \'\'), ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NOW(), NOW())
                          ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address), portal_user = VALUES(portal_user), in_portal = VALUES(in_portal),
                                                  session_started = COALESCE(VALUES(session_started), session_started),
                                                  hostname = COALESCE(VALUES(hostname), hostname), vendor = COALESCE(VALUES(vendor), vendor),
                                                  iface = COALESCE(VALUES(iface), iface), last_seen = NOW()');
    if ($up) {
        foreach ($seen as $mac => $r) {
            $hn = (string)($r['hostname'] ?? '');
            $vd = (string)($r['vendor'] ?? '');
            $if = (string)($r['iface'] ?? '');
            $up->bind_param('sssissss', $mac, $r['ip'], $r['user'], $r['portal'], $r['started'], $hn, $vd, $if);
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

/**
 * Devices online now, identified against registered devices. Filter: kind
 * (member|staff|guest|equipment|unknown). With $withPfList, manual pfSense
 * MAC entries the IMS does not manage are added as "unknown" too ('pf' holds
 * the pfSense entry, 'offline' => true when the device is not connected), so
 * they can be assigned or deleted like any unknown device.
 */
function hubinet_online(mysqli $conn, string $kind = '', bool $withPfList = false): array
{
    $rows = [];
    $res = $conn->query('SELECT o.* FROM hub_network_online o
                          WHERE o.last_seen >= DATE_SUB(NOW(), INTERVAL ' . HUBINET_ONLINE_STALE_MINUTES . ' MINUTE)
                          ORDER BY o.first_seen DESC LIMIT 5000');
    $online = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $pf = [];
    if ($withPfList) {
        $pres = $conn->query('SELECT p.* FROM hub_network_pfmacs p
                               LEFT JOIN hub_network_pfdelete x ON x.mac_address = p.mac_address
                              WHERE x.mac_address IS NULL ORDER BY p.descr IS NULL, p.descr, p.mac_address LIMIT 5000');
        foreach ($pres ? $pres->fetch_all(MYSQLI_ASSOC) : [] as $p) {
            $pf[$p['mac_address']] = $p;
        }
    }
    if (!$online && !$pf) {
        return [];
    }
    $byMac = [];
    foreach (hubnet_devices_where($conn, '1=1') as $d) {
        $byMac[hubnet_normalize_mac((string)$d['mac_address'])] = $d;
    }
    $seen = [];
    foreach ($online as $o) {
        $d = $byMac[$o['mac_address']] ?? null;
        $o['device'] = $d;
        $o['owner_kind'] = $d ? (!empty($d['device_type']) ? 'equipment' : (string)$d['owner_kind']) : 'unknown';
        $o['pf'] = $d ? null : ($pf[$o['mac_address']] ?? null);
        $o['offline'] = false;
        $seen[$o['mac_address']] = true;
        if ($kind !== '' && $o['owner_kind'] !== $kind) {
            continue;
        }
        $rows[] = $o;
    }
    if ($kind === '' || $kind === 'unknown') {
        foreach ($pf as $mac => $p) {
            if (isset($seen[$mac]) || isset($byMac[$mac])) {
                continue;
            }
            $rows[] = ['mac_address' => $mac, 'ip_address' => null, 'portal_user' => null, 'in_portal' => 0,
                'session_started' => null, 'first_seen' => null, 'last_seen' => null, 'hostname' => null, 'vendor' => null,
                'iface' => null, 'device' => null, 'owner_kind' => 'unknown', 'pf' => $p, 'offline' => true];
        }
    }
    return $rows;
}

/** Online now rows filtered by kind and search text (name, MAC, IP, maker, company, pfSense description). */
function hubinet_online_filtered(mysqli $conn, string $kind, string $q): array
{
    $rows = hubinet_online($conn, $kind, true);
    $q = trim($q);
    if ($q === '') {
        return $rows;
    }
    $needle = mb_strtolower($q);
    $macNeedle = preg_replace('/[^a-f0-9]/', '', $needle);
    return array_values(array_filter($rows, static function (array $o) use ($needle, $macNeedle): bool {
        $d = $o['device'] ?? [];
        $hay = mb_strtolower(implode(' ', [$o['mac_address'], $o['ip_address'], $o['portal_user'], $o['hostname'] ?? '', $o['vendor'] ?? '',
            $o['iface'] ?? '', $o['pf']['descr'] ?? '', $d['person_name'] ?? '', $d['company_name'] ?? '', $d['label'] ?? '', $d['person_organisation'] ?? '']));
        return str_contains($hay, $needle)
            || (strlen($macNeedle) >= 4 && str_contains(str_replace(':', '', (string)$o['mac_address']), $macNeedle));
    }));
}

/**
 * Deletes unknown devices: forgets them in the IMS and, for manual pfSense
 * MAC entries, asks the agent (2.6+) to delete those entries on pfSense.
 */
function hubinet_delete_unknown(mysqli $conn, array $macs, int $uid): array
{
    $macs = array_values(array_unique(array_filter(array_map(static fn($m) => hubnet_normalize_mac((string)$m), $macs))));
    $macs = array_values(array_filter($macs, static fn($m) => !hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$m])));
    if (!$macs) {
        return hubinet_result(false, 'Select unknown devices first (registered devices are removed from their owner\'s page).');
    }
    $queued = 0;
    foreach (array_chunk($macs, 500) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        $stmt = $conn->prepare("SELECT mac_address FROM hub_network_pfmacs WHERE mac_address IN ($marks)");
        $stmt->bind_param($types, ...$chunk);
        $stmt->execute();
        $inPf = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'mac_address');
        $stmt->close();
        if ($inPf) {
            $q = $conn->prepare('INSERT IGNORE INTO hub_network_pfdelete (mac_address, requested_by) VALUES (?, ?)');
            foreach ($inPf as $mac) {
                $q->bind_param('si', $mac, $uid);
                $q->execute();
                $queued += $q->affected_rows > 0 ? 1 : 0;
            }
            $q->close();
        }
        foreach (['hub_network_online', 'hub_network_pfmacs'] as $table) {
            $del = $conn->prepare("DELETE FROM $table WHERE mac_address IN ($marks)");
            $del->bind_param($types, ...$chunk);
            $del->execute();
            $del->close();
        }
    }
    if (function_exists('log_action')) {
        log_action($uid, 'Delete Unknown Devices', 'hub_network_online', null, count($macs) . ' device(s), ' . $queued . ' pfSense MAC entr(ies) queued for deletion');
    }
    return hubinet_result(true, count($macs) . ' device(s) deleted.'
        . ($queued ? ' ' . $queued . ' manual pfSense MAC entr' . ($queued === 1 ? 'y is' : 'ies are') . ' deleted on pfSense at its next check-in (agent 2.6).' : '')
        . ' Devices that are still connected appear again at the next check-in.');
}

/** Counts of devices online now by owner kind. */
function hubinet_online_counts(mysqli $conn): array
{
    $counts = ['total' => 0, 'member' => 0, 'staff' => 0, 'guest' => 0, 'equipment' => 0, 'unknown' => 0, 'portal' => 0];
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

/* ======================================================================
 | Internet vouchers for day clients (Hub Team & Internet > Vouchers)
 * ==================================================================== */

const HUBINET_VOUCHER_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L

/** "ABCD2345" from anything the guest typed ("abcd-2345", " ABCD 2345 "). */
function hubinet_voucher_normalize(string $code): string
{
    return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $code));
}

/** "ABCD-2345" for display and printing. */
function hubinet_voucher_format(string $code): string
{
    return strlen($code) === 8 ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
}

/** Duration label: "3 hours", "1 day", "1 week". */
function hubinet_voucher_duration(int $hours): string
{
    if ($hours % 168 === 0) {
        $n = intdiv($hours, 168);
        return $n . ' week' . ($n === 1 ? '' : 's');
    }
    if ($hours % 24 === 0) {
        $n = intdiv($hours, 24);
        return $n . ' day' . ($n === 1 ? '' : 's');
    }
    return $hours . ' hour' . ($hours === 1 ? '' : 's');
}

/** unused | active | used | expired | revoked */
function hubinet_voucher_status(array $v): string
{
    if (!empty($v['revoked_at'])) {
        return 'revoked';
    }
    if (empty($v['used_at'])) {
        return strtotime((string)$v['expires_at']) < time() ? 'expired' : 'unused';
    }
    return strtotime((string)$v['valid_until']) >= time() ? 'active' : 'used';
}

/** Creates a batch of vouchers. */
function hubinet_create_vouchers(mysqli $conn, array $post, int $uid): array
{
    $qty = (int)($post['quantity'] ?? 1);
    $hours = (int)($post['hours'] ?? 0) === -1 ? (int)($post['custom_hours'] ?? 0) : (int)($post['hours'] ?? 24);
    $devices = (int)($post['max_devices'] ?? 1);
    $days = (int)($post['use_within_days'] ?? 7);
    $note = mb_substr(trim((string)($post['note'] ?? '')), 0, 150);
    if ($qty < 1 || $qty > 200) {
        return hubinet_result(false, 'Create between 1 and 200 vouchers at a time.');
    }
    if ($hours < 1 || $hours > 2160) {
        return hubinet_result(false, 'Choose a duration between 1 hour and 90 days.');
    }
    if ($devices < 1 || $devices > 10) {
        return hubinet_result(false, 'A voucher can be used on 1 to 10 devices.');
    }
    if ($days < 1 || $days > 365) {
        return hubinet_result(false, 'Vouchers must be usable for 1 to 365 days.');
    }
    $bwDown = hubnet_parse_mbps($post['bw_down'] ?? '');
    $bwUp = hubnet_parse_mbps($post['bw_up'] ?? '');
    if ($bwDown === false || $bwUp === false) {
        return hubinet_result(false, 'Speed limits must be in Mbps (e.g. 5 or 0.5), or empty for the hotspot default.');
    }
    $bwDown = (int)$bwDown;
    $bwUp = (int)$bwUp;
    $batch = date('ymd-His');
    $expires = date('Y-m-d 23:59:59', strtotime('+' . ($days - 1) . ' days'));
    $stmt = $conn->prepare('INSERT IGNORE INTO hub_network_vouchers (code, batch, hours, max_devices, note, expires_at, bw_down_kbps, bw_up_kbps, created_by)
                            VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, NULLIF(?, 0), NULLIF(?, 0), ?)');
    $made = 0;
    $alphabet = HUBINET_VOUCHER_ALPHABET;
    for ($tries = 0; $made < $qty && $tries < $qty * 5; $tries++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt->bind_param('ssiissiii', $code, $batch, $hours, $devices, $note, $expires, $bwDown, $bwUp, $uid);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $made++;
        }
    }
    $stmt->close();
    return hubinet_result($made > 0, $made > 0
        ? $made . ' voucher(s) created: ' . hubinet_voucher_duration($hours) . ' each, ' . $devices . ' device(s), usable until ' . date('j M Y', strtotime($expires)) . '.'
        : 'Vouchers could not be created.', ['batch' => $batch]);
}

/** Voucher list. Filters: status, q (code, note or guest name), batch. */
function hubinet_vouchers(mysqli $conn, array $filters = []): array
{
    $where = ['1=1'];
    $types = '';
    $params = [];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(v.code LIKE ? OR v.note LIKE ? OR p.full_name LIKE ?)';
        $likeText = '%' . $q . '%';
        array_push($params, '%' . hubinet_voucher_normalize($q) . '%', $likeText, $likeText);
        $types .= 'sss';
    }
    if (($filters['batch'] ?? '') !== '') {
        $where[] = 'v.batch = ?';
        $params[] = (string)$filters['batch'];
        $types .= 's';
    }
    $where[] = match ($filters['status'] ?? '') {
        'unused' => 'v.revoked_at IS NULL AND v.used_at IS NULL AND v.expires_at >= NOW()',
        'active' => 'v.revoked_at IS NULL AND v.used_at IS NOT NULL AND v.valid_until >= NOW()',
        'used' => 'v.revoked_at IS NULL AND v.used_at IS NOT NULL AND v.valid_until < NOW()',
        'expired' => 'v.revoked_at IS NULL AND v.used_at IS NULL AND v.expires_at < NOW()',
        'revoked' => 'v.revoked_at IS NOT NULL',
        default => '1=1',
    };
    $stmt = $conn->prepare('SELECT v.*, p.full_name AS person_name, p.phone AS person_phone,
                   (SELECT COUNT(*) FROM hub_network_devices d WHERE d.person_id = v.person_id) AS device_count
              FROM hub_network_vouchers v
              LEFT JOIN hub_network_people p ON p.person_id = v.person_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY v.created_at DESC, v.voucher_id DESC LIMIT 500');
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['status'] = hubinet_voucher_status($row);
    }
    unset($row);
    return $rows;
}

/** Counts per status for the filter pills. */
function hubinet_voucher_counts(mysqli $conn): array
{
    $res = $conn->query("SELECT COUNT(*) total,
            SUM(revoked_at IS NULL AND used_at IS NULL AND expires_at >= NOW()) unused,
            SUM(revoked_at IS NULL AND used_at IS NOT NULL AND valid_until >= NOW()) active,
            SUM(revoked_at IS NULL AND used_at IS NOT NULL AND valid_until < NOW()) used,
            SUM(revoked_at IS NULL AND used_at IS NULL AND expires_at < NOW()) expired,
            SUM(revoked_at IS NOT NULL) revoked
        FROM hub_network_vouchers");
    $c = $res ? (array)$res->fetch_assoc() : [];
    $out = [];
    foreach (['total', 'unused', 'active', 'used', 'expired', 'revoked'] as $k) {
        $out[$k] = (int)($c[$k] ?? 0);
    }
    return $out;
}

/** Ends the internet access of the guest using a voucher (revoke / delete). */
function hubinet_voucher_end_access(mysqli $conn, int $personId, int $uid): void
{
    if ($personId <= 0) {
        return;
    }
    $stmt = $conn->prepare('UPDATE hub_network_people SET valid_until = NOW() WHERE person_id = ? AND (valid_until IS NULL OR valid_until > NOW())');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $stmt->close();
    hubnet_apply($conn, hubnet_devices_where($conn, 'd.person_id = ?', 'i', [$personId]), $uid, 'voucher-revoked');
}

/** Bulk revoke / delete. Revoking or deleting a voucher in use disconnects its devices. */
function hubinet_vouchers_bulk(mysqli $conn, array $ids, string $op, int $uid): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids || !in_array($op, ['revoke', 'delete'], true)) {
        return hubinet_result(false, 'Select vouchers first.');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT voucher_id, person_id, valid_until, revoked_at FROM hub_network_vouchers WHERE voucher_id IN ($marks)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $v) {
        if (empty($v['revoked_at']) && !empty($v['person_id']) && strtotime((string)$v['valid_until']) > time()) {
            hubinet_voucher_end_access($conn, (int)$v['person_id'], $uid);
        }
    }
    $stmt = $conn->prepare($op === 'delete'
        ? "DELETE FROM hub_network_vouchers WHERE voucher_id IN ($marks)"
        : "UPDATE hub_network_vouchers SET revoked_at = COALESCE(revoked_at, NOW()) WHERE voucher_id IN ($marks)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return hubinet_result(true, $n . ' voucher(s) ' . ($op === 'delete' ? 'deleted' : 'revoked') . '. Devices using them are disconnected.');
}

/** The voucher matching what a guest typed, or null. */
function hubinet_find_voucher(mysqli $conn, string $input): ?array
{
    $code = hubinet_voucher_normalize($input);
    if (strlen($code) !== 8) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM hub_network_vouchers WHERE code = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Why a voucher cannot be used right now, or '' when it can. */
function hubinet_voucher_problem(array $v): string
{
    return match (hubinet_voucher_status($v)) {
        'revoked' => 'This voucher has been cancelled. Please ask at reception.',
        'expired' => 'This voucher was not used in time and has expired. Please ask at reception for a new one.',
        'used' => 'This voucher has run out. Please ask at reception for a new one.',
        default => '',
    };
}

/**
 * Signs a device in with a voucher. The first use starts the access period;
 * further devices (up to max_devices) join the same guest until it ends.
 */
function hubinet_redeem_voucher(mysqli $conn, array $voucher, string $mac, string $name, string $phone, string $email, string $ip): array
{
    $label = 'Voucher ' . hubinet_voucher_format((string)$voucher['code']);
    $existing = hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac]);
    $dev = $existing[0] ?? null;
    if ($dev && $dev['owner_kind'] !== 'guest') {
        return hubinet_result(true, 'This device is already registered with the hub. You should be online within a minute.', ['until' => null]);
    }
    if ($dev && ($dev['access_mode'] === 'block' || ($dev['person_status'] ?? '') === 'Suspended')) {
        return hubinet_result(false, 'This device has been blocked from the hotspot. Please ask at reception.');
    }

    // Already in use: add this device to the same guest (within the device limit).
    if (!empty($voucher['person_id'])) {
        $pid = (int)$voucher['person_id'];
        $until = (string)$voucher['valid_until'];
        if ($dev && (int)$dev['person_id'] === $pid) {
            return hubinet_result(true, 'Welcome back, ' . $name . '!', ['until' => $until]);
        }
        $countRes = $conn->query('SELECT COUNT(*) n FROM hub_network_devices WHERE person_id = ' . $pid);
        $count = $countRes ? (int)($countRes->fetch_assoc()['n'] ?? 0) : 0;
        if ($count >= (int)$voucher['max_devices']) {
            return hubinet_result(false, 'This voucher is already in use on ' . $count . ' device' . ($count === 1 ? '' : 's') . ', the maximum allowed. Please ask at reception.');
        }
        if ($dev) {
            $devId = (int)$dev['device_id'];
            $stmt = $conn->prepare('UPDATE hub_network_devices SET person_id = ?, member_id = NULL, label = ? WHERE device_id = ?');
            $stmt->bind_param('isi', $pid, $label, $devId);
            $stmt->execute();
            $stmt->close();
            hubinet_set_device_bw($conn, [$devId], ...hubinet_voucher_bw($conn, $voucher));
            hubnet_apply($conn, hubnet_devices_where($conn, 'd.device_id = ?', 'i', [$devId]), 0, 'voucher');
        } else {
            $r = hubinet_add_device($conn, $pid, $mac, $label, 0, 'auto', true, hubinet_voucher_bw($conn, $voucher));
            if (!$r['ok']) {
                return $r;
            }
        }
        hubnet_log($conn, null, $mac, 'voucher:device', true, $label . ' ' . $ip, 0);
        return hubinet_result(true, 'Welcome, ' . $name . '!', ['until' => $until]);
    }

    // First use: the access period starts now.
    $until = date('Y-m-d H:i:s', time() + (int)$voucher['hours'] * 3600);
    if ($dev) {
        $pid = (int)$dev['person_id'];
        $stmt = $conn->prepare("UPDATE hub_network_people SET full_name = ?, phone = NULLIF(?, ''), email = NULLIF(?, ''),
                                valid_until = GREATEST(COALESCE(valid_until, NOW()), ?) WHERE person_id = ?");
        $stmt->bind_param('ssssi', $name, $phone, $email, $until, $pid);
        $stmt->execute();
        $stmt->close();
    } else {
        $note = mb_substr($label . (!empty($voucher['note']) ? ' - ' . $voucher['note'] : ''), 0, 255);
        $stmt = $conn->prepare("INSERT INTO hub_network_people (kind, full_name, email, phone, status, valid_until, source, note)
                                VALUES ('guest', ?, NULLIF(?, ''), NULLIF(?, ''), 'Active', ?, 'hotspot', ?)");
        $stmt->bind_param('sssss', $name, $email, $phone, $until, $note);
        if (!$stmt->execute()) {
            $stmt->close();
            return hubinet_result(false, 'We could not sign you in. Please try again.');
        }
        $pid = (int)$stmt->insert_id;
        $stmt->close();
    }

    // Claim the voucher (only if nobody claimed it in the meantime).
    $vid = (int)$voucher['voucher_id'];
    $stmt = $conn->prepare('UPDATE hub_network_vouchers SET person_id = ?, used_at = NOW(), valid_until = ? WHERE voucher_id = ? AND person_id IS NULL AND revoked_at IS NULL');
    $stmt->bind_param('isi', $pid, $until, $vid);
    $stmt->execute();
    $claimed = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$claimed) {
        return hubinet_result(false, 'This voucher was just used by someone else. Please ask at reception.');
    }

    if ($dev) {
        hubinet_set_device_bw($conn, [(int)$dev['device_id']], ...hubinet_voucher_bw($conn, $voucher));
        hubnet_apply($conn, hubnet_devices_where($conn, 'd.device_id = ?', 'i', [(int)$dev['device_id']]), 0, 'voucher');
    } else {
        $r = hubinet_add_device($conn, $pid, $mac, $label, 0, 'auto', true, hubinet_voucher_bw($conn, $voucher));
        if (!$r['ok']) {
            return $r;
        }
    }
    hubnet_log($conn, null, $mac, 'voucher:signin', true, $label . ' ' . $name . ' ' . $ip, 0);
    return hubinet_result(true, 'Welcome, ' . $name . '!', ['until' => $until]);
}

/** Speed limit for voucher devices: the voucher's own, else the hotspot default. [down, up] Kbit/s. */
function hubinet_voucher_bw(mysqli $conn, array $voucher): array
{
    if ((int)($voucher['bw_down_kbps'] ?? 0) > 0 || (int)($voucher['bw_up_kbps'] ?? 0) > 0) {
        return [(int)($voucher['bw_down_kbps'] ?? 0) ?: null, (int)($voucher['bw_up_kbps'] ?? 0) ?: null];
    }
    $settings = hubnet_settings($conn);
    return [$settings['hotspot_bw_down_kbps'] ?: null, $settings['hotspot_bw_up_kbps'] ?: null];
}

/** Forget unknown devices from the online list (they come back if still connected). */
function hubinet_forget_online(mysqli $conn, array $macs): int
{
    $macs = array_values(array_filter(array_map(static fn($m) => hubnet_normalize_mac((string)$m), $macs)));
    if (!$macs) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($macs), '?'));
    $stmt = $conn->prepare("DELETE FROM hub_network_online WHERE mac_address IN ($marks)");
    $stmt->bind_param(str_repeat('s', count($macs)), ...$macs);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/* ======================================================================
 | Guest -> hub member (Guests > person > Make member)
 * ==================================================================== */

/** Hub members for the "Make member" picker. */
function hubinet_member_options(mysqli $conn): array
{
    $res = $conn->query("SELECT m.member_id, m.company_name, m.membership_number, m.membership_status, u.full_name
                           FROM members m LEFT JOIN users u ON u.user_id = m.user_id
                          ORDER BY (m.membership_status = 'Active') DESC, COALESCE(NULLIF(m.company_name, ''), u.full_name) LIMIT 2000");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** Active team members of every company: [member_id => [[id, name], ...]] (Register device picker). */
function hubinet_team_options(mysqli $conn): array
{
    $out = [];
    $res = $conn->query("SELECT team_member_id, member_id, full_name FROM hub_team_members
                          WHERE status = 'Active' ORDER BY full_name LIMIT 5000");
    if ($res) {
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $t) {
            $out[(int)$t['member_id']][] = ['id' => (int)$t['team_member_id'], 'name' => (string)$t['full_name']];
        }
    }
    return $out;
}

/**
 * Registers one device seen online to a member company: the member's own
 * device, or a device of one of its team members. Access follows the
 * membership ("auto").
 */
function hubinet_register_member_device(mysqli $conn, string $mac, int $memberId, int $teamMemberId, string $label, int $uid): array
{
    $mac = hubnet_normalize_mac($mac);
    if ($mac === '') {
        return hubinet_result(false, 'Invalid MAC address.');
    }
    $member = hubnet_member($conn, $memberId);
    if (!$member) {
        return hubinet_result(false, 'Choose the member company the device belongs to.');
    }
    $who = (string)($member['company_name'] ?? '') ?: (string)($member['full_name'] ?? '') ?: 'the member';
    if ($teamMemberId > 0) {
        $stmt = $conn->prepare('SELECT full_name FROM hub_team_members WHERE team_member_id = ? AND member_id = ? LIMIT 1');
        $stmt->bind_param('ii', $teamMemberId, $memberId);
        $stmt->execute();
        $tm = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$tm) {
            return hubinet_result(false, 'That person is not on this company\'s team.');
        }
        $who = $tm['full_name'] . ' (' . $who . ')';
    }
    $taken = hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac]);
    if ($taken) {
        return hubinet_result(false, $mac . ' is already registered to ' . ($taken[0]['person_name'] ?? 'someone') . '.');
    }
    $label = mb_substr(trim($label), 0, 80);
    $tmId = $teamMemberId > 0 ? $teamMemberId : null;
    $stmt = $conn->prepare("INSERT INTO hub_network_devices (member_id, team_member_id, person_id, mac_address, label, access_mode, created_by)
                            VALUES (?, ?, NULL, ?, NULLIF(?, ''), 'auto', NULLIF(?, 0))");
    $stmt->bind_param('iissi', $memberId, $tmId, $mac, $label, $uid);
    $ok = $stmt->execute();
    $deviceId = (int)$stmt->insert_id;
    $stmt->close();
    if (!$ok || $deviceId <= 0) {
        return hubinet_result(false, 'The device could not be saved.');
    }
    $net = hubnet_apply($conn, hubnet_devices_where($conn, 'd.device_id = ?', 'i', [$deviceId]), $uid, 'register');
    if (function_exists('log_action')) {
        log_action($uid, 'Register online device to member', 'hub_network_devices', $deviceId, $mac . ' -> ' . $who);
    }
    return hubinet_result(true, 'Device ' . $mac . ' registered to ' . $who . '. ' . $net['message']);
}

/**
 * Moves a guest (or staff entry) and their devices to a hub member: as a team
 * member of that company (uses a seat), or as the member's own devices.
 * The guest record is removed; internet access then follows the membership.
 */
function hubinet_person_to_member(mysqli $conn, int $personId, int $memberId, string $as, string $jobTitle, int $uid): array
{
    $person = hubinet_person($conn, $personId);
    if (!$person) {
        return hubinet_result(false, 'Guest not found.');
    }
    $member = hubnet_member($conn, $memberId);
    if (!$member) {
        return hubinet_result(false, 'Choose the hub member (company) to add them to.');
    }
    $asTeam = $as !== 'primary';
    $company = (string)($member['company_name'] ?: $member['full_name'] ?: 'the member');
    if ($asTeam) {
        if ($member['seats_max'] === null) {
            return hubinet_result(false, 'Set a workspace package for ' . $company . ' first (Hub Operations > Edit member), or add the devices as the member\'s own.');
        }
        if (!hubnet_seat_available($member)) {
            return hubinet_result(false, 'All ' . $member['seats_max'] . ' seat(s) of ' . $company . ' are taken. Upgrade the package or suspend someone first.');
        }
    }

    $conn->begin_transaction();
    try {
        $teamId = null;
        if ($asTeam) {
            $name = (string)$person['full_name'];
            $email = (string)($person['email'] ?? '');
            $phone = (string)($person['phone'] ?? '');
            $job = mb_substr(trim($jobTitle), 0, 120);
            $stmt = $conn->prepare("INSERT INTO hub_team_members (member_id, full_name, email, phone, job_title, created_by)
                                    VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?)");
            $stmt->bind_param('issssi', $memberId, $name, $email, $phone, $job, $uid);
            $stmt->execute();
            $teamId = (int)$stmt->insert_id;
            $stmt->close();
        }
        $stmt = $conn->prepare('UPDATE hub_network_devices SET member_id = ?, team_member_id = ?, person_id = NULL WHERE person_id = ?');
        $stmt->bind_param('iii', $memberId, $teamId, $personId);
        $stmt->execute();
        $moved = $stmt->affected_rows;
        $stmt->close();
        $stmt = $conn->prepare('DELETE FROM hub_network_people WHERE person_id = ?');
        $stmt->bind_param('i', $personId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[hub-internet] person_to_member: ' . $e->getMessage());
        return hubinet_result(false, 'The guest could not be moved. Nothing was changed.');
    }

    hubnet_log($conn, null, '', 'guest:to-member', true, $person['full_name'] . ' -> member #' . $memberId . ($asTeam ? ' (team)' : ' (primary)'), $uid);
    $net = $moved > 0 ? hubnet_apply_member($conn, $memberId, $uid, 'guest-to-member') : ['message' => ''];
    if (function_exists('log_action')) {
        log_action($uid, 'Guest To Member', 'hub_network_people', $personId, $person['full_name'] . ' moved to ' . $company);
    }
    return hubinet_result(true, $person['full_name'] . ' is now ' . ($asTeam ? 'a team member of ' : 'part of ') . $company
        . ($moved ? ' with ' . $moved . ' device(s)' : '') . '. Internet access now follows the membership. ' . $net['message'],
        ['member_id' => $memberId]);
}

/* ======================================================================
 | Network equipment (access points, switches, printers...) and device details
 * ==================================================================== */

const HUBINET_DEVICE_TYPES = [
    'access_point' => 'Access point',
    'switch' => 'Switch / router',
    'printer' => 'Printer',
    'camera' => 'Camera',
    'display' => 'TV / display',
    'other' => 'Other equipment',
];

/** Private / randomised MAC (locally administered bit): changes per network or over time. */
function hubinet_is_random_mac(string $mac): bool
{
    $mac = hubnet_normalize_mac($mac);
    return $mac !== '' && in_array($mac[1], ['2', '6', 'a', 'e'], true);
}

/** The "Network equipment" owner that equipment devices are registered to (created on first use). */
function hubinet_equipment_person(mysqli $conn, int $uid): int
{
    $res = $conn->query("SELECT person_id FROM hub_network_people WHERE kind = 'staff' AND full_name = 'Network equipment' AND organisation = 'Infrastructure' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if ($row) {
        return (int)$row['person_id'];
    }
    $stmt = $conn->prepare("INSERT INTO hub_network_people (kind, full_name, organisation, note, status, valid_until, source)
                            VALUES ('staff', 'Network equipment', 'Infrastructure', 'Access points, switches, printers and other hub equipment', 'Active', NULL, 'manual')");
    if (!$stmt || !$stmt->execute()) {
        return 0;
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Registers devices seen online as network equipment: always online, typed,
 * named from $labels[mac] (else their DHCP name / maker). Skips devices that
 * are already registered.
 */
function hubinet_register_equipment(mysqli $conn, array $macs, string $type, array $labels, int $uid): array
{
    $type = array_key_exists($type, HUBINET_DEVICE_TYPES) ? $type : 'access_point';
    $pid = hubinet_equipment_person($conn, $uid);
    if ($pid <= 0) {
        return hubinet_result(false, 'The equipment list could not be created.');
    }
    $ids = [];
    $skipped = 0;
    foreach ($macs as $mac) {
        $mac = hubnet_normalize_mac((string)$mac);
        if ($mac === '' || hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac])) {
            $skipped++;
            continue;
        }
        $label = trim((string)($labels[$mac] ?? ''));
        if ($label === '') {
            $stmt = $conn->prepare('SELECT hostname, vendor FROM hub_network_online WHERE mac_address = ? LIMIT 1');
            $stmt->bind_param('s', $mac);
            $stmt->execute();
            $seen = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $label = trim((string)($seen['hostname'] ?? '')) ?: trim((string)($seen['vendor'] ?? '')) ?: HUBINET_DEVICE_TYPES[$type];
        }
        $r = hubinet_add_device($conn, $pid, $mac, $label, $uid, 'allow', false);
        if ($r['ok']) {
            $ids[] = (int)$r['device_id'];
        } else {
            $skipped++;
        }
    }
    if (!$ids) {
        return hubinet_result(false, 'No devices were added' . ($skipped ? ' (' . $skipped . ' already registered or invalid)' : '') . '.');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE hub_network_devices SET device_type = ? WHERE device_id IN ($marks)");
    $stmt->bind_param('s' . str_repeat('i', count($ids)), $type, ...$ids);
    $stmt->execute();
    $stmt->close();
    $net = hubnet_apply($conn, hubnet_devices_where($conn, "d.device_id IN ($marks)", str_repeat('i', count($ids)), $ids), $uid, 'equipment');
    return hubinet_result(true, count($ids) . ' device(s) registered as ' . strtolower(HUBINET_DEVICE_TYPES[$type]) . ' (always online)'
        . ($skipped ? '; ' . $skipped . ' skipped' : '') . '. ' . $net['message']);
}

/* ======================================================================
 | MAC entries added by hand on pfSense (agent 2.4): list and import
 * ==================================================================== */

/** Replaces the stored list with what pfSense reported at this check-in. */
function hubinet_record_pf_macs(mysqli $conn, array $entries): void
{
    $conn->query('DELETE FROM hub_network_pfmacs');
    $stmt = $conn->prepare('INSERT IGNORE INTO hub_network_pfmacs (mac_address, action, descr, bw_down_kbps, bw_up_kbps, seen_at)
                            VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, 0), NULLIF(?, 0), NOW())');
    if (!$stmt) {
        return;
    }
    foreach (array_slice($entries, 0, 5000) as $e) {
        $mac = hubnet_normalize_mac((string)($e['mac'] ?? ''));
        if ($mac === '') {
            continue;
        }
        $action = ($e['action'] ?? '') === 'block' ? 'block' : 'pass';
        $descr = mb_substr(trim((string)($e['descr'] ?? '')), 0, 120);
        $down = max(0, (int)($e['bw_down'] ?? 0));
        $up = max(0, (int)($e['bw_up'] ?? 0));
        $stmt->bind_param('sssii', $mac, $action, $descr, $down, $up);
        $stmt->execute();
    }
    $stmt->close();
}

/** WHERE for manual pfSense entries not managed yet, optionally matching $q (description, MAC, name, maker, IP). */
function hubinet_pf_where(string $q, string &$types, array &$params): string
{
    $where = 'd.device_id IS NULL';
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $macLike = '%' . implode(':', str_split(strtolower((string)preg_replace('/[^A-Fa-f0-9]/', '', $q)), 2)) . '%';
        $where .= ' AND (p.descr LIKE ? OR p.mac_address LIKE ? OR o.hostname LIKE ? OR o.vendor LIKE ? OR o.ip_address LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, strlen(trim($macLike, '%:')) >= 2 ? $macLike : $like, $like, $like, $like);
    }
    return $where;
}

/**
 * Manual pfSense MAC entries the IMS does not manage yet, with what pfSense
 * saw of them online. Returns ['rows' => [...], 'total' => n].
 */
function hubinet_pf_macs(mysqli $conn, string $q = '', int $limit = 100, int $offset = 0): array
{
    $types = '';
    $params = [];
    $where = hubinet_pf_where($q, $types, $params);
    $from = 'FROM hub_network_pfmacs p
             LEFT JOIN hub_network_online o ON o.mac_address = p.mac_address
             LEFT JOIN hub_network_devices d ON d.mac_address = p.mac_address
             WHERE ' . $where;
    $stmt = $conn->prepare('SELECT COUNT(*) n ' . $from);
    if (!$stmt) {
        return ['rows' => [], 'total' => 0];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    $stmt = $conn->prepare('SELECT p.*, o.hostname, o.vendor, o.ip_address, o.last_seen ' . $from
        . ' ORDER BY p.descr IS NULL, p.descr, p.mac_address LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['rows' => $rows, 'total' => $total];
}

/** MACs of every unmanaged pfSense entry matching $q (for "import all matching"). */
function hubinet_pf_macs_matching(mysqli $conn, string $q): array
{
    $types = '';
    $params = [];
    $where = hubinet_pf_where($q, $types, $params);
    $stmt = $conn->prepare('SELECT p.mac_address FROM hub_network_pfmacs p
                             LEFT JOIN hub_network_online o ON o.mac_address = p.mac_address
                             LEFT JOIN hub_network_devices d ON d.mac_address = p.mac_address
                            WHERE ' . $where . ' LIMIT 5000');
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $macs = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'mac_address');
    $stmt->close();
    return $macs;
}

/**
 * Imports manual pfSense MAC entries into the IMS. $as: equipment (with
 * $type), guest or staff (one person per device, named from the pfSense
 * description), or existing ($personId). Pass entries become "Always on",
 * block entries "Blocked"; speed limits are kept. At the next check-in the
 * agent replaces the manual entries with IMS-managed ones.
 */
function hubinet_import_pf_macs(mysqli $conn, array $macs, string $as, string $type, int $personId, int $uid, int $memberId = 0): array
{
    $macs = array_values(array_unique(array_filter(array_map(static fn($m) => hubnet_normalize_mac((string)$m), $macs))));
    if (!$macs) {
        return hubinet_result(false, 'Select unknown devices first.');
    }
    if (!in_array($as, ['equipment', 'guest', 'staff', 'existing', 'member'], true)) {
        return hubinet_result(false, 'Choose how to import the devices.');
    }
    if ($as === 'existing' && !hubinet_person($conn, $personId)) {
        return hubinet_result(false, 'Choose the staff member or guest the devices belong to.');
    }
    $member = $as === 'member' ? hubnet_member($conn, $memberId) : null;
    if ($as === 'member' && !$member) {
        return hubinet_result(false, 'Choose the member company the devices belong to.');
    }
    if (count($macs) > 2000) {
        return hubinet_result(false, 'Import at most 2000 entries at a time (narrow the search).');
    }
    $type = array_key_exists($type, HUBINET_DEVICE_TYPES) ? $type : 'access_point';
    $marks = implode(',', array_fill(0, count($macs), '?'));
    // Works for pfSense-list entries and for unknown devices only seen online:
    // pfSense entry first (pass/block, description, speed), else what pfSense saw online.
    $byMac = array_fill_keys($macs, null);
    $stmt = $conn->prepare("SELECT * FROM hub_network_pfmacs WHERE mac_address IN ($marks)");
    $stmt->bind_param(str_repeat('s', count($macs)), ...$macs);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) {
        $byMac[$p['mac_address']] = $p;
    }
    $stmt->close();
    $stmt = $conn->prepare("SELECT mac_address, hostname, vendor FROM hub_network_online WHERE mac_address IN ($marks)");
    $stmt->bind_param(str_repeat('s', count($macs)), ...$macs);
    $stmt->execute();
    $seenOnline = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        $seenOnline[$o['mac_address']] = $o;
    }
    $stmt->close();
    $rows = [];
    foreach ($byMac as $mac => $p) {
        $rows[] = [
            'mac_address' => $mac,
            'action' => $p['action'] ?? 'pass',
            'descr' => $p['descr'] ?? null,
            'bw_down_kbps' => $p['bw_down_kbps'] ?? null,
            'bw_up_kbps' => $p['bw_up_kbps'] ?? null,
            'hostname' => $seenOnline[$mac]['hostname'] ?? null,
            'vendor' => $seenOnline[$mac]['vendor'] ?? null,
        ];
    }

    $equipmentPid = $as === 'equipment' ? hubinet_equipment_person($conn, $uid) : 0;
    $ids = [];
    $failed = [];
    foreach ($rows as $row) {
        $mac = (string)$row['mac_address'];
        if (hubnet_devices_where($conn, 'd.mac_address = ?', 's', [$mac])) {
            continue; // registered in the meantime
        }
        $label = trim((string)($row['descr'] ?? '')) ?: trim((string)($row['hostname'] ?? '')) ?: trim((string)($row['vendor'] ?? ''));
        $mode = $row['action'] === 'block' ? 'block' : 'allow';
        if ($as === 'member') {
            // The company's own devices: online while the membership is active ("auto"), blocked stays blocked.
            $memberMode = $mode === 'block' ? 'block' : 'auto';
            $ins = $conn->prepare("INSERT INTO hub_network_devices (member_id, team_member_id, person_id, mac_address, label, access_mode, created_by)
                                   VALUES (?, NULL, NULL, ?, NULLIF(?, ''), ?, NULLIF(?, 0))");
            $ins->bind_param('isssi', $memberId, $mac, $label, $memberMode, $uid);
            if (!$ins->execute()) {
                $ins->close();
                $failed[] = $mac;
                continue;
            }
            $deviceId = (int)$ins->insert_id;
            $ins->close();
            hubinet_set_device_bw($conn, [$deviceId], $row['bw_down_kbps'] !== null ? (int)$row['bw_down_kbps'] : null, $row['bw_up_kbps'] !== null ? (int)$row['bw_up_kbps'] : null);
            $ids[] = $deviceId;
            continue;
        }
        $pid = $as === 'existing' ? $personId : $equipmentPid;
        if ($as === 'guest' || $as === 'staff') {
            $r = hubinet_save_person($conn, [
                'kind' => $as,
                'full_name' => $label !== '' ? $label : 'Device ' . $mac,
                'no_expiry' => 1,
                'note' => 'Imported from the pfSense MAC list',
            ], $uid);
            $pid = $r['ok'] ? (int)$r['person_id'] : 0;
        }
        if ($pid <= 0) {
            $failed[] = $mac;
            continue;
        }
        $r = hubinet_add_device($conn, $pid, $mac, $label, $uid, $mode, false);
        if (!$r['ok']) {
            $failed[] = $mac;
            continue;
        }
        $deviceId = (int)$r['device_id'];
        hubinet_set_device_bw($conn, [$deviceId], $row['bw_down_kbps'] !== null ? (int)$row['bw_down_kbps'] : null, $row['bw_up_kbps'] !== null ? (int)$row['bw_up_kbps'] : null);
        if ($as === 'equipment') {
            $u = $conn->prepare('UPDATE hub_network_devices SET device_type = ? WHERE device_id = ?');
            $u->bind_param('si', $type, $deviceId);
            $u->execute();
            $u->close();
        }
        $ids[] = $deviceId;
    }
    if (!$ids) {
        return hubinet_result(false, 'Nothing was imported' . ($failed ? ': ' . implode(', ', array_slice($failed, 0, 5)) . ' could not be added' : ' (already registered?)') . '.');
    }
    $idMarks = implode(',', array_fill(0, count($ids), '?'));
    $net = hubnet_apply($conn, hubnet_devices_where($conn, "d.device_id IN ($idMarks)", str_repeat('i', count($ids)), $ids), $uid, 'import');
    $del = $conn->prepare("DELETE FROM hub_network_pfmacs WHERE mac_address IN ($marks)");
    $del->bind_param(str_repeat('s', count($macs)), ...$macs);
    $del->execute();
    $del->close();
    return hubinet_result(true, count($ids) . ' device(s) assigned'
        . ($member ? ' to ' . ($member['company_name'] ?: $member['full_name']) . ' (online while the membership is active)' : '')
        . ($failed ? '; ' . count($failed) . ' failed' : '')
        . '. pfSense switches them to IMS management at its next check-in. ' . $net['message'], ['member_id' => $member ? $memberId : 0]);
}

/** MACs of manual pfSense entries waiting to be deleted on pfSense. */
function hubinet_pf_delete_queue(mysqli $conn): array
{
    $res = $conn->query('SELECT mac_address FROM hub_network_pfdelete ORDER BY requested_at LIMIT 5000');
    return $res ? array_column($res->fetch_all(MYSQLI_ASSOC), 'mac_address') : [];
}

/** Clears queued deletions that pfSense no longer has in its manual MAC list. */
function hubinet_pf_delete_done(mysqli $conn, array $stillListed): void
{
    $still = array_flip(array_filter(array_map(static fn($m) => hubnet_normalize_mac((string)$m), $stillListed)));
    $del = $conn->prepare('DELETE FROM hub_network_pfdelete WHERE mac_address = ?');
    foreach (hubinet_pf_delete_queue($conn) as $mac) {
        if (!isset($still[$mac])) {
            $del->bind_param('s', $mac);
            $del->execute();
        }
    }
    $del->close();
}
