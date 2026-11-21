<?php
/*
| Hub Team & Internet: Overview, Staff, Guests, Online now, Hotspot views.
| Included by hub-team.php (uses its $conn, $settings, $view, permission
| flags and ht_e()). Forms post to includes/hub-internet-process.php.
| Logic: includes/hub-internet.php.
*/

$hiAction = 'includes/hub-internet-process.php';
$hiAgentVersion = (string)($settings['last_checkin_info']['version'] ?? '');
$hiAgentOld = $hiAgentVersion !== '' && version_compare($hiAgentVersion, '2.1', '<');
$hiOnlineAgent = hubnet_agent_online($settings);
$hiPortalActual = $settings['last_checkin_info']['portal_enabled'] ?? null;

/** Status badge for a staff member / guest. */
$hiPersonBadge = static function (array $p): string {
    if ($p['status'] === 'Suspended') {
        return '<span class="badge badge-danger">Suspended</span>';
    }
    if (!empty($p['valid_until']) && strtotime((string)$p['valid_until']) < time()) {
        return '<span class="badge badge-secondary">Expired</span>';
    }
    return '<span class="badge badge-success">Active</span>';
};
$hiKindBadge = static function (string $kind): string {
    return match ($kind) {
        'member' => '<span class="badge badge-info">Member</span>',
        'staff' => '<span class="badge badge-purple">Staff</span>',
        'guest' => '<span class="badge badge-warning">Guest</span>',
        default => '<span class="badge badge-secondary">Unknown</span>',
    };
};
$hiHidden = static function (string $action, string $view, int $person = 0): string {
    return csrf_field() . '<input type="hidden" name="action" value="' . ht_e($action) . '"><input type="hidden" name="return_view" value="' . ht_e($view) . '">'
        . ($person > 0 ? '<input type="hidden" name="return_person" value="' . $person . '">' : '');
};
?>
<link rel="stylesheet" href="css/hr.css">
<style>
.hi-switch { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.hi-switch form { margin: 0; }
.hi-switch button { width: 100%; height: 100%; text-align: left; padding: 16px; border: 2px solid var(--border); border-radius: var(--radius-lg, 12px); background: var(--surface-card); cursor: pointer; display: flex; gap: 12px; align-items: flex-start; }
.hi-switch button:hover { border-color: var(--brand-500); }
.hi-switch button.is-current { border-color: var(--brand-600); background: var(--brand-50); }
.hi-switch button i { font-size: 22px; margin-top: 2px; }
.hi-switch strong { display: block; color: var(--ink-900); }
.hi-switch span { color: var(--ink-400); font-size: 13px; }
.hi-switch .on i { color: var(--green-fg); } .hi-switch .off i { color: var(--amber-fg); } .hi-switch .unmanaged i { color: var(--ink-300); }
.hi-status { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 12px; font-size: 13px; color: var(--ink-500); }
.hi-charts { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 20px; }
.hi-chart { position: relative; height: 240px; }
.hi-qr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
.hi-qr-box { text-align: center; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-lg, 12px); }
.hi-qr-box .qr { display: inline-block; padding: 10px; background: #fff; border-radius: 8px; }
.hi-qr-box code { display: block; margin-top: 8px; word-break: break-all; font-size: 12px; }
.hi-steps { margin: 0; padding-left: 20px; line-height: 1.7; }
.hi-person-head { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; }
@media (max-width: 900px) { .hi-switch, .hi-charts, .hi-qr { grid-template-columns: 1fr; } }
@media print {
  body.hi-printing > *:not(.hi-poster-host) { display: none !important; }
  .hi-poster-host .hi-poster { display: block !important; }
}
.hi-poster { display: none; text-align: center; font-family: system-ui, sans-serif; padding: 30px; }
.hi-poster h1 { font-size: 34px; margin: 0 0 6px; } .hi-poster p { font-size: 18px; }
.hi-poster .row { display: flex; justify-content: center; gap: 50px; margin-top: 24px; }
.hi-poster .pw { font-size: 26px; font-weight: 800; letter-spacing: 2px; margin-top: 18px; }
</style>

<?php
/* =====================================================================
   OVERVIEW: internet switch, who is online, history
===================================================================== */
if ($view === 'overview'):
    $counts = hubinet_online_counts($conn);
    $history = hubinet_history($conn, 24);
    $peaks = hubinet_daily_peaks($conn, 14);
    $peakToday = 0;
    foreach ($history as $hrow) {
        if (substr($hrow['hour'], 0, 10) === date('Y-m-d')) {
            $peakToday = max($peakToday, $hrow['max']);
        }
    }
    $reg = $conn->query("SELECT
            (SELECT COUNT(*) FROM hub_network_devices) AS devices,
            (SELECT COUNT(*) FROM hub_network_people WHERE kind = 'staff' AND status = 'Active') AS staff,
            (SELECT COUNT(*) FROM hub_network_people WHERE kind = 'guest' AND status = 'Active' AND (valid_until IS NULL OR valid_until >= NOW())) AS guests")->fetch_assoc();
    $mode = $settings['portal_control'];
?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-power-off" aria-hidden="true"></i> Internet control</h3>
        <span class="badge <?= $hiOnlineAgent ? 'badge-success' : 'badge-danger' ?>"><?= $hiOnlineAgent ? 'pfSense checked in ' . ht_e(date('H:i', strtotime((string)$settings['last_checkin_at']))) : 'pfSense not checking in' ?></span>
    </div>
    <div class="card-body">
        <div class="hi-switch">
            <?php foreach ([
                'on' => ['fa-shield-halved', 'Controlled (portal on)', 'Only registered members, staff, allowed devices and hotspot guests get online.'],
                'off' => ['fa-globe', 'Open internet (portal off)', 'The captive portal is switched off: every device gets online, blocks do not apply.'],
                'unmanaged' => ['fa-hand', 'Leave to pfSense', 'The IMS does not switch the portal on or off; it is used as configured on pfSense.'],
            ] as $key => [$icon, $title, $desc]): ?>
                <form method="post" action="<?= $hiAction ?>" <?= $key === 'off' ? 'data-confirm="Switch the captive portal OFF? Every device will get open internet, including blocked ones, until you switch it back on." data-confirm-title="Open internet for everyone" data-confirm-ok="Open internet" data-confirm-danger' : ($key === 'on' ? 'data-confirm="Switch internet control ON? Devices that are not registered will have to sign in through the hotspot." data-confirm-title="Turn internet control on" data-confirm-ok="Turn on"' : '') ?>>
                    <?= $hiHidden('portal_switch', 'overview') ?>
                    <input type="hidden" name="portal" value="<?= $key ?>">
                    <button type="submit" class="<?= $key ?><?= $mode === $key ? ' is-current' : '' ?>" <?= $canSwitch ? '' : 'disabled' ?> aria-pressed="<?= $mode === $key ? 'true' : 'false' ?>">
                        <i class="fas <?= $icon ?>" aria-hidden="true"></i>
                        <div><strong><?= $title ?><?= $mode === $key ? ' · current' : '' ?></strong><span><?= $desc ?></span></div>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
        <div class="hi-status">
            <span><strong>Portal on pfSense:</strong> <?= $hiPortalActual === null ? 'not reported yet' : ($hiPortalActual ? 'enabled' : 'disabled (open)') ?></span>
            <span><strong>Access control:</strong> <?= $settings['enabled'] ? 'on' : 'off' ?></span>
            <span><strong>Agent:</strong> <?= $hiAgentVersion !== '' ? 'version ' . ht_e($hiAgentVersion) : 'unknown' ?></span>
        </div>
        <?php if ($hiAgentOld || ($hiAgentVersion === '' && $mode !== 'unmanaged')): ?>
            <div class="alert alert-warning" style="margin-top:12px"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> The internet switch and the online-device count need agent 2.1. Copy the new <code>tools/pfsense/ims-cp-agent.php</code> to <code>/root/</code> on pfSense (keep your secret and URL settings).</div>
        <?php endif; ?>
        <?php if (!$canSwitch): ?><p class="cell-muted" style="margin-top:8px">Only administrators and IT can change the internet switch.</p><?php endif; ?>
    </div>
</div>

<div class="stats-grid reports-stats">
    <div class="stat-card" data-stat-href="hub-team?view=online"><div class="stat-icon bg-green"><i class="fas fa-signal" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Devices online now</span><span class="stat-value"><?= $counts['total'] ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=online&amp;kind=member"><div class="stat-icon bg-blue"><i class="fas fa-building" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Members' devices online</span><span class="stat-value"><?= $counts['member'] ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=online&amp;kind=staff"><div class="stat-icon bg-purple"><i class="fas fa-id-badge" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Staff devices online</span><span class="stat-value"><?= $counts['staff'] ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=online&amp;kind=guest"><div class="stat-icon bg-amber"><i class="fas fa-user-clock" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Guest devices online</span><span class="stat-value"><?= $counts['guest'] ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=online&amp;kind=unknown"><div class="stat-icon bg-red"><i class="fas fa-circle-question" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Unknown devices online</span><span class="stat-value"><?= $counts['unknown'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-teal"><i class="fas fa-chart-line" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Peak today</span><span class="stat-value"><?= $peakToday ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=companies&amp;tab=devices"><div class="stat-icon bg-slate"><i class="fas fa-laptop" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Registered devices</span><span class="stat-value"><?= (int)$reg['devices'] ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=guests&amp;status=active"><div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Active staff · guests</span><span class="stat-value"><?= (int)$reg['staff'] ?> · <?= (int)$reg['guests'] ?></span></div></div>
</div>

<div class="hi-charts">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-column" aria-hidden="true"></i> Devices connected, last 24 hours</h3></div>
        <div class="card-body"><div class="hi-chart"><canvas id="hiHourly" role="img" aria-label="Devices connected per hour over the last 24 hours"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-line" aria-hidden="true"></i> Daily peak, 14 days</h3></div>
        <div class="card-body"><div class="hi-chart"><canvas id="hiDaily" role="img" aria-label="Peak connected devices per day over the last 14 days"></canvas></div></div>
    </div>
</div>
<?php if (array_sum(array_column($history, 'max')) === 0): ?>
    <p class="cell-muted">No connection data yet. Counts appear once pfSense runs agent 2.1 and checks in.</p>
<?php endif; ?>
<script>
window.addEventListener('load', function () {
    if (!window.Chart) return;
    var hourly = <?= json_encode($history) ?>, daily = <?= json_encode($peaks) ?>;
    var css = getComputedStyle(document.documentElement);
    var brand = (css.getPropertyValue('--brand-500') || '#f97316').trim(), green = (css.getPropertyValue('--green-fg') || '#15803d').trim();
    new Chart(document.getElementById('hiHourly'), {
        type: 'bar',
        data: { labels: hourly.map(function (r) { return r.hour.slice(11, 16); }),
            datasets: [{ label: 'Peak', data: hourly.map(function (r) { return r.max; }), backgroundColor: brand, borderRadius: 4 },
                       { label: 'Average', data: hourly.map(function (r) { return r.avg; }), type: 'line', borderColor: green, pointRadius: 0, tension: .3 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom' } } }
    });
    new Chart(document.getElementById('hiDaily'), {
        type: 'line',
        data: { labels: daily.map(function (r) { return r.day.slice(5); }),
            datasets: [{ label: 'Peak devices', data: daily.map(function (r) { return r.peak; }), borderColor: brand, backgroundColor: brand, tension: .3, fill: false }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
    });
});
</script>

<?php
/* =====================================================================
   STAFF / GUESTS: list, one person, add / edit, bulk actions
===================================================================== */
elseif ($view === 'staff' || $view === 'guests'):
    $kind = $view === 'staff' ? 'staff' : 'guest';
    $label = $kind === 'staff' ? 'Staff' : 'Guests';
    $personId = (int)($_GET['person'] ?? 0);
    $person = $personId > 0 ? hubinet_person($conn, $personId) : null;
    if ($person && $person['kind'] !== $kind) {
        $person = null;
    }
    $staffCandidates = $kind === 'staff' && $canManage ? hubinet_staff_candidates($conn) : [];

    if ($person):
        $pDevices = hubinet_person_devices($conn, $personId);
        $onlineMacs = array_flip(array_column(hubinet_online($conn), 'mac_address'));
?>
<p><a href="hub-team?view=<?= $view ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> All <?= strtolower($label) ?></a></p>
<div class="card">
    <div class="card-header hi-person-head">
        <h3><i class="fas <?= $kind === 'staff' ? 'fa-id-badge' : 'fa-user-clock' ?>" aria-hidden="true"></i> <?= ht_e($person['full_name']) ?> <?= $hiPersonBadge($person) ?></h3>
        <?php if ($canControl): ?>
        <div class="ht-actions">
            <?php foreach (['block' => ['Disconnect all', 'fa-ban', 'btn-danger'], 'auto' => ['Automatic', 'fa-wifi', 'btn-soft'], 'allow' => ['Always on', 'fa-check', 'btn-soft']] as $m => [$t, $i, $c]): ?>
                <form method="post" action="<?= $hiAction ?>" class="ht-inline"><?= $hiHidden('person_access', $view, $personId) ?><input type="hidden" name="person_id" value="<?= $personId ?>"><input type="hidden" name="mode" value="<?= $m ?>"><button class="btn btn-sm <?= $c ?>"><i class="fas <?= $i ?>" aria-hidden="true"></i> <?= $t ?></button></form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="post" action="<?= $hiAction ?>" class="hr-form-grid">
            <?= $hiHidden('save_person', $view, $personId) ?>
            <input type="hidden" name="person_id" value="<?= $personId ?>">
            <div class="form-group"><label>Name</label><input name="full_name" class="form-control" maxlength="150" required value="<?= ht_e($person['full_name']) ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="form-group"><label>Phone</label><input name="phone" class="form-control" maxlength="40" value="<?= ht_e($person['phone']) ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" maxlength="190" value="<?= ht_e($person['email']) ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="form-group"><label><?= $kind === 'staff' ? 'Department / note' : 'Organisation' ?></label><input name="organisation" class="form-control" maxlength="150" value="<?= ht_e($person['organisation']) ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <?php if ($kind === 'guest'): ?>
                <div class="form-group"><label>Access until</label><input type="datetime-local" name="valid_until" class="form-control" value="<?= $person['valid_until'] ? ht_e(date('Y-m-d\TH:i', strtotime((string)$person['valid_until']))) : '' ?>" <?= $canManage ? '' : 'disabled' ?>><p class="form-hint">Empty = no expiry.</p></div>
            <?php endif; ?>
            <div class="form-group"><label>Status</label><select name="status" class="form-control" <?= $canManage ? '' : 'disabled' ?>><option<?= $person['status'] === 'Active' ? ' selected' : '' ?>>Active</option><option<?= $person['status'] === 'Suspended' ? ' selected' : '' ?>>Suspended</option></select></div>
            <div class="form-group is-wide"><label>Note</label><input name="note" class="form-control" maxlength="255" value="<?= ht_e($person['note']) ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <?php if ($canManage): ?><div class="form-group is-wide"><button class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button></div><?php endif; ?>
        </form>
        <p class="cell-muted">Added <?= ht_e(date('j M Y, H:i', strtotime((string)$person['created_at']))) ?><?= $person['source'] === 'hotspot' ? ' through the guest hotspot' : '' ?>.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-laptop" aria-hidden="true"></i> Devices (<?= count($pDevices) ?>)</h3></div>
    <div class="card-body">
        <?php if ($canManage): ?>
        <form method="post" action="<?= $hiAction ?>" style="margin-bottom:12px">
            <?= $hiHidden('add_person_device', $view, $personId) ?>
            <input type="hidden" name="person_id" value="<?= $personId ?>">
            <?= hubinet_device_rows('hiAddDevices', true, 'Add devices') ?>
            <button class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save devices</button>
        </form>
        <?php endif; ?>
        <?php if (!$pDevices): ?>
            <p class="cell-muted">No devices yet.</p>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-stack">
            <thead><tr><th>Device</th><th>Mode</th><th>pfSense</th><th>Online</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pDevices as $d): ?>
                <tr>
                    <td data-label="Device"><code><?= ht_e($d['mac_address']) ?></code><?= $d['label'] ? '<small class="cell-muted">' . ht_e($d['label']) . '</small>' : '' ?></td>
                    <td data-label="Mode"><?= ht_e(ht_mode_label((string)$d['access_mode'])) ?></td>
                    <td data-label="pfSense"><?= ht_state_badge($d) ?></td>
                    <td data-label="Online"><?= isset($onlineMacs[hubnet_normalize_mac((string)$d['mac_address'])]) ? '<span class="badge badge-success">Online</span>' : '<span class="cell-muted">-</span>' ?></td>
                    <td data-label="">
                        <div class="ht-actions">
                        <?php if ($canControl): ?>
                            <?= ht_access_form('device', (int)$d['device_id'], $d['access_mode'] === 'block' ? 'auto' : 'block', $d['access_mode'] === 'block' ? 'Reconnect' : 'Disconnect', $d['access_mode'] === 'block' ? 'fa-wifi' : 'fa-ban', $d['access_mode'] === 'block' ? 'btn-success' : 'btn-danger', 0, true) ?>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <form method="post" action="<?= $hiAction ?>" class="ht-inline" data-confirm="Remove device <?= ht_e($d['mac_address']) ?>?" data-confirm-title="Remove device" data-confirm-ok="Remove" data-confirm-danger>
                                <?= $hiHidden('remove_person_device', $view, $personId) ?><input type="hidden" name="device_id" value="<?= (int)$d['device_id'] ?>">
                                <button class="btn btn-sm btn-soft" title="Remove device"><i class="fas fa-trash" aria-hidden="true"></i></button>
                            </form>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<form method="post" action="<?= $hiAction ?>" data-confirm="Remove <?= ht_e($person['full_name']) ?> and all their devices? They lose internet access." data-confirm-title="Remove person" data-confirm-ok="Remove" data-confirm-danger>
    <?= $hiHidden('remove_person', $view) ?><input type="hidden" name="person_id" value="<?= $personId ?>">
    <button class="btn btn-danger"><i class="fas fa-user-minus" aria-hidden="true"></i> Remove <?= ht_e($person['full_name']) ?></button>
</form>
<?php endif; ?>

<?php
    else:
        $pFilters = ['q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100), 'status' => (string)($_GET['status'] ?? ''), 'source' => (string)($_GET['source'] ?? '')];
        $people = hubinet_people($conn, $kind, $pFilters);
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas <?= $kind === 'staff' ? 'fa-id-badge' : 'fa-user-clock' ?>" aria-hidden="true"></i> <?= $label ?> on the internet</h3>
        <?php if ($canManage): ?><button type="button" class="btn btn-primary btn-sm" onclick="openModal('hiAddPerson')"><i class="fas fa-plus" aria-hidden="true"></i> Add <?= $kind === 'staff' ? 'staff member' : 'guest' ?></button><?php endif; ?>
    </div>
    <div class="card-body">
        <form method="get" action="hub-team" class="filters-bar" role="search">
            <input type="hidden" name="view" value="<?= $view ?>">
            <input type="search" name="q" class="form-control filters-grow" value="<?= ht_e($pFilters['q']) ?>" placeholder="Search name, phone, email<?= $kind === 'guest' ? ', organisation' : '' ?> or MAC" aria-label="Search">
            <select name="status" class="form-control" aria-label="Status">
                <option value="">All</option>
                <?php foreach (['active' => 'Active', 'expired' => 'Expired', 'suspended' => 'Suspended'] as $k => $v): if ($kind === 'staff' && $k === 'expired') continue; ?>
                    <option value="<?= $k ?>"<?= $pFilters['status'] === $k ? ' selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($kind === 'guest'): ?>
                <select name="source" class="form-control" aria-label="Source"><option value="">Any source</option><option value="hotspot"<?= $pFilters['source'] === 'hotspot' ? ' selected' : '' ?>>Hotspot sign-ins</option></select>
            <?php endif; ?>
            <button class="btn btn-secondary"><i class="fas fa-filter" aria-hidden="true"></i> Filter</button>
        </form>

        <form method="post" action="<?= $hiAction ?>" data-bulk-scope>
            <?= $hiHidden('people_bulk', $view) ?>
            <div class="table-responsive"><table class="table table-stack hr-table">
                <thead><tr>
                    <?php if ($canManage): ?><th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all"></th><?php endif; ?>
                    <th>Name</th><th>Contact</th><th>Devices</th><th>Status</th><?php if ($kind === 'guest'): ?><th>Access until</th><?php endif; ?><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$people): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="fas fa-users" aria-hidden="true"></i><p>No <?= strtolower($label) ?> registered<?= $pFilters['q'] !== '' || $pFilters['status'] !== '' ? ' match the filter' : ' yet' ?>.</p></div></td></tr>
                <?php endif; ?>
                <?php foreach ($people as $p): ?>
                    <tr>
                        <?php if ($canManage): ?><td data-label="Select"><input type="checkbox" class="hr-sel" name="ids[]" value="<?= (int)$p['person_id'] ?>" aria-label="Select <?= ht_e($p['full_name']) ?>"></td><?php endif; ?>
                        <td data-label="Name"><a class="hr-name" href="hub-team?view=<?= $view ?>&amp;person=<?= (int)$p['person_id'] ?>"><?= ht_e($p['full_name']) ?></a>
                            <small class="cell-muted"><?= ht_e($p['organisation'] ?: ($p['user_role'] ?: '')) ?><?= $p['source'] === 'hotspot' ? ' · hotspot' : '' ?></small></td>
                        <td data-label="Contact"><?= ht_e($p['phone'] ?: '-') ?><small class="cell-muted"><?= ht_e($p['email']) ?></small></td>
                        <td data-label="Devices"><?= (int)$p['device_count'] ?><?= (int)$p['online_count'] ? ' <span class="badge badge-success">' . (int)$p['online_count'] . ' online</span>' : '' ?></td>
                        <td data-label="Status"><?= $hiPersonBadge($p) ?></td>
                        <?php if ($kind === 'guest'): ?><td data-label="Access until"><?= $p['valid_until'] ? ht_e(date('j M Y, H:i', strtotime((string)$p['valid_until']))) : '<span class="cell-muted">No expiry</span>' ?></td><?php endif; ?>
                        <td data-label=""><a class="btn btn-sm btn-soft" href="hub-team?view=<?= $view ?>&amp;person=<?= (int)$p['person_id'] ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canManage && $people): ?>
            <div class="hr-bulkbar" data-bulk-bar>
                <span data-bulk-count>None selected</span>
                <button type="submit" name="op" value="activate" class="btn btn-sm btn-secondary" data-bulk-required>Activate</button>
                <button type="submit" name="op" value="suspend" class="btn btn-sm btn-secondary" data-bulk-required data-confirm="Suspend the selected people? Their devices go offline." data-confirm-title="Suspend" data-confirm-ok="Suspend">Suspend</button>
                <?php if ($kind === 'guest'): ?>
                    <select name="hours" class="form-control" aria-label="Extend by"><option value="2">2 hours</option><option value="8" selected>8 hours</option><option value="24">1 day</option><option value="168">1 week</option><option value="720">30 days</option></select>
                    <button type="submit" name="op" value="extend" class="btn btn-sm btn-secondary" data-bulk-required>Extend access</button>
                <?php endif; ?>
                <button type="submit" name="op" value="remove" class="btn btn-sm btn-danger" data-bulk-required data-confirm="Remove the selected people and all their devices?" data-confirm-title="Remove" data-confirm-ok="Remove" data-confirm-danger>Remove</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div id="hiAddPerson" class="modal" role="dialog" aria-modal="true" aria-labelledby="hiAddTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header"><h3 id="hiAddTitle">Add <?= $kind === 'staff' ? 'staff member' : 'guest' ?></h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hiAddPerson')">&times;</button></div>
        <form method="post" action="<?= $hiAction ?>">
            <div class="modal-body">
                <?= $hiHidden('save_person', $view) ?>
                <input type="hidden" name="kind" value="<?= $kind ?>">
                <div class="hr-form-grid">
                    <?php if ($kind === 'staff'): ?>
                        <div class="form-group is-wide"><label for="hiUser">IMS user</label>
                            <select id="hiUser" name="user_id" class="form-control" data-staff-fill="hiAddPerson"><option value="0">Not an IMS user (type the details below)</option>
                                <?php foreach ($staffCandidates as $c): ?><option value="<?= (int)$c['user_id'] ?>" data-name="<?= ht_e($c['full_name']) ?>" data-email="<?= ht_e($c['email']) ?>" data-phone="<?= ht_e($c['phone']) ?>" data-org="<?= ht_e(trim(($c['job_title'] ?? '') . ($c['job_title'] && $c['department_name'] ? ', ' : '') . ($c['department_name'] ?? ''))) ?>"><?= ht_e($c['full_name']) ?> · <?= ht_e($c['role']) ?></option><?php endforeach; ?>
                            </select><p class="form-hint">Choosing a user fills in their name, email, phone and department from their profile; you can still edit them.</p></div>
                    <?php endif; ?>
                    <div class="form-group"><label for="hiName"><?= $kind === 'staff' ? 'Name' : 'Name (required)' ?></label><input id="hiName" name="full_name" class="form-control" maxlength="150" <?= $kind === 'guest' ? 'required' : '' ?>></div>
                    <div class="form-group"><label for="hiPhone">Phone</label><input id="hiPhone" name="phone" class="form-control" maxlength="40"></div>
                    <div class="form-group"><label for="hiEmail">Email</label><input id="hiEmail" type="email" name="email" class="form-control" maxlength="190"></div>
                    <div class="form-group"><label for="hiOrg"><?= $kind === 'staff' ? 'Department / note' : 'Organisation' ?></label><input id="hiOrg" name="organisation" class="form-control" maxlength="150"></div>
                    <?php if ($kind === 'guest'): ?>
                        <div class="form-group"><label for="hiUntil">Access until</label><input id="hiUntil" type="datetime-local" name="valid_until" class="form-control" value="<?= date('Y-m-d') ?>T23:59"></div>
                        <div class="form-group"><label class="hr-check" style="margin-top:28px"><input type="checkbox" name="no_expiry" value="1" onchange="document.getElementById('hiUntil').disabled=this.checked"> No expiry</label></div>
                    <?php endif; ?>
                    <div class="form-group is-wide"><?= hubinet_device_rows('hiDevices', false, 'Devices (optional)') ?></div>
                </div>
                <p class="form-hint">Find a device's MAC address under <a href="hub-team?view=online&amp;kind=unknown">Online now</a> (unknown devices) and register it from there.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('hiAddPerson')">Cancel</button><button class="btn btn-primary">Add</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
/* =====================================================================
   ONLINE NOW: devices pfSense sees, identified
===================================================================== */
elseif ($view === 'online'):
    $kindFilter = in_array($_GET['kind'] ?? '', ['member', 'staff', 'guest', 'unknown'], true) ? (string)$_GET['kind'] : '';
    $online = hubinet_online($conn, $kindFilter);
    $counts = hubinet_online_counts($conn);
    $peopleForSelect = $canManage ? array_merge(hubinet_people($conn, 'staff', ['status' => 'active']), hubinet_people($conn, 'guest', ['status' => 'active'])) : [];
    $staffCandidates = $canManage ? hubinet_staff_candidates($conn) : [];
?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-signal" aria-hidden="true"></i> Online now (<?= count($online) ?>)</h3>
        <span class="cell-muted"><?= $settings['last_checkin_at'] ? 'As of pfSense check-in at ' . ht_e(date('H:i', strtotime((string)$settings['last_checkin_at']))) : 'pfSense has not checked in yet' ?></span></div>
    <div class="card-body">
        <div class="hr-stage-pills">
            <?php foreach (['' => ['All', $counts['total']], 'member' => ['Members', $counts['member']], 'staff' => ['Staff', $counts['staff']], 'guest' => ['Guests', $counts['guest']], 'unknown' => ['Unknown', $counts['unknown']]] as $k => [$t, $n]): ?>
                <a href="hub-team?view=online<?= $k !== '' ? '&amp;kind=' . $k : '' ?>" class="<?= $kindFilter === $k ? 'active' : '' ?>"><?= $t ?> <span class="n"><?= $n ?></span></a>
            <?php endforeach; ?>
        </div>
        <?php if (!$online): ?>
            <div class="empty-state"><i class="fas fa-wifi" aria-hidden="true"></i><p>No devices reported online<?= $kindFilter !== '' ? ' in this group' : '' ?>. pfSense agent 2.1 reports connected devices at every check-in.</p></div>
        <?php else: ?>
        <form method="post" action="<?= $hiAction ?>" data-bulk-scope>
            <?= $hiHidden('devices_bulk', 'online') ?>
            <div class="table-responsive"><table class="table table-stack hr-table">
                <thead><tr><?php if ($canControl): ?><th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all registered devices"></th><?php endif; ?><th>Device</th><th>Who</th><th>Type</th><th>IP</th><th>Seen</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($online as $o): $d = $o['device']; ?>
                    <tr>
                        <?php if ($canControl): ?><td data-label="Select"><?php if ($d): ?><input type="checkbox" class="hr-sel" name="ids[]" value="<?= (int)$d['device_id'] ?>" aria-label="Select <?= ht_e($o['mac_address']) ?>"><?php endif; ?></td><?php endif; ?>
                        <td data-label="Device"><code><?= ht_e($o['mac_address']) ?></code><?= $d && $d['label'] ? '<small class="cell-muted">' . ht_e($d['label']) . '</small>' : '' ?></td>
                        <td data-label="Who">
                            <?php if ($d): ?>
                                <?php if ($d['owner_kind'] === 'member'): ?>
                                    <a href="hub-team?member_id=<?= (int)$d['member_id'] ?>"><?= ht_e($d['person_name']) ?></a><small class="cell-muted"><?= ht_e($d['company_name']) ?></small>
                                <?php else: ?>
                                    <a href="hub-team?view=<?= $d['owner_kind'] === 'staff' ? 'staff' : 'guests' ?>&amp;person=<?= (int)$d['person_id'] ?>"><?= ht_e($d['person_name']) ?></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-muted"><?= $o['portal_user'] ? 'Portal login: ' . ht_e($o['portal_user']) : 'Not registered' ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Type"><?= $hiKindBadge($o['owner_kind']) ?><?= (int)$o['in_portal'] ? ' <span class="badge badge-secondary" title="Signed in through the captive portal">Portal</span>' : '' ?><?= $d && $d['access_mode'] === 'block' ? ' <span class="badge badge-danger">Blocked</span>' : '' ?></td>
                        <td data-label="IP"><?= ht_e($o['ip_address'] ?: '-') ?></td>
                        <td data-label="Seen">since <?= ht_e(date('H:i', strtotime((string)($o['session_started'] ?: $o['first_seen'])))) ?></td>
                        <td data-label="">
                            <?php if (!$d && $canManage): ?>
                                <button type="button" class="btn btn-sm btn-primary" onclick="hiRegister(<?= ht_e(json_encode($o['mac_address'])) ?>)"><i class="fas fa-user-plus" aria-hidden="true"></i> Register</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canControl): ?>
            <div class="hr-bulkbar" data-bulk-bar>
                <span data-bulk-count>None selected</span>
                <button type="submit" name="mode" value="block" class="btn btn-sm btn-danger" data-bulk-required="Select registered devices first (unknown devices: use Register)." data-confirm="Disconnect the selected devices?" data-confirm-title="Disconnect" data-confirm-ok="Disconnect" data-confirm-danger><i class="fas fa-ban" aria-hidden="true"></i> Disconnect</button>
                <button type="submit" name="mode" value="allow" class="btn btn-sm btn-secondary" data-bulk-required>Always on</button>
                <button type="submit" name="mode" value="auto" class="btn btn-sm btn-secondary" data-bulk-required>Automatic</button>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<div id="hiRegisterModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hiRegTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header"><h3 id="hiRegTitle">Register device <code id="hiRegMac"></code></h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hiRegisterModal')">&times;</button></div>
        <form method="post" action="<?= $hiAction ?>">
            <div class="modal-body">
                <?= $hiHidden('register_online', 'online') ?>
                <input type="hidden" name="mac_address" id="hiRegMacInput">
                <fieldset class="form-group"><legend>Register as</legend>
                    <label class="hr-check" style="display:flex"><input type="radio" name="register_as" value="guest" checked> New guest</label>
                    <label class="hr-check" style="display:flex"><input type="radio" name="register_as" value="staff"> New staff member</label>
                    <label class="hr-check" style="display:flex"><input type="radio" name="register_as" value="existing"> Device of an existing staff member / guest</label>
                    <label class="hr-check" style="display:flex"><input type="radio" name="register_as" value="block"> Block this device</label>
                </fieldset>
                <div class="hr-form-grid" data-reg="guest staff">
                    <div class="form-group" data-reg="staff"><label>IMS user</label><select name="user_id" class="form-control" data-staff-fill="hiRegisterModal"><option value="0">Not an IMS user</option><?php foreach ($staffCandidates as $c): ?><option value="<?= (int)$c['user_id'] ?>" data-name="<?= ht_e($c['full_name']) ?>" data-email="<?= ht_e($c['email']) ?>" data-phone="<?= ht_e($c['phone']) ?>"><?= ht_e($c['full_name']) ?> · <?= ht_e($c['role']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Name</label><input name="full_name" class="form-control" maxlength="150"></div>
                    <div class="form-group"><label>Phone</label><input name="phone" class="form-control" maxlength="40"></div>
                    <div class="form-group" data-reg="guest"><label>Access until</label><input type="datetime-local" name="valid_until" class="form-control" value="<?= date('Y-m-d') ?>T23:59"></div>
                </div>
                <div class="form-group" data-reg="existing" hidden><label>Person</label><select name="person_id" class="form-control"><?php foreach ($peopleForSelect as $pp): ?><option value="<?= (int)$pp['person_id'] ?>"><?= ht_e($pp['full_name']) ?> (<?= ht_e($pp['kind']) ?>)</option><?php endforeach; ?></select></div>
                <div class="form-group" data-reg="guest staff existing"><label>Device label</label><input name="label" class="form-control" maxlength="80" placeholder="e.g. Phone"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('hiRegisterModal')">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('hiRegisterModal');
    function sync() {
        var as = modal.querySelector('input[name=register_as]:checked').value;
        modal.querySelectorAll('[data-reg]').forEach(function (el) { el.hidden = el.dataset.reg.split(' ').indexOf(as) === -1; });
    }
    modal.querySelectorAll('input[name=register_as]').forEach(function (r) { r.addEventListener('change', sync); });
    window.hiRegister = function (mac) {
        document.getElementById('hiRegMac').textContent = mac;
        document.getElementById('hiRegMacInput').value = mac;
        sync();
        openModal('hiRegisterModal');
    };
})();
</script>
<?php endif; ?>

<?php
/* =====================================================================
   HOTSPOT: settings, QR codes, poster, pfSense setup
===================================================================== */
elseif ($view === 'hotspot'):
    $wifiPayload = hubinet_wifi_qr_payload($settings);
    $hotspotUrl = hubinet_hotspot_url();
    $hotspotGuests = $conn->query("SELECT COUNT(*) n, SUM(valid_until >= NOW()) active, SUM(created_at >= CURDATE()) today
                                     FROM hub_network_people WHERE kind = 'guest' AND source = 'hotspot'")->fetch_assoc();
    $orgName = (string)(ims_brand()['name'] ?? 'Guest Wi-Fi');
?>
<div class="stats-grid reports-stats">
    <div class="stat-card"><div class="stat-icon <?= $settings['hotspot_enabled'] ? 'bg-green' : 'bg-red' ?>"><i class="fas fa-wifi" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Hotspot</span><span class="stat-value"><?= $settings['hotspot_enabled'] ? 'On' : 'Off' ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=guests&amp;source=hotspot&amp;status=active"><div class="stat-icon bg-amber"><i class="fas fa-user-clock" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Hotspot guests with access</span><span class="stat-value"><?= (int)$hotspotGuests['active'] ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=guests&amp;source=hotspot"><div class="stat-icon bg-blue"><i class="fas fa-right-to-bracket" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Sign-ins today</span><span class="stat-value"><?= (int)$hotspotGuests['today'] ?></span></div></div>
    <div class="stat-card" data-stat-href="hub-team?view=online&amp;kind=guest"><div class="stat-icon bg-purple"><i class="fas fa-signal" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Guest devices online</span><span class="stat-value"><?= hubinet_online_counts($conn)['guest'] ?></span></div></div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-qrcode" aria-hidden="true"></i> Join the guest Wi-Fi</h3>
        <button type="button" class="btn btn-sm btn-primary" onclick="hiPrintPoster()"><i class="fas fa-print" aria-hidden="true"></i> Print poster</button></div>
    <div class="card-body">
        <div class="hi-qr">
            <div class="hi-qr-box">
                <h4>1. Scan to join the Wi-Fi</h4>
                <?php if ($wifiPayload !== ''): ?>
                    <div class="qr" id="hiQrWifi"></div>
                    <code>Network: <?= ht_e($settings['wifi_ssid']) ?><?= $settings['wifi_security'] !== 'nopass' && $settings['wifi_key'] !== '' ? ' · Password: ' . ht_e($settings['wifi_key']) : '' ?></code>
                <?php else: ?>
                    <p class="cell-muted">Enter the Wi-Fi network name below to create this QR code.</p>
                <?php endif; ?>
            </div>
            <div class="hi-qr-box">
                <h4>2. Sign in to the hotspot</h4>
                <div class="qr" id="hiQrLink"></div>
                <code><?= ht_e($hotspotUrl) ?></code>
                <?php if ($settings['hotspot_password'] !== ''): ?><p style="margin-top:8px">Hotspot password: <strong><?= ht_e($settings['hotspot_password']) ?></strong></p><?php endif; ?>
                <p class="cell-muted">Phones usually open the sign-in page by themselves after joining the Wi-Fi.</p>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-sliders" aria-hidden="true"></i> Hotspot settings</h3></div>
    <div class="card-body">
        <form method="post" action="<?= $hiAction ?>" class="hr-form-grid">
            <?= $hiHidden('save_hotspot', 'hotspot') ?>
            <div class="form-group is-wide"><label class="hr-check"><input type="checkbox" name="hotspot_enabled" value="1"<?= $settings['hotspot_enabled'] ? ' checked' : '' ?> <?= $canSettings ? '' : 'disabled' ?>> Guest hotspot is on: unknown devices can sign in at <?= ht_e($hotspotUrl) ?></label></div>
            <div class="form-group"><label for="hiHsPass">Hotspot password</label><input id="hiHsPass" type="text" name="hotspot_password" class="form-control" autocomplete="off" placeholder="<?= $settings['hotspot_password'] !== '' ? 'Leave empty to keep the current one' : 'Empty = no password' ?>" <?= $canSettings ? '' : 'disabled' ?>>
                <?php if ($settings['hotspot_password'] !== ''): ?><label class="hr-check"><input type="checkbox" name="clear_hotspot_password" value="1"> Remove the password (anyone on the Wi-Fi can sign in)</label><?php endif; ?></div>
            <div class="form-group"><label for="hiHsHours">Access lasts (hours)</label><input id="hiHsHours" type="number" name="hotspot_hours" min="1" max="720" class="form-control" value="<?= (int)$settings['hotspot_hours'] ?>" <?= $canSettings ? '' : 'disabled' ?>></div>
            <div class="form-group is-wide"><label class="hr-check"><input type="checkbox" name="hotspot_require_phone" value="1"<?= $settings['hotspot_require_phone'] ? ' checked' : '' ?> <?= $canSettings ? '' : 'disabled' ?>> Guests must give a phone number</label></div>
            <div class="form-group is-wide"><label class="hr-check"><input type="checkbox" name="hotspot_verify" value="1"<?= $settings['hotspot_verify'] ? ' checked' : '' ?> <?= $canSettings ? '' : 'disabled' ?>> Only accept sign-ins from devices pfSense sees on the hub Wi-Fi (recommended: stops anyone outside from whitelisting a device)</label></div>
            <div class="form-group is-wide"><label for="hiLanding">After sign-in, send guests to</label><input id="hiLanding" type="url" name="hotspot_landing_url" class="form-control" maxlength="255" placeholder="<?= ht_e((string)(ims_brand()['website'] ?? 'https://www.example.org')) ?>" value="<?= ht_e($settings['hotspot_landing_url']) ?>" <?= $canSettings ? '' : 'disabled' ?>><p class="form-hint">Empty = the organisation website from System Settings<?= !empty(ims_brand()['website']) ? ' (' . ht_e((string)ims_brand()['website']) . ')' : '' ?>.</p></div>
            <div class="form-group is-wide"><label for="hiHsTerms">Terms of use (optional; guests must accept them)</label><textarea id="hiHsTerms" name="hotspot_terms" class="form-control" rows="3" <?= $canSettings ? '' : 'disabled' ?>><?= ht_e($settings['hotspot_terms']) ?></textarea></div>
            <div class="form-group"><label for="hiSsid">Wi-Fi network name (SSID)</label><input id="hiSsid" name="wifi_ssid" class="form-control" maxlength="64" value="<?= ht_e($settings['wifi_ssid']) ?>" <?= $canSettings ? '' : 'disabled' ?>></div>
            <div class="form-group"><label for="hiSec">Wi-Fi security</label><select id="hiSec" name="wifi_security" class="form-control" <?= $canSettings ? '' : 'disabled' ?>><?php foreach (['WPA' => 'WPA/WPA2/WPA3', 'WEP' => 'WEP', 'nopass' => 'Open (no Wi-Fi password)'] as $k => $v): ?><option value="<?= $k ?>"<?= $settings['wifi_security'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="hiKey">Wi-Fi password</label><input id="hiKey" type="text" name="wifi_key" class="form-control" autocomplete="off" placeholder="<?= $settings['wifi_key'] !== '' ? 'Leave empty to keep the current one' : '' ?>" <?= $canSettings ? '' : 'disabled' ?>><p class="form-hint">Only used for the QR code: set the same password on your access points.</p></div>
            <div class="form-group is-wide"><h4 style="margin:12px 0 0">Help page</h4><p class="form-hint">Shown to guests at <a href="<?= ht_e($hotspotUrl) ?>?help" target="_blank" rel="noopener"><?= ht_e($hotspotUrl) ?>?help</a> and linked from every sign-in screen. The contacts also appear on the pfSense pages below.</p></div>
            <div class="form-group"><label for="hiHelpPhone">Help phone number</label><input id="hiHelpPhone" type="tel" name="hotspot_help_phone" class="form-control" maxlength="40" placeholder="<?= ht_e((string)(ims_brand()['phone'] ?? '')) ?>" value="<?= ht_e($settings['hotspot_help_phone']) ?>" <?= $canSettings ? '' : 'disabled' ?>><p class="form-hint">Empty = the organisation phone.</p></div>
            <div class="form-group"><label for="hiHelpWa">WhatsApp number</label><input id="hiHelpWa" type="tel" name="hotspot_help_whatsapp" class="form-control" maxlength="40" placeholder="+256 700 000000" value="<?= ht_e($settings['hotspot_help_whatsapp']) ?>" <?= $canSettings ? '' : 'disabled' ?>><p class="form-hint">With the country code. Optional.</p></div>
            <div class="form-group"><label for="hiHelpEmail">Help email</label><input id="hiHelpEmail" type="email" name="hotspot_help_email" class="form-control" maxlength="190" placeholder="<?= ht_e((string)(ims_brand()['email'] ?? '')) ?>" value="<?= ht_e($settings['hotspot_help_email']) ?>" <?= $canSettings ? '' : 'disabled' ?>><p class="form-hint">Empty = the organisation email.</p></div>
            <div class="form-group is-wide"><label for="hiHelp">Help content</label><textarea id="hiHelp" name="hotspot_help" class="form-control" rows="10" placeholder="<?= ht_e(hubinet_default_help($settings)) ?>" <?= $canSettings ? '' : 'disabled' ?>><?= ht_e($settings['hotspot_help']) ?></textarea><p class="form-hint">Plain text. Start a line with <code>## </code> for a heading and <code>- </code> for a bullet; leave an empty line between paragraphs. Empty = the standard help shown as the example.</p></div>
            <?php if ($canSettings): ?><div class="form-group is-wide"><button class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save hotspot settings</button></div><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> pfSense setup (once)</h3></div>
    <div class="card-body">
        <ol class="hi-steps">
            <li>Install agent 2.1: copy <code>tools/pfsense/ims-cp-agent.php</code> to <code>/root/</code> on pfSense (keep your secret and IMS URL).</li>
            <li>Services &gt; Captive Portal &gt; your zone: enable it, enable <strong>MAC filtering</strong>.</li>
            <li><strong>Portal page contents</strong>: upload the <em>sign-in page</em> below. It is branded, sends guests to this sign-in page, and shows a "No internet connection" page when the internet line is down.</li>
            <li><strong>Auth error page contents</strong>: upload the <em>sign-in problem page</em> below.</li>
            <li>Allowed Hostnames: add <code><?= ht_e((string)parse_url($hotspotUrl, PHP_URL_HOST)) ?></code> so guests can reach this sign-in page before they are online.</li>
            <li>Use <strong>Overview &gt; Internet control</strong> to keep the portal on, or switch it off for open internet.</li>
        </ol>
        <?php if ($canSettings): ?>
        <p class="form-hint" style="margin-top:12px">Download again and re-upload after changing the logo, brand colours or help contacts: these pages are stored on pfSense.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" action="<?= $hiAction ?>"><?= $hiHidden('download_pfsense_page', 'hotspot') ?><input type="hidden" name="page" value="portal"><button class="btn btn-primary"><i class="fas fa-download" aria-hidden="true"></i> Sign-in page (portal page)</button></form>
            <form method="post" action="<?= $hiAction ?>"><?= $hiHidden('download_pfsense_page', 'hotspot') ?><input type="hidden" name="page" value="error"><button class="btn btn-soft"><i class="fas fa-download" aria-hidden="true"></i> Sign-in problem page (auth error page)</button></form>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="hi-poster" id="hiPoster" aria-hidden="true">
    <h1>Free guest Wi-Fi</h1>
    <p><?= ht_e($orgName) ?></p>
    <div class="row">
        <?php if ($wifiPayload !== ''): ?><div><div class="qr" id="hiPosterWifi"></div><p>1. Scan to join <strong><?= ht_e($settings['wifi_ssid']) ?></strong></p></div><?php endif; ?>
        <div><div class="qr" id="hiPosterLink"></div><p><?= $wifiPayload !== '' ? '2. ' : '' ?>Sign in at the page that opens</p></div>
    </div>
    <?php if ($settings['hotspot_password'] !== ''): ?><p class="pw">Hotspot password: <?= ht_e($settings['hotspot_password']) ?></p><?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    var wifi = <?= json_encode($wifiPayload) ?>, link = <?= json_encode($hotspotUrl) ?>;
    function qr(id, text, size) {
        var el = document.getElementById(id);
        if (!el || !text || !window.QRCode) return;
        new QRCode(el, { text: text, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
    }
    qr('hiQrWifi', wifi, 200); qr('hiQrLink', link, 200);
    qr('hiPosterWifi', wifi, 280); qr('hiPosterLink', link, 280);
    window.hiPrintPoster = function () {
        var poster = document.getElementById('hiPoster');
        var host = document.createElement('div');
        host.className = 'hi-poster-host';
        host.appendChild(poster.cloneNode(true));
        document.body.appendChild(host);
        document.body.classList.add('hi-printing');
        var done = function () { document.body.classList.remove('hi-printing'); host.remove(); window.removeEventListener('afterprint', done); };
        window.addEventListener('afterprint', done);
        setTimeout(function () { window.print(); }, 150);
    };
})();
</script>
<?php endif; ?>

<script>
/* Staff: choosing an IMS user fills in their details (name, email, phone, department).
   A field the person typed into is left alone; fields filled by an earlier choice are replaced. */
document.querySelectorAll('select[data-staff-fill]').forEach(function (select) {
    var form = select.form;
    if (!form) return;
    var map = { name: 'full_name', email: 'email', phone: 'phone', org: 'organisation' };
    Object.keys(map).forEach(function (key) {
        var field = form.querySelector('[name="' + map[key] + '"]');
        if (field) field.addEventListener('input', function () { delete field.dataset.autofilled; });
    });
    select.addEventListener('change', function () {
        var opt = select.selectedOptions[0];
        Object.keys(map).forEach(function (key) {
            var field = form.querySelector('[name="' + map[key] + '"]');
            if (!field) return;
            var value = opt && opt.value !== '0' ? (opt.getAttribute('data-' + key) || '') : '';
            if (field.value === '' || field.dataset.autofilled === '1') {
                field.value = value;
                if (value !== '') field.dataset.autofilled = '1'; else delete field.dataset.autofilled;
            }
        });
    });
});
</script>
