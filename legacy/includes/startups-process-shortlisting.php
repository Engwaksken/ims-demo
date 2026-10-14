<?php
declare(strict_types=1);

ob_start();
session_start();

require_once 'config.php';
require_once 'mail-function.php';

if (!ims_allowed('shortlisting.manage', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer'], 'Assign reviewers and change status on the shortlisting page')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ../startups-shortlisting');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['error'] = 'Database connection failed.';
    header('Location: ../startups-shortlisting');
    exit();
}

$conn->set_charset('utf8mb4');

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$action          = trim((string)($_POST['action'] ?? ''));
$filters         = trim((string)($_POST['redirect_filters'] ?? ''));
$redirect        = '../startups-shortlisting' . ($filters !== '' ? '?' . $filters : '');

/* -----------------------------------------------------------------------------
   HELPERS
----------------------------------------------------------------------------- */
function redirectNow(string $url): never
{
    header('Location: ' . $url);
    exit();
}

function safeText(?string $value): string
{
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

function fetchOneAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('fetchOneAssoc prepare failed: ' . $conn->error . ' | SQL: ' . $sql);
        return null;
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log('fetchOneAssoc execute failed: ' . $stmt->error . ' | SQL: ' . $sql);
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->free();
    }

    $stmt->close();
    return $row ?: null;
}

function fetchAllAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('fetchAllAssoc prepare failed: ' . $conn->error . ' | SQL: ' . $sql);
        return [];
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log('fetchAllAssoc execute failed: ' . $stmt->error . ' | SQL: ' . $sql);
        $stmt->close();
        return [];
    }

    $rows = [];
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();
    return $rows;
}

function applicationExists(mysqli $conn, int $application_id): bool
{
    $row = fetchOneAssoc(
        $conn,
        "SELECT application_id FROM applications WHERE application_id = ? LIMIT 1",
        'i',
        [$application_id]
    );

    return !empty($row);
}

function reviewTypeExists(mysqli $conn, int $review_type_id): ?array
{
    return fetchOneAssoc(
        $conn,
        "SELECT review_type_id, name FROM review_types WHERE review_type_id = ? AND is_active = 1 LIMIT 1",
        'i',
        [$review_type_id]
    );
}

function fetchApplicationDetail(mysqli $conn, int $application_id): ?array
{
    return fetchOneAssoc($conn, "
        SELECT
            a.*,
            rt.name AS review_type_name,
            u.user_id   AS applicant_user_id,
            u.email     AS applicant_email,
            u.full_name AS applicant_name,
            u.phone     AS applicant_phone,
            r.full_name AS reviewer_name,
            r.email     AS reviewer_email
        FROM applications a
        LEFT JOIN review_types rt ON rt.review_type_id = a.review_type_id
        LEFT JOIN users u ON u.user_id = a.submitted_by
        LEFT JOIN users r ON r.user_id = a.reviewed_by
        WHERE a.application_id = ?
        LIMIT 1
    ", 'i', [$application_id]);
}

function fetchApplicationsByIds(mysqli $conn, array $application_ids): array
{
    if (empty($application_ids)) {
        return [];
    }

    $application_ids = array_values(array_unique(array_map('intval', $application_ids)));
    $placeholders = implode(',', array_fill(0, count($application_ids), '?'));
    $types = str_repeat('i', count($application_ids));

    return fetchAllAssoc($conn, "
        SELECT
            a.application_id,
            a.startup_name,
            a.status,
            a.submitted_by,
            a.review_type_id,
            u.email AS applicant_email,
            u.full_name AS applicant_name
        FROM applications a
        LEFT JOIN users u ON u.user_id = a.submitted_by
        WHERE a.application_id IN ($placeholders)
        ORDER BY a.application_id DESC
    ", $types, $application_ids);
}

function fetchStaffEmails(mysqli $conn): array
{
    $rows = fetchAllAssoc($conn, "
        SELECT email
        FROM users
        WHERE role IN ('Administrator','MEAL Lead','Programs Lead')
          AND is_active = 1
          AND email IS NOT NULL
          AND TRIM(email) <> ''
    ");

    $emails = [];
    foreach ($rows as $row) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

function createNotification(mysqli $conn, int $user_id, string $title, string $message, string $type = 'info', int $ref_id = 0): void
{
    if ($user_id <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type, reference_id, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");

    if (!$stmt) {
        error_log('createNotification prepare failed: ' . $conn->error);
        return;
    }

    $stmt->bind_param('isssi', $user_id, $title, $message, $type, $ref_id);

    if (!$stmt->execute()) {
        error_log('createNotification execute failed: ' . $stmt->error);
    }

    $stmt->close();
}

function notifyStaff(mysqli $conn, string $title, string $message, string $type, int $ref_id): void
{
    $rows = fetchAllAssoc($conn, "
        SELECT user_id
        FROM users
        WHERE role IN ('Administrator','MEAL Lead','Programs Lead')
          AND is_active = 1
    ");

    foreach ($rows as $row) {
        createNotification($conn, (int)$row['user_id'], $title, $message, $type, $ref_id);
    }
}

function emailWrap(string $inner): string
{
    return "
    <div style='font-family:Arial,sans-serif;max-width:620px;margin:auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
      <div style='background:linear-gradient(135deg,#1a252f,#F47C20);padding:24px 30px;text-align:center;'>
        <h2 style='color:#fff;margin:0;font-size:20px;letter-spacing:.5px;'>Hive Colab Accelerator</h2>
      </div>
      <div style='padding:28px 30px;color:#2c3e50;line-height:1.65;font-size:14px;'>
        {$inner}
      </div>
      <div style='background:#f8f9fa;padding:14px 30px;text-align:center;font-size:11px;color:#aaa;'>
        &copy; " . date('Y') . " Hive Colab &mdash; This is an automated message, please do not reply.
      </div>
    </div>";
}

function statusBadge(string $status): string
{
    $map = [
        'Shortlisted' => '#2980b9',
        'Accepted'    => '#27ae60',
        'Rejected'    => '#e74c3c',
        'Pending'     => '#f39c12',
        'Withdrawn'   => '#95a5a6',
    ];

    $color = $map[$status] ?? '#7f8c8d';
    $safe  = safeText($status);

    return "<span style='background:{$color};color:#fff;padding:3px 12px;border-radius:20px;font-size:13px;font-weight:bold;'>{$safe}</span>";
}

function emailApplicantStatusChange(array $app, string $notes): void
{
    $to = trim((string)($app['applicant_email'] ?? ''));
    if ($to === '') {
        return;
    }

    $name    = safeText((string)($app['applicant_name'] ?? 'Applicant'));
    $startup = safeText((string)($app['startup_name'] ?? ''));
    $status  = (string)($app['status'] ?? 'Pending');
    $app_id  = (int)($app['application_id'] ?? 0);
    $badge   = statusBadge($status);

    $statusMsg = match ($status) {
        'Shortlisted' => "
            <p>Great news! Your application for <strong>{$startup}</strong> has been
            <strong>shortlisted</strong> for the next stage of our review process.</p>
            <p>Our team will be in touch shortly with next steps. Please ensure your
            contact details are up to date.</p>",
        'Accepted' => "
            <p>Congratulations! We are thrilled to inform you that your application
            for <strong>{$startup}</strong> has been <strong>accepted</strong>.</p>
            <p>A member of our team will contact you within 2–3 business days to
            discuss onboarding and next steps.</p>",
        'Rejected' => "
            <p>Thank you for your interest in Hive Colab and for the time you invested
            in your application for <strong>{$startup}</strong>.</p>
            <p>After careful consideration, we regret to inform you that your application
            was <strong>not successful</strong> at this time. We encourage you to apply
            again in future cohorts.</p>",
        default => "
            <p>The status of your application for <strong>{$startup}</strong>
            has been updated.</p>",
    };

    $notesSafe  = trim($notes) !== '' ? nl2br(safeText($notes)) : '';
    $notesBlock = $notesSafe !== ''
        ? "<div style='background:#f8f9fa;border-left:4px solid #F47C20;padding:12px 16px;margin:16px 0;border-radius:0 6px 6px 0;'>
             <strong>Reviewer Note:</strong><br>{$notesSafe}
           </div>"
        : '';

    $inner = "
        <p>Dear <strong>{$name}</strong>,</p>
        <p>Your application status has been updated:</p>
        <p style='margin:14px 0;'>{$badge}</p>
        {$statusMsg}
        {$notesBlock}
        <p style='margin-top:20px;font-size:12px;color:#888;'>
          Application ID: <strong>#{$app_id}</strong>
        </p>
        <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
        <p style='font-size:13px;'>Best regards,<br><strong>Hive Colab Team</strong></p>";

    $subject = match ($status) {
        'Shortlisted' => "You've Been Shortlisted - {$startup}",
        'Accepted'    => "Congratulations! Application Accepted - {$startup}",
        'Rejected'    => "Application Update - {$startup}",
        default       => "Application Status Update - {$startup}",
    };

    sendEmail($to, $subject, emailWrap($inner));
}

function emailStaffStatusChange(array $app, string $notes, array $staffEmails): void
{
    if (empty($staffEmails)) {
        return;
    }

    $startup   = safeText((string)($app['startup_name'] ?? ''));
    $applicant = safeText((string)($app['applicant_name'] ?? 'Unknown'));
    $status    = (string)($app['status'] ?? 'Pending');
    $app_id    = (int)($app['application_id'] ?? 0);
    $reviewer  = safeText((string)($app['reviewer_name'] ?? ($_SESSION['full_name'] ?? 'Staff')));
    $badge     = statusBadge($status);
    $date      = date('d M Y, H:i');

    $notesBlock = trim($notes) !== ''
        ? "<p><strong>Notes:</strong> " . safeText($notes) . "</p>"
        : '';

    $inner = "
        <p>Hi Team,</p>
        <p>An application status has just been updated:</p>
        <table style='width:100%;border-collapse:collapse;font-size:13px;margin:14px 0;'>
          <tr style='background:#f8f9fa;'>
            <td style='padding:8px 12px;font-weight:bold;width:38%;'>Application ID</td>
            <td style='padding:8px 12px;'>#{$app_id}</td>
          </tr>
          <tr>
            <td style='padding:8px 12px;font-weight:bold;'>Startup</td>
            <td style='padding:8px 12px;'>{$startup}</td>
          </tr>
          <tr style='background:#f8f9fa;'>
            <td style='padding:8px 12px;font-weight:bold;'>Applicant</td>
            <td style='padding:8px 12px;'>{$applicant}</td>
          </tr>
          <tr>
            <td style='padding:8px 12px;font-weight:bold;'>New Status</td>
            <td style='padding:8px 12px;'>{$badge}</td>
          </tr>
          <tr style='background:#f8f9fa;'>
            <td style='padding:8px 12px;font-weight:bold;'>Updated By</td>
            <td style='padding:8px 12px;'>{$reviewer}</td>
          </tr>
          <tr>
            <td style='padding:8px 12px;font-weight:bold;'>Date &amp; Time</td>
            <td style='padding:8px 12px;'>{$date}</td>
          </tr>
        </table>
        {$notesBlock}
        <p style='font-size:12px;color:#888;'>Log in to the admin panel to view the full application.</p>";

    $subject = "[Hive Colab] Application #{$app_id} - {$startup} - {$status}";

    $primary = array_shift($staffEmails);
    if (!$primary) {
        return;
    }

    sendEmail($primary, $subject, emailWrap($inner), null, [], $staffEmails);
}

function emailStaffBatchUpdate(int $count, string $status, array $staffEmails): void
{
    if ($count <= 0 || empty($staffEmails)) {
        return;
    }

    $badge = statusBadge($status);
    $date  = date('d M Y, H:i');

    $inner = "
        <p>Hi Team,</p>
        <p>A batch status update was just performed:</p>
        <table style='width:100%;border-collapse:collapse;font-size:13px;margin:14px 0;'>
          <tr style='background:#f8f9fa;'>
            <td style='padding:8px 12px;font-weight:bold;width:40%;'>Applications Updated</td>
            <td style='padding:8px 12px;'><strong>{$count}</strong></td>
          </tr>
          <tr>
            <td style='padding:8px 12px;font-weight:bold;'>New Status</td>
            <td style='padding:8px 12px;'>{$badge}</td>
          </tr>
          <tr style='background:#f8f9fa;'>
            <td style='padding:8px 12px;font-weight:bold;'>Date &amp; Time</td>
            <td style='padding:8px 12px;'>{$date}</td>
          </tr>
        </table>
        <p style='font-size:12px;color:#888;'>Log in to the admin panel for details.</p>";

    $subject = "[Hive Colab] Batch Update: {$count} application(s) - {$status}";
    $primary = array_shift($staffEmails);
    if (!$primary) {
        return;
    }

    sendEmail($primary, $subject, emailWrap($inner), null, [], $staffEmails);
}

function parseSelectedEntries(array $entries): array
{
    $parsed = [];

    foreach ($entries as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            continue;
        }

        $application_id = 0;
        $review_type_id = 0;

        if (strpos($raw, ':') !== false) {
            [$appId, $rtId] = array_pad(explode(':', $raw, 2), 2, '');
            $application_id = (int)$appId;
            $review_type_id = (int)$rtId;
        } else {
            $application_id = (int)$raw;
        }

        if ($application_id > 0) {
            $parsed[$application_id . ':' . $review_type_id] = [
                'application_id' => $application_id,
                'review_type_id' => $review_type_id,
            ];
        }
    }

    return array_values($parsed);
}

function parseDateOrNull(string $date): ?string
{
    $date = trim($date);
    if ($date === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $date);
        if ($dt && $dt->format($format) === $date) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function appendReviewerNotes(string $existing, string $newNote, int $review_type_id = 0): string
{
    $newNote = trim($newNote);
    if ($newNote === '') {
        return $existing;
    }

    $prefix = '[' . date('d M Y') . '] ';
    if ($review_type_id > 0) {
        $prefix .= '[Review Type #' . $review_type_id . '] ';
    }

    $full = $prefix . $newNote;
    $existing = trim($existing);

    return $existing === '' ? $full : ($existing . "\n" . $full);
}

/* -----------------------------------------------------------------------------
   ACTION: SINGLE STATUS UPDATE
----------------------------------------------------------------------------- */
if ($action === 'update_status') {
    $application_id = (int)($_POST['application_id'] ?? 0);
    $review_type_id = (int)($_POST['review_type_id'] ?? 0);
    $new_status     = trim((string)($_POST['new_status'] ?? ''));
    $reviewer_notes = trim((string)($_POST['reviewer_notes'] ?? ''));
    $reminder_input = trim((string)($_POST['reminder_date'] ?? ''));

    $valid_statuses = ['Pending', 'Shortlisted', 'Accepted', 'Rejected', 'Withdrawn'];

    if ($application_id <= 0 || !in_array($new_status, $valid_statuses, true)) {
        $_SESSION['error'] = 'Invalid status update.';
        redirectNow($redirect);
    }

    if (!applicationExists($conn, $application_id)) {
        $_SESSION['error'] = 'Application not found.';
        redirectNow($redirect);
    }

    $existingApp = fetchOneAssoc($conn, "
        SELECT reviewer_notes, review_type_id
        FROM applications
        WHERE application_id = ?
        LIMIT 1
    ", 'i', [$application_id]);

    $existingNotes = (string)($existingApp['reviewer_notes'] ?? '');
    $currentReviewTypeId = (int)($existingApp['review_type_id'] ?? 0);
    $effectiveReviewTypeId = $review_type_id > 0 ? $review_type_id : $currentReviewTypeId;

    $finalNotes = appendReviewerNotes($existingNotes, $reviewer_notes, $effectiveReviewTypeId);
    $reminder_sql_val = parseDateOrNull($reminder_input);

    if ($reminder_input !== '' && $reminder_sql_val === null) {
        $_SESSION['error'] = 'Invalid reminder date.';
        redirectNow($redirect);
    }

    if ($reminder_sql_val !== null) {
        $stmt = $conn->prepare("
            UPDATE applications
            SET status = ?,
                reviewer_notes = ?,
                reviewed_by = ?,
                reviewed_at = NOW(),
                updated_at = NOW(),
                reminder_date = ?
            WHERE application_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            error_log('update_status prepare failed: ' . $conn->error);
            $_SESSION['error'] = 'System error. Please try again.';
            redirectNow($redirect);
        }

        $stmt->bind_param(
            'ssisi',
            $new_status,
            $finalNotes,
            $current_user_id,
            $reminder_sql_val,
            $application_id
        );
    } else {
        $stmt = $conn->prepare("
            UPDATE applications
            SET status = ?,
                reviewer_notes = ?,
                reviewed_by = ?,
                reviewed_at = NOW(),
                updated_at = NOW()
            WHERE application_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            error_log('update_status prepare failed: ' . $conn->error);
            $_SESSION['error'] = 'System error. Please try again.';
            redirectNow($redirect);
        }

        $stmt->bind_param(
            'ssii',
            $new_status,
            $finalNotes,
            $current_user_id,
            $application_id
        );
    }

    if (!$stmt->execute()) {
        error_log('update_status execute failed: ' . $stmt->error);
        $_SESSION['error'] = 'Failed to update status.';
        $stmt->close();
        redirectNow($redirect);
    }

    $stmt->close();

    $app = fetchApplicationDetail($conn, $application_id);
    $startup_name = (string)($app['startup_name'] ?? ('Application #' . $application_id));

    notifyStaff(
        $conn,
        'Application #' . $application_id . ' Updated',
        '"' . $startup_name . '" moved to ' . $new_status . ($reviewer_notes !== '' ? ' - Notes: ' . $reviewer_notes : ''),
        'info',
        $application_id
    );

    if ($app && in_array($new_status, ['Shortlisted', 'Accepted', 'Rejected'], true)) {
        $app['status'] = $new_status;
        emailApplicantStatusChange($app, $reviewer_notes);
        emailStaffStatusChange($app, $reviewer_notes, fetchStaffEmails($conn));
    }

    $reminderNote = $reminder_sql_val !== null
        ? ' (reminder set for ' . date('d M Y', strtotime($reminder_sql_val)) . ')'
        : '';

    $_SESSION['success'] = '"' . $startup_name . '" moved to ' . $new_status . '.' . $reminderNote;
    redirectNow($redirect);
}

/* -----------------------------------------------------------------------------
   ACTION: BATCH STATUS UPDATE
----------------------------------------------------------------------------- */
if ($action === 'batch_update') {
    $selected_entries = $_POST['selected_entries'] ?? [];
    $new_status       = trim((string)($_POST['batch_status'] ?? ''));

    $valid_statuses = ['Pending', 'Shortlisted', 'Accepted', 'Rejected'];

    if (!is_array($selected_entries) || empty($selected_entries) || !in_array($new_status, $valid_statuses, true)) {
        $_SESSION['error'] = 'Please select applications and a valid status.';
        redirectNow($redirect);
    }

    $entries = parseSelectedEntries($selected_entries);
    if (empty($entries)) {
        $_SESSION['error'] = 'No valid entries selected.';
        redirectNow($redirect);
    }

    $application_ids = array_values(array_unique(array_map(
        static fn(array $entry): int => (int)$entry['application_id'],
        $entries
    )));

    if (empty($application_ids)) {
        $_SESSION['error'] = 'No valid applications selected.';
        redirectNow($redirect);
    }

    $placeholders = implode(',', array_fill(0, count($application_ids), '?'));
    $id_types     = str_repeat('i', count($application_ids));

    $stmt = $conn->prepare("
        UPDATE applications
        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
        WHERE application_id IN ($placeholders)
    ");

    if (!$stmt) {
        error_log('batch_update prepare failed: ' . $conn->error);
        $_SESSION['error'] = 'System error. Please try again.';
        redirectNow($redirect);
    }

    $bind_types = 'si' . $id_types;
    $bind_values = [$bind_types, $new_status, $current_user_id, ...$application_ids];
    $refs = [];
    foreach ($bind_values as $k => $v) {
        $refs[$k] = &$bind_values[$k];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);

    if (!$stmt->execute()) {
        error_log('batch_update execute failed: ' . $stmt->error);
        $_SESSION['error'] = 'Failed to batch update.';
        $stmt->close();
        redirectNow($redirect);
    }

    $updated = (int)$stmt->affected_rows;
    $stmt->close();

    $apps = fetchApplicationsByIds($conn, $application_ids);
    $staffEmails = fetchStaffEmails($conn);

    foreach ($apps as $app) {
        $appId = (int)($app['application_id'] ?? 0);
        $startup = (string)($app['startup_name'] ?? ('Application #' . $appId));
        $applicantId = (int)($app['submitted_by'] ?? 0);

        if ($applicantId > 0) {
            $notif_type = match ($new_status) {
                'Accepted'    => 'success',
                'Rejected'    => 'danger',
                'Shortlisted' => 'info',
                default       => 'warning',
            };

            createNotification(
                $conn,
                $applicantId,
                'Application Status: ' . $new_status,
                'Your application for "' . $startup . '" status changed to ' . $new_status . '.',
                $notif_type,
                $appId
            );
        }

        if (in_array($new_status, ['Shortlisted', 'Accepted', 'Rejected'], true)) {
            $app['status'] = $new_status;
            emailApplicantStatusChange($app, '');
        }
    }

    notifyStaff(
        $conn,
        'Batch Update: ' . count($application_ids) . ' Applications - ' . $new_status,
        count($application_ids) . ' application(s) bulk-updated to ' . $new_status . ' by user #' . $current_user_id . '.',
        'info',
        0
    );

    emailStaffBatchUpdate(count($application_ids), $new_status, $staffEmails);

    $_SESSION['success'] = count($application_ids) . ' application(s) moved to ' . $new_status . '.';
    redirectNow($redirect);
}


if ($action === 'assign_review_type') {
    $application_id = (int)($_POST['application_id'] ?? 0);
    $review_type_id = (int)($_POST['review_type_id'] ?? 0);

    if ($application_id <= 0 || $review_type_id <= 0) {
        $_SESSION['error'] = 'Please select a valid review type.';
        redirectNow($redirect);
    }

    $reviewType = reviewTypeExists($conn, $review_type_id);
    if (!$reviewType) {
        $_SESSION['error'] = 'Selected review type does not exist or is inactive.';
        redirectNow($redirect);
    }

    $type_name = (string)($reviewType['name'] ?? 'Selected Review Type');

    $appBefore = fetchOneAssoc($conn, "
        SELECT application_id, startup_name, review_type_id
        FROM applications
        WHERE application_id = ?
        LIMIT 1
    ", 'i', [$application_id]);

    if (!$appBefore) {
        $_SESSION['error'] = 'Application not found.';
        redirectNow($redirect);
    }

    $old_review_type_id = (int)($appBefore['review_type_id'] ?? 0);
    $startup_name = (string)($appBefore['startup_name'] ?? ('Application #' . $application_id));

    if ($old_review_type_id === $review_type_id) {
        $_SESSION['success'] = 'Review type "' . $type_name . '" is already assigned to "' . $startup_name . '".';
        redirectNow($redirect);
    }

    $conn->begin_transaction();

    try {
        $affected_application = 0;

        // 1. Update only the CURRENT stage on applications
        $updApplication = $conn->prepare("
            UPDATE applications
            SET review_type_id = ?,
                reviewed_by = ?,
                reviewed_at = NOW(),
                updated_at = NOW()
            WHERE application_id = ?
            LIMIT 1
        ");
        if (!$updApplication) {
            throw new RuntimeException('Failed to prepare application update.');
        }

        $updApplication->bind_param('iii', $review_type_id, $current_user_id, $application_id);
        if (!$updApplication->execute()) {
            throw new RuntimeException('Failed to update application review type: ' . $updApplication->error);
        }
        $affected_application = (int)$updApplication->affected_rows;
        $updApplication->close();

       
        $conn->commit();

        notifyStaff(
            $conn,
            'Review Type Assigned - Application #' . $application_id,
            'Review type "' . $type_name . '" assigned for application #' . $application_id .
            ' (' . $startup_name . '). Current stage updated successfully.',
            'info',
            $application_id
        );

        $_SESSION['success'] = 'Review type "' . $type_name . '" assigned successfully.';
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('assign_review_type failed: ' . $e->getMessage());
        $_SESSION['error'] = 'Failed to assign review type.';
    }

    redirectNow($redirect);
}

$_SESSION['error'] = 'Unknown action "' . safeText($action) . '".';
redirectNow($redirect);