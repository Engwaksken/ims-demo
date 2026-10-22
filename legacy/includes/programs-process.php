<?php

require_once 'config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Program Director','Program Manager']);

// Handle Add program
if (isset($_POST['add_program'])) {
    $program_code = sanitize_input($_POST['program_code']);
    $program_name = sanitize_input($_POST['program_name']);
    $donor_id = intval($_POST['donor_id']);
    $start_date = sanitize_input($_POST['start_date']);
    $end_date = sanitize_input($_POST['end_date']);
    $budget = floatval($_POST['budget']);
    $currency = sanitize_input($_POST['currency']);
    $status = sanitize_input($_POST['status']);
    $description = sanitize_input($_POST['description']);
    $objectives = sanitize_input($_POST['objectives']);
    
    // Validate required fields
    if (empty($program_code) || empty($program_name) || empty($donor_id) || empty($start_date) || empty($end_date)) {
        send_notification($_SESSION['user_id'], 'All required fields must be filled', 'danger');
        header("Location: ../programs");
        exit();
    }
    
    // Check if program code already exists
    $check_stmt = $conn->prepare("SELECT id FROM programs WHERE program_code = ?");
    $check_stmt->bind_param("s", $program_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'program code already exists. Please use a different code.', 'danger');
        header("Location: ../programs");
        exit();
    }
    
    // Validate dates
    if (strtotime($end_date) < strtotime($start_date)) {
        send_notification($_SESSION['user_id'], 'End date must be after start date', 'danger');
        header("Location: ../programs");
        exit();
    }
    
    // Insert program
    $stmt = $conn->prepare("INSERT INTO programs (program_code, program_name, donor_id, start_date, end_date, budget, currency, status, description, objectives) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssissdssss", $program_code, $program_name, $donor_id, $start_date, $end_date, $budget, $currency, $status, $description, $objectives);
    
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        log_action($_SESSION['user_id'], 'Add program', 'programs', $new_id, "Added program: $program_name");
        send_notification($_SESSION['user_id'], 'program added successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error adding program: ' . $conn->error, 'danger');
    }
    
    header("Location: ../programs");
    exit();
}

// Handle Edit program
if (isset($_POST['edit_program'])) {
    $id = intval($_POST['id']);
    $program_code = sanitize_input($_POST['program_code']);
    $program_name = sanitize_input($_POST['program_name']);
    $donor_id = intval($_POST['donor_id']);
    $start_date = sanitize_input($_POST['start_date']);
    $end_date = sanitize_input($_POST['end_date']);
    $budget = floatval($_POST['budget']);
    $currency = sanitize_input($_POST['currency']);
    $status = sanitize_input($_POST['status']);
    $description = sanitize_input($_POST['description']);
    $objectives = sanitize_input($_POST['objectives']);
    
    // Validate required fields
    if (empty($program_code) || empty($program_name) || empty($donor_id) || empty($start_date) || empty($end_date)) {
        send_notification($_SESSION['user_id'], 'All required fields must be filled', 'danger');
        header("Location: ../programs?edit=$id");
        exit();
    }
    
    // Check if program code already exists (excluding current program)
    $check_stmt = $conn->prepare("SELECT id FROM programs WHERE program_code = ? AND id != ?");
    $check_stmt->bind_param("si", $program_code, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'program code already exists. Please use a different code.', 'danger');
        header("Location: ../programs?edit=$id");
        exit();
    }
    
    // Validate dates
    if (strtotime($end_date) < strtotime($start_date)) {
        send_notification($_SESSION['user_id'], 'End date must be after start date', 'danger');
        header("Location: ../programs?edit=$id");
        exit();
    }
    
    // Update program
    $stmt = $conn->prepare("UPDATE programs SET program_code = ?, program_name = ?, donor_id = ?, start_date = ?, end_date = ?, budget = ?, currency = ?, status = ?, description = ?, objectives = ? WHERE id = ?");
    $stmt->bind_param("ssissdssssi", $program_code, $program_name, $donor_id, $start_date, $end_date, $budget, $currency, $status, $description, $objectives, $id);
    
    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Edit program', 'programs', $id, "Updated program: $program_name");
        send_notification($_SESSION['user_id'], 'program updated successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating program: ' . $conn->error, 'danger');
    }
    
    header("Location: ../programs");
    exit();
}

// Handle Delete program
if (isset($_POST['delete_program']) && ims_allowed('programs.delete', ['Administrator'], 'Delete programs')) {
    $id = intval($_POST['id']);
    
    // Get program name for logging
    $program_result = $conn->query("SELECT program_name FROM programs WHERE id = $id");
    $program_data = $program_result->fetch_assoc();
    $program_name = $program_data['program_name'] ?? 'Unknown';
    
    // Check for dependencies
    $check = $conn->query("SELECT 
        (SELECT COUNT(*) FROM programs_beneficiaries WHERE program_id = $id) as beneficiaries,
        (SELECT COUNT(*) FROM indicators WHERE program_id = $id) as indicators");
    $counts = $check ? $check->fetch_assoc() : ['beneficiaries' => 1, 'indicators' => 0]; // fail closed
    
    if ($counts['beneficiaries'] > 0 || $counts['indicators'] > 0) {
        send_notification($_SESSION['user_id'], "Cannot delete program. It has {$counts['beneficiaries']} beneficiaries and {$counts['indicators']} indicators.", 'danger');
    } else {
        if ($conn->query("DELETE FROM programs WHERE id = $id")) {
            log_action($_SESSION['user_id'], 'Delete program', 'programs', $id, "Deleted program: $program_name");
            send_notification($_SESSION['user_id'], 'program deleted successfully', 'success');
        } else {
            send_notification($_SESSION['user_id'], 'Error deleting program: ' . $conn->error, 'danger');
        }
    }
    
    header("Location: ../programs");
    exit();
}

// If no valid action, redirect to programs page
header("Location: ../programs");
exit();
?>