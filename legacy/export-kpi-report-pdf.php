<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KPI report export (PDF)
|--------------------------------------------------------------------------
|
| Called from kpi-reports.php:
|   export-kpi-report-pdf.php?year=2026&department=0&user=12
|
| Uses the current `kpis` schema (fiscal_year / department_id / user_id /
| category_id, quarterly targets/actuals/achievement, overall_achievement)
| and the same filter + visibility rules as kpi-reports.php: reviewers may
| export any user's KPIs, everyone else only their own. Rendered with
| Dompdf (the previous python/reportlab version read columns that no longer
| exist and needed python3 on the server).
|
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_login('login');

$session_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_hr = ims_allowed('kpi_reports.export_all', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR'], 'Export KPI reports for everyone (not just their own)');

$filter_year = isset($_GET['year']) && (int)$_GET['year'] > 0 ? (int)$_GET['year'] : (int)date('Y');
$filter_department = isset($_GET['department']) ? max(0, (int)$_GET['department']) : 0;
$filter_user = $is_hr
    ? (isset($_GET['user']) ? max(0, (int)$_GET['user']) : 0)
    : $session_user_id;

$where = ['k.fiscal_year = ?'];
$types = 'i';
$params = [$filter_year];

if ($filter_department > 0) {
    $where[] = 'k.department_id = ?';
    $types .= 'i';
    $params[] = $filter_department;
}

if ($filter_user > 0) {
    $where[] = 'k.user_id = ?';
    $types .= 'i';
    $params[] = $filter_user;
}

$sql = 'SELECT k.*, c.category_name, d.department_name, u.full_name AS owner_name
        FROM kpis k
        LEFT JOIN kpi_categories c ON k.category_id = c.category_id
        LEFT JOIN departments d ON k.department_id = d.department_id
        LEFT JOIN users u ON k.user_id = u.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY u.full_name, c.category_name, k.kpi_title';

$kpis = [];
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && ($row = $result->fetch_assoc())) {
        $kpis[] = $row;
    }

    $stmt->close();
}

// Filter labels
$department_label = 'All departments';
if ($filter_department > 0) {
    $s = $conn->prepare('SELECT department_name FROM departments WHERE department_id = ? LIMIT 1');
    if ($s) {
        $s->bind_param('i', $filter_department);
        $s->execute();
        $department_label = (string)($s->get_result()->fetch_assoc()['department_name'] ?? $department_label);
        $s->close();
    }
}

$user_label = 'All staff';
if ($filter_user > 0) {
    $s = $conn->prepare('SELECT full_name FROM users WHERE user_id = ? LIMIT 1');
    if ($s) {
        $s->bind_param('i', $filter_user);
        $s->execute();
        $user_label = (string)($s->get_result()->fetch_assoc()['full_name'] ?? 'User #' . $filter_user);
        $s->close();
    }
}

// Summary statistics (same definitions as kpi-reports.php)
$total_kpis = count($kpis);
$status_counts = array_fill_keys(['Draft', 'Submitted', 'Under Review', 'Approved', 'Rejected', 'Completed'], 0);
$achievements = [];

foreach ($kpis as $kpi) {
    $status = (string)($kpi['status'] ?? '');
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
    if ($kpi['overall_achievement'] !== null) {
        $achievements[] = (float)$kpi['overall_achievement'];
    }
}

$avg_achievement = $achievements ? round(array_sum($achievements) / count($achievements), 1) : 0.0;

$e = static fn (mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$num = static fn (mixed $v, int $d = 2): string => $v === null || $v === '' ? '-' : number_format((float)$v, $d);
$pct = static fn (mixed $v): string => $v === null || $v === '' ? '-' : number_format((float)$v, 1) . '%';

$report_user = (string)($_SESSION['full_name'] ?? 'System');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>KPI Report <?= $e($filter_year) ?></title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #2c3e50; }
    h1 { font-size: 18px; color: #ff6b35; margin: 0 0 4px 0; }
    h2 { font-size: 12px; margin: 14px 0 6px 0; border-bottom: 1px solid #ff6b35; padding-bottom: 3px; }
    .meta td { padding: 2px 8px 2px 0; }
    .meta td.k { color: #7f8c8d; font-weight: bold; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #ff6b35; color: #fff; padding: 4px; text-align: left; font-size: 8.5px; }
    table.grid td { border-bottom: 1px solid #e5e7eb; padding: 4px; vertical-align: top; }
    .muted { color: #7f8c8d; }
    .footer { margin-top: 16px; text-align: center; color: #95a5a6; font-size: 8px; }
</style>
</head>
<body>
<h1>Key Performance Indicators Report</h1>
<table class="meta">
    <tr><td class="k">Fiscal year:</td><td><?= $e($filter_year) ?></td><td class="k">Department:</td><td><?= $e($department_label) ?></td></tr>
    <tr><td class="k">Staff:</td><td><?= $e($user_label) ?></td><td class="k">Generated:</td><td><?= $e(date('Y-m-d H:i')) ?> by <?= $e($report_user) ?></td></tr>
    <tr><td class="k">Total KPIs:</td><td><?= $total_kpis ?></td><td class="k">Avg. achievement:</td><td><?= $e(number_format($avg_achievement, 1)) ?>%</td></tr>
</table>

<h2>Status summary</h2>
<table class="grid">
    <tr><th>Status</th><th>Count</th><th>Share</th></tr>
    <?php foreach ($status_counts as $status => $count): ?>
    <tr>
        <td><?= $e($status) ?></td>
        <td><?= (int)$count ?></td>
        <td><?= $total_kpis > 0 ? $e(number_format($count / $total_kpis * 100, 1)) . '%' : '-' ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<h2>KPI details</h2>
<?php if (!$kpis): ?>
    <p class="muted">No KPIs match the selected filters.</p>
<?php else: ?>
<table class="grid">
    <tr>
        <th>KPI</th><th>Owner / Dept.</th><th>Category</th><th>Weight</th><th>Target</th>
        <th>Q1 (T / A)</th><th>Q2 (T / A)</th><th>Q3 (T / A)</th><th>Q4 (T / A)</th>
        <th>Overall</th><th>Status</th>
    </tr>
    <?php foreach ($kpis as $kpi): ?>
    <tr>
        <td>
            <strong><?= $e($kpi['kpi_title']) ?></strong>
            <?php if (!empty($kpi['measurement_criteria'])): ?><br><span class="muted"><?= $e($kpi['measurement_criteria']) ?></span><?php endif; ?>
        </td>
        <td><?= $e($kpi['owner_name'] ?? '-') ?><br><span class="muted"><?= $e($kpi['department_name'] ?? '') ?></span></td>
        <td><?= $e($kpi['category_name'] ?? '-') ?></td>
        <td><?= $pct($kpi['weight_percentage']) ?></td>
        <td><?= $num($kpi['target_value']) ?> <?= $e($kpi['unit_of_measure'] ?? '') ?></td>
        <?php foreach (['q1', 'q2', 'q3', 'q4'] as $q): ?>
        <td>
            <?= $num($kpi[$q . '_target']) ?> / <?= $num($kpi[$q . '_actual']) ?>
            <br><span class="muted"><?= $pct($kpi[$q . '_achievement']) ?> &middot; <?= $e($kpi[$q . '_status'] ?? '') ?></span>
        </td>
        <?php endforeach; ?>
        <td><?= $pct($kpi['overall_achievement']) ?></td>
        <td><?= $e($kpi['status']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<div class="footer">Generated by <?= $e($GLOBALS['IMS_SITE_BRANDING']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : 'IMS')) ?> | <?= $e(date('Y-m-d H:i:s')) ?></div>
</body>
</html>
<?php
$html = (string)ob_get_clean();

foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) {
    if (is_file($ims_autoload)) {
        require_once $ims_autoload;
        break;
    }
}

if (!class_exists(\Dompdf\Dompdf::class)) {
    $_SESSION['error'] = 'PDF export is not available on this server.';
    header('Location: kpi-reports');
    exit;
}

$options = new \Dompdf\Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
require_once __DIR__ . '/includes/pdf-branding.php';
ims_brand_pdf($dompdf);

if (function_exists('log_action')) {
    log_action(
        $session_user_id,
        'Export KPI Report',
        'kpis',
        null,
        sprintf('Exported KPI report PDF: year=%d department=%d user=%d (%d KPIs)', $filter_year, $filter_department, $filter_user, $total_kpis)
    );
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'KPI_Report_' . $filter_year . '_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
