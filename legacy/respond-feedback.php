<?php
require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
session_start();

// Must be logged in
require_login();

// Only Administrators allowed
$allowed_roles = ['Administrator', 'Operations/Admin'];

if (!ims_allowed('feedback.respond', ['Administrator', 'Operations/Admin'], 'Respond to member feedback')) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    redirect_by_role($_SESSION['role']);
}

$user_id = $_SESSION['user_id'];


// RESPOND TO FEEDBACK
if (isset($_POST['action']) && $_POST['action'] == 'respond') {
    
    $feedback_id = intval($_POST['feedback_id']);
    $admin_response = $conn->real_escape_string(sanitize_input($_POST['admin_response']));
    $new_status = $conn->real_escape_string(sanitize_input($_POST['new_status']));
    
    // Validate inputs
    if (empty($admin_response)) {
        $_SESSION['error'] = "Please provide a response.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    if (empty($new_status)) {
        $_SESSION['error'] = "Please select a status.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    // Validate status
    $valid_statuses = ['Responded', 'Resolved', 'Closed'];
    if (!in_array($new_status, $valid_statuses)) {
        $_SESSION['error'] = "Invalid status selected.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    // Get feedback details
    $feedback_query = "SELECT f.*, m.member_id, u.full_name as member_name
                       FROM member_feedback f
                       LEFT JOIN members m ON f.member_id = m.member_id
                       LEFT JOIN users u ON m.user_id = u.user_id
                       WHERE f.feedback_id = $feedback_id";
    $feedback_result = $conn->query($feedback_query);
    
    if ($feedback_result->num_rows == 0) {
        $_SESSION['error'] = "Feedback not found.";
        header("Location: hub-operations?tab=feedback");
        exit();
    }
    
    $feedback = $feedback_result->fetch_assoc();
    
    // Update feedback
    $update_query = "UPDATE member_feedback SET 
                     admin_response = '$admin_response',
                     responded_by = $user_id,
                     response_date = NOW(),
                     status = '$new_status'
                     WHERE feedback_id = $feedback_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Respond to Feedback', 
                             'Responded to feedback ID: $feedback_id - Status: $new_status', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        // Send notification to member (if not anonymous)
        if (!$feedback['is_anonymous'] && $feedback['member_id']) {
            $notif_query = "INSERT INTO member_notifications (
                                member_id, notification_type, title, message, link_url
                            ) VALUES (
                                {$feedback['member_id']}, 'Feedback', 'Feedback Response Received',
                                'We have responded to your feedback regarding: {$feedback['subject']}',
                                'view-feedback?id=$feedback_id'
                            )";
            $conn->query($notif_query);
        }
        
        $_SESSION['success'] = "Response added successfully. Member has been notified.";
    } else {
        $_SESSION['error'] = "Error adding response: " . $conn->error;
    }
    
    header("Location: view-feedback?id=$feedback_id");
    exit();
}

// UPDATE RESPONSE
if (isset($_POST['action']) && $_POST['action'] == 'update_response') {
    
    $feedback_id = intval($_POST['feedback_id']);
    $admin_response = $conn->real_escape_string(sanitize_input($_POST['admin_response']));
    $new_status = $conn->real_escape_string(sanitize_input($_POST['new_status']));
    
    // Validate inputs
    if (empty($admin_response)) {
        $_SESSION['error'] = "Please provide a response.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    if (empty($new_status)) {
        $_SESSION['error'] = "Please select a status.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    // Validate status
    $valid_statuses = ['Responded', 'Resolved', 'Closed'];
    if (!in_array($new_status, $valid_statuses)) {
        $_SESSION['error'] = "Invalid status selected.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    // Get feedback details
    $feedback_query = "SELECT f.*, m.member_id
                       FROM member_feedback f
                       LEFT JOIN members m ON f.member_id = m.member_id
                       WHERE f.feedback_id = $feedback_id";
    $feedback_result = $conn->query($feedback_query);
    
    if ($feedback_result->num_rows == 0) {
        $_SESSION['error'] = "Feedback not found.";
        header("Location: hub-operations?tab=feedback");
        exit();
    }
    
    $feedback = $feedback_result->fetch_assoc();
    
    // Update response
    $update_query = "UPDATE member_feedback SET 
                     admin_response = '$admin_response',
                     status = '$new_status'
                     WHERE feedback_id = $feedback_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Update Feedback Response', 
                             'Updated response for feedback ID: $feedback_id - Status: $new_status', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        // Send notification to member (if not anonymous)
        if (!$feedback['is_anonymous'] && $feedback['member_id']) {
            $notif_query = "INSERT INTO member_notifications (
                                member_id, notification_type, title, message, link_url
                            ) VALUES (
                                {$feedback['member_id']}, 'Feedback', 'Feedback Response Updated',
                                'The response to your feedback has been updated.',
                                'view-feedback?id=$feedback_id'
                            )";
            $conn->query($notif_query);
        }
        
        $_SESSION['success'] = "Response updated successfully.";
    } else {
        $_SESSION['error'] = "Error updating response: " . $conn->error;
    }
    
    header("Location: view-feedback?id=$feedback_id");
    exit();
}

// ASSIGN FEEDBACK
if (isset($_POST['action']) && $_POST['action'] == 'assign') {
    
    $feedback_id = intval($_POST['feedback_id']);
    $assigned_to = intval($_POST['assigned_to']);
    
    // Validate assigned_to
    if ($assigned_to <= 0) {
        $_SESSION['error'] = "Please select a staff member.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    // Verify staff member exists
    $staff_query = "SELECT user_id, full_name FROM users WHERE user_id = $assigned_to AND status = 'Active'";
    $staff_result = $conn->query($staff_query);
    
    if ($staff_result->num_rows == 0) {
        $_SESSION['error'] = "Invalid staff member selected.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    $staff = $staff_result->fetch_assoc();
    
    // Get feedback details
    $feedback_query = "SELECT feedback_id, subject FROM member_feedback WHERE feedback_id = $feedback_id";
    $feedback_result = $conn->query($feedback_query);
    
    if ($feedback_result->num_rows == 0) {
        $_SESSION['error'] = "Feedback not found.";
        header("Location: hub-operations?tab=feedback");
        exit();
    }
    
    $feedback = $feedback_result->fetch_assoc();
    
    // Update feedback
    $update_query = "UPDATE member_feedback SET 
                     assigned_to = $assigned_to,
                     status = 'In Review'
                     WHERE feedback_id = $feedback_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Assign Feedback', 
                             'Assigned feedback ID: $feedback_id to {$staff['full_name']}', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        // Send notification to assigned staff
        $staff_notif_query = "INSERT INTO member_notifications (
                                  member_id, notification_type, title, message, link_url
                              ) VALUES (
                                  (SELECT member_id FROM members WHERE user_id = $assigned_to LIMIT 1),
                                  'Feedback', 'Feedback Assigned to You',
                                  'You have been assigned to handle feedback: {$feedback['subject']}',
                                  'view-feedback?id=$feedback_id'
                              )";
        $conn->query($staff_notif_query);
        
        $_SESSION['success'] = "Feedback assigned to {$staff['full_name']}.";
    } else {
        $_SESSION['error'] = "Error assigning feedback: " . $conn->error;
    }
    
    header("Location: view-feedback?id=$feedback_id");
    exit();
}

// UPDATE STATUS
if (isset($_POST['action']) && $_POST['action'] == 'update_status') {
    
    $feedback_id = intval($_POST['feedback_id']);
    $new_status = $conn->real_escape_string(sanitize_input($_POST['status']));
    
    // Validate status
    $valid_statuses = ['New', 'In Review', 'Responded', 'Resolved', 'Closed'];
    if (!in_array($new_status, $valid_statuses)) {
        $_SESSION['error'] = "Invalid status selected.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    // Get feedback details
    $feedback_query = "SELECT f.*, m.member_id
                       FROM member_feedback f
                       LEFT JOIN members m ON f.member_id = m.member_id
                       WHERE f.feedback_id = $feedback_id";
    $feedback_result = $conn->query($feedback_query);
    
    if ($feedback_result->num_rows == 0) {
        $_SESSION['error'] = "Feedback not found.";
        header("Location: hub-operations?tab=feedback");
        exit();
    }
    
    $feedback = $feedback_result->fetch_assoc();
    
    // Update status
    $update_query = "UPDATE member_feedback SET 
                     status = '$new_status'
                     WHERE feedback_id = $feedback_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Update Feedback Status', 
                             'Updated feedback ID: $feedback_id status to: $new_status', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        // Send notification to member (if not anonymous and status is significant)
        if (!$feedback['is_anonymous'] && $feedback['member_id'] && in_array($new_status, ['Resolved', 'Closed'])) {
            $status_message = $new_status == 'Resolved' 
                ? 'Your feedback has been resolved.' 
                : 'Your feedback case has been closed.';
            
            $notif_query = "INSERT INTO member_notifications (
                                member_id, notification_type, title, message, link_url
                            ) VALUES (
                                {$feedback['member_id']}, 'Feedback', 'Feedback Status Updated',
                                '$status_message',
                                'view-feedback?id=$feedback_id'
                            )";
            $conn->query($notif_query);
        }
        
        $_SESSION['success'] = "Status updated to: $new_status";
    } else {
        $_SESSION['error'] = "Error updating status: " . $conn->error;
    }
    
    header("Location: view-feedback?id=$feedback_id");
    exit();
}

// UPDATE PRIORITY
if (isset($_POST['action']) && $_POST['action'] == 'update_priority') {
    
    $feedback_id = intval($_POST['feedback_id']);
    $new_priority = $conn->real_escape_string(sanitize_input($_POST['priority']));
    
    // Validate priority
    $valid_priorities = ['Low', 'Medium', 'High', 'Urgent'];
    if (!in_array($new_priority, $valid_priorities)) {
        $_SESSION['error'] = "Invalid priority selected.";
        header("Location: view-feedback?id=$feedback_id");
        exit();
    }
    
    // Update priority
    $update_query = "UPDATE member_feedback SET 
                     priority = '$new_priority'
                     WHERE feedback_id = $feedback_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Update Feedback Priority', 
                             'Updated feedback ID: $feedback_id priority to: $new_priority', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        $_SESSION['success'] = "Priority updated to: $new_priority";
    } else {
        $_SESSION['error'] = "Error updating priority: " . $conn->error;
    }
    
    header("Location: view-feedback?id=$feedback_id");
    exit();
}

// DELETE RESPONSE (Admin only)
if (isset($_GET['action']) && $_GET['action'] == 'delete_response') {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    
    // Extra admin check
    if (!ims_allowed('feedback.delete_response', ['Administrator'], 'Delete feedback responses')) {
        $_SESSION['error'] = "Only administrators can delete responses.";
        header("Location: dashboard");
        exit();
    }
    
    $feedback_id = intval($_GET['id']);
    
    // Get feedback details
    $feedback_query = "SELECT feedback_id, subject FROM member_feedback WHERE feedback_id = $feedback_id";
    $feedback_result = $conn->query($feedback_query);
    
    if ($feedback_result->num_rows == 0) {
        $_SESSION['error'] = "Feedback not found.";
        header("Location: hub-operations?tab=feedback");
        exit();
    }
    
    // Clear response
    $update_query = "UPDATE member_feedback SET 
                     admin_response = NULL,
                     responded_by = NULL,
                     response_date = NULL,
                     status = 'In Review'
                     WHERE feedback_id = $feedback_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Delete Feedback Response', 
                             'Deleted response for feedback ID: $feedback_id', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        $_SESSION['success'] = "Response deleted successfully.";
    } else {
        $_SESSION['error'] = "Error deleting response: " . $conn->error;
    }
    
    header("Location: view-feedback?id=$feedback_id");
    exit();
}

// BULK UPDATE STATUS
if (isset($_POST['action']) && $_POST['action'] == 'bulk_update_status') {
    
    $feedback_ids = isset($_POST['feedback_ids']) ? $_POST['feedback_ids'] : [];
    $new_status = $conn->real_escape_string(sanitize_input($_POST['bulk_status']));
    
    if (empty($feedback_ids)) {
        $_SESSION['error'] = "No feedback items selected.";
        header("Location: hub-operations?tab=feedback");
        exit();
    }
    
    // Validate status
    $valid_statuses = ['New', 'In Review', 'Responded', 'Resolved', 'Closed'];
    if (!in_array($new_status, $valid_statuses)) {
        $_SESSION['error'] = "Invalid status selected.";
        header("Location: hub-operations?tab=feedback");
        exit();
    }
    
    // Sanitize IDs
    $feedback_ids = array_map('intval', $feedback_ids);
    $ids_string = implode(',', $feedback_ids);
    
    // Update status for all selected
    $update_query = "UPDATE member_feedback SET 
                     status = '$new_status'
                     WHERE feedback_id IN ($ids_string)";
    
    if ($conn->query($update_query)) {
        $count = $conn->affected_rows;
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Bulk Update Feedback Status', 
                             'Updated $count feedback items to status: $new_status', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        $_SESSION['success'] = "Updated $count feedback items to: $new_status";
    } else {
        $_SESSION['error'] = "Error updating feedback: " . $conn->error;
    }
    
    header("Location: hub-operations?tab=feedback");
    exit();
}

// EXPORT FEEDBACK (CSV/Excel)
if (isset($_GET['action']) && $_GET['action'] == 'export') {
    
    $format = isset($_GET['format']) ? $conn->real_escape_string(sanitize_input($_GET['format'])) : 'csv';
    $status_filter = isset($_GET['status']) ? $conn->real_escape_string(sanitize_input($_GET['status'])) : '';
    $type_filter = isset($_GET['type']) ? $conn->real_escape_string(sanitize_input($_GET['type'])) : '';
    
    // Build WHERE clause
    $where = "1=1";
    if ($status_filter) {
        $where .= " AND f.status = '$status_filter'";
    }
    if ($type_filter) {
        $where .= " AND f.feedback_type = '$type_filter'";
    }
    
    // Fetch feedback
    $query = "SELECT f.feedback_id, f.subject, f.feedback_type, f.message, 
              f.rating, f.priority, f.status, f.admin_response,
              f.is_anonymous, f.created_at, f.response_date,
              u.full_name as member_name, m.membership_number,
              resp.full_name as responded_by_name
              FROM member_feedback f
              LEFT JOIN members m ON f.member_id = m.member_id
              LEFT JOIN users u ON m.user_id = u.user_id
              LEFT JOIN users resp ON f.responded_by = resp.user_id
              WHERE $where
              ORDER BY f.created_at DESC";
    
    $result = $conn->query($query);
    
    if ($format == 'csv') {
        // CSV Export
        $filename = 'feedback_export_' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        ims_fputcsv($output, ['ID', 'Subject', 'Type', 'Message', 'Rating', 'Priority', 'Status', 
                         'Member Name', 'Membership #', 'Anonymous', 'Submitted', 
                         'Response', 'Responded By', 'Response Date']);
        
        // Data
        while ($row = $result->fetch_assoc()) {
            ims_fputcsv($output, [
                $row['feedback_id'],
                $row['subject'],
                $row['feedback_type'],
                $row['message'],
                $row['rating'] ? $row['rating'] . ' stars' : 'N/A',
                $row['priority'],
                $row['status'],
                $row['is_anonymous'] ? 'Anonymous' : $row['member_name'],
                $row['is_anonymous'] ? 'N/A' : $row['membership_number'],
                $row['is_anonymous'] ? 'Yes' : 'No',
                $row['created_at'],
                $row['admin_response'] ? substr($row['admin_response'], 0, 100) . '...' : 'No response',
                $row['responded_by_name'] ? $row['responded_by_name'] : 'N/A',
                $row['response_date'] ? $row['response_date'] : 'N/A'
            ]);
        }
        
        fclose($output);
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Export Feedback', 
                             'Exported feedback to CSV', 
                             '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        exit();
    }
}

// If no valid action, redirect
$_SESSION['error'] = "Invalid action.";
header("Location: hub-operations?tab=feedback");
exit();
?>