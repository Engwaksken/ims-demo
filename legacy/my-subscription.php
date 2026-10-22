<?php
$page_title = 'My Subscription';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$member_query = "SELECT m.*, u.full_name, u.email 
                 FROM members m 
                 LEFT JOIN users u ON m.user_id = u.user_id 
                 WHERE m.user_id = $user_id";
$member_result = $conn->query($member_query);

if (!$member_result || $member_result->num_rows == 0) {
    echo "<div class='alert alert-danger'>Member profile not found. Please contact administrator.</div>";
    include 'includes/footer.php';
    exit();
}

$member = $member_result->fetch_assoc();
$member_id = (int) $member['member_id'];

$subscription_end = strtotime($member['subscription_end_date']);
$today = strtotime('today');
$days_remaining = ceil(($subscription_end - $today) / (60 * 60 * 24));

$status = 'Active';
$status_color = 'success';
$status_icon = 'check-circle';

if ($member['membership_status'] == 'Expired' || $days_remaining < 0) {
    $status = 'Expired';
    $status_color = 'danger';
    $status_icon = 'times-circle';
} elseif ($days_remaining <= 7) {
    $status = 'Expiring Soon';
    $status_color = 'warning';
    $status_icon = 'exclamation-triangle';
} elseif ($member['membership_status'] == 'Suspended') {
    $status = 'Suspended';
    $status_color = 'secondary';
    $status_icon = 'pause-circle';
}

$payments = [];
$query = "SELECT * FROM subscription_payments 
          WHERE member_id = $member_id 
          ORDER BY created_at DESC 
          LIMIT 10";
$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
}

$stats = [];

$stats['total_paid'] = $conn->query("SELECT SUM(amount) as total FROM subscription_payments 
                                     WHERE member_id = $member_id 
                                     AND payment_status = 'Paid'")->fetch_assoc()['total'] ?? 0;

$stats['pending_amount'] = $conn->query("SELECT SUM(amount) as total FROM subscription_payments 
                                         WHERE member_id = $member_id 
                                         AND payment_status = 'Pending'")->fetch_assoc()['total'] ?? 0;

$stats['payment_count'] = $conn->query("SELECT COUNT(*) as count FROM subscription_payments 
                                        WHERE member_id = $member_id")->fetch_assoc()['count'] ?? 0;

$registration_date = strtotime($member['registration_date']);
$stats['days_member'] = ceil((time() - $registration_date) / (60 * 60 * 24));
?>

<style>
.subscription-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.subscription-header h1 {
    font-size: 32px;
    margin-bottom: 5px;
}

.subscription-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.subscription-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.overview-item {
    text-align: center;
    padding: 25px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px solid #ecf0f1;
}

.overview-item.primary {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    border: none;
}

.overview-item h3 {
    font-size: 36px;
    margin: 10px 0;
    font-weight: 700;
}

.overview-item p {
    margin: 0;
    opacity: 0.8;
    font-size: 14px;
}

.status-banner {
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.status-banner.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.status-banner.warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

.status-banner.danger {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.status-banner i {
    font-size: 32px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 25px 0;
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
    font-size: 18px;
    color: #2c3e50;
    font-weight: 600;
}

.payment-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.payment-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

.payment-table thead {
    background: #f8f9fa;
}

.payment-table th {
    padding: 14px 12px;
    text-align: left;
    font-size: 13px;
    color: #2c3e50;
    border-bottom: 2px solid #ecf0f1;
    white-space: nowrap;
}

.payment-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #ecf0f1;
    font-size: 14px;
    color: #2c3e50;
    vertical-align: middle;
}

.payment-table tbody tr:hover {
    background: #fff8f3;
}

.payment-table .amount {
    color: #2ECC71;
    font-weight: 700;
    white-space: nowrap;
}

.payment-table .actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.payment-table code {
    background: #f1f3f5;
    padding: 4px 6px;
    border-radius: 4px;
    font-size: 13px;
}

.renewal-reminder {
    background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
    color: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    text-align: center;
}

.renewal-reminder h3 {
    margin: 0 0 10px 0;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #7f8c8d;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    color: #bdc3c7;
}
</style>

<div class="subscription-header">
    <h1><i class="fas fa-credit-card"></i> My Subscription</h1>
    <p style="margin: 0; opacity: 0.9;">Manage your membership and subscription details</p>
</div>

<?php if ($status == 'Expiring Soon'): ?>
<div class="renewal-reminder">
    <h3><i class="fas fa-bell"></i> Subscription Expiring Soon!</h3>
    <p style="margin: 10px 0;">Your subscription will expire in <strong><?php echo $days_remaining; ?> days</strong>. Renew now to continue enjoying all membership benefits.</p>
    <button onclick="openModal('renewModal')" class="btn btn-light btn-lg" style="margin-top: 10px;">
        <i class="fas fa-sync"></i> Renew Now
    </button>
</div>
<?php elseif ($status == 'Expired'): ?>
<div class="status-banner danger">
    <i class="fas fa-times-circle"></i>
    <div>
        <strong>Subscription Expired</strong>
        <p style="margin: 5px 0 0 0;">Your subscription expired on <?php echo date('d M Y', strtotime($member['subscription_end_date'])); ?>. Please renew to continue using our services.</p>
    </div>
</div>
<?php elseif ($status == 'Active'): ?>
<div class="status-banner success">
    <i class="fas fa-check-circle"></i>
    <div>
        <strong>Subscription Active</strong>
        <p style="margin: 5px 0 0 0;">Your subscription is active and in good standing. Expires on <?php echo date('d M Y', strtotime($member['subscription_end_date'])); ?>.</p>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Days as Member</span>
            <span class="stat-value"><?php echo $stats['days_member']; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Paid</span>
            <span class="stat-value"><?php echo format_currency($stats['total_paid']); ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Payments</span>
            <span class="stat-value"><?php echo format_currency($stats['pending_amount']); ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-receipt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Payments</span>
            <span class="stat-value"><?php echo $stats['payment_count']; ?></span>
        </div>
    </div>
</div>

<div class="subscription-card">
    <h3 style="margin-bottom: 25px; color: #2c3e50;">
        <i class="fas fa-info-circle"></i> Subscription Details
    </h3>

    <div class="subscription-overview">
        <div class="overview-item primary">
            <i class="fas fa-calendar" style="font-size: 36px;"></i>
            <h3><?php echo abs($days_remaining); ?></h3>
            <p><?php echo $days_remaining >= 0 ? 'Days Remaining' : 'Days Overdue'; ?></p>
        </div>

        <div class="overview-item">
            <i class="fas fa-tag" style="font-size: 24px; color: #ff6b35;"></i>
            <h3 style="font-size: 24px;"><?php echo htmlspecialchars($member['subscription_plan']); ?></h3>
            <p>Current Plan</p>
        </div>

        <div class="overview-item">
            <i class="fas fa-money-bill" style="font-size: 24px; color: #2ECC71;"></i>
            <h3 style="font-size: 24px;"><?php echo format_currency($member['subscription_amount']); ?></h3>
            <p>Monthly Amount</p>
        </div>

        <div class="overview-item">
            <i class="fas fa-<?php echo $status_icon; ?>" style="font-size: 24px; color: <?php
                echo $status_color == 'success' ? '#2ECC71' :
                     ($status_color == 'warning' ? '#F39C12' : '#E74C3C');
            ?>;"></i>
            <h3 style="font-size: 24px;"><?php echo $status; ?></h3>
            <p>Status</p>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <label>Membership Number</label>
            <div class="value">
                <code><?php echo htmlspecialchars($member['membership_number']); ?></code>
            </div>
        </div>

        <div class="info-item">
            <label>Member Type</label>
            <div class="value"><?php echo htmlspecialchars($member['member_type']); ?></div>
        </div>

        <div class="info-item">
            <label>Registration Date</label>
            <div class="value"><?php echo date('d M Y', strtotime($member['registration_date'])); ?></div>
        </div>

        <div class="info-item">
            <label>Start Date</label>
            <div class="value"><?php echo date('d M Y', strtotime($member['subscription_start_date'])); ?></div>
        </div>

        <div class="info-item">
            <label>End Date</label>
            <div class="value"><?php echo date('d M Y', strtotime($member['subscription_end_date'])); ?></div>
        </div>

        <div class="info-item">
            <label>Auto-Renew</label>
            <div class="value">
                <?php if ($member['auto_renew']): ?>
                    <span style="color: #2ECC71;"><i class="fas fa-check"></i> Enabled</span>
                <?php else: ?>
                    <span style="color: #E74C3C;"><i class="fas fa-times"></i> Disabled</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="display: flex; gap: 10px; margin-top: 25px; flex-wrap: wrap;">
        <?php if ($days_remaining <= 30 || $status == 'Expired'): ?>
            <button onclick="openModal('renewModal')" class="btn btn-primary">
                <i class="fas fa-sync"></i> Renew Subscription
            </button>
        <?php endif; ?>

        <button onclick="openModal('upgradeModal')" class="btn btn-success">
            <i class="fas fa-arrow-up"></i> Change Plan
        </button>

        <button onclick="openModal('autoRenewModal')" class="btn btn-info">
            <i class="fas fa-cog"></i> Auto-Renew Settings
        </button>

        <a href="payment-history" class="btn btn-secondary">
            <i class="fas fa-history"></i> Full Payment History
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Recent Payment History</h3>
    </div>

    <div class="card-body">
        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <p>No payment history available</p>
            </div>
        <?php else: ?>
            <div class="payment-table-wrapper">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Period</th>
                            <th>Payment Date</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <?php
                            $status_badges = [
                                'Pending' => 'warning',
                                'Paid' => 'success',
                                'Partially Paid' => 'info',
                                'Overdue' => 'danger',
                                'Cancelled' => 'secondary'
                            ];

                            $badge = $status_badges[$payment['payment_status']] ?? 'secondary';

                            $period_start = !empty($payment['payment_period_start'])
                                ? date('d M', strtotime($payment['payment_period_start']))
                                : '-';

                            $period_end = !empty($payment['payment_period_end'])
                                ? date('d M Y', strtotime($payment['payment_period_end']))
                                : '-';

                            $payment_date = !empty($payment['payment_date'])
                                ? date('d M Y', strtotime($payment['payment_date']))
                                : '-';

                            $created_at = !empty($payment['created_at'])
                                ? date('d M Y', strtotime($payment['created_at']))
                                : '-';
                            ?>

                            <tr>
                                <td>
                                    <code><?php echo htmlspecialchars($payment['invoice_number'] ?? '-'); ?></code>
                                </td>

                                <td>
                                    <span class="badge badge-primary">
                                        <?php echo htmlspecialchars($payment['subscription_plan'] ?? '-'); ?>
                                    </span>
                                </td>

                                <td class="amount">
                                    <?php echo format_currency($payment['amount'] ?? 0); ?>
                                </td>

                                <td>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo htmlspecialchars($payment['payment_status'] ?? '-'); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php echo $period_start . ' - ' . $period_end; ?>
                                </td>

                                <td>
                                    <?php echo $payment_date; ?>
                                </td>

                                <td>
                                    <?php echo !empty($payment['payment_method']) ? htmlspecialchars($payment['payment_method']) : '-'; ?>
                                </td>

                                <td>
                                    <?php echo !empty($payment['payment_reference']) ? htmlspecialchars($payment['payment_reference']) : '-'; ?>
                                </td>

                                <td>
                                    <?php echo $created_at; ?>
                                </td>

                                <td>
                                    <div class="actions">
                                        <?php if (($payment['payment_status'] ?? '') == 'Pending'): ?>
                                            <a href="make-payment?payment_id=<?php echo (int) $payment['payment_id']; ?>" class="btn btn-success btn-sm">
                                                <i class="fas fa-money-bill"></i> Pay
                                            </a>

                                            <a href="upload-receipt?payment_id=<?php echo (int) $payment['payment_id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-upload"></i> Upload
                                            </a>
                                        <?php endif; ?>

                                        <?php if (!empty($payment['receipt_path'])): ?>
                                            <a href="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'])); ?>" target="_blank" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-file-invoice"></i> Receipt
                                            </a>
                                        <?php endif; ?>

                                        <?php if (($payment['payment_status'] ?? '') != 'Pending' && empty($payment['receipt_path'])): ?>
                                            <span style="color: #7f8c8d;">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($payments) >= 10): ?>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="payment-history" class="btn btn-primary">
                        <i class="fas fa-list"></i> View All Payments
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div id="renewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Renew Subscription</h3>
            <span class="close" onclick="closeModal('renewModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/process-subscription.php">
                <input type="hidden" name="action" value="renew">
                <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Renewing will extend your subscription by one billing period.
                </div>

                <div class="form-group">
                    <label>Current Plan</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['subscription_plan']); ?>" readonly>
                </div>

                <div class="form-group">
                    <label>Amount</label>
                    <input type="text" class="form-control" value="<?php echo format_currency($member['subscription_amount']); ?>" readonly>
                </div>

                <div class="form-group">
                    <label>New End Date</label>
                    <?php
                    $billing_periods = [
                        'Daily' => '+1 day',
                        'Weekly' => '+1 week',
                        'Monthly' => '+1 month',
                        'Quarterly' => '+3 months',
                        'Annual' => '+1 year'
                    ];

                    $new_end = strtotime(
                        $billing_periods[$member['subscription_plan']] ?? '+1 month',
                        strtotime($member['subscription_end_date'])
                    );
                    ?>
                    <input type="text" class="form-control" value="<?php echo date('d M Y', $new_end); ?>" readonly>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('renewModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-sync"></i> Proceed to Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="upgradeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Change Plan</h3>
            <span class="close" onclick="closeModal('upgradeModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/process-subscription.php">
                <input type="hidden" name="action" value="upgrade">
                <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">

                <div class="form-group">
                    <label>Current Plan: <strong><?php echo htmlspecialchars($member['subscription_plan']); ?></strong></label>
                </div>

                <div class="form-group">
                    <label for="new_plan" class="required">New Plan</label>
                    <select id="new_plan" name="new_plan" class="form-control" required>
                        <option value="">Select Plan</option>

                        <?php if ($member['subscription_plan'] != 'Weekly'): ?>
                            <option value="Weekly">Weekly - Flexible weekly access</option>
                        <?php endif; ?>

                        <?php if ($member['subscription_plan'] != 'Monthly'): ?>
                            <option value="Monthly">Monthly - Best for regular users</option>
                        <?php endif; ?>

                        <?php if ($member['subscription_plan'] != 'Quarterly'): ?>
                            <option value="Quarterly">Quarterly</option>
                        <?php endif; ?>

                        <?php if ($member['subscription_plan'] != 'Annual'): ?>
                            <option value="Annual">Annual</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Your request goes to the Hive team, who will confirm the new plan and amount with you before anything changes.
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('upgradeModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane"></i> Send Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="autoRenewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Auto-Renew Settings</h3>
            <span class="close" onclick="closeModal('autoRenewModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/process-subscription.php">
                <input type="hidden" name="action" value="toggle_auto_renew">
                <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="auto_renew" value="1"
                            <?php echo $member['auto_renew'] ? 'checked' : ''; ?>>
                        <span>Enable automatic subscription renewal</span>
                    </label>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> When enabled, your subscription will automatically renew before expiration. You will be notified 7 days before renewal.
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('autoRenewModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>