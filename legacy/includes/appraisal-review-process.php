<?php
/**
 * Supervisor review of a performance appraisal (posted from view-appraisal.php).
 *
 *  - "save_review"       : saves manager/agreed ratings and comments; status stays 'pending_review'.
 *  - "approve_appraisal" : saves the same data, then sets status 'reviewed' + reviewed_at
 *                          and notifies the employee.
 *
 * Access mirrors view-appraisal.php: only the appraisal's supervisor sees the form, so only
 * the supervisor (and never the employee themselves) may post it.
 */
require_once __DIR__ . '/config.php';
check_login();

$uid         = (int)($_SESSION['user_id'] ?? 0);
$appraisalId = (int)($_POST['appraisal_id'] ?? 0);
$backUrl     = $appraisalId > 0 ? '../view-appraisal?id=' . $appraisalId : '../my-appraisals';

function appraisal_review_fail(string $message, string $url): void
{
    $_SESSION['notification'] = ['type' => 'error', 'message' => $message];
    header('Location: ' . $url);
    exit();
}

/** Returns an int 1..5, or null for empty/invalid input. */
function appraisal_review_rating($value): ?int
{
    $v = trim((string)$value);
    if ($v === '' || !ctype_digit($v)) {
        return null;
    }
    $n = (int)$v;
    return ($n >= 1 && $n <= 5) ? $n : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'review_appraisal' || $appraisalId <= 0) {
    appraisal_review_fail('Invalid request.', $backUrl);
}

$isApprove = isset($_POST['approve_appraisal']);

/* -- Load appraisal and check access -- */
$appraisal = null;
$stmt = $conn->prepare('SELECT appraisal_id, user_id, supervisor_id, full_name, status FROM performance_appraisals WHERE appraisal_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $appraisalId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        $appraisal = $res ? $res->fetch_assoc() : null;
    }
    $stmt->close();
}

if (!$appraisal) {
    appraisal_review_fail('Appraisal not found.', '../my-appraisals');
}

$employeeId   = (int)$appraisal['user_id'];
$supervisorId = (int)($appraisal['supervisor_id'] ?? 0);

if ($supervisorId <= 0 || $supervisorId !== $uid || $employeeId === $uid) {
    appraisal_review_fail('Only the assigned supervisor can review this appraisal.', $backUrl);
}

if ($appraisal['status'] !== 'pending_review') {
    appraisal_review_fail('This appraisal is not awaiting review.', $backUrl);
}

/* -- Collect input -- */
$kpiInput = is_array($_POST['kpi'] ?? null) ? $_POST['kpi'] : [];
$kraInput = is_array($_POST['kra'] ?? null) ? $_POST['kra'] : [];
$overall  = trim((string)($_POST['mgr_overall_comment'] ?? ''));

/* -- Save -- */
$conn->begin_transaction();
$ok = true;

$kpiStmt = $conn->prepare('UPDATE appraisal_kpis SET mgr_rating = ?, agreed_rating = ? WHERE kpi_id = ? AND appraisal_id = ?');
$kraStmt = $conn->prepare('UPDATE appraisal_kras SET mgr_comments = ?, agreed_rating = ? WHERE kra_id = ? AND appraisal_id = ?');
if (!$kpiStmt || !$kraStmt) {
    $ok = false;
}

if ($ok) {
    foreach ($kpiInput as $kpiId => $row) {
        $kpiId = (int)$kpiId;
        if ($kpiId <= 0 || !is_array($row)) {
            continue;
        }
        $mgr = appraisal_review_rating($row['mgr_rating'] ?? '');
        $agr = appraisal_review_rating($row['agreed_rating'] ?? '');
        $kpiStmt->bind_param('iiii', $mgr, $agr, $kpiId, $appraisalId);
        if (!$kpiStmt->execute()) {
            $ok = false;
            break;
        }
    }
}

if ($ok) {
    foreach ($kraInput as $kraId => $row) {
        $kraId = (int)$kraId;
        if ($kraId <= 0 || !is_array($row)) {
            continue;
        }
        $comment = trim((string)($row['mgr_comments'] ?? ''));
        $comment = $comment === '' ? null : $comment;
        $agr     = appraisal_review_rating($row['agreed_rating'] ?? '');
        $kraStmt->bind_param('siii', $comment, $agr, $kraId, $appraisalId);
        if (!$kraStmt->execute()) {
            $ok = false;
            break;
        }
    }
}

if ($kpiStmt) $kpiStmt->close();
if ($kraStmt) $kraStmt->close();

if ($ok) {
    $overallVal = $overall === '' ? null : $overall;
    if ($isApprove) {
        $sql = "UPDATE performance_appraisals
                SET mgr_overall_comment = ?, status = 'reviewed', reviewed_at = NOW(), updated_at = NOW()
                WHERE appraisal_id = ? AND supervisor_id = ? AND status = 'pending_review'";
    } else {
        $sql = "UPDATE performance_appraisals
                SET mgr_overall_comment = ?, updated_at = NOW()
                WHERE appraisal_id = ? AND supervisor_id = ? AND status = 'pending_review'";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $ok = false;
    } else {
        $stmt->bind_param('sii', $overallVal, $appraisalId, $uid);
        // affected_rows can be 0 for an unchanged draft save; only the approve must hit a row.
        $ok = $stmt->execute() && (!$isApprove || $stmt->affected_rows === 1);
        $stmt->close();
    }
}

if (!$ok) {
    $conn->rollback();
    error_log('[appraisal-review-process] save failed appraisal=' . $appraisalId . ' uid=' . $uid . ' err=' . $conn->error);
    appraisal_review_fail('The review could not be saved. Please try again.', $backUrl);
}

$conn->commit();

if ($isApprove) {
    log_action($uid, 'appraisal_reviewed', 'performance_appraisals', $appraisalId, 'Supervisor review completed');
    notify_user(
        $employeeId,
        'Performance appraisal reviewed',
        'Your supervisor has reviewed your performance appraisal #' . $appraisalId . '.',
        'success',
        $appraisalId,
        'performance_appraisal'
    );
    $_SESSION['notification'] = ['type' => 'success', 'message' => 'Review submitted. The employee has been notified.'];
} else {
    log_action($uid, 'appraisal_review_saved', 'performance_appraisals', $appraisalId, 'Supervisor review draft saved');
    $_SESSION['notification'] = ['type' => 'success', 'message' => 'Review draft saved.'];
}

header('Location: ' . $backUrl);
exit();
