<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

require_once __DIR__ . '/includes/mail-function.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$token = trim((string)($_GET['token'] ?? ''));
$registration_id = 0;
$event_id = 0;

$decoded = base64_decode($token, true);

if ($decoded !== false && preg_match('/^rid=(\d+)&eid=(\d+)(?:&sig=([a-f0-9]{32}))?$/', $decoded, $m)) {
    $sig = (string)($m[3] ?? '');
    $expected = ims_event_checkin_signature((int)$m[1], (int)$m[2]);

    // Unsigned (legacy) QR codes are forgeable by changing the ids. They are
    // accepted only while EVENT_CHECKIN_ALLOW_UNSIGNED is not set to false in
    // .env (so QR codes already e-mailed keep working); new codes are signed.
    $allowUnsigned = function_exists('ims_env_bool') ? ims_env_bool('EVENT_CHECKIN_ALLOW_UNSIGNED', true) : true;

    if (($sig !== '' && hash_equals($expected, $sig)) || ($sig === '' && $allowUnsigned)) {
        $registration_id = (int)$m[1];
        $event_id = (int)$m[2];
    }
}

if ($registration_id <= 0 || $event_id <= 0) {
    exit('Invalid check-in QR code.');
}

$stmt = $conn->prepare("
    SELECT 
        r.*,
        e.event_title,
        e.event_date,
        e.event_days,
        e.start_time,
        e.end_time,
        e.venue
    FROM event_registrations r
    INNER JOIN hub_events e ON e.event_id = r.event_id
    WHERE r.registration_id = ?
      AND r.event_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $registration_id, $event_id);
$stmt->execute();
$reg = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reg) {
    exit('Registration not found.');
}

$attendanceDate = '';

if (!empty($reg['attendance_date']) && strtotime((string)$reg['attendance_date'])) {
    $attendanceDate = date('Y-m-d', strtotime((string)$reg['attendance_date']));
} elseif (!empty($reg['event_date']) && strtotime((string)$reg['event_date'])) {
    $attendanceDate = date('Y-m-d', strtotime((string)$reg['event_date']));
}

$alreadyCheckedIn = ((string)($reg['attendance_status'] ?? '') === 'Checked In');

if (!$alreadyCheckedIn) {
    $update = $conn->prepare("
        UPDATE event_registrations
        SET attendance_status = 'Checked In',
            registration_status = 'Attended',
            check_in_time = NOW()
        WHERE registration_id = ?
          AND event_id = ?
    ");
    $update->bind_param("ii", $registration_id, $event_id);
    $update->execute();
    $update->close();

    $countUpdate = $conn->prepare("
        UPDATE hub_events
        SET actual_participants = (
            SELECT COUNT(*)
            FROM event_registrations
            WHERE event_id = ?
              AND registration_status = 'Attended'
        )
        WHERE event_id = ?
    ");
    $countUpdate->bind_param("ii", $event_id, $event_id);
    $countUpdate->execute();
    $countUpdate->close();
}

$statusText  = $alreadyCheckedIn ? 'Already Checked In' : 'Check-in Successful';
$statusIcon  = $alreadyCheckedIn ? 'fa-circle-check' : 'fa-check-circle';
$statusColor = $alreadyCheckedIn ? '#2563eb' : '#16a34a';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Event Check-in</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">

<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:#f8fafc;
    padding:30px;
    color:#0f172a;
}
.card{
    max-width:540px;
    margin:auto;
    background:#fff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 12px 35px rgba(15,23,42,.1);
    text-align:center;
}
.icon{
    font-size:58px;
    color:<?= e($statusColor) ?>;
    margin-bottom:12px;
}
h2{
    margin:0 0 18px;
}
.info{
    text-align:left;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:16px;
    margin-top:20px;
}
.info p{
    margin:10px 0;
    font-size:14px;
}
.badge{
    display:inline-block;
    margin-top:16px;
    padding:8px 14px;
    border-radius:999px;
    background:#dcfce7;
    color:#166534;
    font-weight:700;
    font-size:13px;
}
.redirect{
    margin-top:16px;
    font-size:13px;
    color:#64748b;
}
</style>
<script src="js/accessibility.js"></script>
</head>
<body>

<div class="card">
    <div class="icon">
        <i class="fas <?= e($statusIcon) ?>"></i>
    </div>

    <h2><?= e($statusText) ?></h2>

    <p><strong><?= e($reg['attendee_name'] ?? '') ?></strong></p>

    <div class="info">
        <p><i class="fas fa-calendar-check"></i> <strong>Event:</strong> <?= e($reg['event_title'] ?? '') ?></p>

        <?php if ($attendanceDate !== ''): ?>
            <p><i class="fas fa-calendar-day"></i> <strong>Event Day:</strong> <?= e(date('l, d F Y', strtotime($attendanceDate))) ?></p>
        <?php endif; ?>

        <?php if (!empty($reg['venue'])): ?>
            <p><i class="fas fa-location-dot"></i> <strong>Venue:</strong> <?= e($reg['venue']) ?></p>
        <?php endif; ?>

        <p><i class="fas fa-user-check"></i> <strong>Status:</strong> Checked In</p>
    </div>

    <div class="badge">
        <i class="fas fa-check"></i> Attendance Recorded
    </div>

    <div class="redirect">
        <i class="fas fa-clock"></i> Redirecting in 3 seconds...
    </div>
</div>

<script>
setTimeout(function () {
    window.location.href = "https://hivecolab.org/";
}, 3000);
</script>

</body>
</html>