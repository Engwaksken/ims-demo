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
        default => hr_result(false, 'Unknown action.'),
    };
    $_SESSION['notification'] = ['message' => $result['message'], 'type' => $result['ok'] ? 'success' : 'danger'];

    $back = array_key_exists((string)($_POST['return_view'] ?? ''), $views) ? (string)$_POST['return_view'] : $view;
    $qs = 'view=' . $back;
    if ((int)($_POST['return_policy'] ?? 0) > 0) {
        $qs .= '&policy=' . (int)$_POST['return_policy'];
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
$canApprove = ims_allowed('employees.approve', ['Administrator'], 'Approve or reject employee profiles');

$complianceOptions = [
    '' => 'Everyone', 'non_compliant' => 'Anything outstanding', 'no_profile' => 'No profile yet', 'missing_docs' => 'Missing documents',
    'pending_policies' => 'Policies not acknowledged', 'pending_approval' => 'Profile awaiting approval', 'compliant' => 'All complete',
];

include 'includes/header.php';
?>
<link rel="stylesheet" href="css/hr.css">

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

    <div class="card">
        <nav class="tab-nav" aria-label="HR views">
            <?php foreach ($views as $key => $label):
                $badge = match ($key) { 'employees' => $stats['staff'] - $stats['compliant'], 'policies' => count($activePolicies), default => 0 }; ?>
                <a class="tab-btn<?= $view === $key ? ' active' : '' ?>" href="hr-operations?view=<?= $key ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?><?php if ($badge): ?> <span class="badge"><?= $badge ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="card-body">

<?php if ($view === 'overview'): ?>
            <div class="stats-grid reports-stats">
                <div class="stat-card"><div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Active staff</span><span class="stat-value"><?= $stats['staff'] ?></span></div></div>
                <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Fully compliant</span><span class="stat-value"><?= $stats['compliant'] ?></span></div></div>
                <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-file-circle-exclamation" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Missing documents</span><span class="stat-value"><?= $stats['missing_docs'] ?></span></div></div>
                <div class="stat-card"><div class="stat-icon bg-red"><i class="fas fa-file-signature" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Policies to acknowledge</span><span class="stat-value"><?= $stats['pending_policies'] ?></span></div></div>
                <div class="stat-card"><div class="stat-icon bg-slate"><i class="fas fa-id-card" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">No profile yet</span><span class="stat-value"><?= $stats['no_profile'] ?></span></div></div>
                <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-hourglass-half" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Profiles to approve</span><span class="stat-value"><?= $stats['pending_approval'] ?></span></div></div>
                <div class="stat-card"><div class="stat-icon bg-teal"><i class="fas fa-briefcase" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Open jobs · applications this month</span><span class="stat-value"><?= $stats['open_jobs'] ?> · <?= $stats['applications_month'] ?></span></div></div>
                <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-calendar-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Interviews ahead · scorecards due</span><span class="stat-value"><?= $stats['upcoming_interviews'] ?> · <?= $stats['scorecards_due'] ?></span></div></div>
            </div>

            <div class="hr-grid-2" style="margin-top:20px">
                <div class="hr-col">
                    <div class="hr-toolbar">
                        <h3 style="margin:0"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Needs attention (<?= count($attention) ?>)</h3>
                        <?php if ($attention): ?>
                            <form method="POST" action="hr-operations" onsubmit="return confirm('Send a reminder to everyone with something outstanding?')">
                                <?= csrf_field() ?><input type="hidden" name="action" value="remind_outstanding"><input type="hidden" name="kind" value="all"><input type="hidden" name="return_view" value="overview">
                                <button class="btn btn-primary btn-sm"><i class="fas fa-bell" aria-hidden="true"></i> Remind all</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!$attention): ?>
                        <div class="empty-state"><i class="fas fa-mug-hot" aria-hidden="true"></i><p>Every member of staff is up to date.</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-stack">
                                <thead><tr><th>Staff</th><th>Outstanding</th><th>Last reminded</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($attention, 0, 15) as $r): ?>
                                    <tr>
                                        <td data-label="Staff"><strong><?= $e($r['name']) ?></strong><div class="hr-muted"><?= $e($r['job_title'] ?: $r['role']) ?></div></td>
                                        <td data-label="Outstanding">
                                            <?php if (!$r['has_profile']): ?><span class="badge badge-secondary">No profile</span><?php endif; ?>
                                            <?php if ($r['missing_docs']): ?><span class="badge badge-warning" title="<?= $e(implode(', ', $r['missing_docs'])) ?>"><?= count($r['missing_docs']) ?> document<?= count($r['missing_docs']) === 1 ? '' : 's' ?></span><?php endif; ?>
                                            <?php if ($r['policies_pending'] > 0): ?><span class="badge badge-danger"><?= $r['policies_pending'] ?> polic<?= $r['policies_pending'] === 1 ? 'y' : 'ies' ?></span><?php endif; ?>
                                        </td>
                                        <td data-label="Last reminded"><?= $r['last_reminded'] ? $e(date('j M', strtotime((string)$r['last_reminded']))) : '<span class="hr-muted">Never</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($attention) > 15): ?><p><a href="hr-operations?view=employees&compliance=non_compliant">See all <?= count($attention) ?></a></p><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="hr-col">
                    <h3 style="margin:0"><i class="fas fa-calendar-day" aria-hidden="true"></i> Upcoming interviews</h3>
                    <?php $upcoming = hr_upcoming_interviews($conn); if (!$upcoming): ?>
                        <p class="hr-muted">None scheduled.</p>
                    <?php else: ?>
                        <ul class="hr-checklist">
                            <?php foreach ($upcoming as $u): ?>
                                <li><i class="fas fa-user-tie" aria-hidden="true"></i><span><a href="interview-scorecard?id=<?= (int)$u['interview_id'] ?>"><?= $e($u['applicant_name']) ?></a><br><small class="hr-muted"><?= $e($u['job_title']) ?> · <?= $e(date('D j M, H:i', strtotime((string)$u['scheduled_at']))) ?></small></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <h3 style="margin:10px 0 0"><i class="fas fa-file-shield" aria-hidden="true"></i> Policies</h3>
                    <?php if (!$activePolicies): ?>
                        <p class="hr-muted">No policies yet. <a href="#" onclick="hrOpenPolicy();return false">Add your first policy</a>.</p>
                    <?php else: ?>
                        <ul class="hr-checklist">
                            <?php foreach (array_slice($activePolicies, 0, 6) as $p): $pct = (int)$p['shared'] ? round((int)$p['acked'] / (int)$p['shared'] * 100) : 0; ?>
                                <li><i class="fas fa-file-lines" aria-hidden="true"></i><span><a href="hr-operations?view=policies&policy=<?= (int)$p['policy_id'] ?>"><?= $e($p['title']) ?></a><br><small class="hr-muted"><?= (int)$p['shared'] ?> shared<?= (int)$p['requires_ack'] ? ' · ' . $pct . '% acknowledged' : '' ?></small></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

<?php elseif ($view === 'employees'): ?>
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

            <form method="POST" action="hr-operations" id="hrEmpForm">
                <?= csrf_field() ?>
                <input type="hidden" name="return_view" value="employees">
                <?php if (!$employees): ?>
                    <div class="empty-state"><i class="fas fa-users" aria-hidden="true"></i><p>No staff match these filters.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr>
                            <th><label class="sr-only" for="hrEmpAll">Select all</label><input type="checkbox" id="hrEmpAll"></th>
                            <th>Staff</th><th>Department</th><th>Profile</th><th>Documents</th><th>Policies</th><th>Reminded</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($employees as $r): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="ids[]" value="<?= (int)$r['user_id'] ?>" class="hr-emp-pick" aria-label="Select <?= $e($r['name']) ?>"></td>
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
                                    <?php if ($r['missing_docs']): ?><div class="hr-muted"><?= $e(implode(', ', array_slice($r['missing_docs'], 0, 3))) ?><?= count($r['missing_docs']) > 3 ? '…' : '' ?></div><?php endif; ?>
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
                                            <button type="submit" class="btn btn-sm btn-soft" name="action" value="remind" title="Send reminder" onclick="hrOnly(<?= (int)$r['user_id'] ?>)"><i class="fas fa-bell" aria-hidden="true"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="hr-toolbar" style="margin-top:14px">
                    <div class="hr-toolbar-group">
                        <label for="hrKind" class="sr-only">Reminder about</label>
                        <select id="hrKind" name="kind" class="form-control" style="width:auto">
                            <option value="all">Remind about everything outstanding</option>
                            <option value="documents">Remind about profile &amp; documents</option>
                            <option value="policies">Remind about policies</option>
                        </select>
                        <button type="submit" name="action" value="remind" class="btn btn-primary" onclick="return hrAnyPicked()"><i class="fas fa-bell" aria-hidden="true"></i> Remind selected</button>
                        <button type="button" class="btn btn-secondary" onclick="hrShareSelected()"><i class="fas fa-share-nodes" aria-hidden="true"></i> Share policies with selected</button>
                    </div>
                    <span class="hr-muted"><?= count($employees) ?> staff shown · documents required: <?= $e(implode(', ', array_map(fn($c) => HR_DOCS[$c], $settings['required_docs_list'])) ?: 'none') ?></span>
                </div>
                <label for="hrEmpNote" class="sr-only">Note</label>
                <textarea id="hrEmpNote" name="note" class="form-control" rows="2" placeholder="Optional note added to the reminder"></textarea>
                <?php endif; ?>
            </form>

<?php elseif ($view === 'reminders'): ?>
            <div class="hr-grid-2">
                <div class="hr-col">
                    <h3 style="margin:0"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send reminders now</h3>
                    <form method="POST" action="hr-operations">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remind_outstanding">
                        <input type="hidden" name="return_view" value="reminders">
                        <p class="hr-muted"><?= $stats['staff'] - $stats['compliant'] ?> staff have something outstanding. Each gets one message (in-app and email) listing exactly what is missing; staff with nothing outstanding for the chosen topic are skipped.</p>
                        <div class="form-group"><label for="hrRemKind">About</label>
                            <select id="hrRemKind" name="kind" class="form-control">
                                <option value="all">Everything outstanding</option>
                                <option value="documents">Profile and documents only</option>
                                <option value="policies">Policies only</option>
                            </select>
                        </div>
                        <div class="form-group"><label for="hrRemNote">Note (optional)</label><textarea id="hrRemNote" name="note" class="form-control" rows="3" placeholder="e.g. Please complete this before payroll on the 25th."></textarea></div>
                        <button class="btn btn-primary" onclick="return confirm('Send reminders now?')"><i class="fas fa-bell" aria-hidden="true"></i> Send reminders</button>
                    </form>

                    <h3 style="margin:10px 0 0"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Recent reminders</h3>
                    <?php $log = hr_reminder_log($conn, 40); if (!$log): ?>
                        <p class="hr-muted">No reminders sent yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-stack">
                                <thead><tr><th>When</th><th>To</th><th>About</th><th>By</th></tr></thead>
                                <tbody>
                                <?php foreach ($log as $l): ?>
                                    <tr>
                                        <td data-label="When"><?= $e(date('j M Y, H:i', strtotime((string)$l['sent_at']))) ?></td>
                                        <td data-label="To"><?= $e($l['full_name']) ?><?= (int)$l['emailed'] ? ' <i class="fas fa-envelope hr-muted" aria-label="Emailed"></i>' : '' ?></td>
                                        <td data-label="About"><span title="<?= $e($l['summary']) ?>"><?= $e(ucfirst((string)$l['kind'])) ?></span></td>
                                        <td data-label="By"><?= (int)$l['is_auto'] ? '<span class="badge badge-secondary">Automatic</span>' : $e($l['sender'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="hr-col">
                    <h3 style="margin:0"><i class="fas fa-gear" aria-hidden="true"></i> Settings</h3>
                    <form method="POST" action="hr-operations">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="settings">
                        <input type="hidden" name="return_view" value="reminders">
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
                </div>
            </div>

<?php elseif ($policy): ?>
            <?php $shares = hr_policy_shares($conn, $policyId); $sharedIds = array_map('intval', array_column($shares, 'user_id')); ?>
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
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr><th>Staff</th><th>Shared</th><th>Opened</th><th>Acknowledged</th></tr></thead>
                        <tbody>
                        <?php foreach ($shares as $s): $ackCurrent = $s['acknowledged_at'] && (int)$s['acknowledged_version'] === (int)$s['version']; ?>
                            <tr>
                                <td data-label="Staff"><strong><?= $e($s['full_name']) ?></strong><div class="hr-muted"><?= $e($s['role']) ?></div></td>
                                <td data-label="Shared"><?= $e(date('j M Y', strtotime((string)$s['shared_at']))) ?><div class="hr-muted"><?= $e($s['shared_by_name'] ?: 'Automatic') ?></div></td>
                                <td data-label="Opened"><?= $s['viewed_at'] ? $e(date('j M Y', strtotime((string)$s['viewed_at']))) : '<span class="hr-muted">Not yet</span>' ?></td>
                                <td data-label="Acknowledged">
                                    <?php if ($ackCurrent): ?><span class="badge badge-success"><?= $e(date('j M Y', strtotime((string)$s['acknowledged_at']))) ?></span>
                                    <?php elseif ($s['acknowledged_at']): ?><span class="badge badge-warning">Older version (v<?= (int)$s['acknowledged_version'] ?>)</span>
                                    <?php else: ?><span class="badge badge-secondary"><?= (int)$policy['requires_ack'] ? 'Pending' : 'Not required' ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

<?php else: ?>
            <div class="hr-toolbar">
                <p class="hr-muted" style="margin:0">Upload organisation policies, share them with staff and track who has read and acknowledged them. Policies marked <strong>new staff</strong> are shared automatically with new accounts<?= (int)$settings['autoshare_new_staff'] ? ' (created in the last ' . (int)$settings['new_staff_days'] . ' days)' : ' (currently switched off in Reminders &gt; Settings)' ?>.</p>
                <div class="hr-toolbar-group">
                    <form method="POST" action="hr-operations"><?= csrf_field() ?><input type="hidden" name="action" value="autoshare_now"><input type="hidden" name="return_view" value="policies"><button class="btn btn-secondary"><i class="fas fa-user-plus" aria-hidden="true"></i> Send new-staff pack now</button></form>
                    <button type="button" class="btn btn-secondary" onclick="hrOpenShare([])"<?= $activePolicies ? '' : ' disabled' ?>><i class="fas fa-share-nodes" aria-hidden="true"></i> Share policies</button>
                    <button type="button" class="btn btn-primary" onclick="hrOpenPolicy()"><i class="fas fa-file-circle-plus" aria-hidden="true"></i> Add policy</button>
                </div>
            </div>
            <?php if (!$policies): ?>
                <div class="empty-state"><i class="fas fa-file-shield" aria-hidden="true"></i><p>No policies yet. Add your code of conduct, safeguarding, leave, IT and finance policies so new staff receive them automatically.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead><tr><th>Policy</th><th>Category</th><th>Version</th><th>Shared</th><th>Acknowledged</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($policies as $p): $pct = (int)$p['shared'] ? round((int)$p['acked'] / (int)$p['shared'] * 100) : 0; ?>
                            <tr>
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
            <?php endif; ?>
<?php endif; ?>
        </div>
    </div>
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
                <button type="submit" name="reject_employee" value="1" class="btn btn-danger" onclick="if(!document.getElementById('hrApReason').value.trim()){alert('Give a reason for rejecting.');return false;}">Reject</button>
                <button type="submit" name="approve_employee" value="1" class="btn btn-success">Approve</button>
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
    const empForm = document.getElementById('hrEmpForm');
    const all = document.getElementById('hrEmpAll');
    if (all) all.addEventListener('change', () => document.querySelectorAll('.hr-emp-pick').forEach((c) => { c.checked = all.checked; }));

    window.hrAnyPicked = function () {
        if (!document.querySelector('.hr-emp-pick:checked')) { alert('Select at least one member of staff.'); return false; }
        return true;
    };
    window.hrOnly = function (userId) {
        document.querySelectorAll('.hr-emp-pick').forEach((c) => { c.checked = parseInt(c.value, 10) === userId; });
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

    window.hrOpenShare = function (policyIds, userIds) {
        const f = document.getElementById('hrShareForm');
        f.querySelectorAll('input[name="policy_ids[]"]').forEach((c) => { c.checked = policyIds.includes(parseInt(c.value, 10)); });
        f.querySelectorAll('#hrShPeople input').forEach((c) => { c.checked = !!userIds && userIds.includes(parseInt(c.value, 10)); });
        f.querySelector('input[name=audience][value=' + (userIds && userIds.length ? 'selected' : 'all') + ']').checked = true;
        openModal('hrShareModal');
    };
    window.hrShareSelected = function () {
        const ids = Array.from(document.querySelectorAll('.hr-emp-pick:checked')).map((c) => parseInt(c.value, 10));
        if (!ids.length) { alert('Select at least one member of staff.'); return; }
        hrOpenShare([], ids);
    };
    document.getElementById('hrShareForm').addEventListener('submit', (ev) => {
        const f = ev.target;
        if (!f.querySelector('input[name="policy_ids[]"]:checked')) { ev.preventDefault(); alert('Choose at least one policy.'); return; }
        if (f.audience.value === 'selected' && !f.querySelector('#hrShPeople input:checked')) { ev.preventDefault(); alert('Choose at least one person.'); }
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
