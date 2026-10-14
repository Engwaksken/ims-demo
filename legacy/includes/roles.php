<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ROLES (built-in + admin-added)
|--------------------------------------------------------------------------
| Built-in roles are the IMS_ALL_ROLES constant in auth.php; every page's
| access check is written against those names. Administrators can add more
| roles on the User Permissions page. Each added role "works like" one
| built-in role (its base role):
|   - users.role stores the added role's name (e.g. "Communications Officer"),
|   - at login $_SESSION['role'] is set to the base role, so every existing
|     check_role()/in_array() check and the sidebar menu just work,
|   - $_SESSION['role_name'] keeps the real name for display.
*/

require_once __DIR__ . '/auth.php';

/** Short descriptions for the built-in roles (shown on User Permissions). */
const IMS_ROLE_DESCRIPTIONS = [
    'Administrator'       => 'Full access: system setup, users, roles and permissions, all modules, logs and backups.',
    'Programs Lead'       => 'Monitors all programs, manages milestones, approves activities and generates reports.',
    'MEAL Lead'           => 'Data quality, indicator tracking, cross-project analysis and data export.',
    'Operations/Admin'    => 'Hub operations: events, space use, visitors, memberships and facility reports.',
    'Project Officer'     => 'Data entry for assigned projects, participants, activities, milestones and documents.',
    'Donor/Partner'       => 'Read-only dashboards, progress data and reports. No edit or delete.',
    'Staff'               => 'General staff access: tasks, events, documents and programme pages.',
    'Member'              => 'Hub member portal: bookings, subscriptions and member services.',
    'Applicant'           => 'Applicant portal: applications and application status.',
    'Reviewer'            => 'Reviews and scores assigned applications.',
    'Accountant'          => 'Finance records, vouchers and payments.',
    'Finance'             => 'Finance management, budgets, vouchers and procurement approvals.',
    'Program Director'    => 'Oversees all programs, milestones, approvals and reports.',
    'Consultant'          => 'Programme work and application reviews as assigned.',
    'Executive Director'  => 'Organisation-wide oversight and administration.',
    'IT Officer'          => 'System administration and technical support.',
    'HR'                  => 'Employees, appraisals, competencies and HR reports.',
    'Program Manager'     => 'Manages programs, projects, milestones and reviews.',
    'Procurement Officer' => 'Procurement requests, RFQs, quotations and purchase orders.',
    'Program Officer'     => 'Programme delivery, activities, participants and reviews.',
];

if (!function_exists('ims_roles_ensure_table')) {
    function ims_roles_ensure_table(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            $ready = (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS ims_roles (
                    role_name VARCHAR(100) NOT NULL,
                    base_role VARCHAR(100) NOT NULL,
                    description VARCHAR(500) NULL,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (role_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('ims_roles table unavailable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

/** Admin-added roles: [role_name => ['role_name', 'base_role', 'description', ...]]. */
if (!function_exists('ims_custom_roles')) {
    function ims_custom_roles(mysqli $conn, bool $refresh = false): array
    {
        static $cache = null;

        if ($cache !== null && !$refresh) {
            return $cache;
        }

        $cache = [];

        if (!ims_roles_ensure_table($conn)) {
            return $cache;
        }

        $result = $conn->query('SELECT role_name, base_role, description, created_at FROM ims_roles ORDER BY role_name');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Ignore rows whose base role is no longer a built-in role.
                if (in_array($row['base_role'], IMS_ALL_ROLES, true)) {
                    $cache[$row['role_name']] = $row;
                }
            }
            $result->close();
        }

        return $cache;
    }
}

/** Every assignable role name: built-in first, then added roles. */
if (!function_exists('ims_all_roles')) {
    function ims_all_roles(mysqli $conn): array
    {
        return array_values(array_unique(array_merge(IMS_ALL_ROLES, array_keys(ims_custom_roles($conn)))));
    }
}

/** The built-in role used for access checks ("Communications Officer" -> "Staff"). */
if (!function_exists('ims_effective_role')) {
    function ims_effective_role(mysqli $conn, string $role): string
    {
        if (in_array($role, IMS_ALL_ROLES, true)) {
            return $role;
        }

        $custom = ims_custom_roles($conn);

        return isset($custom[$role]) ? (string)$custom[$role]['base_role'] : $role;
    }
}

/** Put a user's role into the session (call wherever login sets the role). */
if (!function_exists('ims_set_session_role')) {
    function ims_set_session_role(mysqli $conn, string $role): void
    {
        $_SESSION['role'] = ims_effective_role($conn, $role);
        $_SESSION['role_name'] = $role;
    }
}

/** Role name to show in the UI for the logged-in user. */
if (!function_exists('ims_display_role')) {
    function ims_display_role(): string
    {
        return (string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');
    }
}

if (!function_exists('ims_role_description')) {
    function ims_role_description(mysqli $conn, string $role): string
    {
        if (isset(IMS_ROLE_DESCRIPTIONS[$role])) {
            return IMS_ROLE_DESCRIPTIONS[$role];
        }

        $custom = ims_custom_roles($conn);

        return (string)($custom[$role]['description'] ?? '');
    }
}

/** Number of users per role name. */
if (!function_exists('ims_role_user_counts')) {
    function ims_role_user_counts(mysqli $conn): array
    {
        $counts = [];
        $result = $conn->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $counts[(string)$row['role']] = (int)$row['total'];
            }
            $result->close();
        }

        return $counts;
    }
}

/**
 * If users.role is an ENUM, add $role to its allowed values so the new role
 * can be saved. VARCHAR columns need nothing.
 */
if (!function_exists('ims_roles_allow_in_enum')) {
    function ims_roles_allow_in_enum(mysqli $conn, string $role, string $table = 'users'): bool
    {
        if (!in_array($table, ['users', 'menu_role_permissions'], true)) {
            return false;
        }

        $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'role'");
        $column = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->close();
        }

        if (!$column || stripos((string)$column['Type'], 'enum(') !== 0) {
            return true;
        }

        // SHOW COLUMNS lists values as 'a','b','O''Brien' (quotes doubled).
        preg_match_all('/\'((?:[^\']|\'\')*)\'/', (string)$column['Type'], $m);
        $values = array_map(static fn($v) => str_replace("''", "'", $v), $m[1]);

        if (in_array($role, $values, true)) {
            return true;
        }

        $values[] = $role;
        $list = implode(',', array_map(static fn($v) => "'" . $conn->real_escape_string($v) . "'", $values));
        $null = strtoupper((string)$column['Null']) === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $column['Default'] !== null ? " DEFAULT '" . $conn->real_escape_string((string)$column['Default']) . "'" : '';

        try {
            return (bool)$conn->query("ALTER TABLE `$table` MODIFY role ENUM($list) $null$default");
        } catch (Throwable $e) {
            error_log("Could not add role to $table.role enum: " . $e->getMessage());
            return false;
        }
    }
}

/** Normalise a role name typed by an admin. */
if (!function_exists('ims_clean_role_name')) {
    function ims_clean_role_name(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }
}

/**
 * Validate a role name. Returns an error message or '' if valid.
 * $ignore is the current name when renaming.
 */
if (!function_exists('ims_validate_role_name')) {
    function ims_validate_role_name(mysqli $conn, string $name, string $ignore = ''): string
    {
        $len = mb_strlen($name, 'UTF-8');

        if ($len < 2 || $len > 60) {
            return 'Role name must be between 2 and 60 characters.';
        }

        if (!preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} &\\/().,'-]*$/u", $name)) {
            return 'Role name can only use letters, numbers, spaces and & / ( ) . , \' -';
        }

        // All-digit names would become integer array keys and break role lookups.
        if (ctype_digit(str_replace(' ', '', $name))) {
            return 'Role name must include at least one letter.';
        }

        foreach (ims_all_roles($conn) as $existing) {
            if ($existing !== $ignore && mb_strtolower($existing, 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                return 'A role called "' . $existing . '" already exists.';
            }
        }

        return '';
    }
}

/* ---------------------------------------------------------------------
   Sidebar menus (menu_role_permissions) for added roles
   --------------------------------------------------------------------- */

/** Give $to the same sidebar menu items as $from (added on top of any it has). */
if (!function_exists('ims_menu_copy_role')) {
    function ims_menu_copy_role(mysqli $conn, string $from, string $to): bool
    {
        if (!ims_roles_allow_in_enum($conn, $to, 'menu_role_permissions')) {
            return false;
        }

        try {
            $stmt = $conn->prepare('INSERT IGNORE INTO menu_role_permissions (menu_id, role) SELECT menu_id, ? FROM menu_role_permissions WHERE role = ?');
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $to, $from);
            $ok = $stmt->execute();
            $stmt->close();

            return $ok;
        } catch (Throwable $e) {
            error_log('Could not copy menu permissions: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ims_menu_rename_role')) {
    function ims_menu_rename_role(mysqli $conn, string $from, string $to): void
    {
        try {
            ims_roles_allow_in_enum($conn, $to, 'menu_role_permissions');
            $stmt = $conn->prepare('UPDATE IGNORE menu_role_permissions SET role = ? WHERE role = ?');
            if ($stmt) {
                $stmt->bind_param('ss', $to, $from);
                $stmt->execute();
                $stmt->close();
            }
            ims_menu_delete_role($conn, $from); // rows that clashed with existing ones
        } catch (Throwable $e) {
            error_log('Could not rename menu permissions: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ims_menu_delete_role')) {
    function ims_menu_delete_role(mysqli $conn, string $role): void
    {
        try {
            $stmt = $conn->prepare('DELETE FROM menu_role_permissions WHERE role = ?');
            if ($stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Could not delete menu permissions: ' . $e->getMessage());
        }
    }
}

/**
 * Role whose sidebar menu the current user sees: an added role's own menu
 * once it has one, otherwise the built-in role it works like.
 */
if (!function_exists('ims_menu_role')) {
    function ims_menu_role(mysqli $conn): string
    {
        $sessionRole = trim((string)($_SESSION['role'] ?? ''));
        $roleName = trim((string)($_SESSION['role_name'] ?? ''));

        if ($roleName === '' || $roleName === $sessionRole || ims_effective_role($conn, $roleName) !== $sessionRole) {
            return $sessionRole;
        }

        try {
            $stmt = $conn->prepare('SELECT 1 FROM menu_role_permissions WHERE role = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $roleName);
                $stmt->execute();
                $has = (bool)$stmt->get_result()->fetch_row();
                $stmt->close();
                if ($has) {
                    return $roleName;
                }
            }
        } catch (Throwable $e) {
            // fall back to the built-in role's menu
        }

        return $sessionRole;
    }
}
