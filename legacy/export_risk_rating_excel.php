<?php
declare(strict_types=1);

session_start();
require_once 'includes/config.php';

if (!ims_allowed('risk_ratings.export', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer'], 'Export risk ratings to Excel')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$applicationId = (int)($_GET['application_id'] ?? $_GET['id'] ?? 0);

if ($applicationId <= 0) {
    $_SESSION['error'] = 'Invalid application ID.';
    header('Location: manage_risk_ratings');
    exit();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function fetchOneAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $err);
    }

    $result = $stmt->get_result();
    $row = $result ? ($result->fetch_assoc() ?: []) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row;
}

function fetchAllAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $err);
    }

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();
    return $rows;
}

function findSectionScore(array $sections, string $pillarKey): float
{
    foreach ($sections as $section) {
        if (strtolower(trim((string)($section['pillar_key'] ?? ''))) === strtolower($pillarKey)) {
            return (float)($section['rating_score'] ?? 0);
        }
    }
    return 0.0;
}

function safeFilename(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9\-_ ]+/', '', $name);
    $name = trim((string)$name);
    return $name !== '' ? $name : 'risk-rating-report';
}

try {
    $app = fetchOneAssoc($conn, "
        SELECT
            a.application_id,
            a.opportunity_id,
            a.startup_name,
            a.contact_person,
            a.email,
            a.phone,
            rr.id AS risk_rating_id,
            rr.report_title,
            rr.overall_rating,
            rr.overall_rating_label,
            rr.recommendation,
            rr.executive_summary,
            rr.introduction_notes,
            rr.next_steps,
            rr.updated_at,
            u.name AS prepared_by_name
        FROM applications a
        LEFT JOIN risk_ratings rr
            ON rr.application_id = a.application_id
        LEFT JOIN users u
            ON u.id = rr.prepared_by
        WHERE a.application_id = ?
        LIMIT 1
    ", 'i', [$applicationId]);

    if (empty($app)) {
        throw new RuntimeException('Application not found.');
    }

    if (empty($app['risk_rating_id'])) {
        throw new RuntimeException('No risk rating record found for this application.');
    }

    $riskRatingId = (int)$app['risk_rating_id'];

    $sections = fetchAllAssoc($conn, "
        SELECT pillar_key, pillar_label, rating_score, rating_label, key_findings, recommendations, follow_up_actions, notes
        FROM risk_rating_sections
        WHERE risk_rating_id = ?
        ORDER BY FIELD(pillar_key, 'governance','operational','delivery','fiduciary','safeguarding','reputational'), id ASC
    ", 'i', [$riskRatingId]);

    $governance   = findSectionScore($sections, 'governance');
    $operational  = findSectionScore($sections, 'operational');
    $delivery     = findSectionScore($sections, 'delivery');
    $fiduciary    = findSectionScore($sections, 'fiduciary');
    $safeguarding = findSectionScore($sections, 'safeguarding');
    $reputational = findSectionScore($sections, 'reputational');

    $totalScore = $governance + $operational + $delivery + $fiduciary + $safeguarding + $reputational;

    $preparedBy = trim((string)($app['prepared_by_name'] ?? ''));
    if ($preparedBy === '') {
        $preparedBy = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'System User'));
    }

    $commentParts = [];
    if (!empty($app['recommendation'])) {
        $commentParts[] = 'Recommendation: ' . trim((string)$app['recommendation']);
    }
    if (!empty($app['overall_rating_label'])) {
        $commentParts[] = 'Overall Risk: ' . trim((string)$app['overall_rating_label']);
    }
    if (!empty($app['executive_summary'])) {
        $commentParts[] = trim((string)$app['executive_summary']);
    }

    $comments = trim(implode(' | ', $commentParts));
    if ($comments === '') {
        $comments = 'Risk rating export for ' . ((string)($app['startup_name'] ?? 'Startup'));
    }

    $templateCandidates = [
        __DIR__ . '/uploads/templates/DD - Risk Rating Report.xlsx',
        __DIR__ . '/templates/DD - Risk Rating Report.xlsx',
        __DIR__ . '/DD - Risk Rating Report.xlsx',
    ];

    $templatePath = '';
    foreach ($templateCandidates as $candidate) {
        if (is_file($candidate)) {
            $templatePath = $candidate;
            break;
        }
    }

    if ($templatePath === '') {
        throw new RuntimeException(
            'Excel template not found. Put "DD - Risk Rating Report.xlsx" in /uploads/templates/ or /templates/.'
        );
    }

    $spreadsheet = IOFactory::load($templatePath);
    $sheet = $spreadsheet->getSheet(0);

    if (!$sheet instanceof Worksheet) {
        throw new RuntimeException('Unable to open template worksheet.');
    }

    $startRow = 2;
    $insertRow = $startRow;

    $highestRow = $sheet->getHighestDataRow();
    for ($r = $startRow; $r <= max($highestRow, $startRow); $r++) {
        $entityName = trim((string)$sheet->getCell('B' . $r)->getValue());
        if ($entityName === '') {
            $insertRow = $r;
            break;
        }
        $insertRow = $r + 1;
    }

    if ($insertRow > $startRow) {
        $sheet->insertNewRowBefore($insertRow, 1);
        $sheet->duplicateStyle($sheet->getStyle($insertRow - 1), $sheet->getStyle('A' . $insertRow . ':K' . $insertRow));
    }

    $sheet->setCellValue('A' . $insertRow, $insertRow - 1);
    $sheet->setCellValue('B' . $insertRow, (string)($app['startup_name'] ?? ''));
    $sheet->setCellValue('C' . $insertRow, $preparedBy);
    $sheet->setCellValue('D' . $insertRow, $governance);
    $sheet->setCellValue('E' . $insertRow, $operational);
    $sheet->setCellValue('F' . $insertRow, $delivery);
    $sheet->setCellValue('G' . $insertRow, $fiduciary);
    $sheet->setCellValue('H' . $insertRow, $safeguarding);
    $sheet->setCellValue('I' . $insertRow, $reputational);
    $sheet->setCellValue('J' . $insertRow, '=SUM(D' . $insertRow . ':I' . $insertRow . ')');
    $sheet->setCellValue('K' . $insertRow, $comments);

    foreach (['D','E','F','G','H','I','J'] as $col) {
        $sheet->getStyle($col . $insertRow)->getNumberFormat()->setFormatCode('0.00');
    }

    $exportDir = __DIR__ . '/uploads/risk_rating_reports/excel/';
    if (!is_dir($exportDir) && !mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
        throw new RuntimeException('Failed to create export directory.');
    }

    $startupName = safeFilename((string)($app['startup_name'] ?? 'startup'));
    $fileName = 'Risk_Rating_Report_' . $startupName . '_' . date('Ymd_His') . '.xlsx';
    $filePath = $exportDir . $fileName;

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($filePath);

    if (!headers_sent()) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit();
    }

    throw new RuntimeException('Unable to stream file to browser.');

} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: risk_rating?application_id=' . $applicationId);
    exit();
}