<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Contact Support form handler (contact-support.php)
|--------------------------------------------------------------------------
|
| POST only, logged-in users only (CSRF is enforced globally by config.php).
| Validates input, rate-limits per user, and emails the support inbox with
| sendEmail() from includes/mail-function.php. Reply-To is not trusted from
| the form: the message body carries the account's own email/name.
|
| Support inbox: SUPPORT_EMAIL in .env, else the organisation email from
| Settings (the address shown on the page), else the active email_settings
| from_email.
|
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_login('login');

function support_back(string $message, string $type, int $appId = 0): never
{
    $_SESSION['notification'] = [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'message' => $message,
        'type'    => $type,
    ];

    header('Location: contact-support' . ($appId > 0 ? '?app_id=' . $appId : ''), true, 303);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: contact-support', true, 303);
    exit;
}

$userId = auth_user_id();
$appId = max(0, (int)($_POST['application_id'] ?? 0));

$clean = static function (mixed $value, int $max): string {
    $value = trim(str_replace("\0", '', (string)$value));
    $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

    return mb_substr($value, 0, $max, 'UTF-8');
};

$allowedTypes = [
    'Application Status', 'Application Issue', 'Program Information', 'Technical Support',
    'Account Issue', 'Payment/Subscription', 'General Inquiry', 'Other',
];

$fullName = $clean($_POST['full_name'] ?? '', 150);
$email = $clean($_POST['email'] ?? '', 190);
$phone = $clean($_POST['phone_number'] ?? '', 40);
$inquiryType = $clean($_POST['inquiry_type'] ?? '', 60);
// Single-line fields: strip line breaks (no header injection via subject).
$subject = (string)preg_replace('/[\r\n]+/', ' ', $clean($_POST['subject'] ?? '', 200));
$message = $clean($_POST['message'] ?? '', 2000);
$urgent = !empty($_POST['urgent']);

if ($fullName === '' || $subject === '' || mb_strlen($message, 'UTF-8') < 10 || !in_array($inquiryType, $allowedTypes, true)) {
    support_back('Please complete all required fields (message must be at least 10 characters).', 'danger', $appId);
}

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    support_back('Please provide a valid email address.', 'danger', $appId);
}

if ($phone !== '' && !preg_match('/^[0-9+()\s.-]{6,40}$/', $phone)) {
    support_back('Please provide a valid phone number.', 'danger', $appId);
}

// Rate limit: 5 messages per user per hour.
$rateKey = 'support-form:' . $userId;

if (ims_rate_limit_too_many($rateKey, 5, 3600)) {
    support_back('You have sent several support messages recently. Please wait a while before sending another.', 'warning', $appId);
}

ims_rate_limit_hit($rateKey, 3600);

// Account details come from the database, not the form.
$account = ['full_name' => '', 'email' => '', 'role' => ''];
$stmt = $conn->prepare('SELECT full_name, email, role FROM users WHERE user_id = ? LIMIT 1');

if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc() ?: $account;
    $stmt->close();
}

// Optional attachment (validated, sent from a temporary copy, then deleted).
$attachmentPath = null;

if (!empty($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $check = ims_validate_upload($_FILES['attachment'], ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 5 * 1024 * 1024);

    if (!$check['ok']) {
        support_back('Attachment rejected: ' . $check['error'], 'danger', $appId);
    }

    $tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ims_support_' . bin2hex(random_bytes(6));

    if (@mkdir($tempDir, 0700, true)) {
        $originalBase = (string)preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo((string)$_FILES['attachment']['name'], PATHINFO_FILENAME));
        $target = $tempDir . DIRECTORY_SEPARATOR . substr($originalBase !== '' ? $originalBase : 'attachment', 0, 80) . '.' . $check['extension'];

        if (move_uploaded_file((string)$_FILES['attachment']['tmp_name'], $target)) {
            $attachmentPath = $target;
        }
    }
}

$recipient = (string)ims_env('SUPPORT_EMAIL', '');

require_once __DIR__ . '/includes/mail-function.php';

$orgEmail = function_exists('ims_brand_email') ? ims_brand_email() : '';

if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
    $recipient = $orgEmail;
}

if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
    $settings = function_exists('getEmailSettings') ? getEmailSettings() : null;
    $recipient = (string)($settings['from_email'] ?? '');
}

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$rows = [
    'Inquiry type'    => $inquiryType . ($urgent ? ' (URGENT)' : ''),
    'Name (form)'     => $fullName,
    'Email (form)'    => $email,
    'Phone'           => $phone !== '' ? $phone : '-',
    'Account'         => trim((string)$account['full_name'] . ' <' . (string)$account['email'] . '> #' . $userId . ' ' . (string)$account['role']),
    'Application ID'  => $appId > 0 ? (string)$appId : '-',
    'Submitted'       => date('Y-m-d H:i:s'),
];

$table = '';
foreach ($rows as $label => $value) {
    $table .= '<tr><td style="padding:4px 10px 4px 0;color:#6b7280;"><strong>' . $h($label) . '</strong></td><td style="padding:4px 0;">' . $h($value) . '</td></tr>';
}

$content = '<h2 style="margin:0 0 12px 0;">Support request: ' . $h($subject) . '</h2>'
    . '<table>' . $table . '</table>'
    . '<div style="margin-top:14px;padding:12px;background:#f9fafb;border-radius:6px;">' . nl2br($h($message)) . '</div>';

$body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
$plain = "Support request: {$subject}\n\n";
foreach ($rows as $label => $value) {
    $plain .= $label . ': ' . $value . "\n";
}
$plain .= "\n" . $message . "\n";

$mailSubject = ($urgent ? '[URGENT] ' : '') . '[IMS Support] ' . $inquiryType . ': ' . $subject;

try {
    $sent = sendEmail($recipient, $mailSubject, $body, $plain, [], [], $attachmentPath !== null ? [$attachmentPath] : []);
} catch (Throwable $exception) {
    error_log('process-support sendEmail failed: ' . $exception->getMessage());
    $sent = false;
}

if ($attachmentPath !== null) {
    @unlink($attachmentPath);
    @rmdir(dirname($attachmentPath));
}

if ($sent !== true) {
    error_log('process-support: email not sent: ' . (is_string($sent) ? $sent : 'unknown error'));
    support_back('Sorry, your message could not be sent right now. Please try again later'
        . ($orgEmail !== '' ? ' or email ' . $orgEmail : '') . '.', 'danger', $appId);
}

if (function_exists('log_action')) {
    log_action($userId, 'Contact Support', 'support', $appId > 0 ? $appId : null, $inquiryType . ': ' . $subject);
}

support_back('Thank you! Your message has been sent to our support team. We will get back to you soon.', 'success', $appId);
