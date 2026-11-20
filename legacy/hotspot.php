<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Guest hotspot sign-in (public, no IMS account)
|--------------------------------------------------------------------------
| The pfSense captive portal page (tools/pfsense/ims-portal-page.html) sends
| devices that are not registered here with ?mac=..&ip=..&redirurl=..
| The guest enters their name, phone and the hotspot password; the device is
| registered as a hotspot guest for N hours and pfSense lets it through at
| its next check-in (within about a minute).
| Settings: Hub Team & Internet > Hotspot. Logic: includes/hub-internet.php.
| Self-contained page (inline CSS, no CDN): guests reach it before they are
| online, and only the IMS host is allowed through the portal.
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/hub-internet.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$h = 'hubinet_h';
$ready = isset($conn) && $conn instanceof mysqli && hubnet_ensure_schema($conn);
$settings = $ready ? hubnet_settings($conn) : null;
$brand = function_exists('ims_brand') ? ims_brand() : ['name' => 'Guest Wi-Fi'];
$orgName = (string)($brand['name'] ?? 'Guest Wi-Fi');

// Values from the captive portal redirect (kept through the POST).
$mac = hubnet_normalize_mac((string)($_POST['mac'] ?? $_GET['mac'] ?? ''));
$ip = (string)($_POST['ip'] ?? $_GET['ip'] ?? '');
$ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
$redir = (string)($_POST['redirurl'] ?? $_GET['redirurl'] ?? '');
$redir = preg_match('#^https?://[^\s"<>]+$#i', $redir) ? $redir : '';

$error = '';
$done = null;

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Simple brute-force guard on the hotspot password: 8 tries per 10 minutes per browser.
    $tries = array_filter((array)($_SESSION['hotspot_tries'] ?? []), fn($t) => $t > time() - 600);
    if (count($tries) >= 8) {
        $error = 'Too many attempts. Please wait a few minutes and try again, or ask at reception.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = hubinet_hotspot_signin($conn, $_POST, $mac, $ip ?: (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($result['ok']) {
            $_SESSION['hotspot_tries'] = [];
            $done = $result;
        } else {
            $tries[] = time();
            $_SESSION['hotspot_tries'] = array_values($tries);
            $error = $result['message'];
        }
    }
}

$enabled = $ready && $settings['hotspot_enabled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Guest Wi-Fi · <?= $h($orgName) ?></title>
<style>
:root { --brand: #c2410c; --brand-dark: #9a3412; --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f6f7f9; --ok: #15803d; --bad: #b42318; }
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
.card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(17,24,39,.08); overflow: hidden; }
.top { background: var(--brand); color: #fff; padding: 22px 24px; }
.top h1 { margin: 0; font-size: 1.35rem; }
.top p { margin: 4px 0 0; opacity: .9; font-size: .92rem; }
.body { padding: 22px 24px 26px; }
label { display: block; font-weight: 600; font-size: .9rem; margin: 14px 0 6px; }
input[type=text], input[type=tel], input[type=email], input[type=password] { width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 1rem; }
input:focus { outline: 2px solid var(--brand); outline-offset: 1px; border-color: var(--brand); }
.check { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; font-size: .88rem; color: var(--muted); }
.check input { margin-top: 3px; }
button, .btn { display: block; width: 100%; margin-top: 20px; padding: 14px; border: 0; border-radius: 10px; background: var(--brand); color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; }
button:hover, .btn:hover { background: var(--brand-dark); }
.alert { padding: 12px 14px; border-radius: 10px; font-size: .92rem; margin-bottom: 6px; }
.alert.bad { background: #fdf0ef; color: var(--bad); border: 1px solid #f1b8b2; }
.alert.info { background: #eef5fb; color: #12304a; border: 1px solid #bfd3e6; }
.ok-icon { width: 64px; height: 64px; border-radius: 50%; background: #e7f6ee; color: var(--ok); display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 4px auto 12px; }
.center { text-align: center; }
.muted { color: var(--muted); font-size: .88rem; }
.terms { max-height: 120px; overflow: auto; font-size: .82rem; color: var(--muted); background: #f9fafb; border: 1px solid var(--line); border-radius: 8px; padding: 10px; white-space: pre-wrap; margin-top: 14px; }
.count { font-size: 2rem; font-weight: 800; color: var(--brand); }
</style>
</head>
<body>
<main class="card">
    <div class="top">
        <h1>Guest Wi-Fi</h1>
        <p><?= $h($orgName) ?></p>
    </div>
    <div class="body">
    <?php if (!$enabled): ?>
        <div class="alert info">The guest hotspot is not available right now. Please ask at reception for access.</div>

    <?php elseif ($done): ?>
        <div class="center">
            <div class="ok-icon" aria-hidden="true">&#10003;</div>
            <h2 style="margin:0 0 6px"><?= $h($done['message']) ?></h2>
            <p class="muted">Your device is being connected. This usually takes up to a minute.</p>
            <p class="count" id="count" aria-live="polite">60</p>
            <?php if (!empty($done['until'])): ?>
                <p class="muted">Access is valid until <strong><?= $h(date('D j M, H:i', strtotime((string)$done['until']))) ?></strong>.</p>
            <?php endif; ?>
            <a class="btn" id="go" href="<?= $h($redir !== '' ? $redir : 'http://neverssl.com/') ?>">Start browsing</a>
            <p class="muted">If a page does not load yet, wait a moment and try again.</p>
        </div>
        <script>
        (function () {
            var n = 60, el = document.getElementById('count');
            var t = setInterval(function () { n--; el.textContent = n > 0 ? n : '0'; if (n <= 0) { clearInterval(t); location.href = document.getElementById('go').href; } }, 1000);
        })();
        </script>

    <?php elseif ($mac === ''): ?>
        <div class="alert info">
            <strong>Connect to the hub Wi-Fi first.</strong><br>
            <?php if ($settings['wifi_ssid'] !== ''): ?>Join the network <strong><?= $h($settings['wifi_ssid']) ?></strong>, then<?php else: ?>Join the hub Wi-Fi, then<?php endif; ?>
            open any website: you will be brought back here to sign in.
        </div>

    <?php else: ?>
        <?php if ($error !== ''): ?><div class="alert bad" role="alert"><?= $h($error) ?></div><?php endif; ?>
        <p class="muted">Sign in to use the internet. Access lasts <?= (int)$settings['hotspot_hours'] ?> hour<?= (int)$settings['hotspot_hours'] === 1 ? '' : 's' ?>.</p>
        <form method="post" action="hotspot" autocomplete="on">
            <?= csrf_field() ?>
            <input type="hidden" name="mac" value="<?= $h($mac) ?>">
            <input type="hidden" name="ip" value="<?= $h($ip) ?>">
            <input type="hidden" name="redirurl" value="<?= $h($redir) ?>">
            <label for="hsName">Your name</label>
            <input id="hsName" type="text" name="full_name" required maxlength="150" autocomplete="name" value="<?= $h($_POST['full_name'] ?? '') ?>">
            <label for="hsPhone">Phone number<?= $settings['hotspot_require_phone'] ? '' : ' (optional)' ?></label>
            <input id="hsPhone" type="tel" name="phone" <?= $settings['hotspot_require_phone'] ? 'required' : '' ?> maxlength="40" autocomplete="tel" value="<?= $h($_POST['phone'] ?? '') ?>">
            <label for="hsEmail">Email (optional)</label>
            <input id="hsEmail" type="email" name="email" maxlength="190" autocomplete="email" value="<?= $h($_POST['email'] ?? '') ?>">
            <?php if ($settings['hotspot_password'] !== ''): ?>
                <label for="hsPass">Hotspot password</label>
                <input id="hsPass" type="password" name="hotspot_password" required autocomplete="off" placeholder="Ask at reception">
            <?php endif; ?>
            <?php if ($settings['hotspot_terms'] !== ''): ?>
                <div class="terms"><?= $h($settings['hotspot_terms']) ?></div>
                <label class="check"><input type="checkbox" name="accept_terms" value="1" required> I accept the terms of use</label>
            <?php endif; ?>
            <button type="submit">Connect</button>
        </form>
    <?php endif; ?>
    </div>
</main>
</body>
</html>
