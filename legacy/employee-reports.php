<?php
$page_title = 'Employee Reports';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
include 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'HR']);

// -- Filters -------------------------------------------------------------------
$filter_department = isset($_GET['department']) ? intval($_GET['department']) : 0;
$filter_year       = isset($_GET['year'])       ? intval($_GET['year'])       : (int)date('Y');

// -- Employees -----------------------------------------------------------------
$where = '1=1';
if ($filter_department) {
    $where .= " AND ed.department_id = $filter_department";
}

$employees = [];
$result = $conn->query("
    SELECT ed.*, d.department_name, d.department_code
    FROM   employee_directory ed
    LEFT JOIN departments d ON ed.department_id = d.department_id
    WHERE  $where
    ORDER BY ed.full_name
");
while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

// -- Departments (filter dropdown) ---------------------------------------------
$departments = [];
$result = $conn->query("SELECT * FROM departments ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $departments[] = $row;
}

// -- Aggregate stats -----------------------------------------------------------
$total_employees = count($employees);

// Guard against division-by-zero when there are no employees
$safe_total = max($total_employees, 1);

$approved  = count(array_filter($employees, fn($e) => $e['status'] === 'Approved'));
$submitted = count(array_filter($employees, fn($e) => $e['status'] === 'Submitted'));
$draft     = count(array_filter($employees, fn($e) => $e['status'] === 'Draft'));
$rejected  = count(array_filter($employees, fn($e) => $e['status'] === 'Rejected'));

$staff       = count(array_filter($employees, fn($e) => $e['contract_type'] === 'Staff'));
$consultants = count(array_filter($employees, fn($e) => $e['contract_type'] === 'Consultant'));
$temp        = count(array_filter($employees, fn($e) => $e['contract_type'] === 'Temp'));
$casual      = count(array_filter($employees, fn($e) => $e['contract_type'] === 'Casual'));

$male   = count(array_filter($employees, fn($e) => $e['gender'] === 'Male'));
$female = count(array_filter($employees, fn($e) => $e['gender'] === 'Female'));

// -- Department breakdown ------------------------------------------------------
$dept_stats = [];
foreach ($departments as $dept) {
    $count = count(array_filter($employees, fn($e) => $e['department_id'] == $dept['department_id']));
    if ($count > 0) {
        $dept_stats[] = [
            'name'       => $dept['department_name'],
            'code'       => $dept['department_code'],
            'count'      => $count,
            'percentage' => round(($count / $safe_total) * 100, 1),
        ];
    }
}

// -- Tenure analysis -----------------------------------------------------------
$tenure_ranges = ['new' => 0, 'junior' => 0, 'mid' => 0, 'senior' => 0, 'veteran' => 0];
$today = new DateTime();

foreach ($employees as $emp) {
    if (!empty($emp['start_date'])) {
        $start  = new DateTime($emp['start_date']);
        $diff   = $start->diff($today);
        $months = ($diff->y * 12) + $diff->m;

        if      ($months < 6)   $tenure_ranges['new']++;
        elseif  ($months < 12)  $tenure_ranges['junior']++;
        elseif  ($diff->y < 3)  $tenure_ranges['mid']++;
        elseif  ($diff->y < 5)  $tenure_ranges['senior']++;
        else                    $tenure_ranges['veteran']++;
    }
}

// -- Contracts expiring in next 90 days ---------------------------------------
$contracts_expiring = [];
$expiring_90_days   = new DateTime('+90 days');

foreach ($employees as $emp) {
    if (!empty($emp['contract_end_date'])) {
        $end = new DateTime($emp['contract_end_date']);
        if ($end > $today && $end <= $expiring_90_days) {
            $days = $today->diff($end)->days;
            $contracts_expiring[] = [
                'name'           => $emp['full_name'],
                'job_title'      => $emp['job_title'],
                'department'     => $emp['department_name'],
                'end_date'       => $emp['contract_end_date'],
                'days_remaining' => $days,
            ];
        }
    }
}
usort($contracts_expiring, fn($a, $b) => $a['days_remaining'] - $b['days_remaining']);

// -- Profile completion --------------------------------------------------------
$required_fields = ['full_name','job_title','department_id','contract_type',
                    'start_date','gender','company_email','phone','signature_path'];

$profiles_complete   = 0;
$profiles_incomplete = 0;
foreach ($employees as $emp) {
    $filled = count(array_filter($required_fields, fn($f) => !empty($emp[$f])));
    ($filled === count($required_fields)) ? $profiles_complete++ : $profiles_incomplete++;
}

// -- Helpers -------------------------------------------------------------------
function pct(int $n, int $total): float {
    return $total > 0 ? round(($n / $total) * 100, 1) : 0;
}
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>

<link rel="stylesheet" href="css/opportunities.css">

<div class="rpt-page">

    <!-- -- Hero --------------------------------------------------------------- -->
    <div class="opp-hero">
        <div class="opp-hero-left">
            <div class="opp-eyebrow">
                <span class="opp-dot"></span>
                People &amp; Analytics
            </div>
            <h1><i class="fas fa-chart-bar"></i> Employee Reports</h1>
            <p>Workforce insights, contract tracking and profile completion for <?= h($filter_year) ?>.</p>
        </div>

        <div class="opp-hero-actions">
            <a href="employees-list" class="btn btn-white btn-sm">
                <i class="fas fa-arrow-left"></i> Directory
            </a>
            <button onclick="exportToExcel()" class="btn btn-white btn-sm">
                <i class="fas fa-file-excel"></i> Excel
            </button>
            <button onclick="exportToPDF(this)" class="btn btn-white btn-sm">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <button onclick="window.print()" class="btn btn-white btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- -- Filters ------------------------------------------------------------ -->
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-filter icon-brand"></i> Filters</h3>
        </div>
        <div class="opp-panel-body">
            <form method="GET" action="" class="rpt-filter-form filters-bar" aria-label="Report filters">
                <div class="form-group">
                    <label class="form-label" for="fDept">Department</label>
                    <select name="department" id="fDept" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option
                                value="<?= (int)$dept['department_id'] ?>"
                                <?= $filter_department == $dept['department_id'] ? 'selected' : '' ?>
                            ><?= h($dept['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="fYear">Year</label>
                    <select name="year" id="fYear" class="form-control">
                        <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?= $y ?>" <?= $filter_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filters-actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a href="employee-reports" class="btn btn-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- -- KPI Stats ---------------------------------------------------------- -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total</span>
                <span class="stat-value"><?= $total_employees ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <span class="stat-label">Approved</span>
                <span class="stat-value"><?= $approved ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-user-tie"></i></div>
            <div class="stat-info">
                <span class="stat-label">Staff</span>
                <span class="stat-value"><?= $staff ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-briefcase"></i></div>
            <div class="stat-info">
                <span class="stat-label">Consultants</span>
                <span class="stat-value"><?= $consultants ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-teal">
                <i class="fas fa-venus-mars"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Female %</span>
                <span class="stat-value"><?= pct($female, $safe_total) ?>%</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Expiring</span>
                <span class="stat-value"><?= count($contracts_expiring) ?></span>
            </div>
        </div>
    </div>

    <!-- -- Charts row --------------------------------------------------------- -->
    <div class="rpt-chart-grid">

        <!-- Contract Type -->
        <div class="opp-panel">
            <div class="opp-panel-head">
                <h3><i class="fas fa-briefcase icon-brand"></i> Contract Type Distribution</h3>
            </div>
            <div class="opp-panel-body">
                <div class="rpt-chart-wrap">
                    <canvas id="contractTypeChart"></canvas>
                </div>
                <div class="rpt-legend">
                    <div class="rpt-legend-item">
                        <div class="rpt-legend-dot rpt-legend-dot-blue"></div>
                        Staff: <?= $staff ?> (<?= pct($staff, $safe_total) ?>%)
                    </div>
                    <div class="rpt-legend-item">
                        <div class="rpt-legend-dot rpt-legend-dot-brand"></div>
                        Consultant: <?= $consultants ?> (<?= pct($consultants, $safe_total) ?>%)
                    </div>
                    <div class="rpt-legend-item">
                        <div class="rpt-legend-dot rpt-legend-dot-purple"></div>
                        Temp: <?= $temp ?> (<?= pct($temp, $safe_total) ?>%)
                    </div>
                    <div class="rpt-legend-item">
                        <div class="rpt-legend-dot rpt-legend-dot-slate"></div>
                        Casual: <?= $casual ?> (<?= pct($casual, $safe_total) ?>%)
                    </div>
                </div>
            </div>
        </div>

        <!-- Gender -->
        <div class="opp-panel">
            <div class="opp-panel-head">
                <h3><i class="fas fa-venus-mars icon-brand"></i> Gender Distribution</h3>
            </div>
            <div class="opp-panel-body">
                <div class="rpt-chart-wrap">
                    <canvas id="genderChart"></canvas>
                </div>
                <div class="rpt-legend">
                    <div class="rpt-legend-item">
                        <div class="rpt-legend-dot rpt-legend-dot-blue"></div>
                        Male: <?= $male ?> (<?= pct($male, $safe_total) ?>%)
                    </div>
                    <div class="rpt-legend-item">
                        <div class="rpt-legend-dot rpt-legend-dot-red"></div>
                        Female: <?= $female ?> (<?= pct($female, $safe_total) ?>%)
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- -- Department Distribution -------------------------------------------- -->
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-building icon-brand"></i> Department Distribution</h3>
        </div>
        <div class="opp-panel-body">
            <?php if (empty($dept_stats)): ?>
                <p class="text-muted-sm">No department data available.</p>
            <?php else: ?>
                <?php foreach ($dept_stats as $dept): ?>
                    <div class="rpt-progress-row">
                        <div class="rpt-progress-label">
                            <strong><?= h($dept['name']) ?></strong>
                            <span><?= $dept['count'] ?> employee<?= $dept['count'] !== 1 ? 's' : '' ?> &nbsp;·&nbsp; <?= $dept['percentage'] ?>%</span>
                        </div>
                        <div class="rpt-progress-bg">
                            <div class="rpt-progress-fill" style="width:<?= $dept['percentage'] ?>%">
                                <?= $dept['percentage'] ?>%
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- -- Tenure Analysis ---------------------------------------------------- -->
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-calendar-alt icon-brand"></i> Employee Tenure Analysis</h3>
        </div>
        <div class="opp-panel-body">
            <div class="rpt-mini-grid">
                <div class="rpt-mini red">
                    <div class="rpt-mini-value"><?= $tenure_ranges['new'] ?></div>
                    <div class="rpt-mini-label">New&ensp;&lt;&nbsp;6 months</div>
                </div>
                <div class="rpt-mini amber">
                    <div class="rpt-mini-value"><?= $tenure_ranges['junior'] ?></div>
                    <div class="rpt-mini-label">Junior&ensp;6m - 1y</div>
                </div>
                <div class="rpt-mini blue">
                    <div class="rpt-mini-value"><?= $tenure_ranges['mid'] ?></div>
                    <div class="rpt-mini-label">Mid-Level&ensp;1 - 3y</div>
                </div>
                <div class="rpt-mini purple">
                    <div class="rpt-mini-value"><?= $tenure_ranges['senior'] ?></div>
                    <div class="rpt-mini-label">Senior&ensp;3 - 5y</div>
                </div>
                <div class="rpt-mini green">
                    <div class="rpt-mini-value"><?= $tenure_ranges['veteran'] ?></div>
                    <div class="rpt-mini-label">Veteran&ensp;5y+</div>
                </div>
            </div>

            <div class="rpt-chart-wrap">
                <canvas id="tenureChart"></canvas>
            </div>
        </div>
    </div>

    <!-- -- Profile Completion Status ------------------------------------------ -->
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-tasks icon-brand"></i> Profile Completion Status</h3>
        </div>
        <div class="opp-panel-body">
            <div class="rpt-mini-grid">
                <div class="rpt-mini green">
                    <div class="rpt-mini-value"><?= $approved ?></div>
                    <div class="rpt-mini-label">Approved</div>
                </div>
                <div class="rpt-mini blue">
                    <div class="rpt-mini-value"><?= $submitted ?></div>
                    <div class="rpt-mini-label">Submitted</div>
                </div>
                <div class="rpt-mini slate">
                    <div class="rpt-mini-value"><?= $draft ?></div>
                    <div class="rpt-mini-label">Draft</div>
                </div>
                <div class="rpt-mini red">
                    <div class="rpt-mini-value"><?= $rejected ?></div>
                    <div class="rpt-mini-label">Rejected</div>
                </div>
                <div class="rpt-mini green">
                    <div class="rpt-mini-value"><?= $profiles_complete ?></div>
                    <div class="rpt-mini-label">Fully Complete</div>
                </div>
                <div class="rpt-mini amber">
                    <div class="rpt-mini-value"><?= $profiles_incomplete ?></div>
                    <div class="rpt-mini-label">Incomplete</div>
                </div>
            </div>
        </div>
    </div>

    <!-- -- Contracts Expiring ------------------------------------------------- -->
    <?php if (!empty($contracts_expiring)): ?>
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-exclamation-triangle icon-amber"></i> Contracts Expiring in Next 90 Days</h3>
            <span class="badge badge-warning"><?= count($contracts_expiring) ?> contract<?= count($contracts_expiring) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="opp-panel-body opp-panel-body-flush">
            <div class="rpt-warning">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong><?= count($contracts_expiring) ?> contract<?= count($contracts_expiring) !== 1 ? 's' : '' ?></strong> expiring soon.
                    <span>Please review and take necessary action.</span>
                </div>
            </div>

            <div class="table-overflow">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Job Title</th>
                            <th>Department</th>
                            <th>End Date</th>
                            <th>Days Left</th>
                            <th>Urgency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contracts_expiring as $c): ?>
                            <tr>
                                <td><strong><?= h($c['name']) ?></strong></td>
                                <td><?= h($c['job_title']) ?></td>
                                <td><?= h($c['department']) ?></td>
                                <td><?= date('d M Y', strtotime($c['end_date'])) ?></td>
                                <td>
                                    <span class="badge <?= $c['days_remaining'] <= 30 ? 'badge-urgent' : ($c['days_remaining'] <= 60 ? 'badge-action' : 'badge-monitor') ?>">
                                        <?= $c['days_remaining'] ?> days
                                    </span>
                                </td>
                                <td>
                                    <?php if ($c['days_remaining'] <= 30): ?>
                                        <span class="badge badge-urgent"><i class="fas fa-fire"></i> Urgent</span>
                                    <?php elseif ($c['days_remaining'] <= 60): ?>
                                        <span class="badge badge-action"><i class="fas fa-clock"></i> Action Needed</span>
                                    <?php else: ?>
                                        <span class="badge badge-monitor"><i class="fas fa-eye"></i> Monitor</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.rpt-page -->

<script>
(function () {
    'use strict';

    // -- Shared chart defaults ------------------------------------------------
    Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
    Chart.defaults.color       = '#6b7280'; // --ink-300

    // -- Contract Type doughnut -----------------------------------------------
    new Chart(document.getElementById('contractTypeChart'), {
        type: 'doughnut',
        data: {
            labels: ['Staff', 'Consultant', 'Temp', 'Casual'],
            datasets: [{
                data: [<?= $staff ?>, <?= $consultants ?>, <?= $temp ?>, <?= $casual ?>],
                backgroundColor: ['#3b82f6', '#f97316', '#7e22ce', '#475569'],
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: { legend: { display: false } }
        }
    });

    // -- Gender pie -----------------------------------------------------------
    new Chart(document.getElementById('genderChart'), {
        type: 'pie',
        data: {
            labels: ['Male', 'Female'],
            datasets: [{
                data: [<?= $male ?>, <?= $female ?>],
                backgroundColor: ['#3b82f6', '#b91c1c'],
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });

    // -- Tenure bar -----------------------------------------------------------
    new Chart(document.getElementById('tenureChart'), {
        type: 'bar',
        data: {
            labels: ['New\n(<6m)', 'Junior\n(6m-1y)', 'Mid-Level\n(1-3y)', 'Senior\n(3-5y)', 'Veteran\n(5y+)'],
            datasets: [{
                label: 'Employees',
                data: [<?= $tenure_ranges['new'] ?>, <?= $tenure_ranges['junior'] ?>,
                       <?= $tenure_ranges['mid'] ?>, <?= $tenure_ranges['senior'] ?>,
                       <?= $tenure_ranges['veteran'] ?>],
                backgroundColor: ['#b91c1c', '#b45309', '#1d4ed8', '#7e22ce', '#15803d'],
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    grid: { color: 'rgba(0,0,0,.04)' }
                }
            },
            plugins: { legend: { display: false } }
        }
    });

    // -- Export helpers -------------------------------------------------------
    window.exportToExcel = function () {
        window.location.href = 'export?type=employee_report'
            + '&department=<?= $filter_department ?>&year=<?= $filter_year ?>';
    };

    window.exportToPDF = function (btn) {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        btn.disabled  = true;
        window.location.href = 'export-employee-report-pdf'
            + '?department=<?= $filter_department ?>&year=<?= $filter_year ?>';
        setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 3000);
    };
}());
</script>

<?php include 'includes/footer.php'; ?>
