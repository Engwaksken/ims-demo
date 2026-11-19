-- ============================================================================
-- IMS: hub team members, workspace packages and pfSense internet control
-- ============================================================================
-- /hub-operations: a primary member (company / organisation) gets a
-- workspace package with a seat limit. Authorised staff register the
-- company's team members (/hub-team) up to that limit. Each person's devices
-- (MAC addresses) are pushed to the self-hosted pfSense captive portal so
-- they can be connected / disconnected from the dashboard, or automatically
-- when the membership expires or is suspended.
-- legacy/includes/hub-network.php also creates these tables on first use.
-- Idempotent (MariaDB): safe to run more than once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS hub_space_packages (
    package_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    max_seats INT UNSIGNED NOT NULL DEFAULT 1,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (package_id),
    UNIQUE KEY uq_hub_package_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hub_space_packages (name, max_seats, description, sort_order) VALUES
    ('Startup subscription', 1, 'One person', 10),
    ('Dedicated table for 2', 2, 'Dedicated table, up to 2 people', 20),
    ('Dedicated table for 4', 4, 'Dedicated table, up to 4 people', 30),
    ('Dedicated table for 6', 6, 'Dedicated table, up to 6 people', 40),
    ('Private office', 10, 'Private office (set the maximum to the office capacity)', 50);

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS space_package_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS hub_team_members (
    team_member_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    job_title VARCHAR(120) NULL,
    status ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (team_member_id),
    KEY idx_hub_team_member (member_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- team_member_id NULL = the primary member's own device.
CREATE TABLE IF NOT EXISTS hub_network_devices (
    device_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT NOT NULL,
    team_member_id INT UNSIGNED NULL,
    mac_address CHAR(17) NOT NULL,
    label VARCHAR(80) NULL,
    access_mode ENUM('auto','allow','block') NOT NULL DEFAULT 'auto',
    pf_state ENUM('unknown','connected','disconnected','blocked','error') NOT NULL DEFAULT 'unknown',
    pf_synced_at DATETIME NULL,
    pf_last_error VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (device_id),
    UNIQUE KEY uq_hub_device_mac (mac_address),
    KEY idx_hub_device_owner (member_id, team_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hub_network_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    agent_url VARCHAR(255) NULL,
    agent_secret TEXT NULL,
    cp_zone VARCHAR(60) NOT NULL DEFAULT 'lan',
    auto_enforce TINYINT(1) NOT NULL DEFAULT 1,
    verify_tls TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hub_network_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS hub_network_log (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id INT UNSIGNED NULL,
    mac_address CHAR(17) NULL,
    action VARCHAR(30) NOT NULL,
    ok TINYINT(1) NOT NULL DEFAULT 0,
    message VARCHAR(255) NULL,
    user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_hub_netlog_device (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sidebar entry: Hub Team & Internet (Operations).
INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'hub-team', 'fa-wifi', 'Hub Team & Internet', 'Operations', 18, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items WHERE page IN ('hub-team', 'hub-team.php')
);

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, r.role
FROM menu_items mi
CROSS JOIN (
    SELECT 'Administrator' AS role
    UNION ALL SELECT 'Operations/Admin'
    UNION ALL SELECT 'IT Officer'
) AS r
WHERE mi.page IN ('hub-team', 'hub-team.php');
