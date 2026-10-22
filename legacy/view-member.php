<?php
$page_title = 'Member Details';
include 'includes/header.php';

check_role(['Administrator', 'Operations/Admin']);

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function safe_date($date, string $format = 'M d, Y'): string {
    if (empty($date) || $date === '0000-00-00') {
        return 'N/A';
    }

    $time = strtotime((string)$date);
    return $time ? date($format, $time) : 'N/A';
}

$member_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($member_id <= 0) {
    $_SESSION['error'] = "Invalid member ID.";
    header("Location: hub-operations?tab=members");
    exit();
}

/* =========================
   FETCH MEMBER
========================= */
$stmt = $conn->prepare("
    SELECT 
        m.*, 
        u.full_name, 
        u.email, 
        u.created_at AS user_created_at, 
        u.last_login
    FROM members m
    LEFT JOIN users u ON m.user_id = u.user_id
    WHERE m.member_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $member_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Member not found.";
    header("Location: hub-operations?tab=members");
    exit();
}

$member = $result->fetch_assoc();
$stmt->close();

$memberName = $member['full_name'] ?? 'Member';
$memberEmail = $member['email'] ?? 'N/A';

/* =========================
   SUBSCRIPTION STATUS
========================= */
$days_to_expire = 0;
$is_expired = false;
$is_expiring_soon = false;

if (!empty($member['subscription_end_date'])) {
    $days_to_expire = (strtotime($member['subscription_end_date']) - time()) / (60 * 60 * 24);
    $is_expired = $days_to_expire < 0;
    $is_expiring_soon = $days_to_expire <= 30 && $days_to_expire > 0;
}

/* =========================
   FETCH PAYMENTS
========================= */
$payments = [];
$stmt = $conn->prepare("
    SELECT *
    FROM subscription_payments
    WHERE member_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$payment_result = $stmt->get_result();

while ($row = $payment_result->fetch_assoc()) {
    $payments[] = $row;
}
$stmt->close();

/* =========================
   FETCH RECEIPTS
========================= */
$receipts = [];
$stmt = $conn->prepare("
    SELECT *
    FROM payment_receipts
    WHERE member_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$receipt_result = $stmt->get_result();

while ($row = $receipt_result->fetch_assoc()) {
    $receipts[] = $row;
}
$stmt->close();

/* =========================
   FETCH BOOKINGS
========================= */
$bookings = [];
$stmt = $conn->prepare("
    SELECT *
    FROM space_bookings
    WHERE member_id = ?
    ORDER BY booking_date DESC
    LIMIT 10
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$booking_result = $stmt->get_result();

while ($row = $booking_result->fetch_assoc()) {
    $bookings[] = $row;
}
$stmt->close();

/* =========================
   FETCH FEEDBACK
========================= */
$feedbacks = [];
$stmt = $conn->prepare("
    SELECT *
    FROM member_feedback
    WHERE member_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$feedback_result = $stmt->get_result();

while ($row = $feedback_result->fetch_assoc()) {
    $feedbacks[] = $row;
}
$stmt->close();

/* =========================
   STATISTICS
========================= */
$stats = [
    'total_paid' => 0,
    'total_bookings' => 0,
    'pending_receipts' => 0,
    'feedback_count' => 0,
];

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM subscription_payments
    WHERE member_id = ? AND payment_status = 'Paid'
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$stats['total_paid'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS count
    FROM space_bookings
    WHERE member_id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$stats['total_bookings'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS count
    FROM payment_receipts
    WHERE member_id = ? AND verification_status = 'Pending'
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$stats['pending_receipts'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS count
    FROM member_feedback
    WHERE member_id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$stats['feedback_count'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
$stmt->close();

$status_badges = [
    'Active' => 'success',
    'Inactive' => 'secondary',
    'Suspended' => 'warning',
    'Expired' => 'danger',
    'Pending' => 'info'
];

$membershipStatus = $member['membership_status'] ?? 'Pending';
$badge = $status_badges[$membershipStatus] ?? 'secondary';
?>

<style>
    .member-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, #2980B9 100%);
        color: white;
        padding: 30px;
        border-radius: 12px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }

    .member-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 300px;
        height: 300px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }

    .member-header-content {
        position: relative;
        z-index: 1;
    }

    .member-avatar {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        font-weight: bold;
        color: var(--primary-color);
        margin-bottom: 15px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        flex-shrink: 0;
    }

    .member-name {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .member-number {
        font-size: 16px;
        opacity: 0.9;
        margin-bottom: 15px;
    }

    .member-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }

    .member-info-item {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .member-info-item i {
        font-size: 18px;
        opacity: 0.8;
    }

    .status-alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        font-weight: 500;
    }

    .status-alert.warning {
        background: #FFF3CD;
        color: #856404;
        border-left: 4px solid #F39C12;
    }

    .status-alert.danger {
        background: #F8D7DA;
        color: #721C24;
        border-left: 4px solid #E74C3C;
    }

    .status-alert.success {
        background: #D4EDDA;
        color: #155724;
        border-left: 4px solid #2ECC71;
    }

    .tab-navigation {
        display: flex;
        gap: 0;
        margin-bottom: 20px;
        border-bottom: 2px solid #E5E7EB;
        overflow-x: auto;
    }

    .tab-btn {
        padding: 15px 25px;
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

    .tab-btn:hover {
        color: var(--primary-color);
        background: rgba(52, 152, 219, 0.05);
    }

    .tab-btn.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .info-card {
        background: #F8F9FA;
        padding: 20px;
        border-radius: 8px;
        border-left: 4px solid var(--primary-color);
    }

    .info-label {
        font-size: 12px;
        color: #7f8c8d;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .info-value {
        font-size: 16px;
        font-weight: 600;
        color: #2C3E50;
    }

    .timeline {
        position: relative;
        padding-left: 30px;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #E5E7EB;
    }

    .timeline-item {
        position: relative;
        padding-bottom: 30px;
    }

    .timeline-item::before {
        content: '';
        position: absolute;
        left: -26px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: var(--primary-color);
        border: 3px solid white;
        box-shadow: 0 0 0 2px #E5E7EB;
    }

    .timeline-date {
        font-size: 12px;
        color: #7f8c8d;
        margin-bottom: 5px;
    }

    .timeline-content {
        background: white;
        padding: 15px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .quick-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #95A5A6;
    }

    .empty-state i {
        font-size: 64px;
        margin-bottom: 20px;
        opacity: 0.3;
    }

    .empty-state p {
        font-size: 16px;
        margin: 0;
    }

    @media (max-width: 768px) {
        .member-header-content > div {
            flex-direction: column;
        }

        .member-name {
            font-size: 22px;
        }

        .tab-btn {
            padding: 12px 16px;
            font-size: 14px;
        }
    }
</style>

<div style="margin-bottom: 20px;">
    <a href="hub-operations?tab=members" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Members
    </a>
</div>

<div class="member-header">
    <div class="member-header-content">
        <div style="display: flex; align-items: flex-start; gap: 20px;">
            <div class="member-avatar">
                <?php echo strtoupper(substr((string)$memberName, 0, 1)); ?>
            </div>

            <div style="flex: 1;">
                <div class="member-name"><?php echo h($memberName); ?></div>

                <div class="member-number">
                    <i class="fas fa-id-card"></i>
                    <?php echo h($member['membership_number'] ?? 'N/A'); ?>
                </div>

                <div class="member-info-grid">
                    <div class="member-info-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo h($memberEmail); ?></span>
                    </div>

                    <?php if (!empty($member['emergency_contact'])): ?>
                        <div class="member-info-item">
                            <i class="fas fa-phone"></i>
                            <span><?php echo h($member['emergency_contact']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($member['company_name'])): ?>
                        <div class="member-info-item">
                            <i class="fas fa-building"></i>
                            <span><?php echo h($member['company_name']); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="member-info-item">
                        <i class="fas fa-calendar"></i>
                        <span>Joined <?php echo safe_date($member['registration_date'] ?? null); ?></span>
                    </div>
                </div>
            </div>

            <div>
                <span class="badge badge-<?php echo h($badge); ?>" style="font-size: 14px; padding: 8px 16px;">
                    <?php echo h($membershipStatus); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<?php if ($is_expired): ?>
    <div class="status-alert danger">
        <i class="fas fa-exclamation-circle" style="font-size: 24px;"></i>
        <div>
            <strong>Membership Expired</strong><br>
            <span>
                This membership expired <?php echo abs(round($days_to_expire)); ?> days ago on
                <?php echo safe_date($member['subscription_end_date'] ?? null); ?>
            </span>
        </div>
    </div>
<?php elseif ($is_expiring_soon): ?>
    <div class="status-alert warning">
        <i class="fas fa-exclamation-triangle" style="font-size: 24px;"></i>
        <div>
            <strong>Expiring Soon</strong><br>
            <span>
                This membership will expire in <?php echo round($days_to_expire); ?> days on
                <?php echo safe_date($member['subscription_end_date'] ?? null); ?>
            </span>
        </div>
    </div>
<?php elseif ($membershipStatus === 'Active'): ?>
    <div class="status-alert success">
        <i class="fas fa-check-circle" style="font-size: 24px;"></i>
        <div>
            <strong>Active Membership</strong><br>
            <span>Valid until <?php echo safe_date($member['subscription_end_date'] ?? null); ?></span>
        </div>
    </div>
<?php endif; ?>

<div class="quick-actions">
    <a href="hub-operations?edit_member=<?php echo $member_id; ?>&tab=members" class="btn btn-primary">
        <i class="fas fa-edit"></i> Edit Member
    </a>

    <a href="hub-operations?tab=subscriptions" class="btn btn-success">
        <i class="fas fa-plus"></i> Add Payment
    </a>

    <a href="hub-operations?tab=receipts" class="btn btn-info">
        <i class="fas fa-receipt"></i> Add Receipt
    </a>

    <button onclick="sendNotification(<?php echo $member_id; ?>)" class="btn" style="background: #9B59B6; color: white;">
        <i class="fas fa-bell"></i> Send Notification
    </button>

    <?php if (ims_allowed('members.delete', ['Administrator'], 'Delete members')): ?>
        <button onclick="confirmDelete(<?= htmlspecialchars(json_encode((string)($memberName)), ENT_QUOTES, 'UTF-8') ?>, 'includes/hub-operations-process.php?delete_member=<?php echo $member_id; ?>&tab=members&csrf_token=<?= urlencode(csrf_token()) ?>')"
                class="btn btn-danger">
            <i class="fas fa-trash"></i> Delete Member
        </button>
    <?php endif; ?>
</div>

<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-dollar-sign" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Paid</span>
            <span class="stat-value"><?php echo format_currency($stats['total_paid']); ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Bookings</span>
            <span class="stat-value"><?php echo (int)$stats['total_bookings']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-receipt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Receipts</span>
            <span class="stat-value"><?php echo (int)$stats['pending_receipts']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-comments" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Feedback Submitted</span>
            <span class="stat-value"><?php echo (int)$stats['feedback_count']; ?></span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="tab-navigation">
            <button class="tab-btn active" type="button" onclick="switchTab(event, 'overview')">
                <i class="fas fa-info-circle"></i> Overview
            </button>
            <button class="tab-btn" type="button" onclick="switchTab(event, 'payments')">
                <i class="fas fa-credit-card"></i> Payments (<?php echo count($payments); ?>)
            </button>
            <button class="tab-btn" type="button" onclick="switchTab(event, 'receipts')">
                <i class="fas fa-receipt"></i> Receipts (<?php echo count($receipts); ?>)
            </button>
            <button class="tab-btn" type="button" onclick="switchTab(event, 'bookings')">
                <i class="fas fa-calendar-alt"></i> Bookings (<?php echo count($bookings); ?>)
            </button>
            <button class="tab-btn" type="button" onclick="switchTab(event, 'feedback')">
                <i class="fas fa-comment-dots"></i> Feedback (<?php echo count($feedbacks); ?>)
            </button>
        </div>
    </div>
</div>

<div id="overview-tab" class="tab-content active">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-user"></i> Member Information</h3>
        </div>

        <div class="card-body">
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">Member Type</div>
                    <div class="info-value">
                        <i class="fas fa-user-tag"></i>
                        <?php echo h($member['member_type'] ?? 'N/A'); ?>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-label">Subscription Plan</div>
                    <div class="info-value">
                        <i class="fas fa-calendar"></i>
                        <?php echo h($member['subscription_plan'] ?? 'N/A'); ?>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-label">Subscription Amount</div>
                    <div class="info-value">
                        <i class="fas fa-money-bill"></i>
                        <?php echo format_currency($member['subscription_amount'] ?? 0); ?>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-label">Membership Status</div>
                    <div class="info-value">
                        <span class="badge badge-<?php echo h($badge); ?>">
                            <?php echo h($membershipStatus); ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($member['company_name'])): ?>
                    <div class="info-card">
                        <div class="info-label">Company</div>
                        <div class="info-value">
                            <i class="fas fa-building"></i>
                            <?php echo h($member['company_name']); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($member['industry'])): ?>
                    <div class="info-card">
                        <div class="info-label">Industry</div>
                        <div class="info-value">
                            <i class="fas fa-industry"></i>
                            <?php echo h($member['industry']); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="info-card">
                    <div class="info-label">Start Date</div>
                    <div class="info-value">
                        <i class="fas fa-calendar-plus"></i>
                        <?php echo safe_date($member['subscription_start_date'] ?? null); ?>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-label">End Date</div>
                    <div class="info-value">
                        <i class="fas fa-calendar-times"></i>
                        <?php echo safe_date($member['subscription_end_date'] ?? null); ?>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-label">Registration Date</div>
                    <div class="info-value">
                        <i class="fas fa-user-plus"></i>
                        <?php echo safe_date($member['registration_date'] ?? null); ?>
                    </div>
                </div>

                <?php if (!empty($member['last_login'])): ?>
                    <div class="info-card">
                        <div class="info-label">Last Login</div>
                        <div class="info-value">
                            <i class="fas fa-clock"></i>
                            <?php echo safe_date($member['last_login'], 'M d, Y h:i A'); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="payments-tab" class="tab-content">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-credit-card"></i> Payment History</h3>
            <a href="hub-operations?tab=subscriptions" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Payment
            </a>
        </div>

        <div class="card-body">
            <?php if (empty($payments)): ?>
                <div class="empty-state">
                    <i class="fas fa-credit-card"></i>
                    <p>No payment records found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
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
                            <?php foreach ($payments as $payment): ?>
                                <?php
                                $payment_badges = [
                                    'Pending' => 'warning',
                                    'Paid' => 'success',
                                    'Partially Paid' => 'info',
                                    'Overdue' => 'danger',
                                    'Cancelled' => 'secondary'
                                ];

                                $paymentStatus = $payment['payment_status'] ?? 'Pending';
                                $paymentBadge = $payment_badges[$paymentStatus] ?? 'secondary';
                                ?>

                                <tr>
                                    <td><code><?php echo h($payment['invoice_number'] ?? 'N/A'); ?></code></td>
                                    <td>
                                        <span class="badge badge-primary">
                                            <?php echo h($payment['subscription_plan'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo format_currency($payment['amount'] ?? 0); ?></td>
                                    <td>
                                        <?php echo safe_date($payment['payment_period_start'] ?? null, 'M d'); ?>
                                        -
                                        <?php echo safe_date($payment['payment_period_end'] ?? null); ?>
                                    </td>
                                    <td><?php echo safe_date($payment['payment_date'] ?? null); ?></td>
                                    <td><?php echo h($payment['payment_method'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo h($paymentBadge); ?>">
                                            <?php echo h($paymentStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="hub-operations?edit_subscription=<?php echo (int)($payment['payment_id'] ?? 0); ?>&tab=subscriptions"
                                           class="btn btn-warning btn-sm">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($payments) >= 10): ?>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="hub-operations?tab=subscriptions" class="btn btn-secondary">
                            View All Payments
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="receipts-tab" class="tab-content">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-receipt"></i> Payment Receipts</h3>
            <a href="hub-operations?tab=receipts" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Receipt
            </a>
        </div>

        <div class="card-body">
            <?php if (empty($receipts)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No receipt records found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Uploaded</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($receipts as $receipt): ?>
                                <?php
                                $receipt_status_badges = [
                                    'Pending' => 'warning',
                                    'Verified' => 'success',
                                    'Rejected' => 'danger'
                                ];

                                $receiptStatus = $receipt['verification_status'] ?? 'Pending';
                                $receiptBadge = $receipt_status_badges[$receiptStatus] ?? 'secondary';
                                ?>

                                <tr>
                                    <td><?php echo h($receipt['receipt_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-primary">
                                            <?php echo h($receipt['receipt_type'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo format_currency($receipt['amount'] ?? 0); ?></td>
                                    <td><?php echo safe_date($receipt['payment_date'] ?? null); ?></td>
                                    <td><?php echo h($receipt['payment_method'] ?? 'N/A'); ?></td>
                                    <td><?php echo safe_date($receipt['created_at'] ?? null); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo h($receiptBadge); ?>">
                                            <?php echo h($receiptStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($receipt['receipt_file_path'])): ?>
                                            <a href="<?php echo h(ims_upload_url($receipt['receipt_file_path'])); ?>"
                                               target="_blank"
                                               class="btn btn-info btn-sm">
                                                <i class="fas fa-file"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($receiptStatus === 'Pending'): ?>
                                            <a href="includes/hub-operations-process.php?verify_receipt=<?php echo (int)($receipt['receipt_id'] ?? 0); ?>&tab=receipts&amp;csrf_token=<?= urlencode(csrf_token()) ?>"
                                               class="btn btn-success btn-sm">
                                                <i class="fas fa-check"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($receipts) >= 10): ?>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="hub-operations?tab=receipts" class="btn btn-secondary">
                            View All Receipts
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="bookings-tab" class="tab-content">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-alt"></i> Booking History</h3>
        </div>

        <div class="card-body">
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <p>No booking records found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
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
                            <?php foreach ($bookings as $booking): ?>
                                <?php
                                $booking_payment_badges = [
                                    'Paid' => 'success',
                                    'Pending' => 'warning',
                                    'Partially Paid' => 'info',
                                    'Cancelled' => 'secondary',
                                    'Refunded' => 'danger'
                                ];

                                $booking_status_badges = [
                                    'Pending' => 'warning',
                                    'Confirmed' => 'success',
                                    'Cancelled' => 'danger',
                                    'Completed' => 'info',
                                    'No Show' => 'secondary'
                                ];

                                $bookingPaymentStatus = $booking['payment_status'] ?? 'Pending';
                                $bookingStatus = $booking['booking_status'] ?? 'Pending';

                                $bookingPaymentBadge = $booking_payment_badges[$bookingPaymentStatus] ?? 'secondary';
                                $bookingBadge = $booking_status_badges[$bookingStatus] ?? 'secondary';
                                ?>

                                <tr>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo h($booking['space_type'] ?? 'N/A'); ?>
                                        </span><br>
                                        <small><?php echo h($booking['space_name'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo safe_date($booking['booking_date'] ?? null); ?></td>
                                    <td>
                                        <?php echo safe_date($booking['start_time'] ?? null, 'h:i A'); ?>
                                        -
                                        <?php echo safe_date($booking['end_time'] ?? null, 'h:i A'); ?>
                                    </td>
                                    <td><?php echo h($booking['duration_hours'] ?? 0); ?> hrs</td>
                                    <td><?php echo format_currency($booking['booking_amount'] ?? 0); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo h($bookingPaymentBadge); ?>">
                                            <?php echo h($bookingPaymentStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo h($bookingBadge); ?>">
                                            <?php echo h($bookingStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view-booking?id=<?php echo (int)($booking['booking_id'] ?? 0); ?>"
                                           class="btn btn-info btn-sm">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($bookings) >= 10): ?>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="hub-operations?tab=bookings" class="btn btn-secondary">
                            View All Bookings
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="feedback-tab" class="tab-content">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-comment-dots"></i> Member Feedback</h3>
        </div>

        <div class="card-body">
            <?php if (empty($feedbacks)): ?>
                <div class="empty-state">
                    <i class="fas fa-comment-dots"></i>
                    <p>No feedback submitted yet</p>
                </div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($feedbacks as $feedback): ?>
                        <?php
                        $priority_badges = [
                            'Low' => 'secondary',
                            'Medium' => 'info',
                            'High' => 'warning',
                            'Urgent' => 'danger'
                        ];

                        $feedback_status_badges = [
                            'New' => 'primary',
                            'In Review' => 'info',
                            'Responded' => 'warning',
                            'Resolved' => 'success',
                            'Closed' => 'secondary'
                        ];

                        $priority = $feedback['priority'] ?? 'Medium';
                        $feedbackStatus = $feedback['feedback_status'] ?? 'New';

                        $priorityBadge = $priority_badges[$priority] ?? 'secondary';
                        $feedbackBadge = $feedback_status_badges[$feedbackStatus] ?? 'secondary';
                        ?>

                        <div class="timeline-item">
                            <div class="timeline-date">
                                <?php echo safe_date($feedback['created_at'] ?? null, 'M d, Y h:i A'); ?>
                            </div>

                            <div class="timeline-content">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                    <div>
                                        <strong style="color: #2C3E50; font-size: 16px;">
                                            <?php echo h($feedback['subject'] ?? 'No Subject'); ?>
                                        </strong>

                                        <div style="margin-top: 5px;">
                                            <span class="badge badge-info">
                                                <?php echo h($feedback['feedback_type'] ?? 'General'); ?>
                                            </span>

                                            <span class="badge badge-<?php echo h($priorityBadge); ?>">
                                                <?php echo h($priority); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <span class="badge badge-<?php echo h($feedbackBadge); ?>">
                                            <?php echo h($feedbackStatus); ?>
                                        </span>
                                    </div>
                                </div>

                                <?php if (!empty($feedback['rating'])): ?>
                                    <div style="margin-bottom: 10px;">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star"
                                               style="color: <?php echo $i <= (int)$feedback['rating'] ? '#F39C12' : '#ddd'; ?>; font-size: 14px;"></i>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>

                                <p style="color: #7f8c8d; margin: 10px 0; line-height: 1.6;">
                                    <?php echo nl2br(h($feedback['feedback_message'] ?? '')); ?>
                                </p>

                                <a href="view-feedback?id=<?php echo (int)($feedback['feedback_id'] ?? 0); ?>"
                                   class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (count($feedbacks) >= 5): ?>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="hub-operations?tab=feedback" class="btn btn-secondary">
                            View All Feedback
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function switchTab(event, tabName) {
    const tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(tab => tab.classList.remove('active'));

    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(btn => btn.classList.remove('active'));

    const selectedTab = document.getElementById(tabName + '-tab');
    if (selectedTab) {
        selectedTab.classList.add('active');
    }

    if (event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }
}

function sendNotification(memberId) {
    window.location.href = 'hub-operations?tab=notifications&member=' + encodeURIComponent(memberId);
}
</script>