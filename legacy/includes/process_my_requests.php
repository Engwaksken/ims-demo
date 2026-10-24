<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Africa/Nairobi');

function redirect_my_requests(): never
{
    header('Location: ../my_requests');
    exit;
}

function redirect_asset_request_form(): never
{
    header('Location: ../my_requests');
    exit;
}

function my_request_flash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

function nbi_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d H:i:s');
}

function nbi_date(string $datetime, string $format = 'd M Y H:i'): string
{
    if ($datetime === '') return '-';

    try {
        return (new DateTimeImmutable($datetime, new DateTimeZone('Africa/Nairobi')))->format($format);
    } catch (Exception) {
        return '-';
    }
}

function handleMyRequestPostActions(mysqli $conn, int $currentUserId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    match ($action) {
        'submit_request',
        'create_request',
        'asset_request'  => submitMyAssetRequest($conn, $currentUserId),

        'update_request' => updateMyAssetRequest($conn, $currentUserId),
        'cancel_request' => cancelMyAssetRequest($conn, $currentUserId),
        default          => null,
    };
}

function submitMyAssetRequest(mysqli $conn, int $currentUserId): never
{
    $asset_id       = (int)($_POST['asset_id'] ?? 0);
    $asset_name     = trim((string)($_POST['asset_name'] ?? ''));
    $category_id    = (int)($_POST['category_id'] ?? 0);
    $urgency_level  = trim((string)($_POST['urgency_level'] ?? 'Medium'));
    $request_reason = trim((string)($_POST['request_reason'] ?? ''));

    if (!in_array($urgency_level, ['Low', 'Medium', 'High'], true)) {
        $urgency_level = 'Medium';
    }

    if ($asset_name === '' || $category_id <= 0 || $request_reason === '') {
        my_request_flash('error', 'Asset name, category, and request reason are required.');
        redirect_asset_request_form();
    }

    if ($asset_id > 0) {
        $avail = $conn->prepare("
            SELECT availability_status
            FROM assets
            WHERE asset_id = ?
            LIMIT 1
        ");
        $avail->bind_param('i', $asset_id);
        $avail->execute();
        $assetRow = $avail->get_result()->fetch_assoc();
        $avail->close();

        if (!$assetRow || $assetRow['availability_status'] !== 'Available') {
            my_request_flash('error', 'The selected asset is no longer available.');
            redirect_asset_request_form();
        }
    }

    $dup = $conn->prepare("
        SELECT request_id
        FROM asset_requests
        WHERE staff_id = ?
          AND asset_name = ?
          AND status = 'Pending'
        LIMIT 1
    ");
    $dup->bind_param('is', $currentUserId, $asset_name);
    $dup->execute();
    $dupRow = $dup->get_result()->fetch_assoc();
    $dup->close();

    if ($dupRow) {
        my_request_flash('error', 'You already have a pending request for this asset.');
        redirect_asset_request_form();
    }

    $now = nbi_now();

    if ($asset_id > 0) {
        $stmt = $conn->prepare("
            INSERT INTO asset_requests
                (staff_id, asset_id, asset_name, category_id, urgency_level, request_reason, status, requested_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
        ");

        $stmt->bind_param(
            'iisissss',
            $currentUserId,
            $asset_id,
            $asset_name,
            $category_id,
            $urgency_level,
            $request_reason,
            $now,
            $now
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO asset_requests
                (staff_id, asset_name, category_id, urgency_level, request_reason, status, requested_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, 'Pending', ?, ?)
        ");

        $stmt->bind_param(
            'isissss',
            $currentUserId,
            $asset_name,
            $category_id,
            $urgency_level,
            $request_reason,
            $now,
            $now
        );
    }

    if (!$stmt) {
        my_request_flash('error', 'Failed to prepare asset request.');
        redirect_asset_request_form();
    }

    $ok = $stmt->execute();
    $err = $stmt->error;
    $request_id = $stmt->insert_id;
    $stmt->close();

    if (!$ok) {
        my_request_flash('error', 'Failed to submit asset request. ' . $err);
        redirect_asset_request_form();
    }

    sendAssetRequestEmails($conn, $currentUserId, $request_id, $asset_name, $category_id, $urgency_level, $request_reason);

    my_request_flash('success', 'Asset request submitted successfully. Admin and procurement officer have been notified.');
    redirect_asset_request_form();
}

function sendAssetRequestEmails(
    mysqli $conn,
    int $currentUserId,
    int $request_id,
    string $asset_name,
    int $category_id,
    string $urgency_level,
    string $request_reason
): void {
    $userStmt = $conn->prepare("
        SELECT full_name, email
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $userStmt->bind_param("i", $currentUserId);
    $userStmt->execute();
    $userRow = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    $staff_name  = $userRow['full_name'] ?? 'User';
    $staff_email = $userRow['email'] ?? '';

    $catStmt = $conn->prepare("
        SELECT category_name
        FROM asset_categories
        WHERE category_id = ?
        LIMIT 1
    ");
    $catStmt->bind_param("i", $category_id);
    $catStmt->execute();
    $catRow = $catStmt->get_result()->fetch_assoc();
    $catStmt->close();

    $category_name = $catRow['category_name'] ?? 'N/A';

    $recipients = [];
    $roleStmt = $conn->prepare("
        SELECT email
        FROM users
        WHERE email <> ''
          AND (
                is_active = 1
                OR status = 'Active'
          )
          AND role IN ('Administrator', 'Procurement Officer', 'Operations/Admin', 'Accountant')
    ");

    if ($roleStmt) {
        $roleStmt->execute();
        $res = $roleStmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $recipients[] = $row['email'];
        }

        $roleStmt->close();
    }

    $requestData = [
        'request_id'     => $request_id,
        'staff_name'     => $staff_name,
        'asset_name'     => $asset_name,
        'category'       => $category_name,
        'urgency_level'  => $urgency_level,
        'request_reason' => $request_reason,
    ];

    send_asset_request_admin_notification($requestData, array_unique($recipients));
    send_asset_request_user_confirmation($staff_email, $requestData);
}

function updateMyAssetRequest(mysqli $conn, int $currentUserId): never
{
    $request_id     = (int)($_POST['request_id'] ?? 0);
    $asset_name     = trim((string)($_POST['asset_name'] ?? ''));
    $category_id    = (int)($_POST['category_id'] ?? 0);
    $urgency_level  = trim((string)($_POST['urgency_level'] ?? 'Medium'));
    $request_reason = trim((string)($_POST['request_reason'] ?? ''));

    if (!in_array($urgency_level, ['Low', 'Medium', 'High'], true)) {
        $urgency_level = 'Medium';
    }

    if ($request_id <= 0 || $asset_name === '' || $category_id <= 0 || $request_reason === '') {
        my_request_flash('error', 'Asset name, category, urgency, and reason are required.');
        redirect_my_requests();
    }

    $check = $conn->prepare("
        SELECT status
        FROM asset_requests
        WHERE request_id = ? AND staff_id = ?
        LIMIT 1
    ");
    $check->bind_param('ii', $request_id, $currentUserId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        my_request_flash('error', 'Request not found.');
        redirect_my_requests();
    }

    if (!in_array($row['status'], ['Pending', 'Rejected'], true)) {
        my_request_flash('error', 'Only pending or rejected requests can be edited.');
        redirect_my_requests();
    }

    $now = nbi_now();

    $stmt = $conn->prepare("
        UPDATE asset_requests
        SET asset_name = ?,
            category_id = ?,
            urgency_level = ?,
            request_reason = ?,
            status = 'Pending',
            approval_notes = NULL,
            approved_by = NULL,
            approved_at = NULL,
            updated_at = ?
        WHERE request_id = ? AND staff_id = ?
        LIMIT 1
    ");
    $stmt->bind_param(
        'sisssii',
        $asset_name,
        $category_id,
        $urgency_level,
        $request_reason,
        $now,
        $request_id,
        $currentUserId
    );

    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    if ($ok) {
        sendAssetRequestEmails($conn, $currentUserId, $request_id, $asset_name, $category_id, $urgency_level, $request_reason);
    }

    my_request_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Request updated and resubmitted successfully.' : 'Failed to update request. ' . $err
    );

    redirect_my_requests();
}

function cancelMyAssetRequest(mysqli $conn, int $currentUserId): never
{
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id <= 0) {
        my_request_flash('error', 'Invalid request selected.');
        redirect_my_requests();
    }

    $check = $conn->prepare("
        SELECT status
        FROM asset_requests
        WHERE request_id = ? AND staff_id = ?
        LIMIT 1
    ");
    $check->bind_param('ii', $request_id, $currentUserId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        my_request_flash('error', 'Request not found.');
        redirect_my_requests();
    }

    if (!in_array($row['status'], ['Pending', 'Rejected'], true)) {
        my_request_flash('error', 'Only pending or rejected requests can be cancelled.');
        redirect_my_requests();
    }

    $stmt = $conn->prepare("
        DELETE FROM asset_requests
        WHERE request_id = ? AND staff_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $request_id, $currentUserId);

    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    my_request_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Request cancelled successfully.' : 'Failed to cancel request. ' . $err
    );

    redirect_my_requests();
}

function getMyRequestCategories(mysqli $conn): array
{
    $categories = [];

    $res = $conn->query("
        SELECT category_id, category_name
        FROM asset_categories
        ORDER BY category_name ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }
    }

    return $categories;
}

function getMyRequestsPageData(mysqli $conn, int $currentUserId): array
{
    $search = trim((string)($_GET['search'] ?? ''));
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    [$where, $types, $params] = buildMyRequestsWhere($currentUserId, $search);

    if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
        exportMyRequestsPdf($conn, $where, $types, $params);
    }

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM asset_requests ar
        LEFT JOIN asset_categories c ON c.category_id = ar.category_id
        $where
    ");
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $totalRows = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int)ceil($totalRows / $limit));

    $listTypes  = $types . 'ii';
    $listParams = [...$params, $limit, $offset];

    $stmt = $conn->prepare("
        SELECT
            ar.*,
            c.category_name,
            a.asset_code,
            a.asset_name AS assigned_asset,
            approver.full_name AS approved_by_name
        FROM asset_requests ar
        LEFT JOIN asset_categories c ON c.category_id = ar.category_id
        LEFT JOIN assets a ON a.asset_id = ar.asset_id
        LEFT JOIN users approver ON approver.user_id = ar.approved_by
        $where
        ORDER BY ar.requested_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $res = $stmt->get_result();

    $requests = [];
    while ($row = $res->fetch_assoc()) {
        $requests[] = $row;
    }

    $stmt->close();

    return [
        'requests'   => $requests,
        'search'     => $search,
        'page'       => $page,
        'limit'      => $limit,
        'totalRows'  => $totalRows,
        'totalPages' => $totalPages,
    ];
}

function buildMyRequestsWhere(int $currentUserId, string $search): array
{
    $where  = 'WHERE ar.staff_id = ?';
    $types  = 'i';
    $params = [$currentUserId];

    if ($search !== '') {
        $where .= ' AND (
            ar.asset_name LIKE ?
            OR ar.request_reason LIKE ?
            OR ar.urgency_level LIKE ?
            OR ar.status LIKE ?
            OR c.category_name LIKE ?
        )';

        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }

    return [$where, $types, $params];
}

function exportMyRequestsPdf(mysqli $conn, string $where, string $types, array $params): never
{
    foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }

    $stmt = $conn->prepare("
        SELECT
            ar.*,
            c.category_name,
            a.asset_code,
            a.asset_name AS assigned_asset,
            approver.full_name AS approved_by_name
        FROM asset_requests ar
        LEFT JOIN asset_categories c ON c.category_id = ar.category_id
        LEFT JOIN assets a ON a.asset_id = ar.asset_id
        LEFT JOIN users approver ON approver.user_id = ar.approved_by
        $where
        ORDER BY ar.requested_at DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $pdfRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('d M Y, h:i A') . ' EAT';

    $html = '
    <style>
        body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
        h2{color:#ff5722;margin-bottom:4px}
        .muted{color:#777;font-size:10px;margin-bottom:15px}
        table{width:100%;border-collapse:collapse}
        th{background:#ff5722;color:#fff;padding:7px;text-align:left}
        td{border:1px solid #ddd;padding:6px;vertical-align:top}
    </style>

    <h2>My Asset Requests</h2>
    <div class="muted">Generated on ' . htmlspecialchars($generatedAt) . '</div>

    <table>
        <thead>
            <tr>
                <th>Request</th>
                <th>Category</th>
                <th>Urgency</th>
                <th>Status</th>
                <th>Assigned Asset</th>
                <th>Requested At</th>
            </tr>
        </thead>
        <tbody>';

    if (empty($pdfRows)) {
        $html .= '<tr><td colspan="6">No requests found.</td></tr>';
    }

    foreach ($pdfRows as $r) {
        $html .= '
            <tr>
                <td>
                    <strong>' . htmlspecialchars((string)$r['asset_name']) . '</strong><br>
                    REQ-' . str_pad((string)$r['request_id'], 5, '0', STR_PAD_LEFT) . '<br>
                    ' . nl2br(htmlspecialchars((string)$r['request_reason'])) . '
                </td>
                <td>' . htmlspecialchars((string)($r['category_name'] ?? '-')) . '</td>
                <td>' . htmlspecialchars((string)$r['urgency_level']) . '</td>
                <td>' . htmlspecialchars((string)$r['status']) . '</td>
                <td>' . htmlspecialchars((string)($r['assigned_asset'] ?? 'Not assigned')) . '</td>
                <td>' . nbi_date((string)($r['requested_at'] ?? '')) . '</td>
            </tr>';
    }

    $html .= '</tbody></table>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
require_once __DIR__ . '/pdf-branding.php';
ims_brand_pdf($dompdf);
    $dompdf->stream('my_requests.pdf', ['Attachment' => true]);
    exit;
}