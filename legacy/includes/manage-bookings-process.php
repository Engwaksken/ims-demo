<?php

session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to manage bookings.";
    header("Location: ../login");
    exit();
}

$user_id = $_SESSION['user_id'];


// Check for time conflicts
function check_booking_conflict($space_name, $booking_date, $start_time, $end_time, $exclude_booking_id = 0) {
    global $conn;

    $space_name = $conn->real_escape_string((string)$space_name);
    $booking_date = $conn->real_escape_string((string)$booking_date);
    $start_time = $conn->real_escape_string((string)$start_time);
    $end_time = $conn->real_escape_string((string)$end_time);
    $exclude_booking_id = (int)$exclude_booking_id;

    $query = "SELECT booking_id FROM space_bookings 
              WHERE space_name = '$space_name' 
              AND booking_date = '$booking_date'
              AND booking_status NOT IN ('Cancelled', 'Rejected')
              AND booking_id != $exclude_booking_id
              AND (
                  (start_time < '$end_time' AND end_time > '$start_time')
              )";
    
    $result = $conn->query($query);
    return $result->num_rows > 0;
}

// Calculate booking amount
function calculate_booking_amount($space_name, $booking_type) {
    global $conn;
    
    $query = "SELECT hourly_rate, half_day_rate, full_day_rate 
              FROM space_availability 
              WHERE space_name = '$space_name'";
    
    $result = $conn->query($query);
    if ($result->num_rows == 0) {
        return 0;
    }
    
    $rates = $result->fetch_assoc();
    
    switch ($booking_type) {
        case 'Hourly':
            return $rates['hourly_rate'];
        case 'Half Day':
            return $rates['half_day_rate'];
        case 'Full Day':
            return $rates['full_day_rate'];
        default:
            return 0;
    }
}

// CREATE BOOKING
if (isset($_POST['action']) && $_POST['action'] == 'create') {
    
    // Get member_id
    $member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
    $member_result = $conn->query($member_query);
    
    if ($member_result->num_rows == 0) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../book-space");
        exit();
    }
    
    $member_id = $member_result->fetch_assoc()['member_id'];
    
    // Get and sanitize inputs
    $space_name = $conn->real_escape_string(sanitize_input($_POST['space_name']));
    $booking_date = $conn->real_escape_string(sanitize_input($_POST['booking_date']));
    $start_time = $conn->real_escape_string(sanitize_input($_POST['start_time']));
    $end_time = $conn->real_escape_string(sanitize_input($_POST['end_time']));
    $booking_type = $conn->real_escape_string(sanitize_input($_POST['booking_type']));
    $number_of_attendees = intval($_POST['number_of_attendees']);
    $purpose = $conn->real_escape_string(sanitize_input($_POST['purpose']));
    
    // Validate required fields
    if (empty($space_name) || empty($booking_date) || empty($start_time) || empty($end_time) || empty($booking_type)) {
        $_SESSION['error'] = "Please fill in all required fields.";
        header("Location: ../book-space");
        exit();
    }
    
    // Validate date is not in the past
    if ($booking_date < date('Y-m-d')) {
        $_SESSION['error'] = "Cannot book for past dates.";
        header("Location: ../book-space");
        exit();
    }
    
    // Validate time
    if ($start_time >= $end_time) {
        $_SESSION['error'] = "End time must be after start time.";
        header("Location: ../book-space");
        exit();
    }
    
    // Check for conflicts
    if (check_booking_conflict($space_name, $booking_date, $start_time, $end_time)) {
        $_SESSION['error'] = "This time slot is already booked. Please choose another time.";
        header("Location: ../book-space");
        exit();
    }
    
    // Calculate amount
    $total_amount = calculate_booking_amount($space_name, $booking_type);
    
    // Insert booking
    $insert_query = "INSERT INTO space_bookings (
                        member_id, space_name, booking_date, start_time, end_time,
                        booking_type, number_of_attendees, purpose, total_amount,
                        booking_status
                    ) VALUES (
                        $member_id, '$space_name', '$booking_date', '$start_time', '$end_time',
                        '$booking_type', $number_of_attendees, '$purpose', $total_amount,
                        'Pending'
                    )";
    
    if ($conn->query($insert_query)) {
        $booking_id = $conn->insert_id;
        
        // Log activity
        $log_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Create Booking', 
                             'Created booking for $space_name on $booking_date (ID: $booking_id)', 
                             '" . $conn->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? '')) . "')";
        $conn->query($log_query);
        
        // Send notification to member
        $notif_query = "INSERT INTO member_notifications (
                            member_id, notification_type, title, message, link_url
                        ) VALUES (
                            $member_id, 'Booking', 'Booking Request Submitted',
                            'Your booking request for $space_name on $booking_date is pending approval.',
                            'my-bookings'
                        )";
        $conn->query($notif_query);
        
        $_SESSION['success'] = "Booking request submitted successfully! You will be notified once approved.";
        header("Location: ../my-bookings");
    } else {
        $_SESSION['error'] = "Error creating booking: " . $conn->error;
        header("Location: ../book-space");
    }
    
    exit();
}

// APPROVE BOOKING
if (isset($_POST['action']) && $_POST['action'] == 'approve') {
    
    // Check admin access
    if (!ims_allowed('bookings.approve', ['Administrator', 'Operations/Admin'], 'Approve bookings')) {
        $_SESSION['error'] = "Access denied.";
        header("Location: ../dashboard");
        exit();
    }
    
    $booking_id = intval($_POST['booking_id']);
    
    // Get booking details
    $booking_query = "SELECT sb.*, m.member_id 
                      FROM space_bookings sb
                      LEFT JOIN members m ON sb.member_id = m.member_id
                      WHERE sb.booking_id = $booking_id";
    $booking_result = $conn->query($booking_query);
    
    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found.";
        header("Location: ../manage-bookings");
        exit();
    }
    
    $booking = $booking_result->fetch_assoc();
    // SQL-escaped copy for the string-built queries below (names like "Founder's Room").
    $booking_sql = array_map(fn($v) => is_string($v) ? $conn->real_escape_string($v) : $v, $booking);
    
    // Check if already approved
    if ($booking['booking_status'] != 'Pending') {
        $_SESSION['error'] = "Booking has already been processed.";
        header("Location: ../manage-bookings");
        exit();
    }
    
    // Check for conflicts (in case another booking was made)
    if (check_booking_conflict($booking['space_name'], $booking['booking_date'], 
                               $booking['start_time'], $booking['end_time'], $booking_id)) {
        $_SESSION['error'] = "Cannot approve: Time slot now has a conflict.";
        header("Location: ../manage-bookings");
        exit();
    }
    
    // Update booking status
    $update_query = "UPDATE space_bookings SET 
                     booking_status = 'Confirmed',
                     confirmed_by = $user_id,
                     confirmed_at = NOW()
                     WHERE booking_id = $booking_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Approve Booking', 
                             'Approved booking ID: $booking_id for {$booking_sql['space_name']}', 
                             '" . $conn->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? '')) . "')";
        $conn->query($log_query);
        
        // Send notification to member
        $notif_query = "INSERT INTO member_notifications (
                            member_id, notification_type, title, message, link_url
                        ) VALUES (
                            {$booking_sql['member_id']}, 'Booking', 'Booking Confirmed',
                            'Your booking for {$booking_sql['space_name']} on {$booking_sql['booking_date']} has been confirmed!',
                            'view-booking?id=$booking_id'
                        )";
        $conn->query($notif_query);
        
        $_SESSION['success'] = "Booking approved successfully!";
    } else {
        $_SESSION['error'] = "Error approving booking: " . $conn->error;
    }
    
    header("Location: ../manage-bookings");
    exit();
}

// REJECT BOOKING
if (isset($_POST['action']) && $_POST['action'] == 'reject') {
    
    // Check admin access
    if (!ims_allowed('bookings.reject', ['Administrator', 'Operations/Admin'], 'Reject bookings')) {
        $_SESSION['error'] = "Access denied.";
        header("Location: ../dashboard");
        exit();
    }
    
    $booking_id = intval($_POST['booking_id']);
    $rejection_reason = $conn->real_escape_string(sanitize_input($_POST['rejection_reason']));
    
    if (empty($rejection_reason)) {
        $_SESSION['error'] = "Please provide a rejection reason.";
        header("Location: ../manage-bookings");
        exit();
    }
    
    // Get booking details
    $booking_query = "SELECT sb.*, m.member_id 
                      FROM space_bookings sb
                      LEFT JOIN members m ON sb.member_id = m.member_id
                      WHERE sb.booking_id = $booking_id";
    $booking_result = $conn->query($booking_query);
    
    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found.";
        header("Location: ../manage-bookings");
        exit();
    }
    
    $booking = $booking_result->fetch_assoc();
    // SQL-escaped copy for the string-built queries below (names like "Founder's Room").
    $booking_sql = array_map(fn($v) => is_string($v) ? $conn->real_escape_string($v) : $v, $booking);
    
    // Update booking status
    $update_query = "UPDATE space_bookings SET 
                     booking_status = 'Cancelled',
                     cancellation_reason = CONCAT('Rejected: ', '$rejection_reason'),
                     cancelled_by = $user_id,
                     cancelled_at = NOW()
                     WHERE booking_id = $booking_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Reject Booking', 
                             'Rejected booking ID: $booking_id - Reason: $rejection_reason', 
                             '" . $conn->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? '')) . "')";
        $conn->query($log_query);
        
        // Send notification to member
        $notif_query = "INSERT INTO member_notifications (
                            member_id, notification_type, title, message, link_url
                        ) VALUES (
                            {$booking_sql['member_id']}, 'Booking', 'Booking Rejected',
                            'Your booking for {$booking_sql['space_name']} on {$booking_sql['booking_date']} was rejected. Reason: $rejection_reason',
                            'my-bookings'
                        )";
        $conn->query($notif_query);
        
        $_SESSION['success'] = "Booking rejected. Member has been notified.";
    } else {
        $_SESSION['error'] = "Error rejecting booking: " . $conn->error;
    }
    
    header("Location: ../manage-bookings");
    exit();
}

// CANCEL BOOKING (Member)
if (isset($_GET['action']) && $_GET['action'] == 'cancel') {
    csrf_protect(true); // state change over GET needs a token
    
    $booking_id = intval($_GET['booking_id']);
    
    // Get member_id
    $member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
    $member_result = $conn->query($member_query);
    
    if ($member_result->num_rows == 0) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../my-bookings");
        exit();
    }
    
    $member_id = $member_result->fetch_assoc()['member_id'];
    
    // Get booking details
    $booking_query = "SELECT * FROM space_bookings 
                      WHERE booking_id = $booking_id 
                      AND member_id = $member_id";
    $booking_result = $conn->query($booking_query);
    
    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found or access denied.";
        header("Location: ../my-bookings");
        exit();
    }
    
    $booking = $booking_result->fetch_assoc();
    // SQL-escaped copy for the string-built queries below (names like "Founder's Room").
    $booking_sql = array_map(fn($v) => is_string($v) ? $conn->real_escape_string($v) : $v, $booking);
    
    // Check if booking can be cancelled (24 hours policy)
    $booking_datetime = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
    $hours_until = ($booking_datetime - time()) / 3600;
    
    if ($hours_until < 24) {
        $_SESSION['error'] = "Cannot cancel booking within 24 hours of start time.";
        header("Location: ../view-booking?id=$booking_id");
        exit();
    }
    
    if ($booking['booking_status'] == 'Cancelled') {
        $_SESSION['error'] = "Booking is already cancelled.";
        header("Location: ../my-bookings");
        exit();
    }
    
    // Update booking status
    $update_query = "UPDATE space_bookings SET 
                     booking_status = 'Cancelled',
                     cancellation_reason = 'Cancelled by member',
                     cancelled_by = $user_id,
                     cancelled_at = NOW()
                     WHERE booking_id = $booking_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Cancel Booking', 
                             'Cancelled booking ID: $booking_id for {$booking_sql['space_name']}', 
                             '" . $conn->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? '')) . "')";
        $conn->query($log_query);
        
        // Send notification to member
        $notif_query = "INSERT INTO member_notifications (
                            member_id, notification_type, title, message, link_url
                        ) VALUES (
                            $member_id, 'Booking', 'Booking Cancelled',
                            'Your booking for {$booking_sql['space_name']} on {$booking_sql['booking_date']} has been cancelled.',
                            'my-bookings'
                        )";
        $conn->query($notif_query);
        
        $_SESSION['success'] = "Booking cancelled successfully.";
    } else {
        $_SESSION['error'] = "Error cancelling booking: " . $conn->error;
    }
    
    header("Location: ../my-bookings");
    exit();
}

// ADMIN CANCEL BOOKING
if (isset($_GET['action']) && $_GET['action'] == 'admin_cancel') {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    
    // Check admin access
    if (!ims_allowed('bookings.cancel_any', ['Administrator', 'Operations/Admin'], 'Cancel any booking')) {
        $_SESSION['error'] = "Access denied.";
        header("Location: ../dashboard");
        exit();
    }
    
    $booking_id = intval($_GET['booking_id']);
    
    // Get booking details
    $booking_query = "SELECT sb.*, m.member_id 
                      FROM space_bookings sb
                      LEFT JOIN members m ON sb.member_id = m.member_id
                      WHERE sb.booking_id = $booking_id";
    $booking_result = $conn->query($booking_query);
    
    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found.";
        header("Location: ../manage-bookings");
        exit();
    }
    
    $booking = $booking_result->fetch_assoc();
    // SQL-escaped copy for the string-built queries below (names like "Founder's Room").
    $booking_sql = array_map(fn($v) => is_string($v) ? $conn->real_escape_string($v) : $v, $booking);
    
    // Update booking status
    $update_query = "UPDATE space_bookings SET 
                     booking_status = 'Cancelled',
                     cancellation_reason = 'Cancelled by admin',
                     cancelled_by = $user_id,
                     cancelled_at = NOW()
                     WHERE booking_id = $booking_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Admin Cancel Booking', 
                             'Admin cancelled booking ID: $booking_id', 
                             '" . $conn->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? '')) . "')";
        $conn->query($log_query);
        
        // Send notification to member
        $notif_query = "INSERT INTO member_notifications (
                            member_id, notification_type, title, message, link_url
                        ) VALUES (
                            {$booking_sql['member_id']}, 'Booking', 'Booking Cancelled',
                            'Your booking for {$booking_sql['space_name']} on {$booking_sql['booking_date']} has been cancelled by administration.',
                            'my-bookings'
                        )";
        $conn->query($notif_query);
        
        $_SESSION['success'] = "Booking cancelled. Member has been notified.";
    } else {
        $_SESSION['error'] = "Error cancelling booking: " . $conn->error;
    }
    
    header("Location: ../manage-bookings");
    exit();
}

// RESCHEDULE BOOKING
if (isset($_POST['action']) && $_POST['action'] == 'reschedule') {
    
    $booking_id = intval($_POST['booking_id']);
    $new_date = $conn->real_escape_string(sanitize_input($_POST['new_date']));
    $new_start_time = $conn->real_escape_string(sanitize_input($_POST['new_start_time']));
    $new_end_time = $conn->real_escape_string(sanitize_input($_POST['new_end_time']));
    
    // Get member_id
    $member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
    $member_result = $conn->query($member_query);
    
    if ($member_result->num_rows == 0) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../my-bookings");
        exit();
    }
    
    $member_id = $member_result->fetch_assoc()['member_id'];
    
    // Get booking details
    $booking_query = "SELECT * FROM space_bookings 
                      WHERE booking_id = $booking_id 
                      AND member_id = $member_id";
    $booking_result = $conn->query($booking_query);
    
    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found or access denied.";
        header("Location: ../my-bookings");
        exit();
    }
    
    $booking = $booking_result->fetch_assoc();
    // SQL-escaped copy for the string-built queries below (names like "Founder's Room").
    $booking_sql = array_map(fn($v) => is_string($v) ? $conn->real_escape_string($v) : $v, $booking);
    
    // Check if booking can be rescheduled
    $booking_datetime = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
    $hours_until = ($booking_datetime - time()) / 3600;
    
    if ($hours_until < 24) {
        $_SESSION['error'] = "Cannot reschedule booking within 24 hours of start time.";
        header("Location: ../view-booking?id=$booking_id");
        exit();
    }
    
    // Validate new date and times
    if (empty($new_date) || empty($new_start_time) || empty($new_end_time)) {
        $_SESSION['error'] = "Please provide new date and time.";
        header("Location: ../view-booking?id=$booking_id");
        exit();
    }
    
    if ($new_start_time >= $new_end_time) {
        $_SESSION['error'] = "End time must be after start time.";
        header("Location: ../view-booking?id=$booking_id");
        exit();
    }
    
    // Check for conflicts
    if (check_booking_conflict($booking['space_name'], $new_date, $new_start_time, $new_end_time, $booking_id)) {
        $_SESSION['error'] = "The new time slot is already booked. Please choose another time.";
        header("Location: ../view-booking?id=$booking_id");
        exit();
    }
    
    // Recalculate amount if booking type changed
    $total_amount = calculate_booking_amount($booking['space_name'], $booking['booking_type']);
    
    // Update booking
    $update_query = "UPDATE space_bookings SET 
                     booking_date = '$new_date',
                     start_time = '$new_start_time',
                     end_time = '$new_end_time',
                     total_amount = $total_amount,
                     booking_status = 'Pending'
                     WHERE booking_id = $booking_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Reschedule Booking', 
                             'Rescheduled booking ID: $booking_id to $new_date', 
                             '" . $conn->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? '')) . "')";
        $conn->query($log_query);
        
        // Send notification to member
        $notif_query = "INSERT INTO member_notifications (
                            member_id, notification_type, title, message, link_url
                        ) VALUES (
                            $member_id, 'Booking', 'Booking Rescheduled',
                            'Your booking for {$booking_sql['space_name']} has been rescheduled to $new_date and is pending approval.',
                            'view-booking?id=$booking_id'
                        )";
        $conn->query($notif_query);
        
        $_SESSION['success'] = "Booking rescheduled successfully! It is now pending approval.";
    } else {
        $_SESSION['error'] = "Error rescheduling booking: " . $conn->error;
    }
    
    header("Location: ../view-booking?id=$booking_id");
    exit();
}

// MARK AS COMPLETED
if (isset($_GET['action']) && $_GET['action'] == 'mark_complete') {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    
    // Check admin access
    if (!ims_allowed('bookings.mark_complete', ['Administrator', 'Operations/Admin'], 'Mark bookings as completed')) {
        $_SESSION['error'] = "Access denied.";
        header("Location: ../dashboard");
        exit();
    }
    
    $booking_id = intval($_GET['booking_id']);
    
    // Get booking details
    $booking_query = "SELECT sb.*, m.member_id 
                      FROM space_bookings sb
                      LEFT JOIN members m ON sb.member_id = m.member_id
                      WHERE sb.booking_id = $booking_id";
    $booking_result = $conn->query($booking_query);
    
    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found.";
        header("Location: ../manage-bookings");
        exit();
    }
    
    $booking = $booking_result->fetch_assoc();
    // SQL-escaped copy for the string-built queries below (names like "Founder's Room").
    $booking_sql = array_map(fn($v) => is_string($v) ? $conn->real_escape_string($v) : $v, $booking);
    
    // Update booking status
    $update_query = "UPDATE space_bookings SET 
                     booking_status = 'Completed'
                     WHERE booking_id = $booking_id";
    
    if ($conn->query($update_query)) {
        
        // Log activity
        $log_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                     VALUES ($user_id, 'Complete Booking', 
                             'Marked booking ID: $booking_id as completed', 
                             '" . $conn->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? '')) . "')";
        $conn->query($log_query);
        
        // Send notification to member (optional)
        $notif_query = "INSERT INTO member_notifications (
                            member_id, notification_type, title, message, link_url
                        ) VALUES (
                            {$booking_sql['member_id']}, 'Booking', 'Booking Completed',
                            'Your booking for {$booking_sql['space_name']} on {$booking_sql['booking_date']} has been marked as completed. Thank you!',
                            'my-bookings'
                        )";
        $conn->query($notif_query);
        
        $_SESSION['success'] = "Booking marked as completed.";
    } else {
        $_SESSION['error'] = "Error updating booking: " . $conn->error;
    }
    
    header("Location: ../manage-bookings");
    exit();
}

// If no valid action, redirect
$_SESSION['error'] = "Invalid action.";
header("Location: ../dashboard");
exit();
?>