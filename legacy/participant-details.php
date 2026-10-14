<?php
$page_title = 'Participant Details';
require_once __DIR__ . '/includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Staff']);

// Get beneficiary ID
$beneficiary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$beneficiary_id) {
    send_notification($_SESSION['user_id'], 'Invalid participant ID', 'danger');
    header("Location: participants");
    exit();
}

// Fetch beneficiary details
$query = "SELECT * FROM beneficiaries WHERE beneficiary_id = $beneficiary_id";
$result = $conn->query($query);

if ($result->num_rows == 0) {
    send_notification($_SESSION['user_id'], 'Participant not found', 'danger');
    header("Location: participants");
    exit();
}

$beneficiary = $result->fetch_assoc();

// Calculate age
$age = 'N/A';
if ($beneficiary['date_of_birth']) {
    $dob = new DateTime($beneficiary['date_of_birth']);
    $now = new DateTime();
    $age = $now->diff($dob)->y;
}

// Fetch project associations
$projects = [];
$query = "SELECT p.*, pb.enrollment_date, pb.participation_type, pb.status, pb.completed_date, pb.outcomes
    FROM projects p
    JOIN project_beneficiaries pb ON p.project_id = pb.project_id
    WHERE pb.beneficiary_id = $beneficiary_id
    ORDER BY pb.enrollment_date DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}

// Fetch program associations
$programs = [];
$query = "SELECT pr.*, prb.enrollment_date, prb.participation_type, prb.status, prb.completed_date, prb.outcomes
    FROM programs pr
    JOIN programs_beneficiaries prb ON pr.id = prb.program_id
    WHERE prb.beneficiary_id = $beneficiary_id
    ORDER BY prb.enrollment_date DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $programs[] = $row;
}

// Fetch all projects for linking dropdown
$all_projects = [];
$projects_result = $conn->query("SELECT project_id, project_name, project_code FROM projects ORDER BY project_name ASC");
while ($row = $projects_result->fetch_assoc()) {
    $all_projects[] = $row;
}

// Fetch all programs for linking dropdown
$all_programs = [];
$programs_result = $conn->query("SELECT id, program_name, program_code FROM programs ORDER BY program_name ASC");
while ($row = $programs_result->fetch_assoc()) {
    $all_programs[] = $row;
}

// Fetch event participation
$events = [];
$query = "SELECT e.*, ea.attendance_status, ea.feedback_score, ea.feedback_comments
    FROM hub_events e
    JOIN event_registrations ea ON e.event_id = ea.event_id
    WHERE ea.beneficiary_id = $beneficiary_id
    ORDER BY e.event_date DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $events[] = $row;
}

// Fetch training/capacity building records
$trainings = [];
$query = "SELECT e.*
    FROM hub_events e
    JOIN event_registrations ea ON e.event_id = ea.event_id
    WHERE ea.beneficiary_id = $beneficiary_id 
    AND e.event_type IN ('Training', 'Workshop', 'Capacity Building')
    AND ea.attendance_status = 'Attended'
    ORDER BY e.event_date DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $trainings[] = $row;
}

// Statistics
$stats = [
    'total_projects' => count($projects),
    'total_programs' => count($programs),
    'active_projects' => count(array_filter($projects, function($p) { return $p['status'] == 'Active'; })),
    'active_programs' => count(array_filter($programs, function($pr) { return $pr['status'] == 'Active'; })),
    'events_attended' => count(array_filter($events, function($e) { return $e['attendance_status'] == 'Attended'; })),
    'trainings_completed' => count($trainings)
];

// Calculate average feedback score
$feedback_scores = array_filter(array_column($events, 'feedback_score'));
$avg_feedback = !empty($feedback_scores) ? array_sum($feedback_scores) / count($feedback_scores) : 0;

// Uganda districts
$uganda_districts = ['Abim', 'Adjumani', 'Agago', 'Alebtong', 'Amolatar', 'Amudat', 'Amuria', 'Amuru', 'Apac', 'Arua', 'Budaka', 'Bududa', 'Bugiri', 'Buhweju', 'Buikwe', 'Bukedea', 'Bukomansimbi', 'Bukwo', 'Bulambuli', 'Buliisa', 'Bundibugyo', 'Bunyangabu', 'Bushenyi', 'Busia', 'Butaleja', 'Butambala', 'Butebo', 'Buvuma', 'Buyende', 'Dokolo', 'Gomba', 'Gulu', 'Hoima', 'Ibanda', 'Iganga', 'Isingiro', 'Jinja', 'Kaabong', 'Kabale', 'Kabarole', 'Kaberamaido', 'Kagadi', 'Kakumiro', 'Kalangala', 'Kaliro', 'Kalungu', 'Kampala', 'Kamuli', 'Kamwenge', 'Kanungu', 'Kapchorwa', 'Kasese', 'Katakwi', 'Kayunga', 'Kibaale', 'Kiboga', 'Kibuku', 'Kiruhura', 'Kiryandongo', 'Kisoro', 'Kitgum', 'Koboko', 'Kole', 'Kotido', 'Kumi', 'Kween', 'Kyankwanzi', 'Kyegegwa', 'Kyenjojo', 'Kyotera', 'Lamwo', 'Lira', 'Luuka', 'Luwero', 'Lwengo', 'Lyantonde', 'Manafwa', 'Maracha', 'Masaka', 'Masindi', 'Mayuge', 'Mbale', 'Mbarara', 'Mitooma', 'Mityana', 'Moroto', 'Moyo', 'Mpigi', 'Mubende', 'Mukono', 'Nakapiripirit', 'Nakaseke', 'Nakasongola', 'Namayingo', 'Namisindwa', 'Namutumba', 'Napak', 'Nebbi', 'Ngora', 'Ntoroko', 'Ntungamo', 'Nwoya', 'Omoro', 'Otuke', 'Oyam', 'Pader', 'Pakwach', 'Pallisa', 'Rakai', 'Rubanda', 'Rubirizi', 'Rukiga', 'Rukungiri', 'Sembabule', 'Serere', 'Sheema', 'Sironko', 'Soroti', 'Tororo', 'Wakiso', 'Yumbe', 'Zombo'];
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <a href="participants" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Participants
        </a>
    </div>
    <div>
        <?php if (ims_allowed('participants.edit', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer'], 'Edit participant profiles and programme linkages')): ?>
            <button onclick="openModal('editBeneficiaryModal')" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit Profile
            </button>
            <button onclick="openModal('manageLinkagesModal')" class="btn btn-info">
                <i class="fas fa-link"></i> Manage Linkages
            </button>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-secondary">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- Beneficiary Profile Card -->
<div class="card">
    <div class="card-header" style="background: linear-gradient(135deg, var(--primary-color), #3498DB); color: white;">
        <h3 style="color: white; margin: 0;">
            <i class="fas fa-user"></i> 
            <?php echo htmlspecialchars($beneficiary['first_name'] . ' ' . $beneficiary['last_name']); ?>
        </h3>
        <div style="display: flex; gap: 10px;">
            <?php if ($beneficiary['is_pwd']): ?>
                <span class="badge" style="background: #F39C12; font-size: 14px; padding: 8px 12px;">
                    <i class="fas fa-wheelchair"></i> PWD
                </span>
            <?php endif; ?>
            <span class="badge badge-light" style="font-size: 14px; padding: 8px 12px;">
                ID: <?php echo $beneficiary['beneficiary_id']; ?>
            </span>
        </div>
    </div>
    
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px;">
            <!-- Personal Information -->
            <div>
                <h4 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fas fa-id-card"></i> Personal Information
                </h4>
                
                <table style="width: 100%;">
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d; width: 40%;"><i class="fas fa-venus-mars"></i> Gender:</td>
                        <td style="padding: 10px 0; font-weight: 500;">
                            <?php if ($beneficiary['gender'] == 'Male'): ?>
                                <i class="fas fa-male" style="color: #3498DB;"></i> Male
                            <?php elseif ($beneficiary['gender'] == 'Female'): ?>
                                <i class="fas fa-female" style="color: #E91E63;"></i> Female
                            <?php else: ?>
                                <?php echo $beneficiary['gender']; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-birthday-cake"></i> Date of Birth:</td>
                        <td style="padding: 10px 0; font-weight: 500;">
                            <?php echo $beneficiary['date_of_birth'] ? date('d M Y', strtotime($beneficiary['date_of_birth'])) : 'N/A'; ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-calendar"></i> Age:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo $age; ?> years</td>
                    </tr>
                    <?php if ($beneficiary['is_pwd']): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-wheelchair"></i> Disability Type:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($beneficiary['pwd_type'] ?? 'Not specified'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-graduation-cap"></i> Education:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($beneficiary['education_level'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-briefcase"></i> Occupation:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($beneficiary['occupation'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Contact Information -->
            <div>
                <h4 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fas fa-address-book"></i> Contact Information
                </h4>
                
                <table style="width: 100%;">
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d; width: 40%;"><i class="fas fa-phone"></i> Phone:</td>
                        <td style="padding: 10px 0; font-weight: 500;">
                            <?php if ($beneficiary['phone']): ?>
                                <a href="tel:<?php echo h($beneficiary['phone']); ?>"><?php echo htmlspecialchars($beneficiary['phone']); ?></a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-envelope"></i> Email:</td>
                        <td style="padding: 10px 0; font-weight: 500;">
                            <?php if ($beneficiary['email']): ?>
                                <a href="mailto:<?php echo h($beneficiary['email']); ?>"><?php echo htmlspecialchars($beneficiary['email']); ?></a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-map-marker-alt"></i> District:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($beneficiary['district'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-map-pin"></i> Sub-county:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($beneficiary['subcounty'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; color: #7f8c8d;"><i class="fas fa-home"></i> Village:</td>
                        <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($beneficiary['village'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Engagement Summary -->
            <div>
                <h4 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 15px;">
                    <i class="fas fa-chart-bar"></i> Engagement Summary
                </h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                    <div style="background: #E8F5E9; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #2ECC71;">
                            <?php echo $stats['total_programs']; ?>
                        </div>
                        <div style="font-size: 12px; color: #7f8c8d;">Programs</div>
                        <small style="color: #27AE60;"><?php echo $stats['active_programs']; ?> active</small>
                    </div>
                    
                    <div style="background: #E3F2FD; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #3498DB;">
                            <?php echo $stats['total_projects']; ?>
                        </div>
                        <div style="font-size: 12px; color: #7f8c8d;">Projects</div>
                        <small style="color: #2980B9;"><?php echo $stats['active_projects']; ?> active</small>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div style="background: #FFF3E0; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #F39C12;">
                            <?php echo $stats['events_attended']; ?>
                        </div>
                        <div style="font-size: 12px; color: #7f8c8d;">Events</div>
                    </div>
                    
                    <div style="background: #F3E5F5; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #9B59B6;">
                            <?php echo $stats['trainings_completed']; ?>
                        </div>
                        <div style="font-size: 12px; color: #7f8c8d;">Trainings</div>
                    </div>
                </div>
                
                <?php if ($avg_feedback > 0): ?>
                <div style="margin-top: 10px; text-align: center; padding: 10px; background: #f8f9fa; border-radius: 8px;">
                    <div style="color: #7f8c8d; font-size: 12px;">Average Rating</div>
                    <div style="font-size: 20px; font-weight: bold; color: var(--primary-color);">
                        <?php echo number_format($avg_feedback, 1); ?>/5.0
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Program Participation -->
<?php if (!empty($programs)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-sitemap"></i> Program Participation (<?php echo count($programs); ?>)</h3>
    </div>
    
    <div class="card-body">
        <?php foreach ($programs as $program): ?>
            <div style="padding: 20px; margin-bottom: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid <?php 
                echo $program['status'] == 'Active' ? '#2ECC71' : 
                    ($program['status'] == 'Completed' ? '#3498DB' : '#95A5A6'); 
            ?>;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                            <span class="badge" style="background: #E67E22; color: white;">
                                <i class="fas fa-sitemap"></i> PROGRAM
                            </span>
                            <h4 style="margin: 0; color: var(--dark-color);">
                                <?php echo htmlspecialchars($program['program_name']); ?>
                            </h4>
                        </div>
                        <div style="color: #7f8c8d; font-size: 14px;">
                            <strong><?php echo htmlspecialchars($program['program_code']); ?></strong>
                        </div>
                    </div>
                    <div>
                        <span class="badge badge-<?php 
                            echo $program['status'] == 'Active' ? 'success' : 
                                ($program['status'] == 'Completed' ? 'info' : 'secondary'); 
                        ?>">
                            <?php echo $program['status']; ?>
                        </span>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                    <div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 3px;">Participation Type</div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($program['participation_type'] ?? 'General'); ?></div>
                    </div>
                    
                    <div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 3px;">Enrollment Date</div>
                        <div style="font-weight: 500;">
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('d M Y', strtotime($program['enrollment_date'])); ?>
                        </div>
                    </div>
                    
                    <?php if ($program['completed_date']): ?>
                    <div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 3px;">Completion Date</div>
                        <div style="font-weight: 500;">
                            <i class="fas fa-check-circle" style="color: #2ECC71;"></i> 
                            <?php echo date('d M Y', strtotime($program['completed_date'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($program['outcomes']): ?>
                    <div style="margin-top: 15px; padding: 15px; background: white; border-radius: 5px;">
                        <strong style="color: var(--primary-color);">
                            <i class="fas fa-trophy"></i> Outcomes Achieved:
                        </strong>
                        <p style="margin: 10px 0 0 0; color: #555;">
                            <?php echo nl2br(htmlspecialchars($program['outcomes'])); ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px;">
                    <a href="program-details?id=<?php echo $program['id']; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-eye"></i> View Program Details
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Project Participation -->
<?php if (!empty($projects)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-project-diagram"></i> Project Participation (<?php echo count($projects); ?>)</h3>
    </div>
    
    <div class="card-body">
        <?php foreach ($projects as $project): ?>
            <div style="padding: 20px; margin-bottom: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid <?php 
                echo $project['status'] == 'Active' ? '#2ECC71' : 
                    ($project['status'] == 'Completed' ? '#3498DB' : '#95A5A6'); 
            ?>;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                            <span class="badge" style="background: #2ECC71; color: white;">
                                <i class="fas fa-project-diagram"></i> PROJECT
                            </span>
                            <h4 style="margin: 0; color: var(--dark-color);">
                                <?php echo htmlspecialchars($project['project_name']); ?>
                            </h4>
                        </div>
                        <div style="color: #7f8c8d; font-size: 14px;">
                            <strong><?php echo htmlspecialchars($project['project_code']); ?></strong>
                        </div>
                    </div>
                    <div>
                        <span class="badge badge-<?php 
                            echo $project['status'] == 'Active' ? 'success' : 
                                ($project['status'] == 'Completed' ? 'info' : 'secondary'); 
                        ?>">
                            <?php echo $project['status']; ?>
                        </span>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                    <div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 3px;">Participation Type</div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($project['participation_type'] ?? 'General'); ?></div>
                    </div>
                    
                    <div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 3px;">Enrollment Date</div>
                        <div style="font-weight: 500;">
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('d M Y', strtotime($project['enrollment_date'])); ?>
                        </div>
                    </div>
                    
                    <?php if ($project['completed_date']): ?>
                    <div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 3px;">Completion Date</div>
                        <div style="font-weight: 500;">
                            <i class="fas fa-check-circle" style="color: #2ECC71;"></i> 
                            <?php echo date('d M Y', strtotime($project['completed_date'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($project['outcomes']): ?>
                    <div style="margin-top: 15px; padding: 15px; background: white; border-radius: 5px;">
                        <strong style="color: var(--primary-color);">
                            <i class="fas fa-trophy"></i> Outcomes Achieved:
                        </strong>
                        <p style="margin: 10px 0 0 0; color: #555;">
                            <?php echo nl2br(htmlspecialchars($project['outcomes'])); ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px;">
                    <a href="project-details?id=<?php echo $project['project_id']; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-eye"></i> View Project Details
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Show message if no programs or projects -->
<?php if (empty($programs) && empty($projects)): ?>
<div class="card">
    <div class="card-body">
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
            <h4>No Program or Project Participation</h4>
            <p>This participant has not been enrolled in any programs or projects yet.</p>
            <?php if (ims_allowed('participants.edit', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer'], 'Edit participant profiles and programme linkages')): ?>
                <button onclick="openModal('manageLinkagesModal')" class="btn btn-primary" style="margin-top: 15px;">
                    <i class="fas fa-link"></i> Link to Project or Program
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Event Participation -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-check"></i> Event Participation (<?php echo count($events); ?>)</h3>
    </div>
    
    <div class="card-body">
        <?php if (empty($events)): ?>
            <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                <h4>No Event Participation</h4>
                <p>This participant has not attended any events yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Feedback</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($event['event_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo htmlspecialchars($event['event_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y', strtotime($event['event_date'])); ?></td>
                                <td>
                                    <?php
                                    $status_badges = [
                                        'Registered' => 'warning',
                                        'Attended' => 'success',
                                        'No Show' => 'danger'
                                    ];
                                    $badge = $status_badges[$event['attendance_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $event['attendance_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($event['feedback_score']): ?>
                                        <div style="color: <?php 
                                            echo $event['feedback_score'] >= 4 ? '#2ECC71' : 
                                                ($event['feedback_score'] >= 3 ? '#F39C12' : '#E74C3C'); 
                                        ?>;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star<?php echo $i <= $event['feedback_score'] ? '' : '-o'; ?>"></i>
                                            <?php endfor; ?>
                                            (<?php echo $event['feedback_score']; ?>/5)
                                        </div>
                                        <?php if ($event['feedback_comments']): ?>
                                            <small style="color: #7f8c8d;" title="<?php echo htmlspecialchars($event['feedback_comments']); ?>">
                                                <i class="fas fa-comment"></i> Has comments
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d;">No feedback</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Training & Capacity Building -->
<?php if (!empty($trainings)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-graduation-cap"></i> Training & Capacity Building (<?php echo count($trainings); ?>)</h3>
    </div>
    
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
            <?php foreach ($trainings as $training): ?>
                <div style="padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #F39C12;">
                    <h4 style="margin: 0 0 10px 0; color: var(--dark-color); font-size: 16px;">
                        <?php echo htmlspecialchars($training['event_name']); ?>
                    </h4>
                    <div style="display: flex; align-items: center; gap: 10px; color: #7f8c8d; font-size: 14px;">
                        <span class="badge badge-warning"><?php echo $training['event_type']; ?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($training['event_date'])); ?></span>
                    </div>
                    <?php if ($training['organizer']): ?>
                        <div style="margin-top: 10px; font-size: 13px; color: #7f8c8d;">
                            <i class="fas fa-user-tie"></i> Organized by: <?php echo htmlspecialchars($training['organizer']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Timeline -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Activity Timeline</h3>
    </div>
    
    <div class="card-body">
        <?php
        // Combine all activities
        $timeline = [];
        
        // Add program activities
        foreach ($programs as $program) {
            $timeline[] = [
                'date' => $program['enrollment_date'],
                'type' => 'program_enrollment',
                'icon' => 'fa-sitemap',
                'color' => '#E67E22',
                'title' => 'Enrolled in Program',
                'description' => $program['program_name']
            ];
            
            if ($program['completed_date']) {
                $timeline[] = [
                    'date' => $program['completed_date'],
                    'type' => 'program_completion',
                    'icon' => 'fa-check-circle',
                    'color' => '#27AE60',
                    'title' => 'Completed Program',
                    'description' => $program['program_name']
                ];
            }
        }
        
        // Add project activities
        foreach ($projects as $project) {
            $timeline[] = [
                'date' => $project['enrollment_date'],
                'type' => 'project_enrollment',
                'icon' => 'fa-project-diagram',
                'color' => '#3498DB',
                'title' => 'Enrolled in Project',
                'description' => $project['project_name']
            ];
            
            if ($project['completed_date']) {
                $timeline[] = [
                    'date' => $project['completed_date'],
                    'type' => 'project_completion',
                    'icon' => 'fa-check-circle',
                    'color' => '#2ECC71',
                    'title' => 'Completed Project',
                    'description' => $project['project_name']
                ];
            }
        }
        
        // Add event activities
        foreach ($events as $event) {
            if ($event['attendance_status'] == 'Attended') {
                $timeline[] = [
                    'date' => $event['event_date'],
                    'type' => 'event',
                    'icon' => 'fa-calendar-check',
                    'color' => '#9B59B6',
                    'title' => 'Attended Event',
                    'description' => $event['event_name']
                ];
            }
        }
        
        // Sort by date descending
        usort($timeline, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        if (empty($timeline)): ?>
            <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                <i class="fas fa-clock" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                <h4>No Activity Yet</h4>
                <p>This participant's activity timeline will appear here.</p>
            </div>
        <?php else: ?>
            <div style="position: relative; padding: 20px 0;">
                <?php foreach ($timeline as $index => $activity): ?>
                    <div style="display: flex; gap: 20px; margin-bottom: 30px; position: relative;">
                        <!-- Timeline line -->
                        <?php if ($index < count($timeline) - 1): ?>
                            <div style="position: absolute; left: 20px; top: 40px; bottom: -30px; width: 2px; background: #ddd;"></div>
                        <?php endif; ?>
                        
                        <!-- Icon -->
                        <div style="position: relative; z-index: 1;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo $activity['color']; ?>; display: flex; align-items: center; justify-content: center; color: white;">
                                <i class="fas <?php echo $activity['icon']; ?>"></i>
                            </div>
                        </div>
                        
                        <!-- Content -->
                        <div style="flex: 1; padding: 10px 20px; background: #f8f9fa; border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <strong style="color: var(--dark-color);"><?php echo $activity['title']; ?></strong>
                                <small style="color: #7f8c8d;">
                                    <i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($activity['date'])); ?>
                                </small>
                            </div>
                            <div style="color: #555;"><?php echo htmlspecialchars($activity['description']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Registration Information -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-info-circle"></i> System Information</h3>
    </div>
    
    <div class="card-body">
        <table style="width: 100%;">
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 10px 0; color: #7f8c8d; width: 30%;">
                    <i class="fas fa-clock"></i> Registered On:
                </td>
                <td style="padding: 10px 0; font-weight: 500;">
                    <?php echo date('d M Y H:i', strtotime($beneficiary['created_at'])); ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0; color: #7f8c8d;">
                    <i class="fas fa-edit"></i> Last Updated:
                </td>
                <td style="padding: 10px 0; font-weight: 500;">
                    <?php echo $beneficiary['updated_at'] ? date('d M Y H:i', strtotime($beneficiary['updated_at'])) : 'Never'; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- Edit Beneficiary Modal -->
<div id="editBeneficiaryModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Participant Profile</h3>
            <span class="close" onclick="closeModal('editBeneficiaryModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/participants-process.php">
                <input type="hidden" name="beneficiary_id" value="<?php echo $beneficiary['beneficiary_id']; ?>">
                <input type="hidden" name="redirect_to_details" value="1">
                
                <!-- Personal Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-user"></i> Personal Information
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_first_name" class="required">First Name</label>
                        <input type="text" id="edit_first_name" name="first_name" class="form-control" 
                               value="<?php echo htmlspecialchars($beneficiary['first_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_last_name" class="required">Last Name</label>
                        <input type="text" id="edit_last_name" name="last_name" class="form-control" 
                               value="<?php echo htmlspecialchars($beneficiary['last_name']); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_gender" class="required">Gender</label>
                        <select id="edit_gender" name="gender" class="form-control" required>
                            <option value="Male" <?php echo $beneficiary['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo $beneficiary['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo $beneficiary['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_date_of_birth">Date of Birth</label>
                        <input type="date" id="edit_date_of_birth" name="date_of_birth" class="form-control" 
                               value="<?php echo $beneficiary['date_of_birth']; ?>">
                    </div>
                </div>
                
                <!-- Contact Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-address-book"></i> Contact Information
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_phone">Phone Number</label>
                        <input type="tel" id="edit_phone" name="phone" class="form-control" 
                               value="<?php echo htmlspecialchars($beneficiary['phone']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($beneficiary['email']); ?>">
                    </div>
                </div>
                
                <!-- Location Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-map-marker-alt"></i> Location Information
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_district">District</label>
                        <select id="edit_district" name="district" class="form-control">
                            <option value="">Select District</option>
                            <?php foreach ($uganda_districts as $dist): ?>
                                <option value="<?php echo $dist; ?>" <?php echo $beneficiary['district'] == $dist ? 'selected' : ''; ?>>
                                    <?php echo $dist; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_subcounty">Sub-county</label>
                        <input type="text" id="edit_subcounty" name="subcounty" class="form-control" 
                               value="<?php echo htmlspecialchars($beneficiary['subcounty']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_village">Village</label>
                        <input type="text" id="edit_village" name="village" class="form-control" 
                               value="<?php echo htmlspecialchars($beneficiary['village']); ?>">
                    </div>
                </div>
                
                <!-- Education & Occupation -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-graduation-cap"></i> Education & Occupation
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_education_level">Education Level</label>
                        <select id="edit_education_level" name="education_level" class="form-control">
                            <option value="">Select Level</option>
                            <option value="None" <?php echo $beneficiary['education_level'] == 'None' ? 'selected' : ''; ?>>None</option>
                            <option value="Primary" <?php echo $beneficiary['education_level'] == 'Primary' ? 'selected' : ''; ?>>Primary</option>
                            <option value="Secondary" <?php echo $beneficiary['education_level'] == 'Secondary' ? 'selected' : ''; ?>>Secondary</option>
                            <option value="Tertiary" <?php echo $beneficiary['education_level'] == 'Tertiary' ? 'selected' : ''; ?>>Tertiary</option>
                            <option value="University" <?php echo $beneficiary['education_level'] == 'University' ? 'selected' : ''; ?>>University</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_occupation">Occupation</label>
                        <input type="text" id="edit_occupation" name="occupation" class="form-control" 
                               value="<?php echo htmlspecialchars($beneficiary['occupation']); ?>">
                    </div>
                </div>
                
                <!-- Disability Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-wheelchair"></i> Disability Information
                </h4>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="edit_is_pwd" name="is_pwd" 
                               <?php echo $beneficiary['is_pwd'] ? 'checked' : ''; ?> 
                               onchange="toggleEditDisabilityType()">
                        <span>Person with Disability (PWD)</span>
                    </label>
                </div>
                
                <div class="form-group" id="edit_disability_type_group" style="display: <?php echo $beneficiary['is_pwd'] ? 'block' : 'none'; ?>;">
                    <label for="edit_disability_type">Disability Type</label>
                    <input type="text" id="edit_disability_type" name="disability_type" class="form-control" 
                           value="<?php echo htmlspecialchars($beneficiary['pwd_type']); ?>">
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('editBeneficiaryModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="edit_beneficiary" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Linkages Modal -->
<div id="manageLinkagesModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3><i class="fas fa-link"></i> Manage Project & Program Linkages</h3>
            <span class="close" onclick="closeModal('manageLinkagesModal')">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Current Linkages Summary -->
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0; font-size: 16px;">
                    <i class="fas fa-info-circle"></i> Current Linkages
                </h4>
                <div style="display: flex; gap: 20px;">
                    <div>
                        <strong>Projects:</strong> <span class="badge badge-info"><?php echo count($projects); ?></span>
                    </div>
                    <div>
                        <strong>Programs:</strong> <span class="badge" style="background: #E67E22; color: white;"><?php echo count($programs); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Add New Linkage -->
            <form method="POST" action="includes/participant-link-process.php">
                <input type="hidden" name="beneficiary_id" value="<?php echo $beneficiary_id; ?>">
                
                <h4 style="margin: 0 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-plus-circle"></i> Add New Linkage
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="link_project_id">
                            <i class="fas fa-project-diagram"></i> Select Project
                        </label>
                        <select id="link_project_id" name="link_project_id" class="form-control" onchange="handleProjectSelection()">
                            <option value="">Select Project </option>
                            <?php foreach ($all_projects as $proj): ?>
                                <?php
                                $is_linked = false;
                                foreach ($projects as $linked_proj) {
                                    if ($linked_proj['project_id'] == $proj['project_id']) {
                                        $is_linked = true;
                                        break;
                                    }
                                }
                                if (!$is_linked):
                                ?>
                                <option value="<?php echo $proj['project_id']; ?>">
                                    <?php echo htmlspecialchars($proj['project_code'] . ' - ' . $proj['project_name']); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="link_program_id">
                            <i class="fas fa-sitemap"></i> Select Program
                        </label>
                        <select id="link_program_id" name="link_program_id" class="form-control" onchange="handleProgramSelection()">
                            <option value="">Select Program</option>
                            <?php foreach ($all_programs as $prog): ?>
                                <?php
                                $is_linked = false;
                                foreach ($programs as $linked_prog) {
                                    if ($linked_prog['id'] == $prog['id']) {
                                        $is_linked = true;
                                        break;
                                    }
                                }
                                if (!$is_linked):
                                ?>
                                <option value="<?php echo $prog['id']; ?>">
                                    <?php echo htmlspecialchars($prog['program_code'] . ' - ' . $prog['program_name']); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <small>Select either a project OR a program to link this participant to.</small>
                </div>
                
                <button type="submit" name="add_linkage" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-plus"></i> Add Linkage
                </button>
            </form>
            
            <!-- Current Projects -->
            <?php if (!empty($projects)): ?>
                <h4 style="margin: 30px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-project-diagram"></i> Linked Projects
                </h4>
                
                <?php foreach ($projects as $project): ?>
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong><?php echo htmlspecialchars($project['project_code']); ?></strong>
                            <div style="font-size: 14px; color: #7f8c8d;">
                                <?php echo htmlspecialchars($project['project_name']); ?>
                            </div>
                            <small style="color: #7f8c8d;">
                                <i class="fas fa-calendar"></i> Enrolled: <?php echo date('d M Y', strtotime($project['enrollment_date'])); ?>
                            </small>
                        </div>
                        <form method="POST" action="includes/participant-link-process.php" style="display: inline;">
                            <input type="hidden" name="beneficiary_id" value="<?php echo $beneficiary_id; ?>">
                            <input type="hidden" name="project_id" value="<?php echo $project['project_id']; ?>">
                            <button type="submit" name="remove_project_link" class="btn btn-danger btn-sm" 
                                    onclick="return confirm('Are you sure you want to remove this project linkage?');">
                                <i class="fas fa-unlink"></i> Remove
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Current Programs -->
            <?php if (!empty($programs)): ?>
                <h4 style="margin: 30px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-sitemap"></i> Linked Programs
                </h4>
                
                <?php foreach ($programs as $program): ?>
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong><?php echo htmlspecialchars($program['program_code']); ?></strong>
                            <div style="font-size: 14px; color: #7f8c8d;">
                                <?php echo htmlspecialchars($program['program_name']); ?>
                            </div>
                            <small style="color: #7f8c8d;">
                                <i class="fas fa-calendar"></i> Enrolled: <?php echo date('d M Y', strtotime($program['enrollment_date'])); ?>
                            </small>
                        </div>
                        <form method="POST" action="includes/participant-link-process.php" style="display: inline;">
                            <input type="hidden" name="beneficiary_id" value="<?php echo $beneficiary_id; ?>">
                            <input type="hidden" name="program_id" value="<?php echo $program['id']; ?>">
                            <button type="submit" name="remove_program_link" class="btn btn-danger btn-sm" 
                                    onclick="return confirm('Are you sure you want to remove this program linkage?');">
                                <i class="fas fa-unlink"></i> Remove
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="modal-footer">
                <button type="button" onclick="closeModal('manageLinkagesModal')" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleEditDisabilityType() {
    const isPwd = document.getElementById('edit_is_pwd').checked;
    document.getElementById('edit_disability_type_group').style.display = isPwd ? 'block' : 'none';
}

function handleProjectSelection() {
    const projectSelect = document.getElementById('link_project_id');
    const programSelect = document.getElementById('link_program_id');
    
    if (projectSelect.value) {
        programSelect.value = '';
        programSelect.disabled = true;
        programSelect.style.opacity = '0.5';
    } else {
        programSelect.disabled = false;
        programSelect.style.opacity = '1';
    }
}

function handleProgramSelection() {
    const projectSelect = document.getElementById('link_project_id');
    const programSelect = document.getElementById('link_program_id');
    
    if (programSelect.value) {
        projectSelect.value = '';
        projectSelect.disabled = true;
        projectSelect.style.opacity = '0.5';
    } else {
        projectSelect.disabled = false;
        projectSelect.style.opacity = '1';
    }
}
</script>

<style>
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

@media print {
    .btn, .modal {
        display: none !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>