<?php
require_once 'config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

$beneficiary_id = isset($_POST['beneficiary_id']) ? intval($_POST['beneficiary_id']) : 0;

if (!$beneficiary_id) {
    send_notification($_SESSION['user_id'], 'Invalid participant ID', 'danger');
    header("Location: ../participants");
    exit();
}

// Handle Add Linkage
if (isset($_POST['add_linkage'])) {
    $link_project_id = !empty($_POST['link_project_id']) ? intval($_POST['link_project_id']) : 0;
    $link_program_id = !empty($_POST['link_program_id']) ? intval($_POST['link_program_id']) : 0;
    
    // Validate that at least one is selected
    if ($link_project_id == 0 && $link_program_id == 0) {
        send_notification($_SESSION['user_id'], 'Please select either a project or program to link', 'warning');
        header("Location: ../participant-details?id=$beneficiary_id");
        exit();
    }
    
    // Validate that not both are selected
    if ($link_project_id > 0 && $link_program_id > 0) {
        send_notification($_SESSION['user_id'], 'Please select only one - either project OR program', 'warning');
        header("Location: ../participant-details?id=$beneficiary_id");
        exit();
    }
    
    // Get beneficiary name for notification
    $beneficiary_result = $conn->query("SELECT first_name, last_name FROM beneficiaries WHERE beneficiary_id = $beneficiary_id");
    $beneficiary_data = $beneficiary_result->fetch_assoc();
    $beneficiary_name = ($beneficiary_data['first_name'] ?? '') . ' ' . ($beneficiary_data['last_name'] ?? '');
    
    // Link to project
    if ($link_project_id > 0) {
        // Verify project exists
        $project_check = $conn->query("SELECT project_id, project_name, project_code FROM projects WHERE project_id = $link_project_id");
        if ($project_check->num_rows == 0) {
            send_notification($_SESSION['user_id'], 'Project not found', 'danger');
            header("Location: ../participant-details?id=$beneficiary_id");
            exit();
        }
        
        $project_data = $project_check->fetch_assoc();
        
        // Check if already linked
        $link_check = $conn->query("SELECT * FROM project_beneficiaries WHERE project_id = $link_project_id AND beneficiary_id = $beneficiary_id");
        
        if ($link_check->num_rows > 0) {
            send_notification($_SESSION['user_id'], 'Participant is already linked to this project', 'warning');
        } else {
            // Create linkage
            $stmt = $conn->prepare("INSERT INTO project_beneficiaries (project_id, beneficiary_id, participation_type, status, enrollment_date) VALUES (?, ?, 'Other', 'Active', NOW())");
            $stmt->bind_param("ii", $link_project_id, $beneficiary_id);
            
            if ($stmt->execute()) {
                log_action($_SESSION['user_id'], 'Link Beneficiary to Project', 'project_beneficiaries', $stmt->insert_id, "Linked beneficiary $beneficiary_id ($beneficiary_name) to project $link_project_id ({$project_data['project_code']})");
                send_notification($_SESSION['user_id'], "Successfully linked to project: {$project_data['project_code']}", 'success');
            } else {
                send_notification($_SESSION['user_id'], 'Error creating project linkage: ' . $conn->error, 'danger');
            }
        }
    }
    
    // Link to program
    if ($link_program_id > 0) {
        // Verify program exists
        $program_check = $conn->query("SELECT id, program_name, program_code FROM programs WHERE id = $link_program_id");
        if ($program_check->num_rows == 0) {
            send_notification($_SESSION['user_id'], 'Program not found', 'danger');
            header("Location: ../participant-details?id=$beneficiary_id");
            exit();
        }
        
        $program_data = $program_check->fetch_assoc();
        
        // Check if already linked
        $link_check = $conn->query("SELECT * FROM programs_beneficiaries WHERE program_id = $link_program_id AND beneficiary_id = $beneficiary_id");
        
        if ($link_check->num_rows > 0) {
            send_notification($_SESSION['user_id'], 'Participant is already linked to this program', 'warning');
        } else {
            // Create linkage
            $stmt = $conn->prepare("INSERT INTO programs_beneficiaries (program_id, beneficiary_id, status, enrollment_date) VALUES (?, ?, 'Active', NOW())");
            $stmt->bind_param("ii", $link_program_id, $beneficiary_id);
            
            if ($stmt->execute()) {
                log_action($_SESSION['user_id'], 'Link Beneficiary to Program', 'programs_beneficiaries', $stmt->insert_id, "Linked Participant $beneficiary_id ($beneficiary_name) to program $link_program_id ({$program_data['program_code']})");
                send_notification($_SESSION['user_id'], "Successfully linked to program: {$program_data['program_code']}", 'success');
            } else {
                send_notification($_SESSION['user_id'], 'Error creating program linkage: ' . $conn->error, 'danger');
            }
        }
    }
    
    header("Location: ../participant-details?id=$beneficiary_id");
    exit();
}

// Handle Remove Project Linkage
if (isset($_POST['remove_project_link'])) {
    $project_id = intval($_POST['project_id']);
    
    // Get beneficiary name for notification
    $beneficiary_result = $conn->query("SELECT first_name, last_name FROM beneficiaries WHERE beneficiary_id = $beneficiary_id");
    $beneficiary_data = $beneficiary_result->fetch_assoc();
    $beneficiary_name = ($beneficiary_data['first_name'] ?? '') . ' ' . ($beneficiary_data['last_name'] ?? '');
    
    // Get project name for notification
    $project_result = $conn->query("SELECT project_code FROM projects WHERE project_id = $project_id");
    $project_data = $project_result->fetch_assoc();
    $project_code = $project_data['project_code'] ?? 'Unknown';
    
    // Remove linkage
    if ($conn->query("DELETE FROM project_beneficiaries WHERE project_id = $project_id AND beneficiary_id = $beneficiary_id")) {
        log_action($_SESSION['user_id'], 'Remove Project Linkage', 'project_beneficiaries', 0, "Removed linkage between beneficiary $beneficiary_id ($beneficiary_name) and project $project_id ($project_code)");
        send_notification($_SESSION['user_id'], "Successfully removed linkage to project: $project_code", 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error removing project linkage: ' . $conn->error, 'danger');
    }
    
    header("Location: ../participant-details?id=$beneficiary_id");
    exit();
}

// Handle Remove Program Linkage
if (isset($_POST['remove_program_link'])) {
    $program_id = intval($_POST['program_id']);
    
    // Get beneficiary name for notification
    $beneficiary_result = $conn->query("SELECT first_name, last_name FROM beneficiaries WHERE beneficiary_id = $beneficiary_id");
    $beneficiary_data = $beneficiary_result->fetch_assoc();
    $beneficiary_name = ($beneficiary_data['first_name'] ?? '') . ' ' . ($beneficiary_data['last_name'] ?? '');
    
    // Get program name for notification
    $program_result = $conn->query("SELECT program_code FROM programs WHERE id = $program_id");
    $program_data = $program_result->fetch_assoc();
    $program_code = $program_data['program_code'] ?? 'Unknown';
    
    // Remove linkage
    if ($conn->query("DELETE FROM programs_beneficiaries WHERE program_id = $program_id AND beneficiary_id = $beneficiary_id")) {
        log_action($_SESSION['user_id'], 'Remove Program Linkage', 'programs_beneficiaries', 0, "Removed linkage between Participant $beneficiary_id ($beneficiary_name) and program $program_id ($program_code)");
        send_notification($_SESSION['user_id'], "Successfully removed linkage to program: $program_code", 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error removing program linkage: ' . $conn->error, 'danger');
    }
    
    header("Location: ../participant-details?id=$beneficiary_id");
    exit();
}

// If no valid action, redirect back to beneficiary details
header("Location: ../participant-details?id=$beneficiary_id");
exit();
?>