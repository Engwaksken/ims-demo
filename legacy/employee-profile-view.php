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
/* Employee profile view (pv-) */
.pv-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
}

.pv-grid + .pv-item,
.pv-grid + .pv-full { margin-top: 16px; }

.pv-item {
    padding: 14px 16px;
    background: var(--surface-hover);
    border: 1px solid var(--border);
    border-left: 3px solid var(--brand-500);
    border-radius: var(--radius-sm);
}

.pv-label {
    font-size: 11px;
    color: var(--ink-300);
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 700;
    margin-bottom: 5px;
}

.pv-value {
    font-size: 15px;
    color: var(--ink-700);
    font-weight: 500;
    overflow-wrap: anywhere;
}

.pv-ok { color: var(--green-fg); }
.pv-bad { color: var(--red-fg); }

.pv-docs {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 14px;
}

.pv-doc {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px;
    background: var(--surface-hover);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: inherit;
    text-decoration: none;
    transition: border-color .2s, box-shadow .2s, transform .2s;
}

.pv-doc:hover {
    border-color: var(--brand-200);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.pv-doc-icon {
    width: 46px;
    height: 46px;
    flex-shrink: 0;
    background: var(--brand-50);
    color: var(--brand-600);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.pv-doc-info { flex: 1; min-width: 0; }
.pv-doc-name { font-weight: 600; color: var(--ink-700); margin-bottom: 3px; }
.pv-doc-size { font-size: 12px; color: var(--ink-300); }
.pv-doc-link { color: var(--brand-600); }

.pv-signature {
    margin-top: 16px;
    background: var(--surface-hover);
    padding: 20px;
    border-radius: var(--radius-md);
    text-align: center;
    border: 2px dashed var(--ink-100);
}

.pv-signature .pv-label { margin-bottom: 14px; }

.pv-signature-img {
    max-width: 300px;
    max-height: 100px;
    border: 1px solid var(--border);
    padding: 10px;
    background: var(--surface-card);
    border-radius: var(--radius-sm);
}

.pv-signature-date { margin-top: 14px; }

.pv-icon-ok { color: var(--green-fg); }
.pv-icon-bad { color: var(--red-fg); }

.pv-meta {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
    font-size: 13px;
    color: var(--ink-300);
}

@media print {
    .no-print {
        display: none !important;
    }

    .page-stack .card {
        box-shadow: none;
        page-break-inside: avoid;
    }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-id-badge" aria-hidden="true"></i> <?php echo htmlspecialchars($employee['full_name']); ?></h1>
        <p>
            <?php echo htmlspecialchars($employee['job_title']); ?> &middot;
            <?php echo htmlspecialchars($employee['department_name']); ?> &middot;
            <?php echo htmlspecialchars($employee['contract_type']); ?>
            <span class="badge badge-<?php
                echo $employee['status'] == 'Approved' ? 'success' :
                    ($employee['status'] == 'Submitted' ? 'info' : 'warning');
            ?>">
                <?php echo $employee['status']; ?>
            </span>
        </p>
    </div>
    <div class="hero-actions no-print">
        <?php if ($employee['status'] == 'Draft' || $employee['status'] == 'Rejected'): ?>
            <a href="my-profile" class="btn">
                <i class="fas fa-edit" aria-hidden="true"></i> Edit Profile
            </a>
        <?php endif; ?>
        <button type="button" onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
        <button type="button" onclick="shareProfile()" class="btn">
            <i class="fas fa-share" aria-hidden="true"></i> Share
        </button>
        <button type="button" onclick="downloadPDF()" class="btn btn-primary">
            <i class="fas fa-download" aria-hidden="true"></i> Download PDF
        </button>
    </div>
</div>

<!-- Quick Info -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Start Date</span>
            <span class="stat-value"><?php echo date('d M Y', strtotime($employee['start_date'])); ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-briefcase" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Tenure</span>
            <span class="stat-value"><?php echo $tenure_text; ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-envelope" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Company Email</span>
            <span class="stat-sub"><?php echo htmlspecialchars($employee['company_email']); ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-phone" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Phone</span>
            <span class="stat-sub"><?php echo htmlspecialchars($employee['phone']); ?></span>
        </div>
    </div>
</div>

<!-- Personal Information -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-user" aria-hidden="true"></i> Personal Information</h3></div>
    <div class="card-body">
        <div class="pv-grid">
            <div class="pv-item">
                <div class="pv-label">Contract Type</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['contract_type']); ?></div>
            </div>
            <div class="pv-item">
                <div class="pv-label">Gender</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['gender']); ?></div>
            </div>
            <?php if ($employee['date_of_birth']): ?>
            <div class="pv-item">
                <div class="pv-label">Date of Birth</div>
                <div class="pv-value"><?php echo date('d M Y', strtotime($employee['date_of_birth'])); ?> (<?php echo $age; ?>)</div>
            </div>
            <?php endif; ?>
            <?php if ($employee['nationality']): ?>
            <div class="pv-item">
                <div class="pv-label">Nationality</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['nationality']); ?></div>
            </div>
            <?php endif; ?>
            <div class="pv-item">
                <div class="pv-label">Department</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['department_name']); ?></div>
            </div>
            <?php if ($employee['location']): ?>
            <div class="pv-item">
                <div class="pv-label">Location</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['location']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($employee['contract_end_date']): ?>
        <div class="pv-item">
            <div class="pv-label">Contract Period</div>
            <div class="pv-value">
                <?php echo date('d M Y', strtotime($employee['start_date'])); ?> to
                <?php echo date('d M Y', strtotime($employee['contract_end_date'])); ?>
                <?php
                $end = new DateTime($employee['contract_end_date']);
                $remaining = $today->diff($end);
                if ($end > $today) {
                    echo ' <span class="pv-ok">(' . $remaining->days . ' days remaining)</span>';
                } else {
                    echo ' <span class="pv-bad">(Contract ended)</span>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Supervisor/Manager -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-user-tie" aria-hidden="true"></i> Reporting To</h3></div>
    <div class="card-body">
        <div class="pv-grid">
            <div class="pv-item">
                <div class="pv-label">Supervisor/Manager</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['supervisor_name']); ?></div>
            </div>
            <?php if ($employee['supervisor_email']): ?>
            <div class="pv-item">
                <div class="pv-label">Supervisor Email</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['supervisor_email']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['supervisor_phone']): ?>
            <div class="pv-item">
                <div class="pv-label">Supervisor Phone</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['supervisor_phone']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Contact Information -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-address-book" aria-hidden="true"></i> Contact Information</h3></div>
    <div class="card-body">
        <div class="pv-grid">
            <div class="pv-item">
                <div class="pv-label">Company Email</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['company_email']); ?></div>
            </div>
            <?php if ($employee['personal_email']): ?>
            <div class="pv-item">
                <div class="pv-label">Personal Email</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['personal_email']); ?></div>
            </div>
            <?php endif; ?>
            <div class="pv-item">
                <div class="pv-label">Phone Number</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['phone']); ?></div>
            </div>
            <?php if ($employee['location']): ?>
            <div class="pv-item">
                <div class="pv-label">Location</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['location']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($employee['residence_address']): ?>
        <div class="pv-item">
            <div class="pv-label">Residence Address</div>
            <div class="pv-value"><?php echo nl2br(htmlspecialchars($employee['residence_address'])); ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Emergency Contact -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-phone-alt" aria-hidden="true"></i> Emergency Contact</h3></div>
    <div class="card-body">
        <div class="pv-grid">
            <?php if ($employee['next_of_kin_name']): ?>
            <div class="pv-item">
                <div class="pv-label">Next of Kin Name</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['next_of_kin_name']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['next_of_kin_relationship']): ?>
            <div class="pv-item">
                <div class="pv-label">Relationship</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['next_of_kin_relationship']); ?></div>
            </div>
            <?php endif; ?>
            <div class="pv-item">
                <div class="pv-label">Dependants</div>
                <div class="pv-value">
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
</div>

<!-- Official Details -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-id-card" aria-hidden="true"></i> Official Details</h3></div>
    <div class="card-body">
        <div class="pv-grid">
            <?php if ($employee['nin']): ?>
            <div class="pv-item">
                <div class="pv-label">National ID Number (NIN)</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['nin']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['ura_tin_number']): ?>
            <div class="pv-item">
                <div class="pv-label">URA TIN Number</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['ura_tin_number']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($employee['nssf_number']): ?>
            <div class="pv-item">
                <div class="pv-label">NSSF Number</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['nssf_number']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($employee['bank_details']): ?>
        <div class="pv-item">
            <div class="pv-label">Bank Details</div>
            <div class="pv-value"><?php echo nl2br(htmlspecialchars($employee['bank_details'])); ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Documents -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-folder-open" aria-hidden="true"></i> Documents</h3></div>
    <div class="card-body">
        <div class="pv-docs">
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
                <a href="<?php echo htmlspecialchars(ims_upload_url($doc['path'])); ?>" target="_blank" class="pv-doc">
                    <div class="pv-doc-icon">
                        <i class="fas fa-<?php echo $doc['icon']; ?>" aria-hidden="true"></i>
                    </div>
                    <div class="pv-doc-info">
                        <div class="pv-doc-name"><?php echo $doc['name']; ?></div>
                        <div class="pv-doc-size"><?php echo $file_size_mb; ?> MB</div>
                    </div>
                    <i class="fas fa-external-link-alt pv-doc-link" aria-hidden="true"></i>
                </a>
            <?php
                endif;
            endforeach;
            ?>
        </div>
    </div>
</div>

<!-- Certification -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-signature" aria-hidden="true"></i> Certification</h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            The employee has certified that all information provided is true and accurate to the best of their knowledge.
        </div>

        <?php if ($employee['signature_path']): ?>
        <div class="pv-signature">
            <div class="pv-label">Employee Signature</div>
            <img src="<?php echo htmlspecialchars(ims_upload_url($employee['signature_path'])); ?>" alt="Signature" class="pv-signature-img">
            <div class="pv-signature-date">
                <small><strong>Date:</strong> <?php echo date('d M Y', strtotime($employee['form_completion_date'])); ?></small>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Approval Information -->
<?php if ($employee['status'] == 'Approved'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-check-circle pv-icon-ok" aria-hidden="true"></i> Approval Information</h3></div>
    <div class="card-body">
        <div class="pv-grid">
            <div class="pv-item">
                <div class="pv-label">Approved By</div>
                <div class="pv-value"><?php echo htmlspecialchars($employee['approved_by_name']); ?></div>
            </div>
            <div class="pv-item">
                <div class="pv-label">Approval Date</div>
                <div class="pv-value"><?php echo date('d M Y, H:i', strtotime($employee['approved_at'])); ?></div>
            </div>
            <div class="pv-item">
                <div class="pv-label">Profile Status</div>
                <div class="pv-value"><span class="badge badge-success">Approved</span></div>
            </div>
        </div>
    </div>
</div>
<?php elseif ($employee['status'] == 'Rejected'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-times-circle pv-icon-bad" aria-hidden="true"></i> Rejection Information</h3></div>
    <div class="card-body">
        <div class="alert alert-danger">
            <strong>Rejection Reason:</strong><br>
            <?php echo nl2br(htmlspecialchars($employee['rejection_reason'] ?? 'Not specified')); ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Metadata -->
<div class="card">
    <div class="card-body pv-meta">
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
</div><!-- /.page-stack -->

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