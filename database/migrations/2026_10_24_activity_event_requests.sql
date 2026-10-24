-- Activity/event concept submissions, approval history and project/program budgets.
-- Apply after the base schema. Safe to re-run.
CREATE TABLE IF NOT EXISTS activity_event_requests (
  request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_code VARCHAR(30) NOT NULL,
  title VARCHAR(255) NOT NULL,
  concept TEXT NOT NULL,
  event_type VARCHAR(100) NOT NULL DEFAULT 'Activity',
  entity_type ENUM('Project','Program') NOT NULL,
  entity_id INT NOT NULL,
  milestone_id INT NOT NULL,
  activity_name VARCHAR(255) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  location VARCHAR(255) DEFAULT NULL,
  participants INT UNSIGNED NOT NULL DEFAULT 0,
  requested_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
  status ENUM('Supervisor Review','Procurement Review','Finance Review','Director Approval','Approved','Returned','Rejected') NOT NULL DEFAULT 'Supervisor Review',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (request_id), UNIQUE KEY uq_activity_event_ref (reference_code),
  KEY idx_activity_event_status (status), KEY idx_activity_event_entity (entity_type, entity_id),
  KEY idx_activity_event_creator (created_by), KEY idx_activity_event_milestone (milestone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_event_request_reviews (
  review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,
  stage ENUM('Supervisor','Procurement','Finance','Director') NOT NULL,
  action ENUM('Approved','Returned','Rejected') NOT NULL,
  comments TEXT DEFAULT NULL,
  reviewed_by INT NOT NULL,
  reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (review_id), KEY idx_activity_review_request (request_id), KEY idx_activity_review_actor (reviewed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_program_budget_lines (
  budget_line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type ENUM('Project','Program') NOT NULL,
  entity_id INT NOT NULL,
  activity_name VARCHAR(255) NOT NULL,
  fiscal_year SMALLINT UNSIGNED NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
  allocated_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  updated_by INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (budget_line_id), UNIQUE KEY uq_entity_budget_activity (entity_type, entity_id, activity_name, fiscal_year),
  KEY idx_budget_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'activity-event-requests', 'fa-calendar-check', 'Activity/Event Requests', 'Programs', 25, 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE page IN ('activity-event-requests','activity-event-requests.php'));
INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, r.role FROM menu_items mi CROSS JOIN (
 SELECT 'Administrator' role UNION ALL SELECT 'Operations/Admin' UNION ALL SELECT 'Staff'
 UNION ALL SELECT 'Project Officer' UNION ALL SELECT 'Program Officer' UNION ALL SELECT 'Program Manager'
 UNION ALL SELECT 'Programs Lead' UNION ALL SELECT 'Finance' UNION ALL SELECT 'Accountant'
 UNION ALL SELECT 'Procurement Officer' UNION ALL SELECT 'Executive Director' UNION ALL SELECT 'Program Director'
) r WHERE mi.page IN ('activity-event-requests','activity-event-requests.php');
