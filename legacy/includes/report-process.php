<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* -- Session & auth --------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login");
    exit();
}

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin']);

/* -- Only handle POST ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_report'])) {
    header("Location: ../generate-report");
    exit();
}

/* -- Helper ----------------------------------------------------------- */
function bail(string $msg): never {
    send_notification($_SESSION['user_id'], $msg, 'danger');
    header("Location: ../generate-report");
    exit();
}

/* -- Sanitise inputs -------------------------------------------------- */
$report_type = sanitize_input($_POST['report_type'] ?? '');
$start_date  = sanitize_input($_POST['start_date']  ?? '');
$end_date    = sanitize_input($_POST['end_date']    ?? '');
$project_id  = !empty($_POST['project_id']) ? (int) $_POST['project_id'] : null;
$program_id  = !empty($_POST['program_id']) ? (int) $_POST['program_id'] : null;
$format      = sanitize_input($_POST['format']      ?? 'html');

/* -- Validate dates --------------------------------------------------- */
if (!$start_date || !$end_date || !strtotime($start_date) || !strtotime($end_date)) {
    bail('Invalid date values provided.');
}
if (strtotime($end_date) < strtotime($start_date)) {
    bail('End date must be on or after start date.');
}

/* -- Validate report type --------------------------------------------- */
$valid_types = [
    'program_summary', 'project_summary', 'participant_report',
    'indicator_performance', 'events_report', 'hub_operations',
    'donor_report', 'pwd_report', 'quarterly_report', 'annual_report',
];
if (!in_array($report_type, $valid_types, true)) {
    bail('Invalid report type selected. Please go back and choose a report.');
}

/* -- Validate format -------------------------------------------------- */
if (!in_array($format, ['html', 'pdf', 'excel'], true)) {
    $format = 'html';
}

/* -- Scope label (for logging) ---------------------------------------- */
if ($program_id) {
    $stmt = $conn->prepare("SELECT program_name FROM programs WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $program_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $scope_label = $row ? "Program: {$row['program_name']}" : "Program ID: $program_id";
    $stmt->close();
} elseif ($project_id) {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE project_id = ? LIMIT 1");
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $scope_label = $row ? "Project: {$row['project_name']}" : "Project ID: $project_id";
    $stmt->close();
} else {
    $scope_label = 'Organisation-wide';
}

/* -- Audit log -------------------------------------------------------- */
log_action(
    $_SESSION['user_id'],
    'Generate Report',
    'reports',
    null,
    "Type: {$report_type} | Scope: {$scope_label} | Period: {$start_date} ? {$end_date} | Format: {$format}"
);

/* -- Build the view-report URL (shared by HTML, PDF and Excel) -------- */
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
$base_path = preg_replace('#/legacy$#', '', rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/'));

$query_params = array_filter([
    'type'       => $report_type,
    'start_date' => $start_date,
    'end_date'   => $end_date,
    'project_id' => $project_id,
    'program_id' => $program_id,
    'format'     => 'html',         // always fetch HTML; Dompdf will render it
]);

$report_url = $scheme . '://' . $host . $base_path
            . '/view-report?' . http_build_query($query_params);

/* ----------------------------------------------------------------------
   PDF  � include view-report.php directly (no cURL, no loopback)
   ---------------------------------------------------------------------- */
if ($format === 'pdf') {

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    }
    if (!file_exists($autoload)) {
        bail('Dompdf is not installed. Run: composer require dompdf/dompdf');
    }
    require_once $autoload;
    if (!class_exists('\Dompdf\Dompdf')) {
        bail('Dompdf class not found after autoload. Check your vendor directory.');
    }

  
    if (!defined('REPORT_PDF_RENDER')) {
        define('REPORT_PDF_RENDER', true);
    }

    $_GET['type']       = $report_type;
    $_GET['start_date'] = $start_date;
    $_GET['end_date']   = $end_date;
    if ($program_id) { $_GET['program_id'] = $program_id; } else { unset($_GET['program_id']); }
    if ($project_id) { $_GET['project_id'] = $project_id; } else { unset($_GET['project_id']); }

    ob_start();
    include dirname(__DIR__) . '/view-report.php';
    $html = ob_get_clean();

    if (!$html || strlen(trim($html)) < 200) {
        bail('Report content could not be generated. Check view-report.php for errors.');
    }

    /* -- PDF print CSS: hide chrome, reset layout for A4 -- */
    $pdf_css = '
<style>
/* -- Hide all navigation, action bars and interactive chrome -- */
.sidebar, .side-nav, #sidebar, nav.sidebar,
.topbar, .top-bar, .navbar, .nav-bar, #topbar,
.main-header, .header-bar, .page-header,
.breadcrumb, .breadcrumbs,
.reports-hero, .controls-bar, .tab-header,
.filter-bar, .search-bar, .hero-actions,
.stat-card, .stats-grid, .reports-stats, .projects-stats,
.btn, button, input[type="submit"], input[type="button"],
.no-print, .alert, footer, .footer, #footer,
[onclick], a.btn { display: none !important; }

/* -- Reset body & layout -- */
*  { box-sizing: border-box; }

body {
    margin: 0 !important;
    padding: 0 !important;
    font-family: "DejaVu Sans", Arial, sans-serif !important;
    font-size: 12px !important;
    color: #1a1a1a !important;
    background: #ffffff !important;
    line-height: 1.5 !important;
}

/* -- Content area fills the A4 page -- */
.main-content, .content-wrapper, .page-content, .reports-wrap,
#main-content, #content, .container, .container-fluid,
.projects-wrap, .surveys-wrap {
    margin: 0 !important;
    padding: 16px 20px !important;
    width: 100% !important;
    max-width: 100% !important;
    float: none !important;
    left: 0 !important;
    position: static !important;
    display: block !important;
}

/* -- Report title header (show if present) -- */
.report-title-bar {
    background: #0f2942;
    color: #ffffff;
    padding: 14px 20px;
    border-radius: 0;
    margin-bottom: 20px;
}
.report-title-bar h1, .report-title-bar p { color: #ffffff !important; }

/* -- Panels / cards -- */
.panel, .info-panel, .card, .report-section {
    border: 1px solid #d1d5db !important;
    border-radius: 6px !important;
    margin-bottom: 18px !important;
    page-break-inside: avoid !important;
    box-shadow: none !important;
    background: #ffffff !important;
}
.panel-head, .info-panel-head, .card-header, .report-section-head {
    background: #f3f4f6 !important;
    padding: 9px 14px !important;
    font-weight: 800 !important;
    font-size: 12px !important;
    border-bottom: 1px solid #d1d5db !important;
    color: #1e2530 !important;
}
.panel-body, .info-panel-body, .card-body, .report-section-body {
    padding: 12px 14px !important;
}

/* -- Tables -- */
table {
    width: 100% !important;
    border-collapse: collapse !important;
    margin-bottom: 14px !important;
    font-size: 11px !important;
}
table th {
    background: #f3f4f6 !important;
    color: #374151 !important;
    font-weight: 700 !important;
    font-size: 10px !important;
    text-transform: uppercase !important;
    letter-spacing: .04em !important;
    padding: 7px 10px !important;
    border: 1px solid #d1d5db !important;
    text-align: left !important;
}
table td {
    padding: 7px 10px !important;
    border: 1px solid #e5e7eb !important;
    color: #374151 !important;
    vertical-align: middle !important;
}
table tbody tr:nth-child(even) td { background: #f9fafb !important; }

/* -- Badges -- */
.badge {
    display: inline-block !important;
    padding: 2px 8px !important;
    border-radius: 999px !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    border: 1px solid #d1d5db !important;
}
.badge-available, .badge-ongoing   { background: #f0fdf4 !important; color: #15803d !important; border-color: #bbf7d0 !important; }
.badge-assigned,  .badge-completed { background: #eff6ff !important; color: #1d4ed8 !important; border-color: #bfdbfe !important; }
.badge-pending                     { background: #fffbeb !important; color: #b45309 !important; border-color: #fde68a !important; }
.badge-rejected,  .badge-cancelled { background: #fef2f2 !important; color: #b91c1c !important; border-color: #fecaca !important; }

/* -- Progress bars -- */
.progress-track, .ach-track, .ind-track {
    background: #e5e7eb !important;
    height: 8px !important;
    border-radius: 999px !important;
    overflow: hidden !important;
    margin: 4px 0 !important;
}
.progress-fill, .ach-fill, .ind-fill {
    height: 100% !important;
    border-radius: 999px !important;
}
.progress-fill   { background: #3b82f6 !important; }
.ach-fill.high, .ind-fill.high { background: #15803d !important; }
.ach-fill.mid,  .ind-fill.mid  { background: #b45309 !important; }
.ach-fill.low,  .ind-fill.low  { background: #b91c1c !important; }

/* -- Prose text -- */
.prose-text  { font-size: 12px !important; color: #374151 !important; line-height: 1.6 !important; }
.prose-empty { font-size: 11px !important; color: #9ca3af !important; font-style: italic !important; }

/* -- Two-column grids collapse to single column for print -- */
.detail-grid-2, .guide-grid, .params-grid {
    display: block !important;
}
.detail-grid-2 > *, .guide-grid > *, .params-grid > * {
    width: 100% !important;
    margin-bottom: 14px !important;
}

/* -- Page break helpers -- */
.page-break        { page-break-after: always !important; }
h1, h2, h3, h4     { page-break-after: avoid !important; }
tr, .panel, .card  { page-break-inside: avoid !important; }

/* -- Report meta header injected at top of PDF -- */
.pdf-meta-bar {
    background: #0f2942;
    color: #ffffff;
    padding: 14px 20px 12px;
    margin-bottom: 20px;
    font-family: "DejaVu Sans", Arial, sans-serif;
}
.pdf-meta-bar h1  { font-size: 18px; font-weight: 800; margin: 0 0 4px; color: #ffffff; }
.pdf-meta-bar p   { font-size: 11px; opacity: .8; margin: 0; color: #ffffff; }
.pdf-meta-divider { border: none; border-top: 2px solid #e5e7eb; margin: 0 0 20px; }
</style>';

    /* -- Inject meta bar + CSS into the fetched HTML -- */
    $report_labels = [
        'program_summary'      => 'Program Summary Report',
        'project_summary'      => 'Project Summary Report',
        'participant_report'   => 'Participants Analysis Report',
        'indicator_performance'=> 'Indicator Performance Report',
        'events_report'        => 'Events & Training Report',
        'hub_operations'       => 'Hub Operations Report',
        'donor_report'         => 'Donor Report',
        'pwd_report'           => 'PWD Statistics Report',
        'quarterly_report'     => 'Quarterly Performance Report',
        'annual_report'        => 'Annual Report',
    ];
    $report_label = $report_labels[$report_type] ?? ucwords(str_replace('_', ' ', $report_type));

    $meta_bar = '
<div class="pdf-meta-bar">
    <h1>' . htmlspecialchars($report_label) . '</h1>
    <p>'
        . htmlspecialchars($scope_label)
        . ' &nbsp;�&nbsp; Period: ' . htmlspecialchars($start_date) . ' � ' . htmlspecialchars($end_date)
        . ' &nbsp;�&nbsp; Generated: ' . date('d M Y H:i')
        . ' &nbsp;�&nbsp; By: ' . htmlspecialchars($_SESSION['username'] ?? 'System')
    . '</p>
</div>
<hr class="pdf-meta-divider">';

    /* Inject CSS before </head>, then meta bar after <body> */
    if (stripos($html, '</head>') !== false) {
        $html = str_ireplace('</head>', $pdf_css . '</head>', $html);
    } else {
        $html = $pdf_css . $html;
    }
    if (stripos($html, '<body') !== false) {
        $html = preg_replace('/<body[^>]*>/i', '$0' . $meta_bar, $html, 1);
    } else {
        $html = $meta_bar . $html;
    }

    /* -- Configure Dompdf and render -- */
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', dirname(__DIR__));
    $options->set('isFontSubsettingEnabled', true);
    $options->set('isPhpEnabled', false);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
require_once __DIR__ . '/pdf-branding.php';
ims_brand_pdf($dompdf);

    /* -- File name -- */
    $filename = strtolower(str_replace(['_', ' '], '-', $report_type))
              . '_' . $start_date . '_to_' . $end_date . '.pdf';

    /* -- Save a copy to exports/ for the Recent Exports panel -- */
    $exports_dir = dirname(__DIR__) . '/exports';
    if (!is_dir($exports_dir)) {
        @mkdir($exports_dir, 0755, true);
    }
    if (is_dir($exports_dir) && is_writable($exports_dir)) {
        file_put_contents($exports_dir . '/' . $filename, $dompdf->output());
    }

    /* -- Flush any buffered output before streaming PDF headers -- */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

   
    $dompdf->stream($filename, ['Attachment' => false]);
    exit();
}


$redirect_params = http_build_query(array_filter([
    'type'       => $report_type,
    'start_date' => $start_date,
    'end_date'   => $end_date,
    'project_id' => $project_id,
    'program_id' => $program_id,
    'format'     => $format,
], fn($v) => $v !== null && $v !== ''));

header("Location: ../view-report?{$redirect_params}");
exit();