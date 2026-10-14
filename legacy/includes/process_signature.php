<?php
declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/RiskRatingReportService.php';

header('Content-Type: application/json; charset=UTF-8');

function jsonOut(bool $success, string $message, array $extra = [], int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

if (!ims_allowed('signatures.sign', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer'], 'Sign risk ratings (signature handler)')) {
    jsonOut(false, 'Access denied.', [], 403);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonOut(false, 'Invalid request method. Use POST.', [], 405);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    jsonOut(false, 'Database connection unavailable.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);

function cleanInt(mixed $value): ?int
{
    $i = (int)$value;
    return $i > 0 ? $i : null;
}

function cleanString(mixed $value, int $max = 255): string
{
    return mb_substr(trim((string)$value), 0, $max);
}

function cleanBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return ((int)$value) === 1;
    }

    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function cleanSignature(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $value)) {
        return null;
    }

    if (strlen($value) > 5_000_000) {
        return null;
    }

    $parts = explode(',', $value, 2);
    if (count($parts) !== 2) {
        return null;
    }

    if (base64_decode($parts[1], true) === false) {
        return null;
    }

    return $value;
}

function fetchJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$body = fetchJsonBody();
if (!$body) {
    jsonOut(false, 'Invalid JSON payload.', [], 400);
}

$rrId  = cleanInt($body['rr_id'] ?? null);
$appId = cleanInt($body['application_id'] ?? null);

if ($rrId === null || $appId === null) {
    jsonOut(false, 'Missing risk rating or application ID.', [], 422);
}

$currentUserId   = (int)($_SESSION['user_id'] ?? 0);

$checkSql = "
    SELECT
        id,
        application_id,
        prepared_by,
        prepared_by_name,
        prepared_by_signature,
        reviewed_by_user_id,
        reviewed_by_name,
        reviewed_by_signature,
        approved_by_user_id,
        approved_by_name,
        approved_by_signature
    FROM risk_ratings
    WHERE id = ? AND application_id = ?
    LIMIT 1
";
$checkStmt = $conn->prepare($checkSql);

if (!$checkStmt) {
    jsonOut(false, 'Failed to prepare verification query: ' . $conn->error, [], 500);
}

$checkStmt->bind_param('ii', $rrId, $appId);

if (!$checkStmt->execute()) {
    $err = $checkStmt->error;
    $checkStmt->close();
    jsonOut(false, 'Failed to verify risk rating: ' . $err, [], 500);
}

$record = $checkStmt->get_result()?->fetch_assoc();
$checkStmt->close();

if (!$record) {
    jsonOut(false, 'Risk rating not found.', [], 404);
}

/*
|--------------------------------------------------------------------------
| Optional role-based signing guidance
|--------------------------------------------------------------------------
| Adjust these rules if your workflow should be stricter.
*/
$roleCanPrepare = ims_allowed('signatures.prepare', ['Administrator', 'Reviewer', 'Project Officer', 'Programs Lead', 'MEAL Lead'], 'Sign risk ratings as Prepared By (signature handler)');
$roleCanReview  = ims_allowed('signatures.review', ['Administrator', 'Reviewer', 'Programs Lead', 'MEAL Lead'], 'Sign risk ratings as Reviewed By (signature handler)');
$roleCanApprove = ims_allowed('signatures.approve', ['Administrator', 'Programs Lead', 'MEAL Lead'], 'Sign risk ratings as Approved By (signature handler)');

/*
|--------------------------------------------------------------------------
| Signature sections
|--------------------------------------------------------------------------
*/
$signatureSections = [
    'prep' => [
        'user_col'   => 'prepared_by',
        'name_col'   => 'prepared_by_name',
        'sig_col'    => 'prepared_by_signature',
        'can_sign'   => $roleCanPrepare,
        'label'      => 'Prepared By',
    ],
    'rev' => [
        'user_col'   => 'reviewed_by_user_id',
        'name_col'   => 'reviewed_by_name',
        'sig_col'    => 'reviewed_by_signature',
        'can_sign'   => $roleCanReview,
        'label'      => 'Reviewed By',
    ],
    'app' => [
        'user_col'   => 'approved_by_user_id',
        'name_col'   => 'approved_by_name',
        'sig_col'    => 'approved_by_signature',
        'can_sign'   => $roleCanApprove,
        'label'      => 'Approved By',
    ],
];

$setParts  = ['updated_at = NOW()'];
$setTypes  = '';
$setValues = [];

$changesMade = false;

foreach ($signatureSections as $prefix => $cfg) {
    $userId = cleanInt($body[$prefix . '_by_user_id'] ?? null);
    $name   = cleanString($body[$prefix . '_by_name'] ?? '', 255);
    $sig    = cleanSignature($body[$prefix . '_by_signature'] ?? null);
    $clear  = cleanBool($body[$prefix . '_clear'] ?? false);

    /*
    |----------------------------------------------------------------------
    | Since frontend now signs with logged-in user automatically,
    | force signer identity to current session user when a signature is sent.
    |----------------------------------------------------------------------
    */
    if ($sig !== null && $cfg['can_sign']) {
        $userId = $currentUserId;
        if ($name === '') {
            $name = cleanString($_SESSION['full_name'] ?? $_SESSION['name'] ?? '', 255);
        }
    }

    if ($clear) {
        if (!$cfg['can_sign']) {
            continue;
        }

        $setParts[] = "{$cfg['user_col']} = NULL";
        $setParts[] = "{$cfg['name_col']} = NULL";
        $setParts[] = "{$cfg['sig_col']} = NULL";
        $changesMade = true;
        continue;
    }

    if (!$cfg['can_sign']) {
        continue;
    }

    if ($userId !== null) {
        $setParts[]  = "{$cfg['user_col']} = ?";
        $setTypes   .= 'i';
        $setValues[] = $userId;
        $changesMade = true;
    }

    if ($name !== '') {
        $setParts[]  = "{$cfg['name_col']} = ?";
        $setTypes   .= 's';
        $setValues[] = $name;
        $changesMade = true;
    }

    if ($sig !== null) {
        $setParts[]  = "{$cfg['sig_col']} = ?";
        $setTypes   .= 's';
        $setValues[] = $sig;
        $changesMade = true;
    }
}

/*
|--------------------------------------------------------------------------
| Reviewer comments
|--------------------------------------------------------------------------
*/
$reviewerComments = cleanString($body['reviewer_comments'] ?? '', 5000);
$setParts[]  = 'reviewer_comments = ?';
$setTypes   .= 's';
$setValues[] = $reviewerComments;

$setTypes   .= 'ii';
$setValues[] = $rrId;
$setValues[] = $appId;

$updateSql = "UPDATE risk_ratings SET " . implode(', ', $setParts) . " WHERE id = ? AND application_id = ?";
$updateStmt = $conn->prepare($updateSql);

if (!$updateStmt) {
    jsonOut(false, 'Failed to prepare signature update: ' . $conn->error, [], 500);
}

$conn->begin_transaction();

try {
    $updateStmt->bind_param($setTypes, ...$setValues);

    if (!$updateStmt->execute()) {
        $err = $updateStmt->error;
        $updateStmt->close();
        throw new RuntimeException('Failed to save signatures: ' . $err);
    }
    $updateStmt->close();

    $service = new RiskRatingReportService($conn);
    $pdf = $service->generatePdf($rrId, $currentUserId);

    if (empty($pdf['path']) || empty($pdf['name'])) {
        throw new RuntimeException('PDF regeneration failed - missing output path.');
    }

    $pdfStmt = $conn->prepare("
        UPDATE risk_ratings
        SET
            generated_pdf_path = ?,
            generated_pdf_name = ?,
            status = 'generated',
            updated_at = NOW()
        WHERE id = ?
    ");

    if (!$pdfStmt) {
        throw new RuntimeException('Failed to prepare PDF update query: ' . $conn->error);
    }

    $pdfStmt->bind_param('ssi', $pdf['path'], $pdf['name'], $rrId);

    if (!$pdfStmt->execute()) {
        $err = $pdfStmt->error;
        $pdfStmt->close();
        throw new RuntimeException('Failed to update PDF path: ' . $err);
    }

    $pdfStmt->close();

    $conn->commit();

    jsonOut(true, 'Signatures saved and PDF regenerated successfully.', [
        'rr_id'        => $rrId,
        'application_id' => $appId,
        'file_name'    => $pdf['name'],
        'file_path'    => $pdf['path'],
        'changes_made' => $changesMade,
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignore) {
    }

    jsonOut(false, 'Error: ' . $e->getMessage(), [
        'rr_id' => $rrId,
        'application_id' => $appId,
    ], 500);
}