-- Self-service password reset (forgot-password / reset-password).
-- Only a SHA-256 hash of each emailed token is stored; links are single use
-- and expire after 60 minutes. includes/password-reset.php also creates this
-- table on first use, so running this file is optional but recommended.

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    requested_ip VARCHAR(45) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
