<?php
ob_start();

$page_title = 'Manage Applications';
require_once 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| OPPORTUNITY
|--------------------------------------------------------------------------
*/
$opportunity_id = isset($_GET['opportunity_id']) ? (int)$_GET['opportunity_id'] : 0;
if (!$opportunity_id) {
    $_SESSION['error'] = 'Invalid opportunity ID.';
    header('Location: application-opportunities');
    exit();
}

$opp_stmt = $conn->prepare('SELECT * FROM application_opportunities WHERE opportunity_id = ?');
$opp_stmt->bind_param('i', $opportunity_id);
$opp_stmt->execute();
$opp_result = $opp_stmt->get_result();

if (!$opp_result || $opp_result->num_rows === 0) {
    $_SESSION['error'] = 'Opportunity not found.';
    header('Location: application-opportunities');
    exit();
}
$opportunity = $opp_result->fetch_assoc();
$opp_stmt->close();

/*
|--------------------------------------------------------------------------
| FILTERS & SORT
|--------------------------------------------------------------------------
*/
$filter_status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$filter_stage  = isset($_GET['stage'])  ? sanitize_input($_GET['stage'])  : '';
$search        = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$sort          = isset($_GET['sort'])   ? sanitize_input($_GET['sort'])   : 'submitted_at';
$order         = isset($_GET['order'])  ? strtoupper(sanitize_input($_GET['order'])) : 'DESC';
$export        = isset($_GET['export']) ? sanitize_input($_GET['export']) : '';

if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

$valid_sorts = ['submitted_at', 'startup_name', 'status', 'business_stage', 'avg_score', 'review_count'];
if (!in_array($sort, $valid_sorts, true)) $sort = 'submitted_at';

/*
|--------------------------------------------------------------------------
| QUERY
|--------------------------------------------------------------------------
*/
$where  = ['a.opportunity_id = ?'];
$types  = 'i';
$params = [$opportunity_id];

if ($filter_status !== '') {
    $where[] = 'a.status = ?';
    $types  .= 's';
    $params[] = $filter_status;
}
if ($filter_stage !== '') {
    $where[] = 'a.business_stage = ?';
    $types  .= 's';
    $params[] = $filter_stage;
}
if ($search !== '') {
    $where[] = '(a.startup_name LIKE ? OR a.contact_person LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)';
    $types  .= 'ssss';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$order_sql = match ($sort) {
    'startup_name'   => "a.startup_name $order",
    'status'         => "a.status $order",
    'business_stage' => "a.business_stage $order",
    'avg_score'      => "agg.avg_score $order",
    'review_count'   => "agg.review_count $order",
    default          => "a.submitted_at $order",
};

$sql = "
    SELECT
        a.application_id,
        a.startup_name,
        a.business_stage,
        a.team_size,
        a.status,
        a.submitted_at,
        a.contact_person,
        a.email,
        a.phone,
        a.funding_sought,
        u.full_name AS applicant_name,
        COALESCE(agg.review_count, 0) AS review_count,
        COALESCE(agg.avg_score,    0) AS avg_score
    FROM applications a
    LEFT JOIN users u ON u.user_id = a.submitted_by
    LEFT JOIN (
        SELECT application_id,
               COUNT(*)                       AS review_count,
               ROUND(AVG(overall_score), 1)   AS avg_score
        FROM application_reviews
        GROUP BY application_id
    ) agg ON agg.application_id = a.application_id
    $where_sql
    ORDER BY $order_sql
";

$applications = [];
$stmt = $conn->prepare($sql);
if (!$stmt) die('SQL prepare failed: ' . $conn->error);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $applications[] = $row;
$stmt->close();

/*
|--------------------------------------------------------------------------
| CSV EXPORT
|--------------------------------------------------------------------------
*/
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=applications_' . $opportunity_id . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    ims_fputcsv($out, ['ID','Startup','Stage','Team Size','Status','Submitted','Contact','Email','Phone','Funding Sought','Applicant','Reviews','Avg Score']);
    foreach ($applications as $a) {
        ims_fputcsv($out, [$a['application_id'],$a['startup_name'],$a['business_stage'],$a['team_size'],$a['status'],
                       $a['submitted_at'],$a['contact_person'],$a['email'],$a['phone'],$a['funding_sought'],
                       $a['applicant_name'],$a['review_count'],$a['avg_score']]);
    }
    fclose($out);
    exit();
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
$stats = ['total'=>count($applications),'submitted'=>0,'under_review'=>0,'shortlisted'=>0,'accepted'=>0,'rejected'=>0];
foreach ($applications as $a) {
    $st = strtoupper(trim($a['status'] ?? ''));
    if ($st === 'SUBMITTED')    $stats['submitted']++;
    if ($st === 'UNDER REVIEW') $stats['under_review']++;
    if ($st === 'SHORTLISTED')  $stats['shortlisted']++;
    if ($st === 'ACCEPTED')     $stats['accepted']++;
    if ($st === 'REJECTED')     $stats['rejected']++;
}

$stages = array_values(array_filter(array_unique(array_map(fn($x) => $x['business_stage'] ?? '', $applications))));
sort($stages);

$scored = array_filter($applications, fn($a) => (float)($a['avg_score'] ?? 0) > 0);
$avg_score = !empty($scored)
    ? round(array_sum(array_map(fn($a) => (float)$a['avg_score'], $scored)) / count($scored), 1)
    : 0;

$status_options = ['Draft','Submitted','Under Review','Approved','Rejected','Waitlisted','Shortlisted','Accepted'];

/*
|--------------------------------------------------------------------------
| DRAFT REMINDERS
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/application-reminders.php';
$rem_ready     = apprem_ensure_schema($conn);
$rem_settings  = $rem_ready ? apprem_settings($conn) : ['enabled' => false, 'days_before' => [], 'updated_at' => null];
$rem_last      = $rem_ready ? apprem_last_reminded($conn, $opportunity_id) : [];
$rem_open      = $rem_ready ? apprem_fetch_drafts($conn, $opportunity_id) : [];
$rem_open_ids  = array_fill_keys(array_map(fn($d) => (int)$d['application_id'], $rem_open), true);
$rem_no_email  = count(array_filter($rem_open, fn($d) => !filter_var(trim((string)($d['email'] ?? '')), FILTER_VALIDATE_EMAIL)));
$rem_deadline  = (string)($opportunity['deadline'] ?? '');
$rem_opp_open  = ($opportunity['status'] ?? '') === 'Published' && $rem_deadline !== '' && substr($rem_deadline, 0, 10) >= date('Y-m-d');
$rem_days_left = $rem_opp_open ? apprem_days_left($rem_deadline) : null;
$rem_is_admin  = ($_SESSION['role'] ?? '') === 'Administrator';
$rem_recent    = $rem_ready ? apprem_recent_ids($conn, $opportunity_id, 3600) : [];
$rem_scope_all = $rem_is_admin && (($_GET['rem_scope'] ?? '') === 'all');
$rem_stats     = $rem_ready ? apprem_stats($conn, $rem_scope_all ? 0 : $opportunity_id) : null;
$rem_dt        = fn($v) => $v ? date('d M Y, H:i', strtotime((string)$v)) : '-';
$rem_modal_data = [];
foreach ($rem_open as $d) {
    $did = (int)$d['application_id'];
    $rem_modal_data[] = [
        'id'       => $did,
        'startup'  => (string)($d['startup_name'] ?? ''),
        'contact'  => (string)($d['contact_name'] ?? ''),
        'email'    => (string)($d['email'] ?? ''),
        'hasEmail' => (bool)filter_var(trim((string)($d['email'] ?? '')), FILTER_VALIDATE_EMAIL),
        'recent'   => isset($rem_recent[$did]),
        'last'     => isset($rem_last[$did]) ? date('d M Y, H:i', strtotime($rem_last[$did]['sent_at'])) : '',
        'count'    => (int)($rem_last[$did]['count'] ?? 0),
    ];
}
$rem_days_text = $rem_settings['days_before']
    ? implode(', ', array_map(fn($d) => $d === 0 ? 'on the deadline day' : $d . ' day' . ($d === 1 ? '' : 's'), $rem_settings['days_before']))
    : 'no days set';
?>

<style>
/* -- manage-applications page-specific layout -- */

/* Sortable column headers */
.ma-table th.sortable { cursor: pointer; user-select: none; transition: color .12s, background .12s; }
.ma-table th.sortable:hover,
.ma-table th.sort-active { color: var(--brand-600); background: var(--brand-50); }
.ma-table th .sort-icon { margin-left: 4px; font-size: 10px; opacity: .6; }
.ma-table th.ma-col-check { width: 44px; }
.ma-table th.ma-col-actions { width: 130px; }

.ma-check { width: 15px; height: 15px; accent-color: var(--brand-500); }

/* Startup name cell */
.ma-startup-name { font-size: 13.5px; font-weight: 700; color: var(--ink-700); margin-bottom: 3px; }
.ma-startup-sub { font-size: 11.5px; color: var(--ink-300); display: flex; gap: 10px; flex-wrap: wrap; }
.ma-startup-sub i { color: var(--brand-400); }
.ma-cell-sm { font-size: 12px; }

/* Score circle */
.ma-score {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    font-weight: 800;
    font-size: 13px;
    flex-shrink: 0;
}
.ma-score.high   { background: var(--green-bg); color: var(--green-fg); border: 2px solid var(--green-border); }
.ma-score.medium { background: var(--amber-bg); color: var(--amber-fg); border: 2px solid var(--amber-border); }
.ma-score.low    { background: var(--red-bg);   color: var(--red-fg);   border: 2px solid var(--red-border); }
.ma-score.none   { color: var(--ink-200); font-size: 18px; }

.ma-actions { display: flex; gap: 5px; align-items: center; }

/* Utility buttons under the filters */
.ma-utility-row { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 10px; border-top: 1px solid var(--ink-50); }
.ma-filters { margin-bottom: 12px; }

/* Bulk actions bar */
.ma-bulk-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    background: var(--brand-50);
    border-bottom: 1px solid var(--brand-100);
    font-size: 13px;
}
.ma-bulk-label { display: flex; align-items: center; gap: 7px; font-weight: 700; color: var(--ink-700); cursor: pointer; margin: 0; }
.ma-bulk-divider { width: 1px; height: 20px; background: var(--brand-200); flex-shrink: 0; }
.ma-selected-count { font-weight: 700; color: var(--brand-600); white-space: nowrap; }
.ma-bulk-bar .form-control { width: auto; min-width: 200px; margin: 0; }

.ma-notify { font-weight: 600; font-size: 12.5px; white-space: nowrap; }

/* Tabs */
.ma-tabs { margin-bottom: 0; }
.ma-tab-panel.active { display: flex; flex-direction: column; gap: 20px; }

.ma-reminded { display: block; font-size: 11px; color: var(--ink-300); margin-top: 4px; white-space: nowrap; }
.ma-rem-body { display: grid; gap: 14px; }
.ma-rem-summary { margin: 0; font-size: 13.5px; color: var(--ink-600); }
.ma-rem-forms { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
.ma-rem-box { border: 1px solid var(--ink-50); border-radius: 10px; padding: 14px; }
.ma-rem-box h4 { margin: 0 0 8px; font-size: 13.5px; }
.ma-rem-box .form-group { margin-bottom: 10px; }
.ma-rem-intro { margin: 0 0 12px; font-size: 13.5px; }
.ma-rem-list { max-height: 260px; overflow-y: auto; border: 1px solid var(--ink-50); border-radius: 8px; margin-bottom: 14px; }
.ma-rem-list table { width: 100%; margin: 0; font-size: 12.5px; }
.ma-rs-meta { margin: 0; font-size: 12.5px; color: var(--ink-400); }
.ma-rs-section { border: 1px solid var(--ink-50); border-radius: 10px; }
.ma-rs-section > summary { cursor: pointer; padding: 10px 14px; font-weight: 600; font-size: 13.5px; }
.ma-rs-section .table-responsive { max-height: 360px; overflow: auto; }
.ma-rs-section table { width: 100%; margin: 0; font-size: 12.5px; }
.ma-rs-err { color: var(--red-fg, #c0392b); font-size: 11.5px; overflow-wrap: anywhere; }
.ma-rs-scope { font-size: 12.5px; font-weight: 400; }
.ma-rem-list th { position: sticky; top: 0; background: var(--surface, #fff); }
.ma-rem-list td, .ma-rem-list th { padding: 7px 10px; vertical-align: top; }
.ma-rem-list tr.is-skipped td { color: var(--ink-300); }
.ma-rem-sub { display: block; font-size: 11.5px; color: var(--ink-300); }
.ma-rem-flag { display: inline-block; font-size: 11px; font-weight: 700; }
.ma-rem-flag.ok { color: var(--green-fg); }
.ma-rem-flag.skip { color: var(--amber-fg); }

@media (max-width: 640px) {
    .ma-bulk-bar { flex-direction: column; align-items: stretch; }
    .ma-bulk-bar .form-control { min-width: 0; width: 100%; }
}
</style>

<div class="page-stack">

<!-- -- Hero -------------------------------------------------- -->
<div class="page-hero">
    <div>
        <h1><i class="fas fa-file-alt" aria-hidden="true"></i> Manage Applications</h1>
        <p><?= h($opportunity['opportunity_type'] ?? 'Opportunity') ?>: <?= h($opportunity['opportunity_title']) ?></p>
    </div>
    <div class="hero-actions">
        <a href="application-opportunities" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
        </a>
        <a href="view-opportunity?id=<?= $opportunity_id ?>" class="btn btn-primary">
            <i class="fas fa-eye" aria-hidden="true"></i> View Opportunity
        </a>
    </div>
</div>

<!-- -- Stats -------------------------------------------------- -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-file-alt"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total</span>
            <span class="stat-value"><?= (int)$stats['total'] ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-blue">
            <i class="fas fa-paper-plane"></i>
        </div>
        <div class="stat-info">
            <span class="stat-label">Submitted</span>
            <span class="stat-value"><?= (int)$stats['submitted'] ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-eye"></i></div>
        <div class="stat-info">
            <span class="stat-label">Reviewing</span>
            <span class="stat-value"><?= (int)$stats['under_review'] ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-star"></i></div>
        <div class="stat-info">
            <span class="stat-label">Shortlisted</span>
            <span class="stat-value"><?= (int)$stats['shortlisted'] ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info">
            <span class="stat-label">Accepted</span>
            <span class="stat-value"><?= (int)$stats['accepted'] ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-teal">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-info">
            <span class="stat-label">Avg Score</span>
            <span class="stat-value"><?= number_format($avg_score, 1) ?></span>
        </div>
    </div>
</div>

<!-- -- Tabs ---------------------------------------------------- -->
<?php
$ma_tabs = ['applications' => ['fa-list', 'Applications', (int)$stats['total']]];
if ($rem_ready) {
    $ma_tabs['reminders'] = ['fa-bell', 'Draft reminders', count($rem_open)];
    if ($rem_stats !== null) {
        $ma_tabs['stats'] = ['fa-chart-bar', 'Reminder statistics', (int)$rem_stats['totals']['attempts']];
    }
}
$ma_tab = (string)($_GET['tab'] ?? 'applications');
if (!isset($ma_tabs[$ma_tab])) $ma_tab = 'applications';
?>
<div class="tab-shell ma-tabs">
<nav class="tab-nav" role="tablist" aria-label="Manage applications sections">
    <?php foreach ($ma_tabs as $key => [$ico, $lbl, $cnt]): ?>
    <button class="tab-btn<?= $ma_tab === $key ? ' active' : '' ?>" type="button" role="tab"
            id="ma-tabbtn-<?= h($key) ?>" aria-controls="ma-tab-<?= h($key) ?>"
            aria-selected="<?= $ma_tab === $key ? 'true' : 'false' ?>" data-ma-tab="<?= h($key) ?>">
        <i class="fas <?= h($ico) ?>" aria-hidden="true"></i> <?= h($lbl) ?>
        <span class="badge badge-secondary"><?= (int)$cnt ?></span>
    </button>
    <?php endforeach; ?>
</nav>
</div>

<div id="ma-tab-applications" class="tab-panel ma-tab-panel<?= $ma_tab === 'applications' ? ' active' : '' ?>" role="tabpanel" aria-labelledby="ma-tabbtn-applications">
<!-- -- Filter bar ---------------------------------------------- -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="">
            <input type="hidden" name="opportunity_id" value="<?= $opportunity_id ?>">

            <div class="filters-bar ma-filters" role="search" aria-label="Filter applications">

                <!-- Search -->
                <div class="search-input-wrap filters-grow">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="text" name="search" class="form-control"
                           placeholder="Startup, contact, email, phone..."
                           value="<?= h($search) ?>">
                </div>

                <!-- Status -->
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <?php foreach ($status_options as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $filter_status === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Stage -->
                <?php if (!empty($stages)): ?>
                <select name="stage" class="form-control">
                    <option value="">All Stages</option>
                    <?php foreach ($stages as $stage): ?>
                        <option value="<?= h($stage) ?>" <?= $filter_stage === $stage ? 'selected' : '' ?>><?= h($stage) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <?php if ($filter_status !== '' || $filter_stage !== '' || $search !== ''): ?>
                    <a href="manage-applications?opportunity_id=<?= $opportunity_id ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </div>

            <!-- Utility buttons row -->
            <div class="ma-utility-row">
                <button type="button" onclick="exportApplications()" class="btn btn-success btn-sm">
                    <i class="fas fa-download"></i> Export CSV
                </button>
                <a href="application-csv-import?opportunity_id=<?= $opportunity_id ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-csv"></i> CSV Import
                </a>
            </div>
        </form>
    </div>
</div>

<!-- -- Applications table (or empty state) --------------------- -->
<?php if (!empty($applications)): ?>

<form method="POST" action="review-application-process" id="bulkForm">
    <input type="hidden" name="action" value="bulk_status_change">
    <input type="hidden" name="opportunity_id" value="<?= $opportunity_id ?>">

    <div class="card">

        <!-- Bulk actions bar -->
        <div class="ma-bulk-bar">
            <label class="ma-bulk-label">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="ma-check">
                Select All
            </label>
            <div class="ma-bulk-divider"></div>
            <span id="selectedCount" class="ma-selected-count">0 selected</span>
            <div class="ma-bulk-divider"></div>
            <select name="new_status" class="form-control" required>
                <option value="">Change status to...</option>
                <option value="Under Review">Under Review</option>
                <option value="Shortlisted">Shortlisted</option>
                <option value="Accepted">Accepted</option>
                <option value="Rejected">Rejected</option>
            </select>
            <label class="ma-bulk-label ma-notify" title="Shortlisted, Accepted and Rejected applicants get an email about their new status">
                <input type="hidden" name="notify_applicant" value="0">
                <input type="checkbox" name="notify_applicant" value="1" id="bulkNotify" checked> Email applicants
            </label>
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkAction()">
                <i class="fas fa-check"></i> Apply
            </button>
            <?php if ($rem_ready && $rem_opp_open && $rem_open): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openRemindModal('selected')">
                    <i class="fas fa-bell" aria-hidden="true"></i> Remind selected drafts
                </button>
            <?php endif; ?>
            <?php if (($_SESSION['role'] ?? '') === 'Administrator'): ?>
                <button type="submit" name="action" value="bulk_delete_applications" formnovalidate class="btn btn-danger btn-sm" onclick="return confirmBulkDelete()">
                    <i class="fas fa-trash" aria-hidden="true"></i> Delete selected
                </button>
            <?php endif; ?>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table ma-table">
                <thead>
                    <tr>
                        <th class="ma-col-check">
                            <input type="checkbox" id="selectAllHeader" onchange="toggleSelectAll(this)" class="ma-check">
                        </th>

                        <?php
                        $cols = [
                            'startup_name'   => 'Startup',
                            'business_stage' => 'Stage',
                            'avg_score'      => 'Score',
                            'review_count'   => 'Reviews',
                            'status'         => 'Status',
                            'submitted_at'   => 'Submitted',
                        ];
                        foreach ($cols as $col => $label):
                            $isActive = ($sort === $col);
                            $nextOrder = ($isActive && $order === 'ASC') ? 'DESC' : 'ASC';
                            $icon = $isActive ? ($order === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
                        ?>
                        <th class="sortable <?= $isActive ? 'sort-active' : '' ?>"
                            onclick="sortBy('<?= $col ?>')">
                            <?= $label ?>
                            <i class="fas <?= $icon ?> sort-icon"></i>
                        </th>
                        <?php endforeach; ?>

                        <th class="ma-col-actions">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($applications as $app):
                        $st    = (string)($app['status'] ?? 'Draft');
                        $score = (float)($app['avg_score'] ?? 0);
                        $scoreClass = $score >= 7 ? 'high' : ($score >= 5 ? 'medium' : 'low');

                        $appBadge = match(true) {
                            $st === 'Accepted'    => 'badge-success',
                            $st === 'Rejected'    => 'badge-danger',
                            $st === 'Shortlisted' => 'badge-warning',
                            $st === 'Under Review'=> 'badge-info',
                            $st === 'Waitlisted'  => 'badge-purple',
                            default               => 'badge-secondary',
                        };
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   name="selected_applications[]"
                                   value="<?= (int)$app['application_id'] ?>"
                                   class="app-checkbox ma-check"
                                   onchange="updateSelectedCount()">
                        </td>

                        <td>
                            <div class="ma-startup-name"><?= h($app['startup_name']) ?></div>
                            <div class="ma-startup-sub">
                                <span><i class="fas fa-user"></i> <?= h($app['contact_person']) ?></span>
                                <span><i class="fas fa-envelope"></i> <?= h($app['email']) ?></span>
                            </div>
                        </td>

                        <td class="ma-cell-sm">
                            <?= h($app['business_stage'] ?? '-') ?>
                        </td>

                        <td>
                            <?php if ((int)$app['review_count'] > 0): ?>
                                <div class="ma-score <?= $scoreClass ?>">
                                    <?= number_format($score, 1) ?>
                                </div>
                            <?php else: ?>
                                <div class="ma-score none"><i class="fas fa-minus"></i></div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge badge-secondary"><?= (int)$app['review_count'] ?></span>
                        </td>

                        <td>
                            <span class="badge <?= $appBadge ?>"><?= h($st) ?></span>
                            <?php if ($st === 'Draft' && isset($rem_last[(int)$app['application_id']])):
                                $rl = $rem_last[(int)$app['application_id']]; ?>
                                <span class="ma-reminded" title="<?= (int)$rl['count'] ?> reminder(s) sent">
                                    <i class="fas fa-bell" aria-hidden="true"></i> Reminded <?= h(date('d M', strtotime($rl['sent_at']))) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="cell-muted ma-cell-sm text-nowrap">
                            <?= !empty($app['submitted_at'])
                                ? h(date('d M Y', strtotime($app['submitted_at'])))
                                : '-' ?>
                        </td>

                        <td>
                            <div class="ma-actions">
                                <a href="view-application?id=<?= (int)$app['application_id'] ?>"
                                   class="btn btn-secondary btn-sm" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>

                                <?php if ($st === 'Draft' && isset($rem_open_ids[(int)$app['application_id']])): ?>
                                    <button type="button"
                                            onclick="openRemindModal('one', <?= (int)$app['application_id'] ?>)"
                                            class="btn btn-secondary btn-sm" title="Email a reminder to finish this draft">
                                        <i class="fas fa-bell" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if (in_array($st, ['Submitted', 'Under Review'], true)): ?>
                                    <button type="button"
                                            onclick="quickShortlist(<?= (int)$app['application_id'] ?>)"
                                            class="btn btn-warning btn-sm" title="Shortlist">
                                        <i class="fas fa-star"></i>
                                    </button>
                                    <button type="button"
                                            onclick="quickAccept(<?= (int)$app['application_id'] ?>)"
                                            class="btn btn-success btn-sm" title="Accept">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div><!-- /.table-responsive -->

    </div><!-- /.card -->
</form>

<?php else: ?>

<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <i class="fas fa-inbox" aria-hidden="true"></i>
            <p>
                <?php if ($filter_status !== '' || $filter_stage !== '' || $search !== ''): ?>
                    No applications match your current filters. Try adjusting or clearing them.
                <?php else: ?>
                    This opportunity hasn't received any applications yet.
                <?php endif; ?>
            </p>
            <?php if ($filter_status !== '' || $filter_stage !== '' || $search !== ''): ?>
                <a href="manage-applications?opportunity_id=<?= $opportunity_id ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- /#ma-tab-applications -->

<?php if ($rem_ready): ?>
<div id="ma-tab-reminders" class="tab-panel ma-tab-panel<?= $ma_tab === 'reminders' ? ' active' : '' ?>" role="tabpanel" aria-labelledby="ma-tabbtn-reminders">
<!-- -- Draft reminders ---------------------------------------- -->
<div class="card" id="draft-reminders">
    <div class="card-header">
        <h3><i class="fas fa-bell" aria-hidden="true"></i> Draft reminders</h3>
    </div>
    <div class="card-body ma-rem-body">
        <p class="ma-rem-summary">
            <?php if ($rem_opp_open): ?>
                <strong><?= count($rem_open) ?></strong> unsubmitted draft<?= count($rem_open) === 1 ? '' : 's' ?>.
                The deadline is <?= h(date('d M Y', strtotime($rem_deadline))) ?>
                (<?= $rem_days_left === 0 ? 'today' : ($rem_days_left === 1 ? 'tomorrow' : 'in ' . (int)$rem_days_left . ' days') ?>).
                <?php if ($rem_no_email > 0): ?><?= (int)$rem_no_email ?> draft<?= $rem_no_email === 1 ? ' has' : 's have' ?> no valid email address and can't be reminded.<?php endif; ?>
            <?php else: ?>
                Reminders are only sent while the opportunity is Published and its deadline has not passed.
            <?php endif; ?>
            Automatic reminders are <strong><?= $rem_settings['enabled'] ? 'on' : 'off' ?></strong><?php if ($rem_settings['enabled']): ?>: <?= h($rem_days_text) ?> before the deadline<?php endif; ?> (all opportunities).
        </p>

        <div class="ma-rem-forms">
            <div class="ma-rem-box">
                <h4>Remind now</h4>
                <p class="form-hint">Email every unsubmitted draft for this opportunity, with an optional personal note. You'll see the full list before anything is sent.</p>
                <button type="button" class="btn btn-primary btn-sm" onclick="openRemindModal('all')" <?= ($rem_opp_open && $rem_open) ? '' : 'disabled' ?>>
                    <i class="fas fa-paper-plane" aria-hidden="true"></i> Remind all drafts (<?= count($rem_open) ?>)
                </button>
                <p class="form-hint">To remind only some, tick them in the table and use <em>Remind selected drafts</em>, or use the <i class="fas fa-bell" aria-hidden="true"></i> button on a row.</p>
            </div>

            <?php if ($rem_is_admin): ?>
            <form method="POST" action="review-application-process" class="ma-rem-box">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_draft_reminder_settings">
                <input type="hidden" name="opportunity_id" value="<?= $opportunity_id ?>">
                <h4>Automatic reminders</h4>
                <div class="form-group">
                    <label class="hr-check"><input type="checkbox" name="enabled" value="1" <?= $rem_settings['enabled'] ? 'checked' : '' ?>> Email draft applicants automatically</label>
                </div>
                <div class="form-group">
                    <label for="rem_days">Days before the deadline</label>
                    <input id="rem_days" type="text" name="days_before" class="form-control" required
                           pattern="\s*\d{1,2}(\s*,\s*\d{1,2})*\s*"
                           value="<?= h(implode(',', $rem_settings['days_before'])) ?>">
                    <p class="form-hint">Comma-separated, e.g. 7,3,1. Use 0 for the deadline day. Applies to every published opportunity and needs the daily <code>cron/application-draft-reminders.php</code> job.</p>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- /#ma-tab-reminders -->

<?php if ($rem_stats !== null): ?>
<div id="ma-tab-stats" class="tab-panel ma-tab-panel<?= $ma_tab === 'stats' ? ' active' : '' ?>" role="tabpanel" aria-labelledby="ma-tabbtn-stats">
<?php if ($rem_stats !== null):
    $rt = $rem_stats['totals'];
    $rate = $rt['attempts'] > 0 ? round($rt['sent'] / $rt['attempts'] * 100) : 0;
?>
<div class="card" id="reminder-stats">
    <div class="card-header">
        <h3><i class="fas fa-chart-bar" aria-hidden="true"></i> Reminder statistics</h3>
        <?php if ($rem_is_admin): ?>
            <span class="ma-rs-scope">
                <?php if ($rem_scope_all): ?>
                    All opportunities &middot; <a href="manage-applications?opportunity_id=<?= $opportunity_id ?>&amp;tab=stats">This opportunity only</a>
                <?php else: ?>
                    This opportunity &middot; <a href="manage-applications?opportunity_id=<?= $opportunity_id ?>&amp;rem_scope=all&amp;tab=stats">All opportunities</a>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body ma-rem-body">
        <div class="stats-grid reports-stats">
            <?php foreach ([
                ['bg-primary', 'fa-envelope',        'Attempts',           (string)(int)$rt['attempts']],
                ['bg-green',   'fa-check-circle',    'Sent',               (string)(int)$rt['sent']],
                ['bg-red',     'fa-circle-xmark',    'Failed',             (string)(int)$rt['failed']],
                ['bg-teal',    'fa-percent',         'Success Rate',       $rate . '%'],
                ['bg-purple',  'fa-user-check',      'Applicants Reached', (string)(int)$rt['applicants']],
                ['bg-blue',    'fa-robot',           'Automatic',          (string)(int)$rt['auto']],
                ['bg-amber',   'fa-hand-pointer',    'Manual',             (string)(int)$rt['manual']],
            ] as [$bg, $ico, $lbl, $val]): ?>
            <div class="stat-card">
                <div class="stat-icon <?= $bg ?>"><i class="fas <?= $ico ?>" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label"><?= h($lbl) ?></span>
                    <span class="stat-value"><?= h($val) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="ma-rs-meta">
            <?php if ($rt['attempts'] > 0): ?>
                First reminder <?= h($rem_dt($rt['first_at'])) ?> &middot; last reminder <?= h($rem_dt($rt['last_at'])) ?>.
            <?php else: ?>
                No reminders have been sent yet.
            <?php endif; ?>
        </p>

        <?php if ($rem_stats['senders']): ?>
        <details class="ma-rs-section" open>
            <summary>Who sent them (<?= count($rem_stats['senders']) ?>)</summary>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Sent by</th><th>Attempts</th><th>Sent</th><th>Failed</th><th>Last sent</th></tr></thead>
                    <tbody>
                    <?php foreach ($rem_stats['senders'] as $sr): ?>
                        <tr>
                            <td><strong><?= h($sr['name']) ?></strong><?php if (!empty($sr['role'])): ?><div class="form-hint"><?= h($sr['role']) ?></div><?php endif; ?></td>
                            <td><?= (int)$sr['attempts'] ?></td>
                            <td><span class="badge badge-success"><?= (int)$sr['sent'] ?></span></td>
                            <td><?php if ((int)$sr['failed'] > 0): ?><span class="badge badge-danger"><?= (int)$sr['failed'] ?></span><?php else: ?>0<?php endif; ?></td>
                            <td><?= h($rem_dt($sr['last_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($rem_stats['applicants']): ?>
        <details class="ma-rs-section">
            <summary>Per applicant (<?= count($rem_stats['applicants']) ?>)</summary>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Applicant</th><th>Status</th><th>Times reminded</th><th>Sent</th><th>Failed</th><th>Auto / manual</th><th>Last attempt</th></tr></thead>
                    <tbody>
                    <?php foreach ($rem_stats['applicants'] as $ar): ?>
                        <tr>
                            <td>
                                <strong><?= h($ar['startup_name'] ?: ('#' . (int)$ar['application_id'])) ?></strong>
                                <div class="form-hint"><?= h($ar['contact']) ?><?= !empty($ar['email']) ? ' &middot; ' . h($ar['email']) : '' ?></div>
                                <?php if (!empty($ar['last_error'])): ?><div class="ma-rs-err"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <?= h($ar['last_error']) ?></div><?php endif; ?>
                            </td>
                            <td><?= h($ar['status'] ?? '-') ?></td>
                            <td><?= (int)$ar['attempts'] ?></td>
                            <td><span class="badge badge-success"><?= (int)$ar['sent'] ?></span></td>
                            <td><?php if ((int)$ar['failed'] > 0): ?><span class="badge badge-danger"><?= (int)$ar['failed'] ?></span><?php else: ?>0<?php endif; ?></td>
                            <td><?= (int)$ar['auto'] ?> / <?= (int)$ar['manual'] ?></td>
                            <td><?= h($rem_dt($ar['last_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($rem_stats['recent']): ?>
        <details class="ma-rs-section">
            <summary>Recent activity (last <?= count($rem_stats['recent']) ?>)</summary>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>When</th><th>Applicant</th><th>Email</th><th>Type</th><th>Sent by</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($rem_stats['recent'] as $lr): ?>
                        <tr>
                            <td><?= h($rem_dt($lr['sent_at'])) ?></td>
                            <td><?= h($lr['startup_name'] ?: ('#' . (int)$lr['application_id'])) ?></td>
                            <td><?= h($lr['email'] ?: '-') ?></td>
                            <td><?= $lr['kind'] === 'auto' ? 'Automatic' : 'Manual' ?></td>
                            <td><?= h($lr['sent_by_name']) ?></td>
                            <td>
                                <?php if ((int)$lr['emailed'] === 1): ?>
                                    <span class="badge badge-success">Sent</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Failed</span>
                                    <?php if (!empty($lr['error'])): ?><div class="ma-rs-err"><?= h($lr['error']) ?></div><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- /#ma-tab-stats -->

<?php endif; ?>

<!-- Reminder modal lives outside the panels so it opens from any tab. -->
<div id="remindDraftsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="remindDraftsTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="remindDraftsTitle"><i class="fas fa-bell" aria-hidden="true"></i> Send draft reminder</h3>
            <button type="button" class="modal-close" onclick="closeModal('remindDraftsModal')" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="review-application-process" id="remindDraftsForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remind_drafts">
            <input type="hidden" name="opportunity_id" value="<?= $opportunity_id ?>">
            <input type="hidden" name="remind_scope" id="remindScope" value="">
            <div id="remindIds"></div>

            <div class="modal-body">
                <p class="ma-rem-intro" id="remindIntro"></p>

                <div class="ma-rem-list">
                    <table class="table">
                        <thead><tr><th>Applicant</th><th>Email</th><th>Last reminded</th><th></th></tr></thead>
                        <tbody id="remindRows"></tbody>
                    </table>
                </div>

                <div class="form-group">
                    <label for="rem_note">Personal note (optional)</label>
                    <textarea id="rem_note" name="reminder_note" class="form-control" rows="3" maxlength="1000"
                              placeholder="e.g. Remember to upload your pitch deck before submitting."></textarea>
                    <p class="form-hint">Added to the standard reminder, which states the deadline<?= $rem_deadline !== '' ? ' (' . h(date('d M Y', strtotime($rem_deadline))) . ')' : '' ?>, the draft reference and a link to My Applications.</p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('remindDraftsModal')" class="btn btn-secondary"><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" id="remindSubmit"><i class="fas fa-paper-plane" aria-hidden="true"></i> <span>Send reminder</span></button>
            </div>
        </form>
    </div>
</div>
<script type="application/json" id="remindDraftsData"><?= json_encode($rem_modal_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<script>
/* -- Tabs: Applications / Draft reminders / Reminder statistics -- */
(function () {
    const buttons = Array.from(document.querySelectorAll('[data-ma-tab]'));
    if (!buttons.length) return;

    function show(key, updateUrl) {
        const btn = buttons.find(b => b.dataset.maTab === key);
        if (!btn) return;
        buttons.forEach(b => {
            const on = b === btn;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.ma-tab-panel').forEach(p => p.classList.toggle('active', p.id === 'ma-tab-' + key));
        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', key);
            url.hash = '';
            history.replaceState(null, '', url);
        }
    }

    buttons.forEach(b => b.addEventListener('click', () => show(b.dataset.maTab, true)));

    // Arrow keys move between tabs.
    document.querySelector('.ma-tabs .tab-nav')?.addEventListener('keydown', e => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const i = buttons.findIndex(b => b.classList.contains('active'));
        const next = buttons[(i + (e.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length];
        show(next.dataset.maTab, true);
        next.focus();
    });

    // Old links such as #draft-reminders / #reminder-stats open the right tab.
    const hashTabs = { '#draft-reminders': 'reminders', '#reminder-stats': 'stats' };
    if (hashTabs[window.location.hash]) show(hashTabs[window.location.hash], false);

    window.maShowTab = show;
})();

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.app-checkbox').forEach(cb => cb.checked = checkbox.checked);
    const a = document.getElementById('selectAll');
    const b = document.getElementById('selectAllHeader');
    if (a) a.checked = checkbox.checked;
    if (b) b.checked = checkbox.checked;
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.app-checkbox:checked').length;
    const total   = document.querySelectorAll('.app-checkbox').length;
    const el = document.getElementById('selectedCount');
    if (el) el.textContent = checked + ' selected';
    const allChecked = (checked === total && total > 0);
    const a = document.getElementById('selectAll');
    const b = document.getElementById('selectAllHeader');
    if (a) a.checked = allChecked;
    if (b) b.checked = allChecked;
}

function confirmBulkAction() {
    const checked = document.querySelectorAll('.app-checkbox:checked').length;
    const status  = document.querySelector('select[name="new_status"]').value;
    if (checked === 0) { alert('Please select at least one application.'); return false; }
    if (!status)       { alert('Please choose a target status.');           return false; }
    const emails  = ['Shortlisted', 'Accepted', 'Rejected'].includes(status);
    const notify  = document.getElementById('bulkNotify')?.checked;
    const note    = emails ? (notify ? '\n\nEach applicant will be emailed about their new status.' : '\n\nApplicants will NOT be emailed.') : '';
    return confirm(`Change status of ${checked} application(s) to "${status}"?${note}`);
}

function confirmBulkDelete() {
    const checked = document.querySelectorAll('.app-checkbox:checked').length;
    if (checked === 0) { alert('Please select at least one application.'); return false; }
    return confirm(`Permanently delete selected Draft or Submitted application(s) for this opportunity? Other statuses will be kept.`);
}

const remindDrafts = (() => {
    try { return JSON.parse(document.getElementById('remindDraftsData')?.textContent || '[]'); }
    catch (e) { return []; }
})();

function remindEsc(v) {
    const d = document.createElement('div');
    d.textContent = v == null ? '' : String(v);
    return d.innerHTML;
}

/* mode: 'all' | 'selected' | 'one' (with id). */
function openRemindModal(mode, id) {
    let list = remindDrafts;
    if (mode === 'one') {
        list = remindDrafts.filter(d => d.id === id);
    } else if (mode === 'selected') {
        const picked = new Set([...document.querySelectorAll('.app-checkbox:checked')].map(cb => parseInt(cb.value, 10)));
        if (picked.size === 0) { alert('Please select at least one draft application.'); return; }
        list = remindDrafts.filter(d => picked.has(d.id));
        if (list.length === 0) { alert('None of the selected applications is an unsubmitted draft.'); return; }
    }
    if (list.length === 0) return;

    const sendable = list.filter(d => d.hasEmail && !d.recent);
    const skipped = list.length - sendable.length;

    document.getElementById('remindScope').value = mode === 'all' ? '' : 'selected';
    document.getElementById('remindIds').innerHTML = mode === 'all' ? '' :
        list.map(d => `<input type="hidden" name="selected_applications[]" value="${d.id}">`).join('');

    const who = mode === 'one' ? 'this applicant' : (mode === 'all' ? 'all draft applicants' : 'the selected draft applicants');
    document.getElementById('remindIntro').innerHTML =
        `Email ${who} a reminder to finish and submit their application. ` +
        `<strong>${sendable.length}</strong> will be emailed` +
        (skipped ? `, <strong>${skipped}</strong> skipped.` : '.');

    document.getElementById('remindRows').innerHTML = list.map(d => {
        const flag = !d.hasEmail ? '<span class="ma-rem-flag skip">No email &middot; skipped</span>'
                   : d.recent   ? '<span class="ma-rem-flag skip">Reminded &lt;1h ago &middot; skipped</span>'
                   : '<span class="ma-rem-flag ok">Will send</span>';
        const skip = !d.hasEmail || d.recent;
        return `<tr class="${skip ? 'is-skipped' : ''}">
            <td>${remindEsc(d.startup || '-')}<span class="ma-rem-sub">${remindEsc(d.contact)}</span></td>
            <td>${remindEsc(d.email || '-')}</td>
            <td>${d.last ? remindEsc(d.last) + (d.count > 1 ? `<span class="ma-rem-sub">${d.count} sent</span>` : '') : 'Never'}</td>
            <td>${flag}</td>
        </tr>`;
    }).join('');

    const btn = document.getElementById('remindSubmit');
    btn.disabled = sendable.length === 0;
    btn.querySelector('span').textContent = sendable.length === 1 ? 'Send 1 reminder' : `Send ${sendable.length} reminders`;

    openModal('remindDraftsModal');
}

document.getElementById('remindDraftsForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('remindSubmit');
    btn.disabled = true;
    btn.querySelector('span').textContent = 'Sending...';
});

function sortBy(column) {
    const currentSort  = '<?= $sort ?>';
    const currentOrder = '<?= $order ?>';
    const newOrder = (column === currentSort && currentOrder === 'ASC') ? 'DESC' : 'ASC';
    const url = new URL(window.location.href);
    url.searchParams.set('sort', column);
    url.searchParams.set('order', newOrder);
    window.location.href = url.toString();
}

function quickShortlist(id) {
    if (confirm('Shortlist this application?'))
        window.location.href = 'review-application-process?action=change_status&csrf_token=<?= h(csrf_token()) ?>&application_id=' + id + '&status=Shortlisted';
}

function quickAccept(id) {
    if (confirm('Accept this application?'))
        window.location.href = 'review-application-process?action=change_status&csrf_token=<?= h(csrf_token()) ?>&application_id=' + id + '&status=Accepted';
}

function exportApplications() {
    const url = new URL(window.location.href);
    url.searchParams.set('export', 'csv');
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', updateSelectedCount);
</script>
