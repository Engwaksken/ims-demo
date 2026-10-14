<?php
session_start();
require_once 'config.php';

if (!ims_allowed('shortlisting.shortlist_phase2', ['Administrator'], 'Shortlist applications to Phase 2 from the progress board')) {
    $_SESSION['error'] = "Access denied.";
    header("Location: ../progress-board");
    exit();
}

$application_id = (int)($_POST['application_id'] ?? 0);
if ($application_id <= 0) {
    $_SESSION['error'] = "Invalid application.";
    header("Location: ../progress-board");
    exit();
}

$shortlisted_by = (int)$_SESSION['user_id'];


$sql = "
    INSERT INTO shortlisted_applications
        (application_id, phase, shortlisted_by, shortlisted_at)
    VALUES
        (?, 'Phase 2', ?, NOW())
    ON DUPLICATE KEY UPDATE
        phase = VALUES(phase),
        shortlisted_by = VALUES(shortlisted_by),
        shortlisted_at = NOW()
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Shortlist prepare failed: " . $conn->error);
    $_SESSION['error'] = "System error. Please try again.";
    header("Location: ../progress-board");
    exit();
}

$stmt->bind_param("ii", $application_id, $shortlisted_by);

if (!$stmt->execute()) {
    error_log("Shortlist execute failed: " . $stmt->error);
    $_SESSION['error'] = "Failed to shortlist application.";
    $stmt->close();
    header("Location: ../progress-board");
    exit();
}

$stmt->close();
$_SESSION['success'] = "Application shortlisted for Phase 2.";

header("Location: ../progress-board");
exit;