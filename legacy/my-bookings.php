<?php

$page_title = 'My Bookings';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get member details
$member_query = "SELECT * FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    echo "<div class='alert alert-danger'>Member profile not found. Please contact administrator.</div>";
    include 'includes/footer.php';
    exit();
}

$member = $member_result->fetch_assoc();
$member_id = $member['member_id'];


$spaces = [];
$space_result = $conn->query("SELECT * FROM space_availability WHERE is_available = 1 ORDER BY space_type, space_name");
if ($space_result) {
    while ($row = $space_result->fetch_assoc()) {
        $spaces[] = $row;
    }
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $conn->real_escape_string(sanitize_input($_GET['status'])) : '';
$filter_date_from = isset($_GET['date_from']) ? $conn->real_escape_string(sanitize_input($_GET['date_from'])) : '';
$filter_date_to = isset($_GET['date_to']) ? $conn->real_escape_string(sanitize_input($_GET['date_to'])) : '';

// Build WHERE clause
$where = "member_id = $member_id";

if ($filter_status) {
    $where .= " AND booking_status = '$filter_status'";
}

if ($filter_date_from) {
    $where .= " AND booking_date >= '$filter_date_from'";
}

if ($filter_date_to) {
    $where .= " AND booking_date <= '$filter_date_to'";
}

// Fetch bookings
$bookings = [];
$query = "SELECT * FROM space_bookings WHERE $where ORDER BY booking_date DESC, start_time DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}

// Calculate statistics
$stats = [];

$stats['total'] = count($bookings);

$upcoming = $conn->query("SELECT COUNT(*) as count FROM space_bookings 
                         WHERE member_id = $member_id 
                         AND booking_date >= CURDATE() 
                         AND booking_status NOT IN ('Cancelled', 'No Show')")->fetch_assoc()['count'];
$stats['upcoming'] = $upcoming;

$pending = $conn->query("SELECT COUNT(*) as count FROM space_bookings 
                        WHERE member_id = $member_id 
                        AND booking_status = 'Pending'")->fetch_assoc()['count'];
$stats['pending'] = $pending;

$completed = $conn->query("SELECT COUNT(*) as count FROM space_bookings 
                          WHERE member_id = $member_id 
                          AND booking_status = 'Completed'")->fetch_assoc()['count'];
$stats['completed'] = $completed;


$bank_details = [
    'bank_name'      => 'Stanbic Bank Uganda',
    'account_name'   => 'Hive Colab Limited',
    'account_number' => '9030012345678',
    'branch'         => 'Kampala Road Branch',
    'swift_code'     => 'SBICUGKX',
    'currency'       => 'UGX',
];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" integrity="sha384-39yVKLsD9lMelmY+ij49KZgE+Mfk6hjdUPNE8yKHqdMPceLXzhlCJAK81xlD5jDj" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js" integrity="sha384-5/vsv56401Wf+RP3yE5/aIKW4wutk4nLY3HjueTXN0rA+DmweMtrYaN6RSjdv31b" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<style>
/* My bookings: calendar legend, table row accents, booking overlays and
   the payment details panel. */
.mb-filters { margin-bottom: 0; }

.mb-legend {
    display: flex;
    gap: var(--space-4);
    flex-wrap: wrap;
    margin-bottom: var(--space-3);
    font-size: var(--fs-sm);
    color: var(--ink-400);
}

.mb-legend span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.mb-legend .mb-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
}

.mb-dot.is-pending   { background: var(--amber-fg); }
.mb-dot.is-confirmed { background: var(--green-fg); }
.mb-dot.is-completed { background: var(--blue-fg); }
.mb-dot.is-no-show   { background: var(--slate-fg); }

#calendar {
    max-width: 100%;
}

.fc-event {
    cursor: pointer;
}

.mb-table tbody tr.cancelled {
    opacity: 0.6;
}

.mb-table .bt-row-left td:first-child {
    border-left: 4px solid transparent;
}

.mb-table .bt-row-left.pending td:first-child   { border-left-color: var(--amber-fg); }
.mb-table .bt-row-left.confirmed td:first-child { border-left-color: var(--green-fg); }
.mb-table .bt-row-left.cancelled td:first-child { border-left-color: var(--red-fg); }
.mb-table .bt-row-left.completed td:first-child { border-left-color: var(--blue-fg); }

.mb-space-name {
    font-weight: 700;
    color: var(--ink-900);
}

.mb-meta {
    font-size: var(--fs-xs);
    color: var(--ink-300);
    margin-top: 3px;
}

.mb-meta.is-danger {
    color: var(--red-fg);
}

.mb-id {
    font-size: var(--fs-xs);
    color: var(--ink-200);
    margin-top: 4px;
}

.mb-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

/* Self-contained overlays toggled by .mo-open (kept separate from the
   global .modal so the open/close JS stays unchanged). */
.mo-overlay {
    display: none;
    position: fixed !important;
    inset: 0;
    width: 100vw; height: 100vh;
    background: color-mix(in srgb, var(--ink-900) 55%, transparent);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: var(--space-5);
    box-sizing: border-box;
}

.mo-overlay.mo-open {
    display: flex !important;
}

.mo-modal {
    background: var(--surface-card);
    width: 100%;
    max-width: 640px;
    max-height: 90vh;
    overflow-y: auto;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    animation: moFadeIn 0.18s ease-out;
}

.mo-modal.mo-narrow {
    max-width: 480px;
}

@keyframes moFadeIn {
    from { opacity: 0; transform: translateY(-12px); }
    to   { opacity: 1; transform: translateY(0); }
}

.mo-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-5) var(--space-6);
    border-bottom: 1px solid var(--ink-100);
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface-card);
}

.mo-modal-header h3 {
    margin: 0;
    font-size: var(--fs-lg);
    color: var(--ink-900);
}

.mo-close {
    background: none;
    border: none;
    font-size: 26px;
    line-height: 1;
    color: var(--ink-200);
    cursor: pointer;
    padding: 0 4px;
}

.mo-close:hover {
    color: var(--ink-900);
}

.mo-modal-body {
    padding: var(--space-6);
}

.mo-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    padding: var(--space-4) var(--space-6);
    border-top: 1px solid var(--ink-100);
}

.mo-modal-body .mo-modal-footer {
    padding: var(--space-4) 0 0;
}

.mo-space-readonly {
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-md);
    padding: var(--space-2) var(--space-3);
    font-size: var(--fs-sm);
    color: var(--ink-900);
    margin-bottom: var(--space-4);
}

/* Overlap warning: global alert, hidden until JS adds .show. */
.alert.mo-overlap-warning {
    display: none;
}

.alert.mo-overlap-warning.show {
    display: flex;
}

.existing-bookings-panel {
    background: var(--brand-50);
    border: 1px solid var(--brand-100);
    border-radius: var(--radius-md);
    padding: var(--space-3) var(--space-4);
    margin-bottom: var(--space-4);
    font-size: var(--fs-sm);
}

.existing-bookings-panel h5 {
    margin: 0 0 var(--space-2);
    color: var(--brand-600);
    font-size: var(--fs-sm);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.existing-bookings-panel .slot-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    border-bottom: 1px dashed var(--brand-200);
}

.existing-bookings-panel .slot-row:last-child {
    border-bottom: none;
}

.existing-bookings-panel .empty {
    color: var(--ink-300);
    font-style: italic;
}

.mb-space-info {
    background: var(--ink-50);
    padding: var(--space-4);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-5);
}

.mb-space-info:empty {
    display: none;
}

.mb-space-info h4 {
    margin: 0 0 var(--space-2);
}

.mb-space-meta {
    display: flex;
    gap: var(--space-5);
    flex-wrap: wrap;
    font-size: var(--fs-sm);
    color: var(--ink-300);
}

.mb-total {
    background: var(--green-bg);
    border: 1px solid var(--green-border);
    padding: var(--space-5);
    border-radius: var(--radius-md);
    margin: var(--space-5) 0;
}

.mb-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
}

.mb-total-label {
    font-size: var(--fs-md);
    color: var(--ink-900);
}

.mb-total-value {
    font-size: var(--fs-2xl);
    font-weight: 700;
    color: var(--green-fg);
}

.mb-total small {
    display: block;
    margin-top: var(--space-2);
}

.mb-pricing-note {
    margin: var(--space-2) 0 0;
    font-size: var(--fs-sm);
}

/* View Details modal content */
.vw-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: var(--space-4);
    margin-bottom: var(--space-5);
}

.vw-info-item .label {
    font-size: var(--fs-xs);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--ink-300);
    margin-bottom: 4px;
}

.vw-info-item .value {
    font-size: var(--fs-md);
    color: var(--ink-900);
    font-weight: 600;
}

.vw-notes-box {
    background: var(--ink-50);
    border-radius: var(--radius-md);
    padding: var(--space-4);
    font-size: var(--fs-sm);
    color: var(--ink-500);
}

.vw-notes-box p {
    margin: 6px 0;
}

.vw-notes-empty {
    color: var(--ink-300);
    font-style: italic;
}

/* Make Payment modal content */
.pm-amount-display {
    background: var(--green-bg);
    border: 1px solid var(--green-border);
    color: var(--green-fg);
    padding: var(--space-6);
    border-radius: var(--radius-lg);
    text-align: center;
    margin-bottom: var(--space-5);
}

.pm-amount-display .pm-label {
    font-size: var(--fs-sm);
}

.pm-amount-display .pm-amount {
    font-size: 34px;
    font-weight: 700;
    margin: var(--space-2) 0;
}

.pm-bank-box {
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    padding: var(--space-2) var(--space-5);
    border-radius: var(--radius-lg);
}

.pm-bank-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) 0;
    border-bottom: 1px solid var(--ink-100);
}

.pm-bank-item:last-child {
    border-bottom: none;
}

.pm-bank-item .pm-label {
    font-size: var(--fs-sm);
    color: var(--ink-300);
}

.pm-bank-item .pm-value {
    font-size: var(--fs-md);
    font-weight: 700;
    color: var(--ink-900);
    font-variant-numeric: tabular-nums;
    display: flex;
    align-items: center;
    gap: var(--space-2);
    text-align: right;
}

@media (max-width: 480px) {
    #bkOverlay .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-calendar-check" aria-hidden="true"></i> My Bookings</h1>
        <p>View and manage your space bookings.</p>
    </div>
    <div class="hero-actions">
        <a href="book-space" class="btn"><i class="fas fa-door-open" aria-hidden="true"></i> Browse Spaces</a>
        <button type="button" class="btn btn-primary" onclick="openNewBookingModal()">
            <i class="fas fa-plus" aria-hidden="true"></i> New Booking
        </button>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-calendar" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Bookings</span>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Upcoming</span>
            <span class="stat-value"><?php echo $stats['upcoming']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Approval</span>
            <span class="stat-value"><?php echo $stats['pending']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Completed</span>
            <span class="stat-value"><?php echo $stats['completed']; ?></span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card">
<div class="card-body">
<form method="GET" action="">
    <div class="filters-bar mb-filters" role="search" aria-label="Filter bookings">
        <div class="form-group">
            <label for="status">Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Confirmed" <?php echo $filter_status == 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="Completed" <?php echo $filter_status == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="Cancelled" <?php echo $filter_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>

        <div class="form-group">
            <label for="date_from">From Date</label>
            <input type="date" name="date_from" id="date_from" class="form-control"
                   value="<?php echo htmlspecialchars($filter_date_from); ?>">
        </div>

        <div class="form-group">
            <label for="date_to">To Date</label>
            <input type="date" name="date_to" id="date_to" class="form-control"
                   value="<?php echo htmlspecialchars($filter_date_to); ?>">
        </div>

        <div class="filters-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter" aria-hidden="true"></i> Filter
            </button>
            <a href="my-bookings" class="btn btn-secondary">
                <i class="fas fa-times" aria-hidden="true"></i> Clear
            </a>
        </div>
    </div>
</form>
</div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-alt" aria-hidden="true"></i> Booking Calendar</h3>
    </div>
    <div class="card-body">
    <div class="alert alert-info">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <span>
            Click a date to start a booking on that day, or switch to <strong>Week</strong> / <strong>Day</strong> view
            and click-drag across a time range to pick a start and end time automatically.
        </span>
    </div>
    <div class="mb-legend">
        <span><span class="mb-dot is-pending"></span> Pending</span>
        <span><span class="mb-dot is-confirmed"></span> Confirmed</span>
        <span><span class="mb-dot is-completed"></span> Completed</span>
        <span><span class="mb-dot is-no-show"></span> No Show</span>
    </div>
    <div id="calendar"></div>
    </div>
</div>

<!-- Bookings Table -->
<?php if (empty($bookings)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-calendar-times" aria-hidden="true"></i>
                <p>
                    <?php if ($filter_status || $filter_date_from || $filter_date_to): ?>
                        No bookings match your filter criteria.
                    <?php else: ?>
                        You haven't made any bookings yet.
                    <?php endif; ?>
                </p>
                <button type="button" class="btn btn-primary" onclick="openNewBookingModal()">
                    <i class="fas fa-plus" aria-hidden="true"></i> Book a Space
                </button>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list" aria-hidden="true"></i> Bookings</h3>
    </div>
    <div class="card-body">
    <div class="table-responsive">
        <table class="table table-stack mb-table">
            <thead>
                <tr>
                    <th>Space</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Attendees</th>
                    <th>Amount</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking):
                    $card_class = strtolower($booking['booking_status']);
                    $is_past = strtotime($booking['booking_date']) < strtotime('today');

                    $booking_datetime = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
                    $hours_until = ($booking_datetime - time()) / 3600;
                    $can_cancel = in_array($booking['booking_status'], ['Pending', 'Confirmed']) && $hours_until >= 24;

                    // Full-detail editing (via update_booking) only while Pending -
                    // once Confirmed, changes go through cancel + rebook.
                    $can_edit = $booking['booking_status'] === 'Pending' && !$is_past;
                    $can_pay  = $booking['payment_status'] === 'Pending';

                    $status_badges = [
                        'Pending' => 'warning',
                        'Confirmed' => 'success',
                        'Cancelled' => 'danger',
                        'Completed' => 'info',
                        'No Show' => 'secondary'
                    ];
                    $badge = $status_badges[$booking['booking_status']] ?? 'secondary';

                    $payment_badges = [
                        'Paid' => 'success',
                        'Pending' => 'warning',
                        'Partially Paid' => 'info'
                    ];
                    $payment_badge = $payment_badges[$booking['payment_status']] ?? 'secondary';

                    $extra_bits = [];
                    if ($booking['purpose']) $extra_bits[] = $booking['purpose'];
                    if ($booking['setup_required'] && $booking['setup_required'] != 'None') $extra_bits[] = 'Setup: ' . $booking['setup_required'];
                    if ($booking['equipment_needed']) $extra_bits[] = 'Equip: ' . $booking['equipment_needed'];
                    if ($booking['catering_required']) $extra_bits[] = 'Catering requested';

                    // Full row payload, pre-normalised to what <input type="date"> /
                    // type="time"> expect, reused by both the Edit and View modals.
                    $row_payload = $booking;
                    $row_payload['booking_date'] = date('Y-m-d', strtotime($booking['booking_date']));
                    $row_payload['start_time']   = date('H:i', strtotime($booking['start_time']));
                    $row_payload['end_time']     = date('H:i', strtotime($booking['end_time']));
                ?>
                <tr class="bt-row-left <?php echo $card_class; ?> <?php echo $card_class == 'cancelled' ? 'cancelled' : ''; ?>">
                    <td data-label="Space">
                        <div class="mb-space-name"><?php echo htmlspecialchars($booking['space_name']); ?></div>
                        <div class="cell-muted"><?php echo htmlspecialchars($booking['space_type']); ?></div>
                        <?php if (!empty($extra_bits)): ?>
                            <div class="mb-meta"><?php echo htmlspecialchars(implode(' . ', $extra_bits)); ?></div>
                        <?php endif; ?>
                        <?php if ($booking['booking_status'] == 'Cancelled' && $booking['cancellation_reason']): ?>
                            <div class="mb-meta is-danger">
                                Cancelled: <?php echo htmlspecialchars($booking['cancellation_reason']); ?>
                            </div>
                        <?php endif; ?>
                        <div class="mb-id">#<?php echo $booking['booking_id']; ?></div>
                    </td>
                    <td data-label="Date"><?php echo date('d M Y', strtotime($booking['booking_date'])); ?></td>
                    <td data-label="Time">
                        <?php echo date('h:i A', strtotime($booking['start_time'])); ?> -
                        <?php echo date('h:i A', strtotime($booking['end_time'])); ?>
                        <div class="mb-meta"><?php echo $booking['duration_hours']; ?> hrs</div>
                    </td>
                    <td data-label="Attendees"><?php echo $booking['number_of_attendees']; ?></td>
                    <td data-label="Amount"><?php echo format_currency($booking['booking_amount']); ?></td>
                    <td data-label="Payment">
                        <span class="badge badge-<?php echo $payment_badge; ?>"><?php echo $booking['payment_status']; ?></span>
                    </td>
                    <td data-label="Status">
                        <span class="badge badge-<?php echo $badge; ?>"><?php echo $booking['booking_status']; ?></span>
                    </td>
                    <td data-label="Actions">
                        <div class="mb-actions">
                            <button type="button" class="btn btn-info btn-sm" title="View Details"
                                    onclick="openViewModal(<?php echo htmlspecialchars(json_encode($row_payload)); ?>)">
                                <i class="fas fa-eye"></i>
                            </button>

                            <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-warning btn-sm" title="Edit Booking"
                                        onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row_payload)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                            <?php endif; ?>

                            <?php if ($can_cancel): ?>
                                <a href="javascript:void(0)" class="btn btn-danger btn-sm" title="Cancel Booking"
                                   onclick="confirmCancel(<?php echo $booking['booking_id']; ?>, <?= htmlspecialchars(json_encode((string)($booking['space_name'])), ENT_QUOTES, 'UTF-8') ?>, '<?php echo date('d M Y', strtotime($booking['booking_date'])); ?>')">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>

                            <?php if ($can_pay): ?>
                                <button type="button" class="btn btn-success btn-sm" title="Make Payment"
                                        onclick="openPaymentModal(<?php echo htmlspecialchars(json_encode($row_payload)); ?>)">
                                    <i class="fas fa-money-bill"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
    </div>
<?php endif; ?>

</div><!-- /.page-stack -->


<div id="bkOverlay" class="mo-overlay" onclick="if (event.target === this) closeBookingModal();">
    <div class="mo-modal" onclick="event.stopPropagation();">
        <div class="mo-modal-header">
            <h3 id="modalTitle"><i class="fas fa-calendar-plus"></i> Book Space</h3>
            <button type="button" class="mo-close" onclick="closeBookingModal();" aria-label="Close">&times;</button>
        </div>
        <div class="mo-modal-body">
            <form method="POST" action="includes/process-booking.php" id="bookingForm" onsubmit="return handleBookingFormSubmit(event);">
                <input type="hidden" name="action" value="create_booking" id="field_action">
                <input type="hidden" name="member_id" value="<?php echo $member_id; ?>" id="field_member_id">
                <input type="hidden" name="space_id" id="space_id">
                <input type="hidden" name="total_amount" id="total_amount_hidden" value="0">
                <input type="hidden" name="booking_id" id="field_booking_id" disabled>
                <input type="hidden" name="return_to" value="my-bookings">

                <div id="editModeNote" class="alert alert-warning" style="display:none;">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <span>Saving changes will reset this booking to <strong>Pending</strong>
                    and it will need to be re-confirmed.</span>
                </div>

                <div class="form-group" id="spaceSelectGroup">
                    <label for="modalSpaceSelect" class="required">Space</label>
                    <select id="modalSpaceSelect" class="form-control" required>
                        <option value="">Select a space...</option>
                        <?php foreach ($spaces as $space): ?>
                            <option value="<?php echo $space['availability_id']; ?>">
                                <?php echo htmlspecialchars($space['space_name']); ?> (<?php echo htmlspecialchars($space['space_type']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mo-space-readonly" id="spaceReadonlyBox" style="display:none;"></div>

                <div id="selectedSpaceInfo" class="mb-space-info"></div>

                <div class="existing-bookings-panel" id="existingBookingsPanel" style="display:none;">
                    <h5><i class="fas fa-info-circle"></i> Already booked on this date</h5>
                    <div id="existingBookingsList"></div>
                </div>

                <div class="form-group">
                    <label for="booking_date" class="required">Booking Date</label>
                    <input type="date" id="booking_date" name="booking_date" class="form-control"
                           min="<?php echo date('Y-m-d'); ?>" required onchange="loadExistingBookings(); updateBookingAmount();">
                </div>

                <div class="form-group" id="bookingTypeGroup">
                    <label for="booking_type" class="required">Booking Type</label>
                    <select id="booking_type" name="booking_type" class="form-control" required onchange="updateBookingAmount()">
                        <option value="">Select Type</option>
                        <option value="hourly">Hourly</option>
                        <option value="half_day">Half Day (4 hours)</option>
                        <option value="full_day">Full Day (8 hours)</option>
                    </select>
                </div>

                <div class="form-row" id="timeFields">
                    <div class="form-group">
                        <label for="start_time" class="required">Start Time</label>
                        <input type="time" id="start_time" name="start_time" class="form-control" required onchange="updateBookingAmount()">
                    </div>
                    <div class="form-group">
                        <label for="end_time" class="required">End Time</label>
                        <input type="time" id="end_time" name="end_time" class="form-control" required onchange="updateBookingAmount()">
                    </div>
                </div>

                <div class="alert alert-danger mo-overlap-warning" id="overlapWarning">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    <span>This time overlaps with an existing booking for this space. Please choose a different time.</span>
                </div>

                <div id="editableDetailFields">
                    <div class="form-group">
                        <label for="number_of_attendees">Number of Attendees</label>
                        <input type="number" id="number_of_attendees" name="number_of_attendees" class="form-control" min="1">
                    </div>

                    <div class="form-group">
                        <label for="purpose" class="required">Purpose of Booking</label>
                        <textarea id="purpose" name="purpose" class="form-control" rows="2" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="setup_required">Setup Required</label>
                        <select id="setup_required" name="setup_required" class="form-control">
                            <option value="None">None</option>
                            <option value="Theater">Theater Style</option>
                            <option value="Classroom">Classroom Style</option>
                            <option value="U-Shape">U-Shape</option>
                            <option value="Boardroom">Boardroom</option>
                            <option value="Cocktail">Cocktail/Standing</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="equipment_needed">Equipment Needed</label>
                        <input type="text" id="equipment_needed" name="equipment_needed" class="form-control"
                               placeholder="e.g., Projector, Microphones, Flip charts">
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="catering_required" id="catering_required" value="1" onchange="toggleCateringDetails()">
                            <strong>Catering Required</strong>
                        </label>
                    </div>

                    <div class="form-group" id="cateringDetails" style="display:none;">
                        <label for="catering_description">Catering Details</label>
                        <textarea id="catering_description" name="catering_description" class="form-control" rows="2"
                                  placeholder="Number of people, meal preferences, dietary restrictions..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="special_requests">Special Requests</label>
                        <textarea id="special_requests" name="special_requests" class="form-control" rows="2"></textarea>
                    </div>
                </div>

                <div id="pricingDisplayBox" class="mb-total">
                    <div class="mb-total-row">
                        <div><strong class="mb-total-label">Total Amount:</strong></div>
                        <div>
                            <span class="mb-total-value" id="totalAmount">UGX 0</span>
                        </div>
                    </div>
                    <div id="pricingNote" class="alert alert-success mb-pricing-note" style="display:none;"></div>
                    <small class="cell-muted">
                        <i class="fas fa-info-circle"></i> Payment can be made via Mobile Money, Bank Transfer, or at the Hub
                    </small>
                </div>

                <div class="mo-modal-footer">
                    <button type="button" onclick="closeBookingModal();" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success" id="confirmBookingBtn">
                        <i class="fas fa-check"></i> Confirm Booking
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<div id="vwOverlay" class="mo-overlay" onclick="if (event.target === this) closeViewModal();">
    <div class="mo-modal" onclick="event.stopPropagation();">
        <div class="mo-modal-header">
            <h3><i class="fas fa-eye"></i> Booking Details</h3>
            <button type="button" class="mo-close" onclick="closeViewModal();" aria-label="Close">&times;</button>
        </div>
        <div class="mo-modal-body">
            <div class="vw-info-grid" id="vwInfoGrid"></div>
            <div class="vw-notes-box" id="vwNotesBox"></div>
        </div>
        <div class="mo-modal-footer">
            <button type="button" onclick="closeViewModal();" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<div id="pmOverlay" class="mo-overlay" onclick="if (event.target === this) closePaymentModal();">
    <div class="mo-modal mo-narrow" onclick="event.stopPropagation();">
        <div class="mo-modal-header">
            <h3><i class="fas fa-credit-card"></i> Make Payment</h3>
            <button type="button" class="mo-close" onclick="closePaymentModal();" aria-label="Close">&times;</button>
        </div>
        <div class="mo-modal-body">
            <div class="pm-amount-display">
                <div class="pm-label">Amount to Pay</div>
                <div class="pm-amount" id="pmAmount">UGX 0</div>
                <div class="pm-label">Reference: <span id="pmReference"></span></div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
                <span>Use the reference above as your payment note, then contact the Hub to confirm - your booking
                will be marked <strong>Paid</strong> once verified.</span>
            </div>

            <div class="pm-bank-box">
                <div class="pm-bank-item">
                    <span class="pm-label">Bank Name</span>
                    <span class="pm-value">
                        <?php echo htmlspecialchars($bank_details['bank_name']); ?>
                        <button type="button" class="btn btn-secondary btn-sm pm-copy-btn" onclick="copyToClipboard(<?= htmlspecialchars(json_encode((string)($bank_details['bank_name'])), ENT_QUOTES, 'UTF-8') ?>, this)"><i class="fas fa-copy"></i></button>
                    </span>
                </div>
                <div class="pm-bank-item">
                    <span class="pm-label">Account Name</span>
                    <span class="pm-value">
                        <?php echo htmlspecialchars($bank_details['account_name']); ?>
                        <button type="button" class="btn btn-secondary btn-sm pm-copy-btn" onclick="copyToClipboard(<?= htmlspecialchars(json_encode((string)($bank_details['account_name'])), ENT_QUOTES, 'UTF-8') ?>, this)"><i class="fas fa-copy"></i></button>
                    </span>
                </div>
                <div class="pm-bank-item">
                    <span class="pm-label">Account Number</span>
                    <span class="pm-value">
                        <?php echo htmlspecialchars($bank_details['account_number']); ?>
                        <button type="button" class="btn btn-secondary btn-sm pm-copy-btn" onclick="copyToClipboard(<?= htmlspecialchars(json_encode((string)($bank_details['account_number'])), ENT_QUOTES, 'UTF-8') ?>, this)"><i class="fas fa-copy"></i></button>
                    </span>
                </div>
                <div class="pm-bank-item">
                    <span class="pm-label">Branch</span>
                    <span class="pm-value"><?php echo htmlspecialchars($bank_details['branch']); ?></span>
                </div>
                <div class="pm-bank-item">
                    <span class="pm-label">SWIFT Code</span>
                    <span class="pm-value">
                        <?php echo htmlspecialchars($bank_details['swift_code']); ?>
                        <button type="button" class="btn btn-secondary btn-sm pm-copy-btn" onclick="copyToClipboard(<?= htmlspecialchars(json_encode((string)($bank_details['swift_code'])), ENT_QUOTES, 'UTF-8') ?>, this)"><i class="fas fa-copy"></i></button>
                    </span>
                </div>
                <div class="pm-bank-item">
                    <span class="pm-label">Currency</span>
                    <span class="pm-value"><?php echo htmlspecialchars($bank_details['currency']); ?></span>
                </div>
            </div>
        </div>
        <div class="mo-modal-footer">
            <button type="button" onclick="closePaymentModal();" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>


<div id="evOverlay" class="mo-overlay" onclick="if (event.target === this) closeEventDetailsModal();">
    <div class="mo-modal mo-narrow" onclick="event.stopPropagation();">
        <div class="mo-modal-header">
            <h3><i class="fas fa-calendar-day"></i> Calendar Event</h3>
            <button type="button" class="mo-close" onclick="closeEventDetailsModal();" aria-label="Close">&times;</button>
        </div>
        <div class="mo-modal-body">
            <div class="vw-info-grid" id="evInfoGrid"></div>
        </div>
        <div class="mo-modal-footer">
            <button type="button" onclick="closeEventDetailsModal();" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>

const SPACES = <?php echo json_encode($spaces); ?>;
const SPACES_BY_ID = {};
SPACES.forEach(s => { SPACES_BY_ID[s.availability_id] = s; });

let selectedSpace = null;
let spaceNameForOverlapCheck = null;
let currentMode = 'create'; // 'create' | 'edit'
let editingBookingId = null;
let existingBookingsForSpace = [];

/* =====================  CALENDAR  ===================== */
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        selectable: true,
        selectMirror: true,
        // Colour events from the design tokens so they match the legend.
        eventDataTransform: function(ev) {
            const token = {
                'Pending': '--amber-fg',
                'Confirmed': '--green-fg',
                'Completed': '--blue-fg',
                'No Show': '--slate-fg'
            }[ev.extendedProps && ev.extendedProps.status] || '--red-fg';
            const color = getComputedStyle(document.documentElement).getPropertyValue(token).trim();
            if (color) {
                ev.color = color;
            }
            return ev;
        },
        events: function(info, successCallback, failureCallback) {
            fetch('get-bookings?start=' + info.startStr + '&end=' + info.endStr)
                .then(response => response.json())
                .then(data => successCallback(data))
                .catch(error => failureCallback(error));
        },
        eventClick: function(info) {
            showEventDetailsModal(info.event);
        },
        dateClick: function(info) {
            openBookingModalForSlot(info.dateStr, null);
        },
        select: function(info) {
            openBookingModalForSlot(info.startStr, info.endStr);
            calendar.unselect();
        }
    });
    calendar.render();
});

/* =====================  BOOK / EDIT MODAL  ===================== */

function openNewBookingModal() {
    openBookingModalForSlot(new Date().toISOString().slice(0, 10), null);
}

function openBookingModalForSlot(startStr, endStr) {
    resetModalFields();
    setFormMode('create');

    const [datePart, startTimePart] = startStr.split('T');
    document.getElementById('booking_date').value = datePart;

    if (startTimePart) {
        document.getElementById('booking_type').value = 'hourly';
        document.getElementById('start_time').value = startTimePart.slice(0, 5);
        if (endStr && endStr.includes('T')) {
            document.getElementById('end_time').value = endStr.split('T')[1].slice(0, 5);
        }
    }

    document.getElementById('modalSpaceSelect').value = '';
    document.getElementById('modalSpaceSelect').disabled = false;
    selectedSpace = null;
    spaceNameForOverlapCheck = null;

    openBookingOverlay();
    updateBookingAmount();
}

function openEditModal(booking) {
    resetModalFields();
    setFormMode('edit');

    editingBookingId = booking.booking_id;
    spaceNameForOverlapCheck = booking.space_name;
    selectedSpace = null;

    document.getElementById('field_booking_id').value = booking.booking_id;

    document.getElementById('spaceReadonlyBox').style.display = 'block';
    document.getElementById('spaceReadonlyBox').innerHTML =
        '<i class="fas fa-map-marker-alt"></i> <strong>' + booking.space_name + '</strong> (' + booking.space_type + ') - space cannot be changed when editing';

    document.getElementById('booking_date').value = booking.booking_date;
    document.getElementById('start_time').value = booking.start_time;
    document.getElementById('end_time').value = booking.end_time;

    document.getElementById('number_of_attendees').value = booking.number_of_attendees || '';
    document.getElementById('purpose').value = booking.purpose || '';
    document.getElementById('setup_required').value = booking.setup_required || 'None';
    document.getElementById('equipment_needed').value = booking.equipment_needed || '';
    document.getElementById('special_requests').value = booking.special_requests || '';

    const cateringChecked = !!parseInt(booking.catering_required, 10);
    document.getElementById('catering_required').checked = cateringChecked;
    document.getElementById('catering_description').value = booking.catering_description || '';
    document.getElementById('cateringDetails').style.display = cateringChecked ? 'block' : 'none';

    openBookingOverlay();
    loadExistingBookings();
    updateBookingAmount();
}

function openBookingOverlay() {
    document.getElementById('bkOverlay').classList.add('mo-open');
    document.body.style.overflow = 'hidden';
}

function closeBookingModal() {
    document.getElementById('bkOverlay').classList.remove('mo-open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBookingModal();
        closeViewModal();
        closePaymentModal();
        closeEventDetailsModal();
    }
});

// Status -> global .status-pill modifier for the event details modal.
const EVENT_STATUS_PILLS = {
    'Pending':   'status-pending',
    'Confirmed': 'status-approved',
    'Completed': 'status-completed',
    'No Show':   'status-draft',
    'Cancelled': 'status-cancelled',
};


function showEventDetailsModal(event) {
    const props = event.extendedProps;
    const dateStr = new Date(props.date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' });
    const timeStr = formatTime(props.start_time) + ' - ' + formatTime(props.end_time);
    const statusPill = EVENT_STATUS_PILLS[props.status] || 'status-draft';

    const items = [
        ['Space', props.space_name],
        ['Type', props.space_type],
        ['Date', dateStr],
        ['Time', timeStr],
        ['Status', `<span class="status-pill ${statusPill}">${props.status}</span>`],
    ];

    document.getElementById('evInfoGrid').innerHTML = items.map(([label, value]) => `
        <div class="vw-info-item">
            <div class="label">${label}</div>
            <div class="value">${value}</div>
        </div>
    `).join('');

    document.getElementById('evOverlay').classList.add('mo-open');
    document.body.style.overflow = 'hidden';
}

function closeEventDetailsModal() {
    document.getElementById('evOverlay').classList.remove('mo-open');
    document.body.style.overflow = '';
}

function setFormMode(mode) {
    currentMode = mode;
    const isEdit = mode === 'edit';

    document.getElementById('field_action').value = isEdit ? 'update_booking' : 'create_booking';
    document.getElementById('field_member_id').disabled = isEdit;
    document.getElementById('space_id').disabled = isEdit;
    document.getElementById('total_amount_hidden').disabled = isEdit;
    document.getElementById('field_booking_id').disabled = !isEdit;

    document.getElementById('editModeNote').style.display = isEdit ? 'block' : 'none';

    document.getElementById('spaceSelectGroup').style.display = isEdit ? 'none' : 'block';
    document.getElementById('modalSpaceSelect').required = !isEdit;
    document.getElementById('spaceReadonlyBox').style.display = isEdit ? 'block' : 'none';

    document.getElementById('bookingTypeGroup').style.display = isEdit ? 'none' : 'block';
    document.getElementById('booking_type').required = !isEdit;
    document.getElementById('booking_type').disabled = isEdit;

 
    document.getElementById('modalTitle').innerHTML = isEdit
        ? '<i class="fas fa-edit"></i> Edit Booking'
        : '<i class="fas fa-calendar-plus"></i> Book Space';

    document.getElementById('confirmBookingBtn').innerHTML = isEdit
        ? '<i class="fas fa-check"></i> Save Changes'
        : '<i class="fas fa-check"></i> Confirm Booking';
}

function resetModalFields() {
    document.getElementById('bookingForm').reset();
    document.getElementById('overlapWarning').classList.remove('show');
    document.getElementById('existingBookingsPanel').style.display = 'none';
    document.getElementById('selectedSpaceInfo').innerHTML = '';
    document.getElementById('spaceReadonlyBox').innerHTML = '';
    document.getElementById('cateringDetails').style.display = 'none';
    document.getElementById('field_booking_id').value = '';
    document.getElementById('space_id').value = '';
    document.getElementById('confirmBookingBtn').disabled = false;
   
    document.getElementById('number_of_attendees').removeAttribute('max');
    editingBookingId = null;
    existingBookingsForSpace = [];
}

function renderSelectedSpaceInfo(space) {
    document.getElementById('selectedSpaceInfo').innerHTML = `
        <h4>${space.space_name}</h4>
        <div class="mb-space-meta">
            <span><i class="fas fa-tag"></i> ${space.space_type}</span>
            <span><i class="fas fa-users"></i> Capacity: ${space.capacity}</span>
        </div>
    `;
}

document.getElementById('modalSpaceSelect').addEventListener('change', function() {
    const id = this.value;
    if (!id || !SPACES_BY_ID[id]) {
        selectedSpace = null;
        spaceNameForOverlapCheck = null;
        document.getElementById('space_id').value = '';
        document.getElementById('selectedSpaceInfo').innerHTML = '';
        return;
    }

    selectedSpace = SPACES_BY_ID[id];
    spaceNameForOverlapCheck = selectedSpace.space_name;
    document.getElementById('space_id').value = id;
    document.getElementById('number_of_attendees').max = selectedSpace.capacity;
    renderSelectedSpaceInfo(selectedSpace);

    loadExistingBookings();
    updateBookingAmount();
});

function loadExistingBookings() {
    const date = document.getElementById('booking_date').value;
    if (!date || !spaceNameForOverlapCheck) return;

    const panel = document.getElementById('existingBookingsPanel');
    const list = document.getElementById('existingBookingsList');

    fetch('get-bookings?start=' + date + '&end=' + date + '&space_name=' + encodeURIComponent(spaceNameForOverlapCheck))
        .then(response => response.json())
        .then(events => {
            existingBookingsForSpace = events
                .filter(ev => !(currentMode === 'edit' && String(ev.id) === String(editingBookingId)))
                .map(ev => ({
                    start_time: ev.extendedProps.start_time,
                    end_time: ev.extendedProps.end_time,
                    status: ev.extendedProps.status
                }));

            if (existingBookingsForSpace.length === 0) {
                list.innerHTML = '<div class="empty">No other bookings for this space on this date - fully open.</div>';
            } else {
                list.innerHTML = existingBookingsForSpace.map(b => `
                    <div class="slot-row">
                        <span>${formatTime(b.start_time)} - ${formatTime(b.end_time)}</span>
                        <span>${b.status}</span>
                    </div>
                `).join('');
            }

            panel.style.display = 'block';
            validateNoOverlap();
        })
        .catch(() => {
            list.innerHTML = '<div class="empty">Could not load existing bookings - please double-check availability before confirming.</div>';
            panel.style.display = 'block';
        });
}

function formatTime(t) {
    const [h, m] = t.split(':');
    const d = new Date();
    d.setHours(parseInt(h, 10), parseInt(m, 10), 0);
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function hasOverlap() {
    const start = document.getElementById('start_time').value;
    const end = document.getElementById('end_time').value;
    if (!start || !end) return false;

    return existingBookingsForSpace.some(b => {
        const bStart = b.start_time.slice(0, 5);
        const bEnd = b.end_time.slice(0, 5);
        return (start < bEnd) && (end > bStart);
    });
}

function validateNoOverlap() {
    const warning = document.getElementById('overlapWarning');
    const submitBtn = document.getElementById('confirmBookingBtn');

    if (currentMode === 'create' && !selectedSpace) return true;

    if (hasOverlap()) {
        warning.classList.add('show');
        submitBtn.disabled = true;
        return false;
    } else {
        warning.classList.remove('show');
        submitBtn.disabled = false;
        return true;
    }
}


function handleBookingFormSubmit(e) {
    document.getElementById('field_action').value = currentMode === 'edit' ? 'update_booking' : 'create_booking';

    const form = document.getElementById('bookingForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        e.preventDefault();
        return false;
    }

    return validateNoOverlap();
}


function updateBookingAmount() {
    const date = document.getElementById('booking_date').value;
    const bookingType = document.getElementById('booking_type').value || 'hourly';
    const startTime = document.getElementById('start_time').value;
    const endTime = document.getElementById('end_time').value;

    const params = new URLSearchParams();

    if (currentMode === 'create') {
        if (!selectedSpace || !date) {
            resetAmountDisplay();
            return;
        }
        params.set('space_id', selectedSpace.availability_id);
    } else {
        if (!spaceNameForOverlapCheck || !date) {
            resetAmountDisplay();
            return;
        }
        params.set('space_name', spaceNameForOverlapCheck);
        if (editingBookingId) {
            params.set('exclude_booking_id', editingBookingId);
        }
    }

    params.set('booking_date', date);
    params.set('booking_type', bookingType);
    params.set('start_time', startTime);
    params.set('end_time', endTime);

    fetch('get-quote?' + params.toString())
        .then(response => response.json().catch(() => {
            throw new Error('Server did not return valid JSON (HTTP ' + response.status + ') - check the Network tab for the raw response from get-quote.php.');
        }))
        .then(data => {
            if (data.error) {
                console.error('get-quote.php returned an error:', data.error);
                resetAmountDisplay('Could not calculate price: ' + data.error);
                return;
            }
            if (!data.duration_hours) {
                resetAmountDisplay();
                return;
            }
            renderQuote(data);
        })
        .catch(err => {
            console.error('Failed to fetch booking quote:', err);
            resetAmountDisplay('Could not calculate price - see browser console for details.');
        });

    validateNoOverlap();
}

function resetAmountDisplay(message) {
    document.getElementById('totalAmount').textContent = 'UGX 0';
    document.getElementById('total_amount_hidden').value = 0;
    const note = document.getElementById('pricingNote');
    if (message) {
        note.style.display = 'block';
        note.className = 'alert alert-danger mb-pricing-note';
        note.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + message;
    } else {
        note.style.display = 'none';
    }
}

function renderQuote(data) {
    const roundedAmount = Math.round(data.amount);
    document.getElementById('totalAmount').textContent = 'UGX ' + roundedAmount.toLocaleString();
    document.getElementById('total_amount_hidden').value = data.amount;

    const note = document.getElementById('pricingNote');
    note.className = 'alert alert-success mb-pricing-note';
    if (data.special_offer) {
        note.style.display = 'block';
        note.innerHTML = '<i class="fas fa-gift"></i> ' + data.free_hours_applied + ' free hour(s) applied'
            + (data.member_type ? ' (' + data.member_type + ' member)' : '')
            + ' - ' + data.billable_hours + ' hour(s) billed at UGX 20,000/hr.';
    } else {
        note.style.display = 'none';
    }
}


function toggleCateringDetails() {
    const checked = document.getElementById('catering_required').checked;
    document.getElementById('cateringDetails').style.display = checked ? 'block' : 'none';
}

document.getElementById('booking_type').addEventListener('change', function() {
    if (currentMode !== 'create') return;

    const type = this.value;
    const startTime = document.getElementById('start_time').value;

    if (startTime) {
        const start = new Date('2000-01-01 ' + startTime);
        let hours = 0;
        if (type === 'half_day') hours = 4;
        else if (type === 'full_day') hours = 8;

        if (hours > 0) {
            start.setHours(start.getHours() + hours);
            document.getElementById('end_time').value = start.toTimeString().slice(0, 5);
            updateBookingAmount();
        }
    }
});



function openViewModal(booking) {
    const grid = document.getElementById('vwInfoGrid');
    const dateStr = new Date(booking.booking_date + 'T00:00:00').toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
    const timeStr = formatTime(booking.start_time + ':00') + ' - ' + formatTime(booking.end_time + ':00');

    const items = [
        ['Space', booking.space_name],
        ['Type', booking.space_type],
        ['Date', dateStr],
        ['Time', timeStr],
        ['Duration', booking.duration_hours + ' hrs'],
        ['Attendees', booking.number_of_attendees],
        ['Amount', 'UGX ' + Number(booking.booking_amount).toLocaleString()],
        ['Payment', booking.payment_status],
        ['Status', booking.booking_status],
        ['Booking ID', '#' + booking.booking_id],
    ];

    grid.innerHTML = items.map(([label, value]) => `
        <div class="vw-info-item">
            <div class="label">${label}</div>
            <div class="value">${value !== null && value !== '' ? value : '-'}</div>
        </div>
    `).join('');

    let notesHtml = '';
    if (booking.purpose) notesHtml += `<p><strong>Purpose:</strong> ${booking.purpose}</p>`;
    if (booking.setup_required && booking.setup_required !== 'None') notesHtml += `<p><strong>Setup:</strong> ${booking.setup_required}</p>`;
    if (booking.equipment_needed) notesHtml += `<p><strong>Equipment:</strong> ${booking.equipment_needed}</p>`;
    if (parseInt(booking.catering_required, 10)) notesHtml += `<p><strong>Catering:</strong> ${booking.catering_description || 'Required'}</p>`;
    if (booking.special_requests) notesHtml += `<p><strong>Special Requests:</strong> ${booking.special_requests}</p>`;
    if (booking.booking_status === 'Cancelled' && booking.cancellation_reason) notesHtml += `<p><strong>Cancellation Reason:</strong> ${booking.cancellation_reason}</p>`;

    document.getElementById('vwNotesBox').innerHTML = notesHtml || '<p class="vw-notes-empty">No additional notes.</p>';

    document.getElementById('vwOverlay').classList.add('mo-open');
    document.body.style.overflow = 'hidden';
}

function closeViewModal() {
    document.getElementById('vwOverlay').classList.remove('mo-open');
    document.body.style.overflow = '';
}



function openPaymentModal(booking) {
    document.getElementById('pmAmount').textContent = 'UGX ' + Number(booking.booking_amount).toLocaleString();
    document.getElementById('pmReference').textContent = 'BKG-' + String(booking.booking_id).padStart(6, '0');

    document.getElementById('pmOverlay').classList.add('mo-open');
    document.body.style.overflow = 'hidden';
}

function closePaymentModal() {
    document.getElementById('pmOverlay').classList.remove('mo-open');
    document.body.style.overflow = '';
}

function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(function() {
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(function() { button.innerHTML = originalHTML; }, 2000);
    }).catch(function(err) {
        alert('Failed to copy: ' + err);
    });
}


function confirmCancel(bookingId, spaceName, bookingDate) {
    if (confirm(`Are you sure you want to cancel your booking for ${spaceName} on ${bookingDate}?\n\nNote: Cancellations must be made at least 24 hours in advance.`)) {
        window.location.href = `includes/process-booking.php?cancel_booking=${encodeURIComponent(bookingId)}&csrf_token=<?= h(csrf_token()) ?>`;
    }
}
</script>
