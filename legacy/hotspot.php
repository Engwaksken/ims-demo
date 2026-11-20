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

// Nothing about this page or the device leaks to other sites, caches or search engines.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

/*
 * Device details from the captive portal redirect (?mac=..&ip=..&redirurl=..)
 * are kept in the session and the browser is sent straight to a clean
 * /hotspot address, so they never stay in the address bar, history or links.
 */
if (isset($_GET['mac']) || isset($_GET['ip']) || isset($_GET['redirurl']) || isset($_GET['zone'])) {
    $gIp = (string)($_GET['ip'] ?? '');
    $gRedir = (string)($_GET['redirurl'] ?? '');
    $_SESSION['hotspot_ctx'] = [
        'mac' => hubnet_normalize_mac((string)($_GET['mac'] ?? '')),
        'ip' => filter_var($gIp, FILTER_VALIDATE_IP) ? $gIp : '',
        'redir' => preg_match('#^https?://[^\s"<>]+$#i', $gRedir) ? $gRedir : '',
        'at' => time(),
    ];
    unset($_SESSION['hotspot_pending'], $_SESSION['hotspot_done']);
    header('Location: hotspot', true, 303);
    exit;
}

$ctx = (array)($_SESSION['hotspot_ctx'] ?? []);
if ((int)($ctx['at'] ?? 0) < time() - 1800) {
    $ctx = []; // portal details older than 30 minutes: start again from the Wi-Fi
}
$mac = (string)($ctx['mac'] ?? '');
$ip = (string)($ctx['ip'] ?? '');
$redir = (string)($ctx['redir'] ?? '');

$error = '';
$done = $_SESSION['hotspot_done'] ?? null;
$pending = false;

/** Runs a sign-in; on "device not seen yet" keeps the form data and lets the page retry. */
$attempt = static function (array $data) use ($conn, $mac, $ip, &$error, &$pending): void {
    $result = hubinet_hotspot_signin($conn, $data, $mac, $ip);
    if ($result['ok']) {
        $_SESSION['hotspot_tries'] = [];
        unset($_SESSION['hotspot_pending']);
        $_SESSION['hotspot_done'] = ['message' => $result['message'], 'until' => $result['until'] ?? null, 'at' => time()];
        header('Location: hotspot', true, 303);
        exit;
    }
    if (!empty($result['pending'])) {
        $since = (int)($_SESSION['hotspot_pending']['since'] ?? time());
        if ($since < time() - 180) {
            unset($_SESSION['hotspot_pending']);
            $error = 'We could not find your device on the hub Wi-Fi. Make sure you are connected to the hub network (not mobile data), then try again.';
            return;
        }
        $_SESSION['hotspot_pending'] = ['data' => $data, 'since' => $since];
        $pending = true;
        return;
    }
    unset($_SESSION['hotspot_pending']);
    $tries = array_filter((array)($_SESSION['hotspot_tries'] ?? []), fn($t) => $t > time() - 600);
    $tries[] = time();
    $_SESSION['hotspot_tries'] = array_values($tries);
    $error = $result['message'];
};

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Simple brute-force guard on the hotspot password: 8 tries per 10 minutes per browser.
    $tries = array_filter((array)($_SESSION['hotspot_tries'] ?? []), fn($t) => $t > time() - 600);
    if (count($tries) >= 8) {
        $error = 'Too many attempts. Please wait a few minutes and try again, or ask at reception.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        unset($_SESSION['hotspot_done']);
        $attempt(array_intersect_key($_POST, array_flip(['full_name', 'phone', 'email', 'hotspot_password', 'accept_terms'])));
    }
} elseif ($ready && !empty($_SESSION['hotspot_pending']['data'])) {
    // Waiting for pfSense to report the device: retry with the saved details.
    $attempt((array)$_SESSION['hotspot_pending']['data']);
}

$enabled = $ready && $settings['hotspot_enabled'];
// After sign-in guests land on the organisation's website first.
$landing = $ready ? hubinet_hotspot_landing($settings) : '';
if ($landing === '') {
    $landing = $redir !== '' ? $redir : 'http://neverssl.com/';
}
if ($done && (int)($done['at'] ?? 0) < time() - 3600) {
    $done = null;
    unset($_SESSION['hotspot_done']);
}
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
            <p class="muted">Your device is being connected. You will be taken to our website in</p>
            <p class="count" id="count" aria-live="polite">60</p>
            <?php if (!empty($done['until'])): ?>
                <p class="muted">Access is valid until <strong><?= $h(date('D j M, H:i', strtotime((string)$done['until']))) ?></strong>.</p>
            <?php endif; ?>
            <a class="btn" id="go" href="<?= $h($landing) ?>" rel="noreferrer">Continue</a>
            <p class="muted">If a page does not load yet, wait a moment and try again.</p>
        </div>
        <script>
        (function () {
            var n = 60, el = document.getElementById('count');
            var t = setInterval(function () { n--; el.textContent = n > 0 ? n : '0'; if (n <= 0) { clearInterval(t); location.href = document.getElementById('go').href; } }, 1000);
        })();
        </script>

    <?php elseif ($pending): ?>
        <div class="center">
            <div class="ok-icon" aria-hidden="true" style="background:#eef5fb;color:#1769aa">&#8635;</div>
            <h2 style="margin:0 0 6px">Checking your device&hellip;</h2>
            <p class="muted">We are confirming your device is on the hub Wi-Fi. Keep this page open; it continues by itself.</p>
        </div>
        <script>setTimeout(function () { location.replace('hotspot'); }, 10000);</script>

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
