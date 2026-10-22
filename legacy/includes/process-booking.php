<?php
session_start();
require_once 'config.php';
require_once 'mail-function.php';
require_once __DIR__ . '/pricing.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to book a space.";
    header("Location: ../login");
    exit();
}

$user_id = $_SESSION['user_id'];

/**
 * Runs a query and, if it fails outright (returns false - a real SQL
 * error, not just "0 rows"), logs the actual MySQL error and stops the
 * request with a session error instead of letting the caller crash on
 * ->num_rows / ->fetch_assoc() of a boolean false.
 */
function run_query_or_fail($conn, $sql, $redirect, $context = 'Database query') {
    $result = $conn->query($sql);
    if ($result === false) {
        error_log("$context failed: {$conn->error} | SQL: $sql");
        $_SESSION['error'] = "$context failed: " . $conn->error;
        header("Location: $redirect");
        exit();
    }
    return $result;
}

function get_booking_notification_recipients($conn) {
    $recipients = [];
    $result = $conn->query("SELECT email FROM users WHERE role IN ('Administrator', 'Operations/Admin') AND is_active = 1 AND email IS NOT NULL AND email != ''");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['email'];
        }
    } else {
        // Not fatal - booking still proceeds even if we can't find who to notify.
        error_log("get_booking_notification_recipients failed: " . $conn->error);
    }
    return $recipients;
}

/**
 * Checks whether a column exists on a table, caching the result for the
 * rest of the request. Used to guard optional columns (like reminder_sent)
 * that a given install may not have migrated in yet - so a missing column
 * degrades a feature (no reminder de-duplication) instead of crashing the
 * whole update with "Unknown column ... in 'SET'".
 */
function column_exists($conn, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $table_esc = $conn->real_escape_string($table);
    $column_esc = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table_esc` LIKE '$column_esc'");
    $exists = $result && $result->num_rows > 0;

    if ($result === false) {
        error_log("column_exists check failed for $table.$column: " . $conn->error);
    }

    $cache[$key] = $exists;
    return $exists;
}

/**
 * update_booking is now reachable from two different pages (book-space.php's
 * inline Pending-booking editor, and my-bookings.php's Edit action), each of
 * which wants the member sent back to itself afterwards. The form includes a
 * `return_to` hidden field for this - resolved against a fixed whitelist
 * (never taken as a raw redirect target) so a tampered value can't be used
 * to redirect elsewhere.
 */
function resolve_return_to($default = 'book-space') {
    $allowed = ['book-space', 'my-bookings'];
    // Accept legacy "page.php" values from forms rendered before the clean-URL change.
    $requested = preg_replace('/\.php$/', '', (string)($_POST['return_to'] ?? ''));
    return in_array($requested, $allowed, true) ? $requested : $default;
}

// CREATE BOOKING
if (isset($_POST['action']) && $_POST['action'] == 'create_booking') {

    $return_to = '../' . resolve_return_to();

    // Get member_id + name + email from user_id
    $result = run_query_or_fail(
        $conn,
        "SELECT m.member_id, u.full_name, u.email 
         FROM members m 
         JOIN users u ON u.user_id = m.user_id 
         WHERE m.user_id = $user_id",
        $return_to,
        'Member lookup'
    );

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Member profile not found. Please contact administrator.";
        header("Location: $return_to");
        exit();
    }

    $member = $result->fetch_assoc();
    $member_id = $member['member_id'];
    $member_name = $member['full_name'];
    $member_email = $member['email'];

    // Get form data
    $space_id = intval($_POST['space_id']);
    $booking_date = $conn->real_escape_string(sanitize_input($_POST['booking_date']));
    $booking_type = $conn->real_escape_string(sanitize_input($_POST['booking_type'])); // hourly, half_day, full_day
    $start_time = $conn->real_escape_string(sanitize_input($_POST['start_time']));
    $end_time = $conn->real_escape_string(sanitize_input($_POST['end_time']));
    $number_of_attendees = intval($_POST['number_of_attendees']);
    $purpose = $conn->real_escape_string(sanitize_input($_POST['purpose']));
    $setup_required = $conn->real_escape_string(sanitize_input($_POST['setup_required']));
    $equipment_needed = $conn->real_escape_string(sanitize_input($_POST['equipment_needed']));
    $catering_required = isset($_POST['catering_required']) ? 1 : 0;
    $catering_description = $conn->real_escape_string(sanitize_input($_POST['catering_description']));
    $special_requests = $conn->real_escape_string(sanitize_input($_POST['special_requests']));

    // Validate required fields
    if (empty($space_id) || empty($booking_date) || empty($start_time) || empty($purpose)) {
        $_SESSION['error'] = "Please fill in all required fields.";
        header("Location: $return_to");
        exit();
    }

    // Validate booking date (not in past)
    if (strtotime($booking_date) < strtotime('today')) {
        $_SESSION['error'] = "Booking date cannot be in the past.";
        header("Location: $return_to");
        exit();
    }

    // Get space description
    $space_result = run_query_or_fail(
        $conn,
        "SELECT * FROM space_availability WHERE availability_id = $space_id",
        $return_to,
        'Space lookup'
    );

    if ($space_result->num_rows == 0) {
        $_SESSION['error'] = "Selected space not found.";
        header("Location: $return_to");
        exit();
    }

    $space = $space_result->fetch_assoc();
    // Escaped: these go into string-built SQL below (e.g. "Founder's Room").
    $space_type = $conn->real_escape_string((string)$space['space_type']);
    $space_name = $conn->real_escape_string((string)$space['space_name']);
    $capacity = $space['capacity'];

    // Validate number of attendees
    if ($number_of_attendees > $capacity) {
        $_SESSION['error'] = "Number of attendees ($number_of_attendees) exceeds space capacity ($capacity).";
        header("Location: $return_to");
        exit();
    }

    // Calculate duration in hours
    $duration_hours = 0;
    if ($booking_type == 'hourly') {
        $start = new DateTime($start_time);
        $end = new DateTime($end_time);
        $duration_hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600; // negative if reversed

        if ($duration_hours <= 0) {
            $_SESSION['error'] = "End time must be after start time.";
            header("Location: $return_to");
            exit();
        }
    } elseif ($booking_type == 'half_day') {
        $duration_hours = 4;
    } elseif ($booking_type == 'full_day') {
        $duration_hours = 8;
    } else {
        $_SESSION['error'] = "Please choose a valid booking type.";
        header("Location: $return_to");
        exit();
    }

    // The overlap check below compares start/end, so they must be in order
    // for every booking type, not just hourly.
    if ($end_time === '' || strtotime($end_time) <= strtotime($start_time)) {
        $_SESSION['error'] = "End time must be after start time.";
        header("Location: $return_to");
        exit();
    }

    // Price is computed here; the amount posted by the form is only a preview.
    $pricing = calculate_booking_amount($conn, $member_id, $space, $booking_type, (float)$duration_hours, $booking_date);
    $total_amount = round((float)$pricing['amount'], 2);

    // Check for conflicts (same space, overlapping time) - this is the
    // authoritative double-booking guard; the calendar UI is just a preview.
    $conflict_result = run_query_or_fail(
        $conn,
        "SELECT * FROM space_bookings 
         WHERE space_name = '$space_name' 
         AND booking_date = '$booking_date' 
         AND booking_status NOT IN ('Cancelled', 'No Show')
         AND (
             (start_time < '$end_time' AND end_time > '$start_time')
         )",
        $return_to,
        'Conflict check'
    );

    if ($conflict_result->num_rows > 0) {
        $_SESSION['error'] = "This space is already booked for the selected time slot. Please choose a different time.";
        header("Location: $return_to");
        exit();
    }

    // Insert booking
    $insert_query = "INSERT INTO space_bookings (
        member_id, space_type, space_name, booking_date, start_time, end_time,
        duration_hours, number_of_attendees, purpose, setup_required,
        equipment_needed, catering_required, catering_description, booking_amount,
        special_requests, payment_status, booking_status
    ) VALUES (
        $member_id, '$space_type', '$space_name', '$booking_date', '$start_time', '$end_time',
        $duration_hours, $number_of_attendees, '$purpose', '$setup_required',
        '$equipment_needed', $catering_required, '$catering_description', $total_amount,
        '$special_requests', 'Pending', 'Pending'
    )";

    if ($conn->query($insert_query)) {
        $booking_id = $conn->insert_id;

        // In-app notification to member
        $notif_query = "INSERT INTO member_notifications (member_id, notification_type, title, message, link_url) 
                       VALUES ($member_id, 'Booking Confirmed', 'Booking Request Received', 
                               'Your booking request for $space_name on $booking_date has been received and is pending confirmation.', 
                               '../my-bookings')";
        if (!$conn->query($notif_query)) {
            error_log("Booking #$booking_id: notification insert failed: " . $conn->error);
        }

        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Create Booking', 'Created booking #$booking_id for $space_name', '{$_SERVER['REMOTE_ADDR']}')";
        if (!$conn->query($log_query)) {
            error_log("Booking #$booking_id: activity log insert failed: " . $conn->error);
        }

        // Build a common booking data array for the email templates
        $booking_email_data = [
            'booking_id'          => $booking_id,
            'space_name'          => $space['space_name'],
            'space_type'          => $space_type,
            'booking_date'        => $booking_date,
            'start_time'          => $start_time,
            'end_time'            => $end_time,
            'duration_hours'      => $duration_hours,
            'number_of_attendees' => $number_of_attendees,
            'purpose'             => $purpose,
            'booking_amount'      => $total_amount,
            'member_name'         => $member_name,
        ];

        // Email the member a confirmation that their request was received.
        // Wrapped in try/catch so a mail-server hiccup never blocks the booking
        // that has already been saved to the database.
        if ($member_email) {
            try {
                send_booking_request_confirmation($member_email, $member_name, $booking_email_data);
            } catch (Throwable $e) {
                error_log("Booking #$booking_id: member confirmation email failed: " . $e->getMessage());
            }
        }

        // Email Operations + Admin so they can review/confirm the booking
        try {
            $recipients = get_booking_notification_recipients($conn);
            if (!empty($recipients)) {
                send_booking_admin_notification($recipients, $booking_email_data, $member_name);
            }
        } catch (Throwable $e) {
            error_log("Booking #$booking_id: admin notification email failed: " . $e->getMessage());
        }

        $_SESSION['success'] = "Booking request submitted successfully! Booking ID: #$booking_id. You will be notified once confirmed.";
        header("Location: ../my-bookings");
    } else {
        error_log("Booking insert failed: " . $conn->error . " | SQL: $insert_query");
        $_SESSION['error'] = "Error creating booking: " . $conn->error;
        header("Location: $return_to");
    }
    exit();
}

// -----------------------------------------------------------------
// UPDATE BOOKING (full-detail edit of a member's own Pending booking)
//
// Unlike RESCHEDULE below (date/time only), this lets the member correct
// attendees, purpose, setup, equipment, catering, and special requests too.
// The space/space_type and pricing (booking_amount) are intentionally left
// untouched here - book-space.php keeps the space read-only in edit mode,
// since space_bookings only stores a space_name/space_type snapshot rather
// than a foreign key back to space_availability, and pricing was fixed at
// creation time. Only allowed while the booking is still Pending; anything
// else must go through cancel + rebook.
// -----------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'update_booking') {

    $booking_id = intval($_POST['booking_id']);
    $return_to = '../' . resolve_return_to();

    // Get member_id from user_id
    $result = run_query_or_fail(
        $conn,
        "SELECT m.member_id, u.full_name, u.email 
         FROM members m 
         JOIN users u ON u.user_id = m.user_id 
         WHERE m.user_id = $user_id",
        $return_to,
        'Member lookup'
    );

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Member profile not found. Please contact administrator.";
        header("Location: $return_to");
        exit();
    }

    $member = $result->fetch_assoc();
    $member_id = $member['member_id'];
    $member_name = $member['full_name'];
    $member_email = $member['email'];

    // Verify booking belongs to this member
    $booking_result = run_query_or_fail(
        $conn,
        "SELECT * FROM space_bookings WHERE booking_id = $booking_id AND member_id = $member_id",
        $return_to,
        'Booking lookup'
    );

    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found or does not belong to you.";
        header("Location: $return_to");
        exit();
    }

    $booking = $booking_result->fetch_assoc();

    // Only Pending bookings can be fully edited this way. A Confirmed booking
    // should go through the normal cancel-and-rebook path (or a future
    // admin-mediated change request) rather than being silently altered here.
    if ($booking['booking_status'] !== 'Pending') {
        $_SESSION['error'] = "Only bookings that are still Pending can be edited. This booking is " . $booking['booking_status'] . ".";
        header("Location: $return_to");
        exit();
    }

    // Get + validate form data
    $booking_date = $conn->real_escape_string(sanitize_input($_POST['booking_date']));
    $start_time = $conn->real_escape_string(sanitize_input($_POST['start_time']));
    $end_time = $conn->real_escape_string(sanitize_input($_POST['end_time']));
    $number_of_attendees = intval($_POST['number_of_attendees']);
    $purpose = $conn->real_escape_string(sanitize_input($_POST['purpose']));
    $setup_required = $conn->real_escape_string(sanitize_input($_POST['setup_required']));
    $equipment_needed = $conn->real_escape_string(sanitize_input($_POST['equipment_needed']));
    $catering_required = isset($_POST['catering_required']) ? 1 : 0;
    $catering_description = $conn->real_escape_string(sanitize_input($_POST['catering_description']));
    $special_requests = $conn->real_escape_string(sanitize_input($_POST['special_requests']));

    if (empty($booking_date) || empty($start_time) || empty($end_time) || empty($purpose)) {
        $_SESSION['error'] = "Please fill in all required fields.";
        header("Location: $return_to");
        exit();
    }

    if (strtotime($booking_date) < strtotime('today')) {
        $_SESSION['error'] = "Booking date cannot be in the past.";
        header("Location: $return_to");
        exit();
    }

    // Duration is always derived directly from start/end here - there's no
    // booking_type selector in edit mode (see book-space.php).
    $start = new DateTime($start_time);
    $end = new DateTime($end_time);
    $duration_hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600; // negative if reversed

    if ($duration_hours <= 0) {
        $_SESSION['error'] = "End time must be after start time.";
        header("Location: $return_to");
        exit();
    }

    // Re-check capacity against the space's current capacity (space isn't
    // changing, but capacity might have been adjusted since the original
    // booking was made).
    $space_result = run_query_or_fail(
        $conn,
        "SELECT capacity FROM space_availability WHERE space_name = '" . $conn->real_escape_string($booking['space_name']) . "' LIMIT 1",
        $return_to,
        'Space capacity lookup'
    );

    if ($space_result->num_rows > 0) {
        $capacity = $space_result->fetch_assoc()['capacity'];
        if ($number_of_attendees > $capacity) {
            $_SESSION['error'] = "Number of attendees ($number_of_attendees) exceeds space capacity ($capacity).";
            header("Location: $return_to");
            exit();
        }
    }

    // Conflict check - same space, same date, overlapping time, excluding
    // this booking's own current row.
    $conflict_result = run_query_or_fail(
        $conn,
        "SELECT * FROM space_bookings 
         WHERE space_name = '" . $conn->real_escape_string($booking['space_name']) . "' 
         AND booking_date = '$booking_date' 
         AND booking_id != $booking_id
         AND booking_status NOT IN ('Cancelled', 'No Show')
         AND (
             (start_time < '$end_time' AND end_time > '$start_time')
         )",
        $return_to,
        'Update conflict check'
    );

    if ($conflict_result->num_rows > 0) {
        $_SESSION['error'] = "This space is already booked for the selected time slot. Please choose a different time.";
        header("Location: $return_to");
        exit();
    }

    $old_date = $booking['booking_date'];
    $old_start = $booking['start_time'];
    $old_end = $booking['end_time'];

    // Stays Pending (it already was), but we still clear confirmed_by/at and
    // reset reminder_sent in case any of those had been set in the meantime -
    // an edited booking should go through confirmation again just like a
    // reschedule does. reminder_sent is optional (only present if the
    // ALTER TABLE ... ADD COLUMN reminder_sent migration has been run) -
    // include it only if it exists, so installs without that migration
    // don't hard-fail here.
    $has_reminder_sent = column_exists($conn, 'space_bookings', 'reminder_sent');

    $update_query = "UPDATE space_bookings SET
                    booking_date = '$booking_date',
                    start_time = '$start_time',
                    end_time = '$end_time',
                    duration_hours = $duration_hours,
                    number_of_attendees = $number_of_attendees,
                    purpose = '$purpose',
                    setup_required = '$setup_required',
                    equipment_needed = '$equipment_needed',
                    catering_required = $catering_required,
                    catering_description = '$catering_description',
                    special_requests = '$special_requests',
                    booking_status = 'Pending',
                    confirmed_by = NULL,
                    confirmed_at = NULL"
                    . ($has_reminder_sent ? ", reminder_sent = 0" : "") . "
                    WHERE booking_id = $booking_id";

    if ($conn->query($update_query)) {
        // In-app notification
        $notif_query = "INSERT INTO member_notifications (member_id, notification_type, title, message, link_url) 
                       VALUES ($member_id, 'Booking Confirmed', 'Booking Updated', 
                               'Your booking for " . $conn->real_escape_string((string)$booking['space_name']) . " has been updated and is pending confirmation.', 
                               '../my-bookings')";
        if (!$conn->query($notif_query)) {
            error_log("Booking #$booking_id: update notification insert failed: " . $conn->error);
        }

        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Update Booking', 'Updated booking #$booking_id details for " . $conn->real_escape_string((string)$booking['space_name']) . "', '{$_SERVER['REMOTE_ADDR']}')";
        if (!$conn->query($log_query)) {
            error_log("Booking #$booking_id: update activity log insert failed: " . $conn->error);
        }

        // Reuse the reschedule email templates - they already communicate
        // "schedule changed, pending re-confirmation" clearly, which still
        // applies even when only non-schedule fields changed (old/new will
        // simply be identical in that case, which reads fine).
        $booking_email_data = [
            'booking_id'  => $booking_id,
            'space_name'  => $booking['space_name'],
            'old_date'    => $old_date,
            'old_start'   => $old_start,
            'old_end'     => $old_end,
            'new_date'    => $booking_date,
            'new_start'   => $start_time,
            'new_end'     => $end_time,
            'member_name' => $member_name,
        ];

        if ($member_email) {
            try {
                send_booking_reschedule_email($member_email, $booking_email_data);
            } catch (Throwable $e) {
                error_log("Booking #$booking_id: update email failed: " . $e->getMessage());
            }
        }

        try {
            $recipients = get_booking_notification_recipients($conn);
            if (!empty($recipients)) {
                send_booking_reschedule_admin_notification($recipients, $booking_email_data, $member_name);
            }
        } catch (Throwable $e) {
            error_log("Booking #$booking_id: update admin notification failed: " . $e->getMessage());
        }

        $_SESSION['success'] = "Booking updated successfully. Waiting for confirmation.";
        header("Location: $return_to");
    } else {
        error_log("Booking #$booking_id: update failed: " . $conn->error);
        $_SESSION['error'] = "Error updating booking: " . $conn->error;
        header("Location: $return_to");
    }
    exit();
}

// CANCEL BOOKING (Member cancellation)
if (isset($_GET['cancel_booking'])) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    $booking_id = intval($_GET['cancel_booking']);

    // Get member_id + name + email from user_id
    $result = run_query_or_fail(
        $conn,
        "SELECT m.member_id, u.full_name, u.email 
         FROM members m 
         JOIN users u ON u.user_id = m.user_id 
         WHERE m.user_id = $user_id",
        '../my-bookings',
        'Member lookup'
    );

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../my-bookings");
        exit();
    }

    $member = $result->fetch_assoc();
    $member_id = $member['member_id'];
    $member_name = $member['full_name'];
    $member_email = $member['email'];

    // Verify booking belongs to this member
    $booking_result = run_query_or_fail(
        $conn,
        "SELECT * FROM space_bookings WHERE booking_id = $booking_id AND member_id = $member_id",
        '../my-bookings',
        'Booking lookup'
    );

    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found or does not belong to you.";
        header("Location: ../my-bookings");
        exit();
    }

    $booking = $booking_result->fetch_assoc();

    // Check if booking can be cancelled (not already cancelled or completed)
    if (in_array($booking['booking_status'], ['Cancelled', 'Completed', 'No Show'])) {
        $_SESSION['error'] = "This booking cannot be cancelled.";
        header("Location: ../my-bookings");
        exit();
    }

    // Check cancellation policy (e.g., must cancel at least 24 hours before)
    $booking_datetime = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
    $hours_until_booking = ($booking_datetime - time()) / 3600;

    if ($hours_until_booking < 24) {
        $_SESSION['error'] = "Bookings must be cancelled at least 24 hours in advance.";
        header("Location: ../my-bookings");
        exit();
    }

    // Update booking status
    $update_query = "UPDATE space_bookings SET 
                    booking_status = 'Cancelled',
                    cancelled_by = $user_id,
                    cancelled_at = NOW(),
                    cancellation_reason = 'Cancelled by member'
                    WHERE booking_id = $booking_id";

    if ($conn->query($update_query)) {
        // In-app notification
        $notif_query = "INSERT INTO member_notifications (member_id, notification_type, title, message) 
                       VALUES ($member_id, 'Booking Confirmed', 'Booking Cancelled', 
                               'Your booking for " . $conn->real_escape_string((string)$booking['space_name']) . " on " . $conn->real_escape_string((string)$booking['booking_date']) . " has been cancelled.')";
        if (!$conn->query($notif_query)) {
            error_log("Booking #$booking_id: cancellation notification insert failed: " . $conn->error);
        }

        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Cancel Booking', 'Cancelled booking #$booking_id', '{$_SERVER['REMOTE_ADDR']}')";
        if (!$conn->query($log_query)) {
            error_log("Booking #$booking_id: cancellation activity log insert failed: " . $conn->error);
        }

        $booking_email_data = [
            'booking_id'   => $booking_id,
            'space_name'   => $booking['space_name'],
            'booking_date' => $booking['booking_date'],
            'start_time'   => $booking['start_time'],
            'end_time'     => $booking['end_time'],
            'member_name'  => $member_name,
        ];

        // Email the member confirming the cancellation
        if ($member_email) {
            try {
                send_booking_cancellation_email($member_email, $booking_email_data);
            } catch (Throwable $e) {
                error_log("Booking #$booking_id: cancellation email failed: " . $e->getMessage());
            }
        }

        // Email Operations + Admin so the slot is known to be free again
        try {
            $recipients = get_booking_notification_recipients($conn);
            if (!empty($recipients)) {
                send_booking_cancellation_admin_notification($recipients, $booking_email_data, $member_name);
            }
        } catch (Throwable $e) {
            error_log("Booking #$booking_id: cancellation admin notification failed: " . $e->getMessage());
        }

        $_SESSION['success'] = "Booking cancelled successfully.";
    } else {
        error_log("Booking #$booking_id: cancellation update failed: " . $conn->error);
        $_SESSION['error'] = "Error cancelling booking: " . $conn->error;
    }

    header("Location: ../my-bookings");
    exit();
}

// RESCHEDULE BOOKING (date/time only - kept for my-bookings.php's existing
// reschedule flow; book-space.php's newer inline editor uses update_booking
// above instead, since it also needs to touch the other fields.)
if (isset($_POST['reschedule_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    $new_date = $conn->real_escape_string(sanitize_input($_POST['new_booking_date']));
    $new_start_time = $conn->real_escape_string(sanitize_input($_POST['new_start_time']));
    $new_end_time = $conn->real_escape_string(sanitize_input($_POST['new_end_time']));

    // Get member_id + name + email from user_id
    $result = run_query_or_fail(
        $conn,
        "SELECT m.member_id, u.full_name, u.email 
         FROM members m 
         JOIN users u ON u.user_id = m.user_id 
         WHERE m.user_id = $user_id",
        '../my-bookings',
        'Member lookup'
    );

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../my-bookings");
        exit();
    }

    $member = $result->fetch_assoc();
    $member_id = $member['member_id'];
    $member_name = $member['full_name'];
    $member_email = $member['email'];

    // Verify booking belongs to this member
    $booking_result = run_query_or_fail(
        $conn,
        "SELECT * FROM space_bookings WHERE booking_id = $booking_id AND member_id = $member_id",
        '../my-bookings',
        'Booking lookup'
    );

    if ($booking_result->num_rows == 0) {
        $_SESSION['error'] = "Booking not found or does not belong to you.";
        header("Location: ../my-bookings");
        exit();
    }

    $booking = $booking_result->fetch_assoc();

    // Validate new date (not in past)
    if (strtotime($new_date) < strtotime('today')) {
        $_SESSION['error'] = "New booking date cannot be in the past.";
        header("Location: ../my-bookings?id=$booking_id");
        exit();
    }

    // Calculate new duration
    $start = new DateTime($new_start_time);
    $end = new DateTime($new_end_time);
    $new_duration = ($end->getTimestamp() - $start->getTimestamp()) / 3600; // negative if reversed

    if ($new_duration <= 0) {
        $_SESSION['error'] = "End time must be after start time.";
        header("Location: ../my-bookings?id=$booking_id");
        exit();
    }

    // Check for conflicts
    $conflict_result = run_query_or_fail(
        $conn,
        "SELECT * FROM space_bookings 
         WHERE space_name = '" . $conn->real_escape_string((string)$booking['space_name']) . "' 
         AND booking_date = '$new_date' 
         AND booking_id != $booking_id
         AND booking_status NOT IN ('Cancelled', 'No Show')
         AND (
             (start_time < '$new_end_time' AND end_time > '$new_start_time')
         )",
        '../my-bookings?id=' . $booking_id,
        'Reschedule conflict check'
    );

    if ($conflict_result->num_rows > 0) {
        $_SESSION['error'] = "This space is already booked for the selected time slot. Please choose a different time.";
        header("Location: ../my-bookings?id=$booking_id");
        exit();
    }

    $old_date = $booking['booking_date'];
    $old_start = $booking['start_time'];
    $old_end = $booking['end_time'];

    // Update booking (reset to Pending status for admin approval).
    // Also reset reminder_sent so a fresh reminder goes out for the new date -
    // only if that column actually exists on this install (see column_exists()
    // above; some installs haven't run that optional migration yet).
    $has_reminder_sent = column_exists($conn, 'space_bookings', 'reminder_sent');

    $update_query = "UPDATE space_bookings SET 
                    booking_date = '$new_date',
                    start_time = '$new_start_time',
                    end_time = '$new_end_time',
                    duration_hours = $new_duration,
                    booking_status = 'Pending',
                    confirmed_by = NULL,
                    confirmed_at = NULL"
                    . ($has_reminder_sent ? ", reminder_sent = 0" : "") . "
                    WHERE booking_id = $booking_id";

    if ($conn->query($update_query)) {
        // In-app notification
        $notif_query = "INSERT INTO member_notifications (member_id, notification_type, title, message) 
                       VALUES ($member_id, 'Booking Confirmed', 'Booking Rescheduled', 
                               'Your booking for " . $conn->real_escape_string((string)$booking['space_name']) . " has been rescheduled to $new_date. Pending confirmation.')";
        if (!$conn->query($notif_query)) {
            error_log("Booking #$booking_id: reschedule notification insert failed: " . $conn->error);
        }

        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Reschedule Booking', 'Rescheduled booking #$booking_id to $new_date', '{$_SERVER['REMOTE_ADDR']}')";
        if (!$conn->query($log_query)) {
            error_log("Booking #$booking_id: reschedule activity log insert failed: " . $conn->error);
        }

        $booking_email_data = [
            'booking_id'  => $booking_id,
            'space_name'  => $booking['space_name'],
            'old_date'    => $old_date,
            'old_start'   => $old_start,
            'old_end'     => $old_end,
            'new_date'    => $new_date,
            'new_start'   => $new_start_time,
            'new_end'     => $new_end_time,
            'member_name' => $member_name,
        ];

        // Email the member confirming the reschedule request
        if ($member_email) {
            try {
                send_booking_reschedule_email($member_email, $booking_email_data);
            } catch (Throwable $e) {
                error_log("Booking #$booking_id: reschedule email failed: " . $e->getMessage());
            }
        }

        // Email Operations + Admin - the booking is Pending again and needs review
        try {
            $recipients = get_booking_notification_recipients($conn);
            if (!empty($recipients)) {
                send_booking_reschedule_admin_notification($recipients, $booking_email_data, $member_name);
            }
        } catch (Throwable $e) {
            error_log("Booking #$booking_id: reschedule admin notification failed: " . $e->getMessage());
        }

        $_SESSION['success'] = "Booking rescheduled successfully. Waiting for confirmation.";
    } else {
        error_log("Booking #$booking_id: reschedule update failed: " . $conn->error);
        $_SESSION['error'] = "Error rescheduling booking: " . $conn->error;
    }

    header("Location: ../my-bookings");
    exit();
}

// If no valid action, redirect
$_SESSION['error'] = "Invalid action.";
header("Location: ../book-space");
exit();
?>