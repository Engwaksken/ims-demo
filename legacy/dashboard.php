<?php
$page_title = 'Dashboard';

require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';

require_login();

$restricted_roles = ['Member', 'Applicant', 'job_seeker'];
if (in_array($_SESSION['role'], $restricted_roles)) {
    redirect_by_role($_SESSION['role']);
}

$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once 'includes/header.php';

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

// -- Dashboard sections (editable under Role Permissions) -------
$is_admin     = ims_allowed('dashboard.admin_tools', ['Administrator', 'Operations/Admin'], 'See admin shortcuts on the dashboard');
$can_programs = ims_allowed('dashboard.programs', ['Administrator', 'Operations/Admin', 'Programs Lead', 'Program Manager', 'Program Officer', 'MEAL Lead'], 'See the Programs & Participants dashboard tabs');
$can_kpi_hr   = ims_allowed('dashboard.kpi_hr', ['Administrator', 'Operations/Admin', 'HR', 'Program Director', 'Executive Director', 'Finance', 'Accountant'], 'See the KPIs & HR dashboard tab');
$can_indicators = ims_allowed('dashboard.indicators', ['Administrator', 'Operations/Admin', 'Programs Lead', 'MEAL Lead', 'Program Manager', 'Program Officer'], 'See the Indicators dashboard tab');

// -- Stats -----------------------------------------------------
$stats = [];

$result = $conn->query("SELECT COUNT(*) as t FROM programs"); $stats['total_programs'] = (int)$result->fetch_assoc()['t'];
$result = $conn->query("SELECT COUNT(*) as t FROM programs WHERE status='Active'"); $stats['active_programs'] = (int)$result->fetch_assoc()['t'];
$result = $conn->query("SELECT COUNT(*) as t FROM projects"); $stats['total_projects'] = (int)$result->fetch_assoc()['t'];
$result = $conn->query("SELECT COUNT(*) as t FROM projects WHERE status='Ongoing'"); $stats['ongoing_projects'] = (int)$result->fetch_assoc()['t'];
$result = $conn->query("SELECT COUNT(DISTINCT beneficiary_id) as t FROM (SELECT beneficiary_id FROM programs_beneficiaries WHERE status='Active' UNION SELECT beneficiary_id FROM project_beneficiaries WHERE status='Active') x"); $stats['total_participants'] = (int)$result->fetch_assoc()['t'];
$result = $conn->query("SELECT COUNT(*) as t FROM indicators"); $stats['total_indicators'] = (int)$result->fetch_assoc()['t'];
$result = $conn->query("SELECT COUNT(*) as t FROM hub_events WHERE MONTH(event_date)=MONTH(CURRENT_DATE()) AND YEAR(event_date)=YEAR(CURRENT_DATE())"); $stats['events_this_month'] = (int)$result->fetch_assoc()['t'];
$result = $conn->query("SELECT COUNT(*) as t FROM members WHERE membership_status='Active'"); $stats['active_memberships'] = (int)$result->fetch_assoc()['t'];

// -- Program / Project status ----------------------------------
$program_status = ['Active'=>0,'Completed'=>0,'Pending'=>0,'Cancelled'=>0];
$result = $conn->query("SELECT status, COUNT(*) as c FROM programs GROUP BY status");
while ($r = $result->fetch_assoc()) { if (isset($program_status[$r['status']])) $program_status[$r['status']] = (int)$r['c']; }

$project_status = ['Ongoing'=>0,'Completed'=>0,'Pending'=>0,'Cancelled'=>0];
$result = $conn->query("SELECT status, COUNT(*) as c FROM projects GROUP BY status");
while ($r = $result->fetch_assoc()) { $project_status[$r['status']] = (int)($r['c']); }

// -- Beneficiary trend (12 months) ----------------------------
$beneficiary_labels = [];
$beneficiary_trend  = [];
for ($i = 11; $i >= 0; $i--) {
    $m  = date('Y-m', strtotime("-$i months"));
    $ml = date('M Y', strtotime("-$i months"));
    $r1 = $conn->query("SELECT COUNT(*) as c FROM programs_beneficiaries WHERE DATE_FORMAT(enrollment_date,'%Y-%m')='$m'")->fetch_assoc()['c'];
    $r2 = $conn->query("SELECT COUNT(*) as c FROM project_beneficiaries  WHERE DATE_FORMAT(enrollment_date,'%Y-%m')='$m'")->fetch_assoc()['c'];
    $beneficiary_labels[] = $ml;
    $beneficiary_trend[]  = (int)$r1 + (int)$r2;
}

// -- Indicator achievement -------------------------------------
$indicator_achievement = ['achieved'=>0,'on_track'=>0,'below_target'=>0];
$result = $conn->query("SELECT CASE WHEN target_value>0 THEN (current_value/target_value*100) ELSE 0 END AS pct FROM indicators WHERE target_value>0");
while ($r = $result->fetch_assoc()) {
    $p = (float)$r['pct'];
    if ($p >= 100) $indicator_achievement['achieved']++;
    elseif ($p >= 50) $indicator_achievement['on_track']++;
    else $indicator_achievement['below_target']++;
}

// -- Gender distribution ---------------------------------------
$gender_distribution = ['Male'=>0,'Female'=>0,'Other'=>0];
$result = $conn->query("SELECT b.gender, COUNT(DISTINCT b.beneficiary_id) as c FROM beneficiaries b WHERE b.beneficiary_id IN (SELECT beneficiary_id FROM programs_beneficiaries WHERE status='Active' UNION SELECT beneficiary_id FROM project_beneficiaries WHERE status='Active') GROUP BY b.gender");
while ($r = $result->fetch_assoc()) { if (isset($gender_distribution[$r['gender']])) $gender_distribution[$r['gender']] = (int)$r['c']; }

// -- PWD stats -------------------------------------------------
$pwd_stats = $conn->query("SELECT SUM(CASE WHEN is_pwd=1 THEN 1 ELSE 0 END) as pwd_count, COUNT(DISTINCT beneficiary_id) as total_count FROM beneficiaries WHERE beneficiary_id IN (SELECT beneficiary_id FROM programs_beneficiaries WHERE status='Active' UNION SELECT beneficiary_id FROM project_beneficiaries WHERE status='Active')")->fetch_assoc();

// -- Recent programs & projects --------------------------------
$recent_programs = $conn->query("SELECT p.*, d.donor_name FROM programs p LEFT JOIN donors d ON p.donor_id=d.donor_id ORDER BY p.created_at DESC LIMIT 3")->fetch_all(MYSQLI_ASSOC);
$recent_projects = $conn->query("SELECT p.*, d.donor_name FROM projects p LEFT JOIN donors d ON p.donor_id=d.donor_id ORDER BY p.created_at DESC LIMIT 3")->fetch_all(MYSQLI_ASSOC);

// -- Top indicators --------------------------------------------
$indicators_performance = $conn->query("SELECT i.*, p.project_name, p.project_code, pr.program_name, pr.program_code, CASE WHEN i.project_id IS NOT NULL THEN 'Project' WHEN i.program_id IS NOT NULL THEN 'Program' ELSE 'General' END as context_type, CASE WHEN i.project_id IS NOT NULL THEN p.project_code WHEN i.program_id IS NOT NULL THEN pr.program_code ELSE 'N/A' END as context_code, CASE WHEN i.target_value>0 THEN (i.current_value/i.target_value*100) ELSE 0 END as achievement_percentage FROM indicators i LEFT JOIN projects p ON i.project_id=p.project_id LEFT JOIN programs pr ON i.program_id=pr.id WHERE (p.status='Ongoing' OR pr.status='Active') ORDER BY achievement_percentage DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// -- Upcoming events -------------------------------------------
$upcoming_events = $conn->query("SELECT * FROM hub_events WHERE event_date >= CURDATE() AND event_status != 'Cancelled' ORDER BY event_date ASC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// -- KPI data --------------------------------------------------
$kpi_status_dist = ['Draft'=>0,'Submitted'=>0,'Approved'=>0,'Rejected'=>0,'Completed'=>0];
$result = $conn->query("SELECT status, COUNT(*) as c FROM kpis WHERE fiscal_year=YEAR(CURDATE()) GROUP BY status");
while ($r = $result->fetch_assoc()) { if (isset($kpi_status_dist[$r['status']])) $kpi_status_dist[$r['status']] = (int)$r['c']; }

$kpi_submission_labels = [];
$kpi_submission_trend  = [];
for ($i = 5; $i >= 0; $i--) {
    $m  = date('Y-m', strtotime("-$i months"));
    $ml = date('M Y', strtotime("-$i months"));
    $c  = $conn->query("SELECT COUNT(*) as c FROM kpis WHERE DATE_FORMAT(submitted_at,'%Y-%m')='$m' AND status!='Draft'")->fetch_assoc()['c'];
    $kpi_submission_labels[] = $ml;
    $kpi_submission_trend[]  = (int)$c;
}

$dept_kpi_stats = [];
$dq = $conn->query("SELECT d.department_id, d.department_name, COUNT(k.kpi_id) AS total_kpis, SUM(CASE WHEN k.status='Approved' THEN 1 ELSE 0 END) AS approved_kpis, AVG(k.overall_achievement) AS avg_achievement FROM departments d LEFT JOIN employee_directory e ON d.department_id=e.department_id LEFT JOIN kpis k ON e.user_id=k.user_id AND k.fiscal_year=YEAR(CURDATE()) GROUP BY d.department_id, d.department_name HAVING COUNT(k.kpi_id)>0 ORDER BY avg_achievement DESC LIMIT 5");
if ($dq) { $dept_kpi_stats = $dq->fetch_all(MYSQLI_ASSOC); }

$profile_stats = ['Approved'=>0,'Submitted'=>0,'Draft'=>0,'Rejected'=>0];
$result = $conn->query("SELECT status, COUNT(*) as c FROM employee_directory GROUP BY status");
while ($r = $result->fetch_assoc()) { if (isset($profile_stats[$r['status']])) $profile_stats[$r['status']] = (int)$r['c']; }

// -- Tab definitions (role-gated) ------------------------------
$tabs = [
    'overview'   => ['label'=>'Overview',           'icon'=>'fa-gauge-high',      'show'=> true],
    'programs'   => ['label'=>'Programs & Projects', 'icon'=>'fa-diagram-project', 'show'=> $can_programs || $is_admin],
    'participants'=> ['label'=>'Participants',        'icon'=>'fa-users',           'show'=> $can_programs],
    'indicators' => ['label'=>'Indicators',           'icon'=>'fa-chart-line',      'show'=> $can_indicators],
    'kpi_hr'     => ['label'=>'KPIs & HR',            'icon'=>'fa-bullseye',        'show'=> $can_kpi_hr],
    'events'     => ['label'=>'Events',               'icon'=>'fa-calendar-alt',    'show'=> true],
];

$visible_tabs = array_filter($tabs, fn($t) => $t['show']);
$first_tab    = array_key_first($visible_tabs);
$active_tab   = $_GET['tab'] ?? $first_tab;
if (!isset($visible_tabs[$active_tab])) $active_tab = $first_tab;
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/dashboard.css">

<div class="assets-wrap">

    <!-- -- Page Hero -------------------------------------------- -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-gauge-high"></i> Dashboard</h1>
            <p>Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? $role) ?> &mdash; <?= date('l, d F Y') ?></p>
        </div>
        <div class="hero-actions">
            <?php if ($is_admin): ?>
                <a href="manage_users.php" class="btn btn-primary">
                    <i class="fas fa-users-cog"></i> Manage Users
                </a>
            <?php endif; ?>
            <a href="reports" class="btn btn-dark">
                <i class="fas fa-chart-column"></i> Reports
            </a>
        </div>
    </div>

    <!-- -- Stats strip (always visible) ------------------------ -->
    <div class="stats-grid dash-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-sitemap"></i></div>
            <div class="stat-info">
                <span class="stat-label">Active Programs</span>
                <span class="stat-value"><?= $stats['active_programs'] ?><span class="stat-denom"> / <?= $stats['total_programs'] ?></span></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-project-diagram"></i></div>
            <div class="stat-info">
                <span class="stat-label">Ongoing Projects</span>
                <span class="stat-value"><?= $stats['ongoing_projects'] ?><span class="stat-denom"> / <?= $stats['total_projects'] ?></span></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <span class="stat-label">Active Participants</span>
                <span class="stat-value"><?= number_format($stats['total_participants']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <span class="stat-label">Indicators</span>
                <span class="stat-value"><?= number_format($stats['total_indicators']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-red"><i class="fas fa-calendar"></i></div>
            <div class="stat-info">
                <span class="stat-label">Events This Month</span>
                <span class="stat-value"><?= $stats['events_this_month'] ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-wheelchair"></i></div>
            <div class="stat-info">
                <span class="stat-label">PWD Participants</span>
                <span class="stat-value">
                    <?= number_format((int)$pwd_stats['pwd_count']) ?>
                    <span class="stat-denom"> (<?= $pwd_stats['total_count'] > 0 ? round(($pwd_stats['pwd_count']/$pwd_stats['total_count'])*100,1) : 0 ?>%)</span>
                </span>
            </div>
        </div>
    </div>

    <!-- -- Tab Navigation -------------------------------------- -->
    <nav class="tab-nav dash-tab-nav" role="tablist">
        <?php foreach ($visible_tabs as $key => $tab): ?>
            <a
                href="?tab=<?= $key ?>"
                class="tab-btn<?= $active_tab === $key ? ' active' : '' ?>"
                role="tab"
                aria-selected="<?= $active_tab === $key ? 'true' : 'false' ?>"
            >
                <i class="fas <?= $tab['icon'] ?>"></i>
                <?= $tab['label'] ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- ----------------------------------------------------------
         TAB: OVERVIEW
    ----------------------------------------------------------- -->
    <?php if ($active_tab === 'overview'): ?>

    <div class="dash-grid-2">
        <!-- Program status donut -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-sitemap" style="color:var(--brand-500)"></i> Program Status</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap"><canvas id="programStatusChart"></canvas></div>
            </div>
        </div>

        <!-- Project status donut -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-project-diagram" style="color:var(--blue-fg)"></i> Project Status</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap"><canvas id="projectStatusChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- -- Overview quick-lists ------------------------------ -->
    <div class="dash-grid-2">
        <!-- Recent programs list -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-sitemap" style="color:var(--brand-500)"></i> Recent Programs</h3>
                <a href="programs" class="btn btn-sm btn-soft">View All</a>
            </div>
            <div class="panel-body no-pad">
                <?php if (empty($recent_programs)): ?>
                    <div class="dash-empty"><i class="fas fa-sitemap"></i><p>No programs found</p></div>
                <?php else: ?>
                    <?php foreach ($recent_programs as $p): ?>
                    <div class="recent-item" style="--item-accent:var(--brand-500)">
                        <div class="recent-item-head">
                            <div>
                                <span class="recent-code"><?= htmlspecialchars($p['program_code']) ?></span>
                                <strong class="recent-name"><?= htmlspecialchars($p['program_name']) ?></strong>
                            </div>
                            <span class="badge <?= $p['status']==='Active' ? 'badge-issued' : ($p['status']==='Completed' ? 'badge-approved' : 'badge-pending') ?>">
                                <?= htmlspecialchars($p['status']) ?>
                            </span>
                        </div>
                        <div class="recent-meta">
                            <span><i class="fas fa-hand-holding-usd"></i> <?= htmlspecialchars($p['donor_name'] ?? 'N/A') ?></span>
                            <span><i class="fas fa-calendar"></i> <?= date('M Y', strtotime($p['start_date'])) ?></span>
                        </div>
                        <a href="program-details?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-soft" style="margin-top:8px;">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent projects list -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-project-diagram" style="color:var(--blue-fg)"></i> Recent Projects</h3>
                <a href="projects" class="btn btn-sm btn-soft">View All</a>
            </div>
            <div class="panel-body no-pad">
                <?php if (empty($recent_projects)): ?>
                    <div class="dash-empty"><i class="fas fa-project-diagram"></i><p>No projects found</p></div>
                <?php else: ?>
                    <?php foreach ($recent_projects as $p): ?>
                    <div class="recent-item" style="--item-accent:var(--blue-fg)">
                        <div class="recent-item-head">
                            <div>
                                <span class="recent-code" style="color:var(--blue-fg)"><?= htmlspecialchars($p['project_code']) ?></span>
                                <strong class="recent-name"><?= htmlspecialchars($p['project_name']) ?></strong>
                            </div>
                            <span class="badge <?= $p['status']==='Ongoing' ? 'badge-issued' : ($p['status']==='Completed' ? 'badge-approved' : ($p['status']==='Pending' ? 'badge-pending' : 'badge-rejected')) ?>">
                                <?= htmlspecialchars($p['status']) ?>
                            </span>
                        </div>
                        <div class="recent-meta">
                            <span><i class="fas fa-hand-holding-usd"></i> <?= htmlspecialchars($p['donor_name'] ?? 'N/A') ?></span>
                            <span><i class="fas fa-calendar"></i> <?= date('M Y', strtotime($p['start_date'])) ?></span>
                        </div>
                        <a href="project-details?id=<?= (int)$p['project_id'] ?>" class="btn btn-sm btn-soft" style="margin-top:8px;">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>


    <!-- ----------------------------------------------------------
         TAB: PROGRAMS & PROJECTS
    ----------------------------------------------------------- -->
    <?php if ($active_tab === 'programs' && ($can_programs || $is_admin)): ?>

    <div class="dash-grid-2">
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-sitemap" style="color:var(--brand-500)"></i> Program Status Distribution</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap"><canvas id="programStatusChartTab"></canvas></div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-project-diagram" style="color:var(--blue-fg)"></i> Project Status Distribution</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap"><canvas id="projectStatusChartTab"></canvas></div>
            </div>
        </div>
    </div>

    <div class="dash-grid-2">
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-sitemap" style="color:var(--brand-500)"></i> All Recent Programs</h3>
                <a href="programs" class="btn btn-sm btn-soft">View All</a>
            </div>
            <div class="panel-body no-pad">
                <?php foreach ($recent_programs as $p): ?>
                <div class="recent-item" style="--item-accent:var(--brand-500)">
                    <div class="recent-item-head">
                        <div>
                            <span class="recent-code"><?= htmlspecialchars($p['program_code']) ?></span>
                            <strong class="recent-name"><?= htmlspecialchars($p['program_name']) ?></strong>
                        </div>
                        <span class="badge <?= $p['status']==='Active' ? 'badge-issued' : ($p['status']==='Completed' ? 'badge-approved' : 'badge-pending') ?>">
                            <?= htmlspecialchars($p['status']) ?>
                        </span>
                    </div>
                    <div class="recent-meta">
                        <span><i class="fas fa-hand-holding-usd"></i> <?= htmlspecialchars($p['donor_name'] ?? 'N/A') ?></span>
                        <span><i class="fas fa-calendar"></i> <?= date('M Y', strtotime($p['start_date'])) ?></span>
                    </div>
                    <a href="program-details?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-soft" style="margin-top:8px;"><i class="fas fa-eye"></i> View</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-project-diagram" style="color:var(--blue-fg)"></i> All Recent Projects</h3>
                <a href="projects" class="btn btn-sm btn-soft">View All</a>
            </div>
            <div class="panel-body no-pad">
                <?php foreach ($recent_projects as $p): ?>
                <div class="recent-item" style="--item-accent:var(--blue-fg)">
                    <div class="recent-item-head">
                        <div>
                            <span class="recent-code" style="color:var(--blue-fg)"><?= htmlspecialchars($p['project_code']) ?></span>
                            <strong class="recent-name"><?= htmlspecialchars($p['project_name']) ?></strong>
                        </div>
                        <span class="badge <?= $p['status']==='Ongoing' ? 'badge-issued' : ($p['status']==='Completed' ? 'badge-approved' : ($p['status']==='Pending' ? 'badge-pending' : 'badge-rejected')) ?>">
                            <?= htmlspecialchars($p['status']) ?>
                        </span>
                    </div>
                    <div class="recent-meta">
                        <span><i class="fas fa-hand-holding-usd"></i> <?= htmlspecialchars($p['donor_name'] ?? 'N/A') ?></span>
                        <span><i class="fas fa-calendar"></i> <?= date('M Y', strtotime($p['start_date'])) ?></span>
                    </div>
                    <a href="project-details?id=<?= (int)$p['project_id'] ?>" class="btn btn-sm btn-soft" style="margin-top:8px;"><i class="fas fa-eye"></i> View</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>


    <!-- ----------------------------------------------------------
         TAB: PARTICIPANTS
    ----------------------------------------------------------- -->
    <?php if ($active_tab === 'participants' && $can_programs): ?>

    <div class="dash-grid-2">
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-chart-line"></i> Participant Enrollment Trend (12 months)</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap chart-tall"><canvas id="beneficiaryChart"></canvas></div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-venus-mars"></i> Gender Distribution</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap"><canvas id="genderChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-wheelchair"></i> PWD Participation</h3>
        </div>
        <div class="panel-body">
            <div class="pwd-summary">
                <div class="pwd-stat">
                    <span class="pwd-number"><?= number_format((int)$pwd_stats['pwd_count']) ?></span>
                    <span class="pwd-label">PWD Participants</span>
                </div>
                <div class="pwd-stat">
                    <span class="pwd-number"><?= number_format((int)$pwd_stats['total_count']) ?></span>
                    <span class="pwd-label">Total Active Participants</span>
                </div>
                <div class="pwd-stat">
                    <span class="pwd-number" style="color:var(--green-fg)">
                        <?= $pwd_stats['total_count'] > 0 ? round(($pwd_stats['pwd_count']/$pwd_stats['total_count'])*100, 1) : 0 ?>%
                    </span>
                    <span class="pwd-label">PWD Inclusion Rate</span>
                </div>
            </div>
            <!-- PWD progress bar -->
            <?php $pwd_pct = $pwd_stats['total_count'] > 0 ? min(100, round(($pwd_stats['pwd_count']/$pwd_stats['total_count'])*100,1)) : 0; ?>
            <div style="margin-top:16px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ink-300);margin-bottom:6px;">
                    <span>PWD inclusion</span><span><?= $pwd_pct ?>%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width:<?= $pwd_pct ?>%;background:var(--green-fg);"></div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>


    <!-- ----------------------------------------------------------
         TAB: INDICATORS
    ----------------------------------------------------------- -->
    <?php if ($active_tab === 'indicators' && $can_indicators): ?>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-chart-bar"></i> Indicator Achievement Status</h3>
        </div>
        <div class="panel-body">
            <div class="chart-wrap"><canvas id="indicatorChart"></canvas></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-trophy"></i> Top Performing Indicators</h3>
            <a href="indicators" class="btn btn-sm btn-soft">View All</a>
        </div>
        <div class="panel-body">
            <?php if (empty($indicators_performance)): ?>
                <div class="dash-empty"><i class="fas fa-chart-line"></i><p>No indicators found</p></div>
            <?php else: ?>
                <?php foreach ($indicators_performance as $ind):
                    $pct   = (float)$ind['achievement_percentage'];
                    $color = $pct >= 100 ? 'var(--green-fg)' : ($pct >= 50 ? 'var(--amber-fg)' : 'var(--red-fg)');
                    $track = $pct >= 100 ? '#16a34a' : ($pct >= 50 ? '#d97706' : '#dc2626');
                ?>
                <div class="indicator-row">
                    <div class="indicator-info">
                        <strong class="indicator-name"><?= htmlspecialchars($ind['indicator_name']) ?></strong>
                        <span class="indicator-ctx">
                            <?php if ($ind['context_type']==='Program'): ?>
                                <i class="fas fa-sitemap" style="color:var(--brand-500)"></i>
                                <span style="color:var(--brand-500)"><?= htmlspecialchars($ind['context_code']) ?></span>
                            <?php elseif ($ind['context_type']==='Project'): ?>
                                <i class="fas fa-project-diagram" style="color:var(--blue-fg)"></i>
                                <span style="color:var(--blue-fg)"><?= htmlspecialchars($ind['context_code']) ?></span>
                            <?php else: ?>
                                <i class="fas fa-folder"></i> General
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="indicator-right">
                        <span class="indicator-pct" style="color:<?= $color ?>"><?= number_format($pct, 1) ?>%</span>
                        <div class="progress-track" style="width:200px;">
                            <div class="progress-fill" style="width:<?= min($pct,100) ?>%;background:<?= $track ?>;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>


    <!-- ----------------------------------------------------------
         TAB: KPIs & HR
    ----------------------------------------------------------- -->
    <?php if ($active_tab === 'kpi_hr' && $can_kpi_hr): ?>

    <div class="dash-grid-2">
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-bullseye"></i> KPI Status Distribution (<?= date('Y') ?>)</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap"><canvas id="kpiStatusChart"></canvas></div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-user-circle"></i> Employee Profile Status</h3>
            </div>
            <div class="panel-body">
                <div class="chart-wrap"><canvas id="profileStatusChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-chart-area"></i> KPI Submission Trend (6 months)</h3>
        </div>
        <div class="panel-body">
            <div class="chart-wrap chart-tall"><canvas id="kpiTrendChart"></canvas></div>
        </div>
    </div>

    <?php if (!empty($dept_kpi_stats)): ?>
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-building"></i> Department KPI Performance</h3>
            <a href="kpi-reports" class="btn btn-sm btn-soft">View Reports</a>
        </div>
        <div class="panel-body">
            <div class="chart-wrap chart-tall"><canvas id="deptKpiChart"></canvas></div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>


    <!-- ----------------------------------------------------------
         TAB: EVENTS
    ----------------------------------------------------------- -->
    <?php if ($active_tab === 'events'): ?>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-calendar-alt"></i> Upcoming Events</h3>
            <a href="events" class="btn btn-sm btn-soft">View All</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Event</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Venue</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($upcoming_events)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:48px 16px;color:var(--ink-200);font-size:13px;">
                            <i class="fas fa-calendar-xmark" style="display:block;font-size:1.6rem;margin-bottom:10px;opacity:.3;"></i>
                            No upcoming events scheduled.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($upcoming_events as $ev):
                    $type_badge = ['Training'=>'badge-pending','Workshop'=>'badge-approved','Meeting'=>'badge-assigned','Conference'=>'badge-issued','Other'=>'badge-returned'][$ev['event_type']] ?? 'badge-returned';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ev['event_title']) ?></strong></td>
                    <td><span class="badge <?= $type_badge ?>"><?= htmlspecialchars($ev['event_type']) ?></span></td>
                    <td class="note"><?= date('d M Y', strtotime($ev['event_date'])) ?></td>
                    <td class="note"><?= $ev['start_time'] ? date('h:i A', strtotime($ev['start_time'])) : 'TBA' ?></td>
                    <td class="note"><?= htmlspecialchars($ev['venue'] ?? 'TBA') ?></td>
                    <td>
                        <span class="badge <?= $ev['event_status']==='Planned' ? 'badge-pending' : 'badge-approved' ?>">
                            <?= htmlspecialchars($ev['event_status']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="event-attendance?id=<?= (int)$ev['event_id'] ?>" class="btn btn-sm btn-soft" title="Attendance">
                            <i class="fas fa-users"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

</div><!-- /.assets-wrap -->

<script>
Chart.defaults.font.family = getComputedStyle(document.documentElement).getPropertyValue('--font-body') || 'DM Sans, sans-serif';
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#374151';

const COLORS = {
    green:  '#16a34a',
    blue:   '#1d4ed8',
    amber:  '#d97706',
    red:    '#dc2626',
    slate:  '#475569',
    purple: '#7c3aed',
    brand:  '#f97316',
};

const donutOpts = (extra = {}) => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom', labels: { padding: 14, font: { size: 12 } } },
        tooltip: {
            callbacks: {
                label: ctx => {
                    const total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                    const pct   = total > 0 ? ((ctx.parsed/total)*100).toFixed(1) : 0;
                    return `${ctx.label}: ${ctx.parsed} (${pct}%)`;
                }
            }
        }
    },
    ...extra
});

const lineOpts = (color) => ({
    responsive: true,
    maintainAspectRatio: false,
    scales: { y: { beginAtZero: true, ticks: { callback: v => Number.isInteger(v) ? v : '' } } },
    plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(13,17,23,.85)', padding: 12 } }
});

// -- Program Status ---------------------------------------------
// Overview tab uses #programStatusChart, Programs tab uses #programStatusChartTab (unique ids).
const programCtx = document.getElementById('programStatusChart') || document.getElementById('programStatusChartTab');
if (programCtx) {
    new Chart(programCtx, {
        type: 'doughnut',
        data: {
            labels: ['Active','Completed','Pending','Cancelled'],
            datasets: [{ data: [<?= $program_status['Active'] ?>,<?= $program_status['Completed'] ?>,<?= $program_status['Pending'] ?>,<?= $program_status['Cancelled'] ?>], backgroundColor: [COLORS.green, COLORS.blue, COLORS.amber, COLORS.red], borderWidth: 2, borderColor: '#fff' }]
        },
        options: donutOpts()
    });
}

// -- Project Status ---------------------------------------------
const projectCtx = document.getElementById('projectStatusChart') || document.getElementById('projectStatusChartTab');
if (projectCtx) {
    new Chart(projectCtx, {
        type: 'doughnut',
        data: {
            labels: ['Ongoing','Completed','Pending','Cancelled'],
            datasets: [{ data: [<?= $project_status['Ongoing'] ?>,<?= $project_status['Completed'] ?>,<?= $project_status['Pending'] ?>,<?= $project_status['Cancelled'] ?>], backgroundColor: [COLORS.green, COLORS.blue, COLORS.amber, COLORS.red], borderWidth: 2, borderColor: '#fff' }]
        },
        options: donutOpts()
    });
}

// -- Beneficiary Trend ------------------------------------------
const benCtx = document.getElementById('beneficiaryChart');
if (benCtx) {
    new Chart(benCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($beneficiary_labels) ?>,
            datasets: [{ label: 'Enrolled', data: <?= json_encode($beneficiary_trend) ?>, borderColor: COLORS.brand, backgroundColor: 'rgba(249,115,22,.1)', tension: .4, fill: true, borderWidth: 3, pointBackgroundColor: COLORS.brand, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 4 }]
        },
        options: lineOpts(COLORS.brand)
    });
}

// -- Indicator Achievement --------------------------------------
const indCtx = document.getElementById('indicatorChart');
if (indCtx) {
    new Chart(indCtx, {
        type: 'bar',
        data: {
            labels: ['Achieved (=100%)', 'On Track (50-99%)', 'Below Target (<50%)'],
            datasets: [{ data: [<?= $indicator_achievement['achieved'] ?>,<?= $indicator_achievement['on_track'] ?>,<?= $indicator_achievement['below_target'] ?>], backgroundColor: [COLORS.green, COLORS.amber, COLORS.red], borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: v => Number.isInteger(v) ? v : '' } } }, plugins: { legend: { display: false } } }
    });
}

// -- Gender -----------------------------------------------------
const genderCtx = document.getElementById('genderChart');
if (genderCtx) {
    new Chart(genderCtx, {
        type: 'pie',
        data: {
            labels: ['Male','Female','Other'],
            datasets: [{ data: [<?= $gender_distribution['Male'] ?>,<?= $gender_distribution['Female'] ?>,<?= $gender_distribution['Other'] ?>], backgroundColor: [COLORS.blue,'#db2777',COLORS.purple], borderWidth: 2, borderColor: '#fff' }]
        },
        options: donutOpts()
    });
}

// -- KPI Status -------------------------------------------------
const kpiCtx = document.getElementById('kpiStatusChart');
if (kpiCtx) {
    new Chart(kpiCtx, {
        type: 'pie',
        data: {
            labels: ['Draft','Submitted','Approved','Rejected','Completed'],
            datasets: [{ data: [<?= $kpi_status_dist['Draft'] ?>,<?= $kpi_status_dist['Submitted'] ?>,<?= $kpi_status_dist['Approved'] ?>,<?= $kpi_status_dist['Rejected'] ?>,<?= $kpi_status_dist['Completed'] ?>], backgroundColor: [COLORS.slate, COLORS.blue, COLORS.green, COLORS.red, '#059669'], borderWidth: 2, borderColor: '#fff' }]
        },
        options: donutOpts()
    });
}

// -- Employee Profile -------------------------------------------
const profileCtx = document.getElementById('profileStatusChart');
if (profileCtx) {
    new Chart(profileCtx, {
        type: 'doughnut',
        data: {
            labels: ['Approved','Submitted','Draft','Rejected'],
            datasets: [{ data: [<?= $profile_stats['Approved'] ?>,<?= $profile_stats['Submitted'] ?>,<?= $profile_stats['Draft'] ?>,<?= $profile_stats['Rejected'] ?>], backgroundColor: [COLORS.green, COLORS.blue, COLORS.slate, COLORS.red], borderWidth: 2, borderColor: '#fff' }]
        },
        options: donutOpts()
    });
}

// -- KPI Trend --------------------------------------------------
const kpiTrendCtx = document.getElementById('kpiTrendChart');
if (kpiTrendCtx) {
    new Chart(kpiTrendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($kpi_submission_labels) ?>,
            datasets: [{ label: 'KPIs Submitted', data: <?= json_encode($kpi_submission_trend) ?>, borderColor: COLORS.purple, backgroundColor: 'rgba(124,58,237,.12)', tension: .4, fill: true, borderWidth: 3, pointBackgroundColor: COLORS.purple, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 5 }]
        },
        options: lineOpts(COLORS.purple)
    });
}

// -- Dept KPI ---------------------------------------------------
<?php if (!empty($dept_kpi_stats)): ?>
const deptCtx = document.getElementById('deptKpiChart');
if (deptCtx) {
    const avgAchievement = <?= json_encode(array_column($dept_kpi_stats, 'avg_achievement')) ?>;
    new Chart(deptCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($dept_kpi_stats, 'department_name')) ?>,
            datasets: [
                { label: 'Total KPIs',    data: <?= json_encode(array_column($dept_kpi_stats, 'total_kpis')) ?>,    backgroundColor: COLORS.blue,  borderWidth: 0 },
                { label: 'Approved KPIs', data: <?= json_encode(array_column($dept_kpi_stats, 'approved_kpis')) ?>, backgroundColor: COLORS.green, borderWidth: 0 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            scales: { x: { beginAtZero: true, ticks: { callback: v => Number.isInteger(v) ? v : '' } } },
            plugins: {
                legend: { position: 'top', labels: { padding: 14 } },
                tooltip: { backgroundColor:'rgba(13,17,23,.85)', padding:12, callbacks: { afterLabel: ctx => `Avg Achievement: ${parseFloat(avgAchievement[ctx.dataIndex]).toFixed(1)}%` } }
            }
        }
    });
}
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>