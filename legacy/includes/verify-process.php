<?php
//session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/../helpers/auth_redirect.php';


// Redirect if already logged in
redirect_if_logged_in();

// Check if pending verification exists
if (!isset($_SESSION['pending_user_id']) || !isset($_SESSION['verification_code'])) {
    $_SESSION['login_error'] = 'Session expired. Please login again.';
    header("Location: ../login");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Brute-force protection: 5 wrong codes end the pending login.
    $_SESSION['verification_attempts'] = (int)($_SESSION['verification_attempts'] ?? 0);

    if ($_SESSION['verification_attempts'] >= 5) {
        unset($_SESSION['verification_code'], $_SESSION['verification_expiry'], $_SESSION['pending_user_id'],
            $_SESSION['pending_username'], $_SESSION['pending_full_name'], $_SESSION['pending_role'],
            $_SESSION['pending_email'], $_SESSION['verification_attempts']);

        $_SESSION['login_error'] = 'Too many incorrect verification codes. Please login again.';
        header("Location: ../login");
        exit();
    }

    $entered_code = preg_replace('/\D/', '', sanitize_input($_POST['verification_code'] ?? ''));
    $stored_code = $_SESSION['verification_code'];
    $expiry_time = $_SESSION['verification_expiry'];
    
    // Validation
    if (empty($entered_code)) {
        $_SESSION['verify_error'] = 'Please enter the verification code';
        header("Location: ../verify");
        exit();
    }
    
    // Check if code has expired
    if (time() > strtotime($expiry_time)) {
        // Clear session data
        unset($_SESSION['verification_code']);
        unset($_SESSION['verification_expiry']);
        unset($_SESSION['pending_user_id']);
        unset($_SESSION['pending_username']);
        unset($_SESSION['pending_full_name']);
        unset($_SESSION['pending_role']);
        
        $_SESSION['login_error'] = 'Verification code has expired. Please login again.';
        header("Location: ../login");
        exit();
    }
    
    // Verify code
    if (is_string($stored_code) && $stored_code !== '' && hash_equals($stored_code, (string)$entered_code)) {
        // Code is correct - Complete login
        $user_id = $_SESSION['pending_user_id'];
        $username = $_SESSION['pending_username'];
        $full_name = $_SESSION['pending_full_name'];
        $role = $_SESSION['pending_role'];
        
        // New session id + CSRF token for the authenticated session (fixation protection)
        session_regenerate_id(true);
        ims_csrf_rotate();
        unset($_SESSION['verification_attempts']);

        // Set session variables
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['full_name'] = $full_name;
        // Added roles log in with the access of the built-in role they work like.
        ims_set_session_role($conn, (string)$role);
        
        // Clear verification data
        unset($_SESSION['verification_code']);
        unset($_SESSION['verification_expiry']);
        unset($_SESSION['pending_user_id']);
        unset($_SESSION['pending_username']);
        unset($_SESSION['pending_full_name']);
        unset($_SESSION['pending_role']);
        
        // Update last login
        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $update_stmt->bind_param("i", $user_id);
        $update_stmt->execute();
        
        // Log successful login
        log_action($user_id, 'Login', 'users', $user_id, 'User logged in successfully after email verification');
        
        // Send notification
        send_notification($user_id, 'Login successful! Welcome back.', 'success');
        
        // Redirect to dashboard
      redirect_by_role($role);

    } else {
        // Incorrect code
        $_SESSION['verification_attempts'] = (int)($_SESSION['verification_attempts'] ?? 0) + 1;
        $_SESSION['verify_error'] = 'Invalid verification code. Please try again.';
        
        // Log failed verification attempt
        log_action($_SESSION['pending_user_id'], 'Failed Verification', 'users', $_SESSION['pending_user_id'], 'Failed verification code attempt');
        
        header("Location: ../verify");
        exit();
    }
}

// If not POST request, redirect to verify page
header("Location: ../verify");
exit();
?>