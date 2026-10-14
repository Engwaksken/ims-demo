<?php

session_start();
require_once 'config.php';

// Check if user is logged in and has admin/operations access
if (!isset($_SESSION['user_id']) || !ims_allowed('spaces.manage', ['Administrator', 'Operations/Admin'], 'Add and edit spaces')) {
    $_SESSION['error'] = "Access denied.";
    header("Location: ../manage-space");
    exit();
}

$user_id = $_SESSION['user_id'];

// ======================
// ADD NEW SPACE
// ======================
if (isset($_POST['action']) && $_POST['action'] == 'add') {
    $space_type = $conn->real_escape_string(sanitize_input($_POST['space_type']));
    $space_name = $conn->real_escape_string(sanitize_input($_POST['space_name']));
    $capacity = intval($_POST['capacity']);
    $is_available = intval($_POST['is_available']);
    $hourly_rate = floatval($_POST['hourly_rate']);
    $half_day_rate = floatval($_POST['half_day_rate']);
    $full_day_rate = floatval($_POST['full_day_rate']);
    $monthly_rate = isset($_POST['monthly_rate']) && $_POST['monthly_rate'] !== '' ? floatval($_POST['monthly_rate']) : null;
    $amenities = $conn->real_escape_string(sanitize_input($_POST['amenities']));
    
    // Validate required fields
    if (empty($space_type) || empty($space_name) || $capacity < 1) {
        $_SESSION['error'] = "Space type, name, and capacity are required.";
        header("Location: ../manage-space");
        exit();
    }
    
    // Handle image upload
    $space_image = null;
    if (isset($_FILES['space_image']) && $_FILES['space_image']['error'] == 0) {
        $upload_dir = '../uploads/spaces/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['space_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_extension, $allowed_extensions) && ims_validate_upload($_FILES['space_image'], $allowed_extensions)['ok']) {
            $new_filename = 'space_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['space_image']['tmp_name'], $target_file)) {
                $space_image = 'uploads/spaces/' . $new_filename;
            }
        }
    }
    
    // Insert space
    $query = "INSERT INTO space_availability (
        space_type, space_name, capacity, is_available, 
        hourly_rate, half_day_rate, full_day_rate, monthly_rate,
        amenities, space_image, created_at
    ) VALUES (
        '$space_type', '$space_name', $capacity, $is_available,
        $hourly_rate, $half_day_rate, $full_day_rate, " . ($monthly_rate !== null ? $monthly_rate : "NULL") . ",
        '$amenities', " . ($space_image ? "'$space_image'" : "NULL") . ", NOW()
    )";
    
    if ($conn->query($query)) {
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Add Space', 'Added space: $space_name ($space_type)', '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        $_SESSION['success'] = "Space added successfully!";
    } else {
        $_SESSION['error'] = "Error adding space: " . $conn->error;
    }
    
    header("Location: ../manage-space");
    exit();
}

// ======================
// EDIT SPACE
// ======================
if (isset($_POST['action']) && $_POST['action'] == 'edit') {
    $availability_id = intval($_POST['availability_id']);
    $space_type = $conn->real_escape_string(sanitize_input($_POST['space_type']));
    $space_name = $conn->real_escape_string(sanitize_input($_POST['space_name']));
    $capacity = intval($_POST['capacity']);
    $is_available = intval($_POST['is_available']);
    $hourly_rate = floatval($_POST['hourly_rate']);
    $half_day_rate = floatval($_POST['half_day_rate']);
    $full_day_rate = floatval($_POST['full_day_rate']);
    $monthly_rate = isset($_POST['monthly_rate']) && $_POST['monthly_rate'] !== '' ? floatval($_POST['monthly_rate']) : null;
    $amenities = $conn->real_escape_string(sanitize_input($_POST['amenities']));
    
    // Validate required fields
    if (empty($space_type) || empty($space_name) || $capacity < 1) {
        $_SESSION['error'] = "Space type, name, and capacity are required.";
        header("Location: ../manage-space?edit=$availability_id");
        exit();
    }
    
    // Check if space exists
    $check = $conn->query("SELECT space_image FROM space_availability WHERE availability_id = $availability_id");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Space not found.";
        header("Location: ../manage-space");
        exit();
    }
    
    $existing = $check->fetch_assoc();
    $space_image = $existing['space_image'];
    
    // Handle image upload
    if (isset($_FILES['space_image']) && $_FILES['space_image']['error'] == 0) {
        $upload_dir = '../uploads/spaces/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['space_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_extension, $allowed_extensions) && ims_validate_upload($_FILES['space_image'], $allowed_extensions)['ok']) {
            $new_filename = 'space_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['space_image']['tmp_name'], $target_file)) {
                // Delete old image
                if ($space_image && file_exists('../' . $space_image)) {
                    unlink('../' . $space_image);
                }
                $space_image = 'uploads/spaces/' . $new_filename;
            }
        }
    }
    
    // Update space
    $query = "UPDATE space_availability SET 
                space_type = '$space_type',
                space_name = '$space_name',
                capacity = $capacity,
                is_available = $is_available,
                hourly_rate = $hourly_rate,
                half_day_rate = $half_day_rate,
                full_day_rate = $full_day_rate,
                monthly_rate = " . ($monthly_rate !== null ? $monthly_rate : "NULL") . ",
                amenities = '$amenities',
                space_image = " . ($space_image ? "'$space_image'" : "NULL") . "
              WHERE availability_id = $availability_id";
    
    if ($conn->query($query)) {
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Edit Space', 'Updated space: $space_name', '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        $_SESSION['success'] = "Space updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating space: " . $conn->error;
    }
    
    header("Location: ../manage-space");
    exit();
}

// ======================
// TOGGLE AVAILABILITY
// ======================
if (isset($_GET['toggle_availability'])) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    $availability_id = intval($_GET['toggle_availability']);
    $status = intval($_GET['status']);
    
    $query = "UPDATE space_availability SET is_available = $status WHERE availability_id = $availability_id";
    
    if ($conn->query($query)) {
        // Get space name for log
        $result = $conn->query("SELECT space_name FROM space_availability WHERE availability_id = $availability_id");
        $space = $result->fetch_assoc();
        
        // Log activity
        $action_text = $status ? 'enabled' : 'disabled';
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Toggle Space Availability', 'Space {$space['space_name']} $action_text', '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        $_SESSION['success'] = "Space availability updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating availability: " . $conn->error;
    }
    
    header("Location: ../manage-space");
    exit();
}

// ======================
// DELETE SPACE
// ======================
if (isset($_GET['delete_space']) && ims_allowed('spaces.delete', ['Administrator'], 'Delete spaces')) {
    csrf_protect(true); // destructive GET link must carry the CSRF token
    $availability_id = intval($_GET['delete_space']);
    
    // Get space details
    $result = $conn->query("SELECT space_name, space_image FROM space_availability WHERE availability_id = $availability_id");
    
    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Space not found.";
        header("Location: ../manage-space");
        exit();
    }
    
    $space = $result->fetch_assoc();
    $space_image = $space['space_image'];
    
    // Delete space
    if ($conn->query("DELETE FROM space_availability WHERE availability_id = $availability_id")) {
        // Delete image file
        if ($space_image && file_exists('../' . $space_image)) {
            unlink('../' . $space_image);
        }
        
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, ip_address) 
                     VALUES ($user_id, 'Delete Space', 'Deleted space: {$space['space_name']}', '{$_SERVER['REMOTE_ADDR']}')";
        $conn->query($log_query);
        
        $_SESSION['success'] = "Space deleted successfully.";
    } else {
        $_SESSION['error'] = "Error deleting space: " . $conn->error;
    }
    
    header("Location: ../manage-space");
    exit();
}

// If no valid action, redirect
$_SESSION['error'] = "Invalid action.";
header("Location: ../manage-space");
exit();
?>