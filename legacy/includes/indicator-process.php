<?php
require_once 'config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Handle Add Indicator
if (isset($_POST['add_indicator'])) {
    $context_type = sanitize_input($_POST['context_type']);
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $program_id = !empty($_POST['program_id']) ? intval($_POST['program_id']) : null;
    $indicator_name = sanitize_input($_POST['indicator_name']);
    $indicator_type = sanitize_input($_POST['indicator_type']);
    $unit_of_measure = sanitize_input($_POST['unit_of_measure']);
    $baseline_value = !empty($_POST['baseline_value']) ? floatval($_POST['baseline_value']) : 0;
    $target_value = !empty($_POST['target_value']) ? floatval($_POST['target_value']) : 0;
    $data_source = sanitize_input($_POST['data_source']);
    $collection_method = sanitize_input($_POST['collection_method']);
    $reporting_frequency = sanitize_input($_POST['reporting_frequency']);
    
    // Validate context selection
    if (empty($context_type) || ($context_type !== 'project' && $context_type !== 'program')) {
        send_notification($_SESSION['user_id'], 'Please select a valid context (Project or Program)', 'danger');
        header("Location: ../indicators");
        exit();
    }
    
    // Validate that either project_id or program_id is set based on context
    if ($context_type === 'project' && ($project_id === null || $project_id <= 0)) {
        send_notification($_SESSION['user_id'], 'Please select a project', 'danger');
        header("Location: ../indicators");
        exit();
    }
    
    if ($context_type === 'program' && ($program_id === null || $program_id <= 0)) {
        send_notification($_SESSION['user_id'], 'Please select a program', 'danger');
        header("Location: ../indicators");
        exit();
    }
    
    // Validate required fields
    if (empty($indicator_name) || empty($indicator_type) || $target_value <= 0) {
        send_notification($_SESSION['user_id'], 'Indicator name, type, and target value (greater than zero) are required', 'danger');
        header("Location: ../indicators");
        exit();
    }
    
    // Validate baseline vs target (baseline should not exceed target in most cases)
    if ($baseline_value > $target_value) {
        send_notification($_SESSION['user_id'], 'Warning: Baseline value is greater than target value', 'warning');
    }
    
    // Set the appropriate ID to NULL based on context
    if ($context_type === 'project') {
        $program_id = null;
    } else {
        $project_id = null;
    }
    
    $stmt = $conn->prepare("INSERT INTO indicators (project_id, program_id, indicator_name, indicator_type, unit_of_measure, baseline_value, target_value, current_value, data_source, collection_method, reporting_frequency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $current_value = $baseline_value;
    $stmt->bind_param("iisssdddsss", $project_id, $program_id, $indicator_name, $indicator_type, $unit_of_measure, $baseline_value, $target_value, $current_value, $data_source, $collection_method, $reporting_frequency);
    
    if ($stmt->execute()) {
        $new_indicator_id = $stmt->insert_id;
        $context_name = $context_type === 'project' ? 'Project' : 'Program';
        log_action($_SESSION['user_id'], 'Add Indicator', 'indicators', $new_indicator_id, "Added $context_name indicator: $indicator_name");
        send_notification($_SESSION['user_id'], 'Indicator added successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error adding indicator: ' . $conn->error, 'danger');
    }
    
    header("Location: ../indicators");
    exit();
}

// Handle Edit Indicator
if (isset($_POST['edit_indicator'])) {
    $indicator_id = intval($_POST['indicator_id']);
    $context_type = sanitize_input($_POST['context_type']);
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $program_id = !empty($_POST['program_id']) ? intval($_POST['program_id']) : null;
    $indicator_name = sanitize_input($_POST['indicator_name']);
    $indicator_type = sanitize_input($_POST['indicator_type']);
    $unit_of_measure = sanitize_input($_POST['unit_of_measure']);
    $baseline_value = !empty($_POST['baseline_value']) ? floatval($_POST['baseline_value']) : 0;
    $target_value = !empty($_POST['target_value']) ? floatval($_POST['target_value']) : 0;
    $data_source = sanitize_input($_POST['data_source']);
    $collection_method = sanitize_input($_POST['collection_method']);
    $reporting_frequency = sanitize_input($_POST['reporting_frequency']);
    
    // Validate context selection
    if (empty($context_type) || ($context_type !== 'project' && $context_type !== 'program')) {
        send_notification($_SESSION['user_id'], 'Please select a valid context (Project or Program)', 'danger');
        header("Location: ../indicators?edit=$indicator_id");
        exit();
    }
    
    // Validate that either project_id or program_id is set based on context
    if ($context_type === 'project' && ($project_id === null || $project_id <= 0)) {
        send_notification($_SESSION['user_id'], 'Please select a project', 'danger');
        header("Location: ../indicators?edit=$indicator_id");
        exit();
    }
    
    if ($context_type === 'program' && ($program_id === null || $program_id <= 0)) {
        send_notification($_SESSION['user_id'], 'Please select a program', 'danger');
        header("Location: ../indicators?edit=$indicator_id");
        exit();
    }
    
    // Validate required fields
    if (empty($indicator_name) || empty($indicator_type) || $target_value <= 0) {
        send_notification($_SESSION['user_id'], 'Indicator name, type, and target value (greater than zero) are required', 'danger');
        header("Location: ../indicators?edit=$indicator_id");
        exit();
    }
    
    // Set the appropriate ID to NULL based on context
    if ($context_type === 'project') {
        $program_id = null;
    } else {
        $project_id = null;
    }
    
    $stmt = $conn->prepare("UPDATE indicators SET project_id = ?, program_id = ?, indicator_name = ?, indicator_type = ?, unit_of_measure = ?, baseline_value = ?, target_value = ?, data_source = ?, collection_method = ?, reporting_frequency = ? WHERE indicator_id = ?");
    $stmt->bind_param("iisssddsssi", $project_id, $program_id, $indicator_name, $indicator_type, $unit_of_measure, $baseline_value, $target_value, $data_source, $collection_method, $reporting_frequency, $indicator_id);
    
    if ($stmt->execute()) {
        $context_name = $context_type === 'project' ? 'Project' : 'Program';
        log_action($_SESSION['user_id'], 'Edit Indicator', 'indicators', $indicator_id, "Updated $context_name indicator: $indicator_name");
        send_notification($_SESSION['user_id'], 'Indicator updated successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating indicator: ' . $conn->error, 'danger');
    }
    
    header("Location: ../indicators");
    exit();
}

// Handle Update Progress
if (isset($_POST['update_progress'])) {
    $indicator_id = intval($_POST['indicator_id']);
    $reporting_period = sanitize_input($_POST['reporting_period']);
    $actual_value = isset($_POST['actual_value']) ? floatval($_POST['actual_value']) : null;
    $progress_notes = sanitize_input($_POST['progress_notes']);
    $responsible_person = sanitize_input($_POST['responsible_person']);
    
    // Validate required fields
    if (empty($reporting_period) || $actual_value === null || $actual_value < 0) {
        send_notification($_SESSION['user_id'], 'Reporting period and actual value (non-negative) are required', 'danger');
        header("Location: ../indicators?progress=$indicator_id");
        exit();
    }
    
    // Save the history row and the current value together, so the value
    // never changes without a matching progress record.
    $conn->begin_transaction();
    $stmt2 = $conn->prepare("INSERT INTO indicator_progress (indicator_id, reporting_period, actual_value, progress_notes, responsible_person, recorded_date) VALUES (?, ?, ?, ?, ?, CURDATE())");
    $stmt = $conn->prepare("UPDATE indicators SET current_value = ?, last_updated = NOW() WHERE indicator_id = ?");
    $ok = $stmt2 && $stmt;
    if ($ok) {
        $stmt2->bind_param("isdss", $indicator_id, $reporting_period, $actual_value, $progress_notes, $responsible_person);
        $stmt->bind_param("di", $actual_value, $indicator_id);
        $ok = $stmt2->execute() && $stmt->execute();
    }

    if ($ok) {
        $conn->commit();
        log_action($_SESSION['user_id'], 'Update Indicator Progress', 'indicators', $indicator_id, "Updated progress to $actual_value");
        send_notification($_SESSION['user_id'], 'Progress updated successfully', 'success');
    } else {
        error_log('Indicator progress update failed: ' . $conn->error);
        $conn->rollback();
        send_notification($_SESSION['user_id'], 'Error updating progress. Please try again.', 'danger');
    }
    
    header("Location: ../indicators");
    exit();
}

// Handle Delete Indicator
if (isset($_POST['delete_indicator']) && ims_allowed('indicators.delete', ['Administrator', 'MEAL Lead'], 'Delete indicators')) {
    $indicator_id = intval($_POST['indicator_id']);
    
    // Get indicator name and context for logging
    $indicator_result = $conn->query("
        SELECT 
            i.indicator_name,
            CASE 
                WHEN i.project_id IS NOT NULL THEN 'Project'
                WHEN i.program_id IS NOT NULL THEN 'Program'
                ELSE 'Unknown'
            END AS context_type
        FROM indicators i
        WHERE i.indicator_id = $indicator_id
    ");
    $indicator_data = $indicator_result->fetch_assoc();
    $indicator_name = $indicator_data['indicator_name'] ?? 'Unknown';
    $context_type = $indicator_data['context_type'] ?? 'Unknown';
    
    // Check if indicator has progress records
    $check = $conn->query("SELECT COUNT(*) as count FROM indicator_progress WHERE indicator_id = $indicator_id");
    $count = $check->fetch_assoc()['count'];
    
    if ($count > 0) {
        send_notification($_SESSION['user_id'], "Cannot delete indicator. It has $count progress record(s). Delete progress records first.", 'danger');
    } else {
        if ($conn->query("DELETE FROM indicators WHERE indicator_id = $indicator_id")) {
            log_action($_SESSION['user_id'], 'Delete Indicator', 'indicators', $indicator_id, "Deleted $context_type indicator: $indicator_name");
            send_notification($_SESSION['user_id'], 'Indicator deleted successfully', 'success');
        } else {
            send_notification($_SESSION['user_id'], 'Error deleting indicator: ' . $conn->error, 'danger');
        }
    }
    
    header("Location: ../indicators");
    exit();
}

// If no valid action, redirect to indicators page
header("Location: ../indicators");
exit();
?>