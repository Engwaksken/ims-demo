<?php
require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
require_once 'includes/password-reset.php';
require_once 'includes/auth-layout.php';

redirect_if_logged_in();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['forgot_error'] = 'Your session expired. Please try again.';
        header('Location: forgot-password');
        exit;
    }

    $identifier = trim((string)($_POST['identifier'] ?? ''));
    if ($identifier === '' || mb_strlen($identifier) > 190) {
        $_SESSION['forgot_error'] = 'Enter your username or email address.';
        header('Location: forgot-password');
        exit;
    }

    if (pr_request_throttled($identifier)) {
        $_SESSION['forgot_error'] = 'Too many reset requests. Please wait 15 minutes and try again.';
        header('Location: forgot-password');
        exit;
    }

    try {
        pr_request($conn, $identifier);
    } catch (Throwable $e) {
        // Same answer either way, so a failure can't reveal whether the account exists.
        error_log(sprintf('Password reset request failed; class=%s; file=%s; line=%d', get_class($e), basename($e->getFile()), $e->getLine()));
    }

    $_SESSION['forgot_sent'] = true;
    header('Location: forgot-password');
    exit;
}

$error = (string)($_SESSION['forgot_error'] ?? '');
$sent = !empty($_SESSION['forgot_sent']);
unset($_SESSION['forgot_error'], $_SESSION['forgot_sent']);

auth_layout_start('Forgot Password', 'Reset your password');
?>
            <?php if ($error !== ''): ?>
                <?= auth_alert('danger', $error) ?>
            <?php endif; ?>

            <?php if ($sent): ?>
                <?= auth_alert('success', 'If an active account matches what you entered, we have emailed it a link to reset the password. The link expires in ' . PR_TOKEN_MINUTES . ' minutes.') ?>
                <p class="auth-intro">Not received it after a few minutes? Check your spam folder, or try again.</p>
                <a href="login" class="btn btn-primary btn-block"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to login</a>
                <div class="auth-links"><a href="forgot-password">Send another link</a></div>
            <?php else: ?>
                <p class="auth-intro">Enter your username or email address and we'll email you a link to choose a new password.</p>

                <form method="POST" action="forgot-password" id="forgotForm">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="identifier">Username or Email</label>
                        <input type="text" id="identifier" name="identifier" class="form-control" maxlength="190"
                               placeholder="Enter your username or email" autocomplete="username" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" id="forgotBtn">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i> Email me a reset link
                    </button>
                </form>
                <div class="auth-links"><a href="login"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to login</a></div>
                <script>
                    document.getElementById('forgotForm').addEventListener('submit', function () {
                        var btn = document.getElementById('forgotBtn');
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Sending...';
                    });
                </script>
            <?php endif; ?>
<?php auth_layout_end(); ?>
