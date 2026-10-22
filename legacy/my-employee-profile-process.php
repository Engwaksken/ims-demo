<?php
require_once __DIR__ . '/includes/config.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: my-profile");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* ---------------------------------------------------------------
   Only process if a form button was actually clicked
--------------------------------------------------------------- */
if (!isset($_POST['save_draft']) && !isset($_POST['submit_profile'])) {
    header("Location: my-profile");
    exit();
}

$is_submit = isset($_POST['submit_profile']);

/* ---------------------------------------------------------------
   Fetch existing record (if any) � no edit restriction on status
--------------------------------------------------------------- */
$check  = $conn->query("SELECT * FROM employee_directory WHERE user_id = " . (int)$user_id);
$existing = $check ? $check->fetch_assoc() : null;

/* ---------------------------------------------------------------
   Sanitise all form inputs
--------------------------------------------------------------- */
$full_name               = sanitize_input($_POST['full_name']               ?? '');
$contract_type           = sanitize_input($_POST['contract_type']           ?? '');
$supervisor_id           = !empty($_POST['supervisor_id'])  ? intval($_POST['supervisor_id'])  : null;
$start_date              = sanitize_input($_POST['start_date']              ?? '');
$contract_end_date       = !empty($_POST['contract_end_date'])   ? sanitize_input($_POST['contract_end_date'])   : null;
$job_title               = sanitize_input($_POST['job_title']               ?? '');
$gender                  = sanitize_input($_POST['gender']                  ?? '');
$department_id           = intval($_POST['department_id']                   ?? 0);
$date_of_birth           = !empty($_POST['date_of_birth'])       ? sanitize_input($_POST['date_of_birth'])       : null;
$nationality             = sanitize_input($_POST['nationality']             ?? '');

$personal_email          = sanitize_input($_POST['personal_email']          ?? '');
$company_email           = sanitize_input($_POST['company_email']           ?? '');
$phone                   = sanitize_input($_POST['phone']                   ?? '');
$location                = sanitize_input($_POST['location']                ?? '');
$residence_address       = sanitize_input($_POST['residence_address']       ?? '');

$next_of_kin_name         = sanitize_input($_POST['next_of_kin_name']        ?? '');
$next_of_kin_relationship = sanitize_input($_POST['next_of_kin_relationship'] ?? '');
$has_dependants           = sanitize_input($_POST['has_dependants']          ?? 'No');
$number_of_dependants     = $has_dependants === 'Yes' ? intval($_POST['number_of_dependants'] ?? 0) : 0;

$ura_tin_number          = sanitize_input($_POST['ura_tin_number']          ?? '');
$nssf_number             = sanitize_input($_POST['nssf_number']             ?? '');
$bank_details            = sanitize_input($_POST['bank_details']            ?? '');
$nin                     = sanitize_input($_POST['nin']                     ?? '');
$form_completion_date    = sanitize_input($_POST['form_completion_date']    ?? date('Y-m-d'));

/* ---------------------------------------------------------------
   Validate supervisor: never yourself, and must be an existing active user
   (the supervisor approves this employee's appraisals).
--------------------------------------------------------------- */
if ($supervisor_id !== null) {
    if ($supervisor_id <= 0 || $supervisor_id === $user_id) {
        send_notification($user_id, 'You cannot select yourself as your own supervisor.', 'danger');
        header("Location: my-profile");
        exit();
    }
    $supOk = false;
    $supStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1");
    if ($supStmt) {
        $supStmt->bind_param('i', $supervisor_id);
        if ($supStmt->execute()) {
            $supRes = $supStmt->get_result();
            $supOk  = $supRes && $supRes->fetch_assoc() !== null;
        }
        $supStmt->close();
    }
    if (!$supOk) {
        send_notification($user_id, 'The selected supervisor is not a valid active user.', 'danger');
        header("Location: my-profile");
        exit();
    }
}

/* ---------------------------------------------------------------
   Determine new status
   � Approved profiles that are re-submitted go back to Submitted
   � Save Draft always forces Draft
--------------------------------------------------------------- */
if ($is_submit) {
    $status = 'Submitted';
} else {
    /* Keep current status for drafts that were previously approved,
       so a plain "Save Draft" on an approved profile doesn't
       accidentally reset it � use 'Draft' only when it was already
       Draft / Rejected / new. */
    $prev_status = $existing['status'] ?? '';
    $status = in_array($prev_status, ['Approved', 'Submitted']) ? $prev_status : 'Draft';
}

/* ---------------------------------------------------------------
   Validate required fields when submitting
--------------------------------------------------------------- */
if ($is_submit) {
    $errors = [];
    if (empty($full_name))     $errors[] = 'Full name is required';
    if (empty($contract_type)) $errors[] = 'Contract type is required';
    if (empty($supervisor_id)) $errors[] = 'Supervisor is required';
    if (empty($start_date))    $errors[] = 'Start date is required';
    if (empty($job_title))     $errors[] = 'Job title is required';
    if (empty($gender))        $errors[] = 'Gender is required';
    if (empty($department_id)) $errors[] = 'Department is required';
    if (empty($company_email)) $errors[] = 'Company email is required';
    if (empty($phone))         $errors[] = 'Phone is required';

    if (!empty($errors)) {
        send_notification($user_id, implode(', ', $errors), 'danger');
        header("Location: my-profile");
        exit();
    }
}

/* ---------------------------------------------------------------
   Prepare upload directory
--------------------------------------------------------------- */
$upload_dir = 'uploads/employees/' . $user_id . '/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

/* ---------------------------------------------------------------
   Handle file uploads
   � New file  ? validate, move, store new path
   � No upload ? preserve the previously stored path
--------------------------------------------------------------- */
$file_fields = [
    'academic_documents' => 'academic_documents_path',
    'national_id'        => 'national_id_path',
    'cv'                 => 'cv_path',
    'cover_letter'       => 'cover_letter_path',
    'good_conduct_cert'  => 'good_conduct_cert_path',
    'signed_policies'    => 'signed_policies_path',
    'residence_map'      => 'residence_map_path',
    'signed_contract'    => 'signed_contract_path',
];

$allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
$max_size    = 10 * 1024 * 1024; // 10 MB

$uploaded_files = [];

foreach ($file_fields as $field => $db_field) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $file     = $_FILES[$field];
        $check    = ims_validate_upload($file, $allowed_ext, $max_size);

        if (!$check['ok']) {
            send_notification($user_id, "\"$field\": " . $check['error'] . " Allowed: PDF, DOC, DOCX, JPG, PNG (max 10 MB).", 'danger');
            continue;
        }

        $new_filename = $field . '_' . $user_id . '_' . $check['safe_name'];
        $dest         = $upload_dir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            /* Delete old file to avoid orphans */
            if ($existing && !empty($existing[$db_field]) && file_exists($existing[$db_field])) {
                @unlink($existing[$db_field]);
            }
            $uploaded_files[$db_field] = $dest;
        } else {
            send_notification($user_id, "Failed to save file for \"$field\".", 'danger');
        }

    } elseif ($existing && !empty($existing[$db_field])) {
        /* Keep the existing path */
        $uploaded_files[$db_field] = $existing[$db_field];
    }
}

/* ---------------------------------------------------------------
   Handle signature (base-64 PNG from canvas)
--------------------------------------------------------------- */
if (!empty($_POST['signature_data'])) {
    $raw      = str_replace(['data:image/png;base64,', ' '], ['', '+'], (string)$_POST['signature_data']);
    $decoded  = base64_decode($raw, true);

    // Only accept a real PNG image of reasonable size (no arbitrary file write).
    if (
        $decoded !== false
        && strlen($decoded) <= 2 * 1024 * 1024
        && substr($decoded, 0, 8) === "\x89PNG\r\n\x1a\n"
        && @getimagesizefromstring($decoded) !== false
    ) {
        /* Remove old signature file */
        if ($existing && !empty($existing['signature_path']) && file_exists($existing['signature_path'])) {
            @unlink($existing['signature_path']);
        }
        $sig_file = $upload_dir . 'signature_' . $user_id . '_' . time() . '.png';
        file_put_contents($sig_file, $decoded);
        $uploaded_files['signature_path'] = $sig_file;
    }
} elseif ($existing && !empty($existing['signature_path'])) {
    $uploaded_files['signature_path'] = $existing['signature_path'];
}

/* ---------------------------------------------------------------
   Re-approval of changed Approved profiles (business rule)
   � Any change to an Approved profile - "Submit" or "Save Draft" -
     sends it back to HR: status becomes Submitted, which is the
     status the HR approve/reject actions in employees-list.php act on.
   � Saves with no field differences keep the profile Approved.
   � Each changed field is recorded in employee_directory_history.
--------------------------------------------------------------- */
$profile_changes = [];

if ($existing) {
    $new_values = [
        'full_name' => $full_name, 'contract_type' => $contract_type, 'supervisor_id' => $supervisor_id,
        'start_date' => $start_date, 'contract_end_date' => $contract_end_date, 'job_title' => $job_title,
        'gender' => $gender, 'department_id' => $department_id, 'date_of_birth' => $date_of_birth,
        'nationality' => $nationality, 'personal_email' => $personal_email, 'company_email' => $company_email,
        'phone' => $phone, 'location' => $location, 'residence_address' => $residence_address,
        'next_of_kin_name' => $next_of_kin_name, 'next_of_kin_relationship' => $next_of_kin_relationship,
        'has_dependants' => $has_dependants, 'number_of_dependants' => $number_of_dependants,
        'ura_tin_number' => $ura_tin_number, 'nssf_number' => $nssf_number, 'bank_details' => $bank_details,
        'nin' => $nin, 'form_completion_date' => $form_completion_date,
    ] + $uploaded_files;

    $normalise = static function (mixed $value): string {
        $value = trim((string)($value ?? ''));
        // Treat empty ids / zero ids and empty dates as "no value".
        return in_array($value, ['0', '0000-00-00'], true) ? '' : $value;
    };

    foreach ($new_values as $column => $new_value) {
        if (!array_key_exists($column, $existing)) {
            continue;
        }

        $old_value = $existing[$column];

        // form_completion_date defaults to "today" on every save; not a profile change by itself.
        if ($column === 'form_completion_date') {
            continue;
        }

        if ($normalise($old_value) !== $normalise($new_value)) {
            $profile_changes[$column] = [$old_value, $new_value];
        }
    }
}

$prev_status       = (string)($existing['status'] ?? '');
$needs_reapproval  = false;

if ($prev_status === 'Approved') {
    if ($profile_changes) {
        $status = 'Submitted';
        $needs_reapproval = true;
    } else {
        $status = 'Approved'; // unchanged save: nothing for HR to review
    }
}

$mark_submitted = $status === 'Submitted' && ($is_submit || $needs_reapproval);

/* ---------------------------------------------------------------
   Build and execute SQL
--------------------------------------------------------------- */
if ($existing) {

    /* -- UPDATE ----------------------------------------------- */
    $stmt = $conn->prepare("
        UPDATE employee_directory SET
            full_name               = ?,
            contract_type           = ?,
            supervisor_id           = ?,
            start_date              = ?,
            contract_end_date       = ?,
            job_title               = ?,
            gender                  = ?,
            department_id           = ?,
            date_of_birth           = ?,
            nationality             = ?,
            personal_email          = ?,
            company_email           = ?,
            phone                   = ?,
            location                = ?,
            residence_address       = ?,
            next_of_kin_name        = ?,
            next_of_kin_relationship= ?,
            has_dependants          = ?,
            number_of_dependants    = ?,
            ura_tin_number          = ?,
            nssf_number             = ?,
            bank_details            = ?,
            nin                     = ?,
            form_completion_date    = ?,
            status                  = ?,
            submitted_at            = IF(? = 1, NOW(), submitted_at),
            updated_at              = NOW()
        WHERE employee_id = ?
    ");

    $submit_flag = $mark_submitted ? 1 : 0;
    $stmt->bind_param(
        "ssissssissssssssssissssssii",
        $full_name, $contract_type, $supervisor_id, $start_date,
        $contract_end_date, $job_title, $gender, $department_id,
        $date_of_birth, $nationality, $personal_email, $company_email,
        $phone, $location, $residence_address,
        $next_of_kin_name, $next_of_kin_relationship,
        $has_dependants, $number_of_dependants,
        $ura_tin_number, $nssf_number, $bank_details, $nin,
        $form_completion_date, $status,
        $submit_flag,
        $existing['employee_id']
    );
    $stmt->execute();
    $stmt->close();

    /* Update file paths separately (variable number of fields) */
    if (!empty($uploaded_files)) {
        $set_parts = [];
        $params    = [];
        $types     = '';
        foreach ($uploaded_files as $col => $path) {
            $set_parts[] = "$col = ?";
            $params[]    = $path;
            $types      .= 's';
        }
        $params[] = $existing['employee_id'];
        $types   .= 'i';
        $upd = $conn->prepare("UPDATE employee_directory SET " . implode(', ', $set_parts) . " WHERE employee_id = ?");
        $upd->bind_param($types, ...$params);
        $upd->execute();
        $upd->close();
    }

    $record_id = $existing['employee_id'];

} else {

    /* -- INSERT ----------------------------------------------- */
    $file_cols = array_keys($uploaded_files);
    $file_vals = array_values($uploaded_files);

    $base_cols = [
        'user_id','full_name','contract_type','supervisor_id',
        'start_date','contract_end_date','job_title','gender','department_id',
        'date_of_birth','nationality','personal_email','company_email',
        'phone','location','residence_address','next_of_kin_name',
        'next_of_kin_relationship','has_dependants','number_of_dependants',
        'ura_tin_number','nssf_number','bank_details','nin',
        'form_completion_date','status',
    ];
    if ($is_submit) $base_cols[] = 'submitted_at';

    $all_cols   = array_merge($base_cols, $file_cols);
    $placeholders = implode(',', array_fill(0, count($all_cols), '?'));

    $base_vals = [
        $user_id, $full_name, $contract_type, $supervisor_id,
        $start_date, $contract_end_date, $job_title, $gender, $department_id,
        $date_of_birth, $nationality, $personal_email, $company_email,
        $phone, $location, $residence_address, $next_of_kin_name,
        $next_of_kin_relationship, $has_dependants, $number_of_dependants,
        $ura_tin_number, $nssf_number, $bank_details, $nin,
        $form_completion_date, $status,
    ];

    /* Build type string dynamically */
    $type_map = [
        'user_id'              => 'i', 'supervisor_id'       => 'i',
        'department_id'        => 'i', 'number_of_dependants'=> 'i',
    ];
    $types = '';
    foreach ($base_cols as $c) $types .= ($type_map[$c] ?? 's');
    foreach ($file_cols  as $c) $types .= 's';

    $all_vals = array_merge($base_vals, $file_vals);

    $ins = $conn->prepare(
        "INSERT INTO employee_directory (" . implode(',', $all_cols) . ")
         VALUES ($placeholders)"
    );
    $ins->bind_param($types, ...$all_vals);
    $ins->execute();
    $record_id = $conn->insert_id;
    $ins->close();
}

/* ---------------------------------------------------------------
   Log action & notify
--------------------------------------------------------------- */
/* Field-level change history for an approved profile going back to HR */
if ($needs_reapproval && $existing) {
    try {
        $hist = $conn->prepare(
            "INSERT INTO employee_directory_history (employee_id, field_name, old_value, new_value, changed_by)
             VALUES (?, ?, ?, ?, ?)"
        );

        if ($hist) {
            $history_rows = $profile_changes + ['status' => ['Approved', 'Submitted']];

            foreach ($history_rows as $column => [$old_value, $new_value]) {
                $field_name = (string)$column;
                $old_text   = $old_value === null ? null : (string)$old_value;
                $new_text   = $new_value === null ? null : (string)$new_value;
                $employee_pk = (int)$existing['employee_id'];
                $hist->bind_param('isssi', $employee_pk, $field_name, $old_text, $new_text, $user_id);
                $hist->execute();
            }

            $hist->close();
        }
    } catch (Throwable $exception) {
        error_log('employee_directory_history insert failed: ' . $exception->getMessage());
    }
}

if ($needs_reapproval) {
    $action_label = 'Employee Profile Changed (Re-approval Required)';
    $status_msg   = 'updated and sent back to HR for approval';
} elseif ($is_submit && $status === 'Approved') {
    $action_label = 'Submit Employee Profile';
    $status_msg   = 'saved (no changes - it remains approved)';
} else {
    $action_label = $is_submit ? 'Submit Employee Profile' : 'Save Employee Profile Draft';
    $status_msg   = $is_submit ? 'submitted for approval' : 'saved as draft';
}

log_action(
    $user_id,
    $action_label,
    'employee_directory',
    $record_id,
    "Employee profile $status_msg"
);

/* Notify HR when a profile is (re-)submitted */
if ($mark_submitted) {
    $hr_title = $needs_reapproval ? 'Approved employee profile changed' : 'Employee profile submitted';
    $hr_text  = $needs_reapproval
        ? "The approved employee profile of " . ($_SESSION['full_name'] ?? "User #$user_id") . " was changed ("
            . implode(', ', array_keys($profile_changes)) . ") and needs re-approval."
        : "Employee profile " . ($_SESSION['full_name'] ?? "User #$user_id") . " has been submitted for approval.";

    $hr_result = $conn->query("SELECT user_id FROM users WHERE role IN ('Administrator','HR') LIMIT 10");
    while ($hr_result && ($hr = $hr_result->fetch_assoc())) {
        if ((int)$hr['user_id'] === $user_id) {
            continue;
        }

        notify_user(
            (int)$hr['user_id'],
            $hr_title,
            $hr_text,
            $needs_reapproval ? 'warning' : 'info',
            (int)$record_id,
            'employee_directory'
        );
    }
}

send_notification($user_id, "Profile $status_msg successfully.", 'success');
header("Location: my-profile");
exit();