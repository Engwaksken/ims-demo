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
@media print {
    .no-print { display: none !important; }
    .card { page-break-inside: avoid; }
}
</style>

<!-- Filters -->
<div class="card no-print">
    <div class="card-header">
        <h3><i class="fas fa-filter"></i> Report Filters</h3>
        <div>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
        </div>
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
            
            <div class="filters-actions">
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

<!-- Report Header -->
<div class="card" style="background: linear-gradient(135deg, var(--primary-color), #3498DB); color: white; text-align: center; margin-bottom: 20px;">
    <div class="card-body">
        <h1 style="margin: 0; font-size: 32px; color: white;">Participants Analytics Report</h1>
        <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">
            Period: <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?>
        </p>
        <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.8;">
            Generated: <?php echo date('d M Y H:i'); ?> by <?php echo htmlspecialchars($_SESSION['full_name']); ?>
        </p>
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
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
    <!-- Gender Distribution -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-venus-mars"></i> Gender Distribution</h3>
        </div>
        <div class="card-body">
            <canvas id="genderChart" style="max-height: 300px;"></canvas>
            
            <div style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #E3F2FD; border-radius: 5px; margin-bottom: 5px;">
                    <span><i class="fas fa-male" style="color: #3498DB;"></i> Male</span>
                    <strong><?php echo $stats['male']; ?> (<?php echo $stats['total'] > 0 ? number_format($stats['male'] / $stats['total'] * 100, 1) : 0; ?>%)</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #FCE4EC; border-radius: 5px; margin-bottom: 5px;">
                    <span><i class="fas fa-female" style="color: #E91E63;"></i> Female</span>
                    <strong><?php echo $stats['female']; ?> (<?php echo $stats['total'] > 0 ? number_format($stats['female'] / $stats['total'] * 100, 1) : 0; ?>%)</strong>
                </div>
                <?php 
                $other = $stats['total'] - $stats['male'] - $stats['female'];
                if ($other > 0):
                ?>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #F3E5F5; border-radius: 5px;">
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
            <canvas id="ageChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
</div>

<!-- Education & Location -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
    <!-- Education Level -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-graduation-cap"></i> Education Level</h3>
        </div>
        <div class="card-body">
            <?php if (empty($education_stats)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 40px;">No education data available</p>
            <?php else: ?>
                <?php foreach ($education_stats as $level => $count): ?>
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
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
                <p style="text-align: center; color: #7f8c8d; padding: 40px;">No location data available</p>
            <?php else: ?>
                <?php foreach ($district_stats as $district => $count): ?>
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span><?php echo htmlspecialchars($district); ?></span>
                            <strong><?php echo $count; ?></strong>
                        </div>
                        <div class="progress" style="height: 8px;">
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
        <canvas id="enrollmentChart" style="max-height: 300px;"></canvas>
    </div>
</div>

<!-- Program & Project Participation -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
    <!-- Program Participation -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-sitemap"></i> Program Participation</h3>
        </div>
        <div class="card-body">
            <canvas id="programParticipationChart" style="max-height: 300px;"></canvas>
            
            <div style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #FFEBEE; border-radius: 5px; margin-bottom: 5px;">
                    <span>No Programs</span>
                    <strong><?php echo $program_participation_stats['no_programs']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #FFF3E0; border-radius: 5px; margin-bottom: 5px;">
                    <span>1 Program</span>
                    <strong><?php echo $program_participation_stats['one_program']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #E8F5E9; border-radius: 5px; margin-bottom: 5px;">
                    <span>2 Programs</span>
                    <strong><?php echo $program_participation_stats['two_programs']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #E3F2FD; border-radius: 5px;">
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
            <canvas id="projectParticipationChart" style="max-height: 300px;"></canvas>
            
            <div style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #FFEBEE; border-radius: 5px; margin-bottom: 5px;">
                    <span>No Projects</span>
                    <strong><?php echo $project_participation_stats['no_projects']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #FFF3E0; border-radius: 5px; margin-bottom: 5px;">
                    <span>1 Project</span>
                    <strong><?php echo $project_participation_stats['one_project']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #E8F5E9; border-radius: 5px; margin-bottom: 5px;">
                    <span>2 Projects</span>
                    <strong><?php echo $project_participation_stats['two_projects']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: #E3F2FD; border-radius: 5px;">
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
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
            <canvas id="engagementChart" style="max-height: 300px;"></canvas>
            
            <div>
                <div style="display: flex; justify-content: space-between; padding: 15px; background: #E8F5E9; border-radius: 5px; margin-bottom: 10px;">
                    <span><i class="fas fa-fire" style="color: #2ECC71;"></i> Highly Engaged (5+ events)</span>
                    <strong><?php echo $engagement_stats['highly_engaged']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 15px; background: #FFF3E0; border-radius: 5px; margin-bottom: 10px;">
                    <span><i class="fas fa-chart-line" style="color: #F39C12;"></i> Moderate (2-4 events)</span>
                    <strong><?php echo $engagement_stats['moderately_engaged']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 15px; background: #FCE4EC; border-radius: 5px; margin-bottom: 10px;">
                    <span><i class="fas fa-chart-bar" style="color: #E91E63;"></i> Low (1 event)</span>
                    <strong><?php echo $engagement_stats['low_engagement']; ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 15px; background: #ECEFF1; border-radius: 5px;">
                    <span><i class="fas fa-times-circle" style="color: #95A5A6;"></i> No Engagement</span>
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
            <table class="data-table">
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
                                    <i class="fas fa-trophy" style="color: <?php echo $rank == 1 ? '#FFD700' : ($rank == 2 ? '#C0C0C0' : '#CD7F32'); ?>;"></i>
                                <?php endif; ?>
                                <?php echo $rank++; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($beneficiary['first_name'] . ' ' . $beneficiary['last_name']); ?></strong>
                            </td>
                            <td>
                                <?php if ($beneficiary['gender'] == 'Male'): ?>
                                    <i class="fas fa-male" style="color: #3498DB;"></i> Male
                                <?php elseif ($beneficiary['gender'] == 'Female'): ?>
                                    <i class="fas fa-female" style="color: #E91E63;"></i> Female
                                <?php else: ?>
                                    <?php echo $beneficiary['gender']; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($beneficiary['district'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge" style="background: #E67E22; color: white;"><?php echo $beneficiary['program_count']; ?></span>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo $beneficiary['project_count']; ?></span>
                            </td>
                            <td>
                                <span class="badge badge-success"><?php echo $beneficiary['events_attended']; ?></span>
                            </td>
                            <td>
                                <?php if ($beneficiary['is_pwd']): ?>
                                    <i class="fas fa-check-circle" style="color: #2ECC71;"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle" style="color: #95A5A6;"></i>
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
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #3498DB;">
                <h4 style="margin: 0 0 10px 0; color: var(--primary-color);">Gender Ratio</h4>
                <p style="font-size: 24px; font-weight: bold; margin: 0;">
                    <?php 
                    if ($stats['female'] > 0) {
                        $ratio = $stats['male'] / $stats['female'];
                        echo number_format($ratio, 2) . ':1';
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </p>
                <small style="color: #7f8c8d;">Male to Female Ratio</small>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #F39C12;">
                <h4 style="margin: 0 0 10px 0; color: #F39C12;">PWD Percentage</h4>
                <p style="font-size: 24px; font-weight: bold; margin: 0;">
                    <?php echo $stats['total'] > 0 ? number_format($stats['pwd'] / $stats['total'] * 100, 1) : 0; ?>%
                </p>
                <small style="color: #7f8c8d;"><?php echo $stats['pwd']; ?> out of <?php echo $stats['total']; ?> participants</small>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #E67E22;">
                <h4 style="margin: 0 0 10px 0; color: #E67E22;">Program Coverage</h4>
                <p style="font-size: 24px; font-weight: bold; margin: 0;">
                    <?php echo $stats['total'] > 0 ? number_format($stats['with_programs'] / $stats['total'] * 100, 1) : 0; ?>%
                </p>
                <small style="color: #7f8c8d;"><?php echo $stats['with_programs']; ?> participants in programs</small>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #2ECC71;">
                <h4 style="margin: 0 0 10px 0; color: #2ECC71;">Project Coverage</h4>
                <p style="font-size: 24px; font-weight: bold; margin: 0;">
                    <?php echo $stats['total'] > 0 ? number_format($stats['with_projects'] / $stats['total'] * 100, 1) : 0; ?>%
                </p>
                <small style="color: #7f8c8d;"><?php echo $stats['with_projects']; ?> participants in projects</small>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #9B59B6;">
                <h4 style="margin: 0 0 10px 0; color: #9B59B6;">Avg Programs per Participant</h4>
                <p style="font-size: 24px; font-weight: bold; margin: 0;">
                    <?php 
                    $total_programs = array_sum(array_column($beneficiaries, 'program_count'));
                    echo $stats['with_programs'] > 0 ? number_format($total_programs / $stats['with_programs'], 1) : 0;
                    ?>
                </p>
                <small style="color: #7f8c8d;">Among those enrolled in programs</small>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #3498DB;">
                <h4 style="margin: 0 0 10px 0; color: #3498DB;">Avg Projects per Participant</h4>
                <p style="font-size: 24px; font-weight: bold; margin: 0;">
                    <?php 
                    $total_projects = array_sum(array_column($beneficiaries, 'project_count'));
                    echo $stats['with_projects'] > 0 ? number_format($total_projects / $stats['with_projects'], 1) : 0;
                    ?>
                </p>
                <small style="color: #7f8c8d;">Among those enrolled in projects</small>
            </div>
        </div>
    </div>
</div>

<script>
// Gender Distribution Chart
const genderCtx = document.getElementById('genderChart');
if (genderCtx) {
    new Chart(genderCtx, {
        type: 'doughnut',
        data: {
            labels: ['Male', 'Female', 'Other'],
            datasets: [{
                data: [<?php echo $stats['male']; ?>, <?php echo $stats['female']; ?>, <?php echo $stats['total'] - $stats['male'] - $stats['female']; ?>],
                backgroundColor: ['#3498DB', '#E91E63', '#9B59B6']
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
                backgroundColor: '#3498DB'
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
                borderColor: 'var(--primary-color)',
                backgroundColor: 'rgba(255, 107, 53, 0.1)',
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
                backgroundColor: ['#E74C3C', '#F39C12', '#2ECC71', '#E67E22']
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
                backgroundColor: ['#E74C3C', '#F39C12', '#2ECC71', '#3498DB']
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
                backgroundColor: ['#2ECC71', '#F39C12', '#E91E63', '#95A5A6']
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