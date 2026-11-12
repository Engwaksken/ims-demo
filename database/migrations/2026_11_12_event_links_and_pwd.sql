-- Events: meeting links for meeting-type events, and PWD / photo-consent
-- details captured on the public event registration form.
-- The code also adds these columns lazily (includes/event-links.php).
-- Run once; MySQL has no ADD COLUMN IF NOT EXISTS, so skip any line whose
-- column already exists (hub_events.meeting_link exists on sites that ran
-- the Google Calendar upgrade).

ALTER TABLE hub_events ADD COLUMN meeting_link VARCHAR(500) NULL;

ALTER TABLE event_registrations
    ADD COLUMN is_pwd TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN pwd_type VARCHAR(100) NULL,
    ADD COLUMN photo_consent TINYINT(1) NOT NULL DEFAULT 0;
