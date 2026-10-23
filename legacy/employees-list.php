<?php
$page_title = 'Employee Directory';

// Authorise before any markup is rendered.
require_once __DIR__ . '/includes/config.php';
check_role(['Administrator', 'Programs Lead', 'HR']);

include 'includes/header.php';

// Get filter parameters (whitelisted against the column enums)
$allowed_contracts = ['Staff', 'Consultant', 'Temp', 'Casual'];
$allowed_statuses = ['Draft', 'Submitted', 'Approved', 'Rejected'];

$filter_department = isset($_GET['department']) ? max(0, (int) $_GET['department']) : 0;
$filter_contract = isset($_GET['contract']) ? sanitize_input($_GET['contract']) : '';
$filter_status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$search = isset($_GET['search']) ? mb_substr(sanitize_input($_GET['search']), 0, 100) : '';

if (!in_array($filter_contract, $allowed_contracts, true)) {
    $filter_contract = '';
}

if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = '';
}

// Build WHERE clause (prepared statement - previously interpolated raw input)
$where = ['1=1'];
$types = '';
$params = [];

if ($filter_department > 0) {
    $where[] = 'ed.department_id = ?';
    $types .= 'i';
    $params[] = $filter_department;
}
if ($filter_contract !== '') {
    $where[] = 'ed.contract_type = ?';
    $types .= 's';
    $params[] = $filter_contract;
}
if ($filter_status !== '') {
    $where[] = 'ed.status = ?';
    $types .= 's';
    $params[] = $filter_status;
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_') . '%';
    $where[] = '(ed.full_name LIKE ? OR ed.job_title LIKE ? OR ed.company_email LIKE ? OR d.department_name LIKE ?)';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

// Fetch employees (only the columns the directory needs - no bank/NIN/ID data)
$employees = [];
$query = "SELECT ed.employee_id, ed.user_id, ed.full_name, ed.job_title, ed.contract_type,
        ed.company_email, ed.phone, ed.status, ed.gender, ed.start_date, ed.department_id,
        d.department_name, d.department_code,
        u.username, u.role AS user_role,
        supervisor.full_name AS supervisor_name
    FROM employee_directory ed
    LEFT JOIN departments d ON ed.department_id = d.department_id
    LEFT JOIN users u ON ed.user_id = u.user_id
    LEFT JOIN users supervisor ON ed.supervisor_id = supervisor.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ed.full_name";

$stmt = $conn->prepare($query);

if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
    } else {
        error_log('employees-list query failed: ' . $stmt->error);
    }

    $stmt->close();
} else {
    error_log('employees-list prepare failed: ' . $conn->error);
}

// Fetch departments for filter
$departments = [];
$result = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Calculate statistics
$stats = [
    'total' => count($employees),
    'staff' => count(array_filter($employees, fn($e) => $e['contract_type'] == 'Staff')),
    'consultants' => count(array_filter($employees, fn($e) => $e['contract_type'] == 'Consultant')),
    'approved' => count(array_filter($employees, fn($e) => $e['status'] == 'Approved')),
    'pending' => count(array_filter($employees, fn($e) => $e['status'] == 'Submitted')),
    'male' => count(array_filter($employees, fn($e) => $e['gender'] == 'Male')),
    'female' => count(array_filter($employees, fn($e) => $e['gender'] == 'Female'))
];
?>

<style>
.employee-card {
    background: var(--surface-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: var(--shadow-sm);
    transition: box-shadow .2s var(--ease-out), transform .2s var(--ease-out);
    display: flex;
    align-items: center;
    gap: 20px;
}

.employee-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.employee-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--brand-700), var(--brand-500));
    color: var(--ink-0);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: bold;
    flex-shrink: 0;
}

.employee-info {
    flex: 1;
}

.employee-name {
    font-size: 20px;
    font-weight: 600;
    color: var(--ink-700);
    margin-bottom: 5px;
}

.employee-title {
    color: var(--ink-300);
    margin-bottom: 10px;
}

.employee-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 14px;
}

.employee-meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
}

.employee-actions {
    display: flex;
    gap: 10px;
    flex-direction: column;
}

.view-mode-toggle {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.view-mode-btn {
    padding: 8px 16px;
    border: 1px solid var(--border);
    background: var(--surface-card);
    color: var(--ink-500);
    font-weight: 600;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.2s;
}

.view-mode-btn.active {
    background: var(--brand-600);
    color: var(--ink-0);
    border-color: var(--brand-600);
}

.grid-view {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

.grid-view .employee-card {
    flex-direction: column;
    text-align: center;
}

.grid-view .employee-meta {
    justify-content: center;
}

.grid-view .employee-actions {
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
}

/* Display fixes: long emails/names no longer push the card wider than the
   screen; meta text uses a readable grey. */
.employee-info {
    min-width: 0;
}

.employee-name,
.employee-meta-item span {
    overflow-wrap: anywhere;
}

.employee-meta {
    color: var(--ink-400);
}

.employee-meta-item i {
    width: 14px;
    text-align: center;
    color: var(--ink-200);
}

.employee-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.grid-view .employee-badges {
    justify-content: center;
}

.employee-actions .btn {
    justify-content: center;
    white-space: nowrap;
}

@media (max-width: 768px) {
    .employee-card {
        flex-direction: column;
        align-items: stretch;
        text-align: left;
        gap: 14px;
        padding: 16px;
    }

    .employee-avatar {
        width: 56px;
        height: 56px;
        font-size: 22px;
    }

    .employee-name {
        font-size: 17px;
    }

    .employee-meta {
        gap: 8px 16px;
        font-size: 13px;
    }

    .employee-actions,
    .grid-view .employee-actions {
        flex-direction: row;
        flex-wrap: wrap;
    }

    .employee-actions .btn {
        flex: 1 1 auto;
    }

    .grid-view {
        grid-template-columns: 1fr;
    }

    .grid-view .employee-card {
        align-items: center;
        text-align: center;
    }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-address-book" aria-hidden="true"></i> Employee Directory</h1>
        <p>Browse staff and consultant profiles, contacts and approval status.</p>
    </div>
    <div class="hero-actions">
        <a href="employee-reports" class="btn">
            <i class="fas fa-chart-bar" aria-hidden="true"></i> Reports
        </a>
        <a href="kpi-management" class="btn btn-primary">
            <i class="fas fa-bullseye" aria-hidden="true"></i> Employee KPIs
        </a>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Employees</span>
            <span class="stat-value"><?php echo number_format($stats['total']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-user-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Staff Members</span>
            <span class="stat-value"><?php echo $stats['staff']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-user-tie" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Consultants</span>
            <span class="stat-value"><?php echo $stats['consultants']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Approved Profiles</span>
            <span class="stat-value"><?php echo $stats['approved']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-male" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Male</span>
            <span class="stat-value"><?php echo $stats['male']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-female" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Female</span>
            <span class="stat-value"><?php echo $stats['female']; ?></span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list" aria-hidden="true"></i> Employees</h3>
        <span class="cell-muted"><?php echo number_format($stats['total']); ?> shown</span>
    </div>
    
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" action="" class="filters-bar" role="search" aria-label="Filter employees">
            <div class="form-group filters-grow">
                <label for="empSearch" class="sr-only">Search employees</label>
                <div class="search-input-wrap">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="text" id="empSearch" name="search" class="form-control" placeholder="Search name, title or email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="empDepartment" class="sr-only">Department</label>
                <select id="empDepartment" name="department" class="form-control">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo (int) $dept['department_id']; ?>" <?php echo $filter_department === (int) $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="empContract" class="sr-only">Contract type</label>
                <select id="empContract" name="contract" class="form-control">
                    <option value="">All Contract Types</option>
                    <option value="Staff" <?php echo $filter_contract == 'Staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="Consultant" <?php echo $filter_contract == 'Consultant' ? 'selected' : ''; ?>>Consultant</option>
                    <option value="Temp" <?php echo $filter_contract == 'Temp' ? 'selected' : ''; ?>>Temp</option>
                    <option value="Casual" <?php echo $filter_contract == 'Casual' ? 'selected' : ''; ?>>Casual</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="empStatus" class="sr-only">Profile status</label>
                <select id="empStatus" name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="Draft" <?php echo $filter_status == 'Draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="Submitted" <?php echo $filter_status == 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                    <option value="Approved" <?php echo $filter_status == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Rejected" <?php echo $filter_status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            
            <div class="filters-actions">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-filter" aria-hidden="true"></i> Filter
                </button>
                <a href="employees-list" class="btn btn-secondary">
                    <i class="fas fa-times" aria-hidden="true"></i> Clear
                </a>
            </div>
        </form>

        <!-- View Mode Toggle -->
        <div class="view-mode-toggle" role="group" aria-label="Layout">
            <button type="button" class="view-mode-btn active" onclick="switchView('list')" id="listViewBtn">
                <i class="fas fa-list" aria-hidden="true"></i> List View
            </button>
            <button type="button" class="view-mode-btn" onclick="switchView('grid')" id="gridViewBtn">
                <i class="fas fa-th" aria-hidden="true"></i> Grid View
            </button>
        </div>
        
        <!-- Employees List -->
        <div id="employeesList" class="list-view">
            <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <i class="fas fa-users" aria-hidden="true"></i>
                    <h4>No employees found</h4>
                    <p>Try adjusting your filters or search criteria</p>
                </div>
            <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                    <div class="employee-card">
                        <div class="employee-avatar">
                            <?php echo h(mb_strtoupper(mb_substr((string) $emp['full_name'], 0, 1))); ?>
                        </div>
                        
                        <div class="employee-info">
                            <div class="employee-name">
                                <?php echo htmlspecialchars($emp['full_name']); ?>
                                <?php if ((int) $emp['user_id'] === (int) ($_SESSION['user_id'] ?? 0)): ?>
                                    <span class="badge badge-info">You</span>
                                <?php endif; ?>
                            </div>
                            <div class="employee-title">
                                <?php echo htmlspecialchars($emp['job_title']); ?>
                            </div>
                            
                            <div class="employee-meta">
                                <div class="employee-meta-item">
                                    <i class="fas fa-building"></i>
                                    <span><?php echo h($emp['department_name'] ?? '-'); ?></span>
                                </div>
                                <div class="employee-meta-item">
                                    <i class="fas fa-briefcase"></i>
                                    <span><?php echo h($emp['contract_type']); ?></span>
                                </div>
                                <div class="employee-meta-item">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo htmlspecialchars($emp['company_email']); ?></span>
                                </div>
                                <div class="employee-meta-item">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($emp['phone']); ?></span>
                                </div>
                                <?php if ($emp['supervisor_name']): ?>
                                <div class="employee-meta-item">
                                    <i class="fas fa-user-tie"></i>
                                    <span>Reports to: <?php echo htmlspecialchars($emp['supervisor_name']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="employee-badges">
                                <span class="badge badge-<?php
                                    echo $emp['status'] == 'Approved' ? 'success' :
                                        ($emp['status'] == 'Submitted' ? 'info' :
                                        ($emp['status'] == 'Draft' ? 'secondary' :
                                        ($emp['status'] == 'Rejected' ? 'danger' : 'warning')));
                                ?>">
                                    <?php echo h($emp['status']); ?>
                                </span>

                                <?php if (!empty($emp['gender'])): ?>
                                <span class="badge <?php echo $emp['gender'] == 'Male' ? 'badge-info' : 'badge-purple'; ?>">
                                    <?php echo h($emp['gender']); ?>
                                </span>
                                <?php endif; ?>
                                
                                <?php
                                try {
                                    $start = new DateTime((string) ($emp['start_date'] ?: 'now'));
                                } catch (Exception $e) {
                                    $start = new DateTime();
                                }
                                $today = new DateTime();
                                $tenure = $start->diff($today);
                                $tenure_text = '';
                                if ($tenure->y > 0) {
                                    $tenure_text = $tenure->y . 'y';
                                }
                                if ($tenure->m > 0) {
                                    $tenure_text .= ' ' . $tenure->m . 'm';
                                }
                                ?>
                                <span class="badge badge-dark">
                                    <i class="fas fa-calendar"></i> <?php echo $tenure_text ?: $tenure->d . 'd'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="employee-actions">
                            <a href="employee-profile-view?id=<?php echo (int) $emp['employee_id']; ?>" 
                               class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> View Profile
                            </a>
                            
                            <?php if (ims_allowed('employees.approve', ['Administrator'], 'Approve or reject employee profiles')): ?>
                                <?php if ($emp['status'] == 'Submitted'): ?>
                                    <button onclick="approveEmployee(<?php echo (int) $emp['employee_id']; ?>, <?php echo h(json_encode((string) $emp['full_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>)" 
                                            class="btn btn-success btn-sm">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button onclick="rejectEmployee(<?php echo (int) $emp['employee_id']; ?>, <?php echo h(json_encode((string) $emp['full_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>)" 
                                            class="btn btn-danger btn-sm">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <a href="mailto:<?php echo htmlspecialchars($emp['company_email']); ?>" 
                               class="btn btn-info btn-sm">
                                <i class="fas fa-envelope"></i> Email
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php 
// Include modals
include 'includes/employee-action-modals.php';
include 'includes/footer.php'; 
?>

<script>
function switchView(mode) {
    const listView = document.getElementById('employeesList');
    const listBtn = document.getElementById('listViewBtn');
    const gridBtn = document.getElementById('gridViewBtn');
    
    if (mode === 'grid') {
        listView.classList.remove('list-view');
        listView.classList.add('grid-view');
        listBtn.classList.remove('active');
        gridBtn.classList.add('active');
    } else {
        listView.classList.remove('grid-view');
        listView.classList.add('list-view');
        listBtn.classList.add('active');
        gridBtn.classList.remove('active');
    }
}

function approveEmployee(employeeId, employeeName) {
    document.getElementById('approveEmployeeId').value = employeeId;
    document.getElementById('approveEmployeeName').textContent = employeeName;
    openModal('approveEmployeeModal');
}

function rejectEmployee(employeeId, employeeName) {
    document.getElementById('rejectEmployeeId').value = employeeId;
    document.getElementById('rejectEmployeeName').textContent = employeeName;
    openModal('rejectEmployeeModal');
}
</script>