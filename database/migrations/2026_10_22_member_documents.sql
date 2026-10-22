-- Member folders (Hub Operations > Members > Folder): contracts, monthly
-- receipts and other documents per member. Files live under
-- legacy/uploads/member-files/{member_id}/ and are served by download-file.php.
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS member_documents (
    document_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id      INT NOT NULL,
    category       ENUM('Contract', 'Monthly Receipt', 'ID / KYC', 'Other') NOT NULL DEFAULT 'Other',
    title          VARCHAR(200) NOT NULL,
    period_month   DATE NULL COMMENT 'First day of the month a receipt covers',
    amount         DECIMAL(12,2) NULL,
    reference      VARCHAR(100) NULL,
    notes          TEXT NULL,
    file_path      VARCHAR(500) NOT NULL,
    original_name  VARCHAR(255) NOT NULL,
    mime_type      VARCHAR(100) NULL,
    file_size      INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by    INT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id),
    KEY idx_member_category (member_id, category),
    KEY idx_member_period (member_id, period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
