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
/* -- Header --------------------------------------------------------------- */
.visitors-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
}
.visitors-header h1 { font-size: 26px; margin: 0 0 4px; }
.visitors-header p  { margin: 0; opacity: .9; font-size: 14px; }

/* -- Share-link banner ---------------------------------------------------- */
.share-banner {
    background: #fff8f4;
    border: 1.5px solid #ff9800;
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.share-banner .share-icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg,#ff6b35,#ff9800);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 22px; flex-shrink: 0;
}
.share-banner .share-info { flex: 1; min-width: 220px; }
.share-banner .share-info h4 { margin: 0 0 4px; color: #d84315; font-size: 15px; }
.share-banner .share-info p  { margin: 0; font-size: 13px; color: #6d4c41; }
.share-url-box {
    display: flex; align-items: center; gap: 8px;
    background: white;
    border: 1px solid #ffcc80;
    border-radius: 8px;
    padding: 8px 14px;
    flex: 2; min-width: 260px;
}
.share-url-box span {
    flex: 1; font-size: 13px; color: #333;
    word-break: break-all;
    user-select: all;
}
.share-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* -- Quick-date stats ----------------------------------------------------- */
.quick-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}
.quick-stat {
    background: white; padding: 18px 12px;
    border-radius: 10px; text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    border-left: 4px solid var(--primary-color);
    text-decoration: none; color: inherit;
    transition: transform .15s, box-shadow .15s;
}
.quick-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,.12); }
.quick-stat h3 { font-size: 28px; margin-bottom: 6px; color: var(--primary-color); }
.quick-stat p  { font-size: 12px; color: #7f8c8d; margin: 0; }
.visitor-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px 0 2px;color:#64748b;font-size:13px}
.visitor-pagination nav{display:flex;gap:6px;align-items:center}
.visitor-pagination a,.visitor-pagination span{display:inline-flex;min-width:36px;height:36px;align-items:center;justify-content:center;padding:0 10px;border:1px solid #dbe3ec;border-radius:8px;background:#fff;color:#334155;text-decoration:none;font-weight:700}
.visitor-pagination .current{background:var(--primary-color);border-color:var(--primary-color);color:#fff}
.visitor-section-tabs{display:flex;gap:8px;border-bottom:1px solid #e2e8f0;margin:22px 0 16px;overflow-x:auto}
.visitor-section-tabs button{border:0;border-bottom:3px solid transparent;background:transparent;padding:11px 16px;color:#64748b;font-weight:700;white-space:nowrap;cursor:pointer}
.visitor-section-tabs button.active{color:var(--primary-color);border-bottom-color:var(--primary-color)}
.visitor-section-panel{display:none}.visitor-section-panel.active{display:block}

/* -- Misc ----------------------------------------------------------------- */
.filter-bar { background:#f8f9fa; padding:18px; border-radius:12px; margin-bottom:20px; }
.filter-bar > .filters-bar { margin-bottom:0; }
.visitor-badge { display:inline-flex; align-items:center; gap:6px; background:#f8f9fa; padding:4px 10px; border-radius:6px; font-size:13px; }
.check-in-badge { background:#d4edda; color:#155724; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:600; }

/* -- QR Modal ------------------------------------------------------------- */
#qrModal .modal-content { max-width: 420px; text-align: center; }
#qrCanvas { margin: 20px auto; display: block; }
.qr-url-text { font-size:12px; color:#666; word-break:break-all; margin-top:8px; }

/* -- Analytics ------------------------------------------------------------ */
.analytics-section { background:white; border-radius:12px; padding:25px; margin-top:25px; box-shadow:0 2px 8px rgba(0,0,0,.08); }
</style>

<!-- -- Page Header ----------------------------------------------------------- -->
<div class="visitors-header">
    <div>
        <h1><i class="fas fa-user-friends"></i> Hub Visitors Log</h1>
        <p>Track and manage daily visitors to the hub</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button onclick="openModal('qrModal')" class="btn" style="background:white;color:#ff6b35;font-weight:600;">
            <i class="fas fa-qrcode"></i> Show QR Code
        </button>
        <button onclick="copyRegisterLink()" class="btn" style="background:rgba(255,255,255,.2);color:white;border:1px solid rgba(255,255,255,.5);">
            <i class="fas fa-link"></i> Copy Visitor Link
        </button>
    </div>
</div>

<!-- -- Shareable Registration Banner ---------------------------------------- -->
<div class="share-banner">
    <div class="share-icon"><i class="fas fa-paper-plane"></i></div>
    <div class="share-info">
        <h4>Visitor Self-Registration Link</h4>
        <p>Share this link or QR code with visitors so they can log themselves in.</p>
    </div>
    <div class="share-url-box">
        <i class="fas fa-globe" style="color:#ff9800;"></i>
        <span id="registerUrl"><?php echo htmlspecialchars($register_url); ?></span>
    </div>
    <div class="share-actions">
        <button onclick="copyRegisterLink()" class="btn btn-warning btn-sm" title="Copy link">
            <i class="fas fa-copy"></i> Copy
        </button>
        <button onclick="openModal('qrModal')" class="btn btn-primary btn-sm" title="QR Code">
            <i class="fas fa-qrcode"></i> QR
        </button>
        <a href="<?php echo htmlspecialchars($register_url); ?>" target="_blank" class="btn btn-secondary btn-sm" title="Preview">
            <i class="fas fa-external-link-alt"></i>
        </a>
    </div>
</div>

<!-- -- Stats Cards ----------------------------------------------------------- -->
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

<div class="visitor-section-tabs" role="tablist" aria-label="Visitor sections">
    <button type="button" class="active" data-visitor-tab="log" role="tab" aria-selected="true"><i class="fas fa-list"></i> Visitors Log</button>
    <button type="button" data-visitor-tab="analytics" role="tab" aria-selected="false"><i class="fas fa-chart-bar"></i> Analytics</button>
</div>

<section class="visitor-section-panel active" data-visitor-panel="log" role="tabpanel">
<!-- -- Main Table Card ------------------------------------------------------- -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Visitors Log</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button onclick="openModal('addVisitorModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Log Visitor
            </button>
            <button onclick="openModal('qrModal')" class="btn btn-warning">
                <i class="fas fa-qrcode"></i> Share Form
            </button>
            <button onclick="window.location.href='export?type=hub_visitors'" class="btn btn-success">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>

    <div class="card-body">
        <!-- Filter Bar -->
        <form method="GET" action="" class="filter-bar">
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
                    <button type="submit" class="btn btn-info"><i class="fas fa-filter"></i> Filter</button>
                    <a href="hub-visitors" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                </div>
            </div>
        </form>

        <!-- Quick Date Tiles -->
        <div class="quick-stats">
            <a href="?date=<?php echo date('Y-m-d'); ?>" class="quick-stat">
                <h3><?php echo $stats['today']; ?></h3><p>Today</p>
            </a>
            <a href="?date=<?php echo date('Y-m-d', strtotime('yesterday')); ?>" class="quick-stat">
                <h3><?php echo $yesterday; ?></h3><p>Yesterday</p>
            </a>
            <a href="?month=<?php echo date('Y-m'); ?>" class="quick-stat">
                <h3><?php echo $stats['this_month']; ?></h3><p>This Month</p>
            </a>
            <a href="?month=<?php echo date('Y-m', strtotime('last month')); ?>" class="quick-stat">
                <h3><?php echo $last_month; ?></h3><p>Last Month</p>
            </a>
        </div>

        <!-- Visitors Table -->
        <div class="table-responsive">
            <table class="data-table">
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
                        <td colspan="9" class="text-center" style="padding:60px;">
                            <i class="fas fa-inbox" style="font-size:48px;color:#bdc3c7;margin-bottom:15px;display:block;"></i>
                            <p style="color:#7f8c8d;font-size:16px;">No visitors found</p>
                            <?php if ($filter_date || $filter_purpose || $filter_month || $search): ?>
                                <a href="hub-visitors" class="btn btn-primary">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $purpose_badges = [
                        'Meeting'      => 'info',
                        'Event'        => 'success',
                        'Co-working'   => 'primary',
                        'Training'     => 'warning',
                        'Consultation' => 'purple',
                        'Tour'         => 'secondary',
                        'Interview'    => 'orange',
                        'Other'        => 'secondary'
                    ];
                    foreach ($visitors as $v): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($v['visitor_name']); ?></strong>
                            <?php if ($v['is_member']): ?>
                                <br><span class="badge badge-success" style="font-size:11px;">Member</span>
                            <?php endif; ?>
                            <?php if ($v['is_first_time']): ?>
                                <br><span class="badge badge-info" style="font-size:11px;">First Visit</span>
                            <?php endif; ?>
                            <?php if (!empty($v['self_registered'])): ?>
                                <br><span class="badge badge-secondary" style="font-size:11px;"><i class="fas fa-mobile-alt"></i> Self-registered</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['phone']): ?>
                                <div class="visitor-badge">
                                    <i class="fas fa-phone"></i>
                                    <a href="tel:<?php echo htmlspecialchars($v['phone']); ?>"><?php echo htmlspecialchars($v['phone']); ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if ($v['email']): ?>
                                <div class="visitor-badge" style="margin-top:5px;">
                                    <i class="fas fa-envelope"></i>
                                    <a href="mailto:<?php echo htmlspecialchars($v['email']); ?>"><?php echo htmlspecialchars($v['email']); ?></a>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($v['organization'] ?? 'N/A'); ?></td>
                        <td>
                            <strong><?php echo date('d M Y', strtotime($v['visit_date'])); ?></strong>
                            <br><small style="color:#7f8c8d;"><?php echo date('l', strtotime($v['visit_date'])); ?></small>
                        </td>
                        <td>
                            <?php if ($v['visit_time']): ?>
                                <span class="check-in-badge"><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($v['visit_time'])); ?></span>
                            <?php else: ?>
                                <span style="color:#95a5a6;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $badge = $purpose_badges[$v['purpose']] ?? 'secondary'; ?>
                            <span class="badge badge-<?php echo $badge; ?>"><?php echo htmlspecialchars($v['purpose']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($v['host_contact'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($v['remarks']): ?>
                                <span title="<?php echo htmlspecialchars($v['remarks']); ?>">
                                    <?php echo htmlspecialchars(substr($v['remarks'], 0, 40)); ?>
                                    <?php if (strlen($v['remarks']) > 40): ?>...<?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#95a5a6;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="?edit=<?php echo $v['visitor_id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if (ims_allowed('hub_visitors.delete', ['Administrator'], 'Delete hub visitors')): ?>
                                    <a href="javascript:void(0)"
                                       onclick="confirmDelete(<?= htmlspecialchars(json_encode((string)($v['visitor_name'])), ENT_QUOTES, 'UTF-8') ?>,'includes/hub-visitors-process.php?delete=<?php echo (int)$v['visitor_id']; ?>&csrf_token=<?= h(csrf_token()) ?>')"
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
        <?php if ($visitor_total > $per_page): ?>
        <div class="visitor-pagination">
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
    </div>
</div>
</section>

<!-- -- Analytics ------------------------------------------------------------ -->
<section class="visitor-section-panel" data-visitor-panel="analytics" role="tabpanel"><div class="analytics-section">
    <h3 style="margin-bottom:20px;"><i class="fas fa-chart-bar"></i> Visitor Analytics</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;">
        <div>
            <h4 style="margin-bottom:15px;">Visit Purposes (This Month)</h4>
            <canvas id="purposeChart" style="max-height:300px;"></canvas>
        </div>
        <div>
            <h4 style="margin-bottom:15px;">Daily Visitor Trend (Last 14 Days)</h4>
            <canvas id="trendChart" style="max-height:300px;"></canvas>
        </div>
    </div>
    <div style="margin-top:30px;">
        <h4 style="margin-bottom:15px;">Peak Hours (This Month)</h4>
        <canvas id="hourlyChart" style="max-height:250px;"></canvas>
    </div>
</div>
</section>

<!-- -- QR Code Modal --------------------------------------------------------- -->
<div id="qrModal" class="modal">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header">
            <h3><i class="fas fa-qrcode"></i> Visitor Registration QR Code</h3>
            <span class="close" onclick="closeModal('qrModal')">&times;</span>
        </div>
        <div class="modal-body" style="text-align:center;padding:30px 20px;">
            <p style="color:#555;margin-bottom:20px;font-size:14px;">
                Display or print this QR code at your reception desk.<br>
                Visitors scan it to register themselves instantly.
            </p>
            <canvas id="qrCanvas"></canvas>
            <p class="qr-url-text" id="qrUrlDisplay"></p>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
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
    <div class="modal-content" style="max-width:700px;">
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
(function initQR() {
    document.getElementById('qrUrlDisplay').textContent = REGISTER_URL;
    new QRCode(document.getElementById('qrCanvas'), {
        text       : REGISTER_URL,
        width      : 260,
        height     : 260,
        colorDark  : '#d84315',
        colorLight : '#ffffff',
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
        btn.style.background = '#2ecc71';
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
            backgroundColor: ['#3498DB','#2ECC71','#FF6B35','#F39C12','#9B59B6','#95A5A6','#E74C3C','#1ABC9C'] }]
    },
    options: { responsive:true, plugins:{ legend:{ position:'bottom' } } }
});

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_map(fn($d) => date('d M', strtotime($d)), array_keys($daily_trend))); ?>,
        datasets: [{ label:'Daily Visitors', data: <?php echo json_encode(array_values($daily_trend)); ?>,
            borderColor:'#FF6B35', backgroundColor:'rgba(255,107,53,0.1)', tension:0.4, fill:true }]
    },
    options: { responsive:true, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }, plugins:{ legend:{ display:false } } }
});

new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(fn($h) => date('g A', strtotime("$h:00")), array_keys($hourly_data))); ?>,
        datasets: [{ label:'Visitors', data: <?php echo json_encode(array_values($hourly_data)); ?>,
            backgroundColor:'#ff6b35' }]
    },
    options: { responsive:true, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }, plugins:{ legend:{ display:false } } }
});
</script>
