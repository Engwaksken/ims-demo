-- ============================================================================
-- IMS: hub member subscription reminders (/hub-team?view=settings > Reminders)
-- ============================================================================
-- Automatic reminders (cron/hub-member-reminders.php) before / on / after a
-- member's subscription end date, plus manual reminders from Operations and
-- Administrators. In-app (member_notifications) and email.
-- legacy/includes/hub-reminders.php also creates these tables on first use.
-- Idempotent: safe to run more than once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS hub_reminder_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    days_before VARCHAR(60) NOT NULL DEFAULT '14,7,3,1',
    on_expiry TINYINT(1) NOT NULL DEFAULT 1,
    overdue_every_days INT NOT NULL DEFAULT 7,
    overdue_max INT NOT NULL DEFAULT 3,
    send_email TINYINT(1) NOT NULL DEFAULT 1,
    include_team TINYINT(1) NOT NULL DEFAULT 0,
    subject VARCHAR(190) NOT NULL DEFAULT 'Your hub subscription {status_text}',
    message TEXT NULL,
    updated_by INT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hub_reminder_settings (id, message) VALUES (1,
    'Hello {name},\n\nYour {plan} subscription ({membership_number}) {status_text} ({end_date}).\nPlease renew to keep your workspace and internet access for {company}.\n\nThank you,\n{org}');

CREATE TABLE IF NOT EXISTS hub_reminder_log (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT NOT NULL,
    reminder_key VARCHAR(80) NOT NULL,
    kind ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    subject VARCHAR(190) NULL,
    recipients INT NOT NULL DEFAULT 0,
    emailed INT NOT NULL DEFAULT 0,
    error VARCHAR(255) NULL,
    sent_by INT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    UNIQUE KEY uq_hub_reminder_key (member_id, reminder_key),
    KEY idx_hub_reminder_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
