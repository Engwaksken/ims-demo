<?php

require_once __DIR__ . '/includes/config.php';

check_login();

// Approve/reject: same permission as the buttons on employees-list.
if (!ims_allowed('employees.approve', ['Administrator'], 'Approve or reject employee profiles')) {
    send_notification($_SESSION['user_id'] ?? 0, 'You are not allowed to approve or reject employee profiles.', 'danger');
    header("Location: employees-list");
    exit();
}

// State changes are POST-only (CSRF token is enforced globally by config.php).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: employees-list");
    exit();
}

$current_user_id = (int) $_SESSION['user_id'];

/**
 * Load the employee row or redirect back with an error.
 */
function eap_load_employee(mysqli $conn, int $employee_id): array
{
    $stmt = $conn->prepare("SELECT employee_id, full_name, user_id, status FROM employee_directory WHERE employee_id = ? LIMIT 1");
    $employee = null;

    if ($stmt) {
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$employee) {
        send_notification($_SESSION['user_id'], 'Employee not found', 'danger');
        header("Location: employees-list");
        exit();
    }

    return $employee;
}

// Handle Approve Employee
if (isset($_POST['approve_employee'])) {
    $employee_id = (int) ($_POST['employee_id'] ?? 0);
    $employee = eap_load_employee($conn, $employee_id);

    if ($employee['status'] !== 'Submitted') {
        send_notification($current_user_id, 'Only submitted profiles can be approved', 'warning');
        header("Location: employees-list");
        exit();
    }

    $stmt = $conn->prepare("UPDATE employee_directory SET status = 'Approved', approved_by = ?, approved_at = NOW(), rejection_reason = NULL WHERE employee_id = ? AND status = 'Submitted'");
    $stmt->bind_param("ii", $current_user_id, $employee_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        log_action($current_user_id, 'Approve Employee Profile', 'employee_directory', $employee_id, "Approved profile for {$employee['full_name']}");
        send_notification($current_user_id, 'Employee profile approved successfully', 'success');

        // Notify the employee (persistent in-app notification, not the admin's flash message)
        if ((int) $employee['user_id'] > 0) {
            notify_user((int) $employee['user_id'], 'Profile approved', 'Your employee profile has been approved', 'success', $employee_id, 'employee_profile');
        }
    } else {
        error_log('Approve employee failed: ' . $stmt->error);
        send_notification($current_user_id, 'Error approving profile. Please try again.', 'danger');
    }

    $stmt->close();
    header("Location: employees-list");
    exit();
}

// Handle Reject Employee
if (isset($_POST['reject_employee'])) {
    $employee_id = (int) ($_POST['employee_id'] ?? 0);
    $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');

    if ($rejection_reason === '') {
        send_notification($current_user_id, 'Please provide a reason for rejection', 'danger');
        header("Location: employees-list");
        exit();
    }

    $employee = eap_load_employee($conn, $employee_id);

    if ($employee['status'] !== 'Submitted') {
        send_notification($current_user_id, 'Only submitted profiles can be rejected', 'warning');
        header("Location: employees-list");
        exit();
    }

    $stmt = $conn->prepare("UPDATE employee_directory SET status = 'Rejected', rejection_reason = ? WHERE employee_id = ? AND status = 'Submitted'");
    $stmt->bind_param("si", $rejection_reason, $employee_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        log_action($current_user_id, 'Reject Employee Profile', 'employee_directory', $employee_id, "Rejected profile for {$employee['full_name']}: $rejection_reason");
        send_notification($current_user_id, 'Employee profile rejected', 'success');

        if ((int) $employee['user_id'] > 0) {
            notify_user((int) $employee['user_id'], 'Profile needs changes', 'Your employee profile has been rejected. Please review the feedback and resubmit.', 'warning', $employee_id, 'employee_profile');
        }
    } else {
        error_log('Reject employee failed: ' . $stmt->error);
        send_notification($current_user_id, 'Error rejecting profile. Please try again.', 'danger');
    }

    $stmt->close();
    header("Location: employees-list");
    exit();
}

// If no valid action, redirect
header("Location: employees-list");
exit();
