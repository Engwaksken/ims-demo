<?php
$page_title = 'Participants Reports & Analytics';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once __DIR__ . '/includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Get filters
$project_filter = isset($_GET['project']) ? intval($_GET['project']) : 0;
$program_filter = isset($_GET['program']) ? intval($_GET['program']) : 0;
$gender_filter = isset($_GET['gender']) ? sanitize_input($_GET['gender']) : '';
$district_filter = isset($_GET['district']) ? sanitize_input($_GET['district']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_input($_GET['date_from']) : date('Y-m-d', strtotime('-1 year'));
$date_to = isset($_GET['date_to']) ? sanitize_input($_GET['date_to']) : date('Y-m-d');

// Dates may be interpolated into SQL: accept strict YYYY-MM-DD only.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-1 year'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}

// Build WHERE clause
$where = "1=1";
if ($gender_filter) {
    $where .= " AND b.gender = '" . $conn->real_escape_string($gender_filter) . "'";
}
if ($district_filter) {
    $where .= " AND b.district = '" . $conn->real_escape_string($district_filter) . "'";
}

// Fetch all beneficiaries with project and program info
$beneficiaries = [];
$query = "SELECT b.*, 
    (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id) as project_count,
    (SELECT COUNT(*) FROM program_beneficiaries prb WHERE prb.beneficiary_id = b.beneficiary_id) as program_count,
    (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id AND pb.status = 'Active') as active_projects,
    (SELECT COUNT(*) FROM program_beneficiaries prb WHERE prb.beneficiary_id = b.beneficiary_id AND prb.status = 'Active') as active_programs,
    (SELECT COUNT(*) FROM event_registrations ea WHERE ea.beneficiary_id = b.beneficiary_id AND ea.attendance_status = 'Attended') as events_attended
    FROM beneficiaries b 
    WHERE $where
    ORDER BY b.created_at DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $beneficiaries[] = $row;
}

// Calculate statistics
$stats = [
    'total' => count($beneficiaries),
    'male' => count(array_filter($beneficiaries, function($b) { return $b['gender'] == 'Male'; })),
    'female' => count(array_filter($beneficiaries, function($b) { return $b['gender'] == 'Female'; })),
    'pwd' => count(array_filter($beneficiaries, function($b) { return $b['is_pwd'] == 1; })),
    'with_programs' => count(array_filter($beneficiaries, function($b) { return $b['program_count'] > 0; })),
    'with_projects' => count(array_filter($beneficiaries, function($b) { return $b['project_count'] > 0; })),
    'active_in_programs' => count(array_filter($beneficiaries, function($b) { return $b['active_programs'] > 0; })),
    'active_in_projects' => count(array_filter($beneficiaries, function($b) { return $b['active_projects'] > 0; }))
];

// Age distribution
$age_distribution = [
    '<18' => 0,
    '18-25' => 0,
    '26-35' => 0,
    '36-50' => 0,
    '50+' => 0
];

foreach ($beneficiaries as $beneficiary) {
    if ($beneficiary['date_of_birth']) {
        $dob = new DateTime($beneficiary['date_of_birth']);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
        
        if ($age < 18) {
            $age_distribution['<18']++;
        } elseif ($age >= 18 && $age <= 25) {
            $age_distribution['18-25']++;
        } elseif ($age >= 26 && $age <= 35) {
            $age_distribution['26-35']++;
        } elseif ($age >= 36 && $age <= 50) {
            $age_distribution['36-50']++;
        } else {
            $age_distribution['50+']++;
        }
    }
}

// Education level distribution
$education_stats = [];
$result = $conn->query("SELECT education_level, COUNT(*) as count FROM beneficiaries WHERE $where AND education_level IS NOT NULL GROUP BY education_level");
while ($row = $result->fetch_assoc()) {
    $education_stats[$row['education_level']] = $row['count'];
}

// District distribution (top 10)
$district_stats = [];
$result = $conn->query("SELECT district, COUNT(*) as count FROM beneficiaries WHERE $where AND district IS NOT NULL GROUP BY district ORDER BY count DESC LIMIT 10");
while ($row = $result->fetch_assoc()) {
    $district_stats[$row['district']] = $row['count'];
}

// Enrollment trend (last 12 months)
$enrollment_trend = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $count = $conn->query("SELECT COUNT(*) as count FROM beneficiaries WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month' AND $where")->fetch_assoc()['count'];
    $enrollment_trend[$month] = $count;
}

// Program participation analysis
$program_participation_stats = [
    'no_programs' => count(array_filter($beneficiaries, function($b) { return $b['program_count'] == 0; })),
    'one_program' => count(array_filter($beneficiaries, function($b) { return $b['program_count'] == 1; })),
    'two_programs' => count(array_filter($beneficiaries, function($b) { return $b['program_count'] == 2; })),
    'three_plus_programs' => count(array_filter($beneficiaries, function($b) { return $b['program_count'] >= 3; }))
];

// Project participation analysis
$project_participation_stats = [
    'no_projects' => count(array_filter($beneficiaries, function($b) { return $b['project_count'] == 0; })),
    'one_project' => count(array_filter($beneficiaries, function($b) { return $b['project_count'] == 1; })),
    'two_projects' => count(array_filter($beneficiaries, function($b) { return $b['project_count'] == 2; })),
    'three_plus_projects' => count(array_filter($beneficiaries, function($b) { return $b['project_count'] >= 3; }))
];

// Engagement level
$engagement_stats = [
    'highly_engaged' => count(array_filter($beneficiaries, function($b) { return $b['events_attended'] >= 5; })),
    'moderately_engaged' => count(array_filter($beneficiaries, function($b) { return $b['events_attended'] >= 2 && $b['events_attended'] < 5; })),
    'low_engagement' => count(array_filter($beneficiaries, function($b) { return $b['events_attended'] == 1; })),
    'no_engagement' => count(array_filter($beneficiaries, function($b) { return $b['events_attended'] == 0; }))
];

// Get projects for filter
$projects = [];
$result = $conn->query("SELECT project_id, project_name, project_code FROM projects ORDER BY project_name");
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}

// Get programs for filter
$programs = [];
$result = $conn->query("SELECT id, program_name, program_code FROM programs ORDER BY program_name");
while ($row = $result->fetch_assoc()) {
    $programs[] = $row;
}

// Get districts for filter
$districts = [];
$result = $conn->query("SELECT DISTINCT district FROM beneficiaries WHERE district IS NOT NULL AND district != '' ORDER BY district");
while ($row = $result->fetch_assoc()) {
    $districts[] = $row['district'];
}

// Most active beneficiaries
$top_beneficiaries = [];
$query = "SELECT b.*, 
    (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id) as project_count,
    (SELECT COUNT(*) FROM program_beneficiaries prb WHERE prb.beneficiary_id = b.beneficiary_id) as program_count,
    (SELECT COUNT(*) FROM event_registrations ea WHERE ea.beneficiary_id = b.beneficiary_id AND ea.attendance_status = 'Attended') as events_attended
    FROM beneficiaries b 
    WHERE $where
    HAVING events_attended > 0
    ORDER BY events_attended DESC, (program_count + project_count) DESC 
    LIMIT 10";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $top_beneficiaries[] = $row;
}
?>

<style>
/* Participants report: page-only layout (rpp- prefix). Header, cards, stat
   cards, badges, buttons, tables and empty states are global. */
.rpp-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-5); }
.rpp-engage { display: grid; grid-template-columns: 1fr 2fr; gap: var(--space-5); }
.rpp-chart { max-height: 300px; }
.rpp-legend { margin-top: var(--space-5); }

.rpp-row {
    display: flex;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 10px;
    margin-bottom: 5px;
    border-radius: var(--radius-sm);
    background: var(--ink-50);
}
.rpp-row:last-child { margin-bottom: 0; }
.rpp-row.rpp-row-lg { padding: 15px; margin-bottom: 10px; }
.rpp-row.tone-blue   { background: var(--blue-bg); }
.rpp-row.tone-green  { background: var(--green-bg); }
.rpp-row.tone-amber  { background: var(--amber-bg); }
.rpp-row.tone-red    { background: var(--red-bg); }
.rpp-row.tone-purple { background: var(--purple-bg); }
.rpp-row.tone-slate  { background: var(--slate-bg); }

.rpp-c-blue   { color: var(--blue-fg); }
.rpp-c-green  { color: var(--green-fg); }
.rpp-c-amber  { color: var(--amber-fg); }
.rpp-c-purple { color: var(--purple-fg); }
.rpp-c-slate  { color: var(--ink-200); }

.rpp-bar-row { margin-bottom: 15px; }
.rpp-bar-row:last-child { margin-bottom: 0; }
.rpp-bar-head { display: flex; justify-content: space-between; gap: var(--space-3); margin-bottom: 5px; }
.rpp-progress-thin { height: 8px; }

.rpp-sum-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-5); }
.rpp-sum {
    padding: var(--space-5);
    border-left: 4px solid var(--ink-100);
    border-radius: var(--radius-sm);
    background: var(--ink-50);
}
.rpp-sum h4 { margin: 0 0 10px; }
.rpp-sum.tone-blue   { border-left-color: var(--blue-fg); }
.rpp-sum.tone-blue h4 { color: var(--blue-fg); }
.rpp-sum.tone-amber  { border-left-color: var(--amber-fg); }
.rpp-sum.tone-amber h4 { color: var(--amber-fg); }
.rpp-sum.tone-brand  { border-left-color: var(--brand-600); }
.rpp-sum.tone-brand h4 { color: var(--brand-600); }
.rpp-sum.tone-green  { border-left-color: var(--green-fg); }
.rpp-sum.tone-green h4 { color: var(--green-fg); }
.rpp-sum.tone-purple { border-left-color: var(--purple-fg); }
.rpp-sum.tone-purple h4 { color: var(--purple-fg); }
.rpp-sum-value { margin: 0; font-size: 24px; font-weight: 800; color: var(--ink-700); }
.rpp-muted { color: var(--ink-300); }

.rpp-rank-1 { color: var(--amber-fg); }
.rpp-rank-2 { color: var(--ink-200); }
.rpp-rank-3 { color: var(--brand-700); }

@media (max-width: 900px) {
    .rpp-grid-2,
    .rpp-engage { grid-template-columns: 1fr; }
}

@media print {
    .no-print { display: none !important; }
    .card { page-break-inside: avoid; }
}
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-users" aria-hidden="true"></i> Participants Analytics Report</h1>
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
                <label>Program</label>
                <select name="program" class="form-control">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?php echo $program['program_id']; ?>" <?php echo $program_filter == $program['program_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['program_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
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
                <label>Gender</label>
                <select name="gender" class="form-control">
                    <option value="">All Genders</option>
                    <option value="Male" <?php echo $gender_filter == 'Male' ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo $gender_filter == 'Female' ? 'selected' : ''; ?>>Female</option>
                    <option value="Other" <?php echo $gender_filter == 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>District</label>
                <select name="district" class="form-control">
                    <option value="">All Districts</option>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?php echo htmlspecialchars($district); ?>" <?php echo $district_filter == $district ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($district); ?>
                        </option>
                    <?php endforeach; ?>
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
                <a href="report-participants" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Key Metrics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Participants</span>
            <span class="stat-value"><?php echo number_format($stats['total']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-male" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Male (<?php echo $stats['total'] > 0 ? number_format($stats['male'] / $stats['total'] * 100, 1) : 0; ?>%)</span>
            <span class="stat-value"><?php echo $stats['male']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-female" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Female (<?php echo $stats['total'] > 0 ? number_format($stats['female'] / $stats['total'] * 100, 1) : 0; ?>%)</span>
            <span class="stat-value"><?php echo $stats['female']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-wheelchair" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Persons with Disabilities</span>
            <span class="stat-value"><?php echo $stats['pwd']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-sitemap" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Active in Programs</span>
            <span class="stat-value"><?php echo $stats['active_in_programs']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-project-diagram" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Active in Projects</span>
            <span class="stat-value"><?php echo $stats['active_in_projects']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-user-slash" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Not Enrolled</span>
            <span class="stat-value"><?php echo $stats['total'] - $stats['with_programs'] - $stats['with_projects']; ?></span>
        </div>
    </div>
</div>

<!-- Demographics Overview -->
<div class="rpp-grid-2">
    <!-- Gender Distribution -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-venus-mars"></i> Gender Distribution</h3>
        </div>
        <div class="card-body">
            <canvas id="genderChart" class="rpp-chart"></canvas>
            
            <div class="rpp-legend">
                <div class="rpp-row tone-blue">
                    <span><i class="fas fa-male rpp-c-blue" aria-hidden="true"></i> Male</span>
                    <strong><?php echo $stats['male']; ?> (<?php echo $stats['total'] > 0 ? number_format($stats['male'] / $stats['total'] * 100, 1) : 0; ?>%)</strong>
                </div>
                <div class="rpp-row tone-purple">
                    <span><i class="fas fa-female rpp-c-purple" aria-hidden="true"></i> Female</span>
                    <strong><?php echo $stats['female']; ?> (<?php echo $stats['total'] > 0 ? number_format($stats['female'] / $stats['total'] * 100, 1) : 0; ?>%)</strong>
                </div>
                <?php 
                $other = $stats['total'] - $stats['male'] - $stats['female'];
                if ($other > 0):
                ?>
                <div class="rpp-row tone-purple">
                    <span>Other</span>
                    <strong><?php echo $other; ?> (<?php echo $stats['total'] > 0 ? number_format($other / $stats['total'] * 100, 1) : 0; ?>%)</strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Age Distribution -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-birthday-cake"></i> Age Distribution</h3>
        </div>
        <div class="card-body">
            <canvas id="ageChart" class="rpp-chart"></canvas>
        </div>
    </div>
</div>

<!-- Education & Location -->
<div class="rpp-grid-2">
    <!-- Education Level -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-graduation-cap"></i> Education Level</h3>
        </div>
        <div class="card-body">
            <?php if (empty($education_stats)): ?>
                <div class="empty-state"><i class="fas fa-graduation-cap" aria-hidden="true"></i><p>No education data available</p></div>
            <?php else: ?>
                <?php foreach ($education_stats as $level => $count): ?>
                    <div class="rpp-bar-row">
                        <div class="rpp-bar-head">
                            <span><?php echo htmlspecialchars($level); ?></span>
                            <strong><?php echo $count; ?> (<?php echo $stats['total'] > 0 ? number_format($count / $stats['total'] * 100, 1) : 0; ?>%)</strong>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $stats['total'] > 0 ? ($count / $stats['total'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Top Districts -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-map-marker-alt"></i> Top 10 Districts</h3>
        </div>
        <div class="card-body">
            <?php if (empty($district_stats)): ?>
                <div class="empty-state"><i class="fas fa-map-marker-alt" aria-hidden="true"></i><p>No location data available</p></div>
            <?php else: ?>
                <?php foreach ($district_stats as $district => $count): ?>
                    <div class="rpp-bar-row">
                        <div class="rpp-bar-head">
                            <span><?php echo htmlspecialchars($district); ?></span>
                            <strong><?php echo $count; ?></strong>
                        </div>
                        <div class="progress rpp-progress-thin">
                            <div class="progress-bar" style="width: <?php echo max($district_stats) > 0 ? ($count / max($district_stats) * 100) : 0; ?>%; background: var(--primary-color);"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Enrollment Trend -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-chart-line"></i> Enrollment Trend (Last 12 Months)</h3>
    </div>
    <div class="card-body">
        <canvas id="enrollmentChart" class="rpp-chart"></canvas>
    </div>
</div>

<!-- Program & Project Participation -->
<div class="rpp-grid-2">
    <!-- Program Participation -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-sitemap"></i> Program Participation</h3>
        </div>
        <div class="card-body">
            <canvas id="programParticipationChart" class="rpp-chart"></canvas>
            
            <div class="rpp-legend">
                <div class="rpp-row tone-red">
                    <span>No Programs</span>
                    <strong><?php echo $program_participation_stats['no_programs']; ?></strong>
                </div>
                <div class="rpp-row tone-amber">
                    <span>1 Program</span>
                    <strong><?php echo $program_participation_stats['one_program']; ?></strong>
                </div>
                <div class="rpp-row tone-green">
                    <span>2 Programs</span>
                    <strong><?php echo $program_participation_stats['two_programs']; ?></strong>
                </div>
                <div class="rpp-row tone-blue">
                    <span>3+ Programs</span>
                    <strong><?php echo $program_participation_stats['three_plus_programs']; ?></strong>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Project Participation -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-project-diagram"></i> Project Participation</h3>
        </div>
        <div class="card-body">
            <canvas id="projectParticipationChart" class="rpp-chart"></canvas>
            
            <div class="rpp-legend">
                <div class="rpp-row tone-red">
                    <span>No Projects</span>
                    <strong><?php echo $project_participation_stats['no_projects']; ?></strong>
                </div>
                <div class="rpp-row tone-amber">
                    <span>1 Project</span>
                    <strong><?php echo $project_participation_stats['one_project']; ?></strong>
                </div>
                <div class="rpp-row tone-green">
                    <span>2 Projects</span>
                    <strong><?php echo $project_participation_stats['two_projects']; ?></strong>
                </div>
                <div class="rpp-row tone-blue">
                    <span>3+ Projects</span>
                    <strong><?php echo $project_participation_stats['three_plus_projects']; ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Engagement Level -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-chart-bar"></i> Engagement Level (Event Attendance)</h3>
    </div>
    <div class="card-body">
        <div class="rpp-engage">
            <canvas id="engagementChart" class="rpp-chart"></canvas>
            
            <div>
                <div class="rpp-row rpp-row-lg tone-green">
                    <span><i class="fas fa-fire rpp-c-green" aria-hidden="true"></i> Highly Engaged (5+ events)</span>
                    <strong><?php echo $engagement_stats['highly_engaged']; ?></strong>
                </div>
                <div class="rpp-row rpp-row-lg tone-amber">
                    <span><i class="fas fa-chart-line rpp-c-amber" aria-hidden="true"></i> Moderate (2-4 events)</span>
                    <strong><?php echo $engagement_stats['moderately_engaged']; ?></strong>
                </div>
                <div class="rpp-row rpp-row-lg tone-purple">
                    <span><i class="fas fa-chart-bar rpp-c-purple" aria-hidden="true"></i> Low (1 event)</span>
                    <strong><?php echo $engagement_stats['low_engagement']; ?></strong>
                </div>
                <div class="rpp-row rpp-row-lg tone-slate">
                    <span><i class="fas fa-times-circle rpp-c-slate" aria-hidden="true"></i> No Engagement</span>
                    <strong><?php echo $engagement_stats['no_engagement']; ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Top Active Beneficiaries -->
<?php if (!empty($top_beneficiaries)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-trophy"></i> Most Active Participants (Top 10)</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>District</th>
                        <th>Programs</th>
                        <th>Projects</th>
                        <th>Events Attended</th>
                        <th>PWD</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; ?>
                    <?php foreach ($top_beneficiaries as $beneficiary): ?>
                        <tr>
                            <td>
                                <?php if ($rank <= 3): ?>
                                    <i class="fas fa-trophy rpp-rank-<?php echo $rank; ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                                <?php echo $rank++; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($beneficiary['first_name'] . ' ' . $beneficiary['last_name']); ?></strong>
                            </td>
                            <td>
                                <?php if ($beneficiary['gender'] == 'Male'): ?>
                                    <i class="fas fa-male rpp-c-blue" aria-hidden="true"></i> Male
                                <?php elseif ($beneficiary['gender'] == 'Female'): ?>
                                    <i class="fas fa-female rpp-c-purple" aria-hidden="true"></i> Female
                                <?php else: ?>
                                    <?php echo $beneficiary['gender']; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($beneficiary['district'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge badge-warning"><?php echo $beneficiary['program_count']; ?></span>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo $beneficiary['project_count']; ?></span>
                            </td>
                            <td>
                                <span class="badge badge-success"><?php echo $beneficiary['events_attended']; ?></span>
                            </td>
                            <td>
                                <?php if ($beneficiary['is_pwd']): ?>
                                    <i class="fas fa-check-circle rpp-c-green" aria-hidden="true"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle rpp-c-slate" aria-hidden="true"></i>
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

<!-- Summary Statistics -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-list"></i> Summary Statistics</h3>
    </div>
    <div class="card-body">
        <div class="rpp-sum-grid">
            <div class="rpp-sum tone-blue">
                <h4>Gender Ratio</h4>
                <p class="rpp-sum-value">
                    <?php 
                    if ($stats['female'] > 0) {
                        $ratio = $stats['male'] / $stats['female'];
                        echo number_format($ratio, 2) . ':1';
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </p>
                <small class="rpp-muted">Male to Female Ratio</small>
            </div>
            
            <div class="rpp-sum tone-amber">
                <h4>PWD Percentage</h4>
                <p class="rpp-sum-value">
                    <?php echo $stats['total'] > 0 ? number_format($stats['pwd'] / $stats['total'] * 100, 1) : 0; ?>%
                </p>
                <small class="rpp-muted"><?php echo $stats['pwd']; ?> out of <?php echo $stats['total']; ?> participants</small>
            </div>
            
            <div class="rpp-sum tone-brand">
                <h4>Program Coverage</h4>
                <p class="rpp-sum-value">
                    <?php echo $stats['total'] > 0 ? number_format($stats['with_programs'] / $stats['total'] * 100, 1) : 0; ?>%
                </p>
                <small class="rpp-muted"><?php echo $stats['with_programs']; ?> participants in programs</small>
            </div>
            
            <div class="rpp-sum tone-green">
                <h4>Project Coverage</h4>
                <p class="rpp-sum-value">
                    <?php echo $stats['total'] > 0 ? number_format($stats['with_projects'] / $stats['total'] * 100, 1) : 0; ?>%
                </p>
                <small class="rpp-muted"><?php echo $stats['with_projects']; ?> participants in projects</small>
            </div>
            
            <div class="rpp-sum tone-purple">
                <h4>Avg Programs per Participant</h4>
                <p class="rpp-sum-value">
                    <?php 
                    $total_programs = array_sum(array_column($beneficiaries, 'program_count'));
                    echo $stats['with_programs'] > 0 ? number_format($total_programs / $stats['with_programs'], 1) : 0;
                    ?>
                </p>
                <small class="rpp-muted">Among those enrolled in programs</small>
            </div>
            
            <div class="rpp-sum tone-blue">
                <h4>Avg Projects per Participant</h4>
                <p class="rpp-sum-value">
                    <?php 
                    $total_projects = array_sum(array_column($beneficiaries, 'project_count'));
                    echo $stats['with_projects'] > 0 ? number_format($total_projects / $stats['with_projects'], 1) : 0;
                    ?>
                </p>
                <small class="rpp-muted">Among those enrolled in projects</small>
            </div>
        </div>
    </div>
</div>

</div><!-- /.page-stack -->

<script>
// Chart colours come from the design tokens.
const rppTok = function (name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); };

// Gender Distribution Chart
const genderCtx = document.getElementById('genderChart');
if (genderCtx) {
    new Chart(genderCtx, {
        type: 'doughnut',
        data: {
            labels: ['Male', 'Female', 'Other'],
            datasets: [{
                data: [<?php echo $stats['male']; ?>, <?php echo $stats['female']; ?>, <?php echo $stats['total'] - $stats['male'] - $stats['female']; ?>],
                backgroundColor: [rppTok('--blue-fg'), rppTok('--purple-fg'), rppTok('--slate-fg')]
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

// Age Distribution Chart
const ageCtx = document.getElementById('ageChart');
if (ageCtx) {
    new Chart(ageCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($age_distribution)); ?>,
            datasets: [{
                label: 'Number of Participants',
                data: <?php echo json_encode(array_values($age_distribution)); ?>,
                backgroundColor: rppTok('--blue-fg')
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
                    display: false
                }
            }
        }
    });
}

// Enrollment Trend Chart
const enrollmentCtx = document.getElementById('enrollmentChart');
if (enrollmentCtx) {
    new Chart(enrollmentCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_keys($enrollment_trend)); ?>,
            datasets: [{
                label: 'New Participants',
                data: <?php echo json_encode(array_values($enrollment_trend)); ?>,
                borderColor: rppTok('--brand-600'),
                backgroundColor: rppTok('--brand-50'),
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Program Participation Chart
const programParticipationCtx = document.getElementById('programParticipationChart');
if (programParticipationCtx) {
    new Chart(programParticipationCtx, {
        type: 'doughnut',
        data: {
            labels: ['No Programs', '1 Program', '2 Programs', '3+ Programs'],
            datasets: [{
                data: <?php echo json_encode(array_values($program_participation_stats)); ?>,
                backgroundColor: [rppTok('--red-fg'), rppTok('--amber-fg'), rppTok('--green-fg'), rppTok('--brand-500')]
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

// Project Participation Chart
const projectParticipationCtx = document.getElementById('projectParticipationChart');
if (projectParticipationCtx) {
    new Chart(projectParticipationCtx, {
        type: 'doughnut',
        data: {
            labels: ['No Projects', '1 Project', '2 Projects', '3+ Projects'],
            datasets: [{
                data: <?php echo json_encode(array_values($project_participation_stats)); ?>,
                backgroundColor: [rppTok('--red-fg'), rppTok('--amber-fg'), rppTok('--green-fg'), rppTok('--blue-fg')]
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

// Engagement Chart
const engagementCtx = document.getElementById('engagementChart');
if (engagementCtx) {
    new Chart(engagementCtx, {
        type: 'pie',
        data: {
            labels: ['Highly Engaged', 'Moderate', 'Low', 'No Engagement'],
            datasets: [{
                data: <?php echo json_encode(array_values($engagement_stats)); ?>,
                backgroundColor: [rppTok('--green-fg'), rppTok('--amber-fg'), rppTok('--purple-fg'), rppTok('--ink-200')]
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

function exportToExcel() {
    const params = new URLSearchParams();
    params.append('type', 'participants');
    <?php if ($gender_filter): ?>params.append('gender', <?php echo json_encode((string)$gender_filter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);<?php endif; ?>
    <?php if ($district_filter): ?>params.append('district', <?php echo json_encode((string)$district_filter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);<?php endif; ?>
    <?php if ($program_filter): ?>params.append('program', '<?php echo (int)$program_filter; ?>');<?php endif; ?>
    <?php if ($project_filter): ?>params.append('project', '<?php echo (int)$project_filter; ?>');<?php endif; ?>
    window.location.href = 'export?' + params.toString();
}
</script>

<?php include 'includes/footer.php'; ?>