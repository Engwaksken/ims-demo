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
$filter_user = $is_hr && isset($_GET['user']) ? intval($_GET['user']) : $_SESSION['user_id'];

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
.analytics-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-container {
    position: relative;
    height: 300px;
    margin: 20px 0;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.metric-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
}

.metric-box.success {
    background: linear-gradient(135deg, #27AE60 0%, #2ECC71 100%);
}

.metric-box.warning {
    background: linear-gradient(135deg, #F39C12 0%, #F1C40F 100%);
}

.metric-box.danger {
    background: linear-gradient(135deg, #E74C3C 0%, #C0392B 100%);
}

.metric-box.info {
    background: linear-gradient(135deg, #3498DB 0%, #2980B9 100%);
}

.metric-number {
    font-size: 32px;
    font-weight: bold;
    margin: 10px 0;
}

.metric-label {
    font-size: 13px;
    opacity: 0.9;
}

.kpi-list-simple {
    list-style: none;
    padding: 0;
    margin: 0;
}

.kpi-list-simple li {
    padding: 12px;
    border-bottom: 1px solid #ecf0f1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.kpi-list-simple li:last-child {
    border-bottom: none;
}

.achievement-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 15px;
    font-weight: bold;
    font-size: 12px;
}

.achievement-high {
    background: #d4edda;
    color: #155724;
}

.achievement-medium {
    background: #fff3cd;
    color: #856404;
}

.achievement-low {
    background: #f8d7da;
    color: #721c24;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
}

.insights-box {
    background: #f8f9fa;
    padding: 15px;
    border-left: 4px solid var(--primary-color);
    border-radius: 5px;
    margin: 15px 0;
}

.progress-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
}

.progress-bar-slim {
    flex: 1;
    height: 8px;
    background: #ecf0f1;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill-slim {
    height: 100%;
    background: linear-gradient(90deg, #27AE60, #2ECC71);
    transition: width 0.5s ease;
}
</style>

<!-- Statistics Overview -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-bullseye"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo $total_kpis; ?></h4>
            <p>Total KPIs</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo $status_counts['Approved']; ?></h4>
            <p>Approved</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo $overall_avg_achievement; ?>%</h4>
            <p>Avg Achievement</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="fas fa-tasks"></i>
        </div>
        <div class="stat-details">
            <h4><?php echo count($kpis_with_achievement); ?></h4>
            <p>In Progress</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-chart-bar"></i> KPI Reports & Analytics (<?php echo $filter_year; ?>)</h3>
        <div>
            <button onclick="exportReport()" class="btn btn-success">
                <i class="fas fa-download"></i> Export Report
            </button>
            <button onclick="window.print()" class="btn btn-info">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" action="" class="filters-bar" style="margin-bottom: 30px;" aria-label="Report filters">
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
        
        <!-- Key Metrics -->
        <div class="metric-grid">
            <div class="metric-box success">
                <div class="metric-label">Completion Rate</div>
                <div class="metric-number">
                    <?php echo $total_kpis > 0 ? round((count($kpis_with_achievement) / $total_kpis) * 100) : 0; ?>%
                </div>
            </div>
            
            <div class="metric-box info">
                <div class="metric-label">Approval Rate</div>
                <div class="metric-number">
                    <?php echo $total_kpis > 0 ? round(($status_counts['Approved'] / $total_kpis) * 100) : 0; ?>%
                </div>
            </div>
            
            <div class="metric-box warning">
                <div class="metric-label">Total Weight</div>
                <div class="metric-number"><?php echo round($total_weight); ?>%</div>
            </div>
            
            <div class="metric-box">
                <div class="metric-label">Average Weight</div>
                <div class="metric-number">
                    <?php echo $total_kpis > 0 ? round($total_weight / $total_kpis, 1) : 0; ?>%
                </div>
            </div>
        </div>
        
        <!-- Charts Row 1 -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
            <!-- Status Distribution -->
            <div class="analytics-card">
                <div class="section-title">
                    <i class="fas fa-chart-pie"></i> Status Distribution
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            
            <!-- Quarterly Performance -->
            <div class="analytics-card">
                <div class="section-title">
                    <i class="fas fa-chart-line"></i> Quarterly Achievement
                </div>
                <div class="chart-container">
                    <canvas id="quarterlyChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Charts Row 2 -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            <!-- Category Performance -->
            <div class="analytics-card">
                <div class="section-title">
                    <i class="fas fa-chart-bar"></i> Performance by Category
                </div>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
            
            <!-- Target vs Actual -->
            <div class="analytics-card">
                <div class="section-title">
                    <i class="fas fa-bullseye"></i> Target vs Actual
                </div>
                <div class="chart-container">
                    <canvas id="targetActualChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Weight Distribution -->
        <div class="analytics-card" style="margin-top: 20px;">
            <div class="section-title">
                <i class="fas fa-weight"></i> Weight Distribution
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 15px;">
                <div style="text-align: center; padding: 20px; background: #ffe5e5; border-radius: 8px;">
                    <div style="font-size: 28px; font-weight: bold; color: #c62828;"><?php echo $high_weight; ?></div>
                    <div style="font-size: 13px; color: #666; margin-top: 5px;">High Weight (=30%)</div>
                </div>
                <div style="text-align: center; padding: 20px; background: #fff3e0; border-radius: 8px;">
                    <div style="font-size: 28px; font-weight: bold; color: #e65100;"><?php echo $medium_weight; ?></div>
                    <div style="font-size: 13px; color: #666; margin-top: 5px;">Medium Weight (15-29%)</div>
                </div>
                <div style="text-align: center; padding: 20px; background: #e8f5e9; border-radius: 8px;">
                    <div style="font-size: 28px; font-weight: bold; color: #2e7d32;"><?php echo $low_weight; ?></div>
                    <div style="font-size: 13px; color: #666; margin-top: 5px;">Low Weight (<15%)</div>
                </div>
            </div>
        </div>
        
        <!-- Top Performers & Needs Attention -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            <!-- Top Performers -->
            <div class="analytics-card">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-trophy"></i> Top Performers
                    </div>
                </div>
                
                <?php if (empty($top_performers)): ?>
                    <p style="text-align: center; color: #7f8c8d; padding: 30px;">No data available</p>
                <?php else: ?>
                    <ul class="kpi-list-simple">
                        <?php foreach ($top_performers as $kpi): ?>
                            <li>
                                <div>
                                    <strong><?php echo htmlspecialchars($kpi['kpi_title']); ?></strong>
                                    <div style="font-size: 12px; color: #7f8c8d; margin-top: 3px;">
                                        <?php echo htmlspecialchars($kpi['owner_name']); ?>
                                    </div>
                                </div>
                                <span class="achievement-badge achievement-high">
                                    <?php echo $kpi['overall_achievement']; ?>%
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <!-- Needs Attention -->
            <div class="analytics-card">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-exclamation-triangle"></i> Needs Attention
                    </div>
                </div>
                
                <?php if (empty($needs_attention)): ?>
                    <p style="text-align: center; color: #7f8c8d; padding: 30px;">No data available</p>
                <?php else: ?>
                    <ul class="kpi-list-simple">
                        <?php foreach ($needs_attention as $kpi): ?>
                            <li>
                                <div>
                                    <strong><?php echo htmlspecialchars($kpi['kpi_title']); ?></strong>
                                    <div style="font-size: 12px; color: #7f8c8d; margin-top: 3px;">
                                        <?php echo htmlspecialchars($kpi['owner_name']); ?>
                                    </div>
                                </div>
                                <span class="achievement-badge <?php 
                                    echo $kpi['overall_achievement'] >= 80 ? 'achievement-high' : 
                                        ($kpi['overall_achievement'] >= 60 ? 'achievement-medium' : 'achievement-low'); 
                                ?>">
                                    <?php echo $kpi['overall_achievement']; ?>%
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Insights & Recommendations -->
        <div class="analytics-card" style="margin-top: 20px;">
            <div class="section-title">
                <i class="fas fa-lightbulb"></i> Key Insights & Recommendations
            </div>
            
            <div class="insights-box">
                <h4 style="margin-top: 0; color: var(--primary-color);">Overall Performance</h4>
                <ul style="margin: 10px 0; padding-left: 20px;">
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
            
            <div class="insights-box">
                <h4 style="margin-top: 0; color: var(--primary-color);">Quarterly Trends</h4>
                <ul style="margin: 10px 0; padding-left: 20px;">
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
</div>

<script>
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
                '#27AE60',
                '#3498DB',
                '#F39C12',
                '#E74C3C',
                '#95A5A6',
                '#2ECC71'
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
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
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
            backgroundColor: '#764ba2'
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
                backgroundColor: '#95A5A6'
            },
            {
                label: 'Actual',
                data: [
                    <?php echo $target_actual_data['Q1']['actual']; ?>,
                    <?php echo $target_actual_data['Q2']['actual']; ?>,
                    <?php echo $target_actual_data['Q3']['actual']; ?>,
                    <?php echo $target_actual_data['Q4']['actual']; ?>
                ],
                backgroundColor: '#27AE60'
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
