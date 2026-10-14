<?php
declare(strict_types=1);

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');


function redirect_with_message(string $message, string $type = 'danger', string $location = '../milestones'): void
{
    if (function_exists('send_notification') && isset($_SESSION['user_id'])) {
        send_notification((int)$_SESSION['user_id'], $message, $type);
    } else {
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $type;
    }

    header("Location: {$location}");
    exit();
}

function clean_text(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);

    if ($value === '') {
        return null;
    }

    return function_exists('sanitize_input') ? sanitize_input($value) : $value;
}

function post_string(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function post_nullable_string(string $key): ?string
{
    return isset($_POST[$key]) ? clean_text((string)$_POST[$key]) : null;
}

function post_nullable_int(string $key): ?int
{
    if (!isset($_POST[$key])) {
        return null;
    }

    $value = trim((string)$_POST[$key]);

    return $value === '' ? null : (int)$value;
}

function post_nullable_float(string $key): ?float
{
    if (!isset($_POST[$key])) {
        return null;
    }

    $value = trim((string)$_POST[$key]);

    return $value === '' ? null : (float)$value;
}

function is_valid_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return true;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);

    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}

function json_list_from_post(string $key): string
{
    $values = $_POST[$key] ?? [];

    if (!is_array($values)) {
        $values = [$values];
    }

    $clean = [];

    foreach ($values as $value) {
        $value = clean_text((string)$value);

        if ($value !== null) {
            $clean[] = $value;
        }
    }

    return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/*
|--------------------------------------------------------------------------
| Database Helpers
|--------------------------------------------------------------------------
*/
function project_exists(mysqli $conn, int $projectId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM projects WHERE project_id = ? LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function program_exists(mysqli $conn, int $programId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM programs WHERE id = ? LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function milestone_exists(mysqli $conn, int $milestoneId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM milestones WHERE milestone_id = ? LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $milestoneId);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function get_milestone_name(mysqli $conn, int $milestoneId): string
{
    $stmt = $conn->prepare("SELECT milestone_name FROM milestones WHERE milestone_id = ? LIMIT 1");

    if (!$stmt) {
        return 'Unknown';
    }

    $stmt->bind_param('i', $milestoneId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return (string)($row['milestone_name'] ?? 'Unknown');
}

function resolve_entity(mysqli $conn, ?string $entityType, ?int &$projectId, ?int &$programId, ?int &$entityId): ?string
{
    if ($entityType === 'Project') {
        $programId = null;

        if ($projectId === null || $projectId <= 0) {
            return 'Please select a project.';
        }

        if (!project_exists($conn, $projectId)) {
            return 'Selected project does not exist.';
        }

        $entityId = $projectId;

        return null;
    }

    if ($entityType === 'Program') {
        $projectId = null;

        if ($programId === null || $programId <= 0) {
            return 'Please select a program.';
        }

        if (!program_exists($conn, $programId)) {
            return 'Selected program does not exist.';
        }

        $entityId = $programId;

        return null;
    }

    return 'Please select a valid entity type.';
}

function validate_milestone_dates(?string $startDate, ?string $dueDate, ?string $completionDate = null): ?string
{
    if (!is_valid_date($startDate)) {
        return 'Invalid milestone start date.';
    }

    if (!is_valid_date($dueDate)) {
        return 'Invalid milestone due date.';
    }

    if (!is_valid_date($completionDate)) {
        return 'Invalid milestone completion date.';
    }

    if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
        return 'Start date cannot be after due date.';
    }

    if ($completionDate !== null && $completionDate > date('Y-m-d')) {
        return 'Completion date cannot be in the future.';
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Deliverables Helpers
|--------------------------------------------------------------------------
| Frontend fields expected:
| - deliverables[]
| - deliverable_start_dates[]
| - deliverable_end_dates[]
|
| Optional edit support:
| - deliverable_ids[]
| - deliverable_statuses[]
| - deliverable_progress[]
| - deliverable_notes[]
|--------------------------------------------------------------------------
*/
function collect_deliverables_from_post(
    ?string &$error = null,
    ?string $milestoneStartDate = null,
    ?string $milestoneDueDate = null
): array {
    $ids      = $_POST['deliverable_ids'] ?? [];
    $titles   = $_POST['deliverables'] ?? [];
    $starts   = $_POST['deliverable_start_dates'] ?? [];
    $ends     = $_POST['deliverable_end_dates'] ?? [];
    $statuses = $_POST['deliverable_statuses'] ?? [];
    $progress = $_POST['deliverable_progress'] ?? [];
    $notes    = $_POST['deliverable_notes'] ?? [];

    foreach (['ids', 'titles', 'starts', 'ends', 'statuses', 'progress', 'notes'] as $varName) {
        if (!is_array($$varName)) {
            $$varName = [$$varName];
        }
    }

    $deliverables = [];
    $max = max(count($ids), count($titles), count($starts), count($ends), count($statuses), count($progress), count($notes));

    $allowedStatuses = ['Pending', 'In Progress', 'Completed', 'Delayed', 'Cancelled'];

    for ($i = 0; $i < $max; $i++) {
        $deliverableId = isset($ids[$i]) && trim((string)$ids[$i]) !== '' ? (int)$ids[$i] : null;
        $title         = clean_text((string)($titles[$i] ?? ''));
        $startDate     = clean_text((string)($starts[$i] ?? ''));
        $endDate       = clean_text((string)($ends[$i] ?? ''));
        $status        = clean_text((string)($statuses[$i] ?? 'Pending'));
        $progressValue = isset($progress[$i]) && trim((string)$progress[$i]) !== '' ? (float)$progress[$i] : 0.0;
        $note          = clean_text((string)($notes[$i] ?? ''));

        if ($title === null && $startDate === null && $endDate === null && $deliverableId === null) {
            continue;
        }

        if ($title === null) {
            $error = 'Each deliverable must have a title.';
            return [];
        }

        if (!is_valid_date($startDate)) {
            $error = "Invalid start date for deliverable: {$title}.";
            return [];
        }

        if (!is_valid_date($endDate)) {
            $error = "Invalid end date for deliverable: {$title}.";
            return [];
        }

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            $error = "Deliverable end date cannot be earlier than start date for: {$title}.";
            return [];
        }

        if ($milestoneStartDate !== null && $startDate !== null && $startDate < $milestoneStartDate) {
            $error = "Deliverable start date cannot be before milestone start date for: {$title}.";
            return [];
        }

        if ($milestoneDueDate !== null && $endDate !== null && $endDate > $milestoneDueDate) {
            $error = "Deliverable end date cannot be after milestone due date for: {$title}.";
            return [];
        }

        if ($status === null || !in_array($status, $allowedStatuses, true)) {
            $status = 'Pending';
        }

        if ($progressValue < 0 || $progressValue > 100) {
            $error = "Deliverable progress must be between 0 and 100 for: {$title}.";
            return [];
        }

        $completionDate = null;

        if ($status === 'Completed') {
            $progressValue = 100.0;
            $completionDate = date('Y-m-d');
        }

        $deliverables[] = [
            'deliverable_id'      => $deliverableId,
            'deliverable_title'   => $title,
            'start_date'          => $startDate,
            'end_date'            => $endDate,
            'completion_date'     => $completionDate,
            'status'              => $status,
            'progress_percentage' => $progressValue,
            'notes'               => $note,
        ];
    }

    return $deliverables;
}

function deliverables_json_summary(array $deliverables): string
{
    $summary = [];

    foreach ($deliverables as $item) {
        $summary[] = [
            'title'      => $item['deliverable_title'],
            'start_date' => $item['start_date'],
            'end_date'   => $item['end_date'],
            'status'     => $item['status'],
            'progress'   => $item['progress_percentage'],
        ];
    }

    return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function replace_milestone_deliverables(mysqli $conn, int $milestoneId, array $deliverables, int $userId): void
{
    $deleteStmt = $conn->prepare("DELETE FROM milestone_deliverables WHERE milestone_id = ?");

    if (!$deleteStmt) {
        throw new RuntimeException('Failed to prepare deliverables delete query: ' . $conn->error);
    }

    $deleteStmt->bind_param('i', $milestoneId);

    if (!$deleteStmt->execute()) {
        $error = $deleteStmt->error ?: $conn->error;
        $deleteStmt->close();
        throw new RuntimeException('Failed to delete old deliverables: ' . $error);
    }

    $deleteStmt->close();

    if (empty($deliverables)) {
        return;
    }

    $sql = "
        INSERT INTO milestone_deliverables (
            milestone_id,
            deliverable_title,
            start_date,
            end_date,
            completion_date,
            status,
            progress_percentage,
            notes,
            created_by,
            updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Failed to prepare deliverables insert query: ' . $conn->error);
    }

    foreach ($deliverables as $item) {
        $title          = $item['deliverable_title'];
        $startDate      = $item['start_date'];
        $endDate        = $item['end_date'];
        $completionDate = $item['completion_date'];
        $status         = $item['status'];
        $progress       = (float)$item['progress_percentage'];
        $notes          = $item['notes'];

        $stmt->bind_param(
            'isssssdssi',
            $milestoneId,
            $title,
            $startDate,
            $endDate,
            $completionDate,
            $status,
            $progress,
            $notes,
            $userId,
            $userId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $conn->error;
            $stmt->close();
            throw new RuntimeException('Failed to save deliverable "' . $title . '": ' . $error);
        }
    }

    $stmt->close();
}

function delete_milestone_deliverables(mysqli $conn, int $milestoneId): void
{
    $stmt = $conn->prepare("DELETE FROM milestone_deliverables WHERE milestone_id = ?");

    if (!$stmt) {
        throw new RuntimeException('Failed to prepare deliverables delete query: ' . $conn->error);
    }

    $stmt->bind_param('i', $milestoneId);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new RuntimeException('Failed to delete deliverables: ' . $error);
    }

    $stmt->close();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../milestones');
    exit();
}

/*
|--------------------------------------------------------------------------
| ADD MILESTONE
|--------------------------------------------------------------------------
*/
if (isset($_POST['add_milestone'])) {
    $entityType           = clean_text(post_string('entity_type'));
    $projectId            = post_nullable_int('project_id');
    $programId            = post_nullable_int('program_id');
    $entityId             = null;

    $milestoneName        = clean_text(post_string('milestone_name'));
    $milestoneDescription = post_nullable_string('milestone_description');
    $milestoneType        = clean_text(post_string('milestone_type'));
    $startDate            = post_nullable_string('start_date');
    $dueDate              = clean_text(post_string('due_date'));
    $completionDate       = null;
    $status               = clean_text(post_string('status', 'Not Started'));
    $progressPercentage   = post_nullable_float('progress_percentage') ?? 0.0;
    $responsiblePerson    = post_nullable_string('responsible_person');
    $dependencies         = json_list_from_post('dependencies');
    $budgetAllocation     = post_nullable_float('budget_allocation');
    $actualCost           = post_nullable_float('actual_cost');
    $notes                = post_nullable_string('notes');

    if ($milestoneName === null || $entityType === null || $dueDate === null) {
        redirect_with_message('Milestone name, entity type, and due date are required.');
    }

    $entityError = resolve_entity($conn, $entityType, $projectId, $programId, $entityId);
    if ($entityError !== null) {
        redirect_with_message($entityError);
    }

    $dateError = validate_milestone_dates($startDate, $dueDate);
    if ($dateError !== null) {
        redirect_with_message($dateError);
    }

    if ($progressPercentage < 0 || $progressPercentage > 100) {
        redirect_with_message('Progress percentage must be between 0 and 100.');
    }

    if ($status === 'Completed') {
        $progressPercentage = 100.0;
        $completionDate = date('Y-m-d');
    }

    $deliverableError = null;
    $deliverablesList = collect_deliverables_from_post($deliverableError, $startDate, $dueDate);

    if ($deliverableError !== null) {
        redirect_with_message($deliverableError);
    }

    /*
     * Keep milestones.deliverables populated as a JSON summary for backward
     * compatibility with existing pages, but the real deliverable records
     * now live in milestone_deliverables.
     */
    $deliverablesSummary = deliverables_json_summary($deliverablesList);

    $sql = "
        INSERT INTO milestones (
            project_id,
            program_id,
            entity_type,
            entity_id,
            milestone_name,
            milestone_description,
            milestone_type,
            start_date,
            due_date,
            completion_date,
            status,
            progress_percentage,
            responsible_person,
            deliverables,
            dependencies,
            budget_allocation,
            actual_cost,
            notes,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException('Failed to prepare add milestone query: ' . $conn->error);
        }

        $stmt->bind_param(
            'iisisssssssdsssddsi',
            $projectId,
            $programId,
            $entityType,
            $entityId,
            $milestoneName,
            $milestoneDescription,
            $milestoneType,
            $startDate,
            $dueDate,
            $completionDate,
            $status,
            $progressPercentage,
            $responsiblePerson,
            $deliverablesSummary,
            $dependencies,
            $budgetAllocation,
            $actualCost,
            $notes,
            $userId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $conn->error;
            $stmt->close();
            throw new RuntimeException('Error adding milestone: ' . $error);
        }

        $newMilestoneId = (int)$stmt->insert_id;
        $stmt->close();

        replace_milestone_deliverables($conn, $newMilestoneId, $deliverablesList, $userId);

        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Add Milestone',
                'milestones',
                $newMilestoneId,
                "Added milestone: {$milestoneName} ({$entityType})"
            );
        }

        $conn->commit();

        redirect_with_message('Milestone added successfully.', 'success');
    } catch (Throwable $e) {
        $conn->rollback();
        redirect_with_message($e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| EDIT MILESTONE
|--------------------------------------------------------------------------
*/
if (isset($_POST['edit_milestone'])) {
    $milestoneId          = (int)post_string('milestone_id', '0');
    $editLocation         = "../milestones?edit={$milestoneId}";

    $entityType           = clean_text(post_string('entity_type'));
    $projectId            = post_nullable_int('project_id');
    $programId            = post_nullable_int('program_id');
    $entityId             = null;

    $milestoneName        = clean_text(post_string('milestone_name'));
    $milestoneDescription = post_nullable_string('milestone_description');
    $milestoneType        = clean_text(post_string('milestone_type'));
    $startDate            = post_nullable_string('start_date');
    $dueDate              = clean_text(post_string('due_date'));
    $completionDate       = post_nullable_string('completion_date');
    $status               = clean_text(post_string('status', 'Not Started'));
    $progressPercentage   = post_nullable_float('progress_percentage') ?? 0.0;
    $responsiblePerson    = post_nullable_string('responsible_person');
    $dependencies         = json_list_from_post('dependencies');
    $budgetAllocation     = post_nullable_float('budget_allocation');
    $actualCost           = post_nullable_float('actual_cost');
    $notes                = post_nullable_string('notes');

    if ($milestoneId <= 0 || !milestone_exists($conn, $milestoneId)) {
        redirect_with_message('Invalid milestone selected.', 'danger', '../milestones');
    }

    if ($milestoneName === null || $entityType === null || $dueDate === null) {
        redirect_with_message('Milestone name, entity type, and due date are required.', 'danger', $editLocation);
    }

    $entityError = resolve_entity($conn, $entityType, $projectId, $programId, $entityId);
    if ($entityError !== null) {
        redirect_with_message($entityError, 'danger', $editLocation);
    }

    $dateError = validate_milestone_dates($startDate, $dueDate, $completionDate);
    if ($dateError !== null) {
        redirect_with_message($dateError, 'danger', $editLocation);
    }

    if ($progressPercentage < 0 || $progressPercentage > 100) {
        redirect_with_message('Progress percentage must be between 0 and 100.', 'danger', $editLocation);
    }

    if ($status === 'Completed') {
        $progressPercentage = 100.0;

        if ($completionDate === null) {
            $completionDate = date('Y-m-d');
        }
    } else {
        $completionDate = null;
    }

    $deliverableError = null;
    $deliverablesList = collect_deliverables_from_post($deliverableError, $startDate, $dueDate);

    if ($deliverableError !== null) {
        redirect_with_message($deliverableError, 'danger', $editLocation);
    }

    $deliverablesSummary = deliverables_json_summary($deliverablesList);

    $sql = "
        UPDATE milestones SET
            project_id = ?,
            program_id = ?,
            entity_type = ?,
            entity_id = ?,
            milestone_name = ?,
            milestone_description = ?,
            milestone_type = ?,
            start_date = ?,
            due_date = ?,
            completion_date = ?,
            status = ?,
            progress_percentage = ?,
            responsible_person = ?,
            deliverables = ?,
            dependencies = ?,
            budget_allocation = ?,
            actual_cost = ?,
            notes = ?
        WHERE milestone_id = ?
    ";

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException('Failed to prepare update milestone query: ' . $conn->error);
        }

        $stmt->bind_param(
            'iisisssssssdsssddsi',
            $projectId,
            $programId,
            $entityType,
            $entityId,
            $milestoneName,
            $milestoneDescription,
            $milestoneType,
            $startDate,
            $dueDate,
            $completionDate,
            $status,
            $progressPercentage,
            $responsiblePerson,
            $deliverablesSummary,
            $dependencies,
            $budgetAllocation,
            $actualCost,
            $notes,
            $milestoneId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $conn->error;
            $stmt->close();
            throw new RuntimeException('Error updating milestone: ' . $error);
        }

        $stmt->close();

        replace_milestone_deliverables($conn, $milestoneId, $deliverablesList, $userId);

        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Edit Milestone',
                'milestones',
                $milestoneId,
                "Updated milestone: {$milestoneName} ({$entityType})"
            );
        }

        $conn->commit();

        redirect_with_message('Milestone updated successfully.', 'success');
    } catch (Throwable $e) {
        $conn->rollback();
        redirect_with_message($e->getMessage(), 'danger', $editLocation);
    }
}

/*
|--------------------------------------------------------------------------
| DELETE MILESTONE
|--------------------------------------------------------------------------
*/
if (isset($_POST['delete_milestone'])) {
    if (!ims_allowed('milestones.delete', ['Administrator', 'Programs Lead'], 'Delete milestones')) {
        redirect_with_message('You are not allowed to delete milestones.');
    }

    $milestoneId = (int)post_string('milestone_id', '0');

    if ($milestoneId <= 0 || !milestone_exists($conn, $milestoneId)) {
        redirect_with_message('Invalid milestone selected for deletion.');
    }

    $milestoneName = get_milestone_name($conn, $milestoneId);

    try {
        $conn->begin_transaction();

        /*
         * If FK ON DELETE CASCADE exists, this delete is redundant but safe.
         * It also protects installations where the FK was not created.
         */
        delete_milestone_deliverables($conn, $milestoneId);

        $stmt = $conn->prepare("DELETE FROM milestones WHERE milestone_id = ?");

        if (!$stmt) {
            throw new RuntimeException('Failed to prepare delete milestone query: ' . $conn->error);
        }

        $stmt->bind_param('i', $milestoneId);

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $conn->error;
            $stmt->close();
            throw new RuntimeException('Error deleting milestone: ' . $error);
        }

        $stmt->close();

        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Delete Milestone',
                'milestones',
                $milestoneId,
                "Deleted milestone: {$milestoneName}"
            );
        }

        $conn->commit();

        redirect_with_message('Milestone deleted successfully.', 'success');
    } catch (Throwable $e) {
        $conn->rollback();
        redirect_with_message($e->getMessage());
    }
}

header('Location: ../milestones');
exit();
