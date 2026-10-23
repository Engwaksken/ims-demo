<?php

if (!function_exists('hub_e')) {
    function hub_e(mixed $value): string
    {
        return htmlspecialchars(
            $value === null ? '' : (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

$page_title = 'Hub Operations';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
include 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';
check_role(IMS_ALL_ROLES);


$can_open_member_folders = ims_allowed('members.folder_view', ['Administrator', 'Operations/Admin', 'Finance', 'Accountant', 'Executive Director'], 'View and export member folders (contracts, receipts, documents)');

// Get active tab
$active_tab = isset($_GET['tab']) ? sanitize_input($_GET['tab']) : 'members';

// Fetch Statistics
$stats = [];

// Active members
$result = $conn->query("SELECT COUNT(*) as count FROM members WHERE membership_status = 'Active'");
$stats['active_members'] = $result->fetch_assoc()['count'];

// Expiring soon (within 30 days)
$result = $conn->query("SELECT COUNT(*) as count FROM members 
                        WHERE membership_status = 'Active' 
                        AND subscription_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stats['expiring_soon'] = $result->fetch_assoc()['count'];

// Today's bookings
$result = $conn->query("SELECT COUNT(*) as count FROM space_bookings 
                        WHERE booking_date = CURDATE() 
                        AND booking_status != 'Cancelled'");
$stats['today_bookings'] = $result->fetch_assoc()['count'];

// Pending bookings
$result = $conn->query("SELECT COUNT(*) as count FROM space_bookings 
                        WHERE booking_status = 'Pending'");
$stats['pending_bookings'] = $result->fetch_assoc()['count'];

// Unverified receipts
$result = $conn->query("SELECT COUNT(*) as count FROM payment_receipts 
                        WHERE verification_status = 'Pending'");
$stats['pending_receipts'] = $result->fetch_assoc()['count'];

// Unread feedback
$result = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                        WHERE feedback_status = 'New'");
$stats['new_feedback'] = $result->fetch_assoc()['count'];

// Total monthly revenue (from active subscriptions)
$result = $conn->query("SELECT SUM(subscription_amount) as total FROM members 
                        WHERE membership_status = 'Active'");
$stats['monthly_revenue'] = $result->fetch_assoc()['total'] ?? 0;

// Subscription payments this month
$result = $conn->query("SELECT COUNT(*) as count FROM subscription_payments 
                         WHERE MONTH(created_at) = MONTH(CURDATE()) 
                         AND YEAR(created_at) = YEAR(CURDATE())");
$stats['monthly_subscriptions'] = $result->fetch_assoc()['count'];



// Get member for editing
$edit_member = null;
if (isset($_GET['edit_member'])) {
    $member_id = intval($_GET['edit_member']);
    $result = $conn->query("SELECT m.*, u.email as user_email, u.full_name 
                           FROM members m 
                           LEFT JOIN users u ON m.user_id = u.user_id 
                           WHERE m.member_id = $member_id");
    $edit_member = $result->fetch_assoc();
}

// Get subscription for editing
$edit_subscription = null;
if (isset($_GET['edit_subscription'])) {
    $payment_id = intval($_GET['edit_subscription']);
    $result = $conn->query("SELECT sp.*, u.full_name as member_name 
                           FROM subscription_payments sp 
                           LEFT JOIN members m ON sp.member_id = m.member_id 
                           LEFT JOIN users u ON m.user_id = u.user_id 
                           WHERE sp.payment_id = $payment_id");
    $edit_subscription = $result->fetch_assoc();
}

// Get receipt for editing
$edit_receipt = null;
if (isset($_GET['edit_receipt'])) {
    $receipt_id = intval($_GET['edit_receipt']);
    $result = $conn->query("SELECT pr.*, u.full_name as member_name 
                           FROM payment_receipts pr 
                           LEFT JOIN members m ON pr.member_id = m.member_id 
                           LEFT JOIN users u ON m.user_id = u.user_id 
                           WHERE pr.receipt_id = $receipt_id");
    $edit_receipt = $result->fetch_assoc();
}

?>


<style>
/* Hub Operations: page-specific layout only. */
.ho-tab-count { margin-left: 2px; }
.ho-row-expiring > td { background: var(--amber-bg); }
.ho-expiry-note { color: var(--amber-fg); }
.ho-star { color: var(--ink-100); }
.ho-star.is-on { color: var(--amber-fg); }
.ho-subhead { margin: var(--space-7) 0 var(--space-4); }
.ho-subhead i { color: var(--brand-500); }
.ho-current-file { margin-bottom: var(--space-2); }
.ho-chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr)); gap: var(--space-5); }
.ho-chart-grid + .ho-chart-grid { margin-top: var(--space-6); }
.ho-chart-title { margin-bottom: var(--space-3); }
.ho-chart { max-height: 300px; }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-building" aria-hidden="true"></i> Hub Operations</h1>
        <p>Run the hub day to day: members, space bookings, payment receipts, feedback, notifications and subscriptions.</p>
    </div>
    <div class="hero-actions">
        <a href="hub-visitors" class="btn"><i class="fas fa-user-friends" aria-hidden="true"></i> Hub Visitors</a>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Active Members</span>
            <span class="stat-value"><?php echo $stats['active_members']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Expiring Soon (30 days)</span>
            <span class="stat-value"><?php echo $stats['expiring_soon']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Today's Bookings</span>
            <span class="stat-value"><?php echo $stats['today_bookings']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Bookings</span>
            <span class="stat-value"><?php echo $stats['pending_bookings']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-receipt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Unverified Receipts</span>
            <span class="stat-value"><?php echo $stats['pending_receipts']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-comments" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">New Feedback</span>
            <span class="stat-value"><?php echo $stats['new_feedback']; ?></span>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="tab-shell">
        <div class="tab-nav">
            <button type="button" class="tab-btn <?php echo $active_tab == 'members' ? 'active' : ''; ?>" 
                    onclick="window.location.href='hub-operations?tab=members'">
                <i class="fas fa-users"></i> Members
                <?php if ($stats['expiring_soon'] > 0): ?>
                    <span class="badge badge-danger ho-tab-count"><?php echo $stats['expiring_soon']; ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="tab-btn <?php echo $active_tab == 'bookings' ? 'active' : ''; ?>" 
                    onclick="window.location.href='hub-operations?tab=bookings'">
                <i class="fas fa-calendar-alt"></i> Bookings
                <?php if ($stats['pending_bookings'] > 0): ?>
                    <span class="badge badge-danger ho-tab-count"><?php echo $stats['pending_bookings']; ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="tab-btn <?php echo $active_tab == 'receipts' ? 'active' : ''; ?>" 
                    onclick="window.location.href='hub-operations?tab=receipts'">
                <i class="fas fa-receipt"></i> Receipts
                <?php if ($stats['pending_receipts'] > 0): ?>
                    <span class="badge badge-danger ho-tab-count"><?php echo $stats['pending_receipts']; ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="tab-btn <?php echo $active_tab == 'feedback' ? 'active' : ''; ?>" 
                    onclick="window.location.href='hub-operations?tab=feedback'">
                <i class="fas fa-comment-dots"></i> Feedback
                <?php if ($stats['new_feedback'] > 0): ?>
                    <span class="badge badge-danger ho-tab-count"><?php echo $stats['new_feedback']; ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="tab-btn <?php echo $active_tab == 'notifications' ? 'active' : ''; ?>" 
                    onclick="window.location.href='hub-operations?tab=notifications'">
                <i class="fas fa-bell"></i> Notifications
            </button>
            <button type="button" class="tab-btn <?php echo $active_tab == 'subscriptions' ? 'active' : ''; ?>" 
                    onclick="window.location.href='hub-operations?tab=subscriptions'">
                <i class="fas fa-dollar"></i> Subscriptions
            </button>
              <!--<button class="tab-btn <?php echo $active_tab == 'analytics' ? 'active' : ''; ?>" 
                    onclick="window.location.href='hub-operations?tab=analytics'">
                <i class="fas fa-chart-bar"></i> Analytics
            </button> -->
        </div>
</div>

<!-- Members Tab -->
<?php if ($active_tab == 'members'): 
    // Fetch members with filters
    $filter = "1=1";
    if (isset($_GET['status'])) {
        $status = $conn->real_escape_string(sanitize_input($_GET['status']));
        $filter .= " AND m.membership_status = '$status'";
    }
    if (isset($_GET['plan'])) {
        $plan = $conn->real_escape_string(sanitize_input($_GET['plan']));
        $filter .= " AND m.subscription_plan = '$plan'";
    }
    if (isset($_GET['search'])) {
        $search = $conn->real_escape_string(sanitize_input($_GET['search']));
        $filter .= " AND (u.full_name LIKE '%$search%' OR m.company_name LIKE '%$search%' OR m.membership_number LIKE '%$search%')";
    }
    
    $members = [];
    $query = "SELECT m.*, u.full_name, u.email 
              FROM members m 
              LEFT JOIN users u ON m.user_id = u.user_id 
              WHERE $filter 
              ORDER BY m.created_at DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-users"></i> Members Management</h3>
        <div>
         <a href="hub-visitors" class="btn btn-secondary">
                <i class="fas fa-users"></i> Hub Visitors
            </a>
            <button onclick="openModal('addMemberModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Member
            </button>
            <button onclick="window.location.href='export?type=members'" class="btn btn-success">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filter Bar -->
        <form method="GET" action="">
            <input type="hidden" name="tab" value="members">
            <div class="filters-bar">
                <div class="form-group filters-grow">
                    <input type="text" name="search" class="form-control" placeholder="Search members..."
                           value="<?php echo isset($_GET['search']) ? hub_e($_GET['search']) : ''; ?>">
                </div>
                <div class="form-group">
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="Active" <?php echo (isset($_GET['status']) && $_GET['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo (isset($_GET['status']) && $_GET['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="Suspended" <?php echo (isset($_GET['status']) && $_GET['status'] == 'Suspended') ? 'selected' : ''; ?>>Suspended</option>
                        <option value="Expired" <?php echo (isset($_GET['status']) && $_GET['status'] == 'Expired') ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="plan" class="form-control">
                        <option value="">All Plans</option>
                        <option value="Daily" <?php echo (isset($_GET['plan']) && $_GET['plan'] == 'Daily') ? 'selected' : ''; ?>>Daily</option>
                        <option value="Weekly" <?php echo (isset($_GET['plan']) && $_GET['plan'] == 'Weekly') ? 'selected' : ''; ?>>Weekly</option>
                        <option value="Monthly" <?php echo (isset($_GET['plan']) && $_GET['plan'] == 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                        <option value="Quarterly" <?php echo (isset($_GET['plan']) && $_GET['plan'] == 'Quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                        <option value="Annual" <?php echo (isset($_GET['plan']) && $_GET['plan'] == 'Annual') ? 'selected' : ''; ?>>Annual</option>
                    </select>
                </div>
                <div class="filters-actions">
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="hub-operations?tab=members" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Members Table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Membership #</th>
                        <th>Type</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="9"><div class="empty-state"><i class="fas fa-users" aria-hidden="true"></i><p>No members found</p></div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($members as $member): 
                            // Check if expiring soon
                            $days_to_expire = 0;
                            if ($member['subscription_end_date']) {
                                $days_to_expire = (strtotime($member['subscription_end_date']) - time()) / (60 * 60 * 24);
                            }
                            $expiring_soon = $days_to_expire <= 30 && $days_to_expire > 0;
                        ?>
                            <tr<?php echo $expiring_soon ? ' class="ho-row-expiring"' : ''; ?>>
                                <td>
                                    <strong><?php echo hub_e($member['full_name'] ?? 'N/A'); ?></strong>
                                    <small class="cell-muted">
                                        <?php echo hub_e($member['email'] ?? ''); ?>
                                    </small>
                                    <?php if ($member['company_name']): ?>
                                        <br><small><i class="fas fa-building"></i> <?php echo hub_e($member['company_name']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($expiring_soon): ?>
                                        <small class="cell-muted ho-expiry-note">
                                            <i class="fas fa-exclamation-triangle"></i> Expires in <?php echo round($days_to_expire); ?> days
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo hub_e($member['membership_number']); ?></code></td>
                                <td>
                                    <span class="badge badge-purple">
                                        <?php echo hub_e($member['member_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo hub_e($member['subscription_plan']); ?></td>
                                <td><?php echo format_currency($member['subscription_amount']); ?></td>
                                <td><?php echo $member['subscription_start_date'] ? date('d M Y', strtotime($member['subscription_start_date'])) : 'N/A'; ?></td>
                                <td><?php echo $member['subscription_end_date'] ? date('d M Y', strtotime($member['subscription_end_date'])) : 'N/A'; ?></td>
                                <td>
                                    <?php
                                    $status_badges = [
                                        'Active' => 'success',
                                        'Inactive' => 'secondary',
                                        'Suspended' => 'warning',
                                        'Expired' => 'danger',
                                        'Pending' => 'info'
                                    ];
                                    $badge = $status_badges[$member['membership_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $member['membership_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="view-member?id=<?php echo $member['member_id']; ?>" 
                                           class="btn btn-info btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?edit_member=<?php echo $member['member_id']; ?>&tab=members" 
                                           class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($can_open_member_folders): ?>
                                            <a href="member-folder?id=<?php echo (int)$member['member_id']; ?>"
                                               class="btn btn-primary btn-sm" title="Folder: contracts, receipts &amp; documents">
                                                <i class="fas fa-folder-open" aria-hidden="true"></i><span class="sr-only">Folder</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (ims_allowed('members.delete', ['Administrator'], 'Delete members')): ?>
                                            <a href="javascript:void(0)" 
                                               onclick="confirmDelete(<?php echo hub_e(json_encode((string)($member['full_name'] ?? ''))); ?>, 'includes/hub-operations-process.php?delete_member=<?php echo $member['member_id']; ?>&tab=members&csrf_token=<?= urlencode(csrf_token()) ?>')" 
                                               class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bookings Tab -->
<?php if ($active_tab == 'bookings'): 
    // Fetch bookings with filters
    $filter = "1=1";
    if (isset($_GET['booking_status'])) {
        $status = $conn->real_escape_string(sanitize_input($_GET['booking_status']));
        $filter .= " AND sb.booking_status = '$status'";
    }
    if (isset($_GET['booking_date'])) {
        $date = $conn->real_escape_string(sanitize_input($_GET['booking_date']));
        $filter .= " AND sb.booking_date = '$date'";
    }
    if (isset($_GET['space_type'])) {
        $space_type = $conn->real_escape_string(sanitize_input($_GET['space_type']));
        $filter .= " AND sb.space_type = '$space_type'";
    }
    
    $bookings = [];
    $query = "SELECT sb.*, u.full_name as member_name 
              FROM space_bookings sb 
              LEFT JOIN members m ON sb.member_id = m.member_id 
              LEFT JOIN users u ON m.user_id = u.user_id 
              WHERE $filter 
              ORDER BY sb.booking_date DESC, sb.start_time DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-alt"></i> Space Bookings</h3>
        <div>
        <a href="manage-bookings"  class="btn btn-primary">
                <i class="fas fa-calendar-check"></i> Manage Booking
            </a> 
            <button onclick="window.location.href='export?type=bookings'" class="btn btn-success">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filter Bar -->
        <form method="GET" action="">
            <input type="hidden" name="tab" value="bookings">
            <div class="filters-bar">
                <div class="form-group">
                    <input type="date" name="booking_date" class="form-control" 
                           value="<?php echo isset($_GET['booking_date']) ? hub_e($_GET['booking_date']) : ''; ?>">
                </div>
                <div class="form-group">
                    <select name="space_type" class="form-control">
                        <option value="">All Space Types</option>
                        <option value="Boardroom" <?php echo (isset($_GET['space_type']) && $_GET['space_type'] == 'Boardroom') ? 'selected' : ''; ?>>Boardroom</option>
                        <option value="Meeting Room" <?php echo (isset($_GET['space_type']) && $_GET['space_type'] == 'Meeting Room') ? 'selected' : ''; ?>>Meeting Room</option>
                        <option value="Event Space" <?php echo (isset($_GET['space_type']) && $_GET['space_type'] == 'Event Space') ? 'selected' : ''; ?>>Event Space</option>
                        <option value="Private Office" <?php echo (isset($_GET['space_type']) && $_GET['space_type'] == 'Private Office') ? 'selected' : ''; ?>>Private Office</option>
                        <option value="Hot Desk" <?php echo (isset($_GET['space_type']) && $_GET['space_type'] == 'Hot Desk') ? 'selected' : ''; ?>>Hot Desk</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="booking_status" class="form-control">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo (isset($_GET['booking_status']) && $_GET['booking_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Confirmed" <?php echo (isset($_GET['booking_status']) && $_GET['booking_status'] == 'Confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="Completed" <?php echo (isset($_GET['booking_status']) && $_GET['booking_status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo (isset($_GET['booking_status']) && $_GET['booking_status'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filters-actions">
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="hub-operations?tab=bookings" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Bookings Table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Space</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Duration</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="9"><div class="empty-state"><i class="fas fa-calendar-alt" aria-hidden="true"></i><p>No bookings found</p></div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td>
                                    <strong><?php echo hub_e($booking['member_name'] ?? 'N/A'); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo hub_e($booking['space_type']); ?></span><br>
                                    <small><?php echo hub_e($booking['space_name']); ?></small>
                                </td>
                                <td><?php echo date('d M Y', strtotime($booking['booking_date'])); ?></td>
                                <td>
                                    <?php echo date('h:i A', strtotime($booking['start_time'])); ?> - 
                                    <?php echo date('h:i A', strtotime($booking['end_time'])); ?>
                                </td>
                                <td><?php echo $booking['duration_hours']; ?> hrs</td>
                                <td><?php echo format_currency($booking['booking_amount']); ?></td>
                                <td>
                                    <?php
                                    $payment_badges = [
                                        'Paid' => 'success',
                                        'Pending' => 'warning',
                                        'Partially Paid' => 'info',
                                        'Cancelled' => 'secondary',
                                        'Refunded' => 'danger'
                                    ];
                                    $badge = $payment_badges[$booking['payment_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $booking['payment_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $booking_badges = [
                                        'Pending' => 'warning',
                                        'Confirmed' => 'success',
                                        'Cancelled' => 'danger',
                                        'Completed' => 'info',
                                        'No Show' => 'secondary'
                                    ];
                                    $badge = $booking_badges[$booking['booking_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $booking['booking_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="view-booking?id=<?php echo $booking['booking_id']; ?>" 
                                           class="btn btn-info btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($booking['booking_status'] == 'Pending'): ?>
                                            <a href="includes/hub-operations-process.php?confirm_booking=<?php echo $booking['booking_id']; ?>&tab=bookings" 
                                               class="btn btn-success btn-sm" title="Confirm">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="javascript:void(0)" 
                                               onclick="confirmDelete('this booking', 'includes/hub-operations-process.php?cancel_booking=<?php echo $booking['booking_id']; ?>&tab=bookings')" 
                                               class="btn btn-danger btn-sm" title="Cancel">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Receipts Tab -->
<?php if ($active_tab == 'receipts'): 
    // Fetch receipts with filters
    $filter = "1=1";
    if (isset($_GET['receipt_status'])) {
        $status = $conn->real_escape_string(sanitize_input($_GET['receipt_status']));
        $filter .= " AND pr.verification_status = '$status'";
    }
    if (isset($_GET['receipt_type'])) {
        $type = $conn->real_escape_string(sanitize_input($_GET['receipt_type']));
        $filter .= " AND pr.receipt_type = '$type'";
    }
    
    $receipts = [];
    $query = "SELECT pr.*, u.full_name as member_name, v.full_name as verified_by_name 
              FROM payment_receipts pr 
              LEFT JOIN members m ON pr.member_id = m.member_id 
              LEFT JOIN users u ON m.user_id = u.user_id 
              LEFT JOIN users v ON pr.verified_by = v.user_id 
              WHERE $filter 
              ORDER BY pr.created_at DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $receipts[] = $row;
    }
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-receipt"></i> Payment Receipts</h3>
        <div>
            <button onclick="openModal('addReceiptModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Receipt
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- REST OF RECEIPTS TAB CODE -->
        <!-- Filter Bar -->
        <form method="GET" action="">
            <input type="hidden" name="tab" value="receipts">
            <div class="filters-bar">
                <div class="form-group">
                    <select name="receipt_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="Subscription" <?php echo (isset($_GET['receipt_type']) && $_GET['receipt_type'] == 'Subscription') ? 'selected' : ''; ?>>Subscription</option>
                        <option value="Space Booking" <?php echo (isset($_GET['receipt_type']) && $_GET['receipt_type'] == 'Space Booking') ? 'selected' : ''; ?>>Space Booking</option>
                        <option value="Event Registration" <?php echo (isset($_GET['receipt_type']) && $_GET['receipt_type'] == 'Event Registration') ? 'selected' : ''; ?>>Event Registration</option>
                        <option value="Other" <?php echo (isset($_GET['receipt_type']) && $_GET['receipt_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="receipt_status" class="form-control">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo (isset($_GET['receipt_status']) && $_GET['receipt_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Verified" <?php echo (isset($_GET['receipt_status']) && $_GET['receipt_status'] == 'Verified') ? 'selected' : ''; ?>>Verified</option>
                        <option value="Rejected" <?php echo (isset($_GET['receipt_status']) && $_GET['receipt_status'] == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="filters-actions">
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="hub-operations?tab=receipts" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Receipts Table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Receipt Type</th>
                        <th>Receipt #</th>
                        <th>Amount</th>
                        <th>Payment Date</th>
                        <th>Method</th>
                        <th>Uploaded</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($receipts)): ?>
                        <tr>
                            <td colspan="9"><div class="empty-state"><i class="fas fa-receipt" aria-hidden="true"></i><p>No receipts found</p></div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($receipts as $receipt): ?>
                            <tr>
                                <td><strong><?php echo hub_e($receipt['member_name'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <span class="badge badge-purple">
                                        <?php echo hub_e($receipt['receipt_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo hub_e($receipt['receipt_number'] ?? 'N/A'); ?></td>
                                <td><?php echo format_currency($receipt['amount']); ?></td>
                                <td><?php echo $receipt['payment_date'] ? date('d M Y', strtotime($receipt['payment_date'])) : 'N/A'; ?></td>
                                <td><?php echo hub_e($receipt['payment_method'] ?? 'N/A'); ?></td>
                                <td><?php echo date('d M Y', strtotime($receipt['created_at'])); ?></td>
                                <td>
                                    <?php
                                    $status_badges = [
                                        'Pending' => 'warning',
                                        'Verified' => 'success',
                                        'Rejected' => 'danger'
                                    ];
                                    $badge = $status_badges[$receipt['verification_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $receipt['verification_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo hub_e(ims_upload_url($receipt['receipt_file_path'])); ?>" 
                                           target="_blank" class="btn btn-info btn-sm" title="View Receipt">
                                            <i class="fas fa-file"></i>
                                        </a>
                                        <?php if ($receipt['verification_status'] == 'Pending'): ?>
                                            <a href="includes/hub-operations-process.php?verify_receipt=<?php echo $receipt['receipt_id']; ?>&tab=receipts&amp;csrf_token=<?= urlencode(csrf_token()) ?>" 
                                               class="btn btn-success btn-sm" title="Verify">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="javascript:void(0)" 
                                               onclick="rejectReceipt(<?php echo $receipt['receipt_id']; ?>)" 
                                               class="btn btn-danger btn-sm" title="Reject">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        
                                        <?php if (ims_allowed('hub_operations.edit_receipts', ['Administrator'], 'Edit payment receipts')): ?>
         <a href="?edit_receipt=<?php echo $receipt['receipt_id']; ?>&tab=receipts" 
            class="btn btn-warning btn-sm" title="Edit">
             <i class="fas fa-edit"></i>
         </a>
     <?php endif; ?>
     <?php if (ims_allowed('hub_operations.delete_receipts', ['Administrator'], 'Delete payment receipts')): ?>
         <a href="javascript:void(0)" 
            onclick="confirmDelete('this receipt', 'includes/hub-operations-process.php?delete_receipt=<?php echo $receipt['receipt_id']; ?>&tab=receipts&csrf_token=<?= urlencode(csrf_token()) ?>')" 
            class="btn btn-danger btn-sm" title="Delete">
             <i class="fas fa-trash"></i>
        </a>
     <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Feedback Tab -->
<?php if ($active_tab == 'feedback'): 
    // Fetch feedback with filters
    $filter = "1=1";
    if (isset($_GET['feedback_status'])) {
        $status = $conn->real_escape_string(sanitize_input($_GET['feedback_status']));
        $filter .= " AND mf.feedback_status = '$status'";
    }
    if (isset($_GET['feedback_type'])) {
        $type = $conn->real_escape_string(sanitize_input($_GET['feedback_type']));
        $filter .= " AND mf.feedback_type = '$type'";
    }
    
    $feedbacks = [];
    $query = "SELECT mf.*, u.full_name as member_name, r.full_name as responded_by_name 
              FROM member_feedback mf 
              LEFT JOIN members m ON mf.member_id = m.member_id 
              LEFT JOIN users u ON m.user_id = u.user_id 
              LEFT JOIN users r ON mf.responded_by = r.user_id 
              WHERE $filter 
              ORDER BY mf.created_at DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $feedbacks[] = $row;
    }
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-comment-dots"></i> Member Feedback</h3>
    </div>
    
    <div class="card-body">
        <!-- Filter Bar -->
        <form method="GET" action="">
            <input type="hidden" name="tab" value="feedback">
            <div class="filters-bar">
                <div class="form-group">
                    <select name="feedback_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="General" <?php echo (isset($_GET['feedback_type']) && $_GET['feedback_type'] == 'General') ? 'selected' : ''; ?>>General</option>
                        <option value="Facilities" <?php echo (isset($_GET['feedback_type']) && $_GET['feedback_type'] == 'Facilities') ? 'selected' : ''; ?>>Facilities</option>
                        <option value="Services" <?php echo (isset($_GET['feedback_type']) && $_GET['feedback_type'] == 'Services') ? 'selected' : ''; ?>>Services</option>
                        <option value="Events" <?php echo (isset($_GET['feedback_type']) && $_GET['feedback_type'] == 'Events') ? 'selected' : ''; ?>>Events</option>
                        <option value="Complaint" <?php echo (isset($_GET['feedback_type']) && $_GET['feedback_type'] == 'Complaint') ? 'selected' : ''; ?>>Complaint</option>
                        <option value="Suggestion" <?php echo (isset($_GET['feedback_type']) && $_GET['feedback_type'] == 'Suggestion') ? 'selected' : ''; ?>>Suggestion</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="feedback_status" class="form-control">
                        <option value="">All Status</option>
                        <option value="New" <?php echo (isset($_GET['feedback_status']) && $_GET['feedback_status'] == 'New') ? 'selected' : ''; ?>>New</option>
                        <option value="In Review" <?php echo (isset($_GET['feedback_status']) && $_GET['feedback_status'] == 'In Review') ? 'selected' : ''; ?>>In Review</option>
                        <option value="Responded" <?php echo (isset($_GET['feedback_status']) && $_GET['feedback_status'] == 'Responded') ? 'selected' : ''; ?>>Responded</option>
                        <option value="Resolved" <?php echo (isset($_GET['feedback_status']) && $_GET['feedback_status'] == 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                        <option value="Closed" <?php echo (isset($_GET['feedback_status']) && $_GET['feedback_status'] == 'Closed') ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                <div class="filters-actions">
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="hub-operations?tab=feedback" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Feedback Table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Type</th>
                        <th>Subject</th>
                        <th>Rating</th>
                        <th>Priority</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feedbacks)): ?>
                        <tr>
                            <td colspan="8"><div class="empty-state"><i class="fas fa-comment-dots" aria-hidden="true"></i><p>No feedback found</p></div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($feedbacks as $feedback): ?>
                            <tr>
                                <td>
                                    <?php if ($feedback['is_anonymous']): ?>
                                        <em class="cell-muted">Anonymous</em>
                                    <?php else: ?>
                                        <strong><?php echo hub_e($feedback['member_name'] ?? 'N/A'); ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo hub_e($feedback['feedback_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo hub_e($feedback['subject']); ?></strong>
                                    <small class="cell-muted">
                                        <?php echo hub_e(substr((string) ($feedback['feedback_message'] ?? ''), 0, 60)); ?>...
                                    </small>
                                </td>
                                <td>
                                    <?php if ($feedback['rating']): ?>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star ho-star<?php echo $i <= $feedback['rating'] ? ' is-on' : ''; ?>" aria-hidden="true"></i>
                                        <?php endfor; ?>
                                    <?php else: ?>
                                        <span class="cell-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $priority_badges = [
                                        'Low' => 'secondary',
                                        'Medium' => 'info',
                                        'High' => 'warning',
                                        'Urgent' => 'danger'
                                    ];
                                    $badge = $priority_badges[$feedback['priority']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $feedback['priority']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y', strtotime($feedback['created_at'])); ?></td>
                                <td>
                                    <?php
                                    $status_badges = [
                                        'New' => 'purple',
                                        'In Review' => 'info',
                                        'Responded' => 'warning',
                                        'Resolved' => 'success',
                                        'Closed' => 'secondary'
                                    ];
                                    $badge = $status_badges[$feedback['feedback_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $feedback['feedback_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>" 
                                           class="btn btn-info btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($feedback['feedback_status'] != 'Closed'): ?>
                                            <a href="respond-feedback?id=<?php echo $feedback['feedback_id']; ?>" 
                                               class="btn btn-success btn-sm" title="Respond">
                                                <i class="fas fa-reply"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Notifications Tab -->
<?php if ($active_tab == 'notifications'): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bell"></i> Send Notifications</h3>
    </div>
    
    <div class="card-body">
        <form method="POST" action="includes/hub-operations-process.php">
            <div class="form-group">
                <label for="notification_type" class="required">Notification Type</label>
                <select id="notification_type" name="notification_type" class="form-control" required>
                    <option value="General">General Announcement</option>
                    <option value="Payment Due">Payment Reminder</option>
                    <option value="Event Reminder">Event Reminder</option>
                    <option value="Subscription Expiry">Subscription Expiry Notice</option>
                    <option value="Booking Confirmed">Booking Confirmation</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="recipient_type" class="required">Send To</label>
                <select id="recipient_type" name="recipient_type" class="form-control" required>
                    <option value="all">All Members</option>
                    <option value="active">Active Members Only</option>
                    <option value="expiring">Members Expiring Soon</option>
                    <option value="specific">Specific Member</option>
                </select>
            </div>
            
            <div class="form-group" id="specificMemberField" style="display: none;">
                <label for="specific_member">Select Member</label>
                <select id="specific_member" name="specific_member" class="form-control">
                    <option value="">Select Member...</option>
                    <?php
                    $members_list = $conn->query("SELECT m.member_id, u.full_name FROM members m LEFT JOIN users u ON m.user_id = u.user_id ORDER BY u.full_name");
                    while ($m = $members_list->fetch_assoc()):
                    ?>
                        <option value="<?php echo $m['member_id']; ?>">
                            <?php echo hub_e($m['full_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="notification_title" class="required">Title</label>
                <input type="text" id="notification_title" name="notification_title" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="notification_message" class="required">Message</label>
                <textarea id="notification_message" name="notification_message" class="form-control" rows="5" required></textarea>
            </div>
            
            <div class="form-group">
                <label for="notification_link">Link URL (optional)</label>
                <input type="url" id="notification_link" name="notification_link" class="form-control" 
                       placeholder="https://...">
            </div>
            
            <button type="submit" name="send_notification" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send Notification
            </button>
        </form>
        
        <!-- Recent Notifications -->
        <h4 class="ho-subhead"><i class="fas fa-history" aria-hidden="true"></i> Recent Notifications</h4>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Recipients</th>
                        <th>Sent</th>
                        <th>Read</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $recent_notifications = [];
                    $query = "SELECT notification_type, title, COUNT(*) as total, 
                              SUM(is_read) as read_count, MAX(created_at) as sent_at 
                              FROM member_notifications 
                              GROUP BY notification_type, title 
                              ORDER BY sent_at DESC 
                              LIMIT 10";
                    $result = $conn->query($query);
                    while ($row = $result->fetch_assoc()) {
                        $recent_notifications[] = $row;
                    }
                    
                    if (empty($recent_notifications)):
                    ?>
                        <tr>
                            <td colspan="5"><div class="empty-state"><i class="fas fa-bell" aria-hidden="true"></i><p>No notifications sent yet</p></div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_notifications as $notif): ?>
                            <tr>
                                <td><span class="badge badge-purple"><?php echo hub_e($notif['notification_type']); ?></span></td>
                                <td><?php echo hub_e($notif['title']); ?></td>
                                <td><?php echo $notif['total']; ?> members</td>
                                <td><?php echo date('d M Y, h:i A', strtotime($notif['sent_at'])); ?></td>
                                <td><?php echo $notif['read_count']; ?> / <?php echo $notif['total']; ?> read</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Analytics Tab -->
<?php if ($active_tab == 'analytics'): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-chart-bar"></i> Hub Analytics</h3>
    </div>
    
    <div class="card-body">
        <div class="ho-chart-grid">
            <!-- Member Types Chart -->
            <div>
                <h4 class="ho-chart-title">Member Types Distribution</h4>
                <canvas id="memberTypesChart" class="ho-chart"></canvas>
            </div>
            
            <!-- Subscription Plans Chart -->
            <div>
                <h4 class="ho-chart-title">Subscription Plans</h4>
                <canvas id="subscriptionPlansChart" class="ho-chart"></canvas>
            </div>
        </div>
        
        <div class="ho-chart-grid">
            <!-- Booking Status Chart -->
            <div>
                <h4 class="ho-chart-title">Bookings Status</h4>
                <canvas id="bookingStatusChart" class="ho-chart"></canvas>
            </div>
            
            <!-- Space Types Chart -->
            <div>
                <h4 class="ho-chart-title">Popular Space Types</h4>
                <canvas id="spaceTypesChart" class="ho-chart"></canvas>
            </div>
        </div>
        
        <!-- Revenue Trend -->
        <div class="ho-chart-grid">
            <h4 class="ho-chart-title">Monthly Revenue Trend</h4>
            <canvas id="revenueTrendChart" class="ho-chart"></canvas>
        </div>
    </div>
</div> 

<script>
// Fetch data for charts
const hoToken = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const hoPalette = ['--blue-fg', '--green-fg', '--brand-500', '--amber-fg', '--slate-fg'].map(hoToken);
<?php
// Member types
$member_types = [];
$result = $conn->query("SELECT member_type, COUNT(*) as count FROM members GROUP BY member_type");
while ($row = $result->fetch_assoc()) {
    $member_types[$row['member_type']] = $row['count'];
}

// Subscription plans
$subscription_plans = [];
$result = $conn->query("SELECT subscription_plan, COUNT(*) as count FROM members WHERE membership_status = 'Active' GROUP BY subscription_plan");
while ($row = $result->fetch_assoc()) {
    $subscription_plans[$row['subscription_plan']] = $row['count'];
}

// Booking status
$booking_status = [];
$result = $conn->query("SELECT booking_status, COUNT(*) as count FROM space_bookings GROUP BY booking_status");
while ($row = $result->fetch_assoc()) {
    $booking_status[$row['booking_status']] = $row['count'];
}

// Space types
$space_types = [];
$result = $conn->query("SELECT space_type, COUNT(*) as count FROM space_bookings GROUP BY space_type");
while ($row = $result->fetch_assoc()) {
    $space_types[$row['space_type']] = $row['count'];
}

// Revenue trend (last 6 months)
$revenue_trend = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $result = $conn->query("SELECT SUM(subscription_amount) as total FROM members 
                           WHERE DATE_FORMAT(subscription_start_date, '%Y-%m') <= '$month' 
                           AND (subscription_end_date IS NULL OR DATE_FORMAT(subscription_end_date, '%Y-%m') >= '$month')
                           AND membership_status = 'Active'");
    $revenue_trend[$month] = $result->fetch_assoc()['total'] ?? 0;
}
?>

// Member Types Chart
const memberTypesCtx = document.getElementById('memberTypesChart');
new Chart(memberTypesCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($member_types)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_values($member_types)); ?>,
            backgroundColor: hoPalette
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Subscription Plans Chart
const subscriptionPlansCtx = document.getElementById('subscriptionPlansChart');
new Chart(subscriptionPlansCtx, {
    type: 'pie',
    data: {
        labels: <?php echo json_encode(array_keys($subscription_plans)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_values($subscription_plans)); ?>,
            backgroundColor: hoPalette
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Booking Status Chart
const bookingStatusCtx = document.getElementById('bookingStatusChart');
new Chart(bookingStatusCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($booking_status)); ?>,
        datasets: [{
            label: 'Number of Bookings',
            data: <?php echo json_encode(array_values($booking_status)); ?>,
            backgroundColor: hoToken('--brand-500')
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Space Types Chart
const spaceTypesCtx = document.getElementById('spaceTypesChart');
new Chart(spaceTypesCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($space_types)); ?>,
        datasets: [{
            label: 'Number of Bookings',
            data: <?php echo json_encode(array_values($space_types)); ?>,
            backgroundColor: hoToken('--green-fg')
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Revenue Trend Chart
const revenueTrendCtx = document.getElementById('revenueTrendChart');
new Chart(revenueTrendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_keys($revenue_trend)); ?>,
        datasets: [{
            label: 'Monthly Revenue (UGX)',
            data: <?php echo json_encode(array_values($revenue_trend)); ?>,
            borderColor: hoToken('--blue-fg'),
            backgroundColor: hoToken('--blue-bg'),
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>
<?php endif; ?>
<?php if ($active_tab == 'subscriptions'): 
    // Fetch subscription payments with filters
    $filter = "1=1";
    if (isset($_GET['payment_status'])) {
        $status = $conn->real_escape_string(sanitize_input($_GET['payment_status']));
        $filter .= " AND sp.payment_status = '$status'";
    }
    if (isset($_GET['subscription_plan'])) {
        $plan = $conn->real_escape_string(sanitize_input($_GET['subscription_plan']));
        $filter .= " AND sp.subscription_plan = '$plan'";
    }
    
    // Get subscription for editing
    $edit_subscription = null;
    if (isset($_GET['edit_subscription'])) {
        $payment_id = intval($_GET['edit_subscription']);
        $result = $conn->query("SELECT sp.*, u.full_name as member_name 
                               FROM subscription_payments sp 
                               LEFT JOIN members m ON sp.member_id = m.member_id 
                               LEFT JOIN users u ON m.user_id = u.user_id 
                               WHERE sp.payment_id = $payment_id");
        $edit_subscription = $result->fetch_assoc();
    }
    
    $subscriptions = [];
    $query = "SELECT sp.*, m.membership_number, u.full_name as member_name 
              FROM subscription_payments sp 
              LEFT JOIN members m ON sp.member_id = m.member_id 
              LEFT JOIN users u ON m.user_id = u.user_id 
              WHERE $filter 
              ORDER BY sp.created_at DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $subscriptions[] = $row;
    }
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-credit-card"></i> Subscription Payments</h3>
        <div>
        <a href="manage-space"  class="btn btn-secondary">
                <i class="fas fa-building"></i> Manage Spaces
            </a>
            <button onclick="openModal('addSubscriptionModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Payment
            </button>
            <button onclick="window.location.href='export?type=subscriptions'" class="btn btn-success">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filter Bar -->
        <form method="GET" action="">
            <input type="hidden" name="tab" value="subscriptions">
            <div class="filters-bar">
                <div class="form-group">
                    <select name="subscription_plan" class="form-control">
                        <option value="">All Plans</option>
                        <option value="Daily" <?php echo (isset($_GET['subscription_plan']) && $_GET['subscription_plan'] == 'Daily') ? 'selected' : ''; ?>>Daily</option>
                        <option value="Weekly" <?php echo (isset($_GET['subscription_plan']) && $_GET['subscription_plan'] == 'Weekly') ? 'selected' : ''; ?>>Weekly</option>
                        <option value="Monthly" <?php echo (isset($_GET['subscription_plan']) && $_GET['subscription_plan'] == 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                        <option value="Quarterly" <?php echo (isset($_GET['subscription_plan']) && $_GET['subscription_plan'] == 'Quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                        <option value="Annual" <?php echo (isset($_GET['subscription_plan']) && $_GET['subscription_plan'] == 'Annual') ? 'selected' : ''; ?>>Annual</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="payment_status" class="form-control">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Paid" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] == 'Paid') ? 'selected' : ''; ?>>Paid</option>
                        <option value="Partially Paid" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] == 'Partially Paid') ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="Overdue" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] == 'Overdue') ? 'selected' : ''; ?>>Overdue</option>
                    </select>
                </div>
                <div class="filters-actions">
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="hub-operations?tab=subscriptions" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Subscriptions Table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Invoice #</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Period</th>
                        <th>Payment Date</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscriptions)): ?>
                        <tr>
                            <td colspan="9"><div class="empty-state"><i class="fas fa-credit-card" aria-hidden="true"></i><p>No subscription payments found</p></div></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subscriptions as $sub): ?>
                            <tr>
                                <td>
                                    <strong><?php echo hub_e($sub['member_name'] ?? 'N/A'); ?></strong>
                                    <small class="cell-muted"><?php echo hub_e($sub['membership_number']); ?></small>
                                </td>
                                <td><code><?php echo hub_e($sub['invoice_number']); ?></code></td>
                                <td>
                                    <span class="badge badge-purple">
                                        <?php echo hub_e($sub['subscription_plan']); ?>
                                    </span>
                                </td>
                                <td><?php echo format_currency($sub['amount']); ?></td>
                                <td>
                                    <?php echo date('d M', strtotime($sub['payment_period_start'])); ?> - 
                                    <?php echo date('d M Y', strtotime($sub['payment_period_end'])); ?>
                                </td>
                                <td><?php echo $sub['payment_date'] ? date('d M Y', strtotime($sub['payment_date'])) : '-'; ?></td>
                                <td><?php echo hub_e($sub['payment_method'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                    $status_badges = [
                                        'Pending' => 'warning',
                                        'Paid' => 'success',
                                        'Partially Paid' => 'info',
                                        'Overdue' => 'danger',
                                        'Cancelled' => 'secondary'
                                    ];
                                    $badge = $status_badges[$sub['payment_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $sub['payment_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?edit_subscription=<?php echo $sub['payment_id']; ?>&tab=subscriptions" 
                                           class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (ims_allowed('subscriptions.delete', ['Administrator'], 'Delete subscription payments')): ?>
                                            <a href="javascript:void(0)" 
                                               onclick="confirmDelete('this payment', 'includes/hub-operations-process.php?delete_subscription=<?php echo $sub['payment_id']; ?>&tab=subscriptions&csrf_token=<?= urlencode(csrf_token()) ?>')" 
                                               class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /.page-stack -->

<!-- Add Member Modal -->
<div id="addMemberModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3><?php echo $edit_member ? 'Edit Member' : 'Add New Member'; ?></h3>
            <span class="close" onclick="closeModal('addMemberModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/hub-operations-process.php">
                <?php if ($edit_member): ?>
                    <input type="hidden" name="member_id" value="<?php echo $edit_member['member_id']; ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name" class="required">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" 
                               value="<?php echo $edit_member ? hub_e($edit_member['full_name']) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="required">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?php echo $edit_member ? hub_e($edit_member['user_email']) : ''; ?>"
                               required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control"
                               value="<?php echo $edit_member ? hub_e($edit_member['emergency_contact']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="member_type" class="required">Member Type</label>
                        <select id="member_type" name="member_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="Individual" <?php echo ($edit_member && $edit_member['member_type'] == 'Individual') ? 'selected' : ''; ?>>Individual</option>
                            <option value="Startup" <?php echo ($edit_member && $edit_member['member_type'] == 'Startup') ? 'selected' : ''; ?>>Startup</option>
                            <option value="Corporate" <?php echo ($edit_member && $edit_member['member_type'] == 'Corporate') ? 'selected' : ''; ?>>Corporate</option>
                            <option value="Student" <?php echo ($edit_member && $edit_member['member_type'] == 'Student') ? 'selected' : ''; ?>>Student</option>
                            <option value="Freelancer" <?php echo ($edit_member && $edit_member['member_type'] == 'Freelancer') ? 'selected' : ''; ?>>Freelancer</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="company_name">Company/Organization</label>
                        <input type="text" id="company_name" name="company_name" class="form-control"
                               value="<?php echo $edit_member ? hub_e($edit_member['company_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="industry">Industry</label>
                        <input type="text" id="industry" name="industry" class="form-control"
                               value="<?php echo $edit_member ? hub_e($edit_member['industry']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="subscription_plan" class="required">Subscription Plan</label>
                        <select id="subscription_plan" name="subscription_plan" class="form-control" required>
                            <option value="">Select Plan</option>
                            <option value="Daily" <?php echo ($edit_member && $edit_member['subscription_plan'] == 'Daily') ? 'selected' : ''; ?>>Daily</option>
                            <option value="Weekly" <?php echo ($edit_member && $edit_member['subscription_plan'] == 'Weekly') ? 'selected' : ''; ?>>Weekly</option>
                            <option value="Monthly" <?php echo ($edit_member && $edit_member['subscription_plan'] == 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                            <option value="Quarterly" <?php echo ($edit_member && $edit_member['subscription_plan'] == 'Quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                            <option value="Annual" <?php echo ($edit_member && $edit_member['subscription_plan'] == 'Annual') ? 'selected' : ''; ?>>Annual</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="subscription_amount" class="required">Monthly Amount (UGX)</label>
                        <input type="number" id="subscription_amount" name="subscription_amount" class="form-control" 
                               step="1000" min="0"
                               value="<?php echo $edit_member ? $edit_member['subscription_amount'] : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="subscription_start_date" class="required">Start Date</label>
                        <input type="date" id="subscription_start_date" name="subscription_start_date" class="form-control" 
                               value="<?php echo $edit_member ? $edit_member['subscription_start_date'] : date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="subscription_end_date" class="required">End Date</label>
                        <input type="date" id="subscription_end_date" name="subscription_end_date" class="form-control"
                               value="<?php echo $edit_member ? $edit_member['subscription_end_date'] : date('Y-m-d', strtotime('+1 month')); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="membership_status" class="required">Status</label>
                    <select id="membership_status" name="membership_status" class="form-control" required>
                        <option value="Active" <?php echo ($edit_member && $edit_member['membership_status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo ($edit_member && $edit_member['membership_status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="Suspended" <?php echo ($edit_member && $edit_member['membership_status'] == 'Suspended') ? 'selected' : ''; ?>>Suspended</option>
                        <option value="Expired" <?php echo ($edit_member && $edit_member['membership_status'] == 'Expired') ? 'selected' : ''; ?>>Expired</option>
                        <option value="Pending" <?php echo ($edit_member && $edit_member['membership_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addMemberModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="<?php echo $edit_member ? 'edit_member' : 'add_member'; ?>" class="btn btn-success">
                        <i class="fas fa-save"></i> <?php echo $edit_member ? 'Update' : 'Add'; ?> Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Reject Receipt Modal -->
<div id="rejectReceiptModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reject Receipt</h3>
            <span class="close" onclick="closeModal('rejectReceiptModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/hub-operations-process.php" id="rejectReceiptForm">
                <input type="hidden" name="receipt_id" id="reject_receipt_id">
                <div class="form-group">
                    <label for="rejection_reason" class="required">Rejection Reason</label>
                    <textarea id="rejection_reason" name="rejection_reason" class="form-control" rows="4" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('rejectReceiptModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="reject_receipt" class="btn btn-danger">
                        <i class="fas fa-times"></i> Reject Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Subscription Payment Modal -->
<div id="addSubscriptionModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><?php echo $edit_subscription ? 'Edit Payment' : 'Add Subscription Payment'; ?></h3>
            <span class="close" onclick="closeModal('addSubscriptionModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/hub-operations-process.php">
                <?php if ($edit_subscription): ?>
                    <input type="hidden" name="payment_id" value="<?php echo $edit_subscription['payment_id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="member_id" class="required">Member</label>
                    <select id="member_id" name="member_id" class="form-control" required <?php echo $edit_subscription ? 'disabled' : ''; ?>>
                        <option value="">Select Member</option>
                        <?php
                        $members_list = $conn->query("SELECT m.member_id, m.membership_number, u.full_name 
                                                     FROM members m 
                                                     LEFT JOIN users u ON m.user_id = u.user_id 
                                                     ORDER BY u.full_name");
                        while ($m = $members_list->fetch_assoc()):
                        ?>
                            <option value="<?php echo $m['member_id']; ?>" 
                                    <?php echo ($edit_subscription && $edit_subscription['member_id'] == $m['member_id']) ? 'selected' : ''; ?>>
                                <?php echo hub_e($m['full_name']); ?> (<?php echo $m['membership_number']; ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <?php if ($edit_subscription): ?>
                        <input type="hidden" name="member_id" value="<?php echo $edit_subscription['member_id']; ?>">
                    <?php endif; ?>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="subscription_plan" class="required">Plan</label>
                        <select id="subscription_plan" name="subscription_plan" class="form-control" required>
                            <option value="">Select Plan</option>
                            <option value="Daily" <?php echo ($edit_subscription && $edit_subscription['subscription_plan'] == 'Daily') ? 'selected' : ''; ?>>Daily</option>
                            <option value="Weekly" <?php echo ($edit_subscription && $edit_subscription['subscription_plan'] == 'Weekly') ? 'selected' : ''; ?>>Weekly</option>
                            <option value="Monthly" <?php echo ($edit_subscription && $edit_subscription['subscription_plan'] == 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                            <option value="Quarterly" <?php echo ($edit_subscription && $edit_subscription['subscription_plan'] == 'Quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                            <option value="Annual" <?php echo ($edit_subscription && $edit_subscription['subscription_plan'] == 'Annual') ? 'selected' : ''; ?>>Annual</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="amount" class="required">Amount (UGX)</label>
                        <input type="number" id="amount" name="amount" class="form-control" step="1000" min="0"
                               value="<?php echo $edit_subscription ? $edit_subscription['amount'] : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="payment_period_start" class="required">Period Start</label>
                        <input type="date" id="payment_period_start" name="payment_period_start" class="form-control"
                               value="<?php echo $edit_subscription ? $edit_subscription['payment_period_start'] : date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_period_end" class="required">Period End</label>
                        <input type="date" id="payment_period_end" name="payment_period_end" class="form-control"
                               value="<?php echo $edit_subscription ? $edit_subscription['payment_period_end'] : date('Y-m-d', strtotime('+1 month')); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="payment_date">Payment Date</label>
                        <input type="date" id="payment_date" name="payment_date" class="form-control"
                               value="<?php echo $edit_subscription ? $edit_subscription['payment_date'] : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method" class="form-control">
                            <option value="">Select Method</option>
                            <option value="Cash" <?php echo ($edit_subscription && $edit_subscription['payment_method'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="Mobile Money" <?php echo ($edit_subscription && $edit_subscription['payment_method'] == 'Mobile Money') ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="Bank Transfer" <?php echo ($edit_subscription && $edit_subscription['payment_method'] == 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Card" <?php echo ($edit_subscription && $edit_subscription['payment_method'] == 'Card') ? 'selected' : ''; ?>>Card</option>
                            <option value="Cheque" <?php echo ($edit_subscription && $edit_subscription['payment_method'] == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="payment_reference">Payment Reference</label>
                    <input type="text" id="payment_reference" name="payment_reference" class="form-control"
                           value="<?php echo $edit_subscription ? hub_e($edit_subscription['payment_reference']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="payment_status" class="required">Payment Status</label>
                    <select id="payment_status" name="payment_status" class="form-control" required>
                        <option value="Pending" <?php echo ($edit_subscription && $edit_subscription['payment_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Paid" <?php echo ($edit_subscription && $edit_subscription['payment_status'] == 'Paid') ? 'selected' : ''; ?>>Paid</option>
                        <option value="Partially Paid" <?php echo ($edit_subscription && $edit_subscription['payment_status'] == 'Partially Paid') ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="Overdue" <?php echo ($edit_subscription && $edit_subscription['payment_status'] == 'Overdue') ? 'selected' : ''; ?>>Overdue</option>
                        <option value="Cancelled" <?php echo ($edit_subscription && $edit_subscription['payment_status'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="3"><?php echo $edit_subscription ? hub_e($edit_subscription['notes']) : ''; ?></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addSubscriptionModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="<?php echo $edit_subscription ? 'edit_subscription' : 'add_subscription'; ?>" class="btn btn-success">
                        <i class="fas fa-save"></i> <?php echo $edit_subscription ? 'Update' : 'Add'; ?> Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<div id="addReceiptModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><?php echo $edit_receipt ? 'Edit Receipt' : 'Add Payment Receipt'; ?></h3>
            <span class="close" onclick="closeModal('addReceiptModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/hub-operations-process.php" enctype="multipart/form-data">
                <?php if ($edit_receipt): ?>
                    <input type="hidden" name="receipt_id" value="<?php echo $edit_receipt['receipt_id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="receipt_member_id" class="required">Member</label>
                    <select id="receipt_member_id" name="member_id" class="form-control" required>
                        <option value="">Select Member</option>
                        <?php
                        $members_list = $conn->query("SELECT m.member_id, m.membership_number, u.full_name 
                                                     FROM members m 
                                                     LEFT JOIN users u ON m.user_id = u.user_id 
                                                     ORDER BY u.full_name");
                        while ($m = $members_list->fetch_assoc()):
                        ?>
                            <option value="<?php echo $m['member_id']; ?>" 
                                    <?php echo ($edit_receipt && $edit_receipt['member_id'] == $m['member_id']) ? 'selected' : ''; ?>>
                                <?php echo hub_e($m['full_name']); ?> (<?php echo $m['membership_number']; ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="receipt_type" class="required">Receipt Type</label>
                        <select id="receipt_type" name="receipt_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="Subscription" <?php echo ($edit_receipt && $edit_receipt['receipt_type'] == 'Subscription') ? 'selected' : ''; ?>>Subscription</option>
                            <option value="Space Booking" <?php echo ($edit_receipt && $edit_receipt['receipt_type'] == 'Space Booking') ? 'selected' : ''; ?>>Space Booking</option>
                            <option value="Event Registration" <?php echo ($edit_receipt && $edit_receipt['receipt_type'] == 'Event Registration') ? 'selected' : ''; ?>>Event Registration</option>
                            <option value="Other" <?php echo ($edit_receipt && $edit_receipt['receipt_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="receipt_amount" class="required">Amount (UGX)</label>
                        <input type="number" id="receipt_amount" name="amount" class="form-control" step="1000" min="0"
                               value="<?php echo $edit_receipt ? $edit_receipt['amount'] : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="receipt_number">Receipt Number</label>
                        <input type="text" id="receipt_number" name="receipt_number" class="form-control"
                               value="<?php echo $edit_receipt ? hub_e($edit_receipt['receipt_number']) : ''; ?>"
                               placeholder="Leave blank to auto-generate">
                    </div>
                    
                    <div class="form-group">
                        <label for="receipt_payment_date" class="required">Payment Date</label>
                        <input type="date" id="receipt_payment_date" name="payment_date" class="form-control"
                               value="<?php echo $edit_receipt ? $edit_receipt['payment_date'] : date('Y-m-d'); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="receipt_payment_method" class="required">Payment Method</label>
                        <select id="receipt_payment_method" name="payment_method" class="form-control" required>
                            <option value="">Select Method</option>
                            <option value="Cash" <?php echo ($edit_receipt && $edit_receipt['payment_method'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="Mobile Money" <?php echo ($edit_receipt && $edit_receipt['payment_method'] == 'Mobile Money') ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="Bank Transfer" <?php echo ($edit_receipt && $edit_receipt['payment_method'] == 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Card" <?php echo ($edit_receipt && $edit_receipt['payment_method'] == 'Card') ? 'selected' : ''; ?>>Card</option>
                            <option value="Cheque" <?php echo ($edit_receipt && $edit_receipt['payment_method'] == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="transaction_reference">Transaction Reference</label>
                        <input type="text" id="transaction_reference" name="transaction_reference" class="form-control"
                               value="<?php echo $edit_receipt ? hub_e($edit_receipt['transaction_reference']) : ''; ?>"
                               placeholder="e.g., MM Reference, Check #">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="receipt_file">Receipt File (PDF/Image)</label>
                    <?php if ($edit_receipt && $edit_receipt['receipt_file_path']): ?>
                        <div class="ho-current-file">
                            <small class="cell-muted">
                                Current file: 
                                <a href="<?php echo hub_e(ims_upload_url($edit_receipt['receipt_file_path'])); ?>" target="_blank">
                                    View current receipt
                                </a>
                            </small>
                        </div>
                    <?php endif; ?>
                    <input type="file" id="receipt_file" name="receipt_file" class="form-control" 
                           accept=".pdf,.jpg,.jpeg,.png,.gif">
                    <small class="cell-muted">Upload a scanned copy or photo of the receipt (optional)</small>
                </div>
                
                <div class="form-group">
                    <label for="verification_status" class="required">Verification Status</label>
                    <select id="verification_status" name="verification_status" class="form-control" required>
                        <option value="Pending" <?php echo ($edit_receipt && $edit_receipt['verification_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Verified" <?php echo ($edit_receipt && $edit_receipt['verification_status'] == 'Verified') ? 'selected' : ''; ?>>Verified</option>
                        <option value="Rejected" <?php echo ($edit_receipt && $edit_receipt['verification_status'] == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <small class="cell-muted">Select "Verified" if you're creating a verified receipt</small>
                </div>
                
                <div class="form-group">
                    <label for="receipt_notes">Notes</label>
                    <textarea id="receipt_notes" name="notes" class="form-control" rows="3" 
                              placeholder="Additional information about this payment..."><?php echo $edit_receipt ? hub_e($edit_receipt['notes']) : ''; ?></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addReceiptModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="<?php echo $edit_receipt ? 'edit_receipt' : 'add_receipt'; ?>" class="btn btn-success">
                        <i class="fas fa-save"></i> <?php echo $edit_receipt ? 'Update' : 'Add'; ?> Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<?php include 'includes/footer.php'; ?>
<script>
// Show/hide specific member field - with null check
const recipientType = document.getElementById('recipient_type');
if (recipientType) {
    recipientType.addEventListener('change', function() {
        const specificField = document.getElementById('specificMemberField');
        if (specificField) {
            specificField.style.display = this.value === 'specific' ? 'block' : 'none';
        }
    });
}

// Reject receipt function
function rejectReceipt(receiptId) {
    document.getElementById('reject_receipt_id').value = receiptId;
    openModal('rejectReceiptModal');
}

// Auto-open modals when editing
<?php if ($edit_member): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('addMemberModal');
    });
<?php endif; ?>

<?php if ($edit_subscription): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('addSubscriptionModal');
    });
<?php endif; ?>

<?php if ($edit_receipt): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('addReceiptModal');
    });
<?php endif; ?>
</script>
