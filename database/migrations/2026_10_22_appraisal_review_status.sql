-- The appraisal workflow writes 'pending_review' (submit) and 'reviewed' (supervisor review),
-- but the original enum only allowed draft/submitted/under_review/completed/rejected.
-- Keep the old values for existing rows and add the workflow values.
ALTER TABLE performance_appraisals
    MODIFY status ENUM('draft','submitted','pending_review','under_review','reviewed','approved','completed','rejected')
    NOT NULL DEFAULT 'draft';
