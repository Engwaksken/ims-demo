<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Nairobi');

require_once __DIR__ . '/config.php';

if (!isset($conn) || !$conn instanceof mysqli) {
    $_SESSION['error'] = 'Database connection not found.';
    header('Location: ../internet_subscriptions');
    exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+03:00'");

const PFSENSE_CP_ZONE = 'lan';
const PFSENSE_CP_LOGIN_URL = 'http://192.168.1.1:8000/index.php';

function redirect_back(): never
{
    header('Location: ../internet_subscriptions');
    exit;
}

function normalize_mac(string $mac): string
{
    $mac = strtolower(trim($mac));
    $mac = preg_replace('/[^a-f0-9]/', '', $mac);

    if (strlen((string)$mac) !== 12) {
        return '';
    }

    return implode(':', str_split((string)$mac, 2));
}

function current_admin_id(): ?int
{
    foreach (['user_id', 'admin_id', 'id'] as $key) {
        if (!empty($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
            return (int)$_SESSION[$key];
        }
    }

    return null;
}

function pfsense_authorize_mac(string $mac): bool
{
    $mac = normalize_mac($mac);

    if ($mac === '') {
        return false;
    }

    $url = PFSENSE_CP_LOGIN_URL . '?' . http_build_query([
        'zone'      => PFSENSE_CP_ZONE,
        'auth_user' => $mac,
        'auth_pass' => $mac,
        'redirurl'  => ims_brand()['website'],
    ]);

    if (!function_exists('curl_init')) {
        @file_get_contents($url);
        return true;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
    ]);

    curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return $error === '';
}

function get_plan(mysqli $conn, int $planId): ?array
{
    $stmt = $conn->prepare("
        SELECT 
            id,
            plan_name,
            duration_type,
            duration_minutes,
            final_price,
            status
        FROM internet_plans
        WHERE id = ?
          AND status = 'active'
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $plan ?: null;
}

function calculate_plan_expiry(array $plan): array
{
    $start = new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi'));

    $planName     = strtolower(trim((string)($plan['plan_name'] ?? '')));
    $durationType = strtolower(trim((string)($plan['duration_type'] ?? '')));
    $minutes      = max(1, (int)($plan['duration_minutes'] ?? 1440));

    if (
        $durationType === 'daily' ||
        str_contains($planName, 'daily') ||
        preg_match('/\b1\s*day\b/i', $planName)
    ) {
        $expiry = $start->modify('+24 hours');
    } elseif (
        $durationType === 'annual' ||
        str_contains($planName, 'annual') ||
        str_contains($planName, 'year')
    ) {
        $expiry = $start->modify('+12 months');
    } elseif (preg_match('/(\d+)\s*(month|months|monthly)/i', $planName, $m)) {
        $months = max(1, (int)$m[1]);
        $expiry = $start->modify('+' . $months . ' months');
    } elseif (
        $durationType === 'monthly' ||
        str_contains($planName, 'monthly') ||
        str_contains($planName, 'month')
    ) {
        $expiry = $start->modify('+30 days');
    } else {
        $expiry = $start->modify('+' . $minutes . ' minutes');
    }

    return [
        'starts_at'  => $start->format('Y-m-d H:i:s'),
        'expires_at' => $expiry->format('Y-m-d H:i:s'),
    ];
}

function mac_has_active_subscription(mysqli $conn, string $mac, int $excludeId = 0): bool
{
    $mac = normalize_mac($mac);

    if ($mac === '') {
        return false;
    }

    $sql = "
        SELECT id
        FROM internet_subscriptions
        WHERE LOWER(REPLACE(REPLACE(mac_address, ':', ''), '-', '')) =
              LOWER(REPLACE(REPLACE(?, ':', ''), '-', ''))
          AND status = 'active'
          AND (expires_at IS NULL OR expires_at >= NOW())
    ";

    if ($excludeId > 0) {
        $sql .= " AND id <> ? ";
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    if ($excludeId > 0) {
        $stmt->bind_param('si', $mac, $excludeId);
    } else {
        $stmt->bind_param('s', $mac);
    }

    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

// Only hub/finance staff may add, approve or reject internet devices.
check_role(['Administrator', 'Operations/Admin', 'Accountant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    redirect_back();
}

$action = trim((string)($_POST['action'] ?? ''));

/*
|--------------------------------------------------------------------------
| ADD DEVICE
|--------------------------------------------------------------------------
*/
if ($action === 'add_device') {
    $memberId      = (int)($_POST['member_id'] ?? 0);
    $planId        = (int)($_POST['plan_id'] ?? 0);
    $macAddress    = normalize_mac((string)($_POST['mac_address'] ?? ''));
    $receiptNumber = trim((string)($_POST['receipt_number'] ?? ''));
    $approvedBy    = current_admin_id();

    if ($memberId <= 0) {
        $_SESSION['error'] = 'Please select a member.';
        redirect_back();
    }

    if ($planId <= 0) {
        $_SESSION['error'] = 'Please select an internet plan.';
        redirect_back();
    }

    if ($macAddress === '') {
        $_SESSION['error'] = 'Please enter a valid MAC address.';
        redirect_back();
    }

    $memberStmt = $conn->prepare("
        SELECT
            m.member_id,
            u.is_active
        FROM members m
        INNER JOIN users u ON u.user_id = m.user_id
        WHERE m.member_id = ?
        LIMIT 1
    ");

    if (!$memberStmt) {
        $_SESSION['error'] = 'Database error: ' . $conn->error;
        redirect_back();
    }

    $memberStmt->bind_param('i', $memberId);
    $memberStmt->execute();
    $member = $memberStmt->get_result()->fetch_assoc();
    $memberStmt->close();

    if (!$member) {
        $_SESSION['error'] = 'Selected member was not found.';
        redirect_back();
    }

    if ((int)$member['is_active'] !== 1) {
        $_SESSION['error'] = 'Selected member account is inactive.';
        redirect_back();
    }

    $plan = get_plan($conn, $planId);

    if (!$plan) {
        $_SESSION['error'] = 'Selected internet plan is not active or does not exist.';
        redirect_back();
    }

    if (mac_has_active_subscription($conn, $macAddress)) {
        $_SESSION['error'] = 'This MAC address already has an active internet subscription.';
        redirect_back();
    }

    $dates      = calculate_plan_expiry($plan);
    $startsAt   = $dates['starts_at'];
    $expiresAt  = $dates['expires_at'];
    $amountPaid = (float)$plan['final_price'];

    $stmt = $conn->prepare("
        INSERT INTO internet_subscriptions (
            member_id,
            mac_address,
            plan_id,
            amount_paid,
            starts_at,
            expires_at,
            status,
            receipt_number,
            receipt_photo,
            approved_by,
            approved_at,
            rejection_reason,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, 'active', ?, NULL, ?, NOW(), NULL, NOW()
        )
    ");

    if (!$stmt) {
        $_SESSION['error'] = 'Database error: ' . $conn->error;
        redirect_back();
    }

    $stmt->bind_param(
        'isidsssi',
        $memberId,
        $macAddress,
        $planId,
        $amountPaid,
        $startsAt,
        $expiresAt,
        $receiptNumber,
        $approvedBy
    );

    if ($stmt->execute()) {
        $pfSenseOk = pfsense_authorize_mac($macAddress);

        $_SESSION['success'] = $pfSenseOk
            ? 'Device added, subscription activated, and pfSense authorization sent.'
            : 'Device added and subscription activated, but pfSense authorization could not be confirmed.';
    } else {
        $_SESSION['error'] = 'Failed to add device: ' . $stmt->error;
    }

    $stmt->close();
    redirect_back();
}

/*
|--------------------------------------------------------------------------
| APPROVE SUBSCRIPTION
|--------------------------------------------------------------------------
*/
if ($action === 'approve') {
    $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
    $approvedBy     = current_admin_id();

    if ($subscriptionId <= 0) {
        $_SESSION['error'] = 'Invalid subscription selected.';
        redirect_back();
    }

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.mac_address,
            s.status,
            s.plan_id,
            p.plan_name,
            p.duration_type,
            p.duration_minutes,
            p.final_price
        FROM internet_subscriptions s
        INNER JOIN internet_plans p ON p.id = s.plan_id
        WHERE s.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        $_SESSION['error'] = 'Database error: ' . $conn->error;
        redirect_back();
    }

    $stmt->bind_param('i', $subscriptionId);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sub) {
        $_SESSION['error'] = 'Subscription was not found.';
        redirect_back();
    }

    if (strtolower((string)$sub['status']) !== 'pending') {
        $_SESSION['error'] = 'Only pending subscriptions can be approved.';
        redirect_back();
    }

    $macAddress = normalize_mac((string)$sub['mac_address']);

    if ($macAddress === '') {
        $_SESSION['error'] = 'This subscription has an invalid MAC address.';
        redirect_back();
    }

    if (mac_has_active_subscription($conn, $macAddress, $subscriptionId)) {
        $_SESSION['error'] = 'This MAC address already has another active subscription.';
        redirect_back();
    }

    $dates     = calculate_plan_expiry($sub);
    $startsAt  = $dates['starts_at'];
    $expiresAt = $dates['expires_at'];

    $update = $conn->prepare("
        UPDATE internet_subscriptions
        SET
            mac_address = ?,
            status = 'active',
            starts_at = ?,
            expires_at = ?,
            approved_by = ?,
            approved_at = NOW(),
            rejection_reason = NULL
        WHERE id = ?
        LIMIT 1
    ");

    if (!$update) {
        $_SESSION['error'] = 'Database error: ' . $conn->error;
        redirect_back();
    }

    $update->bind_param(
        'sssii',
        $macAddress,
        $startsAt,
        $expiresAt,
        $approvedBy,
        $subscriptionId
    );

    if ($update->execute()) {
        $pfSenseOk = pfsense_authorize_mac($macAddress);

        $_SESSION['success'] = $pfSenseOk
            ? 'Subscription approved, activated, and pfSense authorization sent.'
            : 'Subscription approved and activated, but pfSense authorization could not be confirmed.';
    } else {
        $_SESSION['error'] = 'Failed to approve subscription: ' . $update->error;
    }

    $update->close();
    redirect_back();
}

/*
|--------------------------------------------------------------------------
| REJECT SUBSCRIPTION
|--------------------------------------------------------------------------
*/
if ($action === 'reject') {
    $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
    $reason         = trim((string)($_POST['rejection_reason'] ?? ''));

    if ($subscriptionId <= 0) {
        $_SESSION['error'] = 'Invalid subscription selected.';
        redirect_back();
    }

    if ($reason === '') {
        $reason = 'Rejected by administrator.';
    }

    $stmt = $conn->prepare("
        UPDATE internet_subscriptions
        SET
            status = 'rejected',
            rejection_reason = ?
        WHERE id = ?
          AND status = 'pending'
        LIMIT 1
    ");

    if (!$stmt) {
        $_SESSION['error'] = 'Database error: ' . $conn->error;
        redirect_back();
    }

    $stmt->bind_param('si', $reason, $subscriptionId);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['success'] = 'Subscription rejected successfully.';
    } else {
        $_SESSION['error'] = 'Subscription could not be rejected. It may no longer be pending.';
    }

    $stmt->close();
    redirect_back();
}

$_SESSION['error'] = 'Invalid action.';
redirect_back();