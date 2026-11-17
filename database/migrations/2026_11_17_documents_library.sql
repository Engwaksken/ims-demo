-- ============================================================================
-- IMS: SharePoint-style document library (/documents)
-- ============================================================================
-- Recycle bin (soft delete), favourites, version history, check-out /
-- check-in and an activity log for the documents module.
--
-- legacy/includes/documents-library.php (doc_lib_ready) also creates these
-- on first use, so this file is for environments where the web user has no
-- CREATE/ALTER privilege. Until the columns exist, deletes stay permanent
-- and the Favorites / Recycle bin views are hidden.
--
-- MariaDB syntax (ADD COLUMN IF NOT EXISTS). Idempotent: safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS document_favorites (
    user_id INT NOT NULL,
    resource_type ENUM('folder','document') NOT NULL,
    resource_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, resource_type, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_activity (
    activity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_type ENUM('folder','document') NOT NULL,
    resource_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(30) NOT NULL,
    details VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id),
    KEY idx_doc_activity_resource (resource_type, resource_id, created_at),
    KEY idx_doc_activity_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_versions (
    version_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT NOT NULL,
    version_number INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NULL,
    original_file_size INT NULL,
    original_filename VARCHAR(255) NULL,
    original_mime_type VARCHAR(150) NULL,
    original_extension VARCHAR(20) NULL,
    is_compressed TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    comment VARCHAR(255) NULL,
    PRIMARY KEY (version_id),
    KEY idx_doc_versions_document (document_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS deleted_by INT NULL,
    ADD COLUMN IF NOT EXISTS modified_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS modified_by INT NULL,
    ADD COLUMN IF NOT EXISTS version_number INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS checked_out_by INT NULL,
    ADD COLUMN IF NOT EXISTS checked_out_at DATETIME NULL;

ALTER TABLE document_folders
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS deleted_by INT NULL;
