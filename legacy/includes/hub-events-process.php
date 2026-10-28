<?php
declare(strict_types=1);

/*
 * Handler for hub-events.php (member space booking requests).
 *   GET  ?action=availability&space_type=&space_name=&booking_date=  -> JSON
 *   POST request_booking                                             -> redirect
 * Bookings go into space_bookings as Pending; staff confirm them from
 * manage-bookings like any other booking.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pricing.php';
check_login();

$userId = (int)$_SESSION['user_id'];
$return = '../hub-events';

$allowedTypes = ['Boardroom', 'Meeting Room', 'Event Space', 'Private Office', 'Hot Desk', 'Dedicated Desk'];

function hub_fail(string $message, string $return): never
{
    $_SESSION['notification'] = ['type' => 'error', 'message' => $message];
    header('Location: ' . $return);
    exit;
}

function hub_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function hub_valid_time(string $time): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time);
}

$stmt = $conn->prepare('SELECT member_id, membership_status FROM members WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ---------------------------------------------------------------- */
/* Availability (JSON)                                               */
/* ---------------------------------------------------------------- */
if (($_GET['action'] ?? '') === 'availability') {
    header('Content-Type: application/json; charset=utf-8');

    $spaceName = trim((string)($_GET['space_name'] ?? ''));
    $date = trim((string)($_GET['booking_date'] ?? ''));

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Only registered members can book spaces.']);
        exit;
    }
    if ($spaceName === '' || !hub_valid_date($date)) {
        echo json_encode(['success' => false, 'message' => 'Choose a space and a valid date.']);
        exit;
    }

    // Times and status only; other members' booking details stay private.
    $stmt = $conn->prepare("
        SELECT start_time, end_time, booking_status
        FROM space_bookings
        WHERE space_name = ?
          AND booking_date = ?
          AND booking_status IN ('Pending', 'Confirmed')
        ORDER BY start_time
    ");
    $stmt->bind_param('ss', $spaceName, $date);
    $stmt->execute();
    $slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'slots' => $slots]);
    exit;
}

/* ---------------------------------------------------------------- */
/* Booking request (POST)                                            */
/* ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['request_booking'])) {
    header('Location: ' . $return);
    exit;
}

if (!$member) {
    hub_fail('Only registered ' . ims_brand_name() . ' members can book spaces.', $return);
}
if (strcasecmp((string)($member['membership_status'] ?? ''), 'Active') !== 0) {
    hub_fail('Your membership must be active to book a space.', $return);
}

$spaceType = trim((string)($_POST['space_type'] ?? ''));
$spaceName = trim((string)($_POST['space_name'] ?? ''));
$date = trim((string)($_POST['booking_date'] ?? ''));
$start = trim((string)($_POST['start_time'] ?? ''));
$end = trim((string)($_POST['end_time'] ?? ''));
$purpose = trim((string)($_POST['purpose'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$attendees = max(1, (int)($_POST['number_of_attendees'] ?? 1));

if (!in_array($spaceType, $allowedTypes, true) || $spaceName === '' || mb_strlen($spaceName) > 100) {
    hub_fail('Please choose a valid space.', $return);
}
if (!hub_valid_date($date) || $date < date('Y-m-d')) {
    hub_fail('Please choose a booking date from today onwards.', $return);
}
if (!hub_valid_time($start) || !hub_valid_time($end) || strtotime($end) <= strtotime($start)) {
    hub_fail('End time must be after start time.', $return);
}
if ($purpose === '') {
    hub_fail('Please describe the purpose of the booking.', $return);
}

$durationHours = round((strtotime($end) - strtotime($start)) / 3600, 2);

// Price and capacity come from the space's configured rates when it is
// listed in space_availability; otherwise staff price it on confirmation.
$amount = 0.0;
$stmt = $conn->prepare('SELECT * FROM space_availability WHERE space_name = ? LIMIT 1');
$stmt->bind_param('s', $spaceName);
$stmt->execute();
$space = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($space) {
    if (!empty($space['capacity']) && $attendees > (int)$space['capacity']) {
        hub_fail('Number of attendees exceeds the space capacity (' . (int)$space['capacity'] . ').', $return);
    }
    $pricing = calculate_booking_amount($conn, (int)$member['member_id'], $space, 'hourly', (float)$durationHours, $date);
    $amount = round((float)$pricing['amount'], 2);
}

// mysqli error reporting is off in config.php, so check each step.
$conn->begin_transaction();

// Lock overlapping rows so two simultaneous requests cannot both pass.
$stmt = $conn->prepare("
    SELECT booking_id
    FROM space_bookings
    WHERE space_name = ?
      AND booking_date = ?
      AND booking_status IN ('Pending', 'Confirmed')
      AND start_time < ?
      AND end_time > ?
    LIMIT 1
    FOR UPDATE
");
if (!$stmt) {
    $conn->rollback();
    error_log('hub-events conflict check prepare failed: ' . $conn->error);
    hub_fail('We could not check availability. Please try again.', $return);
}
$stmt->bind_param('ssss', $spaceName, $date, $end, $start);
$stmt->execute();
$clash = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($clash) {
    $conn->rollback();
    hub_fail('That space is already booked for part of the selected time. Please choose another slot.', $return);
}

$memberId = (int)$member['member_id'];
$stmt = $conn->prepare("
    INSERT INTO space_bookings (
        member_id, space_type, space_name, booking_date, start_time, end_time,
        duration_hours, number_of_attendees, purpose, special_requests,
        booking_amount, payment_status, booking_status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Pending')
");
if (!$stmt) {
    $conn->rollback();
    error_log('hub-events insert prepare failed: ' . $conn->error);
    hub_fail('We could not save your booking request. Please try again.', $return);
}
$stmt->bind_param(
    'isssssdissd',
    $memberId, $spaceType, $spaceName, $date, $start, $end,
    $durationHours, $attendees, $purpose, $notes, $amount
);
if (!$stmt->execute()) {
    error_log('hub-events insert failed: ' . $stmt->error);
    $stmt->close();
    $conn->rollback();
    hub_fail('We could not save your booking request. Please try again.', $return);
}
$bookingId = (int)$stmt->insert_id;
$stmt->close();
$conn->commit();

log_action($userId, 'Request Hub Booking', 'space_bookings', $bookingId, "$spaceName on $date $start-$end");

$_SESSION['notification'] = [
    'type' => 'success',
    'message' => 'Booking request submitted. You will be notified once it is confirmed.',
];
header('Location: ' . $return . '?tab=my-bookings');
exit;
