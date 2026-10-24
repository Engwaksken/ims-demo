-- Audit reminder notifications sent for activity/event request approval stages.
CREATE TABLE IF NOT EXISTS activity_event_request_reminders (
  reminder_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,
  stage VARCHAR(40) NOT NULL,
  sent_by INT NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (reminder_id),
  KEY idx_aer_reminder_cooldown (request_id, stage, sent_at),
  KEY idx_aer_reminder_actor (sent_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
