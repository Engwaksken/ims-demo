<?php
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

check_role(['Administrator', 'Programs Lead', 'HR']);

// Get filter parameters
$filter_department = isset($_GET['department']) ? intval($_GET['department']) : 0;
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Build WHERE clause
$where = "1=1";
if ($filter_department) {
    $where .= " AND ed.department_id = $filter_department";
}

// Fetch all employees
$employees = [];
$query = "SELECT ed.*, d.department_name, d.department_code
    FROM employee_directory ed
    LEFT JOIN departments d ON ed.department_id = d.department_id
    WHERE $where
    ORDER BY ed.full_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

// Fetch departments
$departments = [];
$result = $conn->query("SELECT * FROM departments ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $departments[] = $row;
}

// Calculate statistics
$total_employees = count($employees);
$approved = count(array_filter($employees, fn($e) => $e['status'] == 'Approved'));
$submitted = count(array_filter($employees, fn($e) => $e['status'] == 'Submitted'));
$draft = count(array_filter($employees, fn($e) => $e['status'] == 'Draft'));
$rejected = count(array_filter($employees, fn($e) => $e['status'] == 'Rejected'));

// Contract type breakdown
$staff = count(array_filter($employees, fn($e) => $e['contract_type'] == 'Staff'));
$consultants = count(array_filter($employees, fn($e) => $e['contract_type'] == 'Consultant'));
$temp = count(array_filter($employees, fn($e) => $e['contract_type'] == 'Temp'));
$casual = count(array_filter($employees, fn($e) => $e['contract_type'] == 'Casual'));

// Gender breakdown
$male = count(array_filter($employees, fn($e) => $e['gender'] == 'Male'));
$female = count(array_filter($employees, fn($e) => $e['gender'] == 'Female'));

// Department breakdown
$dept_stats = [];
foreach ($departments as $dept) {
    $count = count(array_filter($employees, fn($e) => $e['department_id'] == $dept['department_id']));
    if ($count > 0) {
        $dept_stats[] = [
            'name' => $dept['department_name'],
            'code' => $dept['department_code'],
            'count' => $count,
            'percentage' => round(($count / $total_employees) * 100, 1)
        ];
    }
}

// Calculate tenure statistics
$tenure_ranges = [
    'new' => 0,
    'junior' => 0,
    'mid' => 0,
    'senior' => 0,
    'veteran' => 0
];

$today = new DateTime();
foreach ($employees as $emp) {
    if ($emp['start_date']) {
        $start = new DateTime($emp['start_date']);
        $diff = $start->diff($today);
        $months = ($diff->y * 12) + $diff->m;
        
        if ($months < 6) $tenure_ranges['new']++;
        elseif ($months < 12) $tenure_ranges['junior']++;
        elseif ($diff->y < 3) $tenure_ranges['mid']++;
        elseif ($diff->y < 5) $tenure_ranges['senior']++;
        else $tenure_ranges['veteran']++;
    }
}

// Contract expiry tracking
$contracts_expiring = [];
$expiring_90_days = new DateTime('+90 days');
foreach ($employees as $emp) {
    if ($emp['contract_end_date']) {
        $end = new DateTime($emp['contract_end_date']);
        if ($end > $today && $end <= $expiring_90_days) {
            $days_remaining = $today->diff($end)->days;
            $contracts_expiring[] = [
                'name' => $emp['full_name'],
                'job_title' => $emp['job_title'],
                'department' => $emp['department_name'],
                'end_date' => $emp['contract_end_date'],
                'days_remaining' => $days_remaining
            ];
        }
    }
}

usort($contracts_expiring, fn($a, $b) => $a['days_remaining'] - $b['days_remaining']);

// Get filter description
$filter_desc = 'All Departments';
if ($filter_department) {
    $dept_result = $conn->query("SELECT department_name FROM departments WHERE department_id = $filter_department");
    if ($dept_result && $dept_result->num_rows > 0) {
        $filter_desc = $dept_result->fetch_assoc()['department_name'];
    }
}

// Load DomPDF
foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }

use Dompdf\Dompdf;
use Dompdf\Options;

// Configure DomPDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);

// Build HTML content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Employee Report - ' . htmlspecialchars(ims_brand_name()) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 15px;
        }
        .header {
            background: linear-gradient(135deg, #FF6B35, #3498DB);
            color: #000000;
            padding: 25px;
            text-align: center;
            margin: -15px -15px 20px -15px;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 12px;
            opacity: 0.9;
        }
        .stats-grid {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .stats-row {
            display: table-row;
        }
        .stat-card {
            display: table-cell;
            width: 16.66%;
            padding: 15px;
            text-align: center;
            background: #f8f9fa;
            border: 1px solid #ddd;
        }
        .stat-number {
            font-size: 20px;
            font-weight: bold;
            color: #FF6B35;
        }
        .stat-label {
            font-size: 9px;
            color: #666;
            margin-top: 3px;
        }
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .section-title {
            background: #f0f0f0;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: bold;
            color: #FF6B35;
            border-left: 4px solid #FF6B35;
            margin-bottom: 10px;
        }
        .two-column {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }
        .column {
            display: table-cell;
            width: 50%;
            padding: 0 10px;
        }
        .column:first-child {
            padding-left: 0;
        }
        .column:last-child {
            padding-right: 0;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .data-table th {
            background: #ecf0f1;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #bdc3c7;
            font-size: 9px;
        }
        .data-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 9px;
        }
        .progress-bar {
            height: 20px;
            background: #ecf0f1;
            border-radius: 10px;
            overflow: hidden;
            margin: 5px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #FF6B35, #3498DB);
            color: white;
            text-align: center;
            line-height: 20px;
            font-size: 9px;
            font-weight: bold;
        }
        .mini-stat-grid {
            display: table;
            width: 100%;
            margin: 10px 0;
        }
        .mini-stat-row {
            display: table-row;
        }
        .mini-stat {
            display: table-cell;
            width: 20%;
            padding: 10px;
            text-align: center;
            background: #f8f9fa;
            border: 1px solid #ddd;
        }
        .mini-stat-number {
            font-size: 18px;
            font-weight: bold;
        }
        .mini-stat-label {
            font-size: 8px;
            color: #666;
            margin-top: 3px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: bold;
        }
        .badge-danger { background: #ffebee; color: #c62828; }
        .badge-warning { background: #fff3e0; color: #e65100; }
        .badge-info { background: #e3f2fd; color: #1565c0; }
        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin: 10px 0;
            font-size: 10px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #ddd;
            text-align: center;
            font-size: 8px;
            color: #777;
        }
        .color-box {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 3px;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>Employee Report & Analytics</h1>
        <p>' . htmlspecialchars(ims_brand_name()) . ' Information Management System</p>
        <p>Report Period: ' . $filter_year . ' | Filter: ' . htmlspecialchars($filter_desc) . '</p>
        <p>Generated on ' . date('d M Y H:i:s') . '</p>
    </div>
    
    <!-- Quick Stats -->
    <div class="stats-grid">
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-number">' . $total_employees . '</div>
                <div class="stat-label">Total Employees</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">' . $approved . '</div>
                <div class="stat-label">Approved Profiles</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">' . $staff . '</div>
                <div class="stat-label">Staff Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">' . $consultants . '</div>
                <div class="stat-label">Consultants</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">' . round(($female / $total_employees) * 100, 1) . '%</div>
                <div class="stat-label">Female Employees</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">' . count($contracts_expiring) . '</div>
                <div class="stat-label">Expiring Soon</div>
            </div>
        </div>
    </div>
    
    <!-- Contract Type & Gender Distribution -->
    <div class="two-column">
        <div class="column">
            <div class="section">
                <div class="section-title">Contract Type Distribution</div>
                <table style="width: 100%; font-size: 10px;">
                    <tr>
                        <td><span class="color-box" style="background: #3498DB;"></span>Staff</td>
                        <td style="text-align: right;"><strong>' . $staff . '</strong> (' . round(($staff/$total_employees)*100,1) . '%)</td>
                    </tr>
                    <tr>
                        <td><span class="color-box" style="background: #E67E22;"></span>Consultant</td>
                        <td style="text-align: right;"><strong>' . $consultants . '</strong> (' . round(($consultants/$total_employees)*100,1) . '%)</td>
                    </tr>
                    <tr>
                        <td><span class="color-box" style="background: #9B59B6;"></span>Temp</td>
                        <td style="text-align: right;"><strong>' . $temp . '</strong> (' . round(($temp/$total_employees)*100,1) . '%)</td>
                    </tr>
                    <tr>
                        <td><span class="color-box" style="background: #95A5A6;"></span>Casual</td>
                        <td style="text-align: right;"><strong>' . $casual . '</strong> (' . round(($casual/$total_employees)*100,1) . '%)</td>
                    </tr>
                </table>
            </div>
        </div>
        <div class="column">
            <div class="section">
                <div class="section-title">Gender Distribution</div>
                <table style="width: 100%; font-size: 10px;">
                    <tr>
                        <td><span class="color-box" style="background: #3498DB;"></span>Male</td>
                        <td style="text-align: right;"><strong>' . $male . '</strong> (' . round(($male/$total_employees)*100,1) . '%)</td>
                    </tr>
                    <tr>
                        <td><span class="color-box" style="background: #E91E63;"></span>Female</td>
                        <td style="text-align: right;"><strong>' . $female . '</strong> (' . round(($female/$total_employees)*100,1) . '%)</td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding-top: 10px; font-size: 9px; color: #666;">
                            Gender Diversity Ratio: ' . round($female/$male, 2) . ':1 (F:M)
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Department Distribution -->
    <div class="section">
        <div class="section-title">Department Distribution</div>';

foreach ($dept_stats as $dept) {
    $html .= '
        <div style="margin-bottom: 8px;">
            <div style="display: table; width: 100%; margin-bottom: 3px;">
                <div style="display: table-cell; font-weight: bold;">' . htmlspecialchars($dept['name']) . '</div>
                <div style="display: table-cell; text-align: right;">' . $dept['count'] . ' employees (' . $dept['percentage'] . '%)</div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: ' . $dept['percentage'] . '%;">' . $dept['percentage'] . '%</div>
            </div>
        </div>';
}

$html .= '
    </div>
    
    <!-- Tenure Analysis -->
    <div class="section">
        <div class="section-title">Employee Tenure Analysis</div>
        <div class="mini-stat-grid">
            <div class="mini-stat-row">
                <div class="mini-stat">
                    <div class="mini-stat-number" style="color: #E74C3C;">' . $tenure_ranges['new'] . '</div>
                    <div class="mini-stat-label">New<br/>(< 6 months)</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-number" style="color: #F39C12;">' . $tenure_ranges['junior'] . '</div>
                    <div class="mini-stat-label">Junior<br/>(6m - 1y)</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-number" style="color: #3498DB;">' . $tenure_ranges['mid'] . '</div>
                    <div class="mini-stat-label">Mid-Level<br/>(1-3 years)</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-number" style="color: #9B59B6;">' . $tenure_ranges['senior'] . '</div>
                    <div class="mini-stat-label">Senior<br/>(3-5 years)</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-number" style="color: #27AE60;">' . $tenure_ranges['veteran'] . '</div>
                    <div class="mini-stat-label">Veteran<br/>(5+ years)</div>
                </div>
            </div>
        </div>
        <p style="font-size: 9px; color: #666; margin-top: 10px;">
            Average Tenure: ' . round((($tenure_ranges['new']*0.25 + $tenure_ranges['junior']*0.75 + $tenure_ranges['mid']*2 + $tenure_ranges['senior']*4 + $tenure_ranges['veteran']*6) / $total_employees), 1) . ' years
        </p>
    </div>
    
    <!-- Profile Status -->
    <div class="section">
        <div class="section-title">Profile Completion Status</div>
        <div class="mini-stat-grid">
            <div class="mini-stat-row">
                <div class="mini-stat" style="width: 25%;">
                    <div class="mini-stat-number" style="color: #27AE60;">' . $approved . '</div>
                    <div class="mini-stat-label">Approved</div>
                </div>
                <div class="mini-stat" style="width: 25%;">
                    <div class="mini-stat-number" style="color: #3498DB;">' . $submitted . '</div>
                    <div class="mini-stat-label">Submitted</div>
                </div>
                <div class="mini-stat" style="width: 25%;">
                    <div class="mini-stat-number" style="color: #95A5A6;">' . $draft . '</div>
                    <div class="mini-stat-label">Draft</div>
                </div>
                <div class="mini-stat" style="width: 25%;">
                    <div class="mini-stat-number" style="color: #E74C3C;">' . $rejected . '</div>
                    <div class="mini-stat-label">Rejected</div>
                </div>
            </div>
        </div>
    </div>';

// Contracts Expiring
if (!empty($contracts_expiring)) {
    $html .= '
    <div class="section">
        <div class="section-title">Contracts Expiring in Next 90 Days</div>
        <div class="alert-warning">
            <strong>' . count($contracts_expiring) . ' contract(s)</strong> are expiring soon. Please review and take necessary action.
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Job Title</th>
                    <th>Department</th>
                    <th>Contract End Date</th>
                    <th>Days Remaining</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($contracts_expiring as $contract) {
        $urgency_class = $contract['days_remaining'] <= 30 ? 'danger' : 
                        ($contract['days_remaining'] <= 60 ? 'warning' : 'info');
        $urgency_text = $contract['days_remaining'] <= 30 ? 'Urgent' : 
                       ($contract['days_remaining'] <= 60 ? 'Action Needed' : 'Monitor');
        
        $html .= '
                <tr>
                    <td><strong>' . htmlspecialchars($contract['name']) . '</strong></td>
                    <td>' . htmlspecialchars($contract['job_title']) . '</td>
                    <td>' . htmlspecialchars($contract['department']) . '</td>
                    <td>' . date('d M Y', strtotime($contract['end_date'])) . '</td>
                    <td><span class="badge badge-' . $urgency_class . '">' . $contract['days_remaining'] . ' days</span></td>
                    <td><span class="badge badge-' . $urgency_class . '">' . $urgency_text . '</span></td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
    </div>';
}

// Summary Section
$html .= '
    <div class="section">
        <div class="section-title">Summary & Key Insights</div>
        <ul style="font-size: 10px; line-height: 1.8;">
            <li><strong>Workforce Size:</strong> ' . $total_employees . ' employees across ' . count($dept_stats) . ' departments</li>
            <li><strong>Contract Composition:</strong> ' . round(($staff/$total_employees)*100,1) . '% Staff, ' . round(($consultants/$total_employees)*100,1) . '% Consultants</li>
            <li><strong>Gender Diversity:</strong> ' . round(($female/$total_employees)*100,1) . '% Female, ' . round(($male/$total_employees)*100,1) . '% Male (Ratio ' . round($female/$male, 2) . ':1)</li>
            <li><strong>Profile Completion:</strong> ' . round(($approved/$total_employees)*100,1) . '% approved, ' . round(($submitted/$total_employees)*100,1) . '% pending review</li>
            <li><strong>Workforce Stability:</strong> ' . $tenure_ranges['veteran'] . ' veteran employees (5+ years), ' . $tenure_ranges['new'] . ' new hires (< 6 months)</li>
            <li><strong>Contract Management:</strong> ' . count($contracts_expiring) . ' contracts expiring in next 90 days requiring attention</li>
        </ul>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        <p><strong>' . htmlspecialchars(ims_brand_name()) . '</strong> - Employee Directory Management System</p>
        <p>This report is confidential and for internal use only.</p>
        <p>Generated by: ' . htmlspecialchars($_SESSION['full_name']) . ' | Date: ' . date('d M Y H:i:s') . '</p>
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
log_action($_SESSION['user_id'], 'Generate Employee Report PDF', 'employee_directory', null, "Generated employee report PDF for $filter_year (Filter: $filter_desc)");

// Output PDF to browser
$filename = 'Employee_Report_' . $filter_year . '_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);

exit();
?>