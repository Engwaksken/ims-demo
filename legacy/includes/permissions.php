<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PAGE PERMISSIONS (database-driven access for check_role)
|--------------------------------------------------------------------------
| Every check_role([...]) call in the code is a "permission": it is keyed by
| the file that makes the call plus the list of roles written in the code.
|
|   - First time a permission is seen (at runtime, or when an administrator
|     runs "Scan pages" on the Role Permissions screen) it is registered and
|     the roles listed in the code are granted it. Nothing changes for users.
|   - From then on the database decides: a user may pass check_role() only if
|     their role (built-in or added on User Permissions) has the grant.
|     Administrators edit grants per role on the Role Permissions screen.
|   - If the permission tables are unavailable, check_role() falls back to the
|     original role-name comparison, so a database problem never locks
|     everyone out.
|
| Administrators can never lose access to the permission screens themselves
| (IMS_PERM_PROTECTED_FILES).
|
| Not covered yet (stage 2): inline $_SESSION['role'] comparisons inside
| pages, which still use the built-in role (see roles.php).
*/

require_once __DIR__ . '/roles.php';

const IMS_PERM_PROTECTED_FILES = [
    'role-permissions',
    'user-permissions',
    'user-permissions-process',
];

if (!function_exists('ims_perm_ensure_tables')) {
    function ims_perm_ensure_tables(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            $ready = (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS ims_permissions (
                    perm_key VARCHAR(190) NOT NULL,
                    source_file VARCHAR(255) NOT NULL,
                    default_roles TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (perm_key),
                    KEY idx_ims_permissions_file (source_file)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ") && (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS ims_role_permissions (
                    role_name VARCHAR(100) NOT NULL,
                    perm_key VARCHAR(190) NOT NULL,
                    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (role_name, perm_key),
                    KEY idx_ims_role_permissions_key (perm_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('Permission tables unavailable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

/** Path of a PHP file relative to legacy/, without ".php" (e.g. "includes/events-process"). */
if (!function_exists('ims_perm_relative_file')) {
    function ims_perm_relative_file(string $file): string
    {
        $base = realpath(dirname(__DIR__));
        $real = realpath($file) ?: $file;
        $rel = ($base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR))
            ? substr($real, strlen($base) + 1)
            : basename($real);

        return preg_replace('/\.php$/i', '', str_replace('\\', '/', $rel)) ?? $rel;
    }
}

/** Trimmed, de-duplicated role list (keeps the spelling used in the code). */
if (!function_exists('ims_perm_clean_roles')) {
    function ims_perm_clean_roles(array $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            if (!is_string($role) || trim($role) === '') {
                continue;
            }
            $out[mb_strtolower(trim($role), 'UTF-8')] = trim($role);
        }
        ksort($out);

        return array_values($out);
    }
}

if (!function_exists('ims_perm_key')) {
    function ims_perm_key(string $relFile, array $roles): string
    {
        $signature = implode('|', array_map(
            static fn(string $r): string => mb_strtolower($r, 'UTF-8'),
            ims_perm_clean_roles($roles)
        ));

        return $relFile . ':' . substr(md5($signature), 0, 8);
    }
}

/** Registered permissions: [perm_key => ['source_file', 'default_roles' => [...]]]. */
if (!function_exists('ims_perm_registry')) {
    function ims_perm_registry(mysqli $conn, bool $refresh = false): array
    {
        static $cache = null;

        if ($cache !== null && !$refresh) {
            return $cache;
        }

        $cache = [];
        $result = $conn->query('SELECT perm_key, source_file, default_roles FROM ims_permissions ORDER BY source_file, perm_key');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $roles = json_decode((string)$row['default_roles'], true);
                $cache[$row['perm_key']] = [
                    'source_file' => (string)$row['source_file'],
                    'default_roles' => is_array($roles) ? $roles : [],
                ];
            }
            $result->close();
        }

        return $cache;
    }
}

/** Permission keys granted to one role: [perm_key => true]. */
if (!function_exists('ims_perm_role_grants')) {
    function ims_perm_role_grants(mysqli $conn, string $role, bool $refresh = false): array
    {
        static $cache = [];

        if (isset($cache[$role]) && !$refresh) {
            return $cache[$role];
        }

        $grants = [];
        $stmt = $conn->prepare('SELECT perm_key FROM ims_role_permissions WHERE role_name = ?');
        if ($stmt) {
            $stmt->bind_param('s', $role);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $grants[$row['perm_key']] = true;
            }
            $stmt->close();
        }

        return $cache[$role] = $grants;
    }
}

/**
 * Roles that should hold a permission by default: the roles written in the
 * code (matched to their real spelling) plus added roles that work like one
 * of them.
 */
if (!function_exists('ims_perm_default_grantees')) {
    function ims_perm_default_grantees(mysqli $conn, array $codeRoles): array
    {
        $byLower = [];
        foreach (ims_all_roles($conn) as $role) {
            $byLower[mb_strtolower($role, 'UTF-8')] = $role;
        }

        $grantees = [];
        foreach ($codeRoles as $role) {
            $canonical = $byLower[mb_strtolower(trim((string)$role), 'UTF-8')] ?? null;
            if ($canonical !== null) {
                $grantees[$canonical] = true;
            }
        }

        foreach (ims_custom_roles($conn) as $name => $row) {
            if (isset($grantees[$row['base_role']])) {
                $grantees[$name] = true;
            }
        }

        return array_keys($grantees);
    }
}

/** Register a permission (once) and grant it to its default roles. */
if (!function_exists('ims_perm_register')) {
    function ims_perm_register(mysqli $conn, string $relFile, array $codeRoles): string
    {
        $codeRoles = ims_perm_clean_roles($codeRoles);
        $key = ims_perm_key($relFile, $codeRoles);
        $json = json_encode($codeRoles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $conn->prepare('INSERT IGNORE INTO ims_permissions (perm_key, source_file, default_roles) VALUES (?, ?, ?)');
        if (!$stmt) {
            return $key;
        }
        $stmt->bind_param('sss', $key, $relFile, $json);
        $stmt->execute();
        $isNew = $stmt->affected_rows === 1;
        $stmt->close();

        if ($isNew) {
            ims_perm_grant_many($conn, $key, ims_perm_default_grantees($conn, $codeRoles));
        }

        return $key;
    }
}

if (!function_exists('ims_perm_grant_many')) {
    function ims_perm_grant_many(mysqli $conn, string $key, array $roles): void
    {
        $stmt = $conn->prepare('INSERT IGNORE INTO ims_role_permissions (role_name, perm_key) VALUES (?, ?)');
        if (!$stmt) {
            return;
        }
        foreach ($roles as $role) {
            $role = (string)$role;
            $stmt->bind_param('ss', $role, $key);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/**
 * The role used for permission lookups: an added role's own name when the
 * session came from a normal login, otherwise the session role (e.g. pages
 * that switch the session to "Applicant").
 */
if (!function_exists('ims_perm_current_role')) {
    function ims_perm_current_role(mysqli $conn): string
    {
        $sessionRole = trim((string)($_SESSION['role'] ?? ''));
        $roleName = trim((string)($_SESSION['role_name'] ?? ''));

        if ($roleName !== '' && ims_effective_role($conn, $roleName) === $sessionRole) {
            return $roleName;
        }

        return $sessionRole;
    }
}

/**
 * Decide a check_role() call. Returns true/false, or null when the
 * permission system is unavailable (caller then uses the old name check).
 */
if (!function_exists('ims_perm_check')) {
    function ims_perm_check(array $codeRoles, string $callerFile): ?bool
    {
        try {
            $conn = function_exists('db_connect') ? db_connect() : ($GLOBALS['conn'] ?? null);
            if (!$conn instanceof mysqli || !ims_perm_ensure_tables($conn)) {
                return null;
            }

            $role = ims_perm_current_role($conn);
            if ($role === '') {
                return false;
            }

            $relFile = ims_perm_relative_file($callerFile);

            if ($role === 'Administrator' && in_array($relFile, IMS_PERM_PROTECTED_FILES, true)) {
                return true;
            }

            $key = ims_perm_key($relFile, $codeRoles);
            if (!isset(ims_perm_registry($conn)[$key])) {
                ims_perm_register($conn, $relFile, $codeRoles);
                ims_perm_registry($conn, true);
                ims_perm_role_grants($conn, $role, true);
            }

            return isset(ims_perm_role_grants($conn, $role)[$key]);
        } catch (Throwable $e) {
            error_log('Permission check failed, using role names: ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * File that called check_role(), skipping the check_*_roles() shortcuts in
 * auth.php so the permission belongs to the page that used them.
 */
if (!function_exists('ims_perm_caller_file')) {
    function ims_perm_caller_file(array $backtrace): string
    {
        $auth = realpath(__DIR__ . '/auth.php');
        foreach ($backtrace as $frame) {
            $file = (string)($frame['file'] ?? '');
            if ($file !== '' && realpath($file) !== $auth) {
                return $file;
            }
        }

        return (string)($backtrace[0]['file'] ?? __FILE__);
    }
}

/* ---------------------------------------------------------------------
   Code scan: find every check_role(...) so the admin screen lists all
   pages, including ones nobody has opened since this was installed.
   --------------------------------------------------------------------- */

/** Roles in a check_role() argument, or null if it isn't a literal/constant. */
if (!function_exists('ims_perm_parse_argument')) {
    function ims_perm_parse_argument(array $tokens, int $start, array $localConsts = []): ?array
    {
        $count = count($tokens);
        $i = $start;

        // Skip whitespace/comments to the first token of the argument.
        while ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }
        if ($i >= $count) {
            return null;
        }

        $tok = $tokens[$i];

        // Constant: one declared in the same file, or a global such as IMS_STAFF_ROLES.
        if (is_array($tok) && $tok[0] === T_STRING) {
            if (isset($localConsts[$tok[1]])) {
                return $localConsts[$tok[1]];
            }
            if (str_starts_with($tok[1], 'IMS_') && defined($tok[1])) {
                $value = constant($tok[1]);
                return is_array($value) ? $value : null;
            }
            return null;
        }

        // Single string: check_role('Administrator').
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [stripcslashes(substr($tok[1], 1, -1))];
        }

        // Array literal: [...] or array(...).
        $open = null;
        if ($tok === '[') {
            $open = '[';
        } elseif (is_array($tok) && $tok[0] === T_ARRAY) {
            $i++;
            while ($i < $count && $tokens[$i] !== '(') {
                $i++;
            }
            $open = '(';
        }
        if ($open === null) {
            return null;
        }

        $close = $open === '[' ? ']' : ')';
        $depth = 0;
        $roles = [];
        for (; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === $open) {
                $depth++;
                continue;
            }
            if ($t === $close) {
                $depth--;
                if ($depth === 0) {
                    return $roles;
                }
                continue;
            }
            if (is_array($t)) {
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $roles[] = stripcslashes(substr($t[1], 1, -1));
                } elseif (in_array($t[0], [T_VARIABLE, T_STRING], true)) {
                    return null; // computed list; leave it to runtime registration
                }
            }
        }

        return null;
    }
}

if (!function_exists('ims_perm_scan')) {
    /** Register every check_role() found in legacy/. Returns [found, new]. */
    function ims_perm_scan(mysqli $conn): array
    {
        if (!ims_perm_ensure_tables($conn) || !function_exists('token_get_all')) {
            return [0, 0];
        }

        $base = dirname(__DIR__);
        $before = count(ims_perm_registry($conn, true));
        $found = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file): bool {
                    if ($file->isDir()) {
                        return !in_array($file->getFilename(), ['vendor', 'uploads', 'exports', 'cgi-bin', 'database', 'node_modules'], true);
                    }
                    return strtolower($file->getExtension()) === 'php';
                }
            )
        );

        foreach ($iterator as $file) {
            $code = @file_get_contents($file->getPathname());
            if ($code === false || stripos($code, 'check_role') === false) {
                continue;
            }

            $relFile = ims_perm_relative_file($file->getPathname());
            if ($relFile === 'includes/auth') {
                continue; // check_*_roles() shortcuts: registered under the calling page at runtime
            }

            $tokens = token_get_all($code);
            $count = count($tokens);

            // File-level role lists: const KPI_REVIEWER_ROLES = [...];
            $localConsts = [];
            for ($i = 0; $i < $count; $i++) {
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_CONST) {
                    $n = $i + 1;
                    while ($n < $count && is_array($tokens[$n]) && $tokens[$n][0] === T_WHITESPACE) {
                        $n++;
                    }
                    $name = is_array($tokens[$n] ?? null) ? $tokens[$n][1] : '';
                    while ($n < $count && $tokens[$n] !== '=') {
                        $n++;
                    }
                    $value = $name !== '' ? ims_perm_parse_argument($tokens, $n + 1) : null;
                    if ($value) {
                        $localConsts[$name] = $value;
                    }
                }
            }

            for ($i = 0; $i < $count; $i++) {
                $tok = $tokens[$i];
                if (!is_array($tok) || $tok[0] !== T_STRING || strcasecmp($tok[1], 'check_role') !== 0) {
                    continue;
                }

                // Skip the function definitions themselves.
                $p = $i - 1;
                while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    $p--;
                }
                if ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    continue;
                }

                $n = $i + 1;
                while ($n < $count && is_array($tokens[$n]) && $tokens[$n][0] === T_WHITESPACE) {
                    $n++;
                }
                if (($tokens[$n] ?? null) !== '(') {
                    continue;
                }

                $roles = ims_perm_parse_argument($tokens, $n + 1, $localConsts);
                if ($roles === null || !$roles) {
                    continue;
                }

                ims_perm_register($conn, $relFile, $roles);
                $found++;
            }
        }

        $after = count(ims_perm_registry($conn, true));

        return [$found, $after - $before];
    }
}

/* ---------------------------------------------------------------------
   Keeping grants in step with added roles (called from user permissions)
   --------------------------------------------------------------------- */

if (!function_exists('ims_perm_copy_role')) {
    /** Give $to the same grants as $from (replacing $to's grants). */
    function ims_perm_copy_role(mysqli $conn, string $from, string $to): void
    {
        if (!ims_perm_ensure_tables($conn)) {
            return;
        }

        $stmt = $conn->prepare('DELETE FROM ims_role_permissions WHERE role_name = ?');
        $stmt->bind_param('s', $to);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('INSERT IGNORE INTO ims_role_permissions (role_name, perm_key) SELECT ?, perm_key FROM ims_role_permissions WHERE role_name = ?');
        $stmt->bind_param('ss', $to, $from);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ims_perm_rename_role')) {
    function ims_perm_rename_role(mysqli $conn, string $from, string $to): void
    {
        if (!ims_perm_ensure_tables($conn)) {
            return;
        }

        $stmt = $conn->prepare('UPDATE ims_role_permissions SET role_name = ? WHERE role_name = ?');
        $stmt->bind_param('ss', $to, $from);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ims_perm_delete_role')) {
    function ims_perm_delete_role(mysqli $conn, string $role): void
    {
        if (!ims_perm_ensure_tables($conn)) {
            return;
        }

        $stmt = $conn->prepare('DELETE FROM ims_role_permissions WHERE role_name = ?');
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $stmt->close();
    }
}

/** Replace a role's grants with $keys (only registered keys are kept). */
if (!function_exists('ims_perm_set_role_grants')) {
    function ims_perm_set_role_grants(mysqli $conn, string $role, array $keys): int
    {
        $registry = ims_perm_registry($conn, true);
        $keys = array_values(array_filter(array_unique(array_map('strval', $keys)), static fn($k) => isset($registry[$k])));

        // Administrators always keep the permission screens.
        if ($role === 'Administrator') {
            foreach ($registry as $key => $row) {
                if (in_array($row['source_file'], IMS_PERM_PROTECTED_FILES, true)) {
                    $keys[] = $key;
                }
            }
            $keys = array_values(array_unique($keys));
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM ims_role_permissions WHERE role_name = ?');
            $stmt->bind_param('s', $role);
            $stmt->execute();
            $stmt->close();

            ims_perm_grant_many_for_role($conn, $role, $keys);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        ims_perm_role_grants($conn, $role, true);

        return count($keys);
    }
}

if (!function_exists('ims_perm_grant_many_for_role')) {
    function ims_perm_grant_many_for_role(mysqli $conn, string $role, array $keys): void
    {
        $stmt = $conn->prepare('INSERT IGNORE INTO ims_role_permissions (role_name, perm_key) VALUES (?, ?)');
        foreach ($keys as $key) {
            $key = (string)$key;
            $stmt->bind_param('ss', $role, $key);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/** Grants a role would have from the code defaults (for "Reset to code defaults"). */
if (!function_exists('ims_perm_default_keys_for_role')) {
    function ims_perm_default_keys_for_role(mysqli $conn, string $role): array
    {
        $custom = ims_custom_roles($conn);
        $matchRole = isset($custom[$role]) ? (string)$custom[$role]['base_role'] : $role;
        $lower = mb_strtolower($matchRole, 'UTF-8');

        $keys = [];
        foreach (ims_perm_registry($conn) as $key => $row) {
            foreach ($row['default_roles'] as $codeRole) {
                if (mb_strtolower((string)$codeRole, 'UTF-8') === $lower) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return $keys;
    }
}
