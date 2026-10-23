<?php

$page_title = 'Payment Details';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if user is admin or member
$is_admin = ims_allowed('payments.view_all', ['Administrator', 'Operations/Admin'], "View any member's payment (not just their own)");

// Get payment ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Payment ID not provided.";
    header("Location: " . ($is_admin ? 'hub-operations?tab=receipts' : 'payment-history'));
    exit();
}

$payment_id = intval($_GET['id']);

// Get member_id if not admin
$member_id = null;
if (!$is_admin) {
    $member_query = "SELECT member_id FROM members WHERE user_id = " . (int)$user_id;
    $member_result = $conn->query($member_query);
    if ($member_result->num_rows > 0) {
        $member_id = $member_result->fetch_assoc()['member_id'];
    }
}

// Build query based on role
if ($is_admin) {
    // Admin can view any payment
    $query = "SELECT sp.*, 
              m.membership_number,
              u.full_name as member_name,
              u.email as member_email,
              u.phone as member_phone,
              verified.full_name as verified_by_name
              FROM subscription_payments sp
              LEFT JOIN members m ON sp.member_id = m.member_id
              LEFT JOIN users u ON m.user_id = u.user_id
              LEFT JOIN users verified ON sp.confirmed_by = verified.user_id
              WHERE sp.payment_id = $payment_id";
} else {
    // Members can only view their own payments
    $query = "SELECT sp.*, 
              m.membership_number,
              u.full_name as member_name,
              verified.full_name as verified_by_name
              FROM subscription_payments sp
              LEFT JOIN members m ON sp.member_id = m.member_id
              LEFT JOIN users u ON m.user_id = u.user_id
              LEFT JOIN users verified ON sp.confirmed_by = verified.user_id
              WHERE sp.payment_id = $payment_id AND sp.member_id = " . (int)$member_id;
}

$result = $conn->query($query);

if (!$result || $result->num_rows == 0) {
    $_SESSION['error'] = "Payment not found or access denied.";
    header("Location: " . ($is_admin ? 'hub-operations?tab=receipts' : 'payment-history'));
    exit();
}

$payment = $result->fetch_assoc();

// Get receipt information if exists
$receipt = null;
if ($payment['receipt_path']) {
    $receipt_query = "SELECT * FROM payment_receipts 
                      WHERE payment_id = $payment_id 
                      ORDER BY uploaded_at DESC LIMIT 1";
    $receipt_result = $conn->query($receipt_query);
    if ($receipt_result->num_rows > 0) {
        $receipt = $receipt_result->fetch_assoc();
    }
}

// Calculate days since payment
$payment_date = strtotime($payment['payment_date']);
$days_ago = floor((time() - $payment_date) / (60 * 60 * 24));
?>

<style>
/* Payment Details: main/aside grid, detail tiles, receipt preview and timeline. */
.vp-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
.vp-main,
.vp-aside { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.vp-main > .alert { margin-bottom: 0; }
.vp-info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
.vp-info-item { padding: 15px; background: var(--ink-50); border-radius: var(--radius-md); }
.vp-info-item label,
.vp-block-label { display: block; font-size: 12px; color: var(--ink-300); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
.vp-info-value { font-size: 15px; color: var(--ink-900); font-weight: 600; }
.vp-sub { display: block; color: var(--ink-300); font-weight: 400; }
.vp-block { margin-top: 20px; }
.vp-block-box { background: var(--ink-50); padding: 15px; border-radius: var(--radius-md); }
.vp-block-box.is-prose { line-height: 1.8; }
.vp-block .alert { margin-bottom: 0; }
.vp-receipt { background: var(--ink-50); border-radius: var(--radius-md); padding: 20px; text-align: center; }
.vp-receipt img { max-width: 100%; border-radius: var(--radius-md); margin-bottom: 15px; box-shadow: var(--shadow-sm); }
.vp-receipt iframe { width: 100%; height: 500px; border: none; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
.vp-receipt-actions,
.vp-receipt-meta { margin-top: 15px; }
.vp-timeline { position: relative; padding-left: 30px; }
.vp-timeline-item { position: relative; padding-bottom: 25px; }
.vp-timeline-item:last-child { padding-bottom: 0; }
.vp-timeline-item::before { content: ''; position: absolute; left: -30px; top: 5px; width: 14px; height: 14px; border-radius: 50%; background: var(--brand-500); }
.vp-timeline-item::after { content: ''; position: absolute; left: -23px; top: 19px; width: 2px; height: calc(100% - 14px); background: var(--ink-100); }
.vp-timeline-item:last-child::after { display: none; }
.vp-timeline-content { background: var(--ink-50); padding: 15px; border-radius: var(--radius-md); }
.vp-timeline-content strong { color: var(--ink-900); display: block; margin-bottom: 5px; }
.vp-timeline-content small { color: var(--ink-300); }
@media (max-width: 968px) {
    .vp-grid,
    .vp-info-grid { grid-template-columns: 1fr; }
}
@media print {
    .vp-aside, .no-print { display: none !important; }
    .vp-grid { grid-template-columns: 1fr; }
}
</style>

<?php
$status_badges = [
    'Pending' => 'warning',
    'Paid' => 'success',
    'Partially Paid' => 'info',
    'Overdue' => 'danger',
    'Cancelled' => 'danger'
];
$badge = $status_badges[$payment['payment_status']] ?? 'secondary';
?>
<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-receipt" aria-hidden="true"></i> Payment Details</h1>
        <p>Invoice #<?php echo htmlspecialchars($payment['invoice_number']); ?></p>
    </div>
    <div class="hero-actions">
        <a href="<?php echo $is_admin ? 'hub-operations?tab=receipts' : 'payment-history'; ?>" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
        </a>
    </div>
</div>

<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Amount Paid</div>
            <div class="stat-value"><?php echo format_currency($payment['amount']); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-layer-group" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Plan</div>
            <div class="stat-value"><?php echo htmlspecialchars($payment['subscription_plan']); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-flag" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Status</div>
            <div class="stat-value"><span class="badge badge-<?php echo $badge; ?>"><?php echo htmlspecialchars($payment['payment_status']); ?></span></div>
        </div>
    </div>
</div>


<!-- Main Content -->
<div class="vp-grid">
    <!-- Main Section -->
    <div class="vp-main">
        <!-- Status Alert -->
        <?php if ($payment['payment_status'] == 'Paid'): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div>
            <strong>Payment Verified</strong><br>
            Your payment has been verified and your subscription is active.
            </div>
        </div>
        <?php elseif ($payment['payment_status'] == 'Pending'): ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock"></i>
            <div>
            <strong>Pending Verification</strong><br>
            Your payment is being verified. This usually takes 1-2 business days.
            </div>
        </div>
        <?php elseif ($payment['payment_status'] == 'Rejected'): ?>
        <div class="alert alert-danger">
            <i class="fas fa-times-circle"></i>
            <div>
            <strong>Payment Rejected</strong><br>
            <?php echo $payment['rejection_reason'] ? nl2br(htmlspecialchars($payment['rejection_reason'])) : 'Please contact support for more information.'; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Payment Information -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-info-circle"></i> Payment Information</h3></div>
            <div class="card-body">
            
            <div class="vp-info-grid">
                <div class="vp-info-item">
                    <label>Invoice Number</label>
                    <div class="vp-info-value"><code><?php echo htmlspecialchars($payment['invoice_number']); ?></code></div>
                </div>
                
                <div class="vp-info-item">
                    <label>Payment Date</label>
                    <div class="vp-info-value">
                        <?php echo date('d M Y', $payment_date); ?>
                        <small class="vp-sub">
                            <?php echo $days_ago == 0 ? 'Today' : $days_ago . ' days ago'; ?>
                        </small>
                    </div>
                </div>
                
                <div class="vp-info-item">
                    <label>Payment Method</label>
                    <div class="vp-info-value"><?php echo htmlspecialchars($payment['payment_method']); ?></div>
                </div>
                
                <div class="vp-info-item">
                    <label>Amount</label>
                    <div class="vp-info-value"><?php echo format_currency($payment['amount']); ?></div>
                </div>
                
                <div class="vp-info-item">
                    <label>Subscription Plan</label>
                    <div class="vp-info-value"><?php echo htmlspecialchars($payment['subscription_plan']); ?></div>
                </div>
                
                <div class="vp-info-item">
                    <label>Status</label>
                    <div class="vp-info-value">
                        <span class="badge badge-<?php echo $badge; ?>">
                            <?php echo htmlspecialchars($payment['payment_status']); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <?php if ($payment['payment_reference']): ?>
            <div class="vp-block">
                <label class="vp-block-label">
                    Transaction Reference
                </label>
                <div class="vp-block-box">
                    <code><?php echo htmlspecialchars($payment['payment_reference']); ?></code>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($payment['notes']): ?>
            <div class="vp-block">
                <label class="vp-block-label">
                    Payment Notes
                </label>
                <div class="vp-block-box is-prose">
                    <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </div>
        
        <!-- Member Information (Admin View) -->
        <?php if ($is_admin): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user"></i> Member Information</h3></div>
            <div class="card-body">
            
            <div class="vp-info-grid">
                <div class="vp-info-item">
                    <label>Member Name</label>
                    <div class="vp-info-value"><?php echo htmlspecialchars($payment['member_name']); ?></div>
                </div>
                
                <div class="vp-info-item">
                    <label>Membership Number</label>
                    <div class="vp-info-value"><code><?php echo htmlspecialchars($payment['membership_number']); ?></code></div>
                </div>
                
                <div class="vp-info-item">
                    <label>Email</label>
                    <div class="vp-info-value">
                        <a href="mailto:<?php echo htmlspecialchars($payment['member_email']); ?>">
                            <?php echo htmlspecialchars($payment['member_email']); ?>
                        </a>
                    </div>
                </div>
                
                <?php if ($payment['member_phone']): ?>
                <div class="vp-info-item">
                    <label>Phone</label>
                    <div class="vp-info-value">
                        <a href="tel:<?php echo htmlspecialchars($payment['member_phone']); ?>">
                            <?php echo htmlspecialchars($payment['member_phone']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Receipt Preview -->
        <?php if ($payment['receipt_path']): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-file-invoice"></i> Payment Receipt</h3></div>
            <div class="card-body">
            
            <div class="vp-receipt">
                <?php 
                $file_extension = strtolower(pathinfo($payment['receipt_path'], PATHINFO_EXTENSION));
                if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])): 
                ?>
                    <img src="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'])); ?>" 
                         alt="Payment Receipt">
                <?php elseif ($file_extension == 'pdf'): ?>
                    <iframe src="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'])); ?>"></iframe>
                <?php endif; ?>
                
                <div class="vp-receipt-actions">
                    <a href="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'], true)); ?>" 
                       class="btn btn-info" target="_blank" download>
                        <i class="fas fa-download"></i> Download Receipt
                    </a>
                </div>
                
                <?php if ($receipt): ?>
                <div class="cell-muted vp-receipt-meta">
                    Uploaded on <?php echo date('d M Y, h:i A', strtotime($receipt['uploaded_at'])); ?>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Verification Details -->
        <?php if ($payment['verified_at']): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-check-square"></i> Verification Details</h3></div>
            <div class="card-body">
            
            <div class="vp-info-grid">
                <div class="vp-info-item">
                    <label>Verified By</label>
                    <div class="vp-info-value"><?php echo htmlspecialchars($payment['verified_by_name']); ?></div>
                </div>
                
                <div class="vp-info-item">
                    <label>Verified At</label>
                    <div class="vp-info-value"><?php echo date('d M Y, h:i A', strtotime($payment['verified_at'])); ?></div>
                </div>
            </div>
            
            <?php if ($payment['payment_status'] == 'Rejected' && $payment['rejection_reason']): ?>
            <div class="vp-block">
                <label class="vp-block-label">
                    Rejection Reason
                </label>
                <div class="alert alert-danger">
                    <?php echo nl2br(htmlspecialchars($payment['rejection_reason'])); ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Timeline -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-history"></i> Payment Timeline</h3></div>
            <div class="card-body">
            <div class="vp-timeline">
                <div class="vp-timeline-item">
                    <div class="vp-timeline-content">
                        <strong>Payment Initiated</strong>
                        <small><?php echo date('d M Y, h:i A', strtotime($payment['created_at'])); ?></small>
                    </div>
                </div>
                
                <?php if ($receipt): ?>
                <div class="vp-timeline-item">
                    <div class="vp-timeline-content">
                        <strong>Receipt Uploaded</strong>
                        <small><?php echo date('d M Y, h:i A', strtotime($receipt['uploaded_at'])); ?></small>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($payment['verified_at']): ?>
                <div class="vp-timeline-item">
                    <div class="vp-timeline-content">
                        <strong>
                            <?php echo $payment['payment_status'] == 'Cancelled' ? 'Payment Rejected' : 'Payment Verified'; ?>
                        </strong>
                        <small>
                            By <?php echo htmlspecialchars($payment['verified_by_name']); ?><br>
                            <?php echo date('d M Y, h:i A', strtotime($payment['verified_at'])); ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </div>
    <div class="vp-aside"><!-- right column (was .sidebar, which collided with the app sidebar) -->
    
   
        <!-- Payments are verified or rejected from Hub Operations (receipts tab). -->
    </div>
</div>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

