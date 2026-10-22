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
.portal-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.member-info {
    flex: 1;
}

.member-info h1 {
    font-size: 28px;
    margin-bottom: 8px;
}

.member-badge {
    background: rgba(255,255,255,0.2);
    padding: 10px 20px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
}

.subscription-status {
    text-align: center;
    min-width: 200px;
}

.subscription-status .status-badge {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    margin-bottom: 8px;
}

.subscription-status .status-badge.active {
    background: #27AE60;
}

.subscription-status .status-badge.expiring {
    background: #F39C12;
}

.subscription-status .status-badge.expired {
    background: #E74C3C;
}

.quick-actions {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.quick-actions h3 {
    margin-bottom: 20px;
    color: #2c3e50;
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.action-card {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 25px;
    border-radius: 10px;
    text-align: center;
    text-decoration: none;
    transition: all 0.3s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.action-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
    color: white;
}

.action-card i {
    font-size: 40px;
}

.action-card .action-title {
    font-weight: 600;
    font-size: 16px;
}

.section-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.section-card h3 {
    color: #ff6b35;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
}

.booking-item, .event-item, .payment-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    border-left: 4px solid var(--primary-color);
}

.booking-item h4, .event-item h4, .payment-item h4 {
    margin-bottom: 8px;
    color: #2c3e50;
    font-size: 16px;
}

.item-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #7f8c8d;
    margin-bottom: 10px;
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: #95a5a6;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
}

.alert-warning {
    background: #fff3cd;
    border: 2px solid #F39C12;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.alert-danger {
    background: #f8d7da;
    border: 2px solid #E74C3C;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}
</style>

<!-- Portal Header -->
<div class="portal-header">
    <div class="member-info">
        <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
        <div class="member-badge">
            <i class="fas fa-id-card"></i>
            <span><?php echo htmlspecialchars($member['membership_number']); ?></span>
            <span>|</span>
            <span><?php echo htmlspecialchars($member['member_type']); ?> Member</span>
            <?php if ($member['company_name']): ?>
                <span>|</span>
                <span><?php echo htmlspecialchars($member['company_name']); ?></span>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="subscription-status">
        <div class="status-badge <?php 
            echo $member['membership_status'] == 'Active' ? 'active' : 
                ($days_until_expiry <= 7 && $days_until_expiry > 0 ? 'expiring' : 'expired'); 
        ?>">
            <?php echo $member['membership_status']; ?>
        </div>
        <?php if ($member['subscription_end_date']): ?>
            <div style="font-size: 13px; opacity: 0.9;">
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
<div class="alert-warning">
    <strong><i class="fas fa-exclamation-triangle"></i> Subscription Expiring Soon!</strong>
    <p style="margin: 5px 0 0 0;">Your membership expires in <?php echo $days_until_expiry; ?> days. Please renew to continue enjoying hub services.</p>
    <a href="my-subscription" class="btn btn-warning btn-sm" style="margin-top: 10px;">
        <i class="fas fa-redo"></i> Renew Now
    </a>
</div>
<?php elseif ($days_until_expiry < 0): ?>
<div class="alert-danger">
    <strong><i class="fas fa-times-circle"></i> Subscription Expired!</strong>
    <p style="margin: 5px 0 0 0;">Your membership expired <?php echo abs($days_until_expiry); ?> days ago. Renew now to regain access.</p>
    <a href="my-subscription" class="btn btn-danger btn-sm" style="margin-top: 10px;">
        <i class="fas fa-redo"></i> Renew Membership
    </a>
</div>
<?php endif; ?>

<!-- Pending Payments -->
<?php if (!empty($pending_payments)): ?>
<div class="alert-warning">
    <strong><i class="fas fa-exclamation-circle"></i> Pending Payments</strong>
    <p style="margin: 5px 0;">You have <?php echo count($pending_payments); ?> pending payment(s).</p>
    <a href="payment-history" class="btn btn-warning btn-sm" style="margin-top: 10px;">
        <i class="fas fa-money-bill"></i> View Payments
    </a>
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
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
    
    <!-- Upcoming Bookings -->
    <div class="section-card">
        <h3><i class="fas fa-calendar-check"></i> Upcoming Bookings</h3>
        <?php if (empty($upcoming_bookings)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <p>No upcoming bookings</p>
                <a href="book-space" class="btn btn-primary btn-sm">
                    Book Now
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($upcoming_bookings as $booking): ?>
            <div class="booking-item">
                <h4><?php echo htmlspecialchars($booking['space_type']); ?> - <?php echo htmlspecialchars($booking['space_name']); ?></h4>
                <div class="item-meta">
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
    
    <!-- Upcoming Events -->
    <div class="section-card">
        <h3><i class="fas fa-calendar-day"></i> Upcoming Events</h3>
        <?php if (empty($upcoming_events)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <p>No upcoming events</p>
                <a href="hub-events" class="btn btn-primary btn-sm">
                    Browse Events
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($upcoming_events as $event): ?>
            <div class="event-item">
                <h4><?php echo htmlspecialchars($event['event_title']); ?></h4>
                <div class="item-meta">
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

<?php include 'includes/footer.php'; ?>