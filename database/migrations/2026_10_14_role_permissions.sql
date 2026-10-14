-- Database-driven page access (legacy/includes/permissions.php).
-- Each check_role([...]) call in the code is a permission; the roles listed
-- in the code are its defaults. The Role Permissions page lets
-- administrators change which roles hold each permission.
-- permissions.php also creates these tables on first use, and the Role
-- Permissions page scans the code to register every page on first visit.

CREATE TABLE IF NOT EXISTS ims_permissions (
    perm_key VARCHAR(190) NOT NULL,
    source_file VARCHAR(255) NOT NULL,
    default_roles TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (perm_key),
    KEY idx_ims_permissions_file (source_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ims_role_permissions (
    role_name VARCHAR(100) NOT NULL,
    perm_key VARCHAR(190) NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_name, perm_key),
    KEY idx_ims_role_permissions_key (perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sidebar link for administrators, in the same section as User Permissions.
INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'role-permissions', 'fa-shield-halved', 'Role Permissions',
       COALESCE((SELECT category FROM menu_items WHERE page IN ('user-permissions', 'user-permissions.php') LIMIT 1), 'Administration'),
       COALESCE((SELECT sort_order FROM menu_items WHERE page IN ('user-permissions', 'user-permissions.php') LIMIT 1), 90) + 1,
       1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items WHERE page IN ('role-permissions', 'role-permissions.php')
);

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT menu_id, 'Administrator'
FROM menu_items
WHERE page = 'role-permissions';
