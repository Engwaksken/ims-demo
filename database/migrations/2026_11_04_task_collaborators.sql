-- Staff invited to work on a task alongside its assignee.
-- An invite on a repeating task's rule row applies to every day of the series.
CREATE TABLE IF NOT EXISTS employee_task_collaborators (
    task_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    invited_by INT NULL,
    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, user_id),
    KEY idx_task_collaborators_user (user_id),
    CONSTRAINT fk_task_collaborators_task FOREIGN KEY (task_id) REFERENCES employee_tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT fk_task_collaborators_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
