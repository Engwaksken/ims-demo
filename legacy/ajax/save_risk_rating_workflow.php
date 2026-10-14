<?php
declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_directory(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create signature directory.');
    }
}

function delete_file_if_exists(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $relativePath = ltrim($relativePath, '/\\');

    // Only ever delete files inside the signatures upload folder.
    if (!str_starts_with(str_replace('\\', '/', $relativePath), 'uploads/risk_rating_signatures/') || str_contains($relativePath, '..')) {
        return;
    }

    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function save_base64_signature(?string $dataUrl, string $prefix, int $riskRatingId, string $slot): ?string
{
    if ($dataUrl === null || trim($dataUrl) === '') {
        return null;
    }

    $dataUrl = trim($dataUrl);

    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $dataUrl, $matches)) {
        throw new RuntimeException('Invalid signature image format.');
    }

    $extension = strtolower($matches[1]);
    if ($extension === 'jpeg') {
        $extension = 'jpg';
    }

    $base64 = preg_replace('#^data:image/(png|jpeg|jpg);base64,#i', '', $dataUrl);
    if ($base64 === null || $base64 === '') {
        throw new RuntimeException('Empty signature image data.');
    }

    $binary = base64_decode(str_replace(' ', '+', $base64), true);
    if ($binary === false || @getimagesizefromstring($binary) === false) {
        throw new RuntimeException('Failed to decode signature image.');
    }

    $relativeDir = 'uploads/risk_rating_signatures/' . date('Y/m');
    $absoluteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativeDir;
    ensure_directory($absoluteDir);

    $filename = sprintf(
        '%s_rr%d_%s_%s.%s',
        $prefix,
        $riskRatingId,
        $slot,
        date('YmdHis'),
        $extension
    );

    $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($absolutePath, $binary) === false) {
        throw new RuntimeException('Failed to save signature image.');
    }

    return $relativeDir . '/' . $filename;
}

function fetch_risk_rating(mysqli $conn, int $riskRatingId): ?array
{
    $sql = "
        SELECT
            id,
            application_id,
            prepared_by,
            prepared_by_name,
            prepared_by_signature,
            reviewed_by_user_id,
            reviewed_by_name,
            reviewed_by_signature,
            reviewer_comments,
            approved_by_user_id,
            approved_by_name,
            approved_by_signature,
            generated_pdf_path,
            generated_pdf_name,
            updated_at
        FROM risk_ratings
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare risk rating query: ' . $conn->error);
    }

    $stmt->bind_param('i', $riskRatingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function update_prepared_signature(
    mysqli $conn,
    int $riskRatingId,
    int $userId,
    string $userName,
    ?string $signaturePath,
    bool $clear
): void {
    if ($clear) {
        $sql = "
            UPDATE risk_ratings
            SET
                prepared_by = NULL,
                prepared_by_name = NULL,
                prepared_by_signature = NULL,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare prepared-signature clear query: ' . $conn->error);
        }
        $stmt->bind_param('i', $riskRatingId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Failed to clear Prepared By signature: ' . $err);
        }
        $stmt->close();
        return;
    }

    if ($signaturePath === null) {
        return;
    }

    $sql = "
        UPDATE risk_ratings
        SET
            prepared_by = ?,
            prepared_by_name = ?,
            prepared_by_signature = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare prepared-signature update query: ' . $conn->error);
    }
    $stmt->bind_param('issi', $userId, $userName, $signaturePath, $riskRatingId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Failed to save Prepared By signature: ' . $err);
    }
    $stmt->close();
}

function update_reviewed_signature(
    mysqli $conn,
    int $riskRatingId,
    int $userId,
    string $userName,
    ?string $signaturePath,
    bool $clear,
    ?string $reviewerComments
): void {
    if ($clear) {
        $sql = "
            UPDATE risk_ratings
            SET
                reviewed_by_user_id = NULL,
                reviewed_by_name = NULL,
                reviewed_by_signature = NULL,
                reviewer_comments = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare reviewed-signature clear query: ' . $conn->error);
        }
        $stmt->bind_param('si', $reviewerComments, $riskRatingId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Failed to clear Reviewed By signature: ' . $err);
        }
        $stmt->close();
        return;
    }

    if ($signaturePath === null && $reviewerComments === null) {
        return;
    }

    if ($signaturePath !== null) {
        $sql = "
            UPDATE risk_ratings
            SET
                reviewed_by_user_id = ?,
                reviewed_by_name = ?,
                reviewed_by_signature = ?,
                reviewer_comments = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare reviewed-signature update query: ' . $conn->error);
        }
        $stmt->bind_param('isssi', $userId, $userName, $signaturePath, $reviewerComments, $riskRatingId);
    } else {
        $sql = "
            UPDATE risk_ratings
            SET
                reviewer_comments = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare reviewer-comments update query: ' . $conn->error);
        }
        $stmt->bind_param('si', $reviewerComments, $riskRatingId);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Failed to save Reviewed By details: ' . $err);
    }
    $stmt->close();
}

function update_approved_signature(
    mysqli $conn,
    int $riskRatingId,
    int $userId,
    string $userName,
    ?string $signaturePath,
    bool $clear
): void {
    if ($clear) {
        $sql = "
            UPDATE risk_ratings
            SET
                approved_by_user_id = NULL,
                approved_by_name = NULL,
                approved_by_signature = NULL,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare approved-signature clear query: ' . $conn->error);
        }
        $stmt->bind_param('i', $riskRatingId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Failed to clear Approved By signature: ' . $err);
        }
        $stmt->close();
        return;
    }

    if ($signaturePath === null) {
        return;
    }

    $sql = "
        UPDATE risk_ratings
        SET
            approved_by_user_id = ?,
            approved_by_name = ?,
            approved_by_signature = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare approved-signature update query: ' . $conn->error);
    }
    $stmt->bind_param('issi', $userId, $userName, $signaturePath, $riskRatingId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Failed to save Approved By signature: ' . $err);
    }
    $stmt->close();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Method not allowed.', [], 405);
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection not available.');
    }

    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        respond(false, 'Your session has expired. Please log in again.', [], 401);
    }

    $conn->set_charset('utf8mb4');

    $loggedInUserId   = (int)$_SESSION['user_id'];
    $loggedInUserRole = trim((string)$_SESSION['role']);
    $loggedInUserName = trim((string)($_SESSION['full_name'] ?? ''));

    if ($loggedInUserName === '') {
        $uStmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param('i', $loggedInUserId);
            $uStmt->execute();
            $uRow = $uStmt->get_result()->fetch_assoc();
            $uStmt->close();
            if (!empty($uRow['full_name'])) {
                $loggedInUserName = trim((string)$uRow['full_name']);
            }
        }
    }
    if ($loggedInUserName === '') {
        $loggedInUserName = 'Current User';
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        respond(false, 'Invalid JSON payload.', [], 400);
    }

    $riskRatingId  = (int)($data['rr_id'] ?? 0);
    $applicationId = (int)($data['application_id'] ?? 0);

    if ($riskRatingId <= 0) {
        respond(false, 'Invalid risk rating ID.', [], 422);
    }

    $riskRating = fetch_risk_rating($conn, $riskRatingId);
    if (!$riskRating) {
        respond(false, 'Risk rating not found.', [], 404);
    }

    if ($applicationId > 0 && (int)$riskRating['application_id'] !== $applicationId) {
        respond(false, 'Application mismatch for this risk rating.', [], 422);
    }

    $isReviewerRole = ims_allowed('risk_ratings.sign_prepared', ['Reviewer'], 'Sign the Prepared By section of risk ratings');
    $isReviewerApproverRole = ims_allowed('risk_ratings.sign_reviewed', ['Administrator', 'Programs Lead', 'Programs Manager'], 'Sign the Reviewed By section of risk ratings');
    $isEdApproverRole = ims_allowed('risk_ratings.sign_approved', ['Administrator', 'ED'], 'Sign the Approved by ED section of risk ratings');

    $prepUserId   = (int)($data['prep_by_user_id'] ?? 0);
    $prepUserName = trim((string)($data['prep_by_name'] ?? ''));
    $prepSignature = isset($data['prep_by_signature']) ? trim((string)$data['prep_by_signature']) : null;
    $prepClear = !empty($data['prep_clear']);

    $revUserId   = (int)($data['rev_by_user_id'] ?? 0);
    $revUserName = trim((string)($data['rev_by_name'] ?? ''));
    $revSignature = isset($data['rev_by_signature']) ? trim((string)$data['rev_by_signature']) : null;
    $revClear = !empty($data['rev_clear']);
    $reviewerComments = array_key_exists('reviewer_comments', $data)
        ? trim((string)$data['reviewer_comments'])
        : null;

    $appUserId   = (int)($data['app_by_user_id'] ?? 0);
    $appUserName = trim((string)($data['app_by_name'] ?? ''));
    $appSignature = isset($data['app_by_signature']) ? trim((string)$data['app_by_signature']) : null;
    $appClear = !empty($data['app_clear']);

    $isPrepAttempt = $prepClear || ($prepSignature !== null && $prepSignature !== '');
    $isRevAttempt  = $revClear || ($revSignature !== null && $revSignature !== '') || $reviewerComments !== null;
    $isAppAttempt  = $appClear || ($appSignature !== null && $appSignature !== '');

    if (!$isPrepAttempt && !$isRevAttempt && !$isAppAttempt) {
        respond(false, 'No workflow changes were submitted.', [], 422);
    }

    if ($isPrepAttempt) {
        if (!$isReviewerRole) {
            respond(false, 'Only Reviewer can sign the Prepared By section.', [], 403);
        }
        if ($prepUserId > 0 && $prepUserId !== $loggedInUserId) {
            respond(false, 'Prepared By signer mismatch.', [], 403);
        }
        $prepUserId = $loggedInUserId;
        $prepUserName = $loggedInUserName;
    }

    if ($isRevAttempt) {
        if (!$isReviewerApproverRole) {
            respond(false, 'Only Administrator, Programs Lead, or Programs Manager can sign the Reviewed By section.', [], 403);
        }
        if ($revUserId > 0 && $revUserId !== $loggedInUserId) {
            respond(false, 'Reviewed By signer mismatch.', [], 403);
        }
        $revUserId = $loggedInUserId;
        $revUserName = $loggedInUserName;
    }

    if ($isAppAttempt) {
        if (!$isEdApproverRole) {
            respond(false, 'Only Administrator or ED can sign the Approved by ED section.', [], 403);
        }
        if ($appUserId > 0 && $appUserId !== $loggedInUserId) {
            respond(false, 'Approved By signer mismatch.', [], 403);
        }
        $appUserId = $loggedInUserId;
        $appUserName = $loggedInUserName;
    }

    $conn->begin_transaction();

    $oldPrepPath = (string)($riskRating['prepared_by_signature'] ?? '');
    $oldRevPath  = (string)($riskRating['reviewed_by_signature'] ?? '');
    $oldAppPath  = (string)($riskRating['approved_by_signature'] ?? '');

    $newPrepPath = null;
    $newRevPath  = null;
    $newAppPath  = null;

    if ($isPrepAttempt && !$prepClear && $prepSignature !== null && $prepSignature !== '') {
        $newPrepPath = save_base64_signature($prepSignature, 'prepared', $riskRatingId, 'prep');
    }

    if ($isRevAttempt && !$revClear && $revSignature !== null && $revSignature !== '') {
        $newRevPath = save_base64_signature($revSignature, 'reviewed', $riskRatingId, 'rev');
    }

    if ($isAppAttempt && !$appClear && $appSignature !== null && $appSignature !== '') {
        $newAppPath = save_base64_signature($appSignature, 'approved', $riskRatingId, 'app');
    }

    if ($isPrepAttempt) {
        update_prepared_signature(
            $conn,
            $riskRatingId,
            $prepUserId,
            $prepUserName,
            $newPrepPath,
            $prepClear
        );
    }

    if ($isRevAttempt) {
        update_reviewed_signature(
            $conn,
            $riskRatingId,
            $revUserId,
            $revUserName,
            $newRevPath,
            $revClear,
            $reviewerComments
        );
    }

    if ($isAppAttempt) {
        update_approved_signature(
            $conn,
            $riskRatingId,
            $appUserId,
            $appUserName,
            $newAppPath,
            $appClear
        );
    }

    $statusStmt = $conn->prepare("
        UPDATE risk_ratings
        SET status = CASE WHEN status = '' OR status IS NULL THEN 'generated' ELSE status END,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    if ($statusStmt) {
        $statusStmt->bind_param('i', $riskRatingId);
        $statusStmt->execute();
        $statusStmt->close();
    }

    $conn->commit();

    if ($isPrepAttempt && ($prepClear || $newPrepPath !== null) && $oldPrepPath !== '' && $oldPrepPath !== $newPrepPath) {
        delete_file_if_exists($oldPrepPath);
    }

    if ($isRevAttempt && ($revClear || $newRevPath !== null) && $oldRevPath !== '' && $oldRevPath !== $newRevPath) {
        delete_file_if_exists($oldRevPath);
    }

    if ($isAppAttempt && ($appClear || $newAppPath !== null) && $oldAppPath !== '' && $oldAppPath !== $newAppPath) {
        delete_file_if_exists($oldAppPath);
    }

    respond(true, 'Workflow signatures saved successfully.', [
        'risk_rating_id' => $riskRatingId,
        'updated_sections' => [
            'prepared_by' => $isPrepAttempt,
            'reviewed_by' => $isRevAttempt,
            'approved_by' => $isAppAttempt,
        ],
        'signed_by' => [
            'user_id' => $loggedInUserId,
            'name' => $loggedInUserName,
            'role' => $loggedInUserRole,
        ],
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignore) {
        }
    }

    respond(false, $e->getMessage(), [], 500);
}