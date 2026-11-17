<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Documents library actions (included by documents-handler.php)
|--------------------------------------------------------------------------
| Runs before the handler's own dispatch, sharing its scope ($conn,
| $userId, $userRole, $action). New actions exit here; for existing ones
| this file only adds recycle-bin deletes, check-out guards and activity
| logging, then falls through to the original code.
*/

if (!isset($conn, $userId, $userRole, $action)) {
    exit;
}

/** JSON response helper. */
function doc_lib_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Redirects back to the library. "return" is the page's own query string
 * (view=bin, folder=12, ...), restricted to safe characters.
 */
function doc_lib_back(string $fallback = ''): never
{
    $return = (string)($_POST['return'] ?? $_GET['return'] ?? $fallback);

    if (!preg_match('/^[A-Za-z0-9_=&%+.-]*$/', $return)) {
        $return = '';
    }

    header('Location: ../documents' . ($return !== '' ? '?' . $return : ''));
    exit;
}

function doc_lib_flash(int $done, int $failed, string $verb, array $errors = []): void
{
    if ($done > 0) {
        $_SESSION['success'] = $done . ' item' . ($done === 1 ? '' : 's') . ' ' . $verb . '.';
    }

    if ($failed > 0) {
        $_SESSION['error'] = $failed . ' item' . ($failed === 1 ? '' : 's') . ' could not be ' . $verb . '.'
            . ($errors ? ' ' . implode(' ', array_unique($errors)) : '');
    }
}

// Database clock (not PHP's) so "created during this request" matches NOW() rows.
$docLibStarted = in_array($action, ['upload', 'upload_folder', 'create_folder'], true)
    ? (string)(doc_lib_rows($conn, 'SELECT NOW() - INTERVAL 1 SECOND AS t')[0]['t'] ?? '')
    : '';

/* ======================================================================
 * Read-only endpoints
 * ====================================================================== */

if ($action === 'get_details') {
    $type = (string)($_GET['type'] ?? '');
    $id = (int)($_GET['id'] ?? 0);

    if (!in_array($type, ['folder', 'document'], true) || $id < 1) {
        doc_lib_json(['success' => false, 'error' => 'Invalid item.'], 400);
    }

    $details = doc_lib_details($conn, $userId, $userRole, $type, $id);

    if (!$details) {
        doc_lib_json(['success' => false, 'error' => 'Item not found or access denied.'], 404);
    }

    doc_lib_json(['success' => true, 'item' => $details]);
}

if ($action === 'get_move_targets') {
    $items = doc_lib_parse_items($_GET['items'] ?? []);
    $exclude = [];

    foreach ($items as [$type, $id]) {
        if (!doc_can($conn, $type, $id, $userId, $userRole, 'edit')) {
            doc_lib_json(['success' => false, 'options' => [], 'error' => 'You cannot move one of the selected items.'], 403);
        }

        if ($type === 'folder') {
            $exclude[] = $id;
        }
    }

    if (!$items) {
        doc_lib_json(['success' => false, 'options' => []], 400);
    }

    doc_lib_json([
        'success' => true,
        'options' => doc_get_movable_folder_options($conn, $userId, $userRole, $exclude),
    ]);
}

if ($action === 'thumb') {
    // Inline image for the info pane preview (images only, view right).
    $id = (int)($_GET['id'] ?? 0);

    if ($id < 1 || !doc_can($conn, 'document', $id, $userId, $userRole, 'view')) {
        http_response_code(403);
        exit;
    }

    $doc = doc_lib_document_row($conn, $id);
    $kind = $doc ? doc_lib_file_kind($doc) : ['kind' => ''];
    $path = (string)($doc['file_path'] ?? '');
    $absolute = dirname(__DIR__) . '/' . ltrim($path, '/');

    if ($kind['kind'] !== 'image' || $path === '' || str_contains($path, '..') || !is_file($absolute) || !empty($doc['deleted_at'])) {
        http_response_code(404);
        exit;
    }

    $mime = trim((string)($doc['original_mime_type'] ?? ''));

    if ($mime === '') {
        $mime = trim((string)($doc['mime_type'] ?? ''));
    }

    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp',
        ][$kind['ext'] ?? ''] ?? '';
    }

    if ($mime === '' || !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
        // SVG is never served inline (script risk); other images need a real image type.
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    header('Content-Disposition: inline');

    if (!empty($doc['is_compressed']) || str_ends_with(strtolower($absolute), '.gz')) {
        doc_stream_gzip_file($absolute);
    } else {
        header('Content-Length: ' . filesize($absolute));
        readfile($absolute);
    }

    exit;
}

if ($action === 'download_version') {
    $version = doc_lib_version_row($conn, (int)($_GET['version_id'] ?? 0));

    if (!$version || !doc_can($conn, 'document', (int)$version['document_id'], $userId, $userRole, 'download')) {
        http_response_code(403);
        exit('You do not have permission to download this version.');
    }

    $doc = doc_lib_document_row($conn, (int)$version['document_id']);
    doc_lib_log($conn, 'document', (int)$version['document_id'], $userId, 'download', 'Version ' . (int)$version['version_number']);
    doc_lib_stream_version($version, (string)($doc['document_name'] ?? 'document'));
}

/* ======================================================================
 * Favourites (JSON)
 * ====================================================================== */

if ($action === 'toggle_favorite') {
    $items = doc_lib_parse_items($_POST['items'] ?? []);
    $on = (string)($_POST['state'] ?? '1') === '1';

    if (!$items || !doc_lib_ready($conn)) {
        doc_lib_json(['success' => false, 'error' => 'Favourites are not available.'], 400);
    }

    $changed = 0;

    foreach ($items as [$type, $id]) {
        if (doc_can($conn, $type, $id, $userId, $userRole, 'view') && doc_lib_set_favorite($conn, $userId, $type, $id, $on)) {
            $changed++;
        }
    }

    doc_lib_json(['success' => $changed > 0, 'changed' => $changed, 'state' => $on]);
}

/* ======================================================================
 * Bulk delete (to the recycle bin) / move
 * ====================================================================== */

if ($action === 'bulk_delete') {
    $items = doc_lib_parse_items($_POST['items'] ?? []);
    $soft = doc_lib_bin_enabled($conn);
    $done = 0;
    $failed = 0;
    $errors = [];

    foreach ($items as [$type, $id]) {
        if (!doc_can($conn, $type, $id, $userId, $userRole, 'edit')) {
            $failed++;
            $errors[] = 'You do not have permission to delete some items.';
            continue;
        }

        if ($type === 'document' && ($holder = doc_lib_checkout_blocker($conn, $id, $userId)) !== null) {
            $failed++;
            $errors[] = 'Checked out to ' . $holder . '.';
            continue;
        }

        $ok = $soft
            ? doc_lib_soft_delete($conn, $type, $id, $userId)
            : !empty(($type === 'folder' ? doc_delete_folder_recursive($conn, $id) : deleteDocument($conn, $id))['success']);

        if ($ok) {
            $done++;

            if ($soft) {
                doc_lib_log($conn, $type, $id, $userId, 'delete');
            }
        } else {
            $failed++;
        }
    }

    doc_lib_flash($done, $failed, $soft ? 'moved to the recycle bin' : 'deleted', $errors);
    doc_lib_back();
}

if ($action === 'bulk_move') {
    $items = doc_lib_parse_items($_POST['items'] ?? []);
    $target = max(0, (int)($_POST['target_folder_id'] ?? -1));
    $done = 0;
    $failed = 0;
    $errors = [];

    foreach ($items as [$type, $id]) {
        if ($type === 'document' && ($holder = doc_lib_checkout_blocker($conn, $id, $userId)) !== null) {
            $failed++;
            $errors[] = 'Checked out to ' . $holder . '.';
            continue;
        }

        $result = $type === 'folder'
            ? doc_move_folder($conn, $id, $target, $userId, $userRole)
            : doc_move_document($conn, $id, $target, $userId, $userRole);

        if (!empty($result['success'])) {
            $done++;
            doc_lib_log($conn, $type, $id, $userId, 'move', 'To ' . doc_lib_folder_path($conn, $target));

            if ($type === 'document') {
                doc_lib_touch($conn, $id, $userId);
            }
        } else {
            $failed++;

            if (!empty($result['error'])) {
                $errors[] = (string)$result['error'];
            }
        }
    }

    doc_lib_flash($done, $failed, 'moved', $errors);

    header('Location: ../documents' . ($target > 0 ? '?folder=' . $target : ''));
    exit;
}

/* ======================================================================
 * Recycle bin
 * ====================================================================== */

if ($action === 'restore' || $action === 'purge') {
    $items = doc_lib_parse_items($_POST['items'] ?? []);
    $done = 0;
    $failed = 0;
    $movedToRoot = 0;

    foreach ($items as [$type, $id]) {
        if (!doc_lib_can_manage_recycled($conn, $type, $id, $userId)) {
            $failed++;
            continue;
        }

        if ($action === 'restore') {
            $result = doc_lib_restore($conn, $type, $id);

            if (!empty($result['success'])) {
                $done++;
                $movedToRoot += !empty($result['to_root']) ? 1 : 0;
                doc_lib_log($conn, $type, $id, $userId, 'restore');
            } else {
                $failed++;
            }

            continue;
        }

        !empty(doc_lib_purge($conn, $type, $id)['success']) ? $done++ : $failed++;
    }

    doc_lib_flash($done, $failed, $action === 'restore' ? 'restored' : 'permanently deleted');

    if ($movedToRoot > 0) {
        $_SESSION['success'] = ($_SESSION['success'] ?? '')
            . ' ' . $movedToRoot . ' went back to the library root because their original folder is no longer available.';
    }

    doc_lib_back('view=bin');
}

if ($action === 'empty_bin') {
    $done = 0;

    foreach (doc_lib_folders($conn, $userId, $userRole, ['deleted' => true]) as $folder) {
        $done += !empty(doc_lib_purge($conn, 'folder', (int)$folder['folder_id'])['success']) ? 1 : 0;
    }

    foreach (doc_lib_documents($conn, $userId, $userRole, ['deleted' => true]) as $doc) {
        $done += !empty(doc_lib_purge($conn, 'document', (int)$doc['document_id'])['success']) ? 1 : 0;
    }

    $_SESSION['success'] = $done > 0
        ? 'Recycle bin emptied (' . $done . ' item' . ($done === 1 ? '' : 's') . ' permanently deleted).'
        : 'The recycle bin is already empty.';

    doc_lib_back('view=bin');
}

/* ======================================================================
 * Versions and check-out
 * ====================================================================== */

if ($action === 'upload_version') {
    $documentId = (int)($_POST['document_id'] ?? 0);

    if ($documentId < 1 || !doc_can($conn, 'document', $documentId, $userId, $userRole, 'edit')) {
        $_SESSION['error'] = 'You do not have permission to update this file.';
        doc_lib_back();
    }

    if (($holder = doc_lib_checkout_blocker($conn, $documentId, $userId)) !== null) {
        $_SESSION['error'] = 'This file is checked out to ' . $holder . '.';
        doc_lib_back();
    }

    $file = $_FILES['version_file'] ?? null;

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $_SESSION['error'] = 'Choose the file to upload as the new version.';
        doc_lib_back();
    }

    $comment = mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 255);
    $result = doc_lib_add_version($conn, $documentId, $file, $userId, $comment);

    if (!empty($result['success'])) {
        doc_lib_log($conn, 'document', $documentId, $userId, 'version', 'Version ' . (int)$result['version'] . '.0' . ($comment !== '' ? ': ' . $comment : ''));

        if (!empty($_POST['check_in'])) {
            doc_lib_set_checkout($conn, $documentId, $userId, false);
            doc_lib_log($conn, 'document', $documentId, $userId, 'checkin');
        }

        $_SESSION['success'] = 'Version ' . (int)$result['version'] . '.0 uploaded.';
    } else {
        $_SESSION['error'] = (string)($result['error'] ?? 'Could not upload the new version.');
    }

    doc_lib_back();
}

if ($action === 'restore_version') {
    $version = doc_lib_version_row($conn, (int)($_POST['version_id'] ?? 0));
    $documentId = (int)($version['document_id'] ?? 0);

    if (!$version || !doc_can($conn, 'document', $documentId, $userId, $userRole, 'edit')) {
        $_SESSION['error'] = 'You do not have permission to restore this version.';
        doc_lib_back();
    }

    if (($holder = doc_lib_checkout_blocker($conn, $documentId, $userId)) !== null) {
        $_SESSION['error'] = 'This file is checked out to ' . $holder . '.';
        doc_lib_back();
    }

    $result = doc_lib_restore_version($conn, (int)$version['version_id'], $userId);

    if (!empty($result['success'])) {
        doc_lib_log($conn, 'document', $documentId, $userId, 'restore_version', 'Version ' . (int)$result['restored'] . '.0 is now ' . (int)$result['version'] . '.0');
        $_SESSION['success'] = 'Version ' . (int)$result['restored'] . '.0 restored as version ' . (int)$result['version'] . '.0.';
    } else {
        $_SESSION['error'] = (string)($result['error'] ?? 'Could not restore the version.');
    }

    doc_lib_back();
}

if ($action === 'checkout' || $action === 'checkin') {
    $documentId = (int)($_POST['document_id'] ?? 0);

    if ($documentId < 1 || !doc_can($conn, 'document', $documentId, $userId, $userRole, 'edit')) {
        $_SESSION['error'] = 'You do not have permission to change this file.';
        doc_lib_back();
    }

    if (($holder = doc_lib_checkout_blocker($conn, $documentId, $userId)) !== null) {
        $_SESSION['error'] = 'This file is already checked out to ' . $holder . '.';
        doc_lib_back();
    }

    if (doc_lib_set_checkout($conn, $documentId, $userId, $action === 'checkout')) {
        doc_lib_log($conn, 'document', $documentId, $userId, $action);
        $_SESSION['success'] = $action === 'checkout'
            ? 'File checked out. Others can view it but not change it until you check it in.'
            : 'File checked in.';
    } else {
        $_SESSION['error'] = 'Check-out is not available on this server yet.';
    }

    doc_lib_back();
}

/* ======================================================================
 * Hooks into the handler's existing actions
 * ====================================================================== */

// Single-item deletes from older links go to the recycle bin as well.
if (in_array($action, ['delete_document', 'delete_folder'], true) && doc_lib_bin_enabled($conn)) {
    $type = $action === 'delete_folder' ? 'folder' : 'document';
    $id = (int)($_POST[$type === 'folder' ? 'folder_id' : 'document_id'] ?? 0);
    $backTo = (int)($_POST[$type === 'folder' ? 'parent_folder_id' : 'folder_id'] ?? 0);

    if ($id > 0 && doc_can($conn, $type, $id, $userId, $userRole, 'edit')
        && ($type === 'folder' || doc_lib_checkout_blocker($conn, $id, $userId) === null)
        && doc_lib_soft_delete($conn, $type, $id, $userId)) {
        doc_lib_log($conn, $type, $id, $userId, 'delete');
        $_SESSION['success'] = ($type === 'folder' ? 'Folder' : 'Document') . ' moved to the recycle bin.';
    } else {
        $_SESSION['error'] = 'Could not delete this item.';
    }

    doc_lib_back($backTo > 0 ? 'folder=' . $backTo : '');
}

if ($action === 'edit_document') {
    $documentId = (int)($_POST['document_id'] ?? 0);

    if (($holder = doc_lib_checkout_blocker($conn, $documentId, $userId)) !== null) {
        $_SESSION['error'] = 'This file is checked out to ' . $holder . '.';
        doc_lib_back();
    }
}

if (in_array($action, ['view', 'download'], true)) {
    $documentId = (int)($_GET['id'] ?? 0);

    $live = $documentId > 0 ? doc_lib_document_row($conn, $documentId) : null;

    if ($live && !empty($live['deleted_at'])) {
        http_response_code(404);
        exit('This file is in the recycle bin. Restore it to open it.');
    }

    if ($live && doc_can($conn, 'document', $documentId, $userId, $userRole, $action === 'download' ? 'download' : 'view')) {
        doc_lib_log($conn, 'document', $documentId, $userId, $action);
    }
}

/*
 * The remaining actions redirect with a flash message when they succeed.
 * Log them after the original code has run, when that message is set.
 */
$docLibFlashBefore = $_SESSION['success'] ?? null;

register_shutdown_function(static function () use ($conn, $userId, $action, $docLibStarted, $docLibFlashBefore): void {
    $flash = $_SESSION['success'] ?? null;

    if (empty($flash) || $flash === $docLibFlashBefore || !doc_lib_ready($conn)) {
        return;
    }

    $since = $docLibStarted;

    switch ($action) {
        case 'upload':
        case 'upload_folder':
            if ($since === '') {
                break;
            }

            foreach (doc_lib_rows($conn, 'SELECT document_id FROM documents WHERE uploaded_by = ? AND upload_date >= ?', 'is', [$userId, $since]) as $row) {
                doc_lib_log($conn, 'document', (int)$row['document_id'], $userId, 'upload');
            }

            if ($action === 'upload') {
                break;
            }
            // folder uploads also create folders
            // no break
        case 'create_folder':
            if ($since === '') {
                break;
            }

            foreach (doc_lib_rows($conn, 'SELECT folder_id FROM document_folders WHERE created_by = ? AND created_at >= ?', 'is', [$userId, $since]) as $row) {
                doc_lib_log($conn, 'folder', (int)$row['folder_id'], $userId, 'create');
            }
            break;

        case 'edit_document':
            $id = (int)($_POST['document_id'] ?? 0);
            doc_lib_touch($conn, $id, $userId);
            doc_lib_log($conn, 'document', $id, $userId, 'edit');
            break;

        case 'edit_folder':
            doc_lib_log($conn, 'folder', (int)($_POST['folder_id'] ?? 0), $userId, 'edit');
            break;

        case 'save_permissions':
            $type = (string)($_POST['resource_type'] ?? '');

            if (in_array($type, ['folder', 'document'], true)) {
                doc_lib_log($conn, $type, (int)($_POST['resource_id'] ?? 0), $userId, 'share');
            }
            break;

        case 'move_document':
        case 'move_folder':
            doc_lib_log($conn, $action === 'move_folder' ? 'folder' : 'document', (int)($_POST['resource_id'] ?? 0), $userId, 'move');
            break;
    }
});
