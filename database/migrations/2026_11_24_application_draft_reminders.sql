-- ============================================================================
-- IMS: reminders to finish draft opportunity applications
-- ============================================================================
-- Automatic reminders (cron/application-draft-reminders.php) N days before an
-- opportunity's deadline, plus manual reminders from Manage Applications.
-- legacy/includes/application-reminders.php also creates these tables on
-- first use. Idempotent: safe to run more than once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS application_reminder_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    days_before VARCHAR(60) NOT NULL DEFAULT '7,3,1',
    updated_by INT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_reminder_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS application_reminder_log (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id INT NOT NULL,
    opportunity_id INT NOT NULL,
    reminder_key VARCHAR(80) NOT NULL,
    kind ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    email VARCHAR(190) NULL,
    emailed TINYINT(1) NOT NULL DEFAULT 0,
    error VARCHAR(255) NULL,
    sent_by INT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    UNIQUE KEY uq_app_reminder_key (application_id, reminder_key),
    KEY idx_app_reminder_opp (opportunity_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
