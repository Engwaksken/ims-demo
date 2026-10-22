<?php
$page_title = 'My Event Registrations';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get member details
$member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    echo "<div class='alert alert-danger'>Member profile not found. Please contact administrator.</div>";
    include 'includes/footer.php';
    exit();
}

$member_id = $member_result->fetch_assoc()['member_id'];

// Cancel own registration (POST + CSRF via config.php; 24-hour rule as shown on the card)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cancel_registration'])) {
    $cancelId = (int) $_POST['cancel_registration'];
    $notice   = ['type' => 'error', 'message' => 'Registration not found.'];

    $stmt = $conn->prepare(
        "SELECT er.registration_status, e.event_date, e.start_time, e.event_title
           FROM event_registrations er
           JOIN hub_events e ON e.event_id = er.event_id
          WHERE er.registration_id = ? AND er.member_id = ?
          LIMIT 1"
    );
    $stmt->bind_param('ii', $cancelId, $member_id);
    $stmt->execute();
    $reg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($reg) {
        $hoursUntil = (strtotime($reg['event_date'] . ' ' . $reg['start_time']) - time()) / 3600;

        if ($reg['registration_status'] === 'Cancelled') {
            $notice = ['type' => 'info', 'message' => 'This registration is already cancelled.'];
        } elseif ($hoursUntil < 24) {
            $notice = ['type' => 'error', 'message' => 'Registrations can only be cancelled at least 24 hours before the event.'];
        } else {
            $stmt = $conn->prepare(
                "UPDATE event_registrations SET registration_status = 'Cancelled'
                  WHERE registration_id = ? AND member_id = ?"
            );
            $stmt->bind_param('ii', $cancelId, $member_id);
            $stmt->execute();
            $stmt->close();
            $notice = ['type' => 'success', 'message' => 'Your registration for "' . $reg['event_title'] . '" has been cancelled.'];
        }
    }

    $_SESSION['notification'] = $notice;
    header('Location: my-event-registrations');
    exit();
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $conn->real_escape_string(sanitize_input($_GET['status'])) : '';
$view = isset($_GET['view']) ? $conn->real_escape_string(sanitize_input($_GET['view'])) : 'upcoming';

// Build WHERE clause
$where = "er.member_id = $member_id";

// View filter
if ($view == 'upcoming') {
    $where .= " AND e.event_date >= CURDATE()";
} elseif ($view == 'past') {
    $where .= " AND e.event_date < CURDATE()";
}

// Status filter
if ($filter_status) {
    $where .= " AND er.registration_status = '$filter_status'";
}

// Fetch registrations
$registrations = [];
$query = "SELECT er.*, e.*, 
          (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.event_id AND registration_status != 'Cancelled') as total_registrations
          FROM event_registrations er
          INNER JOIN hub_events e ON er.event_id = e.event_id
          WHERE $where
          ORDER BY e.event_date DESC, e.start_time DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $registrations[] = $row;
}

// Calculate statistics
$stats = [];

// Total registrations
$stats['total'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations 
                                WHERE member_id = $member_id 
                                AND registration_status != 'Cancelled'")->fetch_assoc()['count'];

// Upcoming events
$stats['upcoming'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations er
                                   INNER JOIN hub_events e ON er.event_id = e.event_id
                                   WHERE er.member_id = $member_id 
                                   AND e.event_date >= CURDATE()
                                   AND er.registration_status != 'Cancelled'")->fetch_assoc()['count'];

// Attended events
$stats['attended'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations er
                                   INNER JOIN hub_events e ON er.event_id = e.event_id
                                   WHERE er.member_id = $member_id 
                                   AND er.check_in_time IS NOT NULL")->fetch_assoc()['count'];

// Pending payment
$stats['pending_payment'] = $conn->query("SELECT COUNT(*) as count FROM event_registrations 
                                          WHERE member_id = $member_id 
                                          AND payment_status = 'Pending'
                                          AND registration_status != 'Cancelled'")->fetch_assoc()['count'];
?>

<style>
.registrations-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.registrations-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.view-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 25px;
    border-bottom: 2px solid #ecf0f1;
    overflow-x: auto;
}

.view-tab {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 15px;
    font-weight: 500;
    color: #7f8c8d;
    transition: all 0.3s;
    white-space: nowrap;
}

.view-tab:hover {
    color: #ff6b35;
}

.view-tab.active {
    color: #ff6b35;
    border-bottom-color: #ff6b35;
}

.registration-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 5px solid #ff6b35;
    transition: transform 0.2s, box-shadow 0.2s;
}

.registration-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.registration-card.pending {
    border-left-color: #F39C12;
}

.registration-card.confirmed {
    border-left-color: #2ECC71;
}

.registration-card.cancelled {
    border-left-color: #E74C3C;
    opacity: 0.7;
}

.registration-card.attended {
    border-left-color: #3498DB;
}

.event-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.event-title {
    flex: 1;
}

.event-title h3 {
    font-size: 22px;
    color: #2c3e50;
    margin-bottom: 5px;
}

.event-title .event-type {
    color: #7f8c8d;
    font-size: 14px;
}

.event-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.detail-item i {
    color: #ff6b35;
    font-size: 18px;
    width: 25px;
}

.detail-item .content {
    flex: 1;
}

.detail-item .label {
    font-size: 12px;
    color: #7f8c8d;
    text-transform: uppercase;
}

.detail-item .value {
    font-size: 16px;
    color: #2c3e50;
    font-weight: 600;
}

.registration-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.registration-info .info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 14px;
}

.registration-info .info-row:last-child {
    margin-bottom: 0;
}

.registration-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.check-in-badge {
    background: #2ECC71;
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i {
    font-size: 64px;
    color: #bdc3c7;
    margin-bottom: 20px;
}

.filter-bar {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.filter-bar > .filters-bar {
    margin-bottom: 0;
}

.countdown {
    background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
    color: white;
    padding: 10px 15px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    display: inline-block;
}

@media (max-width: 768px) {
    .event-details {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Header -->
<div class="registrations-header">
    <h1><i class="fas fa-ticket-alt"></i> My Event Registrations</h1>
    <p style="margin: 0; opacity: 0.9;">View and manage your event registrations</p>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Registrations</span>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Upcoming Events</span>
            <span class="stat-value"><?php echo $stats['upcoming']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Events Attended</span>
            <span class="stat-value"><?php echo $stats['attended']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Payment</span>
            <span class="stat-value"><?php echo $stats['pending_payment']; ?></span>
        </div>
    </div>
</div>

<!-- View Tabs -->
<div class="view-tabs">
    <button class="view-tab <?php echo $view == 'upcoming' ? 'active' : ''; ?>" 
            onclick="window.location.href='my-event-registrations?view=upcoming'">
        <i class="fas fa-arrow-right"></i> Upcoming Events
    </button>
    <button class="view-tab <?php echo $view == 'past' ? 'active' : ''; ?>" 
            onclick="window.location.href='my-event-registrations?view=past'">
        <i class="fas fa-history"></i> Past Events
    </button>
    <button class="view-tab <?php echo $view == 'all' ? 'active' : ''; ?>" 
            onclick="window.location.href='my-event-registrations?view=all'">
        <i class="fas fa-list"></i> All Registrations
    </button>
    <button class="view-tab" onclick="window.location.href='hub-events'">
        <i class="fas fa-plus-circle"></i> Browse Events
    </button>
</div>

<!-- Filter Bar -->
<form method="GET" action="" class="filter-bar">
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
    <div class="filters-bar" role="search" aria-label="Filter registrations">
        <div class="form-group">
            <label for="status">Registration Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Confirmed" <?php echo $filter_status == 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="Cancelled" <?php echo $filter_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                <option value="Waitlisted" <?php echo $filter_status == 'Waitlisted' ? 'selected' : ''; ?>>Waitlisted</option>
            </select>
        </div>
        
        <div class="filters-actions">
            <button type="submit" class="btn btn-info">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="my-event-registrations?view=<?php echo htmlspecialchars($view); ?>" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </div>
</form>

<!-- Registrations List -->
<?php if (empty($registrations)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No registrations found</h3>
                <p style="color: #7f8c8d; margin-bottom: 20px;">
                    <?php if ($view == 'upcoming'): ?>
                        You don't have any upcoming event registrations.
                    <?php elseif ($view == 'past'): ?>
                        You haven't attended any past events.
                    <?php else: ?>
                        No registrations match your filter criteria.
                    <?php endif; ?>
                </p>
                <a href="hub-events" class="btn btn-primary">
                    <i class="fas fa-calendar-alt"></i> Browse Events
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($registrations as $reg): 
        // Determine card class
        $card_class = strtolower($reg['registration_status']);
        if ($reg['check_in_time']) {
            $card_class = 'attended';
        }
        
        // Calculate time until event
        $event_datetime = strtotime($reg['event_date'] . ' ' . $reg['start_time']);
        $hours_until = ($event_datetime - time()) / 3600;
        $days_until = ceil($hours_until / 24);
        
        // Check if event is in the past
        $is_past = strtotime($reg['event_date']) < strtotime('today');
        
        // Can cancel if registered and event is in future and more than 24 hours away
        $can_cancel = $reg['registration_status'] != 'Cancelled' && !$is_past && $hours_until >= 24;
    ?>
    <div class="registration-card <?php echo $card_class; ?>">
        <div class="event-header">
            <div class="event-title">
                <h3><?php echo htmlspecialchars($reg['event_title']); ?></h3>
                <div class="event-type">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($reg['event_type']); ?>
                    <?php if ($reg['is_members_only']): ?>
                        | <i class="fas fa-lock"></i> Members Only
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align: right;">
                <?php
                $status_badges = [
                    'Pending' => 'warning',
                    'Confirmed' => 'success',
                    'Cancelled' => 'danger',
                    'Waitlisted' => 'info'
                ];
                $badge = $status_badges[$reg['registration_status']] ?? 'secondary';
                ?>
                <span class="badge badge-<?php echo $badge; ?>" style="font-size: 14px; padding: 8px 16px;">
                    <?php echo $reg['registration_status']; ?>
                </span>
                
                <?php if ($reg['check_in_time']): ?>
                    <div class="check-in-badge" style="margin-top: 10px;">
                        <i class="fas fa-check"></i> Checked In
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="event-details">
            <div class="detail-item">
                <i class="fas fa-calendar"></i>
                <div class="content">
                    <div class="label">Date</div>
                    <div class="value">
                        <?php echo date('d M Y', strtotime($reg['event_date'])); ?>
                        <br><small style="color: #7f8c8d;"><?php echo date('l', strtotime($reg['event_date'])); ?></small>
                    </div>
                </div>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-clock"></i>
                <div class="content">
                    <div class="label">Time</div>
                    <div class="value">
                        <?php echo date('h:i A', strtotime($reg['start_time'])); ?> - 
                        <?php echo date('h:i A', strtotime($reg['end_time'])); ?>
                    </div>
                </div>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-map-marker-alt"></i>
                <div class="content">
                    <div class="label">Venue</div>
                    <div class="value"><?php echo htmlspecialchars($reg['venue']); ?></div>
                </div>
            </div>
            
            <?php if ($reg['registration_fee'] > 0): ?>
            <div class="detail-item">
                <i class="fas fa-tag"></i>
                <div class="content">
                    <div class="label">Fee</div>
                    <div class="value" style="color: #2ECC71;">
                        <?php echo format_currency($reg['registration_fee']); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!$is_past && $hours_until > 0 && $hours_until <= 168): // Show countdown if within 7 days ?>
        <div style="margin-bottom: 15px;">
            <div class="countdown">
                <i class="fas fa-hourglass-half"></i>
                <?php if ($days_until <= 1): ?>
                    Event starts in <?php echo round($hours_until); ?> hours
                <?php else: ?>
                    Event in <?php echo $days_until; ?> days
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Registration Info -->
        <div class="registration-info">
            <div class="info-row">
                <span style="color: #7f8c8d;">Registration Date:</span>
                <strong><?php echo date('d M Y, h:i A', strtotime($reg['created_at'])); ?></strong>
            </div>
            
            <?php if ($reg['attendee_name'] && $reg['attendee_name'] != ''): ?>
            <div class="info-row">
                <span style="color: #7f8c8d;">Attendee Name:</span>
                <strong><?php echo htmlspecialchars($reg['attendee_name']); ?></strong>
            </div>
            <?php endif; ?>
            
            <?php if ($reg['registration_fee'] > 0): ?>
            <div class="info-row">
                <span style="color: #7f8c8d;">Payment Status:</span>
                <strong>
                    <?php
                    $payment_badges = [
                        'Pending' => 'warning',
                        'Paid' => 'success',
                        'Refunded' => 'danger'
                    ];
                    $payment_badge = $payment_badges[$reg['payment_status']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?php echo $payment_badge; ?>">
                        <?php echo $reg['payment_status']; ?>
                    </span>
                </strong>
            </div>
            <?php endif; ?>
            
            <?php if ($reg['payment_reference']): ?>
            <div class="info-row">
                <span style="color: #7f8c8d;">Payment Reference:</span>
                <strong><code><?php echo htmlspecialchars($reg['payment_reference']); ?></code></strong>
            </div>
            <?php endif; ?>
            
            <?php if ($reg['check_in_time']): ?>
            <div class="info-row">
                <span style="color: #7f8c8d;">Check-in Time:</span>
                <strong style="color: #2ECC71;">
                    <i class="fas fa-check-circle"></i> 
                    <?php echo date('d M Y, h:i A', strtotime($reg['check_in_time'])); ?>
                </strong>
            </div>
            <?php endif; ?>
            
            <?php if ($reg['special_requirements']): ?>
            <div class="info-row">
                <span style="color: #7f8c8d;">Special Requirements:</span>
                <strong><?php echo htmlspecialchars($reg['special_requirements']); ?></strong>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Actions -->
        <div class="registration-actions">
            <a href="event-register?token=<?php echo rawurlencode(base64_encode('eid=' . (int)$reg['event_id'])); ?>" class="btn btn-info btn-sm">
                <i class="fas fa-eye"></i> View Event Details
            </a>
            
            <?php if ($can_cancel): ?>
                <button onclick="confirmCancelRegistration(<?php echo $reg['registration_id']; ?>, <?= htmlspecialchars(json_encode((string)($reg['event_title'])), ENT_QUOTES, 'UTF-8') ?>)" 
                        class="btn btn-danger btn-sm">
                    <i class="fas fa-times"></i> Cancel Registration
                </button>
            <?php endif; ?>
            
            <?php if ($reg['max_attendees'] > 0): ?>
                <span style="color: #7f8c8d; font-size: 13px; display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-users"></i>
                    <?php echo $reg['total_registrations']; ?> / <?php echo $reg['max_attendees']; ?> registered
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<form id="cancelRegistrationForm" method="POST" action="my-event-registrations" hidden>
    <input type="hidden" name="cancel_registration" value="">
</form>

<?php include 'includes/footer.php'; ?>

<script>
function confirmCancelRegistration(registrationId, eventTitle) {
    if (confirm(`Are you sure you want to cancel your registration for "${eventTitle}"?\n\nNote: Cancellations must be made at least 24 hours before the event.`)) {
        const form = document.getElementById('cancelRegistrationForm');
        form.elements['cancel_registration'].value = registrationId;
        form.submit();
    }
}
</script>