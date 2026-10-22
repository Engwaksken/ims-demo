<?php
$page_title = 'Users Management';
include 'includes/header.php';

check_role(['Administrator']);

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}



// -- Role list -------------------------------------------------
// Built-in roles plus roles added on the User Permissions page.
require_once __DIR__ . '/includes/roles.php';
$roles = ims_all_roles($conn);

// -- Get user for editing --------------------------------------
$edit_user = null;
if (isset($_GET['edit'])) {
    $uid  = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// -- Search / filter / pagination params ----------------------
$search       = trim((string)($_GET['q']           ?? ''));
$filterRole   = trim((string)($_GET['role_filter'] ?? ''));
$filterStatus = trim((string)($_GET['status']      ?? ''));
$perPage      = 15;
$page         = max(1, (int)($_GET['p'] ?? 1));

// -- Build WHERE clause (parameterised) -----------------------
$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ?)";
    array_push($params, $like, $like, $like, $like);
    $types  .= 'ssss';
}
if ($filterRole !== '') {
    $where[]  = "role = ?";
    $params[] = $filterRole;
    $types   .= 's';
}
if ($filterStatus === 'active') {
    $where[] = "is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $where[] = "is_active = 0";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// -- Overall stats (always unfiltered) ------------------------
$sr = $conn->query("
    SELECT
        COUNT(*)                    AS total,
        SUM(is_active = 1)          AS active,
        SUM(is_active = 0)          AS inactive,
        SUM(role = 'Administrator') AS admins
    FROM users
")->fetch_assoc();

$totalAll = (int)$sr['total'];
$active   = (int)$sr['active'];
$inactive = (int)$sr['inactive'];
$admins   = (int)$sr['admins'];

// -- Count filtered rows ---------------------------------------
if ($where) {
    $cs = $conn->prepare("SELECT COUNT(*) AS cnt FROM users $whereSql");
    if ($params) {
        $cs->bind_param($types, ...$params);
    }
    $cs->execute();
    $filteredTotal = (int)$cs->get_result()->fetch_assoc()['cnt'];
    $cs->close();
} else {
    $filteredTotal = $totalAll;
}

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// -- Fetch current page ----------------------------------------
$users    = [];
$pTypes   = $types . 'ii';
$pParams  = array_merge($params, [$perPage, $offset]);
$ds = $conn->prepare("
    SELECT user_id, username, email, full_name, role, phone, is_active, created_at, last_login
    FROM users $whereSql
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$ds->bind_param($pTypes, ...$pParams);
$ds->execute();
$res = $ds->get_result();
while ($row = $res->fetch_assoc()) { $users[] = $row; }
$ds->close();

// -- Pagination helpers ----------------------------------------
function pageUrl(int $p): string {
    $qs    = $_GET;
    $qs['p'] = $p;
    unset($qs['edit']);
    return '?' . http_build_query($qs);
}

function pageRange(int $cur, int $total): array {
    $range = [];
    for ($i = max(1, $cur - 2); $i <= min($total, $cur + 2); $i++) {
        $range[] = $i;
    }
    if (($range[0] ?? 1) > 2)   array_unshift($range, '...');
    if (($range[0] ?? 1) > 1)   array_unshift($range, 1);
    $last = end($range);
    if ($last < $total - 1) $range[] = '...';
    if ($last < $total)     $range[] = $total;
    return $range;
}
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/users.css">

<div class="assets-wrap">

    <!-- -- Page Hero -------------------------------------------- -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-users-cog"></i> Users Management</h1>
            <p>Manage system accounts, roles, and access permissions</p>
        </div>
        <div class="hero-actions">
            <a href="user-permissions" class="btn btn-primary">
                <i class="fas fa-shield-alt"></i> Manage Roles
            </a>
            <button class="btn btn-dark" onclick="openModal('addUserModal')">
                <i class="fas fa-user-plus"></i> Add User
            </button>
        </div>
    </div>

    <!-- -- Stats ------------------------------------------------ -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total Users</span>
                <span class="stat-value"><?= number_format($totalAll) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-user-check"></i></div>
            <div class="stat-info">
                <span class="stat-label">Active</span>
                <span class="stat-value"><?= number_format($active) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-red"><i class="fas fa-user-times"></i></div>
            <div class="stat-info">
                <span class="stat-label">Inactive</span>
                <span class="stat-value"><?= number_format($inactive) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-user-shield"></i></div>
            <div class="stat-info">
                <span class="stat-label">Administrators</span>
                <span class="stat-value"><?= number_format($admins) ?></span>
            </div>
        </div>
    </div>

    <!-- -- Users Panel ------------------------------------------ -->
    <div class="panel">

        <!-- Search + filters toolbar -->
        <div class="users-panel-head">
            <h3 class="panel-title"><i class="fas fa-list"></i> All Users</h3>

            <form method="GET" class="users-toolbar filters-bar" id="filterForm" role="search">
                <?php if ($edit_user): ?>
                    <input type="hidden" name="edit" value="<?= (int)$edit_user['user_id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="p" value="1">

                <!-- Search -->
                <div class="search-box filters-grow">
                    <i class="fas fa-search search-icon"></i>
                    <input
                        type="search"
                        name="q"
                        class="search-input"
                        placeholder="Search name, email, username..."
                        value="<?= e($search) ?>"
                        autocomplete="off"
                        id="searchInput"
                    >
                    <?php if ($search !== ''): ?>
                        <a href="<?= e(pageUrl(1)) ?>" class="search-clear" title="Clear">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Role filter -->
                <select name="role_filter" class="filter-select" onchange="this.form.submit()">
                    <option value="">All roles</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r) ?>" <?= $filterRole === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Status filter -->
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value=""        <?= $filterStatus === ''         ? 'selected' : '' ?>>All status</option>
                    <option value="active"  <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive"<?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>

                <button type="submit" class="btn btn-sm btn-dark">
                    <i class="fas fa-search"></i><span class="btn-label"> Search</span>
                </button>

                <?php if ($search !== '' || $filterRole !== '' || $filterStatus !== ''): ?>
                    <a href="users" class="btn btn-sm btn-gray" title="Clear all filters">
                        <i class="fas fa-times"></i><span class="btn-label"> Clear</span>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Results meta bar -->
        <div class="results-meta">
            <span class="results-count">
                <?php if ($search !== '' || $filterRole !== '' || $filterStatus !== ''): ?>
                    <strong><?= number_format($filteredTotal) ?></strong> result<?= $filteredTotal !== 1 ? 's' : '' ?>
                    <?php if ($search !== ''): ?> for <em>"<?= e($search) ?>"</em><?php endif; ?>
                    <span class="note">- <?= number_format($totalAll) ?> total</span>
                <?php else: ?>
                    <strong><?= number_format($totalAll) ?></strong> user<?= $totalAll !== 1 ? 's' : '' ?>
                <?php endif; ?>
            </span>
            <span class="results-page-info">
                Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                &nbsp;.&nbsp;
                <?= number_format(($page - 1) * $perPage + 1) ?>-<?= number_format(min($page * $perPage, $filteredTotal)) ?>
            </span>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr class="empty-row">
                        <td colspan="9">
                            <i class="fas fa-user-slash"></i>
                            No users match your search.
                            <?php if ($search !== '' || $filterRole !== '' || $filterStatus !== ''): ?>
                                <a href="users" style="color:var(--brand-500);margin-left:6px;">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($users as $u): ?>
                    <?php $initials = strtoupper(mb_substr(trim($u['full_name']), 0, 1)); ?>
                    <tr>
                        <td class="note"><?= (int)$u['user_id'] ?></td>

                        <td>
                            <div class="u-cell">
                                <div class="u-avatar" data-letter="<?= e($initials) ?>"><?= e($initials) ?></div>
                                <div class="u-cell-body">
                                    <span class="u-name"><?= e($u['full_name']) ?></span>
                                    <span class="u-handle">@<?= e($u['username']) ?></span>
                                </div>
                            </div>
                        </td>

                        <td class="note"><?= e($u['email']) ?></td>

                        <td>
                            <span class="badge badge-role" data-role="<?= e($u['role']) ?>">
                                <?= e($u['role']) ?>
                            </span>
                        </td>

                        <td class="note"><?= e($u['phone'] ?? '-') ?></td>

                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge badge-issued">Active</span>
                            <?php else: ?>
                                <span class="badge badge-rejected">Inactive</span>
                            <?php endif; ?>
                        </td>

                        <td class="note"><?= date('d M Y', strtotime($u['created_at'])) ?></td>

                        <td>
                            <?php if ($u['last_login']): ?>
                                <span class="login-time"><?= date('d M Y H:i', strtotime($u['last_login'])) ?></span>
                            <?php else: ?>
                                <span class="login-never">Never</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="tbl-actions">
                                <a
                                    href="?edit=<?= (int)$u['user_id'] ?>&q=<?= e($search) ?>&role_filter=<?= e($filterRole) ?>&status=<?= e($filterStatus) ?>&p=<?= $page ?>"
                                    class="btn btn-sm btn-soft" title="Edit"
                                >
                                    <i class="fas fa-pencil-alt"></i>
                                </a>

                                <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                    <button
                                        class="btn btn-sm btn-red"
                                        title="Delete"
                                        onclick="openDeleteModal(<?= (int)$u['user_id'] ?>, <?= e(json_encode((string)$u['username'])) ?>)"
                                    ><i class="fas fa-trash-alt"></i></button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-gray" title="Cannot delete yourself" disabled>
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- -- Pagination --------------------------------------- -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-bar">
            <?php if ($page > 1): ?>
                <a href="<?= e(pageUrl($page - 1)) ?>" class="pg-btn" title="Previous page">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="pg-btn pg-disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php foreach (pageRange($page, $totalPages) as $pn): ?>
                <?php if ($pn === '...'): ?>
                    <span class="pg-ellipsis">...</span>
                <?php elseif ($pn === $page): ?>
                    <span class="pg-btn pg-active"><?= $pn ?></span>
                <?php else: ?>
                    <a href="<?= e(pageUrl((int)$pn)) ?>" class="pg-btn"><?= $pn ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e(pageUrl($page + 1)) ?>" class="pg-btn" title="Next page">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="pg-btn pg-disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.panel -->

</div><!-- /.assets-wrap -->


<!-- -- Add User Modal ------------------------------------------ -->
<div class="modal" id="addUserModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-user-plus"></i> Add New User</h3>
            <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/users-process.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Username <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="text" name="username" class="form-control" required>
                        <span class="form-hint">Used for login</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+256...">
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Role <sup style="color:var(--red-fg)">*</sup></label>
                        <select name="role" class="form-control" required>
                            <option value="">Select role...</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= e($r) ?>"><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Password <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                        <span class="form-hint">Minimum 6 characters</span>
                    </div>
                    <div class="form-group full">
                        <label class="check-label">
                            <input type="checkbox" name="is_active" checked>
                            Active - user can log in immediately
                        </label>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('addUserModal')">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-dark">
                        <i class="fas fa-save"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- -- Edit User Modal ----------------------------------------- -->
<div class="modal" id="editUserModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-user-edit"></i> Edit User</h3>
            <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($edit_user): ?>
            <form method="POST" action="includes/users-process.php">
                <input type="hidden" name="user_id" value="<?= (int)$edit_user['user_id'] ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Username <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="text" name="username" class="form-control"
                               value="<?= e($edit_user['username']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="text" name="full_name" class="form-control"
                               value="<?= e($edit_user['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="email" name="email" class="form-control"
                               value="<?= e($edit_user['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= e($edit_user['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Role <sup style="color:var(--red-fg)">*</sup></label>
                        <select name="role" class="form-control" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= e($r) ?>" <?= $edit_user['role'] === $r ? 'selected' : '' ?>>
                                    <?= e($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" class="form-control" minlength="6">
                        <span class="form-hint">Leave blank to keep the current password</span>
                    </div>
                    <div class="form-group full">
                        <label class="check-label">
                            <input type="checkbox" name="is_active" <?= $edit_user['is_active'] ? 'checked' : '' ?>>
                            Active - user can log in
                        </label>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('editUserModal')">Cancel</button>
                    <button type="submit" name="edit_user" class="btn btn-dark">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- -- Delete Confirmation Modal ------------------------------- -->
<div class="modal" id="deleteModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3 style="color:var(--red-fg)"><i class="fas fa-exclamation-triangle"></i> Delete User</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;color:var(--ink-500)">
                Are you sure you want to permanently delete <strong id="deleteUserName"></strong>?
            </p>
            <div class="delete-warning">
                <i class="fas fa-info-circle"></i>
                This action cannot be undone. All data associated with this account will be removed.
            </div>
            <form method="POST" action="includes/users-process.php">
                <input type="hidden" name="user_id" id="deleteUserId">
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" name="delete_user" class="btn btn-red">
                        <i class="fas fa-trash-alt"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

window.addEventListener('click', function (e) {
    document.querySelectorAll('.modal.show').forEach(function (m) {
        if (e.target === m) m.classList.remove('show');
    });
});

function openDeleteModal(userId, username) {
    document.getElementById('deleteUserId').value       = userId;
    document.getElementById('deleteUserName').textContent = username;
    openModal('deleteModal');
}

// Debounce: submit search 450 ms after user stops typing
(function () {
    var input = document.getElementById('searchInput');
    if (!input) return;
    var timer;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            document.getElementById('filterForm').submit();
        }, 450);
    });
})();

<?php if ($edit_user): ?>
window.addEventListener('DOMContentLoaded', function () { openModal('editUserModal'); });
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>