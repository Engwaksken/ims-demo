-- ============================================================================
-- IMS: hub internet for staff & guests, internet switch, guest hotspot,
-- online-device tracking (/hub-team, /hotspot, pfSense agent 2.1)
-- ============================================================================
-- legacy/includes/hub-network.php (hubnet_ensure_schema) also applies all of
-- this automatically on first use. Idempotent: safe to run more than once
-- (MySQL 8.0.29+/MariaDB 10.0+ for ADD COLUMN IF NOT EXISTS).
-- ============================================================================

-- Staff (linked to an IMS user) and guests (manual or hotspot sign-in).
CREATE TABLE IF NOT EXISTS hub_network_people (
    person_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('staff','guest') NOT NULL,
    user_id INT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    organisation VARCHAR(150) NULL,
    note VARCHAR(255) NULL,
    status ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
    valid_until DATETIME NULL,
    source ENUM('manual','hotspot') NOT NULL DEFAULT 'manual',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (person_id),
    KEY idx_hub_people_kind (kind, status),
    KEY idx_hub_people_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff/guest devices: person_id set, member_id NULL.
ALTER TABLE hub_network_devices MODIFY member_id INT NULL;
ALTER TABLE hub_network_devices ADD COLUMN IF NOT EXISTS person_id INT UNSIGNED NULL;

-- Internet switch and hotspot settings.
ALTER TABLE hub_network_settings
    ADD COLUMN IF NOT EXISTS portal_control VARCHAR(10) NOT NULL DEFAULT 'unmanaged',
    ADD COLUMN IF NOT EXISTS hotspot_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS hotspot_password TEXT NULL,
    ADD COLUMN IF NOT EXISTS hotspot_hours SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    ADD COLUMN IF NOT EXISTS hotspot_require_phone TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS hotspot_terms TEXT NULL,
    ADD COLUMN IF NOT EXISTS wifi_ssid VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS wifi_key TEXT NULL,
    ADD COLUMN IF NOT EXISTS wifi_security VARCHAR(10) NOT NULL DEFAULT 'WPA',
    ADD COLUMN IF NOT EXISTS hotspot_landing_url VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS hotspot_verify TINYINT(1) NOT NULL DEFAULT 1;

-- Devices pfSense saw online at its last check-in.
CREATE TABLE IF NOT EXISTS hub_network_online (
    mac_address CHAR(17) NOT NULL,
    ip_address VARCHAR(45) NULL,
    portal_user VARCHAR(120) NULL,
    in_portal TINYINT(1) NOT NULL DEFAULT 0,
    session_started DATETIME NULL,
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (mac_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hourly connected-device counts (kept 180 days).
CREATE TABLE IF NOT EXISTS hub_network_stats (
    hour_start DATETIME NOT NULL,
    max_online INT UNSIGNED NOT NULL DEFAULT 0,
    sum_online INT UNSIGNED NOT NULL DEFAULT 0,
    samples INT UNSIGNED NOT NULL DEFAULT 0,
    max_portal INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (hour_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
