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

/* Member folder (contracts, receipts, documents) */
$can_open_folder = ims_allowed('members.folder_view', ['Administrator', 'Operations/Admin', 'Finance', 'Accountant', 'Executive Director'], 'View and export member folders (contracts, receipts, documents)');
$folder_count = 0;
if ($can_open_folder && ($fstmt = $conn->prepare('SELECT COUNT(*) FROM member_documents WHERE member_id = ?'))) {
    $fstmt->bind_param('i', $member_id);
    $fstmt->execute();
    $fstmt->bind_result($folder_count);
    $fstmt->fetch();
    $fstmt->close();
}
?>

<link rel="stylesheet" href="css/assets.css">
<style>
    /* Hero, stat cards, alerts and empty states come from the global CSS
       (style.css / reports.css / assets.css); only member-specific bits here. */
    .vm-hero-main { display: flex; align-items: center; gap: 18px; position: relative; min-width: 0; }
    .vm-avatar {
        width: 72px; height: 72px; border-radius: 50%; flex-shrink: 0;
        display: grid; place-items: center;
        background: rgba(255, 255, 255, .2); border: 2px solid rgba(255, 255, 255, .45);
        font-size: 30px; font-weight: 800; color: var(--ink-0);
    }
    .vm-hero-main h1 { margin-bottom: 4px; }
    .vm-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .vm-chip {
        display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px;
        border-radius: 999px; background: rgba(255, 255, 255, .16); border: 1px solid rgba(255, 255, 255, .28);
        font-size: 12px; font-weight: 600; color: var(--ink-0); max-width: 100%;
    }
    .vm-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .vm-status { background: var(--ink-0); color: var(--brand-700); border-color: var(--ink-0); font-weight: 800; }
    .vm-hero-btn { background: rgba(255, 255, 255, .14); color: var(--ink-0); border: 1px solid rgba(255, 255, 255, .35); box-shadow: none; }
    .vm-hero-btn:hover { background: rgba(255, 255, 255, .24); color: var(--ink-0); }
    .vm-hero-btn.solid { background: var(--ink-0); color: var(--brand-700); border-color: var(--ink-0); }
    .vm-hero-btn.solid:hover { background: var(--brand-50); color: var(--brand-700); }
    .vm-hero-btn .vm-count { margin-left: 2px; padding: 1px 7px; border-radius: 999px; background: var(--brand-100); font-size: 11px; }

    .vm-page { display: flex; flex-direction: column; gap: 20px; }
    .vm-page > .stats-grid, .vm-page > .alert, .vm-page > .card { margin-bottom: 0; }
    .vm-page .alert { display: flex; align-items: center; gap: 12px; }
    .vm-page .alert > i { font-size: 22px; }

    .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; }

    .tab-navigation { display: flex; border-bottom: 1px solid var(--ink-100); overflow-x: auto; }
    .tab-btn {
        padding: 14px 22px; background: none; border: none; border-bottom: 3px solid transparent;
        cursor: pointer; font-size: 14px; font-weight: 600; color: var(--ink-300);
        white-space: nowrap; transition: color .2s, border-color .2s, background .2s;
    }
    .tab-btn:hover { color: var(--brand-700); background: var(--brand-50); }
    .tab-btn.active { color: var(--brand-700); border-bottom-color: var(--brand-500); }
    .tab-content { display: none; }
    .tab-content.active { display: block; }

    /* Member details: label/value tiles in the same language as stat cards */
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
    .info-card {
        background: var(--surface-hover); border: 1px solid var(--ink-50);
        border-radius: var(--radius-lg, 12px); padding: 14px 16px;
    }
    .info-label {
        font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
        color: var(--ink-300); margin-bottom: 6px;
    }
    .info-value { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 700; color: var(--ink-700); }
    .info-value i { color: var(--brand-500); }

    .timeline { position: relative; padding-left: 30px; }
    .timeline::before { content: ''; position: absolute; left: 8px; top: 0; bottom: 0; width: 2px; background: var(--ink-100); }
    .timeline-item { position: relative; padding-bottom: 24px; }
    .timeline-item::before {
        content: ''; position: absolute; left: -26px; top: 5px; width: 12px; height: 12px; border-radius: 50%;
        background: var(--brand-500); border: 3px solid var(--ink-0); box-shadow: 0 0 0 2px var(--ink-100);
    }
    .timeline-date { font-size: 12px; color: var(--ink-300); margin-bottom: 5px; }
    .timeline-content { background: var(--surface-card); padding: 15px; border-radius: var(--radius-md, 10px); box-shadow: var(--shadow-sm, 0 2px 4px rgba(0,0,0,.05)); }

    @media (max-width: 768px) {
        .vm-avatar { width: 56px; height: 56px; font-size: 24px; }
        .tab-btn { padding: 12px 16px; font-size: 13px; }
    }
    @media (max-width: 480px) {
        .vm-hero-main { align-items: flex-start; }
    }
</style>

<div class="vm-page">

<div class="page-hero">
    <div class="vm-hero-main">
        <div class="vm-avatar" aria-hidden="true"><?php echo h(strtoupper(substr((string)$memberName, 0, 1))); ?></div>
        <div style="min-width:0">
            <h1><?php echo h($memberName); ?></h1>
            <p><i class="fas fa-id-card" aria-hidden="true"></i> <?php echo h($member['membership_number'] ?? 'N/A'); ?></p>
            <div class="vm-chips">
                <span class="vm-chip vm-status"><?php echo h($membershipStatus); ?></span>
                <?php if ($memberEmail !== ''): ?>
                    <span class="vm-chip"><i class="fas fa-envelope" aria-hidden="true"></i><span><?php echo h($memberEmail); ?></span></span>
                <?php endif; ?>
                <?php if (!empty($member['emergency_contact'])): ?>
                    <span class="vm-chip"><i class="fas fa-phone" aria-hidden="true"></i><span><?php echo h($member['emergency_contact']); ?></span></span>
                <?php endif; ?>
                <?php if (!empty($member['company_name'])): ?>
                    <span class="vm-chip"><i class="fas fa-building" aria-hidden="true"></i><span><?php echo h($member['company_name']); ?></span></span>
                <?php endif; ?>
                <span class="vm-chip"><i class="fas fa-calendar" aria-hidden="true"></i><span>Joined <?php echo safe_date($member['registration_date'] ?? null); ?></span></span>
            </div>
        </div>
    </div>

    <div class="hero-actions">
        <a href="hub-operations?tab=members" class="btn vm-hero-btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Members</a>
        <a href="hub-operations?edit_member=<?php echo $member_id; ?>&tab=members" class="btn vm-hero-btn"><i class="fas fa-edit" aria-hidden="true"></i> Edit</a>
        <?php if ($can_open_folder): ?>
            <a href="member-folder?id=<?php echo $member_id; ?>" class="btn vm-hero-btn solid">
                <i class="fas fa-folder-open" aria-hidden="true"></i> Member Folder
                <?php if ($folder_count > 0): ?><span class="vm-count"><?php echo (int)$folder_count; ?></span><?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_expired): ?>
    <div class="alert alert-danger" role="status">
        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
        <div>
            <strong>Membership Expired</strong><br>
            This membership expired <?php echo abs(round($days_to_expire)); ?> days ago on
            <?php echo safe_date($member['subscription_end_date'] ?? null); ?>.
        </div>
    </div>
<?php elseif ($is_expiring_soon): ?>
    <div class="alert alert-warning" role="status">
        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>Expiring Soon</strong><br>
            This membership will expire in <?php echo round($days_to_expire); ?> days on
            <?php echo safe_date($member['subscription_end_date'] ?? null); ?>.
        </div>
    </div>
<?php elseif ($membershipStatus === 'Active'): ?>
    <div class="alert alert-success" role="status">
        <i class="fas fa-check-circle" aria-hidden="true"></i>
        <div>
            <strong>Active Membership</strong><br>
            Valid until <?php echo safe_date($member['subscription_end_date'] ?? null); ?>.
        </div>
    </div>
<?php endif; ?>

<div class="quick-actions">
    <a href="hub-operations?tab=subscriptions" class="btn btn-success">
        <i class="fas fa-plus" aria-hidden="true"></i> Add Payment
    </a>

    <a href="hub-operations?tab=receipts" class="btn btn-info">
        <i class="fas fa-receipt" aria-hidden="true"></i> Add Receipt
    </a>

    <button type="button" onclick="sendNotification(<?php echo $member_id; ?>)" class="btn btn-secondary">
        <i class="fas fa-bell" aria-hidden="true"></i> Send Notification
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

</div><!-- /.vm-page -->

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