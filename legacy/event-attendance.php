<?php
$page_title = 'Event Attendance';
include 'includes/header.php';

// Same roles as includes/event-attendance-process.php (page had no check).
check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer']);

// e() is used below but was only defined by the MVC helpers.
if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($event_id <= 0) {
    send_notification($_SESSION['user_id'] ?? 0, 'Invalid event ID', 'danger');
    header('Location: events');
    exit();
}

$stmt = $conn->prepare("\n    SELECT e.*, p.project_name, p.project_code, pr.program_name\n    FROM hub_events e\n    LEFT JOIN projects p ON e.project_id = p.project_id\n    LEFT JOIN programs pr ON e.program_id = pr.id\n    WHERE e.event_id = ?\n    LIMIT 1\n");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    send_notification($_SESSION['user_id'] ?? 0, 'Event not found', 'danger');
    header('Location: events');
    exit();
}

$eventDays = [];
if (!empty($event['event_days'])) {
    $decoded = json_decode((string)$event['event_days'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $day) {
            if ($day && strtotime($day)) {
                $eventDays[] = date('Y-m-d', strtotime($day));
            }
        }
    }
}
if (empty($eventDays) && !empty($event['event_date'])) {
    $eventDays[] = date('Y-m-d', strtotime($event['event_date']));
}
$eventDays = array_values(array_unique($eventDays));
sort($eventDays);

$selected_day = trim((string)($_GET['day'] ?? ''));
if ($selected_day === '' || !in_array($selected_day, $eventDays, true)) {
    $selected_day = $eventDays[0] ?? date('Y-m-d');
}

$attendance_records = [];
$stmt = $conn->prepare("\n    SELECT er.*, b.beneficiary_id AS is_beneficiary\n    FROM event_registrations er\n    LEFT JOIN beneficiaries b ON er.beneficiary_id = b.beneficiary_id\n    WHERE er.event_id = ?\n      AND (er.attendance_date = ? OR er.attendance_date IS NULL)\n    ORDER BY er.attendee_name ASC\n");
$stmt->bind_param('is', $event_id, $selected_day);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $attendance_records[] = $row;
}
$stmt->close();

$project_beneficiaries = [];
if (!empty($event['project_id'])) {
    $stmt = $conn->prepare("\n        SELECT DISTINCT b.beneficiary_id, b.first_name, b.last_name, b.phone, b.email\n        FROM beneficiaries b\n        INNER JOIN project_beneficiaries pb ON b.beneficiary_id = pb.beneficiary_id\n        WHERE pb.project_id = ?\n          AND pb.status = 'Active'\n          AND b.beneficiary_id NOT IN (\n              SELECT beneficiary_id\n              FROM event_registrations\n              WHERE event_id = ?\n                AND attendance_date = ?\n                AND beneficiary_id IS NOT NULL\n          )\n        ORDER BY b.first_name, b.last_name\n    ");
    $project_id = (int)$event['project_id'];
    $stmt->bind_param('iis', $project_id, $event_id, $selected_day);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $project_beneficiaries[] = $row;
    }
    $stmt->close();
}

$total_registered = count($attendance_records);
$total_attended = count(array_filter($attendance_records, fn($a) => ($a['registration_status'] ?? '') === 'Attended'));
$total_no_show = count(array_filter($attendance_records, fn($a) => ($a['registration_status'] ?? '') === 'No Show'));
$attendance_rate = $total_registered > 0 ? ($total_attended / $total_registered * 100) : 0;
$feedback_scores = array_filter(array_column($attendance_records, 'feedback_score'));
$avg_feedback = !empty($feedback_scores) ? array_sum($feedback_scores) / count($feedback_scores) : 0;

$update_attendee = null;
if (isset($_GET['update'])) {
    $registration_id = (int)$_GET['update'];
    $stmt = $conn->prepare("SELECT * FROM event_registrations WHERE registration_id = ? AND event_id = ? LIMIT 1");
    $stmt->bind_param('ii', $registration_id, $event_id);
    $stmt->execute();
    $update_attendee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$status_badges = [
    'Registered' => 'warning',
    'Attended'   => 'success',
    'No Show'    => 'danger'
];
$process_url = 'includes/event-attendance-process.php?id=' . (int)$event_id . '&day=' . urlencode($selected_day);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;gap:10px;flex-wrap:wrap;">
    <a href="events" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Events</a>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="<?= e($process_url) ?>&export=csv" class="btn btn-success"><i class="fas fa-file-csv"></i> Export CSV</a>
        <a href="<?= e($process_url) ?>&export=pdf" class="btn btn-danger" target="_blank"><i class="fas fa-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-check"></i> Event Information</h3>
        <span class="badge badge-<?= $event['event_status'] === 'Completed' ? 'success' : ($event['event_status'] === 'Ongoing' ? 'info' : 'warning') ?>"><?= e($event['event_status']) ?></span>
    </div>
    <div class="card-body">
        <h2><?= e($event['event_title']) ?></h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-top:20px;">
            <div><div class="text-muted">Event Type</div><strong><?= e($event['event_type']) ?></strong></div>
            <div><div class="text-muted">Selected Day</div><strong><?= e(date('d M Y', strtotime($selected_day))) ?></strong></div>
            <div><div class="text-muted">Time</div><strong><?= !empty($event['start_time']) ? e(date('h:i A', strtotime($event['start_time']))) : 'TBA' ?><?= !empty($event['end_time']) ? ' - ' . e(date('h:i A', strtotime($event['end_time']))) : '' ?></strong></div>
            <div><div class="text-muted">Venue</div><strong><?= e($event['venue'] ?? 'TBA') ?></strong></div>
            <div><div class="text-muted">Organizer</div><strong><?= e($event['organizer'] ?? 'N/A') ?></strong></div>
            <?php if (!empty($event['project_name'])): ?>
                <div><div class="text-muted">Project</div><strong><?= e(($event['project_code'] ?? '') . ' - ' . $event['project_name']) ?></strong></div>
            <?php elseif (!empty($event['program_name'])): ?>
                <div><div class="text-muted">Program</div><strong><?= e($event['program_name']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="GET" class="filters-bar" style="margin-bottom:0;" aria-label="Attendance day">
            <input type="hidden" name="id" value="<?= (int)$event_id ?>">
            <div class="form-group" style="min-width:260px;">
                <label class="form-label">Attendance Day</label>
                <select name="day" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($eventDays as $day): ?>
                        <option value="<?= e($day) ?>" <?= $selected_day === $day ? 'selected' : '' ?>><?= e(date('l, d M Y', strtotime($day))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> View Day</button>
        </form>
    </div>
</div>

<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Registered Today</span>
            <span class="stat-value"><?= $total_registered ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-user-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Attended Today</span>
            <span class="stat-value"><?= $total_attended ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-user-times" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">No Show Today</span>
            <span class="stat-value"><?= $total_no_show ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-percentage" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Attendance Rate</span>
            <span class="stat-value"><?= number_format($attendance_rate, 1) ?>%</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-star" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Average Feedback</span>
            <span class="stat-value"><?= $avg_feedback > 0 ? number_format($avg_feedback, 1) : 'N/A' ?></span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list-check"></i> Attendance List - <?= e(date('d M Y', strtotime($selected_day))) ?></h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button onclick="openModal('addAttendeeModal')" class="btn btn-primary" type="button"><i class="fas fa-user-plus"></i> Add Attendee</button>
            <?php if (!empty($project_beneficiaries)): ?>
                <button onclick="openModal('bulkRegisterModal')" class="btn btn-success" type="button"><i class="fas fa-users"></i> Bulk Register</button>
            <?php endif; ?>
            <button onclick="window.location.href='download-attendance-template?event_id=<?= (int)$event_id ?>&day=<?= e($selected_day) ?>'" class="btn btn-info" type="button"><i class="fas fa-download"></i> Template</button>
            <button onclick="openModal('uploadCsvModal')" class="btn btn-info" type="button"><i class="fas fa-upload"></i> Upload CSV</button>
            <button onclick="window.print()" class="btn btn-secondary" type="button"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <div class="card-body">
        <?php if (empty($attendance_records)): ?>
            <div style="text-align:center;padding:40px;color:#7f8c8d;">
                <i class="fas fa-users" style="font-size:48px;margin-bottom:20px;opacity:.3;"></i>
                <h4>No Attendees Registered for This Day</h4>
                <p>Add attendees to start tracking attendance for <?= e(date('d M Y', strtotime($selected_day))) ?>.</p>
            </div>
        <?php else: ?>
            <form method="POST" action="<?= e($process_url) ?>" id="attendanceBulkForm">
                <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
                <input type="hidden" name="attendance_date" value="<?= e($selected_day) ?>">
                <div style="display:flex;gap:10px;margin-bottom:15px;flex-wrap:wrap;">
                    <button type="submit" name="bulk_mark_selected_attended" class="btn btn-success" onclick="return ensureSelectedAttendees();"><i class="fas fa-user-check"></i> Mark Selected Attended</button>
                    <button type="submit" name="bulk_mark_all_attended" class="btn btn-warning" onclick="return confirm('Mark all attendees for this day as Attended?');"><i class="fas fa-check-double"></i> Mark All Attended</button>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllAttendance" onclick="toggleAttendanceSelectAll()"></th>
                            <th>#</th><th>Name</th><th>Day</th><th>Contact</th><th>Organization</th><th>Status</th><th>Feedback</th><th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php $count = 1; foreach ($attendance_records as $record): $badge = $status_badges[$record['registration_status']] ?? 'secondary'; ?>
                            <tr>
                                <td><input type="checkbox" name="registration_ids[]" value="<?= (int)$record['registration_id'] ?>" class="attendance-checkbox"></td>
                                <td><?= $count++ ?></td>
                                <td><strong><?= e($record['attendee_name']) ?></strong><?php if (!empty($record['is_beneficiary'])): ?><br><span class="badge badge-info" style="font-size:10px;">Participant</span><?php endif; ?></td>
                                <td><?= e(date('d M Y', strtotime($record['attendance_date'] ?: $selected_day))) ?></td>
                                <td>
                                    <?php if (!empty($record['phone'])): ?><i class="fas fa-phone"></i> <?= e($record['phone']) ?><br><?php endif; ?>
                                    <?php if (!empty($record['email'])): ?><i class="fas fa-envelope"></i> <?= e($record['email']) ?><?php endif; ?>
                                    <?php if (empty($record['phone']) && empty($record['email'])): ?><span style="color:#95a5a6;">-</span><?php endif; ?>
                                </td>
                                <td><?= e($record['organization'] ?? '-') ?></td>
                                <td><span class="badge badge-<?= e($badge) ?>"><?= e($record['registration_status']) ?></span></td>
                                <td>
                                    <?php if (!empty($record['feedback_score'])): ?>
                                        <?= (int)$record['feedback_score'] ?>/5<?php if (!empty($record['feedback_comments'])): ?><br><small><i class="fas fa-comment"></i> Has feedback</small><?php endif; ?>
                                    <?php else: ?><span style="color:#7f8c8d;">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?id=<?= (int)$event_id ?>&day=<?= e($selected_day) ?>&update=<?= (int)$record['registration_id'] ?>" class="btn btn-info btn-sm"><i class="fas fa-edit"></i></a>
                                        <?php if (ims_allowed('event_attendance.delete', ['Administrator', 'Operations/Admin'], 'Delete event attendance records')): ?>
                                            <a href="javascript:void(0)" onclick="confirmDelete(<?= htmlspecialchars(json_encode((string)($record['attendee_name'])), ENT_QUOTES, 'UTF-8') ?>, '<?= e($process_url) ?>&delete=<?= (int)$record['registration_id'] ?>&csrf_token=<?= e(urlencode(csrf_token())) ?>')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div id="addAttendeeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Add Attendee</h3><span class="close" onclick="closeModal('addAttendeeModal')">&times;</span></div>
        <div class="modal-body">
            <form method="POST" action="<?= e($process_url) ?>">
                <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
                <input type="hidden" name="attendance_date" value="<?= e($selected_day) ?>">
                <div class="form-group"><label class="required">Attendance Day</label><input type="date" class="form-control" value="<?= e($selected_day) ?>" disabled></div>
                <div class="form-group"><label class="required">Attendee Name</label><input type="text" name="attendee_name" class="form-control" required></div>
                <div class="form-row"><div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control"></div><div class="form-group"><label>Email</label><input type="email" name="email" class="form-control"></div></div>
                <div class="form-group"><label>Organization</label><input type="text" name="organization" class="form-control"></div>
                <div class="form-group"><label class="required">Status</label><select name="registration_status" class="form-control" required><option value="Registered">Registered</option><option value="Attended">Attended</option><option value="No Show">No Show</option></select></div>
                <input type="hidden" name="beneficiary_id" value="">
                <div class="modal-footer"><button type="button" onclick="closeModal('addAttendeeModal')" class="btn btn-secondary">Cancel</button><button type="submit" name="add_attendee" class="btn btn-success"><i class="fas fa-save"></i> Add Attendee</button></div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($project_beneficiaries)): ?>
<div id="bulkRegisterModal" class="modal">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header"><h3>Bulk Register Project Participants</h3><span class="close" onclick="closeModal('bulkRegisterModal')">&times;</span></div>
        <div class="modal-body">
            <form method="POST" action="<?= e($process_url) ?>">
                <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
                <input type="hidden" name="attendance_date" value="<?= e($selected_day) ?>">
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> Register selected participants for <?= e(date('d M Y', strtotime($selected_day))) ?>.</div>
                <div style="max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:8px;padding:15px;">
                    <label style="display:flex;align-items:center;gap:10px;margin-bottom:15px;"><input type="checkbox" id="selectAllBeneficiaries" onclick="toggleBeneficiarySelectAll()"><strong>Select All (<?= count($project_beneficiaries) ?> participants)</strong></label>
                    <?php foreach ($project_beneficiaries as $beneficiary): ?>
                        <div style="padding:10px;margin-bottom:10px;background:#f8f9fa;border-radius:5px;">
                            <label style="display:flex;gap:10px;cursor:pointer;">
                                <input type="checkbox" name="beneficiaries[]" value="<?= (int)$beneficiary['beneficiary_id'] ?>" class="beneficiary-checkbox">
                                <div><strong><?= e(($beneficiary['first_name'] ?? '') . ' ' . ($beneficiary['last_name'] ?? '')) ?></strong><br><small><?= !empty($beneficiary['phone']) ? '<i class="fas fa-phone"></i> ' . e($beneficiary['phone']) : '' ?><?= !empty($beneficiary['email']) ? ' <i class="fas fa-envelope"></i> ' . e($beneficiary['email']) : '' ?></small></div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer" style="margin-top:20px;"><button type="button" onclick="closeModal('bulkRegisterModal')" class="btn btn-secondary">Cancel</button><button type="submit" name="bulk_register" class="btn btn-success"><i class="fas fa-users"></i> Register Selected</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="uploadCsvModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header"><h3><i class="fas fa-upload"></i> Upload CSV File</h3><span class="close" onclick="closeModal('uploadCsvModal')">&times;</span></div>
        <div class="modal-body">
            <form method="POST" action="<?= e($process_url) ?>" enctype="multipart/form-data">
                <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
                <input type="hidden" name="attendance_date" value="<?= e($selected_day) ?>">
                <div class="form-group"><label class="required">Attendance Day</label><input type="date" class="form-control" value="<?= e($selected_day) ?>" disabled></div>
                <div class="form-group"><label class="required">Select CSV File</label><input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required></div>
                <div class="modal-footer"><button type="button" onclick="closeModal('uploadCsvModal')" class="btn btn-secondary">Cancel</button><button type="submit" name="upload_csv" class="btn btn-success"><i class="fas fa-upload"></i> Upload CSV</button></div>
            </form>
        </div>
    </div>
</div>

<div id="updateStatusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Update Attendance Status</h3><span class="close" onclick="closeModal('updateStatusModal')">&times;</span></div>
        <div class="modal-body">
            <?php if ($update_attendee): ?>
                <form method="POST" action="<?= e($process_url) ?>">
                    <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
                    <input type="hidden" name="registration_id" value="<?= (int)$update_attendee['registration_id'] ?>">
                    <input type="hidden" name="attendance_date" value="<?= e($selected_day) ?>">
                    <div class="info-box" style="margin-bottom:20px;padding:15px;background:#f8f9fa;border-radius:8px;"><h4><?= e($update_attendee['attendee_name']) ?></h4><p><i class="fas fa-calendar-day"></i> <?= e(date('d M Y', strtotime($selected_day))) ?></p></div>
                    <div class="form-group"><label class="required">Attendance Status</label><select name="registration_status" class="form-control" required><?php foreach (['Registered','Attended','No Show'] as $status): ?><option value="<?= e($status) ?>" <?= ($update_attendee['registration_status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Feedback Score</label><select name="feedback_score" class="form-control"><option value="">Not rated</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= (int)($update_attendee['feedback_score'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?>/5</option><?php endfor; ?></select></div>
                    <div class="form-group"><label>Feedback Comments</label><textarea name="feedback_comments" class="form-control" rows="3"><?= e($update_attendee['feedback_comments'] ?? '') ?></textarea></div>
                    <div class="modal-footer"><button type="button" onclick="closeModal('updateStatusModal')" class="btn btn-secondary">Cancel</button><button type="submit" name="update_status" class="btn btn-success"><i class="fas fa-save"></i> Update Status</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleBeneficiarySelectAll() {
    const selectAll = document.getElementById('selectAllBeneficiaries');
    document.querySelectorAll('.beneficiary-checkbox').forEach(cb => cb.checked = selectAll.checked);
}
function toggleAttendanceSelectAll() {
    const selectAll = document.getElementById('selectAllAttendance');
    document.querySelectorAll('.attendance-checkbox').forEach(cb => cb.checked = selectAll.checked);
}
function ensureSelectedAttendees() {
    const selected = document.querySelectorAll('.attendance-checkbox:checked').length;
    if (selected === 0) {
        alert('Please select at least one attendee to mark as attended.');
        return false;
    }
    return confirm('Mark selected attendee(s) as Attended?');
}
<?php if ($update_attendee): ?>
window.addEventListener('DOMContentLoaded', function() { openModal('updateStatusModal'); });
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
