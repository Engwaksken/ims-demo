-- ============================================================================
-- IMS: IT support requests (/it-support)
-- ============================================================================
-- Staff raise tickets for the IT department; IT Officers (and
-- Administrators) are notified and work the queue. See
-- legacy/includes/it-support.php, which also creates these tables on first use.
-- Attachments: legacy/uploads/it-support/{ticket_id}/ (served by
-- download-file.php to IT staff and the requester only).
-- Idempotent: safe to run more than once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS it_support_tickets (
    ticket_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_id INT NOT NULL,
    category VARCHAR(60) NOT NULL,
    priority ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(150) NULL,
    device VARCHAR(150) NULL,
    status ENUM('Open','In Progress','On Hold','Resolved','Closed') NOT NULL DEFAULT 'Open',
    assigned_to INT NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_name VARCHAR(255) NULL,
    first_response_at DATETIME NULL,
    resolved_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_id),
    KEY idx_its_requester (requester_id, status),
    KEY idx_its_assignee (assigned_to, status),
    KEY idx_its_status (status, priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS it_support_comments (
    comment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    kind ENUM('comment','note','event') NOT NULL DEFAULT 'comment',
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_its_comment_ticket (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sidebar entry for every staff role (staff see only their own requests).
INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'it-support', 'fa-screwdriver-wrench', 'IT Support', 'Operations', 17, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items WHERE page IN ('it-support', 'it-support.php')
);

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, r.role
FROM menu_items mi
CROSS JOIN (
    SELECT 'Administrator' AS role
    UNION ALL SELECT 'Programs Lead'
    UNION ALL SELECT 'MEAL Lead'
    UNION ALL SELECT 'Operations/Admin'
    UNION ALL SELECT 'Project Officer'
    UNION ALL SELECT 'Staff'
    UNION ALL SELECT 'Reviewer'
    UNION ALL SELECT 'Accountant'
    UNION ALL SELECT 'Finance'
    UNION ALL SELECT 'Program Director'
    UNION ALL SELECT 'Consultant'
    UNION ALL SELECT 'Executive Director'
    UNION ALL SELECT 'IT Officer'
    UNION ALL SELECT 'HR'
    UNION ALL SELECT 'Program Manager'
    UNION ALL SELECT 'Procurement Officer'
    UNION ALL SELECT 'Program Officer'
) AS r
WHERE mi.page IN ('it-support', 'it-support.php');
