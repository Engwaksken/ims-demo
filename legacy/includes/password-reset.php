<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Self-service password reset
|--------------------------------------------------------------------------
| forgot-password emails a one-time link; reset-password sets the new
| password. Only a SHA-256 hash of the token is stored, links expire after
| PR_TOKEN_MINUTES and are single use, and the request page never reveals
| whether an account exists. Login still asks for the emailed code afterwards.
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

const PR_TOKEN_MINUTES = 60;
const PR_MIN_PASSWORD = 8;
const PR_MAX_PASSWORD_BYTES = 72; // bcrypt ignores anything longer
const PR_RATE_WINDOW = 900;

function pr_ensure_table(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("
        CREATE TABLE IF NOT EXISTS password_resets (
            reset_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            requested_ip VARCHAR(45) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_resets_token (token_hash),
            KEY idx_password_resets_user (user_id, used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/** True when this IP (or this identifier) has asked for too many links recently. Counts the attempt. */
function pr_request_throttled(string $identifier): bool
{
    $ipKey = 'pwreset:ip:' . ims_client_ip();
    $idKey = 'pwreset:id:' . mb_strtolower($identifier);
    $limited = ims_rate_limit_too_many($ipKey, 10, PR_RATE_WINDOW)
        || ims_rate_limit_too_many($idKey, 3, PR_RATE_WINDOW);
    ims_rate_limit_hit($ipKey, PR_RATE_WINDOW);
    if ($identifier !== '') ims_rate_limit_hit($idKey, PR_RATE_WINDOW);
    return $limited;
}

/**
 * Email a reset link when $identifier (username or email) matches an active
 * account with a valid email. Says nothing either way, to avoid enumeration.
 */
function pr_request(mysqli $conn, string $identifier): void
{
    pr_ensure_table($conn);

    $stmt = $conn->prepare('SELECT user_id, username, email, full_name, is_active FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['is_active'] !== 1 || !filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL)) {
        if (function_exists('log_action')) {
            log_action(null, 'Password Reset Requested', 'users', null, 'No active account with a valid email for: ' . mb_substr($identifier, 0, 100));
        }
        return;
    }

    $userId = (int)$user['user_id'];
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + PR_TOKEN_MINUTES * 60);
    $now = date('Y-m-d H:i:s');
    $ip = ims_client_ip();

    // Only the newest link works.
    $stmt = $conn->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL');
    $stmt->bind_param('si', $now, $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $userId, $hash, $expires, $ip);
    $stmt->execute();
    $stmt->close();

    $name = trim((string)$user['full_name']) !== '' ? (string)$user['full_name'] : (string)$user['username'];
    $link = email_app_url() . 'reset-password?token=' . $token;
    $siteName = email_brand()['name'];

    $content = '
        <h3>Reset your password</h3>
        <p>Hello <strong>' . email_h($name) . '</strong>,</p>
        <p>We received a request to reset the password for your account (<strong>' . email_h((string)$user['username']) . '</strong>).
           Use the button below to choose a new password.</p>
        <p style="text-align:center;"><a href="' . email_h($link) . '" class="btn">Choose a new password</a></p>
        <p>This link expires in ' . PR_TOKEN_MINUTES . ' minutes and can only be used once. If the button does not work, copy this address into your browser:<br>
           <a href="' . email_h($link) . '">' . email_h($link) . '</a></p>
        <div class="warning">
            <strong>Didn\'t ask for this?</strong> You can ignore this email; your password will not change.
            The request came from IP address ' . email_h($ip) . ' on ' . date('d M Y, h:i A') . '.
        </div>
        ' . email_signoff();

    $altBody = "Hello {$name},\n\n"
        . "Choose a new password for your {$siteName} account ({$user['username']}) here:\n{$link}\n\n"
        . 'This link expires in ' . PR_TOKEN_MINUTES . " minutes and can only be used once.\n"
        . "If you didn't ask for this, ignore this email; your password will not change.\n";

    sendEmail((string)$user['email'], 'Reset your password - ' . $siteName, email_wrapper($content, 'Password reset'), $altBody);

    if (function_exists('log_action')) {
        log_action($userId, 'Password Reset Requested', 'users', $userId, 'Password reset link emailed');
    }
}

/** The pending reset (with user fields) for a raw token, or null when invalid, used or expired. */
function pr_find(mysqli $conn, string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) return null;
    pr_ensure_table($conn);

    $hash = hash('sha256', $token);
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('
        SELECT r.reset_id, r.user_id, u.username, u.email, u.full_name
        FROM password_resets r
        JOIN users u ON u.user_id = r.user_id
        WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.is_active = 1
        LIMIT 1
    ');
    $stmt->bind_param('ss', $hash, $now);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** A user-facing problem with the new password, or null when acceptable. */
function pr_password_problem(string $password, string $confirm, array $user): ?string
{
    if (strlen($password) < PR_MIN_PASSWORD) return 'Your new password must be at least ' . PR_MIN_PASSWORD . ' characters.';
    if (strlen($password) > PR_MAX_PASSWORD_BYTES) return 'Your new password must be ' . PR_MAX_PASSWORD_BYTES . ' characters or fewer.';
    if (!hash_equals($password, $confirm)) return 'The two passwords do not match.';
    foreach (['username', 'email'] as $field) {
        $value = (string)($user[$field] ?? '');
        if ($value !== '' && strcasecmp($password, $value) === 0) return 'Your new password must not be your username or email address.';
    }
    return null;
}

/**
 * Set the new password for a valid token. Returns null on success or a
 * user-facing error. The token is locked, re-checked and burned in one transaction.
 */
function pr_complete(mysqli $conn, string $token, string $password, string $confirm): ?string
{
    $reset = pr_find($conn, $token);
    if ($reset === null) return 'This reset link is invalid or has expired. Please request a new one.';
    $problem = pr_password_problem($password, $confirm, $reset);
    if ($problem !== null) return $problem;

    $hash = hash('sha256', $token);
    $now = date('Y-m-d H:i:s');
    $userId = (int)$reset['user_id'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT reset_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? FOR UPDATE');
        $stmt->bind_param('ss', $hash, $now);
        $stmt->execute();
        $locked = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$locked) {
            $conn->rollback();
            return 'This reset link has already been used. Please request a new one.';
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $stmt->bind_param('si', $passwordHash, $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL');
        $stmt->bind_param('si', $now, $userId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log(sprintf('Password reset failed; class=%s; file=%s; line=%d', get_class($e), basename($e->getFile()), $e->getLine()));
        return 'Your password could not be changed. Please try again.';
    }

    ims_rate_limit_clear('login:user:' . mb_strtolower((string)$reset['username']));
    ims_rate_limit_clear('login:user:' . mb_strtolower((string)$reset['email']));

    if (function_exists('log_action')) {
        log_action($userId, 'Password Reset', 'users', $userId, 'Password changed with an emailed reset link');
    }

    $name = trim((string)$reset['full_name']) !== '' ? (string)$reset['full_name'] : (string)$reset['username'];
    $siteName = email_brand()['name'];
    $content = '
        <h3>Your password was changed</h3>
        <p>Hello <strong>' . email_h($name) . '</strong>,</p>
        <p>The password for your account (<strong>' . email_h((string)$reset['username']) . '</strong>) was changed on '
        . date('d M Y, h:i A') . ' using a password reset link.</p>
        <div class="warning"><strong>Wasn\'t you?</strong> Reset your password again straight away and contact your administrator.</div>
        <p style="text-align:center;"><a href="' . email_h(email_app_url() . 'login') . '" class="btn">Log in</a></p>
        ' . email_signoff();
    sendEmail(
        (string)$reset['email'],
        'Your password was changed - ' . $siteName,
        email_wrapper($content, 'Security notice'),
        "Hello {$name},\n\nThe password for your {$siteName} account ({$reset['username']}) was just changed using a reset link.\n"
        . "If this wasn't you, reset it again straight away and contact your administrator.\n"
    );

    return null;
}
