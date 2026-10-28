<?php
require_once 'includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

// Get KPI ID
$kpi_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$kpi_id) {
    die('Invalid KPI ID');
}

// Fetch KPI details
$query = "SELECT k.*, c.category_name, c.category_code, d.department_name,
    u.full_name as owner_name, u.email as owner_email,
    reviewer.full_name as reviewer_name,
    approver.full_name as approver_name
    FROM kpis k
    LEFT JOIN kpi_categories c ON k.category_id = c.category_id
    LEFT JOIN departments d ON k.department_id = d.department_id
    LEFT JOIN users u ON k.user_id = u.user_id
    LEFT JOIN users reviewer ON k.reviewed_by = reviewer.user_id
    LEFT JOIN users approver ON k.approved_by = approver.user_id
    WHERE k.kpi_id = $kpi_id";
$result = $conn->query($query);

if ($result->num_rows == 0) {
    die('KPI not found');
}

$kpi = $result->fetch_assoc();

// Check permissions
$is_owner = $kpi['user_id'] == $_SESSION['user_id'];
$is_hr = ims_allowed('kpis.export_all', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin'], "Export any staff member's KPI to PDF");

if (!$is_owner && !$is_hr) {
    die('You do not have permission to export this KPI');
}

// Fetch comments
$comments = [];
$comment_query = "SELECT c.*, u.full_name 
    FROM kpi_comments c
    LEFT JOIN users u ON c.user_id = u.user_id
    WHERE c.kpi_id = $kpi_id
    ORDER BY c.created_at DESC
    LIMIT 10";
$comment_result = $conn->query($comment_query);
while ($row = $comment_result->fetch_assoc()) {
    $comments[] = $row;
}

// Calculate progress
$quarters_completed = 0;
$total_achievement = 0;
foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
    if ($kpi["{$q}_achievement"] !== null) {
        $quarters_completed++;
        $total_achievement += $kpi["{$q}_achievement"];
    }
}
$avg_achievement = $quarters_completed > 0 ? round($total_achievement / $quarters_completed, 1) : 0;

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
    <title>KPI Report - ' . htmlspecialchars($kpi['kpi_title']) . '</title>
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
            background: linear-gradient(135deg, #2c3e50 0%, #004e89 100%);
            color: #000000;
            padding: 25px;
            text-align: center;
            margin: -15px -15px 20px -15px;
        }
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 20px;
        }
        .header .subtitle {
            font-size: 11px;
            opacity: 0.9;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            margin-top: 10px;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        
        .meta-grid {
            display: table;
            width: 100%;
            margin: 15px 0;
            background: #f8f9fa;
            padding: 12px;
            border-radius: 5px;
        }
        .meta-row {
            display: table-row;
        }
        .meta-cell {
            display: table-cell;
            width: 33.33%;
            padding: 5px;
            font-size: 9px;
        }
        .meta-label {
            color: #666;
            font-weight: bold;
        }
        .meta-value {
            color: #333;
        }
        
        .achievement-box {
            background: linear-gradient(135deg, #2c3e50 0%, #004e89 100%);
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
            margin: 15px 0;
        }
        .achievement-number {
            font-size: 36px;
            font-weight: bold;
            margin: 5px 0;
        }
        .achievement-label {
            font-size: 10px;
            opacity: 0.9;
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
            color: #2c3e50;
            border-left: 4px solid #2c3e50;
            margin-bottom: 10px;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .info-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #ecf0f1;
        }
        .info-table td:first-child {
            width: 35%;
            font-weight: bold;
            color: #666;
            background: #f8f9fa;
        }
        
        .quarterly-grid {
            display: table;
            width: 100%;
            margin: 10px 0;
        }
        .quarterly-row {
            display: table-row;
        }
        .quarter-cell {
            display: table-cell;
            width: 25%;
            padding: 10px;
            border: 2px solid #ecf0f1;
            text-align: center;
            background: #f8f9fa;
        }
        .quarter-header {
            font-weight: bold;
            color: #2c3e50;
            font-size: 11px;
            margin-bottom: 8px;
        }
        .quarter-metric {
            margin: 5px 0;
            font-size: 9px;
        }
        .quarter-label {
            color: #666;
            text-transform: uppercase;
            font-size: 8px;
        }
        .quarter-value {
            font-size: 14px;
            font-weight: bold;
            color: #333;
        }
        .achievement-percent {
            font-size: 18px;
            font-weight: bold;
            color: #27AE60;
            margin: 8px 0;
        }
        
        .comment-box {
            background: #f8f9fa;
            padding: 10px;
            margin: 8px 0;
            border-left: 3px solid #2c3e50;
            border-radius: 3px;
            font-size: 9px;
        }
        .comment-header {
            font-weight: bold;
            color: #333;
            margin-bottom: 3px;
        }
        .comment-meta {
            color: #666;
            font-size: 8px;
            margin-bottom: 5px;
        }
        .comment-text {
            color: #333;
        }
        
        .alert {
            padding: 12px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .alert-danger {
            background: #f8d7da;
            border-left: 4px solid #E74C3C;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #27AE60;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #ddd;
            text-align: center;
            font-size: 8px;
            color: #777;
        }
        
        .progress-bar-container {
            height: 15px;
            background: #ecf0f1;
            border-radius: 8px;
            overflow: hidden;
            margin: 5px 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #27AE60, #2ECC71);
            text-align: center;
            line-height: 15px;
            font-size: 8px;
            color: white;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>' . htmlspecialchars($kpi['kpi_title']) . '</h1>
        <div class="subtitle">Key Performance Indicator Report</div>
        <div class="subtitle">Fiscal Year ' . $kpi['fiscal_year'] . ' | ' . htmlspecialchars($kpi['department_name']) . '</div>
        <span class="status-badge badge-' . 
            ($kpi['status'] == 'Approved' ? 'success' : 
            ($kpi['status'] == 'Submitted' ? 'info' : 
            ($kpi['status'] == 'Under Review' ? 'warning' :
            ($kpi['status'] == 'Rejected' ? 'danger' : 
            ($kpi['status'] == 'Completed' ? 'success' : 'secondary'))))) . 
        '">' . $kpi['status'] . '</span>
    </div>
    
    <!-- Key Metadata -->
    <div class="meta-grid">
        <div class="meta-row">
            <div class="meta-cell">
                <span class="meta-label">Owner:</span><br>
                <span class="meta-value">' . htmlspecialchars($kpi['owner_name']) . '</span>
            </div>
            <div class="meta-cell">
                <span class="meta-label">Category:</span><br>
                <span class="meta-value">' . htmlspecialchars($kpi['category_name']) . '</span>
            </div>
            <div class="meta-cell">
                <span class="meta-label">Weight:</span><br>
                <span class="meta-value">' . $kpi['weight_percentage'] . '%</span>
            </div>
        </div>
        <div class="meta-row">
            <div class="meta-cell">
                <span class="meta-label">Annual Target:</span><br>
                <span class="meta-value">' . $kpi['target_value'] . ' ' . htmlspecialchars($kpi['unit_of_measure']) . '</span>
            </div>
            <div class="meta-cell">
                <span class="meta-label">Created:</span><br>
                <span class="meta-value">' . date('d M Y', strtotime($kpi['created_at'])) . '</span>
            </div>
            <div class="meta-cell">
                <span class="meta-label">Last Updated:</span><br>
                <span class="meta-value">' . date('d M Y', strtotime($kpi['updated_at'])) . '</span>
            </div>
        </div>
    </div>';

// Overall Achievement
if ($kpi['overall_achievement'] !== null) {
    $html .= '
    <div class="achievement-box">
        <div class="achievement-label">Overall Achievement</div>
        <div class="achievement-number">' . $kpi['overall_achievement'] . '%</div>
        <div class="achievement-label">' . $quarters_completed . ' of 4 Quarters Completed</div>
    </div>';
}

// KPI Details
$html .= '
    <div class="section">
        <div class="section-title">KPI Details</div>
        <table class="info-table">
            <tr>
                <td>KPI Description</td>
                <td>' . nl2br(htmlspecialchars($kpi['kpi_description'])) . '</td>
            </tr>
            <tr>
                <td>Measurement Criteria</td>
                <td>' . nl2br(htmlspecialchars($kpi['measurement_criteria'])) . '</td>
            </tr>
            <tr>
                <td>Unit of Measure</td>
                <td>' . htmlspecialchars($kpi['unit_of_measure']) . '</td>
            </tr>
            <tr>
                <td>Weight Percentage</td>
                <td>' . $kpi['weight_percentage'] . '%</td>
            </tr>
        </table>
    </div>';

// Quarterly Progress
$html .= '
    <div class="section">
        <div class="section-title">Quarterly Progress</div>
        <div class="quarterly-grid">
            <div class="quarterly-row">';

$quarters = [
    'q1' => ['name' => 'Q1', 'period' => 'Jan-Mar'],
    'q2' => ['name' => 'Q2', 'period' => 'Apr-Jun'],
    'q3' => ['name' => 'Q3', 'period' => 'Jul-Sep'],
    'q4' => ['name' => 'Q4', 'period' => 'Oct-Dec']
];

foreach ($quarters as $q_key => $q_info) {
    $target = $kpi["{$q_key}_target"];
    $actual = $kpi["{$q_key}_actual"];
    $status = $kpi["{$q_key}_status"];
    $achievement = $kpi["{$q_key}_achievement"];
    
    $status_class = $status == 'Completed' ? 'success' : 
                   ($status == 'In Progress' ? 'info' : 
                   ($status == 'Delayed' ? 'danger' : 'secondary'));
    
    $html .= '
                <div class="quarter-cell">
                    <div class="quarter-header">' . $q_info['name'] . '<br><span style="font-weight:normal;font-size:8px;">' . $q_info['period'] . '</span></div>
                    <div class="quarter-metric">
                        <div class="quarter-label">Target</div>
                        <div class="quarter-value">' . ($target ?: '-') . '</div>
                    </div>
                    <div class="quarter-metric">
                        <div class="quarter-label">Actual</div>
                        <div class="quarter-value" style="color:' . ($actual !== null ? '#27AE60' : '#95A5A6') . ';">' . 
                            ($actual !== null ? $actual : 'Not recorded') . '</div>
                    </div>
                    <div class="quarter-metric">
                        <span class="status-badge badge-' . $status_class . '" style="font-size:8px;padding:3px 6px;">' . $status . '</span>
                    </div>';
    
    if ($achievement !== null) {
        $html .= '
                    <div class="achievement-percent">' . round($achievement) . '%</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width:' . min($achievement, 100) . '%;">' . 
                            (min($achievement, 100) >= 20 ? round($achievement) . '%' : '') . '</div>
                    </div>';
    }
    
    $html .= '
                </div>';
}

$html .= '
            </div>
        </div>
    </div>';

// Rejection Reason
if ($kpi['status'] == 'Rejected' && $kpi['rejection_reason']) {
    $html .= '
    <div class="section">
        <div class="alert alert-danger">
            <strong>Rejection Feedback</strong><br>
            <div style="font-size:9px;margin:5px 0;">
                <strong>Reviewed by:</strong> ' . htmlspecialchars($kpi['reviewer_name']) . '<br>
                <strong>Date:</strong> ' . date('d M Y H:i', strtotime($kpi['reviewed_at'])) . '
            </div>
            <div style="margin-top:8px;">' . nl2br(htmlspecialchars($kpi['rejection_reason'])) . '</div>
        </div>
    </div>';
}

// Approval Information
if ($kpi['status'] == 'Approved' || $kpi['status'] == 'Completed') {
    $html .= '
    <div class="section">
        <div class="alert alert-success">
            <strong>Approval Information</strong><br>
            <div style="font-size:9px;margin:5px 0;">
                <strong>Approved by:</strong> ' . htmlspecialchars($kpi['approver_name']) . '<br>
                <strong>Approval Date:</strong> ' . date('d M Y H:i', strtotime($kpi['approved_at'])) . '
            </div>
        </div>
    </div>';
}

// Comments (Top 10)
if (!empty($comments)) {
    $html .= '
    <div class="section">
        <div class="section-title">Recent Comments & Updates</div>';
    
    $comment_count = 0;
    foreach ($comments as $comment) {
        if ($comment_count >= 10) break;
        
        $html .= '
        <div class="comment-box">
            <div class="comment-header">' . htmlspecialchars($comment['full_name']) . ' 
                <span style="background:#2c3e50;color:white;padding:2px 6px;border-radius:3px;font-size:7px;margin-left:5px;">' . 
                $comment['quarter'] . '</span>
                <span style="background:#95a5a6;color:white;padding:2px 6px;border-radius:3px;font-size:7px;">' . 
                $comment['comment_type'] . '</span>
            </div>
            <div class="comment-meta">' . date('d M Y H:i', strtotime($comment['created_at'])) . '</div>
            <div class="comment-text">' . nl2br(htmlspecialchars($comment['comment_text'])) . '</div>
        </div>';
        
        $comment_count++;
    }
    
    $html .= '
    </div>';
}

// Performance Summary
$html .= '
    <div class="section">
        <div class="section-title">Performance Summary</div>
        <table class="info-table">';

// Calculate some statistics
$completed_quarters = array_filter([$kpi['q1_achievement'], $kpi['q2_achievement'], $kpi['q3_achievement'], $kpi['q4_achievement']], fn($a) => $a !== null);
$avg_ach = !empty($completed_quarters) ? round(array_sum($completed_quarters) / count($completed_quarters), 1) : 0;
$highest_ach = !empty($completed_quarters) ? max($completed_quarters) : 0;
$lowest_ach = !empty($completed_quarters) ? min($completed_quarters) : 0;

$html .= '
            <tr>
                <td>Quarters Completed</td>
                <td>' . count($completed_quarters) . ' of 4</td>
            </tr>
            <tr>
                <td>Average Achievement</td>
                <td><strong>' . $avg_ach . '%</strong></td>
            </tr>';

if (!empty($completed_quarters)) {
    $html .= '
            <tr>
                <td>Highest Quarter Achievement</td>
                <td>' . round($highest_ach) . '%</td>
            </tr>
            <tr>
                <td>Lowest Quarter Achievement</td>
                <td>' . round($lowest_ach) . '%</td>
            </tr>';
}

$html .= '
            <tr>
                <td>Overall Status</td>
                <td><span class="status-badge badge-' . 
                    ($kpi['status'] == 'Approved' ? 'success' : 
                    ($kpi['status'] == 'Submitted' ? 'info' : 
                    ($kpi['status'] == 'Completed' ? 'success' : 'secondary'))) . 
                '">' . $kpi['status'] . '</span></td>
            </tr>
        </table>
    </div>';

// Footer
$html .= '
    <div class="footer">
        <p><strong>' . htmlspecialchars(ims_brand_name()) . '</strong> - KPI Management System</p>
        <p>Generated on ' . date('d M Y H:i:s') . ' | Generated by: ' . htmlspecialchars($_SESSION['full_name']) . '</p>
        <p>This document is confidential and for internal use only.</p>
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
log_action($_SESSION['user_id'], 'Generate KPI PDF', 'kpis', $kpi_id, "Generated PDF for KPI: {$kpi['kpi_title']}");

// Output PDF to browser
$filename = 'KPI_' . preg_replace('/[^A-Za-z0-9_]/', '_', $kpi['kpi_title']) . '_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);

exit();
?>