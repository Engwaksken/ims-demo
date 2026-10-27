<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/mail-function.php';

check_role(['Administrator']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['error'] = 'Database connection not available.';
    header('Location: ../users');
    exit();
}

$conn->set_charset('utf8mb4');

function redirect_users(string $message, string $type = 'success', string $location = '../users'): never
{
    if (function_exists('send_notification') && !empty($_SESSION['user_id'])) {
        send_notification((int)$_SESSION['user_id'], $message, $type);
    } else {
        if ($type === 'success') {
            $_SESSION['success'] = $message;
        } else {
            $_SESSION['error'] = $message;
        }
    }

    header('Location: ' . $location);
    exit();
}

function clean_input(?string $value): string
{
    $value = (string)$value;
    if (function_exists('sanitize_input')) {
        return trim((string)sanitize_input($value));
    }
    return trim($value);
}

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function app_login_url(): string
{
    if (function_exists('get_setting')) {
        $baseUrl = rtrim((string)get_setting('site_url', ''), '/');
        if ($baseUrl !== '') {
            return $baseUrl . '/login';
        }
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/login';
    }

    return 'https://ims.kemmytech.com/login.php';
}

function send_new_user_credentials_email(
    string $email,
    string $fullName,
    string $username,
    string $plainPassword,
    string $role
): string|bool {
    $loginUrl = app_login_url();
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
    $safeRole = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    $subject = 'Your Hive Colab Account Has Been Created';

    $content = "
        <h3>Welcome to Hive Colab</h3>
        <p>Hi {$safeName},</p>
        <p>An account has been created for you on the Hive Colab system.</p>

        <div class='info-box'>
            <div><strong>Full Name:</strong> {$safeName}</div>
            <div><strong>Email:</strong> {$safeEmail}</div>
            <div><strong>Username:</strong> {$safeUsername}</div>
            <div><strong>Role:</strong> {$safeRole}</div>
            <div><strong>Created:</strong> " . date('d M Y, h:i A') . "</div>
        </div>

        <div class='cred-box'>
            <h4>Your Login Credentials</h4>
            <p style='margin:0 0 8px;font-size:14px;'>
                <strong>Username:</strong><br>{$safeUsername}
            </p>
            <p style='margin:0 0 8px;font-size:14px;'>
                <strong>Email:</strong><br>{$safeEmail}
            </p>
            <p style='margin:8px 0 4px;font-size:14px;'><strong>Temporary Password:</strong></p>
            <div class='password'>{$safePassword}</div>
            <div class='warning'>
                <strong>Important:</strong> Please save this password and change it after your first login.
            </div>
        </div>

        <a href='{$loginUrl}' class='btn'>Log In Now</a>

        <p>If the button above does not work, use this link:</p>
        <p><a href='{$loginUrl}'>{$loginUrl}</a></p>

        <p>Best regards,<br><strong>Hive Colab Team</strong></p>
    ";

    $altBody = "Welcome to Hive Colab\n"
        . "Your account has been created.\n\n"
        . "Full Name: {$fullName}\n"
        . "Email: {$email}\n"
        . "Username: {$username}\n"
        . "Role: {$role}\n"
        . "Temporary Password: {$plainPassword}\n\n"
        . "Login here: {$loginUrl}\n"
        . "Please change your password after logging in.";

    return sendEmail($email, $subject, email_wrapper($content), $altBody);
}

/* ============================================================
   HANDLE ADD USER
============================================================ */
if (isset($_POST['add_user'])) {
    $username  = clean_input($_POST['username'] ?? '');
    $full_name = clean_input($_POST['full_name'] ?? '');
    $email     = clean_input($_POST['email'] ?? '');
    $role      = clean_input($_POST['role'] ?? '');
    $phone     = clean_input($_POST['phone'] ?? '');
    $password  = (string)($_POST['password'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($username === '' || $full_name === '' || $email === '' || $role === '' || $password === '') {
        redirect_users('All required fields must be filled.', 'danger');
    }

    if (!valid_email($email)) {
        redirect_users('Please enter a valid email address.', 'danger');
    }

    if (!in_array($role, ims_all_roles($conn), true)) {
        redirect_users('Please select a valid role.', 'danger');
    }

    if (strlen($password) < 6) {
        redirect_users('Password must be at least 6 characters.', 'danger');
    }

    $checkUsername = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
    if (!$checkUsername) {
        redirect_users('Error preparing username check: ' . $conn->error, 'danger');
    }
    $checkUsername->bind_param('s', $username);
    $checkUsername->execute();
    $usernameResult = $checkUsername->get_result();
    if ($usernameResult && $usernameResult->num_rows > 0) {
        $checkUsername->close();
        redirect_users('Username already exists. Please choose a different username.', 'danger');
    }
    $checkUsername->close();

    $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    if (!$checkEmail) {
        redirect_users('Error preparing email check: ' . $conn->error, 'danger');
    }
    $checkEmail->bind_param('s', $email);
    $checkEmail->execute();
    $emailResult = $checkEmail->get_result();
    if ($emailResult && $emailResult->num_rows > 0) {
        $checkEmail->close();
        redirect_users('Email already exists. Please use a different email.', 'danger');
    }
    $checkEmail->close();

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users (
            username,
            full_name,
            email,
            role,
            phone,
            password_hash,
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        redirect_users('Error preparing user insert: ' . $conn->error, 'danger');
    }

    $stmt->bind_param(
        'ssssssi',
        $username,
        $full_name,
        $email,
        $role,
        $phone,
        $hashed_password,
        $is_active
    );

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        redirect_users('Error adding user: ' . $error, 'danger');
    }

    $new_user_id = (int)$stmt->insert_id;
    $stmt->close();

    if (function_exists('log_action') && !empty($_SESSION['user_id'])) {
        log_action(
            (int)$_SESSION['user_id'],
            'Add User',
            'users',
            $new_user_id,
            "Added user: {$username}"
        );
    }

    $emailResult = send_new_user_credentials_email(
        $email,
        $full_name,
        $username,
        $password,
        $role
    );

    if ($emailResult === true) {
        redirect_users('User added successfully and credentials email sent.', 'success');
    }

    $mailError = is_string($emailResult) ? $emailResult : 'Unknown mail error';
    redirect_users('User added successfully, but email could not be sent: ' . $mailError, 'danger');
}

/* ============================================================
   HANDLE EDIT USER
============================================================ */
if (isset($_POST['edit_user'])) {
    $user_id    = (int)($_POST['user_id'] ?? 0);
    $username   = clean_input($_POST['username'] ?? '');
    $full_name  = clean_input($_POST['full_name'] ?? '');
    $email      = clean_input($_POST['email'] ?? '');
    $role       = clean_input($_POST['role'] ?? '');
    $phone      = clean_input($_POST['phone'] ?? '');
    $password   = (string)($_POST['password'] ?? '');
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($user_id <= 0) {
        redirect_users('Invalid user selected.', 'danger');
    }

    if ($username === '' || $full_name === '' || $email === '' || $role === '') {
        redirect_users('All required fields must be filled.', 'danger', "../users?edit={$user_id}");
    }

    if (!valid_email($email)) {
        redirect_users('Please enter a valid email address.', 'danger', "../users?edit={$user_id}");
    }

    if (!in_array($role, ims_all_roles($conn), true)) {
        redirect_users('Please select a valid role.', 'danger', "../users?edit={$user_id}");
    }

    if ($user_id === (int)($_SESSION['user_id'] ?? 0) && ($is_active === 0 || $role !== 'Administrator')) {
        redirect_users('You cannot deactivate or demote your own account.', 'danger', "../users?edit={$user_id}");
    }

    $checkUsername = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
    if (!$checkUsername) {
        redirect_users('Error preparing username check: ' . $conn->error, 'danger', "../users?edit={$user_id}");
    }
    $checkUsername->bind_param('si', $username, $user_id);
    $checkUsername->execute();
    $usernameResult = $checkUsername->get_result();
    if ($usernameResult && $usernameResult->num_rows > 0) {
        $checkUsername->close();
        redirect_users('Username already exists. Please choose a different username.', 'danger', "../users?edit={$user_id}");
    }
    $checkUsername->close();

    $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
    if (!$checkEmail) {
        redirect_users('Error preparing email check: ' . $conn->error, 'danger', "../users?edit={$user_id}");
    }
    $checkEmail->bind_param('si', $email, $user_id);
    $checkEmail->execute();
    $emailResult = $checkEmail->get_result();
    if ($emailResult && $emailResult->num_rows > 0) {
        $checkEmail->close();
        redirect_users('Email already exists. Please use a different email.', 'danger', "../users?edit={$user_id}");
    }
    $checkEmail->close();

    if ($password !== '') {
        if (strlen($password) < 6) {
            redirect_users('Password must be at least 6 characters.', 'danger', "../users?edit={$user_id}");
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE users
            SET username = ?, full_name = ?, email = ?, role = ?, phone = ?, password_hash = ?, is_active = ?
            WHERE user_id = ?
        ");

        if (!$stmt) {
            redirect_users('Error preparing user update: ' . $conn->error, 'danger', "../users?edit={$user_id}");
        }

        $stmt->bind_param(
            'ssssssii',
            $username,
            $full_name,
            $email,
            $role,
            $phone,
            $hashed_password,
            $is_active,
            $user_id
        );
    } else {
        $stmt = $conn->prepare("
            UPDATE users
            SET username = ?, full_name = ?, email = ?, role = ?, phone = ?, is_active = ?
            WHERE user_id = ?
        ");

        if (!$stmt) {
            redirect_users('Error preparing user update: ' . $conn->error, 'danger', "../users?edit={$user_id}");
        }

        $stmt->bind_param(
            'sssssii',
            $username,
            $full_name,
            $email,
            $role,
            $phone,
            $is_active,
            $user_id
        );
    }

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        redirect_users('Error updating user: ' . $error, 'danger', "../users?edit={$user_id}");
    }

    $stmt->close();

    if (function_exists('log_action') && !empty($_SESSION['user_id'])) {
        log_action(
            (int)$_SESSION['user_id'],
            'Edit User',
            'users',
            $user_id,
            "Updated user: {$username}"
        );
    }

    redirect_users('User updated successfully.', 'success');
}

/* ============================================================
   HANDLE DELETE USER(S)
   Accepts user_ids[] (multi-select) or a single user_id.
============================================================ */
if (isset($_POST['delete_user'])) {
    $maxBulkDelete = 100;
    $rawIds = $_POST['user_ids'] ?? [$_POST['user_id'] ?? 0];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }

    $userIds = array_values(array_unique(array_filter(
        array_map(static fn ($id): int => is_scalar($id) ? (int)$id : 0, $rawIds),
        static fn (int $id): bool => $id > 0
    )));

    if ($userIds === []) {
        redirect_users('No users selected.', 'danger');
    }

    if (count($userIds) > $maxBulkDelete) {
        redirect_users("You can delete at most {$maxBulkDelete} users at a time.", 'danger');
    }

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId > 0 && in_array($currentUserId, $userIds, true)) {
        redirect_users('Cannot delete your own account. Deselect it and try again.', 'danger');
    }

    $userStmt = $conn->prepare("SELECT username FROM users WHERE user_id = ? LIMIT 1");
    $deleteStmt = $conn->prepare("DELETE FROM users WHERE user_id = ? LIMIT 1");
    if (!$userStmt || !$deleteStmt) {
        redirect_users('Error preparing delete: ' . $conn->error, 'danger');
    }

    $userId = 0;
    $userStmt->bind_param('i', $userId);
    $deleteStmt->bind_param('i', $userId);

    $deleted = [];
    $failed = [];

    // Each user is deleted on its own, so one account that is still referenced
    // elsewhere does not stop the rest of the selection from being removed.
    foreach ($userIds as $userId) {
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userData = $userResult ? $userResult->fetch_assoc() : null;

        if ($userData === null) {
            $failed[] = "#{$userId} (not found)";
            continue;
        }

        $username = (string)$userData['username'];

        if (!$deleteStmt->execute() || $deleteStmt->affected_rows < 1) {
            error_log("Delete user {$userId} failed: " . ($deleteStmt->error ?: $conn->error));
            $failed[] = $username;
            continue;
        }

        $deleted[] = $username;

        if (function_exists('log_action') && $currentUserId > 0) {
            log_action(
                $currentUserId,
                'Delete User',
                'users',
                $userId,
                "Deleted user: {$username}"
            );
        }
    }

    $userStmt->close();
    $deleteStmt->close();

    $deletedCount = count($deleted);

    if ($failed === []) {
        redirect_users(
            $deletedCount === 1 ? 'User deleted successfully.' : "{$deletedCount} users deleted successfully.",
            'success'
        );
    }

    $failedList = implode(', ', array_slice($failed, 0, 10)) . (count($failed) > 10 ? ', ...' : '');
    $message = $deletedCount > 0
        ? "{$deletedCount} user(s) deleted. Could not delete: {$failedList}."
        : "Could not delete: {$failedList}. The account(s) may still be linked to other records.";

    redirect_users($message, $deletedCount > 0 ? 'warning' : 'danger');
}

/* ============================================================
   FALLBACK
============================================================ */
header('Location: ../users');
exit();