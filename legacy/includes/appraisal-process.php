<?php

require_once __DIR__ . '/config.php';

/* -----------------------------------------------
   AUTH GUARD
----------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login');
    exit();
}

$uid = (int) $_SESSION['user_id'];

/* -----------------------------------------------
   SUBMIT AN EXISTING DRAFT (from view-appraisal.php)
----------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_appraisal_existing') {
    $existingId = (int)($_POST['appraisal_id'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE performance_appraisals
        SET status = 'pending_review', submitted_at = NOW(), updated_at = NOW()
        WHERE appraisal_id = ? AND user_id = ? AND status = 'draft'
    ");

    $updated = false;

    if ($stmt) {
        $stmt->bind_param('ii', $existingId, $uid);
        $updated = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
    }

    if ($updated) {
        $sup = $conn->query("SELECT supervisor_id, full_name FROM performance_appraisals WHERE appraisal_id = " . $existingId);
        $supRow = $sup ? $sup->fetch_assoc() : null;

        if ($supRow && (int)$supRow['supervisor_id'] > 0) {
            notify_user(
                (int)$supRow['supervisor_id'],
                'Performance appraisal submitted',
                ($supRow['full_name'] ?: 'An employee') . ' has submitted a Performance Appraisal for your review.',
                'info',
                $existingId,
                'performance_appraisal'
            );
        }

        send_notification($uid, 'Your appraisal has been submitted for supervisor review.', 'success');
    } else {
        send_notification($uid, 'This appraisal could not be submitted (not found, not yours, or already submitted).', 'danger');
    }

    header('Location: ../view-appraisal?id=' . $existingId);
    exit();
}

/* -----------------------------------------------
   ONLY ACCEPT POST WITH CORRECT ACTION
----------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'create_appraisal') {
    send_notification($uid, 'Invalid request.', 'danger');
    header('Location: ../my-kpis');
    exit();
}

/* -----------------------------------------------
   HELPERS
----------------------------------------------- */
function clean(string $val): string {
    return trim(htmlspecialchars($val, ENT_QUOTES, 'UTF-8'));
}

function cleanFloat(?string $val): ?float {
    $v = trim((string) $val);
    return $v === '' ? null : (float) $v;
}

function cleanInt(?string $val): ?int {
    $v = trim((string) $val);
    return $v === '' ? null : (int) $v;
}

/* -----------------------------------------------
   COLLECT & VALIDATE HEADER FIELDS
----------------------------------------------- */
$full_name       = clean($_POST['full_name']        ?? '');
$department      = clean($_POST['department']       ?? '');
$job_title       = clean($_POST['job_title']        ?? '');
$supervisor_name = clean($_POST['supervisor_name']  ?? '');
// Supervisor comes from the employee directory, never from the form
// (a forged supervisor_id would grant another user access to this appraisal).
$supervisor_id   = null;
$supLookup = $conn->query("SELECT supervisor_id FROM employee_directory WHERE user_id = " . $uid . " LIMIT 1");
if ($supLookup && ($supLookupRow = $supLookup->fetch_assoc()) && (int)$supLookupRow['supervisor_id'] > 0) {
    $supervisor_id = (int)$supLookupRow['supervisor_id'];
}
// An employee can never be their own supervisor (they would approve their own appraisal).
if ($supervisor_id === $uid) {
    $supervisor_id = null;
}
$period_from     = clean($_POST['period_from']      ?? '');
$period_to       = clean($_POST['period_to']        ?? '');
$discussion_date = clean($_POST['discussion_date']  ?? '');

$errors = [];

if ($period_from === '')     $errors[] = 'Appraisal period start date is required.';
if ($period_to   === '')     $errors[] = 'Appraisal period end date is required.';
if ($discussion_date === '') $errors[] = 'Discussion date is required.';

if ($period_from && $period_to && $period_from > $period_to) {
    $errors[] = 'Appraisal period start date must be before the end date.';
}

/* -----------------------------------------------
   COLLECT & VALIDATE KRA / KPI DATA
----------------------------------------------- */
$kras_input = $_POST['kra'] ?? [];

if (empty($kras_input)) {
    $errors[] = 'At least one KRA is required.';
}

$kras = [];

foreach ($kras_input as $kra_key => $kra) {
    $kra_title    = clean($kra['title']          ?? '');
    $kra_weight   = cleanFloat($kra['weight']    ?? '');
    $kra_rating   = cleanFloat($kra['kra_rating']     ?? '');
    $kra_agreed   = null; // agreed rating is set during supervisor review
    $emp_comments = clean($kra['emp_comments']   ?? '');
    $mgr_comments = ''; // supervisor-only field

    if ($kra_title === '') {
        $errors[] = "KRA title is required (KRA #{$kra_key}).";
    }

    $kpis     = [];
    $kpi_list = $kra['kpi'] ?? [];

    if (empty($kpi_list)) {
        $errors[] = "KRA \"{$kra_title}\" must have at least one KPI.";
    }

    foreach ($kpi_list as $kpi_key => $kpi) {
        $kpi_title     = clean($kpi['title']             ?? '');
        $kpi_weight    = cleanFloat($kpi['weight']       ?? '');
        $emp_rating    = cleanInt($kpi['emp_rating']     ?? '');
        $mgr_rating    = null; // set by the supervisor, never by the employee
        $agreed_rating = null;

        if ($kpi_title === '') {
            $errors[] = "KPI title is required in KRA \"{$kra_title}\" (KPI #{$kpi_key}).";
        }

        foreach (['emp_rating' => $emp_rating, 'mgr_rating' => $mgr_rating, 'agreed_rating' => $agreed_rating] as $rname => $rval) {
            if ($rval !== null && ($rval < 1 || $rval > 5)) {
                $errors[] = "Invalid {$rname} value in KPI \"{$kpi_title}\".";
            }
        }

        $kpis[] = [
            'title'         => $kpi_title,
            'weight'        => $kpi_weight,
            'emp_rating'    => $emp_rating,
            'mgr_rating'    => $mgr_rating,
            'agreed_rating' => $agreed_rating,
        ];
    }

    $kras[] = [
        'title'         => $kra_title,
        'weight'        => $kra_weight,
        'kra_rating'    => $kra_rating,
        'agreed_rating' => $kra_agreed,
        'emp_comments'  => $emp_comments,
        'mgr_comments'  => $mgr_comments,
        'kpis'          => $kpis,
    ];
}

/* -----------------------------------------------
   BAIL ON VALIDATION ERRORS
----------------------------------------------- */
if (!empty($errors)) {
    foreach ($errors as $err) {
        send_notification($uid, $err, 'danger');
    }
    header('Location: ../performance-appraisal');
    exit();
}

/* -----------------------------------------------
   DETERMINE STATUS & SUBMITTED_AT
   Schema: status, submitted_at
----------------------------------------------- */
$is_submit    = isset($_POST['submit_appraisal']);
$status       = $is_submit ? 'pending_review' : 'draft';
$submitted_at = $is_submit ? date('Y-m-d H:i:s') : null;

/* -----------------------------------------------
   DATABASE TRANSACTION
----------------------------------------------- */
$conn->begin_transaction();

try {

    /* -- 1. Insert main appraisal record ----------------------------------
       Columns: user_id, supervisor_id, full_name, department, job_title,
                supervisor_name, period_from, period_to, discussion_date,
                status, submitted_at, created_at, updated_at
    -------------------------------------------------------------------- */
    $stmt = $conn->prepare("
        INSERT INTO performance_appraisals
            (user_id, supervisor_id, full_name, department, job_title,
             supervisor_name, period_from, period_to, discussion_date,
             status, submitted_at, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->bind_param(
        'iisssssssss',   // i  user_id
                         // i  supervisor_id
                         // s  full_name
                         // s  department
                         // s  job_title
                         // s  supervisor_name
                         // s  period_from
                         // s  period_to
                         // s  discussion_date
                         // s  status
                         // s  submitted_at (null-safe: PHP null ? SQL NULL)
        $uid,
        $supervisor_id,
        $full_name,
        $department,
        $job_title,
        $supervisor_name,
        $period_from,
        $period_to,
        $discussion_date,
        $status,
        $submitted_at
    );
    $stmt->execute();
    $appraisal_id = (int) $conn->insert_id;
    $stmt->close();

    /* -- 2. Insert KRAs ---------------------------------------------------
       Columns: appraisal_id, sort_order, title, weight,
                kra_rating, agreed_rating, emp_comments, mgr_comments, created_at
    -------------------------------------------------------------------- */
    $kra_stmt = $conn->prepare("
        INSERT INTO appraisal_kras
            (appraisal_id, sort_order, title, weight,
             kra_rating, agreed_rating, emp_comments, mgr_comments, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    /* -- 3. Insert KPIs ---------------------------------------------------
       Columns: kra_id, appraisal_id, sort_order, title, weight,
                emp_rating, mgr_rating, agreed_rating, created_at
    -------------------------------------------------------------------- */
    $kpi_stmt = $conn->prepare("
        INSERT INTO appraisal_kpis
            (kra_id, appraisal_id, sort_order, title, weight,
             emp_rating, mgr_rating, agreed_rating, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($kras as $kra_order => $kra) {

        $kra_sort   = $kra_order + 1;
        $kra_weight = $kra['weight'];
        $kra_rating = $kra['kra_rating'];
        $kra_agreed = $kra['agreed_rating'];

        /* bind: appraisal_id(i), sort_order(i), title(s), weight(d),
                 kra_rating(d), agreed_rating(i), emp_comments(s), mgr_comments(s) */
        $kra_stmt->bind_param(
            'iisddiss',
            $appraisal_id,
            $kra_sort,
            $kra['title'],
            $kra_weight,
            $kra_rating,
            $kra_agreed,
            $kra['emp_comments'],
            $kra['mgr_comments']
        );
        $kra_stmt->execute();
        $kra_id = (int) $conn->insert_id;

        foreach ($kra['kpis'] as $kpi_order => $kpi) {
            $kpi_sort   = $kpi_order + 1;
            $kpi_weight = $kpi['weight'];
            $emp_rating = $kpi['emp_rating'];
            $mgr_rating = $kpi['mgr_rating'];
            $agr_rating = $kpi['agreed_rating'];

            /* bind: kra_id(i), appraisal_id(i), sort_order(i), title(s), weight(d),
                     emp_rating(i), mgr_rating(i), agreed_rating(i) */
            $kpi_stmt->bind_param(
                'iiisdiii',
                $kra_id,
                $appraisal_id,
                $kpi_sort,
                $kpi['title'],
                $kpi_weight,
                $emp_rating,
                $mgr_rating,
                $agr_rating
            );
            $kpi_stmt->execute();
        }
    }

    $kra_stmt->close();
    $kpi_stmt->close();

    /* -- 4. Notify supervisor if submitted -- */
    if ($is_submit && $supervisor_id) {
        $msg = "{$full_name} has submitted a Performance Appraisal for your review.";
        notify_user((int)$supervisor_id, 'Performance appraisal submitted', $msg, 'info', $appraisal_id, 'performance_appraisal');
    }

    /* -- 5. Commit -- */
    $conn->commit();

    /* -- 6. Success feedback -- */
    if ($is_submit) {
        send_notification($uid,
            'Your appraisal has been submitted for supervisor review.',
            'success'
        );
    } else {
        send_notification($uid,
            'Appraisal draft saved successfully. You can continue editing it anytime.',
            'info'
        );
    }

    header('Location: ../my-appraisals?appraisal_id=' . $appraisal_id);
    exit();

} catch (Throwable $e) {

    $conn->rollback();

    error_log('[appraisal-process] ' . $e->getMessage() . ' | UID=' . $uid);

    send_notification($uid,
        'An unexpected error occurred while saving your appraisal. Please try again or contact support.',
        'danger'
    );

    header('Location: ../performance-appraisal');
    exit();
}