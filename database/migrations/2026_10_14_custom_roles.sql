-- Roles added by administrators on the User Permissions page. Each one works
-- like a built-in role (base_role): at login the user gets the base role's
-- access, while users.role keeps the added role's name.
-- legacy/includes/roles.php also creates this table on first use.
CREATE TABLE IF NOT EXISTS ims_roles (
    role_name VARCHAR(100) NOT NULL,
    base_role VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If users.role is an ENUM, the page adds each new role to it automatically.
-- To avoid ALTERs at runtime you can switch it to VARCHAR once instead:
-- ALTER TABLE users MODIFY role VARCHAR(100) NOT NULL;
