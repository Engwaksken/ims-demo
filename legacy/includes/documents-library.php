<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Documents library: SharePoint-style features
|--------------------------------------------------------------------------
| Recycle bin (soft delete), favourites, version history, check-out /
| check-in, activity log and the item details used by the info pane.
|
| Tables and columns are created on first use (doc_lib_ready), so the page
| keeps working before database/migrations/2026_11_17_documents_library.sql
| has been run. Every feature degrades to the old behaviour when the schema
| is unavailable (e.g. no ALTER privilege): deletes become permanent again
| and the extra views stay hidden.
|
| Loaded by documents-process.php, so the page and the handler both get it.
*/

if (!function_exists('doc_lib_ready')) {

    /** Ensures the library schema exists. Cached per request. */
    function doc_lib_ready(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS document_favorites (
                    user_id INT NOT NULL,
                    resource_type ENUM('folder','document') NOT NULL,
                    resource_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, resource_type, resource_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS document_activity (
                    activity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    resource_type ENUM('folder','document') NOT NULL,
                    resource_id INT NOT NULL,
                    user_id INT NOT NULL,
                    action VARCHAR(30) NOT NULL,
                    details VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (activity_id),
                    KEY idx_doc_activity_resource (resource_type, resource_id, created_at),
                    KEY idx_doc_activity_user (user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS document_versions (
                    version_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    document_id INT NOT NULL,
                    version_number INT NOT NULL,
                    file_path VARCHAR(500) NOT NULL,
                    file_size INT NULL,
                    original_file_size INT NULL,
                    original_filename VARCHAR(255) NULL,
                    original_mime_type VARCHAR(150) NULL,
                    original_extension VARCHAR(20) NULL,
                    is_compressed TINYINT(1) NOT NULL DEFAULT 0,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    comment VARCHAR(255) NULL,
                    PRIMARY KEY (version_id),
                    KEY idx_doc_versions_document (document_id, version_number)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $wanted = [
                'documents' => [
                    'folder_id' => 'INT NULL',
                    'deleted_at' => 'DATETIME NULL',
                    'deleted_by' => 'INT NULL',
                    'modified_at' => 'DATETIME NULL',
                    'modified_by' => 'INT NULL',
                    'version_number' => 'INT NOT NULL DEFAULT 1',
                    'checked_out_by' => 'INT NULL',
                    'checked_out_at' => 'DATETIME NULL',
                ],
                'document_folders' => [
                    'deleted_at' => 'DATETIME NULL',
                    'deleted_by' => 'INT NULL',
                ],
            ];

            $ok = true;

            foreach ($wanted as $table => $columns) {
                $have = doc_lib_table_columns($conn, $table);

                if (!$have) {
                    $ok = false;
                    continue;
                }

                foreach ($columns as $column => $definition) {
                    if (isset($have[$column])) {
                        continue;
                    }

                    if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
                        $ok = false;
                    }
                }
            }

            $ready = $ok
                && doc_table_exists($conn, 'document_favorites')
                && doc_table_exists($conn, 'document_activity')
                && doc_table_exists($conn, 'document_versions');
        } catch (Throwable $e) {
            error_log('documents library schema: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }

    /** Column names of a table as a set, or [] when it can't be read. */
    function doc_lib_table_columns(mysqli $conn, string $table): array
    {
        $columns = [];
        $res = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns[(string)$row['Field']] = true;
            }

            $res->close();
        }

        return $columns;
    }

    /**
     * SQL fragment that hides recycled rows (" AND d.deleted_at IS NULL"),
     * or '' before the recycle-bin columns exist.
     */
    function doc_live_sql(mysqli $conn, string $alias = '', string $table = 'documents'): string
    {
        static $cache = [];

        // Self-contained (no schema changes) so other pages can load just this
        // file to hide recycled documents. No column yet = nothing recycled.
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = isset(doc_lib_table_columns($conn, $table)['deleted_at']);
        }

        if (!$cache[$table]) {
            return '';
        }

        return ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'deleted_at IS NULL';
    }

    function doc_lib_is_admin(): bool
    {
        return ims_allowed('documents.edit_all', ['Administrator'], 'Edit, move and delete any document or folder');
    }

    /** Runs a prepared SELECT and returns all rows ([] on any failure). */
    function doc_lib_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return [];
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows;
    }

    /** Runs a prepared write; returns affected rows, or -1 on failure. */
    function doc_lib_exec(mysqli $conn, string $sql, string $types = '', array $params = []): int
    {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return -1;
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $ok = $stmt->execute();
        $affected = $ok ? $stmt->affected_rows : -1;
        $stmt->close();

        return $affected;
    }

    /**
     * Parses "document:12" / "folder:3" tokens into [[type, id], ...],
     * dropping anything malformed and duplicates.
     */
    function doc_lib_parse_items(mixed $raw): array
    {
        $items = [];

        foreach ((array)$raw as $token) {
            if (!is_string($token) || !preg_match('/^(document|folder):(\d+)$/', $token, $m)) {
                continue;
            }

            $id = (int)$m[2];

            if ($id > 0) {
                $items[$m[1] . ':' . $id] = [$m[1], $id];
            }
        }

        return array_values($items);
    }

    /* ------------------------------------------------------------------
     * Activity
     * ------------------------------------------------------------------ */

    function doc_lib_log(mysqli $conn, string $type, int $id, int $userId, string $action, string $details = ''): void
    {
        if ($id < 1 || $userId < 1 || !doc_lib_ready($conn)) {
            return;
        }

        doc_lib_exec(
            $conn,
            'INSERT INTO document_activity (resource_type, resource_id, user_id, action, details) VALUES (?, ?, ?, ?, NULLIF(?, \'\'))',
            'siiss',
            [$type, $id, $userId, substr($action, 0, 30), mb_substr($details, 0, 255)]
        );
    }

    /** Marks a document as modified now by $userId. */
    function doc_lib_touch(mysqli $conn, int $documentId, int $userId): void
    {
        if (!doc_lib_ready($conn)) {
            return;
        }

        doc_lib_exec(
            $conn,
            'UPDATE documents SET modified_at = NOW(), modified_by = ? WHERE document_id = ?',
            'ii',
            [$userId, $documentId]
        );
    }

    function doc_lib_activity_label(string $action): string
    {
        return [
            'upload' => 'uploaded',
            'create' => 'created',
            'view' => 'viewed',
            'download' => 'downloaded',
            'edit' => 'edited the properties of',
            'rename' => 'renamed',
            'move' => 'moved',
            'share' => 'changed access to',
            'delete' => 'deleted',
            'restore' => 'restored',
            'version' => 'uploaded a new version of',
            'restore_version' => 'restored a previous version of',
            'checkout' => 'checked out',
            'checkin' => 'checked in',
        ][$action] ?? $action;
    }

    /* ------------------------------------------------------------------
     * Favourites
     * ------------------------------------------------------------------ */

    /** ['document' => [id => true], 'folder' => [id => true]] for a user. */
    function doc_lib_favorites(mysqli $conn, int $userId): array
    {
        $map = ['document' => [], 'folder' => []];

        if (!doc_lib_ready($conn)) {
            return $map;
        }

        foreach (doc_lib_rows($conn, 'SELECT resource_type, resource_id FROM document_favorites WHERE user_id = ?', 'i', [$userId]) as $row) {
            $map[(string)$row['resource_type']][(int)$row['resource_id']] = true;
        }

        return $map;
    }

    function doc_lib_set_favorite(mysqli $conn, int $userId, string $type, int $id, bool $on): bool
    {
        if (!doc_lib_ready($conn)) {
            return false;
        }

        if ($on) {
            return doc_lib_exec(
                $conn,
                'INSERT IGNORE INTO document_favorites (user_id, resource_type, resource_id) VALUES (?, ?, ?)',
                'isi',
                [$userId, $type, $id]
            ) >= 0;
        }

        return doc_lib_exec(
            $conn,
            'DELETE FROM document_favorites WHERE user_id = ? AND resource_type = ? AND resource_id = ?',
            'isi',
            [$userId, $type, $id]
        ) >= 0;
    }

    /* ------------------------------------------------------------------
     * Queries for the library views
     * ------------------------------------------------------------------ */

    /**
     * Documents matching $opts, filtered to what the user may see.
     *
     * Options: folder_id (int, null = anywhere), search, project_id,
     * program_id, uploaded_by, ids (int[]), deleted (bool: recycle bin),
     * limit (int).
     */
    function doc_lib_documents(mysqli $conn, int $userId, string $role, array $opts = []): array
    {
        $ready = doc_lib_ready($conn);
        $deleted = !empty($opts['deleted']);

        if ($deleted && !$ready) {
            return [];
        }

        $select = "
            SELECT
                d.*,
                p.project_name,
                p.project_code,
                pr.program_name,
                pr.program_code,
                u.full_name AS uploaded_by_name
        ";
        $joins = "
            FROM documents d
            LEFT JOIN projects p ON p.project_id = d.project_id
            LEFT JOIN programs pr ON pr.id = d.program_id
            LEFT JOIN users u ON u.user_id = d.uploaded_by
        ";

        if ($ready) {
            $select .= ",
                mu.full_name AS modified_by_name,
                cu.full_name AS checked_out_name,
                du.full_name AS deleted_by_name
            ";
            $joins .= "
                LEFT JOIN users mu ON mu.user_id = d.modified_by
                LEFT JOIN users cu ON cu.user_id = d.checked_out_by
                LEFT JOIN users du ON du.user_id = d.deleted_by
            ";
        }

        $where = ' WHERE 1=1';
        $types = '';
        $params = [];

        if ($ready) {
            $where .= $deleted ? ' AND d.deleted_at IS NOT NULL' : ' AND d.deleted_at IS NULL';
        }

        if ($deleted && !doc_lib_is_admin()) {
            $where .= ' AND d.deleted_by = ?';
            $types .= 'i';
            $params[] = $userId;
        }

        if (array_key_exists('folder_id', $opts) && $opts['folder_id'] !== null) {
            $where .= ' AND COALESCE(d.folder_id, 0) = ?';
            $types .= 'i';
            $params[] = (int)$opts['folder_id'];
        }

        $search = trim((string)($opts['search'] ?? ''));

        if ($search !== '') {
            $where .= ' AND (d.document_name LIKE ? OR d.description LIKE ? OR d.tags LIKE ? OR d.document_type LIKE ?)';
            $like = '%' . $search . '%';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }

        foreach (['project_id' => 'd.project_id', 'program_id' => 'd.program_id', 'uploaded_by' => 'd.uploaded_by'] as $key => $column) {
            if ((int)($opts[$key] ?? 0) > 0) {
                $where .= " AND {$column} = ?";
                $types .= 'i';
                $params[] = (int)$opts[$key];
            }
        }

        if (isset($opts['ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array)$opts['ids'])));

            if (!$ids) {
                return [];
            }

            $where .= ' AND d.document_id IN (' . implode(',', $ids) . ')';
        }

        $order = $deleted
            ? ' ORDER BY d.deleted_at DESC'
            : ($ready
                ? ' ORDER BY COALESCE(d.modified_at, d.upload_date) DESC, d.document_id DESC'
                : ' ORDER BY d.upload_date DESC, d.document_id DESC');

        $limit = (int)($opts['limit'] ?? 0);
        $sql = $select . $joins . $where . $order . ($limit > 0 ? ' LIMIT ' . $limit : '');

        $out = [];

        foreach (doc_lib_rows($conn, $sql, $types, $params) as $row) {
            $id = (int)$row['document_id'];

            if ($deleted || doc_can($conn, 'document', $id, $userId, $role, 'view')) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Folders matching $opts (ids, deleted, search, created_by), filtered
     * to what the user may see. File counts are attached.
     */
    function doc_lib_folders(mysqli $conn, int $userId, string $role, array $opts = []): array
    {
        if (!doc_table_exists($conn, 'document_folders')) {
            return [];
        }

        $ready = doc_lib_ready($conn);
        $deleted = !empty($opts['deleted']);

        if ($deleted && !$ready) {
            return [];
        }

        $sql = "
            SELECT f.*, u.full_name AS owner_name" . ($ready ? ", du.full_name AS deleted_by_name" : '') . "
            FROM document_folders f
            LEFT JOIN users u ON u.user_id = f.created_by
        " . ($ready ? ' LEFT JOIN users du ON du.user_id = f.deleted_by' : '') . ' WHERE 1=1';

        $types = '';
        $params = [];

        if ($ready) {
            $sql .= $deleted ? ' AND f.deleted_at IS NOT NULL' : ' AND f.deleted_at IS NULL';
        }

        if ($deleted && !doc_lib_is_admin()) {
            $sql .= ' AND f.deleted_by = ?';
            $types .= 'i';
            $params[] = $userId;
        }

        $search = trim((string)($opts['search'] ?? ''));

        if ($search !== '') {
            $sql .= ' AND (f.folder_name LIKE ? OR f.description LIKE ? OR f.document_type LIKE ?)';
            $like = '%' . $search . '%';
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }

        if ((int)($opts['created_by'] ?? 0) > 0) {
            $sql .= ' AND f.created_by = ?';
            $types .= 'i';
            $params[] = (int)$opts['created_by'];
        }

        if (isset($opts['ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array)$opts['ids'])));

            if (!$ids) {
                return [];
            }

            $sql .= ' AND f.folder_id IN (' . implode(',', $ids) . ')';
        }

        $sql .= $deleted ? ' ORDER BY f.deleted_at DESC' : ' ORDER BY f.folder_name';

        $counts = doc_recursive_folder_file_counts($conn);
        $out = [];

        foreach (doc_lib_rows($conn, $sql, $types, $params) as $row) {
            $id = (int)$row['folder_id'];

            if (!$deleted && !doc_can($conn, 'folder', $id, $userId, $role, 'view')) {
                continue;
            }

            if (!$deleted && doc_lib_folder_in_bin($conn, $id)) {
                continue; // a live folder inside a recycled one
            }

            $row['file_count'] = $counts[$id] ?? 0;
            $out[] = $row;
        }

        return $out;
    }

    /** Documents the user opened or changed most recently. */
    function doc_lib_recent(mysqli $conn, int $userId, string $role, int $limit = 40): array
    {
        if (!doc_lib_ready($conn)) {
            return doc_lib_documents($conn, $userId, $role, ['uploaded_by' => $userId, 'limit' => $limit]);
        }

        $order = [];

        foreach (doc_lib_rows(
            $conn,
            "SELECT resource_id, MAX(created_at) AS last_at
             FROM document_activity
             WHERE user_id = ? AND resource_type = 'document'
             GROUP BY resource_id
             ORDER BY last_at DESC
             LIMIT " . max(1, $limit),
            'i',
            [$userId]
        ) as $row) {
            $order[(int)$row['resource_id']] = (string)$row['last_at'];
        }

        if (!$order) {
            return doc_lib_documents($conn, $userId, $role, ['uploaded_by' => $userId, 'limit' => $limit]);
        }

        $docs = doc_lib_documents($conn, $userId, $role, ['ids' => array_keys($order)]);

        foreach ($docs as &$doc) {
            $doc['last_accessed'] = $order[(int)$doc['document_id']] ?? null;
        }
        unset($doc);

        usort($docs, static fn(array $a, array $b): int => strcmp((string)$b['last_accessed'], (string)$a['last_accessed']));

        return $docs;
    }

    /** Items someone else owns that were explicitly shared with the user. */
    function doc_lib_shared_with_me(mysqli $conn, int $userId, string $role): array
    {
        if (!doc_table_exists($conn, 'document_permissions')) {
            return ['folders' => [], 'documents' => []];
        }

        $ids = ['folder' => [], 'document' => []];

        foreach (doc_lib_rows(
            $conn,
            'SELECT resource_type, resource_id FROM document_permissions WHERE user_id = ? AND can_view = 1',
            'i',
            [$userId]
        ) as $row) {
            $ids[(string)$row['resource_type']][] = (int)$row['resource_id'];
        }

        $folders = $ids['folder']
            ? array_values(array_filter(
                doc_lib_folders($conn, $userId, $role, ['ids' => $ids['folder']]),
                static fn(array $f): bool => (int)($f['created_by'] ?? 0) !== $userId
            ))
            : [];

        $documents = $ids['document']
            ? array_values(array_filter(
                doc_lib_documents($conn, $userId, $role, ['ids' => $ids['document']]),
                static fn(array $d): bool => (int)($d['uploaded_by'] ?? 0) !== $userId
            ))
            : [];

        return ['folders' => $folders, 'documents' => $documents];
    }

    /** True when the folder or any ancestor is in the recycle bin. */
    function doc_lib_folder_in_bin(mysqli $conn, int $folderId): bool
    {
        static $parents = null;

        if ($folderId < 1 || !doc_lib_ready($conn)) {
            return false;
        }

        if ($parents === null) {
            $parents = [];

            foreach (doc_lib_rows($conn, 'SELECT folder_id, COALESCE(parent_folder_id, 0) AS parent_id, deleted_at FROM document_folders') as $row) {
                $parents[(int)$row['folder_id']] = [(int)$row['parent_id'], $row['deleted_at'] !== null];
            }
        }

        $seen = [];

        while ($folderId > 0 && isset($parents[$folderId]) && !isset($seen[$folderId])) {
            $seen[$folderId] = true;

            if ($parents[$folderId][1]) {
                return true;
            }

            $folderId = $parents[$folderId][0];
        }

        return false;
    }

    /** "Documents › Reports › 2026" style path for a folder id. */
    function doc_lib_folder_path(mysqli $conn, int $folderId): string
    {
        static $folders = null;

        if ($folderId < 1) {
            return 'Documents';
        }

        if ($folders === null) {
            $folders = [];

            if (doc_table_exists($conn, 'document_folders')) {
                foreach (doc_lib_rows($conn, 'SELECT folder_id, COALESCE(parent_folder_id, 0) AS parent_id, folder_name FROM document_folders') as $row) {
                    $folders[(int)$row['folder_id']] = [(int)$row['parent_id'], (string)$row['folder_name']];
                }
            }
        }

        $parts = [];
        $seen = [];

        while ($folderId > 0 && isset($folders[$folderId]) && !isset($seen[$folderId])) {
            $seen[$folderId] = true;
            array_unshift($parts, $folders[$folderId][1]);
            $folderId = $folders[$folderId][0];
        }

        array_unshift($parts, 'Documents');

        return implode(' › ', $parts);
    }

    /* ------------------------------------------------------------------
     * Recycle bin
     * ------------------------------------------------------------------ */

    function doc_lib_bin_enabled(mysqli $conn): bool
    {
        return doc_lib_ready($conn);
    }

    function doc_lib_soft_delete(mysqli $conn, string $type, int $id, int $userId): bool
    {
        if (!doc_lib_ready($conn)) {
            return false;
        }

        $sql = $type === 'folder'
            ? 'UPDATE document_folders SET deleted_at = NOW(), deleted_by = ? WHERE folder_id = ? AND deleted_at IS NULL'
            : 'UPDATE documents SET deleted_at = NOW(), deleted_by = ?, checked_out_by = NULL, checked_out_at = NULL WHERE document_id = ? AND deleted_at IS NULL';

        return doc_lib_exec($conn, $sql, 'ii', [$userId, $id]) > 0;
    }

    /** Whether the user may restore or purge a recycled item. */
    function doc_lib_can_manage_recycled(mysqli $conn, string $type, int $id, int $userId): bool
    {
        if (doc_lib_is_admin()) {
            return true;
        }

        $rows = $type === 'folder'
            ? doc_lib_rows($conn, 'SELECT deleted_by FROM document_folders WHERE folder_id = ? AND deleted_at IS NOT NULL', 'i', [$id])
            : doc_lib_rows($conn, 'SELECT deleted_by FROM documents WHERE document_id = ? AND deleted_at IS NOT NULL', 'i', [$id]);

        return $rows && (int)$rows[0]['deleted_by'] === $userId;
    }

    /**
     * Restores a recycled item. If its original parent folder is gone or
     * itself recycled, the item comes back at the library root.
     */
    function doc_lib_restore(mysqli $conn, string $type, int $id): array
    {
        if ($type === 'folder') {
            $rows = doc_lib_rows($conn, 'SELECT COALESCE(parent_folder_id, 0) AS parent_id FROM document_folders WHERE folder_id = ?', 'i', [$id]);
            $parent = (int)($rows[0]['parent_id'] ?? 0);
            $toRoot = $parent > 0 && (!doc_folder_exists($conn, $parent) || doc_lib_folder_in_bin($conn, $parent));

            $ok = doc_lib_exec(
                $conn,
                'UPDATE document_folders SET deleted_at = NULL, deleted_by = NULL' . ($toRoot ? ', parent_folder_id = NULL' : '') . ' WHERE folder_id = ?',
                'i',
                [$id]
            ) >= 0;

            return ['success' => $ok, 'to_root' => $toRoot, 'folder_id' => $toRoot ? 0 : $parent];
        }

        $rows = doc_lib_rows($conn, 'SELECT COALESCE(folder_id, 0) AS folder_id FROM documents WHERE document_id = ?', 'i', [$id]);
        $folder = (int)($rows[0]['folder_id'] ?? 0);
        $toRoot = $folder > 0 && (!doc_folder_exists($conn, $folder) || doc_lib_folder_in_bin($conn, $folder));

        $ok = doc_lib_exec(
            $conn,
            'UPDATE documents SET deleted_at = NULL, deleted_by = NULL' . ($toRoot ? ', folder_id = NULL' : '') . ' WHERE document_id = ?',
            'i',
            [$id]
        ) >= 0;

        return ['success' => $ok, 'to_root' => $toRoot, 'folder_id' => $toRoot ? 0 : $folder];
    }

    /** Permanently deletes an item (files on disk, versions, rows). */
    function doc_lib_purge(mysqli $conn, string $type, int $id): array
    {
        if ($type === 'folder') {
            foreach (doc_collect_folder_tree($conn, $id) as $folderId) {
                foreach (doc_lib_rows($conn, 'SELECT document_id FROM documents WHERE folder_id = ?', 'i', [$folderId]) as $row) {
                    doc_lib_purge_side_data($conn, 'document', (int)$row['document_id']);
                }

                doc_lib_purge_side_data($conn, 'folder', $folderId);
            }

            return doc_delete_folder_recursive($conn, $id);
        }

        doc_lib_purge_side_data($conn, 'document', $id);

        return deleteDocument($conn, $id);
    }

    /** Removes versions (and their files), favourites and activity. */
    function doc_lib_purge_side_data(mysqli $conn, string $type, int $id): void
    {
        if (!doc_lib_ready($conn)) {
            return;
        }

        if ($type === 'document') {
            foreach (doc_lib_rows($conn, 'SELECT file_path FROM document_versions WHERE document_id = ?', 'i', [$id]) as $row) {
                doc_lib_unlink((string)$row['file_path']);
            }

            doc_lib_exec($conn, 'DELETE FROM document_versions WHERE document_id = ?', 'i', [$id]);
        }

        doc_lib_exec($conn, 'DELETE FROM document_favorites WHERE resource_type = ? AND resource_id = ?', 'si', [$type, $id]);
        doc_lib_exec($conn, 'DELETE FROM document_activity WHERE resource_type = ? AND resource_id = ?', 'si', [$type, $id]);
    }

    function doc_lib_unlink(string $publicPath): void
    {
        if ($publicPath === '' || str_contains($publicPath, '..')) {
            return;
        }

        $absolute = dirname(__DIR__) . '/' . ltrim($publicPath, '/');

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /* ------------------------------------------------------------------
     * Check-out / check-in
     * ------------------------------------------------------------------ */

    /**
     * Name of the other user holding a check-out that blocks $userId from
     * changing the document, or null when nothing blocks them.
     */
    function doc_lib_checkout_blocker(mysqli $conn, int $documentId, int $userId): ?string
    {
        if (!doc_lib_ready($conn) || doc_lib_is_admin()) {
            return null;
        }

        $rows = doc_lib_rows(
            $conn,
            'SELECT d.checked_out_by, u.full_name FROM documents d LEFT JOIN users u ON u.user_id = d.checked_out_by WHERE d.document_id = ?',
            'i',
            [$documentId]
        );

        $holder = (int)($rows[0]['checked_out_by'] ?? 0);

        if ($holder < 1 || $holder === $userId) {
            return null;
        }

        return (string)($rows[0]['full_name'] ?? 'another user');
    }

    function doc_lib_set_checkout(mysqli $conn, int $documentId, int $userId, bool $checkOut): bool
    {
        if (!doc_lib_ready($conn)) {
            return false;
        }

        if ($checkOut) {
            return doc_lib_exec(
                $conn,
                'UPDATE documents SET checked_out_by = ?, checked_out_at = NOW() WHERE document_id = ? AND (checked_out_by IS NULL OR checked_out_by = ?)',
                'iii',
                [$userId, $documentId, $userId]
            ) >= 0;
        }

        return doc_lib_exec(
            $conn,
            'UPDATE documents SET checked_out_by = NULL, checked_out_at = NULL WHERE document_id = ?',
            'i',
            [$documentId]
        ) >= 0;
    }

    /* ------------------------------------------------------------------
     * Versions
     * ------------------------------------------------------------------ */

    /**
     * Stores an uploaded file under uploads/documents/ the same way
     * uploadDocument() does (gzip for compressible types).
     */
    function doc_lib_store_upload(array $file): array
    {
        $errors = validateFileUpload($file);

        if ($errors) {
            return ['success' => false, 'error' => implode(' ', $errors)];
        }

        $uploadDir = dirname(__DIR__) . '/uploads/documents/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['success' => false, 'error' => 'Could not create document upload folder.'];
        }

        $originalName = (string)($file['name'] ?? 'document');
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $token = bin2hex(random_bytes(12)) . '_' . time();
        $suffix = $extension !== '' ? $extension : 'file';

        if (doc_should_compress($extension)) {
            $temporary = $uploadDir . $token . '.upload';

            if (!move_uploaded_file((string)$file['tmp_name'], $temporary)) {
                return ['success' => false, 'error' => 'Failed to save the uploaded file.'];
            }

            $originalSize = (int)filesize($temporary);
            $mime = doc_detect_mime($temporary);
            $name = $token . '.' . $suffix . '.gz';
            $result = doc_gzip_file($temporary, $uploadDir . $name, 6);
            @unlink($temporary);

            if (empty($result['success'])) {
                @unlink($uploadDir . $name);
                return ['success' => false, 'error' => $result['error'] ?? 'Could not compress uploaded file.'];
            }

            return [
                'success' => true,
                'file_path' => 'uploads/documents/' . $name,
                'file_size' => (int)($result['compressed_size'] ?? filesize($uploadDir . $name)),
                'original_file_size' => $originalSize,
                'original_filename' => $originalName,
                'original_mime_type' => $mime,
                'original_extension' => $extension,
                'is_compressed' => 1,
            ];
        }

        $name = $token . '.' . $suffix;

        if (!move_uploaded_file((string)$file['tmp_name'], $uploadDir . $name)) {
            return ['success' => false, 'error' => 'Failed to save the uploaded file.'];
        }

        $size = (int)filesize($uploadDir . $name);

        return [
            'success' => true,
            'file_path' => 'uploads/documents/' . $name,
            'file_size' => $size,
            'original_file_size' => $size,
            'original_filename' => $originalName,
            'original_mime_type' => doc_detect_mime($uploadDir . $name),
            'original_extension' => $extension,
            'is_compressed' => 0,
        ];
    }

    /** Copies the document's current file fields into document_versions. */
    function doc_lib_archive_current(mysqli $conn, array $doc, int $userId, string $comment): bool
    {
        return doc_lib_exec(
            $conn,
            'INSERT INTO document_versions
                (document_id, version_number, file_path, file_size, original_file_size, original_filename,
                 original_mime_type, original_extension, is_compressed, created_by, created_at, comment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'))',
            'iisiisssiiss',
            [
                (int)$doc['document_id'],
                max(1, (int)($doc['version_number'] ?? 1)),
                (string)$doc['file_path'],
                (int)($doc['file_size'] ?? 0),
                (int)($doc['original_file_size'] ?? $doc['file_size'] ?? 0),
                (string)($doc['original_filename'] ?? ''),
                (string)($doc['original_mime_type'] ?? $doc['mime_type'] ?? ''),
                (string)($doc['original_extension'] ?? ''),
                !empty($doc['is_compressed']) ? 1 : 0,
                (int)($doc['modified_by'] ?? $doc['uploaded_by'] ?? $userId),
                (string)($doc['modified_at'] ?? $doc['upload_date'] ?? date('Y-m-d H:i:s')),
                $comment,
            ]
        ) > 0;
    }

    /** Points the document row at a stored file and bumps its version. */
    function doc_lib_apply_file(mysqli $conn, int $documentId, array $stored, int $version, int $userId): bool
    {
        $columns = doc_lib_table_columns($conn, 'documents');
        $sets = ['file_path = ?', 'file_size = ?', 'version_number = ?', 'modified_at = NOW()', 'modified_by = ?'];
        $types = 'siii';
        $params = [(string)$stored['file_path'], (int)$stored['file_size'], $version, $userId];

        foreach (['original_file_size' => 'i', 'original_filename' => 's', 'original_mime_type' => 's', 'original_extension' => 's', 'is_compressed' => 'i'] as $column => $type) {
            if (isset($columns[$column])) {
                $sets[] = $column . ' = ?';
                $types .= $type;
                $params[] = $type === 'i' ? (int)($stored[$column] ?? 0) : (string)($stored[$column] ?? '');
            }
        }

        if (isset($columns['mime_type'])) {
            $sets[] = 'mime_type = ?';
            $types .= 's';
            $params[] = (string)($stored['original_mime_type'] ?? '');
        }

        $types .= 'i';
        $params[] = $documentId;

        return doc_lib_exec($conn, 'UPDATE documents SET ' . implode(', ', $sets) . ' WHERE document_id = ?', $types, $params) >= 0;
    }

    function doc_lib_document_row(mysqli $conn, int $documentId): ?array
    {
        $rows = doc_lib_rows($conn, 'SELECT * FROM documents WHERE document_id = ?', 'i', [$documentId]);

        return $rows[0] ?? null;
    }

    /** Uploads $file as the new current version of a document. */
    function doc_lib_add_version(mysqli $conn, int $documentId, array $file, int $userId, string $comment): array
    {
        if (!doc_lib_ready($conn)) {
            return ['success' => false, 'error' => 'Version history is not available on this server yet.'];
        }

        $doc = doc_lib_document_row($conn, $documentId);

        if (!$doc) {
            return ['success' => false, 'error' => 'Document not found.'];
        }

        $stored = doc_lib_store_upload($file);

        if (empty($stored['success'])) {
            return $stored;
        }

        if (!doc_lib_archive_current($conn, $doc, $userId, $comment)) {
            doc_lib_unlink($stored['file_path']);
            return ['success' => false, 'error' => 'Could not archive the current version.'];
        }

        $next = doc_lib_next_version($conn, $documentId, (int)($doc['version_number'] ?? 1));

        if (!doc_lib_apply_file($conn, $documentId, $stored, $next, $userId)) {
            return ['success' => false, 'error' => 'Could not save the new version.'];
        }

        return ['success' => true, 'version' => $next];
    }

    function doc_lib_next_version(mysqli $conn, int $documentId, int $current): int
    {
        $rows = doc_lib_rows($conn, 'SELECT MAX(version_number) AS v FROM document_versions WHERE document_id = ?', 'i', [$documentId]);

        return max($current, (int)($rows[0]['v'] ?? 0)) + 1;
    }

    /** Version history, newest first, including the current version. */
    function doc_lib_versions(mysqli $conn, int $documentId): array
    {
        $doc = doc_lib_document_row($conn, $documentId);

        if (!$doc) {
            return [];
        }

        $byId = static function (int $uid) use ($conn): string {
            static $names = [];

            if ($uid < 1) {
                return '';
            }

            if (!isset($names[$uid])) {
                $rows = doc_lib_rows($conn, 'SELECT full_name FROM users WHERE user_id = ?', 'i', [$uid]);
                $names[$uid] = (string)($rows[0]['full_name'] ?? '');
            }

            return $names[$uid];
        };

        $list = [[
            'version_id' => 0,
            'version' => max(1, (int)($doc['version_number'] ?? 1)),
            'current' => true,
            'size' => doc_format_bytes((int)($doc['original_file_size'] ?? $doc['file_size'] ?? 0)),
            'by' => $byId((int)($doc['modified_by'] ?? $doc['uploaded_by'] ?? 0)),
            'when' => (string)($doc['modified_at'] ?? $doc['upload_date'] ?? ''),
            'comment' => '',
        ]];

        if (!doc_lib_ready($conn)) {
            return $list;
        }

        foreach (doc_lib_rows(
            $conn,
            'SELECT * FROM document_versions WHERE document_id = ? ORDER BY version_number DESC, version_id DESC',
            'i',
            [$documentId]
        ) as $row) {
            $list[] = [
                'version_id' => (int)$row['version_id'],
                'version' => (int)$row['version_number'],
                'current' => false,
                'size' => doc_format_bytes((int)($row['original_file_size'] ?? $row['file_size'] ?? 0)),
                'by' => $byId((int)($row['created_by'] ?? 0)),
                'when' => (string)$row['created_at'],
                'comment' => (string)($row['comment'] ?? ''),
            ];
        }

        return $list;
    }

    function doc_lib_version_row(mysqli $conn, int $versionId): ?array
    {
        if (!doc_lib_ready($conn)) {
            return null;
        }

        $rows = doc_lib_rows($conn, 'SELECT * FROM document_versions WHERE version_id = ?', 'i', [$versionId]);

        return $rows[0] ?? null;
    }

    /**
     * Makes an older version current again. The current file is archived
     * as a new history entry, so nothing is lost.
     */
    function doc_lib_restore_version(mysqli $conn, int $versionId, int $userId): array
    {
        $version = doc_lib_version_row($conn, $versionId);

        if (!$version) {
            return ['success' => false, 'error' => 'Version not found.'];
        }

        $documentId = (int)$version['document_id'];
        $doc = doc_lib_document_row($conn, $documentId);

        if (!$doc) {
            return ['success' => false, 'error' => 'Document not found.'];
        }

        if (!doc_lib_archive_current($conn, $doc, $userId, 'Replaced by restoring version ' . (int)$version['version_number'])) {
            return ['success' => false, 'error' => 'Could not archive the current version.'];
        }

        $next = doc_lib_next_version($conn, $documentId, (int)($doc['version_number'] ?? 1));

        $stored = [
            'file_path' => (string)$version['file_path'],
            'file_size' => (int)$version['file_size'],
            'original_file_size' => (int)$version['original_file_size'],
            'original_filename' => (string)$version['original_filename'],
            'original_mime_type' => (string)$version['original_mime_type'],
            'original_extension' => (string)$version['original_extension'],
            'is_compressed' => (int)$version['is_compressed'],
        ];

        if (!doc_lib_apply_file($conn, $documentId, $stored, $next, $userId)) {
            return ['success' => false, 'error' => 'Could not restore the version.'];
        }

        // The file now belongs to the live row; drop the history entry without unlinking.
        doc_lib_exec($conn, 'DELETE FROM document_versions WHERE version_id = ?', 'i', [$versionId]);

        return ['success' => true, 'document_id' => $documentId, 'version' => $next, 'restored' => (int)$version['version_number']];
    }

    /** Streams an archived version as a download. */
    function doc_lib_stream_version(array $version, string $documentName): never
    {
        $publicPath = (string)$version['file_path'];
        $absolute = dirname(__DIR__) . '/' . ltrim($publicPath, '/');

        if ($publicPath === '' || str_contains($publicPath, '..') || !is_file($absolute)) {
            http_response_code(404);
            exit('File is missing.');
        }

        $name = trim((string)($version['original_filename'] ?? ''));

        if ($name === '') {
            $name = $documentName . ((string)$version['original_extension'] !== '' ? '.' . $version['original_extension'] : '');
        }

        $info = pathinfo($name);
        $name = ($info['filename'] ?? 'document') . ' (v' . (int)$version['version_number'] . ')'
            . (!empty($info['extension']) ? '.' . $info['extension'] : '');

        $compressed = !empty($version['is_compressed']) || str_ends_with(strtolower($absolute), '.gz');
        $mime = trim((string)($version['original_mime_type'] ?? '')) ?: 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', basename($name)) . '"');

        if ($compressed) {
            if (!doc_stream_gzip_file($absolute)) {
                http_response_code(500);
                exit('Could not decompress document.');
            }
        } else {
            header('Content-Length: ' . filesize($absolute));
            readfile($absolute);
        }

        exit;
    }

    /* ------------------------------------------------------------------
     * Presentation helpers (shared by the page and the details endpoint)
     * ------------------------------------------------------------------ */

    /** kind (CSS modifier), Font Awesome icon and short extension label. */
    function doc_lib_file_kind(array $doc): array
    {
        $ext = strtolower(trim((string)($doc['original_extension'] ?? ''), ". \t"));

        if ($ext === '') {
            $name = (string)($doc['original_filename'] ?? '');

            if ($name === '') {
                $name = (string)($doc['file_path'] ?? '');
            }

            if (substr(strtolower($name), -3) === '.gz') {
                $name = substr($name, 0, -3);
            }

            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        }

        $map = [
            'pdf' => [['pdf'], 'fa-file-pdf'],
            'word' => [['doc', 'docx', 'odt', 'rtf'], 'fa-file-word'],
            'excel' => [['xls', 'xlsx', 'ods'], 'fa-file-excel'],
            'csv' => [['csv'], 'fa-file-csv'],
            'ppt' => [['ppt', 'pptx', 'odp'], 'fa-file-powerpoint'],
            'image' => [['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'heic'], 'fa-file-image'],
            'video' => [['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv'], 'fa-file-video'],
            'audio' => [['mp3', 'wav', 'm4a', 'ogg', 'aac'], 'fa-file-audio'],
            'archive' => [['zip', 'rar', '7z', 'tar', 'gz'], 'fa-file-zipper'],
            'text' => [['txt', 'md', 'log', 'json', 'xml'], 'fa-file-lines'],
        ];

        foreach ($map as $kind => [$exts, $icon]) {
            if (in_array($ext, $exts, true)) {
                return ['kind' => $kind, 'icon' => $icon, 'label' => strtoupper($ext), 'ext' => $ext];
            }
        }

        return [
            'kind' => 'generic',
            'icon' => 'fa-file',
            'label' => $ext !== '' ? strtoupper(substr($ext, 0, 5)) : 'FILE',
            'ext' => $ext,
        ];
    }

    function doc_lib_initials(string $name): string
    {
        $initials = '';

        foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    /**
     * Who has access: the owner, explicit grants, and (for a file without
     * its own grants) the grants it inherits from its folder.
     */
    function doc_lib_access_list(mysqli $conn, string $type, int $id): array
    {
        $ownerRows = $type === 'folder'
            ? doc_lib_rows($conn, 'SELECT f.created_by AS uid, u.full_name, u.role, COALESCE(f.parent_folder_id, 0) AS parent_id FROM document_folders f LEFT JOIN users u ON u.user_id = f.created_by WHERE f.folder_id = ?', 'i', [$id])
            : doc_lib_rows($conn, 'SELECT d.uploaded_by AS uid, u.full_name, u.role, COALESCE(d.folder_id, 0) AS parent_id FROM documents d LEFT JOIN users u ON u.user_id = d.uploaded_by WHERE d.document_id = ?', 'i', [$id]);

        $list = [];
        $ownerId = (int)($ownerRows[0]['uid'] ?? 0);

        if ($ownerId > 0) {
            $name = (string)($ownerRows[0]['full_name'] ?? 'Owner');
            $list[] = ['name' => $name, 'initials' => doc_lib_initials($name), 'role' => (string)($ownerRows[0]['role'] ?? ''), 'access' => 'Owner', 'inherited' => ''];
        }

        if (!doc_table_exists($conn, 'document_permissions')) {
            return $list;
        }

        $grants = static function (string $rtype, int $rid) use ($conn, $ownerId): array {
            $out = [];

            foreach (doc_lib_rows(
                $conn,
                'SELECT p.user_id, p.can_view, p.can_edit, p.can_download, u.full_name, u.role
                 FROM document_permissions p
                 LEFT JOIN users u ON u.user_id = p.user_id
                 WHERE p.resource_type = ? AND p.resource_id = ?
                 ORDER BY u.full_name',
                'si',
                [$rtype, $rid]
            ) as $row) {
                if ((int)$row['user_id'] === $ownerId) {
                    continue;
                }

                $rights = [];

                if ((int)$row['can_edit'] === 1) {
                    $rights[] = 'Can edit';
                } elseif ((int)$row['can_view'] === 1) {
                    $rights[] = 'Can view';
                }

                if ((int)$row['can_download'] === 1) {
                    $rights[] = 'download';
                }

                if (!$rights) {
                    continue;
                }

                $name = (string)($row['full_name'] ?? 'User');
                $out[] = ['name' => $name, 'initials' => doc_lib_initials($name), 'role' => (string)($row['role'] ?? ''), 'access' => implode(', ', $rights), 'inherited' => ''];
            }

            return $out;
        };

        $explicit = $grants($type, $id);

        if (!$explicit && $type === 'document' && (int)($ownerRows[0]['parent_id'] ?? 0) > 0) {
            $folderId = (int)$ownerRows[0]['parent_id'];
            $from = doc_lib_folder_path($conn, $folderId);

            foreach ($grants('folder', $folderId) as $row) {
                $row['inherited'] = $from;
                $list[] = $row;
            }

            return $list;
        }

        return array_merge($list, $explicit);
    }

    /** Recent activity entries for an item. */
    function doc_lib_activity(mysqli $conn, string $type, int $id, int $limit = 15): array
    {
        if (!doc_lib_ready($conn)) {
            return [];
        }

        $out = [];

        foreach (doc_lib_rows(
            $conn,
            'SELECT a.action, a.details, a.created_at, u.full_name
             FROM document_activity a
             LEFT JOIN users u ON u.user_id = a.user_id
             WHERE a.resource_type = ? AND a.resource_id = ?
             ORDER BY a.created_at DESC, a.activity_id DESC
             LIMIT ' . max(1, $limit),
            'si',
            [$type, $id]
        ) as $row) {
            $name = (string)($row['full_name'] ?? 'Someone');
            $out[] = [
                'user' => $name,
                'initials' => doc_lib_initials($name),
                'action' => doc_lib_activity_label((string)$row['action']),
                'details' => (string)($row['details'] ?? ''),
                'when' => (string)$row['created_at'],
            ];
        }

        return $out;
    }

    /** Everything the info pane shows for one item, or null if hidden. */
    function doc_lib_details(mysqli $conn, int $userId, string $role, string $type, int $id): ?array
    {
        $favorites = doc_lib_favorites($conn, $userId);

        if ($type === 'folder') {
            $rows = doc_lib_rows(
                $conn,
                'SELECT f.*, u.full_name AS owner_name, p.project_name, pr.program_name
                 FROM document_folders f
                 LEFT JOIN users u ON u.user_id = f.created_by
                 LEFT JOIN projects p ON p.project_id = f.project_id
                 LEFT JOIN programs pr ON pr.id = f.program_id
                 WHERE f.folder_id = ?',
                'i',
                [$id]
            );
            $row = $rows[0] ?? null;

            if (!$row || !doc_can($conn, 'folder', $id, $userId, $role, 'view')) {
                return null;
            }

            $counts = doc_recursive_folder_file_counts($conn);

            return [
                'type' => 'folder',
                'id' => $id,
                'name' => (string)$row['folder_name'],
                'icon' => 'fa-folder',
                'kind' => 'folder',
                'properties' => array_filter([
                    'Content type' => 'Folder',
                    'Folder type' => (string)($row['document_type'] ?? ''),
                    'Items' => ($counts[$id] ?? 0) . ' file' . (($counts[$id] ?? 0) === 1 ? '' : 's'),
                    'Created' => (string)($row['created_at'] ?? ''),
                    'Created by' => (string)($row['owner_name'] ?? ''),
                    'Project' => (string)($row['project_name'] ?? ''),
                    'Program' => (string)($row['program_name'] ?? ''),
                    'Location' => doc_lib_folder_path($conn, (int)($row['parent_folder_id'] ?? 0)),
                    'Description' => (string)($row['description'] ?? ''),
                ], static fn($v) => $v !== ''),
                'dates' => ['Created'],
                'favorite' => isset($favorites['folder'][$id]),
                'access' => doc_lib_access_list($conn, 'folder', $id),
                'activity' => doc_lib_activity($conn, 'folder', $id),
                'versions' => [],
                'preview' => '',
            ];
        }

        $docs = doc_lib_documents($conn, $userId, $role, ['ids' => [$id]]);

        if (!$docs) {
            $docs = doc_lib_documents($conn, $userId, $role, ['ids' => [$id], 'deleted' => true]);
        }

        $doc = $docs[0] ?? null;

        if (!$doc) {
            return null;
        }

        $kind = doc_lib_file_kind($doc);
        $size = (int)($doc['original_file_size'] ?? 0) ?: (int)($doc['file_size'] ?? 0);
        $canView = doc_can($conn, 'document', $id, $userId, $role, 'view');

        return [
            'type' => 'document',
            'id' => $id,
            'name' => (string)$doc['document_name'],
            'icon' => $kind['icon'],
            'kind' => $kind['kind'],
            'properties' => array_filter([
                'Content type' => $kind['label'] . ' file',
                'Document type' => (string)($doc['document_type'] ?? ''),
                'File size' => doc_format_bytes($size),
                'Version' => (string)max(1, (int)($doc['version_number'] ?? 1)) . '.0',
                'Modified' => (string)($doc['modified_at'] ?? $doc['upload_date'] ?? ''),
                'Modified by' => (string)($doc['modified_by_name'] ?? $doc['uploaded_by_name'] ?? ''),
                'Created' => (string)($doc['upload_date'] ?? ''),
                'Created by' => (string)($doc['uploaded_by_name'] ?? ''),
                'Checked out to' => (string)($doc['checked_out_name'] ?? ''),
                'Project' => trim((string)($doc['project_code'] ?? '') . ' ' . (string)($doc['project_name'] ?? '')),
                'Program' => trim((string)($doc['program_code'] ?? '') . ' ' . (string)($doc['program_name'] ?? '')),
                'Tags' => (string)($doc['tags'] ?? ''),
                'Location' => doc_lib_folder_path($conn, (int)($doc['folder_id'] ?? 0)),
                'Description' => (string)($doc['description'] ?? ''),
                'Original name' => (string)($doc['original_filename'] ?? ''),
            ], static fn($v) => $v !== ''),
            'dates' => ['Modified', 'Created'],
            'favorite' => isset($favorites['document'][$id]),
            'access' => doc_lib_access_list($conn, 'document', $id),
            'activity' => doc_lib_activity($conn, 'document', $id),
            'versions' => doc_lib_versions($conn, $id),
            'preview' => ($kind['kind'] === 'image' && $kind['ext'] !== 'svg' && $canView && empty($doc['deleted_at']))
                ? 'includes/documents-handler.php?action=thumb&id=' . $id
                : '',
        ];
    }
}
