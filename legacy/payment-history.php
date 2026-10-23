<?php

$page_title = 'Payment History';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get member details
$member_query = "SELECT * FROM members m 
                 LEFT JOIN users u ON m.user_id = u.user_id 
                 WHERE m.user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    echo "<div class='alert alert-danger'>Member profile not found. Please contact administrator.</div>";
    include 'includes/footer.php';
    exit();
}

$member = $member_result->fetch_assoc();
$member_id = $member['member_id'];

// Get filter parameters
$filter_status = isset($_GET['status']) ? $conn->real_escape_string(sanitize_input($_GET['status'])) : '';
$filter_plan = isset($_GET['plan']) ? $conn->real_escape_string(sanitize_input($_GET['plan'])) : '';
$filter_year = isset($_GET['year']) ? $conn->real_escape_string(sanitize_input($_GET['year'])) : '';
$filter_month = isset($_GET['month']) ? $conn->real_escape_string(sanitize_input($_GET['month'])) : '';

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where = "member_id = $member_id";

if ($filter_status) {
    $where .= " AND payment_status = '$filter_status'";
}

if ($filter_plan) {
    $where .= " AND subscription_plan = '$filter_plan'";
}

if ($filter_year) {
    $where .= " AND YEAR(created_at) = '$filter_year'";
}

if ($filter_month) {
    $where .= " AND MONTH(created_at) = '$filter_month'";
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM subscription_payments WHERE $where";
$count_result = $conn->query($count_query);
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Fetch payments
$payments = [];
$query = "SELECT * FROM subscription_payments 
          WHERE $where 
          ORDER BY created_at DESC 
          LIMIT $per_page OFFSET $offset";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}

// Calculate statistics
$stats = [];

// Total amount paid
$stats['total_paid'] = $conn->query("SELECT SUM(amount) as total FROM subscription_payments 
                                     WHERE member_id = $member_id 
                                     AND payment_status = 'Paid'")->fetch_assoc()['total'] ?? 0;

// Total pending
$stats['pending_amount'] = $conn->query("SELECT SUM(amount) as total FROM subscription_payments 
                                         WHERE member_id = $member_id 
                                         AND payment_status = 'Pending'")->fetch_assoc()['total'] ?? 0;

// Total payments
$stats['total_count'] = $conn->query("SELECT COUNT(*) as count FROM subscription_payments 
                                      WHERE member_id = $member_id")->fetch_assoc()['count'];

// Average payment
$stats['avg_payment'] = $stats['total_count'] > 0 ? $stats['total_paid'] / $stats['total_count'] : 0;

// Get available years for filter
$years = [];
$years_query = "SELECT DISTINCT YEAR(created_at) as year 
                FROM subscription_payments 
                WHERE member_id = $member_id 
                ORDER BY year DESC";
$years_result = $conn->query($years_query);
while ($row = $years_result->fetch_assoc()) {
    $years[] = $row['year'];
}
?>

<style>
/* Payment History: amount/status colouring, row actions and pager label. */
.ph-filters .filters-bar { margin-bottom: 0; }
.ph-invoice { font-weight: 600; color: var(--brand-600); }
.ph-amount { font-size: 15px; font-weight: 700; }
.ph-amount.is-paid { color: var(--green-fg); }
.ph-amount.is-pending { color: var(--amber-fg); }
.ph-amount.is-cancelled { color: var(--red-fg); text-decoration: line-through; }
.ph-paid-date { color: var(--green-fg); }
.ph-actions { display: flex; gap: 5px; }
.ph-page-info { margin-left: 10px; align-self: center; }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-history" aria-hidden="true"></i> Payment History</h1>
        <p>Complete record of your subscription payments.</p>
    </div>
    <div class="hero-actions">
        <a href="my-subscription" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Subscription
        </a>
        <a href="export?member_id=<?php echo $member_id; ?>&format=csv" class="btn">
            <i class="fas fa-file-csv" aria-hidden="true"></i> Export to CSV
        </a>
        <a href="export?member_id=<?php echo $member_id; ?>&format=excel" class="btn">
            <i class="fas fa-file-excel" aria-hidden="true"></i> Export to Excel
        </a>
        <a href="export?member_id=<?php echo $member_id; ?>&format=pdf" class="btn btn-primary">
            <i class="fas fa-file-pdf" aria-hidden="true"></i> Export to PDF
        </a>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
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
            <span class="stat-label">Total Transactions</span>
            <span class="stat-value"><?php echo $stats['total_count']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-calculator" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Average Payment</span>
            <span class="stat-value"><?php echo format_currency($stats['avg_payment']); ?></span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card ph-filters">
    <div class="card-body">
<form method="GET" action="">
    <div class="filters-bar" role="search" aria-label="Filter payments">
        <div class="form-group">
            <label for="status">Payment Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Paid" <?php echo $filter_status == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="Partially Paid" <?php echo $filter_status == 'Partially Paid' ? 'selected' : ''; ?>>Partially Paid</option>
                <option value="Overdue" <?php echo $filter_status == 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                <option value="Cancelled" <?php echo $filter_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="plan">Subscription Plan</label>
            <select name="plan" id="plan" class="form-control">
                <option value="">All Plans</option>
                <option value="Daily" <?php echo $filter_plan == 'Daily' ? 'selected' : ''; ?>>Daily</option>
                <option value="Weekly" <?php echo $filter_plan == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                <option value="Monthly" <?php echo $filter_plan == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                <option value="Quarterly" <?php echo $filter_plan == 'Quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                <option value="Annual" <?php echo $filter_plan == 'Annual' ? 'selected' : ''; ?>>Annual</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="year">Year</label>
            <select name="year" id="year" class="form-control">
                <option value="">All Years</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="month">Month</label>
            <select name="month" id="month" class="form-control">
                <option value="">All Months</option>
                <option value="1" <?php echo $filter_month == '1' ? 'selected' : ''; ?>>January</option>
                <option value="2" <?php echo $filter_month == '2' ? 'selected' : ''; ?>>February</option>
                <option value="3" <?php echo $filter_month == '3' ? 'selected' : ''; ?>>March</option>
                <option value="4" <?php echo $filter_month == '4' ? 'selected' : ''; ?>>April</option>
                <option value="5" <?php echo $filter_month == '5' ? 'selected' : ''; ?>>May</option>
                <option value="6" <?php echo $filter_month == '6' ? 'selected' : ''; ?>>June</option>
                <option value="7" <?php echo $filter_month == '7' ? 'selected' : ''; ?>>July</option>
                <option value="8" <?php echo $filter_month == '8' ? 'selected' : ''; ?>>August</option>
                <option value="9" <?php echo $filter_month == '9' ? 'selected' : ''; ?>>September</option>
                <option value="10" <?php echo $filter_month == '10' ? 'selected' : ''; ?>>October</option>
                <option value="11" <?php echo $filter_month == '11' ? 'selected' : ''; ?>>November</option>
                <option value="12" <?php echo $filter_month == '12' ? 'selected' : ''; ?>>December</option>
            </select>
        </div>
        
        <div class="filters-actions">
            <button type="submit" class="btn btn-info">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="payment-history" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </div>
</form>
    </div>
</div>

<!-- Payment Table -->
<?php if (empty($payments)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-receipt" aria-hidden="true"></i>
                <h3>No payment records found</h3>
                <p>
                    <?php if ($filter_status || $filter_plan || $filter_year || $filter_month): ?>
                        No payments match your filter criteria.
                    <?php else: ?>
                        You don't have any payment history yet.
                    <?php endif; ?>
                </p>
                <a href="payment-history" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-list" aria-hidden="true"></i> Payments (<?php echo $total_records; ?>)</h3></div>
        <div class="card-body">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Plan</th>
                    <th>Period</th>
                    <th>Amount</th>
                    <th>Payment Date</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $payment): ?>
                <tr>
                    <td>
                        <span class="ph-invoice"><?php echo htmlspecialchars($payment['invoice_number']); ?></span>
                    </td>
                    <td>
                        <span class="badge badge-info">
                            <?php echo htmlspecialchars($payment['subscription_plan']); ?>
                        </span>
                    </td>
                    <td>
                        <?php echo date('d M', strtotime($payment['payment_period_start'])); ?> -
                        <?php echo date('d M Y', strtotime($payment['payment_period_end'])); ?>
                    </td>
                    <td>
                        <?php
                        $amount_class = 'is-paid';
                        if ($payment['payment_status'] == 'Pending' || $payment['payment_status'] == 'Overdue') {
                            $amount_class = 'is-pending';
                        } elseif ($payment['payment_status'] == 'Cancelled') {
                            $amount_class = 'is-cancelled';
                        }
                        ?>
                        <div class="ph-amount <?php echo $amount_class; ?>">
                            <?php echo format_currency($payment['amount']); ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($payment['payment_date']): ?>
                            <div class="ph-paid-date">
                                <i class="fas fa-check-circle"></i>
                                <?php echo date('d M Y', strtotime($payment['payment_date'])); ?>
                            </div>
                        <?php else: ?>
                            <div class="cell-muted">
                                <i class="fas fa-minus-circle"></i>
                                Not paid
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($payment['payment_method']): ?>
                            <?php echo htmlspecialchars($payment['payment_method']); ?>
                        <?php else: ?>
                            <span class="cell-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $status_badges = [
                            'Pending' => 'warning',
                            'Paid' => 'success',
                            'Partially Paid' => 'info',
                            'Overdue' => 'danger',
                            'Cancelled' => 'secondary'
                        ];
                        $badge = $status_badges[$payment['payment_status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?php echo $badge; ?>">
                            <?php echo $payment['payment_status']; ?>
                        </span>
                    </td>
                    <td>
                        <div class="ph-actions">
                            <a href="view-payment?id=<?php echo $payment['payment_id']; ?>" 
                               class="btn btn-info btn-sm" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            
                            <?php if ($payment['payment_status'] == 'Pending'): ?>
                                <a href="make-payment?payment_id=<?php echo $payment['payment_id']; ?>" 
                                   class="btn btn-success btn-sm" title="Make Payment">
                                    <i class="fas fa-money-bill"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($payment['receipt_path']): ?>
                                <a href="<?php echo htmlspecialchars(ims_upload_url($payment['receipt_path'])); ?>" 
                                   target="_blank" class="btn btn-secondary btn-sm" title="View Receipt">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($payment['invoice_path']): ?>
                                <a href="<?php echo htmlspecialchars(ims_upload_url($payment['invoice_path'])); ?>" 
                                   target="_blank" class="btn btn-primary btn-sm" title="Download Invoice">
                                    <i class="fas fa-download"></i>
                                </a>
                            <?php elseif ($payment['payment_status'] != 'Cancelled'): ?>
                                <a href="generate-invoice?payment_id=<?php echo $payment['payment_id']; ?>" 
                                   class="btn btn-primary btn-sm" title="Generate Invoice">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1<?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_plan ? "&plan=$filter_plan" : ''; ?><?php echo $filter_year ? "&year=$filter_year" : ''; ?><?php echo $filter_month ? "&month=$filter_month" : ''; ?>">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="?page=<?php echo $page - 1; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_plan ? "&plan=$filter_plan" : ''; ?><?php echo $filter_year ? "&year=$filter_year" : ''; ?><?php echo $filter_month ? "&month=$filter_month" : ''; ?>">
                <i class="fas fa-angle-left"></i>
            </a>
        <?php endif; ?>
        
        <?php
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        
        for ($i = $start; $i <= $end; $i++):
        ?>
            <a href="?page=<?php echo $i; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_plan ? "&plan=$filter_plan" : ''; ?><?php echo $filter_year ? "&year=$filter_year" : ''; ?><?php echo $filter_month ? "&month=$filter_month" : ''; ?>" 
               class="<?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_plan ? "&plan=$filter_plan" : ''; ?><?php echo $filter_year ? "&year=$filter_year" : ''; ?><?php echo $filter_month ? "&month=$filter_month" : ''; ?>">
                <i class="fas fa-angle-right"></i>
            </a>
            <a href="?page=<?php echo $total_pages; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_plan ? "&plan=$filter_plan" : ''; ?><?php echo $filter_year ? "&year=$filter_year" : ''; ?><?php echo $filter_month ? "&month=$filter_month" : ''; ?>">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
        
        <span class="cell-muted ph-page-info">
            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
        </span>
    </div>
    <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>