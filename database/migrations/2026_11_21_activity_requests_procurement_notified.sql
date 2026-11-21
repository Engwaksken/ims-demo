-- Activity/event requests: Procurement no longer reviews.
-- Flow is now Supervisor Review -> Finance Review -> Director Approval -> Approved.
-- When the Director signs and approves, every Procurement Officer gets an in-app
-- notification and an email with the approved concept note PDF attached.
--
-- Requests still waiting at the old Procurement Review stage move on to Finance.
-- (activity-event-requests.php also does this on page load, so running this is optional.)
-- The 'Procurement Review' / 'Procurement' ENUM values are kept so older review
-- history stays valid.

UPDATE activity_event_requests
SET status = 'Finance Review'
WHERE status = 'Procurement Review';
