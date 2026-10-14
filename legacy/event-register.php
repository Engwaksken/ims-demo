<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mail-function.php';

if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$token = trim((string)($_GET['token'] ?? ''));
$event_id = 0;

if ($token !== '') {
    $decoded = base64_decode($token, true);
    if ($decoded !== false && preg_match('/^eid=(\d+)$/', $decoded, $m)) {
        $event_id = (int)$m[1];
    }
}

if ($event_id <= 0) {
    http_response_code(404);
    exit('<p style="padding:40px;font-family:sans-serif;color:#dc2626;">Invalid registration link.</p>');
}

$stmt = $conn->prepare("SELECT * FROM hub_events WHERE event_id = ? LIMIT 1");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    http_response_code(404);
    exit('<p style="padding:40px;font-family:sans-serif;color:#dc2626;">Event not found.</p>');
}

$eventTitle  = trim((string)($event['event_title'] ?? 'Event'));
$eventDate   = trim((string)($event['event_date'] ?? ''));
$startTime   = trim((string)($event['start_time'] ?? ''));
$endTime     = trim((string)($event['end_time'] ?? ''));
$venue       = trim((string)($event['venue'] ?? ''));
$organizer   = trim((string)($event['organizer'] ?? ''));
$eventStatus = trim((string)($event['event_status'] ?? 'Active'));

if (strcasecmp($eventStatus, 'Cancelled') === 0) {
    http_response_code(410);
    exit('<p style="padding:40px;font-family:sans-serif;color:#dc2626;">This event has been cancelled.</p>');
}

/* =========================
   Get Multiple Event Days
========================= */
$eventDays = [];

if (!empty($event['event_days'])) {
    $decodedDays = json_decode((string)$event['event_days'], true);

    if (is_array($decodedDays)) {
        foreach ($decodedDays as $day) {
            $day = trim((string)$day);

            if ($day !== '' && strtotime($day)) {
                $eventDays[] = date('Y-m-d', strtotime($day));
            }
        }
    }
}

if (empty($eventDays) && $eventDate !== '' && strtotime($eventDate)) {
    $eventDays[] = date('Y-m-d', strtotime($eventDate));
}

$eventDays = array_values(array_unique($eventDays));
sort($eventDays);

if (empty($eventDays)) {
    http_response_code(410);
    exit('<p style="padding:40px;font-family:sans-serif;color:#dc2626;">No valid event day was found for this event.</p>');
}

/* =========================
   Smart Auto Registration Date
   Uses today or next upcoming date
========================= */
$today = date('Y-m-d');
$selectedEventDate = '';

foreach ($eventDays as $day) {
    if ($day >= $today) {
        $selectedEventDate = $day;
        break;
    }
}

if ($selectedEventDate === '') {
    http_response_code(410);
    exit('<p style="padding:40px;font-family:sans-serif;color:#dc2626;">Registration for this event has closed.</p>');
}

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $record_type = trim((string)($_POST['record_type'] ?? 'Guest'));

    $member_id = null;
    $beneficiary_id = null;

    if ($record_type === 'Member') {
        $tmp = (int)($_POST['member_id'] ?? 0);
        $member_id = $tmp > 0 ? $tmp : null;
    }

    if ($record_type === 'Beneficiary') {
        $tmp = (int)($_POST['beneficiary_id'] ?? 0);
        $beneficiary_id = $tmp > 0 ? $tmp : null;
    }

    /*
      Do not trust posted date blindly.
      Force attendance date to the smart selected date.
    */
    $attendanceDate = $selectedEventDate;

    $attendee_name = trim((string)($_POST['attendee_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $organization = trim((string)($_POST['organization'] ?? ''));
    $special_requirements = trim((string)($_POST['special_requirements'] ?? ''));

    $registration_status = 'Registered';
    $attendance_status = 'Pending';
    $payment_status = 'Pending';
    $payment_reference = '';

    if ($attendanceDate === '' || !in_array($attendanceDate, $eventDays, true)) {
        $error = 'Invalid event day.';
    } elseif ($attendee_name === '') {
        $error = 'Full name is required.';
    } elseif ($email === '') {
        $error = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    }

    if ($error === '') {
        $dup = $conn->prepare("
            SELECT registration_id
            FROM event_registrations
            WHERE event_id = ?
              AND email = ?
              AND attendance_date = ?
            LIMIT 1
        ");
        $dup->bind_param("iss", $event_id, $email, $attendanceDate);
        $dup->execute();
        $exists = $dup->get_result()->fetch_assoc();
        $dup->close();

        if ($exists) {
            $error = 'You already registered for this event day using this email.';
        }
    }

    if ($error === '') {
        $ins = $conn->prepare("
            INSERT INTO event_registrations (
                event_id,
                attendance_date,
                beneficiary_id,
                member_id,
                attendance_status,
                attendee_name,
                email,
                organization,
                phone,
                registration_status,
                payment_status,
                payment_reference,
                special_requirements,
                registered_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$ins) {
            $error = 'Registration failed: could not prepare registration.';
        } else {
            $ins->bind_param(
                "isiisssssssss",
                $event_id,
                $attendanceDate,
                $beneficiary_id,
                $member_id,
                $attendance_status,
                $attendee_name,
                $email,
                $organization,
                $phone,
                $registration_status,
                $payment_status,
                $payment_reference,
                $special_requirements
            );

            if ($ins->execute()) {
                $registration_id = (int)$ins->insert_id;

                send_event_registration_confirmation([
                    'registration_id' => $registration_id,
                    'event_id'        => $event_id,
                    'event_title'     => $eventTitle,
                    'event_date'      => $attendanceDate,
                    'start_time'      => $startTime,
                    'end_time'        => $endTime,
                    'venue'           => $venue,
                    'attendee_name'   => $attendee_name,
                    'email'           => $email
                ]);

                $selectedEventDate = $attendanceDate;
                $success = true;
            } else {
                $error = 'Registration failed: ' . $ins->error;
            }

            $ins->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/favicon.png">
<title>Register - <?= e($eventTitle) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">

<link rel="stylesheet" href="css/events.css">
<link rel="stylesheet" href="css/event-register.css">
<script src="js/accessibility.js"></script>
</head>
<body>

<?php if ($success): ?>

<div class="reg-wrap">
    <div class="reg-success">
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <h2>Registration Successful!</h2>

        <p>
            Thank you for registering for
            <strong><?= e($eventTitle) ?></strong>.
        </p>

        <p style="margin-top:8px;">
            <i class="fas fa-calendar-day"></i>
            <?= e(date('l, d F Y', strtotime($selectedEventDate))) ?>
        </p>

        <p style="margin-top:10px;">
            <i class="fas fa-clock"></i> Redirecting you shortly...
        </p>

        <a href="https://hivecolab.org" class="btn btn-primary" style="margin-top:15px;display:inline-block;">
            Go Now
        </a>
    </div>
</div>

<script>
setTimeout(function () {
    window.location.href = "https://hivecolab.org";
}, 5000);
</script>

<?php else: ?>

<div class="reg-wrap">
    <div class="reg-hero">
        <div class="reg-hero-label">
            <i class="fas fa-calendar-alt"></i> Event Registration
        </div>

        <h1><?= e($eventTitle) ?></h1>

        <div class="reg-meta">
            <span>
                <i class="fas fa-calendar-days"></i>
                <?= count($eventDays) > 1 ? count($eventDays) . ' Event Days' : e(date('l, d F Y', strtotime($eventDays[0]))) ?>
            </span>

            <?php if ($startTime !== ''): ?>
                <span>
                    <i class="fas fa-clock"></i>
                    <?= e(date('h:i A', strtotime($startTime))) ?>
                    <?= $endTime !== '' ? ' - ' . e(date('h:i A', strtotime($endTime))) : '' ?>
                </span>
            <?php endif; ?>

            <?php if ($venue !== ''): ?>
                <span><i class="fas fa-location-dot"></i> <?= e($venue) ?></span>
            <?php endif; ?>

            <?php if ($organizer !== ''): ?>
                <span><i class="fas fa-user-tie"></i> <?= e($organizer) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="reg-body">
        <?php if ($error !== ''): ?>
            <div class="err-box">
                <i class="fas fa-circle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="regForm" novalidate>
            <input type="hidden" name="register" value="1">
            <input type="hidden" name="member_id" id="member_id" value="">
            <input type="hidden" name="beneficiary_id" id="beneficiary_id" value="">
            <input type="hidden" name="attendance_date" value="<?= e($selectedEventDate) ?>">

            <div class="form-section">Event day</div>

            <div class="event-day-display">
                <i class="fas fa-calendar-day"></i>
                <div>
                    <small>Registration Date</small>
                    <strong><?= e(date('l, d F Y', strtotime($selectedEventDate))) ?></strong>
                </div>
            </div>

            <div class="form-section">Who are you?</div>

            <div class="type-toggle" role="group" aria-label="Registration type">
                <span>
                    <input type="radio" name="record_type" id="type_member" value="Member" class="type-opt"
                        <?= ($_POST['record_type'] ?? '') === 'Member' ? 'checked' : '' ?>>
                    <label for="type_member" class="type-label">
                        <i class="fas fa-id-card"></i> Member
                    </label>
                </span>

                <span>
                    <input type="radio" name="record_type" id="type_startup" value="Startup" class="type-opt"
                        <?= ($_POST['record_type'] ?? '') === 'Startup' ? 'checked' : '' ?>>
                    <label for="type_startup" class="type-label">
                        <i class="fas fa-building-user"></i> Startup
                    </label>
                </span>

                <span>
                    <input type="radio" name="record_type" id="type_guest" value="Guest" class="type-opt"
                        <?= ($_POST['record_type'] ?? 'Guest') === 'Guest' ? 'checked' : '' ?>>
                    <label for="type_guest" class="type-label">
                        <i class="fas fa-user"></i> Guest
                    </label>
                </span>
            </div>

            <div id="lookupSection" style="display:none;margin-top:16px;">
                <div class="form-section">Find your record</div>

                <div class="form-group lookup-box">
                    <label class="form-label" for="lookupInput">Search by name, email, or organization</label>
                    <input
                        type="search"
                        id="lookupInput"
                        class="form-control"
                        placeholder="Start typing..."
                        autocomplete="off"
                    >
                    <div class="lookup-results" id="lookupResults"></div>
                </div>

                <div id="prefilledNote" style="display:none;font-size:12px;color:#15803d;margin:-8px 0 12px;padding-left:2px;">
                    <i class="fas fa-circle-check"></i> Details filled from your record
                    <span class="prefilled-badge"><i class="fas fa-lock"></i> Auto-filled</span>
                </div>
            </div>

            <div class="form-section" style="margin-top:16px;">Your details</div>

            <div class="form-group">
                <label class="form-label" for="attendee_name">
                    Full Name <sup style="color:#b91c1c">*</sup>
                </label>
                <input
                    type="text"
                    id="attendee_name"
                    name="attendee_name"
                    class="form-control"
                    value="<?= e($_POST['attendee_name'] ?? '') ?>"
                    required
                    placeholder="Enter full name"
                >
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label" for="email">
                        Email <sup style="color:#b91c1c">*</sup>
                    </label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control"
                        value="<?= e($_POST['email'] ?? '') ?>"
                        required
                        placeholder="you@example.com"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Phone</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        class="form-control"
                        value="<?= e($_POST['phone'] ?? '') ?>"
                        placeholder="+256 ..."
                    >
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="organization">Organization / Company</label>
                <input
                    type="text"
                    id="organization"
                    name="organization"
                    class="form-control"
                    value="<?= e($_POST['organization'] ?? '') ?>"
                    placeholder="Optional"
                >
            </div>

            <div class="form-section">Additional information</div>

            <div class="form-group">
                <label class="form-label" for="special_requirements">Special Requirements</label>
                <textarea
                    id="special_requirements"
                    name="special_requirements"
                    class="form-control"
                    style="min-height:80px;resize:vertical;"
                    placeholder="Dietary needs, accessibility needs, questions..."
                ><?= e($_POST['special_requirements'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-paper-plane"></i> Complete Registration
            </button>
        </form>

        <div class="reg-footer">
            Your information is used only for event registration and attendance tracking.
        </div>
    </div>
</div>

<?php endif; ?>

<script>
const typeInputs = document.querySelectorAll('input[name="record_type"]');
const lookupSection = document.getElementById('lookupSection');
const lookupInput = document.getElementById('lookupInput');
const lookupResults = document.getElementById('lookupResults');
const memberIdInput = document.getElementById('member_id');
const beneficiaryIdInput = document.getElementById('beneficiary_id');
const prefilledNote = document.getElementById('prefilledNote');

let lookupTimer = null;

function selectedType() {
    return document.querySelector('input[name="record_type"]:checked')?.value || 'Guest';
}

function updateTypeUI() {
    const type = selectedType();

    clearLinkedRecord();

    if (lookupSection) {
        lookupSection.style.display = (type === 'Member' || type === 'Beneficiary' || type === 'Startup')
            ? 'block'
            : 'none';
    }

    if (lookupInput) {
        lookupInput.value = '';
    }

    closeResults();
}

typeInputs.forEach(input => {
    input.addEventListener('change', updateTypeUI);
});

updateTypeUI();

if (lookupInput) {
    lookupInput.addEventListener('input', function () {
        clearTimeout(lookupTimer);

        const q = lookupInput.value.trim();

        if (q.length < 2) {
            closeResults();
            return;
        }

        lookupTimer = setTimeout(function () {
            const type = selectedType();

            fetch(`includes/event-register-process.php?action=lookup&type=${encodeURIComponent(type)}&q=${encodeURIComponent(q)}`)
                .then(response => response.json())
                .then(data => {
                    renderResults(Array.isArray(data) ? data : []);
                })
                .catch(() => {
                    closeResults();
                });
        }, 300);
    });

    document.addEventListener('click', function (event) {
        if (
            lookupInput &&
            lookupResults &&
            !lookupInput.contains(event.target) &&
            !lookupResults.contains(event.target)
        ) {
            closeResults();
        }
    });
}

function renderResults(data) {
    if (!lookupResults) return;

    lookupResults.innerHTML = '';

    if (!data.length) {
        const empty = document.createElement('div');
        empty.className = 'lookup-item no-result';
        empty.textContent = 'No records found - you can fill in your details manually.';
        lookupResults.appendChild(empty);
    } else {
        data.forEach(item => {
            const row = document.createElement('div');
            row.className = 'lookup-item';

            row.innerHTML = `
                <strong>${escHtml(item.attendee_name || '')}</strong>
                <span>${escHtml(item.sub_label || '')}</span>
            `;

            row.addEventListener('click', function () {
                prefillFromRecord(item);
            });

            lookupResults.appendChild(row);
        });
    }

    lookupResults.classList.add('open');
}

function prefillFromRecord(item) {
    const type = item.record_type || selectedType();

    const attendeeInput = document.getElementById('attendee_name');
    const emailInput = document.getElementById('email');
    const phoneInput = document.getElementById('phone');
    const organizationInput = document.getElementById('organization');

    if (attendeeInput) attendeeInput.value = item.attendee_name || '';
    if (emailInput) emailInput.value = item.email || '';
    if (phoneInput) phoneInput.value = item.phone || '';
    if (organizationInput) organizationInput.value = item.organization || '';

    if (memberIdInput) {
        memberIdInput.value = type === 'Member' ? (item.id || '') : '';
    }

    if (beneficiaryIdInput) {
        beneficiaryIdInput.value = type === 'Beneficiary' ? (item.id || '') : '';
    }

    if (lookupInput) {
        lookupInput.value = item.attendee_name || '';
    }

    closeResults();

    if (prefilledNote) {
        prefilledNote.style.display = 'block';
    }
}

function clearLinkedRecord() {
    if (memberIdInput) memberIdInput.value = '';
    if (beneficiaryIdInput) beneficiaryIdInput.value = '';

    if (prefilledNote) {
        prefilledNote.style.display = 'none';
    }
}

function closeResults() {
    if (lookupResults) {
        lookupResults.classList.remove('open');
    }
}

function escHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

</body>
</html>