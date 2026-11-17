-- Event outreach: invitations to startups, members, participants, past
-- attendees and typed-in emails, plus attendance appreciation emails.
-- legacy/includes/event-outreach.php creates these on first use; run this
-- when the database user cannot CREATE/ALTER.

CREATE TABLE IF NOT EXISTS hub_event_invites (
    invite_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    audience VARCHAR(30) NOT NULL,
    source_id INT NULL,
    full_name VARCHAR(255) NULL,
    email VARCHAR(255) NOT NULL,
    organization VARCHAR(255) NULL,
    invited_by INT NULL,
    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME NULL,
    send_count INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_hub_event_invites (event_id, email),
    KEY idx_hub_event_invites_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE event_registrations ADD COLUMN appreciation_sent_at DATETIME NULL;
