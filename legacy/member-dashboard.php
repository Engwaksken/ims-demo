<?php
$page_title = 'Member Dashboard';

require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
//session_start();

// Must be logged in
require_login();

// Only Members allowed
if ($_SESSION['role'] !== 'Member') {
    redirect_by_role($_SESSION['role']);
}

require_once 'includes/header.php';

$user_id = (int)$_SESSION['user_id'];

// Get member details
$member_query = "SELECT * FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    $_SESSION['error'] = "Member profile not found. Please contact support.";
    header("Location: login");
    exit();
}

$member = $member_result->fetch_assoc();
$member_id = $member['member_id'];

// Check subscription status
$subscription_active = ($member['membership_status'] == 'Active');
$days_until_expiry = 0;
if ($member['subscription_end_date']) {
    $today = new DateTime();
    $expiry = new DateTime($member['subscription_end_date']);
    $days_until_expiry = $today->diff($expiry)->days;
    if ($expiry < $today) {
        $days_until_expiry = -$days_until_expiry;
    }
}

// Get upcoming bookings
$upcoming_bookings = [];
$query = "SELECT * FROM space_bookings 
          WHERE member_id = $member_id 
          AND booking_date >= CURDATE() 
          AND booking_status != 'Cancelled'
          ORDER BY booking_date ASC, start_time ASC LIMIT 5";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $upcoming_bookings[] = $row;
}

// Get upcoming events
$upcoming_events = [];
$query = "SELECT e.*, r.registration_status 
          FROM hub_events e
          LEFT JOIN event_registrations r ON e.event_id = r.event_id 
          WHERE e.event_date >= CURDATE() 
          AND e.event_status = 'Published'
          ORDER BY e.event_date ASC, e.start_time ASC LIMIT 5";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $upcoming_events[] = $row;
}

// Get pending payments
$pending_payments = [];
$query = "SELECT * FROM subscription_payments 
          WHERE member_id = $member_id 
          AND payment_status IN ('Pending', 'Overdue')
          ORDER BY payment_period_end DESC LIMIT 3";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $pending_payments[] = $row;
}

// Get unread notifications
$unread_count = $conn->query("SELECT COUNT(*) as count FROM member_notifications 
                               WHERE member_id = $member_id AND is_read = 0")->fetch_assoc()['count'];

// Statistics
$total_bookings = $conn->query("SELECT COUNT(*) as count FROM space_bookings 
                                WHERE member_id = $member_id")->fetch_assoc()['count'];
$this_month_bookings = $conn->query("SELECT COUNT(*) as count FROM space_bookings 
                                     WHERE member_id = $member_id 
                                     AND MONTH(booking_date) = MONTH(CURDATE())
                                     AND YEAR(booking_date) = YEAR(CURDATE())")->fetch_assoc()['count'];
$total_events_attended = $conn->query("SELECT COUNT(*) as count FROM event_registrations 
                                       WHERE registration_status = 'Attended'")->fetch_assoc()['count'];
?>

<style>
/* Member dashboard: status chip in the hero, two-column lists of upcoming items. */
.md-status { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; position: relative; }
.md-status-note { font-size: 13px; opacity: .9; }
.md-hero-meta { display: inline-flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.md-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.md-item {
    background: var(--surface-hover);
    padding: 15px;
    border-radius: var(--radius-md);
    margin-bottom: 10px;
    border-left: 4px solid var(--brand-500);
}
.md-item h4 { margin: 0 0 8px; color: var(--ink-700); font-size: 16px; }
.md-item-meta { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: var(--ink-300); margin-bottom: 10px; }
.md-alert-text { margin: 5px 0 10px; }
@media (max-width: 900px) { .md-columns { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .md-status { align-items: flex-start; } }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-house-user" aria-hidden="true"></i> Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
        <p class="md-hero-meta">
            <i class="fas fa-id-card" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($member['membership_number']); ?></span>
            <span>|</span>
            <span><?php echo htmlspecialchars($member['member_type']); ?> Member</span>
            <?php if ($member['company_name']): ?>
                <span>|</span>
                <span><?php echo htmlspecialchars($member['company_name']); ?></span>
            <?php endif; ?>
        </p>
    </div>

    <div class="md-status">
        <span class="badge badge-<?php 
            echo $member['membership_status'] == 'Active' ? 'success' : 
                ($days_until_expiry <= 7 && $days_until_expiry > 0 ? 'warning' : 'danger'); 
        ?>">
            <?php echo $member['membership_status']; ?>
        </span>
        <?php if ($member['subscription_end_date']): ?>
            <div class="md-status-note">
                <?php if ($days_until_expiry > 0): ?>
                    Expires in <?php echo $days_until_expiry; ?> days
                <?php elseif ($days_until_expiry == 0): ?>
                    Expires today
                <?php else: ?>
                    Expired <?php echo abs($days_until_expiry); ?> days ago
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Expiry Warnings -->
<?php if ($days_until_expiry <= 7 && $days_until_expiry > 0): ?>
<div class="alert alert-warning">
    <div>
        <strong><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Subscription Expiring Soon!</strong>
        <p class="md-alert-text">Your membership expires in <?php echo $days_until_expiry; ?> days. Please renew to continue enjoying hub services.</p>
        <a href="my-subscription" class="btn btn-warning btn-sm">
            <i class="fas fa-redo" aria-hidden="true"></i> Renew Now
        </a>
    </div>
</div>
<?php elseif ($days_until_expiry < 0): ?>
<div class="alert alert-danger">
    <div>
        <strong><i class="fas fa-times-circle" aria-hidden="true"></i> Subscription Expired!</strong>
        <p class="md-alert-text">Your membership expired <?php echo abs($days_until_expiry); ?> days ago. Renew now to regain access.</p>
        <a href="my-subscription" class="btn btn-danger btn-sm">
            <i class="fas fa-redo" aria-hidden="true"></i> Renew Membership
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Pending Payments -->
<?php if (!empty($pending_payments)): ?>
<div class="alert alert-warning">
    <div>
        <strong><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Pending Payments</strong>
        <p class="md-alert-text">You have <?php echo count($pending_payments); ?> pending payment(s).</p>
        <a href="payment-history" class="btn btn-warning btn-sm">
            <i class="fas fa-money-bill" aria-hidden="true"></i> View Payments
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Bookings</span>
            <span class="stat-value"><?php echo $total_bookings; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-calendar" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">This Month</span>
            <span class="stat-value"><?php echo $this_month_bookings; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Events Attended</span>
            <span class="stat-value"><?php echo $total_events_attended; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-bell" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Notifications</span>
            <span class="stat-value"><?php echo $unread_count; ?></span>
        </div>
    </div>
</div>


<!-- Two Column Layout -->
<div class="md-columns">
    
    <!-- Upcoming Bookings -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-calendar-check" aria-hidden="true"></i> Upcoming Bookings</h3></div>
        <div class="card-body">
        <?php if (empty($upcoming_bookings)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times" aria-hidden="true"></i>
                <p>No upcoming bookings</p>
                <a href="book-space" class="btn btn-primary btn-sm">
                    Book Now
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($upcoming_bookings as $booking): ?>
            <div class="md-item">
                <h4><?php echo htmlspecialchars($booking['space_type']); ?> - <?php echo htmlspecialchars($booking['space_name']); ?></h4>
                <div class="md-item-meta">
                    <span><i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($booking['booking_date'])); ?></span>
                    <span><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($booking['start_time'])); ?> - <?php echo date('h:i A', strtotime($booking['end_time'])); ?></span>
                </div>
                <div>
                    <span class="badge badge-<?php 
                        echo $booking['booking_status'] == 'Confirmed' ? 'success' : 
                            ($booking['booking_status'] == 'Pending' ? 'warning' : 'info'); 
                    ?>">
                        <?php echo $booking['booking_status']; ?>
                    </span>
                    <span class="badge badge-<?php 
                        echo $booking['payment_status'] == 'Paid' ? 'success' : 'warning'; 
                    ?>">
                        Payment: <?php echo $booking['payment_status']; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
            <a href="my-bookings" class="btn btn-primary btn-sm">
                <i class="fas fa-list"></i> View All Bookings
            </a>
        <?php endif; ?>
        </div>
    </div>
    
    <!-- Upcoming Events -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-calendar-day" aria-hidden="true"></i> Upcoming Events</h3></div>
        <div class="card-body">
        <?php if (empty($upcoming_events)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times" aria-hidden="true"></i>
                <p>No upcoming events</p>
                <a href="hub-events" class="btn btn-primary btn-sm">
                    Browse Events
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($upcoming_events as $event): ?>
            <div class="md-item">
                <h4><?php echo htmlspecialchars($event['event_title']); ?></h4>
                <div class="md-item-meta">
                    <span><i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($event['event_date'])); ?></span>
                    <span><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($event['start_time'])); ?></span>
                    <span><i class="fas fa-tag"></i> <?php echo $event['event_type']; ?></span>
                </div>
                <?php if ($event['registration_status']): ?>
                    <span class="badge badge-success">Registered</span>
                <?php else: ?>
                    <a href="hub-events?event_id=<?php echo $event['event_id']; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-user-plus"></i> Register
                    </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <a href="hub-events" class="btn btn-primary btn-sm">
                <i class="fas fa-calendar-alt"></i> View All Events
            </a>
        <?php endif; ?>
        </div>
    </div>
</div>
</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>