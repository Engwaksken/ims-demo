<?php
declare(strict_types=1);

ob_start();
$page_title = 'Manage Reviewers';
require_once 'includes/header.php';

if (!ims_allowed('reviewers.manage', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Program Director'], 'Manage reviewers, review types and assignments')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard');
    exit();
}

$current_user_id = (int)$_SESSION['user_id'];

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$tab        = $_GET['tab'] ?? 'assign';
$active_tab = in_array($tab, ['assign', 'status', 'review_types', 'criteria'], true) ? $tab : 'assign';

$edit_criteria = null;
$edit_id       = isset($_GET['edit_criteria'])    ? (int)$_GET['edit_criteria']    : 0;
$edit_rt       = null;
$edit_rt_id    = isset($_GET['edit_review_type']) ? (int)$_GET['edit_review_type'] : 0;

/* -- Helpers --------------------------------------------------- */
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function af(string $action, string $extra = ''): string {
    return '<form method="POST" action="includes/process_manage_reviewers.php" '.$extra.'>'
         . '<input type="hidden" name="action" value="'.h($action).'">';
}

function preview_words(?string $text, int $limit = 20): string {
    $text  = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)) ?? '');
    $parts = preg_split('/\s+/u', $text) ?: [];
    if (count($parts) <= $limit) return $text;
    return implode(' ', array_slice($parts, 0, $limit)) . '...';
}

function pgRange(int $cur, int $total): array {
    $r = [];
    for ($i = max(1, $cur-2); $i <= min($total, $cur+2); $i++) $r[] = $i;
    if (($r[0]??1) > 2) array_unshift($r, '...');
    if (($r[0]??1) > 1) array_unshift($r, 1);
    $last = end($r);
    if ($last < $total-1) $r[] = '...';
    if ($last < $total)   $r[] = $total;
    return $r;
}

/* -- Review types ---------------------------------------------- */
$review_types = [];
$res = $conn->query("
    SELECT rt.*,
           COALESCE(u.cnt,0)   AS usage_count,
           cr.full_name        AS created_by_name
    FROM review_types rt
    LEFT JOIN (SELECT review_type_id, COUNT(*) AS cnt
               FROM application_reviews GROUP BY review_type_id) u
        ON u.review_type_id = rt.review_type_id
    LEFT JOIN users cr ON cr.user_id = rt.created_by
    ORDER BY rt.sort_order ASC, rt.name ASC
");
if ($res) while ($r = $res->fetch_assoc()) $review_types[] = $r;

$rt_total  = count($review_types);
$rt_active = count(array_filter($review_types, fn($r) => (int)$r['is_active'] === 1));
$rt_uses   = array_sum(array_map(fn($r) => (int)$r['usage_count'], $review_types));
$active_rts = array_values(array_filter($review_types, fn($r) => (int)$r['is_active'] === 1));

if ($edit_rt_id > 0) {
    foreach ($review_types as $rt)
        if ((int)$rt['review_type_id'] === $edit_rt_id) { $edit_rt = $rt; break; }
}

/* -- Reviewers ------------------------------------------------- */
$reviewers = [];
$stmt = $conn->prepare("
    SELECT user_id, full_name, email, role
    FROM users
    WHERE role IN ('Reviewer','MEAL Lead','Administrator','Programs Lead')
      AND is_active = 1
    ORDER BY full_name
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $reviewers[] = $row;
$stmt->close();

/* -- Applications ---------------------------------------------- */
$applications = [];
$stmt = $conn->prepare("
    SELECT a.application_id, a.startup_name, a.status, a.submitted_at,
           ao.opportunity_title,
           (SELECT COUNT(*) FROM reviewer_assignments ra
            WHERE ra.application_id = a.application_id) AS reviewer_count
    FROM applications a
    INNER JOIN application_opportunities ao ON ao.opportunity_id = a.opportunity_id
    WHERE a.status IN ('Pending','Shortlisted')
    ORDER BY a.submitted_at ASC
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $applications[] = $row;
$stmt->close();

/* -- Assignments ----------------------------------------------- */
$assignments = [];
$stmt = $conn->prepare("
    SELECT ra.assignment_id, ra.application_id, ra.reviewer_id, ra.assigned_by,
           ra.assigned_at, ra.reminder_count, ra.reminder_sent_at,
           ra.review_type_id,
           u.full_name  AS reviewer_name,
           u.email      AS reviewer_email,
           u.role       AS reviewer_role,
           ap.startup_name,
           ao.opportunity_title,
           ab.full_name AS assigned_by_name,
           rt.name      AS review_type_name,
           ar.review_id, ar.overall_score, ar.recommendation,
           ar.created_at AS reviewed_at,
           TIMESTAMPDIFF(HOUR, ra.assigned_at, NOW()) AS hours_since_assigned
    FROM reviewer_assignments ra
    INNER JOIN users u   ON u.user_id   = ra.reviewer_id
    INNER JOIN applications ap ON ap.application_id = ra.application_id
    INNER JOIN application_opportunities ao ON ao.opportunity_id = ap.opportunity_id
    INNER JOIN users ab  ON ab.user_id   = ra.assigned_by
    LEFT JOIN review_types rt ON rt.review_type_id = ra.review_type_id
    LEFT JOIN (
        SELECT r1.* FROM application_reviews r1
        INNER JOIN (
            SELECT application_id, reviewer_id, MAX(created_at) AS mc
            FROM application_reviews GROUP BY application_id, reviewer_id
        ) r2 ON r2.application_id = r1.application_id
             AND r2.reviewer_id   = r1.reviewer_id
             AND r2.mc            = r1.created_at
    ) ar ON ar.application_id = ra.application_id
         AND ar.reviewer_id   = ra.reviewer_id
    ORDER BY ra.assigned_at DESC
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['reminder_count'] = (int)($row['reminder_count'] ?? 0);
    $assignments[] = $row;
}
$stmt->close();

/* -- Stats ----------------------------------------------------- */
$total_assignments = count($assignments);
$reviewed_count    = count(array_filter($assignments, fn($a) => !empty($a['review_id'])));
$pending_count     = $total_assignments - $reviewed_count;
$overdue_count     = count(array_filter($assignments,
    fn($a) => empty($a['review_id']) && (int)$a['hours_since_assigned'] >= 5));

/* -- Criteria -------------------------------------------------- */
$criteria_search  = trim((string)($_GET['criteria_search'] ?? ''));
$criteria_rt_id   = isset($_GET['criteria_review_type_id']) && $_GET['criteria_review_type_id'] !== ''
                    ? (int)$_GET['criteria_review_type_id'] : 0;
$criteria_page    = max(1, (int)($_GET['criteria_page'] ?? 1));
$criteria_per_pg  = 10;
$criteria_offset  = ($criteria_page - 1) * $criteria_per_pg;

$cW = ''; $cT = ''; $cP = [];
if ($criteria_search !== '') {
    $cW .= " AND (rc.category LIKE ? OR rc.question LIKE ? OR rc.description LIKE ? OR rt.name LIKE ?) ";
    $sl  = '%'.$criteria_search.'%';
    $cT .= 'ssss'; array_push($cP, $sl, $sl, $sl, $sl);
}
if ($criteria_rt_id > 0) {
    $cW .= " AND rc.review_type_id = ? "; $cT .= 'i'; $cP[] = $criteria_rt_id;
}

$cJoin = "FROM review_criteria rc
          INNER JOIN users u ON u.user_id = rc.created_by
          LEFT JOIN review_types rt ON rt.review_type_id = rc.review_type_id
          WHERE 1=1 {$cW}";

$stmt = $conn->prepare("SELECT COUNT(*) AS total {$cJoin}");
if ($cT !== '') $stmt->bind_param($cT, ...$cP);
$stmt->execute();
$total_criteria_records = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$criteria_total_pages = max(1, (int)ceil($total_criteria_records / $criteria_per_pg));
if ($criteria_page > $criteria_total_pages) {
    $criteria_page   = $criteria_total_pages;
    $criteria_offset = ($criteria_page - 1) * $criteria_per_pg;
}

$criteria = [];
$dT = $cT.'ii'; $dP = array_merge($cP, [$criteria_per_pg, $criteria_offset]);
$stmt = $conn->prepare("SELECT rc.*, u.full_name AS created_by_name, rt.name AS review_type_name
    {$cJoin}
    ORDER BY CASE WHEN rt.name IS NULL THEN 1 ELSE 0 END ASC,
             rt.name DESC, rc.category ASC, rc.criteria_id ASC
    LIMIT ? OFFSET ?");
$stmt->bind_param($dT, ...$dP);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $criteria[] = $row;
$stmt->close();

$criteria_grouped = []; $category_totals = [];
foreach ($criteria as $c) {
    $gk = !empty($c['review_type_name']) ? $c['review_type_name'] : 'All Types';
    $criteria_grouped[$gk][] = $c;
    $ck = $gk.'||'.$c['category'];
    $category_totals[$ck] = ($category_totals[$ck] ?? 0) + (float)$c['max_score'];
}

if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT rc.*, u.full_name AS created_by_name, rt.name AS review_type_name
        FROM review_criteria rc
        INNER JOIN users u ON u.user_id = rc.created_by
        LEFT JOIN review_types rt ON rt.review_type_id = rc.review_type_id
        WHERE rc.criteria_id = ? LIMIT 1");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_criteria = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$active_criteria  = array_filter($criteria, fn($c) => (int)$c['is_active'] === 1);
$active_max_total = array_sum(array_map(fn($c) => (float)$c['max_score'], $active_criteria));

$crit_base = ['tab' => 'criteria'];
if ($criteria_search !== '')  $crit_base['criteria_search'] = $criteria_search;
if ($criteria_rt_id > 0)      $crit_base['criteria_review_type_id'] = $criteria_rt_id;
?>

<style>
/* -- manage-reviewers page-specific layout -- */
.mr-tab-panel.active { display: flex; flex-direction: column; gap: 20px; }
.mr-tabs .tab-btn .badge { padding: 1px 7px; font-size: 10px; }

.mr-field-icon { color: var(--brand-400); margin-right: 4px; }
.mr-opt { font-weight: 400; text-transform: none; letter-spacing: 0; font-size: 11px; }
.mr-step-group { margin-bottom: 24px; }
.mr-step-hint { margin-top: 8px; }
.mr-form-actions { margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.mr-assign-actions { margin-top: 22px; }
.mr-assign-summary { display: none; font-size: 12px; color: var(--ink-400); }
.mr-divider { border: none; border-top: 1px solid var(--ink-100); margin: 16px 0; }
.mr-check { width: 15px; height: 15px; cursor: pointer; accent-color: var(--brand-500); }
.mr-inline-form { display: inline; }
.mr-sent { color: var(--red-fg); }
.mr-mt-4 { margin-top: 4px; }

/* Step indicator */
.assign-steps { display: flex; align-items: center; margin-bottom: 24px; }
.assign-step { display: flex; align-items: center; gap: 10px; flex: 1; }
.assign-step-num {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--ink-50);
    color: var(--ink-300);
    font-size: 13px;
    font-weight: 800;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    transition: background .2s, color .2s;
}
.assign-step.active .assign-step-num { background: var(--brand-500); color: var(--ink-0); }
.assign-step.done .assign-step-num { background: var(--green-fg); color: var(--ink-0); }
.assign-step-label { font-size: 12px; font-weight: 700; color: var(--ink-400); letter-spacing: .02em; line-height: 1.2; }
.assign-step.active .assign-step-label { color: var(--ink-900); }
.assign-step.done .assign-step-label { color: var(--green-fg); }
.assign-step-connector { width: 32px; height: 2px; background: var(--ink-100); flex-shrink: 0; }

/* Review type tile selector */
.rt-tile-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin-bottom: 4px; }
.rt-tile-opt { display: none; }
.rt-tile-label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 14px 16px;
    border: 1.5px solid var(--ink-100);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: border-color .15s, background .15s;
    background: var(--surface-card);
}
.rt-tile-label:hover,
.rt-tile-opt:checked + .rt-tile-label { border-color: var(--brand-500); background: var(--brand-50); }
.rt-tile-opt:checked + .rt-tile-label { box-shadow: var(--focus-ring); }
.rt-tile-name { font-size: 13px; font-weight: 700; color: var(--ink-900); line-height: 1.2; }
.rt-tile-desc { font-size: 11px; color: var(--ink-400); line-height: 1.4; }
.rt-tile-meta { font-size: 10px; font-weight: 700; color: var(--brand-600); letter-spacing: .04em; text-transform: uppercase; }

/* Reviewer / application multi-selects */
.select-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; position: relative; }
.multi-select-wrap { display: flex; flex-direction: column; gap: 8px; }
.multi-select-wrap .form-control { height: 230px; min-height: 200px; padding: 6px; resize: vertical; }
.multi-select-wrap option { padding: 6px 10px; border-radius: var(--radius-sm); }
.select-hint { font-size: 11px; color: var(--ink-300); display: flex; align-items: center; gap: 5px; }
.select-panels-disabled::after {
    content: 'Select a Review Type above first';
    position: absolute;
    inset: 0;
    background: var(--surface-page);
    opacity: .9;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: var(--ink-400);
    border-radius: var(--radius-md);
    z-index: 5;
}

/* Reviewer workload cards */
.reviewer-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.reviewer-card {
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    border-left: 4px solid var(--brand-500);
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.rc-name { font-size: 14px; font-weight: 800; color: var(--ink-900); }
.rc-role { font-size: 11px; color: var(--ink-400); }
.rc-email { font-size: 11px; color: var(--ink-400); font-style: italic; }
.rc-progress { margin-top: 4px; }
.rc-progress-bar { height: 6px; background: var(--ink-100); border-radius: var(--radius-pill); overflow: hidden; margin-bottom: 8px; }
.rc-progress-fill { height: 100%; border-radius: var(--radius-pill); background: var(--green-fg); transition: width .4s ease; }
.rc-counts { display: flex; gap: 7px; flex-wrap: wrap; }

/* Status table */
.mr-toolbar.filters-bar { margin-bottom: 0; }
.select-all-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 22px;
    background: var(--ink-50);
    border-top: 1px solid var(--ink-100);
    border-bottom: 1px solid var(--ink-100);
    font-size: 12px;
    font-weight: 600;
    color: var(--ink-400);
}
.select-all-bar label { margin: 0; }
.mr-selected-count { margin-left: auto; font-size: 11px; color: var(--brand-600); font-weight: 700; }
.mr-col-check { width: 38px; }
.overdue-row td { background: var(--red-bg); }
.overdue-row td:first-child { border-left: 3px solid var(--brand-500); }
.inactive-row td { opacity: .55; }

/* Criteria table group / category sub-headers */
.group-header td {
    background: var(--ink-900);
    color: var(--ink-0);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 8px 16px;
}
.cat-header td {
    background: var(--brand-50);
    color: var(--brand-600);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    padding: 7px 16px;
    border-left: 3px solid var(--brand-500);
}
.group-sub { display: inline-block; margin-left: 8px; font-weight: 400; font-size: 10px; opacity: .7; text-transform: none; letter-spacing: normal; }
.rt-cell-name strong { display: block; font-size: 14px; font-weight: 600; color: var(--ink-900); }
.rt-cell-name span { display: block; font-size: 12px; color: var(--ink-300); margin-top: 2px; font-style: italic; }

.mr-search { min-width: 200px; }
.mr-actions { display: flex; gap: 6px; align-items: center; justify-content: center; }

/* Hover preview tooltip */
.hover-preview { position: relative; line-height: 1.55; color: var(--ink-600); font-weight: 500; cursor: help; max-width: 320px; }
.hover-preview::after {
    content: attr(title);
    position: absolute;
    left: 0;
    top: calc(100% + 6px);
    width: 340px;
    max-width: 90vw;
    background: var(--ink-900);
    color: var(--ink-0);
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    line-height: 1.55;
    box-shadow: var(--shadow-md);
    opacity: 0;
    visibility: hidden;
    transform: translateY(6px);
    transition: opacity .16s, transform .16s, visibility .16s;
    z-index: 200;
    white-space: normal;
    pointer-events: none;
}
.hover-preview:hover { color: var(--ink-900); }
.hover-preview:hover::after { opacity: 1; visibility: visible; transform: translateY(0); }

/* Pagination row + summary */
.mr-pager { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 22px; border-top: 1px solid var(--ink-50); }
.mr-pager .pagination { margin: 0; }
.criteria-summary {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    font-size: 13px;
    background: var(--ink-50);
    padding: 12px 22px;
    border-top: 1px solid var(--ink-100);
}
.criteria-summary .lbl { color: var(--ink-400); }
.criteria-summary strong { color: var(--ink-900); font-weight: 800; }

/* Criteria bundle builder */
.cat-block { background: var(--surface-card); border: 1.5px solid var(--ink-100); border-radius: var(--radius-lg); margin-bottom: 16px; overflow: hidden; }
.cat-block-head { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--brand-50); border-bottom: 1px solid var(--brand-100); }
.cat-block-head i { color: var(--brand-400); flex-shrink: 0; }
.cat-block-head input[type="text"] { flex: 1; border: none; background: transparent; font-size: 14px; font-weight: 700; color: var(--ink-900); outline: none; }
.cat-block-head input[type="text"]::placeholder { color: var(--ink-300); font-weight: 400; }
.cat-block-body { padding: 12px 16px; display: flex; flex-direction: column; gap: 12px; }
.cat-block-foot { padding: 10px 16px; border-top: 1px solid var(--brand-100); display: flex; gap: 8px; }
.criterion-row {
    display: grid;
    grid-template-columns: 1fr auto auto auto;
    gap: 10px;
    align-items: start;
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-md);
    padding: 12px 14px;
}
.criterion-row textarea { grid-column: 1; min-height: 64px; resize: vertical; }
.criterion-row .num-field { width: 80px; }
.remove-row-btn { background: none; border: none; color: var(--red-fg); cursor: pointer; font-size: 14px; padding: 4px; align-self: center; }
.remove-row-btn:hover { opacity: .7; }

@media (max-width: 900px) {
    .select-panels { grid-template-columns: 1fr; }
    .reviewer-grid { grid-template-columns: 1fr; }
    .rt-tile-grid { grid-template-columns: 1fr 1fr; }
    .criterion-row { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
    .assign-steps { flex-wrap: wrap; gap: 8px; }
    .rt-tile-grid { grid-template-columns: 1fr; }
    .criteria-summary { flex-direction: column; gap: 8px; }
}
</style>

<div class="page-stack">

<!-- -- Hero ---------------------------------------------------- -->
<div class="page-hero">
    <div>
        <h1><i class="fas fa-user-check" aria-hidden="true"></i> Manage Reviewers</h1>
        <p>Assign reviewers to applications with a review type, monitor progress, and manage scoring criteria.</p>
    </div>
    <div class="hero-actions">
        <a href="manage_application_scores" class="btn"><i class="fas fa-star-half-stroke" aria-hidden="true"></i> Scores</a>
        <a href="progress-board" class="btn btn-primary"><i class="fas fa-chart-line" aria-hidden="true"></i> Progress Report</a>
    </div>
</div>

<!-- -- Alerts --------------------------------------------------- -->
<?php if ($success_msg !== ''): ?>
    <div class="alert alert-success" id="flashAlert"><i class="fas fa-check-circle"></i> <?= h($success_msg) ?></div>
<?php elseif ($error_msg !== ''): ?>
    <div class="alert alert-danger"  id="flashAlert"><i class="fas fa-exclamation-circle"></i> <?= h($error_msg) ?></div>
<?php endif; ?>

<!-- -- Stats ---------------------------------------------------- -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-info"><span class="stat-label">Assignments</span><span class="stat-value"><?= $total_assignments ?></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-info"><span class="stat-label">Pending</span><span class="stat-value"><?= $pending_count ?></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info"><span class="stat-label">Completed</span><span class="stat-value"><?= $reviewed_count ?></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-red"><i class="fas fa-clock"></i></div>
        <div class="stat-info"><span class="stat-label">Overdue (5h+)</span><span class="stat-value"><?= $overdue_count ?></span></div>
    </div>
</div>

<!-- -- Tab nav -------------------------------------------------- -->
<div class="tab-shell mr-tabs">
<nav class="tab-nav" role="tablist">
    <?php
    $tabs_cfg = [
        'assign'       => ['fa-user-plus',     'Assign Reviewers',  null],
        'status'       => ['fa-tasks',          'Status & Reminders', $total_assignments],
        'review_types' => ['fa-layer-group',    'Review Types',       $rt_total],
        'criteria'     => ['fa-clipboard-list', 'Scoring Criteria',   $total_criteria_records],
    ];
    foreach ($tabs_cfg as $key => [$ico, $lbl, $cnt]):
    ?>
    <button class="tab-btn<?= $active_tab===$key?' active':'' ?>" type="button" data-tab="<?= h($key) ?>">
        <i class="fas <?= h($ico) ?>"></i> <?= $lbl ?>
        <?php if ($cnt !== null): ?><span class="badge badge-secondary"><?= (int)$cnt ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
</nav>
</div>

<!-- --------------------------------------------------------------
     TAB 1 - ASSIGN REVIEWERS
     Step 1: Review Type  ?  Step 2: Reviewer(s)  ?  Step 3: Application(s)
--------------------------------------------------------------- -->
<div id="tab-assign" class="tab-panel mr-tab-panel<?= $active_tab==='assign'?' active':'' ?>">

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-user-plus"></i> Assign Reviewers to Applications</h3>
        </div>
        <div class="panel-body">
            <form method="POST" action="includes/process_manage_reviewers.php" id="assignForm">
                <input type="hidden" name="action" value="assign_reviewers">

                <!-- -- Step indicator ------------------------------- -->
                <div class="assign-steps">
                    <div class="assign-step active" id="si1">
                        <div class="assign-step-num">1</div>
                        <div class="assign-step-label">Review Type</div>
                    </div>
                    <div class="assign-step-connector" id="sc12"></div>
                    <div class="assign-step" id="si2">
                        <div class="assign-step-num">2</div>
                        <div class="assign-step-label">Reviewer(s)</div>
                    </div>
                    <div class="assign-step-connector" id="sc23"></div>
                    <div class="assign-step" id="si3">
                        <div class="assign-step-num">3</div>
                        <div class="assign-step-label">Application(s)</div>
                    </div>
                </div>

                <!-- -- STEP 1: Review Type tile grid ---------------- -->
                <div class="form-group mr-step-group">
                    <label class="form-label">
                        <i class="fas fa-layer-group mr-field-icon"></i>
                        Step 1 - Select Review Type
                        <span class="required-mark"></span>
                    </label>

                    <?php if (empty($active_rts)): ?>
                        <div class="alert alert-danger mr-step-hint">
                            <i class="fas fa-exclamation-triangle"></i>
                            No active review types. Please add one in the <strong>Review Types</strong> tab first.
                        </div>
                    <?php else: ?>
                    <div class="rt-tile-grid">
                        <?php foreach ($active_rts as $rt):
                            $rid = (int)$rt['review_type_id'];
                        ?>
                        <span>
                            <input
                                type="radio"
                                name="review_type_id"
                                id="rt_<?= $rid ?>"
                                value="<?= $rid ?>"
                                class="rt-tile-opt"
                                required
                                onchange="rtSelected()"
                            >
                            <label for="rt_<?= $rid ?>" class="rt-tile-label">
                                <span class="rt-tile-name"><?= h($rt['name']) ?></span>
                                <?php if (!empty($rt['description'])): ?>
                                    <span class="rt-tile-desc"><?= h(preview_words($rt['description'], 10)) ?></span>
                                <?php endif; ?>
                                <span class="rt-tile-meta">
                                    <?= (int)$rt['usage_count'] ?> use<?= (int)$rt['usage_count'] !== 1 ? 's' : '' ?>
                                </span>
                            </label>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="form-hint mr-step-hint" id="rtHint">
                        <i class="fas fa-arrow-up"></i> Choose a review type to unlock steps 2 and 3.
                    </div>
                </div>

                <!-- -- STEPS 2 + 3: Reviewers & Applications -------- -->
                <div class="select-panels select-panels-disabled" id="selectPanels">

                    <!-- Reviewer(s) -->
                    <div class="multi-select-wrap">
                        <label class="form-label">
                            <i class="fas fa-users mr-field-icon"></i>
                            Step 2 - Reviewer(s)
                            <span class="required-mark"></span>
                        </label>
                        <select name="reviewer_ids[]" multiple class="form-control multi-select" id="reviewerSelect" required>
                            <?php foreach ($reviewers as $r): ?>
                                <option value="<?= (int)$r['user_id'] ?>">
                                    <?= h($r['full_name']) ?> (<?= h($r['role']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="select-hint">
                            <i class="fas fa-info-circle"></i>
                            Hold <kbd>Ctrl</kbd> / <kbd><i class="fas fa-keyboard"></i></kbd> to select multiple reviewers.
                        </span>
                    </div>

                    <!-- Application(s) -->
                    <div class="multi-select-wrap">
                        <label class="form-label">
                            <i class="fas fa-file-alt mr-field-icon"></i>
                            Step 3 - Application(s)
                            <span class="required-mark"></span>
                        </label>
                        <select name="application_ids[]" multiple class="form-control multi-select" id="appSelect" required>
                            <?php foreach ($applications as $a): ?>
                                <option value="<?= (int)$a['application_id'] ?>">
                                    #<?= (int)$a['application_id'] ?> - <?= h($a['startup_name']) ?>
                                    [<?= h($a['opportunity_title']) ?>]
                                    . <?= (int)$a['reviewer_count'] ?> reviewer<?= (int)$a['reviewer_count'] !== 1 ? 's' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="select-hint">
                            <i class="fas fa-info-circle"></i>
                            Duplicate assignments (same reviewer + application + type) are skipped.
                        </span>
                    </div>
                </div>

                <!-- Submit -->
                <div class="mr-form-actions mr-assign-actions">
                    <button type="submit" class="btn btn-primary" id="assignBtn" disabled>
                        <i class="fas fa-save"></i> Assign Selected
                    </button>
                    <span class="form-hint" id="assignHint">
                        <i class="fas fa-info-circle"></i>
                        Select a review type above to continue.
                    </span>
                    <!-- Live selection summary -->
                    <span id="assignSummary" class="mr-assign-summary"></span>
                </div>
            </form>
        </div>
    </div>

    <!-- Reviewer workload cards -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-id-badge"></i> Reviewer Workload</h3>
            <span class="note"><?= count($reviewers) ?> eligible reviewer<?= count($reviewers) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="panel-body">
            <?php if (empty($reviewers)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash" aria-hidden="true"></i>
                    <p>No eligible reviewers found.</p>
                </div>
            <?php else: ?>
            <div class="reviewer-grid">
                <?php foreach ($reviewers as $rev):
                    $ra    = array_filter($assignments, fn($a) => (int)$a['reviewer_id'] === (int)$rev['user_id']);
                    $rDone = count(array_filter($ra, fn($a) => !empty($a['review_id'])));
                    $rTot  = count($ra);
                    $pct   = $rTot > 0 ? round(($rDone / $rTot) * 100) : 0;
                ?>
                <div class="reviewer-card">
                    <div class="rc-name"><?= h($rev['full_name']) ?></div>
                    <div class="rc-role"><?= h($rev['role']) ?></div>
                    <div class="rc-email"><?= h($rev['email']) ?></div>
                    <div class="rc-progress">
                        <div class="rc-progress-bar">
                            <div class="rc-progress-fill" style="width:<?= $pct ?>%"></div>
                        </div>
                        <div class="rc-counts">
                            <span class="badge badge-info"><i class="fas fa-clipboard"></i> <?= $rTot ?> assigned</span>
                            <span class="badge badge-success"><i class="fas fa-check"></i> <?= $rDone ?> done</span>
                            <span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> <?= $rTot - $rDone ?> pending</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div><!-- /#tab-assign -->

<!-- --------------------------------------------------------------
     TAB 2 - STATUS & REMINDERS
--------------------------------------------------------------- -->
<div id="tab-status" class="tab-panel mr-tab-panel<?= $active_tab==='status'?' active':'' ?>">
<div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-tasks"></i> Assignment Status &amp; Reminders</h3>
        <span class="note"><?= $total_assignments ?> assignment<?= $total_assignments !== 1 ? 's' : '' ?></span>
    </div>

    <form method="POST" action="includes/process_manage_reviewers.php" id="remindersForm">
        <input type="hidden" name="action" value="send_reminders">

        <!-- Toolbar -->
        <div class="panel-body">
            <div class="asgn-toolbar filters-bar mr-toolbar">
                <input type="search" id="asgnSearch" placeholder="Search startup or reviewer..."
                       class="form-control" autocomplete="off">
                <select id="asgnStatusFilter" class="form-control">
                    <option value="">All statuses</option>
                    <option value="reviewed">Reviewed</option>
                    <option value="overdue">Overdue (5h+)</option>
                    <option value="pending">Pending</option>
                </select>
                <select id="asgnTypeFilter" class="form-control">
                    <option value="">All review types</option>
                    <?php foreach ($review_types as $rt): ?>
                        <option value="<?= h($rt['name']) ?>"><?= h($rt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-soft" id="selectOverdueBtn">
                    <i class="fas fa-bell"></i> Select Overdue
                </button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-paper-plane"></i> Send Reminders
                </button>
            </div>
        </div>

        <!-- Select all bar -->
        <div class="select-all-bar">
            <input type="checkbox" id="checkAll" class="mr-check">
            <label for="checkAll">Select all visible</label>
            <span id="selectedCount" class="mr-selected-count"></span>
        </div>

        <div class="table-responsive">
            <table class="table" id="asgnTable">
                <thead>
                <tr>
                    <th class="mr-col-check"></th>
                    <th>Application</th>
                    <th>Reviewer</th>
                    <th>Review Type</th>
                    <th>Assigned</th>
                    <th>Status</th>
                    <th>Elapsed</th>
                    <th>Reminders</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($assignments)): ?>
                    <tr><td colspan="9">
                        <div class="empty-state">
                            <i class="fas fa-inbox" aria-hidden="true"></i>
                            <p>No assignments yet.</p>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($assignments as $a):
                        $is_rev  = !empty($a['review_id']);
                        $hrs     = (int)$a['hours_since_assigned'];
                        $overdue = !$is_rev && $hrs >= 5;
                        $very    = !$is_rev && $hrs >= 24;
                        $status  = $is_rev ? 'reviewed' : ($overdue ? 'overdue' : 'pending');
                        $d       = (int)floor($hrs / 24);
                        $elapsed = $hrs < 24 ? $hrs.'h' : $d.'d';
                        $srch    = strtolower(($a['startup_name']??'').' '.($a['reviewer_name']??''));
                        $rtname  = strtolower((string)($a['review_type_name'] ?? ''));
                    ?>
                    <tr class="<?= $overdue ? 'overdue-row' : '' ?>"
                        data-status="<?= h($status) ?>"
                        data-search="<?= h($srch) ?>"
                        data-rt="<?= h($rtname) ?>">
                        <td>
                            <?php if (!$is_rev): ?>
                                <input type="checkbox" class="asgn-check mr-check"
                                       name="assignment_ids[]" value="<?= (int)$a['assignment_id'] ?>"
                                       <?= $overdue ? 'data-overdue="1"' : '' ?>
                                       onchange="updateCount()">
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= h($a['startup_name']) ?></strong><br>
                            <span class="cell-muted"><?= h($a['opportunity_title']) ?> . App #<?= (int)$a['application_id'] ?></span>
                        </td>
                        <td>
                            <strong><?= h($a['reviewer_name']) ?></strong><br>
                            <span class="cell-muted"><?= h($a['reviewer_role']) ?></span><br>
                            <span class="note"><?= h($a['reviewer_email']) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($a['review_type_name'])): ?>
                                <span class="badge badge-info"><?= h($a['review_type_name']) ?></span>
                            <?php else: ?>
                                <span class="cell-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="cell-muted"><?= date('d M Y', strtotime((string)$a['assigned_at'])) ?></span><br>
                            <span class="cell-muted"><?= date('h:i A', strtotime((string)$a['assigned_at'])) ?></span><br>
                            <span class="note">by <?= h($a['assigned_by_name']) ?></span>
                        </td>
                        <td>
                            <?php if ($is_rev): ?>
                                <span class="badge badge-success"><i class="fas fa-check"></i> Reviewed</span>
                                <div class="cell-muted mr-mt-4">
                                    <?= h((string)$a['overall_score']) ?>%
                                    <?= !empty($a['recommendation']) ? ' . '.h($a['recommendation']) : '' ?>
                                </div>
                                <?php if (!empty($a['reviewed_at'])): ?>
                                    <div class="cell-muted"><?= date('d M Y', strtotime((string)$a['reviewed_at'])) ?></div>
                                <?php endif; ?>
                            <?php elseif ($very): ?>
                                <span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> Very Late</span>
                            <?php elseif ($overdue): ?>
                                <span class="badge badge-danger"><i class="fas fa-clock"></i> Overdue</span>
                            <?php else: ?>
                                <span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= $elapsed ?></td>
                        <td>
                            <?php if ((int)$a['reminder_count'] > 0): ?>
                                <strong class="mr-sent"><?= (int)$a['reminder_count'] ?> sent</strong>
                                <?php if (!empty($a['reminder_sent_at'])): ?>
                                    <div class="cell-muted">Last: <?= date('d M, h:i A', strtotime((string)$a['reminder_sent_at'])) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <?php if ($is_rev): ?>
                                    <a href="view-review?id=<?= (int)$a['review_id'] ?>" class="btn btn-sm btn-success" title="View review">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                <?php else: ?>
                                    <?= af('send_reminders','class="mr-inline-form"') ?>
                                        <input type="hidden" name="assignment_ids[]" value="<?= (int)$a['assignment_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-soft" title="Send reminder">
                                            <i class="fas fa-bell"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?= af('remove_assignment','class="mr-inline-form" onsubmit="return confirm(\'Remove this assignment?\')"') ?>
                                    <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Remove">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
</div><!-- /#tab-status -->

<!-- --------------------------------------------------------------
     TAB 3 - REVIEW TYPES
--------------------------------------------------------------- -->
<div id="tab-review_types" class="tab-panel mr-tab-panel<?= $active_tab==='review_types'?' active':'' ?>">

    <div class="stats-grid reports-stats">
        <div class="stat-card"><div class="stat-icon bg-primary"><i class="fas fa-layer-group"></i></div><div class="stat-info"><span class="stat-label">Total</span><span class="stat-value"><?= $rt_total ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-toggle-on"></i></div><div class="stat-info"><span class="stat-label">Active</span><span class="stat-value"><?= $rt_active ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-chart-bar"></i></div><div class="stat-info"><span class="stat-label">Total Uses</span><span class="stat-value"><?= number_format($rt_uses) ?></span></div></div>
    </div>

    <!-- Add / edit form -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-<?= $edit_rt ? 'pen' : 'plus-circle' ?>"></i>
                <?= $edit_rt ? 'Edit Review Type' : 'Add Review Type' ?>
            </h3>
            <?php if ($edit_rt): ?>
                <a href="manage-reviewers?tab=review_types" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Cancel</a>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <form method="POST" action="includes/process_manage_reviewers.php">
                <input type="hidden" name="action" value="save_review_type">
                <?php if ($edit_rt): ?><input type="hidden" name="review_type_id" value="<?= (int)$edit_rt['review_type_id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Name <span class="required-mark"></span></label>
                        <input type="text" name="name" class="form-control" required maxlength="120"
                               placeholder="e.g. Technical Review, Financial Assessment"
                               value="<?= h($edit_rt['name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" min="0" max="9999"
                               value="<?= (int)($edit_rt['sort_order'] ?? 0) ?>">
                        <span class="form-hint">Lower = first in lists.</span>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Description <span class="mr-opt">(optional)</span></label>
                        <textarea name="description" class="form-control" rows="2" maxlength="500"
                                  placeholder="Briefly describe what this review type covers..."><?= h($edit_rt['description'] ?? '') ?></textarea>
                    </div>
                    <?php if ($edit_rt): ?>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="is_active" class="form-control">
                            <option value="1" <?= (int)$edit_rt['is_active']===1?'selected':'' ?>>Active</option>
                            <option value="0" <?= (int)$edit_rt['is_active']===0?'selected':'' ?>>Inactive</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mr-form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= $edit_rt ? 'Save Changes' : 'Add Review Type' ?>
                    </button>
                    <?php if ($edit_rt): ?>
                        <a href="manage-reviewers?tab=review_types" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-layer-group"></i> All Review Types</h3>
            <div class="search-input-wrap mr-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="rtSearch" class="form-control" placeholder="Search..." autocomplete="off">
            </div>
        </div>
        <?php if (empty($review_types)): ?>
            <div class="empty-state">
                <i class="fas fa-layer-group" aria-hidden="true"></i>
                <p>No review types yet. Add your first one above.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table" id="rtTable">
                <thead>
                <tr><th>#</th><th>Name &amp; Description</th><th>Order</th><th>Uses</th><th>Status</th><th>Created By</th><th class="text-center">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($review_types as $i => $rt):
                    $active = (int)$rt['is_active'] === 1;
                    $uses   = (int)$rt['usage_count'];
                ?>
                <tr class="<?= $active?'':'inactive-row' ?>"
                    data-rt-name="<?= h(strtolower($rt['name'].' '.($rt['description']??''))) ?>">
                    <td class="cell-muted"><?= $i+1 ?></td>
                    <td class="rt-cell-name">
                        <strong><?= h($rt['name']) ?></strong>
                        <span class="hover-preview" title="<?= h($rt['description']??'') ?>"><?= h(preview_words($rt['description']??'',16)) ?></span>
                    </td>
                    <td><span class="badge badge-secondary"><?= (int)$rt['sort_order'] ?></span></td>
                    <td><span class="badge <?= $uses>0?'badge-info':'badge-secondary' ?>"><i class="fas fa-chart-line"></i> <?= $uses ?></span></td>
                    <td><?= $active
                        ? '<span class="badge badge-success">Active</span>'
                        : '<span class="badge badge-secondary">Inactive</span>' ?></td>
                    <td class="cell-muted"><?= h($rt['created_by_name'] ?? '-') ?></td>
                    <td class="text-center">
                        <div class="mr-actions">
                            <a href="manage-reviewers?tab=review_types&edit_review_type=<?= (int)$rt['review_type_id'] ?>" class="btn btn-sm btn-secondary" title="Edit"><i class="fas fa-pen"></i></a>
                            <?= af('toggle_review_type','class="mr-inline-form"') ?>
                                <input type="hidden" name="review_type_id" value="<?= (int)$rt['review_type_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-warning" title="Toggle status"><i class="fas fa-power-off"></i></button>
                            </form>
                            <?php if ($uses === 0): ?>
                                <?= af('delete_review_type','class="mr-inline-form" onsubmit="return confirm(\'Delete \\\''.addslashes(h($rt['name'])).'\\\' permanently?\')"') ?>
                                    <input type="hidden" name="review_type_id" value="<?= (int)$rt['review_type_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled title="In use - deactivate instead"><i class="fas fa-trash-alt"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /#tab-review_types -->

<!-- --------------------------------------------------------------
     TAB 4 - SCORING CRITERIA
--------------------------------------------------------------- -->
<div id="tab-criteria" class="tab-panel mr-tab-panel<?= $active_tab==='criteria'?' active':'' ?>">

    <!-- Add / edit -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-<?= $edit_criteria ? 'pen' : 'plus-circle' ?>"></i>
                <?= $edit_criteria ? 'Edit Criterion' : 'Add Scoring Criteria' ?>
            </h3>
            <?php if ($edit_criteria): ?>
                <a href="manage-reviewers?tab=criteria" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Cancel</a>
            <?php endif; ?>
        </div>
        <div class="panel-body">

        <?php if ($edit_criteria): ?>
        <!-- -- SINGLE EDIT FORM -- -->
        <form method="POST" action="includes/process_manage_reviewers.php">
            <input type="hidden" name="action" value="save_criteria">
            <input type="hidden" name="criteria_id" value="<?= (int)$edit_criteria['criteria_id'] ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Category <span class="required-mark"></span></label>
                    <input type="text" name="category" class="form-control" required list="catSugg"
                           value="<?= h($edit_criteria['category'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Max Score <span class="required-mark"></span></label>
                    <input type="number" name="max_score" class="form-control" min="0" step="0.01" required
                           value="<?= h((string)($edit_criteria['max_score'] ?? '0')) ?>">
                </div>
                <div class="form-group full">
                    <label class="form-label">Review Type</label>
                    <select name="review_type_id" class="form-control">
                        <option value="">Not assigned (all types)</option>
                        <?php foreach ($review_types as $rt): ?>
                            <option value="<?= (int)$rt['review_type_id'] ?>"
                                <?= isset($edit_criteria['review_type_id'])&&(int)$edit_criteria['review_type_id']===(int)$rt['review_type_id']?'selected':'' ?>
                                <?= (int)$rt['is_active']===0?'disabled':'' ?>>
                                <?= h($rt['name']) ?><?= (int)$rt['is_active']===0?' (inactive)':'' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group full">
                    <label class="form-label">Question / Criterion <span class="required-mark"></span></label>
                    <textarea name="question" class="form-control" rows="2" required><?= h($edit_criteria['question'] ?? '') ?></textarea>
                </div>
                <div class="form-group full">
                    <label class="form-label">Scoring Guide <span class="mr-opt">(optional)</span></label>
                    <textarea name="description" class="form-control" rows="3"
                              placeholder="e.g. 1–3: Minimal; 4–6: Moderate; 7–10: Exceptional..."><?= h($edit_criteria['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Weight</label>
                    <input type="number" name="weight" class="form-control" min="0.1" max="100" step="0.1"
                           value="<?= h((string)($edit_criteria['weight'] ?? '1.0')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-control">
                        <option value="1" <?= (int)$edit_criteria['is_active']===1?'selected':'' ?>>Active</option>
                        <option value="0" <?= (int)$edit_criteria['is_active']===0?'selected':'' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="mr-form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Criterion</button>
                <a href="manage-reviewers?tab=criteria" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>

        <?php else: ?>
        <!-- -- BUNDLE ADD FORM -- -->
        <form method="POST" action="includes/process_manage_reviewers.php" id="bundleForm">
            <input type="hidden" name="action" value="save_criteria_bundle">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Review Type</label>
                    <select name="bundle_review_type_id" class="form-control">
                        <option value="">Not assigned (all types)</option>
                        <?php foreach ($review_types as $rt): ?>
                            <option value="<?= (int)$rt['review_type_id'] ?>" <?= (int)$rt['is_active']===0?'disabled':'' ?>>
                                <?= h($rt['name']) ?><?= (int)$rt['is_active']===0?' (inactive)':'' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint">Leave blank to apply to all review types.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Default Status</label>
                    <select name="bundle_is_active" class="form-control">
                        <option value="1" selected>Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>

            <hr class="mr-divider">

            <datalist id="catSugg">
                <option value="Innovation"><option value="Market Potential">
                <option value="Team"><option value="Financial Viability">
                <option value="Impact"><option value="Scalability">
            </datalist>

            <div id="catBlocks"></div>

            <div class="mr-form-actions">
                <button type="button" class="btn btn-secondary" id="addCatBtn">
                    <i class="fas fa-folder-plus"></i> Add Category
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save All Criteria
                </button>
            </div>
        </form>
        <?php endif; ?>

        </div>
    </div>

    <!-- Criteria table -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-list-alt"></i> Scoring Criteria</h3>
            <span class="note"><?= number_format($total_criteria_records) ?> total</span>
        </div>

        <!-- Filter bar -->
        <div class="panel-body">
            <form method="GET" action="manage-reviewers" class="filters-bar mr-toolbar" role="search">
                <input type="hidden" name="tab" value="criteria">
                <div class="search-input-wrap filters-grow">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" name="criteria_search" value="<?= h($criteria_search) ?>"
                           class="form-control" placeholder="Search category, question, guide, type...">
                </div>
                <select name="criteria_review_type_id" class="form-control">
                    <option value="">All Review Types</option>
                    <?php foreach ($review_types as $rt): ?>
                        <option value="<?= (int)$rt['review_type_id'] ?>"
                            <?= $criteria_rt_id===(int)$rt['review_type_id']?'selected':'' ?>>
                            <?= h($rt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="manage-reviewers?tab=criteria" class="btn btn-sm btn-secondary"><i class="fas fa-undo"></i> Reset</a>
            </form>
        </div>

        <?php if (empty($criteria)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                <p>No criteria found.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>#</th><th>Category</th><th>Question</th><th>Scoring Guide</th>
                    <th>Review Type</th>
                    <th class="text-center">Max</th>
                    <th class="text-center">Wt</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $prev_g = null; $prev_c = null; $rowNo = $criteria_offset + 1;
                foreach ($criteria as $idx => $c):
                    $gk = !empty($c['review_type_name']) ? $c['review_type_name'] : 'All Types';
                    $ck = $gk.'||'.$c['category'];
                    if ($gk !== $prev_g): $prev_g=$gk; $prev_c=null; ?>
                        <tr class="group-header"><td colspan="9">
                            <i class="fas fa-layer-group"></i> <?= h($gk) ?>
                            <span class="group-sub">(<?= count($criteria_grouped[$gk]??[]) ?> on page)</span>
                        </td></tr>
                    <?php endif;
                    if ((string)$c['category'] !== (string)$prev_c): $prev_c=$c['category']; ?>
                        <tr class="cat-header"><td colspan="9">
                            <i class="fas fa-folder-open"></i> <?= h($c['category']) ?>
                            <span class="group-sub">
                                Total max: <strong><?= number_format($category_totals[$ck]??0,2) ?></strong>
                            </span>
                        </td></tr>
                    <?php endif; ?>
                    <tr class="<?= (int)$c['is_active']===0?'inactive-row':'' ?>">
                        <td class="cell-muted"><?= $rowNo+$idx ?></td>
                        <td><span class="badge badge-warning"><?= h($c['category']) ?></span></td>
                        <td>
                            <div class="hover-preview" title="<?= h(trim($c['question']??'')) ?>">
                                <?= h(preview_words($c['question']??'',18)) ?>
                            </div>
                            <div class="cell-muted">By <?= h($c['created_by_name']) ?> . <?= date('d M Y',strtotime($c['created_at'])) ?></div>
                        </td>
                        <td>
                            <div class="hover-preview" title="<?= h(trim($c['description']??'')) ?>">
                                <?= h(preview_words($c['description']??'',16)) ?: '<span class="cell-muted">-</span>' ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($c['review_type_name'])): ?>
                                <span class="badge badge-purple"><i class="fas fa-layer-group"></i> <?= h($c['review_type_name']) ?></span>
                            <?php else: ?><span class="note">All types</span><?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge badge-info"><?= number_format((float)$c['max_score'],2) ?></span></td>
                        <td class="text-center"><span class="badge badge-secondary">×<?= number_format((float)$c['weight'],1) ?></span></td>
                        <td class="text-center">
                            <?= (int)$c['is_active']===1
                                ? '<span class="badge badge-success">Active</span>'
                                : '<span class="badge badge-secondary">Inactive</span>' ?>
                        </td>
                        <td class="text-center">
                            <div class="mr-actions">
                                <a href="manage-reviewers?tab=criteria&edit_criteria=<?= (int)$c['criteria_id'] ?>" class="btn btn-sm btn-secondary" title="Edit"><i class="fas fa-pen"></i></a>
                                <?= af('delete_criteria','class="mr-inline-form" onsubmit="return confirm(\'Delete this criterion?\')"') ?>
                                    <input type="hidden" name="criteria_id" value="<?= (int)$c['criteria_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($criteria_total_pages > 1): ?>
        <div class="mr-pager">
            <span class="cell-muted">Page <?= $criteria_page ?> of <?= $criteria_total_pages ?></span>
            <div class="pagination">
                <?php foreach (pgRange($criteria_page, $criteria_total_pages) as $pn): ?>
                    <?php if ($pn === '...'): ?>
                        <span class="disabled">...</span>
                    <?php else:
                        $pp = array_merge($crit_base, ['criteria_page'=>(int)$pn]); ?>
                        <a href="manage-reviewers?<?= http_build_query($pp) ?>"
                           class="<?= (int)$pn===$criteria_page?'active':'' ?>"><?= $pn ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary -->
        <div class="criteria-summary">
            <div><span class="lbl">Active Criteria:</span> <strong><?= count($active_criteria) ?></strong></div>
            <div><span class="lbl">Total Max Score:</span> <strong><?= number_format($active_max_total,2) ?></strong></div>
            <div><span class="lbl">Groups On Page:</span> <strong><?= count($criteria_grouped) ?></strong></div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /#tab-criteria -->

</div><!-- /.page-stack -->

<script>
/* -- Tab switching --------------------------------------------- */
document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
        const key = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const panel = document.getElementById('tab-' + key);
        if (panel) panel.classList.add('active');
        history.replaceState(null, '', '?tab=' + encodeURIComponent(key));
    });
});

/* -- Assign tab: Review Type as Step 1 ------------------------ */
function rtSelected() {
    const chosen  = document.querySelector('input[name="review_type_id"]:checked');
    const panels  = document.getElementById('selectPanels');
    const btn     = document.getElementById('assignBtn');
    const hint    = document.getElementById('assignHint');
    const summary = document.getElementById('assignSummary');
    const si1     = document.getElementById('si1');
    const si2     = document.getElementById('si2');
    const si3     = document.getElementById('si3');
    const sc12    = document.getElementById('sc12');

    if (chosen) {
        panels.classList.remove('select-panels-disabled');
        btn.disabled = false;
        hint.style.display = 'none';
        summary.style.display = 'inline';
        si1.classList.remove('active');
        si1.classList.add('done');
        sc12.style.background = 'var(--green-fg)';
        si2.classList.add('active');
        si3.classList.add('active');
        updateSummary();
    } else {
        panels.classList.add('select-panels-disabled');
        btn.disabled = true;
        hint.style.display = 'flex';
        summary.style.display = 'none';
        si1.classList.add('active');
        si1.classList.remove('done');
        sc12.style.background = '';
        si2.classList.remove('active');
        si3.classList.remove('active');
    }
}

function updateSummary() {
    const rt   = document.querySelector('input[name="review_type_id"]:checked');
    const revs = document.querySelectorAll('#reviewerSelect option:checked').length;
    const apps = document.querySelectorAll('#appSelect option:checked').length;
    const el   = document.getElementById('assignSummary');
    if (!rt || !el) return;
    const rtLabel = document.querySelector('label[for="' + rt.id + '"] .rt-tile-name')?.textContent || rt.value;
    el.textContent = `${rtLabel} . ${revs} reviewer${revs!==1?'s':''} . ${apps} app${apps!==1?'s':''}`;
}

document.getElementById('reviewerSelect')?.addEventListener('change', updateSummary);
document.getElementById('appSelect')?.addEventListener('change', updateSummary);

/* -- Status tab: filter + checkbox helpers --------------------- */
function filterAsgn() {
    const q  = (document.getElementById('asgnSearch')?.value || '').toLowerCase().trim();
    const st = document.getElementById('asgnStatusFilter')?.value || '';
    const rt = (document.getElementById('asgnTypeFilter')?.value || '').toLowerCase();
    document.querySelectorAll('#asgnTable tbody tr').forEach(row => {
        if (!row.dataset.status) return;
        const mq = !q  || (row.dataset.search||'').includes(q);
        const ms = !st || row.dataset.status === st;
        const mr = !rt || (row.dataset.rt||'').includes(rt);
        row.style.display = mq && ms && mr ? '' : 'none';
    });
}
document.getElementById('asgnSearch')?.addEventListener('input', filterAsgn);
document.getElementById('asgnStatusFilter')?.addEventListener('change', filterAsgn);
document.getElementById('asgnTypeFilter')?.addEventListener('change', filterAsgn);

document.getElementById('checkAll')?.addEventListener('change', function () {
    document.querySelectorAll('.asgn-check').forEach(cb => {
        if (cb.closest('tr')?.style.display !== 'none') cb.checked = this.checked;
    });
    updateCount();
});

document.getElementById('selectOverdueBtn')?.addEventListener('click', () => {
    document.querySelectorAll('.asgn-check').forEach(cb => {
        cb.checked = cb.dataset.overdue === '1';
    });
    updateCount();
});

function updateCount() {
    const n = document.querySelectorAll('.asgn-check:checked').length;
    const el = document.getElementById('selectedCount');
    if (el) el.textContent = n > 0 ? n + ' selected' : '';
}

/* -- Review types: live search --------------------------------- */
document.getElementById('rtSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#rtTable tbody tr').forEach(row => {
        row.style.display = !q || (row.dataset.rtName||'').includes(q) ? '' : 'none';
    });
});

/* -- Criteria bundle builder ----------------------------------- */
let catCount = 0;
document.getElementById('addCatBtn')?.addEventListener('click', addCat);

function addCat() {
    catCount++;
    const c   = catCount;
    const div = document.createElement('div');
    div.className = 'cat-block';
    div.id = 'cb-' + c;
    div.innerHTML = `
        <div class="cat-block-head">
            <i class="fas fa-folder-open"></i>
            <input type="text" name="categories[${c}][name]" placeholder="Category name..." required list="catSugg">
            <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.cat-block').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="cat-block-body" id="cbb-${c}"></div>
        <div class="cat-block-foot">
            <button type="button" class="btn btn-sm btn-soft" onclick="addQ(${c})">
                <i class="fas fa-plus"></i> Add Question
            </button>
        </div>
    `;
    document.getElementById('catBlocks').appendChild(div);
    addQ(c);
}

const qCnt = {};
function addQ(catId) {
    qCnt[catId] = (qCnt[catId]||0) + 1;
    const q    = qCnt[catId];
    const body = document.getElementById('cbb-' + catId);
    const row  = document.createElement('div');
    row.className = 'criterion-row';
    row.innerHTML = `
        <textarea name="categories[${catId}][questions][${q}][question]"
                  class="form-control" placeholder="Question / criterion..." required></textarea>
        <input type="number" name="categories[${catId}][questions][${q}][max_score]"
               class="form-control num-field" placeholder="Max" min="0" step="0.01" required>
        <input type="number" name="categories[${catId}][questions][${q}][weight]"
               class="form-control num-field" placeholder="Wt" min="0.1" step="0.1" value="1">
        <button type="button" class="remove-row-btn" onclick="this.closest('.criterion-row').remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    body.appendChild(row);
}

/* -- Auto-dismiss flash alert ---------------------------------- */
const fa = document.getElementById('flashAlert');
if (fa) setTimeout(() => { fa.style.transition='opacity .4s'; fa.style.opacity='0'; setTimeout(()=>fa.remove(),440); }, 4000);
</script>

<?php require_once 'includes/footer.php'; ?>