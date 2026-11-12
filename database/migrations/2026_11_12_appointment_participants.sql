-- ============================================================================
-- IMS: appointments with more than one invitee (/appointments)
-- ============================================================================
-- Each person in an appointment (organiser + invitees) gets a row with their
-- own response and reminder. Existing one-to-one appointments are copied in
-- (requester = organiser, invitee = the one invitee). legacy/includes/
-- appointments.php also creates and backfills this table on first use.
-- staff_appointments.invitee_id and the requester_/invitee_ reminder columns
-- are kept for history but no longer read.
-- Idempotent: safe to run more than once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS staff_appointment_participants (
    appointment_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    is_organiser TINYINT(1) NOT NULL DEFAULT 0,
    response ENUM('Pending','Accepted','Declined') NOT NULL DEFAULT 'Pending',
    response_note VARCHAR(500) NULL,
    responded_at DATETIME NULL,
    remind_minutes SMALLINT UNSIGNED NULL,
    remind_at DATETIME NULL,
    reminded_at DATETIME NULL,
    PRIMARY KEY (appointment_id, user_id),
    KEY idx_appt_part_user (user_id, response),
    KEY idx_appt_part_remind (response, remind_at, reminded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO staff_appointment_participants
    (appointment_id, user_id, is_organiser, response, responded_at, remind_minutes, remind_at, reminded_at)
SELECT a.appointment_id,
       IF(s.side = 0, a.requester_id, a.invitee_id),
       IF(s.side = 0, 1, 0),
       CASE
           WHEN COALESCE(a.proposed_by, a.requester_id) = IF(s.side = 0, a.requester_id, a.invitee_id) THEN 'Accepted'
           WHEN a.status = 'Accepted' THEN 'Accepted'
           WHEN a.status = 'Declined' THEN 'Declined'
           ELSE 'Pending'
       END,
       a.responded_at,
       IF(s.side = 0, a.requester_remind_minutes, a.invitee_remind_minutes),
       IF(s.side = 0, a.requester_remind_at, a.invitee_remind_at),
       IF(s.side = 0, a.requester_reminded_at, a.invitee_reminded_at)
FROM staff_appointments a
CROSS JOIN (SELECT 0 AS side UNION ALL SELECT 1) s
WHERE NOT EXISTS (
    SELECT 1 FROM staff_appointment_participants p WHERE p.appointment_id = a.appointment_id
);
