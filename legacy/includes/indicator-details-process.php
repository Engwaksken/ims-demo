<?php
// Backend - Indicator Details Processing
require_once 'config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Get indicator ID
$indicator_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['indicator_id']) ? intval($_POST['indicator_id']) : 0);

if (!$indicator_id) {
    send_notification($_SESSION['user_id'], 'Invalid indicator ID', 'danger');
    header("Location: ../indicators");
    exit();
}

// Verify indicator exists and get target value
$check = $conn->query("SELECT indicator_id, target_value FROM indicators WHERE indicator_id = $indicator_id");
if ($check->num_rows == 0) {
    send_notification($_SESSION['user_id'], 'Indicator not found', 'danger');
    header("Location: ../indicators");
    exit();
}
$indicator_data = $check->fetch_assoc();
$target_value = $indicator_data['target_value'];

// Handle Update Progress
if (isset($_POST['update_progress'])) {
    $current_value = isset($_POST['current_value']) ? floatval($_POST['current_value']) : null;
    $reporting_period = sanitize_input($_POST['reporting_period']);
    $progress_notes = sanitize_input($_POST['progress_notes']);
    $responsible_person = sanitize_input($_POST['responsible_person']);
    $recorded_date = !empty($_POST['recorded_date']) ? sanitize_input($_POST['recorded_date']) : date('Y-m-d');
    $data_quality_score = !empty($_POST['data_quality_score']) ? intval($_POST['data_quality_score']) : null;
    
    // Validate required fields
    if ($current_value === null || empty($reporting_period)) {
        send_notification($_SESSION['user_id'], 'Current value and reporting period are required', 'danger');
        header("Location: ../indicator-details?id=$indicator_id");
        exit();
    }
    
    // Validate current value
    if ($current_value < 0) {
        send_notification($_SESSION['user_id'], 'Current value cannot be negative', 'danger');
        header("Location: ../indicator-details?id=$indicator_id");
        exit();
    }
    
    // Validate recorded date
    if ($recorded_date) {
        $recorded_timestamp = strtotime($recorded_date);
        $today = strtotime('today');
        if ($recorded_timestamp > $today) {
            send_notification($_SESSION['user_id'], 'Recorded date cannot be in the future', 'danger');
            header("Location: ../indicator-details?id=$indicator_id");
            exit();
        }
    }
    
    // Validate data quality score
    if ($data_quality_score !== null && ($data_quality_score < 1 || $data_quality_score > 5)) {
        send_notification($_SESSION['user_id'], 'Data quality score must be between 1 and 5', 'danger');
        header("Location: ../indicator-details?id=$indicator_id");
        exit();
    }
    
    // Calculate achievement percentage
    $achievement_percentage = 0;
    if ($target_value > 0) {
        $achievement_percentage = ($current_value / $target_value) * 100;
        $achievement_percentage = min($achievement_percentage, 999.99); // column is decimal(5,2)
    }
    
    // Update indicator current value
    $stmt = $conn->prepare("UPDATE indicators SET current_value = ?, last_updated = NOW() WHERE indicator_id = ?");
    $stmt->bind_param("di", $current_value, $indicator_id);
    
    if ($stmt->execute()) {
        // Add progress record with all fields
        $stmt2 = $conn->prepare("INSERT INTO indicator_progress (indicator_id, reporting_period, actual_value, achievement_percentage, data_quality_score, recorded_date, progress_notes, responsible_person) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt2->bind_param("isddisss", $indicator_id, $reporting_period, $current_value, $achievement_percentage, $data_quality_score, $recorded_date, $progress_notes, $responsible_person);
        
        if ($stmt2->execute()) {
            log_action($_SESSION['user_id'], 'Update Indicator Progress', 'indicators', $indicator_id, "Updated progress to $current_value (Achievement: " . number_format($achievement_percentage, 1) . "%)");
            send_notification($_SESSION['user_id'], 'Progress updated successfully', 'success');
        } else {
            send_notification($_SESSION['user_id'], 'Error adding progress record: ' . $conn->error, 'danger');
        }
    } else {
        send_notification($_SESSION['user_id'], 'Error updating progress: ' . $conn->error, 'danger');
    }
    
    header("Location: ../indicator-details?id=$indicator_id");
    exit();
}

// Handle Edit Indicator
if (isset($_POST['edit_indicator'])) {
    $indicator_name = sanitize_input($_POST['indicator_name']);
    $indicator_type = sanitize_input($_POST['indicator_type']);
    $unit_of_measure = sanitize_input($_POST['unit_of_measure']);
    $baseline_value = !empty($_POST['baseline_value']) ? floatval($_POST['baseline_value']) : 0;
    $target_value = !empty($_POST['target_value']) ? floatval($_POST['target_value']) : 0;
    $data_source = sanitize_input($_POST['data_source']);
    $collection_method = sanitize_input($_POST['collection_method']);
    $reporting_frequency = sanitize_input($_POST['reporting_frequency']);
    
    // Validate required fields
    if (empty($indicator_name) || empty($indicator_type) || empty($unit_of_measure) || $target_value <= 0) {
        send_notification($_SESSION['user_id'], 'Indicator name, type, unit of measure, and target value (greater than zero) are required', 'danger');
        header("Location: ../indicator-details?id=$indicator_id");
        exit();
    }
    
    $stmt = $conn->prepare("UPDATE indicators SET indicator_name = ?, indicator_type = ?, unit_of_measure = ?, baseline_value = ?, target_value = ?, data_source = ?, collection_method = ?, reporting_frequency = ? WHERE indicator_id = ?");
    $stmt->bind_param("sssddsssi", $indicator_name, $indicator_type, $unit_of_measure, $baseline_value, $target_value, $data_source, $collection_method, $reporting_frequency, $indicator_id);
    
    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Edit Indicator', 'indicators', $indicator_id, "Updated indicator: $indicator_name");
        send_notification($_SESSION['user_id'], 'Indicator updated successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating indicator: ' . $conn->error, 'danger');
    }
    
    header("Location: ../indicator-details?id=$indicator_id");
    exit();
}

// Handle Add Progress Update
if (isset($_POST['add_progress_update'])) {
    $reporting_period = sanitize_input($_POST['reporting_period']);
    $actual_value = isset($_POST['actual_value']) ? floatval($_POST['actual_value']) : null;
    $progress_notes = sanitize_input($_POST['progress_notes']);
    $responsible_person = sanitize_input($_POST['responsible_person']);
    $recorded_date = !empty($_POST['recorded_date']) ? sanitize_input($_POST['recorded_date']) : date('Y-m-d');
    $data_quality_score = !empty($_POST['data_quality_score']) ? intval($_POST['data_quality_score']) : null;
    
    // Validate required fields
    if (empty($reporting_period) || $actual_value === null) {
        send_notification($_SESSION['user_id'], 'Reporting period and actual value are required', 'danger');
        header("Location: ../indicator-details?id=$indicator_id");
        exit();
    }
    
    // Validate actual value
    if ($actual_value < 0) {
        send_notification($_SESSION['user_id'], 'Actual value cannot be negative', 'danger');
        header("Location: ../indicator-details?id=$indicator_id");
        exit();
    }
    
    // Validate recorded date
    if ($recorded_date) {
        $recorded_timestamp = strtotime($recorded_date);
        $today = strtotime('today');
        if ($recorded_timestamp > $today) {
            send_notification($_SESSION['user_id'], 'Recorded date cannot be in the future', 'danger');
            header("Location: ../indicator-details?id=$indicator_id");
            exit();
        }
    }
    
    // Validate data quality score
    if ($data_quality_score !== null && ($data_quality_score < 1 || $data_quality_score > 5)) {
        send_notification($_SESSION['user_id'], 'Data quality score must be between 1 and 5', 'danger');
        header("Location: ../indicator-details?id=$indicator_id");
        exit();
    }
    
    // Calculate achievement percentage
    $achievement_percentage = 0;
    if ($target_value > 0) {
        $achievement_percentage = ($actual_value / $target_value) * 100;
        $achievement_percentage = min($achievement_percentage, 999.99); // column is decimal(5,2)
    }
    
    // Add progress record (does NOT update indicator current_value)
    $stmt = $conn->prepare("INSERT INTO indicator_progress (indicator_id, reporting_period, actual_value, achievement_percentage, data_quality_score, recorded_date, progress_notes, responsible_person) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isddisss", $indicator_id, $reporting_period, $actual_value, $achievement_percentage, $data_quality_score, $recorded_date, $progress_notes, $responsible_person);
    
    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Add Progress Update', 'indicator_progress', $stmt->insert_id, "Added progress update for indicator $indicator_id: $actual_value (Achievement: " . number_format($achievement_percentage, 1) . "%)");
        send_notification($_SESSION['user_id'], 'Progress update added successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error adding progress update: ' . $conn->error, 'danger');
    }
    
    header("Location: ../indicator-details?id=$indicator_id");
    exit();
}

// If no valid action, redirect to indicator details
header("Location: ../indicator-details?id=$indicator_id");
exit();
?>