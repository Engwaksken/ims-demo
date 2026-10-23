<?php
declare(strict_types=1);

$page_title = 'Startup Milestones Dashboard';
include 'includes/header.php';

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer',
    'Reviewer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->close();

    return $exists;
}

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $refs = [&$types];

    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function badge_class(string $status): string
{
    return match ($status) {
        'Completed', 'Visited', 'Report Uploaded' => 'badge badge-completed',
        'In Progress', 'Generated', 'Assigned' => 'badge badge-in-progress',
        'Delayed', 'Could Not Locate', 'Needs Follow Up' => 'badge badge-delayed',
        'Cancelled', 'Closed' => 'badge badge-cancelled',
        default => 'badge badge-not-started',
    };
}

function progress_class(float $pct): string
{
    if ($pct >= 75) {
        return 'high';
    }

    if ($pct >= 50) {
        return 'mid';
    }

    return 'low';
}

function score_class(float $score): string
{
    if ($score >= 7.5) {
        return 'high';
    }

    if ($score >= 5) {
        return 'mid';
    }

    return 'low';
}

function format_date(?string $date, string $fallback = '-'): string
{
    if (empty($date)) {
        return $fallback;
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d M Y', $timestamp) : $fallback;
}

function format_datetime(?string $date, string $fallback = '-'): string
{
    if (empty($date)) {
        return $fallback;
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d M Y, h:i A', $timestamp) : $fallback;
}

if (!table_exists($conn, 'startup_milestones')) {
    die('startup_milestones table is missing. Run sql/startup_milestones.sql first.');
}

$hasFieldVisits = table_exists($conn, 'startup_field_visits');
$hasEvidenceTable = table_exists($conn, 'startup_milestone_evidence');

/*
|--------------------------------------------------------------------------
| Milestone stats
|--------------------------------------------------------------------------
*/
$stats = [
    'total' => 0,
    'not_started' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'delayed' => 0,
    'overdue' => 0,
    'avg_progress' => 0,
];

$res = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Not Started') AS not_started,
        SUM(status = 'In Progress') AS in_progress,
        SUM(status = 'Completed') AS completed,
        SUM(status = 'Delayed') AS delayed,
        SUM(status NOT IN ('Completed','Cancelled') AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue,
        AVG(progress_percentage) AS avg_progress
    FROM startup_milestones
");

if ($res && ($row = $res->fetch_assoc())) {
    foreach ($stats as $key => $value) {
        $stats[$key] = (float)($row[$key] ?? 0);
    }

    $res->close();
}

/*
|--------------------------------------------------------------------------
| Field report filters + pagination
|--------------------------------------------------------------------------
*/
$fieldSearch = trim((string)($_GET['field_search'] ?? ''));
$fieldStatus = trim((string)($_GET['field_status'] ?? ''));
$fieldRecommendation = trim((string)($_GET['field_recommendation'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$allowedFieldStatuses = ['Generated', 'Assigned', 'Visited', 'Report Uploaded', 'Completed', 'Cancelled'];
$allowedFieldRecommendations = ['Strongly Recommend', 'Recommend', 'Needs Support', 'Do Not Recommend', 'Pending'];

if (!in_array($fieldStatus, $allowedFieldStatuses, true)) {
    $fieldStatus = '';
}

if (!in_array($fieldRecommendation, $allowedFieldRecommendations, true)) {
    $fieldRecommendation = '';
}

/*
|--------------------------------------------------------------------------
| Field report stats
|--------------------------------------------------------------------------
*/
$fieldStats = [
    'total_forms' => 0,
    'submitted_reports' => 0,
    'visited' => 0,
    'avg_score' => 0,
    'with_files' => 0,
    'pending' => 0,
];

if ($hasFieldVisits) {
    $res = $conn->query("
        SELECT
            COUNT(*) AS total_forms,
            SUM(status IN ('Visited','Report Uploaded','Completed')) AS submitted_reports,
            SUM(business_status = 'Visited') AS visited,
            AVG(NULLIF(field_overall_score, 0)) AS avg_score,
            SUM(report_file IS NOT NULL AND report_file <> '') AS with_files,
            SUM(status IN ('Generated','Assigned')) AS pending
        FROM startup_field_visits
    ");

    if ($res && ($row = $res->fetch_assoc())) {
        foreach ($fieldStats as $key => $value) {
            $fieldStats[$key] = (float)($row[$key] ?? 0);
        }

        $res->close();
    }
}

/*
|--------------------------------------------------------------------------
| Review area breakdown
|--------------------------------------------------------------------------
*/
$typeRows = [];

$res = $conn->query("
    SELECT
        milestone_type,
        COUNT(*) AS total,
        AVG(progress_percentage) AS avg_progress
    FROM startup_milestones
    GROUP BY milestone_type
    ORDER BY total DESC, milestone_type ASC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $typeRows[] = $row;
    }

    $res->close();
}

/*
|--------------------------------------------------------------------------
| Latest milestones
|--------------------------------------------------------------------------
*/
$latest = [];

$sql = "
    SELECT
        sm.*,
        COALESCE(a.startup_name, CONCAT('Application #', sm.application_id)) AS startup_name,
        ar.overall_score,
        ar.recommendation
    FROM startup_milestones sm
    LEFT JOIN applications a ON a.application_id = sm.application_id
    LEFT JOIN application_reviews ar ON ar.review_id = sm.review_id
    ORDER BY sm.updated_at DESC, sm.created_at DESC
    LIMIT 10
";

$res = $conn->query($sql);

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $latest[] = $row;
    }

    $res->close();
}

/*
|--------------------------------------------------------------------------
| Field reports query
|--------------------------------------------------------------------------
*/
$fieldReports = [];
$totalFieldReports = 0;
$totalPages = 1;

if ($hasFieldVisits) {
    $where = [];
    $types = '';
    $params = [];

    if ($fieldSearch !== '') {
        $like = '%' . $fieldSearch . '%';

        $where[] = "(
            sfv.visit_code LIKE ?
            OR sfv.visit_title LIKE ?
            OR sfv.assigned_to LIKE ?
            OR sfv.location LIKE ?
            OR sfv.contact_person LIKE ?
            OR sfv.field_recommendation LIKE ?
            OR sfv.observations LIKE ?
            OR a.startup_name LIKE ?
            OR sm.milestone_title LIKE ?
        )";

        $types .= 'sssssssss';

        for ($i = 0; $i < 9; $i++) {
            $params[] = $like;
        }
    }

    if ($fieldStatus !== '') {
        $where[] = 'sfv.status = ?';
        $types .= 's';
        $params[] = $fieldStatus;
    }

    if ($fieldRecommendation !== '') {
        $where[] = 'sfv.field_recommendation = ?';
        $types .= 's';
        $params[] = $fieldRecommendation;
    }

    if ($dateFrom !== '') {
        $where[] = 'COALESCE(sfv.actual_visit_date, sfv.planned_visit_date, DATE(sfv.created_at)) >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 'COALESCE(sfv.actual_visit_date, sfv.planned_visit_date, DATE(sfv.created_at)) <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countSql = "
        SELECT COUNT(*) AS total
        FROM startup_field_visits sfv
        LEFT JOIN startup_milestones sm ON sm.milestone_id = sfv.milestone_id
        LEFT JOIN applications a ON a.application_id = sfv.application_id
        LEFT JOIN application_reviews ar ON ar.review_id = sfv.review_id
        {$whereSql}
    ";

    $stmt = $conn->prepare($countSql);

    if ($stmt) {
        bind_params_dynamic($stmt, $types, $params);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;

        $totalFieldReports = (int)($row['total'] ?? 0);
        $stmt->close();
    }

    $totalPages = max(1, (int)ceil($totalFieldReports / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $reportSql = "
        SELECT
            sfv.*,
            sm.milestone_title,
            sm.milestone_type,
            COALESCE(a.startup_name, CONCAT('Application #', sfv.application_id)) AS startup_name,
            ar.overall_score AS review_overall_score,
            ar.recommendation AS review_recommendation,
            u.full_name AS submitted_by_name
        FROM startup_field_visits sfv
        LEFT JOIN startup_milestones sm ON sm.milestone_id = sfv.milestone_id
        LEFT JOIN applications a ON a.application_id = sfv.application_id
        LEFT JOIN application_reviews ar ON ar.review_id = sfv.review_id
        LEFT JOIN users u ON u.user_id = sfv.submitted_by
        {$whereSql}
        ORDER BY COALESCE(sfv.submitted_at, sfv.updated_at, sfv.created_at) DESC, sfv.visit_id DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($reportSql);

    if ($stmt) {
        $reportTypes = $types . 'ii';
        $reportParams = $params;
        $reportParams[] = $perPage;
        $reportParams[] = $offset;

        bind_params_dynamic($stmt, $reportTypes, $reportParams);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $fieldReports[] = $row;
            }
        }

        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| Venture milestone evidence for dashboard review
|--------------------------------------------------------------------------
*/
$evidenceRows = [];

if ($hasEvidenceTable) {
    $res = $conn->query("
        SELECT
            sme.*,
            sm.milestone_title,
            sm.milestone_type,
            COALESCE(a.startup_name, CONCAT('Application #', sme.application_id)) AS startup_name,
            reviewer.full_name AS reviewer_name
        FROM startup_milestone_evidence sme
        LEFT JOIN startup_milestones sm ON sm.milestone_id = sme.milestone_id
        LEFT JOIN applications a ON a.application_id = sme.application_id
        LEFT JOIN users reviewer ON reviewer.user_id = sme.reviewer_id
        ORDER BY sme.created_at DESC, sme.evidence_id DESC
        LIMIT 200
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $evidenceRows[] = $row;
        }
        $res->close();
    }
}

$queryBase = $_GET;
unset($queryBase['page']);
$baseQueryString = http_build_query($queryBase);
$basePageUrl = 'startup-milestones-dashboard' . ($baseQueryString ? '?' . $baseQueryString . '&' : '?');
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">

<style>
@media print {
    body * {
        visibility: hidden;
    }

    #fieldReportsPrintArea,
    #fieldReportsPrintArea * {
        visibility: visible;
    }

    #fieldReportsPrintArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: #fff;
    }

    .no-print {
        display: none !important;
    }
}
</style>

<div class="milestones-wrap page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-rocket"></i> Startup Milestones Dashboard</h1>
            <p>Track startup milestones, field-team visits, submitted reports, scores, recommendations, and uploaded files.</p>
        </div>

        <div class="hero-actions">
            <a href="startup-milestones" class="btn btn-primary">
                <i class="fas fa-list-check"></i> Manage Startup Milestones
            </a>

            <?php if ($hasFieldVisits): ?>
                <a href="startup-field-visits" class="btn">
                    <i class="fas fa-clipboard-check"></i> Field Visits
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats-grid reports-stats milestones-stats">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-flag"></i></div>
            <div class="stat-info">
                <div class="stat-label">Milestones</div>
                <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info">
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?php echo number_format($stats['in_progress']); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?php echo number_format($stats['completed']); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-file-upload"></i></div>
            <div class="stat-info">
                <div class="stat-label">Submitted Reports</div>
                <div class="stat-value"><?php echo number_format($fieldStats['submitted_reports']); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <div class="stat-label">Avg Field Score</div>
                <div class="stat-value"><?php echo number_format($fieldStats['avg_score'], 1); ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:var(--red-bg);color:var(--red-fg);">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Overdue</div>
                <div class="stat-value"><?php echo number_format($stats['overdue']); ?></div>
            </div>
        </div>
    </div>

    <?php if (!$hasFieldVisits): ?>
        <div class="callout note">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Field report dashboard is not active yet.</strong><br>
                Run <code>sql/startup_field_visits.sql</code> to enable submitted field reports, search, pagination, and report generation.
            </div>
        </div>
    <?php endif; ?>

    <div class="detail-tabs">
        <div class="tab-nav no-print">
            <button type="button" class="tab-btn active" data-tab="reports">
                <i class="fas fa-file-alt"></i> Field Reports
            </button>
            <button type="button" class="tab-btn" data-tab="latest">
                <i class="fas fa-clock"></i> Latest Milestones
            </button>
            <button type="button" class="tab-btn" data-tab="evidence">
                <i class="fas fa-folder-open"></i> Venture Evidence
            </button>
            <button type="button" class="tab-btn" data-tab="types">
                <i class="fas fa-layer-group"></i> By Review Area
            </button>
        </div>

        <section id="tab-reports" class="tab-panel active">
            <div class="section-title no-print">
                <h3><i class="fas fa-file-alt"></i> Field Team Submitted Reports</h3>

                <div class="actions">
                    <button type="button" class="btn btn-sm btn-soft" onclick="exportFieldReportsCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Generate Report
                    </button>
                </div>
            </div>

            <form method="GET" class="filter-bar filters-bar no-print">
                <input type="hidden" name="active_tab" value="reports">

                <div class="form-group search-group filters-grow">
                    <label class="form-label">Search Reports</label>
                    <div class="search-input-wrap">
                        <i class="fas fa-search"></i>
                        <input
                            type="text"
                            name="field_search"
                            class="form-control"
                            value="<?php echo h($fieldSearch); ?>"
                            placeholder="Startup, visit code, team member, location, observation..."
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="field_status" class="form-control">
                        <option value="">All Statuses</option>
                        <?php foreach ($allowedFieldStatuses as $status): ?>
                            <option value="<?php echo h($status); ?>" <?php echo $fieldStatus === $status ? 'selected' : ''; ?>>
                                <?php echo h($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Recommendation</label>
                    <select name="field_recommendation" class="form-control">
                        <option value="">All Recommendations</option>
                        <?php foreach ($allowedFieldRecommendations as $recommendation): ?>
                            <option value="<?php echo h($recommendation); ?>" <?php echo $fieldRecommendation === $recommendation ? 'selected' : ''; ?>>
                                <?php echo h($recommendation); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                </div>

                <div class="filter-actions">
                    <button class="btn btn-dark" type="submit">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="startup-milestones-dashboard" class="btn">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>

            <div id="fieldReportsPrintArea">
                <div class="panel">
                    <div class="panel-head">
                        <h3>
                            <i class="fas fa-clipboard-check"></i>
                            Field Reports
                        </h3>
                        <span class="badge badge-available">
                            <?php echo number_format($totalFieldReports); ?> report<?php echo $totalFieldReports === 1 ? '' : 's'; ?>
                        </span>
                    </div>

                    <div class="table-wrap">
                        <table class="table" id="fieldReportsTable">
                            <thead>
                                <tr>
                                    <th>Visit Code</th>
                                    <th>Startup</th>
                                    <th>Milestone</th>
                                    <th>Field Team</th>
                                    <th>Visit Date</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>Recommendation</th>
                                    <th>Report File</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fieldReports)): ?>
                                    <tr>
                                        <td colspan="11" class="empty-state">
                                            <i class="fas fa-file-alt"></i>
                                            <span>No submitted field reports found.</span>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($fieldReports as $report): ?>
                                        <?php
                                        $score = max(0, min(10, (float)($report['field_overall_score'] ?? 0)));
                                        $reportPayload = [
                                            'visit_id' => (int)($report['visit_id'] ?? 0),
                                            'visit_code' => (string)($report['visit_code'] ?? ''),
                                            'startup_name' => (string)($report['startup_name'] ?? ''),
                                            'application_id' => (int)($report['application_id'] ?? 0),
                                            'milestone_title' => (string)($report['milestone_title'] ?? ''),
                                            'milestone_type' => (string)($report['milestone_type'] ?? ''),
                                            'assigned_to' => (string)($report['assigned_to'] ?? ''),
                                            'submitted_by_name' => (string)($report['submitted_by_name'] ?? ''),
                                            'actual_visit_date' => format_date($report['actual_visit_date'] ?: ($report['planned_visit_date'] ?? null)),
                                            'location' => (string)($report['location'] ?? ''),
                                            'status' => (string)($report['status'] ?? ''),
                                            'business_status' => (string)($report['business_status'] ?? ''),
                                            'field_overall_score' => number_format($score, 1),
                                            'field_recommendation' => (string)($report['field_recommendation'] ?? ''),
                                            'review_overall_score' => number_format((float)($report['review_overall_score'] ?? 0), 2),
                                            'review_recommendation' => (string)($report['review_recommendation'] ?? ''),
                                            'equity_score' => number_format((float)($report['equity_score'] ?? 0), 1),
                                            'video_intro_score' => number_format((float)($report['video_intro_score'] ?? 0), 1),
                                            'problem_depth_toc_score' => number_format((float)($report['problem_depth_toc_score'] ?? 0), 1),
                                            'product_quality_pedagogy_score' => number_format((float)($report['product_quality_pedagogy_score'] ?? 0), 1),
                                            'product_demo_link_score' => number_format((float)($report['product_demo_link_score'] ?? 0), 1),
                                            'scalability_traction_sustainability_score' => number_format((float)($report['scalability_traction_sustainability_score'] ?? 0), 1),
                                            'team_capability_commitment_score' => number_format((float)($report['team_capability_commitment_score'] ?? 0), 1),
                                            'ursb_registration_score' => number_format((float)($report['ursb_registration_score'] ?? 0), 1),
                                            'observations' => (string)($report['observations'] ?? ''),
                                            'risks' => (string)($report['risks'] ?? ''),
                                            'support_needed' => (string)($report['support_needed'] ?? ''),
                                            'next_steps' => (string)($report['next_steps'] ?? ''),
                                            'report_file' => ims_upload_url((string)($report['report_file'] ?? ''), true),
                                            'report_original_name' => (string)($report['report_original_name'] ?? 'Uploaded Report'),
                                            'submitted_at' => format_datetime($report['submitted_at'] ?? $report['updated_at'] ?? $report['created_at'] ?? null),
                                        ];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo h($report['visit_code']); ?></strong>
                                                <br>
                                                <small>#<?php echo (int)$report['visit_id']; ?></small>
                                            </td>

                                            <td>
                                                <strong><?php echo h($report['startup_name']); ?></strong>
                                                <br>
                                                <small>App #<?php echo (int)$report['application_id']; ?></small>
                                            </td>

                                            <td>
                                                <div class="milestone-name"><?php echo h($report['milestone_title'] ?? '-'); ?></div>
                                                <div class="milestone-desc"><?php echo h($report['milestone_type'] ?? '-'); ?></div>
                                            </td>

                                            <td>
                                                <?php echo h($report['assigned_to'] ?? '-'); ?>
                                                <?php if (!empty($report['submitted_by_name'])): ?>
                                                    <br>
                                                    <small>Submitted by: <?php echo h($report['submitted_by_name']); ?></small>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php echo h(format_date($report['actual_visit_date'] ?: ($report['planned_visit_date'] ?? null))); ?>
                                                <?php if (!empty($report['location'])): ?>
                                                    <br>
                                                    <small><?php echo h($report['location']); ?></small>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="<?php echo h(badge_class((string)$report['status'])); ?>">
                                                    <?php echo h($report['status']); ?>
                                                </span>
                                                <br>
                                                <small><?php echo h($report['business_status'] ?? '-'); ?></small>
                                            </td>

                                            <td>
                                                <div class="progress-cell">
                                                    <div class="progress-header">
                                                        <span class="progress-pct"><?php echo number_format($score, 1); ?>/10</span>
                                                    </div>
                                                    <div class="prog-track">
                                                        <div class="prog-fill <?php echo h(score_class($score)); ?>" style="width:<?php echo min(100, $score * 10); ?>%;"></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td><?php echo h($report['field_recommendation'] ?? '-'); ?></td>

                                            <td>
                                                <?php if (!empty($report['report_file'])): ?>
                                                    <a href="<?php echo h(ims_upload_url($report['report_file'], true)); ?>" class="btn btn-sm btn-soft no-print" download>
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color:var(--ink-200);font-size:12px;">No file</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php echo h(format_datetime($report['submitted_at'] ?? $report['updated_at'] ?? $report['created_at'] ?? null)); ?>
                                            </td>

                                            <td>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-primary no-print"
                                                    onclick='openFieldReportModal(<?php echo json_encode($reportPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'
                                                >
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="panel-body no-print" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div class="note">
                                Showing page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
                                - <?php echo number_format($totalFieldReports); ?> total reports
                            </div>

                            <div class="actions">
                                <?php if ($page > 1): ?>
                                    <a class="btn btn-sm btn-gray" href="<?php echo h($basePageUrl . 'page=' . ($page - 1)); ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a
                                        class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-gray'; ?>"
                                        href="<?php echo h($basePageUrl . 'page=' . $i); ?>"
                                    >
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a class="btn btn-sm btn-gray" href="<?php echo h($basePageUrl . 'page=' . ($page + 1)); ?>">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>


        <section id="tab-evidence" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-folder-open"></i> Venture Uploaded Milestone Evidence</h3>
                <span class="badge badge-available"><?php echo count($evidenceRows); ?> file<?php echo count($evidenceRows) === 1 ? '' : 's'; ?></span>
            </div>

            <?php if (!$hasEvidenceTable): ?>
                <div class="callout note">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Evidence workflow is not active yet.</strong><br>
                        Run <code>sql/startup_milestone_evidence.sql</code> to enable venture evidence upload and reviewer scoring.
                    </div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Startup</th>
                                <th>Milestone</th>
                                <th>Evidence</th>
                                <th>Uploaded By</th>
                                <th>Status</th>
                                <th>Score</th>
                                <th>Uploaded</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($evidenceRows)): ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <span>No venture evidence uploaded yet.</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($evidenceRows as $evidence): ?>
                                    <?php
                                    $evidencePayload = [
                                        'evidence_id' => (int)($evidence['evidence_id'] ?? 0),
                                        'startup_name' => (string)($evidence['startup_name'] ?? ''),
                                        'milestone_title' => (string)($evidence['milestone_title'] ?? ''),
                                        'evidence_title' => (string)($evidence['evidence_title'] ?? ''),
                                        'evidence_description' => (string)($evidence['evidence_description'] ?? ''),
                                        'evidence_file' => ims_upload_url((string)($evidence['evidence_file'] ?? ''), true),
                                        'original_file_name' => (string)($evidence['original_file_name'] ?? 'Evidence File'),
                                        'submitted_name' => (string)($evidence['submitted_name'] ?? ''),
                                        'submitted_email' => (string)($evidence['submitted_email'] ?? ''),
                                        'submitted_phone' => (string)($evidence['submitted_phone'] ?? ''),
                                        'review_status' => (string)($evidence['review_status'] ?? 'Pending'),
                                        'reviewer_score' => (string)($evidence['reviewer_score'] ?? ''),
                                        'reviewer_comments' => (string)($evidence['reviewer_comments'] ?? ''),
                                    ];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo h($evidence['startup_name']); ?></strong>
                                            <br>
                                            <small>App #<?php echo (int)$evidence['application_id']; ?></small>
                                        </td>
                                        <td>
                                            <div class="milestone-name"><?php echo h($evidence['milestone_title'] ?? '-'); ?></div>
                                            <div class="milestone-desc"><?php echo h($evidence['milestone_type'] ?? '-'); ?></div>
                                        </td>
                                        <td>
                                            <strong><?php echo h($evidence['evidence_title']); ?></strong>
                                            <br>
                                            <small><?php echo h($evidence['original_file_name'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php echo h($evidence['submitted_name'] ?? '-'); ?>
                                            <br>
                                            <small><?php echo h($evidence['submitted_email'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <span class="<?php echo h(badge_class((string)$evidence['review_status'])); ?>">
                                                <?php echo h($evidence['review_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $evidence['reviewer_score'] !== null ? number_format((float)$evidence['reviewer_score'], 1) . '/10' : '-'; ?></td>
                                        <td><?php echo !empty($evidence['created_at']) ? date('d M Y, h:i A', strtotime((string)$evidence['created_at'])) : '-'; ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary" onclick='openEvidenceModal(<?php echo json_encode($evidencePayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>
                                                <i class="fas fa-eye"></i> View / Score
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section id="tab-latest" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-list-check"></i> Recent Startup Milestones</h3>
                <a href="startup-milestones" class="btn btn-sm btn-soft">
                    <i class="fas fa-arrow-right"></i> View All
                </a>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Startup</th>
                            <th>Milestone</th>
                            <th>Review Area</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Recommendation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($latest)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="fas fa-rocket"></i>
                                    <span>No startup milestones yet.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($latest as $row): ?>
                                <?php $pct = max(0, min(100, (float)$row['progress_percentage'])); ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h($row['startup_name']); ?></strong>
                                        <br>
                                        <small>App #<?php echo (int)$row['application_id']; ?></small>
                                    </td>
                                    <td>
                                        <div class="milestone-name"><?php echo h($row['milestone_title']); ?></div>
                                        <div class="milestone-desc"><?php echo h(mb_substr((string)$row['milestone_description'], 0, 80)); ?></div>
                                    </td>
                                    <td><?php echo h($row['milestone_type']); ?></td>
                                    <td><?php echo h(format_date($row['due_date'] ?? null)); ?></td>
                                    <td>
                                        <span class="<?php echo h(badge_class((string)$row['status'])); ?>">
                                            <?php echo h($row['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress-cell">
                                            <div class="progress-header">
                                                <span class="progress-pct"><?php echo number_format($pct, 0); ?>%</span>
                                            </div>
                                            <div class="prog-track">
                                                <div class="prog-fill <?php echo h(progress_class($pct)); ?>" style="width:<?php echo $pct; ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo h($row['recommendation'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="tab-types" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-layer-group"></i> Review Area Breakdown</h3>
            </div>

            <div class="report-grid">
                <?php foreach ($typeRows as $row): ?>
                    <?php $pct = max(0, min(100, (float)$row['avg_progress'])); ?>
                    <div class="detail-summary-card">
                        <div class="label"><?php echo h($row['milestone_type']); ?></div>
                        <div class="value"><?php echo number_format((float)$row['total']); ?></div>
                        <div class="hint">Average progress: <?php echo number_format($pct, 0); ?>%</div>
                        <div class="prog-track" style="margin-top:10px;">
                            <div class="prog-fill <?php echo h(progress_class($pct)); ?>" style="width:<?php echo $pct; ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>


<!-- View Full Field Report Modal -->
<div id="fieldReportModal" class="modal">
    <div class="modal-content" style="max-width:980px;">
        <div class="modal-header">
            <h3><i class="fas fa-file-alt"></i> Field Report Details</h3>
            <span class="close" onclick="closeModal('fieldReportModal')">&times;</span>
        </div>

        <div class="modal-body">
            <div class="soft-box" style="margin-bottom:16px;">
                <h3 id="fr_startup_name" style="margin:0 0 6px;"></h3>
                <div style="color:var(--ink-300);font-size:13px;">
                    <strong id="fr_visit_code"></strong>
                    |
                    Application #<span id="fr_application_id"></span>
                    |
                    Submitted: <span id="fr_submitted_at"></span>
                </div>
            </div>

            <div class="detail-summary-grid" style="grid-template-columns:repeat(4,minmax(150px,1fr));">
                <div class="detail-summary-card">
                    <div class="label">Field Score</div>
                    <div class="value" id="fr_field_score">0/10</div>
                </div>
                <div class="detail-summary-card">
                    <div class="label">Field Recommendation</div>
                    <div class="value" style="font-size:16px;" id="fr_field_recommendation">-</div>
                </div>
                <div class="detail-summary-card">
                    <div class="label">Business Status</div>
                    <div class="value" style="font-size:16px;" id="fr_business_status">-</div>
                </div>
                <div class="detail-summary-card">
                    <div class="label">Visit Date</div>
                    <div class="value" style="font-size:16px;" id="fr_actual_visit_date">-</div>
                </div>
            </div>

            <div class="detail-grid-2" style="margin-top:16px;">
                <div>
                    <div class="section-title"><h3><i class="fas fa-info-circle"></i> Summary</h3></div>
                    <table class="info-table">
                        <tr><th>Milestone</th><td id="fr_milestone_title"></td></tr>
                        <tr><th>Review Area</th><td id="fr_milestone_type"></td></tr>
                        <tr><th>Assigned To</th><td id="fr_assigned_to"></td></tr>
                        <tr><th>Submitted By</th><td id="fr_submitted_by_name"></td></tr>
                        <tr><th>Location</th><td id="fr_location"></td></tr>
                        <tr><th>Review Score</th><td><span id="fr_review_score"></span> - <span id="fr_review_recommendation"></span></td></tr>
                        <tr><th>Report File</th><td id="fr_report_file"></td></tr>
                    </table>
                </div>

                <div>
                    <div class="section-title"><h3><i class="fas fa-star"></i> Scores /10</h3></div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Criteria</th>
                                    <th>Score</th>
                                </tr>
                            </thead>
                            <tbody id="fr_scores_body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="section-title" style="margin-top:16px;">
                <h3><i class="fas fa-align-left"></i> Field Notes</h3>
            </div>

            <div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px;">
                <div class="soft-box">
                    <strong>Observations</strong>
                    <p id="fr_observations" style="margin:8px 0 0;color:var(--ink-300);"></p>
                </div>
                <div class="soft-box">
                    <strong>Risks</strong>
                    <p id="fr_risks" style="margin:8px 0 0;color:var(--ink-300);"></p>
                </div>
                <div class="soft-box">
                    <strong>Support Needed</strong>
                    <p id="fr_support_needed" style="margin:8px 0 0;color:var(--ink-300);"></p>
                </div>
                <div class="soft-box">
                    <strong>Next Steps</strong>
                    <p id="fr_next_steps" style="margin:8px 0 0;color:var(--ink-300);"></p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="printFieldReportModal()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print This Report
                </button>
                <button type="button" onclick="closeModal('fieldReportModal')" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>



<!-- View / Score Venture Evidence Modal -->
<div id="evidenceModal" class="modal">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <h3><i class="fas fa-folder-open"></i> Venture Evidence Review</h3>
            <span class="close" onclick="closeModal('evidenceModal')">&times;</span>
        </div>

        <div class="modal-body">
            <div class="soft-box" style="margin-bottom:16px;">
                <h3 id="ev_startup_name" style="margin:0 0 6px;"></h3>
                <div style="color:var(--ink-300);font-size:13px;">
                    Milestone: <strong id="ev_milestone_title"></strong>
                </div>
            </div>

            <table class="info-table">
                <tr><th>Evidence Title</th><td id="ev_evidence_title"></td></tr>
                <tr><th>Description</th><td id="ev_evidence_description"></td></tr>
                <tr><th>Submitted By</th><td><span id="ev_submitted_name"></span><br><small id="ev_submitted_contact"></small></td></tr>
                <tr><th>File</th><td id="ev_file_link"></td></tr>
            </table>

            <form method="POST" action="includes/startup-milestone-evidence-review-process.php" style="margin-top:18px;">
                <input type="hidden" name="evidence_id" id="ev_evidence_id">

                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_review_status">Review Status</label>
                        <select name="review_status" id="ev_review_status" class="form-control" required>
                            <option value="Pending">Pending</option>
                            <option value="Reviewed">Reviewed</option>
                            <option value="Accepted">Accepted</option>
                            <option value="Needs Improvement">Needs Improvement</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="ev_reviewer_score">Score /10</label>
                        <input type="number" name="reviewer_score" id="ev_reviewer_score" class="form-control" min="0" max="10" step="0.01">
                    </div>
                </div>

                <div class="form-group">
                    <label for="ev_reviewer_comments">Reviewer Comments</label>
                    <textarea name="reviewer_comments" id="ev_reviewer_comments" class="form-control" rows="5" placeholder="Add comments for the startup/venture..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('evidenceModal')" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" name="review_milestone_evidence" class="btn btn-success"><i class="fas fa-save"></i> Save Review</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
(function () {
    'use strict';

    const buttons = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.tab-panel');

    function openTab(target) {
        buttons.forEach(function (btn) {
            btn.classList.remove('active');
        });

        panels.forEach(function (panel) {
            panel.classList.remove('active');
        });

        const button = document.querySelector('.tab-btn[data-tab="' + target + '"]');
        const panel = document.getElementById('tab-' + target);

        if (button) {
            button.classList.add('active');
        }

        if (panel) {
            panel.classList.add('active');
        }
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            openTab(button.getAttribute('data-tab'));
        });
    });

    const params = new URLSearchParams(window.location.search);

    if (params.get('active_tab')) {
        openTab(params.get('active_tab'));
    }


    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value || '-';
    }

    function setHtml(id, value) {
        const el = document.getElementById(id);
        if (el) el.innerHTML = value || '-';
    }

    function escHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"'`]/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;'}[c];
        });
    }

    function nl2brSafe(value) {
        const div = document.createElement('div');
        div.textContent = value || '-';
        return div.innerHTML.replace(/\n/g, '<br>');
    }

    window.openFieldReportModal = function (report) {
        if (!report) return;

        setText('fr_startup_name', report.startup_name);
        setText('fr_visit_code', report.visit_code);
        setText('fr_application_id', report.application_id);
        setText('fr_submitted_at', report.submitted_at);
        setText('fr_field_score', (report.field_overall_score || '0') + '/10');
        setText('fr_field_recommendation', report.field_recommendation);
        setText('fr_business_status', report.business_status);
        setText('fr_actual_visit_date', report.actual_visit_date);
        setText('fr_milestone_title', report.milestone_title);
        setText('fr_milestone_type', report.milestone_type);
        setText('fr_assigned_to', report.assigned_to);
        setText('fr_submitted_by_name', report.submitted_by_name);
        setText('fr_location', report.location);
        setText('fr_review_score', report.review_overall_score);
        setText('fr_review_recommendation', report.review_recommendation);

        if (report.report_file) {
            setHtml('fr_report_file', '<a href="' + escHtml(report.report_file) + '" class="btn btn-sm btn-soft" download><i class="fas fa-download"></i> Download ' + escHtml(report.report_original_name || 'Uploaded Report') + '</a>');
        } else {
            setHtml('fr_report_file', '<span style="color:var(--ink-200);">No file uploaded</span>');
        }

        const scoreRows = [
            ['Equity', report.equity_score],
            ['Video Intro', report.video_intro_score],
            ['Problem Depth / TOC', report.problem_depth_toc_score],
            ['Product Quality / Pedagogy', report.product_quality_pedagogy_score],
            ['Product Demo Link', report.product_demo_link_score],
            ['Scalability / Traction / Sustainability', report.scalability_traction_sustainability_score],
            ['Team Capability / Commitment', report.team_capability_commitment_score],
            ['URSB Registration', report.ursb_registration_score]
        ];

        const scoreBody = document.getElementById('fr_scores_body');
        if (scoreBody) {
            scoreBody.innerHTML = scoreRows.map(function (row) {
                return '<tr><td><strong>' + row[0] + '</strong></td><td>' + escHtml(row[1] || '0.0') + '/10</td></tr>';
            }).join('');
        }

        setHtml('fr_observations', nl2brSafe(report.observations));
        setHtml('fr_risks', nl2brSafe(report.risks));
        setHtml('fr_support_needed', nl2brSafe(report.support_needed));
        setHtml('fr_next_steps', nl2brSafe(report.next_steps));

        openModal('fieldReportModal');
    };

    window.printFieldReportModal = function () {
        const modal = document.getElementById('fieldReportModal');
        if (!modal) return;

        const body = modal.querySelector('.modal-body').innerHTML;
        const win = window.open('', '_blank', 'width=1000,height=800');

        win.document.write('<html><head><title>Startup Field Report</title>');
        win.document.write('<link rel="stylesheet" href="css/reports.css">');
        win.document.write('<link rel="stylesheet" href="css/milestones.css">');
        win.document.write('</head><body><div class="milestones-wrap">' + body + '</div></body></html>');
        win.document.close();
        win.focus();
        win.print();
    };



    window.openEvidenceModal = function (evidence) {
        if (!evidence) return;

        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value || '-';
        }

        function setValue(id, value) {
            const el = document.getElementById(id);
            if (el) el.value = value || '';
        }

        setValue('ev_evidence_id', evidence.evidence_id);
        setText('ev_startup_name', evidence.startup_name);
        setText('ev_milestone_title', evidence.milestone_title);
        setText('ev_evidence_title', evidence.evidence_title);
        setText('ev_evidence_description', evidence.evidence_description);
        setText('ev_submitted_name', evidence.submitted_name);
        setText('ev_submitted_contact', [evidence.submitted_email, evidence.submitted_phone].filter(Boolean).join(' | '));

        const fileCell = document.getElementById('ev_file_link');
        if (fileCell) {
            fileCell.innerHTML = evidence.evidence_file
                ? '<a href="' + escHtml(evidence.evidence_file) + '" class="btn btn-sm btn-soft" download><i class="fas fa-download"></i> Download ' + escHtml(evidence.original_file_name || 'Evidence File') + '</a>'
                : '<span style="color:var(--ink-200);">No file</span>';
        }

        setValue('ev_review_status', evidence.review_status || 'Pending');
        setValue('ev_reviewer_score', evidence.reviewer_score || '');
        setValue('ev_reviewer_comments', evidence.reviewer_comments || '');

        openModal('evidenceModal');
    };


    window.exportFieldReportsCSV = function () {
        const table = document.getElementById('fieldReportsTable');

        if (!table) {
            alert('No report table found.');
            return;
        }

        const rows = Array.from(table.querySelectorAll('tr'));
        const csv = rows.map(function (row) {
            const cols = Array.from(row.querySelectorAll('th,td')).map(function (cell) {
                let value = cell.innerText.replace(/\s+/g, ' ').trim();
                // Neutralise spreadsheet formulas (same rule as ims_csv_safe()).
                if (/^[=+\-@\t\r]/.test(value) && value !== '-' && isNaN(Number(value))) {
                    value = "'" + value;
                }
                value = value.replace(/"/g, '""');
                return '"' + value + '"';
            });

            return cols.join(',');
        }).join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = 'startup-field-reports-' + new Date().toISOString().split('T')[0] + '.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };
})();
</script>

<?php include 'includes/footer.php'; ?>
