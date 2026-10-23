<?php
// Frontend - KPI Reports & Analytics
$page_title = 'KPI Reports & Analytics';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
include 'includes/header.php';

// Check role - HR or employee viewing own reports
$is_hr = ims_allowed('kpi_reports.view_all', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin'], 'See KPI reports for everyone (not just their own)');

// Get filter parameters
$filter_department = isset($_GET['department']) ? intval($_GET['department']) : 0;
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
// Reviewers default to "All Employees" (0), matching the dropdown.
$filter_user = $is_hr ? (int)($_GET['user'] ?? 0) : (int)$_SESSION['user_id'];

// If not HR, force filter to current user
if (!$is_hr) {
    $filter_user = $_SESSION['user_id'];
}

// Build WHERE clause
$where = "k.fiscal_year = $filter_year";
if ($filter_department) {
    $where .= " AND k.department_id = $filter_department";
}
if ($filter_user) {
    $where .= " AND k.user_id = $filter_user";
}

// Fetch all KPIs for analysis
$kpis = [];
$query = "SELECT k.*, c.category_name, c.category_code, d.department_name,
    u.full_name as owner_name, u.email as owner_email
    FROM kpis k
    LEFT JOIN kpi_categories c ON k.category_id = c.category_id
    LEFT JOIN departments d ON k.department_id = d.department_id
    LEFT JOIN users u ON k.user_id = u.user_id
    WHERE $where
    ORDER BY k.created_at DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $kpis[] = $row;
}

// Fetch departments for filter
$departments = [];
$result = $conn->query("SELECT * FROM departments ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $departments[] = $row;
}

// Fetch users for filter (HR only)
$users = [];
if ($is_hr) {
    $result = $conn->query("SELECT u.user_id, u.full_name, d.department_name 
        FROM users u 
        LEFT JOIN employee_directory e ON u.user_id = e.user_id 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        WHERE u.role NOT IN ('Donor/Partner') 
        ORDER BY u.full_name");
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Calculate comprehensive statistics
$total_kpis = count($kpis);

// Status breakdown
$status_counts = [
    'Draft' => 0,
    'Submitted' => 0,
    'Under Review' => 0,
    'Approved' => 0,
    'Rejected' => 0,
    'Completed' => 0
];
foreach ($kpis as $kpi) {
    if (isset($status_counts[$kpi['status']])) {
        $status_counts[$kpi['status']]++;
    }
}

// Category breakdown
$category_stats = [];
foreach ($kpis as $kpi) {
    if (!isset($category_stats[$kpi['category_name']])) {
        $category_stats[$kpi['category_name']] = [
            'count' => 0,
            'total_achievement' => 0,
            'completed' => 0
        ];
    }
    $category_stats[$kpi['category_name']]['count']++;
    if ($kpi['overall_achievement'] !== null) {
        $category_stats[$kpi['category_name']]['total_achievement'] += $kpi['overall_achievement'];
        $category_stats[$kpi['category_name']]['completed']++;
    }
}

// Calculate average achievement per category
foreach ($category_stats as $cat => &$stats) {
    $stats['avg_achievement'] = $stats['completed'] > 0 ? 
        round($stats['total_achievement'] / $stats['completed'], 1) : 0;
}

// Quarterly performance analysis
$quarterly_performance = [
    'Q1' => ['total' => 0, 'completed' => 0, 'total_achievement' => 0],
    'Q2' => ['total' => 0, 'completed' => 0, 'total_achievement' => 0],
    'Q3' => ['total' => 0, 'completed' => 0, 'total_achievement' => 0],
    'Q4' => ['total' => 0, 'completed' => 0, 'total_achievement' => 0]
];

foreach ($kpis as $kpi) {
    foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
        $q_upper = strtoupper($q);
        if ($kpi["{$q}_target"] > 0) {
            $quarterly_performance[$q_upper]['total']++;
            if ($kpi["{$q}_achievement"] !== null) {
                $quarterly_performance[$q_upper]['completed']++;
                $quarterly_performance[$q_upper]['total_achievement'] += $kpi["{$q}_achievement"];
            }
        }
    }
}

// Calculate quarterly averages
foreach ($quarterly_performance as $q => &$perf) {
    $perf['avg_achievement'] = $perf['completed'] > 0 ? 
        round($perf['total_achievement'] / $perf['completed'], 1) : 0;
    $perf['completion_rate'] = $perf['total'] > 0 ? 
        round(($perf['completed'] / $perf['total']) * 100, 1) : 0;
}

// Overall achievement
$kpis_with_achievement = array_filter($kpis, fn($k) => $k['overall_achievement'] !== null);
$overall_avg_achievement = !empty($kpis_with_achievement) ? 
    round(array_sum(array_column($kpis_with_achievement, 'overall_achievement')) / count($kpis_with_achievement), 1) : 0;

// Target vs Actual analysis
$target_actual_data = [];
foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q) {
    $q_lower = strtolower($q);
    $total_target = 0;
    $total_actual = 0;
    $count = 0;
    
    foreach ($kpis as $kpi) {
        if ($kpi["{$q_lower}_target"] > 0 && $kpi["{$q_lower}_actual"] !== null) {
            $total_target += $kpi["{$q_lower}_target"];
            $total_actual += $kpi["{$q_lower}_actual"];
            $count++;
        }
    }
    
    $target_actual_data[$q] = [
        'target' => $count > 0 ? round($total_target / $count, 1) : 0,
        'actual' => $count > 0 ? round($total_actual / $count, 1) : 0
    ];
}

// Weight distribution
$total_weight = array_sum(array_column($kpis, 'weight_percentage'));
$high_weight = count(array_filter($kpis, fn($k) => $k['weight_percentage'] >= 30));
$medium_weight = count(array_filter($kpis, fn($k) => $k['weight_percentage'] >= 15 && $k['weight_percentage'] < 30));
$low_weight = count(array_filter($kpis, fn($k) => $k['weight_percentage'] < 15));

// Top performers (highest achievement)
$top_performers = $kpis_with_achievement;
usort($top_performers, fn($a, $b) => $b['overall_achievement'] <=> $a['overall_achievement']);
$top_performers = array_slice($top_performers, 0, 5);

// Needs attention (lowest achievement)
$needs_attention = $kpis_with_achievement;
usort($needs_attention, fn($a, $b) => $a['overall_achievement'] <=> $b['overall_achievement']);
$needs_attention = array_slice($needs_attention, 0, 5);
?>

<style>
.kr-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.kr-chart {
    position: relative;
    height: 300px;
}

.kr-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.kr-list li {
    padding: 12px 0;
    border-bottom: 1px solid var(--ink-50);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.kr-list li:last-child {
    border-bottom: none;
}

.kr-weight-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.kr-weight {
    text-align: center;
    padding: 20px;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
}

.kr-weight-high { background: var(--red-bg); border-color: var(--red-border); color: var(--red-fg); }
.kr-weight-medium { background: var(--amber-bg); border-color: var(--amber-border); color: var(--amber-fg); }
.kr-weight-low { background: var(--green-bg); border-color: var(--green-border); color: var(--green-fg); }

.kr-weight-value {
    font-size: 28px;
    font-weight: 700;
}

.kr-weight-label {
    font-size: 13px;
    color: var(--ink-400);
    margin-top: 5px;
}

.kr-insight {
    background: var(--surface-hover);
    padding: 15px;
    border: 1px solid var(--border);
    border-left: 4px solid var(--brand-500);
    border-radius: var(--radius-sm);
}

.kr-insight + .kr-insight { margin-top: 15px; }

.kr-insight h4 {
    margin: 0;
    color: var(--brand-600);
}

.kr-insight ul {
    margin: 10px 0 0;
    padding-left: 20px;
}

@media (max-width: 768px) {
    .kr-grid-2,
    .kr-weight-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-chart-bar" aria-hidden="true"></i> KPI Reports &amp; Analytics</h1>
        <p>Status, achievement and weighting trends across KPIs for <?php echo $filter_year; ?>.</p>
    </div>
    <div class="hero-actions">
        <button type="button" onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
        <button type="button" onclick="exportReport()" class="btn btn-primary">
            <i class="fas fa-download" aria-hidden="true"></i> Export Report
        </button>
    </div>
</div>

<!-- Statistics Overview -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-bullseye" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total KPIs</span>
            <span class="stat-value"><?php echo $total_kpis; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Approved</span>
            <span class="stat-value"><?php echo $status_counts['Approved']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Avg Achievement</span>
            <span class="stat-value"><?php echo $overall_avg_achievement; ?>%</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-tasks" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">In Progress</span>
            <span class="stat-value"><?php echo count($kpis_with_achievement); ?></span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-filter" aria-hidden="true"></i> Report Filters (<?php echo $filter_year; ?>)</h3>
    </div>

    <div class="card-body">
        <!-- Filters -->
        <form method="GET" action="" class="filters-bar" aria-label="Report filters">
            <div class="form-group">
                <label>Fiscal Year</label>
                <select name="year" class="form-control">
                    <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <?php if ($is_hr): ?>
            <div class="form-group">
                <label>Department</label>
                <select name="department" class="form-control">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>" <?php echo $filter_department == $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Employee</label>
                <select name="user" class="form-control">
                    <option value="">All Employees</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>" <?php echo $filter_user == $user['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                            <?php if ($user['department_name']): ?>
                                (<?php echo htmlspecialchars($user['department_name']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filters-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <a href="kpi-reports" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Key Metrics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-flag-checkered" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Completion Rate</span>
            <span class="stat-value"><?php echo $total_kpis > 0 ? round((count($kpis_with_achievement) / $total_kpis) * 100) : 0; ?>%</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-thumbs-up" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Approval Rate</span>
            <span class="stat-value"><?php echo $total_kpis > 0 ? round(($status_counts['Approved'] / $total_kpis) * 100) : 0; ?>%</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-weight-hanging" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Weight</span>
            <span class="stat-value"><?php echo round($total_weight); ?>%</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-balance-scale" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Average Weight</span>
            <span class="stat-value"><?php echo $total_kpis > 0 ? round($total_weight / $total_kpis, 1) : 0; ?>%</span>
        </div>
    </div>
</div>

<!-- Charts Row 1 -->
<div class="kr-grid-2">
    <!-- Status Distribution -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-pie" aria-hidden="true"></i> Status Distribution</h3></div>
        <div class="card-body">
            <div class="kr-chart">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Quarterly Performance -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-line" aria-hidden="true"></i> Quarterly Achievement</h3></div>
        <div class="card-body">
            <div class="kr-chart">
                <canvas id="quarterlyChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="kr-grid-2">
    <!-- Category Performance -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-bar" aria-hidden="true"></i> Performance by Category</h3></div>
        <div class="card-body">
            <div class="kr-chart">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Target vs Actual -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-bullseye" aria-hidden="true"></i> Target vs Actual</h3></div>
        <div class="card-body">
            <div class="kr-chart">
                <canvas id="targetActualChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Weight Distribution -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-weight" aria-hidden="true"></i> Weight Distribution</h3></div>
    <div class="card-body">
        <div class="kr-weight-grid">
            <div class="kr-weight kr-weight-high">
                <div class="kr-weight-value"><?php echo $high_weight; ?></div>
                <div class="kr-weight-label">High Weight (=30%)</div>
            </div>
            <div class="kr-weight kr-weight-medium">
                <div class="kr-weight-value"><?php echo $medium_weight; ?></div>
                <div class="kr-weight-label">Medium Weight (15-29%)</div>
            </div>
            <div class="kr-weight kr-weight-low">
                <div class="kr-weight-value"><?php echo $low_weight; ?></div>
                <div class="kr-weight-label">Low Weight (<15%)</div>
            </div>
        </div>
    </div>
</div>

<!-- Top Performers & Needs Attention -->
<div class="kr-grid-2">
    <!-- Top Performers -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-trophy" aria-hidden="true"></i> Top Performers</h3></div>
        <div class="card-body">
            <?php if (empty($top_performers)): ?>
                <div class="empty-state">
                    <i class="fas fa-trophy" aria-hidden="true"></i>
                    <p>No data available</p>
                </div>
            <?php else: ?>
                <ul class="kr-list">
                    <?php foreach ($top_performers as $kpi): ?>
                        <li>
                            <div>
                                <strong><?php echo htmlspecialchars($kpi['kpi_title']); ?></strong>
                                <span class="cell-muted"><?php echo htmlspecialchars($kpi['owner_name']); ?></span>
                            </div>
                            <span class="badge badge-success">
                                <?php echo $kpi['overall_achievement']; ?>%
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Needs Attention -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Needs Attention</h3></div>
        <div class="card-body">
            <?php if (empty($needs_attention)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" aria-hidden="true"></i>
                    <p>No data available</p>
                </div>
            <?php else: ?>
                <ul class="kr-list">
                    <?php foreach ($needs_attention as $kpi): ?>
                        <li>
                            <div>
                                <strong><?php echo htmlspecialchars($kpi['kpi_title']); ?></strong>
                                <span class="cell-muted"><?php echo htmlspecialchars($kpi['owner_name']); ?></span>
                            </div>
                            <span class="badge <?php
                                echo $kpi['overall_achievement'] >= 80 ? 'badge-success' :
                                    ($kpi['overall_achievement'] >= 60 ? 'badge-warning' : 'badge-danger');
                            ?>">
                                <?php echo $kpi['overall_achievement']; ?>%
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Insights & Recommendations -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-lightbulb" aria-hidden="true"></i> Key Insights &amp; Recommendations</h3></div>
    <div class="card-body">
        <div class="kr-insight">
            <h4>Overall Performance</h4>
            <ul>
                <?php if ($overall_avg_achievement >= 90): ?>
                    <li>Excellent performance! Average achievement is <?php echo $overall_avg_achievement; ?>%, exceeding expectations.</li>
                <?php elseif ($overall_avg_achievement >= 75): ?>
                    <li>Good performance with <?php echo $overall_avg_achievement; ?>% average achievement. Some room for improvement.</li>
                <?php else: ?>
                    <li>Average achievement is <?php echo $overall_avg_achievement; ?>%. Focus needed to improve performance.</li>
                <?php endif; ?>

                <?php if ($status_counts['Rejected'] > 0): ?>
                    <li><?php echo $status_counts['Rejected']; ?> KPI(s) rejected. Review feedback and resubmit with improvements.</li>
                <?php endif; ?>

                <?php if ($status_counts['Submitted'] > 0): ?>
                    <li><?php echo $status_counts['Submitted']; ?> KPI(s) awaiting review. Timely approval needed.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="kr-insight">
            <h4>Quarterly Trends</h4>
            <ul>
                <?php
                $best_quarter = array_keys($quarterly_performance, max($quarterly_performance))[0];
                $worst_quarter = array_keys($quarterly_performance, min($quarterly_performance))[0];
                ?>
                <li>Best performing quarter: <strong><?php echo $best_quarter; ?></strong> with <?php echo $quarterly_performance[$best_quarter]['avg_achievement']; ?>% achievement.</li>
                <li><?php echo $quarterly_performance['Q4']['completed']; ?> out of <?php echo $quarterly_performance['Q4']['total']; ?> Q4 targets completed.</li>
                <?php if ($quarterly_performance['Q1']['completion_rate'] < 50 && $filter_year == date('Y')): ?>
                    <li>Q1 completion rate is low (<?php echo $quarterly_performance['Q1']['completion_rate']; ?>%). Encourage timely progress updates.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
</div><!-- /.page-stack -->

<script>
// Chart colours come from the design tokens in reports.css.
const krToken = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

// Status Distribution Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Approved', 'Submitted', 'Under Review', 'Rejected', 'Draft', 'Completed'],
        datasets: [{
            data: [
                <?php echo $status_counts['Approved']; ?>,
                <?php echo $status_counts['Submitted']; ?>,
                <?php echo $status_counts['Under Review']; ?>,
                <?php echo $status_counts['Rejected']; ?>,
                <?php echo $status_counts['Draft']; ?>,
                <?php echo $status_counts['Completed']; ?>
            ],
            backgroundColor: [
                krToken('--green-fg'),
                krToken('--blue-fg'),
                krToken('--amber-fg'),
                krToken('--red-fg'),
                krToken('--ink-200'),
                krToken('--teal-fg')
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Quarterly Performance Chart
const quarterlyCtx = document.getElementById('quarterlyChart').getContext('2d');
new Chart(quarterlyCtx, {
    type: 'line',
    data: {
        labels: ['Q1', 'Q2', 'Q3', 'Q4'],
        datasets: [{
            label: 'Average Achievement (%)',
            data: [
                <?php echo $quarterly_performance['Q1']['avg_achievement']; ?>,
                <?php echo $quarterly_performance['Q2']['avg_achievement']; ?>,
                <?php echo $quarterly_performance['Q3']['avg_achievement']; ?>,
                <?php echo $quarterly_performance['Q4']['avg_achievement']; ?>
            ],
            borderColor: krToken('--brand-500'),
            backgroundColor: krToken('--brand-50'),
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                max: 120
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});

// Category Performance Chart
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(categoryCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($category_stats)); ?>,
        datasets: [{
            label: 'Avg Achievement (%)',
            data: <?php echo json_encode(array_column($category_stats, 'avg_achievement')); ?>,
            backgroundColor: krToken('--purple-fg')
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                max: 120
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});

// Target vs Actual Chart
const targetActualCtx = document.getElementById('targetActualChart').getContext('2d');
new Chart(targetActualCtx, {
    type: 'bar',
    data: {
        labels: ['Q1', 'Q2', 'Q3', 'Q4'],
        datasets: [
            {
                label: 'Target',
                data: [
                    <?php echo $target_actual_data['Q1']['target']; ?>,
                    <?php echo $target_actual_data['Q2']['target']; ?>,
                    <?php echo $target_actual_data['Q3']['target']; ?>,
                    <?php echo $target_actual_data['Q4']['target']; ?>
                ],
                backgroundColor: krToken('--ink-200')
            },
            {
                label: 'Actual',
                data: [
                    <?php echo $target_actual_data['Q1']['actual']; ?>,
                    <?php echo $target_actual_data['Q2']['actual']; ?>,
                    <?php echo $target_actual_data['Q3']['actual']; ?>,
                    <?php echo $target_actual_data['Q4']['actual']; ?>
                ],
                backgroundColor: krToken('--green-fg')
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

function exportReport() {
    window.location.href = 'export-kpi-report-pdf?year=<?php echo $filter_year; ?>&department=<?php echo $filter_department; ?>&user=<?php echo $filter_user; ?>';
}
</script>

<?php include 'includes/footer.php'; ?>
