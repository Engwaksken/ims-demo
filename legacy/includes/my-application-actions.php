<?php
declare(strict_types=1);

/**
 * Applicant-owned actions on their own startup applications.
 *
 *  - withdraw     : pulls a Submitted application back out of review.
 *                   applications.status has no 'Withdrawn' enum value, so the
 *                   application is returned to 'Draft' (submitted_at cleared).
 *                   The applicant can then resubmit or discard it.
 *  - delete_draft : permanently deletes the applicant's own Draft and its
 *                   uploaded files (restricted to the uploads directory).
 *
 * POST only (CSRF enforced globally by includes/security.php).
 */

require_once __DIR__ . '/config.php';
check_login();

$redirect = '../my-applications';

function maa_finish(string $type, string $message, string $redirect): void
{
    $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
    header('Location: ' . $redirect);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    maa_finish('error', 'Invalid request method.', $redirect);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$applicationId = (int)($_POST['application_id'] ?? 0);

if ($userId <= 0 || $applicationId <= 0 || !in_array($action, ['withdraw', 'delete_draft'], true)) {
    maa_finish('error', 'Invalid request.', $redirect);
}

global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = db_connect();
}

/* Load the application, scoped to the current user. */
$stmt = $conn->prepare('SELECT * FROM applications WHERE application_id = ? AND submitted_by = ? LIMIT 1');
if (!$stmt) {
    maa_finish('error', 'Unable to process your request right now.', $redirect);
}
$stmt->bind_param('ii', $applicationId, $userId);
if (!$stmt->execute()) {
    $stmt->close();
    maa_finish('error', 'Unable to process your request right now.', $redirect);
}
$application = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$application) {
    maa_finish('error', 'Application not found.', $redirect);
}

$status = (string)$application['status'];
$name = (string)($application['startup_name'] ?? '');

if ($action === 'withdraw') {
    // Only applications that have not yet entered review can be withdrawn.
    if ($status !== 'Submitted') {
        maa_finish('error', 'This application can no longer be withdrawn.', $redirect);
    }

    $upd = $conn->prepare(
        "UPDATE applications
            SET status = 'Draft', submitted_at = NULL, updated_at = NOW()
          WHERE application_id = ? AND submitted_by = ? AND status = 'Submitted'"
    );
    if (!$upd) {
        maa_finish('error', 'Unable to withdraw the application right now.', $redirect);
    }
    $upd->bind_param('ii', $applicationId, $userId);
    $ok = $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if (!$ok || $affected < 1) {
        maa_finish('error', 'Unable to withdraw the application right now.', $redirect);
    }

    $hist = $conn->prepare(
        "INSERT INTO application_status_history (application_id, old_status, new_status, changed_by, comments)
         VALUES (?, 'Submitted', 'Draft', ?, 'Withdrawn by applicant')"
    );
    if ($hist) {
        $hist->bind_param('ii', $applicationId, $userId);
        $hist->execute();
        $hist->close();
    }

    log_action($userId, 'Withdraw Application', 'applications', $applicationId, "Applicant withdrew application '{$name}' (returned to Draft)");

    maa_finish('success', 'Your application has been withdrawn and moved back to your drafts. You can edit and resubmit it before the deadline, or discard it.', $redirect);
}

/* delete_draft */
if ($status !== 'Draft') {
    maa_finish('error', 'Only draft applications can be discarded.', $redirect);
}

// Collect stored file paths before the row (and cascaded attachments) go away.
$paths = [];
foreach ($application as $column => $value) {
    if (str_ends_with((string)$column, '_path') && is_string($value) && $value !== '') {
        $paths[] = $value;
    }
}
$att = $conn->prepare('SELECT file_path FROM application_attachments WHERE application_id = ?');
if ($att) {
    $att->bind_param('i', $applicationId);
    if ($att->execute()) {
        $res = $att->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['file_path'])) {
                $paths[] = (string)$row['file_path'];
            }
        }
    }
    $att->close();
}

$del = $conn->prepare("DELETE FROM applications WHERE application_id = ? AND submitted_by = ? AND status = 'Draft'");
if (!$del) {
    maa_finish('error', 'Unable to discard the draft right now.', $redirect);
}
$del->bind_param('ii', $applicationId, $userId);
$ok = $del->execute();
$affected = $del->affected_rows;
$del->close();

if (!$ok || $affected < 1) {
    maa_finish('error', 'Unable to discard the draft right now.', $redirect);
}

$uploadsRoot = realpath(dirname(__DIR__) . '/uploads');
if ($uploadsRoot !== false) {
    $uploadsPrefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach (array_unique($paths) as $relative) {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $real = realpath(dirname(__DIR__) . '/' . $relative);
        if ($real !== false && is_file($real) && str_starts_with($real, $uploadsPrefix)) {
            @unlink($real);
        }
    }
}

log_action($userId, 'Delete Draft Application', 'applications', $applicationId, "Applicant discarded draft '{$name}'");

maa_finish('success', 'Draft discarded.', $redirect);
