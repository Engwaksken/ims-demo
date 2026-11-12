-- ============================================================================
-- IMS: event attendance reminders and self-confirmation
-- ============================================================================
-- Registrants get reminders 30, 20, 10 and 0 minutes before each event day
-- starts, asking them to confirm attendance (event-confirm.php). See
-- legacy/includes/event-reminders.php, which also adds these columns lazily.
-- Run once; MySQL has no ADD COLUMN IF NOT EXISTS, so skip a line whose
-- column already exists.
-- ============================================================================

ALTER TABLE event_registrations
    ADD COLUMN confirmation_response VARCHAR(20) NULL,
    ADD COLUMN confirmed_at DATETIME NULL,
    ADD COLUMN reminder_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_reminder_at DATETIME NULL;
