<?php
declare(strict_types=1);

/*
| Hub Team & Internet
|   /hub-team                    internet dashboard: every registered person and device
|   /hub-team?member_id=N        one company: seats, team members, devices
|   /hub-team?view=settings      workspace packages (seat limits) and pfSense connection
*/

$page_title = 'Hub Team & Internet';
include 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hub-network.php';
check_role(IMS_ALL_ROLES);

function ht_e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$canView = ims_allowed('hub_team.view', ['Administrator', 'Operations/Admin', 'IT Officer'], 'View hub team members and internet access');
$canManage = ims_allowed('hub_team.manage', ['Administrator', 'Operations/Admin'], 'Register and edit hub team members and their devices');
$canControl = ims_allowed('hub_network.control', ['Administrator', 'Operations/Admin', 'IT Officer'], 'Connect and disconnect hub members from the internet (pfSense)');
$canSettings = ims_allowed('hub_network.settings', ['Administrator', 'IT Officer'], 'Configure hub workspace packages and the pfSense connection');

$successMessage = (string)($_SESSION['success'] ?? '');
$errorMessage = (string)($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

$ready = isset($conn) && $conn instanceof mysqli && hubnet_ensure_schema($conn);
$view = (string)($_GET['view'] ?? '');
$memberId = (int)($_GET['member_id'] ?? 0);

if ($view === 'settings' && !$canSettings) {
    $view = '';
}

$settings = $ready ? hubnet_settings($conn) : null;

/** Small helpers for the access buttons. */
function ht_state_badge(array $device): string
{
    $map = [
        'connected' => ['success', 'Connected'],
        'disconnected' => ['secondary', 'Disconnected'],
        'blocked' => ['danger', 'Blocked'],
        'error' => ['warning', 'Sync error'],
        'unknown' => ['info', 'Not synced'],
    ];
    [$class, $label] = $map[$device['pf_state'] ?? 'unknown'] ?? $map['unknown'];
    $title = ($device['pf_state'] ?? '') === 'error' ? (string)($device['pf_last_error'] ?? '') : (string)($device['pf_synced_at'] ?? '');

    return '<span class="badge badge-' . $class . '" title="' . ht_e($title) . '">' . $label . '</span>';
}

function ht_mode_label(string $mode): string
{
    return ['auto' => 'Automatic', 'allow' => 'Always on', 'block' => 'Disconnected'][$mode] ?? 'Automatic';
}

function ht_access_form(string $scope, int $targetId, string $mode, string $label, string $icon, string $class, int $memberId, bool $toDashboard = false): string
{
    return '<form method="post" action="includes/hub-team-process.php" class="ht-inline">'
        . csrf_field()
        . '<input type="hidden" name="action" value="set_access">'
        . '<input type="hidden" name="scope" value="' . ht_e($scope) . '">'
        . '<input type="hidden" name="target_id" value="' . $targetId . '">'
        . '<input type="hidden" name="member_id" value="' . $memberId . '">'
        . '<input type="hidden" name="mode" value="' . ht_e($mode) . '">'
        . ($toDashboard ? '<input type="hidden" name="return" value="dashboard">' : '')
        . '<button type="submit" class="btn btn-sm ' . $class . '" title="' . ht_e($label) . '"><i class="fas ' . $icon . '" aria-hidden="true"></i> ' . ht_e($label) . '</button>'
        . '</form>';
}
?>

<style>
.ht-inline { display: inline; margin: 0; }
.ht-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.ht-devices { display: grid; gap: 6px; }
.ht-device { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; }
.ht-device code { font-size: 12px; }
.ht-seat-meter { display: flex; align-items: center; gap: 10px; }
.ht-seat-meter .meter { flex: 1; min-width: 120px; }
.ht-over { color: var(--red-fg); font-weight: 600; }
.ht-tabs { display: flex; flex-wrap: wrap; gap: 8px; }
.ht-settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr)); gap: var(--space-5, 20px); align-items: start; }
.ht-package-row input.form-control { min-width: 0; }
.ht-help { font-size: 12px; color: var(--ink-400); margin-top: 4px; }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-wifi" aria-hidden="true"></i> Hub Team &amp; Internet</h1>
        <p>Register each company's team within its workspace package and control their internet access on pfSense.</p>
    </div>
    <div class="hero-actions">
        <a href="hub-operations?tab=members" class="btn"><i class="fas fa-building" aria-hidden="true"></i> Hub Operations</a>
        <?php if ($canControl && $ready): ?>
        <form method="post" action="includes/hub-team-process.php" class="ht-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_now">
            <input type="hidden" name="member_id" value="<?= $memberId ?>">
            <button type="submit" class="btn"><i class="fas fa-rotate" aria-hidden="true"></i> Sync pfSense now</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($successMessage !== ''): ?>
<div class="alert alert-success"><i class="fas fa-check-circle" aria-hidden="true"></i><?= ht_e($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?= ht_e($errorMessage) ?></div>
<?php endif; ?>

<?php if (!$canView): ?>
    <div class="card"><div class="card-body"><div class="empty-state"><i class="fas fa-lock" aria-hidden="true"></i><p>You do not have access to hub team members.</p></div></div></div>
<?php elseif (!$ready): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle" aria-hidden="true"></i>Hub team tables could not be created. Apply <code>database/migrations/2026_11_19_hub_teams_network.sql</code>.</div>
<?php else: ?>

<?php if (!$settings['enabled']): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
    pfSense control is off: people and devices are registered, but nothing is sent to the firewall.
    <?= $canSettings ? '<a href="hub-team?view=settings">Set up the pfSense connection</a>.' : 'Ask an administrator to set up the pfSense connection.' ?>
</div>
<?php endif; ?>

<div class="ht-tabs">
    <a href="hub-team" class="btn <?= $view === '' && $memberId === 0 ? 'btn-primary' : 'btn-soft' ?>"><i class="fas fa-network-wired" aria-hidden="true"></i> Internet dashboard</a>
    <?php if ($canSettings): ?>
    <a href="hub-team?view=settings" class="btn <?= $view === 'settings' ? 'btn-primary' : 'btn-soft' ?>"><i class="fas fa-sliders" aria-hidden="true"></i> Packages &amp; pfSense</a>
    <?php endif; ?>
</div>

<?php
/* =====================================================================
   SETTINGS: packages and pfSense
===================================================================== */
if ($view === 'settings'):
    $packages = hubnet_packages($conn);
    $usage = [];
    $usageResult = $conn->query('SELECT space_package_id, COUNT(*) AS c FROM members WHERE space_package_id IS NOT NULL GROUP BY space_package_id');
    while ($usageResult && ($u = $usageResult->fetch_assoc())) {
        $usage[(int)$u['space_package_id']] = (int)$u['c'];
    }
?>
<div class="ht-settings-grid">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chair" aria-hidden="true"></i> Workspace packages</h3></div>
        <div class="card-body">
            <p class="ht-help">Maximum seats includes the primary member. Changing a maximum does not remove anyone; companies above the new limit are flagged and cannot add more people.</p>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Package</th><th style="width:90px">Max seats</th><th style="width:70px">Order</th><th style="width:70px">Active</th><th>Members</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($packages as $package): $formId = 'pkg' . (int)$package['package_id']; ?>
                        <tr class="ht-package-row">
                            <td>
                                <input form="<?= $formId ?>" class="form-control" name="name" value="<?= ht_e($package['name']) ?>" required aria-label="Package name">
                                <input form="<?= $formId ?>" class="form-control" name="description" value="<?= ht_e($package['description']) ?>" placeholder="Description" aria-label="Description" style="margin-top:6px">
                            </td>
                            <td><input form="<?= $formId ?>" class="form-control" type="number" min="1" max="500" name="max_seats" value="<?= (int)$package['max_seats'] ?>" required aria-label="Maximum seats"></td>
                            <td><input form="<?= $formId ?>" class="form-control" type="number" name="sort_order" value="<?= (int)$package['sort_order'] ?>" aria-label="Sort order"></td>
                            <td><input form="<?= $formId ?>" type="checkbox" name="is_active" value="1" <?= !empty($package['is_active']) ? 'checked' : '' ?> aria-label="Active"></td>
                            <td><?= $usage[(int)$package['package_id']] ?? 0 ?></td>
                            <td>
                                <form id="<?= $formId ?>" method="post" action="includes/hub-team-process.php" class="ht-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="save_package">
                                    <input type="hidden" name="package_id" value="<?= (int)$package['package_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h4 style="margin-top:18px">Add a package</h4>
            <form method="post" action="includes/hub-team-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_package">
                <input type="hidden" name="is_active" value="1">
                <div class="form-row">
                    <div class="form-group"><label for="new_pkg_name" class="required">Name</label><input id="new_pkg_name" class="form-control" name="name" required placeholder="e.g. Dedicated table for 8"></div>
                    <div class="form-group"><label for="new_pkg_seats" class="required">Max seats</label><input id="new_pkg_seats" class="form-control" type="number" min="1" max="500" name="max_seats" value="1" required></div>
                    <div class="form-group"><label for="new_pkg_order">Order</label><input id="new_pkg_order" class="form-control" type="number" name="sort_order" value="60"></div>
                </div>
                <div class="form-group"><label for="new_pkg_desc">Description</label><input id="new_pkg_desc" class="form-control" name="description"></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add package</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-shield-halved" aria-hidden="true"></i> pfSense connection</h3></div>
        <div class="card-body">
            <p class="ht-help">
                Install <code>tools/pfsense/ims-cp-agent.php</code> on the firewall (see the instructions at the top of that file),
                set the same shared secret there and here, then test the connection.
            </p>
            <form method="post" action="includes/hub-team-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_network_settings">
                <div class="form-group">
                    <label for="agent_url">Agent URL</label>
                    <input id="agent_url" class="form-control" name="agent_url" value="<?= ht_e($settings['agent_url']) ?>" placeholder="https://192.168.1.1/ims-cp-agent.php">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="agent_secret">Shared secret</label>
                        <input id="agent_secret" class="form-control" type="password" name="agent_secret" autocomplete="new-password"
                               placeholder="<?= $settings['has_secret'] ? 'Saved - leave blank to keep' : 'At least 24 characters' ?>">
                    </div>
                    <div class="form-group">
                        <label for="cp_zone">Captive portal zone</label>
                        <input id="cp_zone" class="form-control" name="cp_zone" value="<?= ht_e($settings['cp_zone']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>> Send connect / disconnect changes to pfSense</label>
                    <label><input type="checkbox" name="auto_enforce" value="1" <?= $settings['auto_enforce'] ? 'checked' : '' ?>> Automatic: disconnect when a membership expires or is suspended, reconnect when it is active again (runs from the scheduled task)</label>
                    <label><input type="checkbox" name="verify_tls" value="1" <?= $settings['verify_tls'] ? 'checked' : '' ?>> Verify the firewall's HTTPS certificate (leave off for pfSense's self-signed certificate)</label>
                </div>
                <div class="ht-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save settings</button>
                </div>
            </form>
            <form method="post" action="includes/hub-team-process.php" style="margin-top:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test_connection">
                <button type="submit" class="btn btn-soft"><i class="fas fa-plug" aria-hidden="true"></i> Test connection</button>
            </form>
            <p class="ht-help">Scheduled task (every 5 minutes): <code>*/5 * * * * php /path/to/legacy/cron/hub-internet-sync.php</code></p>
        </div>
    </div>
</div>

<?php
/* =====================================================================
   COMPANY VIEW: seats, team, devices
===================================================================== */
elseif ($memberId > 0):
    $member = hubnet_member($conn, $memberId);

    if (!$member):
?>
    <div class="card"><div class="card-body"><div class="empty-state"><i class="fas fa-building" aria-hidden="true"></i><p>Member not found.</p></div></div></div>
<?php
    else:
        $teamStmt = $conn->prepare('SELECT * FROM hub_team_members WHERE member_id = ? ORDER BY status, full_name');
        $teamStmt->bind_param('i', $memberId);
        $teamStmt->execute();
        $team = $teamStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $teamStmt->close();

        $devicesByPerson = [];
        foreach (hubnet_devices_where($conn, 'd.member_id = ?', 'i', [$memberId]) as $device) {
            $devicesByPerson[(int)($device['team_member_id'] ?? 0)][] = $device;
        }

        $people = array_merge(
            [[
                'team_member_id' => 0,
                'full_name' => $member['full_name'],
                'email' => $member['email'],
                'phone' => '',
                'job_title' => 'Primary member',
                'status' => 'Active',
                'is_primary' => true,
            ]],
            $team
        );

        $seatsMax = $member['seats_max'];
        $seatsUsed = (int)$member['seats_used'];
        $over = $seatsMax !== null && $seatsUsed > $seatsMax;
        $percent = $seatsMax ? min(100, (int)round($seatsUsed / $seatsMax * 100)) : 0;
?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-building" aria-hidden="true"></i> <?= ht_e($member['company_name'] ?: $member['full_name']) ?></h3></div>
    <div class="card-body">
        <div class="detail-grid">
            <div><div class="detail-label">Membership</div><div class="detail-value"><code><?= ht_e($member['membership_number']) ?></code> &middot; <?= ht_e($member['membership_status']) ?><?= $member['subscription_end_date'] ? ' until ' . ht_e(date('d M Y', strtotime((string)$member['subscription_end_date']))) : '' ?></div></div>
            <div><div class="detail-label">Workspace package</div><div class="detail-value"><?= $member['package_name'] ? ht_e($member['package_name']) : '<span class="ht-over">Not set</span> - choose one in Hub Operations &gt; Edit member' ?></div></div>
            <div>
                <div class="detail-label">Seats</div>
                <div class="detail-value ht-seat-meter">
                    <span class="<?= $over ? 'ht-over' : '' ?>"><?= $seatsUsed ?> / <?= $seatsMax ?? '-' ?></span>
                    <?php if ($seatsMax): ?><div class="meter" aria-hidden="true"><div class="meter-fill <?= $over ? 'tone-red' : ($percent >= 100 ? 'tone-amber' : 'tone-green') ?>" style="width:<?= $percent ?>%"></div></div><?php endif; ?>
                </div>
                <?php if ($over): ?><div class="ht-help ht-over">Over the package limit: suspend someone or upgrade the package.</div><?php endif; ?>
            </div>
            <div><div class="detail-label">Internet (automatic rule)</div><div class="detail-value"><?= $member['membership_live'] ? '<span class="badge badge-success">Membership live - online</span>' : '<span class="badge badge-secondary">Membership not active - offline</span>' ?></div></div>
        </div>

        <div class="ht-actions" style="margin-top:14px">
            <?php if ($canManage && $seatsMax !== null): ?>
                <button type="button" class="btn btn-primary" onclick="openModal('addTeamModal')" <?= hubnet_seat_available($member) ? '' : 'disabled title="No free seats"' ?>>
                    <i class="fas fa-user-plus" aria-hidden="true"></i> Add team member
                </button>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <button type="button" class="btn" onclick="openModal('addDeviceModal')"><i class="fas fa-laptop" aria-hidden="true"></i> Add device</button>
            <?php endif; ?>
            <?php if ($canControl): ?>
                <?= ht_access_form('member', $memberId, 'block', 'Disconnect whole company', 'fa-ban', 'btn-danger', $memberId) ?>
                <?= ht_access_form('member', $memberId, 'auto', 'Restore automatic', 'fa-rotate-left', 'btn-soft', $memberId) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-users" aria-hidden="true"></i> Team (<?= count($people) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Person</th><th>Status</th><th>Devices &amp; internet</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($people as $person):
                    $personId = (int)$person['team_member_id'];
                    $isPrimary = !empty($person['is_primary']);
                    $devices = $devicesByPerson[$personId] ?? [];
                    $scope = $isPrimary ? 'primary' : 'team_member';
                    $scopeId = $isPrimary ? $memberId : $personId;
                ?>
                    <tr>
                        <td>
                            <strong><?= ht_e($person['full_name']) ?></strong>
                            <small class="cell-muted"><?= ht_e($person['job_title'] ?? '') ?><?= !empty($person['email']) ? ' &middot; ' . ht_e($person['email']) : '' ?><?= !empty($person['phone']) ? ' &middot; ' . ht_e($person['phone']) : '' ?></small>
                        </td>
                        <td><span class="badge badge-<?= $person['status'] === 'Active' ? 'success' : 'warning' ?>"><?= ht_e($person['status']) ?></span></td>
                        <td>
                            <?php if ($devices === []): ?>
                                <span class="cell-muted">No devices registered</span>
                            <?php else: ?>
                            <div class="ht-devices">
                                <?php foreach ($devices as $device): ?>
                                <div class="ht-device">
                                    <code><?= ht_e($device['mac_address']) ?></code>
                                    <?= !empty($device['label']) ? '<span>' . ht_e($device['label']) . '</span>' : '' ?>
                                    <?= ht_state_badge($device) ?>
                                    <span class="cell-muted"><?= ht_e(ht_mode_label((string)$device['access_mode'])) ?></span>
                                    <?php if ($canControl): ?>
                                        <?php if ($device['access_mode'] !== 'block'): ?>
                                            <?= ht_access_form('device', (int)$device['device_id'], 'block', 'Disconnect', 'fa-ban', 'btn-danger', $memberId) ?>
                                        <?php endif; ?>
                                        <?php if ($device['access_mode'] !== 'auto'): ?>
                                            <?= ht_access_form('device', (int)$device['device_id'], 'auto', 'Automatic', 'fa-rotate-left', 'btn-soft', $memberId) ?>
                                        <?php endif; ?>
                                        <?php if ($device['access_mode'] !== 'allow'): ?>
                                            <?= ht_access_form('device', (int)$device['device_id'], 'allow', 'Always on', 'fa-check', 'btn-soft', $memberId) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($canManage): ?>
                                    <form method="post" action="includes/hub-team-process.php" class="ht-inline" onsubmit="return confirm('Remove device <?= ht_e($device['mac_address']) ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_device">
                                        <input type="hidden" name="device_id" value="<?= (int)$device['device_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-soft" title="Remove device"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="ht-actions">
                                <?php if ($canControl && $devices !== []): ?>
                                    <?= ht_access_form($scope, $scopeId, 'block', 'Disconnect', 'fa-ban', 'btn-danger', $memberId) ?>
                                    <?= ht_access_form($scope, $scopeId, 'auto', 'Reconnect', 'fa-wifi', 'btn-success', $memberId) ?>
                                <?php endif; ?>
                                <?php if ($canManage && !$isPrimary): ?>
                                    <button type="button" class="btn btn-sm btn-warning" title="Edit"
                                            onclick='openEditTeam(<?= json_encode([
                                                'id' => $personId,
                                                'full_name' => $person['full_name'],
                                                'email' => $person['email'] ?? '',
                                                'phone' => $person['phone'] ?? '',
                                                'job_title' => $person['job_title'] ?? '',
                                                'status' => $person['status'],
                                            ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                        <i class="fas fa-edit" aria-hidden="true"></i>
                                    </button>
                                    <form method="post" action="includes/hub-team-process.php" class="ht-inline" onsubmit="return confirm('Remove <?= ht_e(addslashes((string)$person['full_name'])) ?> from the team? Their devices are disconnected and the seat is freed.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_team_member">
                                        <input type="hidden" name="team_member_id" value="<?= $personId ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Remove from team"><i class="fas fa-user-minus" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Add team member -->
<div id="addTeamModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add team member</h3>
            <span class="close" onclick="closeModal('addTeamModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="post" action="includes/hub-team-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_team_member">
                <input type="hidden" name="member_id" value="<?= $memberId ?>">
                <p class="ht-help">Seat <?= $seatsUsed + 1 ?> of <?= (int)$seatsMax ?> (<?= ht_e($member['package_name']) ?>).</p>
                <div class="form-row">
                    <div class="form-group"><label for="tm_name" class="required">Full name</label><input id="tm_name" class="form-control" name="full_name" required maxlength="150"></div>
                    <div class="form-group"><label for="tm_title">Role / job title</label><input id="tm_title" class="form-control" name="job_title" maxlength="120"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="tm_email">Email</label><input id="tm_email" type="email" class="form-control" name="email"></div>
                    <div class="form-group"><label for="tm_phone">Phone</label><input id="tm_phone" class="form-control" name="phone" maxlength="40"></div>
                </div>
                <div class="form-group">
                    <label for="tm_macs">Device MAC addresses</label>
                    <textarea id="tm_macs" class="form-control" name="macs" rows="3" placeholder="One per line, e.g. a4:5e:60:12:ab:cd"></textarea>
                    <div class="ht-help">Laptop and phone Wi-Fi addresses. Ask them to turn off "private / random MAC address" for the hub Wi-Fi so the address stays the same.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addTeamModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus" aria-hidden="true"></i> Add team member</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit team member -->
<div id="editTeamModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit team member</h3>
            <span class="close" onclick="closeModal('editTeamModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="post" action="includes/hub-team-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_team_member">
                <input type="hidden" name="member_id" value="<?= $memberId ?>">
                <input type="hidden" name="team_member_id" id="et_id">
                <div class="form-row">
                    <div class="form-group"><label for="et_name" class="required">Full name</label><input id="et_name" class="form-control" name="full_name" required maxlength="150"></div>
                    <div class="form-group"><label for="et_title">Role / job title</label><input id="et_title" class="form-control" name="job_title" maxlength="120"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="et_email">Email</label><input id="et_email" type="email" class="form-control" name="email"></div>
                    <div class="form-group"><label for="et_phone">Phone</label><input id="et_phone" class="form-control" name="phone" maxlength="40"></div>
                </div>
                <div class="form-group">
                    <label for="et_status">Status</label>
                    <select id="et_status" class="form-control" name="status">
                        <option value="Active">Active (uses a seat, online when the membership is live)</option>
                        <option value="Suspended">Suspended (frees the seat, disconnected)</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editTeamModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add device -->
<div id="addDeviceModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add device</h3>
            <span class="close" onclick="closeModal('addDeviceModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="post" action="includes/hub-team-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_device">
                <input type="hidden" name="member_id" value="<?= $memberId ?>">
                <div class="form-group">
                    <label for="dv_person" class="required">Person</label>
                    <select id="dv_person" class="form-control" name="team_member_id">
                        <?php foreach ($people as $person): ?>
                            <option value="<?= (int)$person['team_member_id'] ?>"><?= ht_e($person['full_name']) ?><?= !empty($person['is_primary']) ? ' (primary member)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="dv_mac" class="required">MAC address</label><input id="dv_mac" class="form-control" name="mac_address" required placeholder="a4:5e:60:12:ab:cd"></div>
                    <div class="form-group"><label for="dv_label">Label</label><input id="dv_label" class="form-control" name="label" maxlength="80" placeholder="Laptop, phone..."></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addDeviceModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add device</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditTeam(person) {
    document.getElementById('et_id').value = person.id;
    document.getElementById('et_name').value = person.full_name || '';
    document.getElementById('et_title').value = person.job_title || '';
    document.getElementById('et_email').value = person.email || '';
    document.getElementById('et_phone').value = person.phone || '';
    document.getElementById('et_status').value = person.status || 'Active';
    openModal('editTeamModal');
}
</script>
<?php endif; ?>

<?php
    endif;

/* =====================================================================
   INTERNET DASHBOARD: everyone, across companies
===================================================================== */
else:
    $search = trim((string)($_GET['q'] ?? ''));
    $stateFilter = (string)($_GET['state'] ?? '');
    $where = '1=1';
    $types = '';
    $params = [];

    if ($search !== '') {
        $where .= ' AND (m.company_name LIKE ? OR m.membership_number LIKE ? OR t.full_name LIKE ? OR u.full_name LIKE ? OR d.mac_address LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    if (in_array($stateFilter, ['connected', 'disconnected', 'blocked', 'error', 'unknown'], true)) {
        $where .= ' AND d.pf_state = ?';
        $types .= 's';
        $params[] = $stateFilter;
    }

    $devices = hubnet_devices_where($conn, $where, $types, $params);

    $counts = ['people' => 0, 'devices' => 0, 'connected' => 0, 'offline' => 0];
    $countRow = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM members WHERE membership_status = 'Active') +
            (SELECT COUNT(*) FROM hub_team_members t JOIN members m ON m.member_id = t.member_id
              WHERE t.status = 'Active' AND m.membership_status = 'Active') AS people,
            (SELECT COUNT(*) FROM hub_network_devices) AS devices,
            (SELECT COUNT(*) FROM hub_network_devices WHERE pf_state = 'connected') AS connected,
            (SELECT COUNT(*) FROM hub_network_devices WHERE pf_state IN ('disconnected','blocked')) AS offline"
    );
    if ($countRow) {
        $counts = array_map('intval', $countRow->fetch_assoc() ?: $counts);
    }

    $companies = $conn->query(
        "SELECT m.member_id, m.company_name, m.membership_number, m.membership_status, u.full_name,
                p.name AS package_name, p.max_seats,
                1 + (SELECT COUNT(*) FROM hub_team_members t WHERE t.member_id = m.member_id AND t.status = 'Active') AS seats_used
         FROM members m
         LEFT JOIN users u ON u.user_id = m.user_id
         LEFT JOIN hub_space_packages p ON p.package_id = m.space_package_id
         WHERE m.membership_status IN ('Active', 'Pending', 'Suspended')
         ORDER BY COALESCE(NULLIF(m.company_name, ''), u.full_name)"
    );
    $companyRows = $companies ? $companies->fetch_all(MYSQLI_ASSOC) : [];
?>
<div class="stats-grid reports-stats">
    <div class="stat-card"><div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Registered people (active)</span><span class="stat-value"><?= $counts['people'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-laptop" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Devices</span><span class="stat-value"><?= $counts['devices'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-wifi" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Connected</span><span class="stat-value"><?= $counts['connected'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-red"><i class="fas fa-ban" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Disconnected / blocked</span><span class="stat-value"><?= $counts['offline'] ?></span></div></div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-building" aria-hidden="true"></i> Companies &amp; seats</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Company / member</th><th>Package</th><th>Seats</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if ($companyRows === []): ?>
                    <tr><td colspan="5"><div class="empty-state"><i class="fas fa-building" aria-hidden="true"></i><p>No active members yet.</p></div></td></tr>
                <?php endif; ?>
                <?php foreach ($companyRows as $company):
                    $max = $company['max_seats'] !== null ? (int)$company['max_seats'] : null;
                    $used = (int)$company['seats_used'];
                ?>
                    <tr>
                        <td><strong><?= ht_e($company['company_name'] ?: $company['full_name']) ?></strong><small class="cell-muted"><?= ht_e($company['membership_number']) ?><?= $company['company_name'] ? ' &middot; ' . ht_e($company['full_name']) : '' ?></small></td>
                        <td><?= $company['package_name'] ? ht_e($company['package_name']) : '<span class="cell-muted">Not set</span>' ?></td>
                        <td class="<?= $max !== null && $used > $max ? 'ht-over' : '' ?>"><?= $used ?> / <?= $max ?? '-' ?></td>
                        <td><?= ht_e($company['membership_status']) ?></td>
                        <td><a class="btn btn-sm btn-primary" href="hub-team?member_id=<?= (int)$company['member_id'] ?>"><i class="fas fa-users" aria-hidden="true"></i> Team</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-network-wired" aria-hidden="true"></i> Devices &amp; internet access</h3></div>
    <div class="card-body">
        <form method="get" action="hub-team" class="filters-bar" role="search">
            <input type="text" name="q" class="form-control filters-grow" value="<?= ht_e($search) ?>" placeholder="Search company, person, membership # or MAC...">
            <select name="state" class="form-control" aria-label="Internet state">
                <option value="">All states</option>
                <?php foreach (['connected' => 'Connected', 'disconnected' => 'Disconnected', 'blocked' => 'Blocked', 'error' => 'Sync error', 'unknown' => 'Not synced'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $stateFilter === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary"><i class="fas fa-search" aria-hidden="true"></i> Filter</button>
        </form>

        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Person</th><th>Company</th><th>Device</th><th>Mode</th><th>pfSense</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($devices === []): ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="fas fa-laptop" aria-hidden="true"></i><p>No devices registered<?= $search !== '' || $stateFilter !== '' ? ' match the filter' : ' yet. Open a company\'s Team page to add people and devices' ?>.</p></div></td></tr>
                <?php endif; ?>
                <?php foreach ($devices as $device): $expected = hubnet_state_for_mode(hubnet_desired_mode($device)); ?>
                    <tr>
                        <td><strong><?= ht_e($device['person_name']) ?></strong><?= empty($device['team_member_id']) ? '<small class="cell-muted">Primary member</small>' : (($device['team_status'] ?? '') !== 'Active' ? '<small class="cell-muted">Suspended</small>' : '') ?></td>
                        <td><a href="hub-team?member_id=<?= (int)$device['member_id'] ?>"><?= ht_e($device['company_name'] ?: $device['primary_name']) ?></a><small class="cell-muted"><?= ht_e($device['membership_status']) ?></small></td>
                        <td><code><?= ht_e($device['mac_address']) ?></code><?= !empty($device['label']) ? '<small class="cell-muted">' . ht_e($device['label']) . '</small>' : '' ?></td>
                        <td><?= ht_e(ht_mode_label((string)$device['access_mode'])) ?></td>
                        <td><?= ht_state_badge($device) ?><?= $device['pf_state'] !== $expected ? '<small class="cell-muted">Should be ' . ht_e($expected) . '</small>' : '' ?></td>
                        <td>
                            <div class="ht-actions">
                            <?php if ($canControl): ?>
                                <?php if ($device['access_mode'] !== 'block'): ?>
                                    <?= ht_access_form('device', (int)$device['device_id'], 'block', 'Disconnect', 'fa-ban', 'btn-danger', (int)$device['member_id'], true) ?>
                                <?php else: ?>
                                    <?= ht_access_form('device', (int)$device['device_id'], 'auto', 'Reconnect', 'fa-wifi', 'btn-success', (int)$device['member_id'], true) ?>
                                <?php endif; ?>
                                <?php if ($device['access_mode'] !== 'allow'): ?>
                                    <?= ht_access_form('device', (int)$device['device_id'], 'allow', 'Always on', 'fa-check', 'btn-soft', (int)$device['member_id'], true) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>
</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>
