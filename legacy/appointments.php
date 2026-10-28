<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Appointments
|--------------------------------------------------------------------------
| Staff request time with each other; see includes/appointments.php for the
| workflow (notifications, slot blocking, calendar invites, reminders).
| Every query is limited to appointments the session user is part of.
*/

$page_title = 'Appointments';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/appointments.php';
check_role(IMS_STAFF_ROLES);

$uid = (int)($_SESSION['user_id'] ?? 0);
$views = ['calendar' => 'Calendar', 'requests' => 'Requests for me', 'sent' => 'Sent', 'upcoming' => 'Upcoming', 'history' => 'History'];
$view = array_key_exists((string)($_GET['view'] ?? ''), $views) ? (string)$_GET['view'] : 'calendar';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!appt_ensure_table($conn)) {
    include 'includes/header.php'; ?>
    <div class="card"><div class="card-body"><div class="alert alert-warning">Appointments are unavailable: the database table could not be created. Ask an Administrator to run database/migrations/2026_10_28_staff_appointments.sql.</div></div></div>
    <?php include 'includes/footer.php';
    exit;
}

appt_dispatch_reminders($conn);

/*
|--------------------------------------------------------------------------
| GET ?busy=1: busy blocks for me and another staff member on one day.
| Only times are returned for the other person, never subjects.
|--------------------------------------------------------------------------
*/
if (isset($_GET['busy'])) {
    header('Content-Type: application/json');
    $other = (int)($_GET['user'] ?? 0);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['date'] ?? ''));
    if (!$day || $other <= 0 || !appt_is_staff($conn, $other)) {
        echo json_encode(['busy' => []]);
        exit;
    }
    $from = $day->format('Y-m-d 00:00:00');
    $to = $day->modify('+1 day')->format('Y-m-d 00:00:00');
    $exclude = (int)($_GET['exclude'] ?? 0);
    $busy = [];
    foreach ([$uid => 'You', $other => 'They'] as $person => $label) {
        foreach (appt_for_user($conn, $person, $from, $to, ['Accepted']) as $row) {
            if ((int)$row['appointment_id'] === $exclude) continue;
            $busy[] = ['who' => $label, 'start' => substr((string)$row['start_at'], 11, 5), 'end' => substr((string)$row['end_at'], 11, 5)];
        }
    }
    foreach (appt_hub_event_busy($conn, [$uid, $other], $from, $to) as $event) {
        $label = $event['user_id'] === $uid ? 'You (event)' : 'They (event)';
        $busy[] = ['who' => $label, 'start' => substr($event['start_at'], 11, 5), 'end' => substr($event['end_at'], 11, 5)];
    }
    usort($busy, static fn($a, $b) => strcmp($a['start'], $b['start']));
    echo json_encode(['busy' => $busy]);
    exit;
}

/*
|--------------------------------------------------------------------------
| POST: create / accept / decline / reschedule / cancel (Post/Redirect/Get)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['appointment_id'] ?? 0);
    $result = match ($action) {
        'create' => appt_create($conn, $uid, $_POST),
        'accept' => appt_accept($conn, $uid, $id, (int)($_POST['remind_minutes'] ?? 0)),
        'decline' => appt_decline($conn, $uid, $id, (string)($_POST['note'] ?? '')),
        'reschedule' => appt_reschedule($conn, $uid, $id, $_POST),
        'cancel' => appt_cancel($conn, $uid, $id, (string)($_POST['note'] ?? '')),
        default => ['ok' => false, 'message' => 'Unknown action.'],
    };
    $_SESSION['notification'] = ['message' => $result['message'], 'type' => $result['ok'] ? 'success' : 'danger'];

    $back = array_key_exists((string)($_POST['return_view'] ?? ''), $views) ? (string)$_POST['return_view'] : 'calendar';
    if (in_array($action, ['create', 'reschedule'], true) && $result['ok']) {
        $back = 'sent';
    } elseif ($action === 'accept' && $result['ok']) {
        $back = 'upcoming';
    }
    $query = ['view' => $back];
    if (preg_match('/^\d{4}-\d{2}$/', (string)($_POST['return_month'] ?? ''))) {
        $query['month'] = (string)$_POST['return_month'];
    }
    header('Location: appointments?' . http_build_query($query));
    exit;
}

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/
$now = date('Y-m-d H:i:s');

$countStmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(status = 'Pending' AND COALESCE(proposed_by, requester_id) <> ? AND start_at > ?), 0),
        COALESCE(SUM(status = 'Pending' AND COALESCE(proposed_by, requester_id) = ? AND start_at > ?), 0),
        COALESCE(SUM(status = 'Accepted' AND end_at > ?), 0),
        COALESCE(SUM(status = 'Accepted' AND DATE(start_at) = ?), 0)
     FROM staff_appointments WHERE requester_id = ? OR invitee_id = ?"
);
$today = date('Y-m-d');
$countStmt->bind_param('isisssii', $uid, $now, $uid, $now, $now, $today, $uid, $uid);
$countStmt->execute();
$countStmt->bind_result($countRequests, $countSent, $countUpcoming, $countToday);
$countStmt->fetch();
$countStmt->close();
$tabCounts = ['requests' => (int)$countRequests, 'sent' => (int)$countSent, 'upcoming' => (int)$countUpcoming];

$month = DateTimeImmutable::createFromFormat('!Y-m', (string)($_GET['month'] ?? '')) ?: new DateTimeImmutable('first day of this month 00:00');
$monthKey = $month->format('Y-m');
$rows = [];

if ($view === 'calendar') {
    $gridStart = $month->modify('-' . ((int)$month->format('N') - 1) . ' days');
    $gridEnd = $month->modify('last day of this month')->modify('+' . (7 - (int)$month->modify('last day of this month')->format('N')) . ' days')->modify('+1 day');
    $byDay = [];
    foreach (appt_for_user($conn, $uid, $gridStart->format('Y-m-d H:i:s'), $gridEnd->format('Y-m-d H:i:s')) as $row) {
        $byDay[substr((string)$row['start_at'], 0, 10)][] = $row;
    }
    foreach (appt_hub_event_busy($conn, [$uid], $gridStart->format('Y-m-d H:i:s'), $gridEnd->format('Y-m-d H:i:s')) as $event) {
        $byDay[substr($event['start_at'], 0, 10)][] = $event + ['status' => 'Event'];
    }
    foreach ($byDay as &$dayItems) {
        usort($dayItems, static fn($a, $b) => strcmp((string)$a['start_at'], (string)$b['start_at']));
    }
    unset($dayItems);
} else {
    $where = match ($view) {
        'requests' => "(a.requester_id = ? OR a.invitee_id = ?) AND a.status = 'Pending' AND COALESCE(a.proposed_by, a.requester_id) <> ? AND a.start_at > ?",
        'sent' => "(a.requester_id = ? OR a.invitee_id = ?) AND a.status = 'Pending' AND COALESCE(a.proposed_by, a.requester_id) = ? AND a.start_at > ?",
        'upcoming' => "(a.requester_id = ? OR a.invitee_id = ?) AND a.status = 'Accepted' AND a.end_at > ?",
        'history' => "(a.requester_id = ? OR a.invitee_id = ?) AND (a.status IN ('Declined','Cancelled') OR a.end_at <= ?)",
    };
    $order = in_array($view, ['history'], true) ? 'a.start_at DESC' : 'a.start_at ASC';
    $stmt = $conn->prepare(
        "SELECT a.*, r.full_name AS requester_name, i.full_name AS invitee_name
         FROM staff_appointments a
         JOIN users r ON r.user_id = a.requester_id
         JOIN users i ON i.user_id = a.invitee_id
         WHERE $where ORDER BY $order LIMIT 200"
    );
    if (in_array($view, ['requests', 'sent'], true)) {
        $stmt->bind_param('iiis', $uid, $uid, $uid, $now);
    } else {
        $stmt->bind_param('iis', $uid, $uid, $now);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$staff = appt_staff_options($conn, $uid);
$reminders = appt_reminder_options();

/** Other party's name, from the session user's point of view. */
$otherName = static fn(array $a): string => (int)$a['requester_id'] === $uid ? (string)$a['invitee_name'] : (string)$a['requester_name'];
$statusBadge = static function (array $a) use ($now): array {
    if ($a['status'] === 'Accepted' && (string)$a['end_at'] <= $now) {
        return ['Completed', 'badge-secondary'];
    }
    if ($a['status'] === 'Pending' && (string)$a['start_at'] <= $now) {
        return ['Expired', 'badge-secondary'];
    }
    return [$a['status'], ['Pending' => 'badge-warning', 'Accepted' => 'badge-success', 'Declined' => 'badge-danger', 'Cancelled' => 'badge-secondary'][$a['status']] ?? 'badge-secondary'];
};

include 'includes/header.php';
?>

<style>
.appt-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden}
.appt-cal-head{padding:8px;font-size:12px;font-weight:800;color:var(--ink-300);text-align:center;background:var(--surface-hover);border-bottom:1px solid var(--border)}
.appt-cal-day{min-height:104px;padding:6px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:4px;min-width:0}
.appt-cal-day:nth-child(7n){border-right:0}
.appt-cal-day.is-other{background:var(--surface-hover);color:var(--ink-300)}
.appt-cal-day.is-today .appt-cal-num{background:var(--brand-500);color:var(--ink-0);border-radius:999px}
.appt-cal-num{font-size:12px;font-weight:800;width:24px;height:24px;display:grid;place-items:center}
.appt-chip{display:block;font-size:11px;line-height:1.35;padding:3px 6px;border-radius:var(--radius-sm);border:1px solid var(--green-border);background:var(--green-bg);color:var(--green-fg);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none}
.appt-chip.is-pending{border-style:dashed;border-color:var(--amber-border);background:var(--amber-bg);color:var(--amber-fg)}
.appt-chip.is-event{border-color:var(--blue-border);background:var(--blue-bg);color:var(--blue-fg)}
.appt-cal-nav{display:flex;align-items:center;gap:8px}
.appt-legend{display:flex;gap:14px;font-size:12px;color:var(--ink-300);margin-top:10px}
.appt-legend .appt-chip{display:inline-block}
.appt-row{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:14px 0;border-bottom:1px solid var(--border)}
.appt-row:last-child{border-bottom:0}
.appt-title{font-weight:800;color:var(--ink-900)}
.appt-meta{font-size:12px;color:var(--ink-300);margin-top:4px;line-height:1.6}
.appt-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.appt-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 14px}
.appt-form-grid .is-wide{grid-column:1/-1}
.appt-busy{font-size:12px;margin:-8px 0 14px}
.appt-modal-sm{max-width:480px}
.appt-busy li{margin-left:18px}
@media(max-width:760px){
  .appt-cal{grid-template-columns:1fr}
  .appt-cal-head,.appt-cal-day.is-other:not(.has-items){display:none}
  .appt-cal-day{min-height:0;border-right:0;flex-direction:row;flex-wrap:wrap;align-items:center}
  .appt-row{flex-direction:column;align-items:flex-start}
  .appt-form-grid{grid-template-columns:1fr}
}
</style>

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-handshake" aria-hidden="true"></i> Appointments</h1>
            <p>Book time with colleagues. Accepted appointments block the slot for both of you and come with reminders.</p>
        </div>
        <div class="hero-actions">
            <button type="button" class="btn btn-primary" data-open-modal="apptCreateModal"><i class="fas fa-plus" aria-hidden="true"></i> New appointment</button>
        </div>
    </div>

    <div class="stats-grid reports-stats">
        <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-inbox" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Awaiting my response</span><span class="stat-value"><?= $tabCounts['requests'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-paper-plane" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Sent, awaiting reply</span><span class="stat-value"><?= $tabCounts['sent'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-calendar-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Upcoming</span><span class="stat-value"><?= $tabCounts['upcoming'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-calendar-day" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Today</span><span class="stat-value"><?= (int)$countToday ?></span></div></div>
    </div>

    <div class="card">
        <nav class="tab-nav" aria-label="Appointment views">
            <?php foreach ($views as $key => $label): ?>
                <a class="tab-btn<?= $view === $key ? ' active' : '' ?>" href="appointments?view=<?= $key ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>>
                    <?= $escape($label) ?><?php if (!empty($tabCounts[$key])): ?> <span class="badge"><?= $tabCounts[$key] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($view === 'calendar'): ?>
            <div class="card-header">
                <h3><i class="fas fa-calendar" aria-hidden="true"></i> <?= $escape($month->format('F Y')) ?></h3>
                <div class="appt-cal-nav">
                    <a class="btn btn-secondary btn-sm" href="appointments?view=calendar&month=<?= $month->modify('-1 month')->format('Y-m') ?>" aria-label="Previous month"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                    <a class="btn btn-secondary btn-sm" href="appointments?view=calendar">Today</a>
                    <a class="btn btn-secondary btn-sm" href="appointments?view=calendar&month=<?= $month->modify('+1 month')->format('Y-m') ?>" aria-label="Next month"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                </div>
            </div>
            <div class="card-body">
                <div class="appt-cal">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName): ?>
                        <div class="appt-cal-head"><?= $dayName ?></div>
                    <?php endforeach; ?>
                    <?php for ($day = $gridStart; $day < $gridEnd; $day = $day->modify('+1 day')):
                        $key = $day->format('Y-m-d');
                        $items = $byDay[$key] ?? [];
                        $classes = ['appt-cal-day'];
                        if ($day->format('Y-m') !== $monthKey) $classes[] = 'is-other';
                        if ($key === date('Y-m-d')) $classes[] = 'is-today';
                        if ($items) $classes[] = 'has-items';
                    ?>
                        <div class="<?= implode(' ', $classes) ?>">
                            <span class="appt-cal-num" title="<?= $escape($day->format('l j F Y')) ?>"><?= $day->format('j') ?></span>
                            <?php foreach ($items as $item):
                                $label = substr((string)$item['start_at'], 11, 5) . ' ' . $item['title'];
                                if ($item['status'] === 'Event'): ?>
                                <span class="appt-chip is-event" title="<?= $escape('Hub event you are staffing · ' . appt_format_range((string)$item['start_at'], (string)$item['end_at'])) ?>"><?= $escape($label) ?></span>
                                <?php continue; endif;
                                $pending = $item['status'] === 'Pending';
                                $tip = $item['title'] . ' with ' . $otherName($item) . ' · ' . appt_format_range((string)$item['start_at'], (string)$item['end_at']) . ($pending ? ' · awaiting response' : '');
                            ?>
                                <a class="appt-chip<?= $pending ? ' is-pending' : '' ?>" href="appointments?view=<?= $pending ? (appt_responder_id($item) === $uid ? 'requests' : 'sent') : 'upcoming' ?>" title="<?= $escape($tip) ?>"><?= $escape($label) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="appt-legend">
                    <span><span class="appt-chip">Confirmed</span> slot blocked for both</span>
                    <span><span class="appt-chip is-pending">Pending</span> awaiting a response</span>
                    <span><span class="appt-chip is-event">Event</span> hub event you are staffing</span>
                </div>
            </div>
        <?php else: ?>
            <div class="card-body">
                <?php if (!$rows): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-xmark" aria-hidden="true"></i>
                        <p><?= [
                            'requests' => 'No appointment requests are waiting for you.',
                            'sent' => 'You have no requests awaiting a reply.',
                            'upcoming' => 'No upcoming appointments.',
                            'history' => 'No past appointments yet.',
                        ][$view] ?></p>
                    </div>
                <?php endif; ?>
                <?php foreach ($rows as $a):
                    [$statusLabel, $statusClass] = $statusBadge($a);
                    $isRequester = (int)$a['requester_id'] === $uid;
                    $myRemind = (int)($isRequester ? $a['requester_remind_minutes'] : $a['invitee_remind_minutes']);
                    $rescheduled = $a['status'] === 'Pending' && (int)$a['revision'] > 0;
                    $data = 'data-id="' . (int)$a['appointment_id'] . '" data-title="' . $escape($a['title']) . '" data-other="' . (int)($isRequester ? $a['invitee_id'] : $a['requester_id'])
                        . '" data-start="' . $escape(substr(str_replace(' ', 'T', (string)$a['start_at']), 0, 16)) . '" data-end="' . $escape(substr(str_replace(' ', 'T', (string)$a['end_at']), 0, 16)) . '"';
                    $upcomingFuture = $view === 'upcoming' && (string)$a['start_at'] > $now;
                ?>
                    <article class="appt-row">
                        <div>
                            <div class="appt-title"><?= $escape($a['title']) ?><?php if ($rescheduled): ?> <span class="badge badge-info"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> New time proposed<?= (int)$a['proposed_by'] === $uid ? ' by you' : '' ?></span><?php endif; ?></div>
                            <div class="appt-meta">
                                <i class="fas fa-clock" aria-hidden="true"></i> <?= $escape(appt_format_range((string)$a['start_at'], (string)$a['end_at'])) ?>
                                · <?= $isRequester ? 'With' : 'From' ?> <?= $escape($otherName($a)) ?>
                                <?php if (!empty($a['location'])): ?> · <i class="fas fa-location-dot" aria-hidden="true"></i> <?= $escape($a['location']) ?><?php endif; ?>
                                <?php if ($a['status'] === 'Accepted' && $myRemind > 0): ?> · <i class="fas fa-bell" aria-hidden="true"></i> <?= $escape($reminders[$myRemind] ?? $myRemind . ' min before') ?><?php endif; ?>
                                <?php if (!empty($a['agenda'])): ?><br><?= nl2br($escape($a['agenda'])) ?><?php endif; ?>
                                <?php if (!empty($a['response_note'])): ?><br><strong>Note:</strong> <?= $escape($a['response_note']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="appt-actions">
                            <span class="badge <?= $statusClass ?>"><?= $escape($statusLabel) ?></span>
                            <?php if ($view === 'requests'): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-respond="accept" <?= $data ?>><i class="fas fa-check" aria-hidden="true"></i> Accept</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-reschedule <?= $data ?>><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Suggest new time</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-respond="decline" <?= $data ?>><i class="fas fa-xmark" aria-hidden="true"></i> Decline</button>
                            <?php elseif ($view === 'sent' || $upcomingFuture): ?>
                                <button type="button" class="btn btn-secondary btn-sm" data-reschedule <?= $data ?>><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> <?= $view === 'sent' ? 'Change time' : 'Reschedule' ?></button>
                                <button type="button" class="btn btn-danger btn-sm" data-respond="cancel" <?= $data ?>><i class="fas fa-ban" aria-hidden="true"></i> <?= $view === 'sent' ? 'Withdraw' : 'Cancel' ?></button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="apptCreateModal" role="dialog" aria-modal="true" aria-labelledby="apptCreateTitle">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="apptCreateTitle"><i class="fas fa-handshake" aria-hidden="true"></i> New appointment</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="post" action="appointments">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="return_view" value="<?= $escape($view) ?>">
                <div class="appt-form-grid">
                    <div class="form-group is-wide">
                        <label for="apptInvitee">Meet with <span class="required-mark">*</span></label>
                        <select id="apptInvitee" name="invitee_id" class="form-control" required>
                            <option value="">Choose a staff member</option>
                            <?php foreach ($staff as $person): ?>
                                <option value="<?= (int)$person['user_id'] ?>"><?= $escape($person['full_name']) ?> (<?= $escape($person['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group is-wide">
                        <label for="apptTitle">Subject <span class="required-mark">*</span></label>
                        <input id="apptTitle" name="title" class="form-control" maxlength="200" required placeholder="e.g. Q4 budget review">
                    </div>
                    <div class="form-group">
                        <label for="apptStart">Starts <span class="required-mark">*</span></label>
                        <input id="apptStart" name="start_at" type="datetime-local" class="form-control" required min="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="form-group">
                        <label for="apptEnd">Ends <span class="required-mark">*</span></label>
                        <input id="apptEnd" name="end_at" type="datetime-local" class="form-control" required min="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="is-wide appt-busy" id="apptBusy" aria-live="polite"></div>
                    <div class="form-group">
                        <label for="apptLocation">Location or link</label>
                        <input id="apptLocation" name="location" class="form-control" maxlength="255" placeholder="e.g. Board room, or a meeting link">
                    </div>
                    <div class="form-group">
                        <label for="apptRemind">Remind me</label>
                        <select id="apptRemind" name="remind_minutes" class="form-control">
                            <?php foreach ($reminders as $minutes => $label): ?>
                                <option value="<?= $minutes ?>"<?= $minutes === 30 ? ' selected' : '' ?>><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group is-wide">
                        <label for="apptAgenda">Agenda</label>
                        <textarea id="apptAgenda" name="agenda" class="form-control" rows="3" placeholder="What would you like to discuss?"></textarea>
                        <p class="form-hint">They get a notification and an email. Once they accept, the time is blocked in both calendars.</p>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send request</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="apptRespondModal" role="dialog" aria-modal="true" aria-labelledby="apptRespondTitle">
    <div class="modal-card appt-modal-sm">
        <div class="modal-head">
            <h3 id="apptRespondTitle">Respond</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="post" action="appointments">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="apptRespondAction">
                <input type="hidden" name="appointment_id" id="apptRespondId">
                <input type="hidden" name="return_view" value="<?= $escape($view) ?>">
                <p id="apptRespondMessage"></p>
                <div class="form-group" id="apptRespondRemind">
                    <label for="apptRespondRemindSelect">Remind me</label>
                    <select id="apptRespondRemindSelect" name="remind_minutes" class="form-control">
                        <?php foreach ($reminders as $minutes => $label): ?>
                            <option value="<?= $minutes ?>"<?= $minutes === 30 ? ' selected' : '' ?>><?= $escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="apptRespondNote">
                    <label for="apptRespondNoteInput">Reason (optional)</label>
                    <textarea id="apptRespondNoteInput" name="note" class="form-control" rows="2" maxlength="500" placeholder="e.g. I'm out of office that day; could we do Thursday?"></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal>Back</button>
                <button type="submit" class="btn btn-primary" id="apptRespondSubmit">Confirm</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="apptRescheduleModal" role="dialog" aria-modal="true" aria-labelledby="apptRescheduleTitle">
    <div class="modal-card appt-modal-sm">
        <div class="modal-head">
            <h3 id="apptRescheduleTitle"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Propose a new time</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="post" action="appointments">
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reschedule">
                <input type="hidden" name="appointment_id" id="apptRescheduleId">
                <input type="hidden" name="return_view" value="<?= $escape($view) ?>">
                <p id="apptRescheduleMessage"></p>
                <div class="appt-form-grid">
                    <div class="form-group">
                        <label for="apptRescheduleStart">Starts <span class="required-mark">*</span></label>
                        <input id="apptRescheduleStart" name="start_at" type="datetime-local" class="form-control" required min="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="form-group">
                        <label for="apptRescheduleEnd">Ends <span class="required-mark">*</span></label>
                        <input id="apptRescheduleEnd" name="end_at" type="datetime-local" class="form-control" required min="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="is-wide appt-busy" id="apptRescheduleBusy" aria-live="polite"></div>
                    <div class="form-group is-wide">
                        <label for="apptRescheduleNote">Note (optional)</label>
                        <textarea id="apptRescheduleNote" name="note" class="form-control" rows="2" maxlength="500" placeholder="e.g. Something came up; does Thursday afternoon work?"></textarea>
                        <p class="form-hint">The other person must accept the new time. If it was already confirmed, the slot is released until they do.</p>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal>Back</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send new time</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function open(id) { document.getElementById(id).classList.add('show'); document.body.style.overflow = 'hidden'; }
    function closeAll() { document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show')); document.body.style.overflow = ''; }
    document.querySelectorAll('[data-open-modal]').forEach(b => b.addEventListener('click', () => { open(b.dataset.openModal); document.getElementById('apptInvitee').focus(); }));
    document.querySelectorAll('[data-close-modal]').forEach(b => b.addEventListener('click', closeAll));
    document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) closeAll(); }));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });

    // Respond modal: accept (pick a reminder), decline or cancel (optional reason).
    const copy = {
        accept: ['Accept appointment', 'Accept “%s”? It will be added to both calendars and the time blocked for both of you.', 'Accept', 'btn btn-primary'],
        decline: ['Decline appointment', 'Decline “%s”? The requester will be notified.', 'Decline', 'btn btn-danger'],
        cancel: ['Cancel appointment', 'Cancel “%s”? The other person will be notified and the slot freed.', 'Cancel appointment', 'btn btn-danger'],
    };
    document.querySelectorAll('[data-respond]').forEach(b => b.addEventListener('click', () => {
        const mode = b.dataset.respond, c = copy[mode];
        document.getElementById('apptRespondAction').value = mode;
        document.getElementById('apptRespondId').value = b.dataset.id;
        document.getElementById('apptRespondTitle').textContent = c[0];
        document.getElementById('apptRespondMessage').textContent = c[1].replace('%s', b.dataset.title);
        const submit = document.getElementById('apptRespondSubmit');
        submit.textContent = c[2];
        submit.className = c[3];
        document.getElementById('apptRespondRemind').hidden = mode !== 'accept';
        document.getElementById('apptRespondNote').hidden = mode === 'accept';
        open('apptRespondModal');
    }));

    // Default the end to +30 min and list both people's busy times for the chosen day.
    const pad = n => String(n).padStart(2, '0');
    function wireTimes(start, end, busy, otherOf, excludeOf) {
        function loadBusy() {
            busy.textContent = '';
            const other = otherOf();
            if (!other || !start.value) return;
            fetch('appointments?busy=1&user=' + encodeURIComponent(other) + '&date=' + encodeURIComponent(start.value.substring(0, 10)) + '&exclude=' + encodeURIComponent(excludeOf()), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (!data.busy || !data.busy.length) {
                        busy.innerHTML = '<span class="cell-muted"><i class="fas fa-circle-check" aria-hidden="true"></i> Both of you are free that day.</span>';
                        return;
                    }
                    const list = document.createElement('ul');
                    data.busy.forEach(b => { const li = document.createElement('li'); li.textContent = b.who + ': ' + b.start + '–' + b.end; list.append(li); });
                    busy.innerHTML = '<strong>Already booked that day:</strong>';
                    busy.append(list);
                })
                .catch(() => {});
        }
        start.addEventListener('change', () => {
            if (start.value) {
                end.min = start.value;
                if (!end.value || end.value <= start.value) {
                    const d = new Date(start.value);
                    d.setMinutes(d.getMinutes() + 30);
                    end.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                }
            }
            loadBusy();
        });
        return loadBusy;
    }

    const invitee = document.getElementById('apptInvitee');
    const loadCreateBusy = wireTimes(document.getElementById('apptStart'), document.getElementById('apptEnd'), document.getElementById('apptBusy'), () => invitee.value, () => '');
    invitee.addEventListener('change', loadCreateBusy);

    let rescheduling = null;
    const rStart = document.getElementById('apptRescheduleStart'), rEnd = document.getElementById('apptRescheduleEnd');
    const loadRescheduleBusy = wireTimes(rStart, rEnd, document.getElementById('apptRescheduleBusy'), () => rescheduling ? rescheduling.other : '', () => rescheduling ? rescheduling.id : '');
    document.querySelectorAll('[data-reschedule]').forEach(b => b.addEventListener('click', () => {
        rescheduling = b.dataset;
        document.getElementById('apptRescheduleId').value = b.dataset.id;
        document.getElementById('apptRescheduleMessage').textContent = 'Current time for “' + b.dataset.title + '”: ' + b.dataset.start.replace('T', ' ') + ' – ' + b.dataset.end.substring(11);
        rStart.value = b.dataset.start;
        rEnd.value = b.dataset.end;
        document.getElementById('apptRescheduleNote').value = '';
        open('apptRescheduleModal');
        loadRescheduleBusy();
        rStart.focus();
    }));
})();
</script>
<?php include 'includes/footer.php'; ?>
