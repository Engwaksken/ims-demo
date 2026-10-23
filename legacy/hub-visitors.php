<?php

$page_title = 'Hub Visitors';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
include 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';

check_role(IMS_STAFF_ROLES);
// Build shareable public registration URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
// Pages are served from /legacy/ internally but publicly live at the site root.
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . preg_replace('#/legacy$#', '', rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/'));
$register_url = rtrim($base_url, '/') . '/visitor-register';

// Get filter parameters
$filter_date    = isset($_GET['date'])    ? sanitize_input($_GET['date'])    : '';
$filter_purpose = isset($_GET['purpose']) ? sanitize_input($_GET['purpose']) : '';
$filter_month   = isset($_GET['month'])   ? sanitize_input($_GET['month'])   : '';
$search         = isset($_GET['search'])  ? sanitize_input($_GET['search'])  : '';

// Build WHERE clause (values escaped for SQL; filters validated)
if ($filter_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) { $filter_date = ''; }
if ($filter_month !== '' && !preg_match('/^\d{4}-\d{2}$/', $filter_month)) { $filter_month = ''; }
$sql_purpose = $conn->real_escape_string($filter_purpose);
$sql_search  = $conn->real_escape_string(addcslashes($search, '%_'));
$where = "1=1";
if ($filter_date) {
    $where .= " AND visit_date = '$filter_date'";
} elseif ($filter_month) {
    $where .= " AND DATE_FORMAT(visit_date, '%Y-%m') = '$filter_month'";
}
if ($filter_purpose) {
    $where .= " AND purpose = '$sql_purpose'";
}
if ($search) {
    $where .= " AND (visitor_name LIKE '%$sql_search%' OR organization LIKE '%$sql_search%' OR email LIKE '%$sql_search%' OR phone LIKE '%$sql_search%')";
}

// Fetch visitors with server-side pagination
$per_page = 25;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$count_result = $conn->query("SELECT COUNT(*) AS total FROM hub_visitors WHERE $where");
$visitor_total = $count_result ? (int)$count_result->fetch_assoc()['total'] : 0;
$total_pages = max(1, (int)ceil($visitor_total / $per_page));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $per_page;

// Fetch visitors
$visitors = [];
$query  = "SELECT * FROM hub_visitors WHERE $where ORDER BY visit_date DESC, visit_time DESC LIMIT $per_page OFFSET $offset";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $visitors[] = $row;
}

// -- Statistics --------------------------------------------------------------
$stats = [];
$stats['today']     = $conn->query("SELECT COUNT(*) as c FROM hub_visitors WHERE visit_date = CURDATE()")->fetch_assoc()['c'];
$stats['this_week'] = $conn->query("SELECT COUNT(*) as c FROM hub_visitors WHERE YEARWEEK(visit_date,1)=YEARWEEK(CURDATE(),1)")->fetch_assoc()['c'];
$stats['this_month']= $conn->query("SELECT COUNT(*) as c FROM hub_visitors WHERE MONTH(visit_date)=MONTH(CURDATE()) AND YEAR(visit_date)=YEAR(CURDATE())")->fetch_assoc()['c'];
$stats['total']     = $conn->query("SELECT COUNT(*) as c FROM hub_visitors")->fetch_assoc()['c'];
$stats['unique']    = $conn->query("SELECT COUNT(DISTINCT email) as c FROM hub_visitors WHERE email IS NOT NULL AND email != ''")->fetch_assoc()['c'];
$stats['daily_avg'] = round($conn->query("SELECT COUNT(*)/30 as a FROM hub_visitors WHERE visit_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)")->fetch_assoc()['a'], 1);
$yesterday          = $conn->query("SELECT COUNT(*) as c FROM hub_visitors WHERE visit_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)")->fetch_assoc()['c'];
$last_month         = $conn->query("SELECT COUNT(*) as c FROM hub_visitors WHERE MONTH(visit_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(visit_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))")->fetch_assoc()['c'];

// Get visitor for editing
$edit_visitor = null;
if (isset($_GET['edit'])) {
    $visitor_id   = intval($_GET['edit']);
    $result       = $conn->query("SELECT * FROM hub_visitors WHERE visitor_id = $visitor_id");
    $edit_visitor = $result->fetch_assoc();
}

$purposes = ['Meeting','Event','Co-working','Training','Consultation','Tour','Interview','Other'];
?>

<style>
/* Share-link card: icon, explanation, URL box and buttons in one row. */
.hv-share { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.hv-share-icon {
    width: 48px; height: 48px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    border-radius: var(--radius-lg);
    background: var(--brand-50); color: var(--brand-600); font-size: 22px;
}
.hv-share-info { flex: 1; min-width: 220px; }
.hv-share-info h4 { margin: 0 0 4px; font-size: 15px; color: var(--ink-900); }
.hv-share-info p { margin: 0; font-size: 13px; color: var(--ink-300); }
.hv-share-url {
    display: flex; align-items: center; gap: 8px;
    flex: 2; min-width: 0; flex-basis: 260px;
    padding: 8px 14px;
    background: var(--surface-hover);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-md);
}
.hv-share-url i { color: var(--brand-500); }
.hv-share-url span { flex: 1; font-size: 13px; color: var(--ink-700); word-break: break-all; user-select: all; }
.hv-share-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }

/* Quick date tiles: clickable shortcuts that filter the log. */
.hv-quick { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 20px; }
.hv-quick a {
    display: block; padding: 16px 12px; text-align: center;
    background: var(--surface-card);
    border: 1px solid var(--ink-50);
    border-left: 4px solid var(--brand-500);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    color: inherit; text-decoration: none;
    transition: transform .15s, box-shadow .15s;
}
.hv-quick a:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.hv-quick strong { display: block; font-size: 26px; margin-bottom: 4px; color: var(--brand-600); }
.hv-quick span { font-size: 12px; color: var(--ink-300); }

.hv-filters { margin-bottom: 20px; }
.hv-contact { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
.hv-contact + .hv-contact { margin-top: 5px; }

/* Pagination row under the log table. */
.hv-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 16px 0 2px; color: var(--ink-300); font-size: 13px; }
.hv-pagination nav { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.hv-pagination nav a,
.hv-pagination nav span {
    display: inline-flex; min-width: 36px; height: 36px; padding: 0 10px;
    align-items: center; justify-content: center;
    border: 1px solid var(--ink-100); border-radius: var(--radius-md);
    background: var(--surface-card); color: var(--ink-500);
    text-decoration: none; font-weight: 700;
}
.hv-pagination nav .current { background: var(--brand-500); border-color: var(--brand-500); color: var(--ink-0); }

/* Analytics charts. */
.hv-chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); gap: 20px; }
.hv-chart-grid h4, .hv-chart-wide h4 { margin: 0 0 15px; }
.hv-chart-wide { margin-top: 30px; }
.hv-chart { max-height: 300px; }
.hv-chart-sm { max-height: 250px; }

/* QR modal. */
#qrModal .modal-content { max-width: 440px; }
.hv-qr-body { text-align: center; padding: 30px 20px; }
.hv-qr-intro { color: var(--ink-400); margin-bottom: 20px; font-size: 14px; }
#qrCanvas { margin: 20px auto; display: block; }
.qr-url-text { font-size: 12px; color: var(--ink-300); word-break: break-all; margin-top: 8px; }
.hv-qr-actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; flex-wrap: wrap; }
#addVisitorModal .modal-content { max-width: 700px; }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-user-friends" aria-hidden="true"></i> Hub Visitors Log</h1>
        <p>Track and manage daily visitors to the hub.</p>
    </div>
    <div class="hero-actions">
        <button onclick="copyRegisterLink()" class="btn">
            <i class="fas fa-link" aria-hidden="true"></i> Copy Visitor Link
        </button>
        <button onclick="openModal('qrModal')" class="btn">
            <i class="fas fa-qrcode" aria-hidden="true"></i> Show QR Code
        </button>
        <button onclick="window.location.href='export?type=hub_visitors'" class="btn">
            <i class="fas fa-download" aria-hidden="true"></i> Export
        </button>
        <button onclick="openModal('addVisitorModal')" class="btn btn-primary">
            <i class="fas fa-plus" aria-hidden="true"></i> Log Visitor
        </button>
    </div>
</div>

<!-- Shareable registration link -->
<div class="card">
    <div class="card-body hv-share">
        <div class="hv-share-icon"><i class="fas fa-paper-plane" aria-hidden="true"></i></div>
        <div class="hv-share-info">
            <h4>Visitor Self-Registration Link</h4>
            <p>Share this link or QR code with visitors so they can log themselves in.</p>
        </div>
        <div class="hv-share-url">
            <i class="fas fa-globe" aria-hidden="true"></i>
            <span id="registerUrl"><?php echo htmlspecialchars($register_url); ?></span>
        </div>
        <div class="hv-share-actions">
            <button onclick="copyRegisterLink()" class="btn btn-soft btn-sm" title="Copy link">
                <i class="fas fa-copy" aria-hidden="true"></i> Copy
            </button>
            <button onclick="openModal('qrModal')" class="btn btn-soft btn-sm" title="QR Code">
                <i class="fas fa-qrcode" aria-hidden="true"></i> QR
            </button>
            <a href="<?php echo htmlspecialchars($register_url); ?>" target="_blank" class="btn btn-secondary btn-sm" title="Preview">
                <i class="fas fa-external-link-alt" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-user-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Today's Visitors</span>
            <span class="stat-value"><?php echo $stats['today']; ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-week" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">This Week</span>
            <span class="stat-value"><?php echo $stats['this_week']; ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">This Month</span>
            <span class="stat-value"><?php echo $stats['this_month']; ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Visitors</span>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-user-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">First Visit</span>
            <span class="stat-value"><?php echo $stats['unique']; ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-slate"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Daily Avg (30d)</span>
            <span class="stat-value"><?php echo $stats['daily_avg']; ?></span>
        </div>
    </div>
</div>

<div class="tab-shell">
<div class="tab-nav" role="tablist" aria-label="Visitor sections">
    <button type="button" class="tab-btn active" data-visitor-tab="log" role="tab" aria-selected="true"><i class="fas fa-list" aria-hidden="true"></i> Visitors Log</button>
    <button type="button" class="tab-btn" data-visitor-tab="analytics" role="tab" aria-selected="false"><i class="fas fa-chart-bar" aria-hidden="true"></i> Analytics</button>
</div>

<div class="tab-panels">
<section class="tab-panel active" data-visitor-panel="log" role="tabpanel">
        <!-- Filter Bar -->
        <form method="GET" action="" class="hv-filters">
            <div class="filters-bar">
                <div class="form-group filters-grow">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search name, org, email, phone..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <input type="date" name="date" class="form-control"
                           value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                <div class="form-group">
                    <input type="month" name="month" class="form-control"
                           value="<?php echo htmlspecialchars($filter_month); ?>">
                </div>
                <div class="form-group">
                    <select name="purpose" class="form-control">
                        <option value="">All Purposes</option>
                        <?php foreach ($purposes as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $filter_purpose == $p ? 'selected' : ''; ?>>
                                <?php echo $p; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filters-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter" aria-hidden="true"></i> Filter</button>
                    <a href="hub-visitors" class="btn btn-secondary"><i class="fas fa-times" aria-hidden="true"></i> Clear</a>
                </div>
            </div>
        </form>

        <!-- Quick Date Tiles -->
        <div class="hv-quick">
            <a href="?date=<?php echo date('Y-m-d'); ?>">
                <strong><?php echo $stats['today']; ?></strong><span>Today</span>
            </a>
            <a href="?date=<?php echo date('Y-m-d', strtotime('yesterday')); ?>">
                <strong><?php echo $yesterday; ?></strong><span>Yesterday</span>
            </a>
            <a href="?month=<?php echo date('Y-m'); ?>">
                <strong><?php echo $stats['this_month']; ?></strong><span>This Month</span>
            </a>
            <a href="?month=<?php echo date('Y-m', strtotime('last month')); ?>">
                <strong><?php echo $last_month; ?></strong><span>Last Month</span>
            </a>
        </div>

        <!-- Visitors Table -->
        <div class="table-responsive">
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th>Visitor</th>
                        <th>Contact</th>
                        <th>Organization</th>
                        <th>Visit Date</th>
                        <th>Time</th>
                        <th>Purpose</th>
                        <th>Host / Person to See</th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($visitors)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-inbox" aria-hidden="true"></i>
                                <p>No visitors found</p>
                                <?php if ($filter_date || $filter_purpose || $filter_month || $search): ?>
                                    <a href="hub-visitors" class="btn btn-primary btn-sm">
                                        <i class="fas fa-times" aria-hidden="true"></i> Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $purpose_badges = [
                        'Meeting'      => 'info',
                        'Event'        => 'success',
                        'Co-working'   => 'info',
                        'Training'     => 'warning',
                        'Consultation' => 'purple',
                        'Tour'         => 'secondary',
                        'Interview'    => 'warning',
                        'Other'        => 'secondary'
                    ];
                    foreach ($visitors as $v): ?>
                    <tr>
                        <td data-label="Visitor">
                            <strong><?php echo htmlspecialchars($v['visitor_name']); ?></strong>
                            <?php if ($v['is_member']): ?>
                                <br><span class="badge badge-success">Member</span>
                            <?php endif; ?>
                            <?php if ($v['is_first_time']): ?>
                                <br><span class="badge badge-info">First Visit</span>
                            <?php endif; ?>
                            <?php if (!empty($v['self_registered'])): ?>
                                <br><span class="badge badge-secondary"><i class="fas fa-mobile-alt" aria-hidden="true"></i> Self-registered</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Contact">
                            <?php if ($v['phone']): ?>
                                <div class="hv-contact">
                                    <i class="fas fa-phone" aria-hidden="true"></i>
                                    <a href="tel:<?php echo htmlspecialchars($v['phone']); ?>"><?php echo htmlspecialchars($v['phone']); ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if ($v['email']): ?>
                                <div class="hv-contact">
                                    <i class="fas fa-envelope" aria-hidden="true"></i>
                                    <a href="mailto:<?php echo htmlspecialchars($v['email']); ?>"><?php echo htmlspecialchars($v['email']); ?></a>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Organization"><?php echo htmlspecialchars($v['organization'] ?? 'N/A'); ?></td>
                        <td data-label="Visit Date">
                            <strong><?php echo date('d M Y', strtotime($v['visit_date'])); ?></strong>
                            <br><small class="cell-muted"><?php echo date('l', strtotime($v['visit_date'])); ?></small>
                        </td>
                        <td data-label="Time">
                            <?php if ($v['visit_time']): ?>
                                <span class="badge badge-success"><i class="fas fa-clock" aria-hidden="true"></i> <?php echo date('h:i A', strtotime($v['visit_time'])); ?></span>
                            <?php else: ?>
                                <span class="cell-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Purpose">
                            <?php $badge = $purpose_badges[$v['purpose']] ?? 'secondary'; ?>
                            <span class="badge badge-<?php echo $badge; ?>"><?php echo htmlspecialchars($v['purpose']); ?></span>
                        </td>
                        <td data-label="Host"><?php echo htmlspecialchars($v['host_contact'] ?? 'N/A'); ?></td>
                        <td data-label="Remarks">
                            <?php if ($v['remarks']): ?>
                                <span title="<?php echo htmlspecialchars($v['remarks']); ?>">
                                    <?php echo htmlspecialchars(substr($v['remarks'], 0, 40)); ?>
                                    <?php if (strlen($v['remarks']) > 40): ?>...<?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="cell-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a href="?edit=<?php echo $v['visitor_id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                    <i class="fas fa-edit" aria-hidden="true"></i>
                                </a>
                                <?php if (ims_allowed('hub_visitors.delete', ['Administrator'], 'Delete hub visitors')): ?>
                                    <a href="javascript:void(0)"
                                       onclick="confirmDelete(<?= htmlspecialchars(json_encode((string)($v['visitor_name'])), ENT_QUOTES, 'UTF-8') ?>,'includes/hub-visitors-process.php?delete=<?php echo (int)$v['visitor_id']; ?>&csrf_token=<?= h(csrf_token()) ?>')"
                                       class="btn btn-danger btn-sm" title="Delete">
                                        <i class="fas fa-trash" aria-hidden="true"></i>
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
        <?php if ($visitor_total > $per_page): ?>
        <div class="hv-pagination">
            <span>Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $visitor_total); ?> of <?php echo $visitor_total; ?> visitors</span>
            <nav aria-label="Visitor pages">
                <?php
                $page_query = $_GET;
                foreach (['Previous' => $current_page - 1, 'Next' => $current_page + 1] as $label => $page_number):
                    if ($page_number < 1 || $page_number > $total_pages) continue;
                    $page_query['page'] = $page_number;
                ?>
                    <a href="?<?php echo htmlspecialchars(http_build_query($page_query), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
                <?php for ($page_number = max(1, $current_page - 2); $page_number <= min($total_pages, $current_page + 2); $page_number++): $page_query['page'] = $page_number; ?>
                    <?php if ($page_number === $current_page): ?><span class="current" aria-current="page"><?php echo $page_number; ?></span><?php else: ?><a href="?<?php echo htmlspecialchars(http_build_query($page_query), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $page_number; ?></a><?php endif; ?>
                <?php endfor; ?>
            </nav>
        </div>
        <?php endif; ?>
</section>

<!-- Analytics -->
<section class="tab-panel" data-visitor-panel="analytics" role="tabpanel">
    <div class="hv-chart-grid">
        <div>
            <h4>Visit Purposes (This Month)</h4>
            <canvas id="purposeChart" class="hv-chart"></canvas>
        </div>
        <div>
            <h4>Daily Visitor Trend (Last 14 Days)</h4>
            <canvas id="trendChart" class="hv-chart"></canvas>
        </div>
    </div>
    <div class="hv-chart-wide">
        <h4>Peak Hours (This Month)</h4>
        <canvas id="hourlyChart" class="hv-chart-sm"></canvas>
    </div>
</section>
</div><!-- /.tab-panels -->
</div><!-- /.tab-shell -->

</div><!-- /.page-stack -->

<!-- -- QR Code Modal --------------------------------------------------------- -->
<div id="qrModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-qrcode"></i> Visitor Registration QR Code</h3>
            <span class="close" onclick="closeModal('qrModal')">&times;</span>
        </div>
        <div class="modal-body hv-qr-body">
            <p class="hv-qr-intro">
                Display or print this QR code at your reception desk.<br>
                Visitors scan it to register themselves instantly.
            </p>
            <canvas id="qrCanvas"></canvas>
            <p class="qr-url-text" id="qrUrlDisplay"></p>
            <div class="hv-qr-actions">
                <button onclick="copyRegisterLink()" class="btn btn-warning">
                    <i class="fas fa-copy"></i> Copy Link
                </button>
                <button onclick="printQR()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print QR
                </button>
                <button onclick="downloadQR()" class="btn btn-success">
                    <i class="fas fa-download"></i> Download PNG
                </button>
                <a href="<?php echo htmlspecialchars($register_url); ?>" target="_blank" class="btn btn-secondary">
                    <i class="fas fa-external-link-alt"></i> Preview Form
                </a>
            </div>
        </div>
    </div>
</div>

<!-- -- Add / Edit Visitor Modal ---------------------------------------------- -->
<div id="addVisitorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo $edit_visitor ? 'Edit Visitor' : 'Log New Visitor'; ?></h3>
            <span class="close" onclick="closeModal('addVisitorModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/hub-visitors-process.php">
                <?php if ($edit_visitor): ?>
                    <input type="hidden" name="visitor_id" value="<?php echo $edit_visitor['visitor_id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="visitor_name" class="required">Visitor Name</label>
                        <input type="text" id="visitor_name" name="visitor_name" class="form-control"
                               value="<?php echo $edit_visitor ? htmlspecialchars($edit_visitor['visitor_name']) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control"
                               value="<?php echo $edit_visitor ? htmlspecialchars($edit_visitor['phone']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?php echo $edit_visitor ? htmlspecialchars($edit_visitor['email']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="organization">Organization / Company</label>
                        <input type="text" id="organization" name="organization" class="form-control"
                               value="<?php echo $edit_visitor ? htmlspecialchars($edit_visitor['organization']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="visit_date" class="required">Visit Date</label>
                        <input type="date" id="visit_date" name="visit_date" class="form-control"
                               value="<?php echo $edit_visitor ? $edit_visitor['visit_date'] : date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="visit_time">Visit Time</label>
                        <input type="time" id="visit_time" name="visit_time" class="form-control"
                               value="<?php echo $edit_visitor ? $edit_visitor['visit_time'] : date('H:i'); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="purpose" class="required">Purpose of Visit</label>
                        <select id="purpose" name="purpose" class="form-control" required>
                            <option value="">Select Purpose</option>
                            <?php foreach ($purposes as $p): ?>
                                <option value="<?php echo $p; ?>"
                                    <?php echo ($edit_visitor && $edit_visitor['purpose'] == $p) ? 'selected' : ''; ?>>
                                    <?php echo $p; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="host_contact">Host / Person to See</label>
                        <input type="text" id="host_contact" name="host_contact" class="form-control"
                               value="<?php echo $edit_visitor ? htmlspecialchars($edit_visitor['host_contact']) : ''; ?>"
                               placeholder="Staff member or team">
                    </div>
                </div>

                <div class="form-group">
                    <label for="remarks">Remarks / Notes</label>
                    <textarea id="remarks" name="remarks" class="form-control" rows="3"><?php echo $edit_visitor ? htmlspecialchars($edit_visitor['remarks']) : ''; ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_member" value="1"
                                   <?php echo ($edit_visitor && $edit_visitor['is_member']) ? 'checked' : ''; ?>>
                            <strong>Is Hub Member</strong>
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_first_time" value="1"
                                   <?php echo ($edit_visitor && $edit_visitor['is_first_time']) ? 'checked' : ''; ?>>
                            <strong>First Time Visitor</strong>
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addVisitorModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="<?php echo $edit_visitor ? 'edit_visitor' : 'add_visitor'; ?>" class="btn btn-success">
                        <i class="fas fa-save"></i> <?php echo $edit_visitor ? 'Update' : 'Log'; ?> Visitor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- -- QR.js (CDN) ------------------------------------------------------------ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
const REGISTER_URL = <?php echo json_encode($register_url); ?>;

document.querySelectorAll('[data-visitor-tab]').forEach((tab) => tab.addEventListener('click', () => {
    document.querySelectorAll('[data-visitor-tab]').forEach((item) => {
        const active = item === tab;
        item.classList.toggle('active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-visitor-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.visitorPanel === tab.dataset.visitorTab));
    if (tab.dataset.visitorTab === 'analytics' && window.Chart) {
        document.querySelectorAll('[data-visitor-panel="analytics"] canvas').forEach((canvas) => {
            const chart = Chart.getChart(canvas);
            if (chart) chart.resize();
        });
    }
}));

// -- Populate QR when modal opens -----------------------------------------
const cssToken = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

(function initQR() {
    document.getElementById('qrUrlDisplay').textContent = REGISTER_URL;
    new QRCode(document.getElementById('qrCanvas'), {
        text       : REGISTER_URL,
        width      : 260,
        height     : 260,
        colorDark  : cssToken('--brand-700', '#c2410c'),
        colorLight : cssToken('--ink-0', '#ffffff'),
        correctLevel: QRCode.CorrectLevel.H
    });
})();

// -- Copy link ------------------------------------------------------------
function copyRegisterLink() {
    navigator.clipboard.writeText(REGISTER_URL).then(() => {
        // brief toast-style feedback using existing alert helper (if any)
        const btn = event.target.closest('button');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.style.background = 'var(--green-fg)';
        setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; }, 2000);
    });
}

// -- Print QR -------------------------------------------------------------
function printQR() {
    const canvas = document.querySelector('#qrCanvas canvas');
    const imgSrc = canvas ? canvas.toDataURL() : document.querySelector('#qrCanvas img').src;
    const win = window.open('', '_blank');
    win.document.write(`
        <html><head><title>Visitor Registration QR</title>
        <style>
            body { font-family:sans-serif; text-align:center; padding:40px; }
            img  { width:280px; height:280px; }
            h2   { color:#d84315; margin-bottom:8px; }
            p    { color:#555; font-size:14px; word-break:break-all; max-width:320px; margin:auto; }
        </style></head><body>
        <h2>Scan to Register Your Visit</h2>
        <img src="${imgSrc}" alt="QR Code">
        <p style="margin-top:16px;">${REGISTER_URL}</p>
        <script>window.onload=()=>window.print();<\/script>
        </body></html>
    `);
    win.document.close();
}

// -- Download QR PNG -------------------------------------------------------
function downloadQR() {
    const canvas = document.querySelector('#qrCanvas canvas');
    const imgSrc = canvas ? canvas.toDataURL() : document.querySelector('#qrCanvas img').src;
    const a = document.createElement('a');
    a.href     = imgSrc;
    a.download = 'visitor-registration-qr.png';
    a.click();
}

// -- Auto-open edit modal --------------------------------------------------
<?php if ($edit_visitor): ?>
window.addEventListener('DOMContentLoaded', () => openModal('addVisitorModal'));
<?php endif; ?>

// -- Analytics Charts ------------------------------------------------------
<?php
// Purpose distribution
$purpose_data = [];
$r = $conn->query("SELECT purpose, COUNT(*) as count FROM hub_visitors WHERE MONTH(visit_date)=MONTH(CURDATE()) AND YEAR(visit_date)=YEAR(CURDATE()) GROUP BY purpose");
while ($row = $r->fetch_assoc()) { $purpose_data[$row['purpose']] = $row['count']; }

// Daily trend (last 14 days)
$daily_trend = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $cnt = $conn->query("SELECT COUNT(*) as c FROM hub_visitors WHERE visit_date='$d'")->fetch_assoc()['c'];
    $daily_trend[$d] = $cnt;
}

// Hourly distribution
$hourly_data = array_fill(8, 13, 0);
$r = $conn->query("SELECT HOUR(visit_time) as hour, COUNT(*) as count FROM hub_visitors WHERE visit_time IS NOT NULL AND MONTH(visit_date)=MONTH(CURDATE()) AND YEAR(visit_date)=YEAR(CURDATE()) GROUP BY HOUR(visit_time)");
while ($row = $r->fetch_assoc()) {
    if ($row['hour'] >= 8 && $row['hour'] <= 20) $hourly_data[$row['hour']] = $row['count'];
}
?>

new Chart(document.getElementById('purposeChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($purpose_data)); ?>,
        datasets: [{ data: <?php echo json_encode(array_values($purpose_data)); ?>,
            backgroundColor: ['--blue-fg','--green-fg','--brand-500','--amber-fg','--purple-fg','--slate-fg','--red-fg','--teal-fg'].map((t) => cssToken(t)) }]
    },
    options: { responsive:true, plugins:{ legend:{ position:'bottom' } } }
});

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_map(fn($d) => date('d M', strtotime($d)), array_keys($daily_trend))); ?>,
        datasets: [{ label:'Daily Visitors', data: <?php echo json_encode(array_values($daily_trend)); ?>,
            borderColor: cssToken('--brand-500'), backgroundColor: cssToken('--brand-50'), tension:0.4, fill:true }]
    },
    options: { responsive:true, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }, plugins:{ legend:{ display:false } } }
});

new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(fn($h) => date('g A', strtotime("$h:00")), array_keys($hourly_data))); ?>,
        datasets: [{ label:'Visitors', data: <?php echo json_encode(array_values($hourly_data)); ?>,
            backgroundColor: cssToken('--brand-500') }]
    },
    options: { responsive:true, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }, plugins:{ legend:{ display:false } } }
});
</script>
