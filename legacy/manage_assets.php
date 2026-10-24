<?php
ob_start();

$page_title = 'Manage Assets';
require_once 'includes/header.php';
require_once 'includes/manage-assets.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

$currentUserId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$currentRole   = (string)($user['role'] ?? ($_SESSION['role'] ?? ''));

$isAssetManager = ims_allowed('assets.manage', ['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer'], 'Approve, assign, return and delete assets');



function buildQuery(array $overrides = []): string
{
    return http_build_query(array_merge($_GET, $overrides));
}

function exportCsv(string $filename, array $headers, array $rows): never
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename={$filename}");
    $out = fopen('php://output', 'w');
    ims_fputcsv($out, $headers);
    foreach ($rows as $row) ims_fputcsv($out, $row);
    fclose($out);
    exit;
}

function exportPdf(string $filename, string $title, array $headers, array $rows): never
{
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }
    while (ob_get_level() > 0) ob_end_clean();

    $html = "
    <style>
        body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
        h2{color:#ff5722;margin-bottom:4px}
        .muted{color:#666;font-size:10px;margin-bottom:14px}
        table{width:100%;border-collapse:collapse}
        th{background:#ff5722;color:#fff;padding:7px;text-align:left}
        td{border:1px solid #ddd;padding:6px;vertical-align:top}
    </style>
    <h2>" . htmlspecialchars($title) . "</h2>
    <div class='muted'>Generated on " . date('d M Y, h:i A') . "</div>
    <table><thead><tr>";

    foreach ($headers as $header) $html .= '<th>' . htmlspecialchars($header) . '</th>';
    $html .= '</tr></thead><tbody>';

    if (empty($rows)) $html .= '<tr><td colspan="' . count($headers) . '">No records found.</td></tr>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) $html .= '<td>' . nl2br(htmlspecialchars((string)$cell)) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
require_once __DIR__ . '/includes/pdf-branding.php';
ims_brand_pdf($dompdf);
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

handleAssetPostActions($conn, $currentUserId, $isAssetManager);

$data = getManageAssetsData($conn, $currentUserId, $isAssetManager);

/* ========================= SEARCH TERMS ========================= */
$assetSearch      = trim((string)($_GET['asset_search'] ?? ''));
$requestSearch    = trim((string)($_GET['request_search'] ?? ''));
$assignmentSearch = trim((string)($_GET['assignment_search'] ?? ''));

$assetPage      = max(1, (int)($_GET['asset_page'] ?? 1));
$requestPage    = max(1, (int)($_GET['request_page'] ?? 1));
$assignmentPage = max(1, (int)($_GET['assignment_page'] ?? 1));

$limit = 10;

/* ========================= ACTIVE TAB ========================= */
$activeTab = $_GET['tab'] ?? ($isAssetManager ? 'assets' : 'requests');
if (!$isAssetManager) $activeTab = in_array($activeTab, ['requests']) ? $activeTab : 'requests';

/* ========================= FILTER ARRAYS ========================= */
$assets = array_filter($data['assets'], function ($a) use ($assetSearch) {
    if ($assetSearch === '') return true;
    $haystack = strtolower(
        ($a['asset_code'] ?? '') . ' ' . ($a['asset_name'] ?? '') . ' ' .
        ($a['category_name'] ?? $a['category'] ?? '') . ' ' .
        ($a['serial_number'] ?? '') . ' ' . ($a['condition_status'] ?? '') . ' ' .
        ($a['availability_status'] ?? '')
    );
    return str_contains($haystack, strtolower($assetSearch));
});

$requests = array_filter($data['requests'], function ($r) use ($requestSearch) {
    if ($requestSearch === '') return true;
    $haystack = strtolower(
        ($r['staff_name'] ?? '') . ' ' . ($r['asset_name'] ?? '') . ' ' .
        ($r['category_name'] ?? '') . ' ' . ($r['request_reason'] ?? '') . ' ' .
        ($r['urgency_level'] ?? '') . ' ' . ($r['status'] ?? '')
    );
    return str_contains($haystack, strtolower($requestSearch));
});

$assignments = array_filter($data['assignments'], function ($a) use ($assignmentSearch) {
    if ($assignmentSearch === '') return true;
    $haystack = strtolower(
        ($a['asset_code'] ?? '') . ' ' . ($a['asset_name'] ?? '') . ' ' .
        ($a['staff_name'] ?? '') . ' ' . ($a['status'] ?? '') . ' ' .
        ($a['assigned_at'] ?? '')
    );
    return str_contains($haystack, strtolower($assignmentSearch));
});

/* ========================= EXPORTS ========================= */
$export = $_GET['export'] ?? '';

// Blank CSV for bulk import (columns match the Add Asset form).
if ($export === 'assets_template' && $isAssetManager) {
    asset_import_template($data['categories']);
}

if ($export === 'assets_csv' || $export === 'assets_pdf') {
   $headers = [
    'Code',
    'Asset',
    'Category',
    'Serial',
    'Condition',
    'Status',
    'Purchase Cost',
    'Dep. %',
    'Disposed Cost'
];
   $rows = array_map(fn($a) => [
    $a['asset_code'] ?? '',
    $a['asset_name'] ?? '',
    $a['category_name'] ?? $a['category'] ?? '',
    $a['serial_number'] ?? '',
    $a['condition_status'] ?? '',
    $a['availability_status'] ?? '',
    number_format((float)($a['purchase_cost'] ?? 0)),
    number_format((float)($a['depreciation_rate'] ?? 0), 2) . '%',
    number_format((float)($a['current_value'] ?? 0)),
], $assets);
    $export === 'assets_csv'
        ? exportCsv('assets_inventory.csv', $headers, $rows)
        : exportPdf('assets_inventory.pdf', 'Assets Inventory', $headers, $rows);
}

if ($export === 'requests_csv' || $export === 'requests_pdf') {
    $headers = ['Staff', 'Asset', 'Category', 'Reason', 'Urgency', 'Status', 'Requested'];
    $rows = array_map(fn($r) => [
        $r['staff_name'] ?? '', $r['asset_name'] ?? '', $r['category_name'] ?? '',
        $r['request_reason'] ?? '', $r['urgency_level'] ?? '',
        $r['status'] ?? '', $r['requested_at'] ?? '',
    ], $requests);
    $export === 'requests_csv'
        ? exportCsv('asset_requests.csv', $headers, $rows)
        : exportPdf('asset_requests.pdf', 'Asset Requests', $headers, $rows);
}

if ($export === 'assignments_csv' || $export === 'assignments_pdf') {
    $headers = ['Asset Code', 'Asset', 'Staff', 'Status', 'Assigned'];
    $rows = array_map(fn($a) => [
        $a['asset_code'] ?? '', $a['asset_name'] ?? '', $a['staff_name'] ?? '',
        $a['status'] ?? '', $a['assigned_at'] ?? '',
    ], $assignments);
    $export === 'assignments_csv'
        ? exportCsv('asset_assignments.csv', $headers, $rows)
        : exportPdf('asset_assignments.pdf', 'Asset Assignments', $headers, $rows);
}

/* ========================= PAGINATION ========================= */
$totalAssetRows      = count($assets);
$totalRequestRows    = count($requests);
$totalAssignmentRows = count($assignments);

$totalAssetPages      = max(1, (int)ceil($totalAssetRows / $limit));
$totalRequestPages    = max(1, (int)ceil($totalRequestRows / $limit));
$totalAssignmentPages = max(1, (int)ceil($totalAssignmentRows / $limit));

$data['assets']      = array_slice(array_values($assets),      ($assetPage - 1)      * $limit, $limit);
$data['requests']    = array_slice(array_values($requests),    ($requestPage - 1)    * $limit, $limit);
$data['assignments'] = array_slice(array_values($assignments), ($assignmentPage - 1) * $limit, $limit);

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
$import_report = $_SESSION['asset_import_report'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['asset_import_report']);
?>
<link rel="stylesheet" href="css/assets.css">

<style>
/* -- CSV import -- */
.import-steps { list-style: none; counter-reset: step; margin: 0 0 18px; padding: 0; display: grid; gap: 10px; }
.import-steps li { counter-increment: step; display: flex; align-items: center; gap: 14px; padding: 12px 14px; border: 1px solid var(--border, #e5e7eb); border-radius: var(--radius-md, 12px); background: var(--surface-hover, #f9fafb); }
.import-steps li::before { content: counter(step); flex: none; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; background: var(--brand-600, #ea580c); color: #fff; font-weight: 700; font-size: 13px; }
.import-step-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
.import-step-text span { color: var(--ink-400, #4b5563); }
.import-step-text code { font-size: 12px; padding: 1px 5px; border-radius: 4px; background: var(--ink-50, #f3f4f6); }
.import-steps .btn { flex: none; }
.import-option { display: flex; gap: 10px; align-items: flex-start; margin: 4px 0 14px; font-size: 13px; color: var(--ink-500, #374151); cursor: pointer; }
.import-option input { width: 18px; height: 18px; margin-top: 1px; flex: none; accent-color: var(--brand-600, #ea580c); }
.import-report { margin: 0 0 16px; padding: 14px 16px; border: 1px solid var(--amber-border, #fde68a); border-left: 4px solid var(--amber-fg, #b45309); border-radius: var(--radius-md, 12px); background: var(--amber-bg, #fffbeb); color: var(--ink-600, #2f3744); font-size: 13px; }
.import-report-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
.import-report ul { margin: 0; padding-left: 20px; max-height: 220px; overflow-y: auto; }
.import-report li + li { margin-top: 3px; }
@media (max-width: 640px) {
    .import-steps li { flex-wrap: wrap; }
    .import-steps .btn { width: 100%; justify-content: center; }
}
</style>

<div class="assets-wrap">

    <!-- Page Hero -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-laptop-house"></i> Manage Assets</h1>
            <p><?= $isAssetManager ? 'Approve, reject, assign, return, and delete asset records.' : 'Request assets and track your requests.' ?></p>
        </div>

        <div class="hero-actions">
            <?php if ($isAssetManager): ?>
                <button type="button" class="btn btn-primary" onclick="openModal('addAssetModal')">
                    <i class="fas fa-plus-circle"></i> Add Asset
                </button>
                <button type="button" class="btn" onclick="openModal('importAssetsModal')">
                    <i class="fas fa-file-import"></i> Import CSV
                </button>
                <a href="asset_categories" class="btn">
                    <i class="fas fa-tags"></i> Asset Categories
                </a>
            <?php endif; ?>
            <a href="asset_maintenance" class="btn">
                <i class="fas fa-screwdriver-wrench"></i> Asset Maintenance
            </a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($import_report && !empty($import_report['errors'])): ?>
        <div class="import-report" role="region" aria-label="Import problems">
            <div class="import-report-head">
                <strong><i class="fas fa-list-check" aria-hidden="true"></i>
                    <?= e($import_report['file']) ?>:
                    <?= (int)$import_report['imported'] ?> imported,
                    <?= (int)$import_report['skipped'] ?> row<?= (int)$import_report['skipped'] === 1 ? '' : 's' ?> with problems
                </strong>
                <?php if ((int)$import_report['imported'] === 0): ?>
                    <button type="button" class="btn btn-sm btn-primary" onclick="openModal('importAssetsModal')">
                        <i class="fas fa-file-import" aria-hidden="true"></i> Upload corrected file
                    </button>
                <?php endif; ?>
            </div>
            <ul>
                <?php foreach ($import_report['errors'] as $importError): ?>
                    <li><?= e($importError) ?></li>
                <?php endforeach; ?>
                <?php if ((int)$import_report['more'] > 0): ?>
                    <li><em>...and <?= (int)$import_report['more'] ?> more.</em></li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-boxes-stacked" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total Assets</span>
                <span class="stat-value"><?= number_format($data['stats']['total_assets']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Available</span>
                <span class="stat-value"><?= number_format($data['stats']['available_assets']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-user-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Assigned</span>
                <span class="stat-value"><?= number_format($data['stats']['assigned_assets']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Pending Requests</span>
                <span class="stat-value"><?= number_format($data['stats']['pending_requests']) ?></span>
            </div>
        </div>
    </div>

    <!-- -------------- TAB NAVIGATION -------------- -->
    <nav class="tab-nav" role="tablist">
        <?php if ($isAssetManager): ?>
            <a href="?<?= buildQuery(['tab' => 'assets']) ?>"
               class="tab-btn <?= $activeTab === 'assets' ? 'active' : '' ?>"
               role="tab" aria-selected="<?= $activeTab === 'assets' ? 'true' : 'false' ?>">
                <i class="fas fa-desktop"></i>
                Assets Inventory
                <span class="tab-badge"><?= number_format($totalAssetRows) ?></span>
            </a>
        <?php endif; ?>

        <a href="?<?= buildQuery(['tab' => 'requests']) ?>"
           class="tab-btn <?= $activeTab === 'requests' ? 'active' : '' ?>"
           role="tab" aria-selected="<?= $activeTab === 'requests' ? 'true' : 'false' ?>">
            <i class="fas fa-list-check"></i>
            <?= $isAssetManager ? 'All Requests' : 'My Requests' ?>
            <span class="tab-badge"><?= number_format($totalRequestRows) ?></span>
        </a>

        <?php if ($isAssetManager): ?>
            <a href="?<?= buildQuery(['tab' => 'assignments']) ?>"
               class="tab-btn <?= $activeTab === 'assignments' ? 'active' : '' ?>"
               role="tab" aria-selected="<?= $activeTab === 'assignments' ? 'true' : 'false' ?>">
                <i class="fas fa-hand-holding-medical"></i>
                Assignments
                <span class="tab-badge"><?= number_format($totalAssignmentRows) ?></span>
            </a>
        <?php endif; ?>
    </nav>

    <!-- -------------- TAB: ASSETS INVENTORY -------------- -->
    <?php if ($isAssetManager): ?>
    <div class="tab-panel <?= $activeTab === 'assets' ? 'active' : '' ?>" id="tab-assets">
        <div class="panel">
            <div class="panel-body table-wrap">
                <form method="GET">
                    <input type="hidden" name="tab" value="assets">
                    <div class="tab-toolbar filters-bar">
                        <div class="tab-toolbar-left filters-grow">
                            <input name="asset_search" class="form-control" value="<?= e($assetSearch) ?>" placeholder="Search assets...">
                        </div>
                        <div class="tab-toolbar-right filters-actions">
                            <button class="btn btn-dark"><i class="fas fa-search"></i> Search</button>
                            <a href="?tab=assets" class="btn btn-gray">Reset</a>
                            <a href="?<?= buildQuery(['export' => 'assets_csv', 'tab' => 'assets']) ?>" class="btn btn-green">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                            <a href="?<?= buildQuery(['export' => 'assets_pdf', 'tab' => 'assets']) ?>" class="btn btn-red">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                        </div>
                    </div>
                </form>

                <table class="table">
                    <thead>
                       <tr>
    <th>Asset</th>
    <th>Category</th>
    <th>Serial</th>
    <th>Condition</th>
    <th>Status</th>
    <th>Cost</th>
    <th>Dep. %</th>
    <th>Disposed</th>
    <th>Actions</th>
</tr>
                    </thead>
                    <tbody>
                        <?php if (empty($data['assets'])): ?>
                            <tr><td colspan="9">No assets found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($data['assets'] as $asset): ?>
                            <?php
                            $statusClass = match ($asset['availability_status']) {
                                'Available'    => 'badge-available',
                                'Assigned'     => 'badge-assigned',
                                'Under Repair' => 'badge-repair',
                                default        => 'badge-disposed',
                            };
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e($asset['asset_name']) ?></strong><br>
                                    <span class="note"><?= e($asset['asset_code']) ?></span>
                                </td>
                                <td><?= e($asset['category_name'] ?? $asset['category'] ?? '-') ?></td>
                                <td><?= e($asset['serial_number'] ?: '-') ?></td>
                                <td><?= e($asset['condition_status']) ?></td>
                                <td>
    <span class="badge <?= $statusClass ?>">
        <?= e($asset['availability_status']) ?>
    </span>
</td>

<td>UGX <?= number_format((float)($asset['purchase_cost'] ?? 0)) ?></td>

<td>
    <?= number_format((float)($asset['depreciation_rate'] ?? 0), 2) ?>%
</td>

<td>
    UGX <?= number_format((float)($asset['current_value'] ?? 0)) ?>
</td>
                                <td>
    <div class="actions">
        <button type="button" class="btn btn-sm btn-gray"
            title="Edit Asset"
            onclick='openEditAssetModal(<?= json_encode($asset, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
            <i class="fas fa-edit"></i>
        </button>

        <button type="button" class="btn btn-sm btn-soft"
            title="Depreciation"
            onclick='openDepreciationModal(<?= json_encode($asset, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
            <i class="fas fa-chart-line"></i>
        </button>

        <button type="button" class="btn btn-sm btn-red"
            title="Offload Asset"
            onclick='openOffloadModal(<?= json_encode($asset, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
            <i class="fas fa-box-open"></i>
        </button>

        <button type="button" class="btn btn-sm btn-soft"
            title="Request Asset"
            onclick='openRequestAssetPrefill(<?= json_encode($asset, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
            <i class="fas fa-paper-plane"></i>
        </button>

        <?php if (($asset['offload_status'] ?? 'Active') !== 'Offloaded'): ?>
            <form method="POST" onsubmit="return confirm('Delete this asset?');">
                <input type="hidden" name="action" value="delete_asset">
                <input type="hidden" name="asset_id" value="<?= (int)$asset['asset_id'] ?>">
                <button class="btn btn-sm btn-red" title="Delete Asset">
                    <i class="fas fa-trash"></i>
                </button>
            </form>
        <?php endif; ?>
    </div>
</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalAssetPages > 1): ?>
                    <div class="modal-actions" style="justify-content:center;">
                        <?php if ($assetPage > 1): ?>
                            <a class="btn btn-gray" href="?<?= buildQuery(['asset_page' => $assetPage - 1, 'tab' => 'assets']) ?>">Previous</a>
                        <?php endif; ?>
                        <span class="btn btn-soft">Page <?= $assetPage ?> of <?= $totalAssetPages ?></span>
                        <?php if ($assetPage < $totalAssetPages): ?>
                            <a class="btn btn-gray" href="?<?= buildQuery(['asset_page' => $assetPage + 1, 'tab' => 'assets']) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- -------------- TAB: ASSET REQUESTS -------------- -->
    <div class="tab-panel <?= $activeTab === 'requests' ? 'active' : '' ?>" id="tab-requests">
        <div class="panel">
            <div class="panel-body table-wrap">
                <form method="GET">
                    <input type="hidden" name="tab" value="requests">
                    <div class="tab-toolbar filters-bar">
                        <div class="tab-toolbar-left filters-grow">
                            <input name="request_search" class="form-control" value="<?= e($requestSearch) ?>" placeholder="Search requests...">
                        </div>
                        <div class="tab-toolbar-right filters-actions">
                            <button class="btn btn-dark"><i class="fas fa-search"></i> Search</button>
                            <a href="?tab=requests" class="btn btn-gray">Reset</a>
                            <a href="?<?= buildQuery(['export' => 'requests_csv', 'tab' => 'requests']) ?>" class="btn btn-green">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                            <a href="?<?= buildQuery(['export' => 'requests_pdf', 'tab' => 'requests']) ?>" class="btn btn-red">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                        </div>
                    </div>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th>Requested Asset</th>
                            <th>Reason</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($data['requests'])): ?>
                            <tr><td colspan="7">No requests found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($data['requests'] as $request): ?>
                            <?php
                            $rqClass = match ($request['status']) {
                                'Pending'            => 'badge-pending',
                                'Approved', 'Issued' => 'badge-issued',
                                'Rejected'           => 'badge-rejected',
                                'Returned'           => 'badge-returned',
                                default              => 'badge-pending',
                            };
                            ?>
                            <tr>
                                <td><?= e($request['staff_name']) ?></td>
                                <td>
                                    <strong><?= e($request['asset_name']) ?></strong><br>
                                    <span class="note"><?= e($request['category_name'] ?? '-') ?></span>
                                </td>
                                <td><?= nl2br(e($request['request_reason'])) ?></td>
                                <td><?= e($request['urgency_level']) ?></td>
                                <td>
                                    <span class="badge <?= $rqClass ?>"><?= e($request['status']) ?></span>
                                </td>
                                <td>
                                    <?= !empty($request['requested_at']) ? date('d M Y H:i', strtotime($request['requested_at'])) : '-' ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <?php if ($isAssetManager && $request['status'] === 'Pending'): ?>
                                            <button class="btn btn-sm btn-green" type="button"
                                                onclick='openApprovalModal(<?= json_encode($request, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($isAssetManager): ?>
                                            <form method="POST" onsubmit="return confirm('Delete this request?');">
                                                <input type="hidden" name="action" value="delete_request">
                                                <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                                                <button class="btn btn-sm btn-red"><i class="fas fa-trash"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="note"><?= e($request['approval_notes'] ?: '-') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalRequestPages > 1): ?>
                    <div class="modal-actions" style="justify-content:center;">
                        <?php if ($requestPage > 1): ?>
                            <a class="btn btn-gray" href="?<?= buildQuery(['request_page' => $requestPage - 1, 'tab' => 'requests']) ?>">Previous</a>
                        <?php endif; ?>
                        <span class="btn btn-soft">Page <?= $requestPage ?> of <?= $totalRequestPages ?></span>
                        <?php if ($requestPage < $totalRequestPages): ?>
                            <a class="btn btn-gray" href="?<?= buildQuery(['request_page' => $requestPage + 1, 'tab' => 'requests']) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- -------------- TAB: ASSIGNMENTS -------------- -->
    <?php if ($isAssetManager): ?>
    <div class="tab-panel <?= $activeTab === 'assignments' ? 'active' : '' ?>" id="tab-assignments">
        <div class="panel">
            <div class="panel-body table-wrap">
                <form method="GET">
                    <input type="hidden" name="tab" value="assignments">
                    <div class="tab-toolbar filters-bar">
                        <div class="tab-toolbar-left filters-grow">
                            <input name="assignment_search" class="form-control" value="<?= e($assignmentSearch) ?>" placeholder="Search assignments...">
                        </div>
                        <div class="tab-toolbar-right filters-actions">
                            <button class="btn btn-dark"><i class="fas fa-search"></i> Search</button>
                            <a href="?tab=assignments" class="btn btn-gray">Reset</a>
                            <a href="?<?= buildQuery(['export' => 'assignments_csv', 'tab' => 'assignments']) ?>" class="btn btn-green">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                            <a href="?<?= buildQuery(['export' => 'assignments_pdf', 'tab' => 'assignments']) ?>" class="btn btn-red">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                        </div>
                    </div>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Staff</th>
                            <th>Status</th>
                            <th>Assigned</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['assignments'] as $assignment): ?>
                            <tr>
                                <td>
                                    <strong><?= e($assignment['asset_name']) ?></strong><br>
                                    <span class="note"><?= e($assignment['asset_code']) ?></span>
                                </td>
                                <td><?= e($assignment['staff_name']) ?></td>
                                <td>
                                    <span class="badge <?= $assignment['status'] === 'Assigned' ? 'badge-assigned' : 'badge-returned' ?>">
                                        <?= e($assignment['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= !empty($assignment['assigned_at']) ? date('d M Y H:i', strtotime($assignment['assigned_at'])) : '-' ?>
                                </td>
                                <td>
                                    <?php if ($assignment['status'] === 'Assigned'): ?>
                                        <button class="btn btn-sm btn-green"
                                            onclick='openReturnModal(<?= json_encode($assignment, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                            <i class="fas fa-undo"></i> Return
                                        </button>
                                    <?php else: ?>
                                        <span class="note">Returned</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($data['assignments'])): ?>
                            <tr><td colspan="5">No assignments found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($totalAssignmentPages > 1): ?>
                    <div class="modal-actions" style="justify-content:center;">
                        <?php if ($assignmentPage > 1): ?>
                            <a class="btn btn-gray" href="?<?= buildQuery(['assignment_page' => $assignmentPage - 1, 'tab' => 'assignments']) ?>">Previous</a>
                        <?php endif; ?>
                        <span class="btn btn-soft">Page <?= $assignmentPage ?> of <?= $totalAssignmentPages ?></span>
                        <?php if ($assignmentPage < $totalAssignmentPages): ?>
                            <a class="btn btn-gray" href="?<?= buildQuery(['assignment_page' => $assignmentPage + 1, 'tab' => 'assignments']) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.assets-wrap -->

<!-- -------------- MODALS -------------- -->

<!-- ADD ASSET MODAL -->
<div class="modal" id="addAssetModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Add Asset</h3>
            <button class="modal-close" onclick="closeModal('addAssetModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_asset">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Asset Code *</label>
                        <input name="asset_code" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Asset Name *</label>
                        <input name="asset_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($data['categories'] as $category): ?>
                                <option value="<?= e($category['category_name']) ?>"><?= e($category['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Brand</label>
                        <input name="brand" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Model</label>
                        <input name="model" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Serial Number</label>
                        <input name="serial_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Cost</label>
                        <input type="number" step="0.01" min="0" name="purchase_cost" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Condition</label>
                        <select name="condition_status" class="form-control">
                            <option>New</option>
                            <option selected>Good</option>
                            <option>Fair</option>
                            <option>Damaged</option>
                            <option>Disposed</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('addAssetModal')">Cancel</button>
                    <button class="btn btn-dark"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($isAssetManager): ?>
<!-- IMPORT ASSETS MODAL -->
<div class="modal" id="importAssetsModal" role="dialog" aria-modal="true" aria-labelledby="importAssetsTitle">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="importAssetsTitle"><i class="fas fa-file-import" aria-hidden="true"></i> Import Assets from CSV</h3>
            <button type="button" class="modal-close" onclick="closeModal('importAssetsModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <ol class="import-steps">
                <li>
                    <div class="import-step-text">
                        <strong>Download the template</strong>
                        <span>It has the same columns as the Add Asset form, an example row and notes listing your categories.</span>
                    </div>
                    <a href="?export=assets_template" class="btn btn-dark">
                        <i class="fas fa-download" aria-hidden="true"></i> Download template
                    </a>
                </li>
                <li>
                    <div class="import-step-text">
                        <strong>Fill in one asset per row</strong>
                        <span>
                            Required: <code>asset_code</code> (unique), <code>asset_name</code>, <code>category</code>.
                            Dates as YYYY-MM-DD, costs as plain numbers. Rows starting with # are ignored.
                            In Excel, save as <em>CSV UTF-8 (Comma delimited)</em>.
                        </span>
                    </div>
                </li>
                <li>
                    <div class="import-step-text">
                        <strong>Upload the file</strong>
                        <span>Every row is checked first. Up to <?= (int)ASSET_IMPORT_MAX_ROWS ?> assets, 2 MB per file.</span>
                    </div>
                </li>
            </ol>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_assets">
                <div class="form-group">
                    <label class="form-label" for="assets_file">CSV file *</label>
                    <input type="file" name="assets_file" id="assets_file" class="form-control" accept=".csv,text/csv" required>
                </div>
                <label class="import-option">
                    <input type="checkbox" name="skip_invalid" value="1">
                    <span>Skip rows with errors and import the rest. If unticked, nothing is imported until every row is valid.</span>
                </label>
                <?php if (empty($data['categories'])): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                        There are no asset categories yet. <a href="asset_categories">Add categories</a> before importing.
                    </div>
                <?php endif; ?>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('importAssetsModal')">Cancel</button>
                    <button type="submit" class="btn btn-dark"><i class="fas fa-upload" aria-hidden="true"></i> Import assets</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- EDIT ASSET MODAL -->
<div class="modal" id="editAssetModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Edit Asset</h3>
            <button class="modal-close" onclick="closeModal('editAssetModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_asset">
                <input type="hidden" name="asset_id" id="edit_asset_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Asset Code *</label>
                        <input name="asset_code" id="edit_asset_code" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Asset Name *</label>
                        <input name="asset_name" id="edit_asset_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" id="edit_category" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($data['categories'] as $category): ?>
                                <option value="<?= e($category['category_name']) ?>"><?= e($category['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Brand</label>
                        <input name="brand" id="edit_brand" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Model</label>
                        <input name="model" id="edit_model" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Serial Number</label>
                        <input name="serial_number" id="edit_serial_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" name="purchase_date" id="edit_purchase_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Cost</label>
                        <input type="number" step="0.01" min="0" name="purchase_cost" id="edit_purchase_cost" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Condition</label>
                        <select name="condition_status" id="edit_condition_status" class="form-control">
                            <option>New</option>
                            <option>Good</option>
                            <option>Fair</option>
                            <option>Damaged</option>
                            <option>Disposed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Availability</label>
                        <select name="availability_status" id="edit_availability_status" class="form-control">
                            <option>Available</option>
                            <option>Assigned</option>
                            <option>Under Repair</option>
                            <option>Disposed</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('editAssetModal')">Cancel</button>
                    <button class="btn btn-dark"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- REQUEST ASSET MODAL -->
<div class="modal" id="requestAssetModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Request Asset</h3>
            <button class="modal-close" onclick="closeModal('requestAssetModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="request_asset">
                <input type="hidden" name="asset_id" id="request_asset_id" value="0">
                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Asset Name *</label>
                        <input name="asset_name" id="request_asset_name" class="form-control" required>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Category *</label>
                        <select name="category_id" id="request_category_id" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($data['categories'] as $category): ?>
                                <option value="<?= (int)$category['category_id'] ?>"><?= e($category['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Urgency</label>
                        <select name="urgency_level" class="form-control">
                            <option>Low</option>
                            <option selected>Medium</option>
                            <option>High</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Reason *</label>
                        <textarea name="request_reason" class="form-control" required></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('requestAssetModal')">Cancel</button>
                    <button class="btn btn-dark"><i class="fas fa-paper-plane"></i> Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- APPROVE / REJECT MODAL -->
<div class="modal" id="approvalModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Approve / Reject Request</h3>
            <button class="modal-close" onclick="closeModal('approvalModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="process_request">
                <input type="hidden" name="request_id" id="approval_request_id">
                <p class="note">
                    <strong id="approval_staff_name"></strong> requested
                    <strong id="approval_asset_name"></strong>.
                </p>
                <div class="form-group full">
                    <label class="form-label">Decision</label>
                    <select name="decision" id="approval_decision" class="form-control" onchange="toggleApprovalAssetSelect(this.value)">
                        <option value="Approved">Approve and Assign</option>
                        <option value="Rejected">Reject</option>
                    </select>
                </div>
                <div class="form-group full" id="approval_asset_group">
                    <label class="form-label">Assign Available Asset</label>
                    <select name="asset_id" class="form-control">
                        <option value="">Select asset</option>
                        <?php foreach ($data['available_assets'] as $asset): ?>
                            <option value="<?= (int)$asset['asset_id'] ?>">
                                <?= e($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . ($asset['category_name'] ?? $asset['category'] ?? '-') . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group full">
                    <label class="form-label">Approval / Rejection Reason</label>
                    <textarea name="approval_notes" class="form-control"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('approvalModal')">Cancel</button>
                    <button class="btn btn-dark"><i class="fas fa-check-circle"></i> Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- RETURN MODAL -->
<div class="modal" id="returnModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Return Asset</h3>
            <button class="modal-close" onclick="closeModal('returnModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="return_asset">
                <input type="hidden" name="assignment_id" id="return_assignment_id">
                <p class="note">
                    Returning <strong id="return_asset_name"></strong>
                    from <strong id="return_staff_name"></strong>.
                </p>
                <div class="form-group full">
                    <label class="form-label">Return Notes</label>
                    <textarea name="return_notes" class="form-control"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('returnModal')">Cancel</button>
                    <button class="btn btn-dark"><i class="fas fa-undo"></i> Confirm Return</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Depreciation MODAL -->
<div class="modal" id="depreciationModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Asset Depreciation</h3>
            <button class="modal-close" onclick="closeModal('depreciationModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_depreciation">
                <input type="hidden" name="asset_id" id="dep_asset_id">

                <p class="note">Asset: <strong id="dep_asset_name"></strong></p>

                <div class="form-group full">
                    <label class="form-label">Depreciation Rate (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="depreciation_rate" id="dep_rate" class="form-control" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('depreciationModal')">Cancel</button>
                    <button class="btn btn-dark">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Offload MODAL -->
<div class="modal" id="offloadModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Offload Asset</h3>
            <button class="modal-close" onclick="closeModal('offloadModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" onsubmit="return confirm('Confirm offloading this asset?');">
                <input type="hidden" name="action" value="offload_asset">
                <input type="hidden" name="asset_id" id="offload_asset_id">

                <p class="note">Asset: <strong id="offload_asset_name"></strong></p>

                <div class="form-group full">
                    <label class="form-label">Offload Reason *</label>
                    <textarea name="offload_reason" class="form-control" required></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('offloadModal')">Cancel</button>
                    <button class="btn btn-red">Offload Asset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* -- Modals -- */
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

window.addEventListener('click', e => {
    document.querySelectorAll('.modal.show').forEach(m => {
        if (e.target === m) m.classList.remove('show');
    });
});

/* -- Modal populators -- */
function openEditAssetModal(data) {
    document.getElementById('edit_asset_id').value            = data.asset_id || '';
    document.getElementById('edit_asset_code').value          = data.asset_code || '';
    document.getElementById('edit_asset_name').value          = data.asset_name || '';
    document.getElementById('edit_category').value            = data.category || data.category_name || '';
    document.getElementById('edit_brand').value               = data.brand || '';
    document.getElementById('edit_model').value               = data.model || '';
    document.getElementById('edit_serial_number').value       = data.serial_number || '';
    document.getElementById('edit_purchase_date').value       = data.purchase_date || '';
    document.getElementById('edit_purchase_cost').value       = data.purchase_cost || '';
    document.getElementById('edit_condition_status').value    = data.condition_status || 'Good';
    document.getElementById('edit_availability_status').value = data.availability_status || 'Available';
    document.getElementById('edit_description').value         = data.description || '';
    openModal('editAssetModal');
}

function openRequestAssetPrefill(data) {
    document.getElementById('request_asset_id').value    = data.asset_id || 0;
    document.getElementById('request_asset_name').value  = data.asset_name || '';
    document.getElementById('request_category_id').value = data.category_id || '';
    openModal('requestAssetModal');
}

function openApprovalModal(data) {
    document.getElementById('approval_request_id').value          = data.request_id || '';
    document.getElementById('approval_staff_name').textContent    = data.staff_name || '';
    document.getElementById('approval_asset_name').textContent    = data.asset_name || '';
    document.getElementById('approval_decision').value            = 'Approved';
    toggleApprovalAssetSelect('Approved');
    openModal('approvalModal');
}

function toggleApprovalAssetSelect(decision) {
    document.getElementById('approval_asset_group').style.display =
        decision === 'Approved' ? '' : 'none';
}

function openReturnModal(data) {
    document.getElementById('return_assignment_id').value      = data.assignment_id || '';
    document.getElementById('return_asset_name').textContent   = data.asset_name || '';
    document.getElementById('return_staff_name').textContent   = data.staff_name || '';
    openModal('returnModal');
}


function openDepreciationModal(data) {
    document.getElementById('dep_asset_id').value = data.asset_id || '';
    document.getElementById('dep_asset_name').textContent = data.asset_name || '';
    document.getElementById('dep_rate').value = data.depreciation_rate || 0;
    openModal('depreciationModal');
}

function openOffloadModal(data) {
    document.getElementById('offload_asset_id').value = data.asset_id || '';
    document.getElementById('offload_asset_name').textContent = data.asset_name || '';
    openModal('offloadModal');
}
</script>

<?php require 'includes/footer.php'; ?>