<?php
require_once __DIR__ . '/includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

// Get employee ID (either from URL for viewing others, or use current user)
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

// If no employee_id provided, get current user's profile
if (!$employee_id) {
    $result = $conn->query("SELECT employee_id FROM employee_directory WHERE user_id = " . (int)$_SESSION['user_id']);
    if ($result && $result->num_rows > 0) {
        $employee_id = $result->fetch_assoc()['employee_id'];
    }
}

if (!$employee_id) {
    die('Employee profile not found');
}

// Fetch employee profile
$query = "SELECT ed.*, d.department_name, d.department_code,
    u.username, u.role as user_role,
    u_super.full_name as supervisor_name, u_super.email as supervisor_email, u_super.phone as supervisor_phone,
    u_approve.full_name as approved_by_name
    FROM employee_directory ed
    LEFT JOIN departments d ON ed.department_id = d.department_id
    LEFT JOIN users u ON ed.user_id = u.user_id
    LEFT JOIN users u_super ON ed.supervisor_id = u_super.user_id
    LEFT JOIN users u_approve ON ed.approved_by = u_approve.user_id
    WHERE ed.employee_id = $employee_id";
$result = $conn->query($query);
$employee = $result->fetch_assoc();

if (!$employee) {
    die('Employee not found');
}

// Check permissions - users can only view their own profile unless they're admin/management
if ($employee['user_id'] != $_SESSION['user_id']) {
    if (!ims_allowed('employees.view_all', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR', 'Executive Director'], "View any employee's profile (not just their own)")
        && (int)($employee['supervisor_id'] ?? 0) !== (int)$_SESSION['user_id']) {
        die('You do not have permission to view this profile');
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

// Load DomPDF
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Configure DomPDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);

// Build HTML content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Employee Profile - ' . htmlspecialchars($employee['full_name']) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #FF6B35, #3498DB);
            color: #000000;
            padding: 30px;
            text-align: center;
            margin: -20px -20px 20px -20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 14px;
        }
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .section-title {
            background: #f0f0f0;
            padding: 8px 10px;
            font-size: 14px;
            font-weight: bold;
            color: #FF6B35;
            border-left: 4px solid #FF6B35;
            margin-bottom: 10px;
        }
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }
        .info-row {
            display: table-row;
        }
        .info-label {
            display: table-cell;
            width: 35%;
            padding: 6px;
            background: #f8f9fa;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
        }
        .info-value {
            display: table-cell;
            width: 65%;
            padding: 6px;
            border-bottom: 1px solid #ddd;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .signature-box {
            text-align: center;
            padding: 15px;
            border: 2px dashed #ccc;
            margin: 10px 0;
        }
        .signature-img {
            max-width: 250px;
            max-height: 80px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #ddd;
            text-align: center;
            font-size: 9px;
            color: #777;
        }
        .quick-info {
            background: #f8f9fa;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .quick-info-grid {
            display: table;
            width: 100%;
        }
        .quick-info-cell {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 10px;
        }
        .quick-info-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
        }
        .quick-info-value {
            font-size: 12px;
            font-weight: bold;
            color: #333;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>' . htmlspecialchars($employee['full_name']) . '</h1>
        <p>' . htmlspecialchars($employee['job_title']) . '</p>
        <p>' . htmlspecialchars($employee['department_name']) . ' &bull; ' . htmlspecialchars($employee['contract_type']) . '</p>
        <p><span class="status-badge badge-' . 
            ($employee['status'] == 'Approved' ? 'success' : 
            ($employee['status'] == 'Submitted' ? 'info' : 'warning')) . '">' . 
            $employee['status'] . '</span></p>
    </div>
    
    <!-- Quick Info -->
    <div class="quick-info">
        <div class="quick-info-grid">
            <div class="quick-info-cell">
                <div class="quick-info-label">Start Date</div>
                <div class="quick-info-value">' . date('d M Y', strtotime($employee['start_date'])) . '</div>
            </div>
            <div class="quick-info-cell">
                <div class="quick-info-label">Tenure</div>
                <div class="quick-info-value">' . $tenure_text . '</div>
            </div>
            <div class="quick-info-cell">
                <div class="quick-info-label">Email</div>
                <div class="quick-info-value" style="font-size: 10px;">' . htmlspecialchars($employee['company_email']) . '</div>
            </div>
            <div class="quick-info-cell">
                <div class="quick-info-label">Phone</div>
                <div class="quick-info-value">' . htmlspecialchars($employee['phone']) . '</div>
            </div>
        </div>
    </div>
    
    <!-- Personal Information -->
    <div class="section">
        <div class="section-title">Personal Information</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Contract Type</div>
                <div class="info-value">' . htmlspecialchars($employee['contract_type']) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Gender</div>
                <div class="info-value">' . htmlspecialchars($employee['gender']) . '</div>
            </div>';

if ($employee['date_of_birth']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Date of Birth</div>
                <div class="info-value">' . date('d M Y', strtotime($employee['date_of_birth'])) . ' (' . $age . ')</div>
            </div>';
}

if ($employee['nationality']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Nationality</div>
                <div class="info-value">' . htmlspecialchars($employee['nationality']) . '</div>
            </div>';
}

$html .= '
            <div class="info-row">
                <div class="info-label">Department</div>
                <div class="info-value">' . htmlspecialchars($employee['department_name']) . '</div>
            </div>';

if ($employee['location']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Location</div>
                <div class="info-value">' . htmlspecialchars($employee['location']) . '</div>
            </div>';
}

$html .= '
        </div>
    </div>';

// Contract Period
if ($employee['contract_end_date']) {
    $end = new DateTime($employee['contract_end_date']);
    $remaining = $today->diff($end);
    $contract_status = $end > $today ? 
        '(' . $remaining->days . ' days remaining)' : 
        '(Contract ended)';
    
    $html .= '
    <div class="section">
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Contract Period</div>
                <div class="info-value">' . 
                    date('d M Y', strtotime($employee['start_date'])) . ' to ' . 
                    date('d M Y', strtotime($employee['contract_end_date'])) . ' ' . 
                    $contract_status . '
                </div>
            </div>
        </div>
    </div>';
}

// Supervisor Information
$html .= '
    <div class="section">
        <div class="section-title">Reporting To</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Supervisor/Manager</div>
                <div class="info-value">' . htmlspecialchars($employee['supervisor_name']) . '</div>
            </div>';

if ($employee['supervisor_email']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Supervisor Email</div>
                <div class="info-value">' . htmlspecialchars($employee['supervisor_email']) . '</div>
            </div>';
}

if ($employee['supervisor_phone']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Supervisor Phone</div>
                <div class="info-value">' . htmlspecialchars($employee['supervisor_phone']) . '</div>
            </div>';
}

$html .= '
        </div>
    </div>';

// Contact Information
$html .= '
    <div class="section">
        <div class="section-title">Contact Information</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Company Email</div>
                <div class="info-value">' . htmlspecialchars($employee['company_email']) . '</div>
            </div>';

if ($employee['personal_email']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Personal Email</div>
                <div class="info-value">' . htmlspecialchars($employee['personal_email']) . '</div>
            </div>';
}

$html .= '
            <div class="info-row">
                <div class="info-label">Phone Number</div>
                <div class="info-value">' . htmlspecialchars($employee['phone']) . '</div>
            </div>';

if ($employee['residence_address']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Residence Address</div>
                <div class="info-value">' . nl2br(htmlspecialchars($employee['residence_address'])) . '</div>
            </div>';
}

$html .= '
        </div>
    </div>';

// Emergency Contact
$html .= '
    <div class="section">
        <div class="section-title">Emergency Contact</div>
        <div class="info-grid">';

if ($employee['next_of_kin_name']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Next of Kin Name</div>
                <div class="info-value">' . htmlspecialchars($employee['next_of_kin_name']) . '</div>
            </div>';
}

if ($employee['next_of_kin_relationship']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Relationship</div>
                <div class="info-value">' . htmlspecialchars($employee['next_of_kin_relationship']) . '</div>
            </div>';
}

$dependants_text = $employee['has_dependants'] == 'Yes' ? 
    $employee['number_of_dependants'] . ' dependant' . ($employee['number_of_dependants'] > 1 ? 's' : '') : 
    'No dependants';

$html .= '
            <div class="info-row">
                <div class="info-label">Dependants</div>
                <div class="info-value">' . $dependants_text . '</div>
            </div>
        </div>
    </div>';

// Official Details
$html .= '
    <div class="section">
        <div class="section-title">Official Details</div>
        <div class="info-grid">';

if ($employee['nin']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">National ID Number (NIN)</div>
                <div class="info-value">' . htmlspecialchars($employee['nin']) . '</div>
            </div>';
}

if ($employee['ura_tin_number']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">URA TIN Number</div>
                <div class="info-value">' . htmlspecialchars($employee['ura_tin_number']) . '</div>
            </div>';
}

if ($employee['nssf_number']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">NSSF Number</div>
                <div class="info-value">' . htmlspecialchars($employee['nssf_number']) . '</div>
            </div>';
}

if ($employee['bank_details']) {
    $html .= '
            <div class="info-row">
                <div class="info-label">Bank Details</div>
                <div class="info-value">' . nl2br(htmlspecialchars($employee['bank_details'])) . '</div>
            </div>';
}

$html .= '
        </div>
    </div>';

// Signature
if ($employee['signature_path'] && file_exists($employee['signature_path'])) {
    $html .= '
    <div class="section">
        <div class="section-title">Certification</div>
        <div class="signature-box">
            <p><strong>Employee Signature</strong></p>
            <img src="' . $employee['signature_path'] . '" alt="Signature" class="signature-img">
            <p style="margin-top: 10px; font-size: 10px;">Date: ' . date('d M Y', strtotime($employee['form_completion_date'])) . '</p>
        </div>
    </div>';
}

// Approval Information
if ($employee['status'] == 'Approved') {
    $html .= '
    <div class="section">
        <div class="section-title">Approval Information</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Approved By</div>
                <div class="info-value">' . htmlspecialchars($employee['approved_by_name']) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Approval Date</div>
                <div class="info-value">' . date('d M Y, H:i', strtotime($employee['approved_at'])) . '</div>
            </div>
        </div>
    </div>';
}

// Footer
$html .= '
    <div class="footer">
        <p><strong>Hive Colab</strong> - Employee Directory</p>
        <p>Generated on ' . date('d M Y H:i:s') . ' | This document is confidential and for internal use only.</p>
    </div>
</body>
</html>';

// Load HTML into DomPDF
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'portrait');

// Render PDF
$dompdf->render();
require_once __DIR__ . '/includes/pdf-branding.php';
ims_brand_pdf($dompdf);

// Log PDF generation
log_action($_SESSION['user_id'], 'Generate Employee PDF', 'employee_directory', $employee_id, "Generated PDF for {$employee['full_name']}");

// Output PDF to browser
$filename = 'Employee_Profile_' . preg_replace('/[^A-Za-z0-9_]/', '_', $employee['full_name']) . '_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);

exit();
?>
