<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

/*
|--------------------------------------------------------------------------
| Response helpers
|--------------------------------------------------------------------------
*/

function clearPendingLoginSession(): void
{
    unset(
        $_SESSION['verification_code'],
        $_SESSION['verification_expiry'],
        $_SESSION['pending_user_id'],
        $_SESSION['pending_username'],
        $_SESSION['pending_email'],
        $_SESSION['pending_full_name'],
        $_SESSION['pending_role']
    );
}

function sendLoginResponse(array $response): never
{
    $isAjax = isset($_POST['ajax'])
        && (string) $_POST['ajax'] === '1';

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            $response,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        exit;
    }

    if (
        !empty($response['success'])
        && !empty($response['redirect'])
    ) {
        header('Location: ' . $response['redirect']);
        exit;
    }

    $_SESSION['login_error'] =
        (string) ($response['message'] ?? 'Login failed.');

    header('Location: ../login');
    exit;
}

function safeLogAction(
    ?int $userId,
    string $action,
    string $tableName,
    ?int $recordId,
    string $description
): void {
    if (!function_exists('log_action')) {
        return;
    }

    try {
        log_action(
            $userId,
            $action,
            $tableName,
            $recordId,
            $description
        );
    } catch (Throwable $exception) {
        error_log(
            'Unable to save activity log: '
            . $exception->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Redirect authenticated users
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['user_id'])) {
    header('Location: ../dashboard');
    exit;
}

/*
|--------------------------------------------------------------------------
| Default response
|--------------------------------------------------------------------------
*/

$response = [
    'success'  => false,
    'message'  => '',
    'redirect' => '',
];

/*
|--------------------------------------------------------------------------
| Only accept POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method.';
    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Read submitted credentials
|--------------------------------------------------------------------------
*/

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $response['message'] =
        'Please enter both username and password.';

    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Brute-force protection
|--------------------------------------------------------------------------
|
| Max 5 failed attempts per account and 20 per IP address in 15 minutes.
|
*/

$loginWindow = 900;
$loginUserKey = 'login:user:' . mb_strtolower($username);
$loginIpKey = 'login:ip:' . ims_client_ip();

if (
    ims_rate_limit_too_many($loginUserKey, 5, $loginWindow)
    || ims_rate_limit_too_many($loginIpKey, 20, $loginWindow)
) {
    safeLogAction(null, 'Login Throttled', 'users', null, 'Too many failed login attempts for: ' . mb_substr($username, 0, 100));

    $response['message'] =
        'Too many failed login attempts. Please wait 15 minutes and try again.';

    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Validate database connection
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    try {
        $conn = db_connect();
    } catch (Throwable $exception) {
        error_log(
            'Login database error: ' . $exception->getMessage()
        );

        $response['message'] =
            'The system could not connect to the database.';

        sendLoginResponse($response);
    }
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Find user
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        user_id,
        username,
        email,
        password_hash,
        full_name,
        role,
        is_active
    FROM users
    WHERE username = ?
       OR email = ?
    LIMIT 1
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    $stmt->close();
} catch (Throwable $exception) {
    error_log(
        'Login query error: ' . $exception->getMessage()
    );

    $response['message'] =
        'The login request could not be processed.';

    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Invalid account
|--------------------------------------------------------------------------
*/

if (!$user) {
    ims_rate_limit_hit($loginUserKey, $loginWindow);
    ims_rate_limit_hit($loginIpKey, $loginWindow);

    // Equalise timing with the password check to avoid user enumeration.
    password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

    safeLogAction(
        null,
        'Failed Login',
        'users',
        null,
        'Failed login attempt for: ' . $username
    );

    $response['message'] = 'Invalid username or password.';

    sendLoginResponse($response);
}

$userId       = (int) ($user['user_id'] ?? 0);
$userEmail    = trim((string) ($user['email'] ?? ''));
$userName     = trim((string) ($user['username'] ?? ''));
$fullName     = trim((string) ($user['full_name'] ?? ''));
$userRole     = trim((string) ($user['role'] ?? ''));
$passwordHash = (string) ($user['password_hash'] ?? '');
$isActive     = (int) ($user['is_active'] ?? 0) === 1;

if ($fullName === '') {
    $fullName = $userName !== '' ? $userName : 'User';
}

/*
|--------------------------------------------------------------------------
| Check account status
|--------------------------------------------------------------------------
*/

if (!$isActive) {
    $response['message'] =
        'Your account has been deactivated. Please contact the administrator.';

    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Verify password
|--------------------------------------------------------------------------
*/

if (
    $passwordHash === ''
    || !password_verify($password, $passwordHash)
) {
    ims_rate_limit_hit($loginUserKey, $loginWindow);
    ims_rate_limit_hit($loginIpKey, $loginWindow);

    safeLogAction(
        $userId,
        'Failed Login',
        'users',
        $userId,
        'Incorrect password submitted for: ' . $username
    );

    $response['message'] = 'Invalid username or password.';

    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Validate email
|--------------------------------------------------------------------------
*/

if (
    $userEmail === ''
    || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)
) {
    $response['message'] =
        'Your account does not have a valid email address. Please contact the administrator.';

    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Generate verification code
|--------------------------------------------------------------------------
*/

try {
    $verificationCode = (string) random_int(100000, 999999);
} catch (Throwable $exception) {
    error_log(
        'Verification code generation failed: '
        . $exception->getMessage()
    );

    $response['message'] =
        'The verification code could not be generated.';

    sendLoginResponse($response);
}

$verificationExpiry = date(
    'Y-m-d H:i:s',
    time() + 600
);

/*
|--------------------------------------------------------------------------
| Store pending login
|--------------------------------------------------------------------------
*/

ims_rate_limit_clear($loginUserKey);

// Transparently upgrade old hashes to the current algorithm/cost.
if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $rehash = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    if ($rehash) {
        $rehash->bind_param('si', $newHash, $userId);
        $rehash->execute();
        $rehash->close();
    }
}

session_regenerate_id(true);
clearPendingLoginSession();
$_SESSION['verification_attempts'] = 0;

$_SESSION['verification_code']   = $verificationCode;
$_SESSION['verification_expiry'] = $verificationExpiry;
$_SESSION['pending_user_id']     = $userId;
$_SESSION['pending_username']    = $userName;
$_SESSION['pending_email']       = $userEmail;
$_SESSION['pending_full_name']   = $fullName;
$_SESSION['pending_role']        = $userRole;

/*
|--------------------------------------------------------------------------
| Build verification email
|--------------------------------------------------------------------------
*/

$siteName = (string)($GLOBALS['IMS_SITE_BRANDING']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : 'EdTech Fellowship CMS'));

$safeSiteName = htmlspecialchars(
    $siteName,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$safeFullName = htmlspecialchars(
    $fullName,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$loginTime = date('d M Y, h:i A');

$ipAddress = htmlspecialchars(
    (string) ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$subject = 'Login Verification Code - ' . $siteName;

$body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login Verification</title>

    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
            color: #333333;
            background: #f4f6f9;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
        }

        .header {
            padding: 30px;
            color: #ffffff;
            text-align: center;
            background: #f39c12;
            border-radius: 10px 10px 0 0;
        }

        .header h1,
        .header p {
            margin: 0;
        }

        .header p {
            margin-top: 8px;
        }

        .content {
            padding: 30px;
            background: #ffffff;
            border-radius: 0 0 10px 10px;
        }

        .code-box {
            margin: 24px 0;
            padding: 20px;
            text-align: center;
            background: #fffaf7;
            border: 2px dashed #ff6b35;
            border-radius: 8px;
        }

        .code {
            color: #ff6b35;
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 5px;
        }

        .warning {
            margin: 20px 0;
            padding: 15px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }

        .footer {
            margin-top: 20px;
            color: #7f8c8d;
            font-size: 12px;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>{$safeSiteName}</h1>
            <p>Login Verification Required</p>
        </div>

        <div class="content">
            <p>
                Hello <strong>{$safeFullName}</strong>,
            </p>

            <p>
                A login attempt was made to your account.
                Use the verification code below to complete your login.
            </p>

            <div class="code-box">
                <div class="code">{$verificationCode}</div>
            </div>

            <p>
                <strong>This code expires in 10 minutes.</strong>
            </p>

            <div class="warning">
                <strong>Security notice:</strong>
                If you did not attempt to log in, ignore this email and
                consider changing your password.
            </div>

            <p><strong>Login details</strong></p>

            <ul>
                <li>
                    <strong>Time:</strong>
                    {$loginTime}
                </li>

                <li>
                    <strong>IP address:</strong>
                    {$ipAddress}
                </li>
            </ul>
        </div>

        <div class="footer">
            <p>
                This is an automated message from {$safeSiteName}.
                Please do not reply.
            </p>

            <p>
                &copy; {$safeSiteName}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
HTML;

$altBody = <<<TEXT
Hello {$fullName},

Your login verification code is: {$verificationCode}

This code expires in 10 minutes.

Login time: {$loginTime}
IP address: {$ipAddress}

If you did not attempt to log in, ignore this email and consider changing your password.

- {$siteName}
TEXT;

/*
|--------------------------------------------------------------------------
| Send email
|--------------------------------------------------------------------------
*/

try {
    $emailResult = sendEmail(
        $userEmail,
        $subject,
        $body,
        $altBody
    );
} catch (Throwable $exception) {
    error_log(
        'Verification email error: '
        . $exception->getMessage()
    );

    $emailResult = false;
}

if ($emailResult === true) {
    safeLogAction(
        $userId,
        'Verification Code Sent',
        'users',
        $userId,
        'Email verification code sent for login'
    );

    $response['success']  = true;
    $response['message']  =
        'A verification code was sent to your email.';
    $response['redirect'] = '../verify';

    sendLoginResponse($response);
}

/*
|--------------------------------------------------------------------------
| Email failed
|--------------------------------------------------------------------------
*/

clearPendingLoginSession();

$response['message'] =
    'The verification email could not be sent. Please try again or contact the administrator.';

sendLoginResponse($response);
