<?php
ob_start();
require_once 'includes/config.php';

/* ---------------------------------------------------------------
   Validate job
--------------------------------------------------------------- */
$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
if (!$job_id) { header("Location: jobs-listings"); exit(); }

$job = $conn->query("SELECT * FROM jobs
    WHERE  job_id = $job_id
      AND  status = 'Published'
")->fetch_assoc();

if (!$job) { header("Location: jobs-listings"); exit(); }

/* Max applicants check */
$app_count = $conn->query("
      SELECT COUNT(*) AS c FROM job_applications
    WHERE  job_id = $job_id AND status != 'Draft'
")->fetch_assoc()['c'];
$is_full = $job['max_applicants'] && $app_count >= $job['max_applicants'];

/* Deadline check */
$is_expired = $job['deadline'] && strtotime($job['deadline']) < strtotime('today');

$page_title = 'Apply - ' . $job['job_title'];

/* ---------------------------------------------------------------
   Logged-in applicant draft resume
--------------------------------------------------------------- */
$user_id = $_SESSION['user_id'] ?? null;
$draft   = null;
if ($user_id) {
    $draft = $conn->query("
          SELECT * FROM job_applications
        WHERE  job_id  = $job_id
          AND  user_id = $user_id
          AND  status  = 'Draft'
        LIMIT 1
    ")->fetch_assoc();
} else {
    /* Guests: reuse the draft created earlier in this session for this job,
       but only if it is still an anonymous Draft for the same job. */
    $guest_draft_id = (int)($_SESSION['guest_job_drafts'][$job_id] ?? 0);
    if ($guest_draft_id > 0) {
        $gstmt = $conn->prepare("
            SELECT * FROM job_applications
            WHERE  application_id = ?
              AND  job_id = ?
              AND  user_id IS NULL
              AND  status = 'Draft'
            LIMIT 1
        ");
        if ($gstmt) {
            $gstmt->bind_param('ii', $guest_draft_id, $job_id);
            if ($gstmt->execute()) {
                $draft = $gstmt->get_result()->fetch_assoc() ?: null;
            }
            $gstmt->close();
        }
        if (!$draft) {
            unset($_SESSION['guest_job_drafts'][$job_id]);
        }
    }
}
$draft_id   = $draft['application_id'] ?? null;
$is_resuming = (bool)$draft;
$d = fn($col, $def = '') => htmlspecialchars($draft[$col] ?? $def);

/* Flash messages */
$success_msg = $_SESSION['job_app_success'] ?? '';
$error_msg   = $_SESSION['job_app_error']   ?? '';
unset($_SESSION['job_app_success'], $_SESSION['job_app_error']);

/* Confirmation after submit: only for the session that just submitted it */
$submitted_app_id = isset($_GET['submitted']) ? (int)$_GET['submitted'] : 0;
$show_submitted   = $submitted_app_id > 0
    && (int)($_SESSION['job_app_submitted'] ?? 0) === $submitted_app_id;

/* ---------------------------------------------------------------
   AJAX auto-save
--------------------------------------------------------------- */
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'autosave') {
    header('Content-Type: application/json');
    echo json_encode(saveJobDraft($conn, $user_id, $job_id, $_POST, $_FILES, $draft_id));
    exit();
}

/* ---------------------------------------------------------------
   Save draft handler
--------------------------------------------------------------- */
function saveJobDraft($conn, $user_id, $job_id, $post, $files, $existing_id) {
    $e = fn($v) => $conn->real_escape_string(trim($v ?? ''));

    $fields = [
        'applicant_name'   => $e($post['applicant_name']   ?? ''),
        'applicant_email'  => $e($post['applicant_email']  ?? ''),
        'applicant_phone'  => $e($post['applicant_phone']  ?? ''),
        'applicant_location'=> $e($post['applicant_location']?? ''),
        'cover_letter'     => $e($post['cover_letter']     ?? ''),
        'linkedin_url'     => $e($post['linkedin_url']     ?? ''),
        'portfolio_url'    => $e($post['portfolio_url']    ?? ''),
        'availability'     => $e($post['availability']     ?? ''),
        'notice_period'    => $e($post['notice_period']    ?? ''),
        'salary_expectation'=> $e($post['salary_expectation']?? ''),
        'how_did_you_hear' => $e($post['how_did_you_hear'] ?? ''),
        'additional_info'  => $e($post['additional_info']  ?? ''),
    ];

    /* File uploads */
    $uid     = $user_id ?? 'guest';
    $dir     = 'uploads/job_applications/' . $uid . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $file_defs = [
        'cv'           => ['pdf','doc','docx'],
        'cover_letter_file' => ['pdf','doc','docx'],
        'portfolio_file'    => ['pdf','jpg','jpeg','png','zip'],
    ];
    foreach ($file_defs as $field => $allowed) {
        $col = $field . '_path';
        if (!empty($files[$field]['name']) && $files[$field]['error'] === UPLOAD_ERR_OK) {
            $check = ims_validate_upload($files[$field], $allowed, 10 * 1024 * 1024);
            $ext = $check['extension'];
            if ($check['ok']) {
                $fname = $field . '-' . $uid . '-' . time() . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
                $dest  = $dir . $fname;
                if (move_uploaded_file($files[$field]['tmp_name'], $dest)) {
                    if ($existing_id) {
                        $old = $conn->query("SELECT $col FROM job_applications WHERE application_id=$existing_id")->fetch_assoc();
                        if (!empty($old[$col]) && file_exists($old[$col])) @unlink($old[$col]);
                    }
                    $fields[$col] = $conn->real_escape_string($dest);
                }
            }
        } elseif ($existing_id) {
            $row = $conn->query("SELECT $col FROM job_applications WHERE application_id=$existing_id")->fetch_assoc();
            if (!empty($row[$col])) $fields[$col] = $conn->real_escape_string($row[$col]);
        }
    }

    if ($existing_id) {
        $set = implode(', ', array_map(fn($k,$v) => "`$k`='$v'", array_keys($fields), $fields));
        $ok  = $conn->query("UPDATE job_applications SET $set, updated_at=NOW() WHERE application_id=$existing_id");
        return ['ok' => (bool)$ok, 'draft_id' => $existing_id, 'saved_at' => date('d M Y \a\t h:i A')];
    } else {
        $uid_val = $user_id ? intval($user_id) : 'NULL';
        $fields['job_id']  = $job_id;
        $fields['user_id'] = $user_id ? intval($user_id) : 'NULL';
        $fields['status']  = 'Draft';
        $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($fields)));
        $vals = implode(',', array_map(function($k,$v) use ($user_id) {
            if ($k === 'user_id') return $user_id ? intval($user_id) : 'NULL';
            if ($k === 'job_id')  return intval($v);
            return "'$v'";
        }, array_keys($fields), $fields));
        $ok  = $conn->query("INSERT INTO job_applications ($cols) VALUES ($vals)");
        $new_id = $ok ? (int)$conn->insert_id : 0;
        if ($ok && !$user_id && $new_id > 0) {
            /* Remember the guest's draft so later autosaves update it instead of inserting new rows */
            $_SESSION['guest_job_drafts'][(int)$job_id] = $new_id;
        }
        return ['ok' => (bool)$ok, 'draft_id' => $new_id, 'saved_at' => date('d M Y \a\t h:i A')];
    }
}

/* ---------------------------------------------------------------
   Manual save draft POST
--------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_draft') {
    $r = saveJobDraft($conn, $user_id, $job_id, $_POST, $_FILES, $draft_id);
    $_SESSION['job_app_success'] = 'Draft saved on ' . $r['saved_at'] . '. You can return any time to complete your application.';
    header("Location: apply-job?job_id=$job_id");
    exit();
}

/* ---------------------------------------------------------------
   Submit POST
--------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    /* Server-side gate: deadline / capacity */
    if ($is_expired || $is_full) {
        $_SESSION['job_app_error'] = $is_expired
            ? 'The application deadline for this job has passed.'
            : 'This job is no longer accepting applications (maximum number of applicants reached).';
        header("Location: apply-job?job_id=$job_id");
        exit();
    }

    /* Block a second submitted application for the same job
       (by account when logged in, by email address for guests) */
    $dup_email = trim((string)($_POST['applicant_email'] ?? ''));
    $dstmt = null;
    if ($user_id) {
        $dup_uid = (int)$user_id;
        $dstmt = $conn->prepare("SELECT application_id FROM job_applications WHERE job_id = ? AND user_id = ? AND status != 'Draft' LIMIT 1");
        if ($dstmt) $dstmt->bind_param('ii', $job_id, $dup_uid);
    } elseif ($dup_email !== '') {
        $dstmt = $conn->prepare("SELECT application_id FROM job_applications WHERE job_id = ? AND LOWER(applicant_email) = LOWER(?) AND status != 'Draft' LIMIT 1");
        if ($dstmt) $dstmt->bind_param('is', $job_id, $dup_email);
    }
    if ($user_id || $dup_email !== '') {
        if (!$dstmt || !$dstmt->execute()) {
            $_SESSION['job_app_error'] = 'We could not verify your application right now. Please try again.';
            header("Location: apply-job?job_id=$job_id");
            exit();
        }
        $already_applied = (bool)$dstmt->get_result()->fetch_assoc();
        $dstmt->close();
        if ($already_applied) {
            $_SESSION['job_app_error'] = 'You have already submitted an application for this job.';
            header("Location: apply-job?job_id=$job_id");
            exit();
        }
    }

    /* Save/update draft first */
    $r = saveJobDraft($conn, $user_id, $job_id, $_POST, $_FILES, $draft_id);
    $aid = (int)$r['draft_id'];
    if (empty($r['ok']) || $aid <= 0) {
        $_SESSION['job_app_error'] = 'We could not save your application. Please try again.';
        header("Location: apply-job?job_id=$job_id");
        exit();
    }

    /* Validate required */
    $errors = [];
    if (empty(trim($_POST['applicant_name']  ?? ''))) $errors[] = 'Full name is required.';
    if (empty(trim($_POST['applicant_email'] ?? ''))) $errors[] = 'Email address is required.';
    if (empty(trim($_POST['applicant_phone'] ?? ''))) $errors[] = 'Phone number is required.';
    if (empty(trim($_POST['cover_letter']    ?? ''))) $errors[] = 'Cover letter is required.';

    /* CV must exist (either uploaded now or previously saved) */
    /* Based on the stored path, so a rejected upload does not count */
    $has_cv = false;
    $cvstmt = $conn->prepare("SELECT cv_path FROM job_applications WHERE application_id = ? LIMIT 1");
    if ($cvstmt) {
        $cvstmt->bind_param('i', $aid);
        if ($cvstmt->execute()) {
            $cv_row = $cvstmt->get_result()->fetch_assoc();
            $has_cv = !empty($cv_row['cv_path']);
        }
        $cvstmt->close();
    }
    if (!$has_cv) $errors[] = 'Please upload your CV.';

    if (!empty($errors)) {
        $_SESSION['job_app_error'] = implode('<br>', $errors);
        header("Location: apply-job?job_id=$job_id");
        exit();
    }

    /* Promote to Submitted */
    $promoted = $conn->query("
        UPDATE job_applications
        SET status='Pending', submitted_at=NOW(), updated_at=NOW()
        WHERE application_id=$aid AND status='Draft'
    ");
    if (!$promoted || $conn->affected_rows < 1) {
        $_SESSION['job_app_error'] = 'We could not submit your application. Please try again.';
        header("Location: apply-job?job_id=$job_id");
        exit();
    }
    unset($_SESSION['guest_job_drafts'][$job_id]);

    /* Notify hiring managers */
    $notif_roles = "'Administrator','HR','Programs Lead'";
    $admins = $conn->query("SELECT user_id FROM users WHERE role IN ($notif_roles) LIMIT 10");
    while ($a = $admins->fetch_assoc()) {
        // notify_user() stores a per-user notification; send_notification()
        // would overwrite the applicant's own session flash.
        notify_user((int)$a['user_id'], 'New job application',
            'New job application for "' . $job['job_title'] . '" from ' .
            trim((string)($_POST['applicant_name'] ?? '')), 'info', (int)$aid, 'job_application');
    }
    if (function_exists('log_action') && $user_id) {
        log_action($user_id, 'Submit Job Application', 'job_applications', $aid,
            "Applied for job #{$job_id}: {$job['job_title']}");
    }

    $_SESSION['job_app_submitted'] = $aid;
    header("Location: apply-job?job_id=$job_id&submitted=" . (int)$aid);
    exit();
}

/* Salary range display helper */
$salary_display = '';
if ($job['salary_min'] || $job['salary_max']) {
    $cur = $job['salary_currency'] ?? 'UGX';
    $per = strtolower($job['salary_period'] ?? 'month');
    if ($job['salary_min'] && $job['salary_max'])
        $salary_display = $cur . ' ' . number_format($job['salary_min']) . ' - ' . number_format($job['salary_max']) . ' / ' . $per;
    elseif ($job['salary_min'])
        $salary_display = 'From ' . $cur . ' ' . number_format($job['salary_min']) . ' / ' . $per;
    else
        $salary_display = 'Up to ' . $cur . ' ' . number_format($job['salary_max']) . ' / ' . $per;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars(ims_brand_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(ims_brand()['favicon'], ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<style>
/* Links: no underlines (matches css/style.css); visible keyboard focus. */
a, a:hover, a:focus, a:active, a:visited { text-decoration: none; }
:where(a):focus-visible { outline: 2px solid var(--orange); outline-offset: 2px; }
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    /* Accent colours follow the configured site theme, with the original palette as fallback. */
    --orange:  var(--brand-600, #FF6B2B);
    --orange-d:var(--brand-700, #e85b1e);
    --amber:   var(--brand-secondary, var(--brand-500, #FF9800));
    --green:   #16a34a;
    --red:     #dc2626;
    --blue:    #2563eb;
    --text:    #1e293b;
    --sub:     #475569;
    --muted:   #94a3b8;
    --border:  #e2e8f0;
    --bg:      #f8fafc;
    --surface: #ffffff;
    --r:       12px;
    --sh:      0 1px 4px rgba(0,0,0,.07), 0 4px 20px rgba(0,0,0,.07);
}
body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--bg); color: var(--text);
    line-height: 1.6; min-height: 100vh;
}

/* -- Layout ----------------------------------- */
.aj-page  { max-width: 1060px; margin: 0 auto; padding: 28px 20px 80px; }
.aj-grid  { display: grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start; }
@media(max-width: 860px) { .aj-grid { grid-template-columns: 1fr; } }

/* -- Back link --------------------------------- */
.aj-back {
    display: inline-flex; align-items: center; gap: 8px;
    color: var(--orange); font-weight: 600; font-size: 14px;
    text-decoration: none; margin-bottom: 20px; transition: gap .2s;
}
.aj-back:hover { gap: 12px; }

/* -- Job hero banner --------------------------- */
.aj-hero {
    background: linear-gradient(130deg, var(--orange) 0%, var(--amber) 100%);
    color: #fff; border-radius: var(--r); padding: 28px 32px; margin-bottom: 24px;
    box-shadow: 0 6px 28px color-mix(in srgb, var(--orange) 30%, transparent);
}
.aj-hero h1 { font-size: 24px; font-weight: 800; margin-bottom: 10px; line-height: 1.2; }
.aj-hero-meta {
    display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; opacity: .93;
}
.aj-hero-meta span { display: flex; align-items: center; gap: 6px; }
.aj-hero-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.aj-hero-badge {
    background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.3);
    padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 700;
}

/* -- Alerts ------------------------------------ */
.aj-alert {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 13px 16px; border-radius: 9px; margin-bottom: 18px;
    font-size: 13.5px;
}
.aj-ok   { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
.aj-err  { background: #f8d7da; color: #721c24; border-left: 4px solid var(--red); }
.aj-warn { background: #fff3cd; color: #856404; border-left: 4px solid #f59e0b; }
.aj-info { background: #dbeafe; color: #1e40af; border-left: 4px solid var(--blue); }

/* Resume draft banner */
.aj-draft-banner {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px; padding: 14px 18px; margin-bottom: 18px;
    background: #fffbeb; border: 2px solid #fcd34d; border-radius: 9px;
}
.aj-draft-banner .left { display: flex; align-items: center; gap: 11px; }
.aj-draft-banner i.big { font-size: 22px; color: #d97706; }
.aj-draft-banner h4  { font-size: 14px; font-weight: 700; color: #92400e; }
.aj-draft-banner p   { font-size: 12.5px; color: #b45309; margin-top: 1px; }
.aj-draft-pill {
    background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;
    padding: 3px 12px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
}

/* -- Section cards ----------------------------- */
.aj-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); padding: 24px 26px; margin-bottom: 20px;
    box-shadow: var(--sh);
}
.aj-section-title {
    font-size: 16px; font-weight: 800; color: var(--text);
    display: flex; align-items: center; gap: 9px;
    margin-bottom: 20px; padding-bottom: 12px;
    border-bottom: 2px solid var(--bg);
}
.aj-section-title i { color: var(--orange); }

/* -- Form -------------------------------------- */
.aj-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width: 560px) { .aj-form-row { grid-template-columns: 1fr; } }
.aj-fg { margin-bottom: 16px; }
.aj-fg label {
    display: block; font-size: 12px; font-weight: 700; color: var(--sub);
    text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px;
}
.aj-fg label .req { color: var(--red); margin-left: 2px; }
.aj-fi, .aj-fs, .aj-fta {
    width: 100%; padding: 10px 13px; border: 1.5px solid var(--border);
    border-radius: 8px; font-size: 13.5px; color: var(--text);
    background: #fff; font-family: inherit; outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.aj-fi::placeholder, .aj-fta::placeholder { color: var(--muted); }
.aj-fi:focus, .aj-fs:focus, .aj-fta:focus {
    border-color: var(--orange); box-shadow: 0 0 0 3px color-mix(in srgb, var(--orange) 10%, transparent);
}
.aj-fta { resize: vertical; min-height: 110px; line-height: 1.6; }
.aj-fs  { cursor: pointer; }
.aj-hint { font-size: 11.5px; color: var(--muted); margin-top: 4px; }
.aj-char { font-size: 11.5px; color: var(--muted); text-align: right; margin-top: 3px; }
.aj-char.near { color: #d97706; }
.aj-char.over { color: var(--red); font-weight: 700; }

/* -- File upload ------------------------------- */
.aj-drop {
    border: 2px dashed var(--orange); border-radius: 9px;
    padding: 22px 16px; text-align: center; background: #fff7f4;
    cursor: pointer; transition: all .2s; position: relative;
}
.aj-drop:hover, .aj-drop.drag { background: var(--brand-50, #fff0ea); border-color: var(--orange-d); }
.aj-drop input[type="file"] {
    position: absolute; inset: 0; opacity: 0;
    cursor: pointer; width: 100%; height: 100%;
}
.aj-drop-icon { font-size: 28px; color: var(--orange); margin-bottom: 6px; }
.aj-drop-lbl  { font-size: 13px; font-weight: 600; color: var(--sub); }
.aj-drop-sub  { font-size: 12px; color: var(--muted); margin-top: 2px; }
.aj-file-ok {
    display: none; align-items: center; gap: 10px;
    padding: 10px 13px; background: #f0fdf4; border-radius: 8px;
    border: 1.5px solid #86efac; margin-top: 10px;
}
.aj-file-ok.show { display: flex; }
.aj-file-ok i { color: var(--green); font-size: 18px; flex-shrink: 0; }
.aj-file-ok .fn  { font-size: 13px; font-weight: 600; color: var(--text); }
.aj-file-ok .fsz { font-size: 11.5px; color: var(--muted); }
.aj-file-ok .clr {
    margin-left: auto; width: 24px; height: 24px; border-radius: 6px;
    background: #fee2e2; border: none; color: var(--red);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; cursor: pointer; flex-shrink: 0;
}
/* Existing file pill */
.aj-existing {
    display: flex; align-items: center; gap: 9px; margin-top: 9px;
    padding: 9px 12px; background: #eff6ff; border: 1.5px solid #bfdbfe;
    border-radius: 8px; font-size: 12.5px;
}
.aj-existing i  { color: var(--blue); }
.aj-existing a  { color: var(--blue); font-weight: 600; }
.aj-existing span { color: var(--muted); font-size: 11.5px; margin-left: auto; }

/* -- Completion bar ---------------------------- */
.aj-progress { margin-bottom: 22px; }
.aj-prog-row {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12.5px; font-weight: 600; color: var(--sub); margin-bottom: 6px;
}
.aj-prog-track { height: 8px; background: #e9eef5; border-radius: 4px; overflow: hidden; }
.aj-prog-fill  {
    height: 100%; border-radius: 4px;
    background: linear-gradient(90deg, var(--orange), var(--amber));
    transition: width .4s ease;
}

/* -- Sticky action bar ------------------------- */
.aj-action-bar {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
    background: #1e293b; padding: 13px 24px;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px;
    box-shadow: 0 -4px 24px rgba(0,0,0,.2);
}
.aj-save-status { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #94a3b8; }
.aj-dot { width: 8px; height: 8px; border-radius: 50%; background: #475569; flex-shrink: 0; }
.aj-dot.saving { background: #f59e0b; animation: aj-pulse .8s infinite; }
.aj-dot.saved  { background: #4ade80; }
.aj-dot.err    { background: #f87171; }
@keyframes aj-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
.aj-bar-btns { display: flex; gap: 10px; }

/* -- Buttons ----------------------------------- */
.aj-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 20px; border: none; border-radius: 8px;
    font-size: 13.5px; font-weight: 700; cursor: pointer;
    text-decoration: none; transition: all .18s; font-family: inherit;
    white-space: nowrap;
}
.aj-btn-primary {
    background: linear-gradient(135deg, var(--orange), var(--amber));
    color: #fff; box-shadow: 0 4px 14px color-mix(in srgb, var(--orange) 28%, transparent);
}
.aj-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px color-mix(in srgb, var(--orange) 40%, transparent); }
.aj-btn-ghost { background: rgba(255,255,255,.1); color: #fff; border: 1.5px solid rgba(255,255,255,.2); }
.aj-btn-ghost:hover { background: rgba(255,255,255,.18); }
.aj-btn:disabled { opacity: .55; cursor: not-allowed; transform: none !important; }

/* -- Right sidebar ----------------------------- */
.aj-sidebar > * { margin-bottom: 18px; }

/* Job summary card */
.aj-job-summary {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); overflow: hidden; box-shadow: var(--sh);
}
.aj-job-summary .top {
    background: linear-gradient(135deg, var(--orange), var(--amber));
    color: #fff; padding: 18px 20px;
}
.aj-job-summary .top h3 { font-size: 15px; font-weight: 800; margin-bottom: 4px; }
.aj-job-summary .top p  { font-size: 12.5px; opacity: .88; }
.aj-job-summary .body   { padding: 16px 20px; }
.aj-info-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 0; border-bottom: 1px solid var(--bg); font-size: 13px;
}
.aj-info-row:last-child { border-bottom: none; }
.aj-info-row i { width: 18px; text-align: center; color: var(--orange); font-size: 13px; }
.aj-info-lbl { color: var(--muted); font-size: 11.5px; display: block; }
.aj-info-val { font-weight: 600; color: var(--text); }

/* Tips card */
.aj-tips {
    background: #f0fdf4; border: 1px solid #bbf7d0;
    border-radius: var(--r); padding: 18px 20px;
}
.aj-tips h4 {
    font-size: 13.5px; font-weight: 800; color: #15803d;
    display: flex; align-items: center; gap: 7px; margin-bottom: 12px;
}
.aj-tips ul { list-style: none; display: flex; flex-direction: column; gap: 8px; }
.aj-tips li { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: #166534; }
.aj-tips li i { color: #16a34a; margin-top: 2px; flex-shrink: 0; }

/* Progress card (sidebar) */
.aj-prog-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); padding: 16px 18px; box-shadow: var(--sh);
}
.aj-prog-card h4 {
    font-size: 13px; font-weight: 700; color: var(--sub);
    margin-bottom: 12px; display: flex; align-items: center; gap: 7px;
}
.aj-prog-card h4 i { color: var(--orange); }
.aj-chk-list { list-style: none; display: flex; flex-direction: column; gap: 7px; }
.aj-chk-item {
    display: flex; align-items: center; gap: 9px;
    font-size: 13px; color: var(--sub);
}
.aj-chk-item .dot {
    width: 18px; height: 18px; border-radius: 50%; flex-shrink: 0;
    border: 2px solid var(--border); display: flex; align-items: center;
    justify-content: center; font-size: 9px; color: transparent;
    transition: all .2s;
}
.aj-chk-item.done .dot { background: var(--green); border-color: var(--green); color: #fff; }
.aj-chk-item.done span { color: var(--text); font-weight: 600; }

/* Deadline notice */
.aj-deadline-warn {
    background: #fff3cd; border: 1.5px solid #fcd34d; border-radius: 9px;
    padding: 13px 16px; font-size: 13px; color: #856404;
    display: flex; align-items: center; gap: 9px;
}

/* Closed / full state */
.aj-closed {
    text-align: center; padding: 60px 24px;
}
.aj-closed i { font-size: 52px; color: var(--muted); margin-bottom: 16px; display: block; }
.aj-closed h3 { font-size: 20px; font-weight: 800; color: var(--text); margin-bottom: 8px; }
.aj-closed p  { font-size: 14px; color: var(--sub); margin-bottom: 20px; }

/* Declaration */
.aj-declaration {
    background: #fffbeb; border: 2px solid #fcd34d;
    border-radius: 9px; padding: 16px 18px;
    display: flex; align-items: flex-start; gap: 12px;
}
.aj-declaration input {
    margin-top: 2px; width: 16px; height: 16px;
    accent-color: var(--orange); flex-shrink: 0; cursor: pointer;
}
.aj-declaration label {
    font-size: 13px; color: var(--sub); cursor: pointer; line-height: 1.6;
}
</style>
<?= ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])) ?>
<script src="js/accessibility.js"></script>
</head>
<body>
<div class="aj-page">

    <a href="jobs-listings" class="aj-back">
        <i class="fas fa-arrow-left"></i> Back to Jobs
    </a>

    <!-- -- Hero banner -------------------------- -->
    <div class="aj-hero">
        <h1><?= htmlspecialchars($job['job_title']) ?></h1>
        <div class="aj-hero-meta">
            <?php if ($job['department']): ?>
            <span><i class="fas fa-building"></i> <?= htmlspecialchars($job['department']) ?></span>
            <?php endif; ?>
            <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($job['location'] ?? 'Remote') ?></span>
            <span><i class="fas fa-briefcase"></i> <?= htmlspecialchars($job['job_type']) ?></span>
            <?php if ($job['deadline']): ?>
            <span><i class="fas fa-calendar-alt"></i> Deadline: <?= date('d M Y', strtotime($job['deadline'])) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($salary_display || $job['is_featured'] || $job['is_urgent']): ?>
        <div class="aj-hero-badges">
            <?php if ($salary_display): ?>
            <span class="aj-hero-badge"><i class="fas fa-money-bill-wave"></i> <?= htmlspecialchars($salary_display) ?></span>
            <?php endif; ?>
            <?php if ($job['is_featured']): ?>
            <span class="aj-hero-badge"><i class="fas fa-star"></i> Featured</span>
            <?php endif; ?>
            <?php if ($job['is_urgent']): ?>
            <span class="aj-hero-badge"><i class="fas fa-fire"></i> Urgent Hire</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php /* -- Submitted confirmation -- */ ?>
    <?php if ($show_submitted): ?>
    <div class="aj-card">
        <div class="aj-closed">
            <i class="fas fa-check-circle" style="color:var(--success, #16a34a);"></i>
            <h3>Application Submitted</h3>
            <p>
                Thank you for applying for <strong><?= htmlspecialchars($job['job_title']) ?></strong>.
                Your reference number is <strong>#<?= $submitted_app_id ?></strong>.
                We will contact you by email about the next steps.
            </p>
            <a href="jobs-listings" class="aj-btn aj-btn-primary">
                <i class="fas fa-search"></i> Browse Other Jobs
            </a>
        </div>
    </div>
    <?php include 'includes/footer.php'; exit(); endif; ?>

    <?php /* -- Closed / full states -- */ ?>
    <?php if ($is_expired || $is_full): ?>
    <div class="aj-card">
        <div class="aj-closed">
            <i class="fas fa-lock"></i>
            <h3><?= $is_expired ? 'Application Deadline Passed' : 'Applications Closed' ?></h3>
            <p>
                <?= $is_expired
                    ? 'The deadline for this position was ' . date('d M Y', strtotime($job['deadline'])) . '-'
                    : 'This position has reached its maximum number of applicants.' ?>
            </p>
            <a href="jobs-listings" class="aj-btn aj-btn-primary">
                <i class="fas fa-search"></i> Browse Other Jobs
            </a>
        </div>
    </div>
    <?php include 'includes/footer.php'; exit(); endif; ?>

    <?php /* -- Alerts -- */ ?>
    <?php if ($success_msg): ?>
    <div class="aj-alert aj-ok">
        <i class="fas fa-check-circle" style="flex-shrink:0;font-size:16px;margin-top:1px;"></i>
        <div><?= $success_msg ?></div>
    </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="aj-alert aj-err">
        <i class="fas fa-exclamation-circle" style="flex-shrink:0;font-size:16px;margin-top:1px;"></i>
        <div><?= $error_msg ?></div>
    </div>
    <?php endif; ?>

    <?php if ($job['deadline']): $dl_days = ceil((strtotime($job['deadline']) - time()) / 86400); ?>
    <?php if ($dl_days <= 7 && $dl_days > 0): ?>
    <div class="aj-deadline-warn">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Only <?= $dl_days ?> day<?= $dl_days > 1 ? 's' : '' ?> left to apply!</strong>
        Deadline: <?= date('d M Y', strtotime($job['deadline'])) ?>.
    </div>
    <?php endif; endif; ?>

    <?php if ($is_resuming && !$success_msg): ?>
    <div class="aj-draft-banner">
        <div class="left">
            <i class="fas fa-history big"></i>
            <div>
                <h4><i class="fas fa-pen"></i> Resuming Saved Draft</h4>
                <p>Last saved: <strong><?= date('d M Y \a\t h:i A', strtotime($draft['updated_at'] ?? $draft['created_at'])) ?></strong></p>
            </div>
        </div>
        <span class="aj-draft-pill">Draft</span>
    </div>
    <?php endif; ?>

    <!-- -- Completion progress ------------------- -->
    <div class="aj-progress">
        <div class="aj-prog-row">
            <span><i class="fas fa-tasks"></i> Form Completion</span>
            <span id="aj-pct">0%</span>
        </div>
        <div class="aj-prog-track">
            <div class="aj-prog-fill" id="aj-fill" style="width:0%"></div>
        </div>
    </div>

    <!-- -- Two-column layout -------------------- -->
    <div class="aj-grid">

    <!-- LEFT - Application Form -->
    <div>
    <form method="POST" enctype="multipart/form-data" id="ajForm"
          action="apply-job?job_id=<?= $job_id ?>">
        <?php if ($draft_id): ?>
        <input type="hidden" name="draft_id" value="<?= $draft_id ?>">
        <?php endif; ?>
        <input type="hidden" name="job_id"   value="<?= $job_id ?>">
        <input type="hidden" name="action"   id="aj-action" value="save_draft">

        <!-- Personal Details -->
        <div class="aj-card">
            <div class="aj-section-title"><i class="fas fa-user"></i> Personal Details</div>

            <div class="aj-form-row">
                <div class="aj-fg">
                    <label>Full Name <span class="req">*</span></label>
                    <input type="text" class="aj-fi" name="applicant_name"
                           id="aj-name" data-req="1" maxlength="120"
                           value="<?= $d('applicant_name') ?>"
                           placeholder="Your full legal name">
                </div>
                <div class="aj-fg">
                    <label>Email Address <span class="req">*</span></label>
                    <input type="email" class="aj-fi" name="applicant_email"
                           id="aj-email" data-req="1"
                           value="<?= $d('applicant_email') ?>"
                           placeholder="you@example.com">
                </div>
            </div>
            <div class="aj-form-row">
                <div class="aj-fg">
                    <label>Phone Number <span class="req">*</span></label>
                    <input type="tel" class="aj-fi" name="applicant_phone"
                           id="aj-phone" data-req="1"
                           value="<?= $d('applicant_phone') ?>"
                           placeholder="+256 700 000000">
                </div>
                <div class="aj-fg">
                    <label>Location / City</label>
                    <input type="text" class="aj-fi" name="applicant_location"
                           id="aj-loc"
                           value="<?= $d('applicant_location') ?>"
                           placeholder="e.g. City, Country">
                </div>
            </div>
            <div class="aj-form-row">
                <div class="aj-fg">
                    <label>LinkedIn Profile</label>
                    <input type="url" class="aj-fi" name="linkedin_url"
                           value="<?= $d('linkedin_url') ?>"
                           placeholder="https://linkedin.com/in/...">
                </div>
                <div class="aj-fg">
                    <label>Portfolio / Website</label>
                    <input type="url" class="aj-fi" name="portfolio_url"
                           value="<?= $d('portfolio_url') ?>"
                           placeholder="https://...">
                </div>
            </div>
        </div>

        <!-- Cover Letter -->
        <div class="aj-card">
            <div class="aj-section-title"><i class="fas fa-pen-alt"></i> Cover Letter</div>
            <div class="aj-fg">
                <label>Cover Letter <span class="req">*</span></label>
                <textarea class="aj-fta" name="cover_letter" id="aj-cl"
                          data-req="1" maxlength="2000"
                          style="min-height:160px;"
                          oninput="ajChar(this,'aj-cl-ctr')"
                          placeholder="Tell us why you're the ideal candidate for this role..."><?= $d('cover_letter') ?></textarea>
                <div class="aj-char" id="aj-cl-ctr">
                    <?= mb_strlen($draft['cover_letter'] ?? '') ?> / 2000
                </div>
                <div class="aj-hint">Personalise your letter - explain why you want this role and what makes you stand out.</div>
            </div>
        </div>

        <!-- Documents -->
        <div class="aj-card">
            <div class="aj-section-title"><i class="fas fa-file-upload"></i> Documents</div>

            <!-- CV -->
            <div class="aj-fg">
                <label>CV / Resume <span class="req">*</span></label>
                <div class="aj-drop" id="cv-drop">
                    <input type="file" name="cv" id="cv-input"
                           accept=".pdf,.doc,.docx"
                           onchange="ajFileChosen(this,'cv-preview','cv-drop')">
                    <div class="aj-drop-icon"><i class="fas fa-file-pdf"></i></div>
                    <div class="aj-drop-lbl" id="cv-lbl">Click or drag to upload your CV</div>
                    <div class="aj-drop-sub">PDF, DOC or DOCX - max 10 MB</div>
                </div>
                <div class="aj-file-ok" id="cv-preview">
                    <i class="fas fa-check-circle"></i>
                    <div><div class="fn" id="cv-fname">-</div><div class="fsz" id="cv-fsize">-</div></div>
                    <button type="button" class="clr" onclick="ajClearFile('cv-input','cv-preview','cv-drop','cv-lbl')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php if (!empty($draft['cv_path'])): ?>
                <div class="aj-existing">
                    <i class="fas fa-file-circle-check"></i>
                    <span>Current: <a href="<?= htmlspecialchars(ims_upload_url($draft['cv_path'])) ?>" target="_blank"><?= basename($draft['cv_path']) ?></a></span>
                    <span>Upload new to replace</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Cover letter file (optional) -->
            <div class="aj-fg">
                <label>Cover Letter File <small style="text-transform:none;font-weight:400;color:var(--muted);">(optional - or type above)</small></label>
                <div class="aj-drop" id="clf-drop">
                    <input type="file" name="cover_letter_file" id="clf-input"
                           accept=".pdf,.doc,.docx"
                           onchange="ajFileChosen(this,'clf-preview','clf-drop')">
                    <div class="aj-drop-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="aj-drop-lbl" id="clf-lbl">Upload formatted cover letter</div>
                    <div class="aj-drop-sub">PDF, DOC or DOCX - max 10 MB</div>
                </div>
                <div class="aj-file-ok" id="clf-preview">
                    <i class="fas fa-check-circle"></i>
                    <div><div class="fn" id="clf-fname">-</div><div class="fsz" id="clf-fsize">-</div></div>
                    <button type="button" class="clr" onclick="ajClearFile('clf-input','clf-preview','clf-drop','clf-lbl')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php if (!empty($draft['cover_letter_file_path'])): ?>
                <div class="aj-existing">
                    <i class="fas fa-file-circle-check"></i>
                    <span>Current: <a href="<?= htmlspecialchars(ims_upload_url($draft['cover_letter_file_path'])) ?>" target="_blank"><?= basename($draft['cover_letter_file_path']) ?></a></span>
                    <span>Upload new to replace</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Portfolio file (optional) -->
            <div class="aj-fg">
                <label>Portfolio / Work Sample <small style="text-transform:none;font-weight:400;color:var(--muted);">(optional)</small></label>
                <div class="aj-drop" id="pf-drop">
                    <input type="file" name="portfolio_file" id="pf-input"
                           accept=".pdf,.jpg,.jpeg,.png,.zip"
                           onchange="ajFileChosen(this,'pf-preview','pf-drop')">
                    <div class="aj-drop-icon"><i class="fas fa-images"></i></div>
                    <div class="aj-drop-lbl" id="pf-lbl">Upload portfolio or work samples</div>
                    <div class="aj-drop-sub">PDF, Image or ZIP - max 10 MB</div>
                </div>
                <div class="aj-file-ok" id="pf-preview">
                    <i class="fas fa-check-circle"></i>
                    <div><div class="fn" id="pf-fname">-</div><div class="fsz" id="pf-fsize">-</div></div>
                    <button type="button" class="clr" onclick="ajClearFile('pf-input','pf-preview','pf-drop','pf-lbl')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php if (!empty($draft['portfolio_file_path'])): ?>
                <div class="aj-existing">
                    <i class="fas fa-file-circle-check"></i>
                    <span>Current: <a href="<?= htmlspecialchars(ims_upload_url($draft['portfolio_file_path'])) ?>" target="_blank"><?= basename($draft['portfolio_file_path']) ?></a></span>
                    <span>Upload new to replace</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Additional Details -->
        <div class="aj-card">
            <div class="aj-section-title"><i class="fas fa-info-circle"></i> Additional Details</div>

            <div class="aj-form-row">
                <div class="aj-fg">
                    <label>Availability to Start</label>
                    < class="aj-fs" name="availability" id="aj-avail">
                        <option value=""> - </option>
                        <?php foreach (['Immediately','1 Week','2 Weeks','1 Month','2 Months','3+ Months'] as $av): ?>
                        <option value="<?= $av ?>" <?= ($draft['availability'] ?? '') === $av ? '-ed' : '' ?>><?= $av ?></option>
                        <?php endforeach; ?>
                    
                </div>
                <div class="aj-fg">
                    <label>Current Notice Period</label>
                    <class="aj-fs" name="notice_period">
                        <option value=""> - </option>
                        <?php foreach (['No notice required','1 Week','2 Weeks','1 Month','2 Months','3 Months'] as $np): ?>
                        <option value="<?= $np ?>" <?= ($draft['notice_period'] ?? '') === $np ? '-ed' : '' ?>><?= $np ?></option>
                        <?php endforeach; ?>
                    
                </div>
            </div>

            <div class="aj-form-row">
                <div class="aj-fg">
                    <label>Salary Expectation (<?= htmlspecialchars($job['salary_currency'] ?? 'UGX') ?> / month)</label>
                    <input type="number" class="aj-fi" name="salary_expectation"
                           min="0" placeholder="e.g. 2000000"
                           value="<?= $draft['salary_expectation'] ? intval($draft['salary_expectation']) : '' ?>">
                </div>
                <div class="aj-fg">
                    <label>How did you hear about this job?</label>
                    < class="aj-fs" name="how_did_you_hear">
                        <option value="">- </option>
                        <?php foreach (['Company Website','LinkedIn','Referral','Social Media','Job Board','Email Newsletter','Other'] as $src): ?>
                        <option value="<?= $src ?>" <?= ($draft['how_did_you_hear'] ?? '') === $src ? '-ed' : '' ?>><?= $src ?></option>
                        <?php endforeach; ?>
                    
                </div>
            </div>

            <div class="aj-fg">
                <label>Anything else you'd like us to know?</label>
                <textarea class="aj-fta" name="additional_info" maxlength="600"
                          oninput="ajChar(this,'aj-ai-ctr')"
                          placeholder="Optional additional notes..."><?= $d('additional_info') ?></textarea>
                <div class="aj-char" id="aj-ai-ctr">
                    <?= mb_strlen($draft['additional_info'] ?? '') ?> / 600
                </div>
            </div>
        </div>

        <!-- Declaration -->
        <div class="aj-card">
            <div class="aj-declaration">
                <input type="checkbox" id="aj-decl">
                <label for="aj-decl">
                    I confirm that all information provided in this application is accurate and complete.
                    I understand that providing false information may result in immediate disqualification
                    or termination of employment if discovered after hiring.
                </label>
            </div>
        </div>

    </form>
    </div><!-- /left -->

    <!-- RIGHT - Sidebar -->
    <div class="aj-sidebar">

        <!-- Job summary -->
        <div class="aj-job-summary">
            <div class="top">
                <h3><?= htmlspecialchars($job['job_title']) ?></h3>
                <p><?= htmlspecialchars($job['department'] ?? '') ?></p>
            </div>
            <div class="body">
                <div class="aj-info-row">
                    <i class="fas fa-briefcase"></i>
                    <div>
                        <span class="aj-info-lbl">Job Type</span>
                        <span class="aj-info-val"><?= htmlspecialchars($job['job_type']) ?></span>
                    </div>
                </div>
                <div class="aj-info-row">
                    <i class="fas fa-map-marker-alt"></i>
                    <div>
                        <span class="aj-info-lbl">Location</span>
                        <span class="aj-info-val"><?= htmlspecialchars($job['location'] ?? 'Remote') ?></span>
                    </div>
                </div>
                <?php if ($job['experience_level']): ?>
                <div class="aj-info-row">
                    <i class="fas fa-layer-group"></i>
                    <div>
                        <span class="aj-info-lbl">Experience</span>
                        <span class="aj-info-val"><?= htmlspecialchars($job['experience_level']) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($salary_display && ($job['show_salary'] ?? 1)): ?>
                <div class="aj-info-row">
                    <i class="fas fa-money-bill-wave"></i>
                    <div>
                        <span class="aj-info-lbl">Salary</span>
                        <span class="aj-info-val"><?= htmlspecialchars($salary_display) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($job['deadline']): ?>
                <div class="aj-info-row">
                    <i class="fas fa-calendar-alt"></i>
                    <div>
                        <span class="aj-info-lbl">Application Deadline</span>
                        <span class="aj-info-val"><?= date('d M Y', strtotime($job['deadline'])) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($job['max_applicants']): ?>
                <div class="aj-info-row">
                    <i class="fas fa-users"></i>
                    <div>
                        <span class="aj-info-lbl">Slots Available</span>
                        <span class="aj-info-val"><?= $job['max_applicants'] - $app_count ?> remaining</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Checklist -->
        <div class="aj-prog-card">
            <h4><i class="fas fa-tasks"></i> Application Checklist</h4>
            <ul class="aj-chk-list">
                <li class="aj-chk-item" id="chk-name">
                    <div class="dot"><i class="fas fa-check"></i></div>
                    <span>Full name entered</span>
                </li>
                <li class="aj-chk-item" id="chk-email">
                    <div class="dot"><i class="fas fa-check"></i></div>
                    <span>Email address entered</span>
                </li>
                <li class="aj-chk-item" id="chk-phone">
                    <div class="dot"><i class="fas fa-check"></i></div>
                    <span>Phone number entered</span>
                </li>
                <li class="aj-chk-item" id="chk-cl">
                    <div class="dot"><i class="fas fa-check"></i></div>
                    <span>Cover letter written</span>
                </li>
                <li class="aj-chk-item" id="chk-cv">
                    <div class="dot"><i class="fas fa-check"></i></div>
                    <span>CV uploaded</span>
                </li>
                <li class="aj-chk-item" id="chk-decl">
                    <div class="dot"><i class="fas fa-check"></i></div>
                    <span>Declaration accepted</span>
                </li>
            </ul>
        </div>

        <!-- Tips -->
        <div class="aj-tips">
            <h4><i class="fas fa-lightbulb"></i> Application Tips</h4>
            <ul>
                <li><i class="fas fa-check"></i> Tailor your cover letter to this specific role.</li>
                <li><i class="fas fa-check"></i> Ensure your CV is up to date and clearly formatted.</li>
                <li><i class="fas fa-check"></i> Use Save Draft to come back and finish later.</li>
                <li><i class="fas fa-check"></i> Double-check your contact details before submitting.</li>
                <li><i class="fas fa-check"></i> Include measurable achievements in your CV.</li>
            </ul>
        </div>

    </div><!-- /sidebar -->
    </div><!-- /grid -->
</div><!-- /page -->

<!-- -- Sticky action bar ----------------------- -->
<div class="aj-action-bar">
    <div class="aj-save-status">
        <span class="aj-dot" id="aj-dot"></span>
        <span id="aj-status-txt">Changes auto-saved every 60 s.</span>
    </div>
    <div class="aj-bar-btns">
        <button type="button" class="aj-btn aj-btn-ghost" id="aj-save-btn" onclick="ajManualSave()">
            <i class="fas fa-save"></i> Save Draft
        </button>
        <button type="button" class="aj-btn aj-btn-primary" id="aj-submit-btn" onclick="ajSubmit()">
            <i class="fas fa-paper-plane"></i> Submit Application
        </button>
    </div>
</div>

<script>
/* -- Required fields ---------------------------- */
const AJ_REQ = ['aj-name','aj-email','aj-phone','aj-cl'];
let ajDirty  = false;
let ajTimer  = null;
let ajCvUploaded = <?= (!empty($draft['cv_path']) ? 'true' : 'false') ?>;

/* -- Completion & checklist --------------------- */
function ajUpdateCompletion() {
    const checks = {
        'chk-name':  !!document.getElementById('aj-name')?.value.trim(),
        'chk-email': !!document.getElementById('aj-email')?.value.trim(),
        'chk-phone': !!document.getElementById('aj-phone')?.value.trim(),
        'chk-cl':    (document.getElementById('aj-cl')?.value.trim().length || 0) > 20,
        'chk-cv':    ajCvUploaded || !!document.getElementById('cv-input')?.files.length,
        'chk-decl':  document.getElementById('aj-decl')?.checked,
    };
    let done = 0;
    Object.entries(checks).forEach(([id, ok]) => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('done', ok);
        if (ok) done++;
    });
    const pct = Math.round(done / Object.keys(checks).length * 100);
    document.getElementById('aj-fill').style.width = pct + '%';
    document.getElementById('aj-pct').textContent  = pct + '%';
}

/* -- Character counter -------------------------- */
function ajChar(el, ctrId) {
    const max = parseInt(el.getAttribute('maxlength') || 9999);
    const len = el.value.length;
    const ctr = document.getElementById(ctrId);
    if (!ctr) return;
    ctr.textContent = len + ' / ' + max;
    ctr.className   = 'aj-char' + (len >= max ? ' over' : (len >= max * .85 ? ' near' : ''));
    ajDirty = true;
    ajUpdateCompletion();
}

/* -- File chosen -------------------------------- */
function ajFileChosen(input, previewId, dropId) {
    const file = input.files[0];
    if (!file) return;
    const ext  = file.name.split('.').pop().toLowerCase();
    const ok   = ['pdf','doc','docx','jpg','jpeg','png','zip'].includes(ext);
    const size = file.size <= 10 * 1024 * 1024;
    if (!ok)   { alert('Invalid file type.'); input.value = ''; return; }
    if (!size) { alert('File too large (max 10 MB).'); input.value = ''; return; }

    const pv   = document.getElementById(previewId);
    const fnEl = document.getElementById(previewId.replace('-preview','') + '-fname') || pv.query-or('.fn');
    const fsEl = document.getElementById(previewId.replace('-preview','') + '-fsize') || pv.query-or('.fsz');
    const lbl  = document.getElementById(dropId.replace('-drop','') + '-lbl');

    if (fnEl) fnEl.textContent = file.name;
    if (fsEl) fsEl.textContent = (file.size / 1024).toFixed(1) + ' KB';
    if (pv)   pv.classList.add('show');
    if (lbl)  { lbl.textContent = '' + file.name; lbl.style.color = '#16a34a'; }

    if (input.id === 'cv-input') ajCvUploaded = true;
    ajDirty = true;
    ajUpdateCompletion();
}

function ajClearFile(inputId, previewId, dropId, lblId) {
    const input = document.getElementById(inputId);
    if (input) input.value = '';
    const pv = document.getElementById(previewId);
    if (pv) pv.classList.remove('show');
    const lbl = document.getElementById(lblId);
    if (lbl) { lbl.style.color = ''; }
    if (inputId === 'cv-input') { ajCvUploaded = false; }
    ajUpdateCompletion();
}

/* -- Drag-and-drop ------------------------------ */
document.query-orAll('.aj-drop').forEach(dz => {
    ['dragenter','dragover'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.add('drag'); }));
    ['dragleave','drop'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.remove('drag'); }));
    dz.addEventListener('drop', ev => {
        const inp = dz.query-or('input[type="file"]');
        if (inp && ev.dataTransfer.files.length) {
            inp.files = ev.dataTransfer.files;
            ajFileChosen(inp, inp.id.replace('-input','-preview'), dz.id);
        }
    });
});

/* -- Auto-save status --------------------------- */
function ajSetStatus(state, msg) {
    const dot = document.getElementById('aj-dot');
    const txt = document.getElementById('aj-status-txt');
    dot.className = 'aj-dot ' + state;
    txt.textContent = msg;
}

/* -- AJAX auto-save ----------------------------- */
async function ajPerformSave() {
    ajSetStatus('saving','Saving draft...');
    const fd = new FormData(document.getElementById('ajForm'));
    fd.set('action','autosave');
    try {
        const r = await fetch('apply-job?job_id=<?= $job_id ?>', {
            method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd
        });
        const j = await r.json();
        if (j.ok) {
            ajDirty = false;
            ajSetStatus('saved','Draft saved at ' + j.saved_at);
        } else {
            ajSetStatus('err','Auto-save failed.');
        }
    } catch { ajSetStatus('err','No connection.'); }
}

function ajManualSave() {
    document.getElementById('aj-action').value = 'save_draft';
    document.getElementById('ajForm').submit();
}

/* Auto-save every 60 s */
ajTimer = setInterval(() => { if (ajDirty) ajPerformSave(); }, 60000);

/* -- Submit ------------------------------------- */
function ajSubmit() {
    if (!document.getElementById('aj-decl').checked) {
        alert('Please read and accept the declaration before submitting.');
        document.getElementById('aj-decl').scrollIntoView({behavior:'smooth'});
        return;
    }
    const missing = [];
    if (!document.getElementById('aj-name')?.value.trim())  missing.push('Full Name');
    if (!document.getElementById('aj-email')?.value.trim()) missing.push('Email Address');
    if (!document.getElementById('aj-phone')?.value.trim()) missing.push('Phone Number');
    if (!document.getElementById('aj-cl')?.value.trim())    missing.push('Cover Letter');
    if (!ajCvUploaded && !document.getElementById('cv-input')?.files.length) missing.push('CV Upload');

    if (missing.length) {
        alert('Please complete the following before submitting:\n\n ' + missing.join('\n '));
        return;
    }
    const btn = document.getElementById('aj-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    document.getElementById('aj-action').value = 'submit';
    document.getElementById('ajForm').submit();
}

/* -- Dirty tracking ----------------------------- */
document.getElementById('ajForm').addEventListener('input',  () => { ajDirty = true; ajUpdateCompletion(); });
document.getElementById('ajForm').addEventListener('change', () => { ajDirty = true; ajUpdateCompletion(); });
document.getElementById('aj-decl').addEventListener('change', ajUpdateCompletion);

/* -- Unsaved changes guard ---------------------- */
window.addEventListener('beforeunload', e => { if (ajDirty) { e.preventDefault(); e.returnValue = ''; } });

/* -- Init --------------------------------------- */
ajUpdateCompletion();
</script>
</body>
</html>