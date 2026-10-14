<?php

session_start();
require_once 'includes/config.php';

// Check if user is logged in and is Administrator
if (!ims_allowed('opportunities.manage_legacy', ['Administrator'], 'Manage opportunities (old opportunity-process handler)')) {
    $_SESSION['error'] = "Unauthorized access. Only administrators can manage opportunities.";
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];


// Log activity
function log_activity($conn, $user_id, $action, $details) {
    $user_id = (int)$user_id;
    $action = (string)$action;
    $details = stripslashes((string)$details);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('isss', $user_id, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// All string inputs below are interpolated into quoted SQL literals:
// escape them for the connection charset (SQL injection fix).
function opp_esc($value): string {
    global $conn;
    return $conn->real_escape_string(sanitize_input($value));
}

// Get action
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// State-changing GET links (publish/close/delete...) must carry the CSRF token.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action !== '') {
    csrf_protect(true);
}

// CREATE OPPORTUNITY
if ($action == 'create') {
    // Get form data
    $opportunity_title = opp_esc($_POST['opportunity_title']);
    $opportunity_type = opp_esc($_POST['opportunity_type']);
    $description = opp_esc($_POST['description']);
    $eligibility_criteria = opp_esc($_POST['eligibility_criteria']);
    $required_documents = opp_esc($_POST['required_documents']);
    
    $start_date = opp_esc($_POST['start_date']);
    $deadline = opp_esc($_POST['deadline']);
    $announcement_date = !empty($_POST['announcement_date']) ? opp_esc($_POST['announcement_date']) : NULL;
    
    $max_applicants = !empty($_POST['max_applicants']) ? intval($_POST['max_applicants']) : NULL;
    $available_slots = !empty($_POST['available_slots']) ? intval($_POST['available_slots']) : NULL;
    
    $min_team_size = !empty($_POST['min_team_size']) ? intval($_POST['min_team_size']) : 1;
    $max_team_size = !empty($_POST['max_team_size']) ? intval($_POST['max_team_size']) : NULL;
    
    $sectors_allowed = opp_esc($_POST['sectors_allowed']);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    
    // Determine status based on button clicked
    $status = 'Draft';
    $published_at = NULL;
    
    if (isset($_POST['publish'])) {
        $status = 'Published';
        $published_at = date('Y-m-d H:i:s');
    }
    
    // Validate dates
    if (strtotime($deadline) < strtotime($start_date)) {
        $_SESSION['error'] = "Deadline cannot be before start date.";
        header("Location: application-opportunities");
        exit();
    }
    
    // Insert opportunity
    $announcement_sql = $announcement_date ? "'$announcement_date'" : "NULL";
    $max_applicants_sql = $max_applicants ? $max_applicants : "NULL";
    $available_slots_sql = $available_slots ? $available_slots : "NULL";
    $max_team_size_sql = $max_team_size ? $max_team_size : "NULL";
    $published_at_sql = $published_at ? "'$published_at'" : "NULL";
    
    $query = "INSERT INTO application_opportunities (
        opportunity_title, opportunity_type, description, eligibility_criteria, 
        required_documents, start_date, deadline, announcement_date, max_applicants, 
        available_slots, min_team_size, max_team_size, sectors_allowed, is_featured, 
        status, created_by, published_at
    ) VALUES (
        '$opportunity_title', '$opportunity_type', '$description', '$eligibility_criteria',
        '$required_documents', '$start_date', '$deadline', $announcement_sql, $max_applicants_sql,
        $available_slots_sql, $min_team_size, $max_team_size_sql, '$sectors_allowed', $is_featured,
        '$status', $user_id, $published_at_sql
    )";
    
    if ($conn->query($query)) {
        $opportunity_id = $conn->insert_id;
        
        // Log activity
        log_activity($conn, $user_id, 'Create Opportunity', "Created opportunity: $opportunity_title (ID: $opportunity_id, Status: $status)");
        
        if ($status == 'Published') {
            $_SESSION['success'] = "Opportunity published successfully! Applications are now being accepted.";
        } else {
            $_SESSION['success'] = "Opportunity saved as draft. You can publish it when ready.";
        }
        
        header("Location: view-opportunity?id=$opportunity_id");
    } else {
        $_SESSION['error'] = "Error creating opportunity: " . $conn->error;
        header("Location: application-opportunities");
    }
    exit();
}

// UPDATE OPPORTUNITY
if ($action == 'update') {
    $opportunity_id = intval($_POST['opportunity_id']);
    
    // Check if opportunity exists and user is admin
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found.";
        header("Location: application-opportunities");
        exit();
    }
    
    // Get form data
    $opportunity_title = opp_esc($_POST['opportunity_title']);
    $opportunity_type = opp_esc($_POST['opportunity_type']);
    $description = opp_esc($_POST['description']);
    $eligibility_criteria = opp_esc($_POST['eligibility_criteria']);
    $required_documents = opp_esc($_POST['required_documents']);
    
    $start_date = opp_esc($_POST['start_date']);
    $deadline = opp_esc($_POST['deadline']);
    $announcement_date = !empty($_POST['announcement_date']) ? opp_esc($_POST['announcement_date']) : NULL;
    
    $max_applicants = !empty($_POST['max_applicants']) ? intval($_POST['max_applicants']) : NULL;
    $available_slots = !empty($_POST['available_slots']) ? intval($_POST['available_slots']) : NULL;
    
    $min_team_size = !empty($_POST['min_team_size']) ? intval($_POST['min_team_size']) : 1;
    $max_team_size = !empty($_POST['max_team_size']) ? intval($_POST['max_team_size']) : NULL;
    
    $sectors_allowed = opp_esc($_POST['sectors_allowed']);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    
    // Build update query
    $announcement_sql = $announcement_date ? "'$announcement_date'" : "NULL";
    $max_applicants_sql = $max_applicants ? $max_applicants : "NULL";
    $available_slots_sql = $available_slots ? $available_slots : "NULL";
    $max_team_size_sql = $max_team_size ? $max_team_size : "NULL";
    
    $query = "UPDATE application_opportunities SET
        opportunity_title = '$opportunity_title',
        opportunity_type = '$opportunity_type',
        description = '$description',
        eligibility_criteria = '$eligibility_criteria',
        required_documents = '$required_documents',
        start_date = '$start_date',
        deadline = '$deadline',
        announcement_date = $announcement_sql,
        max_applicants = $max_applicants_sql,
        available_slots = $available_slots_sql,
        min_team_size = $min_team_size,
        max_team_size = $max_team_size_sql,
        sectors_allowed = '$sectors_allowed',
        is_featured = $is_featured
        WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        log_activity($conn, $user_id, 'Update Opportunity', "Updated opportunity: $opportunity_title (ID: $opportunity_id)");
        $_SESSION['success'] = "Opportunity updated successfully!";
        header("Location: view-opportunity?id=$opportunity_id");
    } else {
        $_SESSION['error'] = "Error updating opportunity: " . $conn->error;
        header("Location: edit-opportunity?id=$opportunity_id");
    }
    exit();
}

// PUBLISH OPPORTUNITY
if ($action == 'publish') {
    $opportunity_id = intval($_GET['id']);
    
    // Check if opportunity exists
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id AND status = 'Draft'");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found or already published.";
        header("Location: application-opportunities");
        exit();
    }
    
    $opportunity = $check->fetch_assoc();
    
    // Update status to Published
    $published_at = date('Y-m-d H:i:s');
    $query = "UPDATE application_opportunities SET 
              status = 'Published', 
              published_at = '$published_at' 
              WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        log_activity($conn, $user_id, 'Publish Opportunity', "Published opportunity: {$opportunity['opportunity_title']} (ID: $opportunity_id)");
        $_SESSION['success'] = "Opportunity published successfully! It is now visible to applicants.";
    } else {
        $_SESSION['error'] = "Error publishing opportunity: " . $conn->error;
    }
    
    header("Location: application-opportunities");
    exit();
}

// CLOSE OPPORTUNITY
if ($action == 'close') {
    $opportunity_id = intval($_GET['id']);
    
    // Check if opportunity exists
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id AND status = 'Published'");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found or not published.";
        header("Location: application-opportunities");
        exit();
    }
    
    $opportunity = $check->fetch_assoc();
    
    // Update status to Closed
    $query = "UPDATE application_opportunities SET status = 'Closed' WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        log_activity($conn, $user_id, 'Close Opportunity', "Closed opportunity: {$opportunity['opportunity_title']} (ID: $opportunity_id)");
        $_SESSION['success'] = "Opportunity closed successfully. No new applications will be accepted.";
    } else {
        $_SESSION['error'] = "Error closing opportunity: " . $conn->error;
    }
    
    header("Location: application-opportunities");
    exit();
}

// REOPEN OPPORTUNITY
if ($action == 'reopen') {
    $opportunity_id = intval($_GET['id']);
    
    // Check if opportunity exists
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id AND status = 'Closed'");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found or not closed.";
        header("Location: application-opportunities");
        exit();
    }
    
    $opportunity = $check->fetch_assoc();
    
    // Update status to Published
    $query = "UPDATE application_opportunities SET status = 'Published' WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        log_activity($conn, $user_id, 'Reopen Opportunity', "Reopened opportunity: {$opportunity['opportunity_title']} (ID: $opportunity_id)");
        $_SESSION['success'] = "Opportunity reopened successfully. Applications are now being accepted again.";
    } else {
        $_SESSION['error'] = "Error reopening opportunity: " . $conn->error;
    }
    
    header("Location: application-opportunities");
    exit();
}

// MARK AS COMPLETED
if ($action == 'complete') {
    $opportunity_id = intval($_GET['id']);
    
    // Check if opportunity exists
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found.";
        header("Location: application-opportunities");
        exit();
    }
    
    $opportunity = $check->fetch_assoc();
    
    // Update status to Completed
    $query = "UPDATE application_opportunities SET status = 'Completed' WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        log_activity($conn, $user_id, 'Complete Opportunity', "Marked opportunity as completed: {$opportunity['opportunity_title']} (ID: $opportunity_id)");
        $_SESSION['success'] = "Opportunity marked as completed.";
    } else {
        $_SESSION['error'] = "Error updating opportunity: " . $conn->error;
    }
    
    header("Location: application-opportunities");
    exit();
}

// TOGGLE FEATURED
if ($action == 'toggle_featured') {
    $opportunity_id = intval($_GET['id']);
    
    // Check if opportunity exists
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found.";
        header("Location: application-opportunities");
        exit();
    }
    
    $opportunity = $check->fetch_assoc();
    $new_featured = $opportunity['is_featured'] ? 0 : 1;
    
    // Toggle featured status
    $query = "UPDATE application_opportunities SET is_featured = $new_featured WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        $action_text = $new_featured ? "Featured" : "Unfeatured";
        log_activity($conn, $user_id, 'Toggle Featured', "$action_text opportunity: {$opportunity['opportunity_title']} (ID: $opportunity_id)");
        $_SESSION['success'] = "Opportunity " . strtolower($action_text) . " successfully!";
    } else {
        $_SESSION['error'] = "Error updating opportunity: " . $conn->error;
    }
    
    header("Location: application-opportunities");
    exit();
}

// DELETE OPPORTUNITY
if ($action == 'delete') {
    $opportunity_id = intval($_GET['id']);
    
    // Check if opportunity exists and is in Draft status
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found.";
        header("Location: application-opportunities");
        exit();
    }
    
    $opportunity = $check->fetch_assoc();
    
    // Only allow deletion of Draft opportunities
    if ($opportunity['status'] != 'Draft') {
        $_SESSION['error'] = "Only draft opportunities can be deleted. Please close this opportunity instead.";
        header("Location: application-opportunities");
        exit();
    }
    
    // Check if there are any applications
    $app_check = $conn->query("SELECT COUNT(*) as count FROM applications WHERE opportunity_id = $opportunity_id");
    $app_count = $app_check->fetch_assoc()['count'];
    
    if ($app_count > 0) {
        $_SESSION['error'] = "Cannot delete opportunity with existing applications. Please close it instead.";
        header("Location: application-opportunities");
        exit();
    }
    
    // Delete opportunity
    $query = "DELETE FROM application_opportunities WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        log_activity($conn, $user_id, 'Delete Opportunity', "Deleted opportunity: {$opportunity['opportunity_title']} (ID: $opportunity_id)");
        $_SESSION['success'] = "Opportunity deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting opportunity: " . $conn->error;
    }
    
    header("Location: application-opportunities");
    exit();
}

// EXTEND DEADLINE
if ($action == 'extend_deadline') {
    $opportunity_id = intval($_POST['opportunity_id']);
    $new_deadline = opp_esc($_POST['new_deadline']);
    
    // Check if opportunity exists
    $check = $conn->query("SELECT * FROM application_opportunities WHERE opportunity_id = $opportunity_id");
    if ($check->num_rows == 0) {
        $_SESSION['error'] = "Opportunity not found.";
        header("Location: application-opportunities");
        exit();
    }
    
    $opportunity = $check->fetch_assoc();
    
    // Validate new deadline
    if (strtotime($new_deadline) <= strtotime($opportunity['deadline'])) {
        $_SESSION['error'] = "New deadline must be after the current deadline.";
        header("Location: view-opportunity?id=$opportunity_id");
        exit();
    }
    
    // Update deadline
    $query = "UPDATE application_opportunities SET deadline = '$new_deadline' WHERE opportunity_id = $opportunity_id";
    
    if ($conn->query($query)) {
        log_activity($conn, $user_id, 'Extend Deadline', "Extended deadline for: {$opportunity['opportunity_title']} to $new_deadline");
        $_SESSION['success'] = "Deadline extended successfully to " . date('d M Y', strtotime($new_deadline)) . "!";
    } else {
        $_SESSION['error'] = "Error extending deadline: " . $conn->error;
    }
    
    header("Location: view-opportunity?id=$opportunity_id");
    exit();
}

// BULK ACTIONS
if ($action == 'bulk_action') {
    $bulk_action = opp_esc($_POST['bulk_action']);
    $selected_ids = isset($_POST['selected_opportunities']) ? $_POST['selected_opportunities'] : [];
    
    if (empty($selected_ids)) {
        $_SESSION['error'] = "No opportunities selected.";
        header("Location: application-opportunities");
        exit();
    }
    
    $ids = implode(',', array_map('intval', $selected_ids));
    $count = 0;
    
    switch ($bulk_action) {
        case 'publish':
            $published_at = date('Y-m-d H:i:s');
            $query = "UPDATE application_opportunities SET status = 'Published', published_at = '$published_at' 
                      WHERE opportunity_id IN ($ids) AND status = 'Draft'";
            if ($conn->query($query)) {
                $count = $conn->affected_rows;
                log_activity($conn, $user_id, 'Bulk Publish', "Published $count opportunities");
                $_SESSION['success'] = "$count opportunity/opportunities published successfully!";
            }
            break;
            
        case 'close':
            $query = "UPDATE application_opportunities SET status = 'Closed' 
                      WHERE opportunity_id IN ($ids) AND status = 'Published'";
            if ($conn->query($query)) {
                $count = $conn->affected_rows;
                log_activity($conn, $user_id, 'Bulk Close', "Closed $count opportunities");
                $_SESSION['success'] = "$count opportunity/opportunities closed successfully!";
            }
            break;
            
        case 'feature':
            $query = "UPDATE application_opportunities SET is_featured = 1 WHERE opportunity_id IN ($ids)";
            if ($conn->query($query)) {
                $count = $conn->affected_rows;
                log_activity($conn, $user_id, 'Bulk Feature', "Featured $count opportunities");
                $_SESSION['success'] = "$count opportunity/opportunities marked as featured!";
            }
            break;
            
        case 'unfeature':
            $query = "UPDATE application_opportunities SET is_featured = 0 WHERE opportunity_id IN ($ids)";
            if ($conn->query($query)) {
                $count = $conn->affected_rows;
                log_activity($conn, $user_id, 'Bulk Unfeature', "Unfeatured $count opportunities");
                $_SESSION['success'] = "$count opportunity/opportunities unfeatured!";
            }
            break;
            
        case 'delete':
            // Only delete drafts with no applications
            $query = "DELETE FROM application_opportunities 
                      WHERE opportunity_id IN ($ids) 
                      AND status = 'Draft' 
                      AND opportunity_id NOT IN (SELECT DISTINCT opportunity_id FROM applications)";
            if ($conn->query($query)) {
                $count = $conn->affected_rows;
                log_activity($conn, $user_id, 'Bulk Delete', "Deleted $count draft opportunities");
                $_SESSION['success'] = "$count draft opportunity/opportunities deleted!";
            }
            break;
            
        default:
            $_SESSION['error'] = "Invalid bulk action.";
    }
    
    header("Location: application-opportunities");
    exit();
}

// If no valid action
$_SESSION['error'] = "Invalid action.";
header("Location: application-opportunities");
exit();
?>