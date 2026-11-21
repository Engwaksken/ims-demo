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
<?php
/* ---- Branding (Settings > General): colours, logo, contact. Values are validated
   here because they go straight into inline CSS. ---- */
$hex = static fn($v, string $d): string => preg_match('/\A#[0-9a-f]{6}\z/i', (string)$v) ? strtolower((string)$v) : $d;
$cBrand = $hex($brand['primary'] ?? '', '#c2410c');
$cBrandDark = $hex($brand['primary_dark'] ?? '', '#9a3412');
$cBrandLight = $hex($brand['primary_light'] ?? '', '#fff4ed');
// Text on the brand colour: white unless the colour is light.
[$r, $g, $b] = array_map('hexdec', str_split(substr($cBrand, 1), 2));
$cOnBrand = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 170 ? '#111827' : '#ffffff';
$font = str_replace(['<', '>', '{', '}', ';'], '', (string)($brand['font'] ?? '')) ?: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
$logo = (string)($brand['logo'] ?? '');
$logo = preg_match('#\A(/|https?://)[^\s"<>]+\z#i', $logo) ? $logo : '';
$favicon = (string)($brand['favicon'] ?? '');
$favicon = preg_match('#\A(/|https?://)[^\s"<>]+\z#i', $favicon) ? $favicon : '';
$supportPhone = (string)($brand['phone'] ?? '');
$supportEmail = filter_var($brand['email'] ?? '', FILTER_VALIDATE_EMAIL) ? (string)$brand['email'] : '';
$hours = $ready ? (int)$settings['hotspot_hours'] : 0;
$ssid = $ready ? (string)$settings['wifi_ssid'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?= $h($cBrand) ?>">
<title>Guest Wi-Fi · <?= $h($orgName) ?></title>
<?php if ($favicon !== ''): ?><link rel="icon" href="<?= $h($favicon) ?>"><?php endif; ?>
<style>
:root {
    --brand: <?= $cBrand ?>; --brand-dark: <?= $cBrandDark ?>; --brand-light: <?= $cBrandLight ?>; --on-brand: <?= $cOnBrand ?>;
    --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --field: #ffffff; --field-line: #d1d5db;
    --bg: #f3f4f6; --card: #ffffff; --soft: #f9fafb;
    --ok: #15803d; --ok-bg: #e7f6ee; --bad: #b42318; --bad-bg: #fdf0ef; --bad-line: #f1b8b2;
    --info: #12304a; --info-bg: #eef5fb; --info-line: #bfd3e6;
}
@media (prefers-color-scheme: dark) {
    :root {
        --ink: #f3f4f6; --muted: #9ca3af; --line: #2d3340; --field: #111827; --field-line: #374151;
        --bg: #0b0f17; --card: #161b26; --soft: #1d2330;
        --ok: #4ade80; --ok-bg: #12301f; --bad: #fca5a5; --bad-bg: #3a1616; --bad-line: #7f1d1d;
        --info: #cfe3f5; --info-bg: #13263a; --info-line: #24425f;
    }
}
* { box-sizing: border-box; }
html, body { height: 100%; }
body {
    margin: 0; font-family: <?= $font ?>; color: var(--ink); background: var(--bg);
    background-image: radial-gradient(1200px 500px at 50% -10%, color-mix(in srgb, var(--brand) 22%, transparent), transparent 70%);
    min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 24px 16px calc(24px + env(safe-area-inset-bottom)); -webkit-font-smoothing: antialiased;
}
.card { width: 100%; max-width: 440px; background: var(--card); border-radius: 20px; box-shadow: 0 1px 2px rgba(0,0,0,.06), 0 18px 48px rgba(17,24,39,.12); overflow: hidden; }
.head { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: var(--on-brand); padding: 28px 24px 24px; text-align: center; position: relative; }
.logo { display: inline-flex; align-items: center; justify-content: center; background: #fff; border-radius: 14px; padding: 10px 16px; min-height: 64px; max-width: 220px; box-shadow: 0 6px 18px rgba(0,0,0,.15); }
.logo img { display: block; max-height: 44px; max-width: 188px; object-fit: contain; }
.head h1 { margin: 16px 0 2px; font-size: 1.4rem; font-weight: 750; letter-spacing: -.01em; }
.pill { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 5px 12px; border-radius: 999px; background: rgba(255,255,255,.18); font-size: .82rem; font-weight: 600; }
.pill svg { width: 15px; height: 15px; }
.body { padding: 24px 24px 22px; }
.lead { margin: 0 0 4px; font-size: 1.05rem; font-weight: 700; }
.muted { color: var(--muted); font-size: .9rem; line-height: 1.5; margin: 0; }
label { display: block; font-weight: 600; font-size: .88rem; margin: 16px 0 6px; }
.opt { color: var(--muted); font-weight: 400; }
.field { position: relative; }
.field svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--muted); pointer-events: none; }
input[type=text], input[type=tel], input[type=email], input[type=password] {
    width: 100%; padding: 13px 14px 13px 42px; border: 1px solid var(--field-line); border-radius: 12px;
    font: inherit; font-size: 1rem; background: var(--field); color: var(--ink); transition: border-color .15s, box-shadow .15s;
}
input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 25%, transparent); }
.reveal { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: var(--muted); font: inherit; font-size: .8rem; font-weight: 600; padding: 8px 10px; cursor: pointer; width: auto; margin: 0; }
.check { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; font-size: .88rem; color: var(--muted); margin-top: 12px; }
.check input { margin-top: 3px; width: 18px; height: 18px; accent-color: var(--brand); flex: none; }
.terms { max-height: 130px; overflow: auto; font-size: .82rem; line-height: 1.5; color: var(--muted); background: var(--soft); border: 1px solid var(--line); border-radius: 10px; padding: 12px; white-space: pre-wrap; margin-top: 18px; }
.btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 22px; padding: 15px; border: 0; border-radius: 12px; background: var(--brand); color: var(--on-brand); font: inherit; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: background .15s, transform .05s; }
.btn:hover { background: var(--brand-dark); }
.btn:active { transform: translateY(1px); }
.btn[disabled] { opacity: .7; cursor: progress; }
.alert { display: flex; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: .9rem; line-height: 1.45; margin-bottom: 16px; }
.alert svg { width: 18px; height: 18px; flex: none; margin-top: 1px; }
.alert.bad { background: var(--bad-bg); color: var(--bad); border: 1px solid var(--bad-line); }
.alert.info { background: var(--info-bg); color: var(--info); border: 1px solid var(--info-line); }
.state { text-align: center; padding: 6px 0 2px; }
.state-icon { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.state-icon svg { width: 36px; height: 36px; }
.state-icon.ok { background: var(--ok-bg); color: var(--ok); }
.state-icon.wait { background: var(--brand-light); color: var(--brand); }
.state-icon.wait svg { animation: spin 1.1s linear infinite; }
.state-icon.off { background: var(--soft); color: var(--muted); }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-color-scheme: dark) { .state-icon.wait { background: color-mix(in srgb, var(--brand) 18%, transparent); } }
.state h2 { margin: 0 0 6px; font-size: 1.25rem; }
.valid { display: inline-block; margin-top: 14px; padding: 8px 14px; border-radius: 10px; background: var(--soft); border: 1px solid var(--line); font-size: .88rem; }
.count { font-variant-numeric: tabular-nums; font-weight: 700; color: var(--brand); }
.steps { list-style: none; margin: 16px 0 0; padding: 0; counter-reset: s; }
.steps li { counter-increment: s; display: flex; gap: 14px; padding: 12px 0; border-top: 1px solid var(--line); font-size: .92rem; line-height: 1.45; }
.steps li:first-child { border-top: 0; }
.steps li::before { content: counter(s); flex: none; width: 28px; height: 28px; border-radius: 50%; background: var(--brand-light); color: var(--brand-dark); font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: .85rem; }
@media (prefers-color-scheme: dark) { .steps li::before { background: color-mix(in srgb, var(--brand) 22%, transparent); color: var(--ink); } }
.ssid { display: inline-block; padding: 1px 8px; border-radius: 6px; background: var(--soft); border: 1px solid var(--line); font-weight: 700; }
.foot { border-top: 1px solid var(--line); padding: 14px 24px 18px; text-align: center; font-size: .82rem; color: var(--muted); line-height: 1.6; }
.foot a { color: inherit; font-weight: 600; text-decoration: none; white-space: nowrap; }
.foot a:hover { color: var(--brand); }
.foot .sep { margin: 0 6px; opacity: .5; }
.below { margin-top: 16px; font-size: .78rem; color: var(--muted); text-align: center; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
</head>
<body>
<main class="card">
    <header class="head">
        <?php if ($logo !== ''): ?>
            <div class="logo"><img src="<?= $h($logo) ?>" alt="<?= $h($orgName) ?>" onerror="this.parentNode.style.display='none'"></div>
        <?php endif; ?>
        <h1><?= $h($orgName) ?></h1>
        <span class="pill">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5 5 0 0 1 7 0"/><path d="M2 9a15 15 0 0 1 20 0"/><circle cx="12" cy="19.5" r="1"/></svg>
            Guest Wi-Fi
        </span>
    </header>

    <div class="body">
    <?php if (!$enabled): ?>
        <div class="state">
            <div class="state-icon off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 2l20 20"/><path d="M8.5 16a5 5 0 0 1 7 0"/><path d="M5 12.5a10 10 0 0 1 4.2-2.6M14.8 9.9A10 10 0 0 1 19 12.5"/><circle cx="12" cy="19.5" r="1"/></svg></div>
            <h2>Guest Wi-Fi is not available</h2>
            <p class="muted">Please ask at reception for internet access.</p>
        </div>

    <?php elseif ($done): ?>
        <div class="state">
            <div class="state-icon ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
            <h2><?= $h($done['message']) ?></h2>
            <p class="muted">Your device is being connected. This can take up to a minute.</p>
            <?php if (!empty($done['until'])): ?>
                <div class="valid">Access valid until <strong><?= $h(date('D j M, H:i', strtotime((string)$done['until']))) ?></strong></div>
            <?php endif; ?>
            <a class="btn" id="go" href="<?= $h($landing) ?>" rel="noreferrer">Start browsing</a>
            <p class="muted" style="margin-top:12px">Continuing automatically in <span class="count" id="count" aria-live="polite">60</span>s. If a page does not load yet, wait a moment and try again.</p>
        </div>
        <script>
        (function () {
            var n = 60, el = document.getElementById('count');
            var t = setInterval(function () { n--; el.textContent = n > 0 ? n : '0'; if (n <= 0) { clearInterval(t); location.href = document.getElementById('go').href; } }, 1000);
        })();
        </script>

    <?php elseif ($pending): ?>
        <div class="state">
            <div class="state-icon wait"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.6"/></svg></div>
            <h2>Checking your device&hellip;</h2>
            <p class="muted">We are confirming your device is on the hub Wi-Fi. Keep this page open; it continues by itself.</p>
        </div>
        <script>setTimeout(function () { location.replace('hotspot'); }, 10000);</script>

    <?php elseif ($mac === ''): ?>
        <p class="lead">Connect to our Wi-Fi to sign in</p>
        <p class="muted">This page needs to be opened from the hub Wi-Fi.</p>
        <ol class="steps">
            <li><span>Join the Wi-Fi network <?= $ssid !== '' ? '<span class="ssid">' . $h($ssid) . '</span>' : 'of the hub' ?>. Turn off mobile data.</span></li>
            <li><span>Tap the <strong>Sign in to network</strong> message, or open any website.</span></li>
            <li><span>You will be brought back here to enter your details.</span></li>
        </ol>

    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="alert bad" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v5.5M12 16.5v.01"/></svg><span><?= $h($error) ?></span></div>
        <?php endif; ?>
        <p class="lead">Welcome! Sign in to get online</p>
        <p class="muted">Free access for <?= $hours ?> hour<?= $hours === 1 ? '' : 's' ?>. It only takes a moment.</p>
        <form method="post" action="hotspot" autocomplete="on" id="hsForm">
            <?= csrf_field() ?>
            <label for="hsName">Full name</label>
            <div class="field">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20.5a8 8 0 0 1 16 0"/></svg>
                <input id="hsName" type="text" name="full_name" required maxlength="150" autocomplete="name" value="<?= $h($_POST['full_name'] ?? '') ?>">
            </div>
            <label for="hsPhone">Phone number<?= $settings['hotspot_require_phone'] ? '' : ' <span class="opt">(optional)</span>' ?></label>
            <div class="field">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M11 18h2"/></svg>
                <input id="hsPhone" type="tel" name="phone" <?= $settings['hotspot_require_phone'] ? 'required' : '' ?> maxlength="40" autocomplete="tel" inputmode="tel" value="<?= $h($_POST['phone'] ?? '') ?>">
            </div>
            <label for="hsEmail">Email <span class="opt">(optional)</span></label>
            <div class="field">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 6.5l8.5 6 8.5-6"/></svg>
                <input id="hsEmail" type="email" name="email" maxlength="190" autocomplete="email" placeholder="you@example.com" value="<?= $h($_POST['email'] ?? '') ?>">
            </div>
            <?php if ($settings['hotspot_password'] !== ''): ?>
                <label for="hsPass">Wi-Fi access code</label>
                <div class="field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
                    <input id="hsPass" type="password" name="hotspot_password" required autocomplete="off" placeholder="Ask at reception" style="padding-right:64px">
                    <button type="button" class="reveal" aria-controls="hsPass" onclick="var i=document.getElementById('hsPass');var s=i.type==='password';i.type=s?'text':'password';this.textContent=s?'Hide':'Show';">Show</button>
                </div>
            <?php endif; ?>
            <?php if ($settings['hotspot_terms'] !== ''): ?>
                <div class="terms" tabindex="0" aria-label="Terms of use"><?= $h($settings['hotspot_terms']) ?></div>
                <label class="check"><input type="checkbox" name="accept_terms" value="1" required> I have read and accept the terms of use</label>
            <?php endif; ?>
            <button type="submit" class="btn" id="hsSubmit">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1"/></svg>
                Connect
            </button>
        </form>
        <script>
        document.getElementById('hsForm').addEventListener('submit', function () {
            var b = document.getElementById('hsSubmit');
            setTimeout(function () { b.disabled = true; b.lastChild.textContent = ' Connecting…'; }, 0);
        });
        </script>
    <?php endif; ?>
    </div>

    <?php if ($supportPhone !== '' || $supportEmail !== ''): ?>
    <footer class="foot">
        Need help? Ask at reception<?php if ($supportPhone !== ''): ?><span class="sep">·</span><a href="tel:<?= $h(preg_replace('/[^0-9+]/', '', $supportPhone)) ?>"><?= $h($supportPhone) ?></a><?php endif; ?><?php if ($supportEmail !== ''): ?><span class="sep">·</span><a href="mailto:<?= $h($supportEmail) ?>"><?= $h($supportEmail) ?></a><?php endif; ?>
    </footer>
    <?php endif; ?>
</main>
<p class="below">&copy; <?= date('Y') ?> <?= $h($orgName) ?><span class="sr">. Guest Wi-Fi sign-in.</span></p>
</body>
</html>
