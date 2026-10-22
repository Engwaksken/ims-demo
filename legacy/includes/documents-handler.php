<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/documents-process.php';

$composerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

function doc_preview_escape(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function doc_preview_extension(
    string $fileName,
    string $absolutePath,
    array $document
): string {
    $extension = strtolower(trim((string)($document['original_extension'] ?? '')));

    if ($extension !== '') {
        return ltrim($extension, '.');
    }

    $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));

    if ($extension !== '') {
        return $extension;
    }

    $path = $absolutePath;

    if (str_ends_with(strtolower($path), '.gz')) {
        $path = substr($path, 0, -3);
    }

    return strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
}

function doc_preview_temp_file(
    string $absolutePath,
    bool $isCompressed,
    string $extension = ''
): array {
    if (!$isCompressed) {
        return [
            'path' => $absolutePath,
            'temporary' => false,
        ];
    }

    $suffix = $extension !== '' ? '.' . ltrim($extension, '.') : '';

    $tempBase = tempnam(sys_get_temp_dir(), 'ims_doc_');

    if ($tempBase === false) {
        throw new RuntimeException('Could not create temporary preview file.');
    }

    $tempPath = $tempBase . $suffix;
    @unlink($tempBase);

    $source = gzopen($absolutePath, 'rb');

    if ($source === false) {
        throw new RuntimeException('Could not open compressed document.');
    }

    $target = fopen($tempPath, 'wb');

    if ($target === false) {
        gzclose($source);
        throw new RuntimeException('Could not create decompressed preview file.');
    }

    while (!gzeof($source)) {
        $chunk = gzread($source, 1024 * 1024);

        if ($chunk === false) {
            fclose($target);
            gzclose($source);
            @unlink($tempPath);

            throw new RuntimeException('Could not decompress document.');
        }

        fwrite($target, $chunk);
    }

    fclose($target);
    gzclose($source);

    return [
        'path' => $tempPath,
        'temporary' => true,
    ];
}

function doc_preview_cleanup(?string $path, bool $temporary): void
{
    if ($temporary && $path && is_file($path)) {
        @unlink($path);
    }
}

function doc_preview_html_shell(
    string $title,
    string $body,
    string $downloadUrl,
    string $backUrl,
    string $typeLabel = ''
): never {
    $safeTitle = doc_preview_escape($title);
    $safeDownload = doc_preview_escape($downloadUrl);
    $safeBack = doc_preview_escape($backUrl);
    $safeType = doc_preview_escape($typeLabel);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $safeTitle . '</title>';
    echo '<style>
        *{box-sizing:border-box}
        body{margin:0;background:#f8fafc;color:#0f172a;font-family:Arial,Helvetica,sans-serif}
        .preview-topbar{position:sticky;top:0;z-index:100;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid #e2e8f0;background:#fff}
        .preview-title{min-width:0}
        .preview-title strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px}
        .preview-title small{color:#64748b;font-size:10px}
        .preview-actions{display:flex;gap:8px;flex-wrap:wrap}
        .preview-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 11px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;text-decoration:none;font-size:11px;font-weight:700}
        .preview-btn.primary{border-color:#0f766e;background:#0f766e;color:#fff}
        .preview-stage{padding:18px}
        .preview-document{width:min(100%,1200px);margin:0 auto;overflow:auto;border:1px solid #e2e8f0;border-radius:10px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.08)}
        .preview-document-inner{padding:22px}
        .preview-document table{border-collapse:collapse;max-width:100%}
        .preview-document td,.preview-document th{padding:6px 8px;border:1px solid #dbe3ec}
        .preview-empty{padding:50px 24px;text-align:center;color:#64748b}
        .preview-empty h2{margin:0 0 8px;color:#0f172a;font-size:18px}
        .preview-text{margin:0;padding:18px;overflow:auto;white-space:pre-wrap;word-break:break-word;font-family:Consolas,Monaco,monospace;font-size:12px;line-height:1.6}
        .preview-media{display:block;max-width:100%;max-height:calc(100vh - 130px);margin:0 auto}
    </style>';
    echo '</head><body>';
    echo '<header class="preview-topbar">';
    echo '<div class="preview-title"><strong>' . $safeTitle . '</strong>';
    if ($safeType !== '') {
        echo '<small>' . $safeType . '</small>';
    }
    echo '</div>';
    echo '<div class="preview-actions">';
    echo '<a class="preview-btn" href="' . $safeBack . '">Back</a>';
    echo '<a class="preview-btn primary" href="' . $safeDownload . '">Download</a>';
    echo '</div></header>';
    echo '<main class="preview-stage"><section class="preview-document">';
    echo $body;
    echo '</section></main>';
    echo '</body></html>';
    exit;
}

function doc_preview_unsupported(
    string $title,
    string $downloadUrl,
    string $backUrl,
    string $extension,
    string $message = ''
): never {
    $label = $extension !== '' ? strtoupper($extension) . ' file' : 'File';

    if ($message === '') {
        $message =
            'This file type cannot be rendered directly in the browser, '
            . 'but you can still download and open it with the appropriate application.';
    }

    $body =
        '<div class="preview-empty">'
        . '<h2>' . doc_preview_escape($label) . '</h2>'
        . '<p>' . doc_preview_escape($message) . '</p>'
        . '</div>';

    doc_preview_html_shell(
        $title,
        $body,
        $downloadUrl,
        $backUrl,
        $label
    );
}


$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');

if ($userId < 1) {
    http_response_code(401);
    exit('Unauthorized');
}

function doc_redirect_back(int $folderId = 0): never
{
    $url = '../documents';

    if ($folderId > 0) {
        $url .= '?folder=' . $folderId;
    }

    header('Location: ' . $url);
    exit;
}

$action = trim((string)(
    $_POST['action']
    ?? $_GET['action']
    ?? ''
));

if ($action === 'create_folder') {
    $parentFolderId = (int)($_POST['parent_folder_id'] ?? 0);

    if (
        $parentFolderId > 0
        &&
        !doc_can(
            $conn,
            'folder',
            $parentFolderId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] = 'You do not have permission to create a folder here.';
        doc_redirect_back($parentFolderId);
    }

    $result = doc_create_folder(
        $conn,
        $_POST,
        $userId
    );

    if ($result['success']) {
        $_SESSION['success'] = 'Folder created successfully. You can now add files to it.';

        $newFolderId = (int)($result['folder_id'] ?? 0);

        if ($newFolderId > 0) {
            header(
                'Location: ../documents?folder='
                . $newFolderId
                . '&upload=1'
            );
            exit;
        }
    } else {
        $_SESSION['error'] = $result['error'] ?? 'Could not create folder.';
    }

    doc_redirect_back($parentFolderId);
}

if ($action === 'upload_folder') {
    $baseFolderId = (int)($_POST['folder_id'] ?? 0);

    if (
        $baseFolderId < 1
        ||
        !doc_can(
            $conn,
            'folder',
            $baseFolderId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] =
            'You do not have permission to upload a folder here.';

        doc_redirect_back($baseFolderId);
    }

    $baseFolder = doc_get_folder(
        $conn,
        $baseFolderId,
        $userId,
        $userRole
    );

    $projectId = !empty($baseFolder['project_id'])
        ? (int)$baseFolder['project_id']
        : null;

    $programId = !empty($baseFolder['program_id'])
        ? (int)$baseFolder['program_id']
        : null;

    $files = doc_normalize_uploaded_files(
        $_FILES['folder_documents']
        ?? []
    );

    if (!$files) {
        $_SESSION['error'] =
            'Select a folder to upload.';

        doc_redirect_back($baseFolderId);
    }

    $uploaded = 0;
    $failed = [];

    foreach ($files as $file) {
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            ===
            UPLOAD_ERR_NO_FILE
        ) {
            continue;
        }

        $relativePath = trim(
            str_replace(
                '\\',
                '/',
                (string)(
                    $file['full_path']
                    ?? $file['name']
                    ?? ''
                )
            ),
            '/'
        );

        $parts = array_values(
            array_filter(
                explode('/', $relativePath),
                static fn($part) => $part !== ''
            )
        );

        if (!$parts) {
            continue;
        }

        $fileName = array_pop($parts);

        try {
            $targetFolderId = doc_create_folder_path(
                $conn,
                $baseFolderId,
                $parts,
                $userId,
                $projectId,
                $programId
            );

            $fileData = [
                'folder_id' => $targetFolderId,
                'document_name' => pathinfo(
                    $fileName,
                    PATHINFO_FILENAME
                ),
                'document_type' => 'Inherited',
                'project_id' => $projectId,
                'program_id' => $programId,
                'description' => '',
                'tags' => '',
                'uploaded_by' => $userId,
            ];

            $result = uploadDocument(
                $conn,
                $file,
                $fileData
            );

            if (!empty($result['success'])) {
                $uploaded++;
            } else {
                $failed[] =
                    $relativePath
                    . ': '
                    . implode(
                        ' ',
                        $result['errors']
                        ?? ['Upload failed.']
                    );
            }
        } catch (Throwable $e) {
            $failed[] =
                $relativePath
                . ': '
                . $e->getMessage();
        }
    }

    if ($uploaded > 0) {
        $_SESSION['success'] =
            $uploaded
            . ' file'
            . ($uploaded === 1 ? '' : 's')
            . ' uploaded successfully.';
    }

    if ($failed) {
        $_SESSION['error'] =
            'Some items could not be uploaded: '
            . implode(' | ', $failed);
    }

    if ($uploaded === 0 && !$failed) {
        $_SESSION['error'] =
            'No files were uploaded.';
    }

    doc_redirect_back($baseFolderId);
}


if ($action === 'upload') {
    $folderId = (int)($_POST['folder_id'] ?? 0);

    if (
        $folderId > 0
        &&
        !doc_can(
            $conn,
            'folder',
            $folderId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] =
            'You do not have permission to upload to this folder.';

        doc_redirect_back($folderId);
    }

    $data = $_POST;
    $data['uploaded_by'] = $userId;

    $files = doc_normalize_uploaded_files(
        $_FILES['documents']
        ?? []
    );

    if (!$files) {
        $_SESSION['error'] =
            'Select at least one file to upload.';

        doc_redirect_back($folderId);
    }

    $uploaded = 0;
    $failed = [];

    foreach ($files as $file) {
        /*
         * Skip empty file slots created by the browser.
         */
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            ===
            UPLOAD_ERR_NO_FILE
        ) {
            continue;
        }

        /*
         * When multiple files are selected, use each original
         * filename as the document name. For a single file,
         * preserve the manually entered Document Name.
         */
        $fileData = $data;

        if (
            $folderId > 0
            ||
            count($files) > 1
        ) {
            $fileData['document_name'] =
                pathinfo(
                    (string)($file['name'] ?? 'Document'),
                    PATHINFO_FILENAME
                );
        }

        $result = uploadDocument(
            $conn,
            $file,
            $fileData
        );

        if (!empty($result['success'])) {
            $uploaded++;
            continue;
        }

        $name = (string)(
            $file['name']
            ?? 'Unknown file'
        );

        $errorText = implode(
            ' ',
            $result['errors']
            ?? ['Upload failed.']
        );

        $failed[] =
            $name
            . ': '
            . $errorText;
    }

    if ($uploaded > 0 && !$failed) {
        $_SESSION['success'] =
            $uploaded
            . ' file'
            . ($uploaded === 1 ? '' : 's')
            . ' uploaded successfully.';
    } elseif ($uploaded > 0) {
        $_SESSION['success'] =
            $uploaded
            . ' file'
            . ($uploaded === 1 ? '' : 's')
            . ' uploaded successfully.';

        $_SESSION['error'] =
            'Some files could not be uploaded: '
            . implode(' | ', $failed);
    } else {
        $_SESSION['error'] =
            'No files were uploaded. '
            . implode(' | ', $failed);
    }

    doc_redirect_back($folderId);
}

if ($action === 'get_permissions') {
    header('Content-Type: application/json; charset=utf-8');

    $resourceType = trim((string)($_GET['resource_type'] ?? ''));
    $resourceId = (int)($_GET['resource_id'] ?? 0);

    if (
        !in_array($resourceType, ['folder', 'document'], true)
        ||
        $resourceId < 1
    ) {
        echo json_encode([
            'success' => false,
            'permissions' => [],
        ]);
        exit;
    }

    if (
        !doc_can(
            $conn,
            $resourceType,
            $resourceId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'permissions' => [],
        ]);
        exit;
    }

    $permissions = [];

    $stmt = $conn->prepare("
        SELECT
            user_id,
            can_view,
            can_edit,
            can_download
        FROM document_permissions
        WHERE resource_type = ?
          AND resource_id = ?
        ORDER BY user_id
    ");

    if ($stmt) {
        $stmt->bind_param(
            'si',
            $resourceType,
            $resourceId
        );

        $stmt->execute();

        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $permissions[] = $row;
        }

        $stmt->close();
    }

    echo json_encode([
        'success' => true,
        'permissions' => $permissions,
    ]);

    exit;
}

if ($action === 'get_folder_options') {
    header('Content-Type: application/json; charset=utf-8');

    $resourceType = trim((string)($_GET['type'] ?? ''));
    $resourceId = (int)($_GET['id'] ?? 0);

    if (
        !in_array($resourceType, ['folder', 'document'], true)
        ||
        $resourceId < 1
    ) {
        echo json_encode([
            'success' => false,
            'options' => [],
        ]);
        exit;
    }

    if (
        !doc_can(
            $conn,
            $resourceType,
            $resourceId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'options' => [],
        ]);
        exit;
    }

    $excludeFolderId = $resourceType === 'folder' ? $resourceId : 0;

    $options = doc_get_movable_folder_options(
        $conn,
        $userId,
        $userRole,
        $excludeFolderId
    );

    echo json_encode([
        'success' => true,
        'options' => $options,
    ]);

    exit;
}

if ($action === 'move_document') {
    $documentId = (int)($_POST['resource_id'] ?? 0);
    $currentFolderId = (int)($_POST['current_folder_id'] ?? 0);
    $targetFolderId = max(0, (int)($_POST['target_folder_id'] ?? -1));

    $result = doc_move_document(
        $conn,
        $documentId,
        $targetFolderId,
        $userId,
        $userRole
    );

    if ($result['success']) {
        $_SESSION['success'] = 'Document moved successfully.';
        doc_redirect_back($targetFolderId);
    }

    $_SESSION['error'] = $result['error'] ?? 'Could not move document.';
    doc_redirect_back($currentFolderId);
}

if ($action === 'move_folder') {
    $folderId = (int)($_POST['resource_id'] ?? 0);
    $currentFolderId = (int)($_POST['current_folder_id'] ?? 0);
    $targetFolderId = max(0, (int)($_POST['target_folder_id'] ?? -1));

    $result = doc_move_folder(
        $conn,
        $folderId,
        $targetFolderId,
        $userId,
        $userRole
    );

    if ($result['success']) {
        $_SESSION['success'] = 'Folder moved successfully.';
        doc_redirect_back($targetFolderId);
    }

    $_SESSION['error'] = $result['error'] ?? 'Could not move folder.';
    doc_redirect_back($currentFolderId);
}


if ($action === 'save_permissions') {
    $resourceType = trim((string)($_POST['resource_type'] ?? ''));
    $resourceId = (int)($_POST['resource_id'] ?? 0);

    if (
        !in_array($resourceType, ['folder', 'document'], true)
        ||
        $resourceId < 1
    ) {
        $_SESSION['error'] = 'Invalid permission target.';
        doc_redirect_back();
    }

    if (
        !doc_can(
            $conn,
            $resourceType,
            $resourceId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] = 'You do not have permission to manage access.';
        doc_redirect_back();
    }

    $permissions = $_POST['permissions'] ?? [];

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            DELETE FROM document_permissions
            WHERE resource_type = ?
              AND resource_id = ?
        ");

        if (!$stmt) {
            throw new RuntimeException('Could not clear permissions.');
        }

        $stmt->bind_param(
            'si',
            $resourceType,
            $resourceId
        );

        $stmt->execute();
        $stmt->close();

        $insert = $conn->prepare("
            INSERT INTO document_permissions
            (
                resource_type,
                resource_id,
                user_id,
                can_view,
                can_edit,
                can_download,
                granted_by,
                created_at,
                updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        if (!$insert) {
            throw new RuntimeException('Could not save permissions.');
        }

        foreach ($permissions as $targetUserId => $rights) {
            $targetUserId = (int)$targetUserId;

            if ($targetUserId < 1) {
                continue;
            }

            $canView = !empty($rights['view']) ? 1 : 0;
            $canEdit = !empty($rights['edit']) ? 1 : 0;
            $canDownload = !empty($rights['download']) ? 1 : 0;

            if ($canEdit || $canDownload) {
                $canView = 1;
            }

            if (!$canView && !$canEdit && !$canDownload) {
                continue;
            }

            $insert->bind_param(
                'siiiiii',
                $resourceType,
                $resourceId,
                $targetUserId,
                $canView,
                $canEdit,
                $canDownload,
                $userId
            );

            $insert->execute();
        }

        $insert->close();

        $conn->commit();

        $_SESSION['success'] = 'Permissions updated successfully.';
    } catch (Throwable $e) {
        $conn->rollback();

        error_log(
            'Document permission update failed: '
            . $e->getMessage()
        );

        $_SESSION['error'] = 'Could not update permissions.';
    }

    doc_redirect_back();
}


if ($action === 'edit_folder') {
    $folderId = (int)($_POST['folder_id'] ?? 0);
    $parentFolderId = (int)($_POST['parent_folder_id'] ?? 0);

    if (
        $folderId < 1
        ||
        !doc_can(
            $conn,
            'folder',
            $folderId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] =
            'You do not have permission to edit this folder.';

        doc_redirect_back($parentFolderId);
    }

    $folderName = trim(
        (string)($_POST['folder_name'] ?? '')
    );

    $documentType = trim(
        (string)($_POST['document_type'] ?? '')
    );

    $otherType = trim(
        (string)($_POST['other_type_name'] ?? '')
    );

    $description = trim(
        (string)($_POST['description'] ?? '')
    );

    $projectId = !empty($_POST['project_id'])
        ? (int)$_POST['project_id']
        : null;

    $programId = !empty($_POST['program_id'])
        ? (int)$_POST['program_id']
        : null;

    if ($folderName === '') {
        $_SESSION['error'] = 'Folder name is required.';
        doc_redirect_back($parentFolderId);
    }

    if ($documentType === 'Other') {
        if ($otherType === '') {
            $_SESSION['error'] =
                'Enter the Other folder type name.';

            doc_redirect_back($parentFolderId);
        }

        $documentType = $otherType;
    }

    if ($documentType === '') {
        $_SESSION['error'] = 'Folder type is required.';
        doc_redirect_back($parentFolderId);
    }

    if ($projectId && $programId) {
        $_SESSION['error'] =
            'Select either a project or a program, not both.';

        doc_redirect_back($parentFolderId);
    }

    $stmt = $conn->prepare("
        UPDATE document_folders
        SET
            folder_name = ?,
            document_type = ?,
            description = NULLIF(?, ''),
            project_id = ?,
            program_id = ?
        WHERE folder_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        $_SESSION['error'] =
            'Could not prepare folder update.';

        doc_redirect_back($parentFolderId);
    }

    $stmt->bind_param(
        'sssiii',
        $folderName,
        $documentType,
        $description,
        $projectId,
        $programId,
        $folderId
    );

    if ($stmt->execute()) {
        $_SESSION['success'] =
            'Folder updated successfully.';
    } else {
        error_log(
            'Folder update failed: '
            . $stmt->error
        );

        $_SESSION['error'] =
            'Could not update folder.';
    }

    $stmt->close();

    doc_redirect_back($parentFolderId);
}


if ($action === 'delete_folder') {
    $folderId = (int)($_POST['folder_id'] ?? 0);
    $parentFolderId = (int)($_POST['parent_folder_id'] ?? 0);

    if (
        $folderId < 1
        ||
        !doc_can(
            $conn,
            'folder',
            $folderId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] =
            'You do not have permission to delete this folder.';

        doc_redirect_back($parentFolderId);
    }

    $result = doc_delete_folder_recursive(
        $conn,
        $folderId
    );

    $_SESSION[
        !empty($result['success'])
            ? 'success'
            : 'error'
    ] = !empty($result['success'])
        ? 'Folder deleted successfully.'
        : ($result['error'] ?? 'Could not delete folder.');

    doc_redirect_back($parentFolderId);
}


if ($action === 'edit_document') {
    $documentId = (int)($_POST['document_id'] ?? 0);
    $folderId = (int)($_POST['folder_id'] ?? 0);

    if (
        $documentId < 1
        ||
        !doc_can(
            $conn,
            'document',
            $documentId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] = 'You do not have permission to edit this document.';
        doc_redirect_back($folderId);
    }

    $documentName = trim((string)($_POST['document_name'] ?? ''));
    $documentType = trim((string)($_POST['document_type'] ?? ''));
    $otherType = trim((string)($_POST['other_type_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $tags = trim((string)($_POST['tags'] ?? ''));

    $projectId = !empty($_POST['project_id'])
        ? (int)$_POST['project_id']
        : null;

    $programId = !empty($_POST['program_id'])
        ? (int)$_POST['program_id']
        : null;

    if ($documentName === '') {
        $_SESSION['error'] = 'Document name is required.';
        doc_redirect_back($folderId);
    }

    if ($documentType === 'Other') {
        if ($otherType === '') {
            $_SESSION['error'] = 'Enter the Other document type name.';
            doc_redirect_back($folderId);
        }

        $documentType = $otherType;
    }

    if ($documentType === '') {
        $_SESSION['error'] = 'Document type is required.';
        doc_redirect_back($folderId);
    }

    if ($projectId && $programId) {
        $_SESSION['error'] = 'Select either a project or a program, not both.';
        doc_redirect_back($folderId);
    }

    $stmt = $conn->prepare("
        UPDATE documents
        SET
            document_name = ?,
            document_type = ?,
            project_id = ?,
            program_id = ?,
            description = NULLIF(?, ''),
            tags = NULLIF(?, '')
        WHERE document_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        $_SESSION['error'] = 'Could not prepare document update.';
        doc_redirect_back($folderId);
    }

    $stmt->bind_param(
        'ssiissi',
        $documentName,
        $documentType,
        $projectId,
        $programId,
        $description,
        $tags,
        $documentId
    );

    if ($stmt->execute()) {
        $_SESSION['success'] = 'Document updated successfully.';
    } else {
        error_log(
            'Document update failed: '
            . $stmt->error
        );

        $_SESSION['error'] = 'Could not update document.';
    }

    $stmt->close();

    doc_redirect_back($folderId);
}


if ($action === 'delete_document') {
    $documentId = (int)($_POST['document_id'] ?? 0);
    $folderId = (int)($_POST['folder_id'] ?? 0);

    if (
        $documentId < 1
        ||
        !doc_can(
            $conn,
            'document',
            $documentId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] = 'You do not have permission to delete this file.';
        doc_redirect_back($folderId);
    }

    $result = deleteDocument(
        $conn,
        $documentId
    );

    $_SESSION[
        $result['success']
            ? 'success'
            : 'error'
    ] = $result['success']
        ? 'Document deleted successfully.'
        : ($result['error'] ?? 'Could not delete document.');

    doc_redirect_back($folderId);
}


if ($action === 'delete') {
    csrf_protect(true); // GET state change: the UI uses POST delete_document; links need a token
    $documentId = (int)($_GET['id'] ?? 0);

    if (
        $documentId < 1
        ||
        !doc_can(
            $conn,
            'document',
            $documentId,
            $userId,
            $userRole,
            'edit'
        )
    ) {
        $_SESSION['error'] = 'You do not have permission to delete this file.';
        doc_redirect_back();
    }

    $folderId = 0;

    $stmt = $conn->prepare("
        SELECT folder_id
        FROM documents
        WHERE document_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $documentId);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $folderId = (int)($row['folder_id'] ?? 0);

        $stmt->close();
    }

    $result = deleteDocument(
        $conn,
        $documentId
    );

    $_SESSION[
        $result['success']
            ? 'success'
            : 'error'
    ] = $result['success']
        ? 'Document deleted successfully.'
        : ($result['error'] ?? 'Could not delete document.');

    doc_redirect_back($folderId);
}

if (in_array($action, ['view', 'download'], true)) {
    $documentId = (int)($_GET['id'] ?? 0);

    if ($documentId < 1) {
        http_response_code(404);
        exit('Document not found.');
    }

    $right = $action === 'download' ? 'download' : 'view';

    if (
        !doc_can(
            $conn,
            'document',
            $documentId,
            $userId,
            $userRole,
            $right
        )
    ) {
        http_response_code(403);
        exit('You do not have permission to access this document.');
    }

    $hasCompressionColumns =
        doc_column_exists(
            $conn,
            'documents',
            'is_compressed'
        );

    if ($hasCompressionColumns) {
        $stmt = $conn->prepare("
            SELECT
                folder_id,
                document_name,
                file_path,
                file_size,
                original_file_size,
                original_filename,
                original_mime_type,
                original_extension,
                is_compressed
            FROM documents
            WHERE document_id = ?
            LIMIT 1
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT
                folder_id,
                document_name,
                file_path,
                file_size
            FROM documents
            WHERE document_id = ?
            LIMIT 1
        ");
    }

    if (!$stmt) {
        http_response_code(404);
        exit('Document not found.');
    }

    $stmt->bind_param('i', $documentId);
    $stmt->execute();

    $document =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$document) {
        http_response_code(404);
        exit('Document not found.');
    }

    $absolutePath =
        dirname(__DIR__)
        . '/'
        . ltrim(
            (string)$document['file_path'],
            '/'
        );

    if (!is_file($absolutePath)) {
        http_response_code(404);
        exit('File is missing.');
    }

    $isCompressed = (
        !empty($document['is_compressed'])
        ||
        str_ends_with(
            strtolower($absolutePath),
            '.gz'
        )
    );

    $downloadName =
        trim(
            (string)(
                $document['original_filename']
                ?? ''
            )
        );

    if ($downloadName === '') {
        $extension =
            trim(
                (string)(
                    $document['original_extension']
                    ?? ''
                )
            );

        $downloadName =
            (string)$document['document_name'];

        if (
            $extension !== ''
            &&
            !str_ends_with(
                strtolower($downloadName),
                '.' . strtolower($extension)
            )
        ) {
            $downloadName .= '.' . ltrim($extension, '.');
        }
    }

    if ($downloadName === '') {
        $downloadName =
            basename(
                preg_replace(
                    '/\.gz$/i',
                    '',
                    $absolutePath
                )
            );
    }

    $extension =
        doc_preview_extension(
            $downloadName,
            $absolutePath,
            $document
        );

    $mime =
        trim(
            (string)(
                $document['original_mime_type']
                ?? ''
            )
        );

    if ($action === 'download') {
        if ($mime === '') {
            $mime =
                $isCompressed
                    ? 'application/octet-stream'
                    : (
                        mime_content_type($absolutePath)
                        ?: 'application/octet-stream'
                    );
        }

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');

        header(
            'Content-Disposition: attachment; filename="'
            . str_replace(
                ['"', "\r", "\n"],
                '',
                basename($downloadName)
            )
            . '"'
        );

        if ($isCompressed) {
            if (!doc_stream_gzip_file($absolutePath)) {
                http_response_code(500);
                exit('Could not decompress document.');
            }
        } else {
            header('Content-Length: ' . filesize($absolutePath));
            readfile($absolutePath);
        }

        exit;
    }

    $requestScript =
        basename(
            (string)(
                $_SERVER['SCRIPT_NAME']
                ?? 'document-actions.php'
            )
        );

    $downloadUrl =
        $requestScript
        . '?action=download&id='
        . $documentId;

    $folderId =
        max(
            0,
            (int)(
                $document['folder_id']
                ?? 0
            )
        );

    $backUrl =
        '../documents';

    if ($folderId > 0) {
        $backUrl .=
            '?folder='
            . $folderId;
    }

    $tempPath = null;
    $temporary = false;

    try {
        if (in_array($extension, ['ppt', 'pptx'], true)) {
            header('Cache-Control: private, no-store');
            doc_preview_unsupported(
                $downloadName,
                $downloadUrl,
                $backUrl,
                $extension,
                'PowerPoint presentations cannot be previewed on this platform. Click Download to open the file in Microsoft PowerPoint or LibreOffice.'
            );
        }

        $materialized =
            doc_preview_temp_file(
                $absolutePath,
                $isCompressed,
                $extension
            );

        $previewPath = (string)$materialized['path'];
        $tempPath = $previewPath;
        $temporary = !empty($materialized['temporary']);

        if ($mime === '') {
            $mime =
                mime_content_type($previewPath)
                ?: 'application/octet-stream';
        }

        $imageExtensions = [
            'jpg','jpeg','png','gif','webp','bmp','svg',
        ];

        $audioExtensions = [
            'mp3','wav','ogg','m4a','aac','flac',
        ];

        $videoExtensions = [
            'mp4','webm','ogv','mov','m4v',
        ];

        $textExtensions = [
            'txt','log','md','json','xml','yaml','yml',
            'ini','sql','php','js','css',
        ];

        if ($extension === 'pdf') {
            doc_preview_cleanup($tempPath, $temporary);

            header('Content-Type: application/pdf');
            header(
                'Content-Disposition: inline; filename="'
                . str_replace(
                    ['"', "\r", "\n"],
                    '',
                    basename($downloadName)
                )
                . '"'
            );
            header('X-Content-Type-Options: nosniff');

            if ($isCompressed) {
                if (!doc_stream_gzip_file($absolutePath)) {
                    http_response_code(500);
                    exit('Could not decompress document.');
                }
            } else {
                header('Content-Length: ' . filesize($absolutePath));
                readfile($absolutePath);
            }

            exit;
        }

        if (in_array($extension, $imageExtensions, true)) {
            $data =
                base64_encode(
                    (string)file_get_contents($previewPath)
                );

            $imageMime =
                str_starts_with($mime, 'image/')
                    ? $mime
                    : 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);

            $body =
                '<div style="padding:18px;text-align:center;">'
                . '<img class="preview-media" src="data:'
                . doc_preview_escape($imageMime)
                . ';base64,'
                . $data
                . '" alt="'
                . doc_preview_escape($downloadName)
                . '"></div>';

            doc_preview_cleanup($tempPath, $temporary);

            doc_preview_html_shell(
                $downloadName,
                $body,
                $downloadUrl,
                $backUrl,
                strtoupper($extension)
            );
        }

        if (in_array($extension, $textExtensions, true)) {
            $contents =
                file_get_contents($previewPath);

            if ($contents === false) {
                throw new RuntimeException('Could not read text document.');
            }

            $body =
                '<pre class="preview-text">'
                . doc_preview_escape($contents)
                . '</pre>';

            doc_preview_cleanup($tempPath, $temporary);

            doc_preview_html_shell(
                $downloadName,
                $body,
                $downloadUrl,
                $backUrl,
                strtoupper($extension)
            );
        }

        if ($extension === 'csv') {
            if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                $spreadsheet =
                    \PhpOffice\PhpSpreadsheet\IOFactory::load($previewPath);

                $writer =
                    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter(
                        $spreadsheet,
                        'Html'
                    );

                ob_start();
                $writer->save('php://output');
                $sheetHtml = (string)ob_get_clean();

                $body =
                    '<div class="preview-document-inner">'
                    . $sheetHtml
                    . '</div>';

                doc_preview_cleanup($tempPath, $temporary);

                doc_preview_html_shell(
                    $downloadName,
                    $body,
                    $downloadUrl,
                    $backUrl,
                    'CSV spreadsheet'
                );
            }

            $contents =
                file_get_contents($previewPath);

            $body =
                '<pre class="preview-text">'
                . doc_preview_escape((string)$contents)
                . '</pre>';

            doc_preview_cleanup($tempPath, $temporary);

            doc_preview_html_shell(
                $downloadName,
                $body,
                $downloadUrl,
                $backUrl,
                'CSV file'
            );
        }

        if (in_array($extension, $audioExtensions, true)) {
            $data =
                base64_encode(
                    (string)file_get_contents($previewPath)
                );

            $audioMime =
                str_starts_with($mime, 'audio/')
                    ? $mime
                    : 'audio/mpeg';

            $body =
                '<div style="padding:28px;">'
                . '<audio controls style="width:100%;" src="data:'
                . doc_preview_escape($audioMime)
                . ';base64,'
                . $data
                . '"></audio>'
                . '</div>';

            doc_preview_cleanup($tempPath, $temporary);

            doc_preview_html_shell(
                $downloadName,
                $body,
                $downloadUrl,
                $backUrl,
                strtoupper($extension)
            );
        }

        if (in_array($extension, $videoExtensions, true)) {
            doc_preview_cleanup($tempPath, $temporary);

            header(
                'Content-Type: '
                . (
                    str_starts_with($mime, 'video/')
                        ? $mime
                        : 'video/mp4'
                )
            );

            header(
                'Content-Disposition: inline; filename="'
                . str_replace(
                    ['"', "\r", "\n"],
                    '',
                    basename($downloadName)
                )
                . '"'
            );

            if ($isCompressed) {
                if (!doc_stream_gzip_file($absolutePath)) {
                    http_response_code(500);
                    exit('Could not decompress document.');
                }
            } else {
                header('Content-Length: ' . filesize($absolutePath));
                readfile($absolutePath);
            }

            exit;
        }

        if (
            in_array(
                $extension,
                ['docx','odt','rtf'],
                true
            )
        ) {
            if (!class_exists('\\PhpOffice\\PhpWord\\IOFactory')) {
                doc_preview_cleanup($tempPath, $temporary);

                doc_preview_unsupported(
                    $downloadName,
                    $downloadUrl,
                    $backUrl,
                    $extension,
                    'PhpWord is not installed. Install phpoffice/phpword to preview this document.'
                );
            }

            $phpWord =
                \PhpOffice\PhpWord\IOFactory::load($previewPath);

            $writer =
                \PhpOffice\PhpWord\IOFactory::createWriter(
                    $phpWord,
                    'HTML'
                );

            ob_start();
            $writer->save('php://output');
            $officeHtml = (string)ob_get_clean();

            $body =
                '<div class="preview-document-inner">'
                . $officeHtml
                . '</div>';

            doc_preview_cleanup($tempPath, $temporary);

            doc_preview_html_shell(
                $downloadName,
                $body,
                $downloadUrl,
                $backUrl,
                'Word document'
            );
        }

        if (
            in_array(
                $extension,
                ['xlsx','xls','ods'],
                true
            )
        ) {
            if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                doc_preview_cleanup($tempPath, $temporary);

                doc_preview_unsupported(
                    $downloadName,
                    $downloadUrl,
                    $backUrl,
                    $extension,
                    'PhpSpreadsheet is not installed. Install phpoffice/phpspreadsheet to preview this spreadsheet.'
                );
            }

            $spreadsheet =
                \PhpOffice\PhpSpreadsheet\IOFactory::load($previewPath);

            $writer =
                \PhpOffice\PhpSpreadsheet\IOFactory::createWriter(
                    $spreadsheet,
                    'Html'
                );

            ob_start();
            $writer->save('php://output');
            $sheetHtml = (string)ob_get_clean();

            $body =
                '<div class="preview-document-inner">'
                . $sheetHtml
                . '</div>';

            doc_preview_cleanup($tempPath, $temporary);

            doc_preview_html_shell(
                $downloadName,
                $body,
                $downloadUrl,
                $backUrl,
                'Spreadsheet'
            );
        }

        doc_preview_cleanup($tempPath, $temporary);

        $specialMessage =
            match ($extension) {
                'doc' =>
                    'Legacy Word .doc preview is not reliably supported by PhpWord. Download the file to open it in Microsoft Word or LibreOffice.',

                'odp' =>
                    'PowerPoint files are stored correctly, but this server does not currently have a reliable PowerPoint-to-browser renderer. Download the presentation to open it normally.',

                'zip',
                'rar',
                '7z',
                'tar',
                'gz' =>
                    'Archive files are not rendered as documents. Download the archive to open or extract it.',

                default =>
                    'The file is available in IMS, but this file type does not have a safe browser renderer. Use Download to open it with the appropriate application.',
            };

        doc_preview_unsupported(
            $downloadName,
            $downloadUrl,
            $backUrl,
            $extension,
            $specialMessage
        );

    } catch (Throwable $e) {
        doc_preview_cleanup($tempPath, $temporary);

        error_log(
            'Document preview failed for document #'
            . $documentId
            . ': '
            . $e->getMessage()
        );

        doc_preview_unsupported(
            $downloadName,
            $downloadUrl,
            $backUrl,
            $extension,
            'The file is available, but its preview could not be generated. You can still download the original file.'
        );
    }
}

$_SESSION['error'] = 'Invalid document action.';
doc_redirect_back();
