<?php
ob_start();

$page_title = 'Menu Permissions';
include 'includes/header.php';

check_role(['Administrator']);

// Built-in roles plus roles added on User Permissions.
require_once __DIR__ . '/includes/roles.php';
$all_roles = ims_all_roles($conn);
$custom_roles = ims_custom_roles($conn);

/**
 * Store local menu links extensionless ("reports.php?x=1" -> "reports?x=1").
 * Absolute http(s) URLs are kept as entered.
 */
function menu_clean_page(string $page): string
{
    if ($page === '' || preg_match('#^https?://#i', $page)) {
        return $page;
    }

    return (string)preg_replace('#\.php(?=[?\#]|$)#i', '', $page);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_permissions'])) {

        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            $upd = $conn->prepare("
                UPDATE menu_items
                SET page = ?,
                    label = ?,
                    icon = ?,
                    category = ?,
                    sort_order = ?,
                    is_active = ?
                WHERE menu_id = ?
            ");

            foreach ($_POST['items'] as $mid => $data) {
                $mid  = (int)$mid;
                $page = menu_clean_page(trim((string)($data['page'] ?? '')));
                $lbl  = trim((string)($data['label'] ?? ''));
                $icon = trim((string)($data['icon'] ?? 'fa-circle'));
                $cat  = trim((string)($data['category'] ?? 'General'));
                $sort = (int)($data['sort_order'] ?? 0);
                $act  = isset($_POST['active'][$mid]) ? 1 : 0;

                if ($mid > 0 && $page !== '' && $lbl !== '' && ims_is_safe_link($page)) {
                    $upd->bind_param("ssssiii", $page, $lbl, $icon, $cat, $sort, $act, $mid);
                    $upd->execute();
                }
            }

            $upd->close();
        }

        // Added roles must be valid values if the role column is an ENUM.
        foreach (array_keys($custom_roles) as $customRole) {
            ims_roles_allow_in_enum($conn, (string)$customRole, 'menu_role_permissions');
        }

        $conn->query("DELETE FROM menu_role_permissions");

        if (!empty($_POST['perms']) && is_array($_POST['perms'])) {
            $ins = $conn->prepare("
                INSERT IGNORE INTO menu_role_permissions (menu_id, role)
                VALUES (?, ?)
            ");

            foreach ($_POST['perms'] as $mid => $roles) {
                $mid = (int)$mid;

                if ($mid <= 0 || !is_array($roles)) {
                    continue;
                }

                foreach ($roles as $role => $on) {
                    if (!in_array($role, $all_roles, true)) {
                        continue;
                    }

                    $ins->bind_param("is", $mid, $role);
                    $ins->execute();
                }
            }

            $ins->close();
        }

        $_SESSION['success'] = "Permissions saved successfully.";
        header("Location: menu-permissions");
        exit();
    }

    if (isset($_POST['add_item'])) {
        $page = menu_clean_page(trim((string)($_POST['page'] ?? '')));
        $lbl  = trim((string)($_POST['label'] ?? ''));
        $icon = trim((string)($_POST['icon'] ?? 'fa-circle'));
        $cat  = trim((string)($_POST['category'] ?? 'General'));
        $sort = (int)($_POST['sort_order'] ?? 0);

        if ($page === '' || $lbl === '') {
            $_SESSION['error'] = "Page filename and label are required.";
            header("Location: menu-permissions");
            exit();
        }

        // Menu links are rendered as href: relative paths or http(s) only.
        if (!ims_is_safe_link($page)) {
            $_SESSION['error'] = "Invalid page link. Use a relative page (e.g. reports) or an http(s) URL.";
            header("Location: menu-permissions");
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                icon = VALUES(icon),
                label = VALUES(label),
                category = VALUES(category),
                sort_order = VALUES(sort_order)
        ");

        $stmt->bind_param("ssssi", $page, $icon, $lbl, $cat, $sort);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Menu item \"$lbl\" added/updated.";
        } else {
            $_SESSION['error'] = "Failed to add menu item: " . $stmt->error;
        }

        $stmt->close();
        header("Location: menu-permissions");
        exit();
    }
}

if (!empty($_GET['delete'])) {
    // Deleting via a GET link: require the CSRF token in the query string.
    csrf_protect(true);

    $mid = (int)$_GET['delete'];

    $stmt = $conn->prepare("SELECT label FROM menu_items WHERE menu_id = ? LIMIT 1");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $del_item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM menu_items WHERE menu_id = ? LIMIT 1");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success'] = "\"" . htmlspecialchars($del_item['label'] ?? 'Item') . "\" removed.";
    header("Location: menu-permissions");
    exit();
}

$items = [];
$res = $conn->query("SELECT * FROM menu_items ORDER BY category, sort_order, label");

while ($row = $res->fetch_assoc()) {
    $items[(int)$row['menu_id']] = $row + ['roles' => []];
}

$res = $conn->query("SELECT menu_id, role FROM menu_role_permissions");

while ($row = $res->fetch_assoc()) {
    $mid = (int)$row['menu_id'];

    if (isset($items[$mid])) {
        $items[$mid]['roles'][] = $row['role'];
    }
}

$grouped = [];

foreach ($items as $item) {
    $grouped[$item['category']][] = $item;
}

ksort($grouped);

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$cat_palette = ['#ff5722','#7c3aed','#db2777','#d97706','#16a34a','#0891b2','#dc2626','#9333ea','#059669'];
$cat_keys = array_keys($grouped);

function catColor(string $cat, array $keys, array $pal): string
{
    $idx = array_search($cat, $keys, true);
    return $pal[($idx !== false ? $idx : 0) % count($pal)] ?? '#64748b';
}
?>

<style>
:root {
    --primary:#ff5722; --primary-d:#ff9800;
    --success:#16a34a; --danger:#dc2626;
    --text:#1e293b; --muted:#64748b;
    --border:#e2e8f0; --bg:#f1f5f9;
    --surface:#fff;
    --radius:10px;
    --shadow:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.06);
}

/* Layout*/
.mp { max-width:1500px; margin:0 auto; }

.mp-hdr {
    background:linear-gradient(125deg,#ff5722 0%,#ff9800 100%);
    color:#fff; padding:28px 32px; border-radius:14px;
    margin-bottom:22px;
    display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
}
.mp-hdr h1 { margin:0 0 4px; font-size:22px; display:flex; align-items:center; gap:10px; }
.mp-hdr p  { margin:0; font-size:13px; opacity:.75; }

/*  Cards  */
.card {
    background:var(--surface); border-radius:var(--radius);
    box-shadow:var(--shadow); border:1px solid var(--border);
    margin-bottom:22px; overflow:hidden;
}
.ch {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    padding:14px 22px; border-bottom:1px solid var(--border);
    background:#fafbfd;
}
.ch h2 { margin:0; font-size:15px; font-weight:700; color:var(--text); display:flex; align-items:center; gap:8px; }
.ch h2 i { color:var(--primary); }
.cb { padding:20px 22px; }

/* Alerts  */
.alert {
    display:flex; align-items:center; gap:10px;
    padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:13.5px;
}
.a-ok  { background:#dcfce7; color:#166534; border-left:4px solid #16a34a; }
.a-err { background:#fee2e2; color:#991b1b; border-left:4px solid #dc2626; }

/*  Stats */
.stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.sp {
    background:#fff; border:1px solid var(--border); border-radius:40px;
    padding:7px 16px; font-size:12.5px; font-weight:600; color:var(--muted);
    display:flex; align-items:center; gap:7px;
}
.sp i { color:var(--primary); } .sp b { color:var(--text); }

/* Add form  */
.ag {
    display:grid;
    grid-template-columns:2fr 2fr 1.4fr 1.8fr 70px auto;
    gap:10px; align-items:end;
}
@media(max-width:960px){ .ag { grid-template-columns:1fr 1fr 1fr; } }
@media(max-width:600px){ .ag { grid-template-columns:1fr; } }
.fg label {
    display:block; font-size:11px; font-weight:700; color:var(--muted);
    text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px;
}
.fc {
    width:100%; padding:8px 11px; border:1.5px solid var(--border);
    border-radius:7px; font-size:13px; box-sizing:border-box;
    color:var(--text); background:#fff; transition:border-color .18s; font-family:inherit;
}
.fc:focus { border-color:var(--primary); outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.1); }

/* Quick bar  */
.qbar {
    display:flex; flex-wrap:wrap; gap:7px; align-items:center;
    padding:11px 14px; background:#f8fafc;
    border-radius:8px; border:1px solid var(--border); margin-bottom:14px;
}
.qbar-label { font-size:11px; font-weight:700; color:var(--muted);
    text-transform:uppercase; letter-spacing:.5px; margin-right:4px; }

/*  Table */
.pt-wrap { overflow-x:auto; border-radius:8px; border:1px solid var(--border); }
.pt { width:100%; border-collapse:collapse; font-size:12.5px; min-width:1100px; }

/* Sticky header */
.pt thead th {
    position:sticky; top:0; z-index:10;
    background:#1e293b; color:#fff;
    padding:10px 7px; font-size:11px; font-weight:600;
    text-align:center; white-space:nowrap;
    border-right:1px solid rgba(255,255,255,.07);
}
.pt thead th.th-item {
    text-align:left; min-width:280px;
    position:sticky; left:0; z-index:20; background:#ff5722;
    padding-left:14px;
}
.pt thead th.th-act { min-width:56px; }

/* Freeze first col */
.pt tbody td.td-item {
    position:sticky; left:0; z-index:5;
    background:inherit; border-right:2px solid var(--border) !important;
}

/* Body rows */
.pt tbody tr:nth-child(odd)  td { background:#fff; }
.pt tbody tr:nth-child(even) td { background:#f8fafc; }
.pt tbody tr:hover td { background:#eff6ff !important; transition:background .12s; }
.pt tbody td {
    padding:0; border-bottom:1px solid var(--border);
    border-right:1px solid #f0f4f8;
    vertical-align:middle; text-align:center;
}

/* Category row */
.cat-row td {
    background:linear-gradient(90deg,#f0f7ff,#f8fbff) !important;
    padding:8px 14px !important;
    font-size:11px; font-weight:700; text-transform:uppercase;
    letter-spacing:.7px; border-bottom:1px solid #dbeafe !important;
}

/* Inline-edit fields in item cell */
.item-cell { padding:8px 10px 8px 14px !important; }
.ie-grid { display:grid; grid-template-columns:auto 1fr; gap:3px 8px; align-items:center; }
.ie-grid .ie-lbl {
    font-size:10px; font-weight:700; color:var(--muted);
    text-transform:uppercase; letter-spacing:.4px; white-space:nowrap;
}
.ie-inp {
    border:1.5px solid transparent; border-radius:5px;
    font-size:12.5px; padding:3px 7px; background:transparent;
    transition:all .15s; color:var(--text); font-family:inherit; width:100%;
    box-sizing:border-box;
}
.ie-inp:hover  { border-color:var(--border); background:#fff; }
.ie-inp:focus  { border-color:var(--primary); background:#fff; outline:none; }
.ie-row { display:flex; gap:5px; align-items:center; }
.ie-sort { width:52px !important; text-align:center; }
.ie-icon-prev { font-size:13px; color:var(--muted); flex-shrink:0; width:16px; text-align:center; }
.cat-badge {
    display:inline-block; padding:2px 8px; border-radius:12px;
    font-size:10px; font-weight:700; color:#fff; white-space:nowrap;
    flex-shrink:0; margin-left:6px;
}

/* Active toggle */
.toggle-cell { padding:6px 4px !important; }
.tog { position:relative; display:inline-block; width:36px; height:20px; }
.tog input { opacity:0; width:0; height:0; }
.ts {
    position:absolute; cursor:pointer; inset:0;
    background:#cbd5e1; border-radius:20px; transition:.2s;
}
.ts:before {
    content:''; position:absolute;
    width:14px; height:14px; left:3px; bottom:3px;
    background:#fff; border-radius:50%; transition:.2s;
    box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.tog input:checked + .ts { background:#16a34a; }
.tog input:checked + .ts:before { transform:translateX(16px); }

/* Role cb */
.rc-cell { padding:5px 3px !important; }
.rcb { width:15px; height:15px; cursor:pointer; accent-color:var(--primary); }

/* Del btn */
.del-wrap { padding:4px !important; }
.del-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:28px; height:28px; border-radius:6px;
    background:#fee2e2; color:var(--danger); border:none; cursor:pointer;
    font-size:12px; opacity:.35; transition:all .15s; text-decoration:none;
}
.pt tbody tr:hover .del-btn { opacity:1; }
.del-btn:hover { background:var(--danger); color:#fff; }

/* Buttons  */
.btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:8px 16px; border:none; border-radius:7px;
    font-size:13px; font-weight:600; cursor:pointer;
    text-decoration:none; transition:all .18s; white-space:nowrap; font-family:inherit;
}
.btn-primary { background:var(--primary); color:#fff; }
.btn-primary:hover { background:var(--primary-d); }
.btn-success { background:var(--success); color:#fff; }
.btn-success:hover { background:#15803d; }
.btn-ghost { background:#f1f5f9; color:var(--text); border:1.5px solid var(--border); }
.btn-ghost:hover { background:#e2e8f0; }
.btn-role {
    background:#eff6ff; color:#ff5722; border:1.5px solid #bfdbfe;
    font-size:11.5px; padding:4px 10px; border-radius:20px;
}
.btn-role:hover { background:#dbeafe; }
.btn-role-added { border-style:dashed; }
.th-role-added { background:#faf5ff; color:#7c3aed; }
.btn-xs { padding:4px 9px; font-size:11.5px; }

/* Floating save */
.save-float {
    position:fixed; bottom:26px; right:26px; z-index:999;
    padding:12px 26px; font-size:14px; border-radius:40px;
    box-shadow:0 4px 20px rgba(22,163,74,.45);
    transition:background .2s, box-shadow .2s;
}
.save-float.dirty {
    background:#dc2626 !important;
    box-shadow:0 4px 20px rgba(220,38,38,.5);
    animation:pulse 1.5s infinite;
}
@keyframes pulse {
    0%,100% { box-shadow:0 4px 20px rgba(220,38,38,.5); }
    50%      { box-shadow:0 4px 30px rgba(220,38,38,.8); }
}
</style>

<div class="mp">

<!-- Header -->
<div class="mp-hdr">
    <div>
        <h1><i class="fas fa-shield-alt"></i> Menu Permissions</h1>
        <p>Edit page info inline and assign which roles see each item in the sidebar. Press <strong>Save All</strong> when done.</p>
    </div>
    <a href="dashboard" class="btn btn-ghost" style="background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<?php if ($success_msg): ?>
<div class="alert a-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert a-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats">
    <div class="sp"><i class="fas fa-list"></i> <b><?= count($items) ?></b> menu items</div>
    <div class="sp"><i class="fas fa-users"></i> <b><?= count($all_roles) ?></b> roles</div>
    <div class="sp"><i class="fas fa-folder"></i> <b><?= count($grouped) ?></b> categories</div>
    <div class="sp"><i class="fas fa-eye"></i> <b><?= count(array_filter($items, fn($i)=>$i['is_active'])) ?></b> active</div>
    <div class="sp"><i class="fas fa-eye-slash"></i> <b><?= count(array_filter($items, fn($i)=>!$i['is_active'])) ?></b> hidden</div>
</div>

<!-- Add new item-->
<div class="card">
    <div class="ch">
        <h2><i class="fas fa-plus-circle"></i> Add New Menu Item</h2>
        <small style="color:var(--muted);">Duplicate filenames will update metadata only; permissions are preserved.</small>
    </div>
    <div class="cb">
        <form method="POST">
            <div class="ag">
                <div class="fg">
                    <label>Page Filename *</label>
                    <input type="text" name="page" class="fc" placeholder="reports" required>
                </div>
                <div class="fg">
                    <label>Sidebar Label *</label>
                    <input type="text" name="label" class="fc" placeholder="Reports" required>
                </div>
                <div class="fg">
                    <label>Icon <small style="font-weight:400;text-transform:none;">(FA5 class)</small></label>
                    <input type="text" name="icon" class="fc" value="fa-circle" placeholder="fa-chart-bar">
                </div>
                <div class="fg">
                    <label>Category</label>
                    <input type="text" name="category" class="fc" value="General" list="cat-list" placeholder="General">
                    <datalist id="cat-list">
                        <?php foreach (array_keys($grouped) as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="fg">
                    <label>Sort #</label>
                    <input type="number" name="sort_order" class="fc" value="0" min="0">
                </div>
                <div class="fg">
                    <label>&nbsp;</label>
                    <button type="submit" name="add_item" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-plus"></i> Add
                    </button>
                </div>
            </div>
            <p style="margin:10px 0 0;font-size:12px;color:var(--muted);">
                <i class="fas fa-info-circle" style="color:var(--primary);"></i>
                After adding, tick the roles below and press <strong>Save All Changes</strong>.
                Browse icons at <a href="https://fontawesome.com/icons" target="_blank" style="color:var(--primary);">fontawesome.com/icons</a>.
            </p>
        </form>
    </div>
</div>

<!--  Permission matrix -->
<div class="card">
    <div class="ch">
        <h2><i class="fas fa-table"></i> Page &amp; Role Assignment</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-ghost btn-xs" onclick="checkAll(true)">
                <i class="fas fa-check-square"></i> Check All
            </button>
            <button type="button" class="btn btn-ghost btn-xs" onclick="checkAll(false)">
                <i class="fas fa-square"></i> Clear All
            </button>
        </div>
    </div>
    <div class="cb" style="padding-top:14px;">

        <!-- Quick grant by role -->
        <div class="qbar">
            <span class="qbar-label"><i class="fas fa-bolt"></i> Grant all pages to:</span>
            <?php foreach ($all_roles as $r): ?>
            <button type="button" class="btn btn-role<?= isset($custom_roles[$r]) ? ' btn-role-added' : '' ?>"
                    data-role="<?= htmlspecialchars($r, ENT_QUOTES) ?>" onclick="grantRole(this.dataset.role)"
                    <?= isset($custom_roles[$r]) ? 'title="Added role (works like ' . htmlspecialchars($custom_roles[$r]['base_role'], ENT_QUOTES) . ')"' : '' ?>>
                <i class="fas fa-user-check" style="font-size:10px;"></i>
                <?= htmlspecialchars($r) ?>
            </button>
            <?php endforeach; ?>
            <span style="margin-left:auto;font-size:11px;color:var(--muted);">
                <i class="fas fa-info-circle"></i> Click a role to check all its boxes
            </span>
        </div>

        <form method="POST" id="perm-form">
        <div class="pt-wrap">
        <table class="pt">
            <thead>
                <tr>
                    <th class="th-item">
                        Page &nbsp;/&nbsp; Label &nbsp;/&nbsp; Icon &nbsp;&nbsp; Sort &nbsp; Category
                    </th>
                    <th class="th-act" title="Show in sidebar">Active</th>
                    <?php foreach ($all_roles as $r): ?>
                    <th title="<?= htmlspecialchars($r) ?><?= isset($custom_roles[$r]) ? ' (added role, works like ' . htmlspecialchars($custom_roles[$r]['base_role']) . ')' : '' ?>"<?= isset($custom_roles[$r]) ? ' class="th-role-added"' : '' ?>>
                        <?= nl2br(htmlspecialchars(str_replace([' ','/'],["\n","/\n"],$r))) ?>
                    </th>
                    <?php endforeach; ?>
                    <th title="Delete item"><i class="fas fa-trash"></i></th>
                </tr>
            </thead>
            <tbody>

            <?php foreach ($grouped as $category => $cat_items):
                $cc = catColor($category, $cat_keys, $cat_palette);
            ?>
                <!-- Category heading -->
                <tr class="cat-row">
                    <td colspan="<?= 3 + count($all_roles) ?>" style="color:<?= $cc ?>;">
                        <i class="fas fa-layer-group"></i>
                        <?= htmlspecialchars($category) ?>
                        <span style="margin-left:8px;opacity:.55;font-weight:400;">
                            (<?= count($cat_items) ?> item<?= count($cat_items)!==1?'s':'' ?>)
                        </span>
                    </td>
                </tr>

                <?php foreach ($cat_items as $item):
                    $mid     = $item['menu_id'];
                    $granted = $item['roles'];
                    $active  = (bool)$item['is_active'];
                ?>
                <tr id="row-<?= $mid ?>">

                    <!-- -- Inline-edit cell -- -->
                    <td class="td-item item-cell">
                        <div style="display:flex;align-items:flex-start;gap:8px;">
                            <div style="flex:1;min-width:0;">

                                <!-- Row 1: page filename -->
                                <div class="ie-row" style="margin-bottom:3px;">
                                    <i class="fas fa-file-code ie-icon-prev" style="color:#94a3b8;"></i>
                                    <input type="text"
                                           class="ie-inp"
                                           name="items[<?= $mid ?>][page]"
                                           value="<?= htmlspecialchars($item['page']) ?>"
                                           placeholder="page-name"
                                           title="Page link (e.g. reports)">
                                </div>

                                <!-- Row 2: label -->
                                <div class="ie-row" style="margin-bottom:3px;">
                                    <i class="fas fa-tag ie-icon-prev" style="color:#94a3b8;"></i>
                                    <input type="text"
                                           class="ie-inp"
                                           name="items[<?= $mid ?>][label]"
                                           value="<?= htmlspecialchars($item['label']) ?>"
                                           placeholder="Sidebar label"
                                           title="Sidebar label">
                                </div>

                                <!-- Row 3: icon | sort | category -->
                                <div class="ie-row">
                                    <i class="fas <?= htmlspecialchars($item['icon']) ?> ie-icon-prev"
                                       id="ip-<?= $mid ?>"></i>
                                    <input type="text"
                                           class="ie-inp"
                                           name="items[<?= $mid ?>][icon]"
                                           value="<?= htmlspecialchars($item['icon']) ?>"
                                           placeholder="fa-circle"
                                           title="FontAwesome 5 icon class"
                                           oninput="liveIcon(this,<?= $mid ?>)"
                                           style="width:115px;flex-shrink:0;">
                                    <input type="number"
                                           class="ie-inp ie-sort"
                                           name="items[<?= $mid ?>][sort_order]"
                                           value="<?= intval($item['sort_order']) ?>"
                                           title="Sort order (lower = higher up)"
                                           min="0" max="999">
                                    <input type="text"
                                           class="ie-inp"
                                           name="items[<?= $mid ?>][category]"
                                           value="<?= htmlspecialchars($item['category']) ?>"
                                           placeholder="Category"
                                           title="Sidebar section/category"
                                           list="cat-list"
                                           style="width:100px;flex-shrink:0;">
                                </div>
                            </div>

                            <!-- Category badge -->
                            <span class="cat-badge" style="background:<?= $cc ?>;">
                                <?= htmlspecialchars($category) ?>
                            </span>
                        </div>
                    </td>

                    <!-- -- Active toggle -- -->
                    <td class="toggle-cell">
                        <label class="tog" title="<?= $active ? 'Visible in sidebar' : 'Hidden from sidebar' ?>">
                            <input type="checkbox"
                                   name="active[<?= $mid ?>]"
                                   value="1"
                                   <?= $active ? 'checked' : '' ?>>
                            <span class="ts"></span>
                        </label>
                    </td>

                    <!-- -- Role checkboxes -- -->
                    <?php foreach ($all_roles as $r): ?>
                    <td class="rc-cell">
                        <input type="checkbox"
                               class="rcb"
                               data-role="<?= htmlspecialchars($r, ENT_QUOTES) ?>"
                               name="perms[<?= $mid ?>][<?= htmlspecialchars($r, ENT_QUOTES) ?>]"
                               value="1"
                               <?= in_array($r, $granted) ? 'checked' : '' ?>
                               title="<?= htmlspecialchars($r) ?>: <?= htmlspecialchars($item['label']) ?>">
                    </td>
                    <?php endforeach; ?>

                    <!-- -- Delete -- -->
                    <td class="del-wrap">
                        <a href="menu-permissions?delete=<?= (int)$mid ?>&amp;csrf_token=<?= h(csrf_token()) ?>"
                           class="del-btn"
                           onclick="return confirm('Remove \'<?= htmlspecialchars(addslashes($item['label'])) ?>\'?\n\nThis only removes the sidebar entry - the PHP file is untouched.')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>

                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>

            </tbody>
        </table>
        </div>

        <!-- Floating save -->
        <button type="submit" name="save_permissions"
                class="btn btn-success save-float"
                id="save-btn">
            <i class="fas fa-save"></i> Save All Changes
        </button>

        </form>
    </div><!-- /cb -->
</div><!-- /card -->

</div><!-- /mp -->

<script>
/* -- Live icon preview ----------------------- */
function liveIcon(input, mid) {
    const el = document.getElementById('ip-' + mid);
    if (el) el.className = 'fas ' + input.value.trim() + ' ie-icon-prev';
}

/* -- Check / clear ALL role checkboxes ------- */
function checkAll(state) {
    document.querySelectorAll('.rcb').forEach(cb => cb.checked = state);
    markDirty();
}

/* -- Grant single role to every item --------- */
function grantRole(role) {
    document.querySelectorAll(`.rcb[data-role="${CSS.escape(role)}"]`)
        .forEach(cb => cb.checked = true);
    markDirty();
}

/* -- Dirty-state tracker --------------------- */
let dirty = false;
function markDirty() {
    dirty = true;
    const btn = document.getElementById('save-btn');
    if (btn) {
        btn.classList.add('dirty');
        btn.innerHTML = '<i class="fas fa-exclamation-circle"></i> Unsaved - Save Now';
    }
}
function clearDirty() {
    dirty = false;
    const btn = document.getElementById('save-btn');
    if (btn) {
        btn.classList.remove('dirty');
        btn.innerHTML = '<i class="fas fa-save"></i> Save All Changes';
    }
}

const form = document.getElementById('perm-form');
if (form) {
    form.addEventListener('change', markDirty);
    form.addEventListener('input',  markDirty);
    form.addEventListener('submit', clearDirty);
}
window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<?php require_once 'includes/footer.php'; ?>