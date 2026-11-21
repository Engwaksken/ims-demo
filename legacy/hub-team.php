<?php
declare(strict_types=1);

/*
| Hub Team & Internet
|   /hub-team                    overview: internet switch, devices online now, history
|   /hub-team?view=companies     companies & seats, every registered device and its access
|   /hub-team?view=staff|guests  staff / guests on the internet (?person=N: one person)
|   /hub-team?view=online        devices pfSense sees online, identified; register unknown ones
|   /hub-team?view=hotspot       guest hotspot: password, QR codes, poster, pfSense setup
|   /hub-team?member_id=N        one company: seats, team members, devices
|   /hub-team?view=settings      workspace packages (seat limits) and pfSense connection
*/

$page_title = 'Hub Team & Internet';
include 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hub-network.php';
require_once __DIR__ . '/includes/hub-internet.php';
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
$canSwitch = ims_allowed('hub_network.switch', ['Administrator', 'IT Officer'], 'Switch hub internet control on/off (open internet) on pfSense');
$canVouchers = ims_allowed('hub_network.vouchers', ['Administrator', 'Operations/Admin'], 'Create, print and cancel internet vouchers for day clients');

$successMessage = (string)($_SESSION['success'] ?? '');
$errorMessage = (string)($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

require_once __DIR__ . '/includes/hub-reminders.php';

$ready = isset($conn) && $conn instanceof mysqli && hubnet_ensure_schema($conn) && hubrem_ensure_schema($conn);
$view = (string)($_GET['view'] ?? '');
$memberId = (int)($_GET['member_id'] ?? 0);

if (($view === 'settings' && !$canOpenSettings) || ($view === 'vouchers' && !$canVouchers)) {
    $view = '';
}
// Default page: the overview. The companies/devices dashboard is view=companies (or an old ?tab= link).
if ($view === '' && $memberId === 0) {
    $view = isset($_GET['tab']) || isset($_GET['q']) || isset($_GET['state']) ? 'companies' : 'overview';
}
if (!in_array($view, ['overview', 'companies', 'staff', 'guests', 'online', 'hotspot', 'vouchers', 'settings'], true)) {
    $view = 'overview';
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
    $state = (string)($device['pf_state'] ?? '');
    $title = $state === 'error' ? (string)($device['pf_last_error'] ?? '')
        : (isset($map[$state]) && $state !== 'unknown' ? 'Confirmed by pfSense ' . (string)($device['pf_synced_at'] ?? '')
        : 'Waiting for pfSense: applied at its next check-in (about a minute).');

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
        // Rows in tables (devices, people) show the icon only; company-wide buttons keep their text.
        . '<button type="submit" class="btn btn-sm ' . $class . '" title="' . ht_e($label) . '" aria-label="' . ht_e($label) . '"><i class="fas ' . $icon . '" aria-hidden="true"></i>' . ($scope === 'member' ? ' ' . ht_e($label) : '') . '</button>'
        . '</form>';
}
?>

<style>
.ht-inline { display: inline; margin: 0; }
.pkg-section { border: 1px solid var(--border); border-radius: var(--radius-md, 8px); padding: 10px 14px 4px; margin: 0 0 12px; min-width: 0; }
.pkg-section legend { font-size: 13px; font-weight: 700; padding: 0 6px; color: var(--ink-700); }
.pkg-section legend i { color: var(--brand-500); margin-right: 4px; }
.hi-speed { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
.hi-speed-val { font-size: 11px; }
.hi-row-actions { display: inline-flex; gap: 4px; flex-wrap: nowrap; }
#hiSpeedList { margin: 0 0 8px; padding-left: 18px; max-height: 120px; overflow: auto; font-size: 12px; }
.ht-mainnav { padding: 0; overflow: hidden; }
.ht-mainnav .tab-nav { margin: 0; border-bottom: 0; }
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
        <p>Manage internet access for hub members, staff and guests, run the guest hotspot and control pfSense from here.</p>
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

<div class="card ht-mainnav">
    <nav class="tab-nav" aria-label="Hub team and internet">
        <?php foreach (array_filter([
            'overview' => ['fa-gauge', 'Overview'],
            'companies' => ['fa-building', 'Companies & devices'],
            'staff' => ['fa-id-badge', 'Staff'],
            'guests' => ['fa-user-clock', 'Guests'],
            'online' => ['fa-signal', 'Online now'],
            'hotspot' => ['fa-qrcode', 'Hotspot'],
            'vouchers' => $canVouchers ? ['fa-ticket', 'Vouchers'] : null,
            'settings' => $canOpenSettings ? ['fa-sliders', 'Settings'] : null,
        ]) as $navKey => [$navIcon, $navLabel]):
            $navActive = $view === $navKey || ($navKey === 'companies' && $memberId > 0); ?>
            <a class="tab-btn<?= $navActive ? ' active' : '' ?>" href="hub-team?view=<?= $navKey ?>"<?= $navActive ? ' aria-current="page"' : '' ?>><i class="fas <?= $navIcon ?>" aria-hidden="true"></i> <?= ht_e($navLabel) ?></a>
        <?php endforeach; ?>
    </nav>
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
                    <p class="ht-help">Seats include the primary member. Device limits cap how many devices each person and the whole company can register (empty = no limit). Lowering a limit removes nothing; companies above it are flagged and cannot add more.</p>
                </div>
                <button type="button" class="btn btn-primary" onclick="openPackageModal(null)"><i class="fas fa-plus" aria-hidden="true"></i> Add package</button>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Package</th><th>Seats</th><th>Devices</th><th>Members</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($packages === []): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-chair" aria-hidden="true"></i><p>No packages yet.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($packages as $package): ?>
                        <tr>
                            <td><strong><?= ht_e($package['name']) ?></strong><?= $package['description'] ? '<small class="cell-muted">' . ht_e($package['description']) . '</small>' : '' ?></td>
                            <td><?= (int)$package['max_seats'] ?> <?= (int)$package['max_seats'] === 1 ? 'person' : 'people' ?></td>
                            <td><?php $pkgLimit = hubnet_device_limit_label($package); ?><?= $pkgLimit === 'No limit' ? '<span class="cell-muted">No limit</span>' : ht_e($pkgLimit) ?></td>
                            <td><?= $usage[(int)$package['package_id']] ?? 0 ?></td>
                            <td><span class="badge badge-<?= !empty($package['is_active']) ? 'success' : 'secondary' ?>"><?= !empty($package['is_active']) ? 'Active' : 'Hidden' ?></span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-soft" title="Edit package" aria-label="Edit <?= ht_e($package['name']) ?>"
                                        onclick='openPackageModal(<?= json_encode([
                                            'package_id' => (int)$package['package_id'],
                                            'name' => $package['name'],
                                            'max_seats' => (int)$package['max_seats'],
                                            'devices_per_person' => $package['devices_per_person'] !== null ? (int)$package['devices_per_person'] : '',
                                            'max_devices' => $package['max_devices'] !== null ? (int)$package['max_devices'] : '',
                                            'members' => $usage[(int)$package['package_id']] ?? 0,
                                            'description' => (string)$package['description'],
                                            'sort_order' => (int)$package['sort_order'],
                                            'is_active' => !empty($package['is_active']),
                                        ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                    <i class="fas fa-pen" aria-hidden="true"></i>
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
                    <p class="ht-help">Install <code>tools/pfsense/ims-cp-agent.php</code> on the firewall (step-by-step instructions at the top of that file) and use the same shared secret here.</p>
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
<?php
                $isPull = $settings['mode'] === 'pull';
                $online = hubnet_agent_online($settings);
                $checkin = $settings['last_checkin_info'];
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'your-ims-domain';
                $feedUrl = $scheme . '://' . $host . '/hub-network-agent';
?>
            <div class="detail-grid">
                <div><div class="detail-label">Control</div><div class="detail-value"><?= $settings['enabled'] ? '<span class="badge badge-success">On</span>' : '<span class="badge badge-secondary">Off</span>' ?></div></div>
                <div><div class="detail-label">Connection</div><div class="detail-value"><?= $isPull ? 'pfSense checks in (pull)' : 'IMS calls pfSense (push)' ?></div></div>
                <?php if ($isPull): ?>
                <div>
                    <div class="detail-label">Last check-in</div>
                    <div class="detail-value">
                        <?php if ($settings['last_checkin_at']): ?>
                            <span class="badge badge-<?= $online ? 'success' : 'danger' ?>"><?= $online ? 'Online' : 'Not checking in' ?></span>
                            <?= ht_e(date('d M Y H:i:s', strtotime((string)$settings['last_checkin_at']))) ?>
                            <?= !empty($checkin) ? '<small class="cell-muted">agent ' . ht_e($checkin['version'] ?? '?') . ' &middot; ' . (int)($checkin['sessions'] ?? 0) . ' sessions</small>' : '' ?>
                            <?php if (array_key_exists('mac_filtering', $checkin) && !$checkin['mac_filtering']): ?>
                                <small class="ht-overdue">MAC filtering is off on this zone: pass/block entries have no effect.</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-secondary">Never</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div><div class="detail-label">Feed URL (IMS_FEED_URL on pfSense)</div><div class="detail-value"><code><?= ht_e($feedUrl) ?></code></div></div>
                <?php else: ?>
                <div><div class="detail-label">Agent URL</div><div class="detail-value"><?= $settings['agent_url'] !== '' ? '<code>' . ht_e($settings['agent_url']) . '</code>' : 'Not set' ?></div></div>
                <?php endif; ?>
                <div><div class="detail-label">Captive portal zone</div><div class="detail-value"><code><?= ht_e($settings['cp_zone']) ?></code></div></div>
                <div><div class="detail-label">Shared secret</div><div class="detail-value"><?= $settings['has_secret'] ? 'Saved' : 'Not set' ?></div></div>
                <div><div class="detail-label">Last changed</div><div class="detail-value"><?= $settings['updated_at'] ? ht_e(date('d M Y H:i', strtotime((string)$settings['updated_at']))) : '-' ?></div></div>
            </div>
            <?php if ($isPull): ?>
            <p class="ht-help" style="margin-top:14px">
                On pfSense, Services &gt; Cron, every minute as root:
                <code>/usr/local/bin/php -f /root/ims-cp-agent.php pull</code>.
                Expired and suspended members are disconnected automatically at the next check-in; no cron job is needed on the IMS server.
            </p>
            <?php else: ?>
            <p class="ht-help" style="margin-top:14px">IMS server cron (every 5 minutes): <code>*/5 * * * * php /path/to/legacy/cron/hub-internet-sync.php</code></p>
            <?php endif; ?>
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
<div id="packageModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="packageModalTitle" aria-hidden="true">
    <div class="modal-content" style="max-width:600px">
        <div class="modal-header">
            <h3 id="packageModalTitle">Add package</h3>
            <button type="button" class="close" aria-label="Close" onclick="closeModal('packageModal')">&times;</button>
        </div>
        <form method="post" action="includes/hub-team-process.php" id="pkgForm">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_package">
                <input type="hidden" name="package_id" id="pkg_id" value="0">
                <p class="ht-help" id="pkg_inuse" hidden></p>

                <fieldset class="pkg-section"><legend><i class="fas fa-tag" aria-hidden="true"></i> Package</legend>
                    <div class="form-group"><label for="pkg_name" class="required">Name</label><input id="pkg_name" class="form-control" name="name" required maxlength="100" placeholder="e.g. Dedicated table for 8"></div>
                    <div class="form-group"><label for="pkg_desc">Description</label><input id="pkg_desc" class="form-control" name="description" maxlength="255" placeholder="What the member gets, e.g. Dedicated table, up to 8 people"></div>
                </fieldset>

                <fieldset class="pkg-section"><legend><i class="fas fa-chair" aria-hidden="true"></i> Seats</legend>
                    <div class="form-group"><label for="pkg_seats" class="required">Maximum people</label><input id="pkg_seats" class="form-control" type="number" min="1" max="500" name="max_seats" value="1" required><p class="form-hint">Including the primary member.</p></div>
                </fieldset>

                <fieldset class="pkg-section"><legend><i class="fas fa-laptop" aria-hidden="true"></i> Devices on the hub internet</legend>
                    <div class="form-row">
                        <div class="form-group"><label for="pkg_dpp">Devices per person</label><input id="pkg_dpp" class="form-control" type="number" min="1" max="50" name="devices_per_person" placeholder="No limit"></div>
                        <div class="form-group"><label for="pkg_maxdev">Total devices for the company</label><input id="pkg_maxdev" class="form-control" type="number" min="1" max="5000" name="max_devices" placeholder="No limit"></div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">
                        <?php foreach ([1, 2, 3, 5] as $pp): ?>
                            <button type="button" class="btn btn-sm btn-soft" onclick="pkgPreset(<?= $pp ?>)"><?= $pp ?> per person</button>
                        <?php endforeach; ?>
                        <button type="button" class="btn btn-sm btn-soft" onclick="pkgPreset('')">No limit</button>
                    </div>
                    <p class="form-hint" id="pkg_summary">Leave a box empty for no limit. Phones, laptops and tablets each count as one device.</p>
                </fieldset>

                <fieldset class="pkg-section"><legend><i class="fas fa-sliders" aria-hidden="true"></i> Listing</legend>
                    <div class="form-row">
                        <div class="form-group"><label for="pkg_order">Display order</label><input id="pkg_order" class="form-control" type="number" name="sort_order" value="60"><p class="form-hint">Lower numbers are listed first.</p></div>
                        <div class="form-group"><label class="hr-check" style="margin-top:28px"><input type="checkbox" name="is_active" value="1" id="pkg_active" checked> Offer when adding or editing members</label></div>
                    </div>
                </fieldset>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('packageModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> <span id="pkg_submit">Add package</span></button>
            </div>
        </form>
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
                    <label for="net_mode">Connection</label>
                    <select id="net_mode" class="form-control" name="mode" onchange="document.getElementById('pushOnly').hidden = this.value !== 'push'">
                        <option value="pull" <?= $settings['mode'] === 'pull' ? 'selected' : '' ?>>pfSense checks in every minute (pull) - use this on shared hosting</option>
                        <option value="push" <?= $settings['mode'] === 'push' ? 'selected' : '' ?>>IMS calls pfSense directly (push) - IMS server must reach the firewall</option>
                    </select>
                </div>
                <div id="pushOnly" <?= $settings['mode'] === 'push' ? '' : 'hidden' ?>>
                    <div class="form-group">
                        <label for="agent_url">Agent URL (push)</label>
                        <input id="agent_url" class="form-control" name="agent_url" value="<?= ht_e($settings['agent_url']) ?>" placeholder="https://192.168.1.1/ims-cp-agent.php">
                    </div>
                    <div class="ht-check-list" style="margin-bottom:12px">
                        <label><input type="checkbox" name="auto_enforce" value="1" <?= $settings['auto_enforce'] ? 'checked' : '' ?>> Automatic enforcement from the IMS server cron (pull mode always enforces at each check-in)</label>
                        <label><input type="checkbox" name="verify_tls" value="1" <?= $settings['verify_tls'] ? 'checked' : '' ?>> Verify the firewall's HTTPS certificate (leave off for pfSense's self-signed certificate)</label>
                    </div>
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
                    <label><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>> Control internet access on pfSense (when off, pfSense still checks in but nothing is changed)</label>
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
    document.getElementById('packageModalTitle').textContent = pkg ? 'Edit package: ' + pkg.name : 'Add package';
    document.getElementById('pkg_submit').textContent = pkg ? 'Save changes' : 'Add package';
    document.getElementById('pkg_id').value = pkg ? pkg.package_id : 0;
    document.getElementById('pkg_name').value = pkg ? pkg.name : '';
    document.getElementById('pkg_seats').value = pkg ? pkg.max_seats : 1;
    document.getElementById('pkg_dpp').value = pkg ? pkg.devices_per_person : '';
    document.getElementById('pkg_maxdev').value = pkg ? pkg.max_devices : '';
    document.getElementById('pkg_order').value = pkg ? pkg.sort_order : 60;
    document.getElementById('pkg_desc').value = pkg ? pkg.description : '';
    document.getElementById('pkg_active').checked = pkg ? !!pkg.is_active : true;
    var inUse = document.getElementById('pkg_inuse');
    inUse.hidden = !(pkg && pkg.members);
    inUse.textContent = pkg && pkg.members ? 'Used by ' + pkg.members + ' member' + (pkg.members === 1 ? '' : 's') + '. Lower limits apply to new people and devices only.' : '';
    pkgSummary();
    openModal('packageModal');
}

/* "N per person" also fills the company total (seats x N); the total can then be changed. */
function pkgPreset(n) {
    var seats = parseInt(document.getElementById('pkg_seats').value, 10) || 1;
    document.getElementById('pkg_dpp').value = n;
    document.getElementById('pkg_maxdev').value = n === '' ? '' : seats * n;
    pkgSummary();
}

function pkgSummary() {
    var seats = parseInt(document.getElementById('pkg_seats').value, 10) || 1;
    var dpp = parseInt(document.getElementById('pkg_dpp').value, 10);
    var max = parseInt(document.getElementById('pkg_maxdev').value, 10);
    var out = document.getElementById('pkg_summary');
    var text;
    if (!dpp && !max) {
        text = 'No device limit: every person can register any number of devices.';
    } else {
        text = 'Up to ' + seats + ' ' + (seats === 1 ? 'person' : 'people')
            + (dpp ? ', ' + dpp + ' device' + (dpp === 1 ? '' : 's') + ' each' : '')
            + (max ? ', ' + max + ' device' + (max === 1 ? '' : 's') + ' in total for the company' : ', no company total')
            + '.';
        if (dpp && max && max < dpp) text += ' The total is lower than the devices per person.';
        else if (dpp && max && max < dpp * seats) text += ' Note: the total is lower than ' + seats + ' x ' + dpp + ' = ' + (seats * dpp) + ', so not everyone can use all their devices.';
    }
    out.textContent = text;
    out.classList.toggle('ht-over', !!(dpp && max && max < dpp));
}

['pkg_seats', 'pkg_dpp', 'pkg_maxdev'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', pkgSummary);
});

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
            <?php $devMax = $member['max_devices'] !== null ? (int)$member['max_devices'] : null; $devUsed = (int)$member['devices_used']; ?>
            <div>
                <div class="detail-label">Devices</div>
                <div class="detail-value"><span class="<?= $devMax !== null && $devUsed > $devMax ? 'ht-over' : '' ?>"><?= $devUsed ?> / <?= $devMax ?? '&infin;' ?></span>
                    <?= $member['devices_per_person'] !== null ? '<small class="cell-muted">' . (int)$member['devices_per_person'] . ' per person</small>' : '' ?></div>
                <?php if ($devMax !== null && $devUsed > $devMax): ?><div class="ht-help ht-over">Over the package device limit: remove devices or upgrade the package.</div><?php endif; ?>
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
                                    <?= hubinet_speed_cell($device, $canControl, 'member_id=' . $memberId) ?>
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
                <?php
                    // Pick an existing guest (details + their devices move here) or devices online now.
                    $tmGuests = hubinet_people($conn, 'guest');
                    $tmOnline = hubinet_unregistered_online($conn);
                ?>
                <?php if ($tmGuests): ?>
                <div class="form-group tm-pick">
                    <label for="tm_guest_search">From an existing guest <span class="cell-muted">(optional)</span></label>
                    <input id="tm_guest_search" class="form-control" list="tm_guest_list" placeholder="Type a guest's name, phone or email…" autocomplete="off">
                    <datalist id="tm_guest_list">
                        <?php foreach ($tmGuests as $g): ?>
                            <option value="<?= ht_e($g['full_name'] . ($g['phone'] ? ' · ' . $g['phone'] : '') . ($g['email'] ? ' · ' . $g['email'] : '') . ' · #' . $g['person_id']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="from_person_id" id="tm_from_person" value="0">
                    <p class="form-hint" id="tm_guest_note">Fills in their details; their <?= 'registered devices' ?> move to this team member and the guest record is removed.</p>
                </div>
                <script>
                window.tmGuests = <?= json_encode(array_map(static fn($g) => [
                    'id' => (int)$g['person_id'], 'name' => (string)$g['full_name'], 'email' => (string)($g['email'] ?? ''),
                    'phone' => (string)($g['phone'] ?? ''), 'devices' => (int)$g['device_count'],
                ], $tmGuests), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                </script>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group"><label for="tm_name" class="required">Full name</label><input id="tm_name" class="form-control" name="full_name" required maxlength="150"></div>
                    <div class="form-group"><label for="tm_title">Role / job title</label><input id="tm_title" class="form-control" name="job_title" maxlength="120"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="tm_email">Email</label><input id="tm_email" type="email" class="form-control" name="email"></div>
                    <div class="form-group"><label for="tm_phone">Phone</label><input id="tm_phone" class="form-control" name="phone" maxlength="40"></div>
                </div>
                <?= hubinet_device_rows('tm_devices', false, 'Devices (optional)', 1, $tmOnline) ?>
                <div class="ht-help">Laptop and phone Wi-Fi addresses. Ask them to turn off "private / random MAC address" for the hub Wi-Fi so the address stays the same.</div>
                <style>
                .tm-pick { margin-bottom: 12px; }
                .tm-dev { display: flex; width: 100%; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 12px; border: 0; border-bottom: 1px solid var(--border); background: transparent; text-align: left; cursor: pointer; font: inherit; }
                .tm-dev:last-child { border-bottom: 0; }
                .tm-dev:hover { background: var(--brand-50); }
                .tm-dev small { display: block; color: var(--ink-400); font-size: 12px; }
                .tm-dev.is-added { opacity: .45; pointer-events: none; }
                </style>
                <script>
                (function () {
                    var form = document.querySelector('#addTeamModal form');
                    if (!form) return;
                    // Guest picker: the datalist value ends with "#<id>".
                    var gs = document.getElementById('tm_guest_search');
                    if (gs && window.tmGuests) {
                        gs.addEventListener('change', function () {
                            var m = /#(\d+)$/.exec(gs.value), g = m && window.tmGuests.find(function (x) { return String(x.id) === m[1]; });
                            document.getElementById('tm_from_person').value = g ? g.id : 0;
                            if (!g) return;
                            form.querySelector('[name=full_name]').value = g.name;
                            if (g.email) form.querySelector('[name=email]').value = g.email;
                            if (g.phone) form.querySelector('[name=phone]').value = g.phone;
                            document.getElementById('tm_guest_note').textContent = g.name + "'s " + g.devices + ' registered device' + (g.devices === 1 ? '' : 's') + ' will move to this team member; the guest record is removed.';
                        });
                    }
                })();
                </script>
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
            <h3>Add devices</h3>
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
                <?= hubinet_device_rows('dv_devices', true, 'Devices', 1, hubinet_unregistered_online($conn)) ?>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addDeviceModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add devices</button>
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
   OVERVIEW, STAFF, GUESTS, ONLINE NOW, HOTSPOT (includes/hub-team-internet-views.php)
===================================================================== */
elseif (in_array($view, ['overview', 'staff', 'guests', 'online', 'hotspot', 'vouchers'], true)):
    include __DIR__ . '/includes/hub-team-internet-views.php';

/* =====================================================================
   COMPANIES & DEVICES: companies, seats and every registered device
===================================================================== */
else:
    $search = trim((string)($_GET['q'] ?? ''));
    $stateFilter = (string)($_GET['state'] ?? '');
    $where = '1=1';
    $types = '';
    $params = [];

    if ($search !== '') {
        $where .= ' AND (m.company_name LIKE ? OR m.membership_number LIKE ? OR t.full_name LIKE ? OR u.full_name LIKE ? OR hp.full_name LIKE ? OR d.mac_address LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
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
            <i class="fas fa-network-wired" aria-hidden="true"></i> All devices &amp; internet access
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
                <input type="hidden" name="view" value="companies">
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
                    <a href="hub-team?view=companies&amp;tab=devices" class="btn btn-soft"><i class="fas fa-times" aria-hidden="true"></i> Clear</a>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Person</th><th>Company / type</th><th>Device</th><th>Mode</th><th>Speed</th><th>pfSense</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ($devices === []): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-laptop" aria-hidden="true"></i><p>No devices registered<?= $hasDeviceFilter ? ' match the filter' : ' yet. Open a company\'s Team page to add people and devices' ?>.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($devices as $device): $expected = hubnet_state_for_mode(hubnet_desired_mode($device)); ?>
                        <tr>
                            <?php if (($device['owner_kind'] ?? 'member') === 'member'): ?>
                            <td><strong><?= ht_e($device['person_name']) ?></strong><?= empty($device['team_member_id']) ? '<small class="cell-muted">Primary member</small>' : (($device['team_status'] ?? '') !== 'Active' ? '<small class="cell-muted">Suspended</small>' : '') ?></td>
                            <td><a href="hub-team?member_id=<?= (int)$device['member_id'] ?>"><?= ht_e($device['company_name'] ?: $device['primary_name']) ?></a><small class="cell-muted"><?= ht_e($device['membership_status']) ?></small></td>
                            <?php else: ?>
                            <td><a href="hub-team?view=<?= $device['owner_kind'] === 'staff' ? 'staff' : 'guests' ?>&amp;person=<?= (int)$device['person_id'] ?>"><strong><?= ht_e($device['person_name']) ?></strong></a><?= ($device['person_status'] ?? '') !== 'Active' ? '<small class="cell-muted">Suspended</small>' : (!empty($device['person_valid_until']) && strtotime((string)$device['person_valid_until']) < time() ? '<small class="cell-muted">Expired</small>' : '') ?></td>
                            <td><span class="badge <?= $device['owner_kind'] === 'staff' ? 'badge-purple' : 'badge-warning' ?>"><?= $device['owner_kind'] === 'staff' ? 'Staff' : 'Guest' ?></span><?= !empty($device['person_organisation']) ? '<small class="cell-muted">' . ht_e($device['person_organisation']) . '</small>' : '' ?></td>
                            <?php endif; ?>
                            <td><code><?= ht_e($device['mac_address']) ?></code><?= !empty($device['label']) ? '<small class="cell-muted">' . ht_e($device['label']) . '</small>' : '' ?></td>
                            <td><?= ht_e(ht_mode_label((string)$device['access_mode'])) ?></td>
                            <td><?= hubinet_speed_cell($device, $canControl, 'view=companies&tab=devices') ?></td>
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

<?php if ($canControl && $ready): ?>
<!-- Edit a registered device from Online now: label, internet access, speed -->
<div id="hiEditModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hiEditTitle" aria-hidden="true">
    <div class="modal-content" style="max-width:520px">
        <div class="modal-header"><h3 id="hiEditTitle">Edit device</h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hiEditModal')">&times;</button></div>
        <form method="post" action="includes/hub-internet-process.php">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_device">
                <input type="hidden" name="device_id" id="hiEditId">
                <input type="hidden" name="return_query" id="hiEditReturn">
                <p class="cell-muted" style="margin-top:0"><code id="hiEditMac"></code></p>
                <div class="form-group"><label for="hiEditLabel">Device name</label><input id="hiEditLabel" name="label" maxlength="80" class="form-control" placeholder="e.g. Laptop, iPhone"<?= $canManage ? '' : ' disabled' ?>></div>
                <div class="form-group"><label for="hiEditMode">Internet access</label>
                    <select id="hiEditMode" name="access_mode" class="form-control">
                        <option value="auto">Automatic (follows membership / access period)</option>
                        <option value="allow">Always on</option>
                        <option value="block">Disconnected (blocked)</option>
                    </select></div>
                <div class="form-group"><label for="hiEditType">Device type</label>
                    <select id="hiEditType" name="device_type" class="form-control">
                        <option value="">Personal device (phone, laptop…)</option>
                        <?php foreach (HUBINET_DEVICE_TYPES as $tk => $tl): ?><option value="<?= $tk ?>"><?= ht_e($tl) ?></option><?php endforeach; ?>
                    </select>
                    <p class="form-hint">Equipment is listed under Online now &gt; Equipment instead of with people's devices.</p></div>
                <div class="form-row">
                    <div class="form-group"><label for="hiEditDown"><i class="fas fa-arrow-down" aria-hidden="true"></i> Download (Mbps)</label><input id="hiEditDown" name="bw_down" type="number" min="0.064" max="10000" step="any" class="form-control" placeholder="No limit"></div>
                    <div class="form-group"><label for="hiEditUp"><i class="fas fa-arrow-up" aria-hidden="true"></i> Upload (Mbps)</label><input id="hiEditUp" name="bw_up" type="number" min="0.064" max="10000" step="any" class="form-control" placeholder="No limit"></div>
                </div>
                <p class="form-hint">To give the device to someone else, remove it from its owner's page and register it again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('hiEditModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Speed limit for one device or several (hubinet_speed_cell / hiSpeedBulk) -->
<div id="hiSpeedModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hiSpeedTitle" aria-hidden="true">
    <div class="modal-content" style="max-width:460px">
        <div class="modal-header"><h3 id="hiSpeedTitle">Speed limit</h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hiSpeedModal')">&times;</button></div>
        <form method="post" action="includes/hub-internet-process.php" id="hiSpeedForm">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="device_speed">
                <input type="hidden" name="return_query" id="hiSpeedReturn" value="">
                <div id="hiSpeedIds"></div>
                <p class="cell-muted" id="hiSpeedFor" style="margin-top:0"></p>
                <ul id="hiSpeedList" hidden></ul>
                <p class="form-hint" id="hiSpeedSkipped" hidden></p>
                <div class="form-row">
                    <div class="form-group"><label for="hiSpeedDown"><i class="fas fa-arrow-down" aria-hidden="true"></i> Download (Mbps)</label><input id="hiSpeedDown" name="bw_down" type="number" min="0.064" max="10000" step="any" class="form-control" placeholder="No limit"></div>
                    <div class="form-group"><label for="hiSpeedUp"><i class="fas fa-arrow-up" aria-hidden="true"></i> Upload (Mbps)</label><input id="hiSpeedUp" name="bw_up" type="number" min="0.064" max="10000" step="any" class="form-control" placeholder="No limit"></div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
                    <?php foreach ([[2, 1], [5, 2], [10, 5], [20, 10], [50, 20]] as [$pd, $pu]): ?>
                        <button type="button" class="btn btn-sm btn-soft" onclick="hiSpeedPreset(<?= $pd ?>, <?= $pu ?>)"><?= $pd ?>/<?= $pu ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-sm btn-soft" onclick="hiSpeedPreset('', '')">No limit</button>
                </div>
                <p class="form-hint">Leave a box empty for no limit in that direction. pfSense applies the change at its next check-in (about a minute). Needs pfSense agent 2.2.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('hiSpeedModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save speed</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    function open(ids, name, down, up, ret, names, skipped) {
        var box = document.getElementById('hiSpeedIds');
        box.innerHTML = '';
        ids.forEach(function (id) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; box.appendChild(i); });
        document.getElementById('hiSpeedTitle').textContent = ids.length > 1 ? 'Speed limit for ' + ids.length + ' devices' : 'Speed limit';
        document.getElementById('hiSpeedFor').textContent = name;
        var list = document.getElementById('hiSpeedList');
        list.innerHTML = '';
        (names || []).slice(0, 8).forEach(function (n) { var li = document.createElement('li'); li.textContent = n; list.appendChild(li); });
        if (names && names.length > 8) { var more = document.createElement('li'); more.textContent = 'and ' + (names.length - 8) + ' more'; list.appendChild(more); }
        list.hidden = !(names && names.length > 1);
        var skip = document.getElementById('hiSpeedSkipped');
        skip.textContent = skipped ? skipped + ' unregistered device' + (skipped === 1 ? ' is' : 's are') + ' skipped: register them first to limit their speed.' : '';
        skip.hidden = !skipped;
        document.getElementById('hiSpeedDown').value = down || '';
        document.getElementById('hiSpeedUp').value = up || '';
        document.getElementById('hiSpeedReturn').value = ret || '';
        openModal('hiSpeedModal');
    }
    window.hiSpeed = function (btn) {
        open([btn.dataset.ids], 'Device ' + btn.dataset.name, btn.dataset.down, btn.dataset.up, btn.dataset.return);
    };
    /* Speed limit for the selected rows: registered devices only (unknown ones are reported as skipped). */
    window.hiSpeedBulk = function (btn, ret) {
        var scope = btn.closest('[data-bulk-scope]') || btn.closest('form') || document;
        var picked = Array.prototype.slice.call(scope.querySelectorAll('input.hr-sel[name="ids[]"]:checked'));
        var skipped = scope.querySelectorAll('input.hr-sel[name="macs[]"]:checked').length;
        if (!picked.length) {
            hrAlert('Speed limits apply to registered devices. ' + (skipped ? 'The selected device' + (skipped === 1 ? ' is' : 's are') + ' not registered: use Register first.' : 'Select at least one registered device.'), { title: 'No registered devices selected' });
            return;
        }
        var ids = picked.map(function (c) { return c.value; });
        var names = picked.map(function (c) { return c.dataset.name || (c.getAttribute('aria-label') || '').replace(/^Select /, ''); });
        // Pre-fill when every selected device has the same limit.
        var same = function (k) { var v = picked[0].dataset[k] || ''; return picked.every(function (c) { return (c.dataset[k] || '') === v; }) ? v : ''; };
        open(ids, ids.length + ' selected device' + (ids.length === 1 ? '' : 's') + ' will get this limit:', same('down'), same('up'), ret, names, skipped);
    };
    window.hiEditDevice = function (btn) {
        var d = btn.dataset;
        document.getElementById('hiEditId').value = d.id;
        document.getElementById('hiEditMac').textContent = d.mac + (d.owner ? ' · ' + d.owner : '');
        document.getElementById('hiEditLabel').value = d.label || '';
        document.getElementById('hiEditMode').value = d.mode || 'auto';
        document.getElementById('hiEditType').value = d.type || '';
        document.getElementById('hiEditDown').value = d.down || '';
        document.getElementById('hiEditUp').value = d.up || '';
        var q = new URLSearchParams(location.search);
        q.delete('view');
        document.getElementById('hiEditReturn').value = 'view=online' + (q.toString() ? '&' + q.toString() : '');
        openModal('hiEditModal');
    };
    window.hiSpeedPreset = function (down, up) {
        document.getElementById('hiSpeedDown').value = down;
        document.getElementById('hiSpeedUp').value = up;
    };
})();
</script>
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
