<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-calendar-service.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!auth_is_logged_in()) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Your session has expired.',
    ]);

    exit;
}

if (!ims_allowed('workplan.update_status', IMS_STAFF_ROLES, 'Update work plan activity status from the calendar')) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'You are not allowed to update this activity.',
    ]);

    exit;
}

$recordId = max(
    0,
    (int)($_POST['record_id'] ?? 0)
);

$kind = trim(
    (string)($_POST['kind'] ?? 'activity')
);

$status = trim(
    (string)($_POST['status'] ?? '')
);

$progress = max(
    0,
    min(
        100,
        (float)(
            $_POST['progress_percentage']
            ?? 0
        )
    )
);

$frequency = trim(
    (string)(
        $_POST['calendar_frequency']
        ?? 'Monthly'
    )
);

$comment = trim(
    (string)($_POST['comment'] ?? '')
);

$allowedStatuses = [
    'Pending',
    'In Progress',
    'Completed',
    'Delayed',
    'Cancelled',
];

$allowedFrequencies = [
    'Daily',
    'Weekly',
    'Monthly',
    'Quarterly',
    'Annual',
];

if (
    !in_array($kind, ['activity', 'milestone'], true)
    ||
    $recordId < 1
) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid activity selected.',
    ]);

    exit;
}

if (
    !in_array(
        $status,
        $allowedStatuses,
        true
    )
) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid activity status.',
    ]);

    exit;
}

if (
    !in_array(
        $frequency,
        $allowedFrequencies,
        true
    )
) {
    $frequency = 'Monthly';
}

// Completing a task means 100% progress.
if ($status === 'Completed' && $progress < 100) {
    $progress = 100.0;
}

/*
|--------------------------------------------------------------------------
| Build the UPDATE from the columns that actually exist
|--------------------------------------------------------------------------
|
| Bug fixes:
|  - milestones (kind=milestone, sent by the workplan calendar) were rejected;
|  - the "Comment / Note" box is always opened empty, so saving a status
|    used to wipe the task's notes. The comment is now appended as a dated
|    line instead of overwriting.
|
*/

$table = $kind === 'milestone' ? 'workplan_milestones' : 'workplan_deliverables';

$sets = [];
$types = '';
$params = [];

if ($kind === 'activity' || gcal_column_exists($conn, $table, 'status')) {
    $sets[] = 'status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($kind === 'activity' || gcal_column_exists($conn, $table, 'progress_percentage')) {
    $sets[] = 'progress_percentage = ?';
    $types .= 'd';
    $params[] = $progress;
}

if (gcal_column_exists($conn, $table, 'calendar_frequency')) {
    $sets[] = 'calendar_frequency = ?';
    $types .= 's';
    $params[] = $frequency;
}

if ($comment !== '' && $kind === 'activity' && gcal_column_exists($conn, $table, 'notes')) {
    $author = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'));
    $line = '[' . date('Y-m-d H:i') . ' ' . $author . '] ' . mb_substr($comment, 0, 1000);

    $sets[] = "notes = TRIM(BOTH '\n' FROM CONCAT(COALESCE(notes, ''), '\n', ?))";
    $types .= 's';
    $params[] = $line;
}

if (!$sets) {
    echo json_encode([
        'success' => true,
        'message' => 'Nothing to update for this item.',
        'google_status' => 'Not Synced',
    ]);

    exit;
}

$types .= 'i';
$params[] = $recordId;

$stmt = $conn->prepare(
    'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ?'
);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Could not prepare activity update.',
    ]);

    exit;
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    error_log('workplan-calendar-status update failed: ' . $stmt->error);
    $stmt->close();

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Could not update activity.',
    ]);

    exit;
}

$stmt->close();

if (function_exists('log_action')) {
    log_action(
        (int)($_SESSION['user_id'] ?? 0),
        'Update ' . ucfirst($kind) . ' Status',
        $table,
        $recordId,
        "Status: {$status}, progress: {$progress}%, frequency: {$frequency}"
    );
}

if ($kind === 'milestone') {
    echo json_encode([
        'success' => true,
        'message' => 'Milestone updated successfully.',
        'google_status' => 'Not Synced',
    ]);

    exit;
}

$googleStatus = 'Not Connected';

if (gcal_is_connected($conn)) {
    try {
        gcal_sync_activity(
            $conn,
            $recordId,
            (int)($_SESSION['user_id'] ?? 0)
        );

        $googleStatus = 'Synced';
    } catch (Throwable $e) {
        gcal_mark_activity_error(
            $conn,
            $recordId,
            $e->getMessage()
        );

        $googleStatus =
            'Sync Failed: '
            . $e->getMessage();
    }
}

echo json_encode(
    [
        'success' => true,
        'message' => 'Activity updated successfully.',
        'google_status' => $googleStatus,
    ],
    JSON_UNESCAPED_SLASHES
    |
    JSON_UNESCAPED_UNICODE
);
