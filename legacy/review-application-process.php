<?php
session_start();
require_once 'includes/config.php';

/**
 * review-application-process.php
 * Secure rewrite:
 * - prepared statements everywhere
 * - consistent role checks
 * - safe activity + status logging
 * - supports add_review, change_status, reject, accept, update_notes, delete_review, bulk_status_change, send_email, assign_reviewer, withdraw
 */

if (!function_exists('sanitize_input')) {
    function sanitize_input($v) {
        return trim((string)$v);
    }
}

function failTo(string $url, string $msg): void {
    $_SESSION['error'] = $msg;
    header("Location: " . $url);
    exit();
}

function okTo(string $url, string $msg): void {
    $_SESSION['success'] = $msg;
    header("Location: " . $url);
    exit();
}

/* ============================================================
   ACCESS CONTROL
============================================================ */
if (!ims_allowed('applications.process_reviews', ['Administrator', 'Programs Lead', 'MEAL Lead'], 'Review applications and change their status (bulk actions, notes, emails, assign reviewer)')) {
    $_SESSION['error'] = "Unauthorized access. You don't have permission to review applications.";
    header("Location: login");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* ============================================================
   HELPERS (prepared)
============================================================ */
function log_activity(mysqli $conn, int $user_id, string $action, string $details): void {
    $action  = sanitize_input($action);
    $details = sanitize_input($details);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, description, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $stmt->bind_param("isss", $user_id, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

function log_status_change(mysqli $conn, int $application_id, ?string $old_status, string $new_status, int $user_id, string $comments = ''): void {
    $comments = sanitize_input($comments);

    $stmt = $conn->prepare("
        INSERT INTO application_status_history (application_id, old_status, new_status, changed_by, comments)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;

    $old = $old_status !== null ? sanitize_input($old_status) : null;
    $new = sanitize_input($new_status);
    $stmt->bind_param("issis", $application_id, $old, $new, $user_id, $comments);
    $stmt->execute();
    $stmt->close();
}

function calculate_overall_score($innovation, $market, $team, $financial): ?float {
    // Only compute if all 4 are provided (not null)
    if ($innovation !== null && $market !== null && $team !== null && $financial !== null) {
        return round(((float)$innovation + (float)$market + (float)$team + (float)$financial) / 4, 2);
    }
    return null;
}

function fetch_application(mysqli $conn, int $application_id): ?array {
    // Uses your newer schema (submitted_by)
    $sql = "
        SELECT
            a.*,
            a.startup_name,
            u.full_name AS applicant_name,
            u.email     AS applicant_email
        FROM applications a
        LEFT JOIN users u ON u.user_id = a.submitted_by
        WHERE a.application_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/* ============================================================
   INPUT
============================================================ */
$action = sanitize_input($_POST['action'] ?? ($_GET['action'] ?? ''));
$application_id = (int)($_POST['application_id'] ?? ($_GET['application_id'] ?? 0));

// GET links (change_status, withdraw, delete_draft, delete_review) change state:
// they must carry the session CSRF token. POSTs are covered globally.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action !== '') {
    csrf_protect(true);
}

$redirectBack = $_SERVER['HTTP_REFERER'] ?? 'application-opportunities';

/* ============================================================
   LOAD APPLICATION (if needed)
============================================================ */
$application = null;
if ($application_id > 0) {
    $application = fetch_application($conn, $application_id);
    if (!$application) {
        failTo('application-opportunities', "Application not found.");
    }
}

/* ============================================================
   ROUTER
============================================================ */
switch ($action) {

    /* ------------------------------------------------------------
       ADD REVIEW
       POST: action=add_review, application_id, review_type, innovation_score, market_potential_score, team_score, financial_viability_score, comments, recommendation
    ------------------------------------------------------------ */
    case 'add_review': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $review_type = sanitize_input($_POST['review_type'] ?? '');
        if ($review_type === '') failTo("view-application?id={$application_id}", "Review type is required.");

        $innovation_score         = ($_POST['innovation_score'] ?? '') !== '' ? (float)$_POST['innovation_score'] : null;
        $market_potential_score   = ($_POST['market_potential_score'] ?? '') !== '' ? (float)$_POST['market_potential_score'] : null;
        $team_score               = ($_POST['team_score'] ?? '') !== '' ? (float)$_POST['team_score'] : null;
        $financial_viability_score= ($_POST['financial_viability_score'] ?? '') !== '' ? (float)$_POST['financial_viability_score'] : null;

        $comments = sanitize_input($_POST['comments'] ?? '');
        $recommendation = ($_POST['recommendation'] ?? '') !== '' ? sanitize_input($_POST['recommendation']) : null;

        $overall_score = calculate_overall_score($innovation_score, $market_potential_score, $team_score, $financial_viability_score);

        // Insert review
        $stmt = $conn->prepare("
            INSERT INTO application_reviews (
                application_id, reviewer_id, review_type,
                innovation_score, market_potential_score, team_score, financial_viability_score,
                overall_score, comments, recommendation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);

        $stmt->bind_param(
            "iissddddss",
            $application_id,
            $user_id,
            $review_type,
            $innovation_score,
            $market_potential_score,
            $team_score,
            $financial_viability_score,
            $overall_score,
            $comments,
            $recommendation
        );

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            failTo("view-application?id={$application_id}", "Error submitting review: " . $err);
        }
        $stmt->close();

        // If status is Submitted -> Under Review
        if (($application['status'] ?? '') === 'Submitted') {
            $old_status = 'Submitted';
            $new_status = 'Under Review';

            $up = $conn->prepare("
                UPDATE applications
                SET status = ?, reviewed_by = ?, reviewed_at = NOW()
                WHERE application_id = ?
            ");
            if ($up) {
                $up->bind_param("sii", $new_status, $user_id, $application_id);
                $up->execute();
                $up->close();
            }

            log_status_change($conn, $application_id, $old_status, $new_status, $user_id, "Review added by reviewer");
        } else {
            // still update reviewed_by/at if you want
            $up = $conn->prepare("
                UPDATE applications
                SET reviewed_by = ?, reviewed_at = NOW()
                WHERE application_id = ?
            ");
            if ($up) {
                $up->bind_param("ii", $user_id, $application_id);
                $up->execute();
                $up->close();
            }
        }

        // Update application score (latest)
        if ($overall_score !== null) {
            $up2 = $conn->prepare("UPDATE applications SET score = ? WHERE application_id = ?");
            if ($up2) {
                $up2->bind_param("di", $overall_score, $application_id);
                $up2->execute();
                $up2->close();
            }
        }

        log_activity(
            $conn,
            $user_id,
            'Add Review',
            "Added {$review_type} for application #{$application_id} (" . ($application['startup_name'] ?? 'N/A') . ")"
        );

        okTo("view-application?id={$application_id}", "Review submitted successfully!");
    }

    /* ------------------------------------------------------------
       CHANGE STATUS (GET)
       ?action=change_status&application_id=ID&status=NewStatus
    ------------------------------------------------------------ */
    case 'change_status': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $new_status = sanitize_input($_GET['status'] ?? '');
        $old_status = $application['status'] ?? null;

        $valid_statuses = ['Draft','Submitted','Under Review','Shortlisted','Accepted','Rejected','Withdrawn'];
        if (!in_array($new_status, $valid_statuses, true)) {
            failTo("view-application?id={$application_id}", "Invalid status.");
        }

        $stmt = $conn->prepare("
            UPDATE applications
            SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE application_id = ?
        ");
        if (!$stmt) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);

        $stmt->bind_param("sii", $new_status, $user_id, $application_id);

        if ($stmt->execute()) {
            $stmt->close();
            log_status_change($conn, $application_id, $old_status, $new_status, $user_id, "Status changed from {$old_status} to {$new_status}");
            log_activity($conn, $user_id, 'Change Application Status', "Changed status of application #{$application_id} from {$old_status} to {$new_status}");
            okTo("view-application?id={$application_id}", "Application status changed to {$new_status} successfully!");
        }

        $err = $stmt->error;
        $stmt->close();
        failTo("view-application?id={$application_id}", "Error changing status: " . $err);
    }

    /* ------------------------------------------------------------
       REJECT (POST)
       action=reject, application_id, rejection_reason
    ------------------------------------------------------------ */
    case 'reject': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');
        if ($rejection_reason === '') {
            failTo("view-application?id={$application_id}", "Rejection reason is required.");
        }

        $old_status = $application['status'] ?? null;
        $new_status = 'Rejected';

        $stmt = $conn->prepare("
            UPDATE applications
            SET status = ?,
                reviewer_notes = ?,
                rejection_reason = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE application_id = ?
        ");
        if (!$stmt) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);

        $stmt->bind_param("sssii", $new_status, $rejection_reason, $rejection_reason, $user_id, $application_id);

        if ($stmt->execute()) {
            $stmt->close();

            log_status_change($conn, $application_id, $old_status, $new_status, $user_id, "Rejection reason: {$rejection_reason}");
            log_activity($conn, $user_id, 'Reject Application', "Rejected application #{$application_id} (" . ($application['startup_name'] ?? 'N/A') . ")");

            // Optional email hooks (only call if they exist)
            if (function_exists('send_rejection_email')) {
                @send_rejection_email(($application['email'] ?? $application['applicant_email'] ?? ''), ($application['startup_name'] ?? ''), $rejection_reason);
            }

            okTo("view-application?id={$application_id}", "Application rejected successfully.");
        }

        $err = $stmt->error;
        $stmt->close();
        failTo("view-application?id={$application_id}", "Error rejecting application: " . $err);
    }

    /* ------------------------------------------------------------
       ACCEPT (POST)
       action=accept, application_id, comments(optional)
    ------------------------------------------------------------ */
    case 'accept': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $old_status = $application['status'] ?? null;
        $new_status = 'Accepted';
        $comments   = sanitize_input($_POST['comments'] ?? '');

        $stmt = $conn->prepare("
            UPDATE applications
            SET status = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE application_id = ?
        ");
        if (!$stmt) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);

        $stmt->bind_param("sii", $new_status, $user_id, $application_id);

        if ($stmt->execute()) {
            $stmt->close();

            log_status_change($conn, $application_id, $old_status, $new_status, $user_id, $comments);
            log_activity($conn, $user_id, 'Accept Application', "Accepted application #{$application_id} (" . ($application['startup_name'] ?? 'N/A') . ")");

            if (function_exists('send_acceptance_email')) {
                @send_acceptance_email(($application['email'] ?? $application['applicant_email'] ?? ''), ($application['startup_name'] ?? ''));
            }

            okTo("view-application?id={$application_id}", "Application accepted successfully!");
        }

        $err = $stmt->error;
        $stmt->close();
        failTo("view-application?id={$application_id}", "Error accepting application: " . $err);
    }

    /* ------------------------------------------------------------
       UPDATE REVIEWER NOTES (POST)
       action=update_notes, application_id, reviewer_notes
    ------------------------------------------------------------ */
    case 'update_notes': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $reviewer_notes = sanitize_input($_POST['reviewer_notes'] ?? '');

        $stmt = $conn->prepare("
            UPDATE applications
            SET reviewer_notes = ?, reviewed_by = ?
            WHERE application_id = ?
        ");
        if (!$stmt) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);

        $stmt->bind_param("sii", $reviewer_notes, $user_id, $application_id);

        if ($stmt->execute()) {
            $stmt->close();
            log_activity($conn, $user_id, 'Update Reviewer Notes', "Updated notes for application #{$application_id} (" . ($application['startup_name'] ?? 'N/A') . ")");
            okTo("view-application?id={$application_id}", "Reviewer notes updated successfully!");
        }

        $err = $stmt->error;
        $stmt->close();
        failTo("view-application?id={$application_id}", "Error updating notes: " . $err);
    }

    /* ------------------------------------------------------------
       DELETE REVIEW (GET)
       ?action=delete_review&application_id=ID&review_id=RID
    ------------------------------------------------------------ */
    case 'delete_review': {
        $review_id = (int)($_GET['review_id'] ?? 0);
        if ($application_id <= 0 || $review_id <= 0) {
            failTo("application-opportunities", "Invalid request.");
        }

        $stmt = $conn->prepare("SELECT review_id, reviewer_id FROM application_reviews WHERE review_id = ? LIMIT 1");
        if (!$stmt) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);
        $stmt->bind_param("i", $review_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $review = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$review) failTo("view-application?id={$application_id}", "Review not found.");

        if ((int)$review['reviewer_id'] !== $user_id && !ims_allowed('applications.delete_any_review', ['Administrator'], 'Delete other reviewers\' application reviews')) {
            failTo("view-application?id={$application_id}", "You can only delete your own reviews.");
        }

        $del = $conn->prepare("DELETE FROM application_reviews WHERE review_id = ?");
        if (!$del) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);
        $del->bind_param("i", $review_id);

        if ($del->execute()) {
            $del->close();
            log_activity($conn, $user_id, 'Delete Review', "Deleted review #{$review_id} for application #{$application_id}");
            okTo("view-application?id={$application_id}", "Review deleted successfully!");
        }

        $err = $del->error;
        $del->close();
        failTo("view-application?id={$application_id}", "Error deleting review: " . $err);
    }

    /* ------------------------------------------------------------
       BULK STATUS CHANGE (POST)
       action=bulk_status_change, new_status, selected_applications[]
    ------------------------------------------------------------ */
    case 'bulk_status_change': {
        $new_status = sanitize_input($_POST['new_status'] ?? '');
        $selected_ids = $_POST['selected_applications'] ?? [];

        if ($new_status === '') failTo($redirectBack, "Please select a status.");
        if (!is_array($selected_ids) || empty($selected_ids)) failTo($redirectBack, "No applications selected.");

        $valid_statuses = ['Submitted','Under Review','Shortlisted','Accepted','Rejected','Withdrawn'];
        if (!in_array($new_status, $valid_statuses, true)) failTo($redirectBack, "Invalid status.");

        $ids = array_values(array_unique(array_map('intval', $selected_ids)));
        $ids = array_filter($ids, fn($x) => $x > 0);
        if (empty($ids)) failTo($redirectBack, "No valid applications selected.");

        // Fetch old statuses to log properly
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $sel = $conn->prepare("SELECT application_id, startup_name, status FROM applications WHERE application_id IN ($placeholders)");
        if (!$sel) failTo($redirectBack, "SQL error: " . $conn->error);
        $sel->bind_param($types, ...$ids);
        $sel->execute();
        $res = $sel->get_result();

        $apps = [];
        while ($res && ($row = $res->fetch_assoc())) $apps[] = $row;
        $sel->close();

        // Update
        $up = $conn->prepare("
            UPDATE applications
            SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE application_id IN ($placeholders)
        ");
        if (!$up) failTo($redirectBack, "SQL error: " . $conn->error);

        // bind: s i + ids...
        $bindTypes = "si" . $types;
        $params = array_merge([$new_status, $user_id], $ids);
        $up->bind_param($bindTypes, ...$params);

        if ($up->execute()) {
            $count = $up->affected_rows;
            $up->close();

            foreach ($apps as $a) {
                log_status_change($conn, (int)$a['application_id'], $a['status'] ?? null, $new_status, $user_id, "Bulk status change");
            }

            log_activity($conn, $user_id, 'Bulk Status Change', "Changed status of {$count} applications to {$new_status}");
            okTo($redirectBack, "{$count} application(s) status changed to {$new_status} successfully!");
        }

        $err = $up->error;
        $up->close();
        failTo($redirectBack, "Error changing status: " . $err);
    }

    case 'bulk_delete_applications': {
        if (($_SESSION['role'] ?? '') !== 'Administrator') {
            failTo($redirectBack, 'Only administrators can delete applications.');
        }
        $opportunity_id = (int)($_POST['opportunity_id'] ?? 0);
        $selected_ids = $_POST['selected_applications'] ?? [];
        if ($opportunity_id < 1 || !is_array($selected_ids) || !$selected_ids) {
            failTo($redirectBack, 'Select Draft or Submitted applications to delete.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids), static fn($id) => $id > 0)));
        if (!$ids) failTo($redirectBack, 'No valid applications selected.');

        // Scope deletion to the requested opportunity and Draft/Submitted statuses only.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT application_id FROM applications WHERE opportunity_id = ? AND status IN ('Submitted', 'Draft') AND application_id IN ($placeholders)");
        if (!$stmt) failTo($redirectBack, 'Could not validate selected applications.');
        $params = array_merge([$opportunity_id], $ids);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $deletable = [];
        while ($result && ($row = $result->fetch_assoc())) $deletable[] = (int)$row['application_id'];
        $stmt->close();
        if (!$deletable) failTo($redirectBack, 'No selected Draft or Submitted applications were found for this opportunity.');

        $deleteMarks = implode(',', array_fill(0, count($deletable), '?'));
        $deleteTypes = str_repeat('i', count($deletable));
        $conn->begin_transaction();
        try {
            // Remove dependent reviews first so deletion works even without cascading foreign keys.
            foreach (['application_reviews', 'application_review_scores'] as $table) {
                $exists = $conn->query("SHOW TABLES LIKE '{$table}'");
                if ($exists && $exists->num_rows > 0) {
                    $del = $conn->prepare("DELETE FROM {$table} WHERE application_id IN ($deleteMarks)");
                    if (!$del) throw new RuntimeException($conn->error);
                    $del->bind_param($deleteTypes, ...$deletable);
                    if (!$del->execute()) throw new RuntimeException($del->error);
                    $del->close();
                }
            }
            $del = $conn->prepare("DELETE FROM applications WHERE opportunity_id = ? AND status IN ('Submitted', 'Draft') AND application_id IN ($deleteMarks)");
            if (!$del) throw new RuntimeException($conn->error);
            $deleteParams = array_merge([$opportunity_id], $deletable);
            $del->bind_param('i' . $deleteTypes, ...$deleteParams);
            if (!$del->execute()) throw new RuntimeException($del->error);
            $count = $del->affected_rows;
            $del->close();
            $conn->commit();
            log_activity($conn, $user_id, 'Bulk Delete Applications', "Deleted {$count} Draft or Submitted applications for opportunity #{$opportunity_id}");
            okTo("manage-applications?opportunity_id={$opportunity_id}", "Deleted {$count} Draft or Submitted application(s).");
        } catch (Throwable $error) {
            $conn->rollback();
            failTo($redirectBack, 'Could not delete the selected applications.');
        }
    }

    /* ------------------------------------------------------------
       SEND EMAIL (POST)
       action=send_email, application_id, subject, message
    ------------------------------------------------------------ */
    case 'send_email': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $subject = sanitize_input($_POST['subject'] ?? '');
        $message = sanitize_input($_POST['message'] ?? '');

        if ($subject === '' || $message === '') {
            failTo("view-application?id={$application_id}", "Subject and message are required.");
        }

        $recipient = $application['email'] ?? $application['applicant_email'] ?? '';

        if (function_exists('send_email')) {
            @send_email($recipient, $subject, $message);
        }

        log_activity($conn, $user_id, 'Email Sent', "Sent email to applicant of application #{$application_id}: {$subject}");
        okTo("view-application?id={$application_id}", "Email sent successfully to {$recipient}");
    }

    /* ------------------------------------------------------------
       ASSIGN REVIEWER (POST)
       action=assign_reviewer, application_id, reviewer_id
    ------------------------------------------------------------ */
    case 'assign_reviewer': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $reviewer_to_assign = (int)($_POST['reviewer_id'] ?? 0);
        if ($reviewer_to_assign <= 0) failTo("view-application?id={$application_id}", "Invalid reviewer.");

        $st = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id = ? LIMIT 1");
        if (!$st) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);
        $st->bind_param("i", $reviewer_to_assign);
        $st->execute();
        $res = $st->get_result();
        $reviewer = $res ? $res->fetch_assoc() : null;
        $st->close();

        if (!$reviewer) failTo("view-application?id={$application_id}", "Reviewer not found.");

        $up = $conn->prepare("UPDATE applications SET reviewed_by = ? WHERE application_id = ?");
        if (!$up) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);
        $up->bind_param("ii", $reviewer_to_assign, $application_id);

        if ($up->execute()) {
            $up->close();
            log_activity($conn, $user_id, 'Assign Reviewer', "Assigned {$reviewer['full_name']} to review application #{$application_id}");
            okTo("view-application?id={$application_id}", "Reviewer assigned successfully!");
        }

        $err = $up->error;
        $up->close();
        failTo("view-application?id={$application_id}", "Error assigning reviewer: " . $err);
    }

    /* ------------------------------------------------------------
       WITHDRAW
       (NOTE: Usually applicant-only. Keep here if you really need it.)
       action=withdraw, application_id, reason(optional)
    ------------------------------------------------------------ */
    case 'withdraw': {
        if ($application_id <= 0) failTo('application-opportunities', "Invalid application.");

        $old_status = $application['status'] ?? null;
        $new_status = 'Withdrawn';
        $reason = sanitize_input($_POST['reason'] ?? 'Withdrawn');

        $up = $conn->prepare("UPDATE applications SET status = ? WHERE application_id = ?");
        if (!$up) failTo("view-application?id={$application_id}", "SQL error: " . $conn->error);
        $up->bind_param("si", $new_status, $application_id);

        if ($up->execute()) {
            $up->close();
            log_status_change($conn, $application_id, $old_status, $new_status, $user_id, $reason);
            log_activity($conn, $user_id, 'Withdraw Application', "Application #{$application_id} withdrawn");
            okTo("view-application?id={$application_id}", "Application withdrawn successfully.");
        }

        $err = $up->error;
        $up->close();
        failTo("view-application?id={$application_id}", "Error withdrawing application: " . $err);
    }

    default:
        failTo('application-opportunities', "Invalid action.");
}
