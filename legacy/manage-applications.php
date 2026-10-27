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
                <option value="Waitlisted">Waitlisted</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkAction()">
                <i class="fas fa-check"></i> Apply
            </button>
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

</div><!-- /.page-stack -->

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

function confirmBulkDelete() {
    const checked = document.querySelectorAll('.app-checkbox:checked').length;
    if (checked === 0) { alert('Please select at least one application.'); return false; }
    return confirm(`Permanently delete selected Draft or Submitted application(s) for this opportunity? Other statuses will be kept.`);
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
