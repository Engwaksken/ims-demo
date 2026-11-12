<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Event attendance confirmation (public)
|--------------------------------------------------------------------------
| Opened from the reminder emails (signed ?token=...) or, for registrants
| with an IMS account, from their in-app notification (?rid=... while signed
| in). The answer is only recorded on POST, so email scanners that prefetch
| links cannot answer for the person. See includes/event-reminders.php.
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/event-reminders.php';

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$ids = $token !== '' ? evr_parse_token($token) : null;

// Signed-in registrant coming from an in-app notification.
if (!$ids && !empty($_SESSION['user_id'])) {
    $rid = (int)($_POST['rid'] ?? $_GET['rid'] ?? 0);
    if ($rid > 0) {
        $stmt = $conn->prepare('SELECT registration_id, event_id, member_id, email FROM event_registrations WHERE registration_id = ? LIMIT 1');
        $stmt->bind_param('i', $rid);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($candidate && evr_user_id($conn, $candidate) === (int)$_SESSION['user_id']) {
            $ids = [(int)$candidate['registration_id'], (int)$candidate['event_id']];
            $token = evr_token($ids[0], $ids[1]);
        }
    }
}

$registration = $ids ? evr_find($conn, $ids[0], $ids[1]) : null;
$result = null;

if ($registration && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $result = ['ok' => false, 'message' => 'This page expired. Please choose your answer again.'];
    } else {
        $result = evr_record_response($conn, $ids[0], $ids[1], (string)($_POST['answer'] ?? ''));
        $registration = evr_find($conn, $ids[0], $ids[1]) ?? $registration;
    }
}

$suggested = (string)($_GET['answer'] ?? '');
$start = $registration ? evr_day_start($registration) : null;
$response = (string)($registration['confirmation_response'] ?? '');
$canAnswer = $registration && evr_can_answer($registration);
$brand = ims_brand();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Confirm attendance - <?= $e(ims_brand_name()) ?></title>
<link rel="icon" href="<?= $e($brand['favicon']) ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<style>
:root{--bg:#f8fafc;--card:#fff;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc;--green:#16a34a;--green-bg:#dcfce7;--green-fg:#166534;--red:#dc2626;--red-bg:#fee2e2;--red-fg:#991b1b;--blue-bg:#dbeafe;--blue-fg:#1e40af}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#111a2e;--ink:#e2e8f0;--muted:#94a3b8;--line:#1f2a44;--soft:#0f172a;--green-bg:#052e16;--green-fg:#86efac;--red-bg:#450a0a;--red-fg:#fca5a5;--blue-bg:#172554;--blue-fg:#bfdbfe}}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--ink);padding:32px 16px}
.card{max-width:540px;margin:auto;background:var(--card);padding:28px;border-radius:18px;box-shadow:0 12px 35px rgba(15,23,42,.1);text-align:center}
.icon{font-size:52px;margin-bottom:10px;color:var(--muted)}
h1{font-size:22px;margin:0 0 6px}
.lead{color:var(--muted);margin:0 0 18px}
.info{text-align:left;background:var(--soft);border:1px solid var(--line);border-radius:14px;padding:6px 16px;margin:18px 0}
.info p{margin:10px 0;font-size:14px}
.info i{width:18px;color:var(--muted)}
.answers{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}
button{font:inherit;font-weight:700;border:2px solid transparent;border-radius:12px;padding:14px 10px;cursor:pointer;color:#fff}
.yes{background:var(--green)}
.no{background:var(--red)}
button.is-suggested{outline:3px solid var(--ink);outline-offset:2px}
.msg{padding:12px 14px;border-radius:12px;margin:0 0 16px;font-weight:600}
.msg.ok{background:var(--green-bg);color:var(--green-fg)}
.msg.err{background:var(--red-bg);color:var(--red-fg)}
.current{display:inline-block;padding:6px 12px;border-radius:999px;background:var(--blue-bg);color:var(--blue-fg);font-size:13px;font-weight:700}
.hint{font-size:12px;color:var(--muted);margin-top:14px}
@media(max-width:420px){.answers{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="card">
<?php if (!$registration): ?>
    <div class="icon"><i class="fas fa-link-slash" aria-hidden="true"></i></div>
    <h1>Link not valid</h1>
    <p class="lead">This confirmation link is incomplete or has been changed. Please use the link from your reminder email.</p>
<?php else: ?>
    <div class="icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
    <h1>Confirm your attendance</h1>
    <p class="lead"><?= $e($registration['attendee_name'] ?? '') ?></p>

    <?php if ($result): ?>
        <p class="msg <?= $result['ok'] ? 'ok' : 'err' ?>" role="status"><?= $e($result['message']) ?></p>
    <?php endif; ?>

    <div class="info">
        <p><i class="fas fa-calendar" aria-hidden="true"></i> <strong><?= $e($registration['event_title'] ?? '') ?></strong></p>
        <?php if ($start): ?><p><i class="fas fa-clock" aria-hidden="true"></i> <?= $e(date('l j F Y, H:i', strtotime($start))) ?><?= !empty($registration['end_time']) ? ' – ' . $e(substr((string)$registration['end_time'], 0, 5)) : '' ?></p><?php endif; ?>
        <?php if (!empty($registration['venue'])): ?><p><i class="fas fa-location-dot" aria-hidden="true"></i> <?= $e($registration['venue']) ?></p><?php endif; ?>
    </div>

    <?php if ($response !== ''): ?>
        <p><span class="current"><i class="fas fa-circle-info" aria-hidden="true"></i> Your answer: <?= $e($response === 'Attending' ? 'Attending' : 'Not attending') ?></span></p>
    <?php endif; ?>

    <?php if ($canAnswer): ?>
        <form method="POST" action="event-confirm">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= $e($token) ?>">
            <p style="margin:0"><?= $response !== '' ? 'Changed your plans? You can update your answer.' : 'Will you be attending?' ?></p>
            <div class="answers">
                <button type="submit" name="answer" value="yes" class="yes<?= $suggested === 'yes' && !$result ? ' is-suggested' : '' ?>"><i class="fas fa-check" aria-hidden="true"></i> Yes, I'm attending</button>
                <button type="submit" name="answer" value="no" class="no<?= $suggested === 'no' && !$result ? ' is-suggested' : '' ?>"><i class="fas fa-xmark" aria-hidden="true"></i> No, I can't attend</button>
            </div>
        </form>
        <p class="hint">Answering stops the reminders for this event day.</p>
    <?php elseif (!$result || $result['ok']): ?>
        <p class="lead"><?= ($registration['registration_status'] ?? '') === 'Cancelled' || ($registration['event_status'] ?? '') === 'Cancelled'
            ? 'This registration or event has been cancelled.'
            : 'This event day has passed. Please contact the organisers if your attendance needs correcting.' ?></p>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
