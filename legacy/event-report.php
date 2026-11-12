<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Event registration & attendance report
|--------------------------------------------------------------------------
| One event across all its days: who registered, who attended (QR check-in,
| self-confirmation from the reminders, or marked by staff), who said they
| could not come, and who never answered. Same filters drive the page, the
| CSV export (?export=csv) and the PDF export (?export=pdf).
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/event-reminders.php';

const EVENT_REPORT_ROLES = ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer', 'Program Director', 'Program Manager', 'Program Officer'];
check_role(EVENT_REPORT_ROLES);

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ev_links_ensure_schema($conn);
evr_ensure_schema($conn);

$eventId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare(
    'SELECT e.*, p.project_name, pr.program_name
     FROM hub_events e
     LEFT JOIN projects p ON p.project_id = e.project_id
     LEFT JOIN programs pr ON pr.id = e.program_id
     WHERE e.event_id = ? LIMIT 1'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    $_SESSION['notification'] = ['message' => 'Event not found.', 'type' => 'danger'];
    header('Location: events');
    exit;
}

/*
|--------------------------------------------------------------------------
| Event days and filters
|--------------------------------------------------------------------------
*/
$days = [];
foreach ((array)json_decode((string)($event['event_days'] ?? ''), true) as $day) {
    if (is_string($day) && strtotime($day)) {
        $days[] = date('Y-m-d', strtotime($day));
    }
}
if (!$days && !empty($event['event_date'])) {
    $days[] = date('Y-m-d', strtotime((string)$event['event_date']));
}

$statuses = ['Registered', 'Attended', 'No Show', 'Cancelled'];
$responses = ['yes' => 'Said attending', 'no' => 'Said not attending', 'none' => 'No answer'];
$filters = [
    'day' => (string)($_GET['day'] ?? ''),
    'status' => in_array((string)($_GET['status'] ?? ''), $statuses, true) ? (string)$_GET['status'] : '',
    'response' => array_key_exists((string)($_GET['response'] ?? ''), $responses) ? (string)$_GET['response'] : '',
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
];

/*
|--------------------------------------------------------------------------
| Data: every registration for the event (filtered in PHP; events are small)
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare('SELECT * FROM event_registrations WHERE event_id = ? ORDER BY attendance_date, attendee_name');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($all as $r) {
    $day = substr((string)($r['attendance_date'] ?? ''), 0, 10);
    if ($day !== '' && !in_array($day, $days, true)) {
        $days[] = $day;
    }
}
sort($days);
if ($filters['day'] !== '' && !in_array($filters['day'], $days, true)) {
    $filters['day'] = '';
}

$typeOf = static fn(array $r): string => !empty($r['member_id']) ? 'Member' : (!empty($r['beneficiary_id']) ? 'Participant' : 'Guest');
$fmt = static fn($dt, string $format = 'j M Y, H:i'): string => !empty($dt) && strtotime((string)$dt) ? date($format, strtotime((string)$dt)) : '';

/** How the attendance outcome was recorded. */
$howRecorded = static function (array $r) use ($fmt): string {
    if (($r['attendance_status'] ?? '') === 'Checked In') {
        return 'QR check-in' . (($t = $fmt($r['check_in_time'] ?? '', 'H:i')) !== '' ? ' at ' . $t : '');
    }
    if (!empty($r['confirmation_response'])) {
        return 'Self: ' . ($r['confirmation_response'] === 'Attended' ? 'attending' : 'not attending')
            . (($t = $fmt($r['confirmed_at'] ?? '', 'j M, H:i')) !== '' ? ' (' . $t . ')' : '');
    }
    if (in_array($r['registration_status'] ?? '', ['Attended', 'No Show'], true)) {
        return 'Marked by staff';
    }

    return '';
};

$rows = array_values(array_filter($all, static function (array $r) use ($filters): bool {
    if ($filters['day'] !== '' && substr((string)($r['attendance_date'] ?? ''), 0, 10) !== $filters['day']) {
        return false;
    }
    if ($filters['status'] !== '' && ($r['registration_status'] ?? '') !== $filters['status']) {
        return false;
    }
    $response = (string)($r['confirmation_response'] ?? '');
    if ($filters['response'] === 'yes' && $response !== 'Attended') return false;
    if ($filters['response'] === 'no' && $response !== 'Not Attending') return false;
    if ($filters['response'] === 'none' && $response !== '') return false;
    if ($filters['q'] !== '') {
        $hay = mb_strtolower(($r['attendee_name'] ?? '') . ' ' . ($r['email'] ?? '') . ' ' . ($r['phone'] ?? '') . ' ' . ($r['organization'] ?? ''));
        if (!str_contains($hay, mb_strtolower($filters['q']))) return false;
    }
    return true;
}));

/** Totals for a set of registrations. */
$summarise = static function (array $set): array {
    $s = ['registered' => 0, 'attended' => 0, 'no_show' => 0, 'pending' => 0, 'cancelled' => 0, 'checked_in' => 0, 'said_yes' => 0, 'said_no' => 0, 'no_answer' => 0, 'pwd' => 0, 'photo_consent' => 0];
    foreach ($set as $r) {
        $status = (string)($r['registration_status'] ?? '');
        if ($status === 'Cancelled') {
            $s['cancelled']++;
            continue;
        }
        $s['registered']++;
        if ($status === 'Attended') $s['attended']++;
        elseif ($status === 'No Show') $s['no_show']++;
        else $s['pending']++;
        if (($r['attendance_status'] ?? '') === 'Checked In') $s['checked_in']++;
        $response = (string)($r['confirmation_response'] ?? '');
        if ($response === 'Attended') $s['said_yes']++;
        elseif ($response === 'Not Attending') $s['said_no']++;
        else $s['no_answer']++;
        if (!empty($r['is_pwd'])) $s['pwd']++;
        if (!empty($r['photo_consent'])) $s['photo_consent']++;
    }
    $s['rate'] = $s['registered'] > 0 ? round($s['attended'] / $s['registered'] * 100, 1) : 0.0;

    return $s;
};

$totals = $summarise($rows);
$byDay = [];
foreach ($days as $day) {
    if ($filters['day'] === '' || $filters['day'] === $day) {
        $byDay[$day] = $summarise(array_filter($rows, static fn($r) => substr((string)($r['attendance_date'] ?? ''), 0, 10) === $day));
    }
}

$filterText = implode(' · ', array_filter([
    $filters['day'] !== '' ? 'Day: ' . date('j M Y', strtotime($filters['day'])) : 'All days',
    $filters['status'] !== '' ? 'Status: ' . $filters['status'] : '',
    $filters['response'] !== '' ? $responses[$filters['response']] : '',
    $filters['q'] !== '' ? 'Search: "' . $filters['q'] . '"' : '',
]));
$daysText = $days ? implode(', ', array_map(static fn($d) => date('j M Y', strtotime($d)), $days)) : '-';
$timeText = trim(substr((string)($event['start_time'] ?? ''), 0, 5) . (!empty($event['end_time']) ? ' – ' . substr((string)$event['end_time'], 0, 5) : ''));
$baseName = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', (string)$event['event_title']), '-') ?: 'event';
$fileName = $baseName . '-attendance-report-' . date('Y-m-d');

/*
|--------------------------------------------------------------------------
| CSV export
|--------------------------------------------------------------------------
*/
$export = (string)($_GET['export'] ?? '');
if ($export === 'csv') {
    if (function_exists('log_action')) {
        log_action((int)($_SESSION['user_id'] ?? 0), 'Export Event Report', 'hub_events', $eventId, 'CSV · ' . $filterText);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    $put = static fn(array $fields) => function_exists('ims_fputcsv') ? ims_fputcsv($out, $fields) : fputcsv($out, $fields);

    $put(['Event', $event['event_title']]);
    $put(['Type', $event['event_type'] ?? '']);
    $put(['Days', $daysText]);
    $put(['Time', $timeText]);
    $put(['Venue', $event['venue'] ?? '']);
    $put(['Filters', $filterText]);
    $put(['Generated', date('j M Y, H:i')]);
    $put([]);
    $put(['Summary']);
    $put(['Registered', 'Attended', 'No show', 'Awaiting outcome', 'Cancelled', 'Attendance rate %', 'QR check-ins', 'Said attending', 'Said not attending', 'No answer', 'PWD', 'Photo consent']);
    $put([$totals['registered'], $totals['attended'], $totals['no_show'], $totals['pending'], $totals['cancelled'], $totals['rate'], $totals['checked_in'], $totals['said_yes'], $totals['said_no'], $totals['no_answer'], $totals['pwd'], $totals['photo_consent']]);
    $put([]);
    $put(['By day']);
    $put(['Day', 'Registered', 'Attended', 'No show', 'Awaiting outcome', 'Cancelled', 'Attendance rate %']);
    foreach ($byDay as $day => $s) {
        $put([date('Y-m-d', strtotime($day)), $s['registered'], $s['attended'], $s['no_show'], $s['pending'], $s['cancelled'], $s['rate']]);
    }
    $put([]);
    $put(['Registrations']);
    $put(['#', 'Name', 'Type', 'Organisation', 'Email', 'Phone', 'Event day', 'Registered at', 'Status', 'How recorded', 'Check-in time', 'Self-confirmation', 'Confirmed at', 'Reminders sent', 'PWD', 'Disability type', 'Photo consent', 'Feedback score']);
    foreach ($rows as $i => $r) {
        $put([
            $i + 1,
            $r['attendee_name'] ?? '',
            $typeOf($r),
            $r['organization'] ?? '',
            $r['email'] ?? '',
            $r['phone'] ?? '',
            substr((string)($r['attendance_date'] ?? ''), 0, 10),
            $fmt($r['registered_at'] ?? '', 'Y-m-d H:i'),
            $r['registration_status'] ?? '',
            $howRecorded($r),
            $fmt($r['check_in_time'] ?? '', 'Y-m-d H:i'),
            ($r['confirmation_response'] ?? '') === 'Attended' ? 'Attending' : (($r['confirmation_response'] ?? '') === 'Not Attending' ? 'Not attending' : ''),
            $fmt($r['confirmed_at'] ?? '', 'Y-m-d H:i'),
            (int)($r['reminder_stage'] ?? 0) . '/' . count(EVR_OFFSETS),
            !empty($r['is_pwd']) ? 'Yes' : 'No',
            $r['pwd_type'] ?? '',
            !empty($r['photo_consent']) ? 'Yes' : 'No',
            $r['feedback_score'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/*
|--------------------------------------------------------------------------
| PDF export
|--------------------------------------------------------------------------
*/
if ($export === 'pdf') {
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }
    if (!class_exists('Dompdf\\Dompdf')) {
        $_SESSION['notification'] = ['message' => 'PDF export is unavailable: the Dompdf library is not installed.', 'type' => 'danger'];
        header('Location: event-report?' . http_build_query(['id' => $eventId] + array_filter($filters)));
        exit;
    }
    if (function_exists('log_action')) {
        log_action((int)($_SESSION['user_id'] ?? 0), 'Export Event Report', 'hub_events', $eventId, 'PDF · ' . $filterText);
    }

    $stat = static fn(string $label, $value): string => '<td><strong>' . $h($value) . '</strong><br>' . $h($label) . '</td>';
    $dayRows = '';
    foreach ($byDay as $day => $s) {
        $dayRows .= '<tr><td>' . $h(date('D j M Y', strtotime($day))) . '</td><td>' . $s['registered'] . '</td><td>' . $s['attended'] . '</td><td>' . $s['no_show'] . '</td><td>' . $s['pending'] . '</td><td>' . $s['cancelled'] . '</td><td>' . $s['rate'] . '%</td></tr>';
    }
    $listRows = '';
    foreach ($rows as $i => $r) {
        $listRows .= '<tr>'
            . '<td>' . ($i + 1) . '</td>'
            . '<td><strong>' . $h($r['attendee_name'] ?? '') . '</strong><br><small>' . $h($typeOf($r)) . (!empty($r['organization']) ? ' · ' . $h($r['organization']) : '') . '</small></td>'
            . '<td>' . $h($r['email'] ?? '') . '<br>' . $h($r['phone'] ?? '') . '</td>'
            . '<td>' . $h(date('j M Y', strtotime((string)($r['attendance_date'] ?? 'now')))) . '</td>'
            . '<td>' . $h($r['registration_status'] ?? '') . '</td>'
            . '<td>' . $h($howRecorded($r) ?: '-') . '</td>'
            . '<td>' . (!empty($r['is_pwd']) ? 'Yes' . (!empty($r['pwd_type']) ? ' (' . $h($r['pwd_type']) . ')' : '') : 'No') . '</td>'
            . '</tr>';
    }
    if ($listRows === '') {
        $listRows = '<tr><td colspan="7" style="text-align:center;color:#777;padding:18px">No registrations match these filters.</td></tr>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:10.5px;color:#222;margin:22px}
        h1{font-size:18px;margin:0 0 4px} h2{font-size:13px;margin:16px 0 6px}
        .muted{color:#666} .meta{margin:10px 0 12px;border:1px solid #ddd;padding:8px 10px;border-radius:6px;line-height:1.6}
        table{width:100%;border-collapse:collapse} th,td{border:1px solid #ddd;padding:5px 6px;vertical-align:top;text-align:left}
        th{background:#f2f2f2} .stats td{text-align:center} .footer{margin-top:14px;font-size:9px;color:#777;text-align:right}
    </style></head><body>
        <h1>' . $h($event['event_title']) . '</h1>
        <div class="muted">Registration &amp; attendance report · ' . $h($filterText) . '</div>
        <div class="meta">
            <strong>Type:</strong> ' . $h($event['event_type'] ?? '-') . ' &nbsp; <strong>Days:</strong> ' . $h($daysText) . ($timeText !== '' ? ' &nbsp; <strong>Time:</strong> ' . $h($timeText) : '') . '<br>
            <strong>Venue:</strong> ' . $h(($event['venue'] ?? '') ?: '-') . ' &nbsp; <strong>Organiser:</strong> ' . $h(($event['organizer'] ?? '') ?: '-') . ' &nbsp; <strong>Project/Program:</strong> ' . $h(($event['project_name'] ?? '') ?: (($event['program_name'] ?? '') ?: '-')) . '
        </div>
        <table class="stats"><tr>'
            . $stat('Registered', $totals['registered']) . $stat('Attended', $totals['attended']) . $stat('No show', $totals['no_show'])
            . $stat('Awaiting outcome', $totals['pending']) . $stat('Cancelled', $totals['cancelled']) . $stat('Attendance rate', $totals['rate'] . '%')
        . '</tr><tr>'
            . $stat('QR check-ins', $totals['checked_in']) . $stat('Said attending', $totals['said_yes']) . $stat('Said not attending', $totals['said_no'])
            . $stat('No answer', $totals['no_answer']) . $stat('PWD', $totals['pwd']) . $stat('Photo consent', $totals['photo_consent'])
        . '</tr></table>
        <h2>By day</h2>
        <table><thead><tr><th>Day</th><th>Registered</th><th>Attended</th><th>No show</th><th>Awaiting</th><th>Cancelled</th><th>Rate</th></tr></thead><tbody>' . $dayRows . '</tbody></table>
        <h2>Registrations (' . count($rows) . ')</h2>
        <table><thead><tr><th>#</th><th>Name</th><th>Contact</th><th>Day</th><th>Status</th><th>How recorded</th><th>PWD</th></tr></thead><tbody>' . $listRows . '</tbody></table>
        <div class="footer">Generated ' . $h(date('j M Y, H:i')) . '</div>
    </body></html>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    if (is_file(__DIR__ . '/includes/pdf-branding.php')) {
        require_once __DIR__ . '/includes/pdf-branding.php';
        if (function_exists('ims_brand_pdf')) {
            ims_brand_pdf($dompdf);
        }
    }
    $dompdf->stream($fileName . '.pdf', ['Attachment' => true]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/
$page_title = 'Event Report';
$query = static fn(array $extra = []): string => 'event-report?' . http_build_query(['id' => $eventId] + array_filter($filters, static fn($v) => $v !== '') + $extra);
$statusClass = ['Registered' => 'badge-warning', 'Attended' => 'badge-success', 'No Show' => 'badge-danger', 'Cancelled' => 'badge-secondary'];
include 'includes/header.php';
?>

<style>
.er-tone-good{color:var(--green-fg)} .er-tone-bad{color:var(--red-fg)}
.er-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.er-filters .form-control{width:auto;min-width:150px;flex:1 1 150px}
.er-reminders{font-variant-numeric:tabular-nums}
</style>

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-chart-column" aria-hidden="true"></i> <?= $h($event['event_title']) ?></h1>
            <p>Registration &amp; attendance report · <?= $h($daysText) ?><?= $timeText !== '' ? ' · ' . $h($timeText) : '' ?><?= !empty($event['venue']) ? ' · ' . $h($event['venue']) : '' ?></p>
        </div>
        <div class="hero-actions">
            <a href="events" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Events</a>
            <a href="event-attendance?id=<?= $eventId ?>" class="btn btn-secondary"><i class="fas fa-user-check" aria-hidden="true"></i> Mark attendance</a>
            <a href="<?= $h($query(['export' => 'csv'])) ?>" class="btn btn-secondary"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
            <a href="<?= $h($query(['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
        </div>
    </div>

    <div class="stats-grid reports-stats">
        <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Registered</span><span class="stat-value"><?= $totals['registered'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-user-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Attended (<?= $totals['rate'] ?>%)</span><span class="stat-value"><?= $totals['attended'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-red"><i class="fas fa-user-xmark" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">No show</span><span class="stat-value"><?= $totals['no_show'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-hourglass-half" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Awaiting outcome</span><span class="stat-value"><?= $totals['pending'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-reply" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Answered reminders (yes / no)</span><span class="stat-value"><?= $totals['said_yes'] ?> / <?= $totals['said_no'] ?></span></div></div>
        <div class="stat-card"><div class="stat-icon bg-slate"><i class="fas fa-qrcode" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">QR check-ins</span><span class="stat-value"><?= $totals['checked_in'] ?></span></div></div>
    </div>

    <?php if (count($byDay) > 1 || $filters['day'] === ''): ?>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-calendar-days" aria-hidden="true"></i> By day</h3></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-stack">
                    <thead><tr><th scope="col">Day</th><th scope="col">Registered</th><th scope="col">Attended</th><th scope="col">No show</th><th scope="col">Awaiting</th><th scope="col">Cancelled</th><th scope="col">Attendance</th></tr></thead>
                    <tbody>
                        <?php foreach ($byDay as $day => $s): ?>
                            <tr>
                                <td data-label="Day"><a href="<?= $h($query(['day' => $day])) ?>"><?= $h(date('D j M Y', strtotime($day))) ?></a></td>
                                <td data-label="Registered"><?= $s['registered'] ?></td>
                                <td data-label="Attended"><?= $s['attended'] ?></td>
                                <td data-label="No show"><?= $s['no_show'] ?></td>
                                <td data-label="Awaiting"><?= $s['pending'] ?></td>
                                <td data-label="Cancelled"><?= $s['cancelled'] ?></td>
                                <td data-label="Attendance" style="min-width:140px">
                                    <div class="meter" role="img" aria-label="<?= $s['rate'] ?>% attended"><div class="meter-fill tone-<?= $s['rate'] >= 70 ? 'green' : ($s['rate'] >= 40 ? 'amber' : 'red') ?>" style="width:<?= min(100, $s['rate']) ?>%"></div></div>
                                    <span class="cell-muted"><?= $s['rate'] ?>%</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$byDay): ?><tr><td colspan="7" class="cell-muted">No event days.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-list" aria-hidden="true"></i> Registrations <span class="badge"><?= count($rows) ?></span></h3></div>
        <form method="GET" action="event-report" class="filters-bar card-body er-filters">
            <input type="hidden" name="id" value="<?= $eventId ?>">
            <label class="sr-only" for="erQ">Search</label>
            <input id="erQ" type="search" name="q" class="form-control" value="<?= $h($filters['q']) ?>" placeholder="Search name, email, phone, organisation">
            <label class="sr-only" for="erDay">Day</label>
            <select id="erDay" name="day" class="form-control" onchange="this.form.submit()">
                <option value="">All days</option>
                <?php foreach ($days as $day): ?><option value="<?= $h($day) ?>"<?= $filters['day'] === $day ? ' selected' : '' ?>><?= $h(date('D j M Y', strtotime($day))) ?></option><?php endforeach; ?>
            </select>
            <label class="sr-only" for="erStatus">Status</label>
            <select id="erStatus" name="status" class="form-control" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $s): ?><option value="<?= $h($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h($s) ?></option><?php endforeach; ?>
            </select>
            <label class="sr-only" for="erResponse">Reminder answer</label>
            <select id="erResponse" name="response" class="form-control" onchange="this.form.submit()">
                <option value="">Any reminder answer</option>
                <?php foreach ($responses as $key => $label): ?><option value="<?= $h($key) ?>"<?= $filters['response'] === $key ? ' selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary"><i class="fas fa-filter" aria-hidden="true"></i> Filter</button>
            <?php if (array_filter($filters)): ?><a href="event-report?id=<?= $eventId ?>" class="btn btn-soft">Clear</a><?php endif; ?>
        </form>
        <div class="card-body">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fas fa-user-slash" aria-hidden="true"></i><p><?= $all ? 'No registrations match these filters.' : 'Nobody has registered for this event yet.' ?></p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Name</th>
                                <th scope="col">Contact</th>
                                <th scope="col">Day</th>
                                <th scope="col">Registered</th>
                                <th scope="col">Status</th>
                                <th scope="col">How recorded</th>
                                <th scope="col">Reminders</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $r): $status = (string)($r['registration_status'] ?? ''); ?>
                                <tr>
                                    <td data-label="#" class="cell-muted"><?= $i + 1 ?></td>
                                    <td data-label="Name">
                                        <strong><?= $h($r['attendee_name'] ?? '') ?></strong>
                                        <div class="cell-muted"><?= $h($typeOf($r)) ?><?= !empty($r['organization']) ? ' · ' . $h($r['organization']) : '' ?><?= !empty($r['is_pwd']) ? ' · <i class="fas fa-wheelchair" aria-hidden="true"></i> PWD' . (!empty($r['pwd_type']) ? ' (' . $h($r['pwd_type']) . ')' : '') : '' ?></div>
                                    </td>
                                    <td data-label="Contact"><?= $h($r['email'] ?? '') ?><?php if (!empty($r['phone'])): ?><div class="cell-muted"><?= $h($r['phone']) ?></div><?php endif; ?></td>
                                    <td data-label="Day"><?= $h($fmt($r['attendance_date'] ?? '', 'j M Y')) ?></td>
                                    <td data-label="Registered" class="cell-muted"><?= $h($fmt($r['registered_at'] ?? '')) ?: '-' ?></td>
                                    <td data-label="Status"><span class="badge <?= $statusClass[$status] ?? 'badge-secondary' ?>"><?= $h($status ?: '-') ?></span></td>
                                    <td data-label="How recorded"><?= $h($howRecorded($r)) ?: '<span class="cell-muted">-</span>' ?></td>
                                    <td data-label="Reminders" class="er-reminders"><span title="Reminders sent: 30, 20, 10 minutes before and at the start"><?= (int)($r['reminder_stage'] ?? 0) ?>/<?= count(EVR_OFFSETS) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <p class="form-hint">Registrants get reminders 30, 20 and 10 minutes before each event day starts and at the start, asking them to confirm. Their answer sets the status to Attended or No Show; you can still correct it on the attendance page.</p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
