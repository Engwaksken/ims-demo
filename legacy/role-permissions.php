<?php
ob_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';

check_role(['Administrator']);

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$adminId  = (int)($_SESSION['user_id'] ?? 0);
$allRoles = ims_all_roles($conn);
$custom   = ims_custom_roles($conn);
$ready    = ims_perm_ensure_tables($conn);

function rp_redirect(string $role, string $message, string $type = 'success'): never
{
    send_notification($_SESSION['user_id'] ?? 0, $message, $type);
    header('Location: role-permissions?role=' . rawurlencode($role));
    exit();
}

/* =========================
   ACTIONS
========================= */
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = (string)($_POST['role'] ?? '');
    if (!in_array($role, $allRoles, true)) {
        rp_redirect('', 'Unknown role.', 'danger');
    }

    if (isset($_POST['scan'])) {
        [$found, $new] = ims_perm_scan($conn);
        log_action($adminId, 'Scan Permissions', 'ims_permissions', 0, "Scanned code: {$found} checks, {$new} new");
        rp_redirect($role, "Scanned the code: {$found} access checks found, {$new} new.");
    }

    if (isset($_POST['save'])) {
        $keys = is_array($_POST['perm'] ?? null) ? $_POST['perm'] : [];
        $count = ims_perm_set_role_grants($conn, $role, $keys);
        log_action($adminId, 'Update Role Permissions', 'ims_role_permissions', 0, "Set {$count} permissions for role {$role}");
        rp_redirect($role, "Saved: {$role} can now open {$count} pages/actions. Users get the change on their next page load.");
    }

    if (isset($_POST['reset_defaults'])) {
        $count = ims_perm_set_role_grants($conn, $role, ims_perm_default_keys_for_role($conn, $role));
        log_action($adminId, 'Reset Role Permissions', 'ims_role_permissions', 0, "Reset role {$role} to code defaults ({$count})");
        rp_redirect($role, "{$role} reset to the access written in the code ({$count} pages/actions).");
    }

    if (isset($_POST['copy_from'])) {
        $from = (string)($_POST['from_role'] ?? '');
        if (!in_array($from, $allRoles, true) || $from === $role) {
            rp_redirect($role, 'Choose a different role to copy from.', 'danger');
        }
        $count = ims_perm_set_role_grants($conn, $role, array_keys(ims_perm_role_grants($conn, $from, true)));
        log_action($adminId, 'Copy Role Permissions', 'ims_role_permissions', 0, "Copied permissions from {$from} to {$role}");
        rp_redirect($role, "Copied {$from}'s access to {$role} ({$count} pages/actions).");
    }

    rp_redirect($role, 'Nothing to do.', 'info');
}

/* =========================
   DATA
========================= */
$selectedRole = (string)($_GET['role'] ?? '');
if (!in_array($selectedRole, $allRoles, true)) {
    $selectedRole = in_array('Staff', $allRoles, true) ? 'Staff' : ($allRoles[0] ?? '');
}

$registry = [];
$grants   = [];
if ($ready) {
    if (!ims_perm_registry($conn)) {
        ims_perm_scan($conn); // first visit: list every page straight away
    }
    $registry = ims_perm_registry($conn, true);
    $grants   = ims_perm_role_grants($conn, $selectedRole, true);
}

// Friendly names from the sidebar menu where a page has one.
$menuLabels = [];
try {
    $res = $conn->query('SELECT page, label FROM menu_items');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $menuLabels[preg_replace('/\.php$/i', '', basename((string)$row['page']))] = (string)$row['label'];
        }
    }
} catch (Throwable $e) {
    $menuLabels = [];
}

$groups = ['Pages' => [], 'Actions & processing' => []];
$perFile = [];
foreach ($registry as $key => $row) {
    $perFile[$row['source_file']][] = $key;
}

foreach ($registry as $key => $row) {
    $file  = $row['source_file'];
    $slug  = basename($file);
    $isAction = str_contains($file, '/') || preg_match('/(process|actions?|export|ajax|download|save|delete|update)/i', $slug);
    $label = $menuLabels[$slug] ?? ucwords(str_replace(['-', '_'], ' ', $slug));
    if (count($perFile[$file]) > 1) {
        $label .= ' (check ' . (array_search($key, $perFile[$file], true) + 1) . ' of ' . count($perFile[$file]) . ')';
    }
    $groups[$isAction ? 'Actions & processing' : 'Pages'][] = [
        'key'       => $key,
        'label'     => $label,
        'file'      => $file,
        'defaults'  => $row['default_roles'],
        'granted'   => isset($grants[$key]),
        'protected' => $selectedRole === 'Administrator' && in_array($file, IMS_PERM_PROTECTED_FILES, true),
    ];
}
foreach ($groups as &$items) {
    usort($items, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
}
unset($items);

$grantedCount = count(array_intersect_key($grants, $registry));
$roleBase = isset($custom[$selectedRole]) ? (string)$custom[$selectedRole]['base_role'] : '';

$page_title = 'Role Permissions';
include 'includes/header.php';
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/users.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-shield-halved" aria-hidden="true"></i> Role Permissions</h1>
            <p>Choose which pages and actions each role can open. Changes apply on the user's next page load.</p>
        </div>
        <div class="hero-actions">
            <a href="user-permissions?roles=1" class="btn btn-soft"><i class="fas fa-id-badge" aria-hidden="true"></i> Manage Roles</a>
            <a href="menu-permissions" class="btn btn-soft"><i class="fas fa-bars" aria-hidden="true"></i> Menu Permissions</a>
            <a href="user-permissions" class="btn btn-dark"><i class="fas fa-user-lock" aria-hidden="true"></i> User Permissions</a>
        </div>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            The permission tables could not be created, so access still follows the roles written in the code.
            Run <code>database/migrations/2026_10_14_role_permissions.sql</code> and reload this page.
        </div>
    <?php else: ?>

    <div class="panel">
        <div class="rp-toolbar">
            <form method="GET" class="rp-role-pick">
                <label for="rpRole" class="form-label">Role</label>
                <select id="rpRole" name="role" class="form-control" onchange="this.form.submit()">
                    <optgroup label="Built-in roles">
                        <?php foreach (IMS_ALL_ROLES as $r): ?>
                            <option value="<?= e($r) ?>" <?= $r === $selectedRole ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php if ($custom): ?>
                        <optgroup label="Added roles">
                            <?php foreach ($custom as $r => $row): ?>
                                <option value="<?= e($r) ?>" <?= $r === $selectedRole ? 'selected' : '' ?>><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
                <noscript><button type="submit" class="btn btn-sm btn-dark">Show</button></noscript>
            </form>

            <div class="rp-summary" aria-live="polite">
                <strong id="rpCount"><?= $grantedCount ?></strong> of <?= count($registry) ?> allowed
                <?php if ($roleBase !== ''): ?>
                    <span class="note">&middot; added role, older in-page checks follow <?= e($roleBase) ?></span>
                <?php endif; ?>
            </div>

            <div class="rp-tools">
                <form method="POST" class="rp-inline" onsubmit="return confirm('Replace <?= e(addslashes($selectedRole)) ?>\'s access with the other role\'s?');">
                    <input type="hidden" name="role" value="<?= e($selectedRole) ?>">
                    <label for="rpFrom" class="sr-only">Copy access from</label>
                    <select id="rpFrom" name="from_role" class="form-control" required>
                        <option value="">Copy access from...</option>
                        <?php foreach ($allRoles as $r): if ($r === $selectedRole) continue; ?>
                            <option value="<?= e($r) ?>"><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="copy_from" value="1" class="btn btn-sm btn-soft">Copy</button>
                </form>
                <form method="POST" class="rp-inline" onsubmit="return confirm('Reset <?= e(addslashes($selectedRole)) ?> to the access written in the code?');">
                    <input type="hidden" name="role" value="<?= e($selectedRole) ?>">
                    <button type="submit" name="reset_defaults" value="1" class="btn btn-sm btn-gray">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i> Reset to defaults
                    </button>
                </form>
                <form method="POST" class="rp-inline">
                    <input type="hidden" name="role" value="<?= e($selectedRole) ?>">
                    <button type="submit" name="scan" value="1" class="btn btn-sm btn-gray" title="Find pages added to the code since the last scan">
                        <i class="fas fa-magnifying-glass" aria-hidden="true"></i> Scan pages
                    </button>
                </form>
            </div>
        </div>

        <form method="POST" id="rpForm">
            <input type="hidden" name="role" value="<?= e($selectedRole) ?>">

            <div class="rp-filters">
                <input type="search" id="rpSearch" class="form-control" placeholder="Search pages, files or roles..."
                       aria-label="Search pages" oninput="rpFilter()" onkeydown="if (event.key === 'Enter') event.preventDefault();">
                <select id="rpShow" class="form-control" aria-label="Show" onchange="rpFilter()">
                    <option value="all">All</option>
                    <option value="on">Allowed</option>
                    <option value="off">Not allowed</option>
                </select>
                <button type="button" class="btn btn-sm btn-soft" onclick="rpSetShown(true)">Allow all shown</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="rpSetShown(false)">Remove all shown</button>
            </div>

            <?php foreach ($groups as $groupName => $items): if (!$items) continue; ?>
                <section class="rp-group" aria-labelledby="rp-<?= e(md5($groupName)) ?>">
                    <h3 id="rp-<?= e(md5($groupName)) ?>"><?= e($groupName) ?> <span class="note">(<?= count($items) ?>)</span></h3>
                    <div class="rp-list">
                        <?php foreach ($items as $item): ?>
                            <label class="rp-item" data-search="<?= e(strtolower($item['label'] . ' ' . $item['file'] . ' ' . implode(' ', $item['defaults']))) ?>">
                                <input type="checkbox" name="perm[]" value="<?= e($item['key']) ?>"
                                       <?= $item['granted'] || $item['protected'] ? 'checked' : '' ?>
                                       <?= $item['protected'] ? 'disabled' : '' ?>
                                       onchange="rpUpdate()">
                                <?php if ($item['protected']): ?>
                                    <input type="hidden" name="perm[]" value="<?= e($item['key']) ?>">
                                <?php endif; ?>
                                <span class="rp-text">
                                    <strong><?= e($item['label']) ?></strong>
                                    <small><?= e($item['file']) ?>.php</small>
                                    <small class="rp-defaults">
                                        Default: <?= e(implode(', ', $item['defaults'])) ?>
                                    </small>
                                    <?php if ($item['protected']): ?>
                                        <small class="rp-lock"><i class="fas fa-lock" aria-hidden="true"></i> Always allowed for Administrator</small>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <p class="note rp-nomatch" id="rpNoMatch" hidden>No pages match your search.</p>

            <div class="rp-savebar">
                <span class="note">
                    Sidebar links are set separately under <a href="menu-permissions">Menu Permissions</a>.
                </span>
                <button type="submit" name="save" value="1" class="btn btn-primary">
                    <i class="fas fa-save" aria-hidden="true"></i> Save <?= e($selectedRole) ?>
                </button>
            </div>
        </form>
    </div>

    <?php endif; ?>
</div>

<style>
.rp-toolbar{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px 20px;padding:16px;border-bottom:1px solid var(--border,#e5e7eb)}
.rp-role-pick{display:flex;flex-direction:column;gap:4px;min-width:240px}
.rp-summary{flex:1;min-width:180px;padding-bottom:8px}
.rp-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.rp-inline{display:flex;gap:6px;align-items:center;margin:0}
.rp-inline select{min-width:180px}
.rp-filters{display:flex;flex-wrap:wrap;gap:8px;padding:14px 16px;align-items:center}
.rp-filters input{flex:1;min-width:200px}
.rp-filters select{width:auto}
.rp-group{padding:0 16px 8px}
.rp-group h3{margin:12px 0 8px;font-size:14px}
.rp-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px}
.rp-item{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--border,#e5e7eb);border-radius:10px;background:#fff;cursor:pointer;margin:0}
.rp-item:hover{border-color:var(--brand-400,#fb923c)}
.rp-item:has(input:checked){border-color:var(--brand-400,#fb923c);background:var(--brand-50,#fff4ed)}
.rp-item[hidden]{display:none}
.rp-item input[type=checkbox]{width:18px;height:18px;margin-top:2px;flex:none;accent-color:var(--brand-600,#ea580c)}
.rp-text{display:flex;flex-direction:column;min-width:0;gap:1px}
.rp-text strong{font-size:13px;color:var(--ink-700,#1e2530)}
.rp-text small{font-size:11.5px;color:var(--ink-300,#6b7280);overflow-wrap:anywhere}
.rp-lock{color:var(--amber-fg,#b45309)!important}
.rp-nomatch{padding:0 16px}
.rp-savebar{position:sticky;bottom:0;z-index:5;display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border,#e5e7eb);background:#fff;box-shadow:0 -6px 16px rgba(13,17,23,.06)}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media (max-width:640px){
    .rp-role-pick,.rp-inline,.rp-inline select{width:100%;min-width:0}
    .rp-tools{width:100%}
    .rp-list{grid-template-columns:1fr}
    .rp-savebar .btn{width:100%}
}
</style>

<script>
function rpBoxes() {
    return Array.from(document.querySelectorAll('#rpForm .rp-item input[type=checkbox]'));
}

function rpUpdate() {
    const n = rpBoxes().filter(b => b.checked).length;
    const out = document.getElementById('rpCount');
    if (out) out.textContent = n;
}

function rpFilter() {
    const term = (document.getElementById('rpSearch').value || '').trim().toLowerCase();
    const show = document.getElementById('rpShow').value;
    let shown = 0;
    document.querySelectorAll('#rpForm .rp-item').forEach(item => {
        const box = item.querySelector('input[type=checkbox]');
        const matchText = !term || (item.dataset.search || '').includes(term);
        const matchState = show === 'all' || (show === 'on' ? box.checked : !box.checked);
        item.hidden = !(matchText && matchState);
        if (!item.hidden) shown++;
    });
    document.querySelectorAll('#rpForm .rp-group').forEach(group => {
        group.hidden = !group.querySelector('.rp-item:not([hidden])');
    });
    document.getElementById('rpNoMatch').hidden = shown > 0;
}

function rpSetShown(checked) {
    document.querySelectorAll('#rpForm .rp-item:not([hidden]) input[type=checkbox]:not(:disabled)')
        .forEach(box => { box.checked = checked; });
    rpUpdate();
}
</script>

<?php include 'includes/footer.php'; ?>
