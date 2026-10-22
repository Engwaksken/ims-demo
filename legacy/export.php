<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

check_login();

$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';

// Each export is limited to the roles of the page that offers it.
if ($type === 'employee_report') {
    check_role(['Administrator', 'Programs Lead', 'HR']);                        // employee-reports.php
} elseif (in_array($type, ['members', 'bookings', 'subscriptions'], true)) {
    check_role(['Administrator', 'Operations/Admin', 'Finance', 'Accountant']);  // hub-operations.php
} else {
    check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Operations/Admin']);
}

/* -- Validate export type --------------------------------------------- */
$valid_types = [
    'programs', 'projects', 'participants', 'beneficiaries',
    'indicators', 'events', 'documents',
    'hub_visitors', 'memberships', 'pwd', 'donors',
    'members', 'bookings', 'subscriptions', 'employee_report',
];

if (!in_array($type, $valid_types, true)) {
    http_response_code(400);
    die('Invalid or missing export type. Allowed: ' . implode(', ', $valid_types));
}

/* -- Optional scope filters ------------------------------------------- */
$program_id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$project_id = !empty($_GET['project_id']) ? (int) $_GET['project_id'] : (!empty($_GET['project']) ? (int) $_GET['project'] : 0);
$indicator_type = in_array($_GET['indicator_type'] ?? '', ['Output', 'Outcome', 'Impact'], true) ? $_GET['indicator_type'] : '';
$department_id = !empty($_GET['department']) ? (int) $_GET['department'] : 0;

/* -- Stream CSV headers ----------------------------------------------- */
$filename = $type . '_export_' . date('Y-m-d_His') . '.csv';

while (ob_get_level()) ob_end_clean();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
header('Cache-Control: must-revalidate');

$out = fopen('php://output', 'w');

/* UTF-8 BOM — makes Excel open the file correctly on Windows */
fwrite($out, "\xEF\xBB\xBF");

/* -- Helper: write a row, skipping null ? 'N/A' ---------------------- */
function row(mixed ...$values): array {
    return array_map(fn($v) => $v === null || $v === '' ? 'N/A' : $v, $values);
}

/* -- Helper: calculate age from DOB ---------------------------------- */
function age(?string $dob): string {
    if (!$dob) return 'N/A';
    try {
        return (string) (new DateTime($dob))->diff(new DateTime())->y;
    } catch (Exception) {
        return 'N/A';
    }
}

/* ----------------------------------------------------------------------
   EXPORT CASES
   ---------------------------------------------------------------------- */
switch ($type) {

    /* -- Programs ------------------------------------------------------ */
    case 'programs':
        ims_fputcsv($out, [
            'Program Code', 'Program Name', 'Donor', 'Status',
            'Start Date', 'End Date', 'Budget', 'Currency',
            'Participants', 'Indicators', 'Documents', 'Created At',
        ]);

        $where = $program_id ? "WHERE p.id = {$program_id}" : '';
        $res = $conn->query("
            SELECT p.*,
                d.donor_name,
                (SELECT COUNT(*) FROM programs_beneficiaries pb WHERE pb.program_id = p.id) AS participant_count,
                (SELECT COUNT(*) FROM indicators i     WHERE i.program_id   = p.id) AS indicator_count,
                (SELECT COUNT(*) FROM documents doc    WHERE doc.program_id = p.id) AS document_count
            FROM programs p
            LEFT JOIN donors d ON d.donor_id = p.donor_id
            {$where}
            ORDER BY p.created_at DESC
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['program_code'],   $r['program_name'],
                $r['donor_name'],     $r['status'],
                $r['start_date'],     $r['end_date'],
                $r['budget'],         $r['currency'] ?? 'UGX',
                $r['participant_count'], $r['indicator_count'],
                $r['document_count'], $r['created_at'],
            ));
        }
        break;

    /* -- Projects ------------------------------------------------------ */
    case 'projects':
        ims_fputcsv($out, [
            'Project Code', 'Project Name', 'Donor', 'Status',
            'Start Date', 'End Date', 'Budget', 'Currency',
            'Geographic Scope', 'Participants', 'Indicators', 'Documents', 'Created At',
        ]);

        $where = $project_id ? "WHERE p.project_id = {$project_id}" : '';
        $res = $conn->query("
            SELECT p.*,
                d.donor_name,
                (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.project_id = p.project_id) AS participant_count,
                (SELECT COUNT(*) FROM indicators i  WHERE i.project_id   = p.project_id) AS indicator_count,
                (SELECT COUNT(*) FROM documents doc WHERE doc.project_id = p.project_id) AS document_count
            FROM projects p
            LEFT JOIN donors d ON d.donor_id = p.donor_id
            {$where}
            ORDER BY p.created_at DESC
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['project_code'],   $r['project_name'],
                $r['donor_name'],     $r['status'],
                $r['start_date'],     $r['end_date'],
                $r['budget'],         $r['currency'],
                $r['geographic_scope'],
                $r['participant_count'], $r['indicator_count'],
                $r['document_count'], $r['created_at'],
            ));
        }
        break;

    /* -- Participants / Beneficiaries ---------------------------------- */
    case 'participants':
    case 'beneficiaries':
        ims_fputcsv($out, [
            'ID', 'First Name', 'Last Name', 'Gender', 'Date of Birth', 'Age',
            'Phone', 'Email', 'District', 'Subcounty', 'Village',
            'PWD', 'PWD Type', 'Education Level', 'Occupation',
            'Programs', 'Projects', 'Events', 'Registration Date',
        ]);

        $join  = '';
        $where = 'WHERE 1=1';
        if ($program_id) {
            $join  = "JOIN programs_beneficiaries _pb ON _pb.beneficiary_id = b.beneficiary_id";
            $where = "WHERE _pb.program_id = {$program_id}";
        } elseif ($project_id) {
            $join  = "JOIN project_beneficiaries _pb ON _pb.beneficiary_id = b.beneficiary_id";
            $where = "WHERE _pb.project_id = {$project_id}";
        }

        $res = $conn->query("
            SELECT b.*,
                (SELECT COUNT(*) FROM programs_beneficiaries pb  WHERE pb.beneficiary_id  = b.beneficiary_id) AS program_count,
                (SELECT COUNT(*) FROM project_beneficiaries  pjb WHERE pjb.beneficiary_id = b.beneficiary_id) AS project_count,
                (SELECT COUNT(*) FROM event_attendance       ea  WHERE ea.beneficiary_id  = b.beneficiary_id) AS event_count
            FROM beneficiaries b
            {$join}
            {$where}
            ORDER BY b.last_name, b.first_name
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['beneficiary_id'], $r['first_name'],  $r['last_name'],
                $r['gender'],         $r['date_of_birth'], age($r['date_of_birth']),
                $r['phone'],          $r['email'],
                $r['district'],       $r['subcounty'],    $r['village'],
                $r['is_pwd'] ? 'Yes' : 'No', $r['pwd_type'],
                $r['education_level'], $r['occupation'],
                $r['program_count'],  $r['project_count'], $r['event_count'],
                $r['created_at'],
            ));
        }
        break;

    /* -- Indicators ---------------------------------------------------- */
    case 'indicators':
        ims_fputcsv($out, [
            'Context Type', 'Context Code', 'Context Name',
            'Indicator Name', 'Type', 'Unit of Measure',
            'Baseline', 'Target', 'Current Value', 'Achievement %',
            'Data Source', 'Reporting Frequency',
        ]);

        $scope = '';
        if ($program_id) $scope = "AND i.program_id = {$program_id}";
        if ($project_id) $scope = "AND i.project_id = {$project_id}";
        if ($indicator_type !== '') $scope .= " AND i.indicator_type = '{$indicator_type}'"; // whitelisted above

        $res = $conn->query("
            SELECT i.*,
                p.project_code, p.project_name,
                pr.program_code, pr.program_name,
                CASE
                    WHEN i.project_id IS NOT NULL THEN 'Project'
                    WHEN i.program_id IS NOT NULL THEN 'Program'
                    ELSE 'General'
                END AS context_type,
                CASE
                    WHEN i.project_id IS NOT NULL THEN p.project_code
                    WHEN i.program_id IS NOT NULL THEN pr.program_code
                    ELSE NULL
                END AS context_code,
                CASE
                    WHEN i.project_id IS NOT NULL THEN p.project_name
                    WHEN i.program_id IS NOT NULL THEN pr.program_name
                    ELSE NULL
                END AS context_name,
                CASE
                    WHEN i.target_value > 0
                    THEN ROUND(i.current_value / i.target_value * 100, 2)
                    ELSE 0
                END AS achievement_pct
            FROM indicators i
            LEFT JOIN projects p  ON p.project_id = i.project_id
            LEFT JOIN programs pr ON pr.id         = i.program_id
            WHERE 1=1 {$scope}
            ORDER BY i.indicator_type, i.indicator_name
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['context_type'],    $r['context_code'],  $r['context_name'],
                $r['indicator_name'],  $r['indicator_type'], $r['unit_of_measure'],
                $r['baseline_value'],  $r['target_value'],  $r['current_value'],
                $r['achievement_pct'] . '%',
                $r['data_source'],     $r['reporting_frequency'],
            ));
        }
        break;

    /* -- Events -------------------------------------------------------- */
    case 'events':
        ims_fputcsv($out, [
            'Event Name', 'Type', 'Date', 'Start Time', 'Venue', 'Organiser',
            'Context Type', 'Context Name',
            'Expected', 'Registered', 'Attended', 'No-Show',
            'Avg Feedback', 'Status',
        ]);

        $scope = '';
        if ($program_id) $scope = "AND e.program_id = {$program_id}";
        if ($project_id) $scope = "AND e.project_id = {$project_id}";

        $res = $conn->query("
            SELECT e.*,
                p.project_code,  p.project_name,
                pr.program_code, pr.program_name,
                CASE
                    WHEN e.project_id IS NOT NULL THEN 'Project'
                    WHEN e.program_id IS NOT NULL THEN 'Program'
                    ELSE 'General'
                END AS context_type,
                CASE
                    WHEN e.project_id IS NOT NULL THEN CONCAT(p.project_code, ' — ', p.project_name)
                    WHEN e.program_id IS NOT NULL THEN CONCAT(pr.program_code, ' — ', pr.program_name)
                    ELSE NULL
                END AS context_name,
                (SELECT COUNT(*)     FROM event_attendance ea WHERE ea.event_id = e.event_id)                                         AS registered_count,
                (SELECT COUNT(*)     FROM event_attendance ea WHERE ea.event_id = e.event_id AND ea.attendance_status = 'Attended')    AS attended_count,
                (SELECT COUNT(*)     FROM event_attendance ea WHERE ea.event_id = e.event_id AND ea.attendance_status = 'No Show')     AS noshow_count,
                (SELECT AVG(feedback_score) FROM event_attendance ea WHERE ea.event_id = e.event_id AND ea.feedback_score IS NOT NULL) AS avg_feedback
            FROM events e
            LEFT JOIN projects p  ON p.project_id = e.project_id
            LEFT JOIN programs pr ON pr.id         = e.program_id
            WHERE 1=1 {$scope}
            ORDER BY e.event_date DESC
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['event_name'],        $r['event_type'],
                $r['event_date'],        $r['start_time'],
                $r['venue'],             $r['organizer'],
                $r['context_type'],      $r['context_name'],
                $r['expected_participants'] ?? 0,
                $r['registered_count'],  $r['attended_count'], $r['noshow_count'],
                $r['avg_feedback'] !== null ? number_format((float)$r['avg_feedback'], 1) : 'N/A',
                $r['status'] ?? 'Completed',
            ));
        }
        break;

    /* -- Documents ----------------------------------------------------- */
    case 'documents':
        ims_fputcsv($out, [
            'Document Name', 'Type', 'Context Type', 'Context Code', 'Context Name',
            'File Size (MB)', 'Upload Date', 'Uploaded By', 'Tags', 'Description',
        ]);

        $scope = '';
        if ($program_id) $scope = "AND d.program_id = {$program_id}";
        if ($project_id) $scope = "AND d.project_id = {$project_id}";

        $res = $conn->query("
            SELECT d.*,
                p.project_code,  p.project_name,
                pr.program_code, pr.program_name,
                u.full_name AS uploader_name,
                CASE
                    WHEN d.project_id IS NOT NULL THEN 'Project'
                    WHEN d.program_id IS NOT NULL THEN 'Program'
                    ELSE 'General'
                END AS context_type,
                CASE
                    WHEN d.project_id IS NOT NULL THEN p.project_code
                    WHEN d.program_id IS NOT NULL THEN pr.program_code
                    ELSE NULL
                END AS context_code,
                CASE
                    WHEN d.project_id IS NOT NULL THEN p.project_name
                    WHEN d.program_id IS NOT NULL THEN pr.program_name
                    ELSE NULL
                END AS context_name
            FROM documents d
            LEFT JOIN projects p  ON p.project_id  = d.project_id
            LEFT JOIN programs pr ON pr.id           = d.program_id
            LEFT JOIN users u     ON u.user_id       = d.uploaded_by
            WHERE 1=1 {$scope}
            ORDER BY d.upload_date DESC
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['document_name'],   $r['document_type'],
                $r['context_type'],    $r['context_code'],  $r['context_name'],
                $r['file_size'] ? number_format($r['file_size'] / 1048576, 3) : '0.000',
                $r['upload_date'],     $r['uploader_name'],
                $r['tags'],            $r['description'],
            ));
        }
        break;

    /* -- Hub Visitors -------------------------------------------------- */
    case 'hub_visitors':
        ims_fputcsv($out, [
            'Visitor Name', 'Phone', 'Email', 'Organisation',
            'Visit Date', 'Visit Time', 'Purpose', 'Remarks',
        ]);

        $res = $conn->query("SELECT * FROM hub_visitors ORDER BY visit_date DESC, visit_time DESC");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['visitor_name'], $r['phone'],       $r['email'],
                $r['organization'], $r['visit_date'],  $r['visit_time'],
                $r['purpose'],      $r['remarks'],
            ));
        }
        break;

    /* -- Memberships --------------------------------------------------- */
    case 'memberships':
        ims_fputcsv($out, [
            'Member Name', 'Phone', 'Email', 'Organisation',
            'Membership Type', 'Start Date', 'End Date',
            'Monthly Fee', 'Status',
        ]);

        $res = $conn->query("SELECT * FROM memberships ORDER BY start_date DESC");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['member_name'],   $r['phone'],           $r['email'],
                $r['organization'],  $r['membership_type'],
                $r['start_date'],    $r['end_date'],
                $r['monthly_fee'] ?? 0, $r['status'],
            ));
        }
        break;

    /* -- PWD ----------------------------------------------------------- */
    case 'pwd':
        ims_fputcsv($out, [
            'ID', 'First Name', 'Last Name', 'Gender',
            'Date of Birth', 'Age', 'Phone', 'Email', 'District',
            'Disability Type', 'Programs', 'Projects', 'Events', 'Registration Date',
        ]);

        $res = $conn->query("
            SELECT b.*,
                (SELECT COUNT(*) FROM programs_beneficiaries pb  WHERE pb.beneficiary_id  = b.beneficiary_id) AS program_count,
                (SELECT COUNT(*) FROM project_beneficiaries  pjb WHERE pjb.beneficiary_id = b.beneficiary_id) AS project_count,
                (SELECT COUNT(*) FROM event_attendance       ea  WHERE ea.beneficiary_id  = b.beneficiary_id) AS event_count
            FROM beneficiaries b
            WHERE b.is_pwd = 1
            ORDER BY b.last_name, b.first_name
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['beneficiary_id'], $r['first_name'],    $r['last_name'],
                $r['gender'],         $r['date_of_birth'], age($r['date_of_birth']),
                $r['phone'],          $r['email'],          $r['district'],
                $r['pwd_type'],
                $r['program_count'],  $r['project_count'], $r['event_count'],
                $r['created_at'],
            ));
        }
        break;

    /* -- Donors -------------------------------------------------------- */
    case 'donors':
        ims_fputcsv($out, [
            'Donor Name', 'Type', 'Contact Person', 'Email', 'Phone', 'Website',
            'Programs Funded', 'Projects Funded',
            'Total Program Budget', 'Total Project Budget',
        ]);

        $res = $conn->query("
            SELECT d.*,
                (SELECT COUNT(*)  FROM programs p WHERE p.donor_id = d.donor_id) AS program_count,
                (SELECT COUNT(*)  FROM projects p WHERE p.donor_id = d.donor_id) AS project_count,
                (SELECT SUM(budget) FROM programs p WHERE p.donor_id = d.donor_id) AS program_budget,
                (SELECT SUM(budget) FROM projects p WHERE p.donor_id = d.donor_id) AS project_budget
            FROM donors d
            ORDER BY d.donor_name
        ");
        while ($r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['donor_name'],      $r['donor_type'],    $r['contact_person'],
                $r['email'],           $r['phone'],          $r['website'],
                $r['program_count'],   $r['project_count'],
                number_format((float)($r['program_budget'] ?? 0)),
                number_format((float)($r['project_budget'] ?? 0)),
            ));
        }
        break;

    /* -- Hub members (hub-operations.php) ------------------------------ */
    case 'members':
        ims_fputcsv($out, [
            'Membership No.', 'Company / Name', 'Email', 'Member Type', 'Industry',
            'Status', 'Approval', 'Plan', 'Amount (UGX)', 'Start Date', 'End Date', 'Registered',
        ]);
        $res = $conn->query("SELECT m.*, u.full_name FROM members m LEFT JOIN users u ON u.user_id = m.user_id ORDER BY m.registration_date DESC");
        while ($res && $r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['membership_number'], $r['company_name'] ?: $r['full_name'], $r['email'],
                $r['member_type'], $r['industry'], $r['membership_status'], $r['approval_status'],
                $r['subscription_plan'], $r['subscription_amount'],
                $r['subscription_start_date'], $r['subscription_end_date'], $r['registration_date'],
            ));
        }
        break;

    /* -- Space bookings (hub-operations.php) --------------------------- */
    case 'bookings':
        ims_fputcsv($out, [
            'Booking ID', 'Member', 'Space Type', 'Space', 'Date', 'Start', 'End', 'Hours',
            'Attendees', 'Purpose', 'Amount (UGX)', 'Payment Status', 'Booking Status',
        ]);
        $res = $conn->query("
            SELECT sb.*, COALESCE(NULLIF(m.company_name, ''), u.full_name) AS member_name
            FROM space_bookings sb
            LEFT JOIN members m ON m.member_id = sb.member_id
            LEFT JOIN users u ON u.user_id = m.user_id
            ORDER BY sb.booking_date DESC, sb.start_time DESC
        ");
        while ($res && $r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['booking_id'], $r['member_name'], $r['space_type'], $r['space_name'],
                $r['booking_date'], $r['start_time'], $r['end_time'], $r['duration_hours'],
                $r['number_of_attendees'], $r['purpose'], $r['booking_amount'],
                $r['payment_status'], $r['booking_status'],
            ));
        }
        break;

    /* -- Subscription payments (hub-operations.php) -------------------- */
    case 'subscriptions':
        ims_fputcsv($out, [
            'Invoice', 'Member', 'Plan', 'Amount (UGX)', 'Period Start', 'Period End',
            'Status', 'Method', 'Reference', 'Payment Date', 'Created',
        ]);
        $res = $conn->query("
            SELECT sp.*, COALESCE(NULLIF(m.company_name, ''), u.full_name) AS member_name
            FROM subscription_payments sp
            LEFT JOIN members m ON m.member_id = sp.member_id
            LEFT JOIN users u ON u.user_id = m.user_id
            ORDER BY sp.created_at DESC
        ");
        while ($res && $r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['invoice_number'], $r['member_name'], $r['subscription_plan'], $r['amount'],
                $r['payment_period_start'], $r['payment_period_end'], $r['payment_status'],
                $r['payment_method'], $r['payment_reference'], $r['payment_date'], $r['created_at'],
            ));
        }
        break;

    /* -- Employees (employee-reports.php); no personal/ID/bank fields --- */
    case 'employee_report':
        ims_fputcsv($out, [
            'Name', 'Job Title', 'Department', 'Contract Type', 'Gender',
            'Start Date', 'Contract End', 'Company Email', 'Profile Status',
        ]);
        $dept_sql = $department_id ? "WHERE ed.department_id = {$department_id}" : '';
        $res = $conn->query("
            SELECT ed.*, d.department_name
            FROM employee_directory ed
            LEFT JOIN departments d ON d.department_id = ed.department_id
            {$dept_sql}
            ORDER BY ed.full_name
        ");
        while ($res && $r = $res->fetch_assoc()) {
            ims_fputcsv($out, row(
                $r['full_name'], $r['job_title'], $r['department_name'], $r['contract_type'],
                $r['gender'], $r['start_date'], $r['contract_end_date'], $r['company_email'], $r['status'],
            ));
        }
        break;
}

/* -- Finalise --------------------------------------------------------- */
fclose($out);

/* Audit log */
$scope_note = match(true) {
    $program_id > 0 => " (program_id={$program_id})",
    $project_id > 0 => " (project_id={$project_id})",
    default         => '',
};
log_action(
    $_SESSION['user_id'],
    'Export CSV',
    $type,
    null,
    "Exported {$type} data to CSV{$scope_note}"
);

exit();