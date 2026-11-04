<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| My Tasks: invited staff (collaborators)
|--------------------------------------------------------------------------
|
| Staff invited to a task see it in their own My Tasks and can update its
| status. Only the assignee, the creator or a task manager can edit, move,
| reassign or delete it. An invite stored on a repeating task's rule row
| applies to every day of that series (matched through recurrence_parent_id).
|
| Table: database/migrations/2026_11_04_task_collaborators.sql. When the table
| is missing, invites are simply unavailable; nothing else breaks.
|
*/

if (!function_exists('task_collab_ready')) {
    function task_collab_ready(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'employee_task_collaborators'");
        return $result !== false && $result->num_rows > 0;
    }
}

if (!function_exists('task_collab_create_table')) {
    function task_collab_create_table(mysqli $conn): bool
    {
        return (bool)$conn->query("CREATE TABLE IF NOT EXISTS employee_task_collaborators (
            task_id INT UNSIGNED NOT NULL,
            user_id INT NOT NULL,
            invited_by INT NULL,
            invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id, user_id),
            KEY idx_task_collaborators_user (user_id),
            CONSTRAINT fk_task_collaborators_task FOREIGN KEY (task_id) REFERENCES employee_tasks(task_id) ON DELETE CASCADE,
            CONSTRAINT fk_task_collaborators_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('task_collab_visible_sql')) {
    /** SQL condition (alias t) matching tasks a user is invited to, directly or through its series. Binds one int. */
    function task_collab_visible_sql(): string
    {
        return 'EXISTS (SELECT 1 FROM employee_task_collaborators c WHERE c.user_id=? AND (c.task_id=t.task_id OR c.task_id=t.recurrence_parent_id))';
    }
}

if (!function_exists('task_collaborators_for')) {
    /**
     * Invited staff per task id, including invites inherited from a series rule.
     *
     * @param list<array> $tasks rows with task_id and recurrence_parent_id
     * @return array<int, list<array{user_id:int, full_name:string, via_series:bool}>>
     */
    function task_collaborators_for(mysqli $conn, array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            if (!empty($task['task_id']) && empty($task['is_projection'])) $ids[(int)$task['task_id']] = true;
            if (!empty($task['recurrence_parent_id'])) $ids[(int)$task['recurrence_parent_id']] = true;
        }
        if (!$ids) return [];
        $list = implode(',', array_map('intval', array_keys($ids)));
        $result = $conn->query("SELECT c.task_id, c.user_id, u.full_name FROM employee_task_collaborators c JOIN users u ON u.user_id=c.user_id WHERE c.task_id IN ($list) ORDER BY u.full_name");
        $byTask = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $byTask[(int)$row['task_id']][(int)$row['user_id']] = (string)$row['full_name'];
            }
        }
        $out = [];
        foreach ($tasks as $task) {
            $own = empty($task['is_projection']) ? ($byTask[(int)$task['task_id']] ?? []) : [];
            $series = !empty($task['recurrence_parent_id']) ? ($byTask[(int)$task['recurrence_parent_id']] ?? []) : [];
            $people = [];
            foreach ($series as $userId => $name) $people[$userId] = ['user_id' => $userId, 'full_name' => $name, 'via_series' => true];
            foreach ($own as $userId => $name) $people[$userId] = ['user_id' => $userId, 'full_name' => $name, 'via_series' => false];
            $key = !empty($task['is_projection']) ? 'p' . $task['recurrence_parent_id'] . '|' . $task['task_date'] : (int)$task['task_id'];
            $out[$key] = array_values($people);
        }
        return $out;
    }
}

if (!function_exists('task_is_collaborator')) {
    function task_is_collaborator(mysqli $conn, array $task, int $userId): bool
    {
        $taskId = (int)$task['task_id'];
        $parentId = (int)($task['recurrence_parent_id'] ?? 0);
        $stmt = $conn->prepare('SELECT 1 FROM employee_task_collaborators WHERE user_id=? AND task_id IN (?,?) LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('iii', $userId, $taskId, $parentId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $found;
    }
}

if (!function_exists('task_collab_add')) {
    /** Invite staff; returns the user ids that were newly added. */
    function task_collab_add(mysqli $conn, int $taskId, array $userIds, int $invitedBy): array
    {
        $stmt = $conn->prepare('INSERT IGNORE INTO employee_task_collaborators (task_id, user_id, invited_by) VALUES (?,?,?)');
        if (!$stmt) return [];
        $added = [];
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId < 1) continue;
            $stmt->bind_param('iii', $taskId, $userId, $invitedBy);
            if ($stmt->execute() && $stmt->affected_rows === 1) $added[] = $userId;
        }
        $stmt->close();
        return $added;
    }
}

if (!function_exists('task_collab_remove')) {
    /** Remove people from a task (and, for a series, from the rule too). */
    function task_collab_remove(mysqli $conn, array $taskIds, array $userIds): int
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$taskIds || !$userIds) return 0;
        $sql = 'DELETE FROM employee_task_collaborators WHERE task_id IN (' . implode(',', $taskIds) . ') AND user_id IN (' . implode(',', $userIds) . ')';
        return $conn->query($sql) ? $conn->affected_rows : 0;
    }
}
