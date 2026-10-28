<?php
require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
$siteBranding = $GLOBALS['IMS_SITE_BRANDING'] ?? ['name' => (string)SITE_NAME, 'logo' => '/images/logo.png', 'favicon' => '/images/favicon.png'];
$globalSiteName = (string)($siteBranding['name'] ?? SITE_NAME);

redirect_if_logged_in();


if (
    !isset($_SESSION['pending_user_id']) ||
    !isset($_SESSION['verification_code']) ||
    !isset($_SESSION['verification_expiry'])
) {
    $_SESSION['login_error'] = 'Session expired. Please login again.';
    header("Location: login");
    exit();
}


if (time() > strtotime($_SESSION['verification_expiry'])) {
    unset(
        $_SESSION['verification_code'],
        $_SESSION['verification_expiry'],
        $_SESSION['pending_user_id'],
        $_SESSION['pending_username'],
        $_SESSION['pending_full_name'],
        $_SESSION['pending_role']
    );

    $_SESSION['login_error'] = 'Verification code expired. Please login again.';
    header("Location: login");
    exit();
}


$error   = $_SESSION['verify_error'] ?? '';
$success = $_SESSION['verify_success'] ?? '';

unset($_SESSION['verify_error'], $_SESSION['verify_success']);


$user_email = '';

$stmt = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['pending_user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $user = $result->fetch_assoc();
    if (!empty($user['email']) && strpos($user['email'], '@') !== false) {
        [$local, $domain] = explode('@', $user['email'], 2);
        $user_email = substr($local, 0, 2) . '***@' . $domain;
    }
}
$stmt->close();

$remaining_seconds = max(0, strtotime($_SESSION['verification_expiry']) - time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - <?= htmlspecialchars($globalSiteName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= htmlspecialchars((string)$siteBranding['favicon'], ENT_QUOTES, 'UTF-8') ?>" type="<?= strtolower(pathinfo((string)$siteBranding['favicon'], PATHINFO_EXTENSION)) === 'ico' ? 'image/x-icon' : 'image/png' ?>">
    <link rel="stylesheet" href="css/style.css">
    <?php echo ims_theme_style_tag((array)($siteBranding['theme'] ?? [])); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .code-input-group {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 20px 0;
        }

        .code-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            border: 2px solid #ddd;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .code-input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .timer {
            text-align: center;
            margin: 15px 0;
            font-size: 14px;
            color: #7f8c8d;
        }

        .timer.warning {
            color: #E74C3C;
            font-weight: bold;
        }

        .resend-link {
            text-align: center;
            margin-top: 20px;
        }

        .resend-link button {
            background: none;
            border: none;
            color: var(--primary-color);
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .resend-link button:disabled {
            color: #95a5a6;
            cursor: not-allowed;
            text-decoration: none;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-size: 14px;
        }
    </style>
<script src="js/accessibility.js"></script>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>
                    <img src="<?= htmlspecialchars((string)$siteBranding['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($globalSiteName, ENT_QUOTES, 'UTF-8') ?> Logo" onerror="this.style.display='none'">
                </h1>
                <p>Email Verification</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <div class="info-box">
                <i class="fas fa-envelope"></i>
                We've sent a 6-digit verification code to <strong><?php echo htmlspecialchars($user_email); ?></strong>
            </div>

            <form method="POST" action="includes/verify-process.php" id="verifyForm">
                <div class="form-group">
                    <label for="code-1">Enter Verification Code</label>

                    <div class="code-input-group">
                        <input type="text" id="code-1" class="code-input" maxlength="1" inputmode="numeric" autocomplete="one-time-code" required>
                        <input type="text" id="code-2" class="code-input" maxlength="1" inputmode="numeric" required>
                        <input type="text" id="code-3" class="code-input" maxlength="1" inputmode="numeric" required>
                        <input type="text" id="code-4" class="code-input" maxlength="1" inputmode="numeric" required>
                        <input type="text" id="code-5" class="code-input" maxlength="1" inputmode="numeric" required>
                        <input type="text" id="code-6" class="code-input" maxlength="1" inputmode="numeric" required>
                    </div>

                    <input type="hidden" name="verification_code" id="fullCode">
                </div>

                <div class="timer" id="timer">
                    <i class="fas fa-clock"></i>
                    Code expires in <strong id="timeRemaining"><?php echo gmdate("i:s", $remaining_seconds); ?></strong>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="verifyBtn">
                    <i class="fas fa-check-circle"></i> Verify Code
                </button>

                <div class="resend-link">
                    <button type="button" id="resendBtn" onclick="resendCode()" disabled>
                        <i class="fas fa-redo"></i> Resend Code (<span id="resendTimer">60</span>s)
                    </button>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="login" style="color: #7f8c8d; text-decoration: none; font-size: 14px;">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        const verifyForm = document.getElementById('verifyForm');
        const verifyBtn = document.getElementById('verifyBtn');
        const codeInputs = document.querySelectorAll('.code-input');
        const fullCodeInput = document.getElementById('fullCode');

        function updateFullCode() {
            let code = '';
            codeInputs.forEach(input => {
                code += input.value.trim();
            });
            fullCodeInput.value = code;
            return code;
        }

        function isCodeComplete() {
            return updateFullCode().length === 6;
        }

        function submitVerificationForm() {
            const code = updateFullCode();

            if (code.length !== 6) {
                alert('Please enter all 6 digits of the verification code');
                const firstEmpty = Array.from(codeInputs).find(input => input.value.trim() === '');
                if (firstEmpty) {
                    firstEmpty.focus();
                }
                return;
            }

            verifyBtn.classList.add('btn-loading');
            verifyBtn.disabled = true;
            verifyForm.submit();
        }

        codeInputs.forEach((input, index) => {
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 1);

                if (this.value !== '' && index < codeInputs.length - 1) {
                    codeInputs[index + 1].focus();
                    codeInputs[index + 1].select();
                }

                updateFullCode();
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace') {
                    if (this.value === '' && index > 0) {
                        codeInputs[index - 1].focus();
                        codeInputs[index - 1].select();
                    }
                    return;
                }

                if (e.key === 'ArrowLeft' && index > 0) {
                    e.preventDefault();
                    codeInputs[index - 1].focus();
                    codeInputs[index - 1].select();
                    return;
                }

                if (e.key === 'ArrowRight' && index < codeInputs.length - 1) {
                    e.preventDefault();
                    codeInputs[index + 1].focus();
                    codeInputs[index + 1].select();
                    return;
                }

                if (e.key === 'Enter') {
                    e.preventDefault();
                    updateFullCode();

                    if (isCodeComplete()) {
                        submitVerificationForm();
                    } else if (index < codeInputs.length - 1) {
                        codeInputs[index + 1].focus();
                    }
                }
            });

            input.addEventListener('paste', function (e) {
                e.preventDefault();

                const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                const digits = pastedData.replace(/\D/g, '').slice(0, 6);

                if (!digits) return;

                codeInputs.forEach(box => box.value = '');

                digits.split('').forEach((digit, i) => {
                    if (codeInputs[i]) {
                        codeInputs[i].value = digit;
                    }
                });

                updateFullCode();

                const nextIndex = Math.min(digits.length, codeInputs.length - 1);
                codeInputs[nextIndex].focus();
                codeInputs[nextIndex].select();

                if (digits.length === 6) {
                    submitVerificationForm();
                }
            });

            input.addEventListener('focus', function () {
                this.select();
            });
        });

        verifyForm.addEventListener('submit', function (e) {
            const code = updateFullCode();

            if (code.length !== 6) {
                e.preventDefault();
                alert('Please enter all 6 digits of the verification code');
                return false;
            }

            verifyBtn.classList.add('btn-loading');
            verifyBtn.disabled = true;
        });

        let remainingSeconds = <?php echo (int)$remaining_seconds; ?>;
        const timerElement = document.getElementById('timeRemaining');
        const timerContainer = document.getElementById('timer');

        const countdown = setInterval(function () {
            remainingSeconds--;

            const minutes = Math.floor(Math.max(remainingSeconds, 0) / 60);
            const seconds = Math.max(remainingSeconds, 0) % 60;

            timerElement.textContent =
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;

            if (remainingSeconds <= 60) {
                timerContainer.classList.add('warning');
            }

            if (remainingSeconds <= 0) {
                clearInterval(countdown);
                alert('Verification code has expired. Please login again.');
                window.location.href = 'login';
            }
        }, 1000);

        let resendSeconds = 60;
        const resendBtn = document.getElementById('resendBtn');

        function startResendTimer() {
            const resendTimerSpan = document.getElementById('resendTimer');

            const resendCountdown = setInterval(function () {
                resendSeconds--;
                const currentTimer = document.getElementById('resendTimer');
                if (currentTimer) {
                    currentTimer.textContent = resendSeconds;
                }

                if (resendSeconds <= 0) {
                    clearInterval(resendCountdown);
                    resendBtn.disabled = false;
                    resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
                }
            }, 1000);
        }

        startResendTimer();

        function resendCode() {
            if (!confirm('Send a new verification code to your email?')) {
                return;
            }

            resendBtn.disabled = true;
            resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            fetch('includes/resend-code.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('New verification code sent to your email!');

                    resendSeconds = 60;
                    resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code (<span id="resendTimer">60</span>s)';
                    startResendTimer();

                    codeInputs.forEach(input => input.value = '');
                    updateFullCode();
                    codeInputs[0].focus();
                } else {
                    alert('Error: ' + (data.message || 'Unable to resend code.'));
                    resendBtn.disabled = false;
                    resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
                }
            })
            .catch(() => {
                alert('Error sending code. Please try again.');
                resendBtn.disabled = false;
                resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend Code';
            });
        }

        codeInputs[0].focus();

        const errorAlert = document.getElementById('errorAlert');
        if (errorAlert) {
            setTimeout(function () {
                errorAlert.style.opacity = '0';
                errorAlert.style.transition = 'opacity 0.3s';
                setTimeout(function () {
                    errorAlert.remove();
                }, 300);
            }, 5000);
        }

        const successAlert = document.getElementById('successAlert');
        if (successAlert) {
            setTimeout(function () {
                successAlert.style.opacity = '0';
                successAlert.style.transition = 'opacity 0.3s';
                setTimeout(function () {
                    successAlert.remove();
                }, 300);
            }, 5000);
        }
    </script>
</body>
</html>
