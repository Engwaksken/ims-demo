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
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg,#ff6b35 0%, #f39c12 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .code-box { background: white; border: 2px dashed#ff6b35; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
                .code { font-size: 32px; font-weight: bold; color:#ff6b35; letter-spacing: 5px; }
                .footer { text-align: center; margin-top: 20px; color: #7f8c8d; font-size: 12px; }
                .info { background: #d1ecf1; border-left: 4px solid #0c5460; padding: 15px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . htmlspecialchars($systemName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>
                    <p>New Verification Code</p>
                </div>
                <div class="content">
                    <p>Hello <strong>' . htmlspecialchars($user['full_name']) . '</strong>,</p>
                    
                    <p>You requested a new verification code. Please use the code below to complete your login:</p>
                    
                    <div class="code-box">
                        <div class="code">' . $verification_code . '</div>
                    </div>
                    
                    <p><strong>This code will expire in 10 minutes.</strong></p>
                    
                    <div class="info">
                        <strong>Note:</strong> This is a new code. Any previous codes have been invalidated.
                    </div>
                    
                    <p>Request Details:</p>
                    <ul>
                        <li><strong>Time:</strong> ' . date('d M Y, h:i A') . '</li>
                        <li><strong>IP Address:</strong> ' . $_SERVER['REMOTE_ADDR'] . '</li>
                    </ul>
                </div>
                <div class="footer">
                    <p>This is an automated message from ' . htmlspecialchars($systemName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '. Please do not reply to this email.</p>
                    <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($systemName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
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
