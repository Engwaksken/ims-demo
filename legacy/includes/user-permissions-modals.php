<?php
/*
 * Modals for user-permissions.php. Expects from the page:
 *   $edit_user, $all_roles, $customRoles, $roleCounts, $conn
 */
$builtInRoles = IMS_ALL_ROLES;
$roleInfo = [];
foreach ($all_roles as $r) {
    $roleInfo[$r] = [
        'description' => ims_role_description($conn, $r),
        'base'        => isset($customRoles[$r]) ? (string)$customRoles[$r]['base_role'] : '',
    ];
}
?>

<!-- Edit Permissions Modal -->
<div id="editPermissionsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editPermissionsTitle">
    <div class="modal-content" style="max-width: 640px;">
        <div class="modal-header">
            <h3 id="editPermissionsTitle"><i class="fas fa-user-edit" aria-hidden="true"></i> Edit User Permissions</h3>
            <button type="button" class="close" onclick="closeModal('editPermissionsModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($edit_user): ?>
                <form method="POST" action="user-permissions-process">
                    <input type="hidden" name="user_id" value="<?= (int)$edit_user['user_id'] ?>">

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle" aria-hidden="true"></i>
                        <strong>User:</strong> <?= e($edit_user['full_name']) ?> (<?= e($edit_user['username']) ?>)
                    </div>

                    <?php if ((int)$edit_user['user_id'] === (int)$_SESSION['user_id']): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                            <strong>Warning:</strong> You are editing your own permissions. Be careful not to lock yourself out.
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="edit_role" class="required">Role</label>
                        <select id="edit_role" name="role" class="form-control" required onchange="showRoleInfo(this.value)">
                            <?php if (!in_array($edit_user['role'], $all_roles, true)): ?>
                                <option value="" selected disabled>Unknown role: <?= e($edit_user['role']) ?></option>
                            <?php endif; ?>
                            <optgroup label="Built-in roles">
                                <?php foreach ($builtInRoles as $r): ?>
                                    <option value="<?= e($r) ?>" <?= $edit_user['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php if ($customRoles): ?>
                                <optgroup label="Added roles">
                                    <?php foreach ($customRoles as $r => $row): ?>
                                        <option value="<?= e($r) ?>" <?= $edit_user['role'] === $r ? 'selected' : '' ?>>
                                            <?= e($r) ?> (works like <?= e($row['base_role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                        <small class="note">
                            Role you need isn't listed?
                            <button type="button" class="link-btn" onclick="closeModal('editPermissionsModal'); openRolesModal(true);">Add a role</button>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="edit_status" class="required">Status</label>
                        <select id="edit_status" name="status" class="form-control" required>
                            <option value="1" <?= (int)$edit_user['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= (int)$edit_user['is_active'] !== 1 ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>What this role can do</label>
                        <div id="rolePermissions" class="role-info-box" aria-live="polite"></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" onclick="closeModal('editPermissionsModal')" class="btn btn-gray">Cancel</button>
                        <button type="submit" name="update_permissions" value="1" class="btn btn-primary">
                            <i class="fas fa-save" aria-hidden="true"></i> Update Permissions
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p class="note">User not found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- User Details Modal -->
<div id="userDetailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="userDetailsTitle">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header">
            <h3 id="userDetailsTitle"><i class="fas fa-user" aria-hidden="true"></i> User Details</h3>
            <button type="button" class="close" onclick="closeModal('userDetailsModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <dl class="details-list">
                <dt>Full Name</dt><dd id="detailsUserName">-</dd>
                <dt>Username</dt><dd id="detailsUsername">-</dd>
                <dt>Email</dt><dd id="detailsEmail">-</dd>
                <dt>Phone</dt><dd id="detailsPhone">-</dd>
                <dt>Role</dt><dd id="detailsRole">-</dd>
                <dt>Status</dt><dd id="detailsStatus">-</dd>
                <dt>Created</dt><dd id="detailsCreated">-</dd>
                <dt>Last Login</dt><dd id="detailsLastLogin">-</dd>
                <dt>Total Actions</dt><dd id="detailsActions">-</dd>
            </dl>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('userDetailsModal')" class="btn btn-gray">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="resetPasswordTitle">
    <div class="modal-content" style="max-width: 480px;">
        <div class="modal-header">
            <h3 id="resetPasswordTitle"><i class="fas fa-key" aria-hidden="true"></i> Reset Password</h3>
            <button type="button" class="close" onclick="closeModal('resetPasswordModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="user-permissions-process">
                <input type="hidden" name="user_id" id="resetUserId">

                <p>Reset password for user: <strong id="resetUsername"></strong></p>

                <div class="form-group">
                    <label for="new_password" class="required">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                    <small class="note">Minimum 8 characters</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="required">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('resetPasswordModal')" class="btn btn-gray">Cancel</button>
                    <button type="submit" name="reset_password" value="1" class="btn btn-danger">
                        <i class="fas fa-key" aria-hidden="true"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Roles Modal: list, add, edit, delete -->
<div id="rolesModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="rolesModalTitle">
    <div class="modal-content" style="max-width: 920px;">
        <div class="modal-header">
            <h3 id="rolesModalTitle"><i class="fas fa-id-badge" aria-hidden="true"></i> Roles</h3>
            <button type="button" class="close" onclick="closeModal('rolesModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">

            <!-- Add / edit role form -->
            <form method="POST" action="user-permissions-process" class="role-form" id="roleForm">
                <input type="hidden" name="original_name" id="roleOriginalName">
                <h4 id="roleFormTitle"><i class="fas fa-plus-circle" aria-hidden="true"></i> Add a role</h4>
                <p class="note">
                    A new role starts with the same page access as the built-in role you choose, and uses its sidebar menu.
                    You can then change exactly which pages it can open under
                    <a href="role-permissions">Role Permissions</a> (shield button in the list below).
                </p>
                <div class="role-form-grid">
                    <div class="form-group">
                        <label for="roleName" class="required">Role name</label>
                        <input type="text" id="roleName" name="role_name" class="form-control" required
                               minlength="2" maxlength="60" placeholder="e.g. Communications Officer" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="roleBase" class="required">Works like</label>
                        <select id="roleBase" name="base_role" class="form-control" required>
                            <option value="">Choose a built-in role...</option>
                            <?php foreach ($builtInRoles as $r): ?>
                                <option value="<?= e($r) ?>"><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label for="roleDescription">Description <span class="note">(optional)</span></label>
                        <input type="text" id="roleDescription" name="description" class="form-control" maxlength="500"
                               placeholder="What people with this role do">
                    </div>
                </div>
                <div class="role-form-actions">
                    <button type="button" class="btn btn-gray" id="roleCancelEdit" hidden onclick="resetRoleForm()">Cancel edit</button>
                    <button type="submit" name="add_role" value="1" class="btn btn-primary" id="roleSubmit">
                        <i class="fas fa-plus" aria-hidden="true"></i> <span>Add Role</span>
                    </button>
                </div>
            </form>

            <!-- Roles list -->
            <div class="roles-toolbar">
                <h4><i class="fas fa-list" aria-hidden="true"></i> All roles <span class="note">(<?= count($all_roles) ?>)</span></h4>
                <input type="search" id="roleSearch" class="form-control" placeholder="Search roles..." aria-label="Search roles"
                       oninput="filterRoles(this.value)">
            </div>
            <div class="table-wrap">
                <table class="table roles-table">
                    <thead>
                    <tr>
                        <th>Role</th>
                        <th>Access</th>
                        <th>Users</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_roles as $r):
                        $isCustom = isset($customRoles[$r]);
                        $count = (int)($roleCounts[$r] ?? 0);
                    ?>
                        <tr data-role-search="<?= e(strtolower($r . ' ' . ($roleInfo[$r]['base'] ?? '') . ' ' . $roleInfo[$r]['description'])) ?>">
                            <td>
                                <strong><?= e($r) ?></strong>
                                <?php if ($roleInfo[$r]['description'] !== ''): ?>
                                    <span class="note role-desc"><?= e($roleInfo[$r]['description']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isCustom): ?>
                                    <span class="badge badge-role-custom">Added</span>
                                    <span class="note">works like <strong><?= e($customRoles[$r]['base_role']) ?></strong></span>
                                <?php else: ?>
                                    <span class="badge badge-role-builtin">Built-in</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="user-permissions?role=<?= e(rawurlencode($r)) ?>" class="role-count" title="Show users with this role">
                                    <?= number_format($count) ?>
                                </a>
                            </td>
                            <td>
                                <div class="tbl-actions">
                                    <a href="role-permissions?role=<?= e(rawurlencode($r)) ?>" class="btn btn-sm btn-soft"
                                       title="Choose which pages this role can open" aria-label="Page access for <?= e($r) ?>">
                                        <i class="fas fa-shield-halved" aria-hidden="true"></i>
                                    </a>
                                <?php if ($isCustom): ?>
                                        <button type="button" class="btn btn-sm btn-soft" title="Edit role"
                                                aria-label="Edit role <?= e($r) ?>"
                                                data-name="<?= e($r) ?>"
                                                data-base="<?= e($customRoles[$r]['base_role']) ?>"
                                                data-description="<?= e($customRoles[$r]['description'] ?? '') ?>"
                                                onclick="editRole(this)">
                                            <i class="fas fa-pen" aria-hidden="true"></i>
                                        </button>
                                        <?php if ($count === 0): ?>
                                            <form method="POST" action="user-permissions-process"
                                                  onsubmit="return confirm('Delete the role &quot;<?= e(addslashes($r)) ?>&quot;?');">
                                                <input type="hidden" name="role_name" value="<?= e($r) ?>">
                                                <button type="submit" name="delete_role" value="1" class="btn btn-sm btn-danger"
                                                        title="Delete role" aria-label="Delete role <?= e($r) ?>">
                                                    <i class="fas fa-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-gray" disabled
                                                    title="Move its users to another role before deleting">
                                                <i class="fas fa-trash" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="empty-row" id="rolesNoMatch" hidden><td colspan="4">No roles match your search.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('rolesModal')" class="btn btn-gray">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.link-btn{border:0;padding:0;background:none;color:var(--brand-700,#c2410c);font:inherit;font-weight:600;cursor:pointer;text-decoration:underline}
.role-info-box{padding:12px 14px;border:1px solid var(--border,#e5e7eb);border-radius:10px;background:var(--surface-hover,#f9fafb);font-size:13px}
.role-info-box p{margin:0}
.role-info-box p + p{margin-top:6px}
.details-list{display:grid;grid-template-columns:max-content 1fr;gap:8px 16px;margin:0}
.details-list dt{font-weight:600;color:var(--ink-400,#4b5563)}
.details-list dd{margin:0;word-break:break-word}
.role-form{padding:16px;border:1px solid var(--brand-200,#ffc89e);border-radius:12px;background:var(--brand-50,#fff4ed);margin-bottom:20px}
.role-form h4{margin:0 0 4px;font-size:15px}
.role-form .note{display:block;margin-bottom:12px}
.role-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
.role-form-grid .full{grid-column:1/-1}
.role-form-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.roles-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;flex-wrap:wrap}
.roles-toolbar h4{margin:0;font-size:15px}
.roles-toolbar input{max-width:260px}
.roles-table .role-desc{display:block;margin-top:2px;max-width:420px}
.roles-table .tbl-actions form{display:inline}
.badge-role-custom{background:var(--purple-bg,#faf5ff);color:var(--purple-fg,#7c3aed);border:1px solid var(--purple-border,#e9d5ff)}
.badge-role-builtin{background:var(--slate-bg,#f1f5f9);color:var(--slate-fg,#475569);border:1px solid var(--slate-border,#e2e8f0)}
.role-count{font-weight:700;color:var(--brand-700,#c2410c)}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media (max-width:640px){
    .role-form-grid{grid-template-columns:1fr}
    .roles-toolbar input{max-width:none;width:100%}
    .roles-table .role-desc{max-width:none}
    .details-list{grid-template-columns:1fr;gap:2px}
    .details-list dd{margin-bottom:8px}
}
</style>

<script>
const IMS_ROLE_INFO = <?= json_encode($roleInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function showRoleInfo(role) {
    const box = document.getElementById('rolePermissions');
    if (!box) return;
    const info = IMS_ROLE_INFO[role];
    box.innerHTML = '';
    if (!info) {
        box.textContent = 'Choose a role to see what it can access.';
        return;
    }
    if (info.base) {
        const p = document.createElement('p');
        p.innerHTML = '<strong>Access:</strong> same as ';
        const b = document.createElement('strong');
        b.textContent = info.base;
        p.appendChild(b);
        box.appendChild(p);
    }
    const d = document.createElement('p');
    d.textContent = info.description || (info.base && IMS_ROLE_INFO[info.base] ? IMS_ROLE_INFO[info.base].description : 'No description.');
    box.appendChild(d);
}

function openRolesModal(focusAdd) {
    openModal('rolesModal');
    if (focusAdd) setTimeout(() => document.getElementById('roleName')?.focus(), 50);
}

function resetRoleForm() {
    const form = document.getElementById('roleForm');
    form.reset();
    document.getElementById('roleOriginalName').value = '';
    document.getElementById('roleFormTitle').innerHTML = '<i class="fas fa-plus-circle" aria-hidden="true"></i> Add a role';
    const submit = document.getElementById('roleSubmit');
    submit.name = 'add_role';
    submit.querySelector('span').textContent = 'Add Role';
    document.getElementById('roleCancelEdit').hidden = true;
}

function editRole(btn) {
    document.getElementById('roleOriginalName').value = btn.dataset.name;
    document.getElementById('roleName').value = btn.dataset.name;
    document.getElementById('roleBase').value = btn.dataset.base;
    document.getElementById('roleDescription').value = btn.dataset.description || '';
    const title = document.getElementById('roleFormTitle');
    title.innerHTML = '<i class="fas fa-pen" aria-hidden="true"></i> Edit role: ';
    title.appendChild(document.createTextNode(btn.dataset.name));
    const submit = document.getElementById('roleSubmit');
    submit.name = 'update_role';
    submit.querySelector('span').textContent = 'Save Role';
    document.getElementById('roleCancelEdit').hidden = false;
    document.getElementById('roleForm').scrollIntoView({ block: 'start', behavior: 'smooth' });
    document.getElementById('roleName').focus();
}

function filterRoles(term) {
    term = (term || '').trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('.roles-table tbody tr[data-role-search]').forEach(row => {
        const match = !term || row.dataset.roleSearch.includes(term);
        row.hidden = !match;
        if (match) shown++;
    });
    document.getElementById('rolesNoMatch').hidden = shown > 0;
}

document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('edit_role');
    if (select) showRoleInfo(select.value);
});
</script>
