<?php
require_once __DIR__ . '/includes/config.php';

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

const KPI_REVIEWER_ROLES = ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR'];
const KPI_QUARTER_STATUSES = ['Not Started', 'In Progress', 'Completed', 'Delayed'];
const KPI_COMMENT_QUARTERS = ['Q1', 'Q2', 'Q3', 'Q4', 'Annual'];
const KPI_COMMENT_TYPES = ['Progress Update', 'Challenge', 'Achievement', 'Feedback', 'Review'];

$current_user_id = (int) $_SESSION['user_id'];
$is_kpi_reviewer = ims_allowed('kpis.comment_all', KPI_REVIEWER_ROLES, "Comment on any staff member's KPI");

// Legacy GET links (?action=submit|delete&id=) are still accepted, but only
// with a valid csrf_token query parameter; POST is CSRF-checked globally.
$action = (string) ($_POST['action'] ?? '');
if ($action === '' && isset($_GET['action']) && in_array($_GET['action'], ['submit', 'delete'], true)) {
    csrf_protect(true);
    $action = (string) $_GET['action'];
    $_POST['kpi_id'] = (int) ($_GET['id'] ?? 0);
}
if ($action === 'edit') {
    $action = 'update'; // edit form posts action=edit
}

// ======================
// HELPERS
// ======================

function kpi_fetch(mysqli $conn, int $kpi_id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM kpis WHERE kpi_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $kpi_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function kpi_redirect(string $location, ?string $message = null, string $type = 'info'): never
{
    if ($message !== null) {
        send_notification($_SESSION['user_id'], $message, $type);
    }
    header("Location: $location");
    exit();
}

function kpi_add_history(mysqli $conn, int $kpi_id, int $reviewer_id, string $type, string $comments): void
{
    $stmt = $conn->prepare("INSERT INTO kpi_review_history (kpi_id, reviewer_id, review_type, review_comments) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('iiss', $kpi_id, $reviewer_id, $type, $comments);
        $stmt->execute();
        $stmt->close();
    }
}

function kpi_owned_or_redirect(mysqli $conn, int $kpi_id, int $user_id, string $verb): array
{
    $kpi = kpi_fetch($conn, $kpi_id);
    if (!$kpi) {
        kpi_redirect('my-kpis', 'KPI not found', 'danger');
    }
    if ((int) $kpi['user_id'] !== $user_id) {
        kpi_redirect('my-kpis', "You do not have permission to $verb this KPI", 'danger');
    }
    return $kpi;
}

function kpi_review_back(int $kpi_id): string
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    return str_contains($referer, 'view-kpi') ? "view-kpi?id=$kpi_id" : 'kpi-management';
}

function calculate_overall_achievement(mysqli $conn, int $kpi_id): void
{
    $kpi = kpi_fetch($conn, $kpi_id);
    if (!$kpi) {
        return;
    }

    $valid_achievements = array_filter(
        [$kpi['q1_achievement'], $kpi['q2_achievement'], $kpi['q3_achievement'], $kpi['q4_achievement']],
        fn($a) => $a !== null
    );

    if (empty($valid_achievements)) {
        return;
    }

    $overall = round(array_sum(array_map('floatval', $valid_achievements)) / count($valid_achievements), 2);
    $done = count($valid_achievements) === 4 ? 1 : 0;

    $stmt = $conn->prepare("UPDATE kpis SET overall_achievement = ?, status = IF(? = 1, 'Completed', status) WHERE kpi_id = ?");
    if ($stmt) {
        $stmt->bind_param('dii', $overall, $done, $kpi_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Get user's department (authoritative - never trust department_id from the form)
$user_dept = null;
$stmt = $conn->prepare("SELECT department_id FROM employee_directory WHERE user_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $user_dept = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ======================
// CREATE KPI
// ======================
if ($action === 'create') {
    if (!$user_dept || !$user_dept['department_id']) {
        kpi_redirect('my-profile', 'Please complete your employee profile first', 'danger');
    }

    $user_id = $current_user_id;
    $department_id = (int) $user_dept['department_id'];
    $fiscal_year = (int) ($_POST['fiscal_year'] ?? date('Y'));
    $category_id = (int) ($_POST['category_id'] ?? 0);

    $kpi_title = sanitize_input($_POST['kpi_title'] ?? '');
    $kpi_description = sanitize_input($_POST['kpi_description'] ?? '');
    $measurement_criteria = sanitize_input($_POST['measurement_criteria'] ?? '');
    $target_value = (float) ($_POST['target_value'] ?? 0);
    $unit_of_measure = sanitize_input($_POST['unit_of_measure'] ?? '');
    $weight_percentage = (float) ($_POST['weight_percentage'] ?? 0);

    $q1_target = (float) ($_POST['q1_target'] ?? 0);
    $q2_target = (float) ($_POST['q2_target'] ?? 0);
    $q3_target = (float) ($_POST['q3_target'] ?? 0);
    $q4_target = (float) ($_POST['q4_target'] ?? 0);

    if ($kpi_title === '' || $measurement_criteria === '' || $target_value <= 0 || $category_id <= 0) {
        kpi_redirect('create-kpi', 'Please fill in all required fields', 'danger');
    }
    if ($fiscal_year < 2000 || $fiscal_year > (int) date('Y') + 5) {
        kpi_redirect('create-kpi', 'Invalid fiscal year', 'danger');
    }
    if ($weight_percentage < 0 || $weight_percentage > 100) {
        kpi_redirect('create-kpi', 'Weight percentage must be between 0 and 100', 'danger');
    }
    if ($q1_target < 0 || $q2_target < 0 || $q3_target < 0 || $q4_target < 0) {
        kpi_redirect('create-kpi', 'Quarterly targets cannot be negative', 'danger');
    }

    $status = isset($_POST['submit_kpi']) ? 'Submitted' : 'Draft';
    $submitted_at = isset($_POST['submit_kpi']) ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("INSERT INTO kpis (
        user_id, department_id, fiscal_year, category_id,
        kpi_title, kpi_description, measurement_criteria,
        target_value, unit_of_measure, weight_percentage,
        q1_target, q2_target, q3_target, q4_target,
        status, submitted_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param("iiiisssdsdddddss",
        $user_id, $department_id, $fiscal_year, $category_id,
        $kpi_title, $kpi_description, $measurement_criteria,
        $target_value, $unit_of_measure, $weight_percentage,
        $q1_target, $q2_target, $q3_target, $q4_target,
        $status, $submitted_at
    );

    if ($stmt->execute()) {
        $kpi_id = (int) $conn->insert_id;
        $stmt->close();

        $action_desc = $status === 'Submitted'
            ? "Created and submitted KPI: $kpi_title for $fiscal_year"
            : "Created draft KPI: $kpi_title for $fiscal_year";
        log_action($current_user_id, 'Create KPI', 'kpis', $kpi_id, $action_desc);

        if ($status === 'Submitted') {
            kpi_add_history($conn, $kpi_id, $current_user_id, 'Submitted', 'KPI submitted for review');
        }

        kpi_redirect(
            "view-kpi?id=$kpi_id",
            $status === 'Submitted' ? 'KPI created and submitted for review successfully' : 'KPI saved as draft successfully',
            'success'
        );
    }

    error_log('Create KPI failed: ' . $stmt->error);
    kpi_redirect('create-kpi', 'Error creating KPI. Please try again.', 'danger');
}

// ======================
// UPDATE KPI (action=update or action=edit)
// ======================
if ($action === 'update') {
    $kpi_id = (int) ($_POST['kpi_id'] ?? 0);
    $kpi_data = kpi_owned_or_redirect($conn, $kpi_id, $current_user_id, 'edit');

    if (!in_array($kpi_data['status'], ['Draft', 'Rejected'], true)) {
        kpi_redirect("view-kpi?id=$kpi_id", 'Only draft or rejected KPIs can be edited', 'danger');
    }

    $category_id = (int) ($_POST['category_id'] ?? $kpi_data['category_id']);
    $fiscal_year = (int) ($_POST['fiscal_year'] ?? $kpi_data['fiscal_year']);
    $kpi_title = sanitize_input($_POST['kpi_title'] ?? '');
    $kpi_description = sanitize_input($_POST['kpi_description'] ?? '');
    $measurement_criteria = sanitize_input($_POST['measurement_criteria'] ?? '');
    $target_value = (float) ($_POST['target_value'] ?? 0);
    $unit_of_measure = sanitize_input($_POST['unit_of_measure'] ?? '');
    $weight_percentage = (float) ($_POST['weight_percentage'] ?? 0);

    $q1_target = (float) ($_POST['q1_target'] ?? 0);
    $q2_target = (float) ($_POST['q2_target'] ?? 0);
    $q3_target = (float) ($_POST['q3_target'] ?? 0);
    $q4_target = (float) ($_POST['q4_target'] ?? 0);

    if ($kpi_title === '' || $measurement_criteria === '' || $target_value <= 0 || $category_id <= 0) {
        kpi_redirect("edit-kpi?id=$kpi_id", 'Please fill in all required fields', 'danger');
    }
    if ($weight_percentage < 0 || $weight_percentage > 100) {
        kpi_redirect("edit-kpi?id=$kpi_id", 'Weight percentage must be between 0 and 100', 'danger');
    }
    if ($q1_target < 0 || $q2_target < 0 || $q3_target < 0 || $q4_target < 0) {
        kpi_redirect("edit-kpi?id=$kpi_id", 'Quarterly targets cannot be negative', 'danger');
    }
    if ($fiscal_year < 2000 || $fiscal_year > (int) date('Y') + 5) {
        $fiscal_year = (int) $kpi_data['fiscal_year'];
    }

    $status = isset($_POST['submit_kpi']) ? 'Submitted' : 'Draft';
    $submitted_sql = $status === 'Submitted' ? ", submitted_at = NOW(), rejection_reason = NULL" : "";

    // Bug fix: the type string previously declared 14 types for 13 variables,
    // so bind_param threw and every KPI edit failed.
    $stmt = $conn->prepare("UPDATE kpis SET
        category_id = ?, fiscal_year = ?, kpi_title = ?, kpi_description = ?, measurement_criteria = ?,
        target_value = ?, unit_of_measure = ?, weight_percentage = ?,
        q1_target = ?, q2_target = ?, q3_target = ?, q4_target = ?,
        status = ?, updated_at = NOW()
        $submitted_sql
        WHERE kpi_id = ? AND user_id = ?");

    $stmt->bind_param("iisssdsdddddsii",
        $category_id, $fiscal_year, $kpi_title, $kpi_description, $measurement_criteria,
        $target_value, $unit_of_measure, $weight_percentage,
        $q1_target, $q2_target, $q3_target, $q4_target,
        $status, $kpi_id, $current_user_id
    );

    if ($stmt->execute()) {
        log_action($current_user_id, 'Update KPI', 'kpis', $kpi_id, "Updated KPI: $kpi_title");

        if ($status === 'Submitted') {
            kpi_add_history($conn, $kpi_id, $current_user_id, 'Submitted', 'KPI resubmitted for review');
        }

        send_notification($current_user_id, $status === 'Submitted'
            ? 'KPI updated and submitted for review successfully'
            : 'KPI updated successfully', 'success');
    } else {
        error_log('Update KPI failed: ' . $stmt->error);
        send_notification($current_user_id, 'Error updating KPI. Please try again.', 'danger');
    }
    $stmt->close();

    kpi_redirect("view-kpi?id=$kpi_id");
}

// ======================
// UPDATE PROGRESS
// ======================
if ($action === 'update_progress') {
    $kpi_id = (int) ($_POST['kpi_id'] ?? 0);
    $quarter = strtoupper(sanitize_input($_POST['quarter'] ?? ''));

    $kpi_data = kpi_owned_or_redirect($conn, $kpi_id, $current_user_id, 'update');

    if ($kpi_data['status'] !== 'Approved') {
        kpi_redirect("view-kpi?id=$kpi_id", 'Only approved KPIs can be updated', 'danger');
    }

    // Quarter is used as a column prefix: strict whitelist (was SQL injectable).
    $quarter_columns = ['Q1' => 'q1', 'Q2' => 'q2', 'Q3' => 'q3', 'Q4' => 'q4'];
    if (!isset($quarter_columns[$quarter])) {
        kpi_redirect("view-kpi?id=$kpi_id", 'Invalid quarter selected', 'danger');
    }
    $q_lower = $quarter_columns[$quarter];

    $actual = (float) ($_POST['actual'] ?? 0);
    $status = sanitize_input($_POST['status'] ?? '');
    if (!in_array($status, KPI_QUARTER_STATUSES, true)) {
        $status = 'In Progress';
    }
    $comment = sanitize_input($_POST['comment'] ?? '');

    $target = (float) ($kpi_data["{$q_lower}_target"] ?? 0);
    $achievement = $target > 0 ? round(($actual / $target) * 100, 2) : 0.0;
    $achievement = max(-999.99, min($achievement, 999.99)); // decimal(5,2) column

    $stmt = $conn->prepare("UPDATE kpis SET
        {$q_lower}_actual = ?,
        {$q_lower}_status = ?,
        {$q_lower}_achievement = ?,
        updated_at = NOW()
        WHERE kpi_id = ?");
    $stmt->bind_param("dsdi", $actual, $status, $achievement, $kpi_id);

    if ($stmt->execute()) {
        if ($comment !== '') {
            $stmt2 = $conn->prepare("INSERT INTO kpi_comments (kpi_id, user_id, quarter, comment_type, comment_text)
                                    VALUES (?, ?, ?, 'Progress Update', ?)");
            $stmt2->bind_param("iiss", $kpi_id, $current_user_id, $quarter, $comment);
            $stmt2->execute();
            $stmt2->close();
        }

        calculate_overall_achievement($conn, $kpi_id);

        log_action($current_user_id, 'Update KPI Progress', 'kpis', $kpi_id,
                   "Updated $quarter progress for: {$kpi_data['kpi_title']}");
        send_notification($current_user_id, "$quarter progress updated successfully", 'success');
    } else {
        error_log('Update KPI progress failed: ' . $stmt->error);
        send_notification($current_user_id, 'Error updating progress. Please try again.', 'danger');
    }
    $stmt->close();

    kpi_redirect("view-kpi?id=$kpi_id");
}

// ======================
// SUBMIT KPI (from list)
// ======================
if ($action === 'submit') {
    $kpi_id = (int) ($_POST['kpi_id'] ?? 0);
    $kpi_data = kpi_owned_or_redirect($conn, $kpi_id, $current_user_id, 'submit');

    if (!in_array($kpi_data['status'], ['Draft', 'Rejected'], true)) {
        kpi_redirect('my-kpis', 'This KPI has already been submitted', 'warning');
    }

    $stmt = $conn->prepare("UPDATE kpis SET status = 'Submitted', submitted_at = NOW(), rejection_reason = NULL WHERE kpi_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $kpi_id, $current_user_id);

    if ($stmt->execute()) {
        kpi_add_history($conn, $kpi_id, $current_user_id, 'Submitted', 'KPI submitted for review');
        log_action($current_user_id, 'Submit KPI', 'kpis', $kpi_id, "Submitted KPI: {$kpi_data['kpi_title']}");
        send_notification($current_user_id, 'KPI submitted for review successfully', 'success');
    } else {
        error_log('Submit KPI failed: ' . $stmt->error);
        send_notification($current_user_id, 'Error submitting KPI. Please try again.', 'danger');
    }
    $stmt->close();

    kpi_redirect('my-kpis');
}

// ======================
// DELETE KPI
// ======================
if ($action === 'delete') {
    $kpi_id = (int) ($_POST['kpi_id'] ?? 0);
    $kpi_data = kpi_owned_or_redirect($conn, $kpi_id, $current_user_id, 'delete');

    // Draft and rejected KPIs can be deleted (the list shows Delete for both)
    if (!in_array($kpi_data['status'], ['Draft', 'Rejected'], true)) {
        kpi_redirect('my-kpis', 'Only draft or rejected KPIs can be deleted', 'danger');
    }

    $stmt = $conn->prepare("DELETE FROM kpis WHERE kpi_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $kpi_id, $current_user_id);

    if ($stmt->execute()) {
        log_action($current_user_id, 'Delete KPI', 'kpis', $kpi_id, "Deleted KPI: {$kpi_data['kpi_title']}");
        send_notification($current_user_id, 'KPI deleted successfully', 'success');
    } else {
        error_log('Delete KPI failed: ' . $stmt->error);
        send_notification($current_user_id, 'Error deleting KPI. Please try again.', 'danger');
    }
    $stmt->close();

    kpi_redirect('my-kpis');
}

// ======================
// HR: APPROVE / REJECT KPI
// ======================
if ($action === 'approve' || $action === 'reject') {
    // Same role list as kpi-management.php (previously HR and Operations/Admin
    // could open the review screen but every approve/reject was denied).
    if (!ims_allowed('kpis.approve', KPI_REVIEWER_ROLES, 'Approve or reject KPIs')) {
        kpi_redirect('kpi-management', 'You do not have permission to review KPIs', 'danger');
    }

    $kpi_id = (int) ($_POST['kpi_id'] ?? 0);
    $back = kpi_review_back($kpi_id);
    $kpi_data = kpi_fetch($conn, $kpi_id);

    if (!$kpi_data) {
        kpi_redirect('kpi-management', 'KPI not found', 'danger');
    }
    if (!in_array($kpi_data['status'], ['Submitted', 'Under Review'], true)) {
        kpi_redirect($back, 'Only submitted KPIs can be reviewed', 'warning');
    }
    if ((int) $kpi_data['user_id'] === $current_user_id && !ims_allowed('kpis.approve_own', ['Administrator'], 'Approve or reject their own KPI')) {
        kpi_redirect($back, 'You cannot review your own KPI', 'danger');
    }

    if ($action === 'approve') {
        $review_comments = sanitize_input($_POST['review_comments'] ?? '');

        $stmt = $conn->prepare("UPDATE kpis SET
            status = 'Approved', reviewed_by = ?, reviewed_at = NOW(),
            approved_by = ?, approved_at = NOW(), rejection_reason = NULL
            WHERE kpi_id = ?");
        $stmt->bind_param("iii", $current_user_id, $current_user_id, $kpi_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            kpi_add_history($conn, $kpi_id, $current_user_id, 'Approved', $review_comments);
            notify_user((int) $kpi_data['user_id'], 'KPI approved', "Your KPI '{$kpi_data['kpi_title']}' has been approved", 'success', $kpi_id, 'kpi');
            log_action($current_user_id, 'Approve KPI', 'kpis', $kpi_id, "Approved KPI: {$kpi_data['kpi_title']}");
            kpi_redirect($back, 'KPI approved successfully', 'success');
        }

        kpi_redirect($back, 'Error approving KPI. Please try again.', 'danger');
    }

    $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');
    if ($rejection_reason === '') {
        kpi_redirect($back, 'Please provide a reason for rejection', 'danger');
    }

    $stmt = $conn->prepare("UPDATE kpis SET
        status = 'Rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
        WHERE kpi_id = ?");
    $stmt->bind_param("isi", $current_user_id, $rejection_reason, $kpi_id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        kpi_add_history($conn, $kpi_id, $current_user_id, 'Rejected', $rejection_reason);
        notify_user((int) $kpi_data['user_id'], 'KPI needs changes', "Your KPI '{$kpi_data['kpi_title']}' has been rejected. Please review feedback and resubmit.", 'warning', $kpi_id, 'kpi');
        log_action($current_user_id, 'Reject KPI', 'kpis', $kpi_id, "Rejected KPI: {$kpi_data['kpi_title']}");
        kpi_redirect($back, 'KPI rejected successfully', 'success');
    }

    kpi_redirect($back, 'Error rejecting KPI. Please try again.', 'danger');
}

// ======================
// ADD COMMENT
// ======================
if ($action === 'add_comment') {
    $kpi_id = (int) ($_POST['kpi_id'] ?? 0);
    $quarter = sanitize_input($_POST['quarter'] ?? 'Annual');
    $comment_type = sanitize_input($_POST['comment_type'] ?? 'Progress Update');
    $comment_text = sanitize_input($_POST['comment_text'] ?? '');

    $kpi_data = kpi_fetch($conn, $kpi_id);
    if (!$kpi_data) {
        kpi_redirect('my-kpis', 'KPI not found', 'danger');
    }

    // IDOR fix: only the owner or a KPI reviewer may comment.
    if ((int) $kpi_data['user_id'] !== $current_user_id && !$is_kpi_reviewer) {
        kpi_redirect('my-kpis', 'You do not have permission to comment on this KPI', 'danger');
    }

    if (!in_array($quarter, KPI_COMMENT_QUARTERS, true)) {
        $quarter = 'Annual';
    }
    if (!in_array($comment_type, KPI_COMMENT_TYPES, true)) {
        $comment_type = 'Progress Update';
    }
    if ($comment_text === '') {
        kpi_redirect("view-kpi?id=$kpi_id", 'Comment cannot be empty', 'danger');
    }

    $stmt = $conn->prepare("INSERT INTO kpi_comments (kpi_id, user_id, quarter, comment_type, comment_text)
                           VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $kpi_id, $current_user_id, $quarter, $comment_type, $comment_text);

    if ($stmt->execute()) {
        log_action($current_user_id, 'Add KPI Comment', 'kpis', $kpi_id, "Added comment to KPI");
        send_notification($current_user_id, 'Comment added successfully', 'success');
    } else {
        error_log('Add KPI comment failed: ' . $stmt->error);
        send_notification($current_user_id, 'Error adding comment. Please try again.', 'danger');
    }
    $stmt->close();

    kpi_redirect("view-kpi?id=$kpi_id");
}

// If no valid action, redirect
header("Location: my-kpis");
exit();
