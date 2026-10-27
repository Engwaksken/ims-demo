<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail-function.php';

if (
    !isset($_SESSION['user_id'])
    ||
    !ims_allowed('hub_operations.manage', ['Administrator', 'Operations/Admin'], 'Add and edit hub members, subscriptions and receipts')
) {
    $_SESSION['error'] = 'Unauthorized access.';
    header('Location: ../hub-operations');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['error'] = "Database connection not available.";
    header("Location: ../hub-operations");
    exit();
}

$conn->set_charset('utf8mb4');

$user_id = (int) $_SESSION['user_id'];
$role    = (string) $_SESSION['role'];
$tab     = isset($_GET['tab']) ? sanitize_input((string)$_GET['tab']) : 'members';
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

function redirect_tab(string $tab, ?string $error = null, ?string $success = null): never
{
    if ($error !== null) {
        $_SESSION['error'] = $error;
    }
    if ($success !== null) {
        $_SESSION['success'] = $success;
    }

    header("Location: ../hub-operations?tab=" . urlencode($tab));
    exit();
}

function postv(string $key, string $default = ''): string
{
    return sanitize_input((string)($_POST[$key] ?? $default));
}

function postf(string $key, float $default = 0): float
{
    return (float)($_POST[$key] ?? $default);
}

function posti(string $key, int $default = 0): int
{
    return (int)($_POST[$key] ?? $default);
}

function geti(string $key, int $default = 0): int
{
    return (int)($_GET[$key] ?? $default);
}

function null_if_empty(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function valid_date_or_null(string $date): ?string
{
    $date = trim($date);
    if ($date === '') {
        return null;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : null;
}

function valid_datetime_or_null(string $datetime): ?string
{
    $datetime = trim($datetime);
    if ($datetime === '') {
        return null;
    }

    $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $d = DateTime::createFromFormat($format, $datetime);
        if ($d) {
            if ($format === 'Y-m-d\TH:i' || $format === 'Y-m-d H:i') {
                return $d->format('Y-m-d H:i:s');
            }
            return $d->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function logActivity(mysqli $conn, int $user_id, string $action, string $description, string $ip): void
{
    $stmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, description, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param("isss", $user_id, $action, $description, $ip);
    $stmt->execute();
    $stmt->close();
}

function fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function execute_stmt(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception($err);
    }

    return $stmt;
}

function hub_temp_password(int $length = 14): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%';
    $max = strlen($chars) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

function hub_send_member_credentials(string $email, string $fullName, string $membershipNo, string $plainPassword): string|bool
{
    if (!function_exists('sendEmail') || !function_exists('email_wrapper')) {
        return 'Mail functions unavailable';
    }

    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $loginUrl = $host !== '' ? $scheme . '://' . $host . '/login' : 'https://ims.kemmytech.com/login';

    $safeName     = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeEmail    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeNo       = htmlspecialchars($membershipNo, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
    $safeUrl      = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $content = "
        <h3>Welcome to Hive Colab</h3>
        <p>Hi {$safeName},</p>
        <p>Your hub membership ({$safeNo}) has been registered and a member account has been created for you.</p>
        <div class='cred-box'>
            <h4>Your Login Credentials</h4>
            <p style='margin:0 0 8px;font-size:14px;'><strong>Email:</strong><br>{$safeEmail}</p>
            <p style='margin:8px 0 4px;font-size:14px;'><strong>Temporary Password:</strong></p>
            <div class='password'>{$safePassword}</div>
            <div class='warning'><strong>Important:</strong> Please change this password after your first login.</div>
        </div>
        <a href='{$safeUrl}' class='btn'>Log In Now</a>
        <p>If the button above does not work, use this link:</p>
        <p><a href='{$safeUrl}'>{$safeUrl}</a></p>
        <p>Best regards,<br><strong>Hive Colab Team</strong></p>
    ";

    $altBody = "Welcome to Hive Colab\n"
        . "Membership: {$membershipNo}\n"
        . "Email: {$email}\n"
        . "Temporary Password: {$plainPassword}\n\n"
        . "Login here: {$loginUrl}\n"
        . "Please change your password after logging in.";

    return sendEmail($email, 'Your Hive Colab Member Account', email_wrapper($content), $altBody);
}

/* =========================
   ADD MEMBER
========================= */
if (isset($_POST['add_member'])) {
    $full_name   = postv('full_name');
    $email       = postv('email');
    $phone       = postv('phone');
    $member_type = postv('member_type');
    $company     = postv('company_name');
    $industry    = postv('industry');
    $plan        = postv('subscription_plan');
    $amount      = postf('subscription_amount');
    $start       = valid_date_or_null(postv('subscription_start_date'));
    $end         = valid_date_or_null(postv('subscription_end_date'));
    $status      = postv('membership_status');

    if ($full_name === '' || $email === '' || $plan === '') {
        redirect_tab('members', 'Name, email and subscription plan are required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect_tab('members', 'Invalid email address.');
    }

    $conn->begin_transaction();

    try {
        $exists = fetch_one($conn, "SELECT user_id FROM users WHERE email = ? LIMIT 1", "s", [$email]);
        if ($exists) {
            throw new Exception("User with this email already exists.");
        }

        $plain_password = hub_temp_password();
        $password = password_hash($plain_password, PASSWORD_DEFAULT);

        $stmt = execute_stmt(
            $conn,
            "INSERT INTO users (username, full_name, email, password_hash, role, is_active)
             VALUES (?, ?, ?, ?, 'Member', 1)",
            "ssss",
            [$full_name, $full_name, $email, $password]
        );
        $new_user_id = $stmt->insert_id;
        $stmt->close();

        $countRow = fetch_one($conn, "SELECT COUNT(*) AS c FROM members");
        $count = ((int)($countRow['c'] ?? 0)) + 1;
        $membership_no = 'MEM-' . date('Y') . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);

        $stmt = execute_stmt(
            $conn,
            "INSERT INTO members (
                user_id,
                membership_number,
                member_type,
                company_name,
                industry,
                registration_date,
                membership_status,
                subscription_plan,
                subscription_amount,
                subscription_start_date,
                subscription_end_date,
                emergency_contact
            ) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)",
            "issssssdsss",
            [
                $new_user_id,
                $membership_no,
                $member_type,
                $company,
                $industry,
                $status,
                $plan,
                $amount,
                $start,
                $end,
                $phone
            ]
        );
        $stmt->close();

        logActivity($conn, $user_id, 'Add Member', "Added member $membership_no", $ip);

        $conn->commit();

        $mailResult = hub_send_member_credentials($email, $full_name, $membership_no, $plain_password);
        unset($plain_password);
        if ($mailResult !== true) {
            error_log('Hub member credentials email failed for ' . $email . ': ' . (is_string($mailResult) ? $mailResult : 'unknown error'));
            redirect_tab(
                'members',
                "Member $membership_no was added, but the login email could not be sent. Reset the member's password from Users to give them access.",
                null
            );
        }

        redirect_tab('members', null, "Member added successfully! #: $membership_no. Login details were emailed to the member.");
    } catch (Exception $e) {
        $conn->rollback();
        redirect_tab('members', $e->getMessage());
    }
}

/* =========================
   EDIT MEMBER
========================= */
if (isset($_POST['edit_member'])) {
    $member_id                = posti('member_id');
    $full_name                = postv('full_name');
    $email                    = postv('email');
    $phone                    = postv('phone');
    $member_type              = postv('member_type');
    $company_name             = postv('company_name');
    $industry                 = postv('industry');
    $subscription_plan        = postv('subscription_plan');
    $subscription_amount      = postf('subscription_amount');
    $subscription_start_date  = valid_date_or_null(postv('subscription_start_date'));
    $subscription_end_date    = valid_date_or_null(postv('subscription_end_date'));
    $membership_status        = postv('membership_status');

    if ($member_id <= 0) {
        redirect_tab('members', 'Invalid member selected.');
    }

    if ($full_name === '' || $email === '') {
        redirect_tab('members', 'Name and email are required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect_tab('members', 'Invalid email address.');
    }

    $member = fetch_one(
        $conn,
        "SELECT membership_number, user_id FROM members WHERE member_id = ? LIMIT 1",
        "i",
        [$member_id]
    );

    if (!$member) {
        redirect_tab('members', 'Member not found.');
    }

    $membership_number = (string)$member['membership_number'];
    $member_user_id    = (int)$member['user_id'];

    $duplicate = fetch_one(
        $conn,
        "SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1",
        "si",
        [$email, $member_user_id]
    );

    if ($duplicate) {
        redirect_tab('members', 'Another user already uses this email address.');
    }

    $conn->begin_transaction();

    try {
        $stmt = execute_stmt(
            $conn,
            "UPDATE members SET
                member_type = ?,
                company_name = ?,
                industry = ?,
                subscription_plan = ?,
                subscription_amount = ?,
                subscription_start_date = ?,
                subscription_end_date = ?,
                membership_status = ?,
                emergency_contact = ?
             WHERE member_id = ?",
            "ssssdssssi",
            [
                $member_type,
                $company_name,
                $industry,
                $subscription_plan,
                $subscription_amount,
                $subscription_start_date,
                $subscription_end_date,
                $membership_status,
                $phone,
                $member_id
            ]
        );
        $stmt->close();

        $stmt = execute_stmt(
            $conn,
            "UPDATE users SET
                username = ?,
                full_name = ?,
                email = ?
             WHERE user_id = ?",
            "sssi",
            [$full_name, $full_name, $email, $member_user_id]
        );
        $stmt->close();

        logActivity($conn, $user_id, 'Edit Member', "Updated member: $membership_number", $ip);

        $conn->commit();
        redirect_tab('members', null, 'Member updated successfully!');
    } catch (Exception $e) {
        $conn->rollback();
        redirect_tab('members', 'Error updating member: ' . $e->getMessage());
    }
}

/* =========================
   DELETE MEMBER
========================= */
if (isset($_GET['delete_member']) && ims_allowed('members.delete', ['Administrator'], 'Delete members')) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    $member_id = geti('delete_member');

    if ($member_id <= 0) {
        redirect_tab('members', 'Invalid member selected.');
    }

    $member = fetch_one(
        $conn,
        "SELECT m.membership_number, m.user_id, u.full_name
         FROM members m
         LEFT JOIN users u ON m.user_id = u.user_id
         WHERE m.member_id = ?
         LIMIT 1",
        "i",
        [$member_id]
    );

    if (!$member) {
        redirect_tab('members', 'Member not found.');
    }

    $membership_number = (string)($member['membership_number'] ?? '');
    $member_user_id    = (int)($member['user_id'] ?? 0);
    $full_name         = (string)($member['full_name'] ?? '');

    $conn->begin_transaction();

    try {
        $stmt = execute_stmt($conn, "DELETE FROM members WHERE member_id = ?", "i", [$member_id]);
        $stmt->close();

        if ($member_user_id > 0) {
            $stmt = execute_stmt($conn, "DELETE FROM users WHERE user_id = ?", "i", [$member_user_id]);
            $stmt->close();
        }

        logActivity($conn, $user_id, 'Delete Member', "Deleted member: $full_name ($membership_number)", $ip);

        $conn->commit();
        redirect_tab('members', null, 'Member deleted successfully.');
    } catch (Exception $e) {
        $conn->rollback();
        redirect_tab('members', 'Error deleting member: ' . $e->getMessage());
    }
}

/* =========================
   ADD SUBSCRIPTION PAYMENT
========================= */
if (isset($_POST['add_subscription'])) {
    $member_id            = posti('member_id');
    $subscription_plan    = postv('subscription_plan');
    $amount               = postf('amount');
    $payment_period_start = valid_date_or_null(postv('payment_period_start'));
    $payment_period_end   = valid_date_or_null(postv('payment_period_end'));
    $payment_date         = valid_date_or_null(postv('payment_date'));
    $payment_method       = null_if_empty(postv('payment_method'));
    $payment_reference    = null_if_empty(postv('payment_reference'));
    $payment_status       = postv('payment_status');
    $notes                = null_if_empty(postv('notes'));

    if ($member_id <= 0 || $subscription_plan === '' || $amount <= 0) {
        redirect_tab('subscriptions', 'Member, plan, and amount are required.');
    }

    $memberExists = fetch_one($conn, "SELECT member_id FROM members WHERE member_id = ? LIMIT 1", "i", [$member_id]);
    if (!$memberExists) {
        redirect_tab('subscriptions', 'Selected member was not found.');
    }

    $countRow = fetch_one(
        $conn,
        "SELECT COUNT(*) AS count FROM subscription_payments WHERE YEAR(created_at) = YEAR(CURDATE())"
    );
    $count = ((int)($countRow['count'] ?? 0)) + 1;
    $invoice_number = 'INV-' . date('Ym') . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);

    try {
        $stmt = execute_stmt(
            $conn,
            "INSERT INTO subscription_payments (
                member_id,
                invoice_number,
                subscription_plan,
                amount,
                payment_period_start,
                payment_period_end,
                payment_date,
                payment_method,
                payment_reference,
                payment_status,
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            "issdsssssss",
            [
                $member_id,
                $invoice_number,
                $subscription_plan,
                $amount,
                $payment_period_start,
                $payment_period_end,
                $payment_date,
                $payment_method,
                $payment_reference,
                $payment_status,
                $notes
            ]
        );
        $stmt->close();

        logActivity($conn, $user_id, 'Add Subscription Payment', "Added subscription payment: $invoice_number", $ip);

        redirect_tab('subscriptions', null, "Subscription payment added successfully! Invoice #: $invoice_number");
    } catch (Exception $e) {
        redirect_tab('subscriptions', 'Error adding subscription payment: ' . $e->getMessage());
    }
}

/* =========================
   EDIT SUBSCRIPTION PAYMENT
========================= */
if (isset($_POST['edit_subscription'])) {
    $payment_id            = posti('payment_id');
    $member_id             = posti('member_id');
    $subscription_plan     = postv('subscription_plan');
    $amount                = postf('amount');
    $payment_period_start  = valid_date_or_null(postv('payment_period_start'));
    $payment_period_end    = valid_date_or_null(postv('payment_period_end'));
    $payment_date          = valid_date_or_null(postv('payment_date'));
    $payment_method        = null_if_empty(postv('payment_method'));
    $payment_reference     = null_if_empty(postv('payment_reference'));
    $payment_status        = postv('payment_status');
    $notes                 = null_if_empty(postv('notes'));

    if ($payment_id <= 0) {
        redirect_tab('subscriptions', 'Invalid payment selected.');
    }

    $payment = fetch_one(
        $conn,
        "SELECT invoice_number FROM subscription_payments WHERE payment_id = ? LIMIT 1",
        "i",
        [$payment_id]
    );

    if (!$payment) {
        redirect_tab('subscriptions', 'Payment not found.');
    }

    $invoice_number = (string)$payment['invoice_number'];

    try {
        $stmt = execute_stmt(
            $conn,
            "UPDATE subscription_payments SET
                member_id = ?,
                subscription_plan = ?,
                amount = ?,
                payment_period_start = ?,
                payment_period_end = ?,
                payment_date = ?,
                payment_method = ?,
                payment_reference = ?,
                payment_status = ?,
                notes = ?
             WHERE payment_id = ?",
            "isdsssssssi",
            [
                $member_id,
                $subscription_plan,
                $amount,
                $payment_period_start,
                $payment_period_end,
                $payment_date,
                $payment_method,
                $payment_reference,
                $payment_status,
                $notes,
                $payment_id
            ]
        );
        $stmt->close();

        logActivity($conn, $user_id, 'Edit Subscription Payment', "Updated subscription payment: $invoice_number", $ip);

        redirect_tab('subscriptions', null, 'Subscription payment updated successfully!');
    } catch (Exception $e) {
        redirect_tab('subscriptions', 'Error updating subscription payment: ' . $e->getMessage());
    }
}

/* =========================
   DELETE SUBSCRIPTION PAYMENT
========================= */
if (isset($_GET['delete_subscription']) && ims_allowed('subscriptions.delete', ['Administrator'], 'Delete subscription payments')) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    $payment_id = geti('delete_subscription');

    if ($payment_id <= 0) {
        redirect_tab('subscriptions', 'Invalid payment selected.');
    }

    $payment = fetch_one(
        $conn,
        "SELECT invoice_number FROM subscription_payments WHERE payment_id = ? LIMIT 1",
        "i",
        [$payment_id]
    );

    if (!$payment) {
        redirect_tab('subscriptions', 'Payment not found.');
    }

    $invoice_number = (string)$payment['invoice_number'];

    try {
        $stmt = execute_stmt($conn, "DELETE FROM subscription_payments WHERE payment_id = ?", "i", [$payment_id]);
        $stmt->close();

        logActivity($conn, $user_id, 'Delete Subscription Payment', "Deleted payment: $invoice_number", $ip);

        redirect_tab('subscriptions', null, 'Subscription payment deleted successfully.');
    } catch (Exception $e) {
        redirect_tab('subscriptions', 'Error deleting payment: ' . $e->getMessage());
    }
}

/* =========================
   APPROVE BOOKING
========================= */
if (isset($_POST['approve_booking'])) {
    $booking_id = posti('booking_id');

    if ($booking_id <= 0) {
        redirect_tab(
            'bookings',
            'Invalid booking selected.'
        );
    }

    $conn->begin_transaction();

    try {
        $booking = fetch_one(
            $conn,
            "SELECT
                sb.*,
                m.user_id AS member_user_id,
                u.full_name AS member_name,
                u.email AS member_email
             FROM space_bookings sb
             LEFT JOIN members m
                ON sb.member_id = m.member_id
             LEFT JOIN users u
                ON m.user_id = u.user_id
             WHERE sb.booking_id = ?
             LIMIT 1
             FOR UPDATE",
            'i',
            [$booking_id]
        );

        if (!$booking) {
            throw new Exception(
                'Booking request was not found.'
            );
        }

        if (
            ($booking['booking_status'] ?? '')
            !==
            'Pending'
        ) {
            throw new Exception(
                'Only Pending booking requests can be approved.'
            );
        }

        /*
         * Final overlap check before approval.
         * Ignore this booking itself and ensure another Pending/Confirmed
         * request has not occupied the slot meanwhile.
         */
        $conflict = fetch_one(
            $conn,
            "SELECT booking_id
             FROM space_bookings
             WHERE booking_id <> ?
               AND space_type = ?
               AND space_name = ?
               AND booking_date = ?
               AND booking_status IN ('Pending', 'Confirmed')
               AND start_time < ?
               AND end_time > ?
             LIMIT 1
             FOR UPDATE",
            'isssss',
            [
                $booking_id,
                (string)$booking['space_type'],
                (string)$booking['space_name'],
                (string)$booking['booking_date'],
                (string)$booking['end_time'],
                (string)$booking['start_time'],
            ]
        );

        if ($conflict) {
            throw new Exception(
                'This slot now conflicts with another booking. Reject or reschedule the request instead.'
            );
        }

        $stmt = execute_stmt(
            $conn,
            "UPDATE space_bookings
             SET
                booking_status = 'Confirmed',
                confirmed_by = ?,
                confirmed_at = NOW(),
                rejection_reason = NULL,
                rejected_by = NULL,
                rejected_at = NULL
             WHERE booking_id = ?",
            'ii',
            [
                $user_id,
                $booking_id,
            ]
        );

        $stmt->close();

        if (!empty($booking['member_id'])) {
            $memberIdNotif =
                (int)$booking['member_id'];

            $title =
                'Booking Approved';

            $message =
                'Your booking for '
                . ($booking['space_name'] ?? $booking['space_type'] ?? 'Hive space')
                . ' on '
                . ($booking['booking_date'] ?? '')
                . ' has been approved.';

            $link =
                'hub-events?tab=my-bookings';

            $stmt = execute_stmt(
                $conn,
                "INSERT INTO member_notifications
                 (
                    member_id,
                    notification_type,
                    title,
                    message,
                    link_url
                 )
                 VALUES (?, 'Space Booking', ?, ?, ?)",
                'isss',
                [
                    $memberIdNotif,
                    $title,
                    $message,
                    $link,
                ]
            );

            $stmt->close();
        }

        logActivity(
            $conn,
            $user_id,
            'Approve Booking',
            "Approved booking #{$booking_id}",
            $ip
        );

        $conn->commit();

        send_booking_approved_email(
            (string)(
                $booking['member_email']
                ?? ''
            ),
            (string)(
                $booking['member_name']
                ?? 'Member'
            ),
            $booking
        );

        redirect_tab(
            'bookings',
            null,
            'Booking approved successfully. The member was notified by email and in IMS.'
        );
    } catch (Throwable $e) {
        $conn->rollback();

        redirect_tab(
            'bookings',
            'Error approving booking: '
            . $e->getMessage()
        );
    }
}

/* =========================
   REJECT BOOKING
========================= */
if (isset($_POST['reject_booking'])) {
    $booking_id =
        posti('booking_id');

    $reason =
        postv('rejection_reason');

    if ($booking_id <= 0) {
        redirect_tab(
            'bookings',
            'Invalid booking selected.'
        );
    }

    if ($reason === '') {
        redirect_tab(
            'bookings',
            'A rejection reason is required.'
        );
    }

    $conn->begin_transaction();

    try {
        $booking = fetch_one(
            $conn,
            "SELECT
                sb.*,
                u.full_name AS member_name,
                u.email AS member_email
             FROM space_bookings sb
             LEFT JOIN members m
                ON sb.member_id = m.member_id
             LEFT JOIN users u
                ON m.user_id = u.user_id
             WHERE sb.booking_id = ?
             LIMIT 1
             FOR UPDATE",
            'i',
            [$booking_id]
        );

        if (!$booking) {
            throw new Exception(
                'Booking request was not found.'
            );
        }

        if (
            ($booking['booking_status'] ?? '')
            !==
            'Pending'
        ) {
            throw new Exception(
                'Only Pending booking requests can be rejected.'
            );
        }

        $stmt = execute_stmt(
            $conn,
            "UPDATE space_bookings
             SET
                booking_status = 'Rejected',
                rejection_reason = ?,
                rejected_by = ?,
                rejected_at = NOW()
             WHERE booking_id = ?",
            'sii',
            [
                $reason,
                $user_id,
                $booking_id,
            ]
        );

        $stmt->close();

        if (!empty($booking['member_id'])) {
            $memberIdNotif =
                (int)$booking['member_id'];

            $title =
                'Booking Request Rejected';

            $message =
                'Your booking request for '
                . ($booking['space_name'] ?? $booking['space_type'] ?? 'Hive space')
                . ' on '
                . ($booking['booking_date'] ?? '')
                . ' was not approved. Reason: '
                . $reason;

            $link =
                'hub-events?tab=my-bookings';

            $stmt = execute_stmt(
                $conn,
                "INSERT INTO member_notifications
                 (
                    member_id,
                    notification_type,
                    title,
                    message,
                    link_url
                 )
                 VALUES (?, 'Space Booking', ?, ?, ?)",
                'isss',
                [
                    $memberIdNotif,
                    $title,
                    $message,
                    $link,
                ]
            );

            $stmt->close();
        }

        logActivity(
            $conn,
            $user_id,
            'Reject Booking',
            "Rejected booking #{$booking_id}: {$reason}",
            $ip
        );

        $conn->commit();

        send_booking_rejected_email(
            (string)(
                $booking['member_email']
                ?? ''
            ),
            (string)(
                $booking['member_name']
                ?? 'Member'
            ),
            $booking,
            $reason
        );

        redirect_tab(
            'bookings',
            null,
            'Booking rejected. The member was notified by email and in IMS.'
        );
    } catch (Throwable $e) {
        $conn->rollback();

        redirect_tab(
            'bookings',
            'Error rejecting booking: '
            . $e->getMessage()
        );
    }
}

/* =========================
   ADD RECEIPT
========================= */
if (isset($_POST['add_receipt'])) {
    $member_id   = posti('member_id');
    $type        = postv('receipt_type');
    $amount      = postf('amount');
    $date        = valid_date_or_null(postv('payment_date'));
    $method      = postv('payment_method');
    $reference   = null_if_empty(postv('transaction_reference'));
    $status      = postv('verification_status');
    $notes       = null_if_empty(postv('notes'));

    if ($member_id <= 0 || $type === '' || $amount <= 0 || $date === null || $method === '') {
        redirect_tab('receipts', 'Required fields are missing.');
    }

    $countRow = fetch_one(
        $conn,
        "SELECT COUNT(*) AS c FROM payment_receipts WHERE YEAR(created_at) = YEAR(CURDATE())"
    );
    $count = ((int)($countRow['c'] ?? 0)) + 1;
    $receipt_no = 'RCP-' . date('Y') . '-' . str_pad((string)$count, 5, '0', STR_PAD_LEFT);

    $file_path = null;
    if (!empty($_FILES['receipt_file']['name'])) {
        $dir = dirname(__DIR__) . '/uploads/receipts/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Validate type/content: previously any extension (incl. .php) was kept.
        $receiptCheck = ims_validate_upload($_FILES['receipt_file'], ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (!$receiptCheck['ok']) {
            redirect_tab('receipts', 'Receipt upload rejected: ' . $receiptCheck['error']);
        }
        $ext  = $receiptCheck['extension'];
        $name = 'receipt_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . $name;

        if (is_uploaded_file($_FILES['receipt_file']['tmp_name']) && move_uploaded_file($_FILES['receipt_file']['tmp_name'], $dest)) {
            $file_path = 'uploads/receipts/' . $name;
        }
    }

    $verified_by = ($status === 'Verified') ? $user_id : null;
    $verified_at = ($status === 'Verified') ? date('Y-m-d H:i:s') : null;

    try {
        $stmt = execute_stmt(
            $conn,
            "INSERT INTO payment_receipts (
                member_id,
                receipt_type,
                receipt_number,
                amount,
                payment_date,
                payment_method,
                transaction_reference,
                receipt_file_path,
                verification_status,
                verified_by,
                verified_at,
                notes,
                uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            "issdsssssissi",
            [
                $member_id,
                $type,
                $receipt_no,
                $amount,
                $date,
                $method,
                $reference,
                $file_path,
                $status,
                $verified_by,
                $verified_at,
                $notes,
                $user_id
            ]
        );
        $stmt->close();

        logActivity($conn, $user_id, 'Add Receipt', "Added receipt $receipt_no", $ip);

        redirect_tab('receipts', null, "Receipt added successfully! #: $receipt_no");
    } catch (Exception $e) {
        redirect_tab('receipts', 'Failed to add receipt: ' . $e->getMessage());
    }
}

/* =========================
   EDIT RECEIPT
========================= */
if (isset($_POST['edit_receipt'])) {
    $receipt_id  = posti('receipt_id');
    $member_id   = posti('member_id');
    $type        = postv('receipt_type');
    $amount      = postf('amount');
    $date        = valid_date_or_null(postv('payment_date'));
    $method      = postv('payment_method');
    $reference   = null_if_empty(postv('transaction_reference'));
    $status      = postv('verification_status');
    $notes       = null_if_empty(postv('notes'));

    if ($receipt_id <= 0) {
        redirect_tab('receipts', 'Invalid receipt selected.');
    }

    $receipt = fetch_one(
        $conn,
        "SELECT receipt_file_path FROM payment_receipts WHERE receipt_id = ? LIMIT 1",
        "i",
        [$receipt_id]
    );

    if (!$receipt) {
        redirect_tab('receipts', 'Receipt not found.');
    }

    $file_path = $receipt['receipt_file_path'] ?? null;

    if (!empty($_FILES['receipt_file']['name'])) {
        if ($file_path && file_exists(dirname(__DIR__) . '/' . $file_path)) {
            @unlink(dirname(__DIR__) . '/' . $file_path);
        }

        $dir = dirname(__DIR__) . '/uploads/receipts/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Validate type/content: previously any extension (incl. .php) was kept.
        $receiptCheck = ims_validate_upload($_FILES['receipt_file'], ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (!$receiptCheck['ok']) {
            redirect_tab('receipts', 'Receipt upload rejected: ' . $receiptCheck['error']);
        }
        $ext  = $receiptCheck['extension'];
        $name = 'receipt_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . $name;

        if (is_uploaded_file($_FILES['receipt_file']['tmp_name']) && move_uploaded_file($_FILES['receipt_file']['tmp_name'], $dest)) {
            $file_path = 'uploads/receipts/' . $name;
        }
    }

    $verified_by = ($status === 'Verified') ? $user_id : null;
    $verified_at = ($status === 'Verified') ? date('Y-m-d H:i:s') : null;

    try {
        $stmt = execute_stmt(
            $conn,
            "UPDATE payment_receipts SET
                member_id = ?,
                receipt_type = ?,
                amount = ?,
                payment_date = ?,
                payment_method = ?,
                transaction_reference = ?,
                receipt_file_path = ?,
                verification_status = ?,
                verified_by = ?,
                verified_at = ?,
                notes = ?
             WHERE receipt_id = ?",
            "isdsssssissi",
            [
                $member_id,
                $type,
                $amount,
                $date,
                $method,
                $reference,
                $file_path,
                $status,
                $verified_by,
                $verified_at,
                $notes,
                $receipt_id
            ]
        );
        $stmt->close();

        logActivity($conn, $user_id, 'Edit Receipt', "Updated receipt #$receipt_id", $ip);

        redirect_tab('receipts', null, 'Receipt updated successfully.');
    } catch (Exception $e) {
        redirect_tab('receipts', 'Update failed: ' . $e->getMessage());
    }
}

/* =========================
   DELETE RECEIPT
========================= */
if (isset($_GET['delete_receipt']) && ims_allowed('hub_operations.delete_receipts', ['Administrator'], 'Delete payment receipts')) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    $receipt_id = geti('delete_receipt');

    if ($receipt_id <= 0) {
        redirect_tab('receipts', 'Invalid receipt selected.');
    }

    $receipt = fetch_one(
        $conn,
        "SELECT receipt_file_path, receipt_number FROM payment_receipts WHERE receipt_id = ? LIMIT 1",
        "i",
        [$receipt_id]
    );

    if (!$receipt) {
        redirect_tab('receipts', 'Receipt not found.');
    }

    try {
        $stmt = execute_stmt($conn, "DELETE FROM payment_receipts WHERE receipt_id = ?", "i", [$receipt_id]);
        $stmt->close();

        if (!empty($receipt['receipt_file_path'])) {
            $abs = dirname(__DIR__) . '/' . $receipt['receipt_file_path'];
            if (file_exists($abs)) {
                @unlink($abs);
            }
        }

        logActivity($conn, $user_id, 'Delete Receipt', 'Deleted ' . ($receipt['receipt_number'] ?? ''), $ip);

        redirect_tab('receipts', null, 'Receipt deleted successfully.');
    } catch (Exception $e) {
        redirect_tab('receipts', 'Error deleting receipt: ' . $e->getMessage());
    }
}

/* =========================
   VERIFY RECEIPT
========================= */
if (isset($_GET['verify_receipt'])) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    $receipt_id = geti('verify_receipt');

    if ($receipt_id <= 0) {
        redirect_tab('receipts', 'Invalid receipt selected.');
    }

    try {
        $stmt = execute_stmt(
            $conn,
            "UPDATE payment_receipts SET
                verification_status = 'Verified',
                verified_by = ?,
                verified_at = NOW()
             WHERE receipt_id = ?",
            "ii",
            [$user_id, $receipt_id]
        );
        $stmt->close();

        redirect_tab('receipts', null, 'Receipt verified successfully.');
    } catch (Exception $e) {
        redirect_tab('receipts', 'Failed to verify receipt: ' . $e->getMessage());
    }
}

/* =========================
   REJECT RECEIPT
========================= */
if (isset($_POST['reject_receipt'])) {
    $receipt_id = posti('receipt_id');
    $reason     = postv('rejection_reason');

    if ($receipt_id <= 0) {
        redirect_tab('receipts', 'Invalid receipt selected.');
    }

    if ($reason === '') {
        redirect_tab('receipts', 'Rejection reason required.');
    }

    try {
        $stmt = execute_stmt(
            $conn,
            "UPDATE payment_receipts SET
                verification_status = 'Rejected',
                verified_by = ?,
                verified_at = NOW(),
                rejection_reason = ?
             WHERE receipt_id = ?",
            "isi",
            [$user_id, $reason, $receipt_id]
        );
        $stmt->close();

        redirect_tab('receipts', null, 'Receipt rejected.');
    } catch (Exception $e) {
        redirect_tab('receipts', 'Failed to reject receipt: ' . $e->getMessage());
    }
}

/* =========================
   SEND NOTIFICATION
========================= */
if (isset($_POST['send_notification'])) {
    $notification_type    = postv('notification_type');
    $recipient_type       = postv('recipient_type');
    $specific_member      = posti('specific_member');
    $notification_title   = postv('notification_title');
    $notification_message = postv('notification_message');
    $notification_link    = null_if_empty(postv('notification_link'));

    if ($notification_title === '' || $notification_message === '') {
        redirect_tab('notifications', 'Title and message are required.');
    }

    if ($recipient_type === 'all') {
        $stmt = $conn->prepare("SELECT member_id FROM members");
    } elseif ($recipient_type === 'active') {
        $stmt = $conn->prepare("SELECT member_id FROM members WHERE membership_status = 'Active'");
    } elseif ($recipient_type === 'expiring') {
        $stmt = $conn->prepare("
            SELECT member_id
            FROM members
            WHERE membership_status = 'Active'
              AND subscription_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ");
    } elseif ($recipient_type === 'specific' && $specific_member > 0) {
        $stmt = $conn->prepare("SELECT member_id FROM members WHERE member_id = ?");
        $stmt->bind_param("i", $specific_member);
    } else {
        redirect_tab('notifications', 'Invalid recipient type.');
    }

    if (!$stmt->execute()) {
        $stmt->close();
        redirect_tab('notifications', 'Failed to fetch notification recipients.');
    }

    $res = $stmt->get_result();
    $member_ids = [];
    while ($row = $res->fetch_assoc()) {
        $member_ids[] = (int)$row['member_id'];
    }
    $stmt->close();

    if (empty($member_ids)) {
        redirect_tab('notifications', 'No recipients found.');
    }

    $count = 0;
    $stmt = $conn->prepare("
        INSERT INTO member_notifications (member_id, notification_type, title, message, link_url)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        redirect_tab('notifications', 'Failed to prepare notification insert.');
    }

    foreach ($member_ids as $member_id) {
        $stmt->bind_param(
            "issss",
            $member_id,
            $notification_type,
            $notification_title,
            $notification_message,
            $notification_link
        );

        if ($stmt->execute()) {
            $count++;
        }
    }
    $stmt->close();

    logActivity($conn, $user_id, 'Send Notification', "Sent notification to $count members: $notification_title", $ip);

    redirect_tab('notifications', null, "Notification sent to $count member(s) successfully!");
}

redirect_tab($tab, 'Invalid action.');