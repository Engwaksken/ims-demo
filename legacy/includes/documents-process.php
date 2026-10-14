<?php
declare(strict_types=1);

function doc_table_exists(mysqli $conn, string $table): bool
{
    $res = $conn->query(
        "SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'"
    );

    if (!$res) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->close();

    return $exists;
}

function doc_column_exists(mysqli $conn, string $table, string $column): bool
{
    if (!doc_table_exists($conn, $table)) {
        return false;
    }

    $res = $conn->query(
        "SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` "
        . "LIKE '" . $conn->real_escape_string($column) . "'"
    );

    if (!$res) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->close();

    return $exists;
}

function doc_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }

    return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

function doc_get_active_users(mysqli $conn): array
{
    $users = [];

    $sql = "
        SELECT
            user_id,
            full_name,
            email,
            role
        FROM users
        WHERE COALESCE(is_active, 1) = 1
          AND LOWER(TRIM(role)) NOT IN ('member', 'applicant')
        ORDER BY full_name
    ";

    $res = $conn->query($sql);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $users[] = $row;
        }

        $res->close();
    }

    return $users;
}

function getProjects(mysqli $conn): array
{
    $projects = [];

    $res = $conn->query("
        SELECT project_id, project_name, project_code
        FROM projects
        ORDER BY project_name
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $projects[] = $row;
        }

        $res->close();
    }

    return $projects;
}

function getPrograms(mysqli $conn): array
{
    $programs = [];

    if (!doc_table_exists($conn, 'programs')) {
        return [];
    }

    $res = $conn->query("
        SELECT id, program_name, program_code
        FROM programs
        ORDER BY program_name
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $programs[] = $row;
        }

        $res->close();
    }

    return $programs;
}

function doc_is_owner(
    mysqli $conn,
    string $resourceType,
    int $resourceId,
    int $userId
): bool {
    if ($resourceType === 'folder') {
        $stmt = $conn->prepare("
            SELECT created_by
            FROM document_folders
            WHERE folder_id = ?
            LIMIT 1
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT uploaded_by
            FROM documents
            WHERE document_id = ?
            LIMIT 1
        ");
    }

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $resourceId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $ownerId = (int)(
        $resourceType === 'folder'
            ? ($row['created_by'] ?? 0)
            : ($row['uploaded_by'] ?? 0)
    );

    return $ownerId > 0 && $ownerId === $userId;
}

function doc_can(
    mysqli $conn,
    string $resourceType,
    int $resourceId,
    int $userId,
    string $userRole,
    string $right
): bool {
    if (ims_allowed('documents.edit_all', ['Administrator'], 'Edit, move and delete any document or folder')) {
        return true;
    }

    if (doc_is_owner($conn, $resourceType, $resourceId, $userId)) {
        return true;
    }

    if (!doc_table_exists($conn, 'document_permissions')) {
        return $right === 'view';
    }

    $field = match ($right) {
        'edit' => 'can_edit',
        'download' => 'can_download',
        default => 'can_view',
    };

    $stmt = $conn->prepare("
        SELECT {$field}
        FROM document_permissions
        WHERE resource_type = ?
          AND resource_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'sii',
            $resourceType,
            $resourceId,
            $userId
        );

        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return (int)$row[$field] === 1;
        }
    }

    /*
     * Folder inheritance for documents.
     */
    if ($resourceType === 'document') {
        $stmt = $conn->prepare("
            SELECT folder_id
            FROM documents
            WHERE document_id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $resourceId);
            $stmt->execute();

            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $folderId = (int)($row['folder_id'] ?? 0);

            if ($folderId > 0) {
                return doc_can(
                    $conn,
                    'folder',
                    $folderId,
                    $userId,
                    $userRole,
                    $right
                );
            }
        }
    }

    /*
     * Root-level resources are visible by default to authenticated users,
     * but edit/download still require ownership or explicit permission.
     */
    return $right === 'view';
}

function doc_get_folder(
    mysqli $conn,
    int $folderId,
    int $userId,
    string $role
): ?array {
    if ($folderId < 1 || !doc_table_exists($conn, 'document_folders')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            f.*,
            u.full_name AS owner_name
        FROM document_folders f
        LEFT JOIN users u
            ON u.user_id = f.created_by
        WHERE f.folder_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $folderId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    if (!doc_can($conn, 'folder', $folderId, $userId, $role, 'view')) {
        return null;
    }

    return $row;
}

function doc_get_breadcrumbs(
    mysqli $conn,
    int $folderId,
    int $userId,
    string $role
): array {
    $crumbs = [];
    $guard = 0;

    while ($folderId > 0 && $guard < 30) {
        $folder = doc_get_folder(
            $conn,
            $folderId,
            $userId,
            $role
        );

        if (!$folder) {
            break;
        }

        array_unshift($crumbs, $folder);

        $folderId = (int)($folder['parent_folder_id'] ?? 0);
        $guard++;
    }

    return $crumbs;
}

/*
 * Builds a folder_id => total_file_count map where each folder's count
 * includes files in that folder AND every descendant subfolder,
 * recursively. Computed with two flat queries (folder parent/child
 * links, and direct per-folder file counts) instead of one query per
 * folder, so it stays fast regardless of how deep the tree is.
 */
function doc_recursive_folder_file_counts(mysqli $conn): array
{
    if (!doc_table_exists($conn, 'document_folders')) {
        return [];
    }

    $children = [];

    $res = $conn->query("
        SELECT
            folder_id,
            COALESCE(parent_folder_id, 0) AS parent_folder_id
        FROM document_folders
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $parent = (int)$row['parent_folder_id'];
            $children[$parent][] = (int)$row['folder_id'];
        }

        $res->close();
    }

    $directCounts = [];

    $res = $conn->query("
        SELECT
            folder_id,
            COUNT(*) AS cnt
        FROM documents
        WHERE folder_id IS NOT NULL
        GROUP BY folder_id
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $directCounts[(int)$row['folder_id']] = (int)$row['cnt'];
        }

        $res->close();
    }

    $memo = [];
    $inProgress = [];

    $compute = function (int $folderId) use (
        &$compute,
        &$memo,
        &$inProgress,
        &$children,
        &$directCounts
    ): int {
        if (isset($memo[$folderId])) {
            return $memo[$folderId];
        }

        if (isset($inProgress[$folderId])) {
            /*
             * Guard against a corrupt/cyclical parent_folder_id chain.
             */
            return $directCounts[$folderId] ?? 0;
        }

        $inProgress[$folderId] = true;

        $total = $directCounts[$folderId] ?? 0;

        foreach ($children[$folderId] ?? [] as $childId) {
            $total += $compute($childId);
        }

        unset($inProgress[$folderId]);

        $memo[$folderId] = $total;

        return $total;
    };

    $allFolderIds = [];

    foreach ($children as $childIds) {
        foreach ($childIds as $childId) {
            $allFolderIds[$childId] = true;
        }
    }

    foreach (array_keys($allFolderIds) as $folderId) {
        $compute($folderId);
    }

    return $memo;
}


function doc_get_folders(
    mysqli $conn,
    int $parentFolderId,
    int $userId,
    string $role,
    string $search = '',
    int $projectId = 0,
    int $programId = 0
): array {
    if (!doc_table_exists($conn, 'document_folders')) {
        return [];
    }

    $sql = "
        SELECT
            f.*,
            u.full_name AS owner_name
        FROM document_folders f
        LEFT JOIN users u
            ON u.user_id = f.created_by
        WHERE COALESCE(f.parent_folder_id, 0) = ?
    ";

    $types = 'i';
    $params = [$parentFolderId];

    if ($search !== '') {
        $sql .= " AND (
            f.folder_name LIKE ?
            OR f.description LIKE ?
            OR f.document_type LIKE ?
        )";

        $like = '%' . $search . '%';

        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($projectId > 0) {
        $sql .= " AND f.project_id = ?";
        $types .= 'i';
        $params[] = $projectId;
    }

    if ($programId > 0) {
        $sql .= " AND f.program_id = ?";
        $types .= 'i';
        $params[] = $programId;
    }

    $sql .= " ORDER BY f.folder_name";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    $refs = [$types];

    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();

    $res = $stmt->get_result();

    $fileCounts = doc_recursive_folder_file_counts($conn);

    $folders = [];

    while ($row = $res->fetch_assoc()) {
        if (
            doc_can(
                $conn,
                'folder',
                (int)$row['folder_id'],
                $userId,
                $role,
                'view'
            )
        ) {
            $row['file_count'] = $fileCounts[(int)$row['folder_id']] ?? 0;
            $folders[] = $row;
        }
    }

    $stmt->close();

    return $folders;
}

function doc_get_documents(
    mysqli $conn,
    int $folderId,
    int $userId,
    string $role,
    string $search = '',
    int $projectId = 0,
    int $programId = 0
): array {
    $sql = "
        SELECT
            d.*,
            p.project_name,
            p.project_code,
            pr.program_name,
            pr.program_code,
            u.full_name AS uploaded_by_name
        FROM documents d
        LEFT JOIN projects p
            ON p.project_id = d.project_id
        LEFT JOIN programs pr
            ON pr.id = d.program_id
        LEFT JOIN users u
            ON u.user_id = d.uploaded_by
        WHERE COALESCE(d.folder_id, 0) = ?
    ";

    $types = 'i';
    $params = [$folderId];

    if ($search !== '') {
        $sql .= " AND (
            d.document_name LIKE ?
            OR d.description LIKE ?
            OR d.tags LIKE ?
            OR d.document_type LIKE ?
        )";

        $like = '%' . $search . '%';

        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($projectId > 0) {
        $sql .= " AND d.project_id = ?";
        $types .= 'i';
        $params[] = $projectId;
    }

    if ($programId > 0) {
        $sql .= " AND d.program_id = ?";
        $types .= 'i';
        $params[] = $programId;
    }

    $sql .= " ORDER BY d.upload_date DESC, d.document_id DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    $refs = [$types];

    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();

    $res = $stmt->get_result();

    $documents = [];

    while ($row = $res->fetch_assoc()) {
        if (
            doc_can(
                $conn,
                'document',
                (int)$row['document_id'],
                $userId,
                $role,
                'view'
            )
        ) {
            $documents[] = $row;
        }
    }

    $stmt->close();

    return $documents;
}


/*
 * ==========================================================================
 * MOVE (files / folders)
 * ==========================================================================
 */

function doc_folder_exists(mysqli $conn, int $folderId): bool
{
    if ($folderId < 1 || !doc_table_exists($conn, 'document_folders')) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT folder_id
        FROM document_folders
        WHERE folder_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $folderId);
    $stmt->execute();

    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found;
}

/*
 * Builds an ordered, indented list of folders the user is allowed to move
 * things into (edit permission required on the destination). When moving
 * a folder itself, pass its own id as $excludeFolderId so it — and its
 * entire subtree — never shows up as a possible destination (you cannot
 * move a folder into itself or into one of its own children).
 */
function doc_get_movable_folder_options(
    mysqli $conn,
    int $userId,
    string $role,
    int $excludeFolderId = 0
): array {
    $options = [
        [
            'folder_id' => 0,
            'folder_name' => 'Documents (Root)',
            'depth' => 0,
        ],
    ];

    if (!doc_table_exists($conn, 'document_folders')) {
        return $options;
    }

    $all = [];
    $children = [];

    $res = $conn->query("
        SELECT
            folder_id,
            COALESCE(parent_folder_id, 0) AS parent_folder_id,
            folder_name
        FROM document_folders
        ORDER BY folder_name
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['folder_id'];
            $parent = (int)$row['parent_folder_id'];

            $all[$id] = $row;
            $children[$parent][] = $id;
        }

        $res->close();
    }

    $excluded = [];

    if ($excludeFolderId > 0) {
        $stack = [$excludeFolderId];

        while ($stack) {
            $current = array_pop($stack);
            $excluded[$current] = true;

            foreach ($children[$current] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }
    }

    $walk = function (int $parentId, int $depth) use (
        &$walk,
        &$children,
        &$all,
        &$options,
        $conn,
        $userId,
        $role,
        &$excluded
    ): void {
        foreach ($children[$parentId] ?? [] as $id) {
            if (isset($excluded[$id])) {
                continue;
            }

            if (doc_can($conn, 'folder', $id, $userId, $role, 'edit')) {
                $options[] = [
                    'folder_id' => $id,
                    'folder_name' => (string)($all[$id]['folder_name'] ?? ''),
                    'depth' => $depth,
                ];
            }

            $walk($id, $depth + 1);
        }
    };

    $walk(0, 0);

    return $options;
}

function doc_move_document(
    mysqli $conn,
    int $documentId,
    int $targetFolderId,
    int $userId,
    string $role
): array {
    if ($documentId < 1) {
        return ['success' => false, 'error' => 'Invalid document.'];
    }

    if (!doc_can($conn, 'document', $documentId, $userId, $role, 'edit')) {
        return [
            'success' => false,
            'error' => 'You do not have permission to move this document.',
        ];
    }

    if ($targetFolderId > 0) {
        if (!doc_folder_exists($conn, $targetFolderId)) {
            return ['success' => false, 'error' => 'Destination folder not found.'];
        }

        if (!doc_can($conn, 'folder', $targetFolderId, $userId, $role, 'edit')) {
            return [
                'success' => false,
                'error' => 'You do not have permission to move items into that folder.',
            ];
        }
    }

    $stmt = $conn->prepare("
        UPDATE documents
        SET folder_id = NULLIF(?, 0)
        WHERE document_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return ['success' => false, 'error' => 'Could not prepare move.'];
    }

    $stmt->bind_param('ii', $targetFolderId, $documentId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        return ['success' => false, 'error' => 'Could not move document: ' . $error];
    }

    $stmt->close();

    return ['success' => true];
}

function doc_move_folder(
    mysqli $conn,
    int $folderId,
    int $targetFolderId,
    int $userId,
    string $role
): array {
    if ($folderId < 1 || !doc_folder_exists($conn, $folderId)) {
        return ['success' => false, 'error' => 'Invalid folder.'];
    }

    if (!doc_can($conn, 'folder', $folderId, $userId, $role, 'edit')) {
        return [
            'success' => false,
            'error' => 'You do not have permission to move this folder.',
        ];
    }

    if ($targetFolderId === $folderId) {
        return ['success' => false, 'error' => 'A folder cannot be moved into itself.'];
    }

    if ($targetFolderId > 0) {
        if (!doc_folder_exists($conn, $targetFolderId)) {
            return ['success' => false, 'error' => 'Destination folder not found.'];
        }

        $descendantIds = doc_collect_folder_tree($conn, $folderId);

        if (in_array($targetFolderId, $descendantIds, true)) {
            return [
                'success' => false,
                'error' => 'A folder cannot be moved into one of its own subfolders.',
            ];
        }

        if (!doc_can($conn, 'folder', $targetFolderId, $userId, $role, 'edit')) {
            return [
                'success' => false,
                'error' => 'You do not have permission to move items into that folder.',
            ];
        }
    }

    $stmt = $conn->prepare("
        UPDATE document_folders
        SET parent_folder_id = NULLIF(?, 0)
        WHERE folder_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return ['success' => false, 'error' => 'Could not prepare move.'];
    }

    $stmt->bind_param('ii', $targetFolderId, $folderId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        return ['success' => false, 'error' => 'Could not move folder: ' . $error];
    }

    $stmt->close();

    return ['success' => true];
}


function doc_repository_stats(
    mysqli $conn,
    int $userId,
    string $role
): array {
    $stats = [
        'folders' => 0,
        'files' => 0,
        'shared' => 0,
        'bytes' => 0,
    ];

    if (doc_table_exists($conn, 'document_folders')) {
        $res = $conn->query("
            SELECT folder_id
            FROM document_folders
        ");

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (
                    doc_can(
                        $conn,
                        'folder',
                        (int)$row['folder_id'],
                        $userId,
                        $role,
                        'view'
                    )
                ) {
                    $stats['folders']++;
                }
            }

            $res->close();
        }
    }

    $res = $conn->query("
        SELECT
            document_id,
            file_size,
            uploaded_by
        FROM documents
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $documentId = (int)$row['document_id'];

            if (
                doc_can(
                    $conn,
                    'document',
                    $documentId,
                    $userId,
                    $role,
                    'view'
                )
            ) {
                $stats['files']++;
                $stats['bytes'] += (int)($row['file_size'] ?? 0);

                if ((int)($row['uploaded_by'] ?? 0) !== $userId) {
                    $stats['shared']++;
                }
            }
        }

        $res->close();
    }

    return $stats;
}

function getDocumentIcon(string $filePath): array
{
    return docIconStyle($filePath);
}

function docIconStyle(string $path): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return match (true) {
        in_array($ext, ['pdf'], true)
            => ['fa-file-pdf', '#dc2626', '#fef2f2'],

        in_array($ext, ['doc','docx'], true)
            => ['fa-file-word', '#1d4ed8', '#eff6ff'],

        in_array($ext, ['xls','xlsx','csv'], true)
            => ['fa-file-excel', '#15803d', '#f0fdf4'],

        in_array($ext, ['ppt','pptx'], true)
            => ['fa-file-powerpoint', '#ea580c', '#fff7ed'],

        in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true)
            => ['fa-file-image', '#7e22ce', '#faf5ff'],

        in_array($ext, ['mp4','mov','avi','mkv','webm'], true)
            => ['fa-file-video', '#b91c1c', '#fef2f2'],

        in_array($ext, ['zip','rar','7z'], true)
            => ['fa-file-zipper', '#475569', '#f8fafc'],

        default
            => ['fa-file', '#475569', '#f1f5f9'],
    };
}

function doc_normalize_uploaded_files(array $files): array
{
    /*
     * Converts PHP's multiple-upload structure:
     *
     * documents[name][0]
     * documents[tmp_name][0]
     * ...
     *
     * into:
     *
     * [
     *   ['name' => ..., 'tmp_name' => ..., ...],
     *   ...
     * ]
     */
    if (
        !isset($files['name'])
        ||
        !is_array($files['name'])
    ) {
        return $files ? [$files] : [];
    }

    $normalized = [];

    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => (string)$name,
            'full_path' => (string)($files['full_path'][$index] ?? $name),
            'type' => (string)($files['type'][$index] ?? ''),
            'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
            'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'][$index] ?? 0),
        ];
    }

    return $normalized;
}


function validateFileUpload(array $file): array
{
    $errors = [];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed.';
        return $errors;
    }

    $maxSize = 25 * 1024 * 1024;

    if ((int)$file['size'] > $maxSize) {
        $errors[] = 'File size exceeds the 25 MB limit.';
    }

    $allowed = [
        'pdf','doc','docx','xls','xlsx','csv',
        'ppt','pptx','jpg','jpeg','png','gif',
        'webp','mp4','avi','mov','mkv','webm',
        'zip','rar','7z','txt'
    ];

    $extension = strtolower(
        pathinfo((string)$file['name'], PATHINFO_EXTENSION)
    );

    if (!in_array($extension, $allowed, true)) {
        $errors[] = 'File type is not allowed.';
    }

    return $errors;
}

function doc_create_folder(
    mysqli $conn,
    array $data,
    int $userId
): array {
    $documentType = trim((string)($data['document_type'] ?? ''));

    if ($documentType === '') {
        return [
            'success' => false,
            'error' => 'Select a document type.',
        ];
    }

    $folderName = trim((string)($data['folder_name'] ?? ''));

    if ($documentType === 'Other') {
        $other = trim((string)($data['other_type_name'] ?? ''));

        if ($other === '') {
            return [
                'success' => false,
                'error' => 'Enter the folder name for Other.',
            ];
        }

        $documentType = $other;

        if ($folderName === '') {
            $folderName = $other;
        }
    }

    if ($folderName === '') {
        $folderName = $documentType;
    }

    $parentFolderId = (int)($data['parent_folder_id'] ?? 0);
    $projectId = !empty($data['project_id'])
        ? (int)$data['project_id']
        : null;
    $programId = !empty($data['program_id'])
        ? (int)$data['program_id']
        : null;
    $description = trim((string)($data['description'] ?? ''));

    if ($projectId && $programId) {
        return [
            'success' => false,
            'error' => 'Select either a project or a program, not both.',
        ];
    }

    $stmt = $conn->prepare("
        INSERT INTO document_folders
        (
            parent_folder_id,
            folder_name,
            document_type,
            description,
            project_id,
            program_id,
            created_by,
            created_at
        )
        VALUES
        (
            NULLIF(?,0),
            ?,
            ?,
            NULLIF(?, ''),
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Could not prepare folder creation.',
        ];
    }

    $stmt->bind_param(
        'isssiii',
        $parentFolderId,
        $folderName,
        $documentType,
        $description,
        $projectId,
        $programId,
        $userId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        return [
            'success' => false,
            'error' => 'Could not create folder: ' . $error,
        ];
    }

    $folderId = (int)$stmt->insert_id;
    $stmt->close();

    return [
        'success' => true,
        'folder_id' => $folderId,
    ];
}

function doc_ensure_child_folder(
    mysqli $conn,
    int $parentFolderId,
    string $folderName,
    int $createdBy,
    ?int $projectId = null,
    ?int $programId = null
): int {
    $folderName = trim($folderName);

    if ($folderName === '') {
        return $parentFolderId;
    }

    $stmt = $conn->prepare("
        SELECT folder_id
        FROM document_folders
        WHERE COALESCE(parent_folder_id, 0) = ?
          AND folder_name = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'is',
            $parentFolderId,
            $folderName
        );

        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($row) {
            return (int)$row['folder_id'];
        }
    }

    $documentType = 'Folder';

    $stmt = $conn->prepare("
        INSERT INTO document_folders
        (
            parent_folder_id,
            folder_name,
            document_type,
            project_id,
            program_id,
            created_by,
            created_at
        )
        VALUES
        (
            NULLIF(?, 0),
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Could not prepare subfolder creation.'
        );
    }

    $stmt->bind_param(
        'issiii',
        $parentFolderId,
        $folderName,
        $documentType,
        $projectId,
        $programId,
        $createdBy
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Could not create subfolder: ' . $error
        );
    }

    $newId = (int)$stmt->insert_id;
    $stmt->close();

    return $newId;
}


function doc_create_folder_path(
    mysqli $conn,
    int $baseFolderId,
    array $parts,
    int $createdBy,
    ?int $projectId = null,
    ?int $programId = null
): int {
    $parentId = $baseFolderId;

    foreach ($parts as $part) {
        $part = trim((string)$part);

        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }

        $parentId = doc_ensure_child_folder(
            $conn,
            $parentId,
            $part,
            $createdBy,
            $projectId,
            $programId
        );
    }

    return $parentId;
}


function doc_detect_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            finfo_close($finfo);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);

        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    return 'application/octet-stream';
}


/*
 * ==========================================================================
 * UPLOAD PERFORMANCE / SIZE OPTIMIZATION
 * ==========================================================================
 *
 * Previously EVERY uploaded file was gzip-compressed, regardless of type.
 * That is wasted work for formats that are already compressed internally
 * (JPG, PNG, GIF, WEBP, MP4/AVI/MOV/MKV/WEBM, ZIP/RAR/7Z, PDF, and the
 * modern Office formats DOCX/XLSX/PPTX, which are themselves ZIP archives).
 * Gzipping those again:
 *
 *   - burns CPU time on every upload (this is what made uploads feel slow,
 *     especially for larger files)
 *   - typically makes the stored file the SAME size or slightly LARGER
 *     (compression overhead with no real gain)
 *
 * So we now only gzip the file types that actually shrink well: plain
 * text/CSV and the legacy Office binary formats (DOC/XLS/PPT). Everything
 * else is stored as-is with a single fast move, no extra read/compress
 * pass. This cuts upload time significantly and avoids inflating storage
 * for the majority of real-world uploads (images, PDFs, videos, modern
 * Office files).
 */
function doc_should_compress(string $extension): bool
{
    $extension = strtolower($extension);

    $compressible = [
        'txt',
        'csv',
        'doc',
        'xls',
        'ppt',
    ];

    return in_array($extension, $compressible, true);
}


function doc_gzip_file(
    string $sourcePath,
    string $destinationPath,
    int $level = 6
): array {
    if (!function_exists('gzopen')) {
        return [
            'success' => false,
            'error' => 'PHP zlib extension is not available.',
        ];
    }

    $level = max(1, min(9, $level));

    $input = @fopen($sourcePath, 'rb');

    if (!$input) {
        return [
            'success' => false,
            'error' => 'Could not open uploaded file for compression.',
        ];
    }

    /*
     * Use the zlib stream wrapper with stream_copy_to_stream instead of a
     * manual fread()/gzwrite() loop. This lets PHP copy the data in large,
     * buffered chunks internally rather than one userland loop iteration
     * at a time, which is both simpler and noticeably faster.
     */
    $output = @fopen(
        'compress.zlib://' . $destinationPath,
        'wb' . $level
    );

    if (!$output) {
        fclose($input);

        return [
            'success' => false,
            'error' => 'Could not create compressed file.',
        ];
    }

    $copied = @stream_copy_to_stream($input, $output);

    fclose($input);
    fclose($output);

    if ($copied === false || !is_file($destinationPath)) {
        @unlink($destinationPath);

        return [
            'success' => false,
            'error' => 'File compression failed.',
        ];
    }

    return [
        'success' => true,
        'compressed_size' => (int)filesize($destinationPath),
    ];
}


function doc_stream_gzip_file(string $compressedPath): bool
{
    if (!function_exists('gzopen')) {
        return false;
    }

    $handle = @gzopen($compressedPath, 'rb');

    if (!$handle) {
        return false;
    }

    while (!gzeof($handle)) {
        $chunk = gzread($handle, 1024 * 1024);

        if ($chunk === false) {
            gzclose($handle);
            return false;
        }

        echo $chunk;

        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }

    gzclose($handle);

    return true;
}


function uploadDocument(
    mysqli $conn,
    array $file,
    array $data
): array {
    $errors = validateFileUpload($file);

    if ($errors) {
        return [
            'success' => false,
            'errors' => $errors,
        ];
    }

    $folderId = (int)($data['folder_id'] ?? 0);
    $documentType = trim((string)($data['document_type'] ?? ''));

    /*
     * Auto-detect the type from the file extension whenever it's left
     * blank or marked "Inherited" — not just inside a folder. This lets
     * drag-and-drop uploads (which don't go through the manual type
     * dropdown) work whether they land in a folder or at the root.
     */
    if ($documentType === '' || $documentType === 'Inherited') {
        $documentType = pathinfo(
            (string)($file['name'] ?? ''),
            PATHINFO_EXTENSION
        );

        $documentType = $documentType !== ''
            ? strtoupper($documentType)
            : 'File';
    }

    if ($documentType === 'Other') {
        $other = trim((string)($data['other_type_name'] ?? ''));

        if ($other === '') {
            return [
                'success' => false,
                'errors' => ['Enter the Other document type name.'],
            ];
        }

        $documentType = $other;
    }

    $uploadDir = dirname(__DIR__) . '/uploads/documents/';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [
                'success' => false,
                'errors' => ['Could not create document upload folder.'],
            ];
        }
    }

    $originalName = (string)($file['name'] ?? 'document');
    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    $baseToken =
        bin2hex(random_bytes(12))
        . '_'
        . time();

    $shouldCompress = doc_should_compress($extension);

    if ($shouldCompress) {
        /*
         * Move the browser upload to a temporary uncompressed file first,
         * then gzip it into its final location.
         */
        $temporaryPath =
            $uploadDir
            . $baseToken
            . '.upload';

        if (!move_uploaded_file((string)$file['tmp_name'], $temporaryPath)) {
            return [
                'success' => false,
                'errors' => ['Failed to save the uploaded file.'],
            ];
        }

        $originalSize = (int)filesize($temporaryPath);
        $originalMime = doc_detect_mime($temporaryPath);

        $compressedFileName =
            $baseToken
            . '.'
            . ($extension !== '' ? $extension : 'file')
            . '.gz';

        $absolutePath =
            $uploadDir
            . $compressedFileName;

        $publicPath =
            'uploads/documents/'
            . $compressedFileName;

        $compression = doc_gzip_file(
            $temporaryPath,
            $absolutePath,
            6
        );

        @unlink($temporaryPath);

        if (empty($compression['success'])) {
            @unlink($absolutePath);

            return [
                'success' => false,
                'errors' => [
                    $compression['error']
                    ?? 'Could not compress uploaded file.'
                ],
            ];
        }

        $isCompressed = true;
        $storedSize = (int)(
            $compression['compressed_size']
            ?? filesize($absolutePath)
        );
    } else {
        /*
         * Already-compressed formats (images, video, PDF, ZIP, modern
         * Office files) are stored as-is: a single fast move, no
         * temporary file, no gzip pass.
         */
        $storedFileName =
            $baseToken
            . '.'
            . ($extension !== '' ? $extension : 'file');

        $absolutePath =
            $uploadDir
            . $storedFileName;

        $publicPath =
            'uploads/documents/'
            . $storedFileName;

        if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
            return [
                'success' => false,
                'errors' => ['Failed to save the uploaded file.'],
            ];
        }

        $originalSize = (int)filesize($absolutePath);
        $originalMime = doc_detect_mime($absolutePath);
        $isCompressed = false;
        $storedSize = $originalSize;
    }

    $projectId = !empty($data['project_id'])
        ? (int)$data['project_id']
        : null;

    $programId = !empty($data['program_id'])
        ? (int)$data['program_id']
        : null;

    if ($projectId && $programId) {
        @unlink($absolutePath);

        return [
            'success' => false,
            'errors' => ['Select either a project or a program, not both.'],
        ];
    }

    $documentName = trim((string)($data['document_name'] ?? ''));

    if ($documentName === '') {
        $documentName = pathinfo(
            $originalName,
            PATHINFO_FILENAME
        );
    }

    $description = trim((string)($data['description'] ?? ''));
    $tags = trim((string)($data['tags'] ?? ''));
    $uploadedBy = (int)($data['uploaded_by'] ?? 0);

    $hasCompressed = doc_column_exists(
        $conn,
        'documents',
        'is_compressed'
    );

    $hasOriginalSize = doc_column_exists(
        $conn,
        'documents',
        'original_file_size'
    );

    $hasOriginalName = doc_column_exists(
        $conn,
        'documents',
        'original_filename'
    );

    $hasMime = doc_column_exists(
        $conn,
        'documents',
        'original_mime_type'
    );

    $hasExtension = doc_column_exists(
        $conn,
        'documents',
        'original_extension'
    );

    if (
        $hasCompressed
        &&
        $hasOriginalSize
        &&
        $hasOriginalName
        &&
        $hasMime
        &&
        $hasExtension
    ) {
        $isCompressedInt = $isCompressed ? 1 : 0;

        $stmt = $conn->prepare("
            INSERT INTO documents
            (
                folder_id,
                document_name,
                document_type,
                file_path,
                file_size,
                original_file_size,
                original_filename,
                original_mime_type,
                original_extension,
                is_compressed,
                project_id,
                program_id,
                description,
                tags,
                uploaded_by,
                upload_date
            )
            VALUES
            (
                NULLIF(?,0),
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NULLIF(?, ''),
                NULLIF(?, ''),
                ?,
                NOW()
            )
        ");

        if (!$stmt) {
            @unlink($absolutePath);

            return [
                'success' => false,
                'errors' => ['Could not prepare document upload.'],
            ];
        }

        $stmt->bind_param(
            'isssiisssiiissi',
            $folderId,
            $documentName,
            $documentType,
            $publicPath,
            $storedSize,
            $originalSize,
            $originalName,
            $originalMime,
            $extension,
            $isCompressedInt,
            $projectId,
            $programId,
            $description,
            $tags,
            $uploadedBy
        );
    } else {
        /*
         * Backwards-compatible fallback if the compression migration has
         * not yet been run. file_size reflects whatever was actually
         * stored on disk (compressed or original).
         */
        $stmt = $conn->prepare("
            INSERT INTO documents
            (
                folder_id,
                document_name,
                document_type,
                file_path,
                file_size,
                project_id,
                program_id,
                description,
                tags,
                uploaded_by,
                upload_date
            )
            VALUES
            (
                NULLIF(?,0),
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NULLIF(?, ''),
                NULLIF(?, ''),
                ?,
                NOW()
            )
        ");

        if (!$stmt) {
            @unlink($absolutePath);

            return [
                'success' => false,
                'errors' => ['Could not prepare document upload.'],
            ];
        }

        $stmt->bind_param(
            'isssiiissi',
            $folderId,
            $documentName,
            $documentType,
            $publicPath,
            $storedSize,
            $projectId,
            $programId,
            $description,
            $tags,
            $uploadedBy
        );
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        @unlink($absolutePath);

        return [
            'success' => false,
            'errors' => ['Database error: ' . $error],
        ];
    }

    $documentId = (int)$stmt->insert_id;

    $stmt->close();

    return [
        'success' => true,
        'document_id' => $documentId,
        'compressed' => $isCompressed,
        'original_size' => $originalSize,
        'compressed_size' => $storedSize,
    ];
}


function doc_collect_folder_tree(
    mysqli $conn,
    int $folderId
): array {
    $folderIds = [$folderId];

    $stmt = $conn->prepare("
        SELECT folder_id
        FROM document_folders
        WHERE parent_folder_id = ?
    ");

    if (!$stmt) {
        return $folderIds;
    }

    $stmt->bind_param('i', $folderId);
    $stmt->execute();

    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $childId = (int)$row['folder_id'];

        foreach (
            doc_collect_folder_tree(
                $conn,
                $childId
            )
            as $descendantId
        ) {
            $folderIds[] = $descendantId;
        }
    }

    $stmt->close();

    return $folderIds;
}


function doc_delete_folder_recursive(
    mysqli $conn,
    int $folderId
): array {
    if ($folderId < 1) {
        return [
            'success' => false,
            'error' => 'Invalid folder.',
        ];
    }

    $folderIds = doc_collect_folder_tree(
        $conn,
        $folderId
    );

    /*
     * Delete physical files first, then database records.
     */
    foreach ($folderIds as $id) {
        $stmt = $conn->prepare("
            SELECT
                document_id,
                file_path
            FROM documents
            WHERE folder_id = ?
        ");

        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $publicPath = (string)($row['file_path'] ?? '');

            if ($publicPath !== '') {
                $absolutePath =
                    dirname(__DIR__)
                    . '/'
                    . ltrim($publicPath, '/');

                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }

        $stmt->close();
    }

    $conn->begin_transaction();

    try {
        /*
         * Remove document permissions for all documents in the tree.
         */
        foreach ($folderIds as $id) {
            $stmt = $conn->prepare("
                DELETE dp
                FROM document_permissions dp
                INNER JOIN documents d
                    ON d.document_id = dp.resource_id
                   AND dp.resource_type = 'document'
                WHERE d.folder_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }
        }

        /*
         * Remove files.
         */
        foreach ($folderIds as $id) {
            $stmt = $conn->prepare("
                DELETE FROM documents
                WHERE folder_id = ?
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Could not remove files from folder.'
                );
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        /*
         * Remove folder permissions.
         */
        foreach ($folderIds as $id) {
            $stmt = $conn->prepare("
                DELETE FROM document_permissions
                WHERE resource_type = 'folder'
                  AND resource_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }
        }

        /*
         * Delete children before parents.
         */
        $folderIds = array_reverse($folderIds);

        foreach ($folderIds as $id) {
            $stmt = $conn->prepare("
                DELETE FROM document_folders
                WHERE folder_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Could not remove folder.'
                );
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

        return ['success' => true];
    } catch (Throwable $e) {
        $conn->rollback();

        error_log(
            'Recursive folder delete failed: '
            . $e->getMessage()
        );

        return [
            'success' => false,
            'error' => 'Could not delete folder.',
        ];
    }
}


function deleteDocument(
    mysqli $conn,
    int $documentId
): array {
    $stmt = $conn->prepare("
        SELECT file_path
        FROM documents
        WHERE document_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Could not load document.',
        ];
    }

    $stmt->bind_param('i', $documentId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$row) {
        return [
            'success' => false,
            'error' => 'Document not found.',
        ];
    }

    $stmt = $conn->prepare("
        DELETE FROM documents
        WHERE document_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Could not delete document.',
        ];
    }

    $stmt->bind_param('i', $documentId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        return [
            'success' => false,
            'error' => 'Could not delete document: ' . $error,
        ];
    }

    $stmt->close();

    $publicPath = (string)($row['file_path'] ?? '');

    if ($publicPath !== '') {
        $absolutePath = dirname(__DIR__) . '/' . ltrim($publicPath, '/');

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    return ['success' => true];
}