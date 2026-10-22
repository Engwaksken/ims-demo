<?php
// Frontend - My KPIs (Employee View)
$page_title = 'My KPIs';
include 'includes/header.php';

$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

// Get current user's department
$user_dept = null;
$stmt = $conn->prepare("SELECT ed.department_id, d.department_name
    FROM employee_directory ed
    LEFT JOIN departments d ON ed.department_id = d.department_id
    WHERE ed.user_id = ?
    LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $user_dept = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$user_dept || !$user_dept['department_id']) {
    echo '<div class="alert alert-warning">Please complete your employee profile first to set up KPIs.</div>';
    include 'includes/footer.php';
    exit();
}

// Get filter parameters (whitelisted)
$allowed_kpi_statuses = ['Draft', 'Submitted', 'Under Review', 'Approved', 'Rejected', 'Completed'];
$filter_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($filter_year < 2000 || $filter_year > (int) date('Y') + 5) {
    $filter_year = (int) date('Y');
}
$filter_status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
if (!in_array($filter_status, $allowed_kpi_statuses, true)) {
    $filter_status = '';
}

// Build WHERE clause (prepared)
$where = "k.user_id = ? AND k.fiscal_year = ?";
$types = 'ii';
$params = [$current_user_id, $filter_year];
if ($filter_status !== '') {
    $where .= " AND k.status = ?";
    $types .= 's';
    $params[] = $filter_status;
}

// Fetch user's KPIs
$kpis = [];
$query = "SELECT k.*, c.category_name, c.category_code
    FROM kpis k
    LEFT JOIN kpi_categories c ON k.category_id = c.category_id
    WHERE $where
    ORDER BY k.created_at DESC";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $kpis[] = $row;
        }
    }
    $stmt->close();
} else {
    error_log('my-kpis query failed: ' . $conn->error);
}

// Fetch KPI categories
$categories = [];
$result = $conn->query("SELECT * FROM kpi_categories ORDER BY category_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Calculate statistics
$total_kpis = count($kpis);
$draft = count(array_filter($kpis, fn($k) => $k['status'] == 'Draft'));
$submitted = count(array_filter($kpis, fn($k) => $k['status'] == 'Submitted'));
$approved = count(array_filter($kpis, fn($k) => in_array($k['status'], ['Approved', 'Completed'], true)));
$rejected = count(array_filter($kpis, fn($k) => $k['status'] == 'Rejected'));

// Calculate average achievement
$completed_kpis = array_filter($kpis, fn($k) => $k['overall_achievement'] !== null);
$avg_achievement = !empty($completed_kpis) ? 
    round(array_sum(array_column($completed_kpis, 'overall_achievement')) / count($completed_kpis), 1) : 0;
?>

<style>
.kpi-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s;
    border-left: 4px solid var(--primary-color);
}

.kpi-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.kpi-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
}

.kpi-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 5px;
}

.kpi-meta {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #7f8c8d;
    margin-bottom: 15px;
}

.quarterly-progress {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-top: 15px;
}

.quarter-box {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    text-align: center;
    border: 2px solid #ecf0f1;
}

.quarter-label {
    font-size: 11px;
    color: #7f8c8d;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.quarter-target {
    font-size: 20px;
    font-weight: bold;
    color: var(--primary-color);
    margin-bottom: 3px;
}

.quarter-actual {
    font-size: 14px;
    color: #27AE60;
}

.quarter-status {
    margin-top: 8px;
}

.achievement-bar {
    height: 8px;
    background: #ecf0f1;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 5px;
}

.achievement-fill {
    height: 100%;
    background: linear-gradient(90deg, #27AE60, #2ECC71);
    transition: width 0.5s ease;
}

.kpi-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Display fixes: readable contrast, wrapping header, responsive quarters. */
.kpi-header {
    gap: 12px;
    flex-wrap: wrap;
}

.kpi-header-main {
    flex: 1;
    min-width: 0;
}

.kpi-title {
    overflow-wrap: anywhere;
}

.kpi-meta,
.kpi-description {
    color: #5f6b76;
}

.kpi-description {
    margin-bottom: 15px;
}

.quarter-actual,
.quarter-achievement {
    color: #15803d;
}

.quarter-achievement {
    font-size: 12px;
    font-weight: 600;
    margin-top: 3px;
}

.quarter-status .badge {
    font-size: 11px;
}

.kpi-overall {
    margin-top: 15px;
    padding: 12px;
    background: #e8f5e9;
    border-radius: 8px;
    text-align: center;
    color: #15803d;
}

@media (max-width: 768px) {
    .quarterly-progress {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .kpi-card {
        padding: 16px;
    }

    .kpi-actions .btn {
        flex: 1 1 auto;
        justify-content: center;
    }
}
</style>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-bullseye" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total KPIs (<?php echo $filter_year; ?>)</span>
            <span class="stat-value"><?php echo $total_kpis; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-slate"><i class="fas fa-edit" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Draft KPIs</span>
            <span class="stat-value"><?php echo $draft; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-paper-plane" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Submitted</span>
            <span class="stat-value"><?php echo $submitted; ?></span>
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
        <div class="stat-icon bg-purple"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Avg Achievement</span>
            <span class="stat-value"><?php echo $avg_achievement; ?>%</span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bullseye"></i> My KPIs - <?php echo h($user_dept['department_name'] ?? ''); ?></h3>
        <div class="card-header-actions">
            <a href="create-kpi" class="btn btn-success">
                <i class="fas fa-plus" aria-hidden="true"></i> Create New KPI
            </a>
        </div>
    </div>

    <div class="card-body">
        <!-- Filters -->
        <form method="GET" action="" class="filters-bar" aria-label="Filter KPIs">
            <div class="form-group">
                <label for="kpiYear">Fiscal Year</label>
                <select id="kpiYear" name="year" class="form-control">
                    <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="kpiStatus">Status</label>
                <select id="kpiStatus" name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="Draft" <?php echo $filter_status == 'Draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="Submitted" <?php echo $filter_status == 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                    <option value="Under Review" <?php echo $filter_status == 'Under Review' ? 'selected' : ''; ?>>Under Review</option>
                    <option value="Approved" <?php echo $filter_status == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Rejected" <?php echo $filter_status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            
            <div class="filters-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter" aria-hidden="true"></i> Filter
                </button>
                <a href="my-kpis" class="btn btn-secondary">
                    <i class="fas fa-times" aria-hidden="true"></i> Clear
                </a>
            </div>
        </form>

        <!-- KPI List -->
        <?php if (empty($kpis)): ?>
            <div class="empty-state">
                <i class="fas fa-bullseye" aria-hidden="true"></i>
                <h4>No KPIs Found</h4>
                <p>Create your first KPI to get started with performance tracking.</p>
                <a href="create-kpi" class="btn btn-success">
                    <i class="fas fa-plus" aria-hidden="true"></i> Create Your First KPI
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($kpis as $kpi): ?>
                <div class="kpi-card" style="border-left-color: <?php 
                    echo $kpi['status'] == 'Approved' ? '#27AE60' : 
                        ($kpi['status'] == 'Submitted' ? '#3498DB' : 
                        ($kpi['status'] == 'Rejected' ? '#E74C3C' : '#95A5A6')); 
                ?>;">
                    <div class="kpi-header">
                        <div class="kpi-header-main">
                            <div class="kpi-title"><?php echo htmlspecialchars($kpi['kpi_title']); ?></div>
                            <div class="kpi-meta">
                                <span><i class="fas fa-tag"></i> <?php echo h($kpi['category_name'] ?? '-'); ?></span>
                                <span><i class="fas fa-weight"></i> Weight: <?php echo h($kpi['weight_percentage']); ?>%</span>
                                <span><i class="fas fa-bullseye"></i> Target: <?php echo h($kpi['target_value']); ?> <?php echo h($kpi['unit_of_measure'] ?? ''); ?></span>
                            </div>
                        </div>
                        <div>
                            <span class="badge badge-<?php 
                                echo $kpi['status'] == 'Approved' ? 'success' : 
                                    ($kpi['status'] == 'Submitted' ? 'info' : 
                                    ($kpi['status'] == 'Under Review' ? 'warning' :
                                    ($kpi['status'] == 'Rejected' ? 'danger' : 'secondary'))); 
                            ?>">
                                <?php echo h($kpi['status']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($kpi['kpi_description']): ?>
                        <p class="kpi-description"><?php echo htmlspecialchars($kpi['kpi_description']); ?></p>
                    <?php endif; ?>
                    
                    <!-- Quarterly Progress -->
                    <div class="quarterly-progress">
                        <?php 
                        $quarters = ['Q1', 'Q2', 'Q3', 'Q4'];
                        foreach ($quarters as $q): 
                            $q_lower = strtolower($q);
                            $target = $kpi["{$q_lower}_target"];
                            $actual = $kpi["{$q_lower}_actual"];
                            $achievement = $kpi["{$q_lower}_achievement"];
                            $status = $kpi["{$q_lower}_status"];
                        ?>
                            <div class="quarter-box">
                                <div class="quarter-label"><?php echo $q; ?></div>
                                <div class="quarter-target"><?php echo $target !== null && $target !== '' ? h($target) : '-'; ?></div>
                                <?php if ($actual !== null): ?>
                                    <div class="quarter-actual">Actual: <?php echo h($actual); ?></div>
                                <?php endif; ?>
                                <?php if ($status !== null && $status !== ''): ?>
                                <div class="quarter-status">
                                    <span class="badge badge-<?php
                                        echo $status == 'Completed' ? 'success' :
                                            ($status == 'In Progress' ? 'info' :
                                            ($status == 'Delayed' ? 'danger' : 'secondary'));
                                    ?>">
                                        <?php echo h($status); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($achievement !== null): ?>
                                    <div class="achievement-bar">
                                        <div class="achievement-fill" style="width: <?php echo max(0, min((float) $achievement, 100)); ?>%;"></div>
                                    </div>
                                    <div class="quarter-achievement"><?php echo h($achievement); ?>%</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($kpi['overall_achievement'] !== null): ?>
                        <div class="kpi-overall">
                            <strong>Overall Achievement: <?php echo h($kpi['overall_achievement']); ?>%</strong>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($kpi['status'] == 'Rejected' && $kpi['rejection_reason']): ?>
                        <div class="alert alert-danger alert-static" data-persist style="margin-top: 15px; display: block;">
                            <strong>Rejection Reason:</strong><br>
                            <?php echo nl2br(htmlspecialchars($kpi['rejection_reason'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="kpi-actions" style="margin-top: 15px;">
                        <a href="view-kpi?id=<?php echo (int) $kpi['kpi_id']; ?>" class="btn btn-info btn-sm">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        
                        <?php if ($kpi['status'] == 'Draft' || $kpi['status'] == 'Rejected'): ?>
                            <a href="edit-kpi?id=<?php echo (int) $kpi['kpi_id']; ?>" class="btn btn-warning btn-sm">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <button onclick="submitKPI(<?php echo (int) $kpi['kpi_id']; ?>)" class="btn btn-success btn-sm">
                                <i class="fas fa-paper-plane"></i> Submit
                            </button>
                            <button onclick="deleteKPI(<?php echo (int) $kpi['kpi_id']; ?>)" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($kpi['status'] == 'Approved'): ?>
                            <a href="update-kpi-progress?id=<?php echo (int) $kpi['kpi_id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-chart-line"></i> Update Progress
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// State-changing actions are sent as POST with the CSRF token (not GET links).
function postKpiAction(action, kpiId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'kpi-process';
    [['action', action], ['kpi_id', kpiId], ['csrf_token', <?php echo json_encode(csrf_token()); ?>]].forEach(function (pair) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = pair[0];
        input.value = pair[1];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

function submitKPI(kpiId) {
    if (confirm('Are you sure you want to submit this KPI for review?')) {
        postKpiAction('submit', kpiId);
    }
}

function deleteKPI(kpiId) {
    if (confirm('Are you sure you want to delete this KPI? This action cannot be undone.')) {
        postKpiAction('delete', kpiId);
    }
}
</script>