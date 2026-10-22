<?php
// includes/db.php and includes/functions.php do not exist; use the shared config.
require_once __DIR__ . '/includes/config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login"); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'create_bc') {
    header("Location:employee-competency"); exit();
}

$uid       = (int)$_SESSION['user_id'];
$is_submit = isset($_POST['submit_bc']);
$status    = $is_submit ? 'submitted' : 'draft';

/* -- Helpers -- */
function cl(string $v): string { return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8'); }
function sf($v): float { return (float) filter_var($v, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION); }
function si($v): int   { return (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT); }
function err(string $m): void { $_SESSION['bc_form_error'] = $m; header("Location:employee-competency"); exit(); }
function ok(string $m, string $url): void { $_SESSION['bc_form_success'] = $m; header("Location: $url"); exit(); }

/* -- Header fields -- */
$full_name       = cl($_POST['full_name']          ?? '');
$department      = cl($_POST['department']         ?? '');
$job_title       = cl($_POST['job_title']          ?? '');
// Never trust a client-supplied supervisor: take it from the employee directory.
$supervisor_id   = 0;
$supRes = $conn->query("SELECT supervisor_id FROM employee_directory WHERE user_id = " . (int)$_SESSION['user_id'] . " LIMIT 1");
if ($supRes && ($supRow = $supRes->fetch_assoc())) {
    $supervisor_id = (int)($supRow['supervisor_id'] ?? 0);
}
// An employee can never be their own supervisor (they would approve their own assessment).
if ($supervisor_id <= 0 || $supervisor_id === $uid) {
    $supervisor_id = null;
}
$period_from     = cl($_POST['period_from']        ?? '');
$period_to       = cl($_POST['period_to']          ?? '');
$discussion_date = cl($_POST['discussion_date']    ?? '');
$emp_overall     = cl($_POST['emp_overall_comment']?? '');
$mgr_overall     = ''; // supervisor fills later; employees may not set manager comments

if (empty($period_from) || empty($period_to)) err('Appraisal period is required.');
if ($period_from > $period_to) err('"From" date cannot be after "To" date.');
if ($is_submit && empty($discussion_date)) err('Discussion date is required before submitting.');

/* -- Indicator ratings -- */
$bc_post = $_POST['bc'] ?? [];
if (empty($bc_post)) err('Please rate at least one behavioral indicator before saving.');

$ratings = [];
foreach ($bc_post as $ind_id => $data) {
    $ind_id    = si($ind_id);
    $emp_r     = si($data['emp_rating'] ?? 0);
    $mgr_r     = 0;   // supervisor updates later; never accepted from the employee form
    $agr_r     = 0;

    // Bounds check
    foreach (['emp'=>$emp_r,'mgr'=>$mgr_r,'agr'=>$agr_r] as $k=>$v) {
        if ($v !== 0 && ($v < 1 || $v > 5)) err("Rating value out of range for indicator #{$ind_id}.");
    }

    if ($ind_id > 0) {
        $ratings[] = [
            'indicator_id' => $ind_id,
            'emp_rating'   => $emp_r ?: null,
            'mgr_rating'   => $mgr_r ?: null,
            'agr_rating'   => $agr_r ?: null,
        ];
    }
}

// Compute average employee rating
$emp_sum = array_sum(array_column(array_filter($ratings, fn($r) => $r['emp_rating']), 'emp_rating'));
$emp_cnt = count(array_filter($ratings, fn($r) => $r['emp_rating']));
$avg_emp = $emp_cnt > 0 ? round($emp_sum / $emp_cnt, 2) : null;

/* -- DB Transaction -- */
$conn->begin_transaction();
try {

    // 1. Insert BC submission header
    $stmt = $conn->prepare("
        INSERT INTO bc_submissions
            (user_id, supervisor_id, full_name, department, job_title,
             period_from, period_to, discussion_date,
             emp_overall_comment, mgr_overall_comment,
             avg_emp_rating, status, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
    ");
    $stmt->bind_param(
        'iissssssssds',
        $uid, $supervisor_id, $full_name, $department, $job_title,
        $period_from, $period_to, $discussion_date,
        $emp_overall, $mgr_overall,
        $avg_emp, $status
    );
    $stmt->execute();
    $submission_id = (int)$conn->insert_id;
    $stmt->close();

    // 2. Insert individual indicator ratings
    $rstmt = $conn->prepare("
        INSERT INTO bc_ratings
            (submission_id, indicator_id, emp_rating, mgr_rating, agr_rating, created_at)
        VALUES (?,?,?,?,?,NOW())
    ");
    foreach ($ratings as $r) {
        $rstmt->bind_param(
            'iiiii',
            $submission_id,
            $r['indicator_id'],
            $r['emp_rating'],
            $r['mgr_rating'],
            $r['agr_rating']
        );
        $rstmt->execute();
    }
    $rstmt->close();

    $conn->commit();

    // 3. Notifications
    $mode = $is_submit ? 'submitted for review' : 'saved as draft';
    send_notification($uid, "Your Behavioral Competency Assessment has been {$mode}.", $is_submit ? 'success' : 'info');

    if ($is_submit && $supervisor_id !== null) {
        $nq = $conn->query("SELECT full_name AS fn FROM users WHERE user_id=" . (int)$uid . " LIMIT 1");
        $emp_name = $nq ? ($nq->fetch_assoc()['fn'] ?? 'An employee') : 'An employee';
        notify_user(
            $supervisor_id,
            'Competency assessment submitted',
            "{$emp_name} submitted a Behavioral Competency Assessment for your review.",
            'info',
            $submission_id,
            'bc_submission'
        );
    }

    $msg = $is_submit
        ? 'Behavioral Competency Assessment submitted. Your supervisor has been notified.'
        : 'Draft saved. Continue editing from My Appraisals.';
    ok($msg, "my-appraisals?bc={$submission_id}");

} catch (Throwable $e) {
    $conn->rollback();
    error_log('[bc-process] Error uid=' . $uid . ': ' . $e->getMessage());
    err('A database error occurred. Please try again or contact IT support.');
}