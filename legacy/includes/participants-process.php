<?php
require_once 'config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Handle Add Beneficiary
if (isset($_POST['add_participant'])) {
    $first_name = sanitize_input($_POST['first_name']);
    $last_name = sanitize_input($_POST['last_name']);
    $gender = sanitize_input($_POST['gender']);
    $date_of_birth = !empty($_POST['date_of_birth']) ? sanitize_input($_POST['date_of_birth']) : null;
    $phone = sanitize_input($_POST['phone']);
    $email = sanitize_input($_POST['email']);
    $district = sanitize_input($_POST['district']);
    $subcounty = sanitize_input($_POST['subcounty']);
    $village = sanitize_input($_POST['village']);
    $education_level = sanitize_input($_POST['education_level']);
    $occupation = sanitize_input($_POST['occupation']);
    $is_pwd = isset($_POST['is_pwd']) ? 1 : 0;
    $disability_type = $is_pwd ? sanitize_input($_POST['disability_type']) : null;
    
    // Get linking information (from dropdown selections)
    $link_project_id = !empty($_POST['link_project_id']) ? intval($_POST['link_project_id']) : 0;
    $link_program_id = !empty($_POST['link_program_id']) ? intval($_POST['link_program_id']) : 0;
    
    // Get context for return navigation
    $context_return_project = !empty($_POST['context_return_project']) ? intval($_POST['context_return_project']) : 0;
    $context_return_program = !empty($_POST['context_return_program']) ? intval($_POST['context_return_program']) : 0;
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($gender)) {
        send_notification($_SESSION['user_id'], 'First name, last name, and gender are required', 'danger');
        $redirect = $context_return_project ? "../participants?project=$context_return_project" : ($context_return_program ? "../participants?program=$context_return_program" : "../participants");
        header("Location: $redirect");
        exit();
    }
    
    // Validate date of birth if provided
    if ($date_of_birth) {
        $dob_timestamp = strtotime($date_of_birth);
        $today = strtotime('today');
        if ($dob_timestamp > $today) {
            send_notification($_SESSION['user_id'], 'Date of birth cannot be in the future', 'danger');
            $redirect = $context_return_project ? "../participants?project=$context_return_project" : ($context_return_program ? "../participants?program=$context_return_program" : "../participants");
            header("Location: $redirect");
            exit();
        }
    }
    
    // Check for duplicate beneficiary (same name and phone)
    if (!empty($phone)) {
        $check_stmt = $conn->prepare("SELECT beneficiary_id FROM beneficiaries WHERE first_name = ? AND last_name = ? AND phone = ?");
        $check_stmt->bind_param("sss", $first_name, $last_name, $phone);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            send_notification($_SESSION['user_id'], 'A Participant with this name and phone number already exists', 'warning');
            $redirect = $context_return_project ? "../participants?project=$context_return_project" : ($context_return_program ? "../participants?program=$context_return_program" : "../participants");
            header("Location: $redirect");
            exit();
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO beneficiaries (first_name, last_name, gender, date_of_birth, phone, email, district, subcounty, village, education_level, occupation, is_pwd, pwd_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssssssss", $first_name, $last_name, $gender, $date_of_birth, $phone, $email, $district, $subcounty, $village, $education_level, $occupation, $is_pwd, $disability_type);
    
    if ($stmt->execute()) {
        $new_beneficiary_id = $stmt->insert_id;
        $linked_to = [];
        
        // Link to project if link_project_id is selected
        if ($link_project_id > 0) {
            $project_check = $conn->query("SELECT project_id, project_name, project_code FROM projects WHERE project_id = $link_project_id");
            if ($project_check->num_rows > 0) {
                $project_data = $project_check->fetch_assoc();
                
                // Check if beneficiary is already linked to this project
                $link_check = $conn->query("SELECT * FROM project_beneficiaries WHERE project_id = $link_project_id AND beneficiary_id = $new_beneficiary_id");
                
                if ($link_check->num_rows == 0) {
                    $link_stmt = $conn->prepare("INSERT INTO project_beneficiaries (project_id, beneficiary_id, status, enrollment_date) VALUES (?, ?, 'Active', NOW())");
                    $link_stmt->bind_param("ii", $link_project_id, $new_beneficiary_id);
                    
                    if ($link_stmt->execute()) {
                        log_action($_SESSION['user_id'], 'Link Participant to Project', 'project_participants', $link_stmt->insert_id, "Linked beneficiary $new_beneficiary_id ($first_name $last_name) to project $link_project_id");
                        $linked_to[] = "project '{$project_data['project_code']}'";
                    }
                }
            }
        }
        
        // Link to program if link_program_id is selected
        if ($link_program_id > 0) {
            $program_check = $conn->query("SELECT id, program_name, program_code FROM programs WHERE id = $link_program_id");
            if ($program_check->num_rows > 0) {
                $program_data = $program_check->fetch_assoc();
                
                // Check if beneficiary is already linked to this program
                $link_check = $conn->query("SELECT * FROM programs_beneficiaries WHERE program_id = $link_program_id AND beneficiary_id = $new_beneficiary_id");
                
                if ($link_check->num_rows == 0) {
                    $link_stmt = $conn->prepare("INSERT INTO programs_beneficiaries (program_id, beneficiary_id, status, enrollment_date) VALUES (?, ?, 'Active', NOW())");
                    $link_stmt->bind_param("ii", $link_program_id, $new_beneficiary_id);
                    
                    if ($link_stmt->execute()) {
                        log_action($_SESSION['user_id'], 'Link Participant to Program', 'programs_participants', $link_stmt->insert_id, "Linked beneficiary $new_beneficiary_id ($first_name $last_name) to program $link_program_id");
                        $linked_to[] = "program '{$program_data['program_code']}'";
                    }
                }
            }
        }
        
        // Generate success message
        if (count($linked_to) > 0) {
            $link_message = " and linked to " . implode(" and ", $linked_to);
            send_notification($_SESSION['user_id'], "Participant added successfully{$link_message}", 'success');
        } else {
            send_notification($_SESSION['user_id'], 'Participant added successfully', 'success');
        }
        
        log_action($_SESSION['user_id'], 'Add Participant', 'participants', $new_beneficiary_id, "Added Participant: $first_name $last_name");
    } else {
        send_notification($_SESSION['user_id'], 'Error adding participant: ' . $conn->error, 'danger');
    }
    
    $redirect = $context_return_project ? "../participants?project=$context_return_project" : ($context_return_program ? "../participants?program=$context_return_program" : "../participants");
    header("Location: $redirect");
    exit();
}

// Handle Edit Beneficiary
if (isset($_POST['edit_beneficiary'])) {
    $beneficiary_id = intval($_POST['beneficiary_id']);
    $first_name = sanitize_input($_POST['first_name']);
    $last_name = sanitize_input($_POST['last_name']);
    $gender = sanitize_input($_POST['gender']);
    $date_of_birth = !empty($_POST['date_of_birth']) ? sanitize_input($_POST['date_of_birth']) : null;
    $phone = sanitize_input($_POST['phone']);
    $email = sanitize_input($_POST['email']);
    $district = sanitize_input($_POST['district']);
    $subcounty = sanitize_input($_POST['subcounty']);
    $village = sanitize_input($_POST['village']);
    $education_level = sanitize_input($_POST['education_level']);
    $occupation = sanitize_input($_POST['occupation']);
    $is_pwd = isset($_POST['is_pwd']) ? 1 : 0;
    $disability_type = $is_pwd ? sanitize_input($_POST['disability_type']) : null;
    
    // Get context for return navigation
    $context_return_project = !empty($_POST['context_return_project']) ? intval($_POST['context_return_project']) : 0;
    $context_return_program = !empty($_POST['context_return_program']) ? intval($_POST['context_return_program']) : 0;
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($gender)) {
        send_notification($_SESSION['user_id'], 'First name, last name, and gender are required', 'danger');
        $redirect = $context_return_project ? "../participants?project=$context_return_project&edit=$beneficiary_id" : ($context_return_program ? "../participants?program=$context_return_program&edit=$beneficiary_id" : "../participants?edit=$beneficiary_id");
        header("Location: $redirect");
        exit();
    }
    
    // Validate date of birth if provided
    if ($date_of_birth) {
        $dob_timestamp = strtotime($date_of_birth);
        $today = strtotime('today');
        if ($dob_timestamp > $today) {
            send_notification($_SESSION['user_id'], 'Date of birth cannot be in the future', 'danger');
            $redirect = $context_return_project ? "../participants?project=$context_return_project&edit=$beneficiary_id" : ($context_return_program ? "../participants?program=$context_return_program&edit=$beneficiary_id" : "../participants?edit=$beneficiary_id");
            header("Location: $redirect");
            exit();
        }
    }
    
    $stmt = $conn->prepare("UPDATE beneficiaries SET first_name = ?, last_name = ?, gender = ?, date_of_birth = ?, phone = ?, email = ?, district = ?, subcounty = ?, village = ?, education_level = ?, occupation = ?, is_pwd = ?, pwd_type = ? WHERE beneficiary_id = ?");
    $stmt->bind_param("ssssssssssssii", $first_name, $last_name, $gender, $date_of_birth, $phone, $email, $district, $subcounty, $village, $education_level, $occupation, $is_pwd, $disability_type, $beneficiary_id);
    
    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Edit Participant', 'participants', $beneficiary_id, "Updated participant: $first_name $last_name");
        send_notification($_SESSION['user_id'], 'Participant updated successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating participant: ' . $conn->error, 'danger');
    }
    
    // Check if should redirect to details page
    if (isset($_POST['redirect_to_details']) && $_POST['redirect_to_details'] == '1') {
        header("Location: ../participant-details?id=$beneficiary_id");
        exit();
    }
    
    $redirect = $context_return_project ? "../participants?project=$context_return_project" : ($context_return_program ? "../participants?program=$context_return_program" : "../participants");
    header("Location: $redirect");
    exit();
}

// Handle CSV Upload
if (isset($_POST['upload_csv'])) {
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $program_id = !empty($_POST['program_id']) ? intval($_POST['program_id']) : 0;
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != UPLOAD_ERR_OK) {
        send_notification($_SESSION['user_id'], 'Please select a valid CSV file', 'danger');
        $redirect = $project_id ? "../participants?project=$project_id" : ($program_id ? "../participants?program=$program_id" : "../participants");
        header("Location: $redirect");
        exit();
    }
    
    $file = $_FILES['csv_file'];
    
    // Validate file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_ext != 'csv') {
        send_notification($_SESSION['user_id'], 'Only CSV files are allowed', 'danger');
        $redirect = $project_id ? "../participants?project=$project_id" : ($program_id ? "../participants?program=$program_id" : "../participants");
        header("Location: $redirect");
        exit();
    }
    
    // Read CSV file
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        send_notification($_SESSION['user_id'], 'Error reading CSV file', 'danger');
        $redirect = $project_id ? "../participants?project=$project_id" : ($program_id ? "../participants?program=$program_id" : "../participants");
        header("Location: $redirect");
        exit();
    }
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $row_number = 0;
    
    // Skip BOM if present
    $bom = pack('CCC', 0xEF, 0xBB, 0xBF);
    if (fgets($handle, 4) !== $bom) {
        rewind($handle);
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    
    // Process data rows
    while (($data = fgetcsv($handle)) !== false) {
        $row_number++;
        
        // Skip empty rows
        if (empty(array_filter($data))) {
            continue;
        }
        
        // Skip instruction rows
        if (isset($data[0]) && (
            stripos($data[0], 'INSTRUCTIONS') !== false ||
            stripos($data[0], 'DELETE') !== false ||
            stripos($data[0], 'Fields marked') !== false ||
            stripos($data[0], 'VALID VALUES') !== false ||
            empty($data[0])
        )) {
            continue;
        }
        
        // Map data to expected fields
        $first_name = isset($data[0]) ? sanitize_input(trim($data[0])) : '';
        $last_name = isset($data[1]) ? sanitize_input(trim($data[1])) : '';
        $gender = isset($data[2]) ? sanitize_input(trim($data[2])) : '';
        $date_of_birth = isset($data[3]) && !empty(trim($data[3])) ? sanitize_input(trim($data[3])) : null;
        $phone = isset($data[4]) ? sanitize_input(trim($data[4])) : '';
        $email = isset($data[5]) ? sanitize_input(trim($data[5])) : '';
        $district = isset($data[6]) ? sanitize_input(trim($data[6])) : '';
        $subcounty = isset($data[7]) ? sanitize_input(trim($data[7])) : '';
        $village = isset($data[8]) ? sanitize_input(trim($data[8])) : '';
        $education_level = isset($data[9]) ? sanitize_input(trim($data[9])) : '';
        $occupation = isset($data[10]) ? sanitize_input(trim($data[10])) : '';
        $is_pwd_text = isset($data[11]) ? strtolower(trim($data[11])) : 'no';
        $disability_type = isset($data[12]) ? sanitize_input(trim($data[12])) : '';
        
        // Validate required fields
        if (empty($first_name)) {
            $errors[] = "Row $row_number: First name is required";
            $error_count++;
            continue;
        }
        
        if (empty($last_name)) {
            $errors[] = "Row $row_number: Last name is required";
            $error_count++;
            continue;
        }
        
        if (empty($gender)) {
            $errors[] = "Row $row_number: Gender is required";
            $error_count++;
            continue;
        }
        
        // Validate gender
        $valid_genders = ['Male', 'Female', 'Other'];
        if (!in_array($gender, $valid_genders)) {
            $errors[] = "Row $row_number: Invalid gender '$gender'. Must be: " . implode(', ', $valid_genders);
            $error_count++;
            continue;
        }
        
        // Validate date of birth if provided
        if ($date_of_birth) {
            $dob_timestamp = strtotime($date_of_birth);
            if ($dob_timestamp === false) {
                $errors[] = "Row $row_number: Invalid date format '$date_of_birth'. Use YYYY-MM-DD";
                $error_count++;
                continue;
            }
            $today = strtotime('today');
            if ($dob_timestamp > $today) {
                $errors[] = "Row $row_number: Date of birth cannot be in the future";
                $error_count++;
                continue;
            }
        }
        
        // Validate education level if provided
        if ($education_level && !in_array($education_level, ['None', 'Primary', 'Secondary', 'Tertiary', 'University'])) {
            $errors[] = "Row $row_number: Invalid education level '$education_level'";
            $error_count++;
            continue;
        }
        
        // Convert PWD text to boolean
        $is_pwd = in_array($is_pwd_text, ['yes', 'y', '1', 'true']) ? 1 : 0;
        
        // Check for duplicate (same name and phone)
        if (!empty($phone)) {
            $check_stmt = $conn->prepare("SELECT beneficiary_id FROM beneficiaries WHERE first_name = ? AND last_name = ? AND phone = ?");
            $check_stmt->bind_param("sss", $first_name, $last_name, $phone);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors[] = "Row $row_number: Duplicate Participant - $first_name $last_name with phone $phone already exists";
                $error_count++;
                continue;
            }
        }
        
        // Insert beneficiary
        try {
            $stmt = $conn->prepare("INSERT INTO beneficiaries (first_name, last_name, gender, date_of_birth, phone, email, district, subcounty, village, education_level, occupation, is_pwd, pwd_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssssss", $first_name, $last_name, $gender, $date_of_birth, $phone, $email, $district, $subcounty, $village, $education_level, $occupation, $is_pwd, $disability_type);
            
            if ($stmt->execute()) {
                $new_beneficiary_id = $stmt->insert_id;
                
                // Link to project if project_id is provided
                if ($project_id > 0) {
                    $link_stmt = $conn->prepare("INSERT INTO project_beneficiaries (project_id, beneficiary_id, status, enrollment_date) VALUES (?, ?, 'Active', NOW())");
                    $link_stmt->bind_param("ii", $project_id, $new_beneficiary_id);
                    $link_stmt->execute();
                }
                // Link to program if program_id is provided
                elseif ($program_id > 0) {
                    $link_stmt = $conn->prepare("INSERT INTO programs_beneficiaries (program_id, beneficiary_id, status, enrollment_date) VALUES (?, ?, 'Active', NOW())");
                    $link_stmt->bind_param("ii", $program_id, $new_beneficiary_id);
                    $link_stmt->execute();
                }
                
                $success_count++;
            } else {
                $errors[] = "Row $row_number: Database error - " . $conn->error;
                $error_count++;
            }
        } catch (Exception $e) {
            $errors[] = "Row $row_number: " . $e->getMessage();
            $error_count++;
        }
    }
    
    fclose($handle);
    
    // Log action
    $action_desc = '';
    if ($project_id) {
        $action_desc = "CSV upload: $success_count participants added and linked to project $project_id";
    } elseif ($program_id) {
        $action_desc = "CSV upload: $success_count participants added and linked to program $program_id";
    } else {
        $action_desc = "CSV upload: $success_count participants added";
    }
    log_action($_SESSION['user_id'], 'CSV Bulk Upload', 'participants', 0, "$action_desc, $error_count failed");
    
    // Send notification
    if ($success_count > 0 && $error_count == 0) {
        $message = $project_id ? "Successfully imported $success_count participants and linked to project" : 
                   ($program_id ? "Successfully imported $success_count participants and linked to program" : 
                   "Successfully imported $success_count participants");
        send_notification($_SESSION['user_id'], $message, 'success');
    } elseif ($success_count > 0 && $error_count > 0) {
        $error_summary = implode('<br>', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $error_summary .= '<br>... and ' . (count($errors) - 5) . ' more errors';
        }
        send_notification($_SESSION['user_id'], "Imported $success_count participants with $error_count errors:<br>$error_summary", 'warning');
    } else {
        $error_summary = implode('<br>', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $error_summary .= '<br>... and ' . (count($errors) - 5) . ' more errors';
        }
        send_notification($_SESSION['user_id'], "CSV import failed:<br>$error_summary", 'danger');
    }
    
    $redirect = $project_id ? "../participants?project=$project_id" : ($program_id ? "../participants?program=$program_id" : "../participants");
    header("Location: $redirect");
    exit();
}

// Handle Delete Beneficiary
if (isset($_POST['delete_beneficiary']) && ims_allowed('participants.delete', ['Administrator'], 'Delete participants')) {
    $beneficiary_id = intval($_POST['beneficiary_id']);
    
    // Get context for return navigation
    $context_return_project = !empty($_POST['context_return_project']) ? intval($_POST['context_return_project']) : 0;
    $context_return_program = !empty($_POST['context_return_program']) ? intval($_POST['context_return_program']) : 0;
    
    // Get beneficiary name for logging
    $beneficiary_result = $conn->query("SELECT first_name, last_name FROM beneficiaries WHERE beneficiary_id = $beneficiary_id");
    $beneficiary_data = $beneficiary_result->fetch_assoc();
    $beneficiary_name = ($beneficiary_data['first_name'] ?? '') . ' ' . ($beneficiary_data['last_name'] ?? '');
    
    // Check if beneficiary is linked to any projects or programs
    $project_check = $conn->query("SELECT COUNT(*) as count FROM project_beneficiaries WHERE beneficiary_id = $beneficiary_id");
    $project_count = $project_check->fetch_assoc()['count'];
    
    $program_check = $conn->query("SELECT COUNT(*) as count FROM programs_beneficiaries WHERE beneficiary_id = $beneficiary_id");
    $program_count = $program_check->fetch_assoc()['count'];
    
    $total_count = $project_count + $program_count;
    
    if ($total_count > 0) {
        $message = "Cannot delete participant. They are linked to $project_count project(s) and $program_count program(s). Remove them from all projects and programs first.";
        send_notification($_SESSION['user_id'], $message, 'danger');
    } else {
        if ($conn->query("DELETE FROM beneficiaries WHERE beneficiary_id = $beneficiary_id")) {
            log_action($_SESSION['user_id'], 'Delete Participant', 'participants', $beneficiary_id, "Deleted Participant: $beneficiary_name");
            send_notification($_SESSION['user_id'], 'Participant deleted successfully', 'success');
        } else {
            send_notification($_SESSION['user_id'], 'Error deleting participant: ' . $conn->error, 'danger');
        }
    }
    
    $redirect = $context_return_project ? "../participants?project=$context_return_project" : ($context_return_program ? "../participants?program=$context_return_program" : "../participants");
    header("Location: $redirect");
    exit();
}

// If no valid action, redirect to beneficiaries page
$project_id = !empty($_GET['project']) ? intval($_GET['project']) : 0;
$program_id = !empty($_GET['program']) ? intval($_GET['program']) : 0;
$redirect = $project_id ? "../participants?project=$project_id" : ($program_id ? "../participants?program=$program_id" : "../participants");
header("Location: $redirect");
exit();
?>