<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/voucher-approval.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jsonOut(bool $ok, string $msg, array $extra = []): never
{
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    jsonOut(false, 'Not authenticated.');
}

$userId = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    jsonOut(false, 'Invalid ID.');
}

$approvalReady = pva_ensure_schema($conn);

$stmt = $conn->prepare('SELECT * FROM payment_vouchers WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    jsonOut(false, 'Voucher not found.');
}

// Finance staff see every voucher; an approver sees the vouchers assigned to them.
$isAssignedApprover = $approvalReady && (int)($row['approver_id'] ?? 0) === $userId;

if (
    !$isAssignedApprover
    && !ims_allowed('vouchers.view', ['Administrator', 'Finance', 'Accountant'], 'View, print and download payment vouchers')
) {
    jsonOut(false, 'Access denied.');
}

$items = [];
if (!empty($row['items_json'])) {
    $decoded = json_decode((string)$row['items_json'], true);
    if (is_array($decoded)) {
        $items = $decoded;
    }
}

$row['items'] = $items;

if ($approvalReady) {
    $row['prepared_signature_uri'] = pva_data_uri($row['prepared_signature'] ?? null, $row['prepared_signature_mime'] ?? null);
    $row['approved_signature_uri'] = pva_data_uri($row['approved_signature'] ?? null, $row['approved_signature_mime'] ?? null);
    $row['prepared_by_name'] = pva_user_name($conn, (int)($row['prepared_by'] ?? 0));
    $row['approver_name'] = pva_user_name($conn, (int)($row['approver_id'] ?? 0));
    $row['can_approve'] = pva_can_approve($row, $userId);
}

// Binary columns cannot be JSON encoded; the data URIs above replace them.
unset($row['prepared_signature'], $row['approved_signature']);

jsonOut(true, 'OK', ['voucher' => $row]);
