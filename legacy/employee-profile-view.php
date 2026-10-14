<?php
// Frontend - My Employee Profile View (Read-Only)
$page_title = 'My Employee Profile';
include 'includes/header.php';

$user_id = (int)$_SESSION['user_id'];

// Optional ?id=<employee_id> (from the Employee Directory). Only HR /
// management roles or the employee's supervisor may view someone else.
$view_employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$profile_where = 'ed.user_id = ' . $user_id;

if ($view_employee_id > 0) {
    $profile_where = 'ed.employee_id = ' . $view_employee_id;
}

// Fetch employee profile
$query = "SELECT ed.*, d.department_name, d.department_code,
    u_super.full_name as supervisor_name, u_super.email as supervisor_email, u_super.phone as supervisor_phone,
    u_approve.full_name as approved_by_name
    FROM employee_directory ed
    LEFT JOIN departments d ON ed.department_id = d.department_id
    LEFT JOIN users u_super ON ed.supervisor_id = u_super.user_id
    LEFT JOIN users u_approve ON ed.approved_by = u_approve.user_id
    WHERE {$profile_where}";
$result = $conn->query($query);
$employee = $result ? $result->fetch_assoc() : null;

if (!$employee) {
    header("Location: my-profile");
    exit();
}

if ((int)$employee['user_id'] !== $user_id) {
    $can_view_others = ims_allowed('employees.view_all', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR', 'Executive Director'], "View any employee's profile (not just their own)")
        || (int)($employee['supervisor_id'] ?? 0) === $user_id;

    if (!$can_view_others) {
        send_notification($user_id, 'You do not have permission to view that profile.', 'danger');
        header("Location: dashboard");
        exit();
    }
}

// Calculate tenure
$start = new DateTime($employee['start_date']);
$today = new DateTime();
$tenure = $start->diff($today);
$tenure_text = '';
if ($tenure->y > 0) {
    $tenure_text .= $tenure->y . ' year' . ($tenure->y > 1 ? 's' : '') . ' ';
}
if ($tenure->m > 0) {
    $tenure_text .= $tenure->m . ' month' . ($tenure->m > 1 ? 's' : '');
}
if (empty($tenure_text)) {
    $tenure_text = $tenure->d . ' day' . ($tenure->d > 1 ? 's' : '');
}

// Calculate age if DOB exists
$age = null;
if ($employee['date_of_birth']) {
    $dob = new DateTime($employee['date_of_birth']);
    $age_diff = $dob->diff($today);
    $age = $age_diff->y . ' years';
}
?>

<style>
.profile-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
    margin-bottom: 20px;
}

.profile-header {
    background: linear-gradient(135deg, #2c3e50, #FF5722);
    color: white;
    padding: 40px 30px;
    text-align: center;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: white;
    color: var(--primary-color);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
    font-weight: bold;
    margin-bottom: 15px;
    border: 5px solid rgba(255,255,255,0.3);
}

.profile-section {
    padding: 25px 30px;
    border-bottom: 1px solid #ecf0f1;
}

.profile-section:last-child {
    border-bottom: none;
}

.profile-section h4 {
    color: var(--primary-color);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #ecf0f1;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 5px;
    border-left: 3px solid var(--primary-color);
}

.info-label {
    font-size: 12px;
    color: #7f8c8d;
    text-transform: uppercase;
    font-weight: 600;
    margin-bottom: 5px;
}

.info-value {
    font-size: 15px;
    color: #2c3e50;
    font-weight: 500;
}

.document-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 15px;
}

.document-card {
    display: flex;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    transition: all 0.3s ease;
}

.document-card:hover {
    background: #e8f4f8;
    border-color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.document-icon {
    width: 50px;
    height: 50px;
    background: var(--primary-color);
    color: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-right: 15px;
}

.document-info {
    flex: 1;
}

.document-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 3px;
}

.document-size {
    font-size: 12px;
    color: #7f8c8d;
}

.signature-box {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    border: 2px dashed #bdc3c7;
}

.signature-img {
    max-width: 300px;
    max-height: 100px;
    border: 1px solid #ddd;
    padding: 10px;
    background: white;
    border-radius: 5px;
}

.status-badge-xl {
    font-size: 16px;
    padding: 10px 20px;
    border-radius: 25px;
    font-weight: 600;
}

.quick-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .profile-card {
        box-shadow: none;
        page-break-inside: avoid;
    }
}
</style>

<!-- Action Bar -->
<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;" class="no-print">
    <div>
        <?php if ($employee['status'] == 'Draft' || $employee['status'] == 'Rejected'): ?>
            <a href="employee-profile.php" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit Profile
            </a>
        <?php endif; ?>
    </div>
    <div class="quick-actions">
        <button onclick="window.print()" class="btn btn-info">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="downloadPDF()" class="btn btn-primary">
            <i class="fas fa-download"></i> Download PDF
        </button>
        <button onclick="shareProfile()" class="btn btn-secondary">
            <i class="fas fa-share"></i> Share
        </button>
    </div>
</div>

<!-- Profile Card -->
<div class="profile-card">
    <!-- Profile Header -->
    <div class="profile-header">
        <!--<div class="profile-avatar">
            <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
        </div> -->
        <h2 style="margin: 0 0 5px 0;"><?php echo htmlspecialchars($employee['full_name']); ?></h2>
        <p style="margin: 0; font-size: 18px; opacity: 0.9;"><?php echo htmlspecialchars($employee['job_title']); ?></p>
        <p style="margin: 5px 0 0 0; opacity: 0.8;">
            <?php echo htmlspecialchars($employee['department_name']); ?> -  
            <?php echo htmlspecialchars($employee['contract_type']); ?>
        </p>
        <div style="margin-top: 15px;">
            <span class="badge status-badge-xl badge-<?php 
                echo $employee['status'] == 'Approved' ? 'success' : 
                    ($employee['status'] == 'Submitted' ? 'info' : 'warning'); 
            ?>">
                <?php echo $employee['status']; ?>
            </span>
        </div>
    </div>
    
    <!-- Quick Info -->
    <div class="profile-section" style="background: #f8f9fa;">
        <div class="info-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div style="text-align: center;">
                <i class="fas fa-calendar-check" style="font-size: 24px; color: var(--primary-color); margin-bottom: 5px;"></i>
                <div class="info-label">Start Date</div>
                <div class="info-value"><?php echo date('d M Y', strtotime($employee['start_date'])); ?></div>
            </div>
            <div style="text-align: center;">
                <i class="fas fa-briefcase" style="font-size: 24px; color: var(--primary-color); margin-bottom: 5px;"></i>
                <div class="info-label">Tenure</div>
                <div class="info-value"><?php echo $tenure_text; ?></div>
            </div>
            <div style="text-align: center;">
                <i class="fas fa-envelope" style="font-size: 24px; color: var(--primary-color); margin-bottom: 5px;"></i>
                <div class="info-label">Company Email</div>
                <div class="info-value" style="font-size: 13px;"><?php echo htmlspecialchars($employee['company_email']); ?></div>
            </div>
            <div style="text-align: center;">
                <i class="fas fa-phone" style="font-size: 24px; color: var(--primary-color); margin-bottom: 5px;"></i>
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['phone']); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Personal Information -->
    <div class="profile-section">
        <h4><i class="fas fa-user"></i> Personal Information</h4>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Contract Type</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['contract_type']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Gender</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['gender']); ?></div>
            </div>
            <?php if ($employee['date_of_birth']): ?>
            <div class="info-item">
                <div class="info-label">Date of Birth</div>
                <div class="info-value"><?php echo date('d M Y', strtotime($employee['date_of_birth'])); ?> (<?php echo $age; ?>)</div>
            </div>
            <?php endif; ?>
            <?php if ($employee['nationality']): ?>
            <div class="info-item">
                <div class="info-label">Nationality</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['nationality']); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-item">
                <div class="info-label">Department</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['department_name']); ?></div>
            </div>
            <?php if ($employee['location']): ?>
            <div class="info-item">
                <div class="info-label">Location</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['location']); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($employee['contract_end_date']): ?>
        <div style="margin-top: 20px;">
            <div class="info-item">
                <div class="info-label">Contract Period</div>
                <div class="info-value">
                    <?php echo date('d M Y', strtotime($employee['start_date'])); ?> to 
                    <?php echo date('d M Y', strtotime($employee['contract_end_date'])); ?>
                    <?php
                    $end = new DateTime($employee['contract_end_date']);
                    $remaining = $today->diff($end);
                    if ($end > $today) {
                        echo ' <span style="color: #27AE60;">(' . $remaining->days . ' days remaining)</span>';
                    } else {
                        echo ' <span style="color: #E74C3C;">(Contract ended)</span>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Supervisor/Manager -->
    <div class="profile-section">
        <h4><i class="fas fa-user-tie"></i> Reporting To</h4>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Supervisor/Manager</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['supervisor_name']); ?></div>
            </div>
            <?php if ($employee['supervisor_email']): ?>
            <div class="info-item">
                <div class="info-label">Supervisor Email</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['supervisor_email']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['supervisor_phone']): ?>
            <div class="info-item">
                <div class="info-label">Supervisor Phone</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['supervisor_phone']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Contact Information -->
    <div class="profile-section">
        <h4><i class="fas fa-address-book"></i> Contact Information</h4>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Company Email</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['company_email']); ?></div>
            </div>
            <?php if ($employee['personal_email']): ?>
            <div class="info-item">
                <div class="info-label">Personal Email</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['personal_email']); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-item">
                <div class="info-label">Phone Number</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['phone']); ?></div>
            </div>
            <?php if ($employee['location']): ?>
            <div class="info-item">
                <div class="info-label">Location</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['location']); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($employee['residence_address']): ?>
        <div style="margin-top: 20px;">
            <div class="info-item">
                <div class="info-label">Residence Address</div>
                <div class="info-value"><?php echo nl2br(htmlspecialchars($employee['residence_address'])); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Emergency Contact -->
    <div class="profile-section">
        <h4><i class="fas fa-phone-alt"></i> Emergency Contact</h4>
        <div class="info-grid">
            <?php if ($employee['next_of_kin_name']): ?>
            <div class="info-item">
                <div class="info-label">Next of Kin Name</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['next_of_kin_name']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['next_of_kin_relationship']): ?>
            <div class="info-item">
                <div class="info-label">Relationship</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['next_of_kin_relationship']); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-item">
                <div class="info-label">Dependants</div>
                <div class="info-value">
                    <?php 
                    if ($employee['has_dependants'] == 'Yes') {
                        echo $employee['number_of_dependants'] . ' dependant' . ($employee['number_of_dependants'] > 1 ? 's' : '');
                    } else {
                        echo 'No dependants';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Official Details -->
    <div class="profile-section">
        <h4><i class="fas fa-id-card"></i> Official Details</h4>
        <div class="info-grid">
            <?php if ($employee['nin']): ?>
            <div class="info-item">
                <div class="info-label">National ID Number (NIN)</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['nin']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['ura_tin_number']): ?>
            <div class="info-item">
                <div class="info-label">URA TIN Number</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['ura_tin_number']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['nssf_number']): ?>
            <div class="info-item">
                <div class="info-label">NSSF Number</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['nssf_number']); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($employee['bank_details']): ?>
        <div style="margin-top: 20px;">
            <div class="info-item">
                <div class="info-label">Bank Details</div>
                <div class="info-value"><?php echo nl2br(htmlspecialchars($employee['bank_details'])); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Documents -->
    <div class="profile-section">
        <h4><i class="fas fa-folder-open"></i> Documents</h4>
        <div class="document-list">
            <?php
            $documents = [
                ['path' => $employee['academic_documents_path'], 'name' => 'Academic Documents', 'icon' => 'graduation-cap'],
                ['path' => $employee['national_id_path'], 'name' => 'National ID', 'icon' => 'id-card'],
                ['path' => $employee['cv_path'], 'name' => 'CV/Resume', 'icon' => 'file-alt'],
                ['path' => $employee['cover_letter_path'], 'name' => 'Cover Letter', 'icon' => 'envelope'],
                ['path' => $employee['good_conduct_cert_path'], 'name' => 'Good Conduct Certificate', 'icon' => 'certificate'],
                ['path' => $employee['signed_policies_path'], 'name' => 'Signed Policies', 'icon' => 'file-signature'],
                ['path' => $employee['residence_map_path'], 'name' => 'Residence Map', 'icon' => 'map-marked-alt'],
                ['path' => $employee['signed_contract_path'], 'name' => 'Signed Contract', 'icon' => 'file-contract']
            ];
            
            foreach ($documents as $doc):
                if ($doc['path']):
                    $file_size = file_exists($doc['path']) ? filesize($doc['path']) : 0;
                    $file_size_mb = number_format($file_size / 1048576, 2);
            ?>
                <a href="<?php echo htmlspecialchars(ims_upload_url($doc['path'])); ?>" target="_blank" class="document-card" style="text-decoration: none; color: inherit;">
                    <div class="document-icon">
                        <i class="fas fa-<?php echo $doc['icon']; ?>"></i>
                    </div>
                    <div class="document-info">
                        <div class="document-name"><?php echo $doc['name']; ?></div>
                        <div class="document-size"><?php echo $file_size_mb; ?> MB</div>
                    </div>
                    <i class="fas fa-external-link-alt" style="color: var(--primary-color);"></i>
                </a>
            <?php 
                endif;
            endforeach; 
            ?>
        </div>
    </div>
    
    <!-- Certification -->
    <div class="profile-section">
        <h4><i class="fas fa-signature"></i> Certification</h4>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            The employee has certified that all information provided is true and accurate to the best of their knowledge.
        </div>
        
        <?php if ($employee['signature_path']): ?>
        <div class="signature-box">
            <div class="info-label" style="margin-bottom: 15px;">Employee Signature</div>
            <img src="<?php echo htmlspecialchars(ims_upload_url($employee['signature_path'])); ?>" alt="Signature" class="signature-img">
            <div style="margin-top: 15px;">
                <small><strong>Date:</strong> <?php echo date('d M Y', strtotime($employee['form_completion_date'])); ?></small>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Approval Information -->
    <?php if ($employee['status'] == 'Approved'): ?>
    <div class="profile-section" style="background: #e8f5e9;">
        <h4 style="color: #27AE60;"><i class="fas fa-check-circle"></i> Approval Information</h4>
        <div class="info-grid">
            <div class="info-item" style="background: white;">
                <div class="info-label">Approved By</div>
                <div class="info-value"><?php echo htmlspecialchars($employee['approved_by_name']); ?></div>
            </div>
            <div class="info-item" style="background: white;">
                <div class="info-label">Approval Date</div>
                <div class="info-value"><?php echo date('d M Y, H:i', strtotime($employee['approved_at'])); ?></div>
            </div>
            <div class="info-item" style="background: white;">
                <div class="info-label">Profile Status</div>
                <div class="info-value"><span class="badge badge-success">Approved</span></div>
            </div>
        </div>
    </div>
    <?php elseif ($employee['status'] == 'Rejected'): ?>
    <div class="profile-section" style="background: #ffebee;">
        <h4 style="color: #E74C3C;"><i class="fas fa-times-circle"></i> Rejection Information</h4>
        <div class="alert alert-danger">
            <strong>Rejection Reason:</strong><br>
            <?php echo nl2br(htmlspecialchars($employee['rejection_reason'] ?? 'Not specified')); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Metadata -->
    <div class="profile-section" style="background: #f8f9fa; font-size: 13px; color: #7f8c8d;">
        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
            <div>
                <strong>Profile Created:</strong> <?php echo date('d M Y, H:i', strtotime($employee['created_at'])); ?>
            </div>
            <div>
                <strong>Last Updated:</strong> <?php echo date('d M Y, H:i', strtotime($employee['updated_at'])); ?>
            </div>
            <?php if ($employee['submitted_at']): ?>
            <div>
                <strong>Submitted:</strong> <?php echo date('d M Y, H:i', strtotime($employee['submitted_at'])); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function downloadPDF() {
    // Show loading message
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
    btn.disabled = true;
    
    // Redirect to PDF generation endpoint
    window.location.href = 'generate-employee-pdf?employee_id=<?php echo $employee['employee_id']; ?>';
    
    // Reset button after 3 seconds
    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 3000);
}

function shareProfile() {
    if (navigator.share) {
        navigator.share({
            title: 'My Employee Profile',
            text: 'View my employee profile',
            url: window.location.href
        }).catch(() => { /* user cancelled the share sheet */ });
    } else {
        // Fallback: Copy link to clipboard
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('Profile link copied to clipboard!');
        });
    }
}
</script>

<?php include 'includes/footer.php'; ?>