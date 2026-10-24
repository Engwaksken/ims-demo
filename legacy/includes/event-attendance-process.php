<?php
require_once 'config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer']);

function clean_value($value): string
{
    return sanitize_input(trim((string)$value));
}

function redirect_attendance(int $event_id, string $attendance_date = '', string $extra = ''): never
{
    $url = "../event-attendance?id={$event_id}";
    if ($attendance_date !== '') {
        $url .= '&day=' . urlencode($attendance_date);
    }
    if ($extra !== '') {
        $url .= '&' . ltrim($extra, '&');
    }
    header("Location: {$url}");
    exit();
}

function get_attendance_date(mysqli $conn, int $event_id): string
{
    $date = trim((string)($_POST['attendance_date'] ?? $_GET['day'] ?? ''));
    if ($date !== '' && strtotime($date)) {
        return date('Y-m-d', strtotime($date));
    }

    $stmt = $conn->prepare('SELECT event_date FROM hub_events WHERE event_id = ? LIMIT 1');
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($event['event_date']) ? date('Y-m-d', strtotime($event['event_date'])) : date('Y-m-d');
}

function update_actual_participants(mysqli $conn, int $event_id): void
{
    $stmt = $conn->prepare("\n        UPDATE hub_events\n        SET actual_participants = (\n            SELECT COUNT(*)\n            FROM event_registrations\n            WHERE event_id = ?\n              AND registration_status = 'Attended'\n        )\n        WHERE event_id = ?\n    ");
    $stmt->bind_param('ii', $event_id, $event_id);
    $stmt->execute();
    $stmt->close();
}

function attendance_event(mysqli $conn, int $event_id): ?array
{
    $stmt = $conn->prepare("\n        SELECT e.*, p.project_name, p.project_code, pr.program_name\n        FROM hub_events e\n        LEFT JOIN projects p ON e.project_id = p.project_id\n        LEFT JOIN programs pr ON e.program_id = pr.id\n        WHERE e.event_id = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $event ?: null;
}

function attendance_rows(mysqli $conn, int $event_id, string $attendance_date): array
{
    $rows = [];
    $stmt = $conn->prepare("\n        SELECT er.*, b.beneficiary_id AS is_beneficiary\n        FROM event_registrations er\n        LEFT JOIN beneficiaries b ON er.beneficiary_id = b.beneficiary_id\n        WHERE er.event_id = ?\n          AND (er.attendance_date = ? OR er.attendance_date IS NULL)\n        ORDER BY er.attendee_name ASC\n    ");
    $stmt->bind_param('is', $event_id, $attendance_date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safe_filename(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9_\-]+/', '_', trim($value));
    return trim($value, '_') ?: 'attendance';
}

function export_attendance_csv(array $event, array $rows, string $attendance_date): never
{
    $filename = safe_filename(($event['event_title'] ?? 'event') . '_' . $attendance_date) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    ims_fputcsv($out, ['Event Title', $event['event_title'] ?? '']);
    ims_fputcsv($out, ['Attendance Date', date('d M Y', strtotime($attendance_date))]);
    ims_fputcsv($out, ['Venue', $event['venue'] ?? '']);
    ims_fputcsv($out, []);
    ims_fputcsv($out, ['#', 'Attendee Name', 'Phone', 'Email', 'Organization', 'Status', 'Feedback Score', 'Feedback Comments', 'Participant']);

    $i = 1;
    foreach ($rows as $row) {
        ims_fputcsv($out, [
            $i++,
            $row['attendee_name'] ?? '',
            $row['phone'] ?? '',
            $row['email'] ?? '',
            $row['organization'] ?? '',
            $row['registration_status'] ?? '',
            $row['feedback_score'] ?? '',
            $row['feedback_comments'] ?? '',
            !empty($row['is_beneficiary']) ? 'Yes' : 'No',
        ]);
    }

    fclose($out);
    exit();
}

function export_attendance_pdf(array $event, array $rows, string $attendance_date): never
{
    $autoloads = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
    ];

    $loaded = false;
    foreach ($autoloads as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            $loaded = true;
            break;
        }
    }

    if (!$loaded || !class_exists('Dompdf\\Dompdf')) {
        send_notification($_SESSION['user_id'] ?? 0, 'DOMPDF autoload not found. Run composer require dompdf/dompdf or confirm vendor/autoload.php path.', 'danger');
        redirect_attendance((int)$event['event_id'], $attendance_date);
    }

    $total = count($rows);
    $attended = count(array_filter($rows, fn($r) => ($r['registration_status'] ?? '') === 'Attended'));
    $no_show = count(array_filter($rows, fn($r) => ($r['registration_status'] ?? '') === 'No Show'));
    $rate = $total > 0 ? number_format(($attended / $total) * 100, 1) . '%' : '0.0%';

    $htmlRows = '';
    $i = 1;
    foreach ($rows as $row) {
        $htmlRows .= '<tr>';
        $htmlRows .= '<td>' . $i++ . '</td>';
        $htmlRows .= '<td><strong>' . h($row['attendee_name'] ?? '') . '</strong>' . (!empty($row['is_beneficiary']) ? '<br><small>Participant</small>' : '') . '</td>';
        $htmlRows .= '<td>' . h($row['phone'] ?? '') . '<br>' . h($row['email'] ?? '') . '</td>';
        $htmlRows .= '<td>' . h($row['organization'] ?? '-') . '</td>';
        $htmlRows .= '<td>' . h($row['registration_status'] ?? '') . '</td>';
        $htmlRows .= '<td>' . (!empty($row['feedback_score']) ? (int)$row['feedback_score'] . '/5' : '-') . '</td>';
        $htmlRows .= '</tr>';
    }

    if ($htmlRows === '') {
        $htmlRows = '<tr><td colspan="6" style="text-align:center;color:#777;padding:20px;">No attendance records found.</td></tr>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#222;margin:24px;}
        h1{font-size:20px;margin:0 0 6px;color:#222;}
        .muted{color:#666;}
        .meta{margin:12px 0 18px;border:1px solid #ddd;padding:10px;border-radius:6px;}
        .stats{width:100%;margin-bottom:16px;border-collapse:collapse;}
        .stats td{border:1px solid #ddd;padding:8px;text-align:center;}
        table.list{width:100%;border-collapse:collapse;}
        table.list th,table.list td{border:1px solid #ddd;padding:7px;vertical-align:top;}
        table.list th{background:#f2f2f2;text-align:left;}
        .footer{margin-top:18px;font-size:10px;color:#777;text-align:right;}
    </style></head><body>
        <h1>' . h($event['event_title'] ?? 'Event Attendance') . '</h1>
        <div class="muted">Attendance Report for ' . h(date('d M Y', strtotime($attendance_date))) . '</div>
        <div class="meta">
            <strong>Event Type:</strong> ' . h($event['event_type'] ?? '-') . '<br>
            <strong>Venue:</strong> ' . h($event['venue'] ?? 'TBA') . '<br>
            <strong>Organizer:</strong> ' . h($event['organizer'] ?? 'N/A') . '<br>
            <strong>Project/Program:</strong> ' . h(($event['project_name'] ?? '') ?: ($event['program_name'] ?? '-')) . '
        </div>
        <table class="stats"><tr>
            <td><strong>' . $total . '</strong><br>Total Registered</td>
            <td><strong>' . $attended . '</strong><br>Attended</td>
            <td><strong>' . $no_show . '</strong><br>No Show</td>
            <td><strong>' . $rate . '</strong><br>Attendance Rate</td>
        </tr></table>
        <table class="list"><thead><tr><th>#</th><th>Name</th><th>Contact</th><th>Organization</th><th>Status</th><th>Feedback</th></tr></thead><tbody>' . $htmlRows . '</tbody></table>
        <div class="footer">Generated on ' . h(date('d M Y h:i A')) . '</div>
    </body></html>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
require_once __DIR__ . '/pdf-branding.php';
ims_brand_pdf($dompdf);

    $filename = safe_filename(($event['event_title'] ?? 'event') . '_' . $attendance_date) . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit();
}

$event_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['event_id'] ?? 0);
if ($event_id <= 0) {
    send_notification($_SESSION['user_id'] ?? 0, 'Invalid event ID', 'danger');
    header('Location: ../events');
    exit();
}

$event = attendance_event($conn, $event_id);
if (!$event) {
    send_notification($_SESSION['user_id'] ?? 0, 'Event not found', 'danger');
    header('Location: ../events');
    exit();
}

$attendance_date = get_attendance_date($conn, $event_id);
$valid_statuses = ['Registered', 'Attended', 'No Show'];

if (isset($_GET['export'])) {
    $rows = attendance_rows($conn, $event_id, $attendance_date);
    $export = strtolower(trim((string)$_GET['export']));

    if ($export === 'csv') {
        export_attendance_csv($event, $rows, $attendance_date);
    }
    if ($export === 'pdf') {
        export_attendance_pdf($event, $rows, $attendance_date);
    }

    send_notification($_SESSION['user_id'] ?? 0, 'Invalid export format selected.', 'danger');
    redirect_attendance($event_id, $attendance_date);
}

if (isset($_POST['bulk_mark_selected_attended']) || isset($_POST['bulk_mark_all_attended'])) {
    $status = 'Attended';

    if (isset($_POST['bulk_mark_all_attended'])) {
        $stmt = $conn->prepare("\n            UPDATE event_registrations\n            SET registration_status = ?, attendance_date = ?\n            WHERE event_id = ?\n              AND (attendance_date = ? OR attendance_date IS NULL)\n        ");
        $stmt->bind_param('ssis', $status, $attendance_date, $event_id, $attendance_date);
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['registration_ids'] ?? []))));
        if (empty($ids)) {
            send_notification($_SESSION['user_id'] ?? 0, 'Please select at least one attendee.', 'warning');
            redirect_attendance($event_id, $attendance_date);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'ssi' . str_repeat('i', count($ids));
        $params = array_merge([$status, $attendance_date, $event_id], $ids);

        $stmt = $conn->prepare("\n            UPDATE event_registrations\n            SET registration_status = ?, attendance_date = ?\n            WHERE event_id = ?\n              AND registration_id IN ({$placeholders})\n        ");
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        update_actual_participants($conn, $event_id);
        log_action($_SESSION['user_id'] ?? 0, 'Bulk Mark Attended', 'event_registrations', $event_id, "Marked {$affected} attendee(s) as Attended for {$attendance_date}");
        send_notification($_SESSION['user_id'] ?? 0, "{$affected} attendee(s) marked as attended.", 'success');
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error updating attendance: ' . $stmt->error, 'danger');
    }
    $stmt->close();
    redirect_attendance($event_id, $attendance_date);
}

if (isset($_POST['add_attendee'])) {
    $beneficiary_id = !empty($_POST['beneficiary_id']) ? (int)$_POST['beneficiary_id'] : null;
    $attendee_name = clean_value($_POST['attendee_name'] ?? '');
    $phone = clean_value($_POST['phone'] ?? '');
    $email = clean_value($_POST['email'] ?? '');
    $organization = clean_value($_POST['organization'] ?? '');
    $registration_status = clean_value($_POST['registration_status'] ?? 'Registered');

    if ($attendee_name === '' || !in_array($registration_status, $valid_statuses, true)) {
        send_notification($_SESSION['user_id'] ?? 0, 'Attendee name and a valid status are required.', 'danger');
        redirect_attendance($event_id, $attendance_date);
    }

    if ($beneficiary_id !== null) {
        $stmt = $conn->prepare('SELECT registration_id FROM event_registrations WHERE event_id = ? AND beneficiary_id = ? AND attendance_date = ? LIMIT 1');
        $stmt->bind_param('iis', $event_id, $beneficiary_id, $attendance_date);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) {
            send_notification($_SESSION['user_id'] ?? 0, 'This beneficiary is already registered for this event day.', 'danger');
            redirect_attendance($event_id, $attendance_date);
        }
    }

    $stmt = $conn->prepare("\n        INSERT INTO event_registrations (event_id, attendance_date, beneficiary_id, attendee_name, phone, email, organization, registration_status)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n    ");
    $stmt->bind_param('isisssss', $event_id, $attendance_date, $beneficiary_id, $attendee_name, $phone, $email, $organization, $registration_status);

    if ($stmt->execute()) {
        $new_id = (int)$stmt->insert_id;
        update_actual_participants($conn, $event_id);
        log_action($_SESSION['user_id'] ?? 0, 'Add Attendee', 'event_registrations', $new_id, "Added attendee to event {$event_id} on {$attendance_date}");
        send_notification($_SESSION['user_id'] ?? 0, 'Attendee added successfully.', 'success');
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error adding attendee: ' . $stmt->error, 'danger');
    }
    $stmt->close();
    redirect_attendance($event_id, $attendance_date);
}

if (isset($_POST['bulk_register'])) {
    if (empty($_POST['beneficiaries']) || !is_array($_POST['beneficiaries'])) {
        send_notification($_SESSION['user_id'] ?? 0, 'Please select at least one beneficiary.', 'warning');
        redirect_attendance($event_id, $attendance_date);
    }

    $success_count = 0;
    $already_registered = 0;

    foreach ($_POST['beneficiaries'] as $beneficiary_id_raw) {
        $beneficiary_id = (int)$beneficiary_id_raw;
        if ($beneficiary_id <= 0) {
            continue;
        }

        $stmt = $conn->prepare('SELECT registration_id FROM event_registrations WHERE event_id = ? AND beneficiary_id = ? AND attendance_date = ? LIMIT 1');
        $stmt->bind_param('iis', $event_id, $beneficiary_id, $attendance_date);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            $already_registered++;
            continue;
        }

        $stmt = $conn->prepare('SELECT first_name, last_name, phone, email FROM beneficiaries WHERE beneficiary_id = ? LIMIT 1');
        $stmt->bind_param('i', $beneficiary_id);
        $stmt->execute();
        $beneficiary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$beneficiary) {
            continue;
        }

        $attendee_name = trim(($beneficiary['first_name'] ?? '') . ' ' . ($beneficiary['last_name'] ?? ''));
        $phone = (string)($beneficiary['phone'] ?? '');
        $email = (string)($beneficiary['email'] ?? '');
        $status = 'Registered';

        $stmt = $conn->prepare("\n            INSERT INTO event_registrations (event_id, attendance_date, beneficiary_id, attendee_name, phone, email, registration_status)\n            VALUES (?, ?, ?, ?, ?, ?, ?)\n        ");
        $stmt->bind_param('isissss', $event_id, $attendance_date, $beneficiary_id, $attendee_name, $phone, $email, $status);
        if ($stmt->execute()) {
            $success_count++;
        }
        $stmt->close();
    }

    update_actual_participants($conn, $event_id);
    log_action($_SESSION['user_id'] ?? 0, 'Bulk Register', 'event_registrations', $event_id, "Bulk registered {$success_count} attendees for {$attendance_date}");
    $message = "{$success_count} attendee(s) registered successfully.";
    if ($already_registered > 0) {
        $message .= " {$already_registered} already registered for this day.";
    }
    send_notification($_SESSION['user_id'] ?? 0, $message, 'success');
    redirect_attendance($event_id, $attendance_date);
}

if (isset($_POST['update_status'])) {
    $registration_id = (int)($_POST['registration_id'] ?? 0);
    $registration_status = clean_value($_POST['registration_status'] ?? '');
    $feedback_score_raw = trim((string)($_POST['feedback_score'] ?? ''));
    $feedback_score = $feedback_score_raw !== '' ? (int)$feedback_score_raw : null;
    $feedback_comments = clean_value($_POST['feedback_comments'] ?? '');

    if ($registration_id <= 0 || !in_array($registration_status, $valid_statuses, true)) {
        send_notification($_SESSION['user_id'] ?? 0, 'Invalid attendee or status selected.', 'danger');
        redirect_attendance($event_id, $attendance_date);
    }
    if ($feedback_score !== null && ($feedback_score < 1 || $feedback_score > 5)) {
        send_notification($_SESSION['user_id'] ?? 0, 'Feedback score must be between 1 and 5.', 'danger');
        redirect_attendance($event_id, $attendance_date, 'update=' . $registration_id);
    }

    $stmt = $conn->prepare("\n        UPDATE event_registrations\n        SET registration_status = ?, feedback_score = ?, feedback_comments = ?, attendance_date = ?\n        WHERE registration_id = ? AND event_id = ?\n    ");
    $stmt->bind_param('sissii', $registration_status, $feedback_score, $feedback_comments, $attendance_date, $registration_id, $event_id);

    if ($stmt->execute()) {
        update_actual_participants($conn, $event_id);
        log_action($_SESSION['user_id'] ?? 0, 'Update Attendance', 'event_registrations', $registration_id, "Updated attendance status to {$registration_status} for {$attendance_date}");
        send_notification($_SESSION['user_id'] ?? 0, 'Attendance status updated successfully.', 'success');
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error updating status: ' . $stmt->error, 'danger');
    }
    $stmt->close();
    redirect_attendance($event_id, $attendance_date);
}

if (isset($_POST['upload_csv'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        send_notification($_SESSION['user_id'] ?? 0, 'Please select a valid CSV file.', 'danger');
        redirect_attendance($event_id, $attendance_date);
    }

    $file = $_FILES['csv_file'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'csv') {
        send_notification($_SESSION['user_id'] ?? 0, 'Only CSV files are allowed.', 'danger');
        redirect_attendance($event_id, $attendance_date);
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        send_notification($_SESSION['user_id'] ?? 0, 'Error reading CSV file.', 'danger');
        redirect_attendance($event_id, $attendance_date);
    }

    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $row_number = 1;

    $bom = pack('CCC', 0xEF, 0xBB, 0xBF);
    if (fgets($handle, 4) !== $bom) {
        rewind($handle);
    }
    fgetcsv($handle);

    while (($data = fgetcsv($handle)) !== false) {
        $row_number++;
        if (empty(array_filter($data))) {
            continue;
        }
        if (isset($data[0]) && (stripos($data[0], 'INSTRUCTIONS') !== false || stripos($data[0], 'DELETE') !== false || stripos($data[0], 'Fields marked') !== false)) {
            continue;
        }

        $attendee_name = isset($data[0]) ? clean_value($data[0]) : '';
        $phone = isset($data[1]) ? clean_value($data[1]) : '';
        $email = isset($data[2]) ? clean_value($data[2]) : '';
        $organization = isset($data[3]) ? clean_value($data[3]) : '';
        $registration_status = isset($data[4]) ? clean_value($data[4]) : 'Registered';
        $feedback_score = isset($data[5]) && is_numeric(trim((string)$data[5])) ? (int)$data[5] : null;
        $feedback_comments = isset($data[6]) ? clean_value($data[6]) : '';

        if ($attendee_name === '') {
            $errors[] = "Row {$row_number}: Attendee name is required.";
            $error_count++;
            continue;
        }
        if (!in_array($registration_status, $valid_statuses, true)) {
            $errors[] = "Row {$row_number}: Invalid status '{$registration_status}'.";
            $error_count++;
            continue;
        }
        if ($feedback_score !== null && ($feedback_score < 1 || $feedback_score > 5)) {
            $errors[] = "Row {$row_number}: Feedback score must be between 1 and 5.";
            $error_count++;
            continue;
        }

        $stmt = $conn->prepare("\n            INSERT INTO event_registrations (event_id, attendance_date, attendee_name, phone, email, organization, registration_status, feedback_score, feedback_comments)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        $stmt->bind_param('issssssis', $event_id, $attendance_date, $attendee_name, $phone, $email, $organization, $registration_status, $feedback_score, $feedback_comments);

        if ($stmt->execute()) {
            $success_count++;
        } else {
            $errors[] = "Row {$row_number}: Database error - " . $stmt->error;
            $error_count++;
        }
        $stmt->close();
    }
    fclose($handle);

    update_actual_participants($conn, $event_id);
    log_action($_SESSION['user_id'] ?? 0, 'CSV Bulk Upload', 'event_registrations', $event_id, "Uploaded CSV for {$attendance_date}: {$success_count} succeeded, {$error_count} failed");

    if ($success_count > 0 && $error_count === 0) {
        send_notification($_SESSION['user_id'] ?? 0, "Successfully imported {$success_count} attendees.", 'success');
    } elseif ($success_count > 0) {
        send_notification($_SESSION['user_id'] ?? 0, "Imported {$success_count} attendees with {$error_count} errors.", 'warning');
    } else {
        $error_summary = implode('<br>', array_slice($errors, 0, 5));
        send_notification($_SESSION['user_id'] ?? 0, "CSV import failed:<br>{$error_summary}", 'danger');
    }
    redirect_attendance($event_id, $attendance_date);
}

if (isset($_GET['delete']) && ims_allowed('event_attendance.delete', ['Administrator', 'Operations/Admin'], 'Delete event attendance records')) {
    csrf_protect(true); // state-changing GET link must carry the CSRF token
    $registration_id = (int)$_GET['delete'];

    $stmt = $conn->prepare('SELECT attendee_name FROM event_registrations WHERE registration_id = ? AND event_id = ? LIMIT 1');
    $stmt->bind_param('ii', $registration_id, $event_id);
    $stmt->execute();
    $attendee = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attendee_name = $attendee['attendee_name'] ?? 'Unknown';

    $stmt = $conn->prepare('DELETE FROM event_registrations WHERE registration_id = ? AND event_id = ?');
    $stmt->bind_param('ii', $registration_id, $event_id);

    if ($stmt->execute()) {
        update_actual_participants($conn, $event_id);
        log_action($_SESSION['user_id'] ?? 0, 'Delete Attendee', 'event_registrations', $registration_id, "Deleted attendee {$attendee_name} from event {$event_id} on {$attendance_date}");
        send_notification($_SESSION['user_id'] ?? 0, 'Attendee deleted successfully.', 'success');
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error deleting attendee: ' . $stmt->error, 'danger');
    }
    $stmt->close();
    redirect_attendance($event_id, $attendance_date);
}

redirect_attendance($event_id, $attendance_date);
