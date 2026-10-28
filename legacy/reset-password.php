<?php
require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
require_once 'includes/password-reset.php';
require_once 'includes/auth-layout.php';

redirect_if_logged_in();

// Keep the token out of Referer headers sent to the CDN.
header('Referrer-Policy: no-referrer');

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipKey = 'pwreset:submit:' . ims_client_ip();
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (ims_rate_limit_too_many($ipKey, 10, PR_RATE_WINDOW)) {
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
    } else {
        $error = (string)pr_complete($conn, $token, (string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''));
        if ($error === '') {
            $_SESSION['login_success'] = 'Your password has been changed. Log in with your new password.';
            header('Location: login');
            exit;
        }
        ims_rate_limit_hit($ipKey, PR_RATE_WINDOW);
    }
}

$reset = pr_find($conn, $token);

auth_layout_start('Reset Password', 'Choose a new password');
?>
            <?php if ($reset === null): ?>
                <?= auth_alert('danger', 'This reset link is invalid, has already been used or has expired.') ?>
                <a href="forgot-password" class="btn btn-primary btn-block"><i class="fas fa-redo" aria-hidden="true"></i> Request a new link</a>
                <div class="auth-links"><a href="login"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to login</a></div>
            <?php else: ?>
                <?php if ($error !== ''): ?>
                    <?= auth_alert('danger', $error) ?>
                <?php endif; ?>
                <p class="auth-intro">Choose a new password for <strong><?= htmlspecialchars((string)$reset['username'], ENT_QUOTES, 'UTF-8') ?></strong>.</p>

                <form method="POST" action="reset-password" id="resetForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" name="username" value="<?= htmlspecialchars((string)$reset['username'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" hidden>
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="password" name="password" class="form-control" required
                                   minlength="<?= PR_MIN_PASSWORD ?>" maxlength="<?= PR_MAX_PASSWORD_BYTES ?>" autocomplete="new-password" autofocus>
                            <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Show password">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <small class="auth-hint">At least <?= PR_MIN_PASSWORD ?> characters. Avoid passwords you use elsewhere.</small>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirm New Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control" required
                                   minlength="<?= PR_MIN_PASSWORD ?>" maxlength="<?= PR_MAX_PASSWORD_BYTES ?>" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-toggle-password="password_confirm" aria-label="Show password">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-key" aria-hidden="true"></i> Change password
                    </button>
                </form>
                <div class="auth-links"><a href="login"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to login</a></div>
                <script>
                    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
                            var show = input.type === 'password';
                            input.type = show ? 'text' : 'password';
                            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                            btn.querySelector('i').className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye');
                        });
                    });
                    document.getElementById('resetForm').addEventListener('submit', function (e) {
                        var p = document.getElementById('password'), c = document.getElementById('password_confirm');
                        c.setCustomValidity(p.value === c.value ? '' : 'The two passwords do not match.');
                        if (!c.reportValidity()) e.preventDefault();
                    });
                    document.getElementById('password_confirm').addEventListener('input', function () { this.setCustomValidity(''); });
                </script>
            <?php endif; ?>
<?php auth_layout_end(); ?>
