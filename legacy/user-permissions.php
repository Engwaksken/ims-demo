<?php
ob_start();

$page_title = 'User Permissions';
include 'includes/header.php';

require_once __DIR__ . '/includes/roles.php';

check_role(['Administrator']);

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// -- Roles: built-in + added by administrators -------------------
$all_roles   = ims_all_roles($conn);
$customRoles = ims_custom_roles($conn);
$roleCounts  = ims_role_user_counts($conn);

// -- Filter / pagination params --------------------------------
$filter_role   = trim((string)($_GET['role']   ?? ''));
$filter_status = trim((string)($_GET['status'] ?? ''));
$search        = trim((string)($_GET['search'] ?? ''));
$page          = max(1, (int)($_GET['page'] ?? 1));
$limit         = 20;

// Allow filtering by any role a user actually has, even an unknown one.
if ($filter_role !== '' && !in_array($filter_role, $all_roles, true) && !isset($roleCounts[$filter_role])) {
    $filter_role = '';
}
if ($filter_status !== '' && !in_array($filter_status, ['1', '0'], true)) {
    $filter_status = '';
}

// -- Build WHERE -----------------------------------------------
$where  = [];
$params = [];
$types  = '';

if ($filter_role !== '') {
    $where[]  = "u.role = ?";
    $params[] = $filter_role;
    $types   .= 's';
}
if ($filter_status !== '') {
    $where[]  = "u.is_active = ?";
    $params[] = (int)$filter_status;
    $types   .= 'i';
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.role LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
    $types   .= 'sssss';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// -- Count -----------------------------------------------------
$cs = $conn->prepare("SELECT COUNT(*) AS total FROM users u $whereSql");
if ($types !== '') { $cs->bind_param($types, ...$params); }
$cs->execute();
$totalRows = (int)($cs->get_result()->fetch_assoc()['total'] ?? 0);
$cs->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $limit;

// -- Fetch page ------------------------------------------------
$listTypes  = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);

$stmt = $conn->prepare("
    SELECT
        u.user_id, u.username, u.full_name, u.email, u.phone,
        u.role, u.is_active, u.last_login, u.created_at,
        (SELECT COUNT(*) FROM activity_log al WHERE al.user_id = u.user_id) AS total_actions
    FROM users u
    $whereSql
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Edit user -------------------------------------------------
$edit_user = null;
if (!empty($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $es = $conn->prepare("SELECT user_id, username, full_name, email, role, is_active FROM users WHERE user_id = ? LIMIT 1");
    $es->bind_param("i", $edit_id);
    $es->execute();
    $edit_user = $es->get_result()->fetch_assoc();
    $es->close();
}

// -- Global stats (always unfiltered) -------------------------
$sr = $conn->query("
    SELECT
        COUNT(*)                    AS total,
        SUM(role = 'Administrator') AS administrators,
        SUM(is_active = 1)          AS active,
        SUM(is_active = 0)          AS inactive
    FROM users
")->fetch_assoc();

$rolesInUse = count(array_filter($roleCounts));

// -- URL helper (preserve filters across pagination/edit) ------
function perm_url(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    unset($query['roles']);
    return '?' . http_build_query($query);
}

// -- Pagination page range -------------------------------------
function perm_page_range(int $cur, int $total): array
{
    $range = [];
    for ($i = max(1, $cur - 2); $i <= min($total, $cur + 2); $i++) {
        $range[] = $i;
    }
    if (($range[0] ?? 1) > 2)  array_unshift($range, '...');
    if (($range[0] ?? 1) > 1)  array_unshift($range, 1);
    $last = end($range);
    if ($last < $total - 1) $range[] = '...';
    if ($last < $total)     $range[] = $total;
    return $range;
}

$isFiltered = ($search !== '' || $filter_role !== '' || $filter_status !== '');
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/users.css">

<div class="assets-wrap">

    <!-- Page Hero -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-user-lock" aria-hidden="true"></i> User Permissions</h1>
            <p>Manage roles, access levels, and account status across all users</p>
        </div>
        <div class="hero-actions">
            <button type="button" class="btn btn-primary" onclick="openRolesModal(true)">
                <i class="fas fa-plus" aria-hidden="true"></i> Add Role
            </button>
            <button type="button" class="btn btn-soft" onclick="openRolesModal(false)">
                <i class="fas fa-id-badge" aria-hidden="true"></i> Manage Roles
            </button>
            <a href="role-permissions" class="btn btn-soft">
                <i class="fas fa-shield-halved" aria-hidden="true"></i> Role Permissions
            </a>
            <a href="users" class="btn btn-dark">
                <i class="fas fa-users-cog" aria-hidden="true"></i> Manage Users
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid perm-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total Users</span>
                <span class="stat-value"><?= number_format((int)$sr['total']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-red"><i class="fas fa-user-shield" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Admins</span>
                <span class="stat-value"><?= number_format((int)$sr['administrators']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-id-badge" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Roles (<?= count($customRoles) ?> added)</span>
                <span class="stat-value"><?= number_format(count($all_roles)) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-layer-group" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Roles in Use</span>
                <span class="stat-value"><?= number_format($rolesInUse) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-user-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Active</span>
                <span class="stat-value"><?= number_format((int)$sr['active']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--ink-50);color:var(--ink-300);">
                <i class="fas fa-user-times" aria-hidden="true"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Inactive</span>
                <span class="stat-value"><?= number_format((int)$sr['inactive']) ?></span>
            </div>
        </div>
    </div>

    <!-- Users Panel -->
    <div class="panel">

        <!-- Toolbar -->
        <div class="users-panel-head">
            <h3 class="panel-title"><i class="fas fa-user-lock" aria-hidden="true"></i> Permissions</h3>

            <form method="GET" class="users-toolbar filters-bar" id="filterForm" role="search">
                <input type="hidden" name="page" value="1">

                <div class="search-box filters-grow">
                    <i class="fas fa-search search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        name="search"
                        class="search-input"
                        placeholder="Search name, email, username, role..."
                        aria-label="Search users"
                        value="<?= e($search) ?>"
                        autocomplete="off"
                        id="searchInput"
                    >
                    <?php if ($search !== ''): ?>
                        <a href="<?= e(perm_url(['search' => '', 'page' => 1])) ?>" class="search-clear" title="Clear search" aria-label="Clear search">
                            <i class="fas fa-times" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <select name="role" class="filter-select" onchange="this.form.submit()" aria-label="Filter by role">
                    <option value="">All roles</option>
                    <?php foreach ($all_roles as $r): ?>
                        <option value="<?= e($r) ?>" <?= $filter_role === $r ? 'selected' : '' ?>>
                            <?= e($r) ?><?= isset($roleCounts[$r]) ? ' (' . (int)$roleCounts[$r] . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="filter-select" onchange="this.form.submit()" aria-label="Filter by status">
                    <option value=""  <?= $filter_status === ''  ? 'selected' : '' ?>>All status</option>
                    <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>

                <button type="submit" class="btn btn-sm btn-dark">
                    <i class="fas fa-search" aria-hidden="true"></i><span class="btn-label"> Search</span>
                </button>

                <?php if ($isFiltered): ?>
                    <a href="user-permissions" class="btn btn-sm btn-gray">
                        <i class="fas fa-times" aria-hidden="true"></i><span class="btn-label"> Clear</span>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Results meta -->
        <div class="results-meta">
            <span class="results-count">
                <?php if ($isFiltered): ?>
                    <strong><?= number_format($totalRows) ?></strong> result<?= $totalRows !== 1 ? 's' : '' ?>
                    <?php if ($search !== ''): ?> for <em>"<?= e($search) ?>"</em><?php endif; ?>
                    <span class="note">&middot; <?= number_format((int)$sr['total']) ?> total</span>
                <?php else: ?>
                    <strong><?= number_format($totalRows) ?></strong> user<?= $totalRows !== 1 ? 's' : '' ?>
                <?php endif; ?>
            </span>
            <span class="results-page-info">
                Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                <?php if ($totalRows > 0): ?>
                    &middot; <?= number_format($offset + 1) ?>&ndash;<?= number_format(min($offset + $limit, $totalRows)) ?>
                <?php endif; ?>
            </span>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>User</th>
                    <th>Contact</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Activity</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>

                <?php if (empty($users)): ?>
                    <tr class="empty-row">
                        <td colspan="7">
                            <i class="fas fa-user-slash" aria-hidden="true"></i>
                            No users match your search.
                            <?php if ($isFiltered): ?>
                                <a href="user-permissions" style="color:var(--brand-500);margin-left:6px;">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($users as $row):
                    $isSelf   = (int)$row['user_id'] === (int)$_SESSION['user_id'];
                    $isActive = (int)$row['is_active'] === 1;
                    $initials = strtoupper(mb_substr(trim((string)$row['full_name']), 0, 1));
                    $roleName = (string)$row['role'];
                    $isCustom = isset($customRoles[$roleName]);
                    $isKnown  = in_array($roleName, $all_roles, true);
                ?>
                    <tr>
                        <td>
                            <div class="u-cell">
                                <div class="u-avatar" data-letter="<?= e($initials) ?>" aria-hidden="true"><?= e($initials) ?></div>
                                <div class="u-cell-body">
                                    <span class="u-name">
                                        <?= e($row['full_name']) ?>
                                        <?php if ($isSelf): ?>
                                            <span class="self-tag">You</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="u-handle">@<?= e($row['username']) ?></span>
                                </div>
                            </div>
                        </td>

                        <td>
                            <span class="note" style="display:block;"><?= e($row['email'] ?: '-') ?></span>
                            <?php if ($row['phone']): ?>
                                <span class="note"><?= e($row['phone']) ?></span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge badge-role" data-role="<?= e($roleName) ?>"><?= e($roleName) ?></span>
                            <?php if ($isCustom): ?>
                                <span class="note role-like">works like <?= e($customRoles[$roleName]['base_role']) ?></span>
                            <?php elseif (!$isKnown): ?>
                                <span class="note role-like role-unknown" title="This role is not in the role list. Users with it may not be able to access anything.">
                                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> unknown role
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge badge-issued">Active</span>
                            <?php else: ?>
                                <span class="badge badge-rejected">Inactive</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($row['last_login'])): ?>
                                <span class="login-time">
                                    <?= function_exists('time_ago')
                                        ? e(time_ago($row['last_login']))
                                        : e(date('d M Y H:i', strtotime($row['last_login']))) ?>
                                </span>
                            <?php else: ?>
                                <span class="login-never">Never</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="activity-count">
                                <?= number_format((int)$row['total_actions']) ?>
                                <span class="note"> actions</span>
                            </span>
                        </td>

                        <td>
                            <div class="tbl-actions">
                                <button type="button" class="btn btn-sm btn-soft" title="View details"
                                        aria-label="View details for <?= e($row['full_name']) ?>"
                                        onclick="viewUserDetails(<?= (int)$row['user_id'] ?>)">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>

                                <a href="<?= e(perm_url(['edit' => (int)$row['user_id']])) ?>" class="btn btn-sm btn-dark"
                                   title="Edit permissions" aria-label="Edit permissions for <?= e($row['full_name']) ?>">
                                    <i class="fas fa-user-edit" aria-hidden="true"></i>
                                </a>

                                <?php if (!$isSelf): ?>
                                    <button type="button"
                                            class="btn btn-sm <?= $isActive ? 'btn-gray' : 'btn-green' ?>"
                                            title="<?= $isActive ? 'Deactivate user' : 'Activate user' ?>"
                                            aria-label="<?= ($isActive ? 'Deactivate ' : 'Activate ') . e($row['full_name']) ?>"
                                            data-name="<?= e($row['full_name']) ?>"
                                            onclick="toggleUserStatus(<?= (int)$row['user_id'] ?>, '<?= $isActive ? '0' : '1' ?>', this.dataset.name)">
                                        <i class="fas <?= $isActive ? 'fa-user-times' : 'fa-user-check' ?>" aria-hidden="true"></i>
                                    </button>

                                    <button type="button" class="btn btn-sm btn-soft" title="Reset password"
                                            aria-label="Reset password for <?= e($row['full_name']) ?>"
                                            data-username="<?= e($row['username']) ?>"
                                            onclick="resetPassword(<?= (int)$row['user_id'] ?>, this.dataset.username)">
                                        <i class="fas fa-key" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="pagination-bar" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a href="<?= e(perm_url(['page' => $page - 1])) ?>" class="pg-btn" title="Previous" aria-label="Previous page">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </a>
            <?php else: ?>
                <span class="pg-btn pg-disabled"><i class="fas fa-chevron-left" aria-hidden="true"></i></span>
            <?php endif; ?>

            <?php foreach (perm_page_range($page, $totalPages) as $pn): ?>
                <?php if ($pn === '...'): ?>
                    <span class="pg-ellipsis">&hellip;</span>
                <?php elseif ($pn === $page): ?>
                    <span class="pg-btn pg-active" aria-current="page"><?= $pn ?></span>
                <?php else: ?>
                    <a href="<?= e(perm_url(['page' => (int)$pn])) ?>" class="pg-btn"><?= $pn ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e(perm_url(['page' => $page + 1])) ?>" class="pg-btn" title="Next" aria-label="Next page">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            <?php else: ?>
                <span class="pg-btn pg-disabled"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

    </div><!-- /.panel -->

</div><!-- /.assets-wrap -->

<!-- Hidden form for activate/deactivate -->
<form id="statusForm" method="POST" action="user-permissions-process" style="display:none;">
    <input type="hidden" name="toggle_status" value="1">
    <input type="hidden" name="user_id" id="statusUserId">
    <input type="hidden" name="status" id="statusNewStatus">
</form>

<?php include 'includes/user-permissions-modals.php'; ?>

<style>
.perm-stats{grid-template-columns:repeat(6,1fr)}
.role-like{display:block;margin-top:3px;font-size:12px}
.role-unknown{color:var(--amber-fg,#b45309)}
@media (max-width:1200px){.perm-stats{grid-template-columns:repeat(3,1fr)}}
@media (max-width:640px){.perm-stats{grid-template-columns:repeat(2,1fr)}}
</style>

<script>
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

window.addEventListener('click', function (e) {
    document.querySelectorAll('.modal.show').forEach(function (m) {
        if (e.target === m) m.classList.remove('show');
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(function (m) { m.classList.remove('show'); });
    }
});

function viewUserDetails(userId) {
    fetch('user-permissions-process?action=get_user&user_id=' + encodeURIComponent(userId))
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            var u = data.user;
            var set = function (id, value) { document.getElementById(id).textContent = value; };
            set('detailsUserName',  u.full_name || '');
            set('detailsUsername',  u.username || '');
            set('detailsEmail',     u.email || 'N/A');
            set('detailsPhone',     u.phone || 'N/A');
            set('detailsRole',      (u.role || '') + (u.access_role ? ' (works like ' + u.access_role + ')' : ''));
            set('detailsStatus',    u.is_active == 1 ? 'Active' : 'Inactive');
            set('detailsCreated',   u.created_at || '');
            set('detailsLastLogin', u.last_login || 'Never');
            set('detailsActions',   u.total_actions || 0);
            openModal('userDetailsModal');
        });
}

function toggleUserStatus(userId, newStatus, userName) {
    var verb = newStatus === '1' ? 'activate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + verb + ' ' + userName + '?')) return;
    document.getElementById('statusUserId').value    = userId;
    document.getElementById('statusNewStatus').value = newStatus;
    document.getElementById('statusForm').submit();
}

function resetPassword(userId, username) {
    document.getElementById('resetUserId').value         = userId;
    document.getElementById('resetUsername').textContent = username;
    openModal('resetPasswordModal');
}

// Debounce search: submit after 450 ms idle
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
window.addEventListener('DOMContentLoaded', function () { openModal('editPermissionsModal'); });
<?php elseif (!empty($_GET['roles'])): ?>
window.addEventListener('DOMContentLoaded', function () { openRolesModal(false); });
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
