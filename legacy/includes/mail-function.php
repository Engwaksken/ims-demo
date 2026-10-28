<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;


const EMAIL_DEBUG_MODE = false;


if (!function_exists('getEmailSettings')) {
    function getEmailSettings(): ?array
    {
        try {
            $database = db_connect();
        } catch (Throwable $exception) {
            if (EMAIL_DEBUG_MODE) {
                error_log(
                    'Email settings database connection failed: '
                    . $exception->getMessage()
                );
            }

            return null;
        }

        $sql = "
            SELECT
                id,
                smtp_host,
                smtp_port,
                smtp_user,
                smtp_password,
                smtp_secure,
                from_email,
                from_name,
                status
            FROM email_settings
            WHERE status = 1
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmt = $database->prepare($sql);

        if (!$stmt) {
            if (EMAIL_DEBUG_MODE) {
                error_log(
                    'Email settings prepare error: '
                    . $database->error
                );
            }

            return null;
        }

        if (!$stmt->execute()) {
            if (EMAIL_DEBUG_MODE) {
                error_log(
                    'Email settings execute error: '
                    . $stmt->error
                );
            }

            $stmt->close();

            return null;
        }

        $result = $stmt->get_result();
        $settings = $result->fetch_assoc() ?: null;

        $stmt->close();

        if (!$settings) {
            if (EMAIL_DEBUG_MODE) {
                error_log(
                    'Email settings error: '
                    . 'No active email configuration found.'
                );
            }

            return null;
        }

  

        return $settings;
    }
}


if (!function_exists('sendEmail')) {
    function sendEmail(
        string|array $to,
        string $subject,
        string $body,
        ?string $altBody = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        array $inlineImages = []
    ): bool|string {
        $settings = getEmailSettings();

        if (!$settings) {
            $error = 'No active email settings were found.';

            if (EMAIL_DEBUG_MODE) {
                error_log('sendEmail: ' . $error);
            }

            return $error;
        }


        $smtpHost = trim(
            (string) ($settings['smtp_host'] ?? '')
        );

        $smtpPort = (int) (
            $settings['smtp_port'] ?? 0
        );

        $smtpUser = trim(
            (string) ($settings['smtp_user'] ?? '')
        );

        $smtpPassword = trim(
            (function_exists('ims_decrypt') ? ims_decrypt((string) ($settings['smtp_password'] ?? '')) : (string) ($settings['smtp_password'] ?? ''))
        );

        $smtpSecure = strtolower(
            trim((string) ($settings['smtp_secure'] ?? 'tls'))
        );

        $fromEmail = trim(
            (string) ($settings['from_email'] ?? '')
        );

        $defaultSiteName = (string)($GLOBALS['IMS_SITE_BRANDING']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : 'Website'));

        $fromName = trim(
            (string) ($settings['from_name'] ?? $defaultSiteName)
        );

     

        if ($smtpHost === '') {
            return 'SMTP host is missing.';
        }

        if ($smtpPort <= 0 || $smtpPort > 65535) {
            return 'SMTP port is invalid.';
        }

        if ($smtpUser === '') {
            return 'SMTP username is missing.';
        }

        if ($smtpPassword === '') {
            return 'SMTP password is missing.';
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return 'Sender email address is invalid.';
        }

   

        if (
            str_contains(
                strtolower($smtpHost),
                'gmail.com'
            )
        ) {
            $smtpPassword = str_replace(
                ' ',
                '',
                $smtpPassword
            );
        }

        $mail = new PHPMailer(true);

        try {
           

            $mail->isSMTP();

            $mail->Host       = $smtpHost;
            $mail->Port       = $smtpPort;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPassword;
            $mail->Timeout    = 30;
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;
            $mail->Encoding   = 'base64';


            if (
                in_array(
                    $smtpSecure,
                    ['tls', 'starttls'],
                    true
                )
            ) {
                $mail->SMTPSecure =
                    PHPMailer::ENCRYPTION_STARTTLS;

                $mail->SMTPAutoTLS = true;
            } elseif (
                in_array(
                    $smtpSecure,
                    ['ssl', 'smtps'],
                    true
                )
            ) {
                $mail->SMTPSecure =
                    PHPMailer::ENCRYPTION_SMTPS;

                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

        

            if (EMAIL_DEBUG_MODE) {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;

                $mail->Debugoutput = static function (
                    string $message,
                    int $level
                ): void {
                    error_log(
                        'PHPMailer SMTP [' . $level . ']: '
                        . trim($message)
                    );
                };
            } else {
                $mail->SMTPDebug = SMTP::DEBUG_OFF;
                $mail->Debugoutput = 'error_log';
            }


            $mail->setFrom(
                $fromEmail,
                $fromName !== ''
                    ? $fromName
                    : $defaultSiteName
            );

         

            $recipients = is_array($to)
                ? $to
                : [$to];

            foreach ($recipients as $recipient) {
                $recipient = trim((string) $recipient);

                if (
                    $recipient !== ''
                    && filter_var(
                        $recipient,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $mail->addAddress($recipient);
                }
            }

            if (count($mail->getToAddresses()) === 0) {
                return 'No valid recipient email address was provided.';
            }


            foreach ($cc as $recipient) {
                $recipient = trim((string) $recipient);

                if (
                    $recipient !== ''
                    && filter_var(
                        $recipient,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $mail->addCC($recipient);
                }
            }

         
            foreach ($bcc as $recipient) {
                $recipient = trim((string) $recipient);

                if (
                    $recipient !== ''
                    && filter_var(
                        $recipient,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $mail->addBCC($recipient);
                }
            }

       

            foreach ($attachments as $file) {
                $file = trim((string) $file);

                if (
                    $file !== ''
                    && is_file($file)
                    && is_readable($file)
                ) {
                    $mail->addAttachment($file);
                }
            }

       

            foreach ($inlineImages as $image) {
                if (
                    !is_array($image)
                    || empty($image['path'])
                    || empty($image['cid'])
                ) {
                    continue;
                }

                $path = trim((string) $image['path']);
                $cid  = trim((string) $image['cid']);

                if (
                    $path !== ''
                    && $cid !== ''
                    && is_file($path)
                    && is_readable($path)
                ) {
                    $mail->addEmbeddedImage(
                        $path,
                        $cid
                    );
                }
            }


            $mail->isHTML(true);

            $mail->Subject = trim($subject);
            $mail->Body    = $body;

            if (
                $altBody !== null
                && trim($altBody) !== ''
            ) {
                $mail->AltBody = trim($altBody);
            } else {
                $plainText = html_entity_decode(
                    strip_tags($body),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );

                $mail->AltBody = trim(
                    preg_replace(
                        '/[ \t]+/',
                        ' ',
                        $plainText
                    ) ?? $plainText
                );
            }

        

            $mail->send();

            return true;
        } catch (Throwable $exception) {
            $error = trim((string) $mail->ErrorInfo);

            if ($error === '') {
                $error = trim($exception->getMessage());
            }

            if ($error === '') {
                $error = 'The email could not be sent.';
            }

       

            error_log(
                'Email sending failed'
                . ' | Subject: ' . $subject
                . ' | Host: ' . $smtpHost
                . ' | Port: ' . $smtpPort
                . ' | Security: ' . $smtpSecure
                . ' | From: ' . $fromEmail
                . ' | Error: ' . $error
            );

            return $error;
        }
    }
}

if (!function_exists('sendMail')) {
    /**
     * sendMail($email, $name, $subject, $html) used by the procurement module.
     * It was never defined, so every procurement email call stopped the page
     * with a fatal error. Sends through sendEmail(); true on success.
     */
    function sendMail(string $to, string $name, string $subject, string $body): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $result = sendEmail($to, $subject, $body);
        } catch (Throwable $e) {
            error_log('sendMail to ' . $to . ' failed: ' . $e->getMessage());
            return false;
        }

        if ($result !== true) {
            error_log('sendMail to ' . $to . ' failed: ' . (is_string($result) ? $result : 'unknown error'));
        }

        return $result === true;
    }
}

/** Branding for emails: Settings > General (name, logo, contact) and Appearance (colours, font). */
function email_brand(): array
{
    if (function_exists('ims_brand')) {
        return ims_brand();
    }
    $name = defined('SITE_NAME') ? (string)SITE_NAME : 'Information Management System';
    return [
        'name' => $name, 'logo_url' => '', 'email' => '', 'phone' => '', 'address' => '', 'website' => '', 'website_label' => '',
        'primary' => '#ea580c', 'primary_dark' => '#c2410c', 'primary_light' => '#fdf1ea', 'secondary' => '#f97316',
        'font' => "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif",
    ];
}

function email_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** "Best regards, <Organisation> Team" sign-off; $team names a unit, e.g. "Procurement Team". */
function email_signoff(string $team = 'Team'): string
{
    return '<p>Best regards,<br><strong>' . email_h(email_brand()['name'] . ' ' . $team) . '</strong></p>';
}

/** Absolute URL of a page in this system, e.g. email_app_url() . 'login'. */
function email_app_url(): string
{
    return rtrim(defined('APP_URL') ? (string)APP_URL : '', '/') . '/';
}

/** "If you have any questions please email ..." paragraph, or '' when no contact email is set. */
function email_questions_line(): string
{
    $link = email_contact_link();
    return $link === '' ? '' : '<p>If you have any questions please email ' . $link . '.</p>';
}

/** The organisation contact email as a mailto link, or '' when none is configured. */
function email_contact_link(): string
{
    $email = function_exists('ims_brand_email') ? ims_brand_email() : '';
    return $email === '' ? '' : "<a href='mailto:" . email_h($email) . "'>" . email_h($email) . '</a>';
}

/**
 * Wrap email content in the branded layout. Classes available to $content:
 * info-box, cred-box, password, code-box/code, warning, btn, reminder-badge.
 */
function email_wrapper(string $content, string $subtitle = ''): string {
    $brand = email_brand();
    $primary = $brand['primary'];
    $primaryDark = $brand['primary_dark'];
    $primaryLight = $brand['primary_light'];
    $secondary = $brand['secondary'];
    $font = $brand['font'];
    $name = email_h($brand['name']);
    $logo = $brand['logo_url'] !== ''
        ? "<img src='" . email_h($brand['logo_url']) . "' alt='" . $name . "' style='max-height:48px;max-width:200px;margin:0 auto 12px;display:block;background:#ffffff;border-radius:8px;padding:6px 10px;'>"
        : '';
    $subtitleHtml = $subtitle !== '' ? "<p class='subtitle'>" . email_h($subtitle) . '</p>' : '';
    $contact = [];
    if ($brand['email'] !== '') $contact[] = "<a href='mailto:" . email_h($brand['email']) . "'>" . email_h($brand['email']) . '</a>';
    if ($brand['phone'] !== '') $contact[] = email_h($brand['phone']);
    if ($brand['website'] !== '') $contact[] = "<a href='" . email_h($brand['website']) . "'>" . email_h($brand['website_label']) . '</a>';
    $contactHtml = $contact === [] ? '' : implode(' &middot; ', $contact) . '<br>';
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body        { margin:0; padding:0; background:#f5f6fa; font-family:$font; }
            .outer      { padding:30px 15px; }
            .card       { max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;
                          box-shadow:0 4px 15px rgba(0,0,0,0.08); }
            .header     { background-color:$primary; background-image:linear-gradient(135deg,$primary 0%,$secondary 100%); padding:30px 35px; text-align:center; }
            .header h1  { color:#ffffff; margin:0; font-size:22px; font-weight:700; letter-spacing:0.5px; }
            .header .subtitle { color:#ffffff; margin:8px 0 0; font-size:14px; opacity:0.9; }
            .body       { padding:35px; color:#2c3e50; line-height:1.7; font-size:15px; }
            .body p     { margin:0 0 16px; }
            .body h3    { color:$primary; margin:0 0 12px; font-size:18px; }
            .body a     { color:$primaryDark; }
            .info-box   { background:#f8f9fa; border-left:4px solid $primary; border-radius:6px;
                          padding:15px 18px; margin:20px 0; font-size:14px; }
            .info-box strong { display:inline-block; min-width:150px; color:#7f8c8d; }
            .cred-box   { background:$primaryLight; border:2px solid $secondary; border-radius:8px;
                          padding:20px 22px; margin:20px 0; }
            .cred-box h4 { margin:0 0 12px; color:$primaryDark; font-size:16px; }
            .code-box   { margin:24px 0; padding:20px; text-align:center; background:$primaryLight;
                          border:2px dashed $primary; border-radius:8px; }
            .code       { color:$primaryDark; font-size:32px; font-weight:bold; letter-spacing:5px; }
            .password   { font-family:'Courier New',Courier,monospace; font-size:22px; font-weight:700;
                          color:#c0392b; letter-spacing:3px; background:#fff; border:2px dashed #e74c3c;
                          padding:8px 16px; border-radius:6px; display:inline-block; margin:6px 0; }
            .warning    { background:#fdecea; border-left:4px solid #e74c3c; border-radius:4px;
                          padding:10px 14px; margin-top:14px; font-size:13px; color:#7d1a1a; }
            .btn        { display:inline-block; background-color:$primary; background-image:linear-gradient(135deg,$primary,$secondary);
                          color:#ffffff !important; text-decoration:none; padding:13px 28px;
                          border-radius:8px; font-weight:700; font-size:15px; margin:20px 0; }
            .footer     { background:#f8f9fa; padding:20px 35px; text-align:center;
                          font-size:12px; color:#95a5a6; border-top:1px solid #ecf0f1; }
            .footer a   { color:#7f8c8d; }
            .reminder-badge { display:inline-block; background:#FFF3CD; color:#8a6d1a; font-weight:700;
                          font-size:12px; padding:4px 10px; border-radius:20px; margin-bottom:10px; }
        </style>
    </head>
    <body>
    <div class='outer'>
        <div class='card'>
            <div class='header'>
                $logo
                <h1>$name</h1>
                $subtitleHtml
            </div>
            <div class='body'>
                $content
            </div>
            <div class='footer'>
                $contactHtml
                &copy; " . date('Y') . " $name. All rights reserved.<br>
                This is an automated message - please do not reply directly to this email.
            </div>
        </div>
    </div>
    </body>
    </html>";
}


function send_application_confirmation(string $email, string $startup_name, int $app_id): void {
    $reference = 'APP-' . str_pad($app_id, 6, '0', STR_PAD_LEFT);
    $subject   = "Application Received - {$startup_name}";

    $content = "
        <h3>Application Submitted Successfully!</h3>
        <p>Dear Applicant,</p>
        <p>Thank you for submitting your application for <strong>" . htmlspecialchars($startup_name) . "</strong>.
           We have received it and our team will review it shortly.</p>

        <div class='info-box'>
            <div><strong>Startup:</strong> " . htmlspecialchars($startup_name) . "</div>
            <div><strong>Reference:</strong> {$reference}</div>
            <div><strong>Submitted:</strong> " . date('d M Y, h:i A') . "</div>
        </div>

        <p>We will be in touch if your application is shortlisted. In the meantime you can
           track your status at any time by logging in to your account.</p>

        <a href='" . email_app_url() . "my-applications' class='btn'>
            View My Applications
        </a>

        " . email_questions_line() . "

        " . email_signoff() . "
    ";

    $altBody = "Application Submitted - {$startup_name}\n"
             . "Reference: {$reference}\n"
             . "Submitted: " . date('d M Y, h:i A') . "\n"
             . "We will contact you if shortlisted.\n"
             . "Track status: " . email_app_url() . "my-applications.php";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}

function send_draft_saved_email(
    string $email,
    string $name,
    string $startup,
    int    $app_id,
    string $temp_pass
): void {
    $reference  = 'DRAFT-' . str_pad($app_id, 6, '0', STR_PAD_LEFT);
    $subject    = "Draft Saved & Account Created - " . email_brand()['name'];
    $login_url  = email_app_url() . 'login.php';
    $portal_url = email_app_url() . 'my-applications.php';

    $content = "
        <h3>Your Draft Has Been Saved!</h3>
        <p>Hi " . htmlspecialchars($name) . ",</p>
        <p>Great start! Your application draft for <strong>" . htmlspecialchars($startup) . "</strong>
           has been saved. We have also created a " . email_h(email_brand()['name']) . " account for you so you can
           return and complete it at any time.</p>

        <div class='info-box'>
            <div><strong>Draft Reference:</strong> {$reference}</div>
            <div><strong>Startup:</strong> " . htmlspecialchars($startup) . "</div>
            <div><strong>Saved:</strong> " . date('d M Y, h:i A') . "</div>
        </div>

        <div class='cred-box'>
            <h4>Your Login Credentials</h4>
            <p style='margin:0 0 8px;font-size:14px;'>
                <strong>Email / Username:</strong><br>
                " . htmlspecialchars($email) . "
            </p>
            <p style='margin:8px 0 4px;font-size:14px;'><strong>Temporary Password:</strong></p>
            <div class='password'>" . htmlspecialchars($temp_pass) . "</div>
            <div class='warning'>
                 <strong>Please save this password now.</strong>
                For security, change it in your account settings after you log in.
            </div>
        </div>

        <p><strong>Next steps:</strong></p>
        <ol style='padding-left:20px;line-height:2;font-size:14px;'>
            <li>Log in using the credentials above.</li>
            <li>Go to <em>My Applications</em> and find your draft.</li>
            <li>Complete the remaining fields and click <strong>Submit Application</strong>
                before the deadline.</li>
        </ol>

        <a href='{$login_url}' class='btn'>Log In &amp; Continue</a>

        <p style='font-size:13px;color:#7f8c8d;'>
            Alternatively go directly to your draft:
            <a href='{$portal_url}'>{$portal_url}</a>
        </p>

        " . email_signoff() . "
    ";

    $altBody = "Draft Saved - " . $startup . "\n"
             . "Reference: {$reference}\n"
             . "Your account has been created.\n"
             . "Email: {$email}\n"
             . "Temporary Password: {$temp_pass}\n"
             . "IMPORTANT: Save this password - change it after logging in.\n"
             . "Log in at: {$login_url}\n"
             . "Then go to My Applications to complete your draft.";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}


function send_draft_updated_email(
    string $email,
    string $name,
    string $startup,
    int    $app_id
): void {
    $reference  = 'DRAFT-' . str_pad($app_id, 6, '0', STR_PAD_LEFT);
    $subject    = "Draft Updated - " . $startup;
    $portal_url = email_app_url() . 'my-applications.php';

    $content = "
        <h3>Your Draft Has Been Updated</h3>
        <p>Hi " . htmlspecialchars($name) . ",</p>
        <p>Your application draft for <strong>" . htmlspecialchars($startup) . "</strong>
           has been saved successfully. You can continue editing it at any time from
           your <em>My Applications</em> portal.</p>

        <div class='info-box'>
            <div><strong>Draft Reference:</strong> {$reference}</div>
            <div><strong>Startup:</strong> " . htmlspecialchars($startup) . "</div>
            <div><strong>Last Saved:</strong> " . date('d M Y, h:i A') . "</div>
        </div>

        <p>When you are ready, log in and click <strong>Continue Application</strong>
           to complete and submit your application before the deadline.</p>

        <a href='{$portal_url}' class='btn'>Go to My Applications</a>

        " . email_questions_line() . "

        " . email_signoff() . "
    ";

    $altBody = "Draft Updated - " . $startup . "\n"
             . "Reference: {$reference}\n"
             . "Last Saved: " . date('d M Y, h:i A') . "\n"
             . "Log in to continue: {$portal_url}";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}



//assets
function send_asset_request_admin_notification(array $request, array $recipients): void
{
    if (empty($recipients)) {
        return;
    }

    $subject = 'New Asset Request - ' . $request['asset_name'];

    $content = "
        <h3>New Asset Request Submitted</h3>
        <p>A user has submitted a new asset request.</p>

        <div class='info-box'>
            <div><strong>Requested By:</strong> " . htmlspecialchars($request['staff_name']) . "</div>
            <div><strong>Asset Name:</strong> " . htmlspecialchars($request['asset_name']) . "</div>
            <div><strong>Category:</strong> " . htmlspecialchars($request['category']) . "</div>
            <div><strong>Urgency:</strong> " . htmlspecialchars($request['urgency_level']) . "</div>
            <div><strong>Requested At:</strong> " . date('d M Y, h:i A') . "</div>
        </div>

        <p><strong>Reason:</strong></p>
        <p>" . nl2br(htmlspecialchars($request['request_reason'])) . "</p>

        <a href='" . email_app_url() . "manage_assets' class='btn'>
            Review Asset Request
        </a>
    ";

    $altBody = "New Asset Request\n"
        . "Requested By: {$request['staff_name']}\n"
        . "Asset: {$request['asset_name']}\n"
        . "Category: {$request['category']}\n"
        . "Urgency: {$request['urgency_level']}\n"
        . "Reason: {$request['request_reason']}\n"
        . "Review: " . email_app_url() . "manage_assets.php";

    sendEmail($recipients, $subject, email_wrapper($content), $altBody);
}

function send_asset_request_user_confirmation(string $email, array $request): void
{
    if ($email === '') {
        return;
    }

    $subject = 'Asset Request Received - ' . $request['asset_name'];

    $content = "
        <h3>Asset Request Submitted Successfully</h3>
        <p>Dear " . htmlspecialchars($request['staff_name']) . ",</p>
        <p>Your asset request has been received and is pending review.</p>

        <div class='info-box'>
            <div><strong>Asset Name:</strong> " . htmlspecialchars($request['asset_name']) . "</div>
            <div><strong>Category:</strong> " . htmlspecialchars($request['category']) . "</div>
            <div><strong>Urgency:</strong> " . htmlspecialchars($request['urgency_level']) . "</div>
            <div><strong>Status:</strong> Pending</div>
        </div>

        <p>You will be notified once the request has been reviewed.</p>

        " . email_signoff() . "
    ";

    $altBody = "Asset Request Submitted\n"
        . "Asset: {$request['asset_name']}\n"
        . "Category: {$request['category']}\n"
        . "Urgency: {$request['urgency_level']}\n"
        . "Status: Pending";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}

//Procurement
function send_procurement_submitted_email(array $data, array $recipients): void
{
    if (empty($recipients)) return;

    $subject = "New Procurement Request - " . $data['title'];

    $content = "
        <h3>New Procurement Request Submitted</h3>

        <div class='info-box'>
            <div><strong>Title:</strong> {$data['title']}</div>
            <div><strong>Category:</strong> {$data['category']}</div>
            <div><strong>Budget:</strong> UGX " . number_format($data['budget']) . "</div>
            <div><strong>Urgency:</strong> {$data['urgency']}</div>
        </div>

        <p>{$data['description']}</p>

        <a href='" . email_app_url() . "manage_procurement' class='btn'>
            Review Request
        </a>
    ";

    sendEmail($recipients, $subject, email_wrapper($content));
}

function send_procurement_approved_email(string $email, array $data): void
{
    if ($email === '') return;

    $subject = "Procurement Approved - " . $data['title'];

    $content = "
        <h3>Your Procurement Request Has Been Approved</h3>

        <div class='info-box'>
            <div><strong>Title:</strong> {$data['title']}</div>
            <div><strong>Status:</strong> Approved</div>
        </div>

        <p>The procurement process will now proceed to RFQ stage.</p>
    ";

    sendEmail($email, $subject, email_wrapper($content));
}


function send_procurement_rejected_email(string $email, array $data): void
{
    if ($email === '') return;

    $subject = "Procurement Rejected - " . $data['title'];

    $content = "
        <h3>Your Procurement Request Was Rejected</h3>

        <div class='info-box'>
            <div><strong>Title:</strong> {$data['title']}</div>
            <div><strong>Status:</strong> Rejected</div>
        </div>

        <p><strong>Reason:</strong></p>
        <p>{$data['reason']}</p>
    ";

    sendEmail($email, $subject, email_wrapper($content));
}

function send_rfq_created_email(array $recipients, array $data): void
{
    if (empty($recipients)) return;

    $subject = "RFQ Created - " . $data['title'];

    $content = "
        <h3>RFQ Created Successfully</h3>

        <div class='info-box'>
            <div><strong>Procurement:</strong> {$data['title']}</div>
            <div><strong>RFQ Number:</strong> {$data['rfq_number']}</div>
        </div>

        <a href='" . email_app_url() . "manage_rfq?procurement_id={$data['procurement_id']}' class='btn'>
            Manage RFQ
        </a>
    ";

    sendEmail($recipients, $subject, email_wrapper($content));
}

function send_po_created_email(string $email, array $data): void
{
    if ($email === '') return;

    $subject = "Purchase Order Created - " . $data['title'];

    $content = "
        <h3>Purchase Order Created</h3>

        <div class='info-box'>
            <div><strong>Procurement:</strong> {$data['title']}</div>
            <div><strong>PO Number:</strong> {$data['po_number']}</div>
            <div><strong>Amount:</strong> UGX " . number_format($data['amount']) . "</div>
        </div>
    ";

    sendEmail($email, $subject, email_wrapper($content));
}

function send_supplier_rfq_invitation_email(string $email, array $data): void
{
    if ($email === '') {
        return;
    }

    $subject = 'RFQ Invitation - ' . ($data['rfq_number'] ?? email_brand()['name']);

    $content = "
        <h3>Request for Quotation Invitation</h3>
        <p>Dear " . htmlspecialchars($data['supplier_name'] ?? 'Supplier') . ",</p>

        <p>You have been invited to submit a quotation for the following procurement request.</p>

        <div class='info-box'>
            <div><strong>RFQ Number:</strong> " . htmlspecialchars($data['rfq_number'] ?? '-') . "</div>
            <div><strong>Procurement:</strong> " . htmlspecialchars($data['procurement_title'] ?? '-') . "</div>
            <div><strong>Invited At:</strong> " . date('d M Y, h:i A') . "</div>
        </div>

        <p>Please contact the " . email_h(email_brand()['name']) . " procurement team for submission details.</p>

        " . email_signoff('Procurement Team') . "
    ";

    $altBody = "RFQ Invitation\n"
        . "RFQ Number: " . ($data['rfq_number'] ?? '-') . "\n"
        . "Procurement: " . ($data['procurement_title'] ?? '-') . "\n";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}

function send_quotation_review_invite_email(string $email, array $data): void
{
    if ($email === '') return;

    $subject = 'Quotation Review Invitation - ' . ($data['rfq_number'] ?? 'RFQ');

    $content = "
        <h3>Quotation Review Invitation</h3>
        <p>Hello " . htmlspecialchars($data['name'] ?? 'Reviewer') . ",</p>
        <p>You have been invited to review supplier quotations.</p>

        <div class='info-box'>
            <div><strong>RFQ:</strong> " . htmlspecialchars($data['rfq_number'] ?? '-') . "</div>
            <div><strong>Procurement:</strong> " . htmlspecialchars($data['title'] ?? '-') . "</div>
        </div>

        <a href='" . email_app_url() . "manage_quotation_reviews?rfq_id=" . (int)$data['rfq_id'] . "' class='btn'>
            Review Quotations
        </a>
    ";

    sendEmail($email, $subject, email_wrapper($content));
}

function send_supplier_award_delivery_email(string $email, array $data): void
{
    if ($email === '') return;

    $subject = 'Quotation Selected - ' . ($data['po_number'] ?? 'Purchase Order');

    $content = "
        <h3>Your Quotation Has Been Selected</h3>
        <p>Dear " . htmlspecialchars($data['supplier_name'] ?? 'Supplier') . ",</p>
        <p>Your quotation has been selected. Please proceed with delivery arrangements.</p>

        <div class='info-box'>
            <div><strong>Procurement:</strong> " . htmlspecialchars($data['title'] ?? '-') . "</div>
            <div><strong>PO Number:</strong> " . htmlspecialchars($data['po_number'] ?? '-') . "</div>
            <div><strong>Amount:</strong> UGX " . number_format((float)($data['amount'] ?? 0)) . "</div>
        </div>
    ";

    sendEmail($email, $subject, email_wrapper($content));
}

//events
function send_event_registration_confirmation(array $data): void
{
    $email = trim((string)($data['email'] ?? ''));
    if ($email === '') return;

    $registrationId = (int)($data['registration_id'] ?? 0);
    $eventId        = (int)($data['event_id'] ?? 0);

    // Signed token: prevents forging check-ins for other registrations by id.
    $checkinPayload = 'rid=' . $registrationId . '&eid=' . $eventId;
    if (function_exists('ims_event_checkin_signature')) {
        $checkinPayload .= '&sig=' . ims_event_checkin_signature($registrationId, $eventId);
    }
    $checkinToken = base64_encode($checkinPayload);
    $checkinUrl   = email_app_url() . 'event-checkin?token=' . urlencode($checkinToken);

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($checkinUrl);

    $subject = 'Event Registration Confirmed - ' . ($data['event_title'] ?? email_brand()['name'] . ' Event');

    $eventDate = !empty($data['event_date'])
        ? date('l, d F Y', strtotime((string)$data['event_date']))
        : '-';

    $eventTime = '-';
    if (!empty($data['start_time'])) {
        $eventTime = date('h:i A', strtotime((string)$data['start_time']));
        if (!empty($data['end_time'])) {
            $eventTime .= ' - ' . date('h:i A', strtotime((string)$data['end_time']));
        }
    }

    $content = "
        <h3>Registration Confirmed</h3>

        <p>Dear " . htmlspecialchars((string)$data['attendee_name']) . ",</p>

        <p>Thank you for registering for <strong>" . htmlspecialchars((string)$data['event_title']) . "</strong>.</p>

        <div class='info-box'>
            <div><strong>Event:</strong> " . htmlspecialchars((string)$data['event_title']) . "</div>
            <div><strong>Date:</strong> {$eventDate}</div>
            <div><strong>Time:</strong> {$eventTime}</div>
            <div><strong>Venue:</strong> " . htmlspecialchars((string)($data['venue'] ?? '-')) . "</div>
            <div><strong>Registration No:</strong> REG-" . str_pad((string)$registrationId, 6, '0', STR_PAD_LEFT) . "</div>
        </div>

        <p><strong>Your QR Check-in Code</strong></p>
        <p>Please present this QR code at the event entrance for quick check-in.</p>

        <div style='text-align:center;margin:20px 0;'>
            <img src='{$qrUrl}' alt='QR Check-in Code' style='width:220px;height:220px;border:1px solid #eee;padding:8px;border-radius:10px;'>
        </div>

        <p style='font-size:13px;color:#666;text-align:center;'>
            If the QR code does not display, use this check-in link:<br>
            <a href='{$checkinUrl}'>{$checkinUrl}</a>
        </p>

        " . email_signoff() . "
    ";

    $altBody = "Registration Confirmed\n"
        . "Event: " . ($data['event_title'] ?? '-') . "\n"
        . "Date: {$eventDate}\n"
        . "Time: {$eventTime}\n"
        . "Venue: " . ($data['venue'] ?? '-') . "\n"
        . "Registration No: REG-" . str_pad((string)$registrationId, 6, '0', STR_PAD_LEFT) . "\n"
        . "Check-in link: {$checkinUrl}";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}


function _booking_info_box(array $b): string {
    $dateStr = !empty($b['booking_date']) ? date('l, d M Y', strtotime((string)$b['booking_date'])) : '-';
    $timeStr = '-';
    if (!empty($b['start_time'])) {
        $timeStr = date('h:i A', strtotime((string)$b['start_time']));
        if (!empty($b['end_time'])) {
            $timeStr .= ' - ' . date('h:i A', strtotime((string)$b['end_time']));
        }
    }

    return "
        <div class='info-box'>
            <div><strong>Booking Ref:</strong> #" . (int)($b['booking_id'] ?? 0) . "</div>
            <div><strong>Space:</strong> " . htmlspecialchars((string)($b['space_name'] ?? '-')) . "</div>
            <div><strong>Date:</strong> {$dateStr}</div>
            <div><strong>Time:</strong> {$timeStr}</div>
        </div>";
}


function send_booking_request_confirmation(string $email, string $name, array $booking): void
{
    if ($email === '') return;

    $subject = 'Booking Request Received - ' . ($booking['space_name'] ?? email_brand()['name'] . ' Space');

    $content = "
        <h3>Booking Request Received</h3>
        <p>Dear " . htmlspecialchars($name) . ",</p>
        <p>We've received your booking request and it is now <strong>pending confirmation</strong>
           from our Operations team.</p>
        " . _booking_info_box($booking) . "
        <div class='info-box'>
            <div><strong>Purpose:</strong> " . htmlspecialchars((string)($booking['purpose'] ?? '-')) . "</div>
            <div><strong>Attendees:</strong> " . (int)($booking['number_of_attendees'] ?? 0) . "</div>
            <div><strong>Amount:</strong> UGX " . number_format((float)($booking['booking_amount'] ?? 0)) . "</div>
        </div>
        <p>You'll receive another email as soon as your booking is confirmed.</p>
        <a href='" . email_app_url() . "my-bookings' class='btn'>View My Bookings</a>
        " . email_signoff() . "
    ";

    $altBody = "Booking Request Received\n"
        . "Booking Ref: #" . (int)($booking['booking_id'] ?? 0) . "\n"
        . "Space: " . ($booking['space_name'] ?? '-') . "\n"
        . "Date: " . ($booking['booking_date'] ?? '-') . "\n"
        . "Status: Pending confirmation\n"
        . "View bookings: " . email_app_url() . "my-bookings.php";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}


function send_booking_admin_notification(array $recipients, array $booking, string $member_name): void
{
    if (empty($recipients)) return;

    $subject = 'New Space Booking Request - ' . ($booking['space_name'] ?? 'Space');

    $content = "
        <h3>New Booking Request Awaiting Review</h3>
        <p>A member has requested to book a space. Please review and confirm or decline.</p>
        " . _booking_info_box($booking) . "
        <div class='info-box'>
            <div><strong>Requested By:</strong> " . htmlspecialchars($member_name) . "</div>
            <div><strong>Purpose:</strong> " . htmlspecialchars((string)($booking['purpose'] ?? '-')) . "</div>
            <div><strong>Attendees:</strong> " . (int)($booking['number_of_attendees'] ?? 0) . "</div>
            <div><strong>Amount:</strong> UGX " . number_format((float)($booking['booking_amount'] ?? 0)) . "</div>
        </div>
        <a href='" . email_app_url() . "manage-bookings' class='btn'>Review Booking</a>
    ";

    $altBody = "New Booking Request\n"
        . "Requested By: {$member_name}\n"
        . "Space: " . ($booking['space_name'] ?? '-') . "\n"
        . "Date: " . ($booking['booking_date'] ?? '-') . "\n"
        . "Review: " . email_app_url() . "manage-bookings";

    sendEmail($recipients, $subject, email_wrapper($content), $altBody);
}


function send_booking_cancellation_email(string $email, array $booking): void
{
    if ($email === '') return;

    $subject = 'Booking Cancelled - ' . ($booking['space_name'] ?? email_brand()['name'] . ' Space');

    $content = "
        <h3>Booking Cancelled</h3>
        <p>Dear " . htmlspecialchars((string)($booking['member_name'] ?? '')) . ",</p>
        <p>This confirms that your booking below has been cancelled.</p>
        " . _booking_info_box($booking) . "
        <p>Need to book again? You're welcome to submit a new request any time.</p>
        <a href='" . email_app_url() . "book-space' class='btn'>Book a Space</a>
        " . email_signoff() . "
    ";

    $altBody = "Booking Cancelled\n"
        . "Booking Ref: #" . (int)($booking['booking_id'] ?? 0) . "\n"
        . "Space: " . ($booking['space_name'] ?? '-') . "\n"
        . "Date: " . ($booking['booking_date'] ?? '-');

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}


function send_booking_cancellation_admin_notification(array $recipients, array $booking, string $member_name): void
{
    if (empty($recipients)) return;

    $subject = 'Booking Cancelled - ' . ($booking['space_name'] ?? 'Space');

    $content = "
        <h3>A Booking Has Been Cancelled</h3>
        <p><strong>" . htmlspecialchars($member_name) . "</strong> has cancelled the following booking.
           This slot is now free again.</p>
        " . _booking_info_box($booking) . "
        <a href='" . email_app_url() . "manage-bookings' class='btn'>View Bookings Calendar</a>
    ";

    $altBody = "Booking Cancelled\n"
        . "Cancelled By: {$member_name}\n"
        . "Space: " . ($booking['space_name'] ?? '-') . "\n"
        . "Date: " . ($booking['booking_date'] ?? '-');

    sendEmail($recipients, $subject, email_wrapper($content), $altBody);
}


function send_booking_reschedule_email(string $email, array $booking): void
{
    if ($email === '') return;

    $subject = 'Booking Rescheduled - ' . ($booking['space_name'] ?? email_brand()['name'] . ' Space');

    $oldDate = !empty($booking['old_date']) ? date('d M Y', strtotime((string)$booking['old_date'])) : '-';
    $oldTime = !empty($booking['old_start']) ? date('h:i A', strtotime((string)$booking['old_start'])) . ' - ' . date('h:i A', strtotime((string)$booking['old_end'])) : '-';
    $newDate = !empty($booking['new_date']) ? date('l, d M Y', strtotime((string)$booking['new_date'])) : '-';
    $newTime = !empty($booking['new_start']) ? date('h:i A', strtotime((string)$booking['new_start'])) . ' - ' . date('h:i A', strtotime((string)$booking['new_end'])) : '-';

    $content = "
        <h3>Booking Rescheduled</h3>
        <p>Dear " . htmlspecialchars((string)($booking['member_name'] ?? '')) . ",</p>
        <p>Your booking for <strong>" . htmlspecialchars((string)($booking['space_name'] ?? '')) . "</strong>
           has been moved as shown below. It is now <strong>pending confirmation</strong> again.</p>
        <div class='info-box'>
            <div><strong>Booking Ref:</strong> #" . (int)($booking['booking_id'] ?? 0) . "</div>
            <div><strong>Previous:</strong> {$oldDate}, {$oldTime}</div>
            <div><strong>New Date:</strong> {$newDate}</div>
            <div><strong>New Time:</strong> {$newTime}</div>
        </div>
        <p>You'll be notified again once Operations confirms the new slot.</p>
        <a href='" . email_app_url() . "my-bookings' class='btn'>View My Bookings</a>
        " . email_signoff() . "
    ";

    $altBody = "Booking Rescheduled\n"
        . "Booking Ref: #" . (int)($booking['booking_id'] ?? 0) . "\n"
        . "Previous: {$oldDate}, {$oldTime}\n"
        . "New: {$newDate}, {$newTime}\n"
        . "Status: Pending confirmation";

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}


function send_booking_reschedule_admin_notification(array $recipients, array $booking, string $member_name): void
{
    if (empty($recipients)) return;

    $subject = 'Booking Rescheduled - Needs Review - ' . ($booking['space_name'] ?? 'Space');

    $oldDate = !empty($booking['old_date']) ? date('d M Y', strtotime((string)$booking['old_date'])) : '-';
    $newDate = !empty($booking['new_date']) ? date('d M Y', strtotime((string)$booking['new_date'])) : '-';

    $content = "
        <h3>A Booking Has Been Rescheduled</h3>
        <p><strong>" . htmlspecialchars($member_name) . "</strong> moved their booking for
           <strong>" . htmlspecialchars((string)($booking['space_name'] ?? '')) . "</strong>
           from {$oldDate} to {$newDate}. It is now Pending and needs confirmation.</p>
        <a href='" . email_app_url() . "manage-bookings' class='btn'>Review Booking</a>
    ";

    $altBody = "Booking Rescheduled\n"
        . "By: {$member_name}\n"
        . "Space: " . ($booking['space_name'] ?? '-') . "\n"
        . "From: {$oldDate}  To: {$newDate}\n"
        . "Status: Pending - needs confirmation";

    sendEmail($recipients, $subject, email_wrapper($content), $altBody);
}


function send_booking_reminder_email(string $email, array $booking, string $audience = 'member'): void
{
    if ($email === '') return;

    $spaceName = $booking['space_name'] ?? 'the space';
    $subject   = 'Reminder: Upcoming Booking - ' . $spaceName;

    if ($audience === 'member') {
        $greeting = "Dear " . htmlspecialchars((string)($booking['member_name'] ?? 'Member')) . ",";
        $intro    = "This is a friendly reminder about your upcoming booking.";
    } else {
        $greeting = "Hello,";
        $intro    = "This is a reminder of an upcoming confirmed booking that " .
                    htmlspecialchars((string)($booking['member_name'] ?? 'a member')) . " has on the calendar.";
    }

    $content = "
        <span class='reminder-badge'><i class='fas fa-bell'></i> Upcoming Booking</span>
        <h3>Reminder</h3>
        <p>{$greeting}</p>
        <p>{$intro}</p>
        " . _booking_info_box($booking) . "
        <p>If anything has changed, please update or cancel the booking as soon as possible.</p>
        <a href='" . email_app_url() . "my-bookings' class='btn'>View Booking Details</a>
        " . email_signoff() . "
    ";

    $altBody = "Reminder: Upcoming Booking\n"
        . "Booking Ref: #" . (int)($booking['booking_id'] ?? 0) . "\n"
        . "Space: " . ($booking['space_name'] ?? '-') . "\n"
        . "Date: " . ($booking['booking_date'] ?? '-') . "\n"
        . "Time: " . ($booking['start_time'] ?? '-') . " - " . ($booking['end_time'] ?? '-');

    sendEmail($email, $subject, email_wrapper($content), $altBody);
}


/* ============================================================
   HUB SPACE BOOKING APPROVAL / REJECTION EMAILS
   ============================================================ */

if (!function_exists('send_booking_approved_email')) {
    function send_booking_approved_email(
        string $email,
        string $name,
        array $booking
    ): void {
        if (trim($email) === '') {
            return;
        }

        $subject = 'Booking Approved - '
            . ($booking['space_name'] ?? email_brand()['name'] . ' Space');

        $content = "
            <h3>Your Booking Has Been Approved</h3>
            <p>Dear " . htmlspecialchars($name) . ",</p>
            <p>Your " . email_h(email_brand()['name']) . " space booking request has been
               <strong>approved</strong> by our Operations team.</p>
            " . _booking_info_box($booking) . "
            <div class='info-box'>
                <div><strong>Status:</strong> Confirmed</div>
                <div><strong>Purpose:</strong> "
                    . htmlspecialchars((string)($booking['purpose'] ?? '-'))
                    . "</div>
                <div><strong>Attendees:</strong> "
                    . (int)($booking['number_of_attendees'] ?? 0)
                    . "</div>
            </div>
            <p>Please arrive on time and contact the Operations team if your plans change.</p>
            <a href='" . email_app_url() . "hub-events?tab=my-bookings'
               class='btn'>View My Bookings</a>
            " . email_signoff() . "
        ";

        $altBody = "Booking Approved\n"
            . "Space: " . ($booking['space_name'] ?? '-') . "\n"
            . "Date: " . ($booking['booking_date'] ?? '-') . "\n"
            . "Time: " . ($booking['start_time'] ?? '-')
            . " - " . ($booking['end_time'] ?? '-') . "\n"
            . "Status: Confirmed";

        sendEmail(
            $email,
            $subject,
            email_wrapper($content),
            $altBody
        );
    }
}

if (!function_exists('send_booking_rejected_email')) {
    function send_booking_rejected_email(
        string $email,
        string $name,
        array $booking,
        string $reason
    ): void {
        if (trim($email) === '') {
            return;
        }

        $subject = 'Booking Request Rejected - '
            . ($booking['space_name'] ?? email_brand()['name'] . ' Space');

        $content = "
            <h3>Booking Request Not Approved</h3>
            <p>Dear " . htmlspecialchars($name) . ",</p>
            <p>Your " . email_h(email_brand()['name']) . " space booking request was not approved.</p>
            " . _booking_info_box($booking) . "
            <div class='warning'>
                <strong>Reason:</strong><br>
                " . nl2br(htmlspecialchars($reason)) . "
            </div>
            <p>You may return to the booking page and choose another free date or time.</p>
            <a href='" . email_app_url() . "hub-events'
               class='btn'>Choose Another Slot</a>
            " . email_signoff() . "
        ";

        $altBody = "Booking Request Rejected\n"
            . "Space: " . ($booking['space_name'] ?? '-') . "\n"
            . "Date: " . ($booking['booking_date'] ?? '-') . "\n"
            . "Reason: " . $reason . "\n"
            . "Choose another slot: " . email_app_url() . "hub-events.php";

        sendEmail(
            $email,
            $subject,
            email_wrapper($content),
            $altBody
        );
    }
}

if (!function_exists('ims_event_checkin_signature')) {
    /** HMAC for event check-in QR tokens (key from .env via security.php). */
    function ims_event_checkin_signature(int $registrationId, int $eventId): string
    {
        $key = function_exists('ims_encryption_key') ? (string)ims_encryption_key() : '';
        if ($key === '') {
            $key = (string)(getenv('APP_KEY') ?: 'ims-checkin');
        }
        return substr(hash_hmac('sha256', 'checkin|' . $registrationId . '|' . $eventId, $key), 0, 32);
    }
}
