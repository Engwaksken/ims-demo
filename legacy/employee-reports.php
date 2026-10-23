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

<style>
/* Employee reports (er-) */
.er-filters { margin-bottom: 0; }

.er-chart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.er-chart-wrap {
    position: relative;
    height: 260px;
    margin: 4px 0 12px;
}

.er-legend { display: flex; gap: 18px; flex-wrap: wrap; margin-top: 8px; }
.er-legend-item { display: flex; align-items: center; gap: 7px; font-size: 13px; color: var(--ink-500); }
.er-legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
.er-dot-blue   { background: var(--blue-fg); }
.er-dot-brand  { background: var(--brand-500); }
.er-dot-purple { background: var(--purple-fg); }
.er-dot-slate  { background: var(--slate-fg); }
.er-dot-red    { background: var(--red-fg); }

.er-progress-row + .er-progress-row { margin-top: 14px; }
.er-progress-label {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    margin-bottom: 7px;
    font-size: 13px;
    color: var(--ink-500);
}
.er-progress-label strong { color: var(--ink-700); font-weight: 700; }
.er-progress-label span { color: var(--ink-300); font-size: 12px; white-space: nowrap; }
.er-progress-bg {
    height: 22px;
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-pill);
    overflow: hidden;
}
.er-progress-fill {
    height: 100%;
    min-width: 36px;
    padding: 0 10px;
    display: flex;
    align-items: center;
    border-radius: var(--radius-pill);
    background: linear-gradient(90deg, var(--brand-600), var(--brand-400));
    color: var(--ink-0);
    font-size: 11px;
    font-weight: 700;
    transition: width .5s var(--ease-out);
}

.er-mini-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 14px;
}
.er-mini-grid + .er-chart-wrap { margin-top: 20px; }
.er-mini {
    padding: 16px;
    text-align: center;
    background: var(--surface-card);
    border: 1px solid var(--ink-100);
    border-left: 4px solid var(--ink-200);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}
.er-mini-value { font-size: 32px; font-weight: 800; letter-spacing: -1px; line-height: 1; margin-bottom: 6px; }
.er-mini-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--ink-300);
    line-height: 1.4;
}
.er-mini.red    { border-left-color: var(--red-fg); }
.er-mini.amber  { border-left-color: var(--amber-fg); }
.er-mini.blue   { border-left-color: var(--blue-fg); }
.er-mini.purple { border-left-color: var(--purple-fg); }
.er-mini.green  { border-left-color: var(--green-fg); }
.er-mini.slate  { border-left-color: var(--slate-fg); }
.er-mini.red    .er-mini-value { color: var(--red-fg); }
.er-mini.amber  .er-mini-value { color: var(--amber-fg); }
.er-mini.blue   .er-mini-value { color: var(--blue-fg); }
.er-mini.purple .er-mini-value { color: var(--purple-fg); }
.er-mini.green  .er-mini-value { color: var(--green-fg); }
.er-mini.slate  .er-mini-value { color: var(--slate-fg); }

.er-expiring .table-responsive { margin-top: 0; }

@media (max-width: 900px) {
    .er-chart-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .er-mini-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 400px) {
    .er-mini-grid { grid-template-columns: 1fr; }
    .er-mini-value { font-size: 26px; }
}
</style>

<div class="page-stack">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-chart-bar" aria-hidden="true"></i> Employee Reports</h1>
            <p>Workforce insights, contract tracking and profile completion for <?= h($filter_year) ?>.</p>
        </div>

        <div class="hero-actions">
            <a href="employees-list" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Directory
            </a>
            <button type="button" onclick="exportToExcel()" class="btn">
                <i class="fas fa-file-excel" aria-hidden="true"></i> Excel
            </button>
            <button type="button" onclick="exportToPDF(this)" class="btn">
                <i class="fas fa-file-pdf" aria-hidden="true"></i> PDF
            </button>
            <button type="button" onclick="window.print()" class="btn">
                <i class="fas fa-print" aria-hidden="true"></i> Print
            </button>
        </div>
    </div>

    <!-- -- Filters ------------------------------------------------------------ -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-filter" aria-hidden="true"></i> Filters</h3>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="filters-bar er-filters" aria-label="Report filters">
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
                        <i class="fas fa-filter" aria-hidden="true"></i> Apply
                    </button>
                    <a href="employee-reports" class="btn btn-secondary btn-sm">
                        <i class="fas fa-times" aria-hidden="true"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- -- KPI Stats ---------------------------------------------------------- -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total</span>
                <span class="stat-value"><?= $total_employees ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Approved</span>
                <span class="stat-value"><?= $approved ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-user-tie" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Staff</span>
                <span class="stat-value"><?= $staff ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-briefcase" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Consultants</span>
                <span class="stat-value"><?= $consultants ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-venus-mars" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Female %</span>
                <span class="stat-value"><?= pct($female, $safe_total) ?>%</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Expiring</span>
                <span class="stat-value"><?= count($contracts_expiring) ?></span>
            </div>
        </div>
    </div>

    <!-- -- Charts row --------------------------------------------------------- -->
    <div class="er-chart-grid">

        <!-- Contract Type -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-briefcase" aria-hidden="true"></i> Contract Type Distribution</h3>
            </div>
            <div class="card-body">
                <div class="er-chart-wrap">
                    <canvas id="contractTypeChart"></canvas>
                </div>
                <div class="er-legend">
                    <div class="er-legend-item">
                        <div class="er-legend-dot er-dot-blue"></div>
                        Staff: <?= $staff ?> (<?= pct($staff, $safe_total) ?>%)
                    </div>
                    <div class="er-legend-item">
                        <div class="er-legend-dot er-dot-brand"></div>
                        Consultant: <?= $consultants ?> (<?= pct($consultants, $safe_total) ?>%)
                    </div>
                    <div class="er-legend-item">
                        <div class="er-legend-dot er-dot-purple"></div>
                        Temp: <?= $temp ?> (<?= pct($temp, $safe_total) ?>%)
                    </div>
                    <div class="er-legend-item">
                        <div class="er-legend-dot er-dot-slate"></div>
                        Casual: <?= $casual ?> (<?= pct($casual, $safe_total) ?>%)
                    </div>
                </div>
            </div>
        </div>

        <!-- Gender -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-venus-mars" aria-hidden="true"></i> Gender Distribution</h3>
            </div>
            <div class="card-body">
                <div class="er-chart-wrap">
                    <canvas id="genderChart"></canvas>
                </div>
                <div class="er-legend">
                    <div class="er-legend-item">
                        <div class="er-legend-dot er-dot-blue"></div>
                        Male: <?= $male ?> (<?= pct($male, $safe_total) ?>%)
                    </div>
                    <div class="er-legend-item">
                        <div class="er-legend-dot er-dot-red"></div>
                        Female: <?= $female ?> (<?= pct($female, $safe_total) ?>%)
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- -- Department Distribution -------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-building" aria-hidden="true"></i> Department Distribution</h3>
        </div>
        <div class="card-body">
            <?php if (empty($dept_stats)): ?>
                <div class="empty-state">
                    <i class="fas fa-building" aria-hidden="true"></i>
                    <p>No department data available.</p>
                </div>
            <?php else: ?>
                <?php foreach ($dept_stats as $dept): ?>
                    <div class="er-progress-row">
                        <div class="er-progress-label">
                            <strong><?= h($dept['name']) ?></strong>
                            <span><?= $dept['count'] ?> employee<?= $dept['count'] !== 1 ? 's' : '' ?> &nbsp;&middot;&nbsp; <?= $dept['percentage'] ?>%</span>
                        </div>
                        <div class="er-progress-bg">
                            <div class="er-progress-fill" style="width:<?= $dept['percentage'] ?>%">
                                <?= $dept['percentage'] ?>%
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- -- Tenure Analysis ---------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-alt" aria-hidden="true"></i> Employee Tenure Analysis</h3>
        </div>
        <div class="card-body">
            <div class="er-mini-grid">
                <div class="er-mini red">
                    <div class="er-mini-value"><?= $tenure_ranges['new'] ?></div>
                    <div class="er-mini-label">New&ensp;&lt;&nbsp;6 months</div>
                </div>
                <div class="er-mini amber">
                    <div class="er-mini-value"><?= $tenure_ranges['junior'] ?></div>
                    <div class="er-mini-label">Junior&ensp;6m - 1y</div>
                </div>
                <div class="er-mini blue">
                    <div class="er-mini-value"><?= $tenure_ranges['mid'] ?></div>
                    <div class="er-mini-label">Mid-Level&ensp;1 - 3y</div>
                </div>
                <div class="er-mini purple">
                    <div class="er-mini-value"><?= $tenure_ranges['senior'] ?></div>
                    <div class="er-mini-label">Senior&ensp;3 - 5y</div>
                </div>
                <div class="er-mini green">
                    <div class="er-mini-value"><?= $tenure_ranges['veteran'] ?></div>
                    <div class="er-mini-label">Veteran&ensp;5y+</div>
                </div>
            </div>

            <div class="er-chart-wrap">
                <canvas id="tenureChart"></canvas>
            </div>
        </div>
    </div>

    <!-- -- Profile Completion Status ------------------------------------------ -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-tasks" aria-hidden="true"></i> Profile Completion Status</h3>
        </div>
        <div class="card-body">
            <div class="er-mini-grid">
                <div class="er-mini green">
                    <div class="er-mini-value"><?= $approved ?></div>
                    <div class="er-mini-label">Approved</div>
                </div>
                <div class="er-mini blue">
                    <div class="er-mini-value"><?= $submitted ?></div>
                    <div class="er-mini-label">Submitted</div>
                </div>
                <div class="er-mini slate">
                    <div class="er-mini-value"><?= $draft ?></div>
                    <div class="er-mini-label">Draft</div>
                </div>
                <div class="er-mini red">
                    <div class="er-mini-value"><?= $rejected ?></div>
                    <div class="er-mini-label">Rejected</div>
                </div>
                <div class="er-mini green">
                    <div class="er-mini-value"><?= $profiles_complete ?></div>
                    <div class="er-mini-label">Fully Complete</div>
                </div>
                <div class="er-mini amber">
                    <div class="er-mini-value"><?= $profiles_incomplete ?></div>
                    <div class="er-mini-label">Incomplete</div>
                </div>
            </div>
        </div>
    </div>

    <!-- -- Contracts Expiring ------------------------------------------------- -->
    <?php if (!empty($contracts_expiring)): ?>
    <div class="card er-expiring">
        <div class="card-header">
            <h3><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Contracts Expiring in Next 90 Days</h3>
            <span class="badge badge-warning"><?= count($contracts_expiring) ?> contract<?= count($contracts_expiring) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
                <strong><?= count($contracts_expiring) ?> contract<?= count($contracts_expiring) !== 1 ? 's' : '' ?></strong> expiring soon.
                Please review and take necessary action.
            </div>

            <div class="table-responsive">
                <table class="table table-stack">
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
                                <td data-label="Employee"><strong><?= h($c['name']) ?></strong></td>
                                <td data-label="Job Title"><?= h($c['job_title']) ?></td>
                                <td data-label="Department"><?= h($c['department']) ?></td>
                                <td data-label="End Date"><?= date('d M Y', strtotime($c['end_date'])) ?></td>
                                <td data-label="Days Left">
                                    <span class="badge <?= $c['days_remaining'] <= 30 ? 'badge-danger' : ($c['days_remaining'] <= 60 ? 'badge-warning' : 'badge-info') ?>">
                                        <?= $c['days_remaining'] ?> days
                                    </span>
                                </td>
                                <td data-label="Urgency">
                                    <?php if ($c['days_remaining'] <= 30): ?>
                                        <span class="badge badge-danger"><i class="fas fa-fire" aria-hidden="true"></i> Urgent</span>
                                    <?php elseif ($c['days_remaining'] <= 60): ?>
                                        <span class="badge badge-warning"><i class="fas fa-clock" aria-hidden="true"></i> Action Needed</span>
                                    <?php else: ?>
                                        <span class="badge badge-info"><i class="fas fa-eye" aria-hidden="true"></i> Monitor</span>
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

</div><!-- /.page-stack -->

<script>
(function () {
    'use strict';

    // -- Shared chart defaults ------------------------------------------------
    // Chart colours come from the design tokens so the canvas matches the page.
    const rootStyle = getComputedStyle(document.documentElement);
    const tok = name => rootStyle.getPropertyValue(name).trim();

    Chart.defaults.font.family = tok('--font-body');
    Chart.defaults.color       = tok('--ink-300');

    // -- Contract Type doughnut -----------------------------------------------
    new Chart(document.getElementById('contractTypeChart'), {
        type: 'doughnut',
        data: {
            labels: ['Staff', 'Consultant', 'Temp', 'Casual'],
            datasets: [{
                data: [<?= $staff ?>, <?= $consultants ?>, <?= $temp ?>, <?= $casual ?>],
                backgroundColor: [tok('--blue-fg'), tok('--brand-500'), tok('--purple-fg'), tok('--slate-fg')],
                borderWidth: 3,
                borderColor: tok('--surface-card'),
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
                backgroundColor: [tok('--blue-fg'), tok('--red-fg')],
                borderWidth: 3,
                borderColor: tok('--surface-card'),
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
                backgroundColor: [tok('--red-fg'), tok('--amber-fg'), tok('--blue-fg'), tok('--purple-fg'), tok('--green-fg')],
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
                    grid: { color: tok('--ink-50') }
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
