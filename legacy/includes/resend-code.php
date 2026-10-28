<?php
// Backend - Resend Verification Code
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';
$systemName = (string)($GLOBALS['IMS_SITE_BRANDING']['name'] ?? SITE_NAME);

header('Content-Type: application/json');

// Check if pending verification exists
if (!isset($_SESSION['pending_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['pending_user_id'];

    // Limit resends (mail flooding / code farming): 3 per 10 minutes.
    $resendKey = 'resend:' . (int) $user_id;
    if (ims_rate_limit_too_many($resendKey, 3, 600)) {
        echo json_encode(['success' => false, 'message' => 'Too many code requests. Please wait a few minutes.']);
        exit();
    }
    ims_rate_limit_hit($resendKey, 600);
    
    // Get user details
    $stmt = $conn->prepare("SELECT email, full_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Generate new verification code
        $verification_code = (string) random_int(100000, 999999);
        $code_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // Update session with new code
        $_SESSION['verification_code'] = $verification_code;
        $_SESSION['verification_expiry'] = $code_expiry;
        
        // Send verification email
        $subject = 'New Login Verification Code - ' . $systemName;
        $body = email_wrapper('
            <p>Hello <strong>' . htmlspecialchars((string)$user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>,</p>
            <p>You requested a new verification code. Please use the code below to complete your login:</p>
            <div class="code-box">
                <div class="code">' . $verification_code . '</div>
            </div>
            <p><strong>This code will expire in 10 minutes.</strong></p>
            <div class="info-box">
                <strong>Note:</strong> This is a new code. Any previous codes have been invalidated.
            </div>
            <div class="info-box">
                <div><strong>Time:</strong> ' . date('d M Y, h:i A') . '</div>
                <div><strong>IP Address:</strong> ' . htmlspecialchars((string)($_SERVER['REMOTE_ADDR'] ?? 'Unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>
            </div>
        ', 'New Verification Code');
        
        $altBody = "Hello " . $user['full_name'] . ",\n\n";
        $altBody .= "Your new verification code is: " . $verification_code . "\n\n";
        $altBody .= "This code will expire in 10 minutes.\n\n";
        $altBody .= "This is a new code. Any previous codes have been invalidated.\n\n";
        $altBody .= "- " . $systemName;
        
        $emailResult = sendEmail($user['email'], $subject, $body, $altBody);
        
        if ($emailResult === true) {
            // Log code resent
            log_action($user_id, 'Verification Code Resent', 'users', $user_id, 'New verification code sent');
            
            echo json_encode(['success' => true, 'message' => 'New verification code sent to your email']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error sending email. Please try again.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
