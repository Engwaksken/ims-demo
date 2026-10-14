<?php

$page_title = 'KPI Management';

// Authorise before rendering any markup
require_once __DIR__ . '/includes/config.php';
check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR']);
$can_review_kpi = ims_allowed('kpis.approve', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR'], 'Approve or reject KPIs');

include 'includes/header.php';

// Get filter parameters (whitelisted / cast)
$filter_department = isset($_GET['department']) ? (int) $_GET['department'] : 0;
$filter_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$filter_status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$filter_category = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$search = isset($_GET['search']) ? mb_substr(sanitize_input($_GET['search']), 0, 100) : '';

if (!in_array($filter_status, ['Draft', 'Submitted', 'Under Review', 'Approved', 'Rejected', 'Completed'], true)) {
    $filter_status = '';
}

// Build WHERE clause (prepared - status/search were SQL injectable)
$where = "k.fiscal_year = ?";
$types = 'i';
$params = [$filter_year];
if ($filter_department > 0) {
    $where .= " AND k.department_id = ?";
    $types .= 'i';
    $params[] = $filter_department;
}
if ($filter_status !== '') {
    $where .= " AND k.status = ?";
    $types .= 's';
    $params[] = $filter_status;
}
if ($filter_category > 0) {
    $where .= " AND k.category_id = ?";
    $types .= 'i';
    $params[] = $filter_category;
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_') . '%';
    $where .= " AND (k.kpi_title LIKE ? OR u.full_name LIKE ?)";
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

// Fetch all KPIs
$kpis = [];
$query = "SELECT k.*, c.category_name, c.category_code, d.department_name,
    u.full_name as owner_name, u.email as owner_email
    FROM kpis k
    LEFT JOIN kpi_categories c ON k.category_id = c.category_id
    LEFT JOIN departments d ON k.department_id = d.department_id
    LEFT JOIN users u ON k.user_id = u.user_id
    WHERE $where
    ORDER BY
        CASE k.status
            WHEN 'Submitted' THEN 1
            WHEN 'Under Review' THEN 2
            WHEN 'Approved' THEN 3
            WHEN 'Rejected' THEN 4
            WHEN 'Draft' THEN 5
            WHEN 'Completed' THEN 6
        END,
        k.submitted_at DESC";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $kpis[] = $row;
    }
    $stmt->close();
} else {
    error_log('kpi-management query failed: ' . $conn->error);
}

// Fetch departments for filter
$departments = [];
$result = $conn->query("SELECT * FROM departments ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $departments[] = $row;
}

// Fetch categories for filter
$categories = [];
$result = $conn->query("SELECT * FROM kpi_categories ORDER BY category_name");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

// Calculate statistics
$total_kpis = count($kpis);
$submitted = count(array_filter($kpis, fn($k) => $k['status'] == 'Submitted'));
$under_review = count(array_filter($kpis, fn($k) => $k['status'] == 'Under Review'));
$approved = count(array_filter($kpis, fn($k) => $k['status'] == 'Approved'));
$rejected = count(array_filter($kpis, fn($k) => $k['status'] == 'Rejected'));
$completed = count(array_filter($kpis, fn($k) => $k['status'] == 'Completed'));

// Calculate average achievement
$kpis_with_achievement = array_filter($kpis, fn($k) => $k['overall_achievement'] !== null);
$avg_achievement = !empty($kpis_with_achievement) ? 
    round(array_sum(array_column($kpis_with_achievement, 'overall_achievement')) / count($kpis_with_achievement), 1) : 0;

// Department-wise breakdown
$dept_stats = [];
foreach ($departments as $dept) {
    $dept_kpis = array_filter($kpis, fn($k) => $k['department_id'] == $dept['department_id']);
    if (!empty($dept_kpis)) {
        $dept_approved = count(array_filter($dept_kpis, fn($k) => $k['status'] == 'Approved'));
        $dept_submitted = count(array_filter($dept_kpis, fn($k) => $k['status'] == 'Submitted'));
        
        $dept_stats[] = [
            'name' => $dept['department_name'],
            'total' => count($dept_kpis),
            'approved' => $dept_approved,
            'submitted' => $dept_submitted,
            'approval_rate' => count($dept_kpis) > 0 ? round(($dept_approved / count($dept_kpis)) * 100, 1) : 0
        ];
    }
}
?>

<style>
.kpi-management-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s;
}

.kpi-list-item {
    background: white;
    border-radius: 8px;
    padding: 18px;
    margin-bottom: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-left: 4px solid #ecf0f1;
    transition: all 0.3s;
}

.kpi-list-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateX(3px);
}

.kpi-list-item.submitted {
    border-left-color: #3498DB;
    background: #f8fcff;
}

.kpi-list-item.under-review {
    border-left-color: #F39C12;
    background: #fffef8;
}

.kpi-list-item.approved {
    border-left-color: #27AE60;
}

.kpi-list-item.rejected {
    border-left-color: #E74C3C;
}

.kpi-item-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 12px;
}

.kpi-item-title {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 5px;
}

.kpi-item-meta {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #7f8c8d;
    margin-bottom: 12px;
}

.kpi-item-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.priority-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}

.priority-high {
    background: #ffebee;
    color: #c62828;
}

.priority-medium {
    background: #fff3e0;
    color: #e65100;
}

.priority-low {
    background: #e8f5e9;
    color: #2e7d32;
}

.dept-stats-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    border-left: 4px solid var(--primary-color);
}

.mini-progress {
    height: 6px;
    background: #ecf0f1;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 5px;
}

.mini-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    transition: width 0.5s ease;
}

.tab-container {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #ecf0f1;
}

.tab-button {
    padding: 10px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    color: #7f8c8d;
    transition: all 0.3s;
}

.tab-button.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}

.tab-button:hover {
    color: var(--primary-color);
}

.action-required-banner {
    background: linear-gradient(135deg, #FF6B35, #F7931E);
    color: white;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
</style>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-bullseye" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total KPIs (<?php echo $filter_year; ?>)</span>
            <span class="stat-value"><?php echo $total_kpis; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-paper-plane" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Review</span>
            <span class="stat-value"><?php echo $submitted; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-eye" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Under Review</span>
            <span class="stat-value"><?php echo $under_review; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Approved</span>
            <span class="stat-value"><?php echo $approved; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-red"><i class="fas fa-times-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Rejected</span>
            <span class="stat-value"><?php echo $rejected; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Avg Achievement</span>
            <span class="stat-value"><?php echo $avg_achievement; ?>%</span>
        </div>
    </div>
</div>

<!-- Action Required Banner -->
<?php if ($submitted > 0): ?>
<div class="action-required-banner">
    <div>
        <i class="fas fa-exclamation-circle" style="font-size: 24px; margin-right: 15px;"></i>
        <strong><?php echo $submitted; ?> KPI<?php echo $submitted > 1 ? 's' : ''; ?> waiting for your review</strong>
    </div>
    <button onclick="filterByStatus('Submitted')" class="btn" style="background: white; color: #FF6B35;">
        <i class="fas fa-eye"></i> Review Now
    </button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-tasks"></i> KPI Management Dashboard</h3>
        <div>
            <button onclick="exportAllKPIs()" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export All
            </button>
            <button onclick="window.location.href='kpi-reports'" class="btn btn-info">
                <i class="fas fa-chart-bar"></i> Analytics
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" action="" class="filters-bar" role="search" aria-label="Filter KPIs">
            <div class="form-group filters-grow">
                <input type="text" name="search" class="form-control" placeholder="Search KPIs or employees..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="form-group">
                <select name="year" class="form-control">
                    <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="form-group">
                <select name="department" class="form-control">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>" <?php echo $filter_department == $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <select name="status" class="form-control" id="statusFilter">
                    <option value="">All Statuses</option>
                    <option value="Submitted" <?php echo $filter_status == 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                    <option value="Under Review" <?php echo $filter_status == 'Under Review' ? 'selected' : ''; ?>>Under Review</option>
                    <option value="Approved" <?php echo $filter_status == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Rejected" <?php echo $filter_status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="Completed" <?php echo $filter_status == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            
            <div class="form-group">
                <select name="category" class="form-control">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>" <?php echo $filter_category == $cat['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filters-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="kpi-management" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
        
        <!-- Tabs -->
        <div class="tab-container">
            <button class="tab-button active" onclick="switchTab('all')">
                All KPIs (<?php echo $total_kpis; ?>)
            </button>
            <button class="tab-button" onclick="filterByStatus('Submitted')">
                Pending (<?php echo $submitted; ?>)
            </button>
            <button class="tab-button" onclick="filterByStatus('Approved')">
                Approved (<?php echo $approved; ?>)
            </button>
            <button class="tab-button" onclick="switchTab('departments')">
                By Department
            </button>
        </div>
        
        <!-- KPI List -->
        <div id="kpiListTab">
            <?php if (empty($kpis)): ?>
                <div style="text-align: center; padding: 60px;">
                    <i class="fas fa-bullseye" style="font-size: 64px; color: #bdc3c7; margin-bottom: 20px;"></i>
                    <h4 style="color: #7f8c8d;">No KPIs Found</h4>
                    <p style="color: #95a5a6;">Try adjusting your filters or search criteria</p>
                </div>
            <?php else: ?>
                <?php foreach ($kpis as $kpi): ?>
                    <?php
                    // Calculate priority
                    $priority = 'low';
                    if ($kpi['status'] == 'Submitted' && $kpi['weight_percentage'] >= 30) {
                        $priority = 'high';
                    } elseif ($kpi['status'] == 'Submitted') {
                        $priority = 'medium';
                    }
                    
                    // Calculate days since submission
                    $days_waiting = null;
                    if ($kpi['submitted_at']) {
                        $submitted = new DateTime($kpi['submitted_at']);
                        $today = new DateTime();
                        $days_waiting = $submitted->diff($today)->days;
                    }
                    ?>
                    
                    <div class="kpi-list-item <?php echo strtolower(str_replace(' ', '-', $kpi['status'])); ?>">
                        <div class="kpi-item-header">
                            <div style="flex: 1;">
                                <div class="kpi-item-title"><?php echo htmlspecialchars($kpi['kpi_title']); ?></div>
                                <div class="kpi-item-meta">
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($kpi['owner_name']); ?></span>
                                    <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($kpi['department_name']); ?></span>
                                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($kpi['category_name']); ?></span>
                                    <span><i class="fas fa-weight"></i> <?php echo $kpi['weight_percentage']; ?>%</span>
                                    <span><i class="fas fa-bullseye"></i> <?php echo $kpi['target_value']; ?> <?php echo htmlspecialchars($kpi['unit_of_measure']); ?></span>
                                    <?php if ($days_waiting !== null && $kpi['status'] == 'Submitted'): ?>
                                        <span style="color: <?php echo $days_waiting > 7 ? '#E74C3C' : '#F39C12'; ?>;">
                                            <i class="fas fa-clock"></i> Waiting <?php echo $days_waiting; ?> day<?php echo $days_waiting > 1 ? 's' : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php if ($priority != 'low' && $kpi['status'] == 'Submitted'): ?>
                                    <span class="priority-badge priority-<?php echo $priority; ?>">
                                        <?php echo ucfirst($priority); ?> Priority
                                    </span>
                                <?php endif; ?>
                                <span class="badge badge-<?php 
                                    echo $kpi['status'] == 'Approved' ? 'success' : 
                                        ($kpi['status'] == 'Submitted' ? 'info' : 
                                        ($kpi['status'] == 'Under Review' ? 'warning' :
                                        ($kpi['status'] == 'Rejected' ? 'danger' : 
                                        ($kpi['status'] == 'Completed' ? 'success' : 'secondary')))); 
                                ?>">
                                    <?php echo $kpi['status']; ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($kpi['overall_achievement'] !== null): ?>
                            <div style="margin-bottom: 10px;">
                                <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 3px;">
                                    <span>Overall Achievement</span>
                                    <span><strong><?php echo $kpi['overall_achievement']; ?>%</strong></span>
                                </div>
                                <div class="mini-progress">
                                    <div class="mini-progress-fill" style="width: <?php echo min($kpi['overall_achievement'], 100); ?>%;"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="kpi-item-actions">
                            <a href="view-kpi?id=<?php echo $kpi['kpi_id']; ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-eye"></i> View
                            </a>
                            
                            <?php if ($kpi['status'] == 'Submitted' && $can_review_kpi): ?>
                                <button onclick="quickApprove(<?php echo $kpi['kpi_id']; ?>, '<?php echo htmlspecialchars(addslashes($kpi['kpi_title'])); ?>')" 
                                        class="btn btn-success btn-sm">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button onclick="quickReject(<?php echo $kpi['kpi_id']; ?>, '<?php echo htmlspecialchars(addslashes($kpi['kpi_title'])); ?>')" 
                                        class="btn btn-danger btn-sm">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            <?php endif; ?>
                            
                            <a href="mailto:<?php echo htmlspecialchars($kpi['owner_email']); ?>" class="btn btn-secondary btn-sm">
                                <i class="fas fa-envelope"></i> Contact
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Department Stats Tab -->
        <div id="departmentsTab" style="display: none;">
            <h4 style="margin-bottom: 20px;">Department-wise KPI Overview</h4>
            
            <?php if (empty($dept_stats)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 40px;">No data available</p>
            <?php else: ?>
                <?php foreach ($dept_stats as $stat): ?>
                    <div class="dept-stats-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h5 style="margin: 0;"><?php echo htmlspecialchars($stat['name']); ?></h5>
                            <div style="display: flex; gap: 15px;">
                                <span class="badge badge-primary"><?php echo $stat['total']; ?> Total</span>
                                <span class="badge badge-success"><?php echo $stat['approved']; ?> Approved</span>
                                <span class="badge badge-info"><?php echo $stat['submitted']; ?> Pending</span>
                            </div>
                        </div>
                        <div style="font-size: 13px; color: #7f8c8d; margin-bottom: 5px;">
                            Approval Rate: <strong style="color: #27AE60;"><?php echo $stat['approval_rate']; ?>%</strong>
                        </div>
                        <div class="mini-progress">
                            <div class="mini-progress-fill" style="width: <?php echo $stat['approval_rate']; ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Approve Modal -->
<div id="quickApproveModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="color: #27AE60;"><i class="fas fa-check-circle"></i> Quick Approve KPI</h3>
            <span class="close" onclick="closeModal('quickApproveModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="kpi_id" id="quickApproveId">
                
                <p>Approve this KPI:</p>
                <p style="font-size: 16px; font-weight: 600; color: #2c3e50;" id="quickApproveTitle"></p>
                
                <div class="form-group">
                    <label for="quick_approve_comments">Review Comments (Optional)</label>
                    <textarea id="quick_approve_comments" name="review_comments" class="form-control" rows="2" 
                              placeholder="Add feedback..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('quickApproveModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Reject Modal -->
<div id="quickRejectModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 style="color: #E74C3C;"><i class="fas fa-times-circle"></i> Reject KPI</h3>
            <span class="close" onclick="closeModal('quickRejectModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="kpi_id" id="quickRejectId">
                
                <p>Reject this KPI:</p>
                <p style="font-size: 16px; font-weight: 600; color: #2c3e50;" id="quickRejectTitle"></p>
                
                <div class="form-group">
                    <label for="quick_reject_reason" class="required">Reason for Rejection</label>
                    <textarea id="quick_reject_reason" name="rejection_reason" class="form-control" rows="4" 
                              placeholder="Provide clear feedback..." required></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('quickRejectModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function quickApprove(kpiId, kpiTitle) {
    document.getElementById('quickApproveId').value = kpiId;
    document.getElementById('quickApproveTitle').textContent = kpiTitle;
    openModal('quickApproveModal');
}

function quickReject(kpiId, kpiTitle) {
    document.getElementById('quickRejectId').value = kpiId;
    document.getElementById('quickRejectTitle').textContent = kpiTitle;
    openModal('quickRejectModal');
}

function switchTab(tab) {
    // Update tab buttons
    const buttons = document.querySelectorAll('.tab-button');
    buttons.forEach(btn => btn.classList.remove('active'));
    
    if (tab === 'all') {
        buttons[0].classList.add('active');
        document.getElementById('kpiListTab').style.display = 'block';
        document.getElementById('departmentsTab').style.display = 'none';
    } else if (tab === 'departments') {
        buttons[3].classList.add('active');
        document.getElementById('kpiListTab').style.display = 'none';
        document.getElementById('departmentsTab').style.display = 'block';
    }
}

function filterByStatus(status) {
    document.getElementById('statusFilter').value = status;
    document.querySelector('form').submit();
}

function exportAllKPIs() {
    window.location.href = 'export-kpi-report-pdf?year=<?php echo $filter_year; ?>&department=<?php echo $filter_department; ?>';
}
</script>