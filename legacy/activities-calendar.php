<?php
declare(strict_types=1);

$page_title = 'Activities Calendar';
include 'includes/header.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google-calendar-service.php';
check_role(IMS_ALL_ROLES);

$googleCalendarConfigured =
    gcal_is_configured($conn);

$googleCalendarConnected =
    gcal_is_connected($conn);

$googleCalendarName =
    gcal_setting(
        $conn,
        'google_calendar_name',
        ims_brand_name() . ' Events'
    );




if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

require_once 'includes/workplan-stats.php';
require_once __DIR__ . '/includes/task-carryover.php';

// Roll pending daily tasks forward once per day (works without cron;
// legacy/cron/carry-over-pending-tasks.php does the same from cron).
task_carryover_maybe_run($conn);
$hasCarryOver = task_carryover_supported($conn);

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

function ac_table_exists(mysqli $conn, string $table): bool
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

function ac_column_exists(mysqli $conn, string $table, string $column): bool
{
    if (!ac_table_exists($conn, $table)) {
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

function ac_bind(mysqli_stmt $stmt, string $types, array $params): void
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

function ac_date(mixed $value, string $fallback = '-'): string
{
    if ($value === null || trim((string)$value) === '') {
        return $fallback;
    }

    $ts = strtotime((string)$value);

    return $ts === false ? $fallback : date('d M Y', $ts);
}

function ac_status_class(string $status): string
{
    return match ($status) {
        'Completed' => 'ac-status-completed',
        'In Progress' => 'ac-status-progress',
        'Delayed' => 'ac-status-delayed',
        'Cancelled' => 'ac-status-cancelled',
        default => 'ac-status-pending',
    };
}

function ac_status_color(string $status): string
{
    return match ($status) {
        'Completed' => '#22c55e',
        'In Progress' => '#3b82f6',
        'Delayed' => '#ef4444',
        'Cancelled' => '#64748b',
        default => '#94a3b8',
    };
}

/*
|--------------------------------------------------------------------------
| REQUIRED WORKPLAN TABLES
|--------------------------------------------------------------------------
*/

foreach ([
    'workplans',
    'workplan_milestones',
    'workplan_deliverables',
] as $table) {
    if (!ac_table_exists($conn, $table)) {
        die('Required workplan table is missing: ' . h($table));
    }
}

$hasPrograms = ac_table_exists($conn, 'programs');
$hasFrequency = ac_column_exists(
    $conn,
    'workplan_deliverables',
    'calendar_frequency'
);

/*
|--------------------------------------------------------------------------
| FILTER OPTIONS
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
    SELECT project_id, project_code, project_name
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
        SELECT id, program_code, program_name
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
| FILTERS
|--------------------------------------------------------------------------
*/

$filterWorkplan = max(0, (int)($_GET['workplan_id'] ?? 0));
$filterEntityType = trim((string)($_GET['entity_type'] ?? ''));
$filterEntity = max(0, (int)($_GET['entity'] ?? 0));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterYear = max(0, (int)($_GET['year'] ?? 0));
$search = trim((string)($_GET['search'] ?? ''));
$filterFrequency = trim((string)($_GET['frequency'] ?? ''));
$filterCarried = !empty($_GET['carried']);

$allowedFrequencies = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Annual'];

if (!in_array($filterFrequency, $allowedFrequencies, true)) {
    $filterFrequency = '';
}

$allowedStatuses = [
    'Pending',
    'In Progress',
    'Completed',
    'Delayed',
    'Cancelled',
];

if (!in_array($filterEntityType, ['Project', 'Program'], true)) {
    $filterEntityType = '';
}

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

/*
|--------------------------------------------------------------------------
| LOAD ACTIVITIES FROM WORKPLAN
|--------------------------------------------------------------------------
*/

$frequencySelect = $hasFrequency
    ? "wd.calendar_frequency"
    : "'Monthly' AS calendar_frequency";

$googleEventSelect =
    ac_column_exists(
        $conn,
        'workplan_deliverables',
        'google_event_id'
    )
        ? "wd.google_event_id"
        : "NULL AS google_event_id";

$googleLinkSelect =
    ac_column_exists(
        $conn,
        'workplan_deliverables',
        'google_html_link'
    )
        ? "wd.google_html_link"
        : "NULL AS google_html_link";

$googleStatusSelect =
    ac_column_exists(
        $conn,
        'workplan_deliverables',
        'google_sync_status'
    )
        ? "wd.google_sync_status"
        : "'Not Synced' AS google_sync_status";

$googleErrorSelect =
    ac_column_exists(
        $conn,
        'workplan_deliverables',
        'google_sync_error'
    )
        ? "wd.google_sync_error"
        : "NULL AS google_sync_error";

$googleLastSyncSelect =
    ac_column_exists(
        $conn,
        'workplan_deliverables',
        'google_last_synced_at'
    )
        ? "wd.google_last_synced_at"
        : "NULL AS google_last_synced_at";


$carrySelect = $hasCarryOver
    ? "wd.carried_over, wd.carried_over_count, wd.original_start_date, wd.original_end_date, wd.last_carried_over_at"
    : "0 AS carried_over, 0 AS carried_over_count, NULL AS original_start_date, NULL AS original_end_date, NULL AS last_carried_over_at";

$sql = "
    SELECT
        wd.id AS activity_id,
        wd.milestone_id,
        wd.title AS activity_name,
        wd.start_date,
        wd.end_date,
        wd.status,
        wd.progress_percentage,
        wd.responsible_person,
        wd.notes,
        {$frequencySelect},
        {$googleEventSelect},
        {$googleLinkSelect},
        {$googleStatusSelect},
        {$googleErrorSelect},
        {$googleLastSyncSelect},
        {$carrySelect},

        wm.workplan_id,
        wm.milestone_name,
        wm.milestone_type,

        w.entity_type,
        w.entity_id,
        w.workplan_year,

        CASE
            WHEN w.entity_type = 'Project' THEN p.project_code
            WHEN w.entity_type = 'Program' THEN pr.program_code
            ELSE NULL
        END AS entity_code,

        CASE
            WHEN w.entity_type = 'Project' THEN p.project_name
            WHEN w.entity_type = 'Program' THEN pr.program_name
            ELSE NULL
        END AS entity_name

    FROM workplan_deliverables wd

    INNER JOIN workplan_milestones wm
        ON wm.id = wd.milestone_id

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

if ($filterStatus !== '') {
    $where[] = 'wd.status = ?';
    $types .= 's';
    $params[] = $filterStatus;
}

if ($filterFrequency !== '' && $hasFrequency) {
    $where[] = 'wd.calendar_frequency = ?';
    $types .= 's';
    $params[] = $filterFrequency;
} elseif ($filterFrequency !== '' && $filterFrequency !== 'Monthly') {
    // Column missing: every task is treated as Monthly.
    $where[] = '1 = 0';
}

if ($filterCarried && $hasCarryOver) {
    $where[] = 'wd.carried_over = 1';
}

if ($filterYear > 0) {
    $where[] = 'w.workplan_year = ?';
    $types .= 'i';
    $params[] = $filterYear;
}

if ($search !== '') {
    $like = '%' . $search . '%';

    $where[] = "(
        wd.title LIKE ?
        OR wd.notes LIKE ?
        OR wd.responsible_person LIKE ?
        OR wm.milestone_name LIKE ?
        OR p.project_name LIKE ?
        OR pr.program_name LIKE ?
    )";

    $types .= 'ssssss';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
    }
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= "
    ORDER BY
        w.workplan_year DESC,
        wd.start_date ASC,
        wd.end_date ASC,
        wd.id DESC
";

$activities = [];

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log(
        'activities-calendar workplan query failed: '
        . $conn->error
    );

    die('Unable to load workplan activities.');
}

ac_bind($stmt, $types, $params);

$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/

$todayTs = strtotime(date('Y-m-d'));

$stats = [
    'total' => count($activities),
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'delayed' => 0,
    'overdue' => 0,
];

foreach ($activities as $activity) {
    $status = (string)($activity['status'] ?? 'Pending');

    if ($status === 'Completed') {
        $stats['completed']++;
    } elseif ($status === 'In Progress') {
        $stats['in_progress']++;
    } elseif ($status === 'Delayed') {
        $stats['delayed']++;
    } elseif ($status === 'Pending') {
        $stats['pending']++;
    }

    $endTs = !empty($activity['end_date'])
        ? strtotime((string)$activity['end_date'])
        : false;

    if (
        $endTs
        &&
        !in_array($status, ['Completed', 'Cancelled'], true)
        &&
        $endTs < $todayTs
    ) {
        $stats['overdue']++;
    }
}

/*
|--------------------------------------------------------------------------
| CALENDAR DATA
|--------------------------------------------------------------------------
*/

$calendarEvents = [];

foreach ($activities as $activity) {
    $start = !empty($activity['start_date'])
        ? date('Y-m-d', strtotime((string)$activity['start_date']))
        : null;

    $end = !empty($activity['end_date'])
        ? date('Y-m-d', strtotime((string)$activity['end_date']))
        : $start;

    if (!$start) {
        continue;
    }

    $calendarEvents[] = [
        'id' => (int)$activity['activity_id'],
        'workplan_id' => (int)$activity['workplan_id'],
        'title' => (string)($activity['activity_name'] ?? 'Activity'),
        'start' => $start,
        'end' => $end ?: $start,
        'status' => (string)($activity['status'] ?? 'Pending'),
        'progress' => (float)($activity['progress_percentage'] ?? 0),
        'frequency' => (string)($activity['calendar_frequency'] ?? 'Monthly'),
        'google_event_id' => (string)($activity['google_event_id'] ?? ''),
        'google_html_link' => (string)($activity['google_html_link'] ?? ''),
        'google_sync_status' => (string)($activity['google_sync_status'] ?? 'Not Synced'),
        'google_sync_error' => (string)($activity['google_sync_error'] ?? ''),
        'google_last_synced_at' => (string)($activity['google_last_synced_at'] ?? ''),
        'carried_over' => !empty($activity['carried_over']),
        'carried_over_count' => (int)($activity['carried_over_count'] ?? 0),
        'original_end' => (string)($activity['original_end_date'] ?? ''),

        'responsible' => (string)($activity['responsible_person'] ?? ''),
        'notes' => (string)($activity['notes'] ?? ''),
        'milestone' => (string)($activity['milestone_name'] ?? ''),
        'entity_type' => (string)($activity['entity_type'] ?? ''),
        'entity_code' => (string)($activity['entity_code'] ?? ''),
        'entity_name' => (string)($activity['entity_name'] ?? ''),
        'year' => (int)($activity['workplan_year'] ?? 0),
        'color' => ac_status_color(
            (string)($activity['status'] ?? 'Pending')
        ),
    ];
}


/*
|--------------------------------------------------------------------------
| ACTIVITY EDITOR DATA
|--------------------------------------------------------------------------
|
| Calendar events require a start date, so $calendarEvents intentionally
| excludes undated activities. The List tab, however, can still display
| those activities. The edit modal therefore needs its own dataset built
| from ALL filtered activities.
|
*/

$activityEditorData = [];

foreach ($activities as $activity) {
    $activityEditorData[] = [
        'id' => (int)($activity['activity_id'] ?? 0),

        'workplan_id' =>
            (int)($activity['workplan_id'] ?? 0),

        'title' =>
            (string)($activity['activity_name'] ?? 'Activity'),

        'start' =>
            !empty($activity['start_date'])
                ? date(
                    'Y-m-d',
                    strtotime(
                        (string)$activity['start_date']
                    )
                )
                : '',

        'end' =>
            !empty($activity['end_date'])
                ? date(
                    'Y-m-d',
                    strtotime(
                        (string)$activity['end_date']
                    )
                )
                : '',

        'status' =>
            (string)($activity['status'] ?? 'Pending'),

        'progress' =>
            (float)($activity['progress_percentage'] ?? 0),

        'frequency' =>
            (string)($activity['calendar_frequency'] ?? 'Monthly'),

        'google_event_id' =>
            (string)($activity['google_event_id'] ?? ''),

        'google_html_link' =>
            (string)($activity['google_html_link'] ?? ''),

        'google_sync_status' =>
            (string)($activity['google_sync_status'] ?? 'Not Synced'),

        'google_sync_error' =>
            (string)($activity['google_sync_error'] ?? ''),

        'google_last_synced_at' =>
            (string)($activity['google_last_synced_at'] ?? ''),

        'carried_over' =>
            !empty($activity['carried_over']),

        'carried_over_count' =>
            (int)($activity['carried_over_count'] ?? 0),

        'original_end' =>
            (string)($activity['original_end_date'] ?? ''),

        'responsible' =>
            (string)($activity['responsible_person'] ?? ''),

        'notes' =>
            (string)($activity['notes'] ?? ''),

        'milestone' =>
            (string)($activity['milestone_name'] ?? ''),

        'entity_type' =>
            (string)($activity['entity_type'] ?? ''),

        'entity_code' =>
            (string)($activity['entity_code'] ?? ''),

        'entity_name' =>
            (string)($activity['entity_name'] ?? ''),

        'year' =>
            (int)($activity['workplan_year'] ?? 0),
    ];
}

$calendarYears = $years;

foreach ($calendarEvents as $event) {
    foreach (['start', 'end'] as $key) {
        if (!empty($event[$key])) {
            $calendarYears[] = (int)substr((string)$event[$key], 0, 4);
        }
    }
}

$calendarYears[] = (int)date('Y');
$calendarYears = array_values(array_unique(array_filter($calendarYears)));
rsort($calendarYears);

$initialCalendarYear = $filterYear > 0
    ? $filterYear
    : (
        $calendarYears[0]
        ?? (int)date('Y')
    );

/*
|--------------------------------------------------------------------------
| LIST TAB PAGINATION
|--------------------------------------------------------------------------
|
| Keep the complete $activities collection for the Calendar and modal.
| Only the List table is sliced for pagination.
|
*/

$listPage = max(
    1,
    (int)(
        $_GET['list_page']
        ?? 1
    )
);

$listPerPage = 15;

$listTotalRecords =
    count($activities);

$listTotalPages =
    max(
        1,
        (int)ceil(
            $listTotalRecords
            /
            $listPerPage
        )
    );

if ($listPage > $listTotalPages) {
    $listPage =
        $listTotalPages;
}

$listOffset =
    (
        $listPage - 1
    )
    *
    $listPerPage;

$listActivities =
    array_slice(
        $activities,
        $listOffset,
        $listPerPage
    );

function ac_list_page_url(
    int $page
): string {
    $query =
        $_GET;

    $query['tab'] =
        'list';

    $query['list_page'] =
        max(
            1,
            $page
        );

    return '?'
        . http_build_query(
            $query
        );
}

?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">

<style>
/* ==========================================================================
   WORKPLAN ACTIVITIES CALENDAR
   ========================================================================== */

.activities-wrap {
    width: 100%;
}

.activities-wrap > .filter-bar {
    margin-bottom: 20px;
}

.ac-tabs {
    display: flex;
    gap: 0;

    margin-bottom: 18px;

    overflow-x: auto;

    border-bottom: 2px solid var(--slate-border);
}

.ac-tab-btn {
    flex-shrink: 0;

    display: inline-flex;
    align-items: center;
    gap: 7px;

    padding: 11px 15px;

    margin-bottom: -2px;

    border: 0;
    border-bottom: 2px solid transparent;

    background: transparent;
    color: var(--ink-300);

    font-size: 12.5px;
    font-weight: 800;

    cursor: pointer;
}

.ac-tab-btn.active {
    border-bottom-color: var(--brand-600);
    color: var(--brand-600);
}

.ac-tab-panel {
    display: none;
}

.ac-tab-panel.active {
    display: block;
}

/* Stats */
.ac-stat-link {
    color: inherit;
    text-decoration: none;
}

.ac-stat-link .stat-card {
    height: 100%;
}

/* Calendar */
.ac-calendar {
    overflow: hidden;

    border: 1px solid var(--slate-border);
    border-radius: 12px;

    background: var(--surface-card);
}

.ac-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    padding: 12px 14px;

    border-bottom: 1px solid var(--slate-border);

    flex-wrap: wrap;
}

.ac-toolbar-left,
.ac-toolbar-right {
    display: flex;
    align-items: center;
    gap: 7px;

    flex-wrap: wrap;
}

.ac-nav-btn,
.ac-view-btn,
.ac-today-btn {
    min-height: 35px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 7px 10px;

    border: 1px solid var(--ink-100);
    border-radius: 8px;

    background: var(--surface-card);
    color: var(--slate-fg);

    font-size: 12px;
    font-weight: 800;

    cursor: pointer;
}

.ac-nav-btn {
    width: 35px;
    padding: 0;
}

.ac-view-btn.active {
    border-color: var(--brand-600);
    background: var(--brand-50);
    color: var(--brand-600);
}

.ac-year-select {
    min-height: 35px;

    padding: 6px 28px 6px 9px;

    border: 1px solid var(--ink-100);
    border-radius: 8px;

    background: var(--surface-card);
    color: var(--ink-900);

    font-size: 12px;
    font-weight: 800;
}

.ac-calendar-title {
    min-width: 170px;

    color: var(--ink-900);

    font-size: 17px;
    font-weight: 800;
}

/* Month */
.ac-calendar-scroll {
    overflow-x: auto;
}

.ac-month-grid {
    min-width: 900px;

    display: grid;
    grid-template-columns: repeat(7, 1fr);
}

.ac-weekday {
    padding: 9px 8px;

    border-right: 1px solid var(--ink-50);
    border-bottom: 1px solid var(--slate-border);

    background: var(--surface-hover);
    color: var(--ink-300);

    font-size: 11px;
    font-weight: 800;

    text-align: center;
    text-transform: uppercase;
}

.ac-day {
    min-height: 128px;

    padding: 7px;

    border-right: 1px solid var(--ink-50);
    border-bottom: 1px solid var(--ink-50);

    background: var(--surface-card);
}

.ac-day.outside {
    background: var(--surface-hover);
}

.ac-day.today {
    background: var(--brand-50);
}

.ac-day-number {
    width: 25px;
    height: 25px;

    display: grid;
    place-items: center;

    margin-left: auto;
    margin-bottom: 5px;

    border-radius: 50%;

    color: var(--slate-fg);

    font-size: 11.5px;
    font-weight: 800;
}

.ac-day.today .ac-day-number {
    background: var(--brand-600);
    color: var(--ink-0);
}

.ac-event {
    width: 100%;

    display: block;

    margin-bottom: 4px;
    padding: 5px 7px;

    overflow: hidden;

    border: 0;
    border-left: 4px solid var(--event-color);
    border-radius: 6px;

    background: var(--surface-hover);
    color: var(--ink-500);

    font-size: 11.5px;
    font-weight: 800;

    text-align: left;
    text-overflow: ellipsis;
    white-space: nowrap;

    cursor: pointer;
}

/* Agenda / quarter / year */
.ac-agenda-day {
    border-bottom: 1px solid var(--slate-border);
}

.ac-agenda-head {
    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 9px 13px;

    background: var(--surface-hover);

    color: var(--ink-500);

    font-size: 12px;
    font-weight: 800;
}

.ac-agenda-items {
    padding: 8px 10px;
}

.ac-agenda-event {
    display: grid;
    grid-template-columns: minmax(0,1fr) auto;

    gap: 10px;

    padding: 10px 12px;
    margin-bottom: 7px;

    border: 1px solid var(--slate-border);
    border-left: 4px solid var(--event-color);
    border-radius: 8px;

    background: var(--surface-card);

    cursor: pointer;
}

.ac-agenda-title {
    color: var(--ink-900);

    font-size: 13px;
    font-weight: 800;
}

.ac-agenda-meta {
    margin-top: 3px;

    color: var(--ink-300);

    font-size: 12px;
}

.ac-period-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));

    gap: 12px;

    padding: 14px;
}

.ac-period-card {
    min-width: 0;

    padding: 12px;

    border: 1px solid var(--slate-border);
    border-radius: 9px;

    background: var(--surface-card);
}

.ac-period-card h4 {
    margin: 0 0 9px;

    color: var(--ink-900);

    font-size: 13px;
}

.ac-period-event {
    margin-bottom: 6px;
    padding: 7px 8px;

    border-left: 4px solid var(--event-color);
    border-radius: 6px;

    background: var(--surface-hover);
    color: var(--ink-500);

    font-size: 11.5px;
    font-weight: 800;

    cursor: pointer;
}

/* Legend */
.ac-legend {
    display: flex;
    align-items: center;
    gap: 13px;

    flex-wrap: wrap;

    padding: 10px 13px;

    border-top: 1px solid var(--slate-border);

    color: var(--slate-fg);

    font-size: 12px;
}

.ac-dot {
    display: inline-block;

    width: 9px;
    height: 9px;

    margin-right: 4px;

    border-radius: 50%;
}

/* List table */
.ac-progress-track {
    height: 7px;

    overflow: hidden;

    border-radius: 999px;

    background: var(--slate-border);
}

.ac-progress-fill {
    display: block;

    height: 100%;

    border-radius: 999px;

    background: linear-gradient(90deg,var(--brand-600),var(--brand-400));
}

.ac-status-pill {
    display: inline-flex;
    align-items: center;

    padding: 4px 8px;

    border-radius: 999px;

    font-size: 11.5px;
    font-weight: 800;
}

.ac-status-completed {
    background: var(--green-bg);
    color: var(--green-fg);
}

.ac-status-progress {
    background: var(--blue-bg);
    color: var(--blue-fg);
}

.ac-status-delayed {
    background: var(--red-bg);
    color: var(--red-fg);
}

.ac-status-cancelled,
.ac-status-pending {
    background: var(--slate-bg);
    color: var(--slate-fg);
}

/* Modal */
.ac-modal {
    display: none;

    position: fixed;
    inset: 0;

    z-index: 99999;

    padding: 20px;

    overflow-y: auto;

    background: rgba(15,23,42,.56);
}

.ac-modal.open {
    display: flex;
    align-items: flex-start;
    justify-content: center;
}

.ac-modal-card {
    width: min(100%,560px);

    margin: 45px auto;

    overflow: hidden;

    border-radius: 14px;

    background: var(--surface-card);

    box-shadow: 0 24px 60px rgba(15,23,42,.24);
}

.ac-modal-head,
.ac-modal-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 10px;

    padding: 14px 17px;
}

.ac-modal-head {
    border-bottom: 1px solid var(--slate-border);
}

.ac-modal-foot {
    justify-content: flex-end;

    border-top: 1px solid var(--slate-border);
}

.ac-modal-body {
    padding: 17px;
}

.ac-modal-close {
    width: 34px;
    height: 34px;

    border: 0;
    border-radius: 8px;

    background: var(--surface-hover);
    color: var(--ink-300);

    font-size: 20px;

    cursor: pointer;
}

@media (max-width: 1100px) {
    .ac-period-grid {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 700px) {
    .ac-period-grid {
        grid-template-columns: 1fr;
    }

    .ac-toolbar-left,
    .ac-toolbar-right {
        width: 100%;
    }

    .ac-toolbar-right {
        overflow-x: auto;
        flex-wrap: nowrap;
    }
}

/* List tab pagination */
.ac-list-pagination {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    flex-wrap:wrap;
    padding:14px 12px;
    border-top:1px solid var(--slate-border);
    background:var(--surface-card);
}

.ac-list-pagination a,
.ac-list-pagination .ac-page-number {
    min-width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0 9px;
    border:1px solid var(--ink-100);
    border-radius:8px;
    background:var(--surface-card);
    color:var(--slate-fg);
    text-decoration:none;
    font-size:12px;
    font-weight:800;
}

.ac-list-pagination a:hover,
.ac-list-pagination .ac-page-number.active {
    border-color:var(--brand-600);
    background:var(--brand-600);
    color:var(--ink-0);
}

.ac-list-pagination .ac-page-summary {
    margin-left:6px;
    color:var(--ink-300);
    font-size:12px;
    font-weight:700;
}

@media (max-width:600px) {
    .ac-list-pagination .ac-page-summary {
        width:100%;
        margin-left:0;
        text-align:center;
    }
}
</style>

<div class="activities-wrap">

    <!-- HERO -->
    <div class="page-hero">

        <div>

            <h1>
                <i class="fas fa-calendar-days" aria-hidden="true"></i>
                Workplan Activities Calendar
            </h1>

            <p>
                View and track activities directly from saved workplans in
                daily, weekly, monthly, quarterly and annual calendar views.
            </p>

        </div>

        <div class="hero-actions">
            <span
                class="badge <?= $googleCalendarConnected ? 'badge-completed' : 'badge-pending' ?>"
                title="Google Calendar connection"
            >
                <i class="fab fa-google"></i>
                <?= $googleCalendarConnected ? 'Google Connected' : 'Google Not Connected' ?>
            </span>

            <?php if (!$googleCalendarConnected && ims_allowed('activities.connect_google', IMS_ADMIN_ROLES, 'See the Connect Google Calendar link')): ?>
                <a
                    href="settings?tab=google"
                    class="btn"
                >
                    <i class="fas fa-link"></i>
                    Connect in Settings
                </a>
            <?php endif; ?>


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

        Activities are now read directly from
        <strong>Workplans &rarr; Milestones &rarr; Activities</strong>.
        Update an activity from this calendar or edit the complete workplan
        from the Workplan page.
    </div>


    <!-- STATS -->
    <div class="milestones-stats">

        <div class="stat-card">
            <div class="stat-icon bg-blue">
                <i class="fas fa-list-check"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Total Activities</div>
                <div class="stat-value"><?= (int)$stats['total'] ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?= (int)$stats['in_progress'] ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= (int)$stats['completed'] ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div
                class="stat-icon"
                style="background:var(--red-bg);color:var(--red-fg);"
            >
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Overdue</div>
                <div
                    class="stat-value"
                    style="color:var(--red-fg);"
                >
                    <?= (int)$stats['overdue'] ?>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div
                class="stat-icon"
                style="background:var(--slate-bg);color:var(--slate-fg);"
            >
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= (int)$stats['pending'] ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple">
                <i class="fas fa-triangle-exclamation"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Delayed</div>
                <div class="stat-value"><?= (int)$stats['delayed'] ?></div>
            </div>
        </div>

    </div>


    <!-- FILTERS -->
    <form
        method="GET"
        action="activities-calendar"
        class="filter-bar filters-bar"
    >

        <div class="form-group search-group filters-grow">
            <label class="form-label">Search</label>

            <div class="search-input-wrap">
                <i class="fas fa-search"></i>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Activity, milestone, responsible person..."
                    value="<?= h($search) ?>"
                >
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Workplan</label>

            <select
                name="workplan_id"
                class="form-control"
            >
                <option value="">All Workplans</option>

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
            <label class="form-label">Year</label>

            <select
                name="year"
                class="form-control"
            >
                <option value="">All Years</option>

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
            <label class="form-label">Entity Type</label>

            <select
                name="entity_type"
                id="entityTypeFilter"
                class="form-control"
                onchange="toggleEntityFilter()"
            >
                <option value="">All Entities</option>

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
            <label class="form-label">Project</label>

            <select
                name="<?= $filterEntityType === 'Program'
                    ? 'project_entity_unused'
                    : 'entity' ?>"
                class="form-control"
            >
                <option value="">All Projects</option>

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
                <label class="form-label">Program</label>

                <select
                    name="<?= $filterEntityType === 'Program'
                        ? 'entity'
                        : 'program_entity_unused' ?>"
                    class="form-control"
                >
                    <option value="">All Programs</option>

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
            <label class="form-label">Status</label>

            <select
                name="status"
                class="form-control"
            >
                <option value="">All Statuses</option>

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
            <label class="form-label">Frequency</label>

            <select
                name="frequency"
                class="form-control"
            >
                <option value="">All Periods</option>

                <?php foreach ($allowedFrequencies as $frequencyOption): ?>
                    <option
                        value="<?= h($frequencyOption) ?>"
                        <?= $filterFrequency === $frequencyOption
                            ? 'selected'
                            : '' ?>
                    >
                        <?= h($frequencyOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($hasCarryOver): ?>
            <div class="form-group filters-check">
                <label class="form-label">
                    <input type="checkbox" name="carried" value="1" <?= $filterCarried ? 'checked' : '' ?>>
                    Carried over only
                </label>
            </div>
        <?php endif; ?>

        <div class="filter-actions">
            <button
                type="submit"
                class="btn btn-primary"
            >
                <i class="fas fa-filter"></i>
                Filter
            </button>

            <a
                href="activities-calendar"
                class="btn btn-gray"
            >
                <i class="fas fa-times"></i>
                Clear
            </a>
        </div>

    </form>


    <!-- TABS -->
    <div class="ac-tabs">

        <button
            type="button"
            class="ac-tab-btn <?= (($_GET['tab'] ?? 'calendar') === 'calendar') ? 'active' : '' ?>"
            data-ac-tab="calendar"
        >
            <i class="fas fa-calendar-days"></i>
            Calendar
        </button>

        <button
            type="button"
            class="ac-tab-btn <?= (($_GET['tab'] ?? '') === 'list') ? 'active' : '' ?>"
            data-ac-tab="list"
        >
            <i class="fas fa-list"></i>
            List
            <span class="badge badge-available">
                <?= count($activities) ?>
            </span>
        </button>

    </div>


    <!-- CALENDAR TAB -->
    <section
        class="ac-tab-panel <?= (($_GET['tab'] ?? 'calendar') === 'calendar') ? 'active' : '' ?>"
        id="ac-tab-calendar"
    >

        <div class="panel">

            <div class="panel-head">
                <h3>
                    <i
                        class="fas fa-calendar-days"
                        style="color:var(--brand-600);margin-right:8px;"
                    ></i>
                    Activities Calendar
                </h3>
            </div>

            <div class="ac-calendar">

                <div class="ac-toolbar">

                    <div class="ac-toolbar-left">

                        <button
                            type="button"
                            class="ac-today-btn"
                            id="acTodayBtn"
                        >
                            Today
                        </button>

                        <button
                            type="button"
                            class="ac-nav-btn"
                            id="acPrevBtn"
                            aria-label="Previous period"
                        >
                            <i class="fas fa-chevron-left"></i>
                        </button>

                        <button
                            type="button"
                            class="ac-nav-btn"
                            id="acNextBtn"
                            aria-label="Next period"
                        >
                            <i class="fas fa-chevron-right"></i>
                        </button>

                        <select
                            id="acCalendarYear"
                            class="ac-year-select"
                            aria-label="Select calendar year"
                        >
                            <?php foreach ($calendarYears as $calendarYear): ?>
                                <option
                                    value="<?= (int)$calendarYear ?>"
                                    <?= (int)$calendarYear === $initialCalendarYear
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= (int)$calendarYear ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div
                            class="ac-calendar-title"
                            id="acCalendarTitle"
                        ></div>

                    </div>

                    <div class="ac-toolbar-right">

                        <button
                            type="button"
                            class="ac-view-btn"
                            data-view="day"
                        >
                            Daily
                        </button>

                        <button
                            type="button"
                            class="ac-view-btn"
                            data-view="week"
                        >
                            Weekly
                        </button>

                        <button
                            type="button"
                            class="ac-view-btn active"
                            data-view="month"
                        >
                            Monthly
                        </button>

                        <button
                            type="button"
                            class="ac-view-btn"
                            data-view="quarter"
                        >
                            Quarterly
                        </button>

                        <button
                            type="button"
                            class="ac-view-btn"
                            data-view="year"
                        >
                            Annual
                        </button>

                    </div>

                </div>

                <div class="ac-calendar-scroll">
                    <div id="acCalendarBody"></div>
                </div>

                <div class="ac-legend">
                    <span>
                        <span class="ac-dot" style="background:#94a3b8;"></span>
                        Pending
                    </span>

                    <span>
                        <span class="ac-dot" style="background:#3b82f6;"></span>
                        In Progress
                    </span>

                    <span>
                        <span class="ac-dot" style="background:#22c55e;"></span>
                        Completed
                    </span>

                    <span>
                        <span class="ac-dot" style="background:#ef4444;"></span>
                        Delayed
                    </span>

                    <span>
                        <span class="ac-dot" style="background:var(--ink-300);"></span>
                        Cancelled
                    </span>
                </div>

            </div>

        </div>

    </section>


    <!-- LIST TAB -->
    <section
        class="ac-tab-panel <?= (($_GET['tab'] ?? '') === 'list') ? 'active' : '' ?>"
        id="ac-tab-list"
    >

        <div class="panel">

            <div class="panel-head">

                <h3>
                    <i
                        class="fas fa-list-check"
                        style="color:var(--brand-600);margin-right:8px;"
                    ></i>
                    Workplan Activities
                </h3>

                <span class="badge badge-available">
                    <?= number_format($listTotalRecords) ?> records
                </span>

            </div>

            <div class="table-wrap">

                <table class="table">

                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Milestone</th>
                            <th>Workplan</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Responsible</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (!$listActivities): ?>

                            <tr>
                                <td
                                    colspan="8"
                                    style="text-align:center;padding:48px 16px;"
                                >
                                    No workplan activities found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($listActivities as $activity): ?>

                                <?php
                                $status = (string)(
                                    $activity['status']
                                    ?? 'Pending'
                                );

                                $progress = max(
                                    0,
                                    min(
                                        100,
                                        (float)(
                                            $activity[
                                                'progress_percentage'
                                            ]
                                            ?? 0
                                        )
                                    )
                                );

                                $entityLabel = trim(
                                    (string)(
                                        $activity['entity_code']
                                        ?? ''
                                    )
                                    . ' - '
                                    . (string)(
                                        $activity['entity_name']
                                        ?? ''
                                    ),
                                    ' -'
                                );
                                $isCarriedOver = !empty($activity['carried_over']);
                                ?>

                                <tr class="<?= $isCarriedOver ? 'task-carried-over' : '' ?>">

                                    <td style="max-width:260px;">

                                        <div class="milestone-name">
                                            <?= h(
                                                $activity[
                                                    'activity_name'
                                                ]
                                                ?? ''
                                            ) ?>
                                            <?php if ($isCarriedOver): ?>
                                                <span class="badge-carried-over" title="Rolled over because it was not completed on its due date">
                                                    Carried over<?= (int)($activity['carried_over_count'] ?? 0) > 1 ? ' x' . (int)$activity['carried_over_count'] : '' ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($isCarriedOver && !empty($activity['original_end_date'])): ?>
                                            <div class="task-origin-note">
                                                Carried over from <?= h(ac_date($activity['original_end_date'])) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (
                                            !empty(
                                                $activity['notes']
                                            )
                                        ): ?>
                                            <div class="milestone-desc">
                                                <?= h(
                                                    mb_strlen(
                                                        (string)$activity[
                                                            'notes'
                                                        ]
                                                    ) > 70
                                                        ? mb_substr(
                                                            (string)$activity[
                                                                'notes'
                                                            ],
                                                            0,
                                                            70
                                                        ) . '...'
                                                        : $activity['notes']
                                                ) ?>
                                            </div>
                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= h(
                                            $activity[
                                                'milestone_name'
                                            ]
                                            ?? '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= h(
                                                $entityLabel
                                                !== ''
                                                    ? $entityLabel
                                                    : (
                                                        $activity[
                                                            'entity_type'
                                                        ]
                                                        . ' #'
                                                        . $activity[
                                                            'entity_id'
                                                        ]
                                                    )
                                            ) ?>
                                        </strong>

                                        <div
                                            style="
                                                margin-top:3px;
                                                color:var(--ink-300);
                                                font-size:12px;
                                            "
                                        >
                                            <?= (int)$activity[
                                                'workplan_year'
                                            ] ?>
                                            .
                                            <?= h(
                                                $activity[
                                                    'calendar_frequency'
                                                ]
                                                ?? 'Monthly'
                                            ) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= h(
                                            ac_date(
                                                $activity[
                                                    'start_date'
                                                ]
                                                ?? null
                                            )
                                        ) ?>
                                        <br>
                                        <small>to</small>
                                        <br>
                                        <?= h(
                                            ac_date(
                                                $activity[
                                                    'end_date'
                                                ]
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <span
                                            class="
                                                ac-status-pill
                                                <?= h(
                                                    ac_status_class(
                                                        $status
                                                    )
                                                ) ?>
                                            "
                                        >
                                            <?= h($status) ?>
                                        </span>
                                        <?php if ($isCarriedOver): ?>
                                            <span class="status-pill status-carried-over">Carried over</span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="min-width:120px;">

                                        <div
                                            style="
                                                margin-bottom:4px;
                                                font-size:12px;
                                                font-weight:800;
                                                color:var(--slate-fg);
                                            "
                                        >
                                            <?= number_format(
                                                $progress,
                                                0
                                            ) ?>%
                                        </div>

                                        <div class="ac-progress-track">
                                            <span
                                                class="ac-progress-fill"
                                                style="
                                                    width:
                                                    <?= $progress ?>%;
                                                "
                                            ></span>
                                        </div>

                                    </td>

                                    <td>
                                        <?= h(
                                            $activity[
                                                'responsible_person'
                                            ]
                                            ?? '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <div class="actions">

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-soft"
                                                onclick='acOpenActivity(
                                                    <?= json_encode(
                                                        (int)$activity[
                                                            "activity_id"
                                                        ]
                                                    ) ?>
                                                )'
                                                title="Update activity"
                                            >
                                                <i class="fas fa-pen"></i>
                                            </button>

                                            <?php if ($googleCalendarConnected): ?>
                                                <a
                                                    href="includes/google-calendar-sync.php?type=activity&id=<?= (int)$activity['activity_id'] ?>&return=<?= rawurlencode('../activities-calendar') ?>"
                                                    class="btn btn-sm btn-soft"
                                                    title="Sync activity to Google Calendar"
                                                >
                                                    <i class="fab fa-google"></i>
                                                </a>
                                            <?php endif; ?>

                                            <a
                                                href="workplan?workplan_id=<?= (int)$activity['workplan_id'] ?>#wp-builder"
                                                class="btn btn-sm btn-secondary"
                                                title="Open workplan"
                                            >
                                                <i class="fas fa-route"></i>
                                            </a>

                                        </div>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($listTotalPages > 1): ?>
                <div class="ac-list-pagination">

                    <?php if ($listPage > 1): ?>
                        <a
                            href="<?= h(ac_list_page_url(1)) ?>"
                            title="First page"
                        >
                            <i class="fas fa-angles-left"></i>
                        </a>

                        <a
                            href="<?= h(
                                ac_list_page_url(
                                    $listPage - 1
                                )
                            ) ?>"
                            title="Previous page"
                        >
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php
                    $startPage =
                        max(
                            1,
                            $listPage - 2
                        );

                    $endPage =
                        min(
                            $listTotalPages,
                            $listPage + 2
                        );
                    ?>

                    <?php for (
                        $p = $startPage;
                        $p <= $endPage;
                        $p++
                    ): ?>
                        <?php if ($p === $listPage): ?>
                            <span
                                class="ac-page-number active"
                            >
                                <?= $p ?>
                            </span>
                        <?php else: ?>
                            <a
                                href="<?= h(
                                    ac_list_page_url(
                                        $p
                                    )
                                ) ?>"
                            >
                                <?= $p ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($listPage < $listTotalPages): ?>
                        <a
                            href="<?= h(
                                ac_list_page_url(
                                    $listPage + 1
                                )
                            ) ?>"
                            title="Next page"
                        >
                            <i class="fas fa-angle-right"></i>
                        </a>

                        <a
                            href="<?= h(
                                ac_list_page_url(
                                    $listTotalPages
                                )
                            ) ?>"
                            title="Last page"
                        >
                            <i class="fas fa-angles-right"></i>
                        </a>
                    <?php endif; ?>

                    <span class="ac-page-summary">
                        Page <?= $listPage ?>
                        of <?= $listTotalPages ?>
                        . <?= number_format($listTotalRecords) ?>
                        record(s)
                    </span>

                </div>
            <?php endif; ?>

        </div>

    </section>

</div>


<!-- STATUS MODAL -->
<div
    class="ac-modal"
    id="acStatusModal"
>

    <div class="ac-modal-card">

        <div class="ac-modal-head">

            <h3>
                <i
                    class="fas fa-calendar-check"
                    style="color:var(--brand-600);margin-right:7px;"
                ></i>
                Update Activity
            </h3>

            <button
                type="button"
                class="ac-modal-close"
                id="acModalClose"
            >
                &times;
            </button>

        </div>

        <form id="acStatusForm">

            <input
                type="hidden"
                id="acRecordId"
                name="record_id"
            >

            <input
                type="hidden"
                name="kind"
                value="activity"
            >

            <input
                type="hidden"
                id="acWorkplanId"
                name="workplan_id"
            >

            <div class="ac-modal-body">

                <div
                    style="
                        margin-bottom:14px;
                        padding:11px 12px;
                        border-left:4px solid var(--brand-600);
                        border-radius:var(--radius-sm);
                        background:var(--surface-hover);
                    "
                >
                    <strong id="acModalTitle"></strong>

                    <div
                        id="acModalMeta"
                        style="
                            margin-top:4px;
                            color:var(--ink-300);
                            font-size:12px;
                        "
                    ></div>
                </div>

                <div
                    id="acGoogleStatusBox"
                    style="
                        display:none;
                        margin-bottom:14px;
                        padding:10px 12px;
                        border-left:4px solid var(--blue-fg);
                        border-radius:8px;
                        background:var(--surface-hover);
                        color:var(--slate-fg);
                        font-size:11px;
                    "
                >
                    <i class="fab fa-google"></i>
                    <span id="acGoogleStatusText">Not Synced</span>

                    <a
                        id="acGoogleOpenLink"
                        href="#"
                        target="_blank"
                        rel="noopener"
                        style="display:none;margin-left:8px;"
                    >
                        Open in Google Calendar
                    </a>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>

                    <select
                        id="acStatus"
                        name="status"
                        class="form-control"
                        required
                    >
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?= h($status) ?>">
                                <?= h($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Progress (%)</label>

                    <input
                        type="number"
                        id="acProgress"
                        name="progress_percentage"
                        class="form-control"
                        min="0"
                        max="100"
                        step="1"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label">Calendar Frequency</label>

                    <select
                        id="acFrequency"
                        name="calendar_frequency"
                        class="form-control"
                    >
                        <option value="Daily">Daily</option>
                        <option value="Weekly">Weekly</option>
                        <option value="Monthly">Monthly</option>
                        <option value="Quarterly">Quarterly</option>
                        <option value="Annual">Annual</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Comment / Note</label>

                    <textarea
                        id="acComment"
                        name="comment"
                        class="form-control"
                        rows="3"
                    ></textarea>
                </div>

            </div>

            <div class="ac-modal-foot">

                <button
                    type="button"
                    class="btn btn-gray"
                    id="acModalCancel"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                    id="acSaveBtn"
                >
                    <i class="fas fa-save"></i>
                    Update Activity
                </button>

            </div>

        </form>

    </div>

</div>


<script>
(function () {

    'use strict';

    const activities =
        <?= json_encode(
            $calendarEvents,
            JSON_UNESCAPED_UNICODE
            |
            JSON_UNESCAPED_SLASHES
            |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>;

    /*
     * Complete editor dataset.
     * Unlike `activities`, this includes activities without dates.
     */
    const activityEditorData =
        <?= json_encode(
            $activityEditorData,
            JSON_UNESCAPED_UNICODE
            |
            JSON_UNESCAPED_SLASHES
            |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>;

    let calendarView = 'month';

    const yearSelect =
        document.getElementById(
            'acCalendarYear'
        );

    let anchorDate =
        new Date(
            Number(
                yearSelect?.value
                ||
                <?= $initialCalendarYear ?>
            ),
            0,
            1
        );

    const body =
        document.getElementById(
            'acCalendarBody'
        );

    const title =
        document.getElementById(
            'acCalendarTitle'
        );


    function esc(value) {
        return String(value ?? '')
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }


    function toDate(value) {
        if (!value) return null;

        const p = String(value)
            .split('-')
            .map(Number);

        if (p.length !== 3) return null;

        return new Date(
            p[0],
            p[1] - 1,
            p[2]
        );
    }


    function ymd(date) {
        return date.getFullYear()
            + '-'
            + String(date.getMonth()+1).padStart(2,'0')
            + '-'
            + String(date.getDate()).padStart(2,'0');
    }


    function formatDate(date) {
        return new Intl.DateTimeFormat(
            'en-GB',
            {
                day:'2-digit',
                month:'short',
                year:'numeric'
            }
        ).format(date);
    }


    function occursOn(event,date) {
        const start = toDate(event.start);
        const end = toDate(event.end || event.start);

        if (!start || !end) return false;

        const day = new Date(
            date.getFullYear(),
            date.getMonth(),
            date.getDate()
        );

        return day >= start && day <= end;
    }


    function eventsForYear(year) {
        const start = new Date(year,0,1);
        const end = new Date(year,11,31,23,59,59);

        return activities.filter(function(event) {
            const s = toDate(event.start);
            const e = toDate(event.end || event.start);

            return s && e && s <= end && e >= start;
        });
    }


    function eventButton(event) {
        const carried = !!event.carried_over;
        const carriedTitle = carried && event.original_end
            ? ' (carried over from ' + event.original_end + ')'
            : '';

        return `
            <button
                type="button"
                class="ac-event${carried ? ' task-carried-over' : ''}"
                style="--event-color:${esc(event.color)};"
                data-activity-id="${event.id}"
                title="${esc(event.title + carriedTitle)}"
            >
                ${esc(event.title)}${carried ? ' <span class="badge-carried-over">Carried</span>' : ''}
            </button>
        `;
    }


    function bindEvents() {
        document
            .querySelectorAll(
                '[data-activity-id]'
            )
            .forEach(function(el) {
                el.addEventListener(
                    'click',
                    function() {
                        acOpenActivity(
                            Number(
                                el.dataset.activityId
                            )
                        );
                    }
                );
            });
    }


    function renderMonth() {
        const year = anchorDate.getFullYear();
        const month = anchorDate.getMonth();

        title.textContent =
            new Intl.DateTimeFormat(
                'en-GB',
                {
                    month:'long',
                    year:'numeric'
                }
            ).format(
                new Date(year,month,1)
            );

        const first = new Date(year,month,1);
        const start = new Date(first);

        const shift =
            (start.getDay()+6)%7;

        start.setDate(
            start.getDate()-shift
        );

        const weekdays = [
            'Mon','Tue','Wed',
            'Thu','Fri','Sat','Sun'
        ];

        let html =
            '<div class="ac-month-grid">';

        weekdays.forEach(function(day) {
            html +=
                '<div class="ac-weekday">'
                + day
                + '</div>';
        });

        const today = ymd(new Date());

        for (let i=0;i<42;i++) {
            const date = new Date(start);
            date.setDate(start.getDate()+i);

            const dayEvents =
                activities.filter(
                    function(event) {
                        return occursOn(
                            event,
                            date
                        );
                    }
                );

            const outside =
                date.getMonth() !== month;

            const isToday =
                ymd(date) === today;

            html += `
                <div
                    class="
                        ac-day
                        ${outside ? 'outside' : ''}
                        ${isToday ? 'today' : ''}
                    "
                >
                    <div class="ac-day-number">
                        ${date.getDate()}
                    </div>
            `;

            dayEvents
                .slice(0,4)
                .forEach(function(event) {
                    html +=
                        eventButton(event);
                });

            if (dayEvents.length > 4) {
                html += `
                    <button
                        type="button"
                        class="ac-view-btn"
                        style="
                            min-height:auto;
                            padding:2px 4px;
                            border:0;
                        "
                        data-more-date="${ymd(date)}"
                    >
                        +${dayEvents.length-4} more
                    </button>
                `;
            }

            html += '</div>';
        }

        html += '</div>';

        body.innerHTML = html;

        bindEvents();

        document
            .querySelectorAll(
                '[data-more-date]'
            )
            .forEach(function(button) {
                button.addEventListener(
                    'click',
                    function() {
                        anchorDate =
                            toDate(
                                button.dataset.moreDate
                            ) || anchorDate;

                        calendarView = 'day';

                        syncViewButtons();
                        renderCalendar();
                    }
                );
            });
    }


    function renderAgenda(days) {
        const start = new Date(anchorDate);

        if (days === 7) {
            const shift =
                (start.getDay()+6)%7;

            start.setDate(
                start.getDate()-shift
            );
        }

        const end = new Date(start);

        end.setDate(
            start.getDate()+days-1
        );

        title.textContent =
            days === 1
                ? formatDate(start)
                : formatDate(start)
                    + ' - '
                    + formatDate(end);

        let html = '';

        for (let i=0;i<days;i++) {
            const date = new Date(start);
            date.setDate(start.getDate()+i);

            const dayEvents =
                activities.filter(
                    function(event) {
                        return occursOn(
                            event,
                            date
                        );
                    }
                );

            html += `
                <section class="ac-agenda-day">

                    <div class="ac-agenda-head">
                        <span>
                            ${new Intl.DateTimeFormat(
                                'en-GB',
                                {
                                    weekday:'long',
                                    day:'2-digit',
                                    month:'short'
                                }
                            ).format(date)}
                        </span>

                        <span>
                            ${dayEvents.length}
                            item${dayEvents.length===1?'':'s'}
                        </span>
                    </div>

                    <div class="ac-agenda-items">
            `;

            if (!dayEvents.length) {
                html += `
                    <div
                        style="
                            padding:8px 4px;
                            color:var(--ink-200);
                            font-size:10.5px;
                        "
                    >
                        No activities.
                    </div>
                `;
            } else {
                dayEvents.forEach(function(event) {
                    html += `
                        <div
                            class="ac-agenda-event"
                            style="--event-color:${esc(event.color)};"
                            data-activity-id="${event.id}"
                        >
                            <div>
                                <div class="ac-agenda-title">
                                    ${esc(event.title)}
                                </div>

                                <div class="ac-agenda-meta">
                                    ${esc(event.milestone)}
                                    ${event.responsible
                                        ? ' . ' + esc(event.responsible)
                                        : ''}
                                </div>
                            </div>

                            <span
                                class="
                                    ac-status-pill
                                    ${statusClass(event.status)}
                                "
                            >
                                ${esc(event.status)}
                            </span>
                        </div>
                    `;
                });
            }

            html += `
                    </div>
                </section>
            `;
        }

        body.innerHTML = html;
        bindEvents();
    }


    function statusClass(status) {
        if (status === 'Completed') {
            return 'ac-status-completed';
        }

        if (status === 'In Progress') {
            return 'ac-status-progress';
        }

        if (status === 'Delayed') {
            return 'ac-status-delayed';
        }

        if (status === 'Cancelled') {
            return 'ac-status-cancelled';
        }

        return 'ac-status-pending';
    }


    function renderQuarter() {
        const year = anchorDate.getFullYear();

        const quarter =
            Math.floor(
                anchorDate.getMonth()/3
            );

        const startMonth =
            quarter*3;

        title.textContent =
            'Q'
            + (quarter+1)
            + ' '
            + year;

        let html =
            '<div class="ac-period-grid">';

        for (let offset=0;offset<3;offset++) {
            const month = startMonth+offset;

            const monthStart =
                new Date(year,month,1);

            const monthEnd =
                new Date(year,month+1,0);

            const monthEvents =
                activities.filter(
                    function(event) {
                        const s = toDate(event.start);
                        const e = toDate(event.end || event.start);

                        return (
                            s
                            &&
                            e
                            &&
                            s <= monthEnd
                            &&
                            e >= monthStart
                        );
                    }
                );

            html += `
                <section class="ac-period-card">

                    <h4>
                        ${new Intl.DateTimeFormat(
                            'en-GB',
                            {
                                month:'long',
                                year:'numeric'
                            }
                        ).format(monthStart)}
                    </h4>
            `;

            if (!monthEvents.length) {
                html += `
                    <div
                        style="
                            color:var(--ink-200);
                            font-size:10px;
                        "
                    >
                        No activities.
                    </div>
                `;
            } else {
                monthEvents.forEach(function(event) {
                    html += `
                        <div
                            class="ac-period-event"
                            style="--event-color:${esc(event.color)};"
                            data-activity-id="${event.id}"
                        >
                            ${esc(event.title)}
                        </div>
                    `;
                });
            }

            html += '</section>';
        }

        html += '</div>';

        body.innerHTML = html;
        bindEvents();
    }


    function renderYear() {
        const year = anchorDate.getFullYear();

        title.textContent =
            String(year);

        let html =
            '<div class="ac-period-grid">';

        for (let month=0;month<12;month++) {
            const monthStart =
                new Date(year,month,1);

            const monthEnd =
                new Date(year,month+1,0);

            const monthEvents =
                activities.filter(
                    function(event) {
                        const s = toDate(event.start);
                        const e = toDate(event.end || event.start);

                        return (
                            s
                            &&
                            e
                            &&
                            s <= monthEnd
                            &&
                            e >= monthStart
                        );
                    }
                );

            html += `
                <section class="ac-period-card">

                    <h4>
                        ${new Intl.DateTimeFormat(
                            'en-GB',
                            {
                                month:'long'
                            }
                        ).format(monthStart)}

                        <span
                            style="
                                color:var(--ink-200);
                                font-size:9px;
                                margin-left:4px;
                            "
                        >
                            ${monthEvents.length}
                        </span>
                    </h4>
            `;

            monthEvents
                .slice(0,8)
                .forEach(function(event) {
                    html += `
                        <div
                            class="ac-period-event"
                            style="--event-color:${esc(event.color)};"
                            data-activity-id="${event.id}"
                        >
                            ${esc(event.title)}
                        </div>
                    `;
                });

            if (monthEvents.length > 8) {
                html += `
                    <div
                        style="
                            color:var(--brand-600);
                            font-size:9px;
                            font-weight:800;
                        "
                    >
                        +${monthEvents.length-8} more
                    </div>
                `;
            }

            html += '</section>';
        }

        html += '</div>';

        body.innerHTML = html;
        bindEvents();
    }


    function syncYearSelect() {
        if (!yearSelect) return;

        const year =
            anchorDate.getFullYear();

        const exists =
            Array.from(
                yearSelect.options
            )
            .some(
                function(option) {
                    return Number(option.value) === year;
                }
            );

        if (!exists) {
            const option =
                document.createElement('option');

            option.value = String(year);
            option.textContent = String(year);

            yearSelect.appendChild(option);
        }

        yearSelect.value = String(year);
    }


    function renderCalendar() {
        syncYearSelect();

        switch (calendarView) {
            case 'day':
                renderAgenda(1);
                break;

            case 'week':
                renderAgenda(7);
                break;

            case 'quarter':
                renderQuarter();
                break;

            case 'year':
                renderYear();
                break;

            default:
                renderMonth();
        }
    }


    function syncViewButtons() {
        document
            .querySelectorAll(
                '.ac-view-btn[data-view]'
            )
            .forEach(function(button) {
                button.classList.toggle(
                    'active',
                    button.dataset.view === calendarView
                );
            });
    }


    function movePeriod(direction) {
        if (calendarView === 'day') {
            anchorDate.setDate(
                anchorDate.getDate()+direction
            );
        } else if (calendarView === 'week') {
            anchorDate.setDate(
                anchorDate.getDate()+(direction*7)
            );
        } else if (calendarView === 'quarter') {
            anchorDate.setMonth(
                anchorDate.getMonth()+(direction*3)
            );
        } else if (calendarView === 'year') {
            anchorDate.setFullYear(
                anchorDate.getFullYear()+direction
            );
        } else {
            anchorDate.setMonth(
                anchorDate.getMonth()+direction
            );
        }

        renderCalendar();
    }


    document
        .querySelectorAll(
            '.ac-view-btn[data-view]'
        )
        .forEach(function(button) {
            button.addEventListener(
                'click',
                function() {
                    calendarView =
                        button.dataset.view;

                    syncViewButtons();
                    renderCalendar();
                }
            );
        });


    document
        .getElementById(
            'acPrevBtn'
        )
        ?.addEventListener(
            'click',
            function() {
                movePeriod(-1);
            }
        );


    document
        .getElementById(
            'acNextBtn'
        )
        ?.addEventListener(
            'click',
            function() {
                movePeriod(1);
            }
        );


    document
        .getElementById(
            'acTodayBtn'
        )
        ?.addEventListener(
            'click',
            function() {
                anchorDate = new Date();
                renderCalendar();
            }
        );


    yearSelect
        ?.addEventListener(
            'change',
            function() {
                const year =
                    Number(
                        yearSelect.value
                    );

                if (!year) return;

                anchorDate.setFullYear(year);

                if (calendarView === 'year') {
                    anchorDate.setMonth(0,1);
                }

                renderCalendar();
            }
        );


    /* Tabs */
    document
        .querySelectorAll(
            '.ac-tab-btn'
        )
        .forEach(function(button) {
            button.addEventListener(
                'click',
                function() {
                    const tab =
                        button.dataset.acTab;

                    document
                        .querySelectorAll(
                            '.ac-tab-btn'
                        )
                        .forEach(
                            function(other) {
                                other.classList.toggle(
                                    'active',
                                    other === button
                                );
                            }
                        );

                    document
                        .querySelectorAll(
                            '.ac-tab-panel'
                        )
                        .forEach(
                            function(panel) {
                                panel.classList.toggle(
                                    'active',
                                    panel.id === 'ac-tab-' + tab
                                );
                            }
                        );

                    if (tab === 'calendar') {
                        renderCalendar();
                    }

                    const tabUrl =
                        new URL(
                            window.location.href
                        );

                    if (tab === 'list') {
                        tabUrl.searchParams.set(
                            'tab',
                            'list'
                        );
                    } else {
                        tabUrl.searchParams.delete(
                            'tab'
                        );

                        tabUrl.searchParams.delete(
                            'list_page'
                        );
                    }

                    window.history.replaceState(
                        {},
                        '',
                        tabUrl.toString()
                    );
                }
            );
        });


    /* Entity filters */
    window.toggleEntityFilter =
        function() {
            const type =
                document
                    .getElementById(
                        'entityTypeFilter'
                    )
                    ?.value
                || '';

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

            if (type === 'Program') {
                if (project) {
                    project.style.display = 'none';
                }

                if (program) {
                    program.style.display = 'flex';
                }
            } else {
                if (project) {
                    project.style.display = 'flex';
                }

                if (program) {
                    program.style.display = 'none';
                }
            }
        };


    /* Modal */
    const modal =
        document.getElementById(
            'acStatusModal'
        );

    const statusForm =
        document.getElementById(
            'acStatusForm'
        );


    const activityIndex =
        new Map(
            activityEditorData.map(
                function(item) {
                    return [
                        String(item.id),
                        item
                    ];
                }
            )
        );

    window.acOpenActivity =
        function(id) {
            const activity =
                activityIndex.get(
                    String(id)
                )
                ||
                activityEditorData.find(
                    function(item) {
                        return String(item.id) === String(id);
                    }
                );

            if (!modal) {
                console.error(
                    'Activity modal element was not found.'
                );

                alert(
                    'The activity editor could not be opened. Please reload the page.'
                );

                return;
            }

            if (!activity) {
                console.error(
                    'Activity ID not found in calendar data.',
                    {
                        requestedId: id,
                        availableIds:
                            activityEditorData.map(
                                function(item) {
                                    return item.id;
                                }
                            )
                    }
                );

                alert(
                    'This activity could not be loaded. Please reload the page.'
                );

                return;
            }

            document.getElementById(
                'acRecordId'
            ).value = activity.id;

            document.getElementById(
                'acWorkplanId'
            ).value = activity.workplan_id;

            document.getElementById(
                'acStatus'
            ).value =
                activity.status || 'Pending';

            document.getElementById(
                'acProgress'
            ).value =
                Number(
                    activity.progress || 0
                );

            document.getElementById(
                'acFrequency'
            ).value =
                activity.frequency || 'Monthly';

            document.getElementById(
                'acComment'
            ).value = '';

            document.getElementById(
                'acModalTitle'
            ).textContent =
                activity.title;

            document.getElementById(
                'acModalMeta'
            ).textContent =
                activity.milestone
                + ' . '
                + activity.start
                + (
                    activity.end
                    &&
                    activity.end !== activity.start
                        ? ' to ' + activity.end
                        : ''
                );

            modal.classList.add('open');

            document.body.style.overflow =
                'hidden';
        };


    function closeModal() {
        modal?.classList.remove('open');

        document.body.style.overflow = '';
    }


    document.getElementById(
        'acModalClose'
    )?.addEventListener(
        'click',
        closeModal
    );


    document.getElementById(
        'acModalCancel'
    )?.addEventListener(
        'click',
        closeModal
    );


    modal?.addEventListener(
        'click',
        function(event) {
            if (event.target === modal) {
                closeModal();
            }
        }
    );


    statusForm
        ?.addEventListener(
            'submit',
            function(event) {
                event.preventDefault();

                const saveBtn =
                    document.getElementById(
                        'acSaveBtn'
                    );

                saveBtn.disabled = true;
                saveBtn.innerHTML =
                    '<i class="fas fa-spinner fa-spin"></i> Updating...';

                fetch(
                    'actions/workplan-calendar-status.php',
                    {
                        method:'POST',
                        credentials:'same-origin',
                        body:new FormData(statusForm)
                    }
                )
                    .then(
                        function(response) {
                            return response.json();
                        }
                    )
                    .then(
                        function(data) {
                            saveBtn.disabled = false;
                            saveBtn.innerHTML =
                                '<i class="fas fa-save"></i> Update Activity';

                            if (!data.success) {
                                alert(
                                    data.message
                                    || 'Could not update activity.'
                                );
                                return;
                            }

                            const id =
                                Number(
                                    document.getElementById(
                                        'acRecordId'
                                    ).value
                                );

                            const activity =
                                activities.find(
                                    function(item) {
                                        return Number(item.id) === id;
                                    }
                                );

                            if (activity) {
                                activity.status =
                                    document.getElementById(
                                        'acStatus'
                                    ).value;

                                activity.progress =
                                    Number(
                                        document.getElementById(
                                            'acProgress'
                                        ).value || 0
                                    );

                                activity.frequency =
                                    document.getElementById(
                                        'acFrequency'
                                    ).value;

                                const colourMap = {
                                    'Pending':'#94a3b8',
                                    'In Progress':'#3b82f6',
                                    'Completed':'#22c55e',
                                    'Delayed':'#ef4444',
                                    'Cancelled':'#64748b'
                                };

                                activity.color =
                                    colourMap[
                                        activity.status
                                    ]
                                    || '#94a3b8';
                            }

                            const editorActivity =
                                activityIndex.get(
                                    String(id)
                                );

                            if (editorActivity) {
                                editorActivity.status =
                                    document.getElementById(
                                        'acStatus'
                                    ).value;

                                editorActivity.progress =
                                    Number(
                                        document.getElementById(
                                            'acProgress'
                                        ).value || 0
                                    );

                                editorActivity.frequency =
                                    document.getElementById(
                                        'acFrequency'
                                    ).value;
                            }

                            closeModal();
                            renderCalendar();

                          
                            setTimeout(
                                function() {
                                    window.location.reload();
                                },
                                450
                            );
                        }
                    )
                    .catch(
                        function(error) {
                            console.error(error);

                            saveBtn.disabled = false;
                            saveBtn.innerHTML =
                                '<i class="fas fa-save"></i> Update Activity';

                            alert(
                                'Could not reach the server.'
                            );
                        }
                    );
            }
        );


    renderCalendar();

})();
</script>

<?php include 'includes/footer.php'; ?>
