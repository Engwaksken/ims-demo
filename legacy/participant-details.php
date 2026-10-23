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

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-user" aria-hidden="true"></i> <?php echo htmlspecialchars($beneficiary['first_name'] . ' ' . $beneficiary['last_name']); ?></h1>
        <p>
            <span class="badge badge-light">ID: <?php echo $beneficiary['beneficiary_id']; ?></span>
            <?php if ($beneficiary['is_pwd']): ?>
                <span class="badge badge-warning"><i class="fas fa-wheelchair" aria-hidden="true"></i> PWD</span>
            <?php endif; ?>
            &nbsp;Participant profile, enrolments, events and activity.
        </p>
    </div>
    <div class="hero-actions">
        <a href="participants" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Participants
        </a>
        <?php if (ims_allowed('participants.edit', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer'], 'Edit participant profiles and programme linkages')): ?>
            <button type="button" onclick="openModal('manageLinkagesModal')" class="btn">
                <i class="fas fa-link" aria-hidden="true"></i> Manage Linkages
            </button>
            <button type="button" onclick="openModal('editBeneficiaryModal')" class="btn btn-primary">
                <i class="fas fa-edit" aria-hidden="true"></i> Edit Profile
            </button>
        <?php endif; ?>
        <button type="button" onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
    </div>
</div>

<!-- Beneficiary Profile Card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-id-badge" aria-hidden="true"></i> Profile</h3>
    </div>

    <div class="card-body">
        <div class="ptd-profile-grid">
            <!-- Personal Information -->
            <div>
                <h4 class="ptd-section-title">
                    <i class="fas fa-id-card" aria-hidden="true"></i> Personal Information
                </h4>

                <table class="ptd-kv">
                    <tr>
                        <th scope="row"><i class="fas fa-venus-mars" aria-hidden="true"></i> Gender:</th>
                        <td>
                            <?php if ($beneficiary['gender'] == 'Male'): ?>
                                <i class="fas fa-male ptd-c-blue" aria-hidden="true"></i> Male
                            <?php elseif ($beneficiary['gender'] == 'Female'): ?>
                                <i class="fas fa-female ptd-c-purple" aria-hidden="true"></i> Female
                            <?php else: ?>
                                <?php echo $beneficiary['gender']; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><i class="fas fa-birthday-cake" aria-hidden="true"></i> Date of Birth:</th>
                        <td>
                            <?php echo $beneficiary['date_of_birth'] ? date('d M Y', strtotime($beneficiary['date_of_birth'])) : 'N/A'; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><i class="fas fa-calendar" aria-hidden="true"></i> Age:</th>
                        <td><?php echo $age; ?> years</td>
                    </tr>
                    <?php if ($beneficiary['is_pwd']): ?>
                    <tr>
                        <th scope="row"><i class="fas fa-wheelchair" aria-hidden="true"></i> Disability Type:</th>
                        <td><?php echo htmlspecialchars($beneficiary['pwd_type'] ?? 'Not specified'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><i class="fas fa-graduation-cap" aria-hidden="true"></i> Education:</th>
                        <td><?php echo htmlspecialchars($beneficiary['education_level'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><i class="fas fa-briefcase" aria-hidden="true"></i> Occupation:</th>
                        <td><?php echo htmlspecialchars($beneficiary['occupation'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Contact Information -->
            <div>
                <h4 class="ptd-section-title">
                    <i class="fas fa-address-book" aria-hidden="true"></i> Contact Information
                </h4>

                <table class="ptd-kv">
                    <tr>
                        <th scope="row"><i class="fas fa-phone" aria-hidden="true"></i> Phone:</th>
                        <td>
                            <?php if ($beneficiary['phone']): ?>
                                <a href="tel:<?php echo h($beneficiary['phone']); ?>"><?php echo htmlspecialchars($beneficiary['phone']); ?></a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><i class="fas fa-envelope" aria-hidden="true"></i> Email:</th>
                        <td>
                            <?php if ($beneficiary['email']): ?>
                                <a href="mailto:<?php echo h($beneficiary['email']); ?>"><?php echo htmlspecialchars($beneficiary['email']); ?></a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><i class="fas fa-map-marker-alt" aria-hidden="true"></i> District:</th>
                        <td><?php echo htmlspecialchars($beneficiary['district'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><i class="fas fa-map-pin" aria-hidden="true"></i> Sub-county:</th>
                        <td><?php echo htmlspecialchars($beneficiary['subcounty'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><i class="fas fa-home" aria-hidden="true"></i> Village:</th>
                        <td><?php echo htmlspecialchars($beneficiary['village'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Engagement Summary -->
            <div>
                <h4 class="ptd-section-title">
                    <i class="fas fa-chart-bar" aria-hidden="true"></i> Engagement Summary
                </h4>

                <div class="ptd-tiles">
                    <div class="ptd-tile tone-green">
                        <div class="ptd-tile-value"><?php echo $stats['total_programs']; ?></div>
                        <div class="ptd-tile-label">Programs</div>
                        <small><?php echo $stats['active_programs']; ?> active</small>
                    </div>

                    <div class="ptd-tile tone-blue">
                        <div class="ptd-tile-value"><?php echo $stats['total_projects']; ?></div>
                        <div class="ptd-tile-label">Projects</div>
                        <small><?php echo $stats['active_projects']; ?> active</small>
                    </div>

                    <div class="ptd-tile tone-amber">
                        <div class="ptd-tile-value"><?php echo $stats['events_attended']; ?></div>
                        <div class="ptd-tile-label">Events</div>
                    </div>

                    <div class="ptd-tile tone-purple">
                        <div class="ptd-tile-value"><?php echo $stats['trainings_completed']; ?></div>
                        <div class="ptd-tile-label">Trainings</div>
                    </div>
                </div>

                <?php if ($avg_feedback > 0): ?>
                <div class="ptd-rating">
                    <div class="ptd-tile-label">Average Rating</div>
                    <div class="ptd-rating-value"><?php echo number_format($avg_feedback, 1); ?>/5.0</div>
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
        <h3><i class="fas fa-sitemap" aria-hidden="true"></i> Program Participation (<?php echo count($programs); ?>)</h3>
    </div>

    <div class="card-body">
        <?php foreach ($programs as $program): ?>
            <div class="ptd-enrol <?php
                echo $program['status'] == 'Active' ? 'is-active' :
                    ($program['status'] == 'Completed' ? 'is-completed' : '');
            ?>">
                <div class="ptd-enrol-head">
                    <div class="ptd-enrol-main">
                        <div class="ptd-enrol-title">
                            <span class="badge badge-warning">
                                <i class="fas fa-sitemap" aria-hidden="true"></i> PROGRAM
                            </span>
                            <h4><?php echo htmlspecialchars($program['program_name']); ?></h4>
                        </div>
                        <div class="ptd-muted">
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

                <div class="ptd-enrol-facts">
                    <div>
                        <div class="ptd-fact-label">Participation Type</div>
                        <div class="ptd-fact-value"><?php echo htmlspecialchars($program['participation_type'] ?? 'General'); ?></div>
                    </div>

                    <div>
                        <div class="ptd-fact-label">Enrollment Date</div>
                        <div class="ptd-fact-value">
                            <i class="fas fa-calendar" aria-hidden="true"></i>
                            <?php echo date('d M Y', strtotime($program['enrollment_date'])); ?>
                        </div>
                    </div>

                    <?php if ($program['completed_date']): ?>
                    <div>
                        <div class="ptd-fact-label">Completion Date</div>
                        <div class="ptd-fact-value">
                            <i class="fas fa-check-circle ptd-c-green" aria-hidden="true"></i>
                            <?php echo date('d M Y', strtotime($program['completed_date'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($program['outcomes']): ?>
                    <div class="ptd-outcomes">
                        <strong>
                            <i class="fas fa-trophy" aria-hidden="true"></i> Outcomes Achieved:
                        </strong>
                        <p>
                            <?php echo nl2br(htmlspecialchars($program['outcomes'])); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="ptd-enrol-actions">
                    <a href="program-details?id=<?php echo $program['id']; ?>" class="btn btn-soft btn-sm">
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
        <h3><i class="fas fa-project-diagram" aria-hidden="true"></i> Project Participation (<?php echo count($projects); ?>)</h3>
    </div>

    <div class="card-body">
        <?php foreach ($projects as $project): ?>
            <div class="ptd-enrol <?php
                echo $project['status'] == 'Active' ? 'is-active' :
                    ($project['status'] == 'Completed' ? 'is-completed' : '');
            ?>">
                <div class="ptd-enrol-head">
                    <div class="ptd-enrol-main">
                        <div class="ptd-enrol-title">
                            <span class="badge badge-success">
                                <i class="fas fa-project-diagram" aria-hidden="true"></i> PROJECT
                            </span>
                            <h4><?php echo htmlspecialchars($project['project_name']); ?></h4>
                        </div>
                        <div class="ptd-muted">
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

                <div class="ptd-enrol-facts">
                    <div>
                        <div class="ptd-fact-label">Participation Type</div>
                        <div class="ptd-fact-value"><?php echo htmlspecialchars($project['participation_type'] ?? 'General'); ?></div>
                    </div>

                    <div>
                        <div class="ptd-fact-label">Enrollment Date</div>
                        <div class="ptd-fact-value">
                            <i class="fas fa-calendar" aria-hidden="true"></i>
                            <?php echo date('d M Y', strtotime($project['enrollment_date'])); ?>
                        </div>
                    </div>

                    <?php if ($project['completed_date']): ?>
                    <div>
                        <div class="ptd-fact-label">Completion Date</div>
                        <div class="ptd-fact-value">
                            <i class="fas fa-check-circle ptd-c-green" aria-hidden="true"></i>
                            <?php echo date('d M Y', strtotime($project['completed_date'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($project['outcomes']): ?>
                    <div class="ptd-outcomes">
                        <strong>
                            <i class="fas fa-trophy" aria-hidden="true"></i> Outcomes Achieved:
                        </strong>
                        <p>
                            <?php echo nl2br(htmlspecialchars($project['outcomes'])); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="ptd-enrol-actions">
                    <a href="project-details?id=<?php echo $project['project_id']; ?>" class="btn btn-soft btn-sm">
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
        <div class="empty-state">
            <i class="fas fa-folder-open" aria-hidden="true"></i>
            <p>This participant has not been enrolled in any programs or projects yet.</p>
            <?php if (ims_allowed('participants.edit', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer'], 'Edit participant profiles and programme linkages')): ?>
                <button type="button" onclick="openModal('manageLinkagesModal')" class="btn btn-primary">
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
        <h3><i class="fas fa-calendar-check" aria-hidden="true"></i> Event Participation (<?php echo count($events); ?>)</h3>
    </div>

    <div class="card-body">
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times" aria-hidden="true"></i>
                <p>This participant has not attended any events yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table data-table">
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
                                            echo $event['feedback_score'] >= 4 ? 'var(--green-fg)' :
                                                ($event['feedback_score'] >= 3 ? 'var(--amber-fg)' : 'var(--red-fg)');
                                        ?>;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star<?php echo $i <= $event['feedback_score'] ? '' : '-o'; ?>"></i>
                                            <?php endfor; ?>
                                            (<?php echo $event['feedback_score']; ?>/5)
                                        </div>
                                        <?php if ($event['feedback_comments']): ?>
                                            <small class="ptd-muted" title="<?php echo htmlspecialchars($event['feedback_comments']); ?>">
                                                <i class="fas fa-comment"></i> Has comments
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ptd-muted">No feedback</span>
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
        <h3><i class="fas fa-graduation-cap" aria-hidden="true"></i> Training & Capacity Building (<?php echo count($trainings); ?>)</h3>
    </div>

    <div class="card-body">
        <div class="ptd-training-grid">
            <?php foreach ($trainings as $training): ?>
                <div class="ptd-training">
                    <h4><?php echo htmlspecialchars($training['event_name']); ?></h4>
                    <div class="ptd-training-meta">
                        <span class="badge badge-warning"><?php echo $training['event_type']; ?></span>
                        <span><i class="fas fa-calendar" aria-hidden="true"></i> <?php echo date('d M Y', strtotime($training['event_date'])); ?></span>
                    </div>
                    <?php if ($training['organizer']): ?>
                        <div class="ptd-training-org">
                            <i class="fas fa-user-tie" aria-hidden="true"></i> Organized by: <?php echo htmlspecialchars($training['organizer']); ?>
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
        <h3><i class="fas fa-history" aria-hidden="true"></i> Activity Timeline</h3>
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
                'color' => 'var(--brand-600)',
                'title' => 'Enrolled in Program',
                'description' => $program['program_name']
            ];

            if ($program['completed_date']) {
                $timeline[] = [
                    'date' => $program['completed_date'],
                    'type' => 'program_completion',
                    'icon' => 'fa-check-circle',
                    'color' => 'var(--green-fg)',
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
                'color' => 'var(--blue-fg)',
                'title' => 'Enrolled in Project',
                'description' => $project['project_name']
            ];

            if ($project['completed_date']) {
                $timeline[] = [
                    'date' => $project['completed_date'],
                    'type' => 'project_completion',
                    'icon' => 'fa-check-circle',
                    'color' => 'var(--green-fg)',
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
                    'color' => 'var(--purple-fg)',
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
            <div class="empty-state">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <p>No activity yet. This participant's activity timeline will appear here.</p>
            </div>
        <?php else: ?>
            <div class="ptd-timeline">
                <?php foreach ($timeline as $index => $activity): ?>
                    <div class="ptd-tl-item">
                        <!-- Timeline line -->
                        <?php if ($index < count($timeline) - 1): ?>
                            <div class="ptd-tl-line"></div>
                        <?php endif; ?>

                        <!-- Icon -->
                        <div class="ptd-tl-icon-wrap">
                            <div class="ptd-tl-icon" style="background: <?php echo $activity['color']; ?>;">
                                <i class="fas <?php echo $activity['icon']; ?>"></i>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="ptd-tl-content">
                            <div class="ptd-tl-head">
                                <strong><?php echo $activity['title']; ?></strong>
                                <small class="ptd-muted">
                                    <i class="fas fa-calendar" aria-hidden="true"></i> <?php echo date('d M Y', strtotime($activity['date'])); ?>
                                </small>
                            </div>
                            <div class="ptd-tl-text"><?php echo htmlspecialchars($activity['description']); ?></div>
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
        <h3><i class="fas fa-info-circle" aria-hidden="true"></i> System Information</h3>
    </div>

    <div class="card-body">
        <table class="ptd-kv ptd-kv-wide">
            <tr>
                <th scope="row">
                    <i class="fas fa-clock" aria-hidden="true"></i> Registered On:
                </th>
                <td>
                    <?php echo date('d M Y H:i', strtotime($beneficiary['created_at'])); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <i class="fas fa-edit" aria-hidden="true"></i> Last Updated:
                </th>
                <td>
                    <?php echo $beneficiary['updated_at'] ? date('d M Y H:i', strtotime($beneficiary['updated_at'])) : 'Never'; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

</div><!-- /.page-stack -->

<!-- Edit Beneficiary Modal -->
<div id="editBeneficiaryModal" class="modal">
    <div class="modal-content ptd-modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Participant Profile</h3>
            <span class="close" onclick="closeModal('editBeneficiaryModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/participants-process.php">
                <input type="hidden" name="beneficiary_id" value="<?php echo $beneficiary['beneficiary_id']; ?>">
                <input type="hidden" name="redirect_to_details" value="1">
                
                <!-- Personal Information -->
                <h4 class="ptd-form-title">
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
                <h4 class="ptd-form-title">
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
                <h4 class="ptd-form-title">
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
                <h4 class="ptd-form-title">
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
                <h4 class="ptd-form-title">
                    <i class="fas fa-wheelchair"></i> Disability Information
                </h4>
                
                <div class="form-group">
                    <label class="ptd-check">
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
                    <button type="submit" name="edit_beneficiary" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Linkages Modal -->
<div id="manageLinkagesModal" class="modal">
    <div class="modal-content ptd-modal-md">
        <div class="modal-header">
            <h3><i class="fas fa-link"></i> Manage Project & Program Linkages</h3>
            <span class="close" onclick="closeModal('manageLinkagesModal')">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Current Linkages Summary -->
            <div class="ptd-box">
                <h4>
                    <i class="fas fa-info-circle"></i> Current Linkages
                </h4>
                <div class="ptd-linkages">
                    <div>
                        <strong>Projects:</strong> <span class="badge badge-info"><?php echo count($projects); ?></span>
                    </div>
                    <div>
                        <strong>Programs:</strong> <span class="badge badge-warning"><?php echo count($programs); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Add New Linkage -->
            <form method="POST" action="includes/participant-link-process.php">
                <input type="hidden" name="beneficiary_id" value="<?php echo $beneficiary_id; ?>">
                
                <h4 class="ptd-form-title first">
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
                
                <button type="submit" name="add_linkage" class="btn btn-primary ptd-block-btn">
                    <i class="fas fa-plus"></i> Add Linkage
                </button>
            </form>
            
            <!-- Current Projects -->
            <?php if (!empty($projects)): ?>
                <h4 class="ptd-form-title spaced">
                    <i class="fas fa-project-diagram"></i> Linked Projects
                </h4>
                
                <?php foreach ($projects as $project): ?>
                    <div class="ptd-link-row">
                        <div>
                            <strong><?php echo htmlspecialchars($project['project_code']); ?></strong>
                            <div class="ptd-muted">
                                <?php echo htmlspecialchars($project['project_name']); ?>
                            </div>
                            <small class="ptd-muted">
                                <i class="fas fa-calendar"></i> Enrolled: <?php echo date('d M Y', strtotime($project['enrollment_date'])); ?>
                            </small>
                        </div>
                        <form method="POST" action="includes/participant-link-process.php" class="ptd-inline-form">
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
                <h4 class="ptd-form-title spaced">
                    <i class="fas fa-sitemap"></i> Linked Programs
                </h4>
                
                <?php foreach ($programs as $program): ?>
                    <div class="ptd-link-row">
                        <div>
                            <strong><?php echo htmlspecialchars($program['program_code']); ?></strong>
                            <div class="ptd-muted">
                                <?php echo htmlspecialchars($program['program_name']); ?>
                            </div>
                            <small class="ptd-muted">
                                <i class="fas fa-calendar"></i> Enrolled: <?php echo date('d M Y', strtotime($program['enrollment_date'])); ?>
                            </small>
                        </div>
                        <form method="POST" action="includes/participant-link-process.php" class="ptd-inline-form">
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
/* Participant details: page-only layout (ptd- prefix). Header, cards,
   badges, buttons, tables, alerts, empty states and modals are global. */
.ptd-profile-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; }

.ptd-section-title {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--brand-500);
    color: var(--ink-700);
}

.ptd-kv { width: 100%; background: transparent; }
.ptd-kv tr { border-bottom: 1px solid var(--ink-100); }
.ptd-kv tr:last-child { border-bottom: none; }
.ptd-kv tr:hover { background: transparent; }
.ptd-kv th,
.ptd-kv td { padding: 10px 0; border: none; background: transparent; text-align: left; }
.ptd-kv th { width: 40%; font-weight: 400; color: var(--ink-300); }
.ptd-kv-wide th { width: 30%; }
.ptd-kv td { font-weight: 500; color: var(--ink-700); }

.ptd-c-blue   { color: var(--blue-fg); }
.ptd-c-purple { color: var(--purple-fg); }
.ptd-c-green  { color: var(--green-fg); }
.ptd-muted { color: var(--ink-300); font-size: var(--fs-sm); }

.ptd-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.ptd-tile { padding: 15px; border-radius: var(--radius-sm); text-align: center; }
.ptd-tile-value { font-size: 24px; font-weight: 800; }
.ptd-tile-label { font-size: var(--fs-xs); color: var(--ink-300); }
.ptd-tile.tone-green  { background: var(--green-bg); }
.ptd-tile.tone-green .ptd-tile-value,
.ptd-tile.tone-green small { color: var(--green-fg); }
.ptd-tile.tone-blue   { background: var(--blue-bg); }
.ptd-tile.tone-blue .ptd-tile-value,
.ptd-tile.tone-blue small { color: var(--blue-fg); }
.ptd-tile.tone-amber  { background: var(--amber-bg); }
.ptd-tile.tone-amber .ptd-tile-value { color: var(--amber-fg); }
.ptd-tile.tone-purple { background: var(--purple-bg); }
.ptd-tile.tone-purple .ptd-tile-value { color: var(--purple-fg); }

.ptd-rating { margin-top: 10px; padding: 10px; border-radius: var(--radius-sm); background: var(--ink-50); text-align: center; }
.ptd-rating-value { font-size: 20px; font-weight: 800; color: var(--brand-600); }

.ptd-enrol {
    margin-bottom: 15px;
    padding: 20px;
    border-left: 4px solid var(--ink-200);
    border-radius: var(--radius-sm);
    background: var(--ink-50);
}
.ptd-enrol:last-child { margin-bottom: 0; }
.ptd-enrol.is-active { border-left-color: var(--green-fg); }
.ptd-enrol.is-completed { border-left-color: var(--blue-fg); }
.ptd-enrol-head { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--space-3); margin-bottom: 15px; }
.ptd-enrol-main { flex: 1; min-width: 0; }
.ptd-enrol-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 5px; }
.ptd-enrol-title h4 { margin: 0; color: var(--ink-700); }
.ptd-enrol-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px; }
.ptd-fact-label { margin-bottom: 3px; font-size: var(--fs-xs); color: var(--ink-300); }
.ptd-fact-value { font-weight: 500; color: var(--ink-700); }
.ptd-outcomes { margin-top: 15px; padding: 15px; border-radius: var(--radius-sm); background: var(--surface-card); }
.ptd-outcomes strong { color: var(--brand-600); }
.ptd-outcomes p { margin: 10px 0 0; color: var(--ink-500); }
.ptd-enrol-actions { margin-top: 15px; }

.ptd-training-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
.ptd-training { padding: 20px; border-left: 4px solid var(--amber-fg); border-radius: var(--radius-sm); background: var(--ink-50); }
.ptd-training h4 { margin: 0 0 10px; color: var(--ink-700); font-size: var(--fs-md); }
.ptd-training-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; color: var(--ink-300); font-size: var(--fs-base); }
.ptd-training-org { margin-top: 10px; color: var(--ink-300); font-size: var(--fs-sm); }

.ptd-timeline { position: relative; padding: 20px 0; }
.ptd-tl-item { position: relative; display: flex; gap: 20px; margin-bottom: 30px; }
.ptd-tl-item:last-child { margin-bottom: 0; }
.ptd-tl-line { position: absolute; left: 20px; top: 40px; bottom: -30px; width: 2px; background: var(--ink-100); }
.ptd-tl-icon-wrap { position: relative; z-index: 1; }
.ptd-tl-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    color: var(--ink-0);
}
.ptd-tl-content { flex: 1; min-width: 0; padding: 10px 20px; border-radius: var(--radius-sm); background: var(--ink-50); }
.ptd-tl-head { display: flex; justify-content: space-between; align-items: center; gap: var(--space-3); flex-wrap: wrap; margin-bottom: 5px; }
.ptd-tl-head strong { color: var(--ink-700); }
.ptd-tl-text { color: var(--ink-500); }

/* Modals */
.ptd-modal-lg { max-width: 900px; max-height: 90vh; overflow-y: auto; }
.ptd-modal-md { max-width: 800px; }
.ptd-form-title {
    margin: 20px 0 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--brand-500);
    color: var(--ink-700);
    font-size: var(--fs-md);
}
.ptd-form-title.first { margin-top: 0; }
.ptd-form-title.spaced { margin-top: 30px; }
.ptd-form-title i,
.ptd-box h4 i { margin-right: 8px; color: var(--brand-500); }
.ptd-check { display: flex; align-items: center; gap: 10px; }
.ptd-box { margin-bottom: 20px; padding: 15px; border-radius: var(--radius-sm); background: var(--ink-50); }
.ptd-box h4 { margin: 0 0 10px; font-size: var(--fs-md); }
.ptd-linkages { display: flex; gap: 20px; flex-wrap: wrap; }
.ptd-block-btn { width: 100%; }
.ptd-link-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: 10px;
    padding: 15px;
    border-radius: var(--radius-sm);
    background: var(--ink-50);
}
.ptd-inline-form { display: inline; }

@media print {
    .btn, .modal {
        display: none !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>