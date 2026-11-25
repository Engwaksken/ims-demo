<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application status emails
|--------------------------------------------------------------------------
| Tells an applicant when their application becomes Shortlisted, Accepted or
| Rejected. Used by review-application-process.php (Manage Applications,
| View Application). Internal comments, scores and reviewer notes are never
| included.
*/

require_once __DIR__ . '/mail-function.php';

/** Statuses that email the applicant. */
const APPSTATUS_EMAIL_STATUSES = ['Shortlisted', 'Accepted', 'Rejected'];

if (!function_exists('appstatus_should_email')) {
    function appstatus_should_email(?string $oldStatus, string $newStatus): bool
    {
        return in_array($newStatus, APPSTATUS_EMAIL_STATUSES, true)
            && strcasecmp((string)$oldStatus, $newStatus) !== 0;
    }
}

if (!function_exists('appstatus_fetch')) {
    /** Applicant contact details for one application, or null. */
    function appstatus_fetch(mysqli $conn, int $applicationId): ?array
    {
        $stmt = $conn->prepare("
            SELECT a.application_id, a.startup_name,
                   COALESCE(NULLIF(TRIM(a.email), ''), u.email) AS email,
                   COALESCE(NULLIF(TRIM(a.contact_person), ''), u.full_name, a.startup_name) AS contact_name,
                   o.opportunity_title
            FROM applications a
            JOIN application_opportunities o ON o.opportunity_id = a.opportunity_id
            LEFT JOIN users u ON u.user_id = a.submitted_by
            WHERE a.application_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            error_log('[application-status-emails] fetch: ' . $conn->error);
            return null;
        }
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

if (!function_exists('appstatus_notify')) {
    /**
     * Email the applicant about their new status.
     * Returns true when sent, or an error string.
     */
    function appstatus_notify(mysqli $conn, int $applicationId, string $status): bool|string
    {
        if (!in_array($status, APPSTATUS_EMAIL_STATUSES, true)) {
            return 'No email for this status.';
        }

        $app = appstatus_fetch($conn, $applicationId);
        if (!$app) {
            return 'Application not found.';
        }

        $email = trim((string)($app['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'No valid email address on the application.';
        }

        $brand     = email_brand();
        $name      = trim((string)($app['contact_name'] ?? '')) ?: 'there';
        $startup   = trim((string)($app['startup_name'] ?? ''));
        $title     = trim((string)($app['opportunity_title'] ?? '')) ?: 'the opportunity';
        $reference = 'APP-' . str_pad((string)$applicationId, 6, '0', STR_PAD_LEFT);
        $portalUrl = email_app_url() . 'my-applications';
        $for       = email_h($title) . ($startup !== '' ? ' (' . email_h($startup) . ')' : '');

        [$subject, $heading, $body, $plain] = match ($status) {
            'Shortlisted' => [
                "You've been shortlisted: {$title}",
                'You have been shortlisted',
                "<p>Good news! Your application for <strong>{$for}</strong> has been <strong>shortlisted</strong>.
                    It has passed the first screening and will now go through a more detailed review.</p>
                 <p>This is not a final decision yet. We will contact you about the next steps, which may include
                    an interview or a request for more information. Please keep your contact details up to date.</p>",
                "Your application for {$title} has been shortlisted. It has passed the first screening and will now be reviewed in more detail. This is not a final decision yet; we will contact you about next steps.",
            ],
            'Accepted' => [
                "Congratulations! Your application has been accepted: {$title}",
                'Your application has been accepted',
                "<p>Congratulations! We are pleased to tell you that your application for <strong>{$for}</strong>
                    has been <strong>accepted</strong>.</p>
                 <p>A member of our team will contact you soon about onboarding and what happens next.</p>",
                "Congratulations! Your application for {$title} has been accepted. A member of our team will contact you soon about onboarding and next steps.",
            ],
            'Rejected' => [
                "Update on your application: {$title}",
                'Update on your application',
                "<p>Thank you for applying for <strong>{$for}</strong> and for the time you put into your application.</p>
                 <p>After careful consideration, we are sorry to tell you that your application was
                    <strong>not successful</strong> this time. We encourage you to apply for future opportunities.</p>",
                "Thank you for applying for {$title}. After careful consideration, your application was not successful this time. We encourage you to apply for future opportunities.",
            ],
        };

        $content = "
            <h3>" . email_h($heading) . "</h3>
            <p>Hi " . email_h($name) . ",</p>
            {$body}
            <div class='info-box'>
                <div><strong>Reference:</strong> {$reference}</div>
                <div><strong>Opportunity:</strong> " . email_h($title) . "</div>
                <div><strong>Status:</strong> " . email_h($status) . "</div>
            </div>
            <a href='" . email_h($portalUrl) . "' class='btn'>View My Applications</a>
            " . email_questions_line() . '
            ' . email_signoff();

        $alt = "Hi {$name},\n\n{$plain}\n\nReference: {$reference}\nMy Applications: {$portalUrl}\n\n" . $brand['name'];

        if (!function_exists('sendEmail')) {
            return 'Mail unavailable.';
        }

        try {
            $result = sendEmail($email, $subject, email_wrapper($content, 'Application update'), $alt);
        } catch (Throwable $e) {
            error_log('[application-status-emails] send: ' . $e->getMessage());
            return 'Email could not be sent.';
        }

        return $result === true ? true : (is_string($result) && $result !== '' ? $result : 'Email could not be sent.');
    }
}
