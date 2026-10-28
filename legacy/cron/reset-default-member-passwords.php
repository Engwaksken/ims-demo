<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| One-off: find accounts still using the old default hub-member password
|--------------------------------------------------------------------------
|
| Hub members used to be created with a fixed password that is now public
| (it is in the git history). This script lists every account whose
| password still matches it.
|
|   php legacy/cron/reset-default-member-passwords.php           (list only)
|   php legacy/cron/reset-default-member-passwords.php --reset   (reset + email)
|
| --reset gives each affected account a random temporary password and emails
| it to the account holder. CLI only.
|
*/

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mail-function.php';

$options = getopt('', ['reset']);
$doReset = array_key_exists('reset', $options);

const OLD_DEFAULT_PASSWORD = 'member123';

function temp_password(int $length = 14): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%';
    $max = strlen($chars) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

function send_reset_email(string $email, string $fullName, string $plainPassword): string|bool
{
    $safeName     = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
    $loginUrl     = email_app_url() . 'login';
    if (function_exists('get_setting')) {
        $base = rtrim((string)get_setting('site_url', ''), '/');
        if ($base !== '') {
            $loginUrl = $base . '/login.php';
        }
    }
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $orgName = email_brand()['name'];
    $safeOrg = email_h($orgName);

    $content = "
        <h3>Your password has been reset</h3>
        <p>Hi {$safeName},</p>
        <p>As part of a security update, the password on your {$safeOrg} member account has been reset.</p>
        <div class='cred-box'>
            <p style='margin:8px 0 4px;font-size:14px;'><strong>Temporary Password:</strong></p>
            <div class='password'>{$safePassword}</div>
            <div class='warning'><strong>Important:</strong> Please change this password after logging in.</div>
        </div>
        <a href='{$safeUrl}' class='btn'>Log In</a>
        " . email_signoff() . "
    ";
    $altBody = "Your {$orgName} password has been reset as part of a security update.\n"
        . "Temporary Password: {$plainPassword}\n"
        . "Login here: {$loginUrl}\n"
        . "Please change your password after logging in.";

    return sendEmail($email, 'Your ' . $orgName . ' password has been reset', email_wrapper($content), $altBody);
}

$conn = db_connect();

$result = $conn->query("SELECT user_id, full_name, email, password_hash FROM users WHERE password_hash IS NOT NULL AND password_hash <> ''");
if (!$result) {
    fwrite(STDERR, "Query failed: " . $conn->error . "\n");
    exit(1);
}

$affected = [];
while ($row = $result->fetch_assoc()) {
    if (password_verify(OLD_DEFAULT_PASSWORD, (string)$row['password_hash'])) {
        $affected[] = $row;
    }
}
$result->free();

echo sprintf("Accounts still using the old default password: %d\n", count($affected));
foreach ($affected as $row) {
    echo sprintf("  #%d  %s  <%s>\n", $row['user_id'], $row['full_name'], $row['email']);
}

if (!$doReset) {
    if ($affected) {
        echo "\nRun again with --reset to give each a random password and email it.\n";
    }
    exit(0);
}

$update = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
$ok = 0;
$failedMail = [];

foreach ($affected as $row) {
    $plain = temp_password();
    $hash  = password_hash($plain, PASSWORD_DEFAULT);
    $id    = (int)$row['user_id'];
    $update->bind_param('si', $hash, $id);
    if (!$update->execute()) {
        fwrite(STDERR, "  failed to update #{$id}: {$update->error}\n");
        continue;
    }
    $ok++;

    $mail = filter_var($row['email'], FILTER_VALIDATE_EMAIL)
        ? send_reset_email((string)$row['email'], (string)$row['full_name'], $plain)
        : 'invalid email';
    if ($mail !== true) {
        $failedMail[] = "#{$id} <{$row['email']}>: " . (is_string($mail) ? $mail : 'send failed');
    }
}
$update->close();

echo "\nReset: {$ok}\n";
if ($failedMail) {
    echo "Email could not be sent for these accounts (their old password no longer works; reset them from Users):\n";
    foreach ($failedMail as $line) {
        echo "  {$line}\n";
    }
}

exit(0);
