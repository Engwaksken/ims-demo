-- ============================================================================
-- IMS: HR module (recruitment pipeline, interviews, policies, reminders)
-- ============================================================================
-- Pages: /recruitment, /interview-scorecard, /hr-operations, /my-hr
-- Cron:  legacy/cron/hr-reminders.php (daily)
-- legacy/includes/hr.php also creates these tables on first use, and
-- legacy/includes/menu-defaults.php adds the sidebar entries.
-- Idempotent: safe to run more than once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS hr_job_criteria (
    criterion_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT NOT NULL,
    criterion VARCHAR(255) NOT NULL,
    details TEXT NULL,
    weight TINYINT UNSIGNED NOT NULL DEFAULT 3,
    is_must_have TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (criterion_id),
    KEY idx_hr_crit_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_job_questions (
    question_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT NOT NULL,
    question TEXT NOT NULL,
    guidance TEXT NULL,
    max_score TINYINT UNSIGNED NOT NULL DEFAULT 5,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (question_id),
    KEY idx_hr_q_job (job_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pipeline state per submitted job application (job_applications.status is left untouched).
CREATE TABLE IF NOT EXISTS hr_candidates (
    application_id INT NOT NULL,
    job_id INT NOT NULL,
    stage VARCHAR(20) NOT NULL DEFAULT 'New',
    ai_score DECIMAL(5,1) NULL,
    ai_fit VARCHAR(20) NULL,
    ai_summary TEXT NULL,
    ai_strengths TEXT NULL,
    ai_gaps TEXT NULL,
    ai_red_flags TEXT NULL,
    ai_questions TEXT NULL,
    ai_criteria MEDIUMTEXT NULL,
    must_have_missing TINYINT(1) NOT NULL DEFAULT 0,
    ai_model VARCHAR(120) NULL,
    ai_screened_at DATETIME NULL,
    ai_error VARCHAR(500) NULL,
    hr_notes TEXT NULL,
    stage_changed_at DATETIME NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (application_id),
    KEY idx_hr_cand_job (job_id, stage),
    KEY idx_hr_cand_score (job_id, ai_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_interviews (
    interview_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id INT NOT NULL,
    job_id INT NOT NULL,
    scheduled_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    mode VARCHAR(20) NOT NULL DEFAULT 'In person',
    location VARCHAR(255) NULL,
    meeting_link VARCHAR(500) NULL,
    candidate_message TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Scheduled',
    candidate_notified_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (interview_id),
    KEY idx_hr_int_job (job_id, status),
    KEY idx_hr_int_app (application_id),
    KEY idx_hr_int_when (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_interview_panel (
    interview_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    verdict VARCHAR(30) NULL,
    overall_comment TEXT NULL,
    submitted_at DATETIME NULL,
    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (interview_id, user_id),
    KEY idx_hr_panel_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_interview_scores (
    interview_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    score TINYINT UNSIGNED NULL,
    comment TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (interview_id, question_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_policies (
    policy_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'Other',
    description TEXT NULL,
    file_path VARCHAR(500) NULL,
    original_name VARCHAR(255) NULL,
    version INT NOT NULL DEFAULT 1,
    requires_ack TINYINT(1) NOT NULL DEFAULT 1,
    share_with_new_staff TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (policy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_policy_shares (
    policy_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    shared_by INT NULL,
    shared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    viewed_at DATETIME NULL,
    acknowledged_at DATETIME NULL,
    acknowledged_version INT NULL,
    PRIMARY KEY (policy_id, user_id),
    KEY idx_hr_share_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_settings (
    id TINYINT UNSIGNED NOT NULL,
    auto_reminders TINYINT(1) NOT NULL DEFAULT 0,
    remind_every_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    send_email TINYINT(1) NOT NULL DEFAULT 1,
    required_docs VARCHAR(500) NOT NULL DEFAULT 'cv_path,national_id_path,academic_documents_path,signed_contract_path,signed_policies_path',
    autoshare_new_staff TINYINT(1) NOT NULL DEFAULT 1,
    new_staff_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    updated_by INT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hr_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS hr_reminder_log (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    kind VARCHAR(20) NOT NULL,
    is_auto TINYINT(1) NOT NULL DEFAULT 0,
    summary VARCHAR(500) NULL,
    emailed TINYINT(1) NOT NULL DEFAULT 0,
    sent_by INT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_hr_rem_user (user_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sidebar: Recruitment + HR Operations for HR and Administrators, My HR for all staff.
INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'hr-operations', 'fa-people-roof', 'HR Operations', 'People', 30, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE page IN ('hr-operations', 'hr-operations.php'));

INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'recruitment', 'fa-user-check', 'Recruitment', 'People', 31, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE page IN ('recruitment', 'recruitment.php'));

INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'my-hr', 'fa-id-card-clip', 'My HR', 'People', 32, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE page IN ('my-hr', 'my-hr.php'));

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, r.role
FROM menu_items mi
CROSS JOIN (SELECT 'Administrator' AS role UNION ALL SELECT 'HR') r
WHERE mi.page IN ('hr-operations', 'recruitment');
