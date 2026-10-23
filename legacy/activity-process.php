<?php
require_once 'includes/config.php';

// -- Auth guard --
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: create-activity");
    exit();
}

$uid    = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action !== 'create') {
    header("Location: create-activity");
    exit();
}

/* ----------------------------------------------------------
   HELPERS
---------------------------------------------------------- */
function clean(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}

function safe_float($v): float {
    return (float) filter_var($v, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function safe_int($v): int {
    return (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT);
}

function redirect_error(string $msg): void {
    $_SESSION['activity_error'] = $msg;
    header("Location: create-activity");
    exit();
}

function redirect_success(string $msg, string $url = 'my-activities'): void {
    $_SESSION['activity_success'] = $msg;
    header("Location: $url");
    exit();
}


$is_submit = isset($_POST['submit_plan']);          
$status    = $is_submit ? 'submitted' : 'draft';


$fiscal_year   = safe_int($_POST['fiscal_year']   ?? date('Y'));
$category_id   = safe_int($_POST['category_id']   ?? 0);
$plan_title    = clean($_POST['plan_title']        ?? '');
$department_id = safe_int($_POST['department_id'] ?? 0);

// Validate header
if (empty($plan_title)) {
    redirect_error('Plan title is required.');
}
if ($category_id <= 0) {
    redirect_error('Please select a category.');
}
if ($department_id <= 0) {
    redirect_error('Department could not be determined. Please complete your employee profile.');
}
if ($fiscal_year < 2000 || $fiscal_year > 2100) {
    redirect_error('Invalid fiscal year.');
}


$activities_post = $_POST['activities'] ?? [];

if (empty($activities_post)) {
    redirect_error('Please add at least one activity before saving.');
}

$activities    = [];
$total_weight  = 0.0;

foreach ($activities_post as $raw_id => $act) {
    $description   = clean($act['description']    ?? '');
    $key_result    = clean($act['key_result']      ?? '');
    $weight        = safe_float($act['weight']     ?? 0);
    $unit          = clean($act['unit']            ?? '');
    $annual_target = safe_float($act['annual_target'] ?? 0);

    if (empty($description)) {
        redirect_error('All activities must have a description.');
    }
    if (empty($key_result)) {
        redirect_error("Activity \"{$description}\" must have a Key Result.");
    }
    if ($weight < 0 || $weight > 100) {
        redirect_error("Weight for \"{$description}\" must be between 0 and 100.");
    }

    $total_weight += $weight;

    $activities[] = [
        'post_id'       => (int)$raw_id,   // original JS row ID (for matching weekly data)
        'description'   => $description,
        'key_result'    => $key_result,
        'weight'        => $weight,
        'unit'          => $unit,
        'annual_target' => $annual_target,
    ];
}

// Soft weight check on submit
if ($is_submit && abs($total_weight - 100) > 1.0) {
    // Hard-block — remove the comment below if you want to enforce strictly
    // redirect_error("Activity weights must sum to 100%. Current total: {$total_weight}%");
}


$weekly_post   = $_POST['weekly'] ?? [];
$valid_quarters = ['q1', 'q2', 'q3', 'q4'];

// Build a clean, indexed weekly dataset
// $weekly_data[quarter][activity_post_id][week_num] = ['actual'=>float|null, 'comment'=>string]
$weekly_data = [];

foreach ($valid_quarters as $q) {
    $weekly_data[$q] = [];
    if (empty($weekly_post[$q]) || !is_array($weekly_post[$q])) continue;

    foreach ($weekly_post[$q] as $act_post_id => $weeks) {
        $act_post_id = (int)$act_post_id;
        if (!is_array($weeks)) continue;

        $weekly_data[$q][$act_post_id] = [];

        foreach ($weeks as $wkey => $wdata) {
            // wkey format: "w1", "w2", ... "w12"
            if (!preg_match('/^w(\d{1,2})$/', $wkey, $m)) continue;
            $week_num = (int)$m[1];
            if ($week_num < 1 || $week_num > 12) continue;

            $actual  = isset($wdata['actual']) && $wdata['actual'] !== ''
                       ? safe_float($wdata['actual'])
                       : null;
            $comment = clean($wdata['comment'] ?? '');

            // Only store rows that have at least actual or a comment
            if ($actual !== null || $comment !== '') {
                $weekly_data[$q][$act_post_id][$week_num] = [
                    'actual'  => $actual,
                    'comment' => $comment,
                ];
            }
        }
    }
}

/* ----------------------------------------------------------
   DB TRANSACTION
---------------------------------------------------------- */
$conn->begin_transaction();

try {

    /* -- 1. INSERT activity plan header -- */
    $stmt = $conn->prepare("
        INSERT INTO activity_plans
            (user_id, department_id, category_id, fiscal_year,
             plan_title, total_weight, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param(
        'iiiisds',
        $uid,
        $department_id,
        $category_id,
        $fiscal_year,
        $plan_title,
        $total_weight,
        $status
    );
    $stmt->execute();
    $plan_id = (int)$conn->insert_id;
    $stmt->close();

    /* -- 2. INSERT activities -- */
    $act_stmt = $conn->prepare("
        INSERT INTO plan_activities
            (plan_id, sort_order, description, key_result,
             weight, unit_of_measure, annual_target, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    /* -- 3. INSERT weekly entries -- */
    $week_stmt = $conn->prepare("
        INSERT INTO activity_weekly_entries
            (activity_id, plan_id, quarter, week_number,
             actual_value, comment, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($activities as $sort => $act) {
        $sort_order    = $sort + 1;
        $weight        = $act['weight'];
        $annual_target = $act['annual_target'];

        $act_stmt->bind_param(
            'iissdsd',
            $plan_id,
            $sort_order,
            $act['description'],
            $act['key_result'],
            $weight,
            $act['unit'],
            $annual_target
        );
        $act_stmt->execute();
        $activity_id = (int)$conn->insert_id;

        // Map post_id ? DB activity_id for weekly lookup
        $post_id = $act['post_id'];

        // Insert weekly entries for this activity across all quarters
        foreach ($valid_quarters as $q) {
            if (empty($weekly_data[$q][$post_id])) continue;

            foreach ($weekly_data[$q][$post_id] as $week_num => $entry) {
                $actual_val  = $entry['actual'];    // float or null
                $comment_val = $entry['comment'];

                $week_stmt->bind_param(
                    'iisisd',
                    $activity_id,
                    $plan_id,
                    $q,
                    $week_num,
                    $actual_val,
                    $comment_val
                );
                $week_stmt->execute();
            }
        }
    }

    $act_stmt->close();
    $week_stmt->close();

    /* -- 4. Commit -- */
    $conn->commit();

    /* -- 5. Post-save notifications -- */
    $mode_label = $is_submit ? 'submitted for review' : 'saved as draft';
    send_notification(
        $uid,
        "Your Activity Plan \"{$plan_title}\" has been {$mode_label}.",
        $is_submit ? 'success' : 'info'
    );

    // Notify supervisor if submitted
    if ($is_submit) {
        $sup_q = $conn->query("
            SELECT supervisor_id FROM employee_directory
            WHERE user_id = $uid AND supervisor_id IS NOT NULL
            LIMIT 1
        ");
        if ($sup_q && $sup_row = $sup_q->fetch_assoc()) {
            $sup_id = (int)$sup_row['supervisor_id'];
            if ($sup_id > 0) {
                // Get employee name for notification
                $name_q = $conn->query("
                    SELECT full_name
                    FROM users WHERE user_id = $uid LIMIT 1
                ");
                $emp_name = $name_q ? ($name_q->fetch_assoc()['full_name'] ?? 'An employee') : 'An employee';

                send_notification(
                    $sup_id,
                    "{$emp_name} has submitted an Activity Plan \"{$plan_title}\" for your review.",
                    'info',
                    "activity-review.php?id={$plan_id}"
                );
            }
        }
    }

    $msg = $is_submit
        ? "Activity Plan submitted successfully! Your supervisor has been notified."
        : "Activity Plan draft saved. You can continue editing from My Activities.";

    redirect_success($msg, "my-activities?saved={$plan_id}");

} catch (Exception $e) {
    $conn->rollback();

    error_log('[activity-process] DB error for user ' . $uid . ': ' . $e->getMessage());

    redirect_error('A database error occurred while saving your activity plan. Please try again or contact IT support.');
}