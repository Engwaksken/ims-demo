<?php
declare(strict_types=1);

/*
 * Member folder actions (POST only; CSRF is enforced globally):
 *   action=upload  member_id, category, title, period_month (YYYY-MM), amount, reference, notes, files[]
 *   action=delete  member_id, document_id
 * Files are stored in legacy/uploads/member-files/{member_id}/ and served by
 * download-file.php (category "member-files").
 */

require_once __DIR__ . '/config.php';
check_login();

const MF_CATEGORIES = ['Contract', 'Monthly Receipt', 'ID / KYC', 'Other'];
const MF_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];

$userId = (int)$_SESSION['user_id'];
$memberId = (int)($_POST['member_id'] ?? 0);
$return = '../member-folder?id=' . $memberId;

function mf_flash(string $type, string $message, string $return): never
{
    $_SESSION['notification'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $return);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $memberId < 1) {
    header('Location: ../hub-operations?tab=members');
    exit;
}

$stmt = $conn->prepare('SELECT member_id, membership_number FROM members WHERE member_id = ? LIMIT 1');
$stmt->bind_param('i', $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    mf_flash('error', 'Member not found.', '../hub-operations?tab=members');
}

$action = (string)($_POST['action'] ?? '');

/* ------------------------------------------------------------------ */
/* Upload                                                              */
/* ------------------------------------------------------------------ */
if ($action === 'upload') {
    if (!ims_allowed('hub_operations.manage', ['Administrator', 'Operations/Admin'], 'Add and edit hub members, subscriptions and receipts')) {
        mf_flash('error', 'You do not have permission to upload member documents.', $return);
    }

    $category = (string)($_POST['category'] ?? '');
    $title = trim((string)($_POST['title'] ?? ''));
    $periodRaw = trim((string)($_POST['period_month'] ?? ''));
    $amountRaw = trim((string)($_POST['amount'] ?? ''));
    $reference = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 100);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!in_array($category, MF_CATEGORIES, true)) {
        mf_flash('error', 'Please choose a document category.', $return);
    }

    $period = null;
    if ($periodRaw !== '') {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodRaw)) {
            mf_flash('error', 'Please choose a valid month.', $return);
        }
        $period = $periodRaw . '-01';
    }
    if ($category === 'Monthly Receipt' && $period === null) {
        mf_flash('error', 'Please choose the month this receipt covers.', $return);
    }

    $amount = null;
    if ($amountRaw !== '') {
        if (!is_numeric($amountRaw) || (float)$amountRaw < 0) {
            mf_flash('error', 'Amount must be a positive number.', $return);
        }
        $amount = round((float)$amountRaw, 2);
    }

    // Normalise files[] into a list of single-file arrays.
    $files = [];
    $raw = $_FILES['files'] ?? null;
    if (is_array($raw) && is_array($raw['name'] ?? null)) {
        foreach ($raw['name'] as $i => $name) {
            if ((int)($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'type' => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $raw['size'][$i] ?? 0,
            ];
        }
    }
    if (!$files) {
        mf_flash('error', 'Please choose at least one file to upload.', $return);
    }
    if (count($files) > 20) {
        mf_flash('error', 'Upload at most 20 files at a time.', $return);
    }

    // Validate everything before saving anything.
    $checked = [];
    foreach ($files as $file) {
        $check = ims_validate_upload($file, MF_EXTENSIONS);
        if (!$check['ok']) {
            mf_flash('error', basename((string)$file['name']) . ': ' . $check['error'], $return);
        }
        $checked[] = [$file, $check];
    }

    $dir = dirname(__DIR__) . '/uploads/member-files/' . $memberId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log("member-folder: could not create $dir");
        mf_flash('error', 'The upload folder could not be created. Please contact the administrator.', $return);
    }

    $stmt = $conn->prepare("
        INSERT INTO member_documents
            (member_id, category, title, period_month, amount, reference, notes,
             file_path, original_name, mime_type, file_size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        error_log('member-folder: prepare failed: ' . $conn->error);
        mf_flash('error', 'Documents could not be saved. Has the member_documents migration been run?', $return);
    }

    $saved = [];
    $conn->begin_transaction();
    foreach ($checked as [$file, $check]) {
        $original = mb_substr(basename((string)$file['name']), 0, 255);
        $docTitle = $title !== '' ? $title : pathinfo($original, PATHINFO_FILENAME);
        if (count($checked) > 1 && $title !== '') {
            $docTitle .= ' - ' . pathinfo($original, PATHINFO_FILENAME);
        }
        $docTitle = mb_substr($docTitle, 0, 200);

        $target = $dir . '/' . $check['safe_name'];
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            break;
        }
        $saved[] = $target;

        $relPath = 'uploads/member-files/' . $memberId . '/' . $check['safe_name'];
        $size = (int)$file['size'];
        $mime = (string)$check['mime'];
        $refOrNull = $reference !== '' ? $reference : null;
        $notesOrNull = $notes !== '' ? $notes : null;
        $stmt->bind_param(
            'isssdsssssii',
            $memberId, $category, $docTitle, $period, $amount, $refOrNull, $notesOrNull,
            $relPath, $original, $mime, $size, $userId
        );
        if (!$stmt->execute()) {
            error_log('member-folder: insert failed: ' . $stmt->error);
            break;
        }
    }
    $stmt->close();

    if (count($saved) !== count($checked) || $conn->errno) {
        $conn->rollback();
        foreach ($saved as $path) {
            @unlink($path);
        }
        mf_flash('error', 'The upload failed and nothing was saved. Please try again.', $return);
    }
    $conn->commit();

    log_action($userId, 'Upload Member Documents', 'member_documents', $memberId,
        count($saved) . " file(s) as $category for member {$member['membership_number']}");
    mf_flash('success', count($saved) === 1 ? 'Document uploaded.' : count($saved) . ' documents uploaded.', $return);
}

/* ------------------------------------------------------------------ */
/* Delete                                                              */
/* ------------------------------------------------------------------ */
if ($action === 'delete') {
    if (!ims_allowed('members.folder_delete', ['Administrator', 'Operations/Admin'], 'Delete documents from member folders')) {
        mf_flash('error', 'You do not have permission to delete member documents.', $return);
    }

    $documentId = (int)($_POST['document_id'] ?? 0);
    $stmt = $conn->prepare('SELECT document_id, title, file_path FROM member_documents WHERE document_id = ? AND member_id = ? LIMIT 1');
    $stmt->bind_param('ii', $documentId, $memberId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        mf_flash('error', 'Document not found.', $return);
    }

    $stmt = $conn->prepare('DELETE FROM member_documents WHERE document_id = ? AND member_id = ?');
    $stmt->bind_param('ii', $documentId, $memberId);
    if (!$stmt->execute()) {
        error_log('member-folder: delete failed: ' . $stmt->error);
        mf_flash('error', 'The document could not be deleted.', $return);
    }
    $stmt->close();

    // Remove the file only if it resolves inside this member's folder.
    $base = realpath(dirname(__DIR__) . '/uploads/member-files/' . $memberId);
    $file = realpath(dirname(__DIR__) . '/' . $doc['file_path']);
    if ($base !== false && $file !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
        @unlink($file);
    }

    log_action($userId, 'Delete Member Document', 'member_documents', $documentId, "Deleted \"{$doc['title']}\" from member {$member['membership_number']}");
    mf_flash('success', 'Document deleted.', $return);
}

header('Location: ' . $return);
exit;
