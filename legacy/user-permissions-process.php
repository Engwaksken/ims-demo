<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/permissions.php';

// Only administrators can manage permissions and roles.
check_role(['Administrator']);

$adminId = (int)($_SESSION['user_id'] ?? 0);

function perm_back(string $message, string $type = 'danger', string $query = ''): never
{
    send_notification($_SESSION['user_id'] ?? 0, $message, $type);
    header('Location: user-permissions' . ($query !== '' ? '?' . $query : ''));
    exit();
}

function perm_load_user(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT user_id, username, full_name, email, role, is_active FROM users WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

/* =========================
   AJAX: USER DETAILS
========================= */
if (($_GET['action'] ?? '') === 'get_user') {
    header('Content-Type: application/json; charset=UTF-8');

    $userId = (int)($_GET['user_id'] ?? 0);

    // Never return password_hash to the browser.
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, u.email, u.full_name, u.role, u.phone, u.is_active,
               u.created_at, u.last_login,
               (SELECT COUNT(*) FROM activity_log WHERE user_id = u.user_id) AS total_actions
        FROM users u
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $user = null;
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    if ($user) {
        $effective = ims_effective_role($conn, (string)$user['role']);
        $user['access_role'] = $effective !== $user['role'] ? $effective : '';
    }

    echo json_encode($user ? ['success' => true, 'user' => $user] : ['success' => false, 'message' => 'User not found']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: user-permissions');
    exit();
}

/* =========================
   UPDATE USER ROLE + STATUS
========================= */
if (isset($_POST['update_permissions'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $role   = trim((string)($_POST['role'] ?? ''));
    $status = (string)($_POST['status'] ?? '');
    $back   = 'edit=' . $userId;

    if ($role === '' || $status === '') {
        perm_back('Role and status are required.', 'danger', $back);
    }
    if (!in_array($role, ims_all_roles($conn), true)) {
        perm_back('Invalid role selected.', 'danger', $back);
    }
    if (!in_array($status, ['1', '0'], true)) {
        perm_back('Invalid status selected.', 'danger', $back);
    }

    $current = perm_load_user($conn, $userId);
    if (!$current) {
        perm_back('User not found.');
    }

    if ($userId === $adminId && ims_effective_role($conn, $role) !== 'Administrator') {
        perm_back('You cannot remove your own administrator access.', 'danger', $back);
    }
    if ($userId === $adminId && $status === '0') {
        perm_back('You cannot deactivate your own account.', 'danger', $back);
    }

    if (!ims_roles_allow_in_enum($conn, $role)) {
        perm_back('The database would not accept the role "' . $role . '". Ask IT to allow it in users.role.', 'danger', $back);
    }

    $statusInt = (int)$status;
    $stmt = $conn->prepare('UPDATE users SET role = ?, is_active = ? WHERE user_id = ?');
    $stmt->bind_param('sii', $role, $statusInt, $userId);

    if ($stmt->execute()) {
        $changes = [];
        if ($current['role'] !== $role) {
            $changes[] = "role from '{$current['role']}' to '{$role}'";
        }
        if ((int)$current['is_active'] !== $statusInt) {
            $changes[] = 'status to ' . ($statusInt ? 'active' : 'inactive');
        }

        log_action($adminId, 'Update User Permissions', 'users', $userId,
            "Updated permissions for {$current['full_name']}: " . ($changes ? implode(', ', $changes) : 'no changes'));

        if ($userId === $adminId) {
            ims_set_session_role($conn, $role);
        }

        $stmt->close();
        perm_back('User permissions updated successfully.', 'success');
    }

    $error = $stmt->error;
    $stmt->close();
    perm_back('Error updating permissions: ' . $error, 'danger', $back);
}

/* =========================
   ACTIVATE / DEACTIVATE
========================= */
if (isset($_POST['toggle_status'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');

    if (!in_array($status, ['1', '0'], true)) {
        perm_back('Invalid status.');
    }
    if ($userId === $adminId) {
        perm_back('You cannot change your own status.');
    }

    $user = perm_load_user($conn, $userId);
    if (!$user) {
        perm_back('User not found.');
    }

    $statusInt = (int)$status;
    $stmt = $conn->prepare('UPDATE users SET is_active = ? WHERE user_id = ?');
    $stmt->bind_param('ii', $statusInt, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        perm_back('Error updating status.');
    }

    $verb = $statusInt ? 'activated' : 'deactivated';
    log_action($adminId, 'Toggle User Status', 'users', $userId, ucfirst($verb) . " user: {$user['full_name']}");
    perm_back("User {$verb} successfully.", 'success');
}

/* =========================
   RESET PASSWORD
========================= */
if (isset($_POST['reset_password'])) {
    $userId  = (int)($_POST['user_id'] ?? 0);
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($new === '' || $confirm === '') {
        perm_back('Both password fields are required.');
    }
    if (strlen($new) < 8) {
        perm_back('Password must be at least 8 characters.');
    }
    if (!hash_equals($new, $confirm)) {
        perm_back('Passwords do not match.');
    }

    $user = perm_load_user($conn, $userId);
    if (!$user) {
        perm_back('User not found.');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $stmt->bind_param('si', $hash, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        perm_back('Error resetting password.');
    }

    log_action($adminId, 'Reset User Password', 'users', $userId, "Reset password for user: {$user['username']}");
    perm_back('Password reset successfully.', 'success');
}

/* =========================
   ADD ROLE
========================= */
if (isset($_POST['add_role'])) {
    $name        = ims_clean_role_name((string)($_POST['role_name'] ?? ''));
    $baseRole    = (string)($_POST['base_role'] ?? '');
    $description = trim((string)($_POST['description'] ?? ''));

    $error = ims_validate_role_name($conn, $name);
    if ($error !== '') {
        perm_back($error, 'danger', 'roles=1');
    }
    if (!in_array($baseRole, IMS_ALL_ROLES, true)) {
        perm_back('Choose which built-in role the new role should work like.', 'danger', 'roles=1');
    }
    if (!ims_roles_ensure_table($conn)) {
        perm_back('Could not create the roles table. Run database/migrations/2026_10_14_custom_roles.sql.', 'danger', 'roles=1');
    }
    if (!ims_roles_allow_in_enum($conn, $name)) {
        perm_back('The database would not accept the new role name. Ask IT to allow it in users.role.', 'danger', 'roles=1');
    }

    $description = mb_substr($description, 0, 500, 'UTF-8');
    $stmt = $conn->prepare('INSERT INTO ims_roles (role_name, base_role, description, created_by) VALUES (?, ?, NULLIF(?, \'\'), ?)');
    $stmt->bind_param('sssi', $name, $baseRole, $description, $adminId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        perm_back('Error adding role.', 'danger', 'roles=1');
    }

    // Start the new role with the same page permissions as its base role.
    ims_perm_copy_role($conn, $baseRole, $name);

    log_action($adminId, 'Add Role', 'ims_roles', 0, "Added role: {$name} (works like {$baseRole})");
    perm_back("Role \"{$name}\" added with the same page access as {$baseRole}. Adjust it under Role Permissions if needed.", 'success', 'roles=1');
}

/* =========================
   EDIT ROLE (name, base role, description)
========================= */
if (isset($_POST['update_role'])) {
    $original    = (string)($_POST['original_name'] ?? '');
    $name        = ims_clean_role_name((string)($_POST['role_name'] ?? ''));
    $baseRole    = (string)($_POST['base_role'] ?? '');
    $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500, 'UTF-8');

    $custom = ims_custom_roles($conn);
    if (!isset($custom[$original])) {
        perm_back('Only roles added on this page can be edited.', 'danger', 'roles=1');
    }

    $error = ims_validate_role_name($conn, $name, $original);
    if ($error !== '') {
        perm_back($error, 'danger', 'roles=1');
    }
    if (!in_array($baseRole, IMS_ALL_ROLES, true)) {
        perm_back('Choose which built-in role this role should work like.', 'danger', 'roles=1');
    }

    // Don't let the editing admin remove their own administrator access.
    $self = perm_load_user($conn, $adminId);
    if ($self && $self['role'] === $original && $baseRole !== 'Administrator') {
        perm_back('You hold this role, so it must keep working like Administrator.', 'danger', 'roles=1');
    }

    if ($name !== $original && !ims_roles_allow_in_enum($conn, $name)) {
        perm_back('The database would not accept the new role name. Ask IT to allow it in users.role.', 'danger', 'roles=1');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE ims_roles SET role_name = ?, base_role = ?, description = NULLIF(?, \'\') WHERE role_name = ?');
        $stmt->bind_param('ssss', $name, $baseRole, $description, $original);
        $stmt->execute();
        $stmt->close();

        if ($name !== $original) {
            $stmt = $conn->prepare('UPDATE users SET role = ? WHERE role = ?');
            $stmt->bind_param('ss', $name, $original);
            $stmt->execute();
            $stmt->close();

            ims_perm_rename_role($conn, $original, $name);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        perm_back('Error updating role: ' . $e->getMessage(), 'danger', 'roles=1');
    }

    if ($self && $self['role'] === $original) {
        ims_custom_roles($conn, true);
        ims_set_session_role($conn, $name);
    }

    log_action($adminId, 'Update Role', 'ims_roles', 0,
        "Updated role: {$original}" . ($name !== $original ? " (renamed to {$name})" : '') . ", works like {$baseRole}");
    perm_back("Role \"{$name}\" updated. Users with this role get the change at their next login.", 'success', 'roles=1');
}

/* =========================
   DELETE ROLE (only when unused)
========================= */
if (isset($_POST['delete_role'])) {
    $name = (string)($_POST['role_name'] ?? '');

    if (!isset(ims_custom_roles($conn)[$name])) {
        perm_back('Built-in roles cannot be deleted.', 'danger', 'roles=1');
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM users WHERE role = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $inUse = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($inUse > 0) {
        perm_back("Role \"{$name}\" is assigned to {$inUse} user(s). Move them to another role first.", 'danger', 'roles=1');
    }

    $stmt = $conn->prepare('DELETE FROM ims_roles WHERE role_name = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();

    ims_perm_delete_role($conn, $name);

    log_action($adminId, 'Delete Role', 'ims_roles', 0, "Deleted role: {$name}");
    perm_back("Role \"{$name}\" deleted.", 'success', 'roles=1');
}

header('Location: user-permissions');
exit();
