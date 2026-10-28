<?php
session_start();
require_once 'includes/config.php';

if (empty($_SESSION['draft_saved'])) {
    header("Location: opportunities");
    exit();
}

$draft_id      = $_SESSION['draft_id'];
$startup_name  = $_SESSION['draft_startup'];
$email         = $_SESSION['draft_email'];
$is_new_user   = $_SESSION['draft_is_new_user'];
$temp_password = $_SESSION['draft_temp_pass']; 

unset(
    $_SESSION['draft_saved'],
    $_SESSION['draft_id'],
    $_SESSION['draft_startup'],
    $_SESSION['draft_email'],
    $_SESSION['draft_is_new_user'],
    $_SESSION['draft_temp_pass']
);

$reference = 'DRAFT-' . str_pad($draft_id, 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Saved - <?php echo htmlspecialchars(ims_brand_name(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(ims_brand()['favicon'], ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
/* Links: no underlines (matches css/style.css); visible keyboard focus. */
a, a:hover, a:focus, a:active, a:visited { text-decoration: none; }
/* Accent colours follow the configured site theme, with the original palette as fallback. */
:root {
    --ds-accent:   var(--brand-600, #ff5722);
    --ds-accent-2: var(--brand-secondary, var(--brand-500, #ff9800));
}
:where(a):focus-visible { outline: 2px solid var(--ds-accent); outline-offset: 2px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .wrapper {
            max-width: 680px;
            width: 100%;
        }

        /* -- Success banner -- */
        .banner {
            background: linear-gradient(135deg, var(--ds-accent) 0%, var(--ds-accent-2) 100%);
            color: white;
            border-radius: 14px 14px 0 0;
            padding: 20px 25px 20px;
            text-align: center;
        }

        .banner-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
        }

        .banner h1 { font-size: 28px; margin-bottom: 8px; }
        .banner p  { opacity: .9; font-size: 15px; }

        /* -- Card body -- */
        .body {
            background: white;
            border-radius: 0 0 14px 14px;
            padding: 35px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .ref-tag {
            background: #eaf4fb;
            border: 1px solid #bee5f8;
            color: var(--ds-accent-2);
            border-radius: 6px;
            padding: 8px 14px;
            display: inline-block;
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 25px;
        }

        /* -- Credential box (only shown for new users) -- */
        .cred-box {
            background: #fff8e1;
            border: 2px solid #F39C12;
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 28px;
        }

        .cred-box h3 {
            color: #e67e22;
            font-size: 17px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cred-table { width: 100%; border-collapse: collapse; }
        .cred-table td { padding: 8px 10px; font-size: 15px; vertical-align: middle; }
        .cred-table td:first-child { color: #7f8c8d; width: 35%; font-weight: 600; }

        .password-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .password-val {
            font-family: 'Courier New', Courier, monospace;
            font-size: 18px;
            font-weight: 700;
            color: #c0392b;
            letter-spacing: 2px;
            background: #fff;
            border: 2px dashed #e74c3c;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            user-select: all;
        }

        .copy-btn {
            background: none;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 13px;
            color: #555;
            transition: all .2s;
        }
        .copy-btn:hover { background: #ecf0f1; }
        .copy-btn.copied { color: #27AE60; border-color: #27AE60; }

        .cred-note {
            margin-top: 14px;
            background: #fdecea;
            border-left: 4px solid #e74c3c;
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            color: #7d1a1a;
        }

        /* -- Steps -- */
        .steps { margin-bottom: 28px; }
        .steps h3 { font-size: 16px; color: #2c3e50; margin-bottom: 16px; font-weight: 700; }

        .step-item {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .step-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--ds-accent), var(--ds-accent-2));
            color: white;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .step-text { font-size: 14px; color: #555; line-height: 1.5; padding-top: 5px; }
        .step-text strong { color: #2c3e50; }

        /* -- Buttons -- */
        .btn-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .btn {
            flex: 1;
            min-width: 180px;
            padding: 13px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all .25s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--ds-accent), var(--ds-accent-2));
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--ds-accent), var(--ds-accent-2));
            color: white;
        }

        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.15); }

        /* -- Email notice -- */
        .email-notice {
            margin-top: 24px;
            font-size: 13px;
            color: #7f8c8d;
            text-align: center;
            border-top: 1px solid #ecf0f1;
            padding-top: 18px;
        }

        @media (max-width: 480px) {
            .btn-row { flex-direction: column; }
        }
    </style>
    <?php echo ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])); ?>
<script src="js/accessibility.js"></script>
</head>
<body>
<div class="wrapper">
    <div class="banner">
        <div class="banner-icon"><i class="fas fa-save"></i></div>
        <h1>Draft Saved!</h1>
        <p>Your application for <strong><?php echo htmlspecialchars($startup_name); ?></strong> has been saved as a draft.</p>
    </div>

    <div class="body">
        <div>
            <span class="ref-tag"><i class="fas fa-hashtag"></i> <?php echo $reference; ?></span>
        </div>

        <?php if ($is_new_user && $temp_password): ?>
        <!-- -- NEW ACCOUNT CREDENTIALS -- -->
        <div class="cred-box">
            <h3><i class="fas fa-key"></i> Your Account Has Been Created</h3>

            <table class="cred-table">
                <tr>
                    <td>Email / Username</td>
                    <td><strong><?php echo htmlspecialchars($email); ?></strong></td>
                </tr>
                <tr>
                    <td>Temporary Password</td>
                    <td>
                        <div class="password-cell">
                            <span class="password-val" id="tempPass"><?php echo htmlspecialchars($temp_password); ?></span>
                            <button class="copy-btn" onclick="copyPass()" id="copyBtn">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="cred-note">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Write this down now.</strong>
                This password is shown only once and has also been sent to your email.
                You will need it to log in and finalize your application.
            </div>
        </div>
        <?php else: ?>
        <!-- -- EXISTING ACCOUNT -- -->
        <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:16px;margin-bottom:24px;">
            <p style="color:#155724;font-size:14px;">
                <i class="fas fa-info-circle"></i>
                Your draft has been updated. Log in with your existing credentials to continue.
            </p>
        </div>
        <?php endif; ?>

        <!-- -- NEXT STEPS -- -->
        <div class="steps">
            <h3><i class="fas fa-list-ol" style="color:var(--ds-accent);margin-right:6px;"></i> What to do next</h3>

            <?php if ($is_new_user): ?>
            <div class="step-item">
                <div class="step-num">1</div>
                <div class="step-text">
                    <strong>Check your email</strong> at <em><?php echo htmlspecialchars($email); ?></em> - 
                    we've sent your temporary password there for safe keeping.
                </div>
            </div>
            <div class="step-item">
                <div class="step-num">2</div>
                <div class="step-text">
                    <strong>Log in</strong> using the credentials above, then change your password in account settings.
                </div>
            </div>
            <div class="step-item">
                <div class="step-num">3</div>
                <div class="step-text">
                    Go to <strong>My Applications</strong>, find this draft, click <em>Continue Application</em>,
                    complete any remaining fields, and hit <strong>Submit Application</strong>.
                </div>
            </div>
            <?php else: ?>
            <div class="step-item">
                <div class="step-num">1</div>
                <div class="step-text">
                    Go to <strong>My Applications</strong> and find this draft.
                </div>
            </div>
            <div class="step-item">
                <div class="step-num">2</div>
                <div class="step-text">
                    Click <strong>Continue Application</strong>, complete the remaining fields and submit.
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- -- BUTTONS -- -->
        <div class="btn-row">
            <a href="my-applications" class="btn btn-primary">
                <i class="fas fa-briefcase"></i> My Applications
            </a>
            <a href="login" class="btn btn-secondary">
                <i class="fas fa-sign-in-alt"></i> Log In Now
            </a>
        </div>

        <div class="email-notice">
            <i class="fas fa-envelope"></i>
            A copy of these details has been sent to <strong><?php echo htmlspecialchars($email); ?></strong>.
        </div>
    </div>
</div>

<script>
function copyPass() {
    const text = document.getElementById('tempPass').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copyBtn');
        btn.classList.add('copied');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
        }, 2500);
    });
}
</script>
</body>
</html>