-- The Programs pages offer Pending/Cancelled (and report on them), and the
-- survey form offers Feedback/Assessment, but the columns did not allow those
-- values: saves failed in strict mode or stored ''. Widen the enums and keep
-- every existing value. Safe to re-run.

ALTER TABLE programs
    MODIFY status ENUM('Planned','Pending','Ongoing','Completed','Suspended','Cancelled')
    DEFAULT 'Planned';

ALTER TABLE surveys
    MODIFY survey_type ENUM('Baseline','Midline','Endline','Satisfaction','Follow-up','Feedback','Assessment','Other')
    NOT NULL;
