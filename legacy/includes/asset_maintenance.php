<?php
declare(strict_types=1);

function redirect_maintenance(): never
{
    header('Location: asset_maintenance');
    exit;
}

function maintenance_flash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

function handleMaintenanceActions(mysqli $conn, int $currentUserId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_maintenance') {
        addMaintenanceRecord($conn, $currentUserId);
    }

    if ($action === 'update_maintenance') {
        updateMaintenanceRecord($conn, $currentUserId);
    }

    if ($action === 'delete_maintenance') {
        deleteMaintenanceRecord($conn);
    }
}

function addMaintenanceRecord(mysqli $conn, int $currentUserId): never
{
    $asset_id          = (int)($_POST['asset_id'] ?? 0);
    $issue_title       = trim((string)($_POST['issue_title'] ?? ''));
    $issue_description = trim((string)($_POST['issue_description'] ?? ''));
    $maintenance_type  = trim((string)($_POST['maintenance_type'] ?? 'Repair'));

    if (!in_array($maintenance_type, ['Repair','Service','Inspection','Upgrade','Other'], true)) {
        $maintenance_type = 'Repair';
    }

    if ($asset_id <= 0 || $issue_title === '') {
        maintenance_flash('error', 'Asset and issue title are required.');
        redirect_maintenance();
    }

    $stmt = $conn->prepare("
        INSERT INTO asset_maintenance
            (asset_id, reported_by, issue_title, issue_description, maintenance_type, status)
        VALUES (?, ?, ?, ?, ?, 'Pending')
    ");
    $stmt->bind_param("iisss", $asset_id, $currentUserId, $issue_title, $issue_description, $maintenance_type);

    if ($stmt->execute()) {
        $up = $conn->prepare("
            UPDATE assets
            SET availability_status = 'Under Repair'
            WHERE asset_id = ?
            LIMIT 1
        ");
        $up->bind_param("i", $asset_id);
        $up->execute();
        $up->close();

        maintenance_flash('success', 'Maintenance record created successfully.');
    } else {
        maintenance_flash('error', 'Failed to create maintenance record. ' . $stmt->error);
    }

    $stmt->close();
    redirect_maintenance();
}

function updateMaintenanceRecord(mysqli $conn, int $currentUserId): never
{
    $maintenance_id   = (int)($_POST['maintenance_id'] ?? 0);
    $status           = trim((string)($_POST['status'] ?? 'Pending'));
    $cost             = (float)($_POST['cost'] ?? 0);
    $resolution_notes = trim((string)($_POST['resolution_notes'] ?? ''));

    if (!in_array($status, ['Pending','In Progress','Completed','Cancelled'], true)) {
        $status = 'Pending';
    }

    if ($maintenance_id <= 0) {
        maintenance_flash('error', 'Invalid maintenance record selected.');
        redirect_maintenance();
    }

    $stmt = $conn->prepare("
        SELECT asset_id
        FROM asset_maintenance
        WHERE maintenance_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $maintenance_id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        maintenance_flash('error', 'Maintenance record not found.');
        redirect_maintenance();
    }

    $startedAtSql   = $status === 'In Progress' ? ", started_at = COALESCE(started_at, NOW())" : "";
    $completedAtSql = $status === 'Completed' ? ", completed_at = NOW()" : "";

    $stmt = $conn->prepare("
        UPDATE asset_maintenance
        SET status = ?,
            handled_by = ?,
            cost = ?,
            resolution_notes = ?
            $startedAtSql
            $completedAtSql
        WHERE maintenance_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("sidsi", $status, $currentUserId, $cost, $resolution_notes, $maintenance_id);

    if ($stmt->execute()) {
        $assetStatus = in_array($status, ['Completed', 'Cancelled'], true) ? 'Available' : 'Under Repair';

        $up = $conn->prepare("
            UPDATE assets
            SET availability_status = ?
            WHERE asset_id = ?
            LIMIT 1
        ");
        $up->bind_param("si", $assetStatus, $record['asset_id']);
        $up->execute();
        $up->close();

        maintenance_flash('success', 'Maintenance status updated successfully.');
    } else {
        maintenance_flash('error', 'Failed to update maintenance record. ' . $stmt->error);
    }

    $stmt->close();
    redirect_maintenance();
}

function deleteMaintenanceRecord(mysqli $conn): never
{
    $maintenance_id = (int)($_POST['maintenance_id'] ?? 0);

    if ($maintenance_id <= 0) {
        maintenance_flash('error', 'Invalid maintenance record.');
        redirect_maintenance();
    }

    $stmt = $conn->prepare("
        DELETE FROM asset_maintenance
        WHERE maintenance_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $maintenance_id);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    maintenance_flash(
        $ok && $affected > 0 ? 'success' : 'error',
        $ok && $affected > 0 ? 'Maintenance record deleted.' : 'Failed to delete maintenance record.'
    );

    redirect_maintenance();
}

function getMaintenanceAssets(mysqli $conn): array
{
    $rows = [];

    $res = $conn->query("
        SELECT asset_id, asset_code, asset_name, category_id, availability_status
        FROM assets
        WHERE availability_status <> 'Disposed'
        ORDER BY asset_name ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function buildMaintenanceWhere(): array
{
    $search = trim((string)($_GET['search'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));

    $where = "WHERE 1=1";
    $types = "";
    $params = [];

    if ($search !== '') {
        $where .= " AND (
            a.asset_code LIKE ?
            OR a.asset_name LIKE ?
            OR am.issue_title LIKE ?
            OR am.issue_description LIKE ?
            OR am.maintenance_type LIKE ?
            OR reporter.full_name LIKE ?
            OR handler.full_name LIKE ?
        )";

        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
        $types .= "sssssss";
    }

    if ($status !== '' && in_array($status, ['Pending','In Progress','Completed','Cancelled'], true)) {
        $where .= " AND am.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    return [$where, $types, $params, $search, $status];
}

function getMaintenanceRecords(mysqli $conn): array
{
    [$where, $types, $params, $search, $status] = buildMaintenanceWhere();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        exportMaintenanceCsv($conn, $where, $types, $params);
    }

    if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
        exportMaintenancePdf($conn, $where, $types, $params);
    }

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM asset_maintenance am
        JOIN assets a ON a.asset_id = am.asset_id
        LEFT JOIN users reporter ON reporter.user_id = am.reported_by
        LEFT JOIN users handler ON handler.user_id = am.handled_by
        $where
    ");

    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $totalRows = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int)ceil($totalRows / $limit));

    $listTypes = $types . "ii";
    $listParams = [...$params, $limit, $offset];

    $stmt = $conn->prepare("
        SELECT 
            am.*,
            a.asset_code,
            a.asset_name,
            a.availability_status,
            reporter.full_name AS reported_by_name,
            handler.full_name AS handled_by_name
        FROM asset_maintenance am
        JOIN assets a ON a.asset_id = am.asset_id
        LEFT JOIN users reporter ON reporter.user_id = am.reported_by
        LEFT JOIN users handler ON handler.user_id = am.handled_by
        $where
        ORDER BY am.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();

    $records = [];
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $records[] = $row;
    }

    $stmt->close();

    return [
        'records' => $records,
        'search' => $search,
        'status' => $status,
        'page' => $page,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
    ];
}

function getMaintenanceExportRows(mysqli $conn, string $where, string $types, array $params): array
{
    $stmt = $conn->prepare("
        SELECT 
            am.*,
            a.asset_code,
            a.asset_name,
            reporter.full_name AS reported_by_name,
            handler.full_name AS handled_by_name
        FROM asset_maintenance am
        JOIN assets a ON a.asset_id = am.asset_id
        LEFT JOIN users reporter ON reporter.user_id = am.reported_by
        LEFT JOIN users handler ON handler.user_id = am.handled_by
        $where
        ORDER BY am.created_at DESC
    ");

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function exportMaintenanceCsv(mysqli $conn, string $where, string $types, array $params): never
{
    $rows = getMaintenanceExportRows($conn, $where, $types, $params);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=asset_maintenance.csv');

    $out = fopen('php://output', 'w');

    ims_fputcsv($out, [
        'Asset Code',
        'Asset Name',
        'Issue',
        'Description',
        'Type',
        'Cost',
        'Status',
        'Reported By',
        'Handled By',
        'Started At',
        'Completed At',
        'Created At'
    ]);

    foreach ($rows as $r) {
        ims_fputcsv($out, [
            $r['asset_code'] ?? '',
            $r['asset_name'] ?? '',
            $r['issue_title'] ?? '',
            $r['issue_description'] ?? '',
            $r['maintenance_type'] ?? '',
            $r['cost'] ?? '',
            $r['status'] ?? '',
            $r['reported_by_name'] ?? '',
            $r['handled_by_name'] ?? '',
            $r['started_at'] ?? '',
            $r['completed_at'] ?? '',
            $r['created_at'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

function exportMaintenancePdf(mysqli $conn, string $where, string $types, array $params): never
{
    foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }

    $rows = getMaintenanceExportRows($conn, $where, $types, $params);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $html = '
    <style>
        body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
        h2{color:#ff5722;margin-bottom:4px}
        .muted{color:#666;font-size:10px;margin-bottom:14px}
        table{width:100%;border-collapse:collapse}
        th{background:#ff5722;color:#fff;padding:7px;text-align:left}
        td{border:1px solid #ddd;padding:6px;vertical-align:top}
    </style>

    <h2>Asset Maintenance Report</h2>
    <div class="muted">Generated on ' . date('d M Y, h:i A') . '</div>

    <table>
        <thead>
            <tr>
                <th>Asset</th>
                <th>Issue</th>
                <th>Type</th>
                <th>Cost</th>
                <th>Status</th>
                <th>Reported By</th>
                <th>Handled By</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>';

    if (empty($rows)) {
        $html .= '<tr><td colspan="8">No maintenance records found.</td></tr>';
    }

    foreach ($rows as $r) {
        $html .= '
        <tr>
            <td>
                <strong>' . htmlspecialchars((string)($r['asset_name'] ?? '')) . '</strong><br>
                ' . htmlspecialchars((string)($r['asset_code'] ?? '')) . '
            </td>
            <td>
                <strong>' . htmlspecialchars((string)($r['issue_title'] ?? '')) . '</strong><br>
                ' . nl2br(htmlspecialchars((string)($r['issue_description'] ?? ''))) . '
            </td>
            <td>' . htmlspecialchars((string)($r['maintenance_type'] ?? '')) . '</td>
            <td>UGX ' . number_format((float)($r['cost'] ?? 0)) . '</td>
            <td>' . htmlspecialchars((string)($r['status'] ?? '')) . '</td>
            <td>' . htmlspecialchars((string)($r['reported_by_name'] ?? '-')) . '</td>
            <td>' . htmlspecialchars((string)($r['handled_by_name'] ?? '-')) . '</td>
            <td>' . (!empty($r['created_at']) ? date('d M Y H:i', strtotime((string)$r['created_at'])) : '-') . '</td>
        </tr>';
    }

    $html .= '</tbody></table>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
require_once __DIR__ . '/pdf-branding.php';
ims_brand_pdf($dompdf);
    $dompdf->stream('asset_maintenance.pdf', ['Attachment' => true]);
    exit;
}