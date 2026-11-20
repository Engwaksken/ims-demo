<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HR Operations (HR / Administrators)
|--------------------------------------------------------------------------
| view=overview   headline numbers, items needing attention, interviews.
| view=employees  every active staff member: profile status, required
|                 documents, policy acknowledgements, last reminder. Edit
|                 employment details, approve / reject submitted profiles,
|                 send reminders, share policies.
| view=reminders  manual reminders to everyone outstanding, automatic
|                 reminder settings (cron/hr-reminders.php), recent log.
| view=policies   policy library: upload / new version, share with staff,
|                 new-staff pack, acknowledgement tracking (?policy=ID).
| Logic in includes/hr.php.
*/

$page_title = 'HR Operations';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hr.php';
check_login();

if (!hr_can_manage()) {
    $_SESSION['notification'] = ['message' => 'You are not authorized to access that page.', 'type' => 'danger'];
    header('Location: ' . dashboard_url());
    exit;
}

$uid = (int)$_SESSION['user_id'];
$e = 'hr_h';

if (!hr_ensure_schema($conn)) {
    include 'includes/header.php';
    echo '<div class="card"><div class="card-body"><div class="alert alert-warning">HR Operations is unavailable: the HR tables could not be created. Ask an Administrator to run database/migrations/2026_11_20_hr_module.sql.</div></div></div>';
    include 'includes/footer.php';
    exit;
}

$views = ['overview' => 'Overview', 'employees' => 'Employees', 'reminders' => 'Reminders', 'policies' => 'Policies'];
$view = array_key_exists((string)($_GET['view'] ?? ''), $views) ? (string)$_GET['view'] : 'overview';

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }
    $action = (string)($_POST['action'] ?? '');
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($action === 'remind_outstanding') {
        $ids = array_map('intval', array_column(hr_employees($conn, ['compliance' => 'non_compliant']), 'user_id'));
    }
    if ($action === 'policy_share') {
        $audience = (string)($_POST['audience'] ?? 'selected');
        if ($audience === 'all') {
            $ids = array_map('intval', array_column(hr_staff_users($conn), 'user_id'));
        } elseif ($audience === 'department') {
            $dept = (int)($_POST['department_id'] ?? 0);
            $ids = array_map('intval', array_column(array_filter(hr_employees($conn), fn($r) => (int)$r['department_id'] === $dept), 'user_id'));
        }
    }

    $result = match ($action) {
        'update_employee' => hr_update_employee($conn, (int)($_POST['employee_id'] ?? 0), $_POST, $uid),
        'remind', 'remind_outstanding' => hr_send_reminders($conn, $ids, (string)($_POST['kind'] ?? 'all'), $note, $uid),
        'settings' => hr_save_settings($conn, $_POST, $uid),
        'policy_save' => hr_save_policy($conn, $_POST, $_FILES['policy_file'] ?? null, $uid),
        'policy_share' => hr_share_policies($conn, (array)($_POST['policy_ids'] ?? []), $ids, $uid, $note, !empty($_POST['email'])),
        'autoshare_now' => hr_result(true, hr_autoshare_new_staff($conn) . ' new-staff policy shares sent.'),
        'interviews_bulk' => hr_interviews_bulk($conn, $ids, (string)($_POST['op'] ?? ''), $uid),
        'policies_bulk' => hr_set_policies_active($conn, (array)($_POST['policy_ids'] ?? []), ($_POST['op'] ?? '') === 'activate', $uid),
        default => hr_result(false, 'Unknown action.'),
    };
    $_SESSION['notification'] = ['message' => $result['message'], 'type' => $result['ok'] ? 'success' : 'danger'];

    $back = array_key_exists((string)($_POST['return_view'] ?? ''), $views) ? (string)$_POST['return_view'] : $view;
    $qs = 'view=' . $back;
    if ((int)($_POST['return_policy'] ?? 0) > 0) {
        $qs .= '&policy=' . (int)$_POST['return_policy'];
    }
    if (in_array($_POST['return_tab'] ?? '', ['attention', 'interviews', 'policies', 'send', 'settings', 'log'], true)) {
        $qs .= '&tab=' . $_POST['return_tab'];
    }
    header('Location: hr-operations?' . $qs, true, 303);
    exit;
}

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
hr_autoshare_new_staff($conn);
$settings = hr_settings($conn);
$stats = hr_overview_stats($conn);
$departments = hr_departments($conn);
$staff = hr_staff_users($conn);
$policies = hr_policies($conn);
$activePolicies = array_values(array_filter($policies, fn($p) => (int)$p['is_active'] === 1));

$filters = [
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'department' => (int)($_GET['department'] ?? 0),
    'compliance' => (string)($_GET['compliance'] ?? ''),
];
$employees = $view === 'employees' ? hr_employees($conn, $filters) : [];
$attention = $view === 'overview' ? hr_employees($conn, ['compliance' => 'non_compliant']) : [];
$policyId = (int)($_GET['policy'] ?? 0);
$policy = $view === 'policies' && $policyId > 0 ? hr_policy($conn, $policyId) : null;
$overviewTabs = ['attention' => 'Needs attention', 'interviews' => 'Upcoming interviews', 'policies' => 'Policies'];
$overviewTab = array_key_exists((string)($_GET['tab'] ?? ''), $overviewTabs) ? (string)$_GET['tab'] : 'attention';
$upcoming = $view === 'overview' ? hr_upcoming_interviews($conn, 100) : [];
$remTabs = ['send' => ['Send reminders now', 'fa-paper-plane'], 'settings' => ['Reminder settings', 'fa-gear'], 'log' => ['Recent reminders', 'fa-clock-rotate-left']];
$remTab = array_key_exists((string)($_GET['tab'] ?? ''), $remTabs) ? (string)$_GET['tab'] : 'send';
$reminderLog = $view === 'reminders' ? hr_reminder_log($conn, 500) : [];
$canApprove = ims_allowed('employees.approve', ['Administrator'], 'Approve or reject employee profiles');

$complianceOptions = [
    '' => 'Everyone', 'non_compliant' => 'Anything outstanding', 'no_profile' => 'No profile yet', 'missing_docs' => 'Missing documents',
    'pending_policies' => 'Policies not acknowledged', 'pending_approval' => 'Profile awaiting approval', 'compliant' => 'All complete',
];

include 'includes/header.php';
?>
<link rel="stylesheet" href="css/hr.css">
<script src="js/hr-ui.js"></script>

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-people-roof" aria-hidden="true"></i> HR Operations</h1>
            <p>Manage the employee directory, chase outstanding documents and policies, share organisation policies and run recruitment.</p>
        </div>
        <div class="hero-actions">
            <a href="recruitment" class="btn"><i class="fas fa-user-check" aria-hidden="true"></i> Recruitment</a>
            <a href="jobs" class="btn"><i class="fas fa-briefcase" aria-hidden="true"></i> Manage jobs</a>
            <button type="button" class="btn btn-primary" onclick="hrOpenPolicy()"><i class="fas fa-file-circle-plus" aria-hidden="true"></i> Add policy</button>
        </div>
    </div>

    <div class="card hr-tabbar">
        <nav class="tab-nav" aria-label="HR views">
            <?php foreach ($views as $key => $label):
                $icon = ['overview' => 'fa-gauge', 'employees' => 'fa-users', 'reminders' => 'fa-bell', 'policies' => 'fa-file-shield'][$key];
                $badge = match ($key) { 'employees' => $stats['staff'] - $stats['compliant'], 'policies' => count($activePolicies), default => 0 }; ?>
                <a class="tab-btn<?= $view === $key ? ' active' : '' ?>" href="hr-operations?view=<?= $key ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= $e($label) ?><?php if ($badge): ?> <span class="badge"><?= $badge ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

<?php if ($view === 'overview'): ?>
    <div class="stats-grid reports-stats">
        <div class="stat-card" data-stat-href="hr-operations?view=employees"><div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Active staff</span><span class="stat-value"><?= $stats['staff'] ?></span></div></div>
        <div class="stat-card" data-stat-href="hr-operations?view=employees&amp;compliance=compliant"><div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Fully compliant</span><span class="stat-value"><?= $stats['compliant'] ?></span></div></div>
        <div class="stat-card" data-stat-href="hr-operations?view=employees&amp;compliance=missing_docs"><div class="stat-icon bg-amber"><i class="fas fa-file-circle-exclamation" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Missing documents</span><span class="stat-value"><?= $stats['missing_docs'] ?></span></div></div>
        <div class="stat-card" data-stat-href="hr-operations?view=employees&amp;compliance=pending_policies"><div class="stat-icon bg-red"><i class="fas fa-file-signature" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Policies to acknowledge</span><span class="stat-value"><?= $stats['pending_policies'] ?></span></div></div>
        <div class="stat-card" data-stat-href="hr-operations?view=employees&amp;compliance=no_profile"><div class="stat-icon bg-slate"><i class="fas fa-id-card" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">No profile yet</span><span class="stat-value"><?= $stats['no_profile'] ?></span></div></div>
        <div class="stat-card" data-stat-href="hr-operations?view=employees&amp;compliance=pending_approval"><div class="stat-icon bg-blue"><i class="fas fa-hourglass-half" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Profiles to approve</span><span class="stat-value"><?= $stats['pending_approval'] ?></span></div></div>
        <div class="stat-card" data-stat-href="recruitment"><div class="stat-icon bg-teal"><i class="fas fa-briefcase" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Open jobs · applications this month</span><span class="stat-value"><?= $stats['open_jobs'] ?> · <?= $stats['applications_month'] ?></span></div></div>
        <div class="stat-card" data-stat-href="hr-operations?view=overview&amp;tab=interviews"><div class="stat-icon bg-purple"><i class="fas fa-calendar-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Interviews ahead · scorecards due</span><span class="stat-value"><?= $stats['upcoming_interviews'] ?> · <?= $stats['scorecards_due'] ?></span></div></div>
    </div>

    <div class="card">
        <nav class="tab-nav hr-subtabs" role="tablist" aria-label="Overview sections">
            <?php foreach ($overviewTabs as $key => $label):
                $icon = ['attention' => 'fa-triangle-exclamation', 'interviews' => 'fa-calendar-day', 'policies' => 'fa-file-shield'][$key];
                $count = match ($key) { 'attention' => count($attention), 'interviews' => count($upcoming), 'policies' => count($activePolicies) }; ?>
                <button type="button" class="tab-btn<?= $overviewTab === $key ? ' active' : '' ?>" role="tab" id="ovTab-<?= $key ?>" aria-controls="ovPanel-<?= $key ?>"
                        aria-selected="<?= $overviewTab === $key ? 'true' : 'false' ?>" tabindex="<?= $overviewTab === $key ? '0' : '-1' ?>" data-ov-tab="<?= $key ?>">
                    <i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= $e($label) ?> <span class="badge"><?= $count ?></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <!-- Needs attention -->
        <section class="card-body hr-subpanel" role="tabpanel" id="ovPanel-attention" aria-labelledby="ovTab-attention"<?= $overviewTab === 'attention' ? '' : ' hidden' ?>>
            <?php if (!$attention): ?>
                <div class="empty-state"><i class="fas fa-mug-hot" aria-hidden="true"></i><p>Every member of staff is up to date.</p></div>
            <?php else: ?>
                <form method="POST" action="hr-operations" data-bulk-scope>
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_view" value="overview">
                    <input type="hidden" name="return_tab" value="attention">
                    <div class="table-responsive">
                        <table class="table table-stack hr-table">
                            <thead><tr>
                                <th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all staff"></th>
                                <th>Staff</th><th>Department</th><th>Profile</th><th>Missing documents</th><th>Policies to acknowledge</th><th>Last reminded</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($attention as $r): ?>
                                <tr>
                                    <td data-label="Select"><input type="checkbox" class="hr-sel" name="ids[]" value="<?= (int)$r['user_id'] ?>" aria-label="Select <?= $e($r['name']) ?>"></td>
                                    <td data-label="Staff"><strong><?= $e($r['name']) ?></strong><div class="hr-muted"><?= $e($r['job_title'] ?: $r['role']) ?></div></td>
                                    <td data-label="Department"><?= $e($r['department_name'] ?: '-') ?></td>
                                    <td data-label="Profile"><?php if (!$r['has_profile']): ?><span class="badge badge-secondary">Not started</span><?php else: ?><span class="badge <?= match ($r['profile_status']) { 'Approved' => 'badge-success', 'Submitted' => 'badge-info', 'Rejected' => 'badge-danger', default => 'badge-secondary' } ?>"><?= $e($r['profile_status']) ?></span><?php endif; ?></td>
                                    <td data-label="Missing documents"><?= $r['missing_docs'] ? $e(implode(', ', $r['missing_docs'])) : '<span class="hr-muted">None</span>' ?></td>
                                    <td data-label="Policies to acknowledge"><?= $r['policies_pending'] > 0 ? '<span class="badge badge-danger">' . (int)$r['policies_pending'] . '</span>' : '<span class="hr-muted">None</span>' ?></td>
                                    <td data-label="Last reminded"><?= $r['last_reminded'] ? $e(date('j M Y', strtotime((string)$r['last_reminded']))) : '<span class="hr-muted">Never</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="hr-bulkbar" data-bulk-bar>
                        <span data-bulk-count>None selected</span>
                        <label class="sr-only" for="ovKind">Reminder about</label>
                            <select id="ovKind" name="kind" class="form-control">
                                <option value="all">About everything outstanding</option>
                                <option value="documents">About profile &amp; documents</option>
                                <option value="policies">About policies</option>
                            </select>
                        <button type="submit" name="action" value="remind" class="btn btn-primary btn-sm" data-bulk-required="Select the staff to remind first."
                                data-confirm="Send a reminder to the selected staff?" data-confirm-title="Send reminders" data-confirm-ok="Send reminders"><i class="fas fa-bell" aria-hidden="true"></i> Remind selected</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-bulk-required="Select the staff to share policies with first." onclick="hrOpenShare([], hrSelected(this))"><i class="fas fa-share-nodes" aria-hidden="true"></i> Share policies</button>
                        <button type="submit" name="action" value="remind_outstanding" class="btn btn-soft btn-sm" formnovalidate
                                data-confirm="Send a reminder to all <?= count($attention) ?> staff with something outstanding?" data-confirm-title="Remind everyone" data-confirm-ok="Remind all"><i class="fas fa-bullhorn" aria-hidden="true"></i> Remind all (<?= count($attention) ?>)</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <!-- Upcoming interviews -->
        <section class="card-body hr-subpanel" role="tabpanel" id="ovPanel-interviews" aria-labelledby="ovTab-interviews"<?= $overviewTab === 'interviews' ? '' : ' hidden' ?>>
            <?php if (!$upcoming): ?>
                <div class="empty-state"><i class="fas fa-calendar" aria-hidden="true"></i><p>No interviews scheduled. <a href="recruitment">Open Recruitment</a> to schedule one.</p></div>
            <?php else: ?>
                <form method="POST" action="hr-operations" data-bulk-scope>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="interviews_bulk">
                    <input type="hidden" name="return_view" value="overview">
                    <input type="hidden" name="return_tab" value="interviews">
                    <div class="table-responsive">
                        <table class="table table-stack hr-table">
                            <thead><tr>
                                <th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all interviews"></th>
                                <th>Candidate</th><th>Position</th><th>When</th><th>Format</th><th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($upcoming as $u): ?>
                                <tr>
                                    <td data-label="Select"><input type="checkbox" class="hr-sel" name="ids[]" value="<?= (int)$u['interview_id'] ?>" aria-label="Select interview with <?= $e($u['applicant_name']) ?>"></td>
                                    <td data-label="Candidate"><strong><?= $e($u['applicant_name']) ?></strong></td>
                                    <td data-label="Position"><a href="recruitment?job_id=<?= (int)$u['job_id'] ?>&view=interviews"><?= $e($u['job_title']) ?></a></td>
                                    <td data-label="When"><?= $e(date('D j M Y, H:i', strtotime((string)$u['scheduled_at']))) ?></td>
                                    <td data-label="Format"><?= $e($u['mode']) ?></td>
                                    <td data-label=""><a class="btn btn-sm btn-soft" href="interview-scorecard?id=<?= (int)$u['interview_id'] ?>">Scorecards</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="hr-bulkbar" data-bulk-bar>
                        <span data-bulk-count>None selected</span>
                        <button type="submit" name="op" value="remind" class="btn btn-primary btn-sm" data-bulk-required="Select the interviews first."
                                data-confirm="Remind the panel members who have not scored the selected interviews?" data-confirm-title="Remind panels" data-confirm-ok="Send reminders"><i class="fas fa-bell" aria-hidden="true"></i> Remind panels</button>
                        <button type="submit" name="op" value="complete" class="btn btn-secondary btn-sm" data-bulk-required="Select the interviews first."
                                data-confirm="Mark the selected interviews as completed?" data-confirm-title="Mark completed" data-confirm-ok="Mark completed"><i class="fas fa-check" aria-hidden="true"></i> Mark completed</button>
                        <button type="submit" name="op" value="cancel" class="btn btn-danger btn-sm" data-bulk-required="Select the interviews first."
                                data-confirm="Cancel the selected interviews? Candidates and panel members will be told." data-confirm-title="Cancel interviews" data-confirm-ok="Cancel interviews" data-confirm-danger><i class="fas fa-xmark" aria-hidden="true"></i> Cancel</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <!-- Policies -->
        <section class="card-body hr-subpanel" role="tabpanel" id="ovPanel-policies" aria-labelledby="ovTab-policies"<?= $overviewTab === 'policies' ? '' : ' hidden' ?>>
            <?php if (!$activePolicies): ?>
                <div class="empty-state"><i class="fas fa-file-shield" aria-hidden="true"></i><p>No policies yet. <a href="#" onclick="hrOpenPolicy();return false">Add your first policy</a>.</p></div>
            <?php else: ?>
                <form method="POST" action="hr-operations" data-bulk-scope>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="policies_bulk">
                    <input type="hidden" name="return_view" value="overview">
                    <input type="hidden" name="return_tab" value="policies">
                    <div class="table-responsive">
                        <table class="table table-stack hr-table">
                            <thead><tr>
                                <th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all policies"></th>
                                <th>Policy</th><th>Category</th><th>Shared with</th><th>Opened</th><th>Acknowledged</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($activePolicies as $p): $pct = (int)$p['shared'] ? round((int)$p['acked'] / (int)$p['shared'] * 100) : 0; ?>
                                <tr>
                                    <td data-label="Select"><input type="checkbox" class="hr-sel" name="policy_ids[]" value="<?= (int)$p['policy_id'] ?>" aria-label="Select <?= $e($p['title']) ?>"></td>
                                    <td data-label="Policy"><a class="hr-name" href="hr-operations?view=policies&policy=<?= (int)$p['policy_id'] ?>"><?= $e($p['title']) ?></a></td>
                                    <td data-label="Category"><?= $e($p['category']) ?></td>
                                    <td data-label="Shared with"><?= (int)$p['shared'] ?> staff</td>
                                    <td data-label="Opened"><?= (int)$p['viewed'] ?></td>
                                    <td data-label="Acknowledged"><?= (int)$p['requires_ack'] ? (int)$p['acked'] . ' (' . $pct . '%)' : '<span class="hr-muted">Not required</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="hr-bulkbar" data-bulk-bar>
                        <span data-bulk-count>None selected</span>
                        <button type="button" class="btn btn-primary btn-sm" data-bulk-required="Select the policies to share first." onclick="hrOpenShare(hrSelected(this))"><i class="fas fa-share-nodes" aria-hidden="true"></i> Share selected</button>
                        <button type="submit" name="op" value="archive" class="btn btn-danger btn-sm" data-bulk-required="Select the policies to archive first."
                                data-confirm="Archive the selected policies? Staff will no longer see them in My HR." data-confirm-title="Archive policies" data-confirm-ok="Archive" data-confirm-danger><i class="fas fa-box-archive" aria-hidden="true"></i> Archive</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>

<?php elseif ($view === 'employees'): ?>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-users" aria-hidden="true"></i> Employee directory</h3><span class="hr-muted"><?= count($employees) ?> staff shown</span></div>
        <div class="card-body">
            <form method="GET" action="hr-operations" class="filters-bar" role="search">
                <input type="hidden" name="view" value="employees">
                <div class="form-group filters-grow">
                    <label for="hrEmpQ" class="sr-only">Search</label>
                    <div class="search-input-wrap"><i class="fas fa-search" aria-hidden="true"></i><input id="hrEmpQ" type="search" name="q" class="form-control" value="<?= $e($filters['q']) ?>" placeholder="Search name, email, title or department"></div>
                </div>
                <div class="form-group"><label for="hrEmpDept" class="sr-only">Department</label>
                    <select id="hrEmpDept" name="department" class="form-control"><option value="">All departments</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>"<?= $filters['department'] === (int)$d['department_id'] ? ' selected' : '' ?>><?= $e($d['department_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label for="hrEmpComp" class="sr-only">Compliance</label>
                    <select id="hrEmpComp" name="compliance" class="form-control">
                        <?php foreach ($complianceOptions as $k => $lbl): ?><option value="<?= $e($k) ?>"<?= $filters['compliance'] === $k ? ' selected' : '' ?>><?= $e($lbl) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="filters-actions">
                    <button class="btn btn-info"><i class="fas fa-filter" aria-hidden="true"></i> Filter</button>
                    <a href="hr-operations?view=employees" class="btn btn-secondary">Clear</a>
                </div>
            </form>

            <form method="POST" action="hr-operations" id="hrEmpForm" data-bulk-scope>
                <?= csrf_field() ?>
                <input type="hidden" name="return_view" value="employees">
                <?php if (!$employees): ?>
                    <div class="empty-state"><i class="fas fa-users" aria-hidden="true"></i><p>No staff match these filters.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack hr-table">
                        <thead><tr>
                            <th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all staff"></th>
                            <th>Staff</th><th>Department</th><th>Profile</th><th>Documents</th><th>Policies</th><th>Reminded</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($employees as $r): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="ids[]" value="<?= (int)$r['user_id'] ?>" class="hr-sel" aria-label="Select <?= $e($r['name']) ?>"></td>
                                <td data-label="Staff">
                                    <strong><?= $e($r['name']) ?></strong>
                                    <div class="hr-muted"><?= $e($r['job_title'] ?: $r['role']) ?> · <?= $e($r['company_email'] ?: $r['email']) ?></div>
                                    <?php if ($r['supervisor_name']): ?><div class="hr-muted">Reports to <?= $e($r['supervisor_name']) ?></div><?php endif; ?>
                                </td>
                                <td data-label="Department"><?= $e($r['department_name'] ?: '-') ?><div class="hr-muted"><?= $e($r['contract_type'] ?: '') ?></div></td>
                                <td data-label="Profile">
                                    <?php if (!$r['has_profile']): ?><span class="badge badge-secondary">Not started</span>
                                    <?php else: ?><span class="badge <?= match ($r['profile_status']) { 'Approved' => 'badge-success', 'Submitted' => 'badge-info', 'Rejected' => 'badge-danger', default => 'badge-secondary' } ?>"><?= $e($r['profile_status']) ?></span><?php endif; ?>
                                </td>
                                <td data-label="Documents">
                                    <span class="<?= $r['missing_docs'] ? 'text-warning' : 'text-success' ?>" title="<?= $r['missing_docs'] ? 'Missing: ' . $e(implode(', ', $r['missing_docs'])) : 'All required documents uploaded' ?>"><strong><?= $r['docs_done'] ?>/<?= $r['docs_total'] ?></strong></span>
                                    <?php if ($r['missing_docs']): ?><div class="hr-muted"><?= $e(implode(', ', $r['missing_docs'])) ?></div><?php endif; ?>
                                </td>
                                <td data-label="Policies"><?= (int)$r['policies_shared'] ? '<strong>' . (int)$r['policies_acked'] . '/' . (int)$r['policies_shared'] . '</strong> acknowledged' : '<span class="hr-muted">None shared</span>' ?></td>
                                <td data-label="Reminded"><?= $r['last_reminded'] ? $e(date('j M', strtotime((string)$r['last_reminded']))) : '<span class="hr-muted">Never</span>' ?></td>
                                <td data-label="Actions">
                                    <div class="hr-inline">
                                        <?php if ($r['has_profile']): ?>
                                            <a class="btn btn-sm btn-soft" href="employee-profile-view?id=<?= (int)$r['employee_id'] ?>" title="View profile"><i class="fas fa-eye" aria-hidden="true"></i></a>
                                            <button type="button" class="btn btn-sm btn-soft" title="Edit employment details" onclick='hrOpenEmp(<?= json_encode([
                                                'employee_id' => (int)$r['employee_id'], 'name' => $r['name'], 'job_title' => (string)$r['job_title'],
                                                'department_id' => (int)$r['department_id'], 'supervisor_id' => (int)$r['supervisor_id'],
                                                'contract_type' => (string)$r['contract_type'], 'start_date' => (string)$r['start_date'],
                                            ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fas fa-pen" aria-hidden="true"></i></button>
                                            <?php if ($canApprove && $r['profile_status'] === 'Submitted'): ?>
                                                <button type="button" class="btn btn-sm btn-success" title="Approve or reject profile" onclick='hrOpenApprove(<?= (int)$r['employee_id'] ?>, <?= json_encode($r['name'], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fas fa-check" aria-hidden="true"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (!$r['compliant']): ?>
                                            <button type="button" class="btn btn-sm btn-soft" title="Send reminder" onclick="hrRemindOne(this, <?= (int)$r['user_id'] ?>, <?= $e(json_encode($r['name'], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"><i class="fas fa-bell" aria-hidden="true"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="hr-bulkbar" data-bulk-bar>
                    <span data-bulk-count>None selected</span>
                    <label class="sr-only" for="hrKind">Reminder about</label>
                            <select id="hrKind" name="kind" class="form-control">
                                <option value="all">About everything outstanding</option>
                                <option value="documents">About profile &amp; documents</option>
                                <option value="policies">About policies</option>
                            </select>
                    <button type="submit" name="action" value="remind" class="btn btn-primary btn-sm" data-bulk-required="Select the staff to remind first."
                            data-confirm="Send a reminder to the selected staff?" data-confirm-title="Send reminders" data-confirm-ok="Send reminders"><i class="fas fa-bell" aria-hidden="true"></i> Remind selected</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bulk-required="Select the staff to share policies with first." onclick="hrOpenShare([], hrSelected(this))"><i class="fas fa-share-nodes" aria-hidden="true"></i> Share policies</button>
                    <span class="hr-muted">Documents required: <?= $e(implode(', ', array_map(fn($c) => HR_DOCS[$c], $settings['required_docs_list'])) ?: 'none') ?></span>
                </div>
                <label for="hrEmpNote" class="sr-only">Note</label>
                <textarea id="hrEmpNote" name="note" class="form-control" rows="2" placeholder="Optional note added to the reminder" style="margin-top:8px"></textarea>
                <?php endif; ?>
            </form>
        </div>
    </div>

<?php elseif ($view === 'reminders'): ?>
    <div class="card">
        <nav class="tab-nav hr-subtabs" role="tablist" aria-label="Reminder sections">
            <?php foreach ($remTabs as $key => [$label, $icon]): ?>
                <button type="button" class="tab-btn<?= $remTab === $key ? ' active' : '' ?>" role="tab" id="ovTab-<?= $key ?>" aria-controls="ovPanel-<?= $key ?>"
                        aria-selected="<?= $remTab === $key ? 'true' : 'false' ?>" tabindex="<?= $remTab === $key ? '0' : '-1' ?>" data-ov-tab="<?= $key ?>">
                    <i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= $e($label) ?><?php if ($key === 'log' && $reminderLog): ?> <span class="badge"><?= count($reminderLog) ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
        </nav>
        <section class="card-body hr-subpanel" role="tabpanel" id="ovPanel-send" aria-labelledby="ovTab-send"<?= $remTab === 'send' ? '' : ' hidden' ?>>
            <form method="POST" action="hr-operations" data-confirm="Send reminders to everyone with something outstanding?" data-confirm-title="Send reminders" data-confirm-ok="Send reminders">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remind_outstanding">
                <input type="hidden" name="return_view" value="reminders">
                <input type="hidden" name="return_tab" value="send">
                <p class="hr-muted"><?= $stats['staff'] - $stats['compliant'] ?> staff have something outstanding. Each gets one message (in-app and email) listing exactly what is missing; staff with nothing outstanding for the chosen topic are skipped.</p>
                <div class="hr-form-grid">
                    <div class="form-group"><label for="hrRemKind">About</label>
                        <select id="hrRemKind" name="kind" class="form-control">
                            <option value="all">Everything outstanding</option>
                            <option value="documents">Profile and documents only</option>
                            <option value="policies">Policies only</option>
                        </select>
                    </div>
                    <div class="form-group"><label for="hrRemNote">Note (optional)</label><textarea id="hrRemNote" name="note" class="form-control" rows="2" placeholder="e.g. Please complete this before payroll on the 25th."></textarea></div>
                </div>
                <button class="btn btn-primary"><i class="fas fa-bell" aria-hidden="true"></i> Send reminders</button>
            </form>
        </section>

        <section class="card-body hr-subpanel" role="tabpanel" id="ovPanel-settings" aria-labelledby="ovTab-settings"<?= $remTab === 'settings' ? '' : ' hidden' ?>>
            <form method="POST" action="hr-operations">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="settings">
                <input type="hidden" name="return_view" value="reminders">
                <input type="hidden" name="return_tab" value="settings">
                <fieldset class="form-group">
                    <legend>Documents every member of staff must upload</legend>
                    <?php foreach (HR_DOCS as $col => $lbl): ?>
                        <label class="hr-check" style="display:flex"><input type="checkbox" name="required_docs[]" value="<?= $e($col) ?>"<?= in_array($col, $settings['required_docs_list'], true) ? ' checked' : '' ?>> <?= $e($lbl) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset class="form-group">
                    <legend>Automatic reminders</legend>
                    <label class="hr-check" style="display:flex"><input type="checkbox" name="auto_reminders" value="1"<?= (int)$settings['auto_reminders'] ? ' checked' : '' ?>> Remind staff with anything outstanding automatically</label>
                    <div class="hr-inline" style="margin-top:6px"><label for="hrEvery">Every</label><input id="hrEvery" type="number" name="remind_every_days" min="1" max="90" value="<?= (int)$settings['remind_every_days'] ?>" class="form-control" style="width:90px"><span>days</span></div>
                    <label class="hr-check" style="display:flex;margin-top:6px"><input type="checkbox" name="send_email" value="1"<?= (int)$settings['send_email'] ? ' checked' : '' ?>> Email automatic reminders too (not only in-app)</label>
                    <p class="form-hint">Needs the daily cron job: <code>0 8 * * * php legacy/cron/hr-reminders.php</code></p>
                </fieldset>
                <fieldset class="form-group">
                    <legend>New staff</legend>
                    <label class="hr-check" style="display:flex"><input type="checkbox" name="autoshare_new_staff" value="1"<?= (int)$settings['autoshare_new_staff'] ? ' checked' : '' ?>> Share “new staff” policies automatically with new accounts</label>
                    <div class="hr-inline" style="margin-top:6px"><label for="hrNewDays">Accounts created in the last</label><input id="hrNewDays" type="number" name="new_staff_days" min="1" max="365" value="<?= (int)$settings['new_staff_days'] ?>" class="form-control" style="width:90px"><span>days</span></div>
                </fieldset>
                <button class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save settings</button>
            </form>
        </section>

        <section class="card-body hr-subpanel" role="tabpanel" id="ovPanel-log" aria-labelledby="ovTab-log"<?= $remTab === 'log' ? '' : ' hidden' ?>>
            <?php $log = $reminderLog; if (!$log): ?>
                <p class="hr-muted">No reminders sent yet.</p>
            <?php else: ?>
                <form method="POST" action="hr-operations" data-bulk-scope>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remind">
                    <input type="hidden" name="return_view" value="reminders">
                    <input type="hidden" name="return_tab" value="log">
                    <div class="table-responsive">
                        <table class="table table-stack hr-table">
                            <thead><tr><th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all reminders"></th><th>When</th><th>To</th><th>About</th><th>What was outstanding</th><th>By</th></tr></thead>
                            <tbody>
                            <?php foreach ($log as $l): ?>
                                <tr>
                                    <td data-label="Select"><input type="checkbox" class="hr-sel" name="ids[]" value="<?= (int)$l['user_id'] ?>" aria-label="Select reminder to <?= $e($l['full_name']) ?>"></td>
                                    <td data-label="When"><?= $e(date('j M Y, H:i', strtotime((string)$l['sent_at']))) ?></td>
                                    <td data-label="To"><?= $e($l['full_name']) ?><?= (int)$l['emailed'] ? ' <i class="fas fa-envelope hr-muted" aria-label="Emailed"></i>' : '' ?></td>
                                    <td data-label="About"><?= $e(ucfirst((string)$l['kind'])) ?></td>
                                    <td data-label="What was outstanding"><span class="hr-muted"><?= $e($l['summary']) ?></span></td>
                                    <td data-label="By"><?= (int)$l['is_auto'] ? '<span class="badge badge-secondary">Automatic</span>' : $e($l['sender'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="hr-bulkbar" data-bulk-bar>
                        <span data-bulk-count>None selected</span>
                        <label class="sr-only" for="logKind">Reminder about</label>
                            <select id="logKind" name="kind" class="form-control">
                                <option value="all">About everything outstanding</option>
                                <option value="documents">About profile &amp; documents</option>
                                <option value="policies">About policies</option>
                            </select>
                        <button type="submit" class="btn btn-primary btn-sm" data-bulk-required="Select the people to remind again first."
                                data-confirm="Send another reminder to the selected people? Anyone with nothing outstanding is skipped." data-confirm-title="Remind again" data-confirm-ok="Send reminders"><i class="fas fa-rotate-right" aria-hidden="true"></i> Remind again</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>

<?php elseif ($policy): ?>
    <?php $shares = hr_policy_shares($conn, $policyId); ?>
    <div class="card">
        <div class="card-body">
            <p><a href="hr-operations?view=policies"><i class="fas fa-arrow-left" aria-hidden="true"></i> All policies</a></p>
            <div class="hr-toolbar">
                <div>
                    <h3 style="margin:0"><?= $e($policy['title']) ?> <span class="badge badge-secondary">v<?= (int)$policy['version'] ?></span><?= (int)$policy['is_active'] ? '' : ' <span class="badge badge-danger">Archived</span>' ?></h3>
                    <div class="hr-muted"><?= $e($policy['category']) ?><?= (int)$policy['requires_ack'] ? ' · acknowledgement required' : '' ?><?= (int)$policy['share_with_new_staff'] ? ' · part of the new-staff pack' : '' ?></div>
                </div>
                <div class="hr-toolbar-group">
                    <a class="btn btn-secondary" href="<?= $e(ims_upload_url((string)$policy['file_path'])) ?>" target="_blank" rel="noopener"><i class="fas fa-file" aria-hidden="true"></i> Open document</a>
                    <button type="button" class="btn btn-secondary" onclick='hrOpenPolicy(<?= json_encode(array_intersect_key($policy, array_flip(['policy_id', 'title', 'category', 'description', 'requires_ack', 'share_with_new_staff', 'is_active', 'original_name'])), JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fas fa-pen" aria-hidden="true"></i> Edit / new version</button>
                    <?php if ((int)$policy['is_active']): ?><button type="button" class="btn btn-primary" onclick="hrOpenShare([<?= $policyId ?>])"><i class="fas fa-share-nodes" aria-hidden="true"></i> Share</button><?php endif; ?>
                </div>
            </div>
            <?php if ($policy['description']): ?><p class="hr-pre"><?= $e($policy['description']) ?></p><?php endif; ?>
            <?php if (!$shares): ?>
                <div class="empty-state"><i class="fas fa-share-nodes" aria-hidden="true"></i><p>Not shared with anyone yet.</p></div>
            <?php else: ?>
                <form method="POST" action="hr-operations" data-bulk-scope>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remind">
                    <input type="hidden" name="kind" value="policies">
                    <input type="hidden" name="return_view" value="policies">
                    <input type="hidden" name="return_policy" value="<?= $policyId ?>">
                    <div class="table-responsive">
                        <table class="table table-stack hr-table">
                            <thead><tr><th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all staff"></th><th>Staff</th><th>Shared</th><th>Opened</th><th>Acknowledged</th></tr></thead>
                            <tbody>
                            <?php foreach ($shares as $sh): $ackCurrent = $sh['acknowledged_at'] && (int)$sh['acknowledged_version'] === (int)$sh['version']; ?>
                                <tr>
                                    <td data-label="Select"><input type="checkbox" class="hr-sel" name="ids[]" value="<?= (int)$sh['user_id'] ?>" aria-label="Select <?= $e($sh['full_name']) ?>"></td>
                                    <td data-label="Staff"><strong><?= $e($sh['full_name']) ?></strong><div class="hr-muted"><?= $e($sh['role']) ?></div></td>
                                    <td data-label="Shared"><?= $e(date('j M Y', strtotime((string)$sh['shared_at']))) ?><div class="hr-muted"><?= $e($sh['shared_by_name'] ?: 'Automatic') ?></div></td>
                                    <td data-label="Opened"><?= $sh['viewed_at'] ? $e(date('j M Y', strtotime((string)$sh['viewed_at']))) : '<span class="hr-muted">Not yet</span>' ?></td>
                                    <td data-label="Acknowledged">
                                        <?php if ($ackCurrent): ?><span class="badge badge-success"><?= $e(date('j M Y', strtotime((string)$sh['acknowledged_at']))) ?></span>
                                        <?php elseif ($sh['acknowledged_at']): ?><span class="badge badge-warning">Older version (v<?= (int)$sh['acknowledged_version'] ?>)</span>
                                        <?php else: ?><span class="badge badge-secondary"><?= (int)$policy['requires_ack'] ? 'Pending' : 'Not required' ?></span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="hr-bulkbar" data-bulk-bar>
                        <span data-bulk-count>None selected</span>
                        <button type="submit" class="btn btn-primary btn-sm" data-bulk-required="Select the staff to remind first."
                                data-confirm="Remind the selected staff to read and acknowledge their policies?" data-confirm-title="Remind to acknowledge" data-confirm-ok="Send reminders"><i class="fas fa-bell" aria-hidden="true"></i> Remind to acknowledge</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-file-shield" aria-hidden="true"></i> Policy library</h3></div>
        <div class="card-body">
            <div class="hr-toolbar">
                <p class="hr-muted" style="margin:0">Upload organisation policies, share them with staff and track who has read and acknowledged them. Policies marked <strong>new staff</strong> are shared automatically with new accounts<?= (int)$settings['autoshare_new_staff'] ? ' (created in the last ' . (int)$settings['new_staff_days'] . ' days)' : ' (currently switched off in Reminders &gt; Settings)' ?>.</p>
                <div class="hr-toolbar-group">
                    <form method="POST" action="hr-operations" data-confirm="Share every “new staff” policy with recently created staff accounts that do not have it yet?" data-confirm-title="Send new-staff pack" data-confirm-ok="Send pack"><?= csrf_field() ?><input type="hidden" name="action" value="autoshare_now"><input type="hidden" name="return_view" value="policies"><button class="btn btn-secondary"><i class="fas fa-user-plus" aria-hidden="true"></i> Send new-staff pack now</button></form>
                    <button type="button" class="btn btn-primary" onclick="hrOpenPolicy()"><i class="fas fa-file-circle-plus" aria-hidden="true"></i> Add policy</button>
                </div>
            </div>
            <?php if (!$policies): ?>
                <div class="empty-state"><i class="fas fa-file-shield" aria-hidden="true"></i><p>No policies yet. Add your code of conduct, safeguarding, leave, IT and finance policies so new staff receive them automatically.</p></div>
            <?php else: ?>
                <form method="POST" action="hr-operations" data-bulk-scope>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="policies_bulk">
                    <input type="hidden" name="return_view" value="policies">
                    <div class="table-responsive">
                        <table class="table table-stack hr-table">
                            <thead><tr><th class="hr-sel-col"><input type="checkbox" class="hr-sel-all" aria-label="Select all policies"></th><th>Policy</th><th>Category</th><th>Version</th><th>Shared</th><th>Acknowledged</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($policies as $p): $pct = (int)$p['shared'] ? round((int)$p['acked'] / (int)$p['shared'] * 100) : 0; ?>
                                <tr>
                                    <td data-label="Select"><input type="checkbox" class="hr-sel" name="policy_ids[]" value="<?= (int)$p['policy_id'] ?>" aria-label="Select <?= $e($p['title']) ?>"></td>
                                    <td data-label="Policy">
                                        <a class="hr-name" href="hr-operations?view=policies&policy=<?= (int)$p['policy_id'] ?>"><?= $e($p['title']) ?></a>
                                        <div class="hr-muted">
                                            <?= (int)$p['is_active'] ? '' : '<span class="badge badge-danger">Archived</span> ' ?>
                                            <?= (int)$p['share_with_new_staff'] ? '<span class="badge badge-info">New staff</span> ' : '' ?>
                                            <?= (int)$p['requires_ack'] ? 'Acknowledgement required' : 'For information' ?>
                                        </div>
                                    </td>
                                    <td data-label="Category"><?= $e($p['category']) ?></td>
                                    <td data-label="Version">v<?= (int)$p['version'] ?><div class="hr-muted"><?= $e(date('j M Y', strtotime((string)($p['updated_at'] ?: $p['created_at'])))) ?></div></td>
                                    <td data-label="Shared"><?= (int)$p['shared'] ?> staff<div class="hr-muted"><?= (int)$p['viewed'] ?> opened</div></td>
                                    <td data-label="Acknowledged"><?= (int)$p['requires_ack'] ? '<div class="hr-score"><div class="hr-score-bar ' . ($pct >= 90 ? 'is-high' : ($pct >= 50 ? 'is-mid' : 'is-low')) . '"><span style="width:' . max(2, $pct) . '%"></span></div><b>' . $pct . '%</b></div>' : '<span class="hr-muted">-</span>' ?></td>
                                    <td data-label="Actions">
                                        <div class="hr-inline">
                                            <a class="btn btn-sm btn-soft" href="<?= $e(ims_upload_url((string)$p['file_path'])) ?>" target="_blank" rel="noopener" title="Open document"><i class="fas fa-file" aria-hidden="true"></i></a>
                                            <?php if ((int)$p['is_active']): ?><button type="button" class="btn btn-sm btn-primary" onclick="hrOpenShare([<?= (int)$p['policy_id'] ?>])" title="Share"><i class="fas fa-share-nodes" aria-hidden="true"></i></button><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="hr-bulkbar" data-bulk-bar>
                        <span data-bulk-count>None selected</span>
                        <button type="button" class="btn btn-primary btn-sm" data-bulk-required="Select the policies to share first." onclick="hrOpenShare(hrSelected(this))"><i class="fas fa-share-nodes" aria-hidden="true"></i> Share selected</button>
                        <button type="submit" name="op" value="activate" class="btn btn-secondary btn-sm" data-bulk-required="Select the policies to re-activate first."
                                data-confirm="Re-activate the selected policies?" data-confirm-title="Re-activate policies" data-confirm-ok="Re-activate"><i class="fas fa-rotate-left" aria-hidden="true"></i> Re-activate</button>
                        <button type="submit" name="op" value="archive" class="btn btn-danger btn-sm" data-bulk-required="Select the policies to archive first."
                                data-confirm="Archive the selected policies? Staff will no longer see them in My HR." data-confirm-title="Archive policies" data-confirm-ok="Archive" data-confirm-danger><i class="fas fa-box-archive" aria-hidden="true"></i> Archive</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- Edit employment details -->
<div id="hrEmpModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hrEmpTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header"><h3 id="hrEmpTitle"><i class="fas fa-id-badge" aria-hidden="true"></i> <span>Employment details</span></h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hrEmpModal')">&times;</button></div>
        <form method="POST" action="hr-operations" id="hrEmpEdit">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_employee">
                <input type="hidden" name="return_view" value="employees">
                <input type="hidden" name="employee_id" value="">
                <div class="hr-form-grid">
                    <div class="form-group is-wide"><label for="hrEJob" class="required">Job title</label><input id="hrEJob" name="job_title" class="form-control" maxlength="150" required></div>
                    <div class="form-group"><label for="hrEDept">Department</label><select id="hrEDept" name="department_id" class="form-control"><option value="0">-</option><?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= $e($d['department_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="hrESup">Supervisor</label><select id="hrESup" name="supervisor_id" class="form-control"><option value="0">-</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['user_id'] ?>"><?= $e($s['full_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="hrECon">Contract type</label><select id="hrECon" name="contract_type" class="form-control"><?php foreach (['Staff', 'Consultant', 'Temp', 'Casual'] as $ct): ?><option><?= $ct ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="hrEStart">Start date</label><input id="hrEStart" type="date" name="start_date" class="form-control"></div>
                </div>
                <p class="form-hint">Changes are recorded in the profile history. Personal details, bank and ID information stay with the employee.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('hrEmpModal')">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<!-- Approve / reject profile (handled by employee-actions-process.php) -->
<div id="hrApproveModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hrApTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header"><h3 id="hrApTitle"><i class="fas fa-user-check" aria-hidden="true"></i> <span>Review profile</span></h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hrApproveModal')">&times;</button></div>
        <form method="POST" action="employee-actions-process">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="employee_id" value="">
                <input type="hidden" name="return_to" value="hr-operations">
                <p>Check the profile and documents first (<a href="#" id="hrApView" target="_blank">open profile</a>).</p>
                <div class="form-group"><label for="hrApReason">Reason (required to reject)</label><textarea id="hrApReason" name="rejection_reason" class="form-control" rows="3"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="reject_employee" value="1" class="btn btn-danger" onclick="return hrRejectProfile(this)">Reject</button>
                <button type="submit" name="approve_employee" value="1" class="btn btn-success" data-confirm="Approve this employee profile?" data-confirm-title="Approve profile" data-confirm-ok="Approve">Approve</button>
            </div>
        </form>
    </div>
</div>

<!-- Add / edit policy -->
<div id="hrPolicyModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hrPolTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header"><h3 id="hrPolTitle"><i class="fas fa-file-shield" aria-hidden="true"></i> <span>Add policy</span></h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hrPolicyModal')">&times;</button></div>
        <form method="POST" action="hr-operations" enctype="multipart/form-data" id="hrPolForm">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="policy_save">
                <input type="hidden" name="return_view" value="policies">
                <input type="hidden" name="policy_id" value="0">
                <input type="hidden" name="return_policy" value="0">
                <div class="hr-form-grid">
                    <div class="form-group is-wide"><label for="hrPTitle" class="required">Title</label><input id="hrPTitle" name="title" class="form-control" maxlength="200" required placeholder="e.g. Code of Conduct"></div>
                    <div class="form-group"><label for="hrPCat">Category</label><select id="hrPCat" name="category" class="form-control"><?php foreach (HR_POLICY_CATEGORIES as $c): ?><option><?= $e($c) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="hrPFile" id="hrPFileLbl" class="required">Document</label><input id="hrPFile" type="file" name="policy_file" class="form-control" accept=".<?= implode(',.', HR_POLICY_TYPES) ?>"><p class="form-hint" id="hrPFileHint">PDF or Office document, up to 20 MB.</p></div>
                    <div class="form-group is-wide"><label for="hrPDesc">Summary</label><textarea id="hrPDesc" name="description" class="form-control" rows="3" placeholder="What the policy covers and who it applies to"></textarea></div>
                    <div class="form-group is-wide">
                        <label class="hr-check" style="display:flex"><input type="checkbox" name="requires_ack" value="1" checked> Staff must acknowledge they have read it</label>
                        <label class="hr-check" style="display:flex"><input type="checkbox" name="share_with_new_staff" value="1" checked> Include in the new-staff pack (shared automatically with new staff)</label>
                        <label class="hr-check" style="display:flex" id="hrPActiveRow" hidden><input type="checkbox" name="is_active" value="1" checked> Active (untick to archive)</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('hrPolicyModal')">Cancel</button><button class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save policy</button></div>
        </form>
    </div>
</div>

<!-- Share policies -->
<div id="hrShareModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hrShTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header"><h3 id="hrShTitle"><i class="fas fa-share-nodes" aria-hidden="true"></i> Share policies with staff</h3><button type="button" class="close" aria-label="Close" onclick="closeModal('hrShareModal')">&times;</button></div>
        <form method="POST" action="hr-operations" id="hrShareForm">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="policy_share">
                <input type="hidden" name="return_view" value="<?= $e($view) ?>">
                <input type="hidden" name="return_policy" value="<?= $policyId ?>">
                <fieldset class="form-group">
                    <legend class="required">Policies</legend>
                    <div class="hr-picker" id="hrShPolicies">
                        <?php foreach ($activePolicies as $p): ?>
                            <label><input type="checkbox" name="policy_ids[]" value="<?= (int)$p['policy_id'] ?>"> <span><?= $e($p['title']) ?> <small><?= $e($p['category']) ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <fieldset class="form-group">
                    <legend>Share with</legend>
                    <label class="hr-check" style="display:flex"><input type="radio" name="audience" value="all" checked> All active staff (<?= count($staff) ?>)</label>
                    <label class="hr-check" style="display:flex"><input type="radio" name="audience" value="department"> A department
                        <select name="department_id" class="form-control" style="width:auto;margin-left:6px"><?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= $e($d['department_name']) ?></option><?php endforeach; ?></select></label>
                    <label class="hr-check" style="display:flex"><input type="radio" name="audience" value="selected"> Selected people</label>
                    <input type="search" class="form-control hr-picker-search" placeholder="Filter staff…" aria-label="Filter staff" data-filter="hrShPeople">
                    <div class="hr-picker" id="hrShPeople">
                        <?php foreach ($staff as $s): ?>
                            <label data-name="<?= $e(mb_strtolower($s['full_name'] . ' ' . $s['role'])) ?>"><input type="checkbox" name="ids[]" value="<?= (int)$s['user_id'] ?>"> <span><?= $e($s['full_name']) ?> <small><?= $e($s['role']) ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="form-group"><label for="hrShNote">Message (optional)</label><textarea id="hrShNote" name="note" class="form-control" rows="2" placeholder="e.g. Please read these before your induction on Monday."></textarea></div>
                <label class="hr-check"><input type="checkbox" name="email" value="1" checked> Also send by email</label>
                <p class="form-hint">People who already have a policy are not notified about it again.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('hrShareModal')">Cancel</button><button class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Share</button></div>
        </form>
    </div>
</div>

<script>
(function () {
    // Sub-tabs (Overview: attention / interviews / policies; Reminders: send / settings / log); kept in the URL.
    const subTabs = Array.from(document.querySelectorAll('[data-ov-tab]'));
    function showSub(key, focus) {
        subTabs.forEach((b) => {
            const on = b.dataset.ovTab === key;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            b.tabIndex = on ? 0 : -1;
            const panel = document.getElementById('ovPanel-' + b.dataset.ovTab);
            if (panel) panel.hidden = !on;
            if (on && focus) b.focus();
        });
        const url = new URL(window.location.href);
        url.searchParams.set('view', <?= json_encode($view) ?>);
        url.searchParams.set('tab', key);
        history.replaceState(null, '', url);
    }
    subTabs.forEach((b, i) => {
        b.addEventListener('click', () => showSub(b.dataset.ovTab));
        b.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            e.preventDefault();
            const next = subTabs[(i + (e.key === 'ArrowRight' ? 1 : subTabs.length - 1)) % subTabs.length];
            showSub(next.dataset.ovTab, true);
        });
    });

    // One-row reminder from the Employees table, with the custom confirm popup.
    window.hrRemindOne = function (btn, userId, name) {
        const form = btn.closest('form');
        hrConfirm('Send ' + name + ' a reminder about everything outstanding?', { title: 'Send reminder', okText: 'Send reminder' }).then((yes) => {
            if (!yes) return;
            form.querySelectorAll('input.hr-sel').forEach((c) => { c.checked = parseInt(c.value, 10) === userId; });
            const kind = form.querySelector('select[name=kind]');
            if (kind) kind.value = 'all';
            const action = document.createElement('input');
            action.type = 'hidden'; action.name = 'action'; action.value = 'remind';
            form.appendChild(action);
            form.submit();
        });
    };

    window.hrOpenEmp = function (d) {
        const f = document.getElementById('hrEmpEdit');
        f.employee_id.value = d.employee_id;
        f.job_title.value = d.job_title || '';
        f.department_id.value = d.department_id || 0;
        f.supervisor_id.value = d.supervisor_id || 0;
        f.contract_type.value = d.contract_type || 'Staff';
        f.start_date.value = d.start_date || '';
        document.querySelector('#hrEmpTitle span').textContent = 'Employment details: ' + d.name;
        openModal('hrEmpModal');
    };

    // Reject: a reason first, then a confirmation, then submit with the reject button's name.
    window.hrRejectProfile = function (btn) {
        if (btn.dataset.ok === '1') { delete btn.dataset.ok; return true; }
        if (!document.getElementById('hrApReason').value.trim()) {
            hrAlert('Give a reason for rejecting this profile.', { title: 'Reason needed' });
            return false;
        }
        hrConfirm('Reject this profile? The employee is told to correct and resubmit it.', { title: 'Reject profile', okText: 'Reject', danger: true }).then((yes) => {
            if (yes) { btn.dataset.ok = '1'; btn.click(); }
        });
        return false;
    };

    window.hrOpenApprove = function (id, name) {
        const m = document.getElementById('hrApproveModal');
        m.querySelector('input[name=employee_id]').value = id;
        m.querySelector('#hrApTitle span').textContent = 'Review profile: ' + name;
        document.getElementById('hrApView').href = 'employee-profile-view?id=' + id;
        openModal('hrApproveModal');
    };

    window.hrOpenPolicy = function (p) {
        const f = document.getElementById('hrPolForm');
        f.reset();
        p = p || {};
        f.policy_id.value = p.policy_id || 0;
        f.return_policy.value = p.policy_id || 0;
        f.title.value = p.title || '';
        if (p.category) f.category.value = p.category;
        f.description.value = p.description || '';
        f.requires_ack.checked = p.policy_id ? parseInt(p.requires_ack, 10) === 1 : true;
        f.share_with_new_staff.checked = p.policy_id ? parseInt(p.share_with_new_staff, 10) === 1 : true;
        f.is_active.checked = p.policy_id ? parseInt(p.is_active, 10) === 1 : true;
        document.getElementById('hrPActiveRow').hidden = !p.policy_id;
        f.policy_file.required = !p.policy_id;
        document.getElementById('hrPFileLbl').classList.toggle('required', !p.policy_id);
        document.getElementById('hrPFileHint').textContent = p.policy_id
            ? 'Current file: ' + (p.original_name || 'document') + '. Upload a file only to publish a new version (staff will be asked to acknowledge it again).'
            : 'PDF or Office document, up to 20 MB.';
        document.querySelector('#hrPolTitle span').textContent = p.policy_id ? 'Edit policy' : 'Add policy';
        openModal('hrPolicyModal');
    };

    // Share modal: preselect policies and/or people (from table selections).
    window.hrOpenShare = function (policyIds, userIds) {
        const f = document.getElementById('hrShareForm');
        policyIds = policyIds || [];
        f.querySelectorAll('input[name="policy_ids[]"]').forEach((c) => { c.checked = policyIds.includes(parseInt(c.value, 10)); });
        f.querySelectorAll('#hrShPeople input').forEach((c) => { c.checked = !!userIds && userIds.includes(parseInt(c.value, 10)); });
        f.querySelector('input[name=audience][value=' + (userIds && userIds.length ? 'selected' : 'all') + ']').checked = true;
        openModal('hrShareModal');
    };
    document.getElementById('hrShareForm').addEventListener('submit', (ev) => {
        const f = ev.target;
        if (!f.querySelector('input[name="policy_ids[]"]:checked')) { ev.preventDefault(); hrAlert('Choose at least one policy to share.', { title: 'No policy chosen' }); return; }
        if (f.audience.value === 'selected' && !f.querySelector('#hrShPeople input:checked')) { ev.preventDefault(); hrAlert('Choose at least one person, or share with all staff or a department.', { title: 'Nobody chosen' }); }
    });
    document.querySelectorAll('#hrShPeople input').forEach((c) => c.addEventListener('change', () => {
        document.querySelector('#hrShareForm input[name=audience][value=selected]').checked = true;
    }));
    document.querySelectorAll('[data-filter]').forEach((input) => input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        document.querySelectorAll('#' + input.dataset.filter + ' label').forEach((l) => { l.hidden = q !== '' && !(l.dataset.name || '').includes(q); });
    }));
    <?php if (isset($_GET['new_policy'])): ?>hrOpenPolicy();<?php endif; ?>
})();
</script>
<?php include 'includes/footer.php'; ?>
