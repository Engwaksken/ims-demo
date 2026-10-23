<?php
$page_title = 'Indicator Reports & Analytics';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Get filters
$project_filter = isset($_GET['project']) ? intval($_GET['project']) : 0;
$type_filter = isset($_GET['type']) ? sanitize_input($_GET['type']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_input($_GET['date_from']) : date('Y-m-d', strtotime('-6 months'));
$date_to = isset($_GET['date_to']) ? sanitize_input($_GET['date_to']) : date('Y-m-d');

// Dates are interpolated into SQL below: accept strict YYYY-MM-DD only.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-6 months'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}

// Build WHERE clause
$where = "1=1";
if ($project_filter) {
    $where .= " AND i.project_id = $project_filter";
}
if ($type_filter) {
    $where .= " AND i.indicator_type = '" . $conn->real_escape_string($type_filter) . "'";
}

// Fetch all indicators with achievement data
$indicators = [];
$query = "SELECT i.*, p.project_name, p.project_code, p.status as project_status,
    CASE WHEN i.target_value > 0 THEN (i.current_value / i.target_value * 100) ELSE 0 END as achievement_percentage,
    (SELECT COUNT(*) FROM indicator_progress ip WHERE ip.indicator_id = i.indicator_id) as total_updates,
    (SELECT COUNT(*) FROM indicator_progress ip WHERE ip.indicator_id = i.indicator_id 
     AND ip.recorded_date BETWEEN '$date_from' AND '$date_to') as period_updates,
    (SELECT MAX(recorded_date) FROM indicator_progress ip WHERE ip.indicator_id = i.indicator_id) as last_update_date
    FROM indicators i
    JOIN projects p ON i.project_id = p.project_id
    WHERE $where
    ORDER BY p.project_name, i.indicator_type, i.indicator_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $indicators[] = $row;
}

// Calculate statistics
$stats = [
    'total' => count($indicators),
    'outputs' => count(array_filter($indicators, function($i) { return $i['indicator_type'] == 'Output'; })),
    'outcomes' => count(array_filter($indicators, function($i) { return $i['indicator_type'] == 'Outcome'; })),
    'impacts' => count(array_filter($indicators, function($i) { return $i['indicator_type'] == 'Impact'; })),
    'achieved' => count(array_filter($indicators, function($i) { return $i['achievement_percentage'] >= 100; })),
    'on_track' => count(array_filter($indicators, function($i) { return $i['achievement_percentage'] >= 50 && $i['achievement_percentage'] < 100; })),
    'below_target' => count(array_filter($indicators, function($i) { return $i['achievement_percentage'] < 50; })),
    'no_progress' => count(array_filter($indicators, function($i) { return $i['current_value'] == $i['baseline_value']; }))
];

// Calculate average achievement by type
$avg_by_type = [];
foreach (['Output', 'Outcome', 'Impact'] as $type) {
    $type_indicators = array_filter($indicators, function($i) use ($type) { return $i['indicator_type'] == $type; });
    if (!empty($type_indicators)) {
        $avg_by_type[$type] = array_sum(array_column($type_indicators, 'achievement_percentage')) / count($type_indicators);
    } else {
        $avg_by_type[$type] = 0;
    }
}

// Get projects for filter
$projects = [];
$result = $conn->query("SELECT project_id, project_name, project_code FROM projects ORDER BY project_name");
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}

// Get indicators with progress over time (for trend analysis)
$trend_data = [];
if (!empty($indicators)) {
    foreach (array_slice($indicators, 0, 5) as $indicator) {
        $progress = [];
        $query = "SELECT reporting_period, actual_value, achievement_percentage, recorded_date 
            FROM indicator_progress 
            WHERE indicator_id = {$indicator['indicator_id']} 
            AND recorded_date BETWEEN '$date_from' AND '$date_to'
            ORDER BY recorded_date";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
        if (!empty($progress)) {
            $trend_data[] = [
                'indicator' => $indicator,
                'progress' => $progress
            ];
        }
    }
}

// Top and bottom performers
$sorted_by_achievement = $indicators;
usort($sorted_by_achievement, function($a, $b) {
    return $b['achievement_percentage'] <=> $a['achievement_percentage'];
});
$top_performers = array_slice($sorted_by_achievement, 0, 5);
$bottom_performers = array_slice(array_reverse($sorted_by_achievement), 0, 5);

// Indicators needing attention (no updates in last 30 days)
$stale_indicators = array_filter($indicators, function($i) {
    if (!$i['last_update_date']) return true;
    $days_since_update = (strtotime('now') - strtotime($i['last_update_date'])) / (60 * 60 * 24);
    return $days_since_update > 30;
});
?>

<style>
/* Indicator performance report: page-only layout (rpi- prefix). Header,
   cards, stat cards, badges, buttons, tables and alerts are global. */
.rpi-stats-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }

.rpi-type-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-5); }
.rpi-type-box { padding: var(--space-5); background: var(--ink-50); border-radius: var(--radius-sm); }
.rpi-type-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.rpi-big-pct { font-size: 24px; font-weight: 800; }
.rpi-hint { margin-top: 10px; color: var(--ink-300); font-size: var(--fs-base); }

.rpi-row {
    padding: 15px;
    margin-bottom: 10px;
    background: var(--ink-50);
    border-radius: var(--radius-sm);
    border-left: 4px solid var(--ink-100);
}
.rpi-row:last-child { margin-bottom: 0; }
.rpi-row.good { border-left-color: var(--green-fg); }
.rpi-row.bad  { border-left-color: var(--red-fg); }
.rpi-row-head { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--space-3); margin-bottom: 10px; }
.rpi-row-main { flex: 1; min-width: 0; }
.rpi-row-side { min-width: 120px; text-align: right; }
.rpi-good { color: var(--green-fg); }
.rpi-bad  { color: var(--red-fg); }

.rpi-track { height: 20px; overflow: hidden; border-radius: var(--radius-pill); background: var(--ink-100); }
.rpi-fill {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    background: var(--brand-500);
    color: var(--ink-0);
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    transition: width .3s;
}
.rpi-fill.bad { background: var(--red-fg); }

.rpi-stale {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
    padding: 10px;
    margin-bottom: 8px;
    background: var(--amber-bg);
    border-radius: var(--radius-sm);
}
.rpi-stale small { color: var(--amber-fg); }

.rpi-trend { margin-bottom: 40px; }
.rpi-trend:last-child { margin-bottom: 0; }
.rpi-trend h4 { margin: 0 0 4px; }
.rpi-trend canvas { max-height: 200px; margin-top: 15px; }
.rpi-chart { max-height: 300px; }

.rpi-ach-cell { min-width: 100px; }
.rpi-ach-pct { font-weight: 700; }
.progress-mini {
    height: 8px;
    margin: 5px 0;
    overflow: hidden;
    border-radius: 4px;
    background: var(--ink-100);
}
.progress-mini-bar { height: 100%; transition: width 0.3s; }

@media (max-width: 1100px) {
    .rpi-stats-5 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
    .rpi-stats-5 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .rpi-row-head { flex-direction: column; }
    .rpi-row-side { text-align: left; }
}

@media print {
    .no-print { display: none !important; }
    .card { page-break-inside: avoid; }
}
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-chart-line" aria-hidden="true"></i> Indicator Performance Analytics</h1>
        <p>
            Period: <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?>
            &middot; Generated: <?php echo date('d M Y H:i'); ?> by <?php echo htmlspecialchars($_SESSION['full_name']); ?>
        </p>
    </div>
    <div class="hero-actions no-print">
        <button type="button" onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
        <button type="button" onclick="exportToExcel()" class="btn btn-primary">
            <i class="fas fa-file-excel" aria-hidden="true"></i> Export Excel
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card no-print">
    <div class="card-header">
        <h3><i class="fas fa-filter" aria-hidden="true"></i> Report Filters</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="filters-bar">
            <div class="form-group">
                <label>Project</label>
                <select name="project" class="form-control">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['project_id']; ?>" <?php echo $project_filter == $project['project_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['project_code'] . ' - ' . $project['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Indicator Type</label>
                <select name="type" class="form-control">
                    <option value="">All Types</option>
                    <option value="Output" <?php echo $type_filter == 'Output' ? 'selected' : ''; ?>>Output</option>
                    <option value="Outcome" <?php echo $type_filter == 'Outcome' ? 'selected' : ''; ?>>Outcome</option>
                    <option value="Impact" <?php echo $type_filter == 'Impact' ? 'selected' : ''; ?>>Impact</option>
                </select>
            </div>

            <div class="form-group">
                <label>Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
            </div>

            <div class="form-group">
                <label>Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
                <a href="report-indicators" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Key Performance Metrics -->
<div class="stats-grid reports-stats rpi-stats-5">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total Indicators</div>
            <div class="stat-value"><?php echo $stats['total']; ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Achieved (≥100%)</div>
            <div class="stat-value"><?php echo $stats['achieved']; ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-chart-bar" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">On Track (50-99%)</div>
            <div class="stat-value"><?php echo $stats['on_track']; ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-red"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Below Target (&lt;50%)</div>
            <div class="stat-value"><?php echo $stats['below_target']; ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-slate"><i class="fas fa-pause-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">No Progress</div>
            <div class="stat-value"><?php echo $stats['no_progress']; ?></div>
        </div>
    </div>
</div>

<!-- Achievement by Type -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-layer-group" aria-hidden="true"></i> Achievement by Indicator Type</h3>
    </div>
    <div class="card-body">
        <div class="rpi-type-grid">
            <?php foreach (['Output', 'Outcome', 'Impact'] as $type): ?>
                <div class="rpi-type-box">
                    <div class="rpi-type-head">
                        <strong><?php echo $type; ?>s</strong>
                        <span class="rpi-big-pct" style="color: <?php
                            echo $avg_by_type[$type] >= 75 ? 'var(--green-fg)' : ($avg_by_type[$type] >= 50 ? 'var(--amber-fg)' : 'var(--red-fg)');
                        ?>;">
                            <?php echo number_format($avg_by_type[$type], 1); ?>%
                        </span>
                    </div>
                    <div class="rpi-track">
                        <div class="rpi-fill" style="width: <?php echo min($avg_by_type[$type], 100); ?>%">
                            <?php echo number_format($avg_by_type[$type], 1); ?>%
                        </div>
                    </div>
                    <div class="rpi-hint">
                        <?php echo $stats[strtolower($type) . 's']; ?> indicators
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Achievement Distribution Chart -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-chart-pie" aria-hidden="true"></i> Achievement Distribution</h3>
    </div>
    <div class="card-body">
        <canvas id="achievementChart" class="rpi-chart"></canvas>
    </div>
</div>

<!-- Top Performers -->
<?php if (!empty($top_performers)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-trophy" aria-hidden="true"></i> Top Performing Indicators</h3>
    </div>
    <div class="card-body">
        <?php foreach ($top_performers as $indicator): ?>
            <div class="rpi-row good">
                <div class="rpi-row-head">
                    <div class="rpi-row-main">
                        <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                        <span class="cell-muted">
                            <?php echo htmlspecialchars($indicator['project_code']); ?> |
                            <?php echo $indicator['indicator_type']; ?>
                            <?php if ($indicator['unit_of_measure']): ?>
                                | <?php echo htmlspecialchars($indicator['unit_of_measure']); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="rpi-row-side">
                        <div class="rpi-big-pct rpi-good">
                            <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                        </div>
                        <span class="cell-muted">
                            <?php echo number_format($indicator['current_value'], 2); ?> /
                            <?php echo number_format($indicator['target_value'], 2); ?>
                        </span>
                    </div>
                </div>
                <div class="rpi-track">
                    <div class="rpi-fill" style="width: <?php echo min($indicator['achievement_percentage'], 100); ?>%">
                        <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Indicators Needing Attention -->
<?php if (!empty($bottom_performers)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Indicators Needing Attention</h3>
    </div>
    <div class="card-body">
        <?php foreach ($bottom_performers as $indicator): ?>
            <div class="rpi-row bad">
                <div class="rpi-row-head">
                    <div class="rpi-row-main">
                        <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                        <span class="cell-muted">
                            <?php echo htmlspecialchars($indicator['project_code']); ?> |
                            <?php echo $indicator['indicator_type']; ?>
                            <?php if ($indicator['responsible_person']): ?>
                                | Responsible: <?php echo htmlspecialchars($indicator['responsible_person']); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="rpi-row-side">
                        <div class="rpi-big-pct rpi-bad">
                            <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                        </div>
                        <span class="cell-muted">
                            <?php echo number_format($indicator['current_value'], 2); ?> /
                            <?php echo number_format($indicator['target_value'], 2); ?>
                        </span>
                    </div>
                </div>
                <div class="rpi-track">
                    <div class="rpi-fill bad" style="width: <?php echo $indicator['achievement_percentage']; ?>%;">
                        <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Stale Indicators -->
<?php if (!empty($stale_indicators)): ?>
<div class="card no-print">
    <div class="card-header">
        <h3><i class="fas fa-clock" aria-hidden="true"></i> Indicators Without Recent Updates (30+ days)</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i>
            <span><strong><?php echo count($stale_indicators); ?></strong> indicator(s) haven't been updated in the last 30 days.
            Regular updates are essential for accurate progress tracking.</span>
        </div>
        <?php foreach (array_slice($stale_indicators, 0, 10) as $indicator): ?>
            <div class="rpi-stale">
                <div>
                    <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                    <br>
                    <small>
                        <?php echo htmlspecialchars($indicator['project_code']); ?>
                        <?php if ($indicator['last_update_date']): ?>
                            | Last updated: <?php echo date('d M Y', strtotime($indicator['last_update_date'])); ?>
                        <?php else: ?>
                            | Never updated
                        <?php endif; ?>
                    </small>
                </div>
                <a href="indicators?progress=<?php echo $indicator['indicator_id']; ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-plus"></i> Update
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Progress Trends -->
<?php if (!empty($trend_data)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-chart-area" aria-hidden="true"></i> Progress Trends (Top 5 Most Active)</h3>
    </div>
    <div class="card-body">
        <?php foreach ($trend_data as $trend): ?>
            <div class="rpi-trend">
                <h4><?php echo htmlspecialchars($trend['indicator']['indicator_name']); ?></h4>
                <span class="cell-muted">
                    <?php echo htmlspecialchars($trend['indicator']['project_name']); ?> |
                    <?php echo $trend['indicator']['indicator_type']; ?>
                </span>
                <canvas id="trendChart<?php echo $trend['indicator']['indicator_id']; ?>"></canvas>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Detailed Indicator List -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list" aria-hidden="true"></i> All Indicators (<?php echo count($indicators); ?>)</h3>
    </div>
    <div class="card-body">
    <div class="table-responsive">
        <table class="table data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Indicator</th>
                    <th>Type</th>
                    <th>Target</th>
                    <th>Current</th>
                    <th>Achievement</th>
                    <th>Updates</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($indicators)): ?>
                    <tr>
                        <td colspan="8"><div class="empty-state"><i class="fas fa-chart-line" aria-hidden="true"></i><p>No indicators found matching the criteria</p></div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($indicators as $indicator): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($indicator['project_code']); ?></strong>
                                <span class="cell-muted"><?php echo htmlspecialchars($indicator['project_name']); ?></span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                                <?php if ($indicator['unit_of_measure']): ?>
                                    <span class="cell-muted"><?php echo htmlspecialchars($indicator['unit_of_measure']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php
                                    echo $indicator['indicator_type'] == 'Output' ? 'info' :
                                        ($indicator['indicator_type'] == 'Outcome' ? 'warning' : 'secondary');
                                ?>">
                                    <?php echo $indicator['indicator_type']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($indicator['target_value'], 2); ?></td>
                            <td><strong><?php echo number_format($indicator['current_value'], 2); ?></strong></td>
                            <td>
                                <div class="rpi-ach-cell">
                                    <span class="rpi-ach-pct" style="color: <?php
                                        echo $indicator['achievement_percentage'] >= 100 ? 'var(--green-fg)' :
                                            ($indicator['achievement_percentage'] >= 50 ? 'var(--amber-fg)' : 'var(--red-fg)');
                                    ?>;">
                                        <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                                    </span>
                                    <div class="progress-mini">
                                        <div class="progress-mini-bar" style="width: <?php echo min($indicator['achievement_percentage'], 100); ?>%; background: <?php
                                            echo $indicator['achievement_percentage'] >= 100 ? 'var(--green-fg)' :
                                                ($indicator['achievement_percentage'] >= 50 ? 'var(--amber-fg)' : 'var(--red-fg)');
                                        ?>;"></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo $indicator['total_updates']; ?></td>
                            <td>
                                <?php if ($indicator['achievement_percentage'] >= 100): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle" aria-hidden="true"></i> Achieved</span>
                                <?php elseif ($indicator['achievement_percentage'] >= 50): ?>
                                    <span class="badge badge-warning"><i class="fas fa-chart-line" aria-hidden="true"></i> On Track</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Behind</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>

</div><!-- /.page-stack -->

<script>
// Chart colours come from the design tokens.
const rpiTok = function (name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); };

// Achievement Distribution Chart
const achievementCtx = document.getElementById('achievementChart');
if (achievementCtx) {
    new Chart(achievementCtx, {
        type: 'doughnut',
        data: {
            labels: ['Achieved (≥100%)', 'On Track (50-99%)', 'Below Target (<50%)', 'No Progress'],
            datasets: [{
                data: [<?php echo $stats['achieved']; ?>, <?php echo $stats['on_track']; ?>, <?php echo $stats['below_target']; ?>, <?php echo $stats['no_progress']; ?>],
                backgroundColor: [rpiTok('--green-fg'), rpiTok('--amber-fg'), rpiTok('--red-fg'), rpiTok('--ink-200')]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Progress Trend Charts
<?php foreach ($trend_data as $trend): ?>
    const trendCtx<?php echo $trend['indicator']['indicator_id']; ?> = document.getElementById('trendChart<?php echo $trend['indicator']['indicator_id']; ?>');
    if (trendCtx<?php echo $trend['indicator']['indicator_id']; ?>) {
        new Chart(trendCtx<?php echo $trend['indicator']['indicator_id']; ?>, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($trend['progress'], 'reporting_period')); ?>,
                datasets: [{
                    label: 'Actual Value',
                    data: <?php echo json_encode(array_column($trend['progress'], 'actual_value')); ?>,
                    borderColor: rpiTok('--blue-fg'),
                    backgroundColor: rpiTok('--blue-bg'),
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Target',
                    data: <?php echo json_encode(array_fill(0, count($trend['progress']), $trend['indicator']['target_value'])); ?>,
                    borderColor: rpiTok('--green-fg'),
                    borderDash: [5, 5],
                    tension: 0,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
<?php endforeach; ?>

function exportToExcel() {
    window.location.href = 'export?type=indicators<?php echo $project_filter ? "&project=" . (int)$project_filter : ""; ?><?php echo $type_filter ? "&indicator_type=" . h(rawurlencode($type_filter)) : ""; ?>';
}
</script>

<?php include 'includes/footer.php'; ?>