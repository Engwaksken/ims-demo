-- Staff invited to hub events. They receive a calendar invite via Google
-- Calendar (as attendees) or by email (.ics) when Google is not connected.
-- legacy/includes/event-staff.php also creates this table on first use.
CREATE TABLE IF NOT EXISTS hub_event_staff (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    invited_by INT NULL,
    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id),
    KEY idx_hub_event_staff_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
