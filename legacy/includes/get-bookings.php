<?php
session_start();
require_once 'config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if user has access
$is_admin = ims_allowed('bookings.view_all', ['Administrator', 'Operations/Admin'], 'See all bookings (not just their own)');
$is_member = isset($_SESSION['role']) && $_SESSION['role'] === 'Member';

if (!$is_admin && !$is_member) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

// Get member_id if member
$member_id = null;
if ($is_member) {
    $member_query = "SELECT member_id FROM members WHERE user_id = ?";
    $stmt = $conn->prepare($member_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $member_id = $result->fetch_assoc()['member_id'];
    }
    $stmt->close();
}

// Get filter parameters
$filter_space = isset($_GET['space']) ? trim($_GET['space']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$start_date = isset($_GET['start']) ? $_GET['start'] : '';
$end_date = isset($_GET['end']) ? $_GET['end'] : '';
$list_view = isset($_GET['list']) ? true : false;

try {
    // Build the query
    $query = "SELECT 
                sb.booking_id,
                sb.member_id,
                sb.booking_id,
                sb.booking_date,
                sb.start_time,
                sb.end_time,
                sb.number_of_attendees,
                sb.booking_status,
                sb.booking_amount,
                sb.created_at,
                sa.space_name,
                sa.space_type,
                CONCAT(m.company_name) as member_name,
                m.email as member_email
              FROM space_bookings sb
              LEFT JOIN space_availability sa ON sb.booking_id = sa.availability_id
              LEFT JOIN members m ON sb.member_id = m.member_id
              WHERE 1=1";
    
    $params = [];
    $types = "";
    
    // If member, only show their bookings
    if ($is_member && $member_id) {
        $query .= " AND sb.member_id = ?";
        $params[] = $member_id;
        $types .= "i";
    }
    
    // Filter by space
    if (!empty($filter_space)) {
        $query .= " AND sa.space_name = ?";
        $params[] = $filter_space;
        $types .= "s";
    }
    
    // Filter by status
    if (!empty($filter_status)) {
        $query .= " AND sb.booking_status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    
    // Filter by date range (for calendar view)
    if (!empty($start_date) && !empty($end_date)) {
        // Extract just the date part from datetime strings
        $start = substr($start_date, 0, 10);
        $end = substr($end_date, 0, 10);
        
        $query .= " AND sb.booking_date >= ? AND sb.booking_date <= ?";
        $params[] = $start;
        $params[] = $end;
        $types .= "ss";
    }
    
    // Order by date and time
    $query .= " ORDER BY sb.booking_date DESC, sb.start_time ASC";
    
    // Prepare and execute
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = [
            'booking_id' => $row['booking_id'],
            'member_id' => $row['member_id'],
            'space_type' => $row['space_type'],
            'booking_date' => $row['booking_date'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'expected_attendees' => $row['number_of_attendees'],
            'booking_status' => $row['booking_status'],
            'booking_amount' => $row['booking_amount'],
            'space_name' => $row['space_name'],
            'space_type' => $row['space_type'],
            'member_name' => $row['member_name'],
            'member_email' => $row['member_email'],
            'created_at' => $row['created_at']
        ];
    }
    
    $stmt->close();
    
    // Return JSON response
    echo json_encode($bookings);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>