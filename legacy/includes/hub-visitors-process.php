<?php

session_start();
require_once 'config.php';      



function redirect(string $msg, bool $success = true, string $extra = ''): never
{
    $_SESSION[$success ? 'success' : 'error'] = $msg;
    header('Location: ../hub-visitors' . ($extra ? "?$extra" : ''));
    exit();
}



function collect_visitor_fields(bool $allow_future_date = false): array
{
    $fields = [
        'visitor_name' => sanitize_input($_POST['visitor_name'] ?? ''),
        'phone'        => sanitize_input($_POST['phone']        ?? ''),
        'email'        => sanitize_input($_POST['email']        ?? ''),
        'organization' => sanitize_input($_POST['organization'] ?? ''),
        'visit_date'   => sanitize_input($_POST['visit_date']   ?? ''),
        'visit_time'   => sanitize_input($_POST['visit_time']   ?? ''),
        'purpose'      => sanitize_input($_POST['purpose']      ?? ''),
        'host_contact' => sanitize_input($_POST['host_contact'] ?? ''),
        'remarks'      => sanitize_input($_POST['remarks']      ?? ''),
        'is_member'    => isset($_POST['is_member'])    ? 1 : 0,
        'is_first_time'=> isset($_POST['is_first_time']) ? 1 : 0,
    ];

    $allowed_purposes = ['Meeting','Event','Co-working','Training','Consultation','Tour','Interview','Other'];

    if (empty($fields['visitor_name']) || empty($fields['visit_date']) || empty($fields['purpose'])) {
        redirect('Please fill in all required fields (Name, Date, Purpose).', false);
    }

    if (!in_array($fields['purpose'], $allowed_purposes, true)) {
        redirect('Invalid purpose selected.', false);
    }

    if (!$allow_future_date && strtotime($fields['visit_date']) > strtotime('today')) {
        redirect('Visit date cannot be in the future.', false);
    }

    if (!empty($fields['email']) && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        redirect('Invalid email address format.', false);
    }

    if (!empty($fields['visit_time']) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $fields['visit_time'])) {
        redirect('Invalid time format.', false);
    }

    return $fields;
}

// -- Auth gate -----------------------------------------------------------------

if (!isset($_SESSION['user_id']) || !ims_allowed('hub_visitors.manage', ['Administrator', 'Operations/Admin'], 'Add and edit hub visitors')) {
    redirect('Unauthorized access.', false);
}

$user_id = (int) $_SESSION['user_id'];
$role    = $_SESSION['role'];

// -----------------------------------------------------------------------------
// ADD VISITOR
// -----------------------------------------------------------------------------
if (isset($_POST['add_visitor'])) {

    $f = collect_visitor_fields();

    $stmt = $conn->prepare(
        'INSERT INTO hub_visitors
            (visitor_name, phone, email, organization, visit_date, visit_time,
             purpose, host_contact, remarks, is_member, is_first_time, self_registered)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->bind_param(
        'sssssssssii',
        $f['visitor_name'], $f['phone'],   $f['email'],    $f['organization'],
        $f['visit_date'],   $f['visit_time'], $f['purpose'], $f['host_contact'],
        $f['remarks'],      $f['is_member'],  $f['is_first_time']
    );

    if ($stmt->execute()) {
        log_action($conn, $user_id, 'Add Visitor',
            "Added visitor: {$f['visitor_name']} on {$f['visit_date']}");
        redirect('Visitor logged successfully!');
    } else {
        redirect('Error logging visitor: ' . $conn->error, false);
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// EDIT VISITOR
// -----------------------------------------------------------------------------
if (isset($_POST['edit_visitor'])) {

    $visitor_id = (int) ($_POST['visitor_id'] ?? 0);
    if ($visitor_id <= 0) {
        redirect('Invalid visitor ID.', false);
    }

    $f = collect_visitor_fields();

    // Confirm the record exists and grab old name for the audit log
    $chk = $conn->prepare('SELECT visitor_name FROM hub_visitors WHERE visitor_id = ?');
    $chk->bind_param('i', $visitor_id);
    $chk->execute();
    $chk->bind_result($old_name);
    if (!$chk->fetch()) {
        $chk->close();
        redirect('Visitor not found.', false);
    }
    $chk->close();

    $stmt = $conn->prepare(
        'UPDATE hub_visitors SET
            visitor_name  = ?,
            phone         = ?,
            email         = ?,
            organization  = ?,
            visit_date    = ?,
            visit_time    = ?,
            purpose       = ?,
            host_contact  = ?,
            remarks       = ?,
            is_member     = ?,
            is_first_time = ?,
            updated_at    = CURRENT_TIMESTAMP
         WHERE visitor_id = ?'
    );
    $stmt->bind_param(
        'sssssssssiii',
        $f['visitor_name'], $f['phone'],    $f['email'],    $f['organization'],
        $f['visit_date'],   $f['visit_time'], $f['purpose'], $f['host_contact'],
        $f['remarks'],      $f['is_member'],  $f['is_first_time'], $visitor_id
    );

    if ($stmt->execute()) {
        log_action($conn, $user_id, 'Edit Visitor',
            "Updated visitor ID $visitor_id: \"$old_name\" ? \"{$f['visitor_name']}\"");
        redirect('Visitor updated successfully!');
    } else {
        redirect('Error updating visitor: ' . $conn->error, false);
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// DELETE VISITOR  (Administrator only)
// -----------------------------------------------------------------------------
if (isset($_GET['delete'])) {

    if (!ims_allowed('hub_visitors.delete', ['Administrator'], 'Delete hub visitors')) {
        redirect('Only administrators can delete visitor records.', false);
    }

    csrf_protect(true); // destructive GET link must carry the CSRF token

    $visitor_id = (int) $_GET['delete'];
    if ($visitor_id <= 0) {
        redirect('Invalid visitor ID.', false);
    }

    // Fetch for audit log
    $chk = $conn->prepare('SELECT visitor_name, visit_date FROM hub_visitors WHERE visitor_id = ?');
    $chk->bind_param('i', $visitor_id);
    $chk->execute();
    $chk->bind_result($v_name, $v_date);
    if (!$chk->fetch()) {
        $chk->close();
        redirect('Visitor not found.', false);
    }
    $chk->close();

    $del = $conn->prepare('DELETE FROM hub_visitors WHERE visitor_id = ?');
    $del->bind_param('i', $visitor_id);

    if ($del->execute()) {
        log_action($conn, $user_id, 'Delete Visitor',
            "Deleted visitor: $v_name (visit date: $v_date)");
        redirect('Visitor record deleted successfully.');
    } else {
        redirect('Error deleting visitor: ' . $conn->error, false);
    }
    $del->close();
}

// -----------------------------------------------------------------------------
// BULK DELETE  (Administrator only)
// -----------------------------------------------------------------------------
if (isset($_POST['bulk_delete'])) {

    if (!ims_allowed('hub_visitors.delete', ['Administrator'], 'Delete hub visitors')) {
        redirect('Only administrators can bulk-delete visitor records.', false);
    }

    if (empty($_POST['selected_visitors']) || !is_array($_POST['selected_visitors'])) {
        redirect('No visitors selected.', false);
    }

    // Sanitise IDs – keep only positive integers
    $ids = array_filter(array_map('intval', $_POST['selected_visitors']), fn($id) => $id > 0);
    if (empty($ids)) {
        redirect('No valid visitor IDs provided.', false);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));

    // Fetch names for audit log
    $sel = $conn->prepare("SELECT visitor_name FROM hub_visitors WHERE visitor_id IN ($placeholders)");
    $sel->bind_param($types, ...$ids);
    $sel->execute();
    $res   = $sel->get_result();
    $names = [];
    while ($row = $res->fetch_assoc()) {
        $names[] = $row['visitor_name'];
    }
    $sel->close();

    // Delete
    $del = $conn->prepare("DELETE FROM hub_visitors WHERE visitor_id IN ($placeholders)");
    $del->bind_param($types, ...$ids);

    if ($del->execute()) {
        $count = $del->affected_rows;
        log_action($conn, $user_id, 'Bulk Delete Visitors',
            "Deleted $count visitor(s): " . implode(', ', $names));
        redirect("$count visitor record(s) deleted successfully.");
    } else {
        redirect('Error bulk-deleting visitors: ' . $conn->error, false);
    }
    $del->close();
}

// -----------------------------------------------------------------------------
// EXPORT TO CSV
// -----------------------------------------------------------------------------
if (isset($_GET['export'])) {

    // Build a safe WHERE clause with prepared-statement params
    $conditions = ['1=1'];
    $bind_types = '';
    $bind_vals  = [];

    if (!empty($_GET['date'])) {
        $d = sanitize_input($_GET['date']);
        $conditions[] = 'visit_date = ?';
        $bind_types  .= 's';
        $bind_vals[]  = $d;
    } elseif (!empty($_GET['month'])) {
        $m = sanitize_input($_GET['month']);
        $conditions[] = "DATE_FORMAT(visit_date,'%Y-%m') = ?";
        $bind_types  .= 's';
        $bind_vals[]  = $m;
    }

    if (!empty($_GET['purpose'])) {
        $allowed_purposes = ['Meeting','Event','Co-working','Training','Consultation','Tour','Interview','Other'];
        $p = sanitize_input($_GET['purpose']);
        if (in_array($p, $allowed_purposes, true)) {
            $conditions[] = 'purpose = ?';
            $bind_types  .= 's';
            $bind_vals[]  = $p;
        }
    }

    $where_sql = implode(' AND ', $conditions);
    $stmt = $conn->prepare(
        "SELECT visitor_name, phone, email, organization, visit_date, visit_time,
                purpose, host_contact, is_member, is_first_time, self_registered,
                remarks, created_at
         FROM hub_visitors
         WHERE $where_sql
         ORDER BY visit_date DESC, visit_time DESC"
    );

    if (!empty($bind_vals)) {
        $stmt->bind_param($bind_types, ...$bind_vals);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    // Stream CSV
    $filename = 'hub_visitors_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: no-cache, no-store');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens it correctly
    fwrite($out, "\xEF\xBB\xBF");

    ims_fputcsv($out, [
        'Visitor Name','Phone','Email','Organization',
        'Visit Date','Visit Time','Purpose','Host / Contact',
        'Is Member','First Time','Self-Registered','Remarks','Logged At'
    ]);

    while ($row = $result->fetch_assoc()) {
        ims_fputcsv($out, [
            $row['visitor_name'],
            $row['phone'],
            $row['email'],
            $row['organization'],
            $row['visit_date'],
            $row['visit_time'],
            $row['purpose'],
            $row['host_contact'],
            $row['is_member']       ? 'Yes' : 'No',
            $row['is_first_time']   ? 'Yes' : 'No',
            $row['self_registered'] ? 'Yes' : 'No',
            $row['remarks'],
            $row['created_at'],
        ]);
    }

    fclose($out);
    $stmt->close();

    log_action($conn, $user_id, 'Export Visitors', "Exported visitor list to CSV ($filename)");
    exit();
}

// -----------------------------------------------------------------------------
// GENERATE SUMMARY REPORT
// -----------------------------------------------------------------------------
if (isset($_POST['generate_report'])) {

    $report_type = sanitize_input($_POST['report_type'] ?? 'summary');
    $start_date  = sanitize_input($_POST['start_date']  ?? '');
    $end_date    = sanitize_input($_POST['end_date']    ?? '');

    if (empty($start_date) || empty($end_date)) {
        redirect('Please select both a start and an end date.', false);
    }
    if (strtotime($end_date) < strtotime($start_date)) {
        redirect('End date must be on or after the start date.', false);
    }

    if ($report_type === 'summary') {

        $stats = [];

        // Total visitors
        $s = $conn->prepare('SELECT COUNT(*) FROM hub_visitors WHERE visit_date BETWEEN ? AND ?');
        $s->bind_param('ss', $start_date, $end_date);
        $s->execute();
        $s->bind_result($stats['total']);
        $s->fetch();
        $s->close();

        // By purpose
        $s = $conn->prepare(
            'SELECT purpose, COUNT(*) as cnt FROM hub_visitors
              WHERE visit_date BETWEEN ? AND ?
             GROUP BY purpose ORDER BY cnt DESC'
        );
        $s->bind_param('ss', $start_date, $end_date);
        $s->execute();
        $res = $s->get_result();
        $stats['by_purpose'] = [];
        while ($row = $res->fetch_assoc()) {
            $stats['by_purpose'][$row['purpose']] = $row['cnt'];
        }
        $s->close();

        // Members vs non-members
        $s = $conn->prepare(
            'SELECT SUM(is_member) as members, SUM(1 - is_member) as non_members
               FROM hub_visitors WHERE visit_date BETWEEN ? AND ?'
        );
        $s->bind_param('ss', $start_date, $end_date);
        $s->execute();
        $s->bind_result($stats['members'], $stats['non_members']);
        $s->fetch();
        $s->close();

        // First-time vs returning
        $s = $conn->prepare(
            'SELECT SUM(is_first_time) as first_time, SUM(1 - is_first_time) as returning_v
               FROM hub_visitors WHERE visit_date BETWEEN ? AND ?'
        );
        $s->bind_param('ss', $start_date, $end_date);
        $s->execute();
        $s->bind_result($stats['first_time'], $stats['returning']);
        $s->fetch();
        $s->close();

        // Self-registered (via public link) vs staff-logged
        $s = $conn->prepare(
            'SELECT SUM(self_registered) as self_reg, SUM(1 - self_registered) as staff_logged
               FROM hub_visitors WHERE visit_date BETWEEN ? AND ?'
        );
        $s->bind_param('ss', $start_date, $end_date);
        $s->execute();
        $s->bind_result($stats['self_registered'], $stats['staff_logged']);
        $s->fetch();
        $s->close();

        $_SESSION['report_data']   = $stats;
        $_SESSION['report_period'] = "$start_date to $end_date";

        log_action($conn, $user_id, 'Generate Report',
            "Generated visitor summary report for $start_date to $end_date");

        redirect('Report generated successfully.', true, 'view_report=summary');
    }

    redirect('Unknown report type.', false);
}

// -----------------------------------------------------------------------------
// MARK AS MEMBER
// -----------------------------------------------------------------------------
if (isset($_GET['mark_member'])) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token

    $visitor_id = (int) $_GET['mark_member'];
    if ($visitor_id <= 0) {
        redirect('Invalid visitor ID.', false);
    }

    // Fetch name for audit log
    $chk = $conn->prepare('SELECT visitor_name FROM hub_visitors WHERE visitor_id = ?');
    $chk->bind_param('i', $visitor_id);
    $chk->execute();
    $chk->bind_result($v_name);
    if (!$chk->fetch()) {
        $chk->close();
        redirect('Visitor not found.', false);
    }
    $chk->close();

    $upd = $conn->prepare('UPDATE hub_visitors SET is_member = 1 WHERE visitor_id = ?');
    $upd->bind_param('i', $visitor_id);

    if ($upd->execute()) {
        log_action($conn, $user_id, 'Update Visitor', "Marked \"$v_name\" (ID $visitor_id) as a Hub member");
        redirect("$v_name has been marked as a Hub member.");
    } else {
        redirect('Error updating visitor: ' . $conn->error, false);
    }
    $upd->close();
}

// -----------------------------------------------------------------------------
// APPROVE SELF-REGISTERED VISITOR  (new action for public-form submissions)
// -----------------------------------------------------------------------------
if (isset($_GET['approve_self_reg'])) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token

    $visitor_id = (int) $_GET['approve_self_reg'];
    if ($visitor_id <= 0) {
        redirect('Invalid visitor ID.', false);
    }

    $chk = $conn->prepare(
        'SELECT visitor_name FROM hub_visitors WHERE visitor_id = ? AND self_registered = 1'
    );
    $chk->bind_param('i', $visitor_id);
    $chk->execute();
    $chk->bind_result($v_name);
    if (!$chk->fetch()) {
        $chk->close();
        redirect('Self-registered visitor not found.', false);
    }
    $chk->close();

    // "Approving" simply clears the self_registered flag (now staff-verified)
    $upd = $conn->prepare(
        'UPDATE hub_visitors SET self_registered = 0 WHERE visitor_id = ?'
    );
    $upd->bind_param('i', $visitor_id);

    if ($upd->execute()) {
        log_action($conn, $user_id, 'Approve Self-Registration',
            "Approved self-registered visitor: \"$v_name\" (ID $visitor_id)");
        redirect("Self-registration for \"$v_name\" approved.");
    } else {
        redirect('Error approving visitor: ' . $conn->error, false);
    }
    $upd->close();
}

// -----------------------------------------------------------------------------
// SEND WELCOME EMAIL
// -----------------------------------------------------------------------------
if (isset($_POST['send_welcome_email'])) {

    $visitor_id = (int) ($_POST['visitor_id'] ?? 0);
    if ($visitor_id <= 0) {
        redirect('Invalid visitor ID.', false);
    }

    $chk = $conn->prepare('SELECT visitor_name, email FROM hub_visitors WHERE visitor_id = ?');
    $chk->bind_param('i', $visitor_id);
    $chk->execute();
    $chk->bind_result($v_name, $v_email);
    if (!$chk->fetch()) {
        $chk->close();
        redirect('Visitor not found.', false);
    }
    $chk->close();

    if (empty($v_email)) {
        redirect("$v_name has no email address on record.", false);
    }

  

    log_action($conn, $user_id, 'Send Welcome Email',
        "Sent welcome email to $v_name ($v_email)");

    redirect("Welcome email sent to $v_email.");
}

// -----------------------------------------------------------------------------
// Fallback — no recognised action
// -----------------------------------------------------------------------------
redirect('Invalid or unrecognised action.', false);