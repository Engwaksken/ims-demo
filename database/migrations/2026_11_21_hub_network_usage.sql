-- Data used per device per day, reported by the pfSense agent 2.7+ at every
-- check-in (bytes since the previous check-in, added to today's row).
-- hubnet_ensure_schema() creates this table automatically; this file is for
-- running by hand. Rows older than 90 days are removed by the IMS.

CREATE TABLE IF NOT EXISTS hub_network_usage (
    mac_address CHAR(17) NOT NULL,
    day DATE NOT NULL,
    bytes_up BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_down BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (mac_address, day),
    KEY idx_hub_usage_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
