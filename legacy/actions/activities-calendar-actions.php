<?php
declare(strict_types=1);


require_once '../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer',
    'consultant'
    
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_with_message(string $message, string $type = 'danger', string $location = '../activities-calendar'): void
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

function is_valid_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return true;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);

    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}

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

function activity_exists(mysqli $conn, int $activityId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM activities_calendar WHERE activity_id = ? LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function get_activity_name(mysqli $conn, int $activityId): string
{
    $stmt = $conn->prepare("SELECT activity_name FROM activities_calendar WHERE activity_id = ? LIMIT 1");

    if (!$stmt) {
        return 'Unknown';
    }

    $stmt->bind_param('i', $activityId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return (string)($row['activity_name'] ?? 'Unknown');
}

function resolve_entity(mysqli $conn, ?string $entityType, ?int $projectEntityId, ?int $programEntityId, ?int &$entityId): ?string
{
    if ($entityType === 'Project') {
        if ($projectEntityId === null || $projectEntityId <= 0) {
            return 'Please select a project.';
        }

        if (!project_exists($conn, $projectEntityId)) {
            return 'Selected project does not exist.';
        }

        $entityId = $projectEntityId;

        return null;
    }

    if ($entityType === 'Program') {
        if ($programEntityId === null || $programEntityId <= 0) {
            return 'Please select a program.';
        }

        if (!program_exists($conn, $programEntityId)) {
            return 'Selected program does not exist.';
        }

        $entityId = $programEntityId;

        return null;
    }

    return 'Please select a valid entity type.';
}

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $refs = [&$types];

    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$allowedStatuses = ['Planned', 'Open', 'Ongoing', 'Closed', 'Completed', 'Cancelled'];

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../activities-calendar');
    exit();
}

/*
|--------------------------------------------------------------------------
| Shared field extraction + validation for add/edit
|--------------------------------------------------------------------------
*/
function extract_and_validate_activity(mysqli $conn, ?int &$entityId): array|string
{
    global $allowedStatuses;

    $entityType = clean_text(post_string('entity_type'));
    $projectEntityId = isset($_POST['project_entity_id']) && trim((string)$_POST['project_entity_id']) !== ''
        ? (int)$_POST['project_entity_id'] : null;
    $programEntityId = isset($_POST['program_entity_id']) && trim((string)$_POST['program_entity_id']) !== ''
        ? (int)$_POST['program_entity_id'] : null;

    $entityError = resolve_entity($conn, $entityType, $projectEntityId, $programEntityId, $entityId);
    if ($entityError !== null) {
        return $entityError;
    }

    $activityName = clean_text(post_string('activity_name'));
    if ($activityName === null) {
        return 'Activity/Event name is required.';
    }

    $calendarYearRaw = post_string('calendar_year');
    $calendarYear = $calendarYearRaw !== '' ? (int)$calendarYearRaw : null;

    $startDate = post_nullable_string('start_date');
    $endDate = post_nullable_string('end_date');

    if (!is_valid_date($startDate)) {
        return 'Invalid start date.';
    }

    if (!is_valid_date($endDate)) {
        return 'Invalid end date.';
    }

    if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
        return 'Start date cannot be after end date.';
    }

    $status = clean_text(post_string('status', 'Planned'));
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'Planned';
    }

    return [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'calendar_year' => $calendarYear,
        'activity_name' => $activityName,
        'proposed_format' => post_nullable_string('proposed_format'),
        'resources_required' => post_nullable_string('resources_required'),
        'purpose' => post_nullable_string('purpose'),
        'intended_outcomes' => post_nullable_string('intended_outcomes'),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'status' => $status,
        'comments' => post_nullable_string('comments'),
    ];
}

/*
|--------------------------------------------------------------------------
| ADD ACTIVITY
|--------------------------------------------------------------------------
*/
if (isset($_POST['add_activity'])) {
    $entityId = null;
    $data = extract_and_validate_activity($conn, $entityId);

    if (is_string($data)) {
        redirect_with_message($data);
    }

    $sql = "
        INSERT INTO activities_calendar (
            entity_type, entity_id, calendar_year, activity_name,
            proposed_format, resources_required, purpose, intended_outcomes,
            start_date, end_date, status, comments, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        redirect_with_message('Failed to prepare insert query: ' . $conn->error);
    }

    $stmt->bind_param(
        'siisssssssssii',
        $data['entity_type'],
        $data['entity_id'],
        $data['calendar_year'],
        $data['activity_name'],
        $data['proposed_format'],
        $data['resources_required'],
        $data['purpose'],
        $data['intended_outcomes'],
        $data['start_date'],
        $data['end_date'],
        $data['status'],
        $data['comments'],
        $userId,
        $userId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        redirect_with_message('Error adding activity: ' . $error);
    }

    $newActivityId = (int)$stmt->insert_id;
    $stmt->close();

    if (function_exists('log_action')) {
        log_action($userId, 'Add Activity', 'activities_calendar', $newActivityId, "Added activity: {$data['activity_name']}");
    }

    redirect_with_message('Activity added successfully.', 'success');
}

/*
|--------------------------------------------------------------------------
| EDIT ACTIVITY
|--------------------------------------------------------------------------
*/
if (isset($_POST['edit_activity'])) {
    $activityId = (int)post_string('activity_id', '0');
    $editLocation = "../activities-calendar?edit={$activityId}";

    if ($activityId <= 0 || !activity_exists($conn, $activityId)) {
        redirect_with_message('Invalid activity selected.', 'danger', '../activities-calendar');
    }

    $entityId = null;
    $data = extract_and_validate_activity($conn, $entityId);

    if (is_string($data)) {
        redirect_with_message($data, 'danger', $editLocation);
    }

    $sql = "
        UPDATE activities_calendar SET
            entity_type = ?,
            entity_id = ?,
            calendar_year = ?,
            activity_name = ?,
            proposed_format = ?,
            resources_required = ?,
            purpose = ?,
            intended_outcomes = ?,
            start_date = ?,
            end_date = ?,
            status = ?,
            comments = ?,
            updated_by = ?
        WHERE activity_id = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        redirect_with_message('Failed to prepare update query: ' . $conn->error, 'danger', $editLocation);
    }

    $stmt->bind_param(
        'siisssssssssii',
        $data['entity_type'],
        $data['entity_id'],
        $data['calendar_year'],
        $data['activity_name'],
        $data['proposed_format'],
        $data['resources_required'],
        $data['purpose'],
        $data['intended_outcomes'],
        $data['start_date'],
        $data['end_date'],
        $data['status'],
        $data['comments'],
        $userId,
        $activityId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        redirect_with_message('Error updating activity: ' . $error, 'danger', $editLocation);
    }

    $stmt->close();

    if (function_exists('log_action')) {
        log_action($userId, 'Edit Activity', 'activities_calendar', $activityId, "Updated activity: {$data['activity_name']}");
    }

    redirect_with_message('Activity updated successfully.', 'success');
}

/*
|--------------------------------------------------------------------------
| DELETE ACTIVITY
|--------------------------------------------------------------------------
*/
if (isset($_POST['delete_activity'])) {
    if (!ims_allowed('activities.delete', ['Administrator', 'Programs Lead'], 'Delete activities')) {
        redirect_with_message('You are not allowed to delete activities.');
    }

    $activityId = (int)post_string('activity_id', '0');

    if ($activityId <= 0 || !activity_exists($conn, $activityId)) {
        redirect_with_message('Invalid activity selected for deletion.');
    }

    $activityName = get_activity_name($conn, $activityId);

    $stmt = $conn->prepare("DELETE FROM activities_calendar WHERE activity_id = ?");

    if (!$stmt) {
        redirect_with_message('Failed to prepare delete query: ' . $conn->error);
    }

    $stmt->bind_param('i', $activityId);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        redirect_with_message('Error deleting activity: ' . $error);
    }

    $stmt->close();

    if (function_exists('log_action')) {
        log_action($userId, 'Delete Activity', 'activities_calendar', $activityId, "Deleted activity: {$activityName}");
    }

    redirect_with_message('Activity deleted successfully.', 'success');
}

header('Location: ../activities-calendar');
exit();