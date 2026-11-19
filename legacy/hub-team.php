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
$canRemind = ims_allowed('hub_reminders.send', ['Administrator', 'Operations/Admin'], 'Send subscription reminders to hub members');
$canReminderSettings = ims_allowed('hub_reminders.settings', ['Administrator', 'Operations/Admin'], 'Configure automatic hub member reminders');
$canOpenSettings = $canSettings || $canRemind || $canReminderSettings;

$successMessage = (string)($_SESSION['success'] ?? '');
$errorMessage = (string)($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

require_once __DIR__ . '/includes/hub-reminders.php';

$ready = isset($conn) && $conn instanceof mysqli && hubnet_ensure_schema($conn) && hubrem_ensure_schema($conn);
$view = (string)($_GET['view'] ?? '');
$memberId = (int)($_GET['member_id'] ?? 0);

if ($view === 'settings' && !$canOpenSettings) {
    $view = '';
}

// Settings tabs: Packages and pfSense (IT/admin), Reminders (Operations/admin).
$settingsTabs = array_filter([
    'packages' => $canSettings ? ['fa-chair', 'Packages'] : null,
    'pfsense' => $canSettings ? ['fa-shield-halved', 'pfSense'] : null,
    'reminders' => ($canRemind || $canReminderSettings) ? ['fa-bell', 'Reminders'] : null,
]);
$activeSettingsTab = (string)($_GET['tab'] ?? '');
if (!isset($settingsTabs[$activeSettingsTab])) {
    $activeSettingsTab = (string)array_key_first($settingsTabs);
}

$settings = $ready ? hubnet_settings($conn) : null;
$reminderSettings = $ready ? hubrem_settings($conn) : null;

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
.ht-panel-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.ht-panel-head h3 { margin: 0; font-size: 15px; }
.ht-check-list { display: grid; gap: 8px; }
.ht-check-list label { display: flex; gap: 8px; align-items: flex-start; font-weight: 500; }
.ht-placeholders code { font-size: 11px; margin-right: 4px; }
.ht-quick-filter { max-width: 260px; }
.ht-seat-meter .meter { max-width: 140px; }
.ht-due-soon { color: var(--amber-fg); font-weight: 600; }
.ht-overdue { color: var(--red-fg); font-weight: 600; }
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
    <?= $canSettings ? '<a href="hub-team?view=settings&amp;tab=pfsense">Set up the pfSense connection</a>.' : 'Ask an administrator to set up the pfSense connection.' ?>
</div>
<?php endif; ?>

<div class="ht-tabs">
    <a href="hub-team" class="btn <?= $view === '' && $memberId === 0 ? 'btn-primary' : 'btn-soft' ?>"><i class="fas fa-network-wired" aria-hidden="true"></i> Internet dashboard</a>
    <?php if ($canOpenSettings): ?>
    <a href="hub-team?view=settings" class="btn <?= $view === 'settings' ? 'btn-primary' : 'btn-soft' ?>"><i class="fas fa-sliders" aria-hidden="true"></i> Settings &amp; reminders</a>
    <?php endif; ?>
</div>

<?php
/* =====================================================================
   SETTINGS: tabs for packages, pfSense and reminders
===================================================================== */
if ($view === 'settings'):
    $packages = $canSettings ? hubnet_packages($conn) : [];
    $usage = [];
    if ($canSettings) {
        $usageResult = $conn->query('SELECT space_package_id, COUNT(*) AS c FROM members WHERE space_package_id IS NOT NULL GROUP BY space_package_id');
        while ($usageResult && ($u = $usageResult->fetch_assoc())) {
            $usage[(int)$u['space_package_id']] = (int)$u['c'];
        }
    }

    $dueMembers = [];
    $reminderLog = [];
    if (isset($settingsTabs['reminders'])) {
        $dueMembers = hubrem_candidates($conn, 30, 60);
        $logResult = $conn->query(
            "SELECT l.*, m.company_name, m.membership_number, u.full_name, s.full_name AS sender
             FROM hub_reminder_log l
             JOIN members m ON m.member_id = l.member_id
             LEFT JOIN users u ON u.user_id = m.user_id
             LEFT JOIN users s ON s.user_id = l.sent_by
             ORDER BY l.sent_at DESC
             LIMIT 25"
        );
        $reminderLog = $logResult ? $logResult->fetch_all(MYSQLI_ASSOC) : [];
    }
?>
<div class="tab-shell" id="settingsTabs" data-ht-tabs>
    <div class="tab-nav" role="tablist" aria-label="Hub settings">
        <?php foreach ($settingsTabs as $key => [$icon, $label]): ?>
            <button type="button" class="tab-btn <?= $key === $activeSettingsTab ? 'active' : '' ?>" role="tab"
                    id="tab-<?= $key ?>" aria-controls="panel-<?= $key ?>" data-tab="<?= $key ?>"
                    aria-selected="<?= $key === $activeSettingsTab ? 'true' : 'false' ?>">
                <i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= ht_e($label) ?>
                <?php if ($key === 'reminders' && $dueMembers !== []): ?><span class="badge"><?= count($dueMembers) ?></span><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="tab-panels">
    <?php if (isset($settingsTabs['packages'])): ?>
        <!-- Packages -->
        <section class="tab-panel <?= $activeSettingsTab === 'packages' ? 'active' : '' ?>" id="panel-packages" role="tabpanel" aria-labelledby="tab-packages">
            <div class="ht-panel-head">
                <div>
                    <h3>Workspace packages</h3>
                    <p class="ht-help">Maximum seats includes the primary member. Lowering a maximum removes nobody; companies above it are flagged and cannot add more people.</p>
                </div>
                <button type="button" class="btn btn-primary" onclick="openPackageModal(null)"><i class="fas fa-plus" aria-hidden="true"></i> Add package</button>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Package</th><th>Max seats</th><th>Members</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($packages === []): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-chair" aria-hidden="true"></i><p>No packages yet.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($packages as $package): ?>
                        <tr>
                            <td><strong><?= ht_e($package['name']) ?></strong><?= $package['description'] ? '<small class="cell-muted">' . ht_e($package['description']) . '</small>' : '' ?></td>
                            <td><?= (int)$package['max_seats'] ?> <?= (int)$package['max_seats'] === 1 ? 'person' : 'people' ?></td>
                            <td><?= $usage[(int)$package['package_id']] ?? 0 ?></td>
                            <td><span class="badge badge-<?= !empty($package['is_active']) ? 'success' : 'secondary' ?>"><?= !empty($package['is_active']) ? 'Active' : 'Hidden' ?></span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning" title="Edit package"
                                        onclick='openPackageModal(<?= json_encode([
                                            'package_id' => (int)$package['package_id'],
                                            'name' => $package['name'],
                                            'max_seats' => (int)$package['max_seats'],
                                            'description' => (string)$package['description'],
                                            'sort_order' => (int)$package['sort_order'],
                                            'is_active' => !empty($package['is_active']),
                                        ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                    <i class="fas fa-edit" aria-hidden="true"></i> Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- pfSense -->
        <section class="tab-panel <?= $activeSettingsTab === 'pfsense' ? 'active' : '' ?>" id="panel-pfsense" role="tabpanel" aria-labelledby="tab-pfsense">
            <div class="ht-panel-head">
                <div>
                    <h3>pfSense connection</h3>
                    <p class="ht-help">Install <code>tools/pfsense/ims-cp-agent.php</code> on the firewall (instructions at the top of that file) and use the same shared secret here.</p>
                </div>
                <div class="ht-actions">
                    <form method="post" action="includes/hub-team-process.php" class="ht-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="test_connection">
                        <button type="submit" class="btn btn-soft"><i class="fas fa-plug" aria-hidden="true"></i> Test connection</button>
                    </form>
                    <button type="button" class="btn btn-primary" onclick="openModal('pfsenseModal')"><i class="fas fa-pen" aria-hidden="true"></i> Edit connection</button>
                </div>
            </div>
            <div class="detail-grid">
                <div><div class="detail-label">Status</div><div class="detail-value"><?= $settings['enabled'] ? '<span class="badge badge-success">On</span>' : '<span class="badge badge-secondary">Off</span>' ?></div></div>
                <div><div class="detail-label">Agent URL</div><div class="detail-value"><?= $settings['agent_url'] !== '' ? '<code>' . ht_e($settings['agent_url']) . '</code>' : 'Not set' ?></div></div>
                <div><div class="detail-label">Captive portal zone</div><div class="detail-value"><code><?= ht_e($settings['cp_zone']) ?></code></div></div>
                <div><div class="detail-label">Shared secret</div><div class="detail-value"><?= $settings['has_secret'] ? 'Saved' : 'Not set' ?></div></div>
                <div><div class="detail-label">Automatic enforcement</div><div class="detail-value"><?= $settings['auto_enforce'] ? 'On' : 'Off' ?></div></div>
                <div><div class="detail-label">Last changed</div><div class="detail-value"><?= $settings['updated_at'] ? ht_e(date('d M Y H:i', strtotime((string)$settings['updated_at']))) : '-' ?></div></div>
            </div>
            <p class="ht-help" style="margin-top:14px">Scheduled task (every 5 minutes): <code>*/5 * * * * php /path/to/legacy/cron/hub-internet-sync.php</code></p>
        </section>
    <?php endif; ?>

    <?php if (isset($settingsTabs['reminders'])): ?>
        <!-- Reminders -->
        <section class="tab-panel <?= $activeSettingsTab === 'reminders' ? 'active' : '' ?>" id="panel-reminders" role="tabpanel" aria-labelledby="tab-reminders">
            <div class="ht-panel-head">
                <div>
                    <h3>Subscription reminders</h3>
                    <p class="ht-help">
                        Automatic reminders are <strong><?= $reminderSettings['enabled'] ? 'on' : 'off' ?></strong>:
                        <?= $reminderSettings['days_before'] !== [] ? ht_e(implode(', ', array_reverse($reminderSettings['days_before']))) . ' day(s) before' : 'none before' ?><?= $reminderSettings['on_expiry'] ? ', on the end date' : '' ?><?= $reminderSettings['overdue_every_days'] > 0 && $reminderSettings['overdue_max'] > 0 ? ', then every ' . (int)$reminderSettings['overdue_every_days'] . ' days up to ' . (int)$reminderSettings['overdue_max'] . ' times' : '' ?>.
                        In-app notification<?= $reminderSettings['send_email'] ? ' and email' : '' ?><?= $reminderSettings['include_team'] ? ', copied to team members' : '' ?>.
                    </p>
                </div>
                <div class="ht-actions">
                    <?php if ($canReminderSettings): ?>
                        <button type="button" class="btn btn-soft" onclick="openModal('reminderSettingsModal')"><i class="fas fa-gear" aria-hidden="true"></i> Automatic reminders</button>
                    <?php endif; ?>
                    <?php if ($canRemind): ?>
                        <form method="post" action="includes/hub-team-process.php" class="ht-inline" onsubmit="return confirm('Send every automatic reminder that is due today?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="run_reminders_now">
                            <button type="submit" class="btn btn-soft"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send due reminders now</button>
                        </form>
                        <button type="button" class="btn btn-primary" onclick="openReminderModal('bulk')" id="bulkRemindBtn" disabled>
                            <i class="fas fa-bell" aria-hidden="true"></i> Remind selected (<span id="selectedRemindCount">0</span>)
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <h4>Ending in the next 30 days or ended in the last 60</h4>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr>
                        <?php if ($canRemind): ?><th style="width:40px"><input type="checkbox" id="remindSelectAll" aria-label="Select all"></th><?php endif; ?>
                        <th>Member</th><th>Plan</th><th>End date</th><th>Last reminder</th><?php if ($canRemind): ?><th></th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php if ($dueMembers === []): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-calendar-check" aria-hidden="true"></i><p>No subscriptions ending soon.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($dueMembers as $due): $daysLeft = (int)$due['days_left']; ?>
                        <tr>
                            <?php if ($canRemind): ?><td><input type="checkbox" class="remind-check" value="<?= (int)$due['member_id'] ?>" aria-label="Select <?= ht_e($due['full_name']) ?>"></td><?php endif; ?>
                            <td><strong><?= ht_e($due['company_name'] ?: $due['full_name']) ?></strong><small class="cell-muted"><?= ht_e($due['membership_number']) ?> &middot; <?= ht_e($due['email'] ?: 'no email') ?></small></td>
                            <td><?= ht_e($due['subscription_plan']) ?><?= $due['package_name'] ? '<small class="cell-muted">' . ht_e($due['package_name']) . '</small>' : '' ?></td>
                            <td>
                                <?= ht_e(date('d M Y', strtotime((string)$due['subscription_end_date']))) ?>
                                <small class="<?= $daysLeft < 0 ? 'ht-overdue' : ($daysLeft <= 7 ? 'ht-due-soon' : 'cell-muted') ?>"><?= ht_e(ucfirst(hubrem_status_text($daysLeft))) ?></small>
                            </td>
                            <td><?= $due['last_reminder_at'] ? ht_e(date('d M Y H:i', strtotime((string)$due['last_reminder_at']))) : '<span class="cell-muted">Never</span>' ?></td>
                            <?php if ($canRemind): ?>
                            <td><button type="button" class="btn btn-sm btn-primary" onclick="openReminderModal([<?= (int)$due['member_id'] ?>], <?= ht_e(json_encode((string)($due['company_name'] ?: $due['full_name']))) ?>)"><i class="fas fa-bell" aria-hidden="true"></i> Remind</button></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h4 style="margin-top:18px">Recently sent</h4>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Sent</th><th>Member</th><th>Type</th><th>Subject</th><th>Delivered</th></tr></thead>
                    <tbody>
                    <?php if ($reminderLog === []): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-bell-slash" aria-hidden="true"></i><p>No reminders sent yet.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($reminderLog as $log): ?>
                        <tr>
                            <td><?= ht_e(date('d M Y H:i', strtotime((string)$log['sent_at']))) ?></td>
                            <td><?= ht_e($log['company_name'] ?: $log['full_name']) ?><small class="cell-muted"><?= ht_e($log['membership_number']) ?></small></td>
                            <td><?= $log['kind'] === 'manual' ? 'Manual' . ($log['sender'] ? ' &middot; ' . ht_e($log['sender']) : '') : 'Automatic' ?></td>
                            <td><?= ht_e($log['subject']) ?></td>
                            <td><?= (int)$log['emailed'] ?> email(s)<?= $log['error'] ? '<small class="ht-overdue" title="' . ht_e($log['error']) . '">' . ht_e(mb_strimwidth((string)$log['error'], 0, 60, '...')) . '</small>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    </div>
</div>

<?php if ($canSettings): ?>
<!-- Add / edit package -->
<div id="packageModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="packageModalTitle">Add package</h3>
            <span class="close" onclick="closeModal('packageModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="post" action="includes/hub-team-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_package">
                <input type="hidden" name="package_id" id="pkg_id" value="0">
                <div class="form-group"><label for="pkg_name" class="required">Name</label><input id="pkg_name" class="form-control" name="name" required maxlength="100" placeholder="e.g. Dedicated table for 8"></div>
                <div class="form-row">
                    <div class="form-group"><label for="pkg_seats" class="required">Maximum seats</label><input id="pkg_seats" class="form-control" type="number" min="1" max="500" name="max_seats" value="1" required><div class="ht-help">Including the primary member.</div></div>
                    <div class="form-group"><label for="pkg_order">Display order</label><input id="pkg_order" class="form-control" type="number" name="sort_order" value="60"></div>
                </div>
                <div class="form-group"><label for="pkg_desc">Description</label><input id="pkg_desc" class="form-control" name="description" maxlength="255"></div>
                <div class="form-group"><label><input type="checkbox" name="is_active" value="1" id="pkg_active" checked> Offer this package when adding or editing members</label></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('packageModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save package</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- pfSense connection -->
<div id="pfsenseModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>pfSense connection</h3>
            <span class="close" onclick="closeModal('pfsenseModal')">&times;</span>
        </div>
        <div class="modal-body">
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
                <div class="ht-check-list">
                    <label><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>> Send connect / disconnect changes to pfSense</label>
                    <label><input type="checkbox" name="auto_enforce" value="1" <?= $settings['auto_enforce'] ? 'checked' : '' ?>> Automatic: disconnect when a membership expires or is suspended, reconnect when it is active again</label>
                    <label><input type="checkbox" name="verify_tls" value="1" <?= $settings['verify_tls'] ? 'checked' : '' ?>> Verify the firewall's HTTPS certificate (leave off for pfSense's self-signed certificate)</label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('pfsenseModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save connection</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canReminderSettings): ?>
<!-- Automatic reminder settings -->
<div id="reminderSettingsModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Automatic reminders</h3>
            <span class="close" onclick="closeModal('reminderSettingsModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="post" action="includes/hub-team-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_reminder_settings">
                <div class="ht-check-list">
                    <label><input type="checkbox" name="enabled" value="1" <?= $reminderSettings['enabled'] ? 'checked' : '' ?>> Send reminders automatically</label>
                </div>
                <div class="form-row" style="margin-top:12px">
                    <div class="form-group">
                        <label for="rs_days">Days before the end date</label>
                        <input id="rs_days" class="form-control" name="days_before" value="<?= ht_e(implode(', ', array_reverse($reminderSettings['days_before']))) ?>" placeholder="14, 7, 3, 1">
                        <div class="ht-help">Comma separated. Leave empty for none.</div>
                    </div>
                    <div class="form-group">
                        <label for="rs_every">After it ends: every (days)</label>
                        <input id="rs_every" class="form-control" type="number" min="0" max="90" name="overdue_every_days" value="<?= (int)$reminderSettings['overdue_every_days'] ?>">
                    </div>
                    <div class="form-group">
                        <label for="rs_max">At most (times)</label>
                        <input id="rs_max" class="form-control" type="number" min="0" max="24" name="overdue_max" value="<?= (int)$reminderSettings['overdue_max'] ?>">
                    </div>
                </div>
                <div class="ht-check-list">
                    <label><input type="checkbox" name="on_expiry" value="1" <?= $reminderSettings['on_expiry'] ? 'checked' : '' ?>> Remind on the end date</label>
                    <label><input type="checkbox" name="send_email" value="1" <?= $reminderSettings['send_email'] ? 'checked' : '' ?>> Email as well as the in-app notification</label>
                    <label><input type="checkbox" name="include_team" value="1" <?= $reminderSettings['include_team'] ? 'checked' : '' ?>> Copy the email to the company's active team members</label>
                </div>
                <div class="form-group" style="margin-top:12px">
                    <label for="rs_subject" class="required">Subject</label>
                    <input id="rs_subject" class="form-control" name="subject" required maxlength="190" value="<?= ht_e($reminderSettings['subject']) ?>">
                </div>
                <div class="form-group">
                    <label for="rs_message" class="required">Message</label>
                    <textarea id="rs_message" class="form-control" name="message" rows="7" required><?= ht_e($reminderSettings['message']) ?></textarea>
                    <div class="ht-help ht-placeholders">Placeholders: <?php foreach (HUBREM_PLACEHOLDERS as $placeholder): ?><code><?= ht_e($placeholder) ?></code><?php endforeach; ?></div>
                </div>
                <p class="ht-help">Scheduled task (daily): <code>0 8 * * * php /path/to/legacy/cron/hub-member-reminders.php</code></p>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('reminderSettingsModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>

function openPackageModal(pkg) {
    document.getElementById('packageModalTitle').textContent = pkg ? 'Edit package' : 'Add package';
    document.getElementById('pkg_id').value = pkg ? pkg.package_id : 0;
    document.getElementById('pkg_name').value = pkg ? pkg.name : '';
    document.getElementById('pkg_seats').value = pkg ? pkg.max_seats : 1;
    document.getElementById('pkg_order').value = pkg ? pkg.sort_order : 60;
    document.getElementById('pkg_desc').value = pkg ? pkg.description : '';
    document.getElementById('pkg_active').checked = pkg ? !!pkg.is_active : true;
    openModal('packageModal');
}

(function () {
    const all = document.getElementById('remindSelectAll');
    const checks = Array.from(document.querySelectorAll('.remind-check'));
    const button = document.getElementById('bulkRemindBtn');
    const count = document.getElementById('selectedRemindCount');
    if (!button) return;

    function update() {
        const selected = checks.filter(function (c) { return c.checked; }).length;
        count.textContent = String(selected);
        button.disabled = selected === 0;
        if (all) {
            all.checked = checks.length > 0 && selected === checks.length;
            all.indeterminate = selected > 0 && selected < checks.length;
        }
    }

    checks.forEach(function (c) { c.addEventListener('change', update); });
    if (all) all.addEventListener('change', function () { checks.forEach(function (c) { c.checked = all.checked; }); update(); });
    window.selectedReminderMembers = function () {
        return checks.filter(function (c) { return c.checked; }).map(function (c) { return Number(c.value); });
    };
})();
</script>

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
            <?php if ($canRemind): ?>
                <button type="button" class="btn" onclick="openReminderModal([<?= $memberId ?>], <?= ht_e(json_encode((string)($member['company_name'] ?: $member['full_name']))) ?>)"><i class="fas fa-bell" aria-hidden="true"></i> Send reminder</button>
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
<?php
    $hasDeviceFilter = $search !== '' || $stateFilter !== '';
    $dashboardTab = (string)($_GET['tab'] ?? ($hasDeviceFilter ? 'devices' : 'companies'));
    if (!in_array($dashboardTab, ['companies', 'devices'], true)) {
        $dashboardTab = 'companies';
    }
    $overSeats = count(array_filter($companyRows, static fn(array $c): bool =>
        $c['max_seats'] !== null && (int)$c['seats_used'] > (int)$c['max_seats']));
?>
<div class="stats-grid reports-stats">
    <div class="stat-card"><div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Registered people (active)</span><span class="stat-value"><?= $counts['people'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-laptop" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Devices</span><span class="stat-value"><?= $counts['devices'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-wifi" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Connected</span><span class="stat-value"><?= $counts['connected'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-red"><i class="fas fa-ban" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Disconnected / blocked</span><span class="stat-value"><?= $counts['offline'] ?></span></div></div>
</div>

<div class="tab-shell" data-ht-tabs>
    <div class="tab-nav" role="tablist" aria-label="Hub team and internet">
        <button type="button" class="tab-btn <?= $dashboardTab === 'companies' ? 'active' : '' ?>" role="tab"
                id="tab-companies" aria-controls="panel-companies" data-tab="companies"
                aria-selected="<?= $dashboardTab === 'companies' ? 'true' : 'false' ?>">
            <i class="fas fa-building" aria-hidden="true"></i> Companies &amp; seats
            <span class="badge"><?= count($companyRows) ?></span>
        </button>
        <button type="button" class="tab-btn <?= $dashboardTab === 'devices' ? 'active' : '' ?>" role="tab"
                id="tab-devices" aria-controls="panel-devices" data-tab="devices"
                aria-selected="<?= $dashboardTab === 'devices' ? 'true' : 'false' ?>">
            <i class="fas fa-network-wired" aria-hidden="true"></i> Devices &amp; internet access
            <span class="badge"><?= count($devices) ?></span>
        </button>
    </div>

    <div class="tab-panels">
        <!-- Companies & seats -->
        <section class="tab-panel <?= $dashboardTab === 'companies' ? 'active' : '' ?>" id="panel-companies" role="tabpanel" aria-labelledby="tab-companies">
            <div class="ht-panel-head">
                <div>
                    <h3>Companies &amp; seats</h3>
                    <p class="ht-help">Active, pending and suspended members. The primary member takes one seat.<?= $overSeats > 0 ? ' <span class="ht-over">' . $overSeats . ' over their package limit.</span>' : '' ?></p>
                </div>
                <input type="search" class="form-control ht-quick-filter" data-filter-table="companiesTable"
                       placeholder="Filter companies..." aria-label="Filter companies">
            </div>
            <div class="table-responsive">
                <table class="table" id="companiesTable">
                    <thead><tr><th>Company / member</th><th>Package</th><th>Seats</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($companyRows === []): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-building" aria-hidden="true"></i><p>No active members yet.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($companyRows as $company):
                        $max = $company['max_seats'] !== null ? (int)$company['max_seats'] : null;
                        $used = (int)$company['seats_used'];
                        $percent = $max ? min(100, (int)round($used / $max * 100)) : 0;
                    ?>
                        <tr>
                            <td><strong><?= ht_e($company['company_name'] ?: $company['full_name']) ?></strong><small class="cell-muted"><?= ht_e($company['membership_number']) ?><?= $company['company_name'] ? ' &middot; ' . ht_e($company['full_name']) : '' ?></small></td>
                            <td><?= $company['package_name'] ? ht_e($company['package_name']) : '<span class="cell-muted">Not set</span>' ?></td>
                            <td>
                                <div class="ht-seat-meter">
                                    <span class="<?= $max !== null && $used > $max ? 'ht-over' : '' ?>"><?= $used ?> / <?= $max ?? '-' ?></span>
                                    <?php if ($max): ?><div class="meter" aria-hidden="true"><div class="meter-fill <?= $used > $max ? 'tone-red' : ($used === $max ? 'tone-amber' : 'tone-green') ?>" style="width:<?= $percent ?>%"></div></div><?php endif; ?>
                                </div>
                            </td>
                            <td><?= ht_e($company['membership_status']) ?></td>
                            <td><a class="btn btn-sm btn-primary" href="hub-team?member_id=<?= (int)$company['member_id'] ?>"><i class="fas fa-users" aria-hidden="true"></i> Team</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Devices & internet access -->
        <section class="tab-panel <?= $dashboardTab === 'devices' ? 'active' : '' ?>" id="panel-devices" role="tabpanel" aria-labelledby="tab-devices">
            <form method="get" action="hub-team" class="filters-bar" role="search">
                <input type="hidden" name="tab" value="devices">
                <input type="text" name="q" class="form-control filters-grow" value="<?= ht_e($search) ?>" placeholder="Search company, person, membership # or MAC...">
                <select name="state" class="form-control" aria-label="Internet state">
                    <option value="">All states</option>
                    <?php foreach (['connected' => 'Connected', 'disconnected' => 'Disconnected', 'blocked' => 'Blocked', 'error' => 'Sync error', 'unknown' => 'Not synced'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $stateFilter === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary"><i class="fas fa-search" aria-hidden="true"></i> Filter</button>
                <?php if ($hasDeviceFilter): ?>
                    <a href="hub-team?tab=devices" class="btn btn-soft"><i class="fas fa-times" aria-hidden="true"></i> Clear</a>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Person</th><th>Company</th><th>Device</th><th>Mode</th><th>pfSense</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ($devices === []): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-laptop" aria-hidden="true"></i><p>No devices registered<?= $hasDeviceFilter ? ' match the filter' : ' yet. Open a company\'s Team page to add people and devices' ?>.</p></div></td></tr>
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
        </section>
    </div>
</div>
<?php endif; ?>

<?php if ($canRemind && ($view === 'settings' || $memberId > 0)): ?>
<!-- Send a reminder (Operations / Administrators) -->
<div id="reminderModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="reminderModalTitle">Send reminder</h3>
            <span class="close" onclick="closeModal('reminderModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="post" action="includes/hub-team-process.php" id="reminderForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_reminder">
                <?php if ($view === 'settings'): ?>
                    <input type="hidden" name="return_view" value="settings">
                    <input type="hidden" name="return_tab" value="reminders">
                <?php else: ?>
                    <input type="hidden" name="member_id" value="<?= $memberId ?>">
                <?php endif; ?>
                <div id="reminderRecipients"></div>
                <p class="ht-help" id="reminderTo"></p>
                <div class="form-group">
                    <label for="rm_subject" class="required">Subject</label>
                    <input id="rm_subject" class="form-control" name="subject" required maxlength="190" value="<?= ht_e($reminderSettings['subject']) ?>">
                </div>
                <div class="form-group">
                    <label for="rm_message" class="required">Message</label>
                    <textarea id="rm_message" class="form-control" name="message" rows="7" required><?= ht_e($reminderSettings['message']) ?></textarea>
                    <div class="ht-help ht-placeholders">Filled in for each member: <?php foreach (HUBREM_PLACEHOLDERS as $placeholder): ?><code><?= ht_e($placeholder) ?></code><?php endforeach; ?></div>
                </div>
                <div class="ht-check-list">
                    <label><input type="checkbox" name="send_email" value="1" checked> Email as well as the in-app notification</label>
                    <label><input type="checkbox" name="include_team" value="1" <?= $reminderSettings['include_team'] ? 'checked' : '' ?>> Copy the email to the company's active team members</label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('reminderModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send reminder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ids: array of member ids, or 'bulk' for the ticked rows; name: shown when one member. */
function openReminderModal(ids, name) {
    if (ids === 'bulk') {
        ids = typeof window.selectedReminderMembers === 'function' ? window.selectedReminderMembers() : [];
    }
    ids = Array.isArray(ids) ? ids : [];

    const box = document.getElementById('reminderRecipients');
    box.innerHTML = '';
    ids.forEach(function (id) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'member_ids[]';
        input.value = String(id);
        box.appendChild(input);
    });

    const single = ids.length === 1 && name;
    document.getElementById('reminderModalTitle').textContent = single ? 'Send reminder to ' + name : 'Send reminder';
    document.getElementById('reminderTo').textContent = ids.length > 1
        ? 'To ' + ids.length + ' members. Placeholders are filled in for each one.'
        : (ids.length === 0 ? 'To this member.' : '');
    openModal('reminderModal');
}

<?php if ($memberId > 0 && isset($_GET['remind'])): ?>
window.addEventListener('DOMContentLoaded', function () { openReminderModal([<?= $memberId ?>], ''); });
<?php endif; ?>
</script>
<?php endif; ?>

<?php endif; ?>
</div><!-- /.page-stack -->

<script>
/* Tabs: any .tab-shell[data-ht-tabs]; the open tab is kept in ?tab= so reloads and redirects return to it. */
document.querySelectorAll('.tab-shell[data-ht-tabs]').forEach(function (shell) {
    const buttons = Array.from(shell.querySelectorAll('.tab-btn[data-tab]'));

    function activate(key) {
        buttons.forEach(function (button) {
            const on = button.dataset.tab === key;
            button.classList.toggle('active', on);
            button.setAttribute('aria-selected', on ? 'true' : 'false');
            button.tabIndex = on ? 0 : -1;
            const panel = document.getElementById('panel-' + button.dataset.tab);
            if (panel) panel.classList.toggle('active', on);
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', key);
        history.replaceState(null, '', url.toString());
    }

    buttons.forEach(function (button) {
        button.tabIndex = button.classList.contains('active') ? 0 : -1;
        button.addEventListener('click', function () { activate(button.dataset.tab); });
        button.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
            event.preventDefault();
            const index = buttons.indexOf(button) + (event.key === 'ArrowRight' ? 1 : -1);
            const next = buttons[(index + buttons.length) % buttons.length];
            next.focus();
            activate(next.dataset.tab);
        });
    });
});

/* Quick filter: <input data-filter-table="tableId"> hides rows that do not contain the text. */
document.querySelectorAll('[data-filter-table]').forEach(function (input) {
    const table = document.getElementById(input.dataset.filterTable);
    if (!table) return;
    input.addEventListener('input', function () {
        const needle = input.value.trim().toLowerCase();
        table.querySelectorAll('tbody tr').forEach(function (row) {
            if (row.querySelector('.empty-state')) return;
            row.hidden = needle !== '' && !row.textContent.toLowerCase().includes(needle);
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
