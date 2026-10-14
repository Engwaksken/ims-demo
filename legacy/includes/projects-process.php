<?php

require_once 'config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Program Director','Program Manager']);

// Handle Add Project
if (isset($_POST['add_project'])) {
    $project_code = sanitize_input($_POST['project_code']);
    $project_name = sanitize_input($_POST['project_name']);
    $donor_id = intval($_POST['donor_id']);
    $start_date = sanitize_input($_POST['start_date']);
    $end_date = sanitize_input($_POST['end_date']);
    $budget = floatval($_POST['budget']);
    $currency = sanitize_input($_POST['currency']);
    $status = sanitize_input($_POST['status']);
    $description = sanitize_input($_POST['description']);
    $objectives = sanitize_input($_POST['objectives']);
    
    // Validate required fields
    if (empty($project_code) || empty($project_name) || empty($donor_id) || empty($start_date) || empty($end_date)) {
        send_notification($_SESSION['user_id'], 'All required fields must be filled', 'danger');
        header("Location: ../projects");
        exit();
    }
    
    // Check if project code already exists
    $check_stmt = $conn->prepare("SELECT project_id FROM projects WHERE project_code = ?");
    $check_stmt->bind_param("s", $project_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'Project code already exists. Please use a different code.', 'danger');
        header("Location: ../projects");
        exit();
    }
    
    // Validate dates
    if (strtotime($end_date) < strtotime($start_date)) {
        send_notification($_SESSION['user_id'], 'End date must be after start date', 'danger');
        header("Location: ../projects");
        exit();
    }
    
    // Insert project
    $stmt = $conn->prepare("INSERT INTO projects (project_code, project_name, donor_id, start_date, end_date, budget, currency, status, description, objectives) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssissdssss", $project_code, $project_name, $donor_id, $start_date, $end_date, $budget, $currency, $status, $description, $objectives);
    
    if ($stmt->execute()) {
        $new_project_id = $stmt->insert_id;
        log_action($_SESSION['user_id'], 'Add Project', 'projects', $new_project_id, "Added project: $project_name");
        send_notification($_SESSION['user_id'], 'Project added successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error adding project: ' . $conn->error, 'danger');
    }
    
    header("Location: ../projects");
    exit();
}

// Handle Edit Project
if (isset($_POST['edit_project'])) {
    $project_id = intval($_POST['project_id']);
    $project_code = sanitize_input($_POST['project_code']);
    $project_name = sanitize_input($_POST['project_name']);
    $donor_id = intval($_POST['donor_id']);
    $start_date = sanitize_input($_POST['start_date']);
    $end_date = sanitize_input($_POST['end_date']);
    $budget = floatval($_POST['budget']);
    $currency = sanitize_input($_POST['currency']);
    $status = sanitize_input($_POST['status']);
    $description = sanitize_input($_POST['description']);
    $objectives = sanitize_input($_POST['objectives']);
    
    // Validate required fields
    if (empty($project_code) || empty($project_name) || empty($donor_id) || empty($start_date) || empty($end_date)) {
        send_notification($_SESSION['user_id'], 'All required fields must be filled', 'danger');
        header("Location: ../projects?edit=$project_id");
        exit();
    }
    
    // Check if project code already exists (excluding current project)
    $check_stmt = $conn->prepare("SELECT project_id FROM projects WHERE project_code = ? AND project_id != ?");
    $check_stmt->bind_param("si", $project_code, $project_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'Project code already exists. Please use a different code.', 'danger');
        header("Location: ../projects?edit=$project_id");
        exit();
    }
    
    // Validate dates
    if (strtotime($end_date) < strtotime($start_date)) {
        send_notification($_SESSION['user_id'], 'End date must be after start date', 'danger');
        header("Location: ../projects?edit=$project_id");
        exit();
    }
    
    // Update project
    $stmt = $conn->prepare("UPDATE projects SET project_code = ?, project_name = ?, donor_id = ?, start_date = ?, end_date = ?, budget = ?, currency = ?, status = ?, description = ?, objectives = ? WHERE project_id = ?");
    $stmt->bind_param("ssissddsssi", $project_code, $project_name, $donor_id, $start_date, $end_date, $budget, $currency, $status, $description, $objectives, $project_id);
    
    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Edit Project', 'projects', $project_id, "Updated project: $project_name");
        send_notification($_SESSION['user_id'], 'Project updated successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating project: ' . $conn->error, 'danger');
    }
    
    header("Location: ../projects");
    exit();
}

// Handle Delete Project
if (isset($_POST['delete_project']) && ims_allowed('projects.delete', ['Administrator'], 'Delete projects')) {
    $project_id = intval($_POST['project_id']);
    
    // Get project name for logging
    $project_result = $conn->query("SELECT project_name FROM projects WHERE project_id = $project_id");
    $project_data = $project_result->fetch_assoc();
    $project_name = $project_data['project_name'] ?? 'Unknown';
    
    // Check for dependencies
    $check = $conn->query("SELECT 
        (SELECT COUNT(*) FROM project_beneficiaries WHERE project_id = $project_id) as beneficiaries,
        (SELECT COUNT(*) FROM indicators WHERE project_id = $project_id) as indicators");
    $counts = $check->fetch_assoc();
    
    if ($counts['beneficiaries'] > 0 || $counts['indicators'] > 0) {
        send_notification($_SESSION['user_id'], "Cannot delete project. It has {$counts['beneficiaries']} beneficiaries and {$counts['indicators']} indicators.", 'danger');
    } else {
        if ($conn->query("DELETE FROM projects WHERE project_id = $project_id")) {
            log_action($_SESSION['user_id'], 'Delete Project', 'projects', $project_id, "Deleted project: $project_name");
            send_notification($_SESSION['user_id'], 'Project deleted successfully', 'success');
        } else {
            send_notification($_SESSION['user_id'], 'Error deleting project: ' . $conn->error, 'danger');
        }
    }
    
    header("Location: ../projects");
    exit();
}

// If no valid action, redirect to projects page
header("Location: ../projects");
exit();
?>