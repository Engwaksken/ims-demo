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
/* My Subscription: overview tiles, detail tiles and the payments table extras. */
.ms-overview { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px; }
.ms-tile { text-align: center; padding: 22px; background: var(--ink-50); border-radius: var(--radius-lg); border: 1px solid var(--ink-100); }
.ms-tile > i { font-size: 24px; }
.ms-tile-value { font-size: 22px; margin: 10px 0; font-weight: 700; color: var(--ink-900); }
.ms-tile p { margin: 0; font-size: 14px; color: var(--ink-400); }
.ms-tile.is-primary { background: var(--brand-50); border-color: var(--brand-200); }
.ms-tile.is-primary > i,
.ms-tile.is-primary .ms-tile-value { color: var(--brand-600); }
.ms-tile.is-primary > i { font-size: 32px; }
.ms-tile.is-primary .ms-tile-value { font-size: 34px; }
.ms-icon-brand { color: var(--brand-500); }
.ms-icon-success { color: var(--green-fg); }
.ms-icon-warning { color: var(--amber-fg); }
.ms-icon-danger { color: var(--red-fg); }
.ms-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
.ms-info-item { padding: 15px; background: var(--ink-50); border-radius: var(--radius-md); }
.ms-info-item label { display: block; font-size: 12px; color: var(--ink-300); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
.ms-info-value { font-size: 16px; color: var(--ink-900); font-weight: 600; }
.ms-on { color: var(--green-fg); }
.ms-off { color: var(--red-fg); }
.ms-amount { color: var(--green-fg); font-weight: 700; white-space: nowrap; }
.ms-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.ms-more { text-align: center; margin-top: 20px; }
.ms-banner-body { flex: 1; }
.ms-banner-body p { margin: 5px 0 0; }
.ms-check-label { display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; letter-spacing: 0; }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-credit-card" aria-hidden="true"></i> My Subscription</h1>
        <p>Manage your membership and subscription details.</p>
    </div>
    <div class="hero-actions">
        <a href="payment-history" class="btn">
            <i class="fas fa-history" aria-hidden="true"></i> Full Payment History
        </a>
        <button onclick="openModal('autoRenewModal')" class="btn">
            <i class="fas fa-cog" aria-hidden="true"></i> Auto-Renew Settings
        </button>
        <button onclick="openModal('upgradeModal')" class="btn">
            <i class="fas fa-arrow-up" aria-hidden="true"></i> Change Plan
        </button>
        <?php if ($days_remaining <= 30 || $status == 'Expired'): ?>
            <button onclick="openModal('renewModal')" class="btn btn-primary">
                <i class="fas fa-sync" aria-hidden="true"></i> Renew Subscription
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($status == 'Expiring Soon'): ?>
<div class="alert alert-warning">
    <i class="fas fa-bell" aria-hidden="true"></i>
    <div class="ms-banner-body">
        <strong>Subscription Expiring Soon!</strong>
        <p>Your subscription will expire in <strong><?php echo $days_remaining; ?> days</strong>. Renew now to continue enjoying all membership benefits.</p>
    </div>
    <button onclick="openModal('renewModal')" class="btn btn-warning">
        <i class="fas fa-sync"></i> Renew Now
    </button>
</div>
<?php elseif ($status == 'Expired'): ?>
<div class="alert alert-danger">
    <i class="fas fa-times-circle" aria-hidden="true"></i>
    <div class="ms-banner-body">
        <strong>Subscription Expired</strong>
        <p>Your subscription expired on <?php echo date('d M Y', strtotime($member['subscription_end_date'])); ?>. Please renew to continue using our services.</p>
    </div>
</div>
<?php elseif ($status == 'Active'): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle" aria-hidden="true"></i>
    <div class="ms-banner-body">
        <strong>Subscription Active</strong>
        <p>Your subscription is active and in good standing. Expires on <?php echo date('d M Y', strtotime($member['subscription_end_date'])); ?>.</p>
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

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-info-circle" aria-hidden="true"></i> Subscription Details</h3>
    </div>
    <div class="card-body">
        <div class="ms-overview">
            <div class="ms-tile is-primary">
                <i class="fas fa-calendar"></i>
                <div class="ms-tile-value"><?php echo abs($days_remaining); ?></div>
                <p><?php echo $days_remaining >= 0 ? 'Days Remaining' : 'Days Overdue'; ?></p>
            </div>

            <div class="ms-tile">
                <i class="fas fa-tag ms-icon-brand"></i>
                <div class="ms-tile-value"><?php echo htmlspecialchars($member['subscription_plan']); ?></div>
                <p>Current Plan</p>
            </div>

            <div class="ms-tile">
                <i class="fas fa-money-bill ms-icon-success"></i>
                <div class="ms-tile-value"><?php echo format_currency($member['subscription_amount']); ?></div>
                <p>Monthly Amount</p>
            </div>

            <div class="ms-tile">
                <i class="fas fa-<?php echo $status_icon; ?> <?php
                    echo $status_color == 'success' ? 'ms-icon-success' :
                         ($status_color == 'warning' ? 'ms-icon-warning' : 'ms-icon-danger');
                ?>"></i>
                <div class="ms-tile-value"><?php echo $status; ?></div>
                <p>Status</p>
            </div>
        </div>

        <div class="ms-info-grid">
            <div class="ms-info-item">
                <label>Membership Number</label>
                <div class="ms-info-value">
                    <code><?php echo htmlspecialchars($member['membership_number']); ?></code>
                </div>
            </div>

            <div class="ms-info-item">
                <label>Member Type</label>
                <div class="ms-info-value"><?php echo htmlspecialchars($member['member_type']); ?></div>
            </div>

            <div class="ms-info-item">
                <label>Registration Date</label>
                <div class="ms-info-value"><?php echo date('d M Y', strtotime($member['registration_date'])); ?></div>
            </div>

            <div class="ms-info-item">
                <label>Start Date</label>
                <div class="ms-info-value"><?php echo date('d M Y', strtotime($member['subscription_start_date'])); ?></div>
            </div>

            <div class="ms-info-item">
                <label>End Date</label>
                <div class="ms-info-value"><?php echo date('d M Y', strtotime($member['subscription_end_date'])); ?></div>
            </div>

            <div class="ms-info-item">
                <label>Auto-Renew</label>
                <div class="ms-info-value">
                    <?php if ($member['auto_renew']): ?>
                        <span class="ms-on"><i class="fas fa-check"></i> Enabled</span>
                    <?php else: ?>
                        <span class="ms-off"><i class="fas fa-times"></i> Disabled</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history" aria-hidden="true"></i> Recent Payment History</h3>
    </div>

    <div class="card-body">
        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt" aria-hidden="true"></i>
                <p>No payment history available</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
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
                                    <span class="badge badge-info">
                                        <?php echo htmlspecialchars($payment['subscription_plan'] ?? '-'); ?>
                                    </span>
                                </td>

                                <td class="ms-amount">
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
                                    <div class="ms-actions">
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
                                            <span class="cell-muted">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($payments) >= 10): ?>
                <div class="ms-more">
                    <a href="payment-history" class="btn btn-primary">
                        <i class="fas fa-list"></i> View All Payments
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.page-stack -->

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
                    <label class="ms-check-label">
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