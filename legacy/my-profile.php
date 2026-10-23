<?php
$page_title = 'My Employee Profile';
include 'includes/header.php';

// Any logged-in user can access
$user_id = (int)$_SESSION['user_id'];

// Fetch user's employee profile
$query = "SELECT ed.*, d.department_name,
    u_super.full_name   AS supervisor_name,
    u_approve.full_name AS approved_by_name
    FROM employee_directory ed
    LEFT JOIN departments d       ON ed.department_id = d.department_id
    LEFT JOIN users u_super       ON ed.supervisor_id  = u_super.user_id
    LEFT JOIN users u_approve     ON ed.approved_by    = u_approve.user_id
    WHERE ed.user_id = $user_id";
$result   = $conn->query($query);
$employee = $result->fetch_assoc();

// Fetch supervisors
$supervisors = [];
$result = $conn->query("SELECT user_id, full_name, role FROM users WHERE user_id != $user_id ORDER BY full_name");
while ($row = $result->fetch_assoc()) $supervisors[] = $row;

// Fetch departments
$departments = [];
$result = $conn->query("SELECT * FROM departments ORDER BY department_name");
while ($row = $result->fetch_assoc()) $departments[] = $row;

$is_new   = !$employee;
$can_edit = true; // Always allow editing regardless of status
?>

<style>
.hint-text {
    font-size: 12px;
    color: var(--ink-300);
    font-style: italic;
    margin-top: 5px;
}
.document-preview {
    display: flex;
    align-items: center;
    padding: 10px 14px;
    background: var(--surface-hover);
    border-radius: var(--radius-sm);
    margin-top: 8px;
    border: 1px solid var(--border);
}
.signature-pad {
    border: 2px dashed var(--brand-500);
    border-radius: var(--radius-sm);
    cursor: crosshair;
    background: var(--ink-0);
    display: block;
    max-width: 100%;
}
.status-info-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-id-badge" aria-hidden="true"></i> My Employee Profile</h1>
        <p>Keep your personal, contact and official details up to date for HR.</p>
    </div>
</div>

<?php
/* -- Status banner --------------------------------- */
if ($employee):
    $st     = $employee['status'];
    $cls    = strtolower($st);
    $icons  = ['Approved'=>'fa-check-circle','Submitted'=>'fa-clock','Rejected'=>'fa-times-circle','Draft'=>'fa-edit'];
    $icon   = $icons[$st] ?? 'fa-info-circle';
    $alertCls = ['Approved'=>'alert-success','Submitted'=>'alert-info','Rejected'=>'alert-danger','Draft'=>'alert-warning'][$st] ?? 'alert-info';
?>
<div class="alert <?= $alertCls ?> status-info-bar <?= $cls ?>">
    <div>
        <i class="fas <?= $icon ?>"></i>
        <strong>Profile Status: <?= htmlspecialchars($st) ?></strong>
        <?php if ($st === 'Approved'): ?>
            &mdash; Approved by <strong><?= htmlspecialchars($employee['approved_by_name'] ?? '—') ?></strong>
            on <?= date('d M Y', strtotime($employee['approved_at'])) ?>
        <?php elseif ($st === 'Submitted'): ?>
            &mdash; Pending review by HR.
        <?php elseif ($st === 'Rejected'): ?>
            &mdash; Reason: <?= htmlspecialchars($employee['rejection_reason'] ?? 'Not specified') ?>
        <?php endif; ?>
    </div>
    <?php if ($st === 'Approved'): ?>
    <a href="employee-profile-view" class="btn btn-sm btn-success">
        <i class="fas fa-eye"></i> View Profile
    </a>
    <?php endif; ?>
</div>

<?php if ($st === 'Approved'): ?>
<div class="alert alert-warning">
    <i class="fas fa-pen"></i>
    Your profile is <strong>Approved</strong> but you can still update your information below.
    Re-submitting will set the status back to <strong>Submitted</strong> for HR to re-approve.
</div>
<?php endif; ?>

<?php endif; ?>


<form method="POST" action="my-employee-profile-process" enctype="multipart/form-data" id="employeeForm" class="page-stack">
    <?php if ($employee): ?>
        <input type="hidden" name="employee_id" value="<?= $employee['employee_id'] ?>">
    <?php endif; ?>

    <!-- -- Personal Information  -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-user" aria-hidden="true"></i> Personal Information</h3></div>
        <div class="card-body">

        <div class="form-group">
            <label for="full_name" class="required">Full Name</label>
            <input type="text" id="full_name" name="full_name" class="form-control" required
                   value="<?= htmlspecialchars($employee['full_name'] ?? '') ?>">
            <div class="hint-text">Please write your name exactly as it appears on your legal documents.</div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contract_type" class="required">Contract Type</label>
                <select id="contract_type" name="contract_type" class="form-control" required>
                    <option value="">Select Contract Type</option>
                    <?php foreach (['Consultant','Staff','Temp','Casual'] as $ct): ?>
                    <option value="<?= $ct ?>" <?= ($employee['contract_type'] ?? '') === $ct ? 'selected' : '' ?>>
                        <?= $ct ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="supervisor_id" class="required">Manager / Supervisor</label>
                <select id="supervisor_id" name="supervisor_id" class="form-control" required>
                    <option value="">Select Supervisor</option>
                    <?php foreach ($supervisors as $sup): ?>
                    <option value="<?= $sup['user_id'] ?>"
                            <?= ($employee['supervisor_id'] ?? '') == $sup['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sup['full_name'] . ' - ' . $sup['role']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="start_date" class="required">Start Date</label>
                <input type="date" id="start_date" name="start_date" class="form-control" required
                       value="<?= $employee['start_date'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label for="contract_end_date">Contract End Date</label>
                <input type="date" id="contract_end_date" name="contract_end_date" class="form-control"
                       value="<?= $employee['contract_end_date'] ?? '' ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="job_title" class="required">Job Title</label>
                <input type="text" id="job_title" name="job_title" class="form-control" required
                       value="<?= htmlspecialchars($employee['job_title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="gender" class="required">Gender</label>
                <select id="gender" name="gender" class="form-control" required>
                    <option value="">Select Gender</option>
                    <option value="Male"   <?= ($employee['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= ($employee['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="department_id" class="required">Department</label>
                <select id="department_id" name="department_id" class="form-control" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['department_id'] ?>"
                            <?= ($employee['department_id'] ?? '') == $dept['department_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['department_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="date_of_birth">Date of Birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                       value="<?= $employee['date_of_birth'] ?? '' ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="nationality">Nationality</label>
            <input type="text" id="nationality" name="nationality" class="form-control"
                   value="<?= htmlspecialchars($employee['nationality'] ?? '') ?>">
        </div>
        </div>
    </div>

    <!-- -- Contact Information ------------------ -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-address-book" aria-hidden="true"></i> Contact Information</h3></div>
        <div class="card-body">

        <div class="form-row">
            <div class="form-group">
                <label for="personal_email">Personal Email</label>
                <input type="email" id="personal_email" name="personal_email" class="form-control"
                       value="<?= htmlspecialchars($employee['personal_email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="company_email" class="required">Company Email</label>
                <input type="email" id="company_email" name="company_email" class="form-control" required
                       value="<?= htmlspecialchars($employee['company_email'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="phone" class="required">Phone</label>
                <input type="tel" id="phone" name="phone" class="form-control" required
                       value="<?= htmlspecialchars($employee['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="location">Location</label>
                <input type="text" id="location" name="location" class="form-control"
                       value="<?= htmlspecialchars($employee['location'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="residence_address">Where do you stay?</label>
            <textarea id="residence_address" name="residence_address" class="form-control" rows="3"><?= htmlspecialchars($employee['residence_address'] ?? '') ?></textarea>
            <div class="hint-text">Please give clear directions that one can use to get to your current residence.</div>
        </div>
        </div>
    </div>

    <!-- -- Emergency Contact -------------------- -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-phone-alt" aria-hidden="true"></i> Emergency Contact</h3></div>
        <div class="card-body">

        <div class="form-row">
            <div class="form-group">
                <label for="next_of_kin_name">Next of Kin Name</label>
                <input type="text" id="next_of_kin_name" name="next_of_kin_name" class="form-control"
                       value="<?= htmlspecialchars($employee['next_of_kin_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="next_of_kin_relationship">Relationship with Next of Kin</label>
                <input type="text" id="next_of_kin_relationship" name="next_of_kin_relationship"
                       class="form-control" placeholder="e.g., Spouse, Sibling, Parent"
                       value="<?= htmlspecialchars($employee['next_of_kin_relationship'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="has_dependants">Do you have dependants?</label>
                <select id="has_dependants" name="has_dependants" class="form-control" onchange="toggleDependants()">
                    <option value="No"  <?= ($employee['has_dependants'] ?? 'No') === 'No'  ? 'selected' : '' ?>>No</option>
                    <option value="Yes" <?= ($employee['has_dependants'] ?? '')   === 'Yes' ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>
            <div class="form-group" id="dependants_count_group"
                 style="display:<?= ($employee['has_dependants'] ?? 'No') === 'Yes' ? 'block' : 'none' ?>;">
                <label for="number_of_dependants">Total Number of Dependants</label>
                <input type="number" id="number_of_dependants" name="number_of_dependants"
                       class="form-control" min="0"
                       value="<?= $employee['number_of_dependants'] ?? '0' ?>">
            </div>
        </div>
        </div>
    </div>

    <!-- -- Official Details --------------------- -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-id-card" aria-hidden="true"></i> Official Details</h3></div>
        <div class="card-body">

        <div class="form-row">
            <div class="form-group">
                <label for="ura_tin_number">URA TIN Number</label>
                <input type="text" id="ura_tin_number" name="ura_tin_number" class="form-control"
                       value="<?= htmlspecialchars($employee['ura_tin_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="nssf_number">NSSF Number</label>
                <input type="text" id="nssf_number" name="nssf_number" class="form-control"
                       value="<?= htmlspecialchars($employee['nssf_number'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="bank_details">Bank Details</label>
            <textarea id="bank_details" name="bank_details" class="form-control" rows="3"
                      placeholder="Bank Name, Account Number, Branch, etc."><?= htmlspecialchars($employee['bank_details'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="nin">National Identification Number (NIN)</label>
            <input type="text" id="nin" name="nin" class="form-control"
                   value="<?= htmlspecialchars($employee['nin'] ?? '') ?>">
        </div>
        </div>
    </div>

    <!-- -- Document Uploads --------------------- -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-file-upload" aria-hidden="true"></i> Document Uploads</h3></div>
        <div class="card-body">

        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Supported formats: PDF, DOC, DOCX, JPG, PNG (Max 10 MB per file).
            Leave a field blank to keep the existing file.
        </div>

        <?php
        $documents = [
            ['field' => 'academic_documents', 'label' => 'Academic Documents',       'hint' => 'Please compile all documents into 1 document before uploading.',                    'required' => true],
            ['field' => 'national_id',         'label' => 'National ID',              'hint' => 'Please ensure it is clear showing the back and front of the ID.',                   'required' => true],
            ['field' => 'cv',                  'label' => 'CV',                       'hint' => 'Please attach the most updated CV version.',                                         'required' => true],
            ['field' => 'cover_letter',        'label' => 'Cover Letter',             'hint' => null,                                                                                 'required' => false],
            ['field' => 'good_conduct_cert',   'label' => 'Certificate of Good Conduct','hint' => null,                                                                              'required' => false],
            ['field' => 'signed_policies',     'label' => 'Signed Policies Declaration','hint' => null,                                                                              'required' => true],
            ['field' => 'residence_map',       'label' => 'Map to Your Residence',    'hint' => null,                                                                                 'required' => false],
            ['field' => 'signed_contract',     'label' => 'Signed Contract',          'hint' => null,                                                                                 'required' => true],
        ];

        foreach ($documents as $doc):
            $file_path = $employee[$doc['field'] . '_path'] ?? '';
            /* Required only when no file exists yet */
            $is_required = $doc['required'] && !$file_path;
        ?>
        <div class="form-group">
            <label for="<?= $doc['field'] ?>" <?= $doc['required'] ? 'class="required"' : '' ?>>
                <?= $doc['label'] ?>
            </label>
            <input type="file" id="<?= $doc['field'] ?>" name="<?= $doc['field'] ?>"
                   class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                   <?= $is_required ? 'required' : '' ?>>
            <?php if ($doc['hint']): ?>
                <div class="hint-text"><?= $doc['hint'] ?></div>
            <?php endif; ?>
            <?php if ($file_path): ?>
            <div class="document-preview">
                <i class="fas fa-file-circle-check" style="font-size:22px;margin-right:10px;color:var(--brand-600);"></i>
                <div style="flex:1;">
                    <small><strong>Current file:</strong> <?= basename($file_path) ?></small><br>
                    <small class="cell-muted">Upload a new file to replace it.</small>
                </div>
                <a href="<?= htmlspecialchars(ims_upload_url($file_path)) ?>" target="_blank" class="btn btn-sm btn-info">
                    <i class="fas fa-eye"></i> View
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- -- Certification / Signature ----------- -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-signature" aria-hidden="true"></i> Certification</h3></div>
        <div class="card-body">

        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Declaration:</strong> I hereby certify that all information provided in this form is true and accurate to the best of my knowledge.
        </div>

        <div class="form-group">
            <label for="signaturePad" class="required">Your Signature</label>
            <canvas id="signaturePad" class="signature-pad" width="600" height="150"></canvas>
            <input type="hidden" name="signature_data" id="signature_data">
            <div style="margin-top:10px;">
                <button type="button" onclick="clearSignature()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-eraser"></i> Clear Signature
                </button>
            </div>
            <div class="hint-text">Please sign here to attest that the information you have provided is accurate.</div>
        </div>

        <div class="form-group">
            <label for="form_completion_date" class="required">Date of Completing this Form</label>
            <input type="date" id="form_completion_date" name="form_completion_date"
                   class="form-control" required value="<?= date('Y-m-d') ?>" readonly>
        </div>
        </div>
    </div>

    <!-- -- Action Buttons ----------------------- -->
    <div class="card">
        <div class="card-body">
        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
            <button type="submit" name="save_draft" class="btn btn-secondary">
                <i class="fas fa-save"></i> Save Draft
            </button>
            <button type="submit" name="submit_profile" class="btn btn-success">
                <i class="fas fa-paper-plane"></i>
                <?= ($employee && $employee['status'] === 'Approved') ? 'Update &amp; Re-submit' : 'Submit for Approval' ?>
            </button>
        </div>
        <?php if ($employee && $employee['status'] === 'Approved'): ?>
        <p style="text-align:right;font-size:12px;color:var(--amber-fg);margin-top:8px;">
            <i class="fas fa-info-circle"></i>
            Any change you save (including Save Draft) sends your profile back to <strong>Submitted</strong> for HR review.
        </p>
        <?php endif; ?>
        </div>
    </div>

</form>
</div>

<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js" integrity="sha384-WlgD02h8MN9icrgEOX8hj4xb9fs/MgaMecdLh5G2cdgDLPn1DNJSm6o2/MMVPt7m" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
/* -- Dependants toggle ----------------------------- */
function toggleDependants() {
    const val   = document.getElementById('has_dependants').value;
    const grp   = document.getElementById('dependants_count_group');
    grp.style.display = val === 'Yes' ? 'block' : 'none';
    if (val === 'No') document.getElementById('number_of_dependants').value = '0';
}

/* -- Signature pad --------------------------------- */
const canvas       = document.getElementById('signaturePad');
const signaturePad = new SignaturePad(canvas, { backgroundColor: 'rgb(255,255,255)' });

function clearSignature() {
    signaturePad.clear();
    document.getElementById('signature_data').value = '';
}

/* Capture signature on submit */
document.getElementById('employeeForm').addEventListener('submit', function(e) {
    if (!signaturePad.isEmpty()) {
        document.getElementById('signature_data').value = signaturePad.toDataURL();
    } else if (e.submitter && e.submitter.name === 'submit_profile') {
        alert('Please provide your signature before submitting.');
        e.preventDefault();
    }
});

/* Pre-load existing signature */
<?php if (!empty($employee['signature_path'])): ?>
(function() {
    const img = new Image();
    img.onload = function() {
        canvas.getContext('2d').drawImage(img, 0, 0);
    };
    img.src = <?= json_encode(ims_upload_url($employee['signature_path']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
})();
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>