-- Keep each submitted request linked to its selected workplan activity.
ALTER TABLE activity_event_requests
  ADD COLUMN IF NOT EXISTS deliverable_id INT DEFAULT NULL AFTER milestone_id;
