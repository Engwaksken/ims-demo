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
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
/* -- manage-applications page-specific additions -- */

/* Sortable table */
.ma-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.ma-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
    font-size: 13px;
}

.ma-table th {
    background: var(--ink-50);
    color: var(--ink-300);
    text-align: left;
    padding: 11px 14px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    border-bottom: 1px solid var(--ink-100);
    white-space: nowrap;
    user-select: none;
}

.ma-table th.sortable {
    cursor: pointer;
    transition: color .12s, background .12s;
}

.ma-table th.sortable:hover {
    color: var(--brand-600);
    background: var(--brand-50);
}

.ma-table th.sort-active {
    color: var(--brand-600);
    background: var(--brand-50);
}

.ma-table th .sort-icon {
    margin-left: 4px;
    font-size: 10px;
    opacity: .6;
}

.ma-table td {
    padding: 13px 14px;
    border-bottom: 1px solid var(--ink-50);
    color: var(--ink-500);
    vertical-align: middle;
}

.ma-table tbody tr {
    transition: background .1s;
}

.ma-table tbody tr:hover { background: var(--surface-hover); }
.ma-table tbody tr:last-child td { border-bottom: none; }

/* Startup name cell */
.ma-startup-name {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink-700);
    margin-bottom: 3px;
}

.ma-startup-sub {
    font-size: 11.5px;
    color: var(--ink-300);
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.ma-startup-sub i { color: var(--brand-400); }

/* Score badge */
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

.ma-score.high   { background: var(--green-bg);  color: var(--green-fg);  border: 2px solid var(--green-border); }
.ma-score.medium { background: var(--amber-bg);  color: var(--amber-fg);  border: 2px solid var(--amber-border); }
.ma-score.low    { background: var(--red-bg);    color: var(--red-fg);    border: 2px solid var(--red-border);   }
.ma-score.none   { color: var(--ink-200); font-size: 18px; }

/* Review count pill */
.ma-review-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: var(--ink-100);
    color: var(--ink-400);
}

/* Quick action buttons */
.ma-actions {
    display: flex;
    gap: 5px;
    align-items: center;
}

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

.ma-bulk-divider {
    width: 1px;
    height: 20px;
    background: var(--brand-200);
    flex-shrink: 0;
}

.ma-selected-count {
    font-weight: 700;
    color: var(--brand-600);
    white-space: nowrap;
}

.ma-bulk-select {
    height: 36px;
    padding: 0 32px 0 10px;
    border: 1.5px solid var(--brand-200);
    border-radius: var(--radius-md);
    background: var(--surface-card)
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E")
        no-repeat right 10px center;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-700);
    outline: none;
    cursor: pointer;
    appearance: none;
    min-width: 200px;
    transition: border-color .15s;
}

.ma-bulk-select:focus { border-color: var(--brand-500); }

/* Empty state */
.ma-empty {
    text-align: center;
    padding: 72px 24px;
    color: var(--ink-200);
}

.ma-empty i { font-size: 3rem; opacity: .3; display: block; margin-bottom: 16px; }
.ma-empty h3 { font-size: 18px; font-weight: 800; color: var(--ink-700); margin-bottom: 8px; }
.ma-empty p  { font-size: 13px; color: var(--ink-300); margin-bottom: 20px; line-height: 1.65; }

/* Search input in filter bar */
.ma-search-wrap {
    position: relative;
    flex: 1;
    min-width: 200px;
    max-width: 340px;
}

.ma-search-icon {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--ink-200);
    font-size: 12px;
    pointer-events: none;
}

.ma-search-input {
    width: 100%;
    height: 38px;
    padding: 0 12px 0 32px;
    background: var(--surface-card);
    border: 1.5px solid var(--ink-100);
    border-radius: var(--radius-md);
    font-family: var(--font-body);
    font-size: 13px;
    color: var(--ink-700);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}

.ma-search-input:focus {
    border-color: var(--brand-500);
    box-shadow: 0 0 0 3px rgba(249,115,22,.12);
}

/* App status badge variants not in opportunities.css */
.badge-under-review { background: var(--blue-bg);   color: var(--blue-fg);   border-color: var(--blue-border);   }
.badge-shortlisted  { background: var(--amber-bg);  color: var(--amber-fg);  border-color: var(--amber-border);  }
.badge-waitlisted   { background: var(--slate-bg);  color: var(--slate-fg);  border-color: var(--slate-border);  }

@media (max-width: 640px) {
    .ma-search-wrap { max-width: none; }
    .ma-bulk-bar    { flex-direction: column; align-items: stretch; }
    .ma-bulk-select { min-width: 0; }
}
</style>

<!-- -- Hero -------------------------------------------------- -->
<div class="opp-hero" style="margin-bottom:20px;">
    <div class="opp-hero-left">
        <div class="opp-eyebrow">
            <span class="opp-dot"></span>
            <?= h($opportunity['opportunity_type'] ?? 'Opportunity') ?>
        </div>
        <h1>Manage Applications</h1>
        <p><?= h($opportunity['opportunity_title']) ?></p>
    </div>
    <div class="opp-hero-actions" style="position:relative;">
        <a href="view-opportunity?id=<?= $opportunity_id ?>" class="btn btn-white btn-sm">
            <i class="fas fa-eye"></i> View Opportunity
        </a>
        <a href="application-opportunities" class="btn btn-white btn-sm">
            <i class="fas fa-arrow-left"></i> Back
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
        <div class="stat-icon bg-green">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-info">
            <span class="stat-label">Avg Score</span>
            <span class="stat-value"><?= number_format($avg_score, 1) ?></span>
        </div>
    </div>
</div>

<!-- -- Filter bar ---------------------------------------------- -->
<div class="opp-panel" style="margin-bottom:20px;">
    <div class="opp-panel-body" style="padding:14px 18px;">
        <form method="GET" action="">
            <input type="hidden" name="opportunity_id" value="<?= $opportunity_id ?>">

            <div class="filters-bar" role="search" aria-label="Filter applications" style="margin-bottom:12px;">

                <!-- Search -->
                <div class="ma-search-wrap filters-grow">
                    <i class="fas fa-search ma-search-icon"></i>
                    <input type="text" name="search" class="ma-search-input"
                           placeholder="Startup, contact, email, phone..."
                           value="<?= h($search) ?>">
                </div>

                <!-- Status -->
                <select name="status" class="form-control" style="height:38px;font-size:13px;padding:0 32px 0 10px;min-width:160px;border:1.5px solid var(--ink-100);border-radius:var(--radius-md);appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E\");background-repeat:no-repeat;background-position:right 10px center;outline:none;font-family:var(--font-body);color:var(--ink-700);">
                    <option value="">All Statuses</option>
                    <?php foreach ($status_options as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $filter_status === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Stage -->
                <?php if (!empty($stages)): ?>
                <select name="stage" class="form-control" style="height:38px;font-size:13px;padding:0 32px 0 10px;min-width:150px;border:1.5px solid var(--ink-100);border-radius:var(--radius-md);appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E\");background-repeat:no-repeat;background-position:right 10px center;outline:none;font-family:var(--font-body);color:var(--ink-700);">
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
            <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--ink-50);">
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

    <div class="opp-panel">

        <!-- Bulk actions bar -->
        <div class="ma-bulk-bar">
            <label style="display:flex;align-items:center;gap:7px;font-weight:700;color:var(--ink-700);cursor:pointer;margin:0;">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"
                       style="width:15px;height:15px;accent-color:var(--brand-500);">
                Select All
            </label>
            <div class="ma-bulk-divider"></div>
            <span id="selectedCount" class="ma-selected-count">0 selected</span>
            <div class="ma-bulk-divider"></div>
            <select name="new_status" class="ma-bulk-select" required>
                <option value="">Change status to...</option>
                <option value="Under Review">Under Review</option>
                <option value="Shortlisted">Shortlisted</option>
                <option value="Accepted">Accepted</option>
                <option value="Rejected">Rejected</option>
                <option value="Waitlisted">Waitlisted</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkAction()">
                <i class="fas fa-check"></i> Apply
            </button>
        </div>

        <!-- Table -->
        <div class="ma-table-wrap">
            <table class="ma-table">
                <thead>
                    <tr>
                        <th style="width:44px;cursor:default;">
                            <input type="checkbox" id="selectAllHeader" onchange="toggleSelectAll(this)"
                                   style="width:15px;height:15px;accent-color:var(--brand-500);">
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

                        <th style="width:130px;cursor:default;">Actions</th>
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
                            $st === 'Shortlisted' => 'badge-shortlisted',
                            $st === 'Under Review'=> 'badge-under-review',
                            $st === 'Waitlisted'  => 'badge-waitlisted',
                            $st === 'Draft'       => 'badge-draft',
                            default               => 'badge-secondary',
                        };
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   name="selected_applications[]"
                                   value="<?= (int)$app['application_id'] ?>"
                                   class="app-checkbox"
                                   onchange="updateSelectedCount()"
                                   style="width:15px;height:15px;accent-color:var(--brand-500);">
                        </td>

                        <td>
                            <div class="ma-startup-name"><?= h($app['startup_name']) ?></div>
                            <div class="ma-startup-sub">
                                <span><i class="fas fa-user"></i> <?= h($app['contact_person']) ?></span>
                                <span><i class="fas fa-envelope"></i> <?= h($app['email']) ?></span>
                            </div>
                        </td>

                        <td style="font-size:12.5px;color:var(--ink-400);">
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
                            <span class="ma-review-count"><?= (int)$app['review_count'] ?></span>
                        </td>

                        <td>
                            <span class="badge <?= $appBadge ?>"><?= h($st) ?></span>
                        </td>

                        <td style="font-size:12px;color:var(--ink-300);">
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
        </div><!-- /.ma-table-wrap -->

    </div><!-- /.opp-panel -->
</form>

<?php else: ?>

<div class="opp-panel">
    <div class="opp-panel-body">
        <div class="ma-empty">
            <i class="fas fa-inbox"></i>
            <h3>No Applications Found</h3>
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

<?php include 'includes/footer.php'; ?>

<script>
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
    return confirm(`Change status of ${checked} application(s) to "${status}"?`);
}

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