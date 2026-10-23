<?php
// Frontend - Activity Log Viewer
$page_title = 'Activity Log';
include 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

// Get filter parameters
$filter_user = isset($_GET['user']) ? intval($_GET['user']) : 0;
$filter_action = isset($_GET['action']) ? sanitize_input($_GET['action']) : '';
$filter_module = isset($_GET['module']) ? sanitize_input($_GET['module']) : '';
$filter_date_from = isset($_GET['date_from']) ? sanitize_input($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? sanitize_input($_GET['date_to']) : '';
$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build WHERE clause (parameterised)
$where = "1=1";
$where_types = '';
$where_params = [];
if ($filter_user) {
    $where .= " AND al.user_id = ?";
    $where_types .= 'i';
    $where_params[] = $filter_user;
}
if ($filter_action) {
    $where .= " AND al.action = ?";
    $where_types .= 's';
    $where_params[] = $filter_action;
}
if ($filter_module) {
    $where .= " AND al.module = ?";
    $where_types .= 's';
    $where_params[] = $filter_module;
}
if ($filter_date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
    $where .= " AND DATE(al.action_time) >= ?";
    $where_types .= 's';
    $where_params[] = $filter_date_from;
}
if ($filter_date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
    $where .= " AND DATE(al.action_time) <= ?";
    $where_types .= 's';
    $where_params[] = $filter_date_to;
}
if ($search) {
    $where .= " AND (al.description LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
    $like = '%' . $search . '%';
    $where_types .= 'sss';
    array_push($where_params, $like, $like, $like);
}

$run_log_query = static function (mysqli $conn, string $sql, string $types, array $params) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('activity log query failed: ' . $conn->error);
        return false;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
};

// Count total records
$count_query = "SELECT COUNT(*) as total FROM activity_log al LEFT JOIN users u ON al.user_id = u.user_id WHERE $where";
$count_result = $run_log_query($conn, $count_query, $where_types, $where_params);
$total_records = $count_result ? (int)$count_result->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_records / $per_page);

// Fetch activity logs
$logs = [];
$query = "SELECT al.*, u.full_name, u.username, u.role
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE $where
    ORDER BY al.action_time DESC
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$result = $run_log_query($conn, $query, $where_types, $where_params);
while ($result && ($row = $result->fetch_assoc())) {
    $logs[] = $row;
}

// Fetch users for filter
$users = [];
$result = $conn->query("SELECT user_id, full_name, username FROM users ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Get unique actions and modules
$actions = [];
$result = $conn->query("SELECT DISTINCT action FROM activity_log WHERE action IS NOT NULL ORDER BY action");
while ($row = $result->fetch_assoc()) {
    $actions[] = $row['action'];
}

$modules = [];
$result = $conn->query("SELECT DISTINCT module FROM activity_log WHERE module IS NOT NULL ORDER BY module");
while ($row = $result->fetch_assoc()) {
    $modules[] = $row['module'];
}

// Calculate statistics
$stats_query = "SELECT 
    COUNT(*) as total_actions,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT DATE(action_time)) as active_days,
    COUNT(CASE WHEN DATE(action_time) = CURDATE() THEN 1 END) as today_actions,
    COUNT(CASE WHEN action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_actions,
    COUNT(CASE WHEN action_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as month_actions
    FROM activity_log";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<style>
/* Activity log: compact role chip, IP column and the pager row. */
.al-time-col { width: 150px; }
.al-role { font-size: 10px; }
.al-ip { font-size: 12px; }
.al-pager { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; margin-top: 20px; gap: 10px; }
.al-pager-info { padding: 0 15px; }
.al-danger-title { color: var(--red-fg); }
.al-modal-intro { margin-bottom: 20px; }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-history" aria-hidden="true"></i> Activity Log</h1>
        <p>Review who did what across the system, filter by user, action or module, and export the results.</p>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-list" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Actions</span>
            <span class="stat-value"><?php echo number_format($stats['total_actions']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Today's Actions</span>
            <span class="stat-value"><?php echo number_format($stats['today_actions']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-calendar-week" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">This Week</span>
            <span class="stat-value"><?php echo number_format($stats['week_actions']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">This Month</span>
            <span class="stat-value"><?php echo number_format($stats['month_actions']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Active Users</span>
            <span class="stat-value"><?php echo number_format($stats['unique_users']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-slate"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Active Days</span>
            <span class="stat-value"><?php echo number_format($stats['active_days']); ?></span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history" aria-hidden="true"></i> System Activity Log</h3>
        <div>
            <button onclick="exportLogs()" class="btn btn-info">
                <i class="fas fa-download"></i> Export
            </button>
            <?php if (ims_allowed('activity_log.clear', ['Administrator'], 'Clear old activity logs')): ?>
                <button onclick="openModal('clearLogsModal')" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Clear Old Logs
                </button>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" action="" class="filters-bar" role="search" aria-label="Filter activity log">
            <div class="form-group filters-grow">
                <input type="text" name="search" class="form-control" placeholder="Search logs..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="form-group">
                <select name="user" class="form-control">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>" <?php echo $filter_user == $user['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <select name="action" class="form-control">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filter_action == $action ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($action); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <select name="module" class="form-control">
                    <option value="">All Modules</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?php echo htmlspecialchars($module); ?>" <?php echo $filter_module == $module ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($module); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <input type="date" name="date_from" class="form-control" placeholder="From Date" value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            
            <div class="form-group">
                <input type="date" name="date_to" class="form-control" placeholder="To Date" value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            
            <div class="filters-actions">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="activity_log" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
        
        <!-- Activity Log Table -->
        <div class="table-responsive">
            <table class="table" id="activityLogTable">
                <thead>
                    <tr>
                        <th class="al-time-col">Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Description</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fas fa-history" aria-hidden="true"></i>
                                    <p>No activity logs found</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <small>
                                        <?php echo date('d M Y', strtotime($log['action_time'])); ?><br>
                                        <?php echo date('H:i:s', strtotime($log['action_time'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown User'); ?></strong>
                                        <?php if ($log['username']): ?>
                                            <br><small class="cell-muted">@<?php echo htmlspecialchars($log['username']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($log['role']): ?>
                                            <br><span class="badge badge-secondary al-role"><?php echo htmlspecialchars($log['role']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $action_icons = [
                                        'Login' => 'sign-in-alt',
                                        'Logout' => 'sign-out-alt',
                                        'Add' => 'plus',
                                        'Create' => 'plus-circle',
                                        'Edit' => 'edit',
                                        'Update' => 'sync',
                                        'Delete' => 'trash',
                                        'View' => 'eye',
                                        'Export' => 'download',
                                        'Import' => 'upload'
                                    ];
                                    $icon = 'circle';
                                    foreach ($action_icons as $key => $value) {
                                        if (stripos($log['action'], $key) !== false) {
                                            $icon = $value;
                                            break;
                                        }
                                    }
                                    ?>
                                    <span class="badge badge-info">
                                        <i class="fas fa-<?php echo $icon; ?>"></i>
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log['module']): ?>
                                        <span class="badge badge-secondary">
                                            <?php echo htmlspecialchars($log['module']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="cell-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?php echo htmlspecialchars($log['description'] ?? '-'); ?></small>
                                    <?php if ($log['record_id']): ?>
                                        <br><small class="cell-muted">Record ID: <?php echo $log['record_id']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="al-ip"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="al-pager">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $filter_user ? '&user=' . $filter_user : ''; ?><?php echo $filter_action ? '&action=' . urlencode($filter_action) : ''; ?><?php echo $filter_module ? '&module=' . urlencode($filter_module) : ''; ?><?php echo $filter_date_from ? '&date_from=' . urlencode($filter_date_from) : ''; ?><?php echo $filter_date_to ? '&date_to=' . urlencode($filter_date_to) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <span class="al-pager-info">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                    (<?php echo number_format($total_records); ?> records)
                </span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $filter_user ? '&user=' . $filter_user : ''; ?><?php echo $filter_action ? '&action=' . urlencode($filter_action) : ''; ?><?php echo $filter_module ? '&module=' . urlencode($filter_module) : ''; ?><?php echo $filter_date_from ? '&date_from=' . urlencode($filter_date_from) : ''; ?><?php echo $filter_date_to ? '&date_to=' . urlencode($filter_date_to) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                       class="btn btn-secondary">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</div><!-- /.page-stack -->

<!-- Clear Logs Modal -->
<div id="clearLogsModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="al-danger-title"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Clear Old Logs</h3>
            <span class="close" onclick="closeModal('clearLogsModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="activity-log-process">
                <p class="al-modal-intro">
                    Delete activity logs older than a specified number of days to keep the database clean.
                </p>
                
                <div class="form-group">
                    <label for="days_to_keep" class="required">Keep logs from the last</label>
                    <select id="days_to_keep" name="days_to_keep" class="form-control" required>
                        <option value="30">30 days</option>
                        <option value="60">60 days</option>
                        <option value="90" selected>90 days</option>
                        <option value="180">180 days (6 months)</option>
                        <option value="365">365 days (1 year)</option>
                    </select>
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i>
                    <strong>Warning:</strong> This action cannot be undone. Logs older than the selected period will be permanently deleted.
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('clearLogsModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="clear_old_logs" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Clear Old Logs
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function exportLogs() {
    // Get current filters
    const params = new URLSearchParams(window.location.search);
    params.set('export', '1');
    
    // Redirect to export
    window.location.href = 'activity-log-process?' + params.toString();
}
</script>