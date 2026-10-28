-- ============================================================================
-- IMS: staff appointments (/appointments)
-- ============================================================================
-- A staff member requests a time slot with a colleague; accepted appointments
-- block the slot for both and send reminders; either party may propose a new
-- time (proposed_by / revision). See legacy/includes/appointments.php, which
-- also creates this table on first use.
-- Idempotent: safe to run more than once.
-- Reminders go out from legacy/cron/task-reminders.php (every five minutes).
-- ============================================================================

CREATE TABLE IF NOT EXISTS staff_appointments (
    appointment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_id INT NOT NULL,
    invitee_id INT NOT NULL,
    proposed_by INT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    title VARCHAR(200) NOT NULL,
    agenda TEXT NULL,
    location VARCHAR(255) NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    status ENUM('Pending','Accepted','Declined','Cancelled') NOT NULL DEFAULT 'Pending',
    response_note VARCHAR(500) NULL,
    responded_at DATETIME NULL,
    cancelled_by INT NULL,
    requester_remind_minutes SMALLINT UNSIGNED NULL,
    invitee_remind_minutes SMALLINT UNSIGNED NULL,
    requester_remind_at DATETIME NULL,
    invitee_remind_at DATETIME NULL,
    requester_reminded_at DATETIME NULL,
    invitee_reminded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (appointment_id),
    KEY idx_appt_requester (requester_id, status, start_at),
    KEY idx_appt_invitee (invitee_id, status, start_at),
    KEY idx_appt_status_start (status, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sidebar entry for every staff role (the page only shows the user's own appointments).
INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'appointments', 'fa-handshake', 'Appointments', 'Operations', 16, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items WHERE page IN ('appointments', 'appointments.php')
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
WHERE mi.page IN ('appointments', 'appointments.php');
