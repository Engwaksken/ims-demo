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
.payment-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
    position: relative;
}

.payment-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.status-badge-large {
    position: absolute;
    top: 30px;
    right: 30px;
    padding: 12px 24px;
    border-radius: 25px;
    font-size: 18px;
    font-weight: 600;
}

.payment-content {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
}

.main-section {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.info-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.info-card h3 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 15px;
    border-bottom: 2px solid #ecf0f1;
}

.info-card h3 i {
    color: #ff6b35;
}

.amount-display {
    background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    text-align: center;
}

.amount-display .label {
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 10px;
}

.amount-display .amount {
    font-size: 48px;
    font-weight: 700;
}

.amount-display .plan {
    font-size: 18px;
    opacity: 0.9;
    margin-top: 10px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.info-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.info-item label {
    font-size: 12px;
    color: #7f8c8d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
    display: block;
}

.info-item .value {
    font-size: 16px;
    color: #2c3e50;
    font-weight: 600;
}

.receipt-preview {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.receipt-preview img {
    max-width: 100%;
    border-radius: 8px;
    margin-bottom: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.receipt-preview iframe {
    width: 100%;
    height: 500px;
    border: none;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 25px;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -30px;
    top: 5px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #ff6b35;
}

.timeline-item::after {
    content: '';
    position: absolute;
    left: -23px;
    top: 19px;
    width: 2px;
    height: calc(100% - 14px);
    background: #ecf0f1;
}

.timeline-item:last-child::after {
    display: none;
}

.timeline-content {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}

.timeline-content strong {
    color: #2c3e50;
    display: block;
    margin-bottom: 5px;
}

.timeline-content small {
    color: #7f8c8d;
}

.payment-aside {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.action-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.action-card h3 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 18px;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.alert-success-custom {
    background: #d4edda;
    border-left: 4px solid #2ECC71;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #155724;
}

.alert-warning-custom {
    background: #fff3cd;
    border-left: 4px solid #F39C12;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #856404;
}

.alert-danger-custom {
    background: #f8d7da;
    border-left: 4px solid #E74C3C;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #721c24;
}

.verification-form {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-top: 20px;
}

@media (max-width: 968px) {
    .payment-content {
        grid-template-columns: 1fr;
    }
    
    .status-badge-large {
        position: static;
        display: inline-block;
        margin-top: 15px;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
}

@media print {
    .payment-aside, .no-print {
        display: none !important;
    }
    
    .main-section {
        width: 100%;
    }
}
</style>

<!-- Header -->
<div class="payment-header">
    <h1><i class="fas fa-receipt"></i> Payment Details</h1>
    <div style="opacity: 0.9;">Invoice #<?php echo htmlspecialchars($payment['invoice_number']); ?></div>
    
    <?php
    $status_badges = [
        'Pending' => 'warning',
        'Verified' => 'success',
        'Rejected' => 'danger',
        'Processing' => 'info'
    ];
    $badge = $status_badges[$payment['payment_status']] ?? 'secondary';
    ?>
    <span class="badge badge-<?php echo $badge; ?> status-badge-large">
        <?php echo htmlspecialchars($payment['payment_status']); ?>
    </span>
</div>

<!-- Main Content -->
<div class="payment-content">
    <!-- Main Section -->
    <div class="main-section">
        <!-- Status Alert -->
        <?php if ($payment['payment_status'] == 'Verified'): ?>
        <div class="alert-success-custom">
            <i class="fas fa-check-circle"></i>
            <strong>Payment Verified</strong><br>
            Your payment has been verified and your subscription is active.
        </div>
        <?php elseif ($payment['payment_status'] == 'Pending'): ?>
        <div class="alert-warning-custom">
            <i class="fas fa-clock"></i>
            <strong>Pending Verification</strong><br>
            Your payment is being verified. This usually takes 1-2 business days.
        </div>
        <?php elseif ($payment['payment_status'] == 'Rejected'): ?>
        <div class="alert-danger-custom">
            <i class="fas fa-times-circle"></i>
            <strong>Payment Rejected</strong><br>
            <?php echo $payment['rejection_reason'] ? nl2br(htmlspecialchars($payment['rejection_reason'])) : 'Please contact support for more information.'; ?>
        </div>
        <?php endif; ?>
        
        <!-- Amount Display -->
        <div class="amount-display">
            <div class="label">Amount Paid</div>
            <div class="amount"><?php echo format_currency($payment['amount']); ?></div>
            <div class="plan"><?php echo htmlspecialchars($payment['subscription_plan']); ?> Plan</div>
        </div>
        
        <!-- Payment Information -->
        <div class="info-card">
            <h3><i class="fas fa-info-circle"></i> Payment Information</h3>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>Invoice Number</label>
                    <div class="value"><code><?php echo htmlspecialchars($payment['invoice_number']); ?></code></div>
                </div>
                
                <div class="info-item">
                    <label>Payment Date</label>
                    <div class="value">
                        <?php echo date('d M Y', $payment_date); ?>
                        <small style="display: block; color: #7f8c8d; font-weight: normal;">
                            <?php echo $days_ago == 0 ? 'Today' : $days_ago . ' days ago'; ?>
                        </small>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>Payment Method</label>
                    <div class="value"><?php echo htmlspecialchars($payment['payment_method']); ?></div>
                </div>
                
                <div class="info-item">
                    <label>Amount</label>
                    <div class="value"><?php echo format_currency($payment['amount']); ?></div>
                </div>
                
                <div class="info-item">
                    <label>Subscription Plan</label>
                    <div class="value"><?php echo htmlspecialchars($payment['subscription_plan']); ?></div>
                </div>
                
                <div class="info-item">
                    <label>Status</label>
                    <div class="value">
                        <span class="badge badge-<?php echo $badge; ?>" style="font-size: 14px;">
                            <?php echo htmlspecialchars($payment['payment_status']); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <?php if ($payment['payment_reference']): ?>
            <div style="margin-top: 20px;">
                <label style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 10px; display: block;">
                    Transaction Reference
                </label>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <code style="font-size: 14px;"><?php echo htmlspecialchars($payment['payment_reference']); ?></code>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($payment['notes']): ?>
            <div style="margin-top: 20px;">
                <label style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 10px; display: block;">
                    Payment Notes
                </label>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; line-height: 1.8;">
                    <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Member Information (Admin View) -->
        <?php if ($is_admin): ?>
        <div class="info-card">
            <h3><i class="fas fa-user"></i> Member Information</h3>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>Member Name</label>
                    <div class="value"><?php echo htmlspecialchars($payment['member_name']); ?></div>
                </div>
                
                <div class="info-item">
                    <label>Membership Number</label>
                    <div class="value"><code><?php echo htmlspecialchars($payment['membership_number']); ?></code></div>
                </div>
                
                <div class="info-item">
                    <label>Email</label>
                    <div class="value">
                        <a href="mailto:<?php echo htmlspecialchars($payment['member_email']); ?>">
                            <?php echo htmlspecialchars($payment['member_email']); ?>
                        </a>
                    </div>
                </div>
                
                <?php if ($payment['member_phone']): ?>
                <div class="info-item">
                    <label>Phone</label>
                    <div class="value">
                        <a href="tel:<?php echo htmlspecialchars($payment['member_phone']); ?>">
                            <?php echo htmlspecialchars($payment['member_phone']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Receipt Preview -->
        <?php if ($payment['receipt_path']): ?>
        <div class="info-card">
            <h3><i class="fas fa-file-invoice"></i> Payment Receipt</h3>
            
            <div class="receipt-preview">
                <?php 
                $file_extension = strtolower(pathinfo($payment['receipt_path'], PATHINFO_EXTENSION));
                if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])): 
                ?>
                    <img src="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'])); ?>" 
                         alt="Payment Receipt">
                <?php elseif ($file_extension == 'pdf'): ?>
                    <iframe src="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'])); ?>"></iframe>
                <?php endif; ?>
                
                <div style="margin-top: 15px;">
                    <a href="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'], true)); ?>" 
                       class="btn btn-info" target="_blank" download>
                        <i class="fas fa-download"></i> Download Receipt
                    </a>
                </div>
                
                <?php if ($receipt): ?>
                <div style="margin-top: 15px; font-size: 12px; color: #7f8c8d;">
                    Uploaded on <?php echo date('d M Y, h:i A', strtotime($receipt['uploaded_at'])); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Verification Details -->
        <?php if ($payment['verified_at']): ?>
        <div class="info-card">
            <h3><i class="fas fa-check-square"></i> Verification Details</h3>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>Verified By</label>
                    <div class="value"><?php echo htmlspecialchars($payment['verified_by_name']); ?></div>
                </div>
                
                <div class="info-item">
                    <label>Verified At</label>
                    <div class="value"><?php echo date('d M Y, h:i A', strtotime($payment['verified_at'])); ?></div>
                </div>
            </div>
            
            <?php if ($payment['payment_status'] == 'Rejected' && $payment['rejection_reason']): ?>
            <div style="margin-top: 20px;">
                <label style="font-size: 12px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 10px; display: block;">
                    Rejection Reason
                </label>
                <div style="background: #f8d7da; padding: 15px; border-radius: 8px; border-left: 4px solid #E74C3C;">
                    <?php echo nl2br(htmlspecialchars($payment['rejection_reason'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Timeline -->
        <div class="info-card">
            <h3><i class="fas fa-history"></i> Payment Timeline</h3>
            <div class="timeline">
                <div class="timeline-item">
                    <div class="timeline-content">
                        <strong>Payment Initiated</strong>
                        <small><?php echo date('d M Y, h:i A', strtotime($payment['created_at'])); ?></small>
                    </div>
                </div>
                
                <?php if ($receipt): ?>
                <div class="timeline-item">
                    <div class="timeline-content">
                        <strong>Receipt Uploaded</strong>
                        <small><?php echo date('d M Y, h:i A', strtotime($receipt['uploaded_at'])); ?></small>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($payment['verified_at']): ?>
                <div class="timeline-item">
                    <div class="timeline-content">
                        <strong>
                            <?php echo $payment['payment_status'] == 'Verified' ? 'Payment Verified' : 'Payment Rejected'; ?>
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
    <div class="payment-aside"><!-- right column (was .sidebar, which collided with the app sidebar) -->
    
   
        <!-- Reject Form (Admin Only) -->
        <?php if (ims_allowed('payments.verify', ['Administrator', 'Operations/Admin'], 'Verify or reject member payments') && $payment['payment_status'] == 'Pending'): ?>
        <div id="rejectForm" style="display: none;">
            <div class="action-card">
                <h3>Reject Payment</h3>
                <form method="POST" action="process-receipt-verification.php">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="payment_id" value="<?php echo $payment_id; ?>">
                    
                    <div class="form-group">
                        <label for="rejection_reason" class="required">Rejection Reason</label>
                        <textarea id="rejection_reason" name="rejection_reason" 
                                  class="form-control" rows="4" required
                                  placeholder="Explain why this payment is being rejected..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times"></i> Confirm Reject
                        </button>
                        <button type="button" onclick="hideRejectForm()" class="btn btn-secondary">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function verifyPayment() {
    if (confirm('Verify this payment?\n\nThis will activate the member\'s subscription.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process-receipt-verification.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'verify';
        form.appendChild(actionInput);
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'payment_id';
        idInput.value = <?php echo $payment_id; ?>;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function showRejectForm() {
    document.getElementById('rejectForm').style.display = 'block';
    document.querySelector('.action-card').style.display = 'none';
}

function hideRejectForm() {
    document.getElementById('rejectForm').style.display = 'none';
    document.querySelector('.action-card').style.display = 'block';
}
</script>