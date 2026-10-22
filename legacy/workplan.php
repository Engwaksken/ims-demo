<?php
declare(strict_types=1);


$page_title = 'Create Workplan';
include 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';

check_role(IMS_ALL_ROLES);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');
require_once 'includes/workplan-stats.php';
require_once __DIR__ . '/includes/task-carryover.php';
task_carryover_maybe_run($conn); // daily carry-over fallback when cron is not configured

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = trim($table);

    if ($table === '') {
        return false;
    }

    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

    if (!$res) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->close();

    return $exists;
}

function wp_column_exists(mysqli $conn, string $table, string $column): bool
{
    if (!table_exists($conn, $table)) {
        return false;
    }

    $res = $conn->query(
        "SHOW COLUMNS FROM `" . $conn->real_escape_string($table) .
        "` LIKE '" . $conn->real_escape_string($column) . "'"
    );

    if (!$res) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->close();

    return $exists;
}

function wp_calendar_date(mixed $value): ?string
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $ts = strtotime((string)$value);

    return $ts === false ? null : date('Y-m-d', $ts);
}

$hasPrograms = table_exists($conn, 'programs');

$projects = [];
$res = $conn->query("SELECT project_id, project_code, project_name FROM projects ORDER BY project_code, project_name");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row;
    }
    $res->close();
}

$programs = [];
if ($hasPrograms) {
    $res = $conn->query("SELECT id, program_code, program_name FROM programs ORDER BY program_code, program_name");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $programs[] = $row;
        }
        $res->close();
    }
}

$allowed_milestone_types = WP_MILESTONE_TYPES;
$allowed_deliverable_statuses = WP_DELIVERABLE_STATUSES;
$allowed_monthly_statuses = WP_MONTHLY_STATUSES;

$currentYear = (int)date('Y');
$yearOptions = range($currentYear - 1, $currentYear + 2);

$months = WP_MONTHS;

// --- Load an existing workplan (edit / view mode) if one was requested ---
$existingWorkplanId = isset($_GET['workplan_id']) ? (int)$_GET['workplan_id'] : 0;
$existingWorkplan = $existingWorkplanId ? wp_get_full_workplan($conn, $existingWorkplanId) : null;
$existingStats = $existingWorkplan ? wp_compute_stats($existingWorkplan) : null;

$savedWorkplans = table_exists($conn, 'workplans') ? wp_list_workplans($conn) : [];

$milestoneHasStatus = wp_column_exists($conn, 'workplan_milestones', 'status');
$milestoneHasProgress = wp_column_exists($conn, 'workplan_milestones', 'progress_percentage');
$milestoneHasFrequency = wp_column_exists($conn, 'workplan_milestones', 'calendar_frequency');
$deliverableHasFrequency = wp_column_exists($conn, 'workplan_deliverables', 'calendar_frequency');

$calendarEvents = [];

if ($existingWorkplan) {
    foreach (($existingWorkplan['milestones'] ?? []) as $milestone) {
        $mid = (int)($milestone['id'] ?? 0);
        $mStart = wp_calendar_date($milestone['start_date'] ?? $milestone['due_date'] ?? null);
        $mEnd = wp_calendar_date($milestone['due_date'] ?? $milestone['start_date'] ?? null);

        if ($mStart !== null) {
            $calendarEvents[] = [
                'id' => 'm-' . $mid,
                'record_id' => $mid,
                'kind' => 'milestone',
                'title' => (string)($milestone['milestone_name'] ?? 'Milestone'),
                'start' => $mStart,
                'end' => $mEnd ?? $mStart,
                'status' => $milestoneHasStatus ? (string)($milestone['status'] ?? 'Pending') : 'Pending',
                'progress' => $milestoneHasProgress ? (float)($milestone['progress_percentage'] ?? 0) : 0,
                'frequency' => $milestoneHasFrequency ? (string)($milestone['calendar_frequency'] ?? 'Annual') : 'Annual',
                'responsible' => (string)($milestone['responsible_person'] ?? ''),
                'milestone_name' => (string)($milestone['milestone_name'] ?? ''),
            ];
        }

        foreach (($milestone['deliverables'] ?? []) as $deliverable) {
            $did = (int)($deliverable['id'] ?? 0);
            $dStart = wp_calendar_date($deliverable['start_date'] ?? $deliverable['end_date'] ?? $milestone['start_date'] ?? null);
            $dEnd = wp_calendar_date($deliverable['end_date'] ?? $deliverable['start_date'] ?? $milestone['due_date'] ?? null);

            if ($dStart === null) {
                continue;
            }

            $calendarEvents[] = [
                'id' => 'a-' . $did,
                'record_id' => $did,
                'kind' => 'activity',
                'title' => (string)($deliverable['title'] ?? 'Activity'),
                'start' => $dStart,
                'end' => $dEnd ?? $dStart,
                'status' => (string)($deliverable['status'] ?? 'Pending'),
                'progress' => (float)($deliverable['progress_percentage'] ?? 0),
                'frequency' => $deliverableHasFrequency ? (string)($deliverable['calendar_frequency'] ?? 'Monthly') : 'Monthly',
                'responsible' => (string)($deliverable['responsible_person'] ?? ''),
                'milestone_name' => (string)($milestone['milestone_name'] ?? ''),
            ];
        }
    }
}
$calendarYears = [];

if ($existingWorkplan) {
    $calendarYears[] = (int)($existingWorkplan['workplan_year'] ?? $currentYear);
}

$calendarYears[] = $currentYear;

foreach ($calendarEvents as $event) {
    foreach (['start', 'end'] as $dateKey) {
        if (!empty($event[$dateKey])) {
            $year = (int)substr((string)$event[$dateKey], 0, 4);

            if ($year > 0) {
                $calendarYears[] = $year;
            }
        }
    }
}

$calendarYears = array_values(array_unique($calendarYears));
sort($calendarYears);

$initialCalendarYear = $existingWorkplan
    ? (int)($existingWorkplan['workplan_year'] ?? $currentYear)
    : $currentYear;

$initialCalendarCount = 0;
$yearStartTs = strtotime($initialCalendarYear . '-01-01 00:00:00');
$yearEndTs   = strtotime($initialCalendarYear . '-12-31 23:59:59');

foreach ($calendarEvents as $event) {
    $startTs = !empty($event['start'])
        ? strtotime((string)$event['start'])
        : false;

    $endTs = !empty($event['end'])
        ? strtotime((string)$event['end'])
        : $startTs;

    if ($startTs === false) {
        continue;
    }

    if ($endTs === false) {
        $endTs = $startTs;
    }

    if ($startTs <= $yearEndTs && $endTs >= $yearStartTs) {
        $initialCalendarCount++;
    }
}

?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">
<link rel="stylesheet" href="css/workplan.css">

<style>
    /* Global .stats-grid.reports-stats inside a panel: pad it, drop the outer gap. */
    .stats-grid.reports-stats.wp-stats-grid { padding:16px; margin-bottom:0; }
    .wp-status-bar-wrap { padding:0 16px 16px; }
    .wp-status-bar { display:flex; height:14px; border-radius:7px; overflow:hidden; background:#e2e8f0; }
    .wp-status-bar-seg { height:100%; }
    .wp-status-legend-inline { display:flex; gap:14px; flex-wrap:wrap; margin-top:8px; font-size:12px; color:#475569; }
    .wp-status-legend-inline .swatch { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:4px; vertical-align:middle; }
    .wp-load-picker { display:flex; gap:10px; align-items:flex-end; padding:16px; flex-wrap:wrap; }
    .wp-export-actions { display:flex; gap:10px; padding:0 16px 16px; }

    /* Google-style Workplan Calendar */
    .wp-calendar-shell{overflow:hidden;background:#fff;border:1px solid #e2e8f0;border-radius:12px}
    .wp-calendar-toolbar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 14px;border-bottom:1px solid #e2e8f0}
    .wp-calendar-toolbar-left,.wp-calendar-toolbar-right{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
    .wp-cal-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:34px;padding:7px 10px;border:1px solid #dbe3ec;border-radius:8px;background:#fff;color:#475569;font-size:12px;font-weight:700;cursor:pointer}
    .wp-cal-btn.active{background:#ecfdf5;border-color:#0f766e;color:#0f766e}
    .wp-cal-nav{width:34px;height:34px;border:1px solid #dbe3ec;border-radius:50%;background:#fff;color:#475569;cursor:pointer}
    .wp-calendar-title{min-width:190px;font-size:17px;font-weight:800;color:#0f172a;text-align:center}
    .wp-calendar-scroll{overflow-x:auto}.wp-month-grid{display:grid;grid-template-columns:repeat(7,1fr);min-width:900px}
    .wp-weekday{padding:8px;border-right:1px solid #eef2f7;border-bottom:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:11px;font-weight:800;text-align:center;text-transform:uppercase}
    .wp-day-cell{min-height:128px;padding:6px;border-right:1px solid #eef2f7;border-bottom:1px solid #eef2f7;background:#fff}
    .wp-day-cell.outside{background:#f8fafc}.wp-day-cell.today{background:#f0fdfa}
    .wp-day-head{display:flex;justify-content:flex-end;margin-bottom:5px}.wp-day-number{width:25px;height:25px;display:grid;place-items:center;border-radius:50%;font-size:11.5px;font-weight:800;color:#475569}.wp-day-cell.today .wp-day-number{background:#0f766e;color:#fff}
    .wp-event{display:block;width:100%;margin-bottom:4px;padding:5px 6px;overflow:hidden;border:0;border-left:4px solid var(--event-color);border-radius:6px;background:#f8fafc;color:#334155;font-size:11.5px;font-weight:700;text-align:left;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
    .wp-event-kind{font-size:9.5px;opacity:.65;text-transform:uppercase;margin-right:3px}.wp-more{border:0;background:transparent;color:#0f766e;font-size:11px;font-weight:800;cursor:pointer}
    .wp-agenda-day{border-bottom:1px solid #e2e8f0}.wp-agenda-day-head{display:flex;justify-content:space-between;padding:10px 13px;background:#f8fafc;color:#334155;font-size:12px;font-weight:800}.wp-agenda-items{padding:8px 10px}
    .wp-agenda-event{display:grid;grid-template-columns:110px minmax(0,1fr) auto;gap:10px;align-items:center;padding:9px 11px;margin-bottom:7px;border:1px solid #e2e8f0;border-left:4px solid var(--event-color);border-radius:8px;background:#fff;cursor:pointer}
    .wp-agenda-title{font-size:13px;font-weight:800;color:#0f172a}.wp-agenda-meta{font-size:12px;color:#64748b;margin-top:2px}.wp-agenda-status{padding:4px 8px;border-radius:999px;color:#fff;font-size:11px;font-weight:800}
    .wp-period-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:14px}.wp-period-card{padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}.wp-period-card h4{margin:0 0 9px;font-size:12.5px;color:#0f172a}.wp-period-event{padding:6px 7px;margin-bottom:5px;border-left:4px solid var(--event-color);border-radius:6px;background:#f8fafc;color:#334155;font-size:11.5px;font-weight:700;cursor:pointer}
    .wp-calendar-legend{display:flex;gap:13px;flex-wrap:wrap;padding:9px 13px;border-top:1px solid #e2e8f0;color:#475569;font-size:12px}.wp-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:4px}
    .wp-modal{display:none;position:fixed;inset:0;z-index:99999;padding:20px;overflow-y:auto;background:rgba(15,23,42,.58)}.wp-modal.open{display:flex;align-items:flex-start;justify-content:center}.wp-modal-card{width:min(100%,560px);margin:45px auto;overflow:hidden;border-radius:14px;background:#fff;box-shadow:0 24px 60px rgba(15,23,42,.25)}.wp-modal-head,.wp-modal-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:15px 18px}.wp-modal-head{border-bottom:1px solid #e2e8f0}.wp-modal-foot{justify-content:flex-end;border-top:1px solid #e2e8f0}.wp-modal-body{padding:18px}.wp-modal-close{width:34px;height:34px;border:0;border-radius:8px;background:#f8fafc;color:#64748b;font-size:20px;cursor:pointer}
    .wp-event-summary{padding:11px 13px;margin-bottom:13px;border-left:4px solid #0f766e;border-radius:8px;background:#f8fafc}.wp-event-summary h4{margin:0 0 4px;font-size:14px;color:#0f172a}.wp-event-summary p{margin:0;font-size:12px;color:#64748b;line-height:1.5}
    @media(max-width:1200px){.wp-period-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:760px){.wp-calendar-toolbar-left,.wp-calendar-toolbar-right{width:100%}.wp-calendar-toolbar-right{overflow-x:auto;flex-wrap:nowrap}.wp-calendar-title{width:100%;text-align:left;order:3}.wp-agenda-event{grid-template-columns:1fr}.wp-period-grid{grid-template-columns:1fr}}


    /* Workplan main tabs */
    .wp-main-tabs{
        display:flex;
        gap:0;
        margin:16px 0 18px;
        overflow-x:auto;
        border-bottom:2px solid #e2e8f0;
        scrollbar-width:none
    }
    .wp-main-tabs::-webkit-scrollbar{display:none}
    .wp-main-tab{
        flex-shrink:0;
        display:inline-flex;
        align-items:center;
        gap:7px;
        padding:11px 16px;
        margin-bottom:-2px;
        border:0;
        border-bottom:2px solid transparent;
        background:transparent;
        color:#64748b;
        font-size:12.5px;
        font-weight:800;
        white-space:nowrap;
        cursor:pointer
    }
    .wp-main-tab:hover{color:#0f172a}
    .wp-main-tab.active{color:#0f766e;border-bottom-color:#0f766e}
    .wp-tab-badge{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-width:20px;
        height:20px;
        padding:0 6px;
        border-radius:999px;
        background:#e2e8f0;
        color:#475569;
        font-size:10px;
        font-weight:800
    }
    .wp-main-tab.active .wp-tab-badge{background:#ccfbf1;color:#0f766e}
    .wp-tab-panel{display:none}
    .wp-tab-panel.active{display:block}


    .wp-calendar-year-select{
        min-height:36px;
        min-width:92px;
        padding:6px 30px 6px 10px;
        border:1px solid #dbe3ec;
        border-radius:8px;
        background:#fff;
        color:#0f172a;
        font-size:12px;
        font-weight:800;
        cursor:pointer
    }
    .wp-calendar-year-select:focus{
        border-color:#0f766e;
        outline:none;
        box-shadow:0 0 0 3px rgba(15,118,110,.10)
    }


/* ==========================================================================
   MILESTONE & ACTIVITY LIST CARDS
   ========================================================================== */

.wp-milestone-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.wp-milestone-list-card,
.wp-activity-list-card {
    overflow: hidden;

    border: 1px solid #e2e8f0;
    border-radius: 11px;

    background: #fff;

    box-shadow: 0 2px 7px rgba(15, 23, 42, .05);
}

.wp-milestone-list-card.open {
    border-color: #99f6e4;
}

.wp-list-card-head {
    width: 100%;

    appearance: none;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    padding: 13px 14px;

    border: 0;

    background: #fff;

    text-align: left;

    cursor: pointer;
}

.wp-list-card-head:hover {
    background: #f8fafc;
}

.wp-list-card-main {
    min-width: 0;

    flex: 1;
}

.wp-list-card-title-row {
    display: flex;
    align-items: center;
    gap: 8px;

    min-width: 0;

    margin-bottom: 5px;
}

.wp-list-card-title {
    min-width: 0;

    color: #0f172a;

    font-size: 13px;
    font-weight: 800;

    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.wp-list-card-sub {
    color: #64748b;

    font-size: 10.5px;
    font-weight: 700;
}

.wp-list-card-side {
    display: flex;
    align-items: center;
    gap: 10px;

    flex-shrink: 0;
}

.wp-list-chevron {
    color: #94a3b8;

    font-size: 11px;

    transition: transform .2s ease;
}

.wp-milestone-list-card.open > .wp-list-card-head .wp-list-chevron,
.wp-activity-list-card.open > .wp-list-card-head .wp-list-chevron {
    transform: rotate(180deg);
}

.wp-list-card-body {
    display: none;

    padding: 14px;

    border-top: 1px solid #eef2f7;
}

.wp-milestone-list-card.open > .wp-list-card-body,
.wp-activity-list-card.open > .wp-list-card-body {
    display: block;
}

.wp-progress-wrap {
    display: flex;
    align-items: center;
    gap: 8px;

    margin-top: 6px;
}

.wp-progress-track {
    position: relative;

    flex: 1;

    height: 7px;

    overflow: hidden;

    border-radius: 999px;

    background: #e2e8f0;
}

.wp-progress-fill {
    display: block;

    width: 0;
    height: 100%;

    border-radius: 999px;

    background: linear-gradient(
        90deg,
        #0f766e,
        #22c55e
    );

    transition: width .25s ease;
}

.wp-progress-pct {
    width: 40px;

    color: #0f766e;

    font-size: 10.5px;
    font-weight: 800;

    text-align: right;
}

.wp-activity-list {
    display: flex;
    flex-direction: column;
    gap: 9px;

    margin-top: 10px;
}

.wp-activity-list-card .wp-list-card-head {
    padding: 11px 12px;
}

.wp-activity-list-card .wp-list-card-title {
    font-size: 12px;
}

.wp-mini-status {
    display: inline-flex;
    align-items: center;

    padding: 4px 8px;

    border-radius: 999px;

    background: #f1f5f9;
    color: #475569;

    font-size: 10px;
    font-weight: 800;
}

@media (max-width: 640px) {
    .wp-list-card-head {
        align-items: flex-start;
    }

    .wp-list-card-side {
        gap: 6px;
    }

    .wp-list-card-sub {
        display: block;
    }
}


/* ==========================================================================
   MILESTONE GRID - 3 PER ROW
   ========================================================================== */

#milestonesContainer.wp-milestone-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    align-items: start;
}

#milestonesContainer .wp-milestone-list-card {
    min-width: 0;
    align-self: start;
}

#milestonesContainer .wp-milestone-list-card.open {
    grid-column: 1 / -1;
}

#milestonesContainer .wp-milestone-list-card.open > .wp-list-card-body {
    display: block;
}

#milestonesContainer .wp-milestone-list-card:not(.open) > .wp-list-card-body {
    display: none;
}

.wp-activity-list {
    display: flex;
    flex-direction: column;
    gap: 9px;
}

@media (max-width: 1100px) {
    #milestonesContainer.wp-milestone-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    #milestonesContainer .wp-milestone-list-card.open {
        grid-column: 1 / -1;
    }
}

@media (max-width: 680px) {
    #milestonesContainer.wp-milestone-list {
        grid-template-columns: 1fr;
    }

    #milestonesContainer .wp-milestone-list-card.open {
        grid-column: auto;
    }
}


/* ==========================================================================
   MILESTONE PAGINATION
   ========================================================================== */

.wp-milestone-pagination {
    display: none;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
}

.wp-milestone-pagination.show {
    display: flex;
}

.wp-pagination-info {
    color: #64748b;
    font-size: 11.5px;
    font-weight: 700;
}

.wp-pagination-controls {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.wp-page-btn {
    min-width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 9px;
    border: 1px solid #dbe3ec;
    border-radius: 8px;
    background: #fff;
    color: #475569;
    font-size: 11.5px;
    font-weight: 800;
    cursor: pointer;
}

.wp-page-btn:hover:not(:disabled) {
    border-color: #99f6e4;
    background: #f0fdfa;
    color: #0f766e;
}

.wp-page-btn.active {
    border-color: #0f766e;
    background: #0f766e;
    color: #fff;
}

.wp-page-btn:disabled {
    opacity: .45;
    cursor: not-allowed;
}

@media (max-width: 640px) {
    .wp-milestone-pagination {
        align-items: flex-start;
        flex-direction: column;
    }

    .wp-pagination-controls {
        width: 100%;
        overflow-x: auto;
        flex-wrap: nowrap;
        padding-bottom: 2px;
    }
}

</style>

<div class="workplan-wrap">

    <div class="milestones-hero workplan-hero">
        <div class="milestones-hero-text">
            <h1><i class="fas fa-route" style="margin-right:10px;opacity:.85;"></i> <?php echo $existingWorkplan ? 'Edit Workplan' : 'Create Workplan'; ?></h1>
            <p>
                Build an annual workplan for a project or program: add milestones, break each one into
                activities/deliverables with a responsible actor, and track month-by-month status.
            </p>
        </div>

        <div class="hero-actions">
            <button type="button" class="btn btn-primary" id="wpImportOpen"><i class="fas fa-file-import"></i> Import CSV</button>
            <a href="milestones" class="btn btn-gray"><i class="fas fa-flag-checkered"></i> Back to Milestones</a>
        </div>
    </div>

    <?php if (!empty($savedWorkplans)): ?>
    <!-- LOAD EXISTING WORKPLAN -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-folder-open" style="color:#0f766e;margin-right:8px;"></i> Load an Existing Workplan</h3>
        </div>
        <div class="wp-load-picker">
            <div class="form-group" style="min-width:320px;">
                <label class="form-label">Saved Workplans</label>
                <select class="form-control" onchange="if(this.value){ window.location.href='workplan?workplan_id=' + this.value; }">
                    <option value="">Start a new workplan...</option>
                    <?php foreach ($savedWorkplans as $wp): ?>
                        <option value="<?php echo (int)$wp['id']; ?>" <?php echo ((int)$wp['id'] === $existingWorkplanId) ? 'selected' : ''; ?>>
                            <?php echo h($wp['label'] . ' - ' . $wp['workplan_year']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="wp-main-tabs" role="tablist" aria-label="Workplan sections">
        <button type="button" class="wp-main-tab active" data-wp-tab="overview" role="tab" aria-selected="true">
            <i class="fas fa-chart-pie"></i> Overview
        </button>

        <?php if ($existingWorkplan): ?>
            <button type="button" class="wp-main-tab" data-wp-tab="calendar" role="tab" aria-selected="false">
                <i class="fas fa-calendar-days"></i> Calendar
                <span class="wp-tab-badge" id="wpCalendarTabBadge"><?php echo $initialCalendarCount; ?></span>
            </button>
        <?php endif; ?>

        <button type="button" class="wp-main-tab" data-wp-tab="setup" role="tab" aria-selected="false">
            <i class="fas fa-bullseye"></i> Workplan Setup
        </button>

        <button type="button" class="wp-main-tab" data-wp-tab="builder" role="tab" aria-selected="false">
            <i class="fas fa-list-check"></i> Milestones & Activities
            <?php if ($existingStats): ?>
                <span class="wp-tab-badge"><?php echo (int)$existingStats['milestone_count']; ?></span>
            <?php endif; ?>
        </button>
    </div>

    <section class="wp-tab-panel active" id="wp-tab-overview" role="tabpanel">

    <?php if ($existingWorkplan && $existingStats): ?>
    <!-- STATS DASHBOARD -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-chart-column" style="color:#0f766e;margin-right:8px;"></i> Workplan Stats</h3>
        </div>

        <div class="stats-grid reports-stats wp-stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-primary"><i class="fas fa-flag-checkered" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Milestones</span>
                    <span class="stat-value"><?php echo $existingStats['milestone_count']; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-blue"><i class="fas fa-list-check" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Activities / Deliverables</span>
                    <span class="stat-value"><?php echo $existingStats['deliverable_count']; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-teal"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Average Progress</span>
                    <span class="stat-value"><?php echo $existingStats['completion_pct']; ?>%</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Completed</span>
                    <span class="stat-value"><?php echo $existingStats['completed_deliverables']; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-amber"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Overdue Flags</span>
                    <span class="stat-value"><span style="color:<?php echo $existingStats['overdue_count'] > 0 ? '#dc2626' : 'inherit'; ?>;"><?php echo $existingStats['overdue_count']; ?></span></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-purple"><i class="fas fa-gauge-high" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">On Track</span>
                    <span class="stat-value"><?php echo $existingStats['on_track_count']; ?></span>
                </div>
            </div>
        </div>

        <?php
            $totalMonthly = array_sum($existingStats['monthly_status_counts']);
        ?>
        <div class="wp-status-bar-wrap">
            <div class="wp-status-bar">
                <?php foreach ($allowed_monthly_statuses as $st):
                    $count = $existingStats['monthly_status_counts'][$st];
                    $pct = $totalMonthly > 0 ? ($count / $totalMonthly * 100) : 0;
                    if ($pct <= 0) continue;
                ?>
                    <div class="wp-status-bar-seg" style="width:<?php echo $pct; ?>%; background:<?php echo WP_STATUS_COLORS[$st]; ?>;"
                         title="<?php echo h($st . ': ' . $count); ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="wp-status-legend-inline">
                <?php foreach ($allowed_monthly_statuses as $st): ?>
                    <span><span class="swatch" style="background:<?php echo WP_STATUS_COLORS[$st]; ?>;"></span><?php echo h($st); ?>: <?php echo $existingStats['monthly_status_counts'][$st]; ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="wp-export-actions">
            <a class="btn btn-gray" href="actions/workplan-export.php?workplan_id=<?php echo $existingWorkplanId; ?>&format=csv">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <a class="btn btn-primary" href="actions/workplan-export.php?workplan_id=<?php echo $existingWorkplanId; ?>&format=pdf">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$existingWorkplan): ?>
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-circle-info" style="color:#0f766e;margin-right:8px;"></i> Workplan Overview</h3>
            </div>
            <div style="padding:18px;color:#64748b;font-size:13px;">
                Create and save a workplan first. After saving, this tab will show statistics and completion progress.
            </div>
        </div>
    <?php endif; ?>

    </section>

    <?php if ($existingWorkplan): ?>
    <section class="wp-tab-panel" id="wp-tab-calendar" role="tabpanel">
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-calendar-alt" style="color:#0f766e;margin-right:8px;"></i> Workplan Calendar</h3>
        </div>
        <div style="padding:16px;">
            <div class="wp-calendar-shell">
                <div class="wp-calendar-toolbar">
                    <div class="wp-calendar-toolbar-left">
                        <button type="button" class="wp-cal-btn" id="wpTodayBtn">Today</button>
                        <button type="button" class="wp-cal-nav" id="wpPrevBtn"><i class="fas fa-chevron-left"></i></button>
                        <button type="button" class="wp-cal-nav" id="wpNextBtn"><i class="fas fa-chevron-right"></i></button>

                        <select
                            id="wpCalendarYear"
                            class="wp-calendar-year-select"
                            aria-label="Select calendar year"
                            title="Select calendar year"
                        >
                            <?php foreach ($calendarYears as $calendarYear): ?>
                                <option
                                    value="<?php echo (int)$calendarYear; ?>"
                                    <?php echo ((int)$calendarYear === $initialCalendarYear) ? 'selected' : ''; ?>
                                >
                                    <?php echo (int)$calendarYear; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="wp-calendar-title" id="wpCalendarTitle"></div>
                    </div>
                    <div class="wp-calendar-toolbar-right">
                        <button type="button" class="wp-cal-btn active" data-item-filter="all">All</button>
                        <button type="button" class="wp-cal-btn" data-item-filter="milestone"><i class="fas fa-flag"></i> Milestones</button>
                        <button type="button" class="wp-cal-btn" data-item-filter="activity"><i class="fas fa-list-check"></i> Activities</button>
                        <button type="button" class="wp-cal-btn" data-calendar-view="day">Daily</button>
                        <button type="button" class="wp-cal-btn" data-calendar-view="week">Weekly</button>
                        <button type="button" class="wp-cal-btn active" data-calendar-view="month">Monthly</button>
                        <button type="button" class="wp-cal-btn" data-calendar-view="quarter">Quarterly</button>
                        <button type="button" class="wp-cal-btn" data-calendar-view="year">Annual</button>
                    </div>
                </div>
                <div class="wp-calendar-scroll"><div id="wpCalendarBody"></div></div>
                <div class="wp-calendar-legend">
                    <span><span class="wp-dot" style="background:#94a3b8"></span>Pending</span>
                    <span><span class="wp-dot" style="background:#3b82f6"></span>In Progress</span>
                    <span><span class="wp-dot" style="background:#f59e0b"></span>Ongoing</span>
                    <span><span class="wp-dot" style="background:#ef4444"></span>Delayed / Overdue</span>
                    <span><span class="wp-dot" style="background:#22c55e"></span>Completed</span>
                </div>
            </div>
        </div>
    </div>
    </section>
    <?php endif; ?>

    <section class="wp-tab-panel" id="wp-tab-setup" role="tabpanel">

    <!-- STEP 1: Entity + Year -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-bullseye" style="color:#0f766e;margin-right:8px;"></i> Workplan Target</h3>
        </div>

        <div style="padding:16px;">
            <div class="entity-picker">
                <div class="form-group">
                    <label class="form-label">Entity Type</label>
                    <select id="entityType" class="form-control" onchange="wpToggleEntity()">
                        <option value="Project" <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Project') ? 'selected' : ''; ?>>Project</option>
                        <?php if (!empty($programs)): ?>
                            <option value="Program" <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Program') ? 'selected' : ''; ?>>Program</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group" id="wpProjectField">
                    <label class="form-label">Project</label>
                    <select id="entityIdProject" class="form-control">
                        <option value="">Select a project...</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo (int)$project['project_id']; ?>"
                                <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Project' && (int)$existingWorkplan['entity_id'] === (int)$project['project_id']) ? 'selected' : ''; ?>>
                                <?php echo h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($programs)): ?>
                <div class="form-group" id="wpProgramField" style="display:none;">
                    <label class="form-label">Program</label>
                    <select id="entityIdProgram" class="form-control">
                        <option value="">Select a program...</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo (int)$program['id']; ?>"
                                <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Program' && (int)$existingWorkplan['entity_id'] === (int)$program['id']) ? 'selected' : ''; ?>>
                                <?php echo h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Workplan Year</label>
                    <select id="workplanYear" class="form-control">
                        <?php foreach ($yearOptions as $y): ?>
                            <option value="<?php echo (int)$y; ?>"
                                <?php echo ($existingWorkplan ? ((int)$existingWorkplan['workplan_year'] === $y) : ($y === $currentYear)) ? 'selected' : ''; ?>>
                                <?php echo (int)$y; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- STATUS KEY -->
    <div class="status-legend">
        <?php foreach ($allowed_monthly_statuses as $st): ?>
            <span class="legend-item">
                <span class="legend-swatch status-<?php echo h($st); ?>"></span>
                <?php echo h($st); ?>
            </span>
        <?php endforeach; ?>
    </div>

    </section>

    <section class="wp-tab-panel" id="wp-tab-builder" role="tabpanel">

    <div id="workplanAlert" style="display:none;"></div>

    <!-- STEP 2/3: Milestones builder -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-flag-checkered" style="color:#0f766e;margin-right:8px;"></i> Milestones</h3>
            <button type="button" class="btn btn-primary btn-sm" onclick="wpAddMilestone()">
                <i class="fas fa-plus"></i> Add Milestone
            </button>
        </div>

        <div style="padding:16px;">
            <div id="milestonesContainer" class="wp-milestone-list"></div>

            <div id="milestonesEmptyHint" class="workplan-empty-hint">
                No milestones yet. Click "Add Milestone" to start building the workplan.
            </div>

            <div
                id="milestonePagination"
                class="wp-milestone-pagination"
                aria-label="Milestone pagination"
            >
                <div
                    id="milestonePaginationInfo"
                    class="wp-pagination-info"
                ></div>

                <div
                    id="milestonePaginationControls"
                    class="wp-pagination-controls"
                ></div>
            </div>
        </div>
    </div>

    <div class="panel workplan-actionbar">
        <button type="button" class="btn btn-gray" onclick="window.location.href='milestones'">Cancel</button>
        <button type="button" class="btn btn-primary" id="saveWorkplanBtn" onclick="wpSaveWorkplan()">
            <i class="fas fa-save"></i> <?php echo $existingWorkplan ? 'Update Workplan' : 'Save Workplan'; ?>
        </button>
    </div>

    </section>
</div>


<div class="wp-modal" id="wpImportModal" role="dialog" aria-modal="true" aria-labelledby="wpImportTitle">
    <div class="wp-modal-card wp-import-card">
        <div class="wp-modal-head">
            <h3 id="wpImportTitle"><i class="fas fa-file-import" style="color:#0f766e;margin-right:7px;" aria-hidden="true"></i> Import Workplan from CSV</h3>
            <button type="button" class="wp-modal-close" id="wpImportClose" aria-label="Close">&times;</button>
        </div>
        <form id="wpImportForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_csv">
            <div class="wp-modal-body">
                <ol class="wp-import-steps">
                    <li>
                        <div>
                            <strong>Download the template</strong>
                            <span>One row per activity. Repeat the milestone name for each of its activities.</span>
                        </div>
                        <a href="actions/workplan-actions.php?action=template" class="btn btn-gray"><i class="fas fa-download" aria-hidden="true"></i> Template</a>
                    </li>
                    <li>
                        <div>
                            <strong>Choose the project or program and year, then upload</strong>
                            <span>If a workplan already exists for it, the activities are added to it (nothing is deleted, existing activities are skipped).</span>
                        </div>
                    </li>
                </ol>

                <div class="wp-import-grid">
                    <div class="form-group">
                        <label class="form-label" for="wpImportEntityType">Entity Type</label>
                        <select class="form-control" name="entity_type" id="wpImportEntityType">
                            <option value="Project" <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Project') ? 'selected' : ''; ?>>Project</option>
                            <?php if (!empty($programs)): ?>
                                <option value="Program" <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Program') ? 'selected' : ''; ?>>Program</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group" id="wpImportProjectField">
                        <label class="form-label" for="wpImportProject">Project</label>
                        <select class="form-control" id="wpImportProject">
                            <option value="">Select a project...</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo (int)$project['project_id']; ?>"
                                    <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Project' && (int)$existingWorkplan['entity_id'] === (int)$project['project_id']) ? 'selected' : ''; ?>>
                                    <?php echo h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!empty($programs)): ?>
                    <div class="form-group" id="wpImportProgramField" hidden>
                        <label class="form-label" for="wpImportProgram">Program</label>
                        <select class="form-control" id="wpImportProgram">
                            <option value="">Select a program...</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo (int)$program['id']; ?>"
                                    <?php echo ($existingWorkplan && $existingWorkplan['entity_type'] === 'Program' && (int)$existingWorkplan['entity_id'] === (int)$program['id']) ? 'selected' : ''; ?>>
                                    <?php echo h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label" for="wpImportYear">Workplan Year</label>
                        <select class="form-control" name="workplan_year" id="wpImportYear">
                            <?php foreach ($yearOptions as $y): ?>
                                <option value="<?php echo (int)$y; ?>"
                                    <?php echo ($existingWorkplan ? ((int)$existingWorkplan['workplan_year'] === $y) : ($y === $currentYear)) ? 'selected' : ''; ?>>
                                    <?php echo (int)$y; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group wp-import-full">
                        <label class="form-label" for="wpImportFile">CSV file</label>
                        <input type="file" class="form-control" name="workplan_file" id="wpImportFile" accept=".csv,text/csv" required>
                    </div>
                </div>

                <div class="wp-import-result" id="wpImportResult" role="alert" hidden></div>
            </div>
            <div class="wp-modal-foot">
                <button type="button" class="btn btn-gray" id="wpImportCancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="wpImportSubmit"><i class="fas fa-upload" aria-hidden="true"></i> Import</button>
            </div>
        </form>
    </div>
</div>

<style>
.wp-import-card { width: min(100%, 680px); }
.wp-import-steps { list-style: none; counter-reset: wpstep; margin: 0 0 16px; padding: 0; display: grid; gap: 10px; }
.wp-import-steps li { counter-increment: wpstep; display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--border, #e5e7eb); border-radius: 12px; background: var(--surface-hover, #f9fafb); font-size: 13px; }
.wp-import-steps li::before { content: counter(wpstep); flex: none; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: #0f766e; color: #fff; font-weight: 700; font-size: 12px; }
.wp-import-steps li > div { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.wp-import-steps li span { color: var(--ink-400, #4b5563); }
.wp-import-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 12px; }
.wp-import-full { grid-column: 1 / -1; }
.wp-import-result { margin-top: 6px; padding: 12px 14px; border-radius: 10px; font-size: 13px; border: 1px solid var(--red-border, #fecaca); background: var(--red-bg, #fef2f2); color: var(--red-fg, #b91c1c); }
.wp-import-result ul { margin: 8px 0 0; padding-left: 18px; max-height: 200px; overflow-y: auto; color: var(--ink-600, #2f3744); }
@media (max-width: 640px) {
    .wp-import-grid { grid-template-columns: 1fr; }
    .wp-import-steps li { flex-wrap: wrap; }
    .wp-import-steps .btn { width: 100%; justify-content: center; }
}
</style>

<script>
(function () {
    'use strict';
    const modal = document.getElementById('wpImportModal');
    const form = document.getElementById('wpImportForm');
    if (!modal || !form) return;

    const type = document.getElementById('wpImportEntityType');
    const projectField = document.getElementById('wpImportProjectField');
    const programField = document.getElementById('wpImportProgramField');
    const result = document.getElementById('wpImportResult');
    const submit = document.getElementById('wpImportSubmit');

    function syncEntity() {
        const isProgram = type.value === 'Program';
        if (projectField) projectField.hidden = isProgram;
        if (programField) programField.hidden = !isProgram;
    }
    function open() { syncEntity(); result.hidden = true; modal.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function close() { modal.classList.remove('open'); document.body.style.overflow = ''; }
    function showError(message, errors, more) {
        result.textContent = message;
        if (errors && errors.length) {
            const list = document.createElement('ul');
            errors.forEach(text => { const li = document.createElement('li'); li.textContent = text; list.appendChild(li); });
            if (more) { const li = document.createElement('li'); li.textContent = '...and ' + more + ' more.'; list.appendChild(li); }
            result.appendChild(list);
        }
        result.hidden = false;
    }

    document.getElementById('wpImportOpen')?.addEventListener('click', open);
    document.getElementById('wpImportClose')?.addEventListener('click', close);
    document.getElementById('wpImportCancel')?.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('open')) close(); });
    type.addEventListener('change', syncEntity);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const data = new FormData(form);
        const entityId = type.value === 'Program'
            ? document.getElementById('wpImportProgram')?.value
            : document.getElementById('wpImportProject')?.value;
        if (!entityId) { showError('Choose the ' + type.value.toLowerCase() + ' for this workplan.'); return; }
        data.set('entity_id', entityId);

        submit.disabled = true;
        submit.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Importing...';
        result.hidden = true;

        fetch('actions/workplan-actions.php', { method: 'POST', credentials: 'same-origin', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    window.location.href = 'workplan?workplan_id=' + encodeURIComponent(res.workplan_id);
                    return;
                }
                showError(res.message || 'The import failed.', res.errors, res.more);
            })
            .catch(() => showError('The import failed. Check your connection and try again.'))
            .finally(() => {
                submit.disabled = false;
                submit.innerHTML = '<i class="fas fa-upload" aria-hidden="true"></i> Import';
            });
    });
})();
</script>

<div class="wp-modal" id="wpCalendarStatusModal">
    <div class="wp-modal-card">
        <div class="wp-modal-head">
            <h3><i class="fas fa-calendar-check" style="color:#0f766e;margin-right:7px;"></i> Update Calendar Item</h3>
            <button type="button" class="wp-modal-close" id="wpCalendarModalClose">&times;</button>
        </div>
        <form id="wpCalendarStatusForm">
            <input type="hidden" name="workplan_id" value="<?php echo $existingWorkplanId; ?>">
            <input type="hidden" name="record_id" id="wpCalendarRecordId">
            <input type="hidden" name="kind" id="wpCalendarKind">
            <div class="wp-modal-body">
                <div class="wp-event-summary">
                    <h4 id="wpCalendarModalTitle"></h4>
                    <p id="wpCalendarModalMeta"></p>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status" id="wpCalendarStatus" required>
                        <?php foreach (WP_DELIVERABLE_STATUSES as $statusOption): ?>
                            <option value="<?php echo h($statusOption); ?>"><?php echo h($statusOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Progress (%)</label>
                    <input type="number" class="form-control" name="progress_percentage" id="wpCalendarProgress" min="0" max="100" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Calendar Frequency</label>
                    <select class="form-control" name="calendar_frequency" id="wpCalendarFrequency">
                        <option value="Daily">Daily</option>
                        <option value="Weekly">Weekly</option>
                        <option value="Monthly">Monthly</option>
                        <option value="Quarterly">Quarterly</option>
                        <option value="Annual">Annual</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Comment / Note</label>
                    <textarea class="form-control" name="comment" id="wpCalendarComment" rows="3" placeholder="Optional update note"></textarea>
                </div>
            </div>
            <div class="wp-modal-foot">
                <button type="button" class="btn btn-gray" id="wpCalendarModalCancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="wpCalendarStatusSave"><i class="fas fa-save"></i> Update Status</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const MILESTONE_TYPES = <?php echo json_encode($allowed_milestone_types); ?>;
    const DELIVERABLE_STATUSES = <?php echo json_encode($allowed_deliverable_statuses); ?>;
    const MONTHLY_STATUSES = <?php echo json_encode($allowed_monthly_statuses); ?>;
    const MONTHS = <?php echo json_encode($months, JSON_FORCE_OBJECT); ?>;
    const EXISTING_WORKPLAN_ID = <?php echo $existingWorkplanId ? $existingWorkplanId : 'null'; ?>;
    const EXISTING_WORKPLAN = <?php echo $existingWorkplan ? json_encode($existingWorkplan) : 'null'; ?>;
    const CALENDAR_EVENTS = <?php echo json_encode($calendarEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const CALENDAR_FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Annual'];

    let milestoneCount = 0;


    const MILESTONES_PER_PAGE = 12;
    let milestoneCurrentPage = 1;

    function wpGetMilestoneCards() {
        return Array.from(
            document.querySelectorAll(
                '#milestonesContainer > .wp-milestone-list-card'
            )
        );
    }

    function wpMilestoneTotalPages() {
        return Math.max(
            1,
            Math.ceil(
                wpGetMilestoneCards().length
                /
                MILESTONES_PER_PAGE
            )
        );
    }

    function wpRenderMilestonePagination() {
        const cards = wpGetMilestoneCards();

        const pagination = document.getElementById('milestonePagination');
        const info = document.getElementById('milestonePaginationInfo');
        const controls = document.getElementById('milestonePaginationControls');

        if (!pagination || !info || !controls) {
            return;
        }

        const total = cards.length;
        const totalPages = Math.max(
            1,
            Math.ceil(total / MILESTONES_PER_PAGE)
        );

        milestoneCurrentPage = Math.min(
            Math.max(1, milestoneCurrentPage),
            totalPages
        );

        const startIndex =
            (milestoneCurrentPage - 1)
            * MILESTONES_PER_PAGE;

        const endIndex = Math.min(
            startIndex + MILESTONES_PER_PAGE,
            total
        );

        cards.forEach(function (card, index) {
            const visible =
                index >= startIndex
                &&
                index < endIndex;

            card.style.display = visible ? '' : 'none';

            if (!visible) {
                card.classList.remove('open');

                const header =
                    card.querySelector(
                        ':scope > .wp-list-card-head'
                    );

                if (header) {
                    header.setAttribute(
                        'aria-expanded',
                        'false'
                    );
                }
            }
        });

        if (total <= MILESTONES_PER_PAGE) {
            pagination.classList.remove('show');
            controls.innerHTML = '';
            info.textContent = '';
            return;
        }

        pagination.classList.add('show');

        info.textContent =
            'Showing '
            + (startIndex + 1)
            + '-'
            + endIndex
            + ' of '
            + total
            + ' milestones';

        let html = '';

        html += `
            <button
                type="button"
                class="wp-page-btn"
                data-milestone-page="prev"
                ${milestoneCurrentPage === 1 ? 'disabled' : ''}
                aria-label="Previous milestone page"
            >
                <i class="fas fa-chevron-left"></i>
            </button>
        `;

        const pageWindow = [];

        for (let page = 1; page <= totalPages; page++) {
            if (
                page === 1
                ||
                page === totalPages
                ||
                Math.abs(page - milestoneCurrentPage) <= 1
            ) {
                pageWindow.push(page);
            }
        }

        let previousPage = 0;

        pageWindow.forEach(function (page) {
            if (
                previousPage
                &&
                page - previousPage > 1
            ) {
                html += `
                    <span
                        style="
                            padding:0 2px;
                            color:#94a3b8;
                            font-size:11px;
                        "
                    >
                        …
                    </span>
                `;
            }

            html += `
                <button
                    type="button"
                    class="wp-page-btn ${page === milestoneCurrentPage ? 'active' : ''}"
                    data-milestone-page="${page}"
                    aria-label="Milestone page ${page}"
                    ${page === milestoneCurrentPage ? 'aria-current="page"' : ''}
                >
                    ${page}
                </button>
            `;

            previousPage = page;
        });

        html += `
            <button
                type="button"
                class="wp-page-btn"
                data-milestone-page="next"
                ${milestoneCurrentPage === totalPages ? 'disabled' : ''}
                aria-label="Next milestone page"
            >
                <i class="fas fa-chevron-right"></i>
            </button>
        `;

        controls.innerHTML = html;

        controls
            .querySelectorAll('[data-milestone-page]')
            .forEach(function (button) {
                button.addEventListener('click', function () {
                    if (button.disabled) {
                        return;
                    }

                    const requestedPage =
                        button.dataset.milestonePage;

                    if (requestedPage === 'prev') {
                        milestoneCurrentPage--;
                    } else if (requestedPage === 'next') {
                        milestoneCurrentPage++;
                    } else {
                        milestoneCurrentPage =
                            Number(requestedPage);
                    }

                    wpRenderMilestonePagination();

                    document
                        .getElementById('milestonesContainer')
                        ?.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                });
            });
    }

    function wpShowLastMilestonePage() {
        milestoneCurrentPage = wpMilestoneTotalPages();
        wpRenderMilestonePagination();
    }



    /* ======================================================================
       MILESTONE / ACTIVITY LIST CARD INTERACTIONS
       ====================================================================== */

    window.wpToggleMilestoneCard = function (button) {
        const card = button.closest('.wp-milestone-list-card');

        if (!card) {
            return;
        }

        const willOpen = !card.classList.contains('open');

        document
            .querySelectorAll('#milestonesContainer .wp-milestone-list-card')
            .forEach(function (otherCard) {
                otherCard.classList.remove('open');

                const otherHead = otherCard.querySelector('.wp-list-card-head');

                if (otherHead) {
                    otherHead.setAttribute('aria-expanded', 'false');
                }
            });

        if (willOpen) {
            card.classList.add('open');
            button.setAttribute('aria-expanded', 'true');
        }
    };


    window.wpToggleActivityCard = function (button) {
        const card = button.closest('.wp-activity-list-card');

        if (!card) {
            return;
        }

        const list = card.closest('.wp-activity-list');

        if (!list) {
            return;
        }

        const willOpen = !card.classList.contains('open');

        list
            .querySelectorAll('.wp-activity-list-card')
            .forEach(function (otherCard) {
                otherCard.classList.remove('open');

                const otherHead = otherCard.querySelector('.wp-list-card-head');

                if (otherHead) {
                    otherHead.setAttribute('aria-expanded', 'false');
                }
            });

        if (willOpen) {
            card.classList.add('open');
            button.setAttribute('aria-expanded', 'true');
        }
    };


    function wpRefreshActivitySummary(row) {
        if (!row) {
            return;
        }

        const titleInput = row.querySelector('.a-title');
        const actorInput = row.querySelector('.a-actor');
        const statusInput = row.querySelector('.a-status');
        const progressInput = row.querySelector('.a-progress');

        const title = titleInput ? titleInput.value.trim() : '';
        const actor = actorInput ? actorInput.value.trim() : '';
        const status = statusInput ? statusInput.value : 'Pending';

        let progress = progressInput
            ? Number(progressInput.value || 0)
            : 0;

        progress = Math.max(0, Math.min(100, progress));

        const titleEl = row.querySelector('.activity-summary-title');
        const metaEl = row.querySelector('.activity-summary-meta');
        const statusEl = row.querySelector('.activity-summary-status');
        const fillEl = row.querySelector('.activity-progress-fill');
        const pctEl = row.querySelector('.activity-progress-pct');

        if (titleEl) {
            titleEl.textContent = title || 'Untitled Activity';
        }

        if (metaEl) {
            metaEl.textContent = actor
                ? actor + ' . ' + status
                : status;
        }

        if (statusEl) {
            statusEl.textContent = status;
        }

        if (fillEl) {
            fillEl.style.width = Math.max(0, Math.min(100, progress)) + '%';
        }

        if (pctEl) {
            pctEl.textContent = Math.round(progress) + '%';
        }
    }


    function wpRefreshMilestoneSummary(card) {
        if (!card) {
            return;
        }

        const nameInput = card.querySelector('.m-name');
        const typeInput = card.querySelector('.m-type');
        const responsibleInput = card.querySelector('.m-responsible');

        const name = nameInput ? nameInput.value.trim() : '';
        const type = typeInput ? typeInput.value : '';
        const responsible = responsibleInput
            ? responsibleInput.value.trim()
            : '';

        const activityRows = Array.from(
            card.querySelectorAll('.wp-activity-list-card')
        );

        let progress = 0;

        if (activityRows.length > 0) {
            const totalProgress = activityRows.reduce(function (sum, row) {
                const progressInput = row.querySelector('.a-progress');

                return sum + Number(
                    progressInput
                        ? progressInput.value || 0
                        : 0
                );
            }, 0);

            progress = totalProgress / activityRows.length;
        }

        progress = Math.max(0, Math.min(100, progress));

        const titleEl = card.querySelector('.milestone-summary-title');
        const metaEl = card.querySelector('.milestone-summary-meta');
        const fillEl = card.querySelector('.milestone-progress-fill');
        const pctEl = card.querySelector('.milestone-progress-pct');

        if (titleEl) {
            titleEl.textContent = name || 'Untitled Milestone';
        }

        if (metaEl) {
            const parts = [];

            if (type) {
                parts.push(type);
            }

            if (responsible) {
                parts.push(responsible);
            }

            parts.push(
                activityRows.length
                + ' activit'
                + (activityRows.length === 1 ? 'y' : 'ies')
            );

            metaEl.textContent = parts.join(' . ');
        }

        if (fillEl) {
            fillEl.style.width = Math.max(0, Math.min(100, progress)).toFixed(1) + '%';
        }

        if (pctEl) {
            pctEl.textContent = Math.round(progress) + '%';
        }
    }


    document.addEventListener('input', function (event) {
        const activityCard = event.target.closest('.wp-activity-list-card');

        if (activityCard) {
            wpRefreshActivitySummary(activityCard);

            const milestoneCard = activityCard.closest('.wp-milestone-list-card');

            if (milestoneCard) {
                wpRefreshMilestoneSummary(milestoneCard);
            }

            return;
        }

        const milestoneCard = event.target.closest('.wp-milestone-list-card');

        if (milestoneCard) {
            wpRefreshMilestoneSummary(milestoneCard);
        }
    });


    document.addEventListener('change', function (event) {
        const activityCard = event.target.closest('.wp-activity-list-card');

        if (activityCard) {
            wpRefreshActivitySummary(activityCard);

            const milestoneCard = activityCard.closest('.wp-milestone-list-card');

            if (milestoneCard) {
                wpRefreshMilestoneSummary(milestoneCard);
            }

            return;
        }

        const milestoneCard = event.target.closest('.wp-milestone-list-card');

        if (milestoneCard) {
            wpRefreshMilestoneSummary(milestoneCard);
        }
    });



    function wpOpenMainTab(name, updateHash) {
        if (typeof updateHash === 'undefined') {
            updateHash = true;
        }

        document.querySelectorAll('.wp-main-tab').forEach(function (button) {
            const active = button.dataset.wpTab === name;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        document.querySelectorAll('.wp-tab-panel').forEach(function (panel) {
            panel.classList.toggle('active', panel.id === 'wp-tab-' + name);
        });

        if (updateHash) {
            history.replaceState(null, '', '#wp-' + name);
        }

        if (name === 'calendar' && typeof wpRenderCalendar === 'function') {
            setTimeout(function () {
                wpRenderCalendar();
            }, 20);
        }
    }

    document.querySelectorAll('.wp-main-tab').forEach(function (button) {
        button.addEventListener('click', function () {
            wpOpenMainTab(button.dataset.wpTab || 'overview');
        });
    });

    const requestedWorkplanTab = window.location.hash.indexOf('#wp-') === 0
        ? window.location.hash.replace('#wp-', '')
        : 'overview';

    if (document.getElementById('wp-tab-' + requestedWorkplanTab)) {
        wpOpenMainTab(requestedWorkplanTab, false);
    }

    function optionsHtml(list, selected) {
        return list.map(function (v) {
            return '<option value="' + v + '"' + (v === selected ? ' selected' : '') + '>' + v + '</option>';
        }).join('');
    }

    window.wpToggleEntity = function () {
        const type = document.getElementById('entityType').value;
        const projectField = document.getElementById('wpProjectField');
        const programField = document.getElementById('wpProgramField');

        if (type === 'Program') {
            if (projectField) projectField.style.display = 'none';
            if (programField) programField.style.display = 'flex';
        } else {
            if (projectField) projectField.style.display = 'flex';
            if (programField) programField.style.display = 'none';
        }
    };

    function refreshEmptyHint() {
        const container = document.getElementById('milestonesContainer');
        const hint = document.getElementById('milestonesEmptyHint');

        if (container && hint) {
            hint.style.display =
                container.children.length === 0
                    ? 'block'
                    : 'none';
        }

        wpRenderMilestonePagination();
    }

    window.wpAddMilestone = function (prefill) {
        milestoneCount++;
        const mIndex = milestoneCount;

        const card = document.createElement('div');
        card.className = 'milestone-card wp-milestone-list-card';
        card.dataset.milestoneId = mIndex;

        card.innerHTML = `
            <button
                type="button"
                class="wp-list-card-head"
                aria-expanded="false"
                onclick="wpToggleMilestoneCard(this)"
            >
                <span class="wp-list-card-main">
                    <span class="wp-list-card-title-row">
                        <i class="fas fa-flag" style="color:#0f766e;"></i>
                        <span class="wp-list-card-title milestone-summary-title">
                            Milestone ${mIndex}
                        </span>
                    </span>

                    <span class="wp-list-card-sub milestone-summary-meta">
                        Add milestone details
                    </span>

                    <span class="wp-progress-wrap">
                        <span class="wp-progress-track">
                            <span
                                class="wp-progress-fill milestone-progress-fill"
                                style="width:0%;"
                            ></span>
                        </span>
                        <span class="wp-progress-pct milestone-progress-pct">
                            0%
                        </span>
                    </span>
                </span>

                <span class="wp-list-card-side">
                    <i class="fas fa-chevron-down wp-list-chevron"></i>
                </span>
            </button>

            <div class="wp-list-card-body">
                <div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
                    <button type="button" class="remove-btn" onclick="wpRemoveMilestone(this)">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>

                <div class="milestone-card-body">
                <div class="milestone-fields-grid">
                    <div class="form-group">
                        <label class="form-label">Milestone Name</label>
                        <input type="text" class="form-control m-name" placeholder="e.g. Preparation and Planning">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select class="form-control m-type">
                            ${optionsHtml(MILESTONE_TYPES, 'Implementation')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Calendar Frequency</label>
                        <select class="form-control m-frequency">
                            ${optionsHtml(CALENDAR_FREQUENCIES, 'Annual')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control m-start">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date</label>
                        <input type="date" class="form-control m-due">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Overall Responsible</label>
                        <input type="text" class="form-control m-responsible" placeholder="e.g. Bashir">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control m-description" rows="2" placeholder="Optional milestone description"></textarea>
                </div>

                <div class="activities-block">
                    <div class="activities-block-head">
                        <h4><i class="fas fa-list-check" style="color:#0f766e;margin-right:6px;"></i> Activities / Deliverables</h4>
                        <button type="button" class="btn btn-sm btn-soft" onclick="wpAddActivity(this)">
                            <i class="fas fa-plus"></i> Add Activity
                        </button>
                    </div>
                    <div class="activities-container wp-activity-list"></div>
                    <div class="workplan-empty-hint activities-empty-hint" style="padding:14px;">
                        No activities yet for this milestone.
                    </div>
                </div>
            </div>
        </div>
        `;

        document.getElementById('milestonesContainer').appendChild(card);
        refreshEmptyHint();

        if (!prefill) {
            wpShowLastMilestonePage();
        }

        if (prefill) {
            card.querySelector('.m-name').value = prefill.milestone_name || '';
            card.querySelector('.m-type').value = prefill.milestone_type || 'Implementation';
            card.querySelector('.m-frequency').value = prefill.calendar_frequency || 'Annual';
            card.querySelector('.m-start').value = prefill.start_date || '';
            card.querySelector('.m-due').value = prefill.due_date || '';
            card.querySelector('.m-responsible').value = prefill.responsible_person || '';
            card.querySelector('.m-description').value = prefill.milestone_description || '';

            const addBtn = card.querySelector('.activities-block-head button');
            (prefill.deliverables || []).forEach(function (dv) {
                window.wpAddActivity(addBtn, dv);
            });
        }

        wpRefreshMilestoneSummary(card);

        return card;
    };

    window.wpRemoveMilestone = function (btn) {
        const card = btn.closest('.milestone-card');
        card.remove();
        refreshEmptyHint();
    };

    function refreshActivitiesHint(block) {
        const container = block.querySelector('.activities-container');
        const hint = block.querySelector('.activities-empty-hint');
        hint.style.display = container.children.length === 0 ? 'block' : 'none';
    }

    window.wpAddActivity = function (btn, prefill) {
        const block = btn.closest('.activities-block');
        const container = block.querySelector('.activities-container');
        const activityIndex = container.children.length + 1;

        const row = document.createElement('div');
        row.className = 'activity-row wp-activity-list-card';

        let monthCells = '';
        for (const m in MONTHS) {
            const pre = prefill && prefill.monthly_status && prefill.monthly_status[m]
                ? prefill.monthly_status[m] : null;
            const preStatus = pre ? pre.status : 'Planned';
            const preComment = pre ? (pre.comment || '') : '';
            monthCells += `
                <div class="month-cell">
                    <label>${MONTHS[m]}</label>
                    <select class="month-status-select status-${preStatus}" data-month="${m}"
                        onchange="this.className='month-status-select status-' + this.value">
                        ${optionsHtml(MONTHLY_STATUSES, preStatus)}
                    </select>
                    <input type="text" class="month-comment-input" data-month="${m}" placeholder="note" value="${preComment.replace(/"/g, '&quot;')}">
                </div>
            `;
        }

        row.innerHTML = `
            <button
                type="button"
                class="wp-list-card-head"
                aria-expanded="false"
                onclick="wpToggleActivityCard(this)"
            >
                <span class="wp-list-card-main">
                    <span class="wp-list-card-title-row">
                        <i class="fas fa-list-check" style="color:#0f766e;"></i>
                        <span class="wp-list-card-title activity-summary-title">
                            Activity ${activityIndex}
                        </span>
                    </span>

                    <span class="wp-list-card-sub activity-summary-meta">
                        Add activity details
                    </span>

                    <span class="wp-progress-wrap">
                        <span class="wp-progress-track">
                            <span
                                class="wp-progress-fill activity-progress-fill"
                                style="width:0%;"
                            ></span>
                        </span>
                        <span class="wp-progress-pct activity-progress-pct">
                            0%
                        </span>
                    </span>
                </span>

                <span class="wp-list-card-side">
                    <span class="wp-mini-status activity-summary-status">
                        Pending
                    </span>
                    <i class="fas fa-chevron-down wp-list-chevron"></i>
                </span>
            </button>

            <div class="wp-list-card-body">
                <div style="display:flex;justify-content:flex-end;margin-bottom:9px;">
                    <button type="button" class="remove-btn" onclick="wpRemoveActivity(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>

                <div class="activity-fields-grid">
                <div class="form-group">
                    <label class="form-label">Activity / Deliverable Title</label>
                    <input type="text" class="form-control a-title" placeholder="e.g. Finalize curriculum">
                </div>
                <div class="form-group">
                    <label class="form-label">Actor(s)</label>
                    <input type="text" class="form-control a-actor" placeholder="e.g. Bruno/Bashir">
                </div>
                <div class="form-group">
                    <label class="form-label">Calendar Frequency</label>
                    <select class="form-control a-frequency">
                        ${optionsHtml(CALENDAR_FREQUENCIES, 'Monthly')}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control a-start">
                </div>
                <div class="form-group">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control a-end">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control a-status">
                        ${optionsHtml(DELIVERABLE_STATUSES, 'Pending')}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Progress (%)</label>
                    <input type="number" class="form-control a-progress" min="0" max="100" value="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea class="form-control a-notes" rows="1" placeholder="Optional notes"></textarea>
            </div>

            <div class="monthly-toggle">
                <button type="button" class="btn btn-sm btn-gray" onclick="wpToggleMonthly(this)">
                    <i class="fas fa-calendar-alt"></i> Set Monthly Status
                </button>
            </div>
            <div class="monthly-grid-wrap">
                <div class="monthly-grid">
                    ${monthCells}
                </div>
            </div>
        </div>
        `;

        container.appendChild(row);
        refreshActivitiesHint(block);

        if (prefill) {
            row.querySelector('.a-title').value = prefill.title || '';
            row.querySelector('.a-actor').value = prefill.responsible_person || '';
            row.querySelector('.a-frequency').value = prefill.calendar_frequency || 'Monthly';
            row.querySelector('.a-start').value = prefill.start_date || '';
            row.querySelector('.a-end').value = prefill.end_date || '';
            row.querySelector('.a-status').value = prefill.status || 'Pending';
            row.querySelector('.a-progress').value = prefill.progress_percentage || 0;
            row.querySelector('.a-notes').value = prefill.notes || '';
        }

        wpRefreshActivitySummary(row);

        const milestoneCard = row.closest('.wp-milestone-list-card');
        if (milestoneCard) {
            wpRefreshMilestoneSummary(milestoneCard);
        }

        return row;
    };

    window.wpRemoveActivity = function (btn) {
        const row = btn.closest('.activity-row');
        const block = btn.closest('.activities-block');
        const milestoneCard = btn.closest('.wp-milestone-list-card');

        row.remove();

        refreshActivitiesHint(block);

        if (milestoneCard) {
            wpRefreshMilestoneSummary(milestoneCard);
        }
    };

    window.wpToggleMonthly = function (btn) {
        const wrap = btn.closest('.activity-row').querySelector('.monthly-grid-wrap');
        wrap.classList.toggle('open');
    };

    function showAlert(message, type) {
        const el = document.getElementById('workplanAlert');
        el.className = 'badge ' + (type === 'success' ? 'badge-available' : 'badge-overdue');
        el.style.display = 'inline-block';
        el.style.padding = '10px 14px';
        el.style.fontSize = '13px';
        el.textContent = message;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function collectPayload() {
        const entityType = document.getElementById('entityType').value;
        const entityId = entityType === 'Program'
            ? (document.getElementById('entityIdProgram') ? document.getElementById('entityIdProgram').value : '')
            : document.getElementById('entityIdProject').value;
        const workplanYear = document.getElementById('workplanYear').value;

        if (!entityId) {
            showAlert('Please select a ' + entityType.toLowerCase() + ' before saving.', 'danger');
            return null;
        }

        const milestoneCards = document.querySelectorAll('.milestone-card');

        if (milestoneCards.length === 0) {
            showAlert('Add at least one milestone before saving.', 'danger');
            return null;
        }

        const milestones = [];

        for (const card of milestoneCards) {
            const name = card.querySelector('.m-name').value.trim();

            if (!name) {
                showAlert('Every milestone needs a name.', 'danger');
                return null;
            }

            const activities = [];
            const activityRows = card.querySelectorAll('.activity-row');

            for (const row of activityRows) {
                const title = row.querySelector('.a-title').value.trim();

                if (!title) {
                    continue; // skip blank rows silently
                }

                const monthlyStatus = {};
                row.querySelectorAll('.month-cell').forEach(function (cell) {
                    const select = cell.querySelector('.month-status-select');
                    const comment = cell.querySelector('.month-comment-input');
                    monthlyStatus[select.dataset.month] = {
                        status: select.value,
                        comment: comment.value.trim()
                    };
                });

                activities.push({
                    title: title,
                    responsible_person: row.querySelector('.a-actor').value.trim(),
                    calendar_frequency: row.querySelector('.a-frequency').value,
                    start_date: row.querySelector('.a-start').value || null,
                    end_date: row.querySelector('.a-end').value || null,
                    status: row.querySelector('.a-status').value,
                    progress_percentage: parseFloat(row.querySelector('.a-progress').value || '0'),
                    notes: row.querySelector('.a-notes').value.trim(),
                    monthly_status: monthlyStatus
                });
            }

            milestones.push({
                milestone_name: name,
                milestone_description: card.querySelector('.m-description').value.trim(),
                milestone_type: card.querySelector('.m-type').value,
                calendar_frequency: card.querySelector('.m-frequency').value,
                start_date: card.querySelector('.m-start').value || null,
                due_date: card.querySelector('.m-due').value || null,
                responsible_person: card.querySelector('.m-responsible').value.trim(),
                deliverables: activities
            });
        }

        const payload = {
            entity_type: entityType,
            entity_id: entityId,
            workplan_year: workplanYear,
            milestones: milestones
        };

        if (EXISTING_WORKPLAN_ID) {
            payload.update_workplan = 1;
            payload.workplan_id = EXISTING_WORKPLAN_ID;
        } else {
            payload.create_workplan = 1;
        }

        return payload;
    }

    window.wpSaveWorkplan = function () {
        const payload = collectPayload();

        if (!payload) {
            return;
        }

        const btn = document.getElementById('saveWorkplanBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        function resetButton() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Workplan';
        }


        fetch('actions/workplan-actions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
            .then(function (res) {

                return res.text().then(function (text) {
                    return { status: res.status, ok: res.ok, text: text };
                });
            })
            .then(function (result) {
                resetButton();

                let data = null;
                try {
                    data = JSON.parse(result.text);
                } catch (parseErr) {
                    console.error('Workplan save: non-JSON response', result.status, result.text);
                    const preview = result.text.replace(/<[^>]*>/g, ' ').trim().slice(0, 200);
                    showAlert(
                        'The server did not return a valid response (HTTP ' + result.status + ').'
                        + (preview ? ' Details: ' + preview : ' Check the browser console and server error log for details.'),
                        'danger'
                    );
                    return;
                }

                if (data && data.success) {
                    showAlert(data.message || 'Workplan saved successfully.', 'success');
                    setTimeout(function () {
                        window.location.href = 'workplan?workplan_id=' + encodeURIComponent(data.workplan_id);
                    }, 900);
                } else {
                    showAlert((data && data.message) || 'Could not save workplan.', 'danger');
                }
            })
            .catch(function (err) {

                console.error('Workplan save: network error', err);
                resetButton();
                showAlert('Could not reach the server. Check your connection and try again.', 'danger');
            });
    };



    /* ============================================================
       CALENDAR
    ============================================================ */
    let wpCalendarEvents = Array.isArray(CALENDAR_EVENTS) ? CALENDAR_EVENTS.slice() : [];
    let wpCalendarView = 'month';
    let wpCalendarFilter = 'all';

    const wpCalendarYearSelect = document.getElementById('wpCalendarYear');
    const wpCalendarTabBadge = document.getElementById('wpCalendarTabBadge');

    const wpInitialCalendarYear = wpCalendarYearSelect
        ? Number(wpCalendarYearSelect.value)
        : (
            EXISTING_WORKPLAN && EXISTING_WORKPLAN.workplan_year
                ? Number(EXISTING_WORKPLAN.workplan_year)
                : new Date().getFullYear()
        );

    let wpCalendarAnchor = new Date(wpInitialCalendarYear, 0, 1);

    function wpEsc(v) {
        return String(v ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function wpDate(v) {
        if (!v) return null;
        const p = String(v).split('-').map(Number);
        return p.length === 3 ? new Date(p[0], p[1] - 1, p[2]) : null;
    }

    function wpYmd(d) {
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    function wpColor(status) {
        return ({
            'Pending':'#94a3b8','Planned':'#94a3b8',
            'In Progress':'#3b82f6','Started':'#3b82f6',
            'Ongoing':'#f59e0b','Modified':'#8b5cf6',
            'Delayed':'#ef4444','Overdue':'#ef4444',
            'Cancelled':'#64748b','Completed':'#22c55e'
        })[status] || '#64748b';
    }

    function wpOccurs(event, d) {
        const s = wpDate(event.start);
        const e = wpDate(event.end || event.start);
        if (!s || !e) return false;
        const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        return x >= s && x <= e;
    }

    function wpEvents() {
        return wpCalendarFilter === 'all'
            ? wpCalendarEvents
            : wpCalendarEvents.filter(e => e.kind === wpCalendarFilter);
    }

    function wpEventOverlapsYear(event, year) {
        const start = wpDate(event.start);
        const end = wpDate(event.end || event.start);

        if (!start || !end) {
            return false;
        }

        const yearStart = new Date(year, 0, 1);
        const yearEnd = new Date(year, 11, 31, 23, 59, 59);

        return start <= yearEnd && end >= yearStart;
    }

    function wpUpdateCalendarBadge() {
        if (!wpCalendarTabBadge) {
            return;
        }

        const year = wpCalendarAnchor.getFullYear();

        const visibleYearCount = wpEvents().filter(function (event) {
            return wpEventOverlapsYear(event, year);
        }).length;

        wpCalendarTabBadge.textContent = String(visibleYearCount);
    }

    function wpSyncYearSelect() {
        if (!wpCalendarYearSelect) {
            return;
        }

        const year = wpCalendarAnchor.getFullYear();

        const exists = Array.from(wpCalendarYearSelect.options).some(function (option) {
            return Number(option.value) === year;
        });

        if (!exists) {
            const option = document.createElement('option');
            option.value = String(year);
            option.textContent = String(year);
            wpCalendarYearSelect.appendChild(option);
        }

        wpCalendarYearSelect.value = String(year);
    }

    function wpEventHtml(event) {
        return `<button type="button" class="wp-event"
            style="--event-color:${wpColor(event.status)}"
            data-wp-event-id="${wpEsc(event.id)}">
            <span class="wp-event-kind">${wpEsc(event.kind)}</span>
            ${wpEsc(event.title)}
        </button>`;
    }

    function wpBindEvents() {
        document.querySelectorAll('[data-wp-event-id]').forEach(el => {
            el.addEventListener('click', () => {
                const event = wpCalendarEvents.find(x => x.id === el.dataset.wpEventId);
                if (event) wpOpenStatus(event);
            });
        });
    }

    function wpRenderMonth() {
        const body = document.getElementById('wpCalendarBody');
        const title = document.getElementById('wpCalendarTitle');
        if (!body || !title) return;

        const year = wpCalendarAnchor.getFullYear();
        const month = wpCalendarAnchor.getMonth();
        const first = new Date(year, month, 1);
        const start = new Date(first);
        start.setDate(start.getDate() - ((start.getDay() + 6) % 7));

        title.textContent = new Intl.DateTimeFormat('en-GB', {
            month:'long', year:'numeric'
        }).format(first);

        let html = '<div class="wp-month-grid">';
        ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(day => {
            html += '<div class="wp-weekday">' + day + '</div>';
        });

        const events = wpEvents();
        const today = wpYmd(new Date());

        for (let i = 0; i < 42; i++) {
            const d = new Date(start);
            d.setDate(start.getDate() + i);
            const dayEvents = events.filter(e => wpOccurs(e, d));

            html += `<div class="wp-day-cell ${d.getMonth() !== month ? 'outside' : ''} ${wpYmd(d) === today ? 'today' : ''}">
                <div class="wp-day-head"><span class="wp-day-number">${d.getDate()}</span></div>`;

            dayEvents.slice(0, 4).forEach(e => html += wpEventHtml(e));

            if (dayEvents.length > 4) {
                html += `<button type="button" class="wp-more" data-wp-more="${wpYmd(d)}">+${dayEvents.length - 4} more</button>`;
            }

            html += '</div>';
        }

        html += '</div>';
        body.innerHTML = html;
        wpBindEvents();

        document.querySelectorAll('[data-wp-more]').forEach(btn => {
            btn.addEventListener('click', () => {
                wpCalendarAnchor = wpDate(btn.dataset.wpMore) || wpCalendarAnchor;
                wpCalendarView = 'day';
                wpSyncViewButtons();
                wpRenderCalendar();
            });
        });
    }

    function wpRenderAgenda(days) {
        const body = document.getElementById('wpCalendarBody');
        const title = document.getElementById('wpCalendarTitle');
        if (!body || !title) return;

        const start = new Date(wpCalendarAnchor);
        if (days === 7) start.setDate(start.getDate() - ((start.getDay() + 6) % 7));

        const end = new Date(start);
        end.setDate(start.getDate() + days - 1);

        const fmt = new Intl.DateTimeFormat('en-GB', {
            day:'2-digit', month:'short', year:'numeric'
        });

        title.textContent = days === 1 ? fmt.format(start) : fmt.format(start) + ' - ' + fmt.format(end);

        let html = '';
        const events = wpEvents();

        for (let i = 0; i < days; i++) {
            const d = new Date(start);
            d.setDate(start.getDate() + i);
            const dayEvents = events.filter(e => wpOccurs(e, d));

            html += `<section class="wp-agenda-day">
                <div class="wp-agenda-day-head">
                    <span>${new Intl.DateTimeFormat('en-GB',{weekday:'long',day:'2-digit',month:'short'}).format(d)}</span>
                    <span>${dayEvents.length} item${dayEvents.length === 1 ? '' : 's'}</span>
                </div><div class="wp-agenda-items">`;

            if (!dayEvents.length) {
                html += '<div style="color:#94a3b8;font-size:10.5px;padding:7px 4px;">No milestones or activities.</div>';
            }

            dayEvents.forEach(e => {
                const c = wpColor(e.status);
                html += `<div class="wp-agenda-event" style="--event-color:${c}" data-wp-event-id="${wpEsc(e.id)}">
                    <div style="font-size:10px;color:#64748b;font-weight:700;">${wpEsc(e.kind)}<br>${wpEsc(e.frequency || '')}</div>
                    <div><div class="wp-agenda-title">${wpEsc(e.title)}</div>
                    <div class="wp-agenda-meta">${wpEsc(e.milestone_name || e.responsible || '')}</div></div>
                    <span class="wp-agenda-status" style="background:${c}">${wpEsc(e.status)}</span>
                </div>`;
            });

            html += '</div></section>';
        }

        body.innerHTML = html;
        wpBindEvents();
    }

    function wpRenderPeriod(yearView) {
        const body = document.getElementById('wpCalendarBody');
        const title = document.getElementById('wpCalendarTitle');
        if (!body || !title) return;

        const year = wpCalendarAnchor.getFullYear();
        const startMonth = yearView ? 0 : Math.floor(wpCalendarAnchor.getMonth() / 3) * 3;
        const count = yearView ? 12 : 3;

        title.textContent = yearView ? String(year) : 'Q' + (Math.floor(startMonth / 3) + 1) + ' ' + year;

        let html = '<div class="wp-period-grid">';
        const events = wpEvents();

        for (let i = 0; i < count; i++) {
            const m = startMonth + i;
            const ms = new Date(year, m, 1);
            const me = new Date(year, m + 1, 0);
            const monthEvents = events.filter(e => {
                const s = wpDate(e.start), end = wpDate(e.end || e.start);
                return s && end && s <= me && end >= ms;
            });

            html += `<section class="wp-period-card"><h4>${new Intl.DateTimeFormat('en-GB',{month:'long'}).format(ms)}
                <span style="color:#94a3b8;font-size:9px;margin-left:4px">${monthEvents.length}</span></h4>`;

            monthEvents.slice(0, 8).forEach(e => {
                html += `<div class="wp-period-event" style="--event-color:${wpColor(e.status)}"
                    data-wp-event-id="${wpEsc(e.id)}">${wpEsc(e.title)}</div>`;
            });

            if (monthEvents.length > 8) {
                html += '<div style="color:#0f766e;font-size:9.5px;font-weight:800">+' + (monthEvents.length - 8) + ' more</div>';
            }

            html += '</section>';
        }

        html += '</div>';

        const selectedYearCount = events.filter(function (event) {
            return wpEventOverlapsYear(event, year);
        }).length;

        if (selectedYearCount === 0 && events.length > 0) {
            html += '<div style="margin:12px 14px 14px;padding:10px 12px;border:1px solid #fed7aa;border-radius:8px;background:#fff7ed;color:#9a3412;font-size:11px;font-weight:700;">'
                + 'No calendar items fall within ' + year
                + '. Select another year from the year dropdown to view the '
                + events.length + ' saved calendar item' + (events.length === 1 ? '' : 's') + '.'
                + '</div>';
        }

        body.innerHTML = html;
        wpBindEvents();
    }

    function wpRenderCalendar() {
        wpSyncYearSelect();
        wpUpdateCalendarBadge();

        switch (wpCalendarView) {
            case 'day': return wpRenderAgenda(1);
            case 'week': return wpRenderAgenda(7);
            case 'quarter': return wpRenderPeriod(false);
            case 'year': return wpRenderPeriod(true);
            default: return wpRenderMonth();
        }
    }

    function wpSyncViewButtons() {
        document.querySelectorAll('[data-calendar-view]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.calendarView === wpCalendarView);
        });
    }

    function wpMoveCalendar(direction) {
        if (wpCalendarView === 'day') wpCalendarAnchor.setDate(wpCalendarAnchor.getDate() + direction);
        else if (wpCalendarView === 'week') wpCalendarAnchor.setDate(wpCalendarAnchor.getDate() + direction * 7);
        else if (wpCalendarView === 'quarter') wpCalendarAnchor.setMonth(wpCalendarAnchor.getMonth() + direction * 3);
        else if (wpCalendarView === 'year') wpCalendarAnchor.setFullYear(wpCalendarAnchor.getFullYear() + direction);
        else wpCalendarAnchor.setMonth(wpCalendarAnchor.getMonth() + direction);

        wpRenderCalendar();
    }

    document.querySelectorAll('[data-calendar-view]').forEach(btn => {
        btn.addEventListener('click', () => {
            wpCalendarView = btn.dataset.calendarView;
            wpSyncViewButtons();
            wpRenderCalendar();
        });
    });

    document.querySelectorAll('[data-item-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            wpCalendarFilter = btn.dataset.itemFilter;
            document.querySelectorAll('[data-item-filter]').forEach(other => {
                other.classList.toggle('active', other === btn);
            });
            wpRenderCalendar();
        });
    });

    wpCalendarYearSelect?.addEventListener('change', function () {
        const selectedYear = Number(this.value);

        if (!selectedYear) {
            return;
        }

        wpCalendarAnchor.setFullYear(selectedYear);

        /*
         * Annual view always starts at January.
         * Other views keep their current month/day when switching years.
         */
        if (wpCalendarView === 'year') {
            wpCalendarAnchor.setMonth(0, 1);
        }

        wpRenderCalendar();
    });

    document.getElementById('wpPrevBtn')?.addEventListener('click', () => wpMoveCalendar(-1));
    document.getElementById('wpNextBtn')?.addEventListener('click', () => wpMoveCalendar(1));
    document.getElementById('wpTodayBtn')?.addEventListener('click', () => {
        wpCalendarAnchor = new Date();
        wpRenderCalendar();
    });

    function wpOpenStatus(event) {
        const modal = document.getElementById('wpCalendarStatusModal');
        if (!modal) return;

        document.getElementById('wpCalendarRecordId').value = event.record_id;
        document.getElementById('wpCalendarKind').value = event.kind;
        document.getElementById('wpCalendarStatus').value = event.status || 'Pending';
        document.getElementById('wpCalendarProgress').value = Number(event.progress || 0);
        document.getElementById('wpCalendarFrequency').value = event.frequency || (event.kind === 'milestone' ? 'Annual' : 'Monthly');
        document.getElementById('wpCalendarComment').value = '';
        document.getElementById('wpCalendarModalTitle').textContent = event.title;
        document.getElementById('wpCalendarModalMeta').textContent =
            event.kind.charAt(0).toUpperCase() + event.kind.slice(1)
            + ' . ' + event.start
            + ((event.end && event.end !== event.start) ? ' to ' + event.end : '')
            + (event.responsible ? ' . ' + event.responsible : '');

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function wpCloseStatus() {
        document.getElementById('wpCalendarStatusModal')?.classList.remove('open');
        document.body.style.overflow = '';
    }

    document.getElementById('wpCalendarModalClose')?.addEventListener('click', wpCloseStatus);
    document.getElementById('wpCalendarModalCancel')?.addEventListener('click', wpCloseStatus);
    document.getElementById('wpCalendarStatusModal')?.addEventListener('click', e => {
        if (e.target.id === 'wpCalendarStatusModal') wpCloseStatus();
    });

    document.getElementById('wpCalendarStatusForm')?.addEventListener('submit', function (e) {
        e.preventDefault();

        const saveBtn = document.getElementById('wpCalendarStatusSave');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

        fetch('actions/workplan-calendar-status.php', {
            method:'POST',
            credentials:'same-origin',
            body:new FormData(this)
        })
        .then(r => r.json())
        .then(data => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Update Status';

            if (!data.success) {
                alert(data.message || 'Could not update status.');
                return;
            }

            const id = Number(document.getElementById('wpCalendarRecordId').value);
            const kind = document.getElementById('wpCalendarKind').value;
            const current = wpCalendarEvents.find(x => Number(x.record_id) === id && x.kind === kind);

            if (current) {
                current.status = document.getElementById('wpCalendarStatus').value;
                current.progress = Number(document.getElementById('wpCalendarProgress').value || 0);
                current.frequency = document.getElementById('wpCalendarFrequency').value;
            }

            wpCloseStatus();
            wpRenderCalendar();
            showAlert(data.message || 'Status updated successfully.', 'success');
        })
        .catch(err => {
            console.error(err);
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Update Status';
            alert('Could not reach the server.');
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (EXISTING_WORKPLAN && EXISTING_WORKPLAN.milestones && EXISTING_WORKPLAN.milestones.length) {

            EXISTING_WORKPLAN.milestones.forEach(function (m) {
                window.wpAddMilestone(m);
            });

            milestoneCurrentPage = 1;
            wpRenderMilestonePagination();

        } else {

            wpAddMilestone();
        }

        wpToggleEntity();
        wpRenderCalendar();
    });
})();
</script>

<?php include 'includes/footer.php'; ?>