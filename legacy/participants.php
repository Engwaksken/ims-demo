<?php
$page_title = 'Participants Management';
include 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Check if filtering by project or program
$filter_project_id = isset($_GET['project']) ? intval($_GET['project']) : 0;
$filter_program_id = isset($_GET['program']) ? intval($_GET['program']) : 0;
$project_info = null;
$program_info = null;
$context_type = null;

if ($filter_project_id) {
    $project_result = $conn->query("SELECT project_id, project_name, project_code FROM projects WHERE project_id = $filter_project_id");
    if ($project_result->num_rows > 0) {
        $project_info = $project_result->fetch_assoc();
        $context_type = 'project';
        $page_title = 'Participants for ' . $project_info['project_code'];
    } else {
        send_notification($_SESSION['user_id'], 'Project not found', 'danger');
        header("Location: participants");
        exit();
    }
} elseif ($filter_program_id) {
    $program_result = $conn->query("SELECT id, program_name, program_code FROM programs WHERE id = $filter_program_id");
    if ($program_result->num_rows > 0) {
        $program_info = $program_result->fetch_assoc();
        $context_type = 'program';
        $page_title = 'Participants for ' . $program_info['program_code'];
    } else {
        send_notification($_SESSION['user_id'], 'Program not found', 'danger');
        header("Location: participants");
        exit();
    }
}

// Fetch all projects for dropdown
$projects = [];
$projects_result = $conn->query("SELECT project_id, project_name, project_code FROM projects ORDER BY project_name ASC");
while ($row = $projects_result->fetch_assoc()) {
    $projects[] = $row;
}

// Fetch all programs for dropdown
$programs = [];
$programs_result = $conn->query("SELECT id, program_name, program_code FROM programs ORDER BY program_name ASC");
while ($row = $programs_result->fetch_assoc()) {
    $programs[] = $row;
}

// Get beneficiary for editing
$edit_beneficiary = null;
$edit_linked_projects = [];
$edit_linked_programs = [];
if (isset($_GET['edit'])) {
    $beneficiary_id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM beneficiaries WHERE beneficiary_id = $beneficiary_id");
    $edit_beneficiary = $result->fetch_assoc();
    
    // Get linked projects
    $linked_projects_result = $conn->query("SELECT project_id FROM project_beneficiaries WHERE beneficiary_id = $beneficiary_id");
    while ($row = $linked_projects_result->fetch_assoc()) {
        $edit_linked_projects[] = $row['project_id'];
    }
    
    // Get linked programs
    $linked_programs_result = $conn->query("SELECT program_id FROM programs_beneficiaries WHERE beneficiary_id = $beneficiary_id");
    while ($row = $linked_programs_result->fetch_assoc()) {
        $edit_linked_programs[] = $row['program_id'];
    }
}

// Fetch beneficiaries with filters
$where = "1=1";
$filter_gender = isset($_GET['gender']) ? sanitize_input($_GET['gender']) : '';
$filter_district = isset($_GET['district']) ? sanitize_input($_GET['district']) : '';
$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';

// If filtering by project, only show beneficiaries in that project
if ($filter_project_id) {
    $where .= " AND b.beneficiary_id IN (SELECT beneficiary_id FROM project_beneficiaries WHERE project_id = $filter_project_id)";
}

// If filtering by program, only show beneficiaries in that program
if ($filter_program_id) {
    $where .= " AND b.beneficiary_id IN (SELECT beneficiary_id FROM programs_beneficiaries WHERE program_id = $filter_program_id)";
}

if ($filter_gender) {
    $where .= " AND b.gender = '" . $conn->real_escape_string($filter_gender) . "'";
}
if ($filter_district) {
    $where .= " AND b.district = '" . $conn->real_escape_string($filter_district) . "'";
}
if ($search) {
    $search_like = $conn->real_escape_string(addcslashes($search, '%_'));
    $where .= " AND (b.first_name LIKE '%$search_like%' OR b.last_name LIKE '%$search_like%' OR b.phone LIKE '%$search_like%' OR b.email LIKE '%$search_like%')";
}

$beneficiaries = [];
$query = "SELECT b.*, 
    (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id) as project_count,
    (SELECT COUNT(*) FROM programs_beneficiaries prb WHERE prb.beneficiary_id = b.beneficiary_id) as program_count
    FROM beneficiaries b 
    WHERE $where
    ORDER BY b.created_at DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $beneficiaries[] = $row;
}

// Get distinct districts for filter
$districts = [];
$result = $conn->query("SELECT DISTINCT district FROM beneficiaries WHERE district IS NOT NULL AND district != '' ORDER BY district");
while ($row = $result->fetch_assoc()) {
    $districts[] = $row['district'];
}

// Statistics
$stats = [
    'total' => count($beneficiaries),
    'male' => count(array_filter($beneficiaries, function($b) { return $b['gender'] == 'Male'; })),
    'female' => count(array_filter($beneficiaries, function($b) { return $b['gender'] == 'Female'; })),
    'pwd' => count(array_filter($beneficiaries, function($b) { return $b['is_pwd'] == 1; }))
];

// Uganda districts
$uganda_districts = ['Abim', 'Adjumani', 'Agago', 'Alebtong', 'Amolatar', 'Amudat', 'Amuria', 'Amuru', 'Apac', 'Arua', 'Budaka', 'Bududa', 'Bugiri', 'Buhweju', 'Buikwe', 'Bukedea', 'Bukomansimbi', 'Bukwo', 'Bulambuli', 'Buliisa', 'Bundibugyo', 'Bunyangabu', 'Bushenyi', 'Busia', 'Butaleja', 'Butambala', 'Butebo', 'Buvuma', 'Buyende', 'Dokolo', 'Gomba', 'Gulu', 'Hoima', 'Ibanda', 'Iganga', 'Isingiro', 'Jinja', 'Kaabong', 'Kabale', 'Kabarole', 'Kaberamaido', 'Kagadi', 'Kakumiro', 'Kalangala', 'Kaliro', 'Kalungu', 'Kampala', 'Kamuli', 'Kamwenge', 'Kanungu', 'Kapchorwa', 'Kasese', 'Katakwi', 'Kayunga', 'Kibaale', 'Kiboga', 'Kibuku', 'Kiruhura', 'Kiryandongo', 'Kisoro', 'Kitgum', 'Koboko', 'Kole', 'Kotido', 'Kumi', 'Kween', 'Kyankwanzi', 'Kyegegwa', 'Kyenjojo', 'Kyotera', 'Lamwo', 'Lira', 'Luuka', 'Luwero', 'Lwengo', 'Lyantonde', 'Manafwa', 'Maracha', 'Masaka', 'Masindi', 'Mayuge', 'Mbale', 'Mbarara', 'Mitooma', 'Mityana', 'Moroto', 'Moyo', 'Mpigi', 'Mubende', 'Mukono', 'Nakapiripirit', 'Nakaseke', 'Nakasongola', 'Namayingo', 'Namisindwa', 'Namutumba', 'Napak', 'Nebbi', 'Ngora', 'Ntoroko', 'Ntungamo', 'Nwoya', 'Omoro', 'Otuke', 'Oyam', 'Pader', 'Pakwach', 'Pallisa', 'Rakai', 'Rubanda', 'Rubirizi', 'Rukiga', 'Rukungiri', 'Sembabule', 'Serere', 'Sheema', 'Sironko', 'Soroti', 'Tororo', 'Wakiso', 'Yumbe', 'Zombo'];

// Build context query string for navigation
$context_query = '';
if ($project_info) {
    $context_query = "project=$filter_project_id";
} elseif ($program_info) {
    $context_query = "program=$filter_program_id";
}
?>

<!-- Project/Program Context Banner -->
<?php if ($project_info || $program_info): ?>
<div class="card" style="background: linear-gradient(135deg, <?php echo $program_info ? '#E67E22' : 'var(--primary-color)'; ?>, <?php echo $program_info ? '#D35400' : '#3498DB'; ?>); color: white; margin-bottom: 20px;">
    <div class="card-body" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <?php if ($program_info): ?>
                        <span class="badge" style="background: white; color: #E67E22; font-size: 14px; padding: 8px 12px;">
                            <i class="fas fa-sitemap"></i> PROGRAM
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background: white; color: var(--primary-color); font-size: 14px; padding: 8px 12px;">
                            <i class="fas fa-project-diagram"></i> PROJECT
                        </span>
                    <?php endif; ?>
                </div>
                <h2 style="margin: 0; color: white;">
                    <?php echo htmlspecialchars($project_info ? $project_info['project_code'] : $program_info['program_code']); ?>
                </h2>
                <p style="margin: 5px 0 0 0; font-size: 16px; opacity: 0.9;">
                    <?php echo htmlspecialchars($project_info ? $project_info['project_name'] : $program_info['program_name']); ?>
                </p>
            </div>
            <div>
                <a href="participants" class="btn" style="background: white; color: <?php echo $program_info ? '#E67E22' : 'var(--primary-color)'; ?>;">
                    <i class="fas fa-users"></i> View All Participants
                </a>
                <?php if ($project_info): ?>
                    <a href="project-details?id=<?php echo $filter_project_id; ?>" class="btn" style="background: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-arrow-left"></i> Back to Project
                    </a>
                <?php else: ?>
                    <a href="program-details?id=<?php echo $filter_program_id; ?>" class="btn" style="background: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-arrow-left"></i> Back to Program
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Statistics -->
<?php
$malePct   = $stats['total'] > 0 ? round(($stats['male'] / $stats['total']) * 100, 1) : 0;
$femalePct = $stats['total'] > 0 ? round(($stats['female'] / $stats['total']) * 100, 1) : 0;
?>
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label"><?php echo ($project_info || $program_info) ? 'Participants' : 'Total Participants'; ?></div>
            <div class="stat-value"><?php echo number_format((int)$stats['total']); ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-male" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Male</div>
            <div class="stat-value"><?php echo number_format((int)$stats['male']); ?><span class="stat-sub"><?php echo $malePct; ?>%</span></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-female" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Female</div>
            <div class="stat-value"><?php echo number_format((int)$stats['female']); ?><span class="stat-sub"><?php echo $femalePct; ?>%</span></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-wheelchair" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label" title="Persons with Disabilities">Persons with Disabilities</div>
            <div class="stat-value"><?php echo number_format((int)$stats['pwd']); ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-users"></i> Participants Management</h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button onclick="openModal('addBeneficiaryModal')" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Add Participant
            </button>
            <div class="btn-group">
                <button onclick="window.location.href='download-participants-template<?php echo $context_query ? "?$context_query" : ""; ?>'" class="btn btn-info">
                    <i class="fas fa-download"></i> Download Template
                </button>
                <button onclick="openModal('uploadCsvModal')" class="btn btn-info">
                    <i class="fas fa-upload"></i> Upload CSV
                </button>
            </div>
            <button onclick="exportToCSV('beneficiariesTable', 'participants')" class="btn btn-success">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" action="" class="filters-bar" role="search" aria-label="Filter participants">
            <?php if ($filter_project_id): ?>
                <input type="hidden" name="project" value="<?php echo $filter_project_id; ?>">
            <?php elseif ($filter_program_id): ?>
                <input type="hidden" name="program" value="<?php echo $filter_program_id; ?>">
            <?php endif; ?>
            
            <div class="form-group filters-grow">
                <input type="text" name="search" class="form-control" placeholder="Search by name, phone, or email..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="form-group">
                <select name="gender" class="form-control">
                    <option value="">All Genders</option>
                    <option value="Male" <?php echo $filter_gender == 'Male' ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo $filter_gender == 'Female' ? 'selected' : ''; ?>>Female</option>
                    <option value="Other" <?php echo $filter_gender == 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <select name="district" class="form-control">
                    <option value="">All Districts</option>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?php echo htmlspecialchars($district); ?>" <?php echo $filter_district == $district ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($district); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filters-actions">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="<?php echo $context_query ? "participants?$context_query" : "participants"; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
        
        <!-- Beneficiaries Table -->
        <div class="table-responsive">
            <table class="data-table" id="beneficiariesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>Age</th>
                        <th>Phone</th>
                        <th>District</th>
                        <th>PWD</th>
                        <th>Programs</th>
                        <th>Projects</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($beneficiaries)): ?>
                        <tr>
                            <td colspan="10" class="text-center" style="padding: 40px;">
                                <?php if ($project_info || $program_info): ?>
                                    <i class="fas fa-users" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3; color: #7f8c8d;"></i>
                                    <h4>No participants found for this <?php echo $project_info ? 'project' : 'program'; ?>.</h4>
                                    <p style="color: #7f8c8d;">Start adding participants to this <?php echo $project_info ? 'project' : 'program'; ?></p>
                                    <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                                        <button onclick="openModal('addBeneficiaryModal')" class="btn btn-primary">
                                            <i class="fas fa-user-plus"></i> Add First Participant
                                        </button>
                                        <button onclick="window.location.href='download-participants-template?<?php echo $context_query; ?>'" class="btn btn-info">
                                            <i class="fas fa-download"></i> Download CSV Template
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <i class="fas fa-users" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3; color: #7f8c8d;"></i>
                                    <h4>No Participants found</h4>
                                    <p style="color: #7f8c8d;">Start by adding participants individually or import from CSV</p>
                                    <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                                        <button onclick="openModal('addBeneficiaryModal')" class="btn btn-primary">
                                            <i class="fas fa-user-plus"></i> Add Participant
                                        </button>
                                        <button onclick="window.location.href='download-participants-template'" class="btn btn-info">
                                            <i class="fas fa-download"></i> Download CSV Template
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($beneficiaries as $beneficiary): ?>
                            <tr>
                                <td><?php echo $beneficiary['beneficiary_id']; ?></td>
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
                                <td>
                                    <?php 
                                    if ($beneficiary['date_of_birth']) {
                                        $dob = new DateTime($beneficiary['date_of_birth']);
                                        $now = new DateTime();
                                        $age = $now->diff($dob)->y;
                                        echo $age;
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($beneficiary['phone'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($beneficiary['district'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($beneficiary['is_pwd']): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-wheelchair"></i> Yes
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge" style="background: #E67E22; color: white;">
                                        <?php echo $beneficiary['program_count']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-info"><?php echo $beneficiary['project_count']; ?></span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="participant-details?id=<?php echo $beneficiary['beneficiary_id']; ?><?php echo $context_query ? "&$context_query" : ''; ?>" 
                                           class="btn btn-info btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <a href="?<?php echo $context_query ? "$context_query&" : ''; ?>edit=<?php echo $beneficiary['beneficiary_id']; ?>" 
                                           class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if (ims_allowed('participants.delete', ['Administrator'], 'Delete participants')): ?>
                                            <button onclick="openDeleteModal(<?php echo $beneficiary['beneficiary_id']; ?>, <?= htmlspecialchars(json_encode((string)($beneficiary['first_name'] . ' ' . $beneficiary['last_name'])), ENT_QUOTES, 'UTF-8') ?>)" 
                                                    class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Upload CSV Modal -->
<div id="uploadCsvModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><i class="fas fa-upload"></i> Upload Participants CSV</h3>
            <span class="close" onclick="closeModal('uploadCsvModal')">&times;</span>
        </div>
        <div class="modal-body">
            <?php if ($project_info): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Project Linking:</strong> All participants in this CSV will be automatically linked to <strong><?php echo htmlspecialchars($project_info['project_code']); ?></strong>
                </div>
            <?php elseif ($program_info): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Program Linking:</strong> All participants in this CSV will be automatically linked to <strong><?php echo htmlspecialchars($program_info['program_code']); ?></strong>
                </div>
            <?php endif; ?>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>Instructions:</strong>
                <ol style="margin: 10px 0 0 0; padding-left: 20px;">
                    <li>Download the CSV template using the "Download Template" button</li>
                    <li>Fill in the participants information in the CSV file</li>
                    <li>Delete the sample rows and instructions</li>
                    <li>Upload the completed CSV file below</li>
                </ol>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>CSV Format Requirements:</strong>
                <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                    <li><strong>Required fields:</strong> First Name, Last Name, Gender</li>
                    <li><strong>Valid Gender Values:</strong> Male, Female, Other</li>
                    <li><strong>Date Format:</strong> YYYY-MM-DD (e.g., 1990-05-15)</li>
                    <li><strong>Education Levels:</strong> None, Primary, Secondary, Tertiary, University</li>
                    <li><strong>PWD Values:</strong> Yes or No</li>
                </ul>
            </div>
            
            <form method="POST" action="includes/participants-process.php" enctype="multipart/form-data">
                <?php if ($filter_project_id): ?>
                    <input type="hidden" name="project_id" value="<?php echo $filter_project_id; ?>">
                <?php elseif ($filter_program_id): ?>
                    <input type="hidden" name="program_id" value="<?php echo $filter_program_id; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="csv_file" class="required">Select CSV File</label>
                    <input type="file" id="csv_file" name="csv_file" class="form-control" accept=".csv" required>
                    <small style="color: #7f8c8d;">Only CSV files are allowed</small>
                </div>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 15px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 14px;">CSV Template Columns:</h4>
                    <div style="font-family: monospace; font-size: 12px; color: #555; line-height: 1.8;">
                        1. First Name* (required)<br>
                        2. Last Name* (required)<br>
                        3. Gender* (Male/Female/Other - required)<br>
                        4. Date of Birth (YYYY-MM-DD)<br>
                        5. Phone (+256700000000)<br>
                        6. Email<br>
                        7. District<br>
                        8. Sub-county<br>
                        9. Village<br>
                        10. Education Level (None/Primary/Secondary/Tertiary/University)<br>
                        11. Occupation<br>
                        12. Is PWD (Yes/No)<br>
                        13. Disability Type (if PWD is Yes)
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('uploadCsvModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="upload_csv" class="btn btn-success">
                        <i class="fas fa-upload"></i> Upload CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Beneficiary Modal -->
<div id="addBeneficiaryModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Add New Participant</h3>
            <span class="close" onclick="closeModal('addBeneficiaryModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/participants-process.php" id="addBeneficiaryForm">
                <?php if ($filter_project_id): ?>
                    <input type="hidden" name="context_return_project" value="<?php echo $filter_project_id; ?>">
                <?php elseif ($filter_program_id): ?>
                    <input type="hidden" name="context_return_program" value="<?php echo $filter_program_id; ?>">
                <?php endif; ?>
                
                <!-- Link to Project or Program Section -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 15px 0; font-size: 16px; color: #2c3e50;">
                        <i class="fas fa-link"></i> Link to Project or Program (Optional)
                    </h4>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="link_project_id">
                                <i class="fas fa-project-diagram"></i> Select Project
                            </label>
                            <select id="link_project_id" name="link_project_id" class="form-control" onchange="handleProjectSelection()">
                                <option value="">-- None --</option>
                                <?php foreach ($projects as $proj): ?>
                                    <option value="<?php echo $proj['project_id']; ?>" 
                                            <?php echo ($filter_project_id == $proj['project_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($proj['project_code'] . ' - ' . $proj['project_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="link_program_id">
                                <i class="fas fa-sitemap"></i> Select Program
                            </label>
                            <select id="link_program_id" name="link_program_id" class="form-control" onchange="handleProgramSelection()">
                                <option value="">-- None --</option>
                                <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo $prog['id']; ?>"
                                            <?php echo ($filter_program_id == $prog['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prog['program_code'] . ' - ' . $prog['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="alert alert-info" style="margin-top: 10px; margin-bottom: 0;">
                        <i class="fas fa-info-circle"></i>
                        <small>You can select either a project OR a program, not both. Leave both empty if you don't want to link this participant yet.</small>
                    </div>
                </div>
                
                <!-- Personal Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-user"></i> Personal Information
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name" class="required">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name" class="required">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="gender" class="required">Gender</label>
                        <select id="gender" name="gender" class="form-control" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" class="form-control">
                    </div>
                </div>
                
                <!-- Contact Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-address-book"></i> Contact Information
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" placeholder="+256...">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control">
                    </div>
                </div>
                
                <!-- Location Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-map-marker-alt"></i> Location Information
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="district">District</label>
                        <select id="district" name="district" class="form-control">
                            <option value="">Select District</option>
                            <?php foreach ($uganda_districts as $dist): ?>
                                <option value="<?php echo $dist; ?>"><?php echo $dist; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="subcounty">Sub-county</label>
                        <input type="text" id="subcounty" name="subcounty" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="village">Village</label>
                        <input type="text" id="village" name="village" class="form-control">
                    </div>
                </div>
                
                <!-- Education & Occupation -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-graduation-cap"></i> Education & Occupation
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="education_level">Education Level</label>
                        <select id="education_level" name="education_level" class="form-control">
                            <option value="">Select Level</option>
                            <option value="None">None</option>
                            <option value="Primary">Primary</option>
                            <option value="Secondary">Secondary</option>
                            <option value="Tertiary">Tertiary</option>
                            <option value="University">University</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="occupation">Occupation</label>
                        <input type="text" id="occupation" name="occupation" class="form-control">
                    </div>
                </div>
                
                <!-- Disability Information -->
                <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                    <i class="fas fa-wheelchair"></i> Disability Information
                </h4>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="is_pwd" name="is_pwd" onchange="toggleDisabilityType()">
                        <span>Person with Disability (PWD)</span>
                    </label>
                </div>
                
                <div class="form-group" id="disability_type_group" style="display: none;">
                    <label for="disability_type">Disability Type</label>
                    <input type="text" id="disability_type" name="disability_type" class="form-control" placeholder="e.g., Visual, Physical, Hearing">
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addBeneficiaryModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_participant" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Participant
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Beneficiary Modal -->
<div id="editBeneficiaryModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Participant</h3>
            <span class="close" onclick="closeModal('editBeneficiaryModal')">&times;</span>
        </div>
        <div class="modal-body">
            <?php if ($edit_beneficiary): ?>
                <form method="POST" action="includes/participants-process.php">
                    <input type="hidden" name="beneficiary_id" value="<?php echo $edit_beneficiary['beneficiary_id']; ?>">
                    <?php if ($filter_project_id): ?>
                        <input type="hidden" name="context_return_project" value="<?php echo $filter_project_id; ?>">
                    <?php elseif ($filter_program_id): ?>
                        <input type="hidden" name="context_return_program" value="<?php echo $filter_program_id; ?>">
                    <?php endif; ?>
                    
                    <!-- Current Linkages -->
                    <div style="background: #ecf0f1; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 16px; color: #2c3e50;">
                            <i class="fas fa-link"></i> Current Linkages
                        </h4>
                        <div style="display: flex; gap: 20px;">
                            <div>
                                <strong>Projects:</strong> 
                                <span class="badge badge-info"><?php echo count($edit_linked_projects); ?></span>
                            </div>
                            <div>
                                <strong>Programs:</strong>
                                <span class="badge" style="background: #E67E22; color: white;"><?php echo count($edit_linked_programs); ?></span>
                            </div>
                        </div>
                        <small style="color: #7f8c8d; display: block; margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> To modify linkages, please use the participant details page after saving.
                        </small>
                    </div>
                    
                    <!-- Personal Information -->
                    <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                        <i class="fas fa-user"></i> Personal Information
                    </h4>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_first_name" class="required">First Name</label>
                            <input type="text" id="edit_first_name" name="first_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_beneficiary['first_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_last_name" class="required">Last Name</label>
                            <input type="text" id="edit_last_name" name="last_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_beneficiary['last_name']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_gender" class="required">Gender</label>
                            <select id="edit_gender" name="gender" class="form-control" required>
                                <option value="Male" <?php echo $edit_beneficiary['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $edit_beneficiary['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo $edit_beneficiary['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_date_of_birth">Date of Birth</label>
                            <input type="date" id="edit_date_of_birth" name="date_of_birth" class="form-control" 
                                   value="<?php echo $edit_beneficiary['date_of_birth']; ?>">
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
                                   value="<?php echo htmlspecialchars($edit_beneficiary['phone']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_email">Email</label>
                            <input type="email" id="edit_email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_beneficiary['email']); ?>">
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
                                    <option value="<?php echo $dist; ?>" <?php echo $edit_beneficiary['district'] == $dist ? 'selected' : ''; ?>>
                                        <?php echo $dist; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_subcounty">Sub-county</label>
                            <input type="text" id="edit_subcounty" name="subcounty" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_beneficiary['subcounty']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_village">Village</label>
                            <input type="text" id="edit_village" name="village" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_beneficiary['village']); ?>">
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
                                <option value="None" <?php echo $edit_beneficiary['education_level'] == 'None' ? 'selected' : ''; ?>>None</option>
                                <option value="Primary" <?php echo $edit_beneficiary['education_level'] == 'Primary' ? 'selected' : ''; ?>>Primary</option>
                                <option value="Secondary" <?php echo $edit_beneficiary['education_level'] == 'Secondary' ? 'selected' : ''; ?>>Secondary</option>
                                <option value="Tertiary" <?php echo $edit_beneficiary['education_level'] == 'Tertiary' ? 'selected' : ''; ?>>Tertiary</option>
                                <option value="University" <?php echo $edit_beneficiary['education_level'] == 'University' ? 'selected' : ''; ?>>University</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_occupation">Occupation</label>
                            <input type="text" id="edit_occupation" name="occupation" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_beneficiary['occupation']); ?>">
                        </div>
                    </div>
                    
                    <!-- Disability Information -->
                    <h4 style="margin: 20px 0 15px 0; font-size: 16px; color: #2c3e50; border-bottom: 2px solid #3498DB; padding-bottom: 8px;">
                        <i class="fas fa-wheelchair"></i> Disability Information
                    </h4>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="edit_is_pwd" name="is_pwd" 
                                   <?php echo $edit_beneficiary['is_pwd'] ? 'checked' : ''; ?> 
                                   onchange="toggleEditDisabilityType()">
                            <span>Person with Disability (PWD)</span>
                        </label>
                    </div>
                    
                    <div class="form-group" id="edit_disability_type_group" style="display: <?php echo $edit_beneficiary['is_pwd'] ? 'block' : 'none'; ?>;">
                        <label for="edit_disability_type">Disability Type</label>
                        <input type="text" id="edit_disability_type" name="disability_type" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_beneficiary['pwd_type']); ?>">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" onclick="closeModal('editBeneficiaryModal')" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="edit_beneficiary" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Participant
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="color: #E74C3C;"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
            <span class="close" onclick="closeModal('deleteModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p style="font-size: 16px; margin-bottom: 20px;">
                Are you sure you want to delete <strong id="deleteBeneficiaryName"></strong>?
            </p>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                This action cannot be undone. The participant must not be linked to any projects or programs.
            </div>
            
            <form method="POST" action="includes/participants-process.php">
                <input type="hidden" name="beneficiary_id" id="deleteBeneficiaryId">
                <?php if ($filter_project_id): ?>
                    <input type="hidden" name="context_return_project" value="<?php echo $filter_project_id; ?>">
                <?php elseif ($filter_program_id): ?>
                    <input type="hidden" name="context_return_program" value="<?php echo $filter_program_id; ?>">
                <?php endif; ?>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="delete_beneficiary" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle disability type input based on PWD checkbox
function toggleDisabilityType() {
    const isPwd = document.getElementById('is_pwd').checked;
    document.getElementById('disability_type_group').style.display = isPwd ? 'block' : 'none';
}

function toggleEditDisabilityType() {
    const isPwd = document.getElementById('edit_is_pwd').checked;
    document.getElementById('edit_disability_type_group').style.display = isPwd ? 'block' : 'none';
}

// Handle project/program selection mutual exclusivity
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

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    handleProjectSelection();
    handleProgramSelection();
});

function openDeleteModal(beneficiaryId, beneficiaryName) {
    document.getElementById('deleteBeneficiaryId').value = beneficiaryId;
    document.getElementById('deleteBeneficiaryName').textContent = beneficiaryName;
    openModal('deleteModal');
}

// Auto-open edit modal if editing
<?php if ($edit_beneficiary): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('editBeneficiaryModal');
    });
<?php endif; ?>
</script>

<style>
.btn-group {
    display: flex;
    gap: 0;
}
.btn-group .btn {
    border-radius: 0;
}
.btn-group .btn:first-child {
    border-top-left-radius: 4px;
    border-bottom-left-radius: 4px;
}
.btn-group .btn:last-child {
    border-top-right-radius: 4px;
    border-bottom-right-radius: 4px;
}

/* Section headers styling */
h4 i {
    margin-right: 8px;
    color: #3498DB;
}

/* Form sections */
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
</style>

<?php include 'includes/footer.php'; ?>