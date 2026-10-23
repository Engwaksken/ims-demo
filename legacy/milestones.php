<?php
declare(strict_types=1);

$page_title = 'Milestones';
include 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';
check_role(IMS_ALL_ROLES);


if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

require_once 'includes/workplan-stats.php';

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function ms_table_exists(mysqli $conn, string $table): bool
{
    $res = $conn->query(
        "SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'"
    );

    if (!$res) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->close();

    return $exists;
}

function ms_column_exists(mysqli $conn, string $table, string $column): bool
{
    if (!ms_table_exists($conn, $table)) {
        return false;
    }

    $res = $conn->query(
        "SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` "
        . "LIKE '" . $conn->real_escape_string($column) . "'"
    );

    if (!$res) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->close();

    return $exists;
}

function ms_bind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || !$params) {
        return;
    }

    $refs = [$types];

    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function ms_date(mixed $value, string $fallback = '-'): string
{
    if ($value === null || trim((string)$value) === '') {
        return $fallback;
    }

    $ts = strtotime((string)$value);

    return $ts === false
        ? $fallback
        : date('d M Y', $ts);
}

function ms_initials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $name) ?: [];

    if (count($parts) >= 2) {
        return strtoupper(
            mb_substr($parts[0], 0, 1)
            . mb_substr($parts[1], 0, 1)
        );
    }

    return strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
}

function ms_progress_class(float $progress): string
{
    if ($progress >= 75) {
        return 'high';
    }

    if ($progress >= 40) {
        return 'mid';
    }

    return 'low';
}

function ms_status_class(string $status): string
{
    return match ($status) {
        'Completed' => 'badge-completed',
        'In Progress' => 'badge-in-progress',
        'Delayed' => 'badge-delayed',
        'Cancelled' => 'badge-cancelled',
        'Pending', 'Not Started' => 'badge-not-started',
        default => 'badge-not-started',
    };
}

/*
|--------------------------------------------------------------------------
| REQUIRED WORKPLAN TABLES
|--------------------------------------------------------------------------
*/

$requiredTables = [
    'workplans',
    'workplan_milestones',
    'workplan_deliverables',
];

foreach ($requiredTables as $requiredTable) {
    if (!ms_table_exists($conn, $requiredTable)) {
        die(
            'Required workplan table is missing: '
            . h($requiredTable)
        );
    }
}

$hasPrograms = ms_table_exists($conn, 'programs');

$milestoneHasStatus = ms_column_exists(
    $conn,
    'workplan_milestones',
    'status'
);

$milestoneHasProgress = ms_column_exists(
    $conn,
    'workplan_milestones',
    'progress_percentage'
);

$milestoneHasFrequency = ms_column_exists(
    $conn,
    'workplan_milestones',
    'calendar_frequency'
);

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$filterWorkplan = max(0, (int)($_GET['workplan_id'] ?? 0));
$filterEntityType = trim((string)($_GET['entity_type'] ?? ''));
$filterEntity = max(0, (int)($_GET['entity'] ?? 0));
$filterYear = max(0, (int)($_GET['year'] ?? 0));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

$allowedEntityTypes = ['Project', 'Program'];
$allowedStatuses = [
    'Pending',
    'Not Started',
    'In Progress',
    'Completed',
    'Delayed',
    'Cancelled',
];
$allowedTypes = WP_MILESTONE_TYPES;

if (!in_array($filterEntityType, $allowedEntityTypes, true)) {
    $filterEntityType = '';
}

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

if (!in_array($filterType, $allowedTypes, true)) {
    $filterType = '';
}

/*
|--------------------------------------------------------------------------
| WORKPLAN FILTER OPTIONS
|--------------------------------------------------------------------------
*/

$workplans = wp_list_workplans($conn);

$years = [];

$res = $conn->query("
    SELECT DISTINCT workplan_year
    FROM workplans
    ORDER BY workplan_year DESC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $years[] = (int)$row['workplan_year'];
    }

    $res->close();
}

$projects = [];

$res = $conn->query("
    SELECT
        project_id,
        project_code,
        project_name
    FROM projects
    ORDER BY project_code, project_name
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row;
    }

    $res->close();
}

$programs = [];

if ($hasPrograms) {
    $res = $conn->query("
        SELECT
            id,
            program_code,
            program_name
        FROM programs
        ORDER BY program_code, program_name
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $programs[] = $row;
        }

        $res->close();
    }
}

/*
|--------------------------------------------------------------------------
| MILESTONES QUERY
|--------------------------------------------------------------------------
|
| This page intentionally reads ONLY from the workplan structure:
|
| workplans
|   -> workplan_milestones
|      -> workplan_deliverables
|
| The old milestones / milestone_deliverables tables are not used here.
|--------------------------------------------------------------------------
*/

$statusSelect = $milestoneHasStatus
    ? "wm.status AS milestone_status"
    : "'Pending' AS milestone_status";

$progressSelect = $milestoneHasProgress
    ? "wm.progress_percentage AS stored_milestone_progress"
    : "NULL AS stored_milestone_progress";

$frequencySelect = $milestoneHasFrequency
    ? "wm.calendar_frequency"
    : "'Annual' AS calendar_frequency";

$sql = "
    SELECT
        wm.id AS milestone_id,
        wm.workplan_id,
        wm.milestone_name,
        wm.milestone_description,
        wm.milestone_type,
        wm.start_date,
        wm.due_date,
        wm.responsible_person,
        wm.sort_order,

        {$statusSelect},
        {$progressSelect},
        {$frequencySelect},

        w.entity_type,
        w.entity_id,
        w.workplan_year,

        CASE
            WHEN w.entity_type = 'Project'
                THEN p.project_code
            WHEN w.entity_type = 'Program'
                THEN pr.program_code
            ELSE NULL
        END AS entity_code,

        CASE
            WHEN w.entity_type = 'Project'
                THEN p.project_name
            WHEN w.entity_type = 'Program'
                THEN pr.program_name
            ELSE NULL
        END AS entity_name,

        COUNT(wd.id) AS activity_count,

        SUM(
            CASE
                WHEN wd.status = 'Completed'
                    THEN 1
                ELSE 0
            END
        ) AS completed_activity_count,

        SUM(
            CASE
                WHEN wd.status = 'Delayed'
                    THEN 1
                ELSE 0
            END
        ) AS delayed_activity_count,

        ROUND(
            COALESCE(
                AVG(
                    COALESCE(
                        wd.progress_percentage,
                        0
                    )
                ),
                0
            ),
            1
        ) AS activity_progress,

        MIN(wd.start_date) AS first_activity_start,
        MAX(wd.end_date) AS last_activity_end,

        DATEDIFF(
            wm.due_date,
            CURDATE()
        ) AS days_until_due

    FROM workplan_milestones wm

    INNER JOIN workplans w
        ON w.id = wm.workplan_id

    LEFT JOIN projects p
        ON w.entity_type = 'Project'
       AND p.project_id = w.entity_id

    LEFT JOIN
";

if ($hasPrograms) {
    $sql .= " programs pr
        ON w.entity_type = 'Program'
       AND pr.id = w.entity_id ";
} else {
    $sql .= " (
        SELECT
            NULL AS id,
            NULL AS program_code,
            NULL AS program_name
    ) pr ON 1 = 0 ";
}

$sql .= "
    LEFT JOIN workplan_deliverables wd
        ON wd.milestone_id = wm.id
";

$where = [];
$types = '';
$params = [];

if ($filterWorkplan > 0) {
    $where[] = 'w.id = ?';
    $types .= 'i';
    $params[] = $filterWorkplan;
}

if ($filterEntityType !== '') {
    $where[] = 'w.entity_type = ?';
    $types .= 's';
    $params[] = $filterEntityType;
}

if ($filterEntity > 0) {
    $where[] = 'w.entity_id = ?';
    $types .= 'i';
    $params[] = $filterEntity;
}

if ($filterYear > 0) {
    $where[] = 'w.workplan_year = ?';
    $types .= 'i';
    $params[] = $filterYear;
}

if ($filterType !== '') {
    $where[] = 'wm.milestone_type = ?';
    $types .= 's';
    $params[] = $filterType;
}

if ($filterStatus !== '') {
    if ($milestoneHasStatus) {
        $where[] = 'wm.status = ?';
    } else {
        /*
         * Before the workplan-calendar migration milestones do not
         * have their own status. In that case Pending is the only
         * direct milestone status available.
         */
        $where[] = "'Pending' = ?";
    }

    $types .= 's';
    $params[] = $filterStatus;
}

if ($search !== '') {
    $like = '%' . $search . '%';

    $where[] = "(
        wm.milestone_name LIKE ?
        OR wm.milestone_description LIKE ?
        OR wm.responsible_person LIKE ?
        OR wd.title LIKE ?
        OR p.project_code LIKE ?
        OR p.project_name LIKE ?
        OR pr.program_code LIKE ?
        OR pr.program_name LIKE ?
    )";

    $types .= 'ssssssss';

    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
    }
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= "
    GROUP BY
        wm.id,
        wm.workplan_id,
        wm.milestone_name,
        wm.milestone_description,
        wm.milestone_type,
        wm.start_date,
        wm.due_date,
        wm.responsible_person,
        wm.sort_order,
        w.entity_type,
        w.entity_id,
        w.workplan_year,
        p.project_code,
        p.project_name,
        pr.program_code,
        pr.program_name
";

if ($milestoneHasStatus) {
    $sql .= ", wm.status";
}

if ($milestoneHasProgress) {
    $sql .= ", wm.progress_percentage";
}

if ($milestoneHasFrequency) {
    $sql .= ", wm.calendar_frequency";
}

$sql .= "
    ORDER BY
        w.workplan_year DESC,
        wm.due_date ASC,
        wm.sort_order ASC,
        wm.id ASC
";

$milestones = [];

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log(
        'Milestones workplan query prepare failed: '
        . $conn->error
    );

    die('Unable to load workplan milestones.');
}

ms_bind($stmt, $types, $params);

$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $activityProgress = (float)(
            $row['activity_progress']
            ?? 0
        );

        $storedProgress = $row['stored_milestone_progress'];

        /*
         * Activity progress is the primary displayed milestone progress.
         * If there are no activities, use the stored milestone progress
         * from the calendar extension when available.
         */
        if ((int)$row['activity_count'] > 0) {
            $row['display_progress'] = $activityProgress;
        } elseif ($storedProgress !== null) {
            $row['display_progress'] = (float)$storedProgress;
        } else {
            $row['display_progress'] = 0.0;
        }

        if (
            (string)$row['milestone_status'] === 'Completed'
        ) {
            $row['display_progress'] = 100.0;
        }

        $milestones[] = $row;
    }
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| PAGE STATS
|--------------------------------------------------------------------------
*/

$todayTs = strtotime(date('Y-m-d'));

$stats = [
    'total' => count($milestones),
    'in_progress' => 0,
    'completed' => 0,
    'delayed' => 0,
    'pending' => 0,
    'overdue' => 0,
    'activities' => 0,
];

foreach ($milestones as $milestone) {
    $status = (string)(
        $milestone['milestone_status']
        ?? 'Pending'
    );

    $stats['activities'] += (int)(
        $milestone['activity_count']
        ?? 0
    );

    if ($status === 'In Progress') {
        $stats['in_progress']++;
    } elseif ($status === 'Completed') {
        $stats['completed']++;
    } elseif ($status === 'Delayed') {
        $stats['delayed']++;
    } elseif (in_array($status, ['Pending', 'Not Started'], true)) {
        $stats['pending']++;
    }

    $dueTs = !empty($milestone['due_date'])
        ? strtotime((string)$milestone['due_date'])
        : false;

    if (
        $dueTs
        &&
        !in_array($status, ['Completed', 'Cancelled'], true)
        &&
        $dueTs < $todayTs
    ) {
        $stats['overdue']++;
    }
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalRows = count($milestones);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$pageMilestones = array_slice(
    $milestones,
    $offset,
    $perPage
);

function ms_page_url(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;

    return 'milestones?' . http_build_query($query);
}
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">

<style>
/* ==========================================================================
   WORKPLAN-BASED MILESTONES
   ========================================================================== */

.ms-stat-click {
    color: inherit;
    text-decoration: none;
}

.ms-stat-click .stat-card {
    height: 100%;

    transition:
        transform .18s ease,
        box-shadow .18s ease;
}

.ms-stat-click:hover .stat-card {
    transform: translateY(-2px);

    box-shadow: var(--shadow-md);
}

.ms-workplan-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ms-workplan-title {
    color: var(--ink-700);

    font-size: 12px;
    font-weight: 800;
}

.ms-workplan-year {
    color: var(--ink-300);

    font-size: 10.5px;
    font-weight: 700;
}

.ms-activity-summary {
    display: flex;
    flex-direction: column;
    gap: 5px;

    min-width: 150px;
}

.ms-activity-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;

    width: fit-content;

    padding: 4px 8px;

    border-radius: 999px;

    background: var(--green-bg);
    color: var(--green-fg);

    font-size: 10.5px;
    font-weight: 800;
}

.ms-activity-range {
    color: var(--ink-300);

    font-size: 10.5px;
}

.ms-progress-line {
    min-width: 120px;
}

.ms-progress-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    margin-bottom: 5px;

    color: var(--ink-400);

    font-size: 10.5px;
    font-weight: 800;
}

.ms-progress-track {
    height: 7px;

    overflow: hidden;

    border-radius: 999px;

    background: var(--ink-100);
}

.ms-progress-fill {
    display: block;

    height: 100%;

    border-radius: 999px;
}

.ms-progress-fill.high {
    background: var(--green-fg);
}

.ms-progress-fill.mid {
    background: var(--amber-fg);
}

.ms-progress-fill.low {
    background: var(--blue-fg);
}

.ms-frequency {
    display: inline-flex;
    align-items: center;
    gap: 5px;

    padding: 4px 8px;

    border-radius: 999px;

    background: var(--slate-bg);
    color: var(--slate-fg);

    font-size: 10px;
    font-weight: 800;
}

.ms-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    padding: 14px 16px;

    border-top: 1px solid var(--ink-100);
}

.ms-pagination-info {
    color: var(--ink-300);

    font-size: 11.5px;
    font-weight: 700;
}

.ms-pagination-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
}

.ms-page-btn {
    min-width: 34px;
    height: 34px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 0 9px;

    border: 1px solid var(--ink-100);
    border-radius: var(--radius-sm);

    background: var(--surface-card);
    color: var(--ink-400);

    font-size: 11px;
    font-weight: 800;

    text-decoration: none;
}

.ms-page-btn:hover {
    background: var(--brand-50);
    color: var(--brand-700);
}

.ms-page-btn.active {
    border-color: var(--brand-500);

    background: var(--brand-500);
    color: var(--ink-0);
}

@media (max-width: 800px) {
    .ms-pagination {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<div class="milestones-wrap page-stack">

    <!-- ================================================================
         HERO
         ================================================================ -->

    <div class="page-hero">

        <div>

            <h1>

                <i
                    class="fas fa-flag-checkered"
                    aria-hidden="true"
                ></i>

                Workplan Milestones

            </h1>

            <p>
                Track milestones and activities directly from saved
                project and programme workplans.
            </p>

        </div>

        <div class="hero-actions">

            <a
                href="workplan#wp-builder"
                class="btn btn-primary"
            >

                <i class="fas fa-plus"></i>

                Add via Workplan

            </a>

        </div>

    </div>


    <div class="alert alert-info">

        <i class="fas fa-circle-info"></i>

        Milestones on this page now come directly from
        <strong>Workplans ? Milestones ? Activities</strong>.
        Create or edit milestones from the Workplan page so both pages
        always remain synchronised.

    </div>


    <!-- ================================================================
         STATS
         ================================================================ -->

    <div class="stats-grid reports-stats milestones-stats">

        <a
            href="milestones"
            class="ms-stat-click"
        >

            <div class="stat-card">

                <div class="stat-icon bg-blue">
                    <i class="fas fa-flag-checkered"></i>
                </div>

                <div class="stat-info">

                    <div class="stat-label">
                        Milestones
                    </div>

                    <div class="stat-value">
                        <?= (int)$stats['total'] ?>
                    </div>

                </div>

            </div>

        </a>


        <div class="stat-card">

            <div class="stat-icon bg-purple">
                <i class="fas fa-list-check"></i>
            </div>

            <div class="stat-info">

                <div class="stat-label">
                    Activities
                </div>

                <div class="stat-value">
                    <?= (int)$stats['activities'] ?>
                </div>

            </div>

        </div>


        <a
            href="milestones?status=In+Progress"
            class="ms-stat-click"
        >

            <div class="stat-card">

                <div class="stat-icon bg-amber">
                    <i class="fas fa-spinner"></i>
                </div>

                <div class="stat-info">

                    <div class="stat-label">
                        In Progress
                    </div>

                    <div class="stat-value">
                        <?= (int)$stats['in_progress'] ?>
                    </div>

                </div>

            </div>

        </a>


        <a
            href="milestones?status=Completed"
            class="ms-stat-click"
        >

            <div class="stat-card">

                <div class="stat-icon bg-green">
                    <i class="fas fa-check-circle"></i>
                </div>

                <div class="stat-info">

                    <div class="stat-label">
                        Completed
                    </div>

                    <div class="stat-value">
                        <?= (int)$stats['completed'] ?>
                    </div>

                </div>

            </div>

        </a>


        <div class="stat-card">

            <div
                class="stat-icon"
                style="background:var(--red-bg);color:var(--red-fg);"
            >
                <i class="fas fa-exclamation-triangle"></i>
            </div>

            <div class="stat-info">

                <div class="stat-label">
                    Overdue
                </div>

                <div
                    class="stat-value"
                    style="color:var(--red-fg);"
                >
                    <?= (int)$stats['overdue'] ?>
                </div>

            </div>

        </div>


        <a
            href="milestones?status=Delayed"
            class="ms-stat-click"
        >

            <div class="stat-card">

                <div class="stat-icon bg-purple">
                    <i class="fas fa-clock"></i>
                </div>

                <div class="stat-info">

                    <div class="stat-label">
                        Delayed
                    </div>

                    <div class="stat-value">
                        <?= (int)$stats['delayed'] ?>
                    </div>

                </div>

            </div>

        </a>

    </div>


    <!-- ================================================================
         FILTERS
         ================================================================ -->

    <form
        method="GET"
        action="milestones"
        class="filter-bar filters-bar"
    >

        <div class="form-group search-group filters-grow">

            <label class="form-label">
                Search
            </label>

            <div class="search-input-wrap">

                <i class="fas fa-search"></i>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Milestone, activity, responsible person..."
                    value="<?= h($search) ?>"
                >

            </div>

        </div>


        <div class="form-group">

            <label class="form-label">
                Workplan
            </label>

            <select
                name="workplan_id"
                class="form-control"
            >

                <option value="">
                    All Workplans
                </option>

                <?php foreach ($workplans as $workplan): ?>

                    <option
                        value="<?= (int)$workplan['id'] ?>"
                        <?= $filterWorkplan === (int)$workplan['id']
                            ? 'selected'
                            : '' ?>
                    >

                        <?= h(
                            $workplan['label']
                            . ' - '
                            . $workplan['workplan_year']
                        ) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="form-group">

            <label class="form-label">
                Year
            </label>

            <select
                name="year"
                class="form-control"
            >

                <option value="">
                    All Years
                </option>

                <?php foreach ($years as $year): ?>

                    <option
                        value="<?= $year ?>"
                        <?= $filterYear === $year
                            ? 'selected'
                            : '' ?>
                    >

                        <?= $year ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="form-group">

            <label class="form-label">
                Entity Type
            </label>

            <select
                name="entity_type"
                id="entityTypeFilter"
                class="form-control"
                onchange="toggleEntityFilter()"
            >

                <option value="">
                    All Entities
                </option>

                <option
                    value="Project"
                    <?= $filterEntityType === 'Project'
                        ? 'selected'
                        : '' ?>
                >
                    Projects
                </option>

                <?php if ($hasPrograms): ?>

                    <option
                        value="Program"
                        <?= $filterEntityType === 'Program'
                            ? 'selected'
                            : '' ?>
                    >
                        Programs
                    </option>

                <?php endif; ?>

            </select>

        </div>


        <div
            class="form-group"
            id="projectFilter"
            style="display:
                <?= $filterEntityType === 'Program'
                    ? 'none'
                    : 'flex' ?>;"
        >

            <label class="form-label">
                Project
            </label>

            <select
                name="<?= $filterEntityType === 'Program'
                    ? 'project_entity_unused'
                    : 'entity' ?>"
                class="form-control"
            >

                <option value="">
                    All Projects
                </option>

                <?php foreach ($projects as $project): ?>

                    <option
                        value="<?= (int)$project['project_id'] ?>"
                        <?= (
                            $filterEntityType === 'Project'
                            &&
                            $filterEntity === (int)$project['project_id']
                        )
                            ? 'selected'
                            : '' ?>
                    >

                        <?= h(
                            ($project['project_code'] ?? '')
                            . ' - '
                            . ($project['project_name'] ?? '')
                        ) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <?php if ($hasPrograms): ?>

            <div
                class="form-group"
                id="programFilter"
                style="display:
                    <?= $filterEntityType === 'Program'
                        ? 'flex'
                        : 'none' ?>;"
            >

                <label class="form-label">
                    Program
                </label>

                <select
                    name="<?= $filterEntityType === 'Program'
                        ? 'entity'
                        : 'program_entity_unused' ?>"
                    class="form-control"
                >

                    <option value="">
                        All Programs
                    </option>

                    <?php foreach ($programs as $program): ?>

                        <option
                            value="<?= (int)$program['id'] ?>"
                            <?= (
                                $filterEntityType === 'Program'
                                &&
                                $filterEntity === (int)$program['id']
                            )
                                ? 'selected'
                                : '' ?>
                        >

                            <?= h(
                                ($program['program_code'] ?? '')
                                . ' - '
                                . ($program['program_name'] ?? '')
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        <?php endif; ?>


        <div class="form-group">

            <label class="form-label">
                Status
            </label>

            <select
                name="status"
                class="form-control"
            >

                <option value="">
                    All Statuses
                </option>

                <?php foreach ($allowedStatuses as $status): ?>

                    <option
                        value="<?= h($status) ?>"
                        <?= $filterStatus === $status
                            ? 'selected'
                            : '' ?>
                    >

                        <?= h($status) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="form-group">

            <label class="form-label">
                Type
            </label>

            <select
                name="type"
                class="form-control"
            >

                <option value="">
                    All Types
                </option>

                <?php foreach ($allowedTypes as $type): ?>

                    <option
                        value="<?= h($type) ?>"
                        <?= $filterType === $type
                            ? 'selected'
                            : '' ?>
                    >

                        <?= h($type) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="filter-actions">

            <button
                type="submit"
                class="btn btn-dark"
            >

                <i class="fas fa-filter"></i>

                Filter

            </button>


            <a
                href="milestones"
                class="btn btn-gray"
            >

                <i class="fas fa-times"></i>

                Clear

            </a>

        </div>

    </form>


    <!-- ================================================================
         MILESTONES TABLE
         ================================================================ -->

    <div class="panel">

        <div class="panel-head">

            <h3>

                <i
                    class="fas fa-flag-checkered"
                    style="color:var(--brand-500);margin-right:8px;"
                ></i>

                Workplan Milestones

            </h3>

            <span
                class="badge
                    <?= $stats['total'] > 0
                        ? 'badge-available'
                        : 'badge-pending' ?>"
            >

                <?= (int)$stats['total'] ?>

                record<?= $stats['total'] === 1
                    ? ''
                    : 's' ?>

            </span>

        </div>


        <div class="table-wrap">

            <table class="table">

                <thead>

                    <tr>

                        <th>Milestone</th>
                        <th>Workplan</th>
                        <th>Entity</th>
                        <th>Type</th>
                        <th>Activities</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Responsible</th>
                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody>

                    <?php if (!$pageMilestones): ?>

                        <tr>

                            <td
                                colspan="10"
                                style="text-align:center;padding:48px 16px;"
                            >

                                <div
                                    style="
                                        display:flex;
                                        flex-direction:column;
                                        align-items:center;
                                        gap:10px;
                                        color:var(--ink-200);
                                    "
                                >

                                    <i
                                        class="fas fa-flag-checkered"
                                        style="font-size:32px;"
                                    ></i>

                                    <span
                                        style="
                                            font-size:14px;
                                            font-weight:600;
                                            color:var(--ink-300);
                                        "
                                    >

                                        No workplan milestones found

                                    </span>

                                    <span class="note">

                                        Adjust your filters or add milestones
                                        from the Workplan page.

                                    </span>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($pageMilestones as $milestone): ?>

                            <?php
                            $status = (string)(
                                $milestone['milestone_status']
                                ?? 'Pending'
                            );

                            $progress = max(
                                0.0,
                                min(
                                    100.0,
                                    (float)(
                                        $milestone['display_progress']
                                        ?? 0
                                    )
                                )
                            );

                            $dueTs = !empty($milestone['due_date'])
                                ? strtotime((string)$milestone['due_date'])
                                : false;

                            $isOverdue = (
                                $dueTs
                                &&
                                !in_array(
                                    $status,
                                    ['Completed', 'Cancelled'],
                                    true
                                )
                                &&
                                $dueTs < $todayTs
                            );

                            $activityCount = (int)(
                                $milestone['activity_count']
                                ?? 0
                            );

                            $completedActivities = (int)(
                                $milestone['completed_activity_count']
                                ?? 0
                            );

                            $workplanLabel = trim(
                                (string)(
                                    $milestone['entity_code']
                                    ?? ''
                                )
                                . ' - '
                                . (string)(
                                    $milestone['entity_name']
                                    ?? ''
                                ),
                                " -"
                            );
                            ?>

                            <tr>

                                <td style="max-width:260px;">

                                    <div class="milestone-name">

                                        <?= h(
                                            $milestone[
                                                'milestone_name'
                                            ]
                                            ?? ''
                                        ) ?>

                                    </div>


                                    <?php if (
                                        !empty(
                                            $milestone[
                                                'milestone_description'
                                            ]
                                        )
                                    ): ?>

                                        <div class="milestone-desc">

                                            <?php
                                            $description = (string)$milestone[
                                                'milestone_description'
                                            ];

                                            echo h(
                                                mb_strlen($description) > 80
                                                    ? mb_substr(
                                                        $description,
                                                        0,
                                                        80
                                                    ) . '...'
                                                    : $description
                                            );
                                            ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <div class="ms-workplan-cell">

                                        <span class="ms-workplan-title">

                                            <?= h(
                                                $workplanLabel !== ''
                                                    ? $workplanLabel
                                                    : (
                                                        $milestone[
                                                            'entity_type'
                                                        ]
                                                        . ' #'
                                                        . $milestone[
                                                            'entity_id'
                                                        ]
                                                    )
                                            ) ?>

                                        </span>

                                        <span class="ms-workplan-year">

                                            Workplan
                                            <?= (int)$milestone[
                                                'workplan_year'
                                            ] ?>

                                        </span>

                                    </div>

                                </td>


                                <td>

                                    <span
                                        class="
                                            entity-chip
                                            <?= strtolower(
                                                (string)$milestone[
                                                    'entity_type'
                                                ]
                                            ) === 'project'
                                                ? 'project'
                                                : 'program' ?>
                                        "
                                    >

                                        <i
                                            class="
                                                fas
                                                <?= $milestone[
                                                    'entity_type'
                                                ] === 'Project'
                                                    ? 'fa-project-diagram'
                                                    : 'fa-sitemap' ?>
                                            "
                                        ></i>

                                        <?= h(
                                            $milestone[
                                                'entity_type'
                                            ]
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <span
                                        style="
                                            font-size:12px;
                                            font-weight:700;
                                            color:var(--ink-400);
                                        "
                                    >

                                        <?= h(
                                            $milestone[
                                                'milestone_type'
                                            ]
                                            ?? '-'
                                        ) ?>

                                    </span>

                                    <div style="margin-top:5px;">

                                        <span class="ms-frequency">

                                            <i
                                                class="
                                                    fas
                                                    fa-repeat
                                                "
                                            ></i>

                                            <?= h(
                                                $milestone[
                                                    'calendar_frequency'
                                                ]
                                                ?? 'Annual'
                                            ) ?>

                                        </span>

                                    </div>

                                </td>


                                <td>

                                    <div class="ms-activity-summary">

                                        <?php if ($activityCount > 0): ?>

                                            <span class="ms-activity-pill">

                                                <i
                                                    class="
                                                        fas
                                                        fa-list-check
                                                    "
                                                ></i>

                                                <?= $completedActivities ?>
                                                /
                                                <?= $activityCount ?>

                                                completed

                                            </span>

                                            <span class="ms-activity-range">

                                                <?= h(
                                                    ms_date(
                                                        $milestone[
                                                            'first_activity_start'
                                                        ]
                                                        ?? null
                                                    )
                                                ) ?>

                                                - 

                                                <?= h(
                                                    ms_date(
                                                        $milestone[
                                                            'last_activity_end'
                                                        ]
                                                        ?? null
                                                    )
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span
                                                style="
                                                    color:var(--ink-200);
                                                    font-size:11px;
                                                "
                                            >

                                                No activities

                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>


                                <td>

                                    <div
                                        style="
                                            display:flex;
                                            flex-direction:column;
                                            gap:4px;
                                            font-size:11px;
                                        "
                                    >

                                        <span>

                                            <strong>Start:</strong>

                                            <?= h(
                                                ms_date(
                                                    $milestone[
                                                        'start_date'
                                                    ]
                                                    ?? null
                                                )
                                            ) ?>

                                        </span>

                                        <span
                                            style="
                                                color:
                                                <?= $isOverdue
                                                    ? 'var(--red-fg)'
                                                    : 'var(--ink-400)' ?>;
                                            "
                                        >

                                            <strong>Due:</strong>

                                            <?= h(
                                                ms_date(
                                                    $milestone[
                                                        'due_date'
                                                    ]
                                                    ?? null
                                                )
                                            ) ?>

                                        </span>

                                        <?php if ($isOverdue): ?>

                                            <span
                                                class="
                                                    badge
                                                    badge-overdue
                                                "
                                                style="
                                                    width:fit-content;
                                                    font-size:9px;
                                                    padding:2px 7px;
                                                "
                                            >

                                                <i
                                                    class="
                                                        fas
                                                        fa-exclamation-circle
                                                    "
                                                ></i>

                                                Overdue

                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>


                                <td>

                                    <span
                                        class="
                                            badge
                                            <?= h(
                                                ms_status_class(
                                                    $status
                                                )
                                            ) ?>
                                        "
                                    >

                                        <?= h($status) ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="ms-progress-line">

                                        <div class="ms-progress-header">

                                            <span>
                                                Activity progress
                                            </span>

                                            <span>
                                                <?= number_format(
                                                    $progress,
                                                    0
                                                ) ?>%
                                            </span>

                                        </div>

                                        <div class="ms-progress-track">

                                            <span
                                                class="
                                                    ms-progress-fill
                                                    <?= h(
                                                        ms_progress_class(
                                                            $progress
                                                        )
                                                    ) ?>
                                                "
                                                style="
                                                    width:
                                                    <?= $progress ?>%;
                                                "
                                            ></span>

                                        </div>

                                    </div>

                                </td>


                                <td>

                                    <?php if (
                                        !empty(
                                            $milestone[
                                                'responsible_person'
                                            ]
                                        )
                                    ): ?>

                                        <div class="responsible-cell">

                                            <div class="responsible-avatar">

                                                <?= h(
                                                    ms_initials(
                                                        (string)$milestone[
                                                            'responsible_person'
                                                        ]
                                                    )
                                                ) ?>

                                            </div>

                                            <span>

                                                <?= h(
                                                    $milestone[
                                                        'responsible_person'
                                                    ]
                                                ) ?>

                                            </span>

                                        </div>

                                    <?php else: ?>

                                        <span
                                            style="
                                                color:var(--ink-200);
                                                font-size:11px;
                                            "
                                        >
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <div class="actions">

                                        <a
                                            href="workplan?workplan_id=<?= (int)$milestone['workplan_id'] ?>#wp-calendar"
                                            class="btn btn-sm btn-soft"
                                            title="Open calendar"
                                        >

                                            <i
                                                class="
                                                    fas
                                                    fa-calendar-days
                                                "
                                            ></i>

                                        </a>


                                        <a
                                            href="workplan?workplan_id=<?= (int)$milestone['workplan_id'] ?>#wp-builder"
                                            class="btn btn-sm btn-gray"
                                            title="Edit in workplan"
                                        >

                                            <i
                                                class="
                                                    fas
                                                    fa-pen-to-square
                                                "
                                            ></i>

                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <?php if ($totalPages > 1): ?>

            <div class="ms-pagination">

                <div class="ms-pagination-info">

                    Showing
                    <?= $offset + 1 ?>
                    -
                    <?= min(
                        $offset + $perPage,
                        $totalRows
                    ) ?>
                    of
                    <?= $totalRows ?>
                    milestones

                </div>


                <div class="ms-pagination-buttons">

                    <?php if ($page > 1): ?>

                        <a
                            href="<?= h(
                                ms_page_url(
                                    $page - 1
                                )
                            ) ?>"
                            class="ms-page-btn"
                            title="Previous page"
                        >

                            <i
                                class="
                                    fas
                                    fa-chevron-left
                                "
                            ></i>

                        </a>

                    <?php endif; ?>


                    <?php
                    for (
                        $p = 1;
                        $p <= $totalPages;
                        $p++
                    ):

                        if (
                            $p !== 1
                            &&
                            $p !== $totalPages
                            &&
                            abs($p - $page) > 1
                        ) {
                            continue;
                        }
                    ?>

                        <a
                            href="<?= h(
                                ms_page_url(
                                    $p
                                )
                            ) ?>"
                            class="
                                ms-page-btn
                                <?= $p === $page
                                    ? 'active'
                                    : '' ?>
                            "
                        >

                            <?= $p ?>

                        </a>

                    <?php endfor; ?>


                    <?php if ($page < $totalPages): ?>

                        <a
                            href="<?= h(
                                ms_page_url(
                                    $page + 1
                                )
                            ) ?>"
                            class="ms-page-btn"
                            title="Next page"
                        >

                            <i
                                class="
                                    fas
                                    fa-chevron-right
                                "
                            ></i>

                        </a>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>


<script>
(function () {

    'use strict';


    window.toggleEntityFilter =
        function () {

            const type =
                document
                    .getElementById(
                        'entityTypeFilter'
                    )
                    ?.value
                ||
                '';


            const project =
                document
                    .getElementById(
                        'projectFilter'
                    );


            const program =
                document
                    .getElementById(
                        'programFilter'
                    );


            if (
                type === 'Program'
            ) {

                if (project) {
                    project.style.display =
                        'none';
                }


                if (program) {
                    program.style.display =
                        'flex';
                }

            } else {

                if (project) {
                    project.style.display =
                        'flex';
                }


                if (program) {
                    program.style.display =
                        'none';
                }
            }
        };

})();
</script>

<?php include 'includes/footer.php'; ?>
