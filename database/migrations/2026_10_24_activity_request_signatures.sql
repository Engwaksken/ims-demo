-- Preserve submitter and reviewer signatures with each activity/event request.
ALTER TABLE activity_event_requests
  ADD COLUMN IF NOT EXISTS requester_signature MEDIUMBLOB DEFAULT NULL AFTER created_by,
  ADD COLUMN IF NOT EXISTS requester_signature_mime VARCHAR(40) DEFAULT NULL AFTER requester_signature;

ALTER TABLE activity_event_request_reviews
  ADD COLUMN IF NOT EXISTS signature_data MEDIUMBLOB DEFAULT NULL AFTER reviewed_by,
  ADD COLUMN IF NOT EXISTS signature_mime VARCHAR(40) DEFAULT NULL AFTER signature_data;
