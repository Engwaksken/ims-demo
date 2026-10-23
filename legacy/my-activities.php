<?php
ob_start();
// config.php starts the session with hardened cookie settings.
require_once __DIR__ . '/includes/config.php';

/* ----------------------------------------------------------
   AUTH GUARD
---------------------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$uid = (int)$_SESSION['user_id'];

/* ----------------------------------------------------------
   HELPERS
---------------------------------------------------------- */
if (!function_exists('h')) {
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

function fmt_date(?string $date): string
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    return date('d M Y', strtotime($date));
}

function status_label(string $status): string
{
    $map = [
        'draft'        => 'Draft',
        'submitted'    => 'Submitted',
        'under_review' => 'Under Review',
        'approved'     => 'Approved',
        'completed'    => 'Completed',
        'rejected'     => 'Rejected',
    ];

    return $map[$status] ?? ucfirst($status);
}

/* ----------------------------------------------------------
   FILTERS
---------------------------------------------------------- */
$status_filter = trim((string)($_GET['status'] ?? ''));
if (!in_array($status_filter, ['draft', 'submitted', 'under_review', 'approved', 'rejected'], true)) {
    $status_filter = '';
}
$year_filter   = (int)($_GET['fiscal_year'] ?? 0);
$q             = trim((string)($_GET['q'] ?? ''));

/* ----------------------------------------------------------
   LOAD AVAILABLE YEARS
---------------------------------------------------------- */
$years = [];
$stmt = $conn->prepare("
    SELECT DISTINCT fiscal_year
    FROM activity_plans
    WHERE user_id = ?
      AND fiscal_year IS NOT NULL
    ORDER BY fiscal_year DESC
");
if ($stmt) {
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $years[] = (int)$row['fiscal_year'];
    }
    $stmt->close();
}

/* ----------------------------------------------------------
   BUILD QUERY
---------------------------------------------------------- */
$where  = ["ap.user_id = ?"];
$params = [$uid];
$types  = "i";

if ($status_filter !== '') {
    $where[] = "ap.status = ?";
    $types  .= "s";
    $params[] = $status_filter;
}

if ($year_filter > 0) {
    $where[] = "ap.fiscal_year = ?";
    $types  .= "i";
    $params[] = $year_filter;
}

if ($q !== '') {
    $where[] = "(ap.plan_title LIKE ? OR COALESCE(kc.category_name,'') LIKE ?)";
    $types  .= "ss";
    $like = '%' . addcslashes($q, '%_') . '%';
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(' AND ', $where);

/* ----------------------------------------------------------
   LOAD PLANS
---------------------------------------------------------- */
$sql = "
    SELECT
        ap.plan_id,
        ap.plan_title,
        ap.department_id,
        ap.category_id,
        ap.fiscal_year,
        ap.total_weight,
        ap.status,
        ap.created_at,
        ap.updated_at,
        ap.submitted_at,
        ap.reviewed_at,
        ap.reviewer_notes,
        kc.category_name,
        (
            SELECT COUNT(*)
            FROM plan_activities pa
            WHERE pa.plan_id = ap.plan_id
        ) AS activity_count,
        (
            SELECT COUNT(*)
            FROM activity_weekly_entries awe
            WHERE awe.plan_id = ap.plan_id
        ) AS weekly_count
    FROM activity_plans ap
    LEFT JOIN kpi_categories kc
        ON kc.category_id = ap.category_id
    WHERE {$where_sql}
    ORDER BY ap.created_at DESC, ap.plan_id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('my-activities prepare failed: ' . $conn->error);
    die('Unable to load activity plans.');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ----------------------------------------------------------
   SUMMARY STATS
---------------------------------------------------------- */
$total_plans = count($plans);
$draft_count = 0;
$submitted_count = 0;
$approved_count = 0;

foreach ($plans as $plan) {
    $status = (string)($plan['status'] ?? '');
    if ($status === 'draft') {
        $draft_count++;
    } elseif ($status === 'submitted') {
        $submitted_count++;
    } elseif ($status === 'approved') {
        $approved_count++;
    }
}

$page_title = 'My Activities';
include 'includes/header.php';
?>
<style>
.my-activities-page .filters-card .card-body{padding:14px 16px}
.my-activities-page .filters-bar{margin-bottom:0}
.my-activities-page .filters-bar .spacer{flex:1}
.my-activities-page .plan-title{font-weight:700;color:var(--ink-700)}
.my-activities-page .table .status-pill{text-transform:none}
@media (max-width:640px){
    .my-activities-page .filters-bar .spacer{display:none}
}
</style>

<div class="page-stack my-activities-page">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-clipboard-list" aria-hidden="true"></i> My Activity Plans</h1>
            <p>Create, track, edit drafts, and view submitted or approved activity plans.</p>
        </div>
        <div class="hero-actions">
            <a href="create-activity" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> New Activity Plan</a>
        </div>
    </div>

    <?php if (!empty($_SESSION['activity_success'])): ?>
        <div class="alert alert-success"><?= h($_SESSION['activity_success']) ?></div>
        <?php unset($_SESSION['activity_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['activity_error'])): ?>
        <div class="alert alert-danger"><?= h($_SESSION['activity_error']) ?></div>
        <?php unset($_SESSION['activity_error']); ?>
    <?php endif; ?>

    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-clipboard-list" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Plans</div>
                <div class="stat-value"><?= $total_plans ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-slate"><i class="fas fa-pen" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Drafts</div>
                <div class="stat-value"><?= $draft_count ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-paper-plane" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Submitted</div>
                <div class="stat-value"><?= $submitted_count ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Approved</div>
                <div class="stat-value"><?= $approved_count ?></div>
            </div>
        </div>
    </div>

    <div class="card filters-card">
    <div class="card-body">
    <form method="GET" class="filters-bar" aria-label="Filter activity plans">
        <select name="status" onchange="this.form.submit()" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="under_review" <?= $status_filter === 'under_review' ? 'selected' : '' ?>>Under Review</option>
            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>

        <select name="fiscal_year" onchange="this.form.submit()" aria-label="Filter by fiscal year">
            <option value="">All Years</option>
            <?php foreach ($years as $year): ?>
                <option value="<?= $year ?>" <?= $year_filter === $year ? 'selected' : '' ?>>
                    <?= $year ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="q" class="filters-grow" value="<?= h($q) ?>" placeholder="Search plan title or category" aria-label="Search plan title or category">

        <button type="submit" class="btn btn-secondary">Filter</button>

        <div class="spacer"></div>

        <?php if ($status_filter !== '' || $year_filter > 0 || $q !== ''): ?>
            <a href="my-activities" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
    </div>
    </div>

    <?php if (empty($plans)): ?>
        <div class="card">
            <div class="empty-state">
                <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                <h4>No activity plans found</h4>
                <p>Start by creating a new activity plan.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Category</th>
                            <th>FY</th>
                            <th>Activities</th>
                            <th>Weekly Entries</th>
                            <th>Total Weight</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th style="width:260px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <?php
                        $label = status_label((string)$plan['status']);
                        $can_edit = in_array((string)$plan['status'], ['draft', 'rejected'], true);
                        ?>
                        <tr>
                            <td>
                                <div class="plan-title"><?= h($plan['plan_title']) ?></div>
                                <div class="cell-muted">
                                    Created: <?= fmt_date($plan['created_at']) ?>
                                </div>
                            </td>
                            <td><?= h($plan['category_name'] ?? '-') ?></td>
                            <td><?= h($plan['fiscal_year'] ?? '-') ?></td>
                            <td><?= (int)$plan['activity_count'] ?></td>
                            <td><?= (int)$plan['weekly_count'] ?></td>
                            <td><?= number_format((float)($plan['total_weight'] ?? 0), 1) ?>%</td>
                            <td>
                                <span class="status-pill status-<?= h(strtolower((string)$plan['status'])) ?>">
                                    <?= h($label) ?>
                                </span>
                            </td>
                            <td><?= fmt_date($plan['updated_at']) ?></td>
                            <td>
                                <div class="actions">
                                    <a href="view-activity?id=<?= (int)$plan['plan_id'] ?>" class="btn btn-sm btn-secondary">View</a>

                                    <?php if ($can_edit): ?>
                                        <a href="edit-activity?id=<?= (int)$plan['plan_id'] ?>" class="btn btn-sm btn-soft">Edit</a>
                                    <?php endif; ?>

                                    <?php if ((string)$plan['status'] === 'approved'): ?>
                                        <a href="view-activity?id=<?= (int)$plan['plan_id'] ?>#weekly" class="btn btn-sm btn-info">Weekly Progress</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
include 'includes/footer.php';
ob_end_flush();
?>