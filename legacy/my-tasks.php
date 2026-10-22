<?php
declare(strict_types=1);

$page_title = 'My Tasks';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/task-reminders.php';
require_once __DIR__ . '/includes/task-importer.php';
check_role(IMS_STAFF_ROLES);

$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ims_allowed('tasks.view_all', ['Administrator'], "See everyone's tasks (not just their own)");
$canManageAllTasks = ims_allowed('tasks.manage_all', ['Administrator'], "Edit or delete anyone's tasks");
$today = date('Y-m-d');
$view = in_array($_GET['view'] ?? 'daily', ['daily','weekly','past','all'], true) ? (string)($_GET['view'] ?? 'daily') : 'daily';
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
$schemaRepairError = '';

// Apply the additive task schema to the exact database connected by this page.
// This recovery action is administrator-only and protected by CSRF validation.
if (!$schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repair_task_schema' && ims_allowed('tasks.repair_schema', ['Administrator'], 'Apply the task database update')) {
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

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'import_monday') {
        try {
            $importSummary = task_import_monday_spreadsheet($conn, $_FILES['monday_file'] ?? [], $uid);
            $message = 'Imported ' . $importSummary['created'] . ' task(s); skipped ' . $importSummary['skipped'] . ' row(s).';
            if ($importSummary['errors']) $message .= ' ' . implode(' ', $importSummary['errors']);
            $_SESSION['notification'] = ['message' => $message, 'type' => $importSummary['skipped'] > 0 ? 'warning' : 'success'];
        } catch (Throwable $exception) {
            error_log('[task-import] ' . $exception->getMessage());
            $_SESSION['notification'] = ['message' => $exception->getMessage(), 'type' => 'danger'];
        }
        header('Location: my-tasks?view=' . urlencode($view));
        exit;
    }
    $taskId = (int)($_POST['task_id'] ?? 0);
    $returnView = in_array($_POST['return_view'] ?? 'daily', ['daily','weekly','past','all'], true) ? (string)$_POST['return_view'] : 'daily';
    $scopeTask = null;
    if ($taskId > 0) {
        $find = $conn->prepare('SELECT task_id,assigned_to,created_by,is_recurring,recurrence_parent_id,status,task_date,task_due_at FROM employee_tasks WHERE task_id=? LIMIT 1');
        $find->bind_param('i', $taskId);
        $find->execute();
        $scopeTask = $find->get_result()->fetch_assoc() ?: null;
        $find->close();
    }
    $mayManage = !$scopeTask || $canManageAllTasks || (int)$scopeTask['assigned_to'] === $uid || (int)$scopeTask['created_by'] === $uid;

    if ($action === 'delete' && $scopeTask && $mayManage) {
        if ((int)$scopeTask['is_recurring'] === 1) {
            $deleteChildren = $conn->prepare('DELETE FROM employee_tasks WHERE recurrence_parent_id=?');
            $deleteChildren->bind_param('i', $taskId); $deleteChildren->execute(); $deleteChildren->close();
        } elseif (!empty($scopeTask['recurrence_parent_id'])) {
            // Preserve the unique series/date marker so the rule does not
            // regenerate a deleted occurrence on the next visit or cron run.
            $delete = $conn->prepare('UPDATE employee_tasks SET status="Cancelled",reminder_sent_at=NOW() WHERE task_id=?');
            $delete->bind_param('i', $taskId); $delete->execute(); $delete->close();
            header('Location: my-tasks?view=' . urlencode($returnView)); exit;
        }
        $delete = $conn->prepare('DELETE FROM employee_tasks WHERE task_id=?');
        $delete->bind_param('i', $taskId); $delete->execute(); $delete->close();
        header('Location: my-tasks?view=' . urlencode($returnView)); exit;
    }

    if ($action === 'move' && $scopeTask && $mayManage && $scopeTask['status'] === 'Pending' && $scopeTask['task_date'] < $today) {
        if (!empty($scopeTask['recurrence_parent_id'])) {
            $parentId = (int)$scopeTask['recurrence_parent_id'];
            $todayCheck = $conn->prepare('SELECT task_id FROM employee_tasks WHERE recurrence_parent_id=? AND task_date=? AND status IN ("Pending","In Progress") LIMIT 1');
            $todayCheck->bind_param('is', $parentId, $today); $todayCheck->execute();
            $alreadyScheduledToday = (bool)$todayCheck->get_result()->fetch_assoc(); $todayCheck->close();
            if ($alreadyScheduledToday) {
                $move = $conn->prepare('UPDATE employee_tasks SET status="Cancelled",reminder_sent_at=NOW() WHERE task_id=?');
                $move->bind_param('i', $taskId);
            } else {
                $move = $conn->prepare('UPDATE employee_tasks SET moved_from=task_date,task_date=?,task_due_at=IF(task_due_at IS NULL,NULL,CONCAT(?," ",TIME(task_due_at))),recurrence_parent_id=NULL,reminder_at=NULL,reminder_sent_at=NULL WHERE task_id=? AND status="Pending"');
                $move->bind_param('ssi', $today, $today, $taskId);
            }
        } else {
            $move = $conn->prepare('UPDATE employee_tasks SET moved_from=task_date,task_date=?,task_due_at=IF(task_due_at IS NULL,NULL,CONCAT(?," ",TIME(task_due_at))),reminder_at=NULL,reminder_sent_at=NULL WHERE task_id=? AND status="Pending"');
            $move->bind_param('ssi', $today, $today, $taskId);
        }
        $move->execute(); $move->close();
        header('Location: my-tasks?view=' . urlencode($returnView)); exit;
    }

    if ($action === 'status' && $scopeTask && $mayManage) {
        $status = (string)($_POST['status'] ?? 'Pending');
        if (in_array($status, ['Pending','In Progress','Completed'], true)) {
            $updateStatus = $conn->prepare('UPDATE employee_tasks SET status=?,completed_at=IF(?="Completed",NOW(),NULL),reminder_sent_at=IF(? IN ("Pending","In Progress"),NULL,reminder_sent_at) WHERE task_id=?');
            $updateStatus->bind_param('sssi', $status, $status, $status, $taskId); $updateStatus->execute(); $updateStatus->close();
        }
        header('Location: my-tasks?view=' . urlencode($returnView)); exit;
    }

    if (in_array($action, ['create','update'], true) && ($action === 'create' || ($scopeTask && $mayManage))) {
        $title = trim((string)($_POST['title'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $frequency = in_array($_POST['frequency'] ?? '', ['Daily','Weekly'], true) ? (string)$_POST['frequency'] : 'Daily';
        $taskDueValue = trim((string)($_POST['task_due_at'] ?? ''));
        $taskDueDateTime = $taskDueValue !== '' ? DateTime::createFromFormat('!Y-m-d\TH:i', $taskDueValue) : false;
        $taskDueAt = $taskDueDateTime ? $taskDueDateTime->format('Y-m-d H:i:s') : null;
        $taskDate = $taskDueDateTime ? $taskDueDateTime->format('Y-m-d') : '';
        $assignedTo = (int)($_POST['assigned_to'] ?? $uid);
        $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['recurrence_days'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        sort($days);
        $recurrenceDays = $isRecurring ? implode(',', $days) : null;
        $endDate = trim((string)($_POST['recurrence_end_date'] ?? '')) ?: null;
        $reminderValue = trim((string)($_POST['reminder_at'] ?? ''));
        $reminderTimeValue = trim((string)($_POST['reminder_time'] ?? ''));
        $reminderAt = null; $reminderTime = null;
        if ($isRecurring && $reminderTimeValue !== '') {
            $reminderClock = DateTime::createFromFormat('!H:i', $reminderTimeValue);
            if ($reminderClock) $reminderTime = $reminderClock->format('H:i:s');
        } elseif ($reminderValue !== '') {
            $reminderValue = str_replace('T', ' ', $reminderValue);
            $reminderDate = DateTime::createFromFormat('!Y-m-d H:i', $reminderValue);
            if ($reminderDate) {
                $reminderAt = $reminderDate->format('Y-m-d H:i:s');
            }
        }
        $kpiRef = trim((string)($_POST['kpi_ref'] ?? ''));
        $kpiSource = null; $kpiId = null; $kraId = null;
        if ($kpiRef !== '' && preg_match('/^(employee|appraisal):(\d+)(?::(\d+))?$/', $kpiRef, $matches)) {
            $kpiSource = $matches[1]; $kpiId = (int)$matches[2]; $kraId = isset($matches[3]) ? (int)$matches[3] : null;
        }
        $validDate = $taskDueDateTime !== false && $taskDueDateTime->format('Y-m-d\TH:i') === $taskDueValue;
        $validEnd = !$endDate || (DateTime::createFromFormat('!Y-m-d', $endDate) !== false && date('Y-m-d', strtotime($endDate)) === $endDate && $endDate >= $taskDate);
        $assigneeStmt = $conn->prepare('SELECT user_id FROM users WHERE user_id=? AND is_active=1 AND role NOT IN ("Member","Applicant","Donor/Partner") LIMIT 1');
        $assigneeStmt->bind_param('i', $assignedTo); $assigneeStmt->execute();
        $assigneeExists = (bool)$assigneeStmt->get_result()->fetch_assoc(); $assigneeStmt->close();
        $kpiValid = ($kpiRef === '' || $kpiSource !== null);
        if ($kpiSource === 'employee') {
            $check = $conn->prepare('SELECT kpi_id FROM kpis WHERE kpi_id=? AND user_id=?');
            $check->bind_param('ii', $kpiId, $assignedTo); $check->execute(); $kpiValid = (bool)$check->get_result()->fetch_assoc(); $check->close();
        } elseif ($kpiSource === 'appraisal') {
            $check = $conn->prepare('SELECT ak.kpi_id FROM appraisal_kpis ak JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE ak.kpi_id=? AND ak.kra_id=? AND pa.user_id=?');
            $check->bind_param('iii', $kpiId, $kraId, $assignedTo); $check->execute(); $kpiValid = (bool)$check->get_result()->fetch_assoc(); $check->close();
        }
        $status = in_array($_POST['status'] ?? '', ['Pending','In Progress','Completed'], true) ? (string)$_POST['status'] : 'Pending';
        $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        if ($title === '' || $titleLength > 240 || !$validDate || !$validEnd || !$assigneeExists || !$kpiValid || ($isRecurring && !$days) || ($isRecurring && $reminderTimeValue !== '' && !$reminderTime) || (!$isRecurring && $reminderValue !== '' && !$reminderAt)) {
            $notice = 'Please check the task details, assignee, repeat days, reminder time, and linked KPI/KRA.';
        } elseif ($action === 'create') {
            $insert = $conn->prepare('INSERT INTO employee_tasks (title,details,assigned_to,created_by,kpi_source,kpi_id,kra_id,task_frequency,task_date,task_due_at,status,is_recurring,recurrence_days,recurrence_end_date,reminder_at,reminder_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->bind_param('ssiisiissssissss', $title, $details, $assignedTo, $uid, $kpiSource, $kpiId, $kraId, $frequency, $taskDate, $taskDueAt, $status, $isRecurring, $recurrenceDays, $endDate, $reminderAt, $reminderTime);
            $ok = $insert->execute(); $newId = (int)$conn->insert_id; $insert->close();
            if ($ok) {
                if ($assignedTo !== $uid) notify_user($assignedTo, 'A task was assigned to you', $title . ' · Due ' . $taskDate, 'info', $newId, 'employee_task');
                task_generate_recurring_occurrences($conn, $today);
                header('Location: my-tasks?view=' . urlencode($returnView)); exit;
            }
            $notice = 'The task could not be saved. Please try again.';
        } else {
            $taskIsRule = (int)$scopeTask['is_recurring'] === 1;
            if ($taskIsRule) {
                $clearFuture = $conn->prepare('DELETE FROM employee_tasks WHERE recurrence_parent_id=? AND task_date>=CURDATE() AND status="Pending"');
                $clearFuture->bind_param('i', $taskId); $clearFuture->execute(); $clearFuture->close();
            }
            $update = $conn->prepare('UPDATE employee_tasks SET title=?,details=?,assigned_to=?,kpi_source=?,kpi_id=?,kra_id=?,task_frequency=?,task_date=?,task_due_at=?,status=?,is_recurring=?,recurrence_days=?,recurrence_end_date=?,reminder_at=?,reminder_time=?,recurrence_parent_id=IF(?=1,NULL,recurrence_parent_id),reminder_sent_at=NULL,completed_at=IF(?="Completed",NOW(),NULL) WHERE task_id=?');
            $update->bind_param('ssisiissssissssisi', $title, $details, $assignedTo, $kpiSource, $kpiId, $kraId, $frequency, $taskDate, $taskDueAt, $status, $isRecurring, $recurrenceDays, $endDate, $reminderAt, $reminderTime, $isRecurring, $status, $taskId);
            $ok = $update->execute(); $update->close();
            if ($ok) {
                if ($assignedTo !== (int)$scopeTask['assigned_to']) notify_user($assignedTo, 'A task was assigned to you', $title . ' · Due ' . $taskDate, 'info', $taskId, 'employee_task');
                task_generate_recurring_occurrences($conn, $today);
                header('Location: my-tasks?view=' . urlencode($returnView)); exit;
            }
            $notice = 'The task could not be updated. Please try again.';
        }
    } elseif ($action !== '') {
        $notice = 'You do not have permission to make that task change.';
    }
}

$selectedUser = $isAdmin ? (int)($_GET['user'] ?? 0) : $uid;
$where = $isAdmin ? 't.is_recurring=0 AND t.status<>"Cancelled"' : 't.is_recurring=0 AND t.status<>"Cancelled" AND t.assigned_to=' . $uid;
if ($isAdmin && $selectedUser > 0) $where .= ' AND t.assigned_to=' . $selectedUser;
if ($view === 'daily') $where .= " AND t.task_date='" . $conn->real_escape_string($today) . "'";
elseif ($view === 'weekly') { $monday = date('Y-m-d', strtotime('monday this week')); $sunday = date('Y-m-d', strtotime('sunday this week')); $where .= " AND t.task_date BETWEEN '$monday' AND '$sunday'"; }
elseif ($view === 'past') $where .= " AND t.task_date<'$today'";

$tasks = [];
$sql = "SELECT t.*, assignee.full_name assignee_name, creator.full_name creator_name,
        CASE WHEN t.kpi_source='employee' THEN ek.kpi_title ELSE ak.title END AS kpi_title,
        CASE WHEN t.kpi_source='appraisal' THEN kr.title ELSE NULL END AS kra_title
        FROM employee_tasks t JOIN users assignee ON assignee.user_id=t.assigned_to
        JOIN users creator ON creator.user_id=t.created_by
        LEFT JOIN kpis ek ON t.kpi_source='employee' AND ek.kpi_id=t.kpi_id
        LEFT JOIN appraisal_kpis ak ON t.kpi_source='appraisal' AND ak.kpi_id=t.kpi_id
        LEFT JOIN appraisal_kras kr ON kr.kra_id=t.kra_id WHERE $where ORDER BY t.task_date DESC, t.status='Completed', t.created_at DESC";
$res = $conn->query($sql); if ($res) while ($row = $res->fetch_assoc()) $tasks[] = $row;
$rules = [];
$ruleWhere = $isAdmin ? 'is_recurring=1' : '(is_recurring=1 AND (assigned_to=' . $uid . ' OR created_by=' . $uid . '))';
$res = $conn->query("SELECT t.*,u.full_name assignee_name,
    CASE WHEN t.kpi_source='employee' THEN ek.kpi_title ELSE ak.title END kpi_title,
    CASE WHEN t.kpi_source='appraisal' THEN kr.title ELSE NULL END kra_title
    FROM employee_tasks t JOIN users u ON u.user_id=t.assigned_to
    LEFT JOIN kpis ek ON t.kpi_source='employee' AND ek.kpi_id=t.kpi_id
    LEFT JOIN appraisal_kpis ak ON t.kpi_source='appraisal' AND ak.kpi_id=t.kpi_id
    LEFT JOIN appraisal_kras kr ON kr.kra_id=t.kra_id
    WHERE $ruleWhere ORDER BY t.task_date DESC");
if ($res) while ($row = $res->fetch_assoc()) $rules[] = $row;

$kpis = [];
$sql = 'SELECT CONCAT("employee:",k.kpi_id) ref,k.kpi_title title,NULL kra_title,u.full_name owner_name FROM kpis k JOIN users u ON u.user_id=k.user_id WHERE 1=1';
if (!$isAdmin) $sql .= ' AND k.user_id=' . $uid;
$res = $conn->query($sql); if ($res) while ($row = $res->fetch_assoc()) $kpis[] = $row;
$appKpis = $conn->query('SELECT CONCAT("appraisal:",ak.kpi_id,":",ak.kra_id) ref,ak.title,kr.title kra_title,pa.full_name owner_name FROM appraisal_kpis ak JOIN appraisal_kras kr ON kr.kra_id=ak.kra_id JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE pa.status IN ("draft","submitted","pending_review","under_review","reviewed","completed")' . ($isAdmin ? '' : ' AND pa.user_id=' . $uid));
if ($appKpis) while ($row = $appKpis->fetch_assoc()) $kpis[] = $row;
$users = [];
$res = $conn->query("SELECT user_id,full_name FROM users WHERE is_active=1 AND role NOT IN ('Member','Applicant','Donor/Partner') ORDER BY full_name"); if ($res) while ($row = $res->fetch_assoc()) $users[] = $row;
$counts = ['Pending'=>0,'In Progress'=>0,'Completed'=>0];
$countWhere = $isAdmin ? '1=1' : 'assigned_to=' . $uid;
$res = $conn->query("SELECT status,COUNT(*) total FROM employee_tasks WHERE is_recurring=0 AND $countWhere AND task_date='$today' GROUP BY status");
if ($res) while ($row = $res->fetch_assoc()) $counts[$row['status']] = (int)$row['total'];

include 'includes/header.php';
if (!$schemaReady): ?>
    <div class="card"><div class="card-body"><div class="alert alert-warning">The connected database is missing task recurrence/reminder schema.<?php if ($schemaRepairError): ?><br><strong>Update error:</strong> <?= $escape($schemaRepairError) ?><?php endif; ?><?php if (ims_allowed('tasks.repair_schema', ['Administrator'], 'Apply the task database update')): ?><form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="repair_task_schema"><button type="submit" class="btn btn-primary"><i class="fas fa-database"></i> Apply task database update</button></form><?php else: ?><br>Ask an Administrator to apply the task database update from this page.<?php endif; ?></div></div></div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<style>
.tasks-page,.task-modal{--task-brand:var(--brand-500,#f97316);--task-brand-dark:var(--brand-700,#c2410c);--task-brand-soft:var(--brand-50,#fff7ed);--task-border:var(--line-200,#e5e7eb);--task-ink:var(--ink-900,#1f2937)}
.tasks-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:24px 28px;margin-bottom:18px;border-radius:var(--radius-xl,14px);background:linear-gradient(120deg,var(--task-brand-dark),var(--task-brand));color:var(--ink-0,#fff)}.tasks-hero h1{margin:0 0 6px;font-size:24px}.tasks-hero p{margin:0;color:rgba(255,255,255,.9)}.task-hero-actions{display:flex;gap:8px;flex-wrap:wrap}
.task-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.task-stat{padding:18px;border-radius:12px;background:var(--ink-0,#fff);border:1px solid var(--task-border);box-shadow:var(--shadow-sm,0 2px 8px #0f172a0a)}.task-stat strong{display:block;font-size:25px;color:var(--task-brand-dark)}.task-stat span{font-size:12px;color:var(--ink-500,#64748b)}
.task-tabs{display:flex;gap:4px;overflow:auto;border-bottom:1px solid var(--task-border);margin:10px 0 16px}.task-tabs a{padding:11px 15px;text-decoration:none;color:var(--ink-500,#64748b);font-weight:700;white-space:nowrap;border-bottom:3px solid transparent}.task-tabs a.active{color:var(--task-brand-dark);border-color:var(--task-brand)}
.task-row{padding:15px 0;border-bottom:1px solid var(--task-border);display:flex;justify-content:space-between;gap:18px;align-items:center}.task-row:last-child{border:0}.task-title{font-weight:800;color:var(--task-ink)}.task-meta{font-size:12px;color:var(--ink-500,#64748b);margin-top:5px;line-height:1.65}.task-controls{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.task-status{border-radius:999px;background:#f3f4f6;padding:5px 9px;font-size:11px;font-weight:800;color:#4b5563}.task-status.completed{background:#ecfdf3;color:#166534}.task-status.in-progress{background:#eff6ff;color:#1d4ed8}.task-actions{display:flex;gap:6px}
.task-modal[hidden]{display:none}.task-modal{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:18px}.task-modal-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.56)}.task-modal-card{position:relative;display:flex;flex-direction:column;width:min(760px,100%);max-height:calc(100vh - 36px);overflow:hidden;background:var(--ink-0,#fff);border:1px solid var(--task-border);border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.25)}.task-modal-card form{display:flex;flex:1;min-height:0;flex-direction:column;overflow:hidden}.task-modal-head,.task-modal-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;flex-shrink:0}.task-modal-head{border-bottom:1px solid var(--task-border)}.task-modal-head h3{margin:0;color:var(--task-ink)}.task-modal-body{padding:20px;overflow-y:auto;flex:1;min-height:0}.task-modal-foot{position:sticky;bottom:0;justify-content:flex-end;border-top:1px solid var(--task-border);background:var(--ink-0,#fff);z-index:2;box-shadow:0 -5px 12px rgba(17,24,39,.04)}.task-close{border:0;background:transparent;color:#6b7280;font-size:25px;cursor:pointer}.task-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.task-form-grid .wide{grid-column:1/-1}.task-repeat-days{display:flex;gap:8px;flex-wrap:wrap}.task-repeat-days label{display:inline-flex;gap:6px;align-items:center;padding:7px 10px;border:1px solid var(--task-border);border-radius:8px;background:#fff}.task-hidden{display:none!important}.task-rule-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--task-border)}.task-rule-row:last-child{border:0}.task-brand-btn{background:var(--task-brand);border-color:var(--task-brand);color:#fff}.task-brand-btn:hover{background:var(--task-brand-dark);border-color:var(--task-brand-dark)}.task-icon-btn{border:1px solid var(--task-border);border-radius:8px;background:#fff;color:var(--task-brand-dark);padding:7px 9px;cursor:pointer}.task-icon-btn:hover{background:var(--task-brand-soft)}
@media(max-width:680px){.task-stats{grid-template-columns:1fr}.task-row{align-items:flex-start;flex-direction:column}.task-form-grid{grid-template-columns:1fr}.task-form-grid .wide{grid-column:auto}.tasks-hero{align-items:flex-start;flex-direction:column}.task-modal{padding:8px}.task-modal-card{max-height:calc(100vh - 16px)}.task-modal-body{padding:14px}}
</style>

<div class="tasks-page">
<section class="tasks-hero"><div><h1><i class="fas fa-list-check"></i> <?= $isAdmin && $view === 'all' ? 'Team Tasks' : 'My Tasks' ?></h1><p>Daily actions and weekly priorities linked to KPI and KRA commitments.</p></div><div class="task-hero-actions"><button type="button" class="btn btn-secondary" id="importTasksButton"><i class="fas fa-file-excel"></i> Import Monday.com Excel</button><button type="button" class="btn task-brand-btn" id="addTaskButton"><i class="fas fa-plus"></i> Add task</button></div></section>
<div class="task-stats"><div class="task-stat"><strong><?= $counts['Pending'] ?></strong><span>Pending today</span></div><div class="task-stat"><strong><?= $counts['In Progress'] ?></strong><span>In progress today</span></div><div class="task-stat"><strong><?= $counts['Completed'] ?></strong><span>Completed today</span></div></div>
<nav class="task-tabs" aria-label="Task periods"><?php foreach (['daily'=>'Today','weekly'=>'This Week','past'=>'Past Tasks'] as $key=>$label): ?><a class="<?= $view===$key?'active':'' ?>" href="my-tasks?view=<?= $key ?>"><?= $label ?></a><?php endforeach; ?><?php if ($isAdmin): ?><a class="<?= $view==='all'?'active':'' ?>" href="my-tasks?view=all">Everyone</a><?php endif; ?></nav>
<?php if ($isAdmin && $view==='all'): ?><form method="get" class="card card-body" style="margin-bottom:16px"><input type="hidden" name="view" value="all"><label for="taskUser">Filter by staff member</label><select id="taskUser" name="user" class="form-control" onchange="this.form.submit()"><option value="0">Everyone</option><?php foreach($users as $user): ?><option value="<?= (int)$user['user_id'] ?>" <?= $selectedUser===(int)$user['user_id']?'selected':'' ?>><?= $escape($user['full_name']) ?></option><?php endforeach; ?></select></form><?php endif; ?>

<div class="card"><div class="card-header"><h3><i class="fas fa-calendar-check" style="color:var(--brand-500)"></i> <?= ['daily'=>'Today','weekly'=>'This Week','past'=>'Past Tasks','all'=>'Everyone’s Tasks'][$view] ?></h3><span><?= count($tasks) ?> task<?= count($tasks)===1?'':'s' ?></span></div><div class="card-body">
<?php if (!$tasks): ?><div class="text-center" style="padding:28px;color:#64748b">No tasks in this period.</div><?php else: foreach($tasks as $task): $taskJson = htmlspecialchars(json_encode($task, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '{}', ENT_QUOTES, 'UTF-8'); ?>
<article class="task-row"><div><div class="task-title"><?= $escape($task['title']) ?><?php if (!empty($task['recurrence_parent_id'])): ?> <span class="badge" style="background:var(--task-brand-soft);color:var(--task-brand-dark)"><i class="fas fa-repeat"></i> Recurring</span><?php endif; ?></div><div class="task-meta"><?= $escape($task['task_date']) ?> · <?= $escape($task['task_frequency']) ?><?php if ($isAdmin): ?> · Assigned to <?= $escape($task['assignee_name']) ?><?php endif; ?><?php if ($task['kpi_title']): ?> · KPI: <?= $escape($task['kpi_title']) ?><?php endif; ?><?php if ($task['kra_title']): ?> · KRA: <?= $escape($task['kra_title']) ?><?php endif; ?><?php if ($task['reminder_at']): ?> · <i class="fas fa-bell"></i> Reminder <?= $escape(date('d M, H:i', strtotime($task['reminder_at']))) ?><?php endif; ?><?php if ($task['details']): ?><br><?= $escape($task['details']) ?><?php endif; ?></div></div><div class="task-controls"><span class="task-status <?= strtolower(str_replace(' ','-',$task['status'])) ?>"><?= $escape($task['status']) ?></span><?php if ($canManageAllTasks || (int)$task['assigned_to']===$uid || (int)$task['created_by']===$uid): ?><div class="task-actions"><button type="button" class="task-icon-btn task-edit" title="Edit task" aria-label="Edit task" data-task="<?= $taskJson ?>"><i class="fas fa-pen"></i></button><?php if ($task['status']==='Pending' && $task['task_date']<$today): ?><button type="button" class="task-icon-btn task-confirm" data-mode="move" data-id="<?= (int)$task['task_id'] ?>" data-title="<?= $escape($task['title']) ?>" title="Move pending task to today" aria-label="Move pending task to today"><i class="fas fa-calendar-day"></i></button><?php endif; ?><button type="button" class="task-icon-btn task-confirm" data-mode="delete" data-id="<?= (int)$task['task_id'] ?>" data-title="<?= $escape($task['title']) ?>" title="Delete task" aria-label="Delete task"><i class="fas fa-trash"></i></button></div><form method="post" class="task-status-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="task_id" value="<?= (int)$task['task_id'] ?>"><input type="hidden" name="return_view" value="<?= $escape($view) ?>"><select class="form-control" name="status" aria-label="Update task status" onchange="this.form.submit()"><?php foreach(['Pending','In Progress','Completed'] as $status): ?><option <?= $task['status']===$status?'selected':'' ?>><?= $status ?></option><?php endforeach; ?></select></form><?php endif; ?></div></article>
<?php endforeach; endif; ?></div></div>

<?php if ($rules): ?><div class="card" style="margin-top:18px"><div class="card-header"><h3><i class="fas fa-repeat" style="color:var(--brand-500)"></i> Recurring task rules</h3><span><?= count($rules) ?></span></div><div class="card-body"><?php foreach($rules as $rule):
    $ruleJson = htmlspecialchars(json_encode($rule, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}', ENT_QUOTES, 'UTF-8');
    $weekdayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $ruleDays = array_filter(explode(',', (string)$rule['recurrence_days']));
    $ruleDaysLabel = implode(', ', array_map(static fn($day): string => $weekdayNames[(int)$day] ?? '', $ruleDays));
?>
<div class="task-rule-row"><div><strong><?= $escape($rule['title']) ?></strong><div class="task-meta"><?= $isAdmin ? 'Assigned to ' . $escape($rule['assignee_name']) . ' · ' : '' ?><?= $escape($ruleDaysLabel) ?><?php if($rule['recurrence_end_date']): ?> · Ends <?= $escape($rule['recurrence_end_date']) ?><?php endif; ?><?php if($rule['reminder_time']): ?> · Reminder <?= $escape(date('H:i', strtotime($rule['reminder_time']))) ?><?php endif; ?></div></div><div class="task-actions"><button type="button" class="task-icon-btn task-edit" data-task="<?= $ruleJson ?>" title="Edit recurring rule"><i class="fas fa-pen"></i></button><button type="button" class="task-icon-btn task-confirm" data-mode="delete" data-id="<?= (int)$rule['task_id'] ?>" data-title="<?= $escape($rule['title']) ?>" title="Delete recurring rule and occurrences"><i class="fas fa-trash"></i></button></div></div><?php endforeach; ?></div></div><?php endif; ?>
</div>

<div class="task-modal" id="taskFormModal" hidden><div class="task-modal-backdrop" data-close-task-modal></div><section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskModalTitle"><div class="task-modal-head"><h3 id="taskModalTitle">Add task</h3><button type="button" class="task-close" data-close-task-modal aria-label="Close">&times;</button></div><form method="post" id="taskForm"><div class="task-modal-body"><?= csrf_field() ?><input type="hidden" name="action" id="taskAction" value="create"><input type="hidden" name="task_id" id="taskId"><input type="hidden" name="return_view" value="<?= $escape($view) ?>"><div class="task-form-grid"><div class="form-group wide"><label for="taskTitle" class="required">Task</label><input id="taskTitle" name="title" maxlength="240" class="form-control" required></div><div class="form-group wide"><label for="taskDetails">Details</label><textarea id="taskDetails" name="details" class="form-control" rows="2"></textarea></div><div class="form-group"><label for="taskFrequency">Frequency</label><select id="taskFrequency" name="frequency" class="form-control"><option>Daily</option><option>Weekly</option></select></div><div class="form-group"><label for="taskDate">Start / Due date</label><input id="taskDate" type="date" name="task_date" value="<?= $today ?>" class="form-control" required></div><div class="form-group"><label for="assignedTo">Assign to</label><select id="assignedTo" name="assigned_to" class="form-control" required><?php foreach($users as $user): ?><option value="<?= (int)$user['user_id'] ?>" <?= (int)$user['user_id']===$uid?'selected':'' ?>><?= $escape($user['full_name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="kpiRef">Linked KPI / KRA</label><select id="kpiRef" name="kpi_ref" class="form-control"><option value="">No KPI link</option><?php foreach($kpis as $kpi): ?><option value="<?= $escape($kpi['ref']) ?>"><?= $escape($kpi['title']) ?><?= $kpi['kra_title']?' — KRA: '.$escape($kpi['kra_title']):'' ?> · <?= $escape($kpi['owner_name']) ?></option><?php endforeach; ?></select></div><div class="form-group task-status-field"><label for="taskStatus">Status</label><select id="taskStatus" name="status" class="form-control"><option>Pending</option><option>In Progress</option><option>Completed</option></select></div><div class="form-group task-repeat-toggle"><label><input type="checkbox" id="taskRecurring" name="is_recurring" value="1"> Repeat on selected weekdays</label></div><div class="form-group wide task-repeat-fields task-hidden"><label>Repeat on</label><div class="task-repeat-days"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" name="recurrence_days[]" value="<?= $day ?>"> <?= $label ?></label><?php endforeach; ?></div></div><div class="form-group task-repeat-fields task-hidden"><label for="recurrenceEnd">Repeat until (optional)</label><input type="date" id="recurrenceEnd" name="recurrence_end_date" class="form-control"></div><div class="form-group task-one-reminder"><label for="reminderAt">Reminder</label><input id="reminderAt" type="datetime-local" name="reminder_at" class="form-control"><small>Send an in-app reminder at this time.</small></div><div class="form-group task-repeat-reminder task-hidden"><label for="reminderTime">Reminder time</label><input id="reminderTime" type="time" class="form-control"><small>Remind the assignee on each repeated task day.</small></div></div></div><div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-task-modal>Cancel</button><button type="submit" class="btn task-brand-btn"><i class="fas fa-save"></i> Save task</button></div></form></section></div>

<div class="task-modal" id="taskConfirmModal" hidden><div class="task-modal-backdrop" data-close-confirm-modal></div><section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskConfirmTitle" style="max-width:470px"><div class="task-modal-head"><h3 id="taskConfirmTitle">Confirm action</h3><button type="button" class="task-close" data-close-confirm-modal aria-label="Close">&times;</button></div><form method="post" id="taskConfirmForm"><div class="task-modal-body"><?= csrf_field() ?><input type="hidden" name="task_id" id="confirmTaskId"><input type="hidden" name="action" id="confirmTaskAction"><input type="hidden" name="return_view" value="<?= $escape($view) ?>"><p id="taskConfirmMessage" style="margin:0;color:#4b5563"></p></div><div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-confirm-modal>Cancel</button><button type="submit" class="btn btn-danger" id="confirmTaskSubmit">Confirm</button></div></form></section></div>

<div class="task-modal" id="taskImportModal" hidden><div class="task-modal-backdrop" data-close-import-modal></div><section class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="taskImportTitle" style="max-width:660px"><div class="task-modal-head"><h3 id="taskImportTitle"><i class="fas fa-file-excel" style="color:var(--task-brand)"></i> Import Monday.com tasks</h3><button type="button" class="task-close" data-close-import-modal aria-label="Close">&times;</button></div><form method="post" enctype="multipart/form-data"><div class="task-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="import_monday"><p>Upload a Monday.com board export in <strong>.xlsx, .xls, or .csv</strong> format (up to 15 MB). Tasks are assigned to the person in the sheet; rows without an owner are assigned to you.</p><div class="alert alert-info"><strong>Recognized columns:</strong> Task/Name, Person/Owner, Status, Due Date/Date, Time, Notes/Description, KPI, KRA, Frequency, Repeat On/Weekdays, Repeat Until, Reminder.<br><strong>Example:</strong> “Submit monthly KPI report” · “Alex Staff” · “Working on it” · “2026-10-15 16:00” · “Attach the report”.</div><div class="form-group"><label for="mondayFile" class="required">Monday.com export</label><input id="mondayFile" type="file" name="monday_file" class="form-control" accept=".xlsx,.xls,.csv" required></div><small>Rows with an unrecognized assignee, date/time, weekday rule, or linked KPI/KRA are skipped and reported.</small></div><div class="task-modal-foot"><button type="button" class="btn btn-secondary" data-close-import-modal>Cancel</button><button type="submit" class="btn task-brand-btn"><i class="fas fa-upload"></i> Import tasks</button></div></form></section></div>

<script>
 (function(){
 const formModal=document.getElementById('taskFormModal'),confirmModal=document.getElementById('taskConfirmModal'),importModal=document.getElementById('taskImportModal'),form=document.getElementById('taskForm');
 const dueInput=document.getElementById('taskDate');
 dueInput.type='datetime-local';dueInput.name='task_due_at';dueInput.id='taskDueAt';dueInput.placeholder='e.g. 2026-10-15 16:30';
 const dueLabel=form.querySelector('label[for="taskDate"]');if(dueLabel){dueLabel.htmlFor='taskDueAt';dueLabel.textContent='Due date & time'}
 const dueHint=document.createElement('small');dueHint.textContent='Example: 2026-10-15 16:30 (local time).';dueInput.insertAdjacentElement('afterend',dueHint);
 const submitTaskButton=form.querySelector('.task-modal-foot .task-brand-btn');
 if(submitTaskButton)submitTaskButton.innerHTML='<i class="fas fa-paper-plane"></i> Submit task';
 const fields={id:document.getElementById('taskId'),title:document.getElementById('taskTitle'),details:document.getElementById('taskDetails'),frequency:document.getElementById('taskFrequency'),date:dueInput,assignee:document.getElementById('assignedTo'),kpi:document.getElementById('kpiRef'),status:document.getElementById('taskStatus'),recurring:document.getElementById('taskRecurring'),end:document.getElementById('recurrenceEnd'),reminder:document.getElementById('reminderAt'),reminderTime:document.getElementById('reminderTime')};
 fields.title.placeholder='e.g. Submit the monthly KPI progress report';
 fields.details.placeholder='e.g. Attach the report and note any delivery blockers.';
 const repeatDays=Array.from(form.querySelectorAll('[name="recurrence_days[]"]'));
 function updateRepeat(){const on=fields.recurring.checked;document.querySelectorAll('.task-repeat-fields').forEach(el=>el.classList.toggle('task-hidden',!on));document.querySelector('.task-one-reminder').classList.toggle('task-hidden',on);document.querySelector('.task-repeat-reminder').classList.toggle('task-hidden',!on);fields.reminderTime.name=on?'reminder_time':'';fields.reminder.name=on?'':'reminder_at';fields.end.required=false;repeatDays.forEach(day=>day.required=false)}
 function openNew(){form.reset();fields.id.value='';document.getElementById('taskAction').value='create';document.getElementById('taskModalTitle').textContent='Add task';fields.date.value='<?= $today ?>T09:00';fields.assignee.value='<?= $uid ?>';fields.status.value='Pending';updateRepeat();formModal.hidden=false;document.body.style.overflow='hidden';fields.title.focus()}
 function openEdit(task){form.reset();document.getElementById('taskAction').value='update';fields.id.value=task.task_id||'';fields.title.value=task.title||'';fields.details.value=task.details||'';fields.frequency.value=task.task_frequency||'Daily';fields.date.value=String(task.task_due_at||((task.task_date||'<?= $today ?>')+' 09:00:00')).replace(' ','T').substring(0,16);fields.assignee.value=task.assigned_to||'<?= $uid ?>';fields.status.value=task.status||'Pending';const kpiRef=task.kpi_source?(task.kpi_source+':'+task.kpi_id+(task.kra_id?':'+task.kra_id:'')):'';if(kpiRef&&!Array.from(fields.kpi.options).some(option=>option.value===kpiRef)){fields.kpi.add(new Option((task.kpi_title||'Linked KPI')+(task.kra_title?' — KRA: '+task.kra_title:''),kpiRef))}fields.kpi.value=kpiRef;fields.recurring.checked=String(task.is_recurring)==='1';fields.end.value=task.recurrence_end_date||'';repeatDays.forEach(day=>day.checked=(String(task.recurrence_days||'').split(',').includes(day.value)));if(task.is_recurring){fields.reminderTime.value=task.reminder_time?String(task.reminder_time).substring(0,5):''}else{fields.reminder.value=task.reminder_at?String(task.reminder_at).replace(' ','T').substring(0,16):''}updateRepeat();document.getElementById('taskModalTitle').textContent=String(task.is_recurring)==='1'?'Edit recurring task':'Edit task';formModal.hidden=false;document.body.style.overflow='hidden';fields.title.focus()}
 function close(modal){modal.hidden=true;if(formModal.hidden&&confirmModal.hidden&&importModal.hidden)document.body.style.overflow=''}
 document.getElementById('addTaskButton').addEventListener('click',openNew);
 document.getElementById('importTasksButton').addEventListener('click',()=>{importModal.hidden=false;document.body.style.overflow='hidden';document.getElementById('mondayFile').focus()});
 document.querySelectorAll('.task-edit').forEach(button=>button.addEventListener('click',()=>{try{openEdit(JSON.parse(button.dataset.task||'{}'))}catch(e){window.showNotification?.('Could not load this task for editing','danger')}}));
 fields.recurring.addEventListener('change',updateRepeat);updateRepeat();
 document.querySelectorAll('[data-close-task-modal]').forEach(button=>button.addEventListener('click',()=>close(formModal)));
 document.querySelectorAll('[data-close-confirm-modal]').forEach(button=>button.addEventListener('click',()=>close(confirmModal)));
 document.querySelectorAll('[data-close-import-modal]').forEach(button=>button.addEventListener('click',()=>close(importModal)));
 document.querySelectorAll('.task-row').forEach(row=>{const edit=row.querySelector('.task-edit'),meta=row.querySelector('.task-meta');if(!edit||!meta)return;try{const task=JSON.parse(edit.dataset.task||'{}');if(task.task_due_at){const time=document.createElement('span');time.textContent=' · Due '+String(task.task_due_at).substring(11,16);meta.append(time)}}catch(e){}});
  document.querySelectorAll('.task-confirm').forEach(button=>button.addEventListener('click',()=>{const mode=button.dataset.mode;document.getElementById('confirmTaskId').value=button.dataset.id;document.getElementById('confirmTaskAction').value=mode;document.getElementById('taskConfirmTitle').textContent=mode==='move'?'Move pending task':'Delete task';document.getElementById('taskConfirmMessage').textContent=mode==='move'?'Move “'+button.dataset.title+'” to today?':'Delete “'+button.dataset.title+'”? This cannot be undone.';document.getElementById('confirmTaskSubmit').className=mode==='move'?'btn task-brand-btn':'btn btn-danger';document.getElementById('confirmTaskSubmit').textContent=mode==='move'?'Move task':'Delete task';confirmModal.hidden=false;document.body.style.overflow='hidden'}));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){if(!formModal.hidden)close(formModal);if(!confirmModal.hidden)close(confirmModal);if(!importModal.hidden)close(importModal)}});
})();
</script>
<?php if ($notice): ?><script>window.addEventListener('DOMContentLoaded',()=>window.showNotification(<?= json_encode($notice) ?>,<?= json_encode($noticeType) ?>));</script><?php endif; ?>
<?php include 'includes/footer.php'; ?>
