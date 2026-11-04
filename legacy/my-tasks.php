<?php
declare(strict_types=1);

$page_title = 'My Tasks';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/task-reminders.php';
require_once __DIR__ . '/includes/task-importer.php';
require_once __DIR__ . '/includes/task-schedule.php';
require_once __DIR__ . '/includes/task-collaborators.php';
check_role(IMS_STAFF_ROLES);

$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ims_allowed('tasks.view_all', ['Administrator'], "See everyone's tasks (not just their own)");
$canManageAllTasks = ims_allowed('tasks.manage_all', ['Administrator'], "Edit or delete anyone's tasks");
$today = date('Y-m-d');
$view = (string)($_GET['view'] ?? 'today'); // resolved by task_period() below
$notice = '';
$noticeType = 'warning';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$requiredTaskColumns = ['task_due_at','is_recurring','recurrence_days','recurrence_end_date','recurrence_parent_id','reminder_at','reminder_time','reminder_sent_at'];
$requiredTaskIndexes = ['uq_employee_tasks_recurrence_date','idx_employee_tasks_reminder','idx_employee_tasks_recurring','idx_employee_tasks_due_datetime'];
$inspectTaskSchema = static function () use ($conn, $requiredTaskColumns, $requiredTaskIndexes): bool {
    foreach ($requiredTaskColumns as $column) {
        $result = $conn->query("SHOW COLUMNS FROM employee_tasks LIKE '" . $conn->real_escape_string($column) . "'");
        if (!$result || $result->num_rows === 0) return false;
    }
    foreach ($requiredTaskIndexes as $index) {
        $result = $conn->query("SHOW INDEX FROM employee_tasks WHERE Key_name='" . $conn->real_escape_string($index) . "'");
        if (!$result || $result->num_rows === 0) return false;
    }
    return true;
};
$schemaReady = $inspectTaskSchema();
$collabReady = $schemaReady && task_collab_ready($conn);
$schemaRepairError = '';

// Apply the additive task schema to the exact database connected by this page.
// This recovery action is administrator-only and protected by CSRF validation.
if ((!$schemaReady || !$collabReady) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repair_task_schema' && ims_allowed('tasks.repair_schema', ['Administrator'], 'Apply the task database update')) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'employee_tasks'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $createTasks = $conn->query("CREATE TABLE IF NOT EXISTS employee_tasks (
            task_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(240) NOT NULL,
            details TEXT NULL,
            assigned_to INT NOT NULL,
            created_by INT NOT NULL,
            kpi_source ENUM('employee','appraisal') NULL,
            kpi_id INT NULL,
            kra_id INT NULL,
            task_frequency ENUM('Daily','Weekly') NOT NULL DEFAULT 'Daily',
            task_date DATE NOT NULL,
            task_due_at DATETIME NULL,
            status ENUM('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
            moved_from DATE NULL,
            completed_at DATETIME NULL,
            is_recurring TINYINT(1) NOT NULL DEFAULT 0,
            recurrence_days VARCHAR(20) NULL,
            recurrence_end_date DATE NULL,
            recurrence_parent_id INT UNSIGNED NULL,
            reminder_at DATETIME NULL,
            reminder_time TIME NULL,
            reminder_sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id),
            KEY idx_employee_tasks_assignee_date (assigned_to, task_date, status),
            KEY idx_employee_tasks_kpi (kpi_source, kpi_id),
            KEY idx_employee_tasks_kra (kra_id),
            UNIQUE KEY uq_employee_tasks_recurrence_date (recurrence_parent_id, task_date),
            KEY idx_employee_tasks_reminder (status, reminder_at, reminder_sent_at),
            KEY idx_employee_tasks_recurring (is_recurring, task_date, recurrence_end_date),
            KEY idx_employee_tasks_due_datetime (task_due_at),
            CONSTRAINT fk_employee_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE CASCADE,
            CONSTRAINT fk_employee_tasks_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$createTasks) $schemaRepairError = 'Could not create employee_tasks: ' . $conn->error;
        $tableCheck = $conn->query("SHOW TABLES LIKE 'employee_tasks'");
    }

    if (!$tableCheck || $tableCheck->num_rows === 0) {
        if ($schemaRepairError === '') $schemaRepairError = 'The employee_tasks table could not be created.';
    } else {
        $columns = [
            'task_due_at' => 'DATETIME NULL',
            'is_recurring' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'recurrence_days' => 'VARCHAR(20) NULL',
            'recurrence_end_date' => 'DATE NULL',
            'recurrence_parent_id' => 'INT UNSIGNED NULL',
            'reminder_at' => 'DATETIME NULL',
            'reminder_time' => 'TIME NULL',
            'reminder_sent_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $column => $definition) {
            $columnCheck = $conn->query("SHOW COLUMNS FROM employee_tasks LIKE '" . $conn->real_escape_string($column) . "'");
            if ($columnCheck && $columnCheck->num_rows > 0) continue;
            if (!$conn->query("ALTER TABLE employee_tasks ADD COLUMN `$column` $definition")) {
                $schemaRepairError = 'Could not add ' . $column . ': ' . $conn->error;
                break;
            }
        }

        $indexes = [
            'uq_employee_tasks_recurrence_date' => 'UNIQUE INDEX uq_employee_tasks_recurrence_date ON employee_tasks (recurrence_parent_id, task_date)',
            'idx_employee_tasks_reminder' => 'INDEX idx_employee_tasks_reminder ON employee_tasks (status, reminder_at, reminder_sent_at)',
            'idx_employee_tasks_recurring' => 'INDEX idx_employee_tasks_recurring ON employee_tasks (is_recurring, task_date, recurrence_end_date)',
            'idx_employee_tasks_due_datetime' => 'INDEX idx_employee_tasks_due_datetime ON employee_tasks (task_due_at)',
        ];
        if ($schemaRepairError === '') foreach ($indexes as $index => $definition) {
            $indexCheck = $conn->query("SHOW INDEX FROM employee_tasks WHERE Key_name='" . $conn->real_escape_string($index) . "'");
            if ($indexCheck && $indexCheck->num_rows > 0) continue;
            if (!$conn->query("CREATE $definition")) {
                $schemaRepairError = 'Could not create index ' . $index . ': ' . $conn->error;
                break;
            }
        }

        $schemaReady = $inspectTaskSchema();
        if ($schemaReady && $schemaRepairError === '' && !task_collab_create_table($conn)) {
            $schemaRepairError = 'Could not create employee_task_collaborators: ' . $conn->error;
        }
        if ($schemaReady && $schemaRepairError === '') {
            $conn->query("INSERT INTO menu_items (page,icon,label,category,sort_order,is_active)
                SELECT 'my-tasks','fa-list-check','My Tasks','Operations',15,1 FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE page='my-tasks')");
            $conn->query("INSERT IGNORE INTO menu_role_permissions (menu_id,role)
                SELECT mi.menu_id,r.role FROM menu_items mi CROSS JOIN (
                    SELECT 'Administrator' role UNION ALL SELECT 'Programs Lead' UNION ALL SELECT 'MEAL Lead' UNION ALL
                    SELECT 'Operations/Admin' UNION ALL SELECT 'Project Officer' UNION ALL SELECT 'Staff' UNION ALL
                    SELECT 'Program Director' UNION ALL SELECT 'HR' UNION ALL SELECT 'Program Manager' UNION ALL
                    SELECT 'Program Officer' UNION ALL SELECT 'IT Officer' UNION ALL SELECT 'Executive Director' UNION ALL
                    SELECT 'Consultant' UNION ALL SELECT 'Reviewer' UNION ALL SELECT 'Accountant' UNION ALL
                    SELECT 'Finance' UNION ALL SELECT 'Procurement Officer'
                ) r WHERE mi.page='my-tasks'");
            header('Location: my-tasks?view=' . urlencode($view));
            exit;
        }
    }
}

if (!$schemaReady) {
    error_log('[my-tasks] active database task recurrence/reminder schema is incomplete: ' . $conn->error);
}

if ($schemaReady) {
    task_generate_recurring_occurrences($conn, $today);
    task_dispatch_due_reminders($conn);
}

/*
|--------------------------------------------------------------------------
| Where to return after an action (only known query keys are kept)
|--------------------------------------------------------------------------
*/
$cleanQuery = static function (array $source): array {
    $query = [];
    foreach (['view', 'date', 'from', 'to', 'user'] as $key) {
        $value = isset($source[$key]) ? trim((string)$source[$key]) : '';
        if ($value === '') continue;
        if ($key === 'view' && !in_array($value, ['today', 'week', 'month', 'range', 'overdue', 'daily', 'weekly', 'past'], true)) continue;
        if (in_array($key, ['date', 'from', 'to'], true) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) continue;
        if ($key === 'user' && $value !== 'all' && !ctype_digit($value)) continue;
        $query[$key] = $value;
    }
    return $query;
};
$currentQuery = $cleanQuery($_GET);
$backTo = static function (array $query): never {
    header('Location: my-tasks' . ($query ? '?' . http_build_query($query) : ''));
    exit;
};

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }
    parse_str((string)($_POST['return_query'] ?? ''), $returnSource);
    $returnQuery = $cleanQuery(is_array($returnSource) ? $returnSource : []);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'import_monday') {
        try {
            $importSummary = task_import_monday_spreadsheet($conn, $_FILES['monday_file'] ?? [], $uid);
            $message = 'Imported ' . $importSummary['created'] . ' task(s) for today; skipped ' . $importSummary['skipped'] . ' row(s).';
            if ($importSummary['errors']) $message .= ' Skipped: ' . implode(' ', $importSummary['errors']);
            if (!empty($importSummary['notices'])) $message .= ' Note: ' . implode(' ', $importSummary['notices']);
            $type = $importSummary['created'] === 0 ? 'danger' : (($importSummary['skipped'] > 0 || !empty($importSummary['notices'])) ? 'warning' : 'success');
            $_SESSION['notification'] = ['message' => $message, 'type' => $type];
        } catch (Throwable $exception) {
            error_log('[task-import] ' . $exception->getMessage());
            $_SESSION['notification'] = ['message' => $exception->getMessage(), 'type' => 'danger'];
        }
        $backTo(['view' => 'today']);
    }

    $taskId = (int)($_POST['task_id'] ?? 0);
    $scopeTask = null;
    if ($taskId > 0) {
        $find = $conn->prepare('SELECT task_id,assigned_to,created_by,is_recurring,recurrence_parent_id,status,task_date,task_due_at FROM employee_tasks WHERE task_id=? LIMIT 1');
        $find->bind_param('i', $taskId);
        $find->execute();
        $scopeTask = $find->get_result()->fetch_assoc() ?: null;
        $find->close();
    }
    $mayManage = $scopeTask && ($canManageAllTasks || (int)$scopeTask['assigned_to'] === $uid || (int)$scopeTask['created_by'] === $uid);
    // Invited staff may update the status or leave the task, nothing else.
    $isInvited = $scopeTask && !$mayManage && $collabReady && task_is_collaborator($conn, $scopeTask, $uid);
    $activeStaff = static function (int $userId) use ($conn): bool {
        $stmt = $conn->prepare('SELECT user_id FROM users WHERE user_id=? AND is_active=1 AND role NOT IN ("Member","Applicant","Donor/Partner") LIMIT 1');
        $stmt->bind_param('i', $userId); $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
        return $found;
    };

    if ($action === 'delete' && $mayManage) {
        if ((int)$scopeTask['is_recurring'] === 1) {
            // Stop the series: remove its not-yet-done tasks from today on, keep past history as normal tasks.
            $clear = $conn->prepare('DELETE FROM employee_tasks WHERE recurrence_parent_id=? AND task_date>=? AND status="Pending"');
            $clear->bind_param('is', $taskId, $today); $clear->execute(); $clear->close();
            $detach = $conn->prepare('UPDATE employee_tasks SET recurrence_parent_id=NULL WHERE recurrence_parent_id=?');
            $detach->bind_param('i', $taskId); $detach->execute(); $detach->close();
        } elseif (!empty($scopeTask['recurrence_parent_id'])) {
            // Keep the series/date marker so the rule does not create this day again.
            $cancel = $conn->prepare('UPDATE employee_tasks SET status="Cancelled",reminder_sent_at=NOW() WHERE task_id=?');
            $cancel->bind_param('i', $taskId); $cancel->execute(); $cancel->close();
            $backTo($returnQuery);
        }
        $delete = $conn->prepare('DELETE FROM employee_tasks WHERE task_id=?');
        $delete->bind_param('i', $taskId); $delete->execute(); $delete->close();
        $backTo($returnQuery);
    }

    // Move a task to another day (and optionally time). "move" is the quick "to today" for overdue tasks.
    if (in_array($action, ['move', 'reschedule'], true) && $mayManage && (int)$scopeTask['is_recurring'] === 0 && $scopeTask['status'] !== 'Cancelled') {
        $newDate = $action === 'move' ? $today : trim((string)($_POST['new_date'] ?? ''));
        $newTime = $action === 'move' ? '' : trim((string)($_POST['new_time'] ?? ''));
        $dateObject = DateTime::createFromFormat('!Y-m-d', $newDate);
        $clockObject = $newTime !== '' ? DateTime::createFromFormat('!H:i', $newTime) : null;
        if (!$dateObject || $dateObject->format('Y-m-d') !== $newDate || ($newTime !== '' && (!$clockObject || $clockObject->format('H:i') !== $newTime))) {
            $_SESSION['notification'] = ['message' => 'Choose a valid date and time to move the task to.', 'type' => 'warning'];
            $backTo($returnQuery);
        }
        $oldDate = (string)$scopeTask['task_date'];
        $time = $newTime !== '' ? $newTime . ':00' : (!empty($scopeTask['task_due_at']) ? substr((string)$scopeTask['task_due_at'], 11, 8) : null);
        $newDueAt = $time !== null ? $newDate . ' ' . $time : null;
        if ($newDate === $oldDate && $newDueAt === $scopeTask['task_due_at']) $backTo($returnQuery);

        $parentId = (int)($scopeTask['recurrence_parent_id'] ?? 0);
        if ($parentId > 0 && $newDate !== $oldDate) {
            // A repeating task has one task per day: check the target day.
            $clash = $conn->prepare('SELECT task_id,status FROM employee_tasks WHERE recurrence_parent_id=? AND task_date=? AND task_id<>? LIMIT 1');
            $clash->bind_param('isi', $parentId, $newDate, $taskId); $clash->execute();
            $existing = $clash->get_result()->fetch_assoc(); $clash->close();
            if ($existing && $existing['status'] !== 'Cancelled') {
                if ($action === 'move') {
                    // Today already has this repeating task: close the old copy instead of duplicating it.
                    $cancel = $conn->prepare('UPDATE employee_tasks SET status="Cancelled",reminder_sent_at=NOW() WHERE task_id=?');
                    $cancel->bind_param('i', $taskId); $cancel->execute(); $cancel->close();
                    $_SESSION['notification'] = ['message' => 'Today already has this repeating task, so the old copy was closed.', 'type' => 'info'];
                } else {
                    $_SESSION['notification'] = ['message' => 'That day already has this repeating task. Pick another day.', 'type' => 'warning'];
                }
                $backTo($returnQuery);
            }
            if ($existing) {
                // The target day was removed from the series earlier: move this task out of the series.
                if ($collabReady) {
                    $copy = $conn->prepare('INSERT IGNORE INTO employee_task_collaborators (task_id,user_id,invited_by) SELECT ?,user_id,invited_by FROM employee_task_collaborators WHERE task_id=?');
                    $copy->bind_param('ii', $taskId, $parentId); $copy->execute(); $copy->close();
                }
                $detach = $conn->prepare('UPDATE employee_tasks SET recurrence_parent_id=NULL WHERE task_id=?');
                $detach->bind_param('i', $taskId); $detach->execute(); $detach->close();
                $parentId = 0;
            }
        }

        $move = $conn->prepare('UPDATE employee_tasks SET moved_from=IF(task_date<>?,task_date,moved_from),
            reminder_at=IF(reminder_at IS NULL,NULL,DATE_ADD(reminder_at, INTERVAL DATEDIFF(?,task_date) DAY)),
            task_date=?,task_due_at=?,reminder_sent_at=NULL WHERE task_id=?');
        $move->bind_param('ssssi', $newDate, $newDate, $newDate, $newDueAt, $taskId);
        $move->execute(); $move->close();

        if ($parentId > 0 && $oldDate === $today && $newDate !== $today) {
            // Leave a closed marker for today so the series does not recreate it.
            $marker = $conn->prepare('INSERT IGNORE INTO employee_tasks (title,details,assigned_to,created_by,task_frequency,task_date,status,recurrence_parent_id,reminder_sent_at)
                SELECT title,details,assigned_to,created_by,task_frequency,?,"Cancelled",recurrence_parent_id,NOW() FROM employee_tasks WHERE task_id=?');
            $marker->bind_param('si', $today, $taskId); $marker->execute(); $marker->close();
        }
        if ((int)$scopeTask['assigned_to'] !== $uid) {
            $titleRow = $conn->query('SELECT title FROM employee_tasks WHERE task_id=' . $taskId)->fetch_assoc();
            notify_user((int)$scopeTask['assigned_to'], 'A task was moved', ($titleRow['title'] ?? 'Task') . ' · now ' . date('D j M', strtotime($newDate)) . ($time ? ' ' . substr($time, 0, 5) : ''), 'info', $taskId, 'employee_task');
        }
        $_SESSION['notification'] = ['message' => 'Task moved to ' . date('l j M', strtotime($newDate)) . ($time ? ' at ' . substr($time, 0, 5) : '') . '.', 'type' => 'success'];
        $backTo($returnQuery);
    }

    if ($action === 'status' && ($mayManage || $isInvited) && (int)$scopeTask['is_recurring'] === 0) {
        $status = (string)($_POST['status'] ?? 'Pending');
        if (in_array($status, ['Pending', 'In Progress', 'Completed'], true)) {
            $updateStatus = $conn->prepare('UPDATE employee_tasks SET status=?,completed_at=IF(?="Completed",NOW(),NULL),reminder_sent_at=IF(? IN ("Pending","In Progress"),NULL,reminder_sent_at) WHERE task_id=?');
            $updateStatus->bind_param('sssi', $status, $status, $status, $taskId); $updateStatus->execute(); $updateStatus->close();
        }
        $backTo($returnQuery);
    }

    // Reassign a task and invite or remove other staff.
    if ($action === 'share' && $mayManage) {
        $isRule = (int)$scopeTask['is_recurring'] === 1;
        $parentId = (int)($scopeTask['recurrence_parent_id'] ?? 0);
        $wholeSeries = $isRule || ($parentId > 0 && !empty($_POST['whole_series']));
        $ruleId = $isRule ? $taskId : $parentId;
        $messages = [];
        $titleRow = $conn->query('SELECT title FROM employee_tasks WHERE task_id=' . $taskId)->fetch_assoc();
        $taskTitle = (string)($titleRow['title'] ?? 'Task');

        $newAssignee = (int)($_POST['assigned_to'] ?? $scopeTask['assigned_to']);
        if ($newAssignee !== (int)$scopeTask['assigned_to']) {
            if (!$activeStaff($newAssignee)) {
                $_SESSION['notification'] = ['message' => 'Choose an active staff member to assign the task to.', 'type' => 'warning'];
                $backTo($returnQuery);
            }
            if ($wholeSeries && $ruleId > 0) {
                // The repeating task and its unfinished days from today on move to the new person.
                $reassign = $conn->prepare('UPDATE employee_tasks SET assigned_to=? WHERE task_id=? OR (recurrence_parent_id=? AND task_date>=? AND status IN ("Pending","In Progress"))');
                $reassign->bind_param('iiis', $newAssignee, $ruleId, $ruleId, $today);
            } else {
                $reassign = $conn->prepare('UPDATE employee_tasks SET assigned_to=? WHERE task_id=?');
                $reassign->bind_param('ii', $newAssignee, $taskId);
            }
            $reassign->execute(); $reassign->close();
            if ($collabReady) task_collab_remove($conn, [$taskId, $ruleId], [$newAssignee]);
            if ($newAssignee !== $uid) {
                notify_user($newAssignee, 'A task was assigned to you', $taskTitle . ($wholeSeries ? ' · repeating' : ' · Due ' . $scopeTask['task_date']), 'info', $wholeSeries && $ruleId ? $ruleId : $taskId, 'employee_task');
            }
            $messages[] = 'Task assigned.';
        }

        if ($collabReady) {
            $remove = array_map('intval', (array)($_POST['remove'] ?? []));
            if ($remove && task_collab_remove($conn, [$taskId, $parentId], $remove) > 0) $messages[] = count($remove) . ' removed.';

            $invite = array_values(array_filter(array_map('intval', (array)($_POST['invite'] ?? [])),
                static fn(int $userId): bool => $userId > 0 && $userId !== $newAssignee && $activeStaff($userId)));
            if ($invite) {
                $target = $wholeSeries && $ruleId > 0 ? $ruleId : $taskId;
                $added = task_collab_add($conn, $target, $invite, $uid);
                $inviter = (string)($_SESSION['full_name'] ?? 'A colleague');
                foreach ($added as $userId) {
                    if ($userId !== $uid) notify_user($userId, 'You were invited to a task', $inviter . ' invited you to: ' . $taskTitle, 'info', $target, 'employee_task');
                }
                if ($added) $messages[] = count($added) . ' staff invited.';
            }
        }
        $_SESSION['notification'] = ['message' => $messages ? implode(' ', $messages) : 'No changes.', 'type' => $messages ? 'success' : 'info'];
        $backTo($returnQuery);
    }

    if ($action === 'leave' && $isInvited) {
        task_collab_remove($conn, [$taskId, (int)($scopeTask['recurrence_parent_id'] ?? 0)], [$uid]);
        $_SESSION['notification'] = ['message' => 'You left the task.', 'type' => 'success'];
        $backTo($returnQuery);
    }

    if ($action === 'create' || ($action === 'update' && $mayManage)) {
        // One or more tasks (create) or exactly one (update). Shared settings apply to all of them.
        $items = [];
        foreach ((array)($_POST['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $itemTitle = trim((string)($item['title'] ?? ''));
            if ($itemTitle === '') continue;
            $items[] = ['title' => $itemTitle, 'details' => trim((string)($item['details'] ?? ''))];
            if (count($items) >= 50) break;
        }
        if ($action === 'update') $items = array_slice($items, 0, 1);

        $frequency = in_array($_POST['frequency'] ?? '', ['Daily', 'Weekly'], true) ? (string)$_POST['frequency'] : 'Daily';
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $dueTime = trim((string)($_POST['due_time'] ?? ''));
        $dueDateObject = DateTime::createFromFormat('!Y-m-d', $dueDate);
        $validDate = $dueDateObject !== false && $dueDateObject->format('Y-m-d') === $dueDate;
        $dueClock = $dueTime !== '' ? DateTime::createFromFormat('!H:i', $dueTime) : null;
        $validTime = $dueTime === '' || ($dueClock !== false && $dueClock->format('H:i') === $dueTime);
        $taskDate = $validDate ? $dueDate : '';
        $taskDueAt = $validDate && $dueTime !== '' && $validTime ? $dueDate . ' ' . $dueTime . ':00' : null;

        $assignedTo = (int)($_POST['assigned_to'] ?? $uid);

        $isOccurrence = $action === 'update' && !empty($scopeTask['recurrence_parent_id']);
        $repeat = $isOccurrence ? 'none' : (string)($_POST['repeat'] ?? 'none');
        $days = task_repeat_days_from_input($repeat, (array)($_POST['recurrence_days'] ?? []));
        $isRecurring = $days ? 1 : 0;
        $recurrenceDays = $isRecurring ? implode(',', $days) : null;
        $endDate = $isRecurring ? (trim((string)($_POST['recurrence_end_date'] ?? '')) ?: null) : null;
        $validEnd = !$endDate || (DateTime::createFromFormat('!Y-m-d', $endDate) !== false && date('Y-m-d', strtotime($endDate)) === $endDate && $endDate >= $taskDate);

        $reminderAt = null; $reminderTime = null; $validReminder = true;
        if ($isRecurring) {
            $reminderTimeValue = trim((string)($_POST['reminder_time'] ?? ''));
            if ($reminderTimeValue !== '') {
                $clock = DateTime::createFromFormat('!H:i', $reminderTimeValue);
                $validReminder = $clock !== false;
                if ($clock) $reminderTime = $clock->format('H:i:s');
            }
        } else {
            $reminderValue = str_replace('T', ' ', trim((string)($_POST['reminder_at'] ?? '')));
            if ($reminderValue !== '') {
                $reminderDate = DateTime::createFromFormat('!Y-m-d H:i', $reminderValue);
                $validReminder = $reminderDate !== false;
                if ($reminderDate) $reminderAt = $reminderDate->format('Y-m-d H:i:s');
            }
        }

        $kpiRef = trim((string)($_POST['kpi_ref'] ?? ''));
        $kpiSource = null; $kpiId = null; $kraId = null;
        if ($kpiRef !== '' && preg_match('/^(employee|appraisal):(\d+)(?::(\d+))?$/', $kpiRef, $matches)) {
            $kpiSource = $matches[1]; $kpiId = (int)$matches[2]; $kraId = isset($matches[3]) ? (int)$matches[3] : null;
        }
        $assigneeStmt = $conn->prepare('SELECT user_id FROM users WHERE user_id=? AND is_active=1 AND role NOT IN ("Member","Applicant","Donor/Partner") LIMIT 1');
        $assigneeStmt->bind_param('i', $assignedTo); $assigneeStmt->execute();
        $assigneeExists = (bool)$assigneeStmt->get_result()->fetch_assoc(); $assigneeStmt->close();
        $kpiValid = $kpiRef === '' || $kpiSource !== null;
        if ($kpiSource === 'employee') {
            $check = $conn->prepare('SELECT kpi_id FROM kpis WHERE kpi_id=? AND user_id=?');
            $check->bind_param('ii', $kpiId, $assignedTo); $check->execute(); $kpiValid = (bool)$check->get_result()->fetch_assoc(); $check->close();
        } elseif ($kpiSource === 'appraisal') {
            $check = $conn->prepare('SELECT ak.kpi_id FROM appraisal_kpis ak JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE ak.kpi_id=? AND ak.kra_id=? AND pa.user_id=?');
            $check->bind_param('iii', $kpiId, $kraId, $assignedTo); $check->execute(); $kpiValid = (bool)$check->get_result()->fetch_assoc(); $check->close();
        }
        // A repeating task's own row is the rule; its status stays Pending (active).
        $status = $isRecurring ? 'Pending' : (in_array($_POST['status'] ?? '', ['Pending', 'In Progress', 'Completed'], true) ? (string)$_POST['status'] : 'Pending');

        $problems = [];
        if (!$items) $problems[] = 'enter at least one task';
        foreach ($items as $item) {
            if ((function_exists('mb_strlen') ? mb_strlen($item['title'], 'UTF-8') : strlen($item['title'])) > 240) { $problems[] = 'keep each task under 240 characters'; break; }
        }
        if (!$validDate) $problems[] = 'choose a valid date';
        if (!$validTime) $problems[] = 'choose a valid time';
        if ($repeat === 'custom' && !$days) $problems[] = 'choose at least one day to repeat on';
        if (!$validEnd) $problems[] = 'the repeat end date must be on or after the start date';
        if (!$validReminder) $problems[] = 'check the reminder';
        if (!$assigneeExists) $problems[] = 'choose an active staff member';
        if (!$kpiValid) $problems[] = 'the linked KPI/KRA does not belong to the assignee';

        if ($problems) {
            $notice = 'Task not saved: ' . implode(', ', $problems) . '.';
        } elseif ($action === 'create') {
            $insert = $conn->prepare('INSERT INTO employee_tasks (title,details,assigned_to,created_by,kpi_source,kpi_id,kra_id,task_frequency,task_date,task_due_at,status,is_recurring,recurrence_days,recurrence_end_date,reminder_at,reminder_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $created = 0; $firstId = 0;
            $conn->begin_transaction();
            foreach ($items as $item) {
                $title = $item['title']; $details = $item['details'];
                $insert->bind_param('ssiisiissssissss', $title, $details, $assignedTo, $uid, $kpiSource, $kpiId, $kraId, $frequency, $taskDate, $taskDueAt, $status, $isRecurring, $recurrenceDays, $endDate, $reminderAt, $reminderTime);
                if (!$insert->execute()) { $created = -1; break; }
                $firstId = $firstId ?: (int)$conn->insert_id;
                $created++;
            }
            $insert->close();
            if ($created > 0) {
                $conn->commit();
                if ($assignedTo !== $uid) {
                    $summary = $created === 1 ? $items[0]['title'] : $created . ' tasks, starting with ' . $items[0]['title'];
                    notify_user($assignedTo, $created === 1 ? 'A task was assigned to you' : 'Tasks were assigned to you', $summary . ' · ' . ($isRecurring ? task_repeat_label($recurrenceDays) : 'Due ' . $taskDate), 'info', $firstId, 'employee_task');
                }
                task_generate_recurring_occurrences($conn, $today);
                $_SESSION['notification'] = ['message' => $created === 1 ? 'Task added.' : $created . ' tasks added.', 'type' => 'success'];
                $backTo($returnQuery);
            }
            $conn->rollback();
            $notice = 'The tasks could not be saved. Please try again.';
        } else {
            $title = $items[0]['title']; $details = $items[0]['details'];
            $wasRule = (int)$scopeTask['is_recurring'] === 1;
            $conn->begin_transaction();
            if ($wasRule) {
                // Changing a repeating task: its not-yet-done tasks from today on are recreated from the new settings.
                $clearFuture = $conn->prepare('DELETE FROM employee_tasks WHERE recurrence_parent_id=? AND task_date>=? AND status="Pending"');
                $clearFuture->bind_param('is', $taskId, $today); $clearFuture->execute(); $clearFuture->close();
                if (!$isRecurring) {
                    $detach = $conn->prepare('UPDATE employee_tasks SET recurrence_parent_id=NULL WHERE recurrence_parent_id=?');
                    $detach->bind_param('i', $taskId); $detach->execute(); $detach->close();
                }
            }
            $update = $conn->prepare('UPDATE employee_tasks SET title=?,details=?,assigned_to=?,kpi_source=?,kpi_id=?,kra_id=?,task_frequency=?,task_date=?,task_due_at=?,status=?,is_recurring=?,recurrence_days=?,recurrence_end_date=?,reminder_at=?,reminder_time=?,recurrence_parent_id=IF(?=1,NULL,recurrence_parent_id),reminder_sent_at=NULL,completed_at=IF(?="Completed",COALESCE(completed_at,NOW()),NULL) WHERE task_id=?');
            $update->bind_param('ssisiissssissssisi', $title, $details, $assignedTo, $kpiSource, $kpiId, $kraId, $frequency, $taskDate, $taskDueAt, $status, $isRecurring, $recurrenceDays, $endDate, $reminderAt, $reminderTime, $isRecurring, $status, $taskId);
            $ok = $update->execute(); $update->close();
            if ($ok) {
                $conn->commit();
                if ($assignedTo !== (int)$scopeTask['assigned_to'] && $assignedTo !== $uid) {
                    notify_user($assignedTo, 'A task was assigned to you', $title . ' · ' . ($isRecurring ? task_repeat_label($recurrenceDays) : 'Due ' . $taskDate), 'info', $taskId, 'employee_task');
                }
                task_generate_recurring_occurrences($conn, $today);
                $_SESSION['notification'] = ['message' => 'Task updated.', 'type' => 'success'];
                $backTo($returnQuery);
            }
            $conn->rollback();
            $notice = 'The task could not be updated. Please try again.';
        }
    } elseif ($action !== '') {
        $notice = 'You do not have permission to make that task change.';
    }
}

/*
|--------------------------------------------------------------------------
| What to show
|--------------------------------------------------------------------------
*/
$period = task_period((string)($_GET['view'] ?? 'today'), $today, $_GET['date'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
$view = $period['view'];

// Whose tasks: staff see their own; admins can pick a person or everyone.
$personParam = (string)($_GET['user'] ?? '');
$personId = $uid;
if ($isAdmin && $personParam === 'all') $personId = 0;
elseif ($isAdmin && ctype_digit($personParam) && (int)$personParam > 0) $personId = (int)$personParam;

$fetchAll = static function (mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { error_log('[my-tasks] ' . $conn->error); return []; }
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};
$taskColumns = "t.*, assignee.full_name assignee_name, creator.full_name creator_name,
        CASE WHEN t.kpi_source='employee' THEN ek.kpi_title ELSE ak.title END AS kpi_title,
        CASE WHEN t.kpi_source='appraisal' THEN kr.title ELSE NULL END AS kra_title
        FROM employee_tasks t JOIN users assignee ON assignee.user_id=t.assigned_to
        JOIN users creator ON creator.user_id=t.created_by
        LEFT JOIN kpis ek ON t.kpi_source='employee' AND ek.kpi_id=t.kpi_id
        LEFT JOIN appraisal_kpis ak ON t.kpi_source='appraisal' AND ak.kpi_id=t.kpi_id
        LEFT JOIN appraisal_kras kr ON kr.kra_id=t.kra_id";

// A person's tasks: the ones assigned to them plus the ones they were invited to.
if ($personId > 0 && $collabReady) {
    $personSql = ' AND (t.assigned_to=? OR ' . task_collab_visible_sql() . ')';
    $personTypes = 'ii';
    $personParams = [$personId, $personId];
} else {
    $personSql = $personId > 0 ? ' AND t.assigned_to=?' : '';
    $personTypes = $personId > 0 ? 'i' : '';
    $personParams = $personId > 0 ? [$personId] : [];
}

if ($view === 'overdue') {
    $tasks = $fetchAll($conn, "SELECT $taskColumns WHERE t.is_recurring=0 AND t.status IN ('Pending','In Progress') AND t.task_date<?$personSql ORDER BY t.task_date ASC, t.task_due_at ASC LIMIT 2000",
        's' . $personTypes, array_merge([$today], $personParams));
} else {
    $tasks = $fetchAll($conn, "SELECT $taskColumns WHERE t.is_recurring=0 AND t.status<>'Cancelled' AND t.task_date BETWEEN ? AND ?$personSql
        ORDER BY t.task_date ASC, FIELD(t.status,'In Progress','Pending','Completed'), t.task_due_at IS NULL, t.task_due_at ASC, t.created_at ASC LIMIT 2000",
        'ss' . $personTypes, array_merge([$period['start'], $period['end']], $personParams));
}

// Repeating tasks: the rules list, and their upcoming days in this period.
$ruleInvite = $collabReady ? ' OR ' . task_collab_visible_sql() : '';
$ruleScope = $personId > 0
    ? ($personId === $uid ? " AND (t.assigned_to=? OR t.created_by=?$ruleInvite)" : " AND (t.assigned_to=?$ruleInvite)")
    : '';
$ruleParams = $personId > 0 ? ($personId === $uid ? [$uid, $uid] : [$personId]) : [];
if ($personId > 0 && $collabReady) $ruleParams[] = $personId;
$rules = $fetchAll($conn, "SELECT $taskColumns WHERE t.is_recurring=1$ruleScope ORDER BY t.title", str_repeat('i', count($ruleParams)), $ruleParams);

$upcoming = [];
if ($view !== 'overdue' && $period['end'] > $today) {
    $ruleCollab = $collabReady ? task_collaborators_for($conn, $rules) : [];
    $projectionRules = array_values(array_filter($rules, static fn(array $rule): bool => $personId === 0
        || (int)$rule['assigned_to'] === $personId
        || in_array($personId, array_column($ruleCollab[(int)$rule['task_id']] ?? [], 'user_id'), true)));
    if ($projectionRules) {
        $existing = [];
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        foreach ($fetchAll($conn, 'SELECT recurrence_parent_id, task_date FROM employee_tasks WHERE recurrence_parent_id IS NOT NULL AND task_date BETWEEN ? AND ?', 'ss', [max($period['start'], $tomorrow), $period['end']]) as $row) {
            $existing[$row['recurrence_parent_id'] . '|' . $row['task_date']] = true;
        }
        $upcoming = task_projected_occurrences($projectionRules, $period['start'], $period['end'], $today, $existing);
    }
}

// Group by day for multi-day periods.
$byDay = [];
foreach (array_merge($tasks, $upcoming) as $task) $byDay[$task['task_date']][] = $task;
ksort($byDay);
foreach ($byDay as &$dayTasks) {
    usort($dayTasks, static function (array $a, array $b): int {
        $rank = ['In Progress' => 0, 'Pending' => 1, 'Completed' => 2];
        return [$rank[$a['status']] ?? 3, $a['task_due_at'] ?? '9', $a['title']] <=> [$rank[$b['status']] ?? 3, $b['task_due_at'] ?? '9', $b['title']];
    });
}
unset($dayTasks);

// Invited staff per task (including invites that cover a whole repeating series).
$collabMap = $collabReady ? task_collaborators_for($conn, array_merge($tasks, $upcoming, $rules)) : [];
$peopleFor = static fn(array $task): array => $collabMap[!empty($task['is_projection']) ? 'p' . $task['recurrence_parent_id'] . '|' . $task['task_date'] : (int)$task['task_id']] ?? [];
$jsonAttr = static fn(array $data): string => htmlspecialchars(json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}', ENT_QUOTES, 'UTF-8');
$shareData = static fn(array $task, array $people): string => $jsonAttr([
    'task_id' => (int)$task['task_id'], 'title' => $task['title'], 'assigned_to' => (int)$task['assigned_to'],
    'is_rule' => (int)$task['is_recurring'], 'is_occurrence' => !empty($task['recurrence_parent_id']) ? 1 : 0,
    'people' => $people,
]);

$kpis = [];
$sql = 'SELECT CONCAT("employee:",k.kpi_id) ref,k.kpi_title title,NULL kra_title,u.full_name owner_name FROM kpis k JOIN users u ON u.user_id=k.user_id';
$res = $isAdmin ? $conn->query($sql) : null;
$kpis = $isAdmin ? ($res ? $res->fetch_all(MYSQLI_ASSOC) : []) : $fetchAll($conn, $sql . ' WHERE k.user_id=?', 'i', [$uid]);
$appraisalSql = 'SELECT CONCAT("appraisal:",ak.kpi_id,":",ak.kra_id) ref,ak.title,kr.title kra_title,pa.full_name owner_name FROM appraisal_kpis ak JOIN appraisal_kras kr ON kr.kra_id=ak.kra_id JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE pa.status IN ("draft","submitted","pending_review","under_review","reviewed","completed")';
$kpis = array_merge($kpis, $isAdmin ? $fetchAll($conn, $appraisalSql, '', []) : $fetchAll($conn, $appraisalSql . ' AND pa.user_id=?', 'i', [$uid]));
$users = $fetchAll($conn, "SELECT user_id,full_name FROM users WHERE is_active=1 AND role NOT IN ('Member','Applicant','Donor/Partner') ORDER BY full_name", '', []);

$counts = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0];
foreach ($fetchAll($conn, "SELECT status,COUNT(*) total FROM employee_tasks t WHERE t.is_recurring=0 AND t.task_date=?$personSql GROUP BY status", 's' . $personTypes, array_merge([$today], $personParams)) as $row) {
    $counts[$row['status']] = (int)$row['total'];
}
$overdueCount = (int)($fetchAll($conn, "SELECT COUNT(*) total FROM employee_tasks t WHERE t.is_recurring=0 AND t.status IN ('Pending','In Progress') AND t.task_date<?$personSql", 's' . $personTypes, array_merge([$today], $personParams))[0]['total'] ?? 0);

$canManage = static fn(array $task): bool => $canManageAllTasks || (int)$task['assigned_to'] === $uid || (int)$task['created_by'] === $uid;
$link = static function (array $changes) use ($currentQuery): string {
    $query = array_filter(array_merge($currentQuery, $changes), static fn($value): bool => $value !== null && $value !== '');
    return 'my-tasks' . ($query ? '?' . http_build_query($query) : '');
};
$taskJson = static fn(array $task): string => htmlspecialchars(json_encode([
    'task_id' => (int)$task['task_id'], 'title' => $task['title'], 'details' => (string)$task['details'],
    'task_frequency' => $task['task_frequency'], 'task_date' => $task['task_date'],
    'due_time' => !empty($task['task_due_at']) ? substr((string)$task['task_due_at'], 11, 5) : '',
    'assigned_to' => (int)$task['assigned_to'], 'status' => $task['status'],
    'kpi_ref' => $task['kpi_source'] ? $task['kpi_source'] . ':' . $task['kpi_id'] . ($task['kra_id'] ? ':' . $task['kra_id'] : '') : '',
    'kpi_label' => trim((string)$task['kpi_title'] . ($task['kra_title'] ? ' — KRA: ' . $task['kra_title'] : '')),
    'is_recurring' => (int)$task['is_recurring'], 'is_occurrence' => !empty($task['recurrence_parent_id']) ? 1 : 0,
    'repeat' => task_repeat_preset_for($task['recurrence_days'] ?? null),
    'recurrence_days' => array_map('intval', array_filter(explode(',', (string)($task['recurrence_days'] ?? '')))),
    'recurrence_end_date' => (string)($task['recurrence_end_date'] ?? ''),
    'reminder_at' => !empty($task['reminder_at']) ? str_replace(' ', 'T', substr((string)$task['reminder_at'], 0, 16)) : '',
    'reminder_time' => !empty($task['reminder_time']) ? substr((string)$task['reminder_time'], 0, 5) : '',
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}', ENT_QUOTES, 'UTF-8');
$rulesById = [];
foreach ($rules as $rule) $rulesById[(int)$rule['task_id']] = $rule;
$returnQueryString = http_build_query($currentQuery);
$dayLabel = static function (string $date) use ($today): string {
    if ($date === $today) return 'Today · ' . date('l j M', strtotime($date));
    if ($date === date('Y-m-d', strtotime($today . ' +1 day'))) return 'Tomorrow · ' . date('l j M', strtotime($date));
    if ($date === date('Y-m-d', strtotime($today . ' -1 day'))) return 'Yesterday · ' . date('l j M', strtotime($date));
    return date('l j M Y', strtotime($date));
};
$taskTotal = count($tasks) + count($upcoming);

include 'includes/header.php';
if (!$schemaReady): ?>
    <div class="card"><div class="card-body"><div class="alert alert-warning">The connected database is missing task recurrence/reminder schema.<?php if ($schemaRepairError): ?><br><strong>Update error:</strong> <?= $escape($schemaRepairError) ?><?php endif; ?><?php if (ims_allowed('tasks.repair_schema', ['Administrator'], 'Apply the task database update')): ?><form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="repair_task_schema"><button type="submit" class="btn btn-primary"><i class="fas fa-database"></i> Apply task database update</button></form><?php else: ?><br>Ask an Administrator to apply the task database update from this page.<?php endif; ?></div></div></div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<style>
.tasks-page,.task-modal{--task-brand:var(--brand-500);--task-brand-dark:var(--brand-700);--task-brand-soft:var(--brand-50);--task-border:var(--border);--task-ink:var(--ink-900)}
.tasks-page .stats-grid.reports-stats{grid-template-columns:repeat(4,minmax(0,1fr))}
.task-tabs{display:flex;gap:4px;overflow:auto;border-bottom:1px solid var(--task-border)}
.task-tabs a{padding:11px 15px;text-decoration:none;color:var(--ink-300);font-weight:700;white-space:nowrap;border-bottom:3px solid transparent}
.task-tabs a.active{color:var(--task-brand-dark);border-color:var(--task-brand)}
.task-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;justify-content:space-between}
.task-toolbar form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:0}
.task-toolbar .form-group{margin:0}
.task-period-nav{display:flex;gap:6px;align-items:center}
.task-period-nav strong{color:var(--task-ink);margin:0 6px}
.task-day{margin-bottom:6px}
.task-day h4{margin:14px 0 4px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-400)}
.task-day.is-today h4{color:var(--task-brand-dark)}
.task-row{padding:13px 0;border-bottom:1px solid var(--task-border);display:flex;justify-content:space-between;gap:18px;align-items:center}
.task-row:last-child{border:0}
.task-row.is-done .task-title{text-decoration:line-through;color:var(--ink-300)}
.task-row.is-upcoming{opacity:.8}
.task-title{font-weight:800;color:var(--task-ink)}
.task-meta{font-size:12px;color:var(--ink-300);margin-top:5px;line-height:1.65;white-space:pre-line}
.task-controls{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.task-actions{display:flex;gap:6px}
.task-icon-btn{border:1px solid var(--task-border);border-radius:8px;background:var(--ink-0);color:var(--task-brand-dark);padding:7px 9px;cursor:pointer}
.task-icon-btn:hover{background:var(--task-brand-soft)}
.task-rule-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--task-border)}
.task-rule-row:last-child{border:0}
.task-modal[hidden]{display:none}
.task-modal{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:18px}
.task-modal-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.56)}
.task-modal-card{position:relative;display:flex;flex-direction:column;width:min(780px,100%);max-height:calc(100vh - 36px);overflow:hidden;background:var(--ink-0,#fff);border:1px solid var(--task-border);border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.25)}
.task-modal-card form{display:flex;flex:1;min-height:0;flex-direction:column;overflow:hidden}
.task-modal-head,.task-modal-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;flex-shrink:0}
.task-modal-head{border-bottom:1px solid var(--task-border)}
.task-modal-head h3{margin:0;color:var(--task-ink)}
.task-modal-body{padding:20px;overflow-y:auto;flex:1;min-height:0}
.task-modal-foot{justify-content:flex-end;border-top:1px solid var(--task-border);background:var(--ink-0,#fff)}
.task-close{border:0;background:transparent;color:var(--ink-300);font-size:25px;cursor:pointer}
.task-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.task-form-grid .wide{grid-column:1/-1}
.task-items{display:flex;flex-direction:column;gap:10px;margin-bottom:12px}
.task-item{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:start;padding:10px;border:1px solid var(--task-border);border-radius:10px;background:var(--task-brand-soft)}
.task-item textarea{min-height:42px}
.task-repeat-days{display:flex;gap:8px;flex-wrap:wrap}
.task-repeat-days label{display:inline-flex;gap:6px;align-items:center;padding:7px 10px;border:1px solid var(--task-border);border-radius:8px;background:var(--ink-0)}
.task-hidden{display:none!important}
@media(max-width:780px){.tasks-page .stats-grid.reports-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.task-row{align-items:flex-start;flex-direction:column}.task-form-grid,.task-item{grid-template-columns:1fr}.task-form-grid .wide{grid-column:auto}.task-modal{padding:8px}.task-modal-card{max-height:calc(100vh - 16px)}.task-modal-body{padding:14px}}
</style>

<div class="tasks-page page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-list-check" aria-hidden="true"></i> <?= $personId === 0 ? 'Team Tasks' : ($personId === $uid ? 'My Tasks' : 'Tasks') ?></h1>
        <p>Daily actions and weekly priorities linked to KPI and KRA commitments. Repeating tasks are added to each day automatically.</p>
    </div>
    <div class="hero-actions">
        <button type="button" class="btn" id="importTasksButton"><i class="fas fa-file-excel" aria-hidden="true"></i> Import Excel</button>
        <button type="button" class="btn btn-primary" id="addTaskButton"><i class="fas fa-plus" aria-hidden="true"></i> Add tasks</button>
    </div>
</div>

<?php if (!$collabReady && ims_allowed('tasks.repair_schema', ['Administrator'], 'Apply the task database update')): ?>
    <div class="alert alert-info">Inviting staff to tasks needs a small database update. <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="repair_task_schema"><button type="submit" class="btn btn-primary btn-sm">Apply task database update</button></form></div>
<?php endif; ?>
<div class="stats-grid reports-stats">
    <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-hourglass-half" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Pending today</span><span class="stat-value"><?= $counts['Pending'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-spinner" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">In progress today</span><span class="stat-value"><?= $counts['In Progress'] ?></span></div></div>
    <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Completed today</span><span class="stat-value"><?= $counts['Completed'] ?></span></div></div>
    <a class="stat-card" href="<?= $escape($link(['view' => 'overdue', 'date' => null, 'from' => null, 'to' => null])) ?>" style="text-decoration:none"><div class="stat-icon bg-red"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Overdue</span><span class="stat-value"><?= $overdueCount ?></span></div></a>
</div>

<nav class="task-tabs" aria-label="Task periods">
    <?php foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'range' => 'Date range', 'overdue' => 'Overdue'] as $key => $label): ?>
        <a class="<?= $view === $key ? 'active' : '' ?>" href="<?= $escape($link(['view' => $key, 'date' => null, 'from' => null, 'to' => null])) ?>" <?= $view === $key ? 'aria-current="page"' : '' ?>><?= $label ?><?= $key === 'overdue' && $overdueCount ? ' (' . $overdueCount . ')' : '' ?></a>
    <?php endforeach; ?>
</nav>

<div class="card"><div class="card-body task-toolbar">
    <?php if ($period['prev'] !== null): ?>
        <div class="task-period-nav">
            <a class="task-icon-btn" href="<?= $escape($link(['date' => $period['prev']])) ?>" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>
            <strong><?= $escape($period['label']) ?></strong>
            <a class="task-icon-btn" href="<?= $escape($link(['date' => $period['next']])) ?>" aria-label="Next"><i class="fas fa-chevron-right"></i></a>
            <?php if ($period['anchor'] !== task_period($view, $today)['anchor']): ?><a class="btn btn-secondary btn-sm" href="<?= $escape($link(['date' => null])) ?>">Back to now</a><?php endif; ?>
        </div>
    <?php elseif ($view === 'range'): ?>
        <form method="get">
            <input type="hidden" name="view" value="range">
            <?php if (isset($currentQuery['user'])): ?><input type="hidden" name="user" value="<?= $escape($currentQuery['user']) ?>"><?php endif; ?>
            <div class="form-group"><label for="rangeFrom">From</label><input id="rangeFrom" type="date" name="from" value="<?= $escape($period['start']) ?>" class="form-control" required></div>
            <div class="form-group"><label for="rangeTo">To</label><input id="rangeTo" type="date" name="to" value="<?= $escape($period['end']) ?>" class="form-control" required></div>
            <button type="submit" class="btn btn-primary">Show</button>
        </form>
    <?php else: ?>
        <strong><?= $escape($period['label']) ?></strong>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
        <form method="get">
            <?php foreach ($currentQuery as $key => $value): if ($key === 'user') continue; ?><input type="hidden" name="<?= $escape($key) ?>" value="<?= $escape($value) ?>"><?php endforeach; ?>
            <div class="form-group">
                <label for="taskUser">Show tasks for</label>
                <select id="taskUser" name="user" class="form-control" onchange="this.form.submit()">
                    <option value="">Me</option>
                    <option value="all" <?= $personId === 0 ? 'selected' : '' ?>>Everyone</option>
                    <?php foreach ($users as $user): if ((int)$user['user_id'] === $uid) continue; ?>
                        <option value="<?= (int)$user['user_id'] ?>" <?= $personId === (int)$user['user_id'] ? 'selected' : '' ?>><?= $escape($user['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
        </form>
    <?php endif; ?>
</div></div>

<?php if ($view === 'today' && $overdueCount > 0 && $period['start'] === $today): ?>
    <div class="alert alert-warning">You have <?= $overdueCount ?> unfinished task<?= $overdueCount === 1 ? '' : 's' ?> from earlier days. <a href="<?= $escape($link(['view' => 'overdue', 'date' => null])) ?>">Review overdue tasks</a></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-calendar-check" style="color:var(--brand-500)"></i> <?= $escape($period['label']) ?></h3><span><?= $taskTotal ?> task<?= $taskTotal === 1 ? '' : 's' ?></span></div>
    <div class="card-body">
    <?php if (!$byDay): ?>
        <div class="empty-state"><i class="fas fa-clipboard-list" aria-hidden="true"></i><p><?= $view === 'overdue' ? 'Nothing overdue. Well done.' : 'No tasks in this period.' ?></p></div>
    <?php else: foreach ($byDay as $date => $dayTasks): ?>
        <section class="task-day <?= $date === $today ? 'is-today' : '' ?>">
            <?php if ($view !== 'today'): ?><h4><?= $escape($dayLabel($date)) ?> <span>(<?= count($dayTasks) ?>)</span></h4><?php endif; ?>
            <?php foreach ($dayTasks as $task):
                $upcomingTask = !empty($task['is_projection']);
                $editable = $canManage($task);
                $editSource = $upcomingTask ? ($rulesById[(int)$task['recurrence_parent_id']] ?? $task) : $task;
                $people = $peopleFor($task);
                $invitedHere = !$editable && in_array($uid, array_column($people, 'user_id'), true);
            ?>
            <article class="task-row <?= $task['status'] === 'Completed' ? 'is-done' : '' ?> <?= $upcomingTask ? 'is-upcoming' : '' ?>">
                <div>
                    <div class="task-title"><?= $escape($task['title']) ?>
                        <?php if (!empty($task['recurrence_parent_id'])): ?> <span class="badge badge-purple"><i class="fas fa-repeat"></i> Repeating</span><?php endif; ?>
                        <?php if (!empty($task['moved_from'])): ?> <span class="badge badge-secondary">Moved from <?= $escape(date('j M', strtotime((string)$task['moved_from']))) ?></span><?php endif; ?>
                        <?php if ($invitedHere): ?> <span class="badge badge-info"><i class="fas fa-user-group"></i> Shared with you</span><?php endif; ?>
                    </div>
                    <div class="task-meta"><?php
                        $meta = [];
                        if (!empty($task['task_due_at'])) $meta[] = 'Due ' . substr((string)$task['task_due_at'], 11, 5);
                        $meta[] = $task['task_frequency'] === 'Weekly' ? 'Weekly priority' : 'Daily action';
                        if ($personId !== $uid || (int)$task['assigned_to'] !== $uid) $meta[] = 'Assigned to ' . $task['assignee_name'];
                        $others = array_column(array_filter($people, static fn(array $person): bool => (int)$person['user_id'] !== $uid), 'full_name');
                        if ($others) $meta[] = 'With ' . implode(', ', $others);
                        if ($task['kpi_title']) $meta[] = 'KPI: ' . $task['kpi_title'];
                        if ($task['kra_title']) $meta[] = 'KRA: ' . $task['kra_title'];
                        if (!empty($task['reminder_at']) && !$upcomingTask) $meta[] = 'Reminder ' . date('d M, H:i', strtotime((string)$task['reminder_at']));
                        echo $escape(implode(' · ', $meta));
                        if ($task['details']) echo "\n" . $escape($task['details']);
                    ?></div>
                </div>
                <div class="task-controls">
                    <?php if ($upcomingTask): ?>
                        <span class="badge badge-secondary">Upcoming</span>
                    <?php else: ?>
                        <span class="badge <?= ['Completed' => 'badge-success', 'In Progress' => 'badge-info'][$task['status']] ?? 'badge-secondary' ?>"><?= $escape($task['status']) ?></span>
                    <?php endif; ?>
                    <?php if ($editable): ?>
                        <div class="task-actions">
                            <button type="button" class="task-icon-btn task-edit" data-task="<?= $taskJson($editSource) ?>" title="<?= $upcomingTask ? 'Edit the repeating task' : 'Edit task' ?>" aria-label="<?= $upcomingTask ? 'Edit the repeating task' : 'Edit task' ?>: <?= $escape($task['title']) ?>"><i class="fas fa-pen"></i></button>
                            <?php if (!$upcomingTask && $task['status'] !== 'Completed'): ?>
                                <button type="button" class="task-icon-btn task-move" data-move="<?= $jsonAttr(['task_id' => (int)$task['task_id'], 'title' => $task['title'], 'date' => $task['task_date'], 'time' => !empty($task['task_due_at']) ? substr((string)$task['task_due_at'], 11, 5) : '', 'repeating' => !empty($task['recurrence_parent_id'])]) ?>" title="Move to another day" aria-label="Move to another day: <?= $escape($task['title']) ?>"><i class="fas fa-calendar-days"></i></button>
                            <?php endif; ?>
                            <button type="button" class="task-icon-btn task-share" data-share="<?= $upcomingTask ? $shareData($editSource, $peopleFor($editSource)) : $shareData($task, $people) ?>" title="Assign &amp; invite staff" aria-label="Assign and invite staff: <?= $escape($task['title']) ?>"><i class="fas fa-user-plus"></i></button>
                            <?php if (!$upcomingTask && in_array($task['status'], ['Pending', 'In Progress'], true) && $task['task_date'] < $today): ?>
                                <button type="button" class="task-icon-btn task-confirm" data-mode="move" data-id="<?= (int)$task['task_id'] ?>" data-title="<?= $escape($task['title']) ?>" title="Move to today" aria-label="Move to today: <?= $escape($task['title']) ?>"><i class="fas fa-calendar-day"></i></button>
                            <?php endif; ?>
                            <?php if (!$upcomingTask): ?>
                                <button type="button" class="task-icon-btn task-confirm" data-mode="delete" data-id="<?= (int)$task['task_id'] ?>" data-title="<?= $escape($task['title']) ?>" data-recurring="<?= !empty($task['recurrence_parent_id']) ? '1' : '0' ?>" title="Delete task" aria-label="Delete task: <?= $escape($task['title']) ?>"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$upcomingTask && ($editable || $invitedHere)): ?>
                        <form method="post" class="task-status-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="task_id" value="<?= (int)$task['task_id'] ?>">
                            <input type="hidden" name="return_query" value="<?= $escape($returnQueryString) ?>">
                            <select class="form-control" name="status" aria-label="Status of <?= $escape($task['title']) ?>" onchange="this.form.submit()">
                                <?php foreach (['Pending', 'In Progress', 'Completed'] as $status): ?><option <?= $task['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?>
                            </select>
                            <noscript><button type="submit" class="btn btn-secondary btn-sm">Save</button></noscript>
                        </form>
                    <?php endif; ?>
                    <?php if ($invitedHere && !$upcomingTask): ?>
                        <button type="button" class="task-icon-btn task-confirm" data-mode="leave" data-id="<?= (int)$task['task_id'] ?>" data-title="<?= $escape($task['title']) ?>" title="Leave this task" aria-label="Leave task: <?= $escape($task['title']) ?>"><i class="fas fa-right-from-bracket"></i></button>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; endif; ?>
    </div>
</div>

<?php if ($rules): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-repeat" style="color:var(--brand-500)"></i> Repeating tasks</h3><span><?= count($rules) ?></span></div>
    <div class="card-body">
    <?php foreach ($rules as $rule): $ended = !empty($rule['recurrence_end_date']) && $rule['recurrence_end_date'] < $today; ?>
        <div class="task-rule-row">
            <div>
                <strong><?= $escape($rule['title']) ?></strong> <?php if ($ended): ?><span class="badge badge-secondary">Ended</span><?php endif; ?>
                <div class="task-meta"><?= $escape(implode(' · ', array_filter([
                    $personId !== $uid ? 'Assigned to ' . $rule['assignee_name'] : null,
                    task_repeat_label($rule['recurrence_days']),
                    $peopleFor($rule) ? 'With ' . implode(', ', array_column($peopleFor($rule), 'full_name')) : null,
                    'From ' . date('j M Y', strtotime((string)$rule['task_date'])),
                    $rule['recurrence_end_date'] ? 'Until ' . date('j M Y', strtotime((string)$rule['recurrence_end_date'])) : 'No end date',
                    !empty($rule['task_due_at']) ? 'Due ' . substr((string)$rule['task_due_at'], 11, 5) : null,
                    $rule['reminder_time'] ? 'Reminder ' . substr((string)$rule['reminder_time'], 0, 5) : null,
                ]))) ?></div>
            </div>
            <?php if ($canManage($rule)): ?>
            <div class="task-actions">
                <button type="button" class="task-icon-btn task-edit" data-task="<?= $taskJson($rule) ?>" title="Edit repeating task" aria-label="Edit repeating task: <?= $escape($rule['title']) ?>"><i class="fas fa-pen"></i></button>
                <button type="button" class="task-icon-btn task-share" data-share="<?= $shareData($rule, $peopleFor($rule)) ?>" title="Assign &amp; invite staff" aria-label="Assign and invite staff: <?= $escape($rule['title']) ?>"><i class="fas fa-user-plus"></i></button>
                <button type="button" class="task-icon-btn task-confirm" data-mode="delete-rule" data-id="<?= (int)$rule['task_id'] ?>" data-title="<?= $escape($rule['title']) ?>" title="Stop repeating" aria-label="Stop repeating: <?= $escape($rule['title']) ?>"><i class="fas fa-trash"></i></button>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
</div>

<!-- Add / edit tasks -->
<div class="task-modal" id="taskFormModal" hidden>
<div class="task-modal-backdrop" data-close-modal></div>
<section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskModalTitle">
    <div class="task-modal-head"><h3 id="taskModalTitle">Add tasks</h3><button type="button" class="task-close" data-close-modal aria-label="Close">&times;</button></div>
    <form method="post" id="taskForm">
    <div class="task-modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="taskAction" value="create">
        <input type="hidden" name="task_id" id="taskId">
        <input type="hidden" name="return_query" value="<?= $escape($returnQueryString) ?>">

        <div class="task-items" id="taskItems"></div>
        <button type="button" class="btn btn-secondary btn-sm" id="addTaskItem"><i class="fas fa-plus"></i> Add another task</button>
        <p class="task-hidden" id="occurrenceNote" style="margin:10px 0 0;color:var(--ink-400)">This is one day of a repeating task. Changes here apply to this day only. To change every day, edit it under “Repeating tasks”.</p>
        <hr>
        <p id="sharedNote" style="margin:0 0 10px;color:var(--ink-400)">These settings apply to every task above.</p>
        <div class="task-form-grid">
            <div class="form-group"><label for="taskDueDate" class="required" id="taskDueDateLabel">Date</label><input id="taskDueDate" type="date" name="due_date" class="form-control" required></div>
            <div class="form-group"><label for="taskDueTime">Due time (optional)</label><input id="taskDueTime" type="time" name="due_time" class="form-control"></div>
            <div class="form-group"><label for="assignedTo">Assign to</label>
                <select id="assignedTo" name="assigned_to" class="form-control" required>
                    <?php foreach ($users as $user): ?><option value="<?= (int)$user['user_id'] ?>"><?= $escape($user['full_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="taskFrequency">Type</label>
                <select id="taskFrequency" name="frequency" class="form-control"><option value="Daily">Daily action</option><option value="Weekly">Weekly priority</option></select>
            </div>
            <div class="form-group wide"><label for="kpiRef">Linked KPI / KRA</label>
                <select id="kpiRef" name="kpi_ref" class="form-control">
                    <option value="">No KPI link</option>
                    <?php foreach ($kpis as $kpi): ?><option value="<?= $escape($kpi['ref']) ?>"><?= $escape($kpi['title']) ?><?= $kpi['kra_title'] ? ' — KRA: ' . $escape($kpi['kra_title']) : '' ?> · <?= $escape($kpi['owner_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group task-status-field"><label for="taskStatus">Status</label>
                <select id="taskStatus" name="status" class="form-control"><option>Pending</option><option>In Progress</option><option>Completed</option></select>
            </div>
            <div class="form-group task-repeat-section"><label for="taskRepeat">Repeat</label>
                <select id="taskRepeat" name="repeat" class="form-control">
                    <option value="none">Does not repeat</option>
                    <option value="daily">Every day</option>
                    <option value="weekdays">Every weekday (Mon–Fri)</option>
                    <option value="custom">On chosen days…</option>
                </select>
            </div>
            <fieldset class="form-group wide task-repeat-days-field task-hidden" style="border:0;padding:0;margin:0">
                <legend style="font-weight:600;font-size:14px">Repeat on</legend>
                <div class="task-repeat-days">
                    <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $number => $name): ?>
                        <label><input type="checkbox" name="recurrence_days[]" value="<?= $number ?>"> <?= $name ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="form-group task-repeat-on task-hidden"><label for="recurrenceEnd">Repeat until (optional)</label><input id="recurrenceEnd" type="date" name="recurrence_end_date" class="form-control"></div>
            <div class="form-group task-repeat-on task-hidden"><label for="reminderTime">Daily reminder time (optional)</label><input id="reminderTime" type="time" name="reminder_time" class="form-control"></div>
            <div class="form-group task-repeat-off"><label for="reminderAt">Reminder (optional)</label><input id="reminderAt" type="datetime-local" name="reminder_at" class="form-control"></div>
        </div>
        <p class="task-repeat-on task-hidden" style="margin:6px 0 0;color:var(--ink-400)">A repeating task is added to each matching day automatically, starting on the date above.</p>
    </div>
    <div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary" id="taskSubmit"><i class="fas fa-check"></i> Save</button></div>
    </form>
</section>
</div>

<template id="taskItemTemplate">
    <div class="task-item">
        <div><label class="required">Task</label><input type="text" class="form-control" data-field="title" maxlength="240" placeholder="e.g. Submit the monthly KPI report"></div>
        <div><label>Details (optional)</label><textarea class="form-control" data-field="details" rows="1" placeholder="Notes, links or blockers"></textarea></div>
        <button type="button" class="task-icon-btn task-item-remove" aria-label="Remove this task" title="Remove" style="margin-top:24px"><i class="fas fa-xmark"></i></button>
    </div>
</template>

<!-- Move to another day -->
<div class="task-modal" id="taskMoveModal" hidden>
<div class="task-modal-backdrop" data-close-modal></div>
<section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskMoveTitle" style="max-width:480px">
    <div class="task-modal-head"><h3 id="taskMoveTitle">Move task</h3><button type="button" class="task-close" data-close-modal aria-label="Close">&times;</button></div>
    <form method="post">
    <div class="task-modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="task_id" id="moveTaskId">
        <input type="hidden" name="return_query" value="<?= $escape($returnQueryString) ?>">
        <p id="moveTaskName" style="margin:0 0 12px;font-weight:700;color:var(--task-ink)"></p>
        <div class="task-form-grid">
            <div class="form-group"><label for="moveDate" class="required">New date</label><input id="moveDate" type="date" name="new_date" class="form-control" required></div>
            <div class="form-group"><label for="moveTime">Time (optional)</label><input id="moveTime" type="time" name="new_time" class="form-control"></div>
        </div>
        <div class="task-repeat-days" style="margin-top:4px">
            <button type="button" class="btn btn-secondary btn-sm" data-move-to="0">Today</button>
            <button type="button" class="btn btn-secondary btn-sm" data-move-to="1">Tomorrow</button>
            <button type="button" class="btn btn-secondary btn-sm" data-move-to="monday">Next Monday</button>
        </div>
        <p id="moveRepeatNote" class="task-hidden" style="margin:12px 0 0;color:var(--ink-400)">Only this day moves. The task keeps repeating on its usual days.</p>
    </div>
    <div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-calendar-days"></i> Move task</button></div>
    </form>
</section>
</div>

<!-- Assign & invite staff -->
<div class="task-modal" id="taskShareModal" hidden>
<div class="task-modal-backdrop" data-close-modal></div>
<section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskShareTitle" style="max-width:560px">
    <div class="task-modal-head"><h3 id="taskShareTitle">Assign &amp; invite staff</h3><button type="button" class="task-close" data-close-modal aria-label="Close">&times;</button></div>
    <form method="post">
    <div class="task-modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="share">
        <input type="hidden" name="task_id" id="shareTaskId">
        <input type="hidden" name="return_query" value="<?= $escape($returnQueryString) ?>">
        <p id="shareTaskName" style="margin:0 0 12px;font-weight:700;color:var(--task-ink)"></p>
        <div class="form-group">
            <label for="shareAssignee">Assigned to</label>
            <select id="shareAssignee" name="assigned_to" class="form-control">
                <?php foreach ($users as $user): ?><option value="<?= (int)$user['user_id'] ?>"><?= $escape($user['full_name']) ?></option><?php endforeach; ?>
            </select>
            <small>The assigned person owns the task and can edit, move or delete it.</small>
        </div>
        <label class="task-hidden" id="shareSeriesOption" style="display:flex;gap:8px;align-items:flex-start;margin:0 0 12px">
            <input type="checkbox" name="whole_series" value="1" checked> <span>Apply to every day of this repeating task (not just this day)</span>
        </label>
        <?php if ($collabReady): ?>
            <div class="form-group" id="shareCurrentWrap">
                <span style="font-weight:600;display:block;margin-bottom:6px">Invited staff</span>
                <div id="shareCurrent"></div>
            </div>
            <div class="form-group">
                <label for="shareSearch">Invite staff</label>
                <input id="shareSearch" type="search" class="form-control" placeholder="Search staff by name" autocomplete="off">
                <div id="shareCandidates" style="max-height:220px;overflow:auto;margin-top:8px;border:1px solid var(--task-border);border-radius:8px;padding:6px 10px">
                    <?php foreach ($users as $user): ?>
                        <label style="display:flex;gap:8px;align-items:center;padding:4px 0" data-user-id="<?= (int)$user['user_id'] ?>" data-name="<?= $escape(strtolower((string)$user['full_name'])) ?>">
                            <input type="checkbox" name="invite[]" value="<?= (int)$user['user_id'] ?>"> <?= $escape($user['full_name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small>Invited staff see the task in their own My Tasks, get a notification, and can update its status.</small>
            </div>
        <?php elseif (ims_allowed('tasks.repair_schema', ['Administrator'], 'Apply the task database update')): ?>
            <div class="alert alert-info" style="margin:0">To invite staff to tasks, apply the task database update first.</div>
        <?php endif; ?>
    </div>
    <div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save</button></div>
    </form>
</section>
</div>

<!-- Confirm move / delete -->
<div class="task-modal" id="taskConfirmModal" hidden>
<div class="task-modal-backdrop" data-close-modal></div>
<section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskConfirmTitle" style="max-width:470px">
    <div class="task-modal-head"><h3 id="taskConfirmTitle">Confirm</h3><button type="button" class="task-close" data-close-modal aria-label="Close">&times;</button></div>
    <form method="post" id="taskConfirmForm">
    <div class="task-modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="task_id" id="confirmTaskId">
        <input type="hidden" name="action" id="confirmTaskAction">
        <input type="hidden" name="return_query" value="<?= $escape($returnQueryString) ?>">
        <p id="taskConfirmMessage" style="margin:0;color:var(--ink-400)"></p>
    </div>
    <div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-danger" id="confirmTaskSubmit">Confirm</button></div>
    </form>
</section>
</div>

<!-- Import -->
<div class="task-modal" id="taskImportModal" hidden>
<div class="task-modal-backdrop" data-close-modal></div>
<section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskImportTitle" style="max-width:660px">
    <div class="task-modal-head"><h3 id="taskImportTitle"><i class="fas fa-file-excel" style="color:var(--task-brand)"></i> Import tasks from Excel</h3><button type="button" class="task-close" data-close-modal aria-label="Close">&times;</button></div>
    <form method="post" enctype="multipart/form-data">
    <div class="task-modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_monday">
        <p>Upload a Monday.com board export or any Excel/CSV task list (<strong>.xlsx, .xls or .csv</strong>, up to 15 MB). Upload the Monday.com export as it is.</p>
        <div class="alert alert-info">
            <strong>All imported tasks are added for today.</strong> Dates in the sheet are not used for scheduling; each task keeps its sheet date in its details for reference.<br>
            Tasks go to the matching staff member in the Person column, or to you if nobody matches. Rows marked as repeating repeat on the weekdays given, or every day.
        </div>
        <div class="form-group"><label for="mondayFile" class="required">File</label><input id="mondayFile" type="file" name="monday_file" class="form-control" accept=".xlsx,.xls,.csv" required></div>
        <small>Recognised columns: Task/Name, Person/Owner, Status, Notes/Description, KPI, KRA, Frequency, Recurring, Repeat On/Weekdays, Reminder Time. Board names, group titles and headings are skipped; subitems are imported as tasks.</small>
    </div>
    <div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Import tasks</button></div>
    </form>
</section>
</div>

<script>
(function () {
    const today = <?= json_encode($today) ?>;
    const me = <?= json_encode((string)$uid) ?>;
    const form = document.getElementById('taskForm');
    const items = document.getElementById('taskItems');
    const itemTemplate = document.getElementById('taskItemTemplate');
    const f = {
        action: document.getElementById('taskAction'), id: document.getElementById('taskId'),
        date: document.getElementById('taskDueDate'), time: document.getElementById('taskDueTime'),
        assignee: document.getElementById('assignedTo'), frequency: document.getElementById('taskFrequency'),
        kpi: document.getElementById('kpiRef'), status: document.getElementById('taskStatus'),
        repeat: document.getElementById('taskRepeat'), end: document.getElementById('recurrenceEnd'),
        reminderAt: document.getElementById('reminderAt'), reminderTime: document.getElementById('reminderTime'),
    };
    const dayBoxes = Array.from(form.querySelectorAll('[name="recurrence_days[]"]'));
    let lastFocus = null;

    function openModal(modal, focusEl) {
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        (focusEl || modal.querySelector('input:not([type=hidden]),select,textarea,button')).focus();
    }
    function closeModal(modal) {
        modal.hidden = true;
        if (document.querySelectorAll('.task-modal:not([hidden])').length === 0) document.body.style.overflow = '';
        if (lastFocus) lastFocus.focus();
    }

    function renumber() {
        items.querySelectorAll('.task-item').forEach((item, index) => {
            item.querySelectorAll('[data-field]').forEach(field => {
                field.name = 'items[' + index + '][' + field.dataset.field + ']';
                field.id = 'item-' + index + '-' + field.dataset.field;
                const label = field.parentElement.querySelector('label');
                if (label) label.htmlFor = field.id;
            });
            item.querySelector('[data-field="title"]').required = index === 0;
            item.querySelector('.task-item-remove').hidden = items.children.length === 1;
        });
    }
    function addItem(title, details) {
        const item = itemTemplate.content.firstElementChild.cloneNode(true);
        item.querySelector('[data-field="title"]').value = title || '';
        item.querySelector('[data-field="details"]').value = details || '';
        items.appendChild(item);
        renumber();
        return item;
    }
    items.addEventListener('click', event => {
        const remove = event.target.closest('.task-item-remove');
        if (!remove) return;
        remove.closest('.task-item').remove();
        renumber();
        items.querySelector('[data-field="title"]').focus();
    });
    // Enter in a task name adds the next row, so a list can be typed quickly.
    items.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.target.dataset.field !== 'title' || f.action.value !== 'create') return;
        event.preventDefault();
        const rows = Array.from(items.children);
        const row = event.target.closest('.task-item');
        if (rows.indexOf(row) === rows.length - 1) addItem().querySelector('[data-field="title"]').focus();
        else rows[rows.indexOf(row) + 1].querySelector('[data-field="title"]').focus();
    });
    document.getElementById('addTaskItem').addEventListener('click', () => addItem().querySelector('[data-field="title"]').focus());

    function updateRepeat() {
        const repeating = f.repeat.value !== 'none';
        form.querySelectorAll('.task-repeat-on').forEach(el => el.classList.toggle('task-hidden', !repeating));
        form.querySelectorAll('.task-repeat-off').forEach(el => el.classList.toggle('task-hidden', repeating));
        form.querySelector('.task-repeat-days-field').classList.toggle('task-hidden', f.repeat.value !== 'custom');
        form.querySelector('.task-status-field').classList.toggle('task-hidden', repeating || f.action.value === 'create');
        document.getElementById('taskDueDateLabel').textContent = repeating ? 'Starts on' : 'Date';
    }
    f.repeat.addEventListener('change', updateRepeat);

    function openNew() {
        form.reset();
        items.innerHTML = '';
        addItem();
        f.action.value = 'create'; f.id.value = '';
        f.date.value = today; f.assignee.value = me; f.repeat.value = 'none';
        document.getElementById('taskModalTitle').textContent = 'Add tasks';
        document.getElementById('addTaskItem').hidden = false;
        document.getElementById('sharedNote').hidden = false;
        document.getElementById('occurrenceNote').classList.add('task-hidden');
        form.querySelector('.task-repeat-section').classList.remove('task-hidden');
        updateRepeat();
        openModal(document.getElementById('taskFormModal'), items.querySelector('[data-field="title"]'));
    }
    function openEdit(task) {
        form.reset();
        items.innerHTML = '';
        addItem(task.title, task.details);
        f.action.value = 'update'; f.id.value = task.task_id;
        f.date.value = task.task_date || today; f.time.value = task.due_time || '';
        f.assignee.value = String(task.assigned_to || me);
        f.frequency.value = task.task_frequency || 'Daily';
        if (task.kpi_ref && !Array.from(f.kpi.options).some(o => o.value === task.kpi_ref)) f.kpi.add(new Option(task.kpi_label || 'Linked KPI', task.kpi_ref));
        f.kpi.value = task.kpi_ref || '';
        f.status.value = task.status || 'Pending';
        f.repeat.value = task.is_recurring ? task.repeat : 'none';
        dayBoxes.forEach(box => box.checked = (task.recurrence_days || []).includes(Number(box.value)));
        f.end.value = task.recurrence_end_date || '';
        f.reminderAt.value = task.reminder_at || '';
        f.reminderTime.value = task.reminder_time || '';
        document.getElementById('taskModalTitle').textContent = task.is_recurring ? 'Edit repeating task' : 'Edit task';
        document.getElementById('addTaskItem').hidden = true;
        document.getElementById('sharedNote').hidden = true;
        document.getElementById('occurrenceNote').classList.toggle('task-hidden', !task.is_occurrence);
        form.querySelector('.task-repeat-section').classList.toggle('task-hidden', !!task.is_occurrence);
        updateRepeat();
        openModal(document.getElementById('taskFormModal'), items.querySelector('[data-field="title"]'));
    }

    document.getElementById('addTaskButton').addEventListener('click', openNew);
    document.getElementById('importTasksButton').addEventListener('click', () => openModal(document.getElementById('taskImportModal'), document.getElementById('mondayFile')));
    document.querySelectorAll('.task-edit').forEach(button => button.addEventListener('click', () => {
        try { openEdit(JSON.parse(button.dataset.task || '{}')); }
        catch (e) { window.showNotification?.('Could not open this task for editing', 'danger'); }
    }));
    document.querySelectorAll('.task-confirm').forEach(button => button.addEventListener('click', () => {
        const mode = button.dataset.mode, title = '“' + button.dataset.title + '”';
        const text = {
            move: ['Move to today', 'Move ' + title + ' to today?', 'Move task', 'btn btn-primary'],
            leave: ['Leave task', 'Leave ' + title + '? It will no longer appear in your tasks.', 'Leave task', 'btn btn-danger'],
            delete: ['Delete task', button.dataset.recurring === '1' ? 'Remove ' + title + ' from this day only? The task keeps repeating on other days.' : 'Delete ' + title + '? This cannot be undone.', 'Delete', 'btn btn-danger'],
            'delete-rule': ['Stop repeating', 'Stop repeating ' + title + '? Unfinished copies from today on are removed; past days stay in your history.', 'Stop repeating', 'btn btn-danger'],
        }[mode];
        document.getElementById('confirmTaskId').value = button.dataset.id;
        document.getElementById('confirmTaskAction').value = mode === 'delete-rule' ? 'delete' : mode;
        document.getElementById('taskConfirmTitle').textContent = text[0];
        document.getElementById('taskConfirmMessage').textContent = text[1];
        const submit = document.getElementById('confirmTaskSubmit');
        submit.textContent = text[2]; submit.className = text[3];
        openModal(document.getElementById('taskConfirmModal'), submit);
    }));
    document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => closeModal(button.closest('.task-modal'))));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.task-modal:not([hidden])').forEach(closeModal);
    });
    // Move a task to another day.
    const moveModal = document.getElementById('taskMoveModal');
    const moveDate = document.getElementById('moveDate');
    const isoDay = date => date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    document.querySelectorAll('.task-move').forEach(button => button.addEventListener('click', () => {
        const task = JSON.parse(button.dataset.move || '{}');
        document.getElementById('moveTaskId').value = task.task_id;
        document.getElementById('moveTaskName').textContent = task.title;
        moveDate.value = task.date || today;
        document.getElementById('moveTime').value = task.time || '';
        document.getElementById('moveRepeatNote').classList.toggle('task-hidden', !task.repeating);
        openModal(moveModal, moveDate);
    }));
    moveModal.querySelectorAll('[data-move-to]').forEach(button => button.addEventListener('click', () => {
        const date = new Date(today + 'T12:00:00');
        const to = button.dataset.moveTo;
        if (to === 'monday') date.setDate(date.getDate() + ((8 - date.getDay()) % 7 || 7));
        else date.setDate(date.getDate() + Number(to));
        moveDate.value = isoDay(date);
    }));

    // Assign and invite staff.
    const shareModal = document.getElementById('taskShareModal');
    const shareCurrent = document.getElementById('shareCurrent');
    const shareSearch = document.getElementById('shareSearch');
    const candidates = Array.from(document.querySelectorAll('#shareCandidates [data-user-id]'));
    let shareTask = null;
    function refreshCandidates() {
        if (!shareTask) return;
        const assignee = document.getElementById('shareAssignee').value;
        const invited = (shareTask.people || []).map(person => String(person.user_id));
        const term = shareSearch ? shareSearch.value.trim().toLowerCase() : '';
        candidates.forEach(label => {
            const id = label.dataset.userId;
            const hide = id === assignee || invited.includes(id) || (term !== '' && !label.dataset.name.includes(term));
            label.hidden = hide;
            if (id === assignee || invited.includes(id)) label.querySelector('input').checked = false;
        });
    }
    document.querySelectorAll('.task-share').forEach(button => button.addEventListener('click', () => {
        shareTask = JSON.parse(button.dataset.share || '{}');
        document.getElementById('shareTaskId').value = shareTask.task_id;
        document.getElementById('shareTaskName').textContent = shareTask.title;
        document.getElementById('shareAssignee').value = String(shareTask.assigned_to);
        document.getElementById('shareSeriesOption').classList.toggle('task-hidden', !shareTask.is_occurrence);
        if (shareCurrent) {
            shareCurrent.innerHTML = '';
            const people = shareTask.people || [];
            if (!people.length) {
                const none = document.createElement('p');
                none.style.margin = '0';
                none.style.color = 'var(--ink-400)';
                none.textContent = 'Nobody yet.';
                shareCurrent.appendChild(none);
            }
            people.forEach(person => {
                const row = document.createElement('label');
                row.style.cssText = 'display:flex;gap:8px;align-items:center;padding:3px 0';
                const box = document.createElement('input');
                box.type = 'checkbox'; box.name = 'remove[]'; box.value = person.user_id;
                const text = document.createElement('span');
                text.textContent = 'Remove ' + person.full_name + (person.via_series && !shareTask.is_rule ? ' (from every day)' : '');
                row.append(box, text);
                shareCurrent.appendChild(row);
            });
        }
        candidates.forEach(label => { label.querySelector('input').checked = false; });
        if (shareSearch) shareSearch.value = '';
        refreshCandidates();
        openModal(shareModal, document.getElementById('shareAssignee'));
    }));
    document.getElementById('shareAssignee').addEventListener('change', refreshCandidates);
    if (shareSearch) shareSearch.addEventListener('input', refreshCandidates);

    form.addEventListener('submit', event => {
        if (f.repeat.value === 'custom' && !dayBoxes.some(box => box.checked)) {
            event.preventDefault();
            window.showNotification?.('Choose at least one day to repeat on.', 'warning');
        }
    });
})();
</script>
<?php if ($notice): ?><script>window.addEventListener('DOMContentLoaded', () => window.showNotification(<?= json_encode($notice) ?>, <?= json_encode($noticeType) ?>));</script><?php endif; ?>
<?php include 'includes/footer.php'; ?>
