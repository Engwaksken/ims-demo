<?php
require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
//session_start();

// Redirect if already logged in (role-based)
redirect_if_logged_in();

// Fetch and clear login error
$error = $_SESSION['login_error'] ?? '';
$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_success']);
$siteBranding = $GLOBALS['IMS_SITE_BRANDING'] ?? ['name' => (string)SITE_NAME, 'logo' => '/images/logo.png', 'favicon' => '/images/favicon.png'];
$globalSiteName = (string)($siteBranding['name'] ?? SITE_NAME);
$faviconQuery = [];
parse_str((string)(parse_url((string)$siteBranding['favicon'], PHP_URL_QUERY) ?? ''), $faviconQuery);
$faviconFile = (string)($faviconQuery['file'] ?? $siteBranding['favicon']);
$faviconType = strtolower(pathinfo($faviconFile, PATHINFO_EXTENSION)) === 'ico' ? 'image/x-icon' : 'image/png';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($globalSiteName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= htmlspecialchars((string)$siteBranding['favicon'], ENT_QUOTES, 'UTF-8') ?>" type="<?= $faviconType ?>">
    <link rel="stylesheet" href="css/style.css">
    <?php echo ims_theme_style_tag((array)($siteBranding['theme'] ?? [])); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="js/accessibility.js"></script>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>
                    <img src="<?= htmlspecialchars((string)$siteBranding['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($globalSiteName, ENT_QUOTES, 'UTF-8') ?> Logo" onerror="this.style.display='none'">
                </h1>
                <p><?= htmlspecialchars($globalSiteName, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" role="status">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <div class="alert alert-info" style="margin-bottom: 20px;">
                
                <span>You'll receive a verification code via email after login.</span>
            </div>
            
            <form method="POST" action="includes/login-process.php" id="loginForm">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           class="form-control" 
                           placeholder="Enter your username or email"
                           required 
                           autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control" 
                               placeholder="Enter your password"
                               required>
                        <button type="button" 
                                class="password-toggle" 
                                onclick="togglePassword()"
                                title="Show/Hide Password"
                                aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>

            <div class="login-forgot">
                <a href="forgot-password">Forgot your password?</a>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle Password Visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Keyboard support for toggle button
        document.querySelector('.password-toggle').addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                togglePassword();
            }
        });
        
        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const loginBtn = document.getElementById('loginBtn');
            loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending verification code...';
            loginBtn.classList.add('btn-loading');
            loginBtn.disabled = true;
        });
        
        // Auto-dismiss error alert after 5 seconds
        const errorAlert = document.getElementById('errorAlert');
        if (errorAlert) {
            setTimeout(function() {
                errorAlert.style.opacity = '0';
                errorAlert.style.transition = 'opacity 0.3s';
                setTimeout(function() {
                    errorAlert.remove();
                }, 300);
            }, 5000);
        }
        
        // Prevent multiple form submissions
        let formSubmitted = false;
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (formSubmitted) {
                e.preventDefault();
                return false;
            }
            formSubmitted = true;
        });
    </script>
</body>
</html>
