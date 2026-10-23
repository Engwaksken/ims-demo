<?php
$page_title = 'Book a Space';
require_once 'includes/header.php';

// Must be logged in member
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to book a space.";
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get member details
$member = $conn->query("SELECT * FROM members WHERE user_id = " . (int)$user_id)->fetch_assoc();
if (!$member) {
    $_SESSION['error'] = "Member profile not found.";
    header("Location: member-dashboard");
    exit();
}

$member_id = $member['member_id'];

// Get all available spaces
$spaces = [];
$query = "SELECT * FROM space_availability WHERE is_available = 1 ORDER BY space_type, space_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $spaces[] = $row;
}

// Get selected date (default today)
$selected_date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
// Validate format: value is interpolated into SQL and echoed into the page.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) || strtotime($selected_date) === false) {
    $selected_date = date('Y-m-d');
}

// Get bookings for selected date (used for the "Booked Today" reference list)
$bookings_today = [];
$query = "SELECT * FROM space_bookings 
          WHERE booking_date = '$selected_date' 
          AND booking_status != 'Cancelled'
          ORDER BY start_time ASC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $bookings_today[] = $row;
}

// This member's own PENDING bookings - fully editable inline on this page
// (date, time, attendees, purpose, setup, equipment, catering, special
// requests) via the update_booking action in includes/process-booking.php.
// Only Pending bookings are editable here - a Confirmed booking should go
// through cancel + rebook rather than being silently altered from this page.
//
// Date/time fields are normalised to exactly what <input type="date"> /
// type="time"> expect (YYYY-MM-DD / HH:MM) here in PHP, before they're ever
// JSON-encoded for JS - raw DB strings with trailing " 00:00:00" or seconds
// are what silently break prefill on input fields (see my-bookings.php fix).
$my_pending_bookings = [];
$query = "SELECT * FROM space_bookings 
          WHERE member_id = $member_id 
          AND booking_status = 'Pending'
          ORDER BY booking_date ASC, start_time ASC";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['booking_date'] = date('Y-m-d', strtotime($row['booking_date']));
        $row['start_time']   = date('H:i', strtotime($row['start_time']));
        $row['end_time']     = date('H:i', strtotime($row['end_time']));
        $my_pending_bookings[] = $row;
    }
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" integrity="sha384-39yVKLsD9lMelmY+ij49KZgE+Mfk6hjdUPNE8yKHqdMPceLXzhlCJAK81xlD5jDj" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js" integrity="sha384-5/vsv56401Wf+RP3yE5/aIKW4wutk4nLY3HjueTXN0rA+DmweMtrYaN6RSjdv31b" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<style>
/* Book a space: space cards, calendar legend, pending-booking rows and
   the booking overlay. */
.bs-space-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-5);
}

.bs-space {
    display: flex;
    flex-direction: column;
    padding: var(--space-5);
    background: var(--surface-card);
    border: 1px solid var(--ink-100);
    border-left: 5px solid var(--brand-500);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s, box-shadow 0.2s;
}

.bs-space:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.bs-space-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
}

.bs-space-head h3 {
    margin: 0;
    font-size: var(--fs-lg);
    color: var(--ink-900);
}

.bs-detail-row {
    display: flex;
    justify-content: space-between;
    padding: var(--space-2) 0;
    border-bottom: 1px solid var(--ink-100);
    font-size: var(--fs-sm);
}

.bs-amenities {
    margin: var(--space-3) 0 0;
    font-size: var(--fs-sm);
    color: var(--ink-300);
}

.bs-amenities i {
    color: var(--green-fg);
    margin-right: 5px;
}

.bs-book-btn {
    width: 100%;
    margin-top: auto;
}

.bs-space-details {
    margin: var(--space-3) 0 var(--space-4);
}

.bs-legend {
    display: flex;
    gap: var(--space-4);
    flex-wrap: wrap;
    margin-bottom: var(--space-3);
    font-size: var(--fs-sm);
    color: var(--ink-400);
}

.bs-legend span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.bs-legend .bs-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
}

.bs-dot.is-pending   { background: var(--amber-fg); }
.bs-dot.is-confirmed { background: var(--green-fg); }
.bs-dot.is-completed { background: var(--blue-fg); }
.bs-dot.is-no-show   { background: var(--slate-fg); }

#calendar {
    max-width: 100%;
}

.fc-event {
    cursor: pointer;
}

.bs-pending {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
    padding: var(--space-4) var(--space-5);
    margin-bottom: var(--space-3);
    border: 1px solid var(--ink-100);
    border-left: 5px solid var(--amber-fg);
    border-radius: var(--radius-md);
    background: var(--surface-card);
}

.bs-pending h4 {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin: 0 0 4px;
    font-size: var(--fs-md);
    color: var(--ink-900);
}

.bs-pending-note {
    margin: var(--space-2) 0 0;
}

/* Existing bookings on the chosen date (inside the booking overlay). */
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

/* Overlap warning: global alert, hidden until JS adds .show. */
.alert.overlap-warning {
    display: none;
}

.alert.overlap-warning.show {
    display: flex;
}

/* Read-only "space" display used in edit mode, where the space itself
   can't be changed (only its other details can). */
.space-readonly-box {
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-md);
    padding: var(--space-2) var(--space-3);
    font-size: var(--fs-sm);
    color: var(--ink-900);
    margin-bottom: 4px;
}

.bs-space-info {
    background: var(--ink-50);
    padding: var(--space-4);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-5);
}

.bs-space-info:empty {
    display: none;
}

.bs-space-info h4 {
    margin: 0 0 var(--space-2);
}

.bs-space-meta {
    display: flex;
    gap: var(--space-5);
    flex-wrap: wrap;
    font-size: var(--fs-sm);
    color: var(--ink-300);
}

.bs-total {
    background: var(--green-bg);
    border: 1px solid var(--green-border);
    padding: var(--space-5);
    border-radius: var(--radius-md);
    margin: var(--space-5) 0;
}

.bs-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
}

.bs-total-label {
    font-size: var(--fs-md);
    color: var(--ink-900);
}

.bs-total-value {
    font-size: var(--fs-2xl);
    font-weight: 700;
    color: var(--green-fg);
}

.bs-total small {
    display: block;
    margin-top: var(--space-2);
}

/* ---------------------------------------------------------------
   BOOKING MODAL - self-contained overlay toggled by .bk-open.
   Scoped with #bkOverlay / .bk-* so this can't be knocked off-center
   or left undimmed by a clashing global ".modal" rule elsewhere in
   the app.
   --------------------------------------------------------------- */
#bkOverlay {
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

#bkOverlay.bk-open {
    display: flex !important;
}

#bkOverlay .bk-modal {
    background: var(--surface-card);
    width: 100%;
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    animation: bkFadeIn 0.18s ease-out;
}

@keyframes bkFadeIn {
    from { opacity: 0; transform: translateY(-12px); }
    to   { opacity: 1; transform: translateY(0); }
}

#bkOverlay .bk-modal-header {
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

#bkOverlay .bk-modal-header h3 {
    margin: 0;
    font-size: var(--fs-lg);
    color: var(--ink-900);
}

#bkOverlay .bk-close {
    background: none;
    border: none;
    font-size: 26px;
    line-height: 1;
    color: var(--ink-200);
    cursor: pointer;
    padding: 0 4px;
}

#bkOverlay .bk-close:hover {
    color: var(--ink-900);
}

#bkOverlay .bk-modal-body {
    padding: var(--space-6);
}

#bkOverlay .bk-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    padding-top: var(--space-4);
    border-top: 1px solid var(--ink-100);
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-door-open" aria-hidden="true"></i> Book a Space</h1>
        <p>Select a space and choose your preferred date and time.</p>
    </div>
    <div class="hero-actions">
        <a href="my-bookings" class="btn"><i class="fas fa-list" aria-hidden="true"></i> My Bookings</a>
    </div>
</div>

<!-- Your Pending Bookings - full inline editing without leaving this page -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-hourglass-half" aria-hidden="true"></i> Your Pending Bookings</h3>
    </div>
    <div class="card-body">
    <?php if (empty($my_pending_bookings)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-check" aria-hidden="true"></i>
            <p>You have no bookings awaiting confirmation right now.</p>
        </div>
    <?php else: ?>
        <?php foreach ($my_pending_bookings as $pb): ?>
        <div class="bs-pending">
            <div>
                <h4>
                    <?php echo htmlspecialchars($pb['space_name']); ?>
                    <span class="status-pill status-pending">Pending</span>
                </h4>
                <div class="cell-muted">
                    <i class="fas fa-calendar" aria-hidden="true"></i> <?php echo date('d M Y', strtotime($pb['booking_date'])); ?>
                    &nbsp;&middot;&nbsp;
                    <i class="fas fa-clock" aria-hidden="true"></i> <?php echo date('h:i A', strtotime($pb['start_time'])); ?> - <?php echo date('h:i A', strtotime($pb['end_time'])); ?>
                    <?php if ($pb['purpose']): ?>
                        &nbsp;&middot;&nbsp; <?php echo htmlspecialchars($pb['purpose']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <button type="button" class="btn btn-warning btn-sm"
                        onclick="openEditModal(<?php echo htmlspecialchars(json_encode($pb)); ?>)">
                    <i class="fas fa-edit" aria-hidden="true"></i> Edit Booking
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <p class="cell-muted bs-pending-note">
            Space and pricing can't be changed once a booking exists. To change space, cancel it from
            <a href="my-bookings">My Bookings</a> and create a new one.
        </p>
    <?php endif; ?>
    </div>
</div>

<!-- Calendar View -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-alt" aria-hidden="true"></i> Booking Calendar</h3>
    </div>
    <div class="card-body">
    <div class="alert alert-info">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <span>
            Click a date to start a booking on that day, or switch to <strong>Week</strong> / <strong>Day</strong> view
            and click-drag across a time range to pick a start and end time automatically - covering one slot or several.
        </span>
    </div>
    <div class="bs-legend">
        <span><span class="bs-dot is-pending"></span> Pending</span>
        <span><span class="bs-dot is-confirmed"></span> Confirmed</span>
        <span><span class="bs-dot is-completed"></span> Completed</span>
        <span><span class="bs-dot is-no-show"></span> No Show</span>
    </div>
    <div id="calendar"></div>
    </div>
</div>

<!-- Available Spaces -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list" aria-hidden="true"></i> Available Spaces</h3>
    </div>
    <div class="card-body">
<div class="bs-space-grid">
    <?php foreach ($spaces as $space): ?>
    <div class="bs-space">
        <div class="bs-space-head">
            <h3><?php echo htmlspecialchars($space['space_name']); ?></h3>
            <span class="badge badge-warning"><?php echo htmlspecialchars($space['space_type']); ?></span>
        </div>

        <div class="bs-space-details">
            <div class="bs-detail-row">
                <span><i class="fas fa-users" aria-hidden="true"></i> Capacity</span>
                <strong><?php echo $space['capacity']; ?> people</strong>
            </div>
            <div class="bs-detail-row">
                <span><i class="fas fa-clock" aria-hidden="true"></i> Hourly Rate</span>
                <strong>UGX <?php echo number_format($space['hourly_rate']); ?></strong>
            </div>
            <div class="bs-detail-row">
                <span><i class="fas fa-sun" aria-hidden="true"></i> Half Day (4hrs)</span>
                <strong>UGX <?php echo number_format($space['half_day_rate']); ?></strong>
            </div>
            <div class="bs-detail-row">
                <span><i class="fas fa-calendar-day" aria-hidden="true"></i> Full Day (8hrs)</span>
                <strong>UGX <?php echo number_format($space['full_day_rate']); ?></strong>
            </div>
            <?php if ($space['amenities']): ?>
            <div class="bs-amenities">
                <strong><i class="fas fa-check-circle" aria-hidden="true"></i> Amenities:</strong><br>
                <?php echo nl2br(htmlspecialchars($space['amenities'])); ?>
            </div>
            <?php endif; ?>
        </div>

        <button type="button" onclick="openBookingModal(<?php echo htmlspecialchars(json_encode($space)); ?>)"
                class="btn btn-primary bs-book-btn">
            <i class="fas fa-calendar-plus" aria-hidden="true"></i> Book Now
        </button>
    </div>
    <?php endforeach; ?>
</div>
<?php if (empty($spaces)): ?>
    <div class="empty-state">
        <i class="fas fa-door-closed" aria-hidden="true"></i>
        <p>No spaces are available for booking right now.</p>
    </div>
<?php endif; ?>
    </div>
</div>

</div><!-- /.page-stack -->

<!-- Booking Modal - handles BOTH creating a new booking (action=create_booking)
     and fully editing one of the member's own Pending bookings
     (action=update_booking). Self-contained overlay, independent of any
     global .modal styling. -->
<div id="bkOverlay" onclick="if (event.target === this) closeBookingModal();">
    <div class="bk-modal" onclick="event.stopPropagation();">
        <div class="bk-modal-header">
            <h3 id="modalTitle"><i class="fas fa-calendar-plus"></i> Book Space</h3>
            <button type="button" class="bk-close" onclick="closeBookingModal();" aria-label="Close">&times;</button>
        </div>
        <div class="bk-modal-body">
            <form method="POST" action="includes/process-booking.php" id="bookingForm" onsubmit="return handleBookingFormSubmit(event);">
                <!-- action switches between 'create_booking' and 'update_booking' via
                     setFormMode() below - includes/process-booking.php branches on this. -->
                <input type="hidden" name="action" value="create_booking" id="field_action">
                <input type="hidden" name="member_id" value="<?php echo $member_id; ?>" id="field_member_id">
                <input type="hidden" name="space_id" id="space_id">
                <input type="hidden" name="total_amount" id="total_amount_hidden" value="0">
                <!-- Only relevant (and only enabled) for action=update_booking. -->
                <input type="hidden" name="booking_id" id="field_booking_id" disabled>
                <input type="hidden" name="return_to" value="book-space">

                <div id="editModeNote" class="alert alert-warning" style="display:none;">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <span>Saving changes will reset this booking to <strong>Pending</strong>
                    and it will need to be re-confirmed.</span>
                </div>

                <!-- Space picker - required + enabled when creating from the calendar or
                     a card; replaced with a read-only label when editing, since the space
                     itself can't be changed here. -->
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

                <div class="space-readonly-box" id="spaceReadonlyBox" style="display:none;"></div>
                
                <div id="selectedSpaceInfo" class="bs-space-info"></div>

                <!-- Existing bookings for this space on the chosen date - populated via AJAX -->
                <div class="existing-bookings-panel" id="existingBookingsPanel" style="display:none;">
                    <h5><i class="fas fa-info-circle"></i> Already booked on this date</h5>
                    <div id="existingBookingsList"></div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="booking_date" class="required">Booking Date</label>
                        <input type="date" id="booking_date" name="booking_date" class="form-control" 
                               min="<?php echo date('Y-m-d'); ?>" required onchange="loadExistingBookings()">
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

                <div class="alert alert-danger overlap-warning" id="overlapWarning">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    <span>This time overlaps with an existing booking for this space. Please choose a different time.</span>
                </div>

                <!-- Editable details - shown and required in BOTH create and edit mode,
                     since includes/process-booking.php's update_booking action accepts
                     all of these (only space/pricing stay fixed once a booking exists). -->
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
                            <input type="checkbox" name="catering_required" id="catering_required" value="1" 
                                   onchange="toggleCateringDetails()">
                            <strong>Catering Required</strong>
                        </label>
                    </div>
                    
                    <div class="form-group" id="cateringDetails" style="display: none;">
                        <label for="catering_description">Catering Details</label>
                        <textarea id="catering_description" name="catering_description" class="form-control" rows="2" 
                                  placeholder="Number of people, meal preferences, dietary restrictions..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="special_requests">Special Requests</label>
                        <textarea id="special_requests" name="special_requests" class="form-control" rows="2"></textarea>
                    </div>
                </div>

                <!-- Pricing - CREATE-mode only. Amount is fixed once a booking exists,
                     so this (and booking_type above) is hidden and disabled in edit mode. -->
                <div id="pricingDisplayBox" class="bs-total">
                    <div class="bs-total-row">
                        <div>
                            <strong class="bs-total-label">Total Amount:</strong>
                        </div>
                        <div>
                            <span class="bs-total-value" id="totalAmount">
                                UGX 0
                            </span>
                        </div>
                    </div>
                    <small class="cell-muted">
                        <i class="fas fa-info-circle"></i> Payment can be made via Mobile Money, Bank Transfer, or at the Hub
                    </small>
                </div>
                
                <div class="bk-modal-footer">
                    <button type="button" onclick="closeBookingModal();" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success" id="confirmBookingBtn">
                        <i class="fas fa-check"></i> Confirm Booking
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// All available spaces, keyed by availability_id, for instant client-side
// lookups (rates, capacity) without extra round-trips.
const SPACES = <?php echo json_encode($spaces); ?>;
const SPACES_BY_ID = {};
SPACES.forEach(s => { SPACES_BY_ID[s.availability_id] = s; });

let selectedSpace = null;            // full space object, when known (create mode)
let spaceNameForOverlapCheck = null; // space_name string used to filter get-bookings.php, works for both modes
let currentMode = 'create';          // 'create' | 'edit'
let editingBookingId = null;         // set in edit mode, used to exclude self from overlap check

// Bookings already on file for the currently-selected space + date.
// Each entry: { start_time: 'HH:MM:SS', end_time: 'HH:MM:SS', status }
let existingBookingsForSpace = [];

// Initialize calendar - shows every active booking across all spaces so
// members can see at a glance what is already taken, and supports both a
// single click (dateClick) and a click-drag range (select) to start a
// booking directly from the calendar.
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
            const props = info.event.extendedProps;
            alert(
                'Space: ' + props.space_name +
                '\nStatus: ' + props.status +
                '\nTime: ' + info.event.start.toLocaleTimeString() + ' - ' + info.event.end.toLocaleTimeString()
            );
        },
        // Single click on a day (month view) - start a new booking on that date.
        dateClick: function(info) {
            openBookingModalForSlot(info.dateStr, null);
        },
        // Click-and-drag across one or more time slots (week/day view) - start a
        // new booking with both the start and end time pre-filled from the drag.
        select: function(info) {
            openBookingModalForSlot(info.startStr, info.endStr);
            calendar.unselect();
        }
    });
    calendar.render();
});

/**
 * Opens the booking modal in CREATE mode, pre-filled from a calendar click
 * or drag-select. startStr / endStr are FullCalendar date strings - either
 * plain "YYYY-MM-DD" (month view day click) or "YYYY-MM-DDTHH:MM:SS" (week/
 * day view time selection).
 */
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

    // No space chosen yet - the member picks one from the dropdown, which
    // then triggers the same space-info / existing-bookings / amount logic
    // used by the "Book Now" card buttons.
    document.getElementById('modalSpaceSelect').value = '';
    document.getElementById('modalSpaceSelect').disabled = false;
    selectedSpace = null;
    spaceNameForOverlapCheck = null;

    openBookingOverlay();
    updateBookingAmount();
}

/**
 * Opens the booking modal in CREATE mode for a specific space, triggered by
 * a space card's "Book Now" button.
 */
function openBookingModal(space) {
    resetModalFields();
    setFormMode('create');

    selectedSpace = space;
    spaceNameForOverlapCheck = space.space_name;

    document.getElementById('modalSpaceSelect').value = space.availability_id;
    document.getElementById('modalSpaceSelect').disabled = false;
    document.getElementById('space_id').value = space.availability_id;
    document.getElementById('number_of_attendees').max = space.capacity;
    renderSelectedSpaceInfo(space);

    const dateField = document.getElementById('booking_date');
    if (!dateField.value) {
        dateField.value = new Date().toISOString().slice(0, 10);
    }

    openBookingOverlay();
    loadExistingBookings();
}

/**
 * Opens the booking modal in EDIT mode for one of the member's own Pending
 * bookings (see the "Your Pending Bookings" section above). Everything
 * except space and pricing can be changed here - it posts to the
 * update_booking action in includes/process-booking.php, which resets
 * status to Pending and re-runs the server-side conflict check.
 */
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
}

function openBookingOverlay() {
    const overlay = document.getElementById('bkOverlay');
    overlay.classList.add('bk-open');
    document.body.style.overflow = 'hidden'; // prevent background scroll while modal is open
}

function closeBookingModal() {
    document.getElementById('bkOverlay').classList.remove('bk-open');
    document.body.style.overflow = '';
}

// Allow Escape key to close the modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBookingModal();
    }
});

/**
 * Switches the shared form between CREATE and EDIT behaviour:
 *  - swaps the `action` hidden field between 'create_booking' / 'update_booking'
 *  - toggles which hidden/visible fields are enabled (disabled fields are
 *    simply left out of the POST body)
 *  - shows/hides the space picker, booking type, and pricing box, which only
 *    apply when creating a brand-new booking
 */
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

    document.getElementById('pricingDisplayBox').style.display = isEdit ? 'none' : 'block';

    document.getElementById('modalTitle').innerHTML = isEdit
        ? '<i class="fas fa-edit"></i> Edit Booking'
        : '<i class="fas fa-calendar-plus"></i> Book Space';

    document.getElementById('confirmBookingBtn').innerHTML = isEdit
        ? '<i class="fas fa-check"></i> Save Changes'
        : '<i class="fas fa-check"></i> Confirm Booking';

    // Note: everything inside #editableDetailFields (attendees, purpose,
    // setup, equipment, catering, special requests) stays enabled and
    // visible in BOTH modes - includes/process-booking.php's update_booking
    // action accepts all of these.
}

/**
 * Resets the shared modal back to a blank slate before opening it again in
 * either mode, so leftover values from a previous session don't leak
 * across (e.g. an old purpose text carrying over into a fresh booking).
 */
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
    // form.reset() only restores VALUES, not attributes set via JS - the
    // "max" on attendees is set to a specific space's capacity by
    // openBookingModal() and, left in place, can silently block native
    // HTML5 validation (no visible error, submit just does nothing) the
    // next time the modal opens for a different space/booking with a
    // higher attendee count. Always clear it here so each open starts fresh.
    document.getElementById('number_of_attendees').removeAttribute('max');
    editingBookingId = null;
    existingBookingsForSpace = [];
}

function renderSelectedSpaceInfo(space) {
    document.getElementById('selectedSpaceInfo').innerHTML = `
        <h4>${space.space_name}</h4>
        <div class="bs-space-meta">
            <span><i class="fas fa-tag"></i> ${space.space_type}</span>
            <span><i class="fas fa-users"></i> Capacity: ${space.capacity}</span>
        </div>
    `;
}

// Space dropdown - used when a booking is started from the calendar (no
// space chosen yet) rather than from a specific space's "Book Now" card.
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

// Fetches all active bookings for the selected space on the selected date
// and renders them so the member can see what's taken before picking a time.
// Works in both modes, since spaceNameForOverlapCheck is set either from the
// chosen space (create) or the booking being edited (edit).
function loadExistingBookings() {
    const date = document.getElementById('booking_date').value;
    if (!date || !spaceNameForOverlapCheck) return;

    const panel = document.getElementById('existingBookingsPanel');
    const list = document.getElementById('existingBookingsList');

    fetch('get-bookings?start=' + date + '&end=' + date + '&space_name=' + encodeURIComponent(spaceNameForOverlapCheck))
        .then(response => response.json())
        .then(events => {
            existingBookingsForSpace = events
                // Exclude the booking currently being edited from its own conflict list.
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
            validateNoOverlap(); // re-check in case fields already filled
        })
        .catch(() => {
            list.innerHTML = '<div class="empty">Could not load existing bookings - please double-check availability before confirming.</div>';
            panel.style.display = 'block';
        });
}

function formatTime(t) {
    // t is 'HH:MM:SS' -> 'h:MM AM/PM'
    const [h, m] = t.split(':');
    const d = new Date();
    d.setHours(parseInt(h, 10), parseInt(m, 10), 0);
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

// Returns true if the currently entered start/end time range overlaps any
// existing booking for the relevant space on the selected date (already
// excludes the booking's own current slot when editing).
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

/**
 * Runs on the form's submit event. Two jobs:
 *  1. Re-syncs the hidden `action` field to match currentMode right before
 *     submitting, as a last line of defence against any UI state drift.
 *  2. If the browser's own HTML5 validation would silently block the
 *     submit (e.g. a stale "max" on a field, or something left required),
 *     surface that explicitly via reportValidity() instead of the form
 *     just doing nothing with no feedback - that "nothing visibly
 *     happened" is exactly what made this bug hard to notice.
 */
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

// Called on time change and on form submit - blocks submission client-side
// if the chosen slot collides with a known booking. The server still does
// the authoritative check in includes/process-booking.php.
function validateNoOverlap() {
    const warning = document.getElementById('overlapWarning');
    const submitBtn = document.getElementById('confirmBookingBtn');

    if (currentMode === 'create' && !selectedSpace) {
        // Nothing chosen yet - don't warn, just don't allow submit.
        return true;
    }

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

function updateBookingAmount() {
    if (currentMode !== 'create' || !selectedSpace) return;
    
    const bookingType = document.getElementById('booking_type').value;
    const startTime = document.getElementById('start_time').value;
    const endTime = document.getElementById('end_time').value;
    
    let amount = 0;
    
    if (bookingType === 'hourly' && startTime && endTime) {
        const start = new Date('2000-01-01 ' + startTime);
        const end = new Date('2000-01-01 ' + endTime);
        const hours = (end - start) / (1000 * 60 * 60);
        amount = hours > 0 ? hours * selectedSpace.hourly_rate : 0;
    } else if (bookingType === 'half_day') {
        amount = selectedSpace.half_day_rate;
    } else if (bookingType === 'full_day') {
        amount = selectedSpace.full_day_rate;
    }
    
    document.getElementById('totalAmount').textContent = 'UGX ' + amount.toLocaleString();
    document.getElementById('total_amount_hidden').value = amount;

    validateNoOverlap();
}

function toggleCateringDetails() {
    const checkbox = document.getElementById('catering_required');
    const details = document.getElementById('cateringDetails');
    details.style.display = checkbox.checked ? 'block' : 'none';
}

// Auto-set end time based on booking type (create mode only)
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
            const endTime = start.toTimeString().slice(0, 5);
            document.getElementById('end_time').value = endTime;
            updateBookingAmount();
        }
    }
});
</script>