<?php
session_start();
require_once 'config.php';

// mail-function.php is optional here - only used if the relevant send_*
// functions exist, so this file doesn't hard-fail if none are defined yet.
if (file_exists(__DIR__ . '/mail-function.php')) {
    require_once 'mail-function.php';
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to manage your subscription.";
    header("Location: ../login");
    exit();
}

$user_id = $_SESSION['user_id'];

/**
 * Runs a query and, if it fails outright (returns false - a real SQL
 * error), logs the actual MySQL error and stops the request with a
 * session error instead of letting the caller crash trying to use a
 * boolean false as a result set.
 */
function safe_query($conn, $sql, $redirect, $context = 'Database query') {
    $result = $conn->query($sql);
    if ($result === false) {
        error_log("$context failed: {$conn->error} | SQL: $sql");
        $_SESSION['error'] = "$context failed: " . $conn->error;
        header("Location: $redirect");
        exit();
    }
    return $result;
}

/**
 * Confirms the posted member_id actually belongs to the logged-in user,
 * and returns the member row. Prevents a tampered hidden field letting
 * one member modify another member's subscription.
 */
function get_owned_member($conn, $user_id, $member_id, $redirect) {
    $member_id = intval($member_id);
    $result = safe_query(
        $conn,
        "SELECT * FROM members WHERE member_id = $member_id AND user_id = $user_id",
        $redirect,
        'Member ownership check'
    );

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Subscription not found or does not belong to you.";
        header("Location: $redirect");
        exit();
    }

    return $result->fetch_assoc();
}

/**
 * Generates a simple, human-readable invoice number once we know the
 * payment_id (INV-{member_id}-{payment_id}, zero-padded).
 */
function build_invoice_number($member_id, $payment_id) {
    return 'INV-' . str_pad($member_id, 4, '0', STR_PAD_LEFT) . '-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);
}

$redirect_back = '../my-subscription';

// -----------------------------------------------------------------
// RENEW SUBSCRIPTION
// -----------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'renew') {

    $member = get_owned_member($conn, $user_id, $_POST['member_id'] ?? 0, $redirect_back);
    $member_id = $member['member_id'];

    // Same billing-period mapping used on the display page, kept in sync here
    // so the calculated new end date always matches what the member saw.
    $billing_periods = [
        'Daily'     => '+1 day',
        'Weekly'    => '+1 week',
        'Monthly'   => '+1 month',
        'Quarterly' => '+3 months',
        'Annual'    => '+1 year',
    ];

    $plan = $member['subscription_plan'];
    if (!isset($billing_periods[$plan])) {
        $_SESSION['error'] = "Unrecognized subscription plan - cannot calculate renewal period. Please contact administrator.";
        header("Location: $redirect_back");
        exit();
    }

    // Renewal always extends from the current end date, not from today -
    // this way renewing early doesn't cost the member unused days, and
    // renewing late doesn't grant "free" days either.
    $current_end = $member['subscription_end_date'];
    $new_end_timestamp = strtotime($billing_periods[$plan], strtotime($current_end));
    $new_end_date = date('Y-m-d', $new_end_timestamp);

    $amount = floatval($member['subscription_amount']);
    $period_start = date('Y-m-d', strtotime($current_end . ' +1 day'));
    $period_end = $new_end_date;

    // Create the renewal invoice as Pending - the subscription end date
    // itself is NOT extended yet. It only gets extended once the payment
    // is confirmed (see make-payment.php / wherever payments get marked Paid),
    // so a member can't get extra days just by clicking "Renew".
    // invoice_number is NOT NULL and UNIQUE: insert a unique placeholder,
    // then replace it with the real number once payment_id is known.
    $tmp_invoice = 'TMP-' . bin2hex(random_bytes(8));
    $insert_query = "INSERT INTO subscription_payments (
        member_id, invoice_number, subscription_plan, amount, payment_period_start, payment_period_end,
        payment_status, created_at
    ) VALUES (
        $member_id, '$tmp_invoice', '" . $conn->real_escape_string($plan) . "', $amount,
        '$period_start', '$period_end', 'Pending', NOW()
    )";

    if ($conn->query($insert_query)) {
        $payment_id = $conn->insert_id;
        $invoice_number = build_invoice_number($member_id, $payment_id);

        $update_invoice = "UPDATE subscription_payments SET invoice_number = '$invoice_number' WHERE payment_id = $payment_id";
        if (!$conn->query($update_invoice)) {
            error_log("Renewal payment #$payment_id: invoice number update failed: " . $conn->error);
        }

        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Renew Subscription', 'Created renewal invoice #$invoice_number for member #$member_id', '{$_SERVER['REMOTE_ADDR']}')";
        if (!$conn->query($log_query)) {
            error_log("Renewal #$payment_id: activity log insert failed: " . $conn->error);
        }

        // In-app notification
        $notif_query = "INSERT INTO member_notifications (member_id, notification_type, title, message, link_url) 
                       VALUES ($member_id, 'Payment', 'Renewal Invoice Created', 
                               'Your renewal invoice $invoice_number for UGX " . number_format($amount) . " is ready. Complete payment to extend your subscription to " . date('d M Y', $new_end_timestamp) . ".', 
                               '../make-payment?payment_id=$payment_id')";
        if (!$conn->query($notif_query)) {
            error_log("Renewal #$payment_id: notification insert failed: " . $conn->error);
        }

        // Optional email confirmation - only fires if mail-function.php defines it
        if (function_exists('send_subscription_renewal_invoice_email') && !empty($member['email'])) {
            try {
                send_subscription_renewal_invoice_email($member['email'], [
                    'full_name'       => $member['full_name'] ?? '',
                    'invoice_number'  => $invoice_number,
                    'plan'            => $plan,
                    'amount'          => $amount,
                    'period_start'    => $period_start,
                    'period_end'      => $period_end,
                ]);
            } catch (Throwable $e) {
                error_log("Renewal #$payment_id: confirmation email failed: " . $e->getMessage());
            }
        }

        $_SESSION['success'] = "Renewal invoice #$invoice_number created. Please complete payment to extend your subscription.";
        header("Location: ../make-payment?payment_id=$payment_id");
    } else {
        error_log("Renewal insert failed: " . $conn->error . " | SQL: $insert_query");
        $_SESSION['error'] = "Error creating renewal invoice. Please try again or contact the administrator.";
        header("Location: $redirect_back");
    }
    exit();
}

// -----------------------------------------------------------------
// UPGRADE PLAN
// -----------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'upgrade') {

    $member = get_owned_member($conn, $user_id, $_POST['member_id'] ?? 0, $redirect_back);
    $member_id = $member['member_id'];

    $new_plan = sanitize_input($_POST['new_plan'] ?? '');
    $valid_plans = ['Weekly', 'Monthly', 'Quarterly', 'Annual'];

    if (!in_array($new_plan, $valid_plans) || $new_plan === $member['subscription_plan']) {
        $_SESSION['error'] = "Please select a valid, different plan to upgrade to.";
        header("Location: $redirect_back");
        exit();
    }

    $old_plan = $member['subscription_plan'];

    /*
     * There is no plan price table: each member's subscription_amount is
     * set by staff in Hub Operations. Switching the plan here without a
     * price meant the next renewal billed the new period at the old amount
     * (e.g. a year at the weekly price). So a member-initiated change is a
     * request: staff are notified and apply the new plan + amount.
     */
    $member_name = (string)($member['full_name'] ?? $member['company_name'] ?? ('Member #' . $member_id));
    $staff = $conn->query("SELECT user_id FROM users WHERE is_active = 1 AND role IN ('Administrator', 'Operations/Admin', 'Finance', 'Accountant')");
    $notified = 0;
    if ($staff) {
        while ($row = $staff->fetch_assoc()) {
            if (notify_user(
                (int)$row['user_id'],
                'Plan change requested',
                "$member_name asked to change their subscription from $old_plan to $new_plan. Update the plan and amount in Hub Operations.",
                'info',
                (int)$member_id,
                'member'
            )) {
                $notified++;
            }
        }
    }

    log_action($user_id, 'Request Plan Change', 'members', (int)$member_id, "Requested change from $old_plan to $new_plan");

    $member_msg = $conn->real_escape_string("Your request to change from $old_plan to $new_plan has been sent. The team will confirm the new amount with you.");
    if (!$conn->query("INSERT INTO member_notifications (member_id, notification_type, title, message, link_url)
                       VALUES ($member_id, 'Payment', 'Plan Change Requested', '$member_msg', '../my-subscription')")) {
        error_log("Plan change request #$member_id: notification insert failed: " . $conn->error);
    }

    if ($notified > 0) {
        $_SESSION['success'] = "Your request to change to the $new_plan plan has been sent. The team will confirm the new amount with you.";
    } else {
        error_log("Plan change request #$member_id: no staff could be notified");
        $_SESSION['error'] = "We could not send your plan change request. Please contact the administrator.";
    }
    header("Location: $redirect_back");
    exit();
}

// -----------------------------------------------------------------
// TOGGLE AUTO-RENEW
// -----------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'toggle_auto_renew') {

    $member = get_owned_member($conn, $user_id, $_POST['member_id'] ?? 0, $redirect_back);
    $member_id = $member['member_id'];

    $auto_renew = isset($_POST['auto_renew']) ? 1 : 0;

    $update_query = "UPDATE members SET auto_renew = $auto_renew WHERE member_id = $member_id";

    if ($conn->query($update_query)) {
        // Log activity
        $state = $auto_renew ? 'enabled' : 'disabled';
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Update Auto-Renew', 'Auto-renew $state for member #$member_id', '{$_SERVER['REMOTE_ADDR']}')";
        if (!$conn->query($log_query)) {
            error_log("Auto-renew #$member_id: activity log insert failed: " . $conn->error);
        }

        // In-app notification
        $notif_query = "INSERT INTO member_notifications (member_id, notification_type, title, message) 
                       VALUES ($member_id, 'General', 'Auto-Renew Settings Updated', 
                               'Automatic subscription renewal has been $state.')";
        if (!$conn->query($notif_query)) {
            error_log("Auto-renew #$member_id: notification insert failed: " . $conn->error);
        }

        $_SESSION['success'] = "Auto-renew has been $state.";
        header("Location: $redirect_back");
    } else {
        error_log("Auto-renew #$member_id: update failed: " . $conn->error);
        $_SESSION['error'] = "Error updating auto-renew settings: " . $conn->error;
        header("Location: $redirect_back");
    }
    exit();
}

// If no valid action, redirect
$_SESSION['error'] = "Invalid action.";
header("Location: $redirect_back");
exit();
?>