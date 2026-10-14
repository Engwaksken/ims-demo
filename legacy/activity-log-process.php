<?php

require_once __DIR__ . '/includes/config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

// Handle Clear Old Logs
if (isset($_POST['clear_old_logs']) && ims_allowed('activity_log.clear', ['Administrator'], 'Clear old activity logs')) {
    $days_to_keep = intval($_POST['days_to_keep']);
    
    // Validate days
    $valid_days = [30, 60, 90, 180, 365];
    if (!in_array($days_to_keep, $valid_days)) {
        send_notification($_SESSION['user_id'], 'Invalid days selected', 'danger');
        header("Location: activity_log");
        exit();
    }
    
    // Calculate cutoff date
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days_to_keep days"));
    
    // Count logs to be deleted
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM activity_log WHERE action_time < ?");
    $count_stmt->bind_param('s', $cutoff_date);
    $count_stmt->execute();
    $count = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
    
    // Delete old logs
    $delete_stmt = $conn->prepare("DELETE FROM activity_log WHERE action_time < ?");
    $delete_stmt->bind_param('s', $cutoff_date);
    if ($delete_stmt->execute()) {
        log_action($_SESSION['user_id'], 'Clear Activity Logs', 'activity_log', null, "Deleted $count log entries older than $days_to_keep days");
        send_notification($_SESSION['user_id'], "Successfully deleted $count old log entries", 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error clearing logs: ' . $conn->error, 'danger');
    }
    
    header("Location: activity_log");
    exit();
}

// Handle Export Logs
if (isset($_GET['export'])) {
    // Get filter parameters
    $filter_user = isset($_GET['user']) ? intval($_GET['user']) : 0;
    $filter_action = isset($_GET['action']) ? sanitize_input($_GET['action']) : '';
    $filter_module = isset($_GET['module']) ? sanitize_input($_GET['module']) : '';
    $filter_date_from = isset($_GET['date_from']) ? sanitize_input($_GET['date_from']) : '';
    $filter_date_to = isset($_GET['date_to']) ? sanitize_input($_GET['date_to']) : '';
    $search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
    
    // Build WHERE clause (parameterised)
    $where = "1=1";
    $where_types = '';
    $where_params = [];
    if ($filter_user) {
        $where .= " AND al.user_id = ?";
        $where_types .= 'i';
        $where_params[] = $filter_user;
    }
    if ($filter_action) {
        $where .= " AND al.action = ?";
        $where_types .= 's';
        $where_params[] = $filter_action;
    }
    if ($filter_module) {
        $where .= " AND al.module = ?";
        $where_types .= 's';
        $where_params[] = $filter_module;
    }
    if ($filter_date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
        $where .= " AND DATE(al.action_time) >= ?";
        $where_types .= 's';
        $where_params[] = $filter_date_from;
    }
    if ($filter_date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
        $where .= " AND DATE(al.action_time) <= ?";
        $where_types .= 's';
        $where_params[] = $filter_date_to;
    }
    if ($search) {
        $where .= " AND (al.description LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
        $like = '%' . $search . '%';
        $where_types .= 'sss';
        array_push($where_params, $like, $like, $like);
    }

    $run_log_query = static function (mysqli $conn, string $sql, string $types, array $params) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('activity log query failed: ' . $conn->error);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result();
    };
    
    // Fetch logs
    $query = "SELECT al.*, u.full_name, u.username, u.role
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE $where
        ORDER BY al.action_time DESC
        LIMIT 10000"; // Limit to prevent timeout
    $result = $run_log_query($conn, $query, $where_types, $where_params);
    if (!$result) {
        send_notification($_SESSION['user_id'], 'Could not export logs.', 'danger');
        header("Location: activity_log");
        exit();
    }
    
    // Generate CSV
    $filename = 'activity_log_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, ['Date', 'Time', 'User', 'Username', 'Role', 'Action', 'Module', 'Description', 'Record ID', 'IP Address']);
    
    // CSV data
    while ($row = $result->fetch_assoc()) {
        // ims_csv_safe(): neutralise spreadsheet formulas (= + - @ TAB CR).
        fputcsv($output, array_map('ims_csv_safe', [
            date('Y-m-d', strtotime($row['action_time'])),
            date('H:i:s', strtotime($row['action_time'])),
            $row['full_name'] ?? 'Unknown',
            $row['username'] ?? '',
            $row['role'] ?? '',
            $row['action'] ?? '',
            $row['module'] ?? '',
            $row['description'] ?? '',
            $row['record_id'] ?? '',
            $row['ip_address'] ?? ''
        ]));
    }
    
    fclose($output);
    
    // Log export action
    log_action($_SESSION['user_id'], 'Export Activity Logs', 'activity_log', null, "Exported activity logs to CSV");
    
    exit();
}

// If no valid action, redirect
header("Location: activity_log");
exit();
?>