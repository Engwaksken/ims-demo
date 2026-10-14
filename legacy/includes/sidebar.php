<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


require_once __DIR__ . '/config.php';

if (function_exists('check_login')) {
    check_login();
}

$current_page = basename($_SERVER['PHP_SELF'] ?? '');
// Page name without ".php", for matching extensionless menu links.
$current_page_name = (string)preg_replace('/\.php$/i', '', $current_page);
$user_role    = (string)($_SESSION['role'] ?? '');
// Display name: an admin-added role's own name.
$user_role_label = (string)($_SESSION['role_name'] ?? $user_role);
// Menu: an added role's own menu (Menu Permissions), else its base role's.
$menu_role = (isset($conn) && $conn instanceof mysqli && function_exists('ims_menu_role'))
    ? ims_menu_role($conn)
    : $user_role;
$user_name    = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');

$menu_items = [];

try {
    if (isset($conn) && $conn instanceof mysqli && $user_role !== '') {
        $stmt = $conn->prepare("
            SELECT  mi.page,
                    mi.icon,
                    mi.label,
                    mi.category,
                    mi.sort_order
            FROM    menu_items mi
            INNER JOIN menu_role_permissions mrp
                    ON mrp.menu_id = mi.menu_id
                   AND mrp.role = ?
            WHERE   mi.is_active = 1
            ORDER BY mi.sort_order ASC, mi.label ASC
        ");

        if ($stmt) {
            $stmt->bind_param('s', $menu_role);
            $stmt->execute();

            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $menu_items[] = $row;
            }

            $stmt->close();
        }
    }
} catch (Throwable $e) {
    $menu_items = [];
}

/*
 * Build the menu: validate each row, then group by menu_items.category so the
 * sidebar shows collapsible sections instead of one long scrolling list.
 */
$sidebar_pinned = [];   // dashboards: always visible at the top
$sidebar_groups = [];   // category => list of entries, in first-seen order

foreach ($menu_items as $item) {
    $page  = trim((string)($item['page'] ?? ''));
    // menu_items is editable from Menu Permissions: never render a
    // javascript:/data:/protocol-relative link. Unsafe rows are skipped.
    $page_ok = function_exists('ims_is_safe_link')
        ? ims_is_safe_link($page)
        : (bool)preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $page);
    if (!$page_ok) {
        continue;
    }
    // Local pages are linked extensionless ("dashboard.php?x=1" -> "dashboard?x=1");
    // absolute http(s) URLs are left untouched.
    $is_local = !preg_match('#^https?://#i', $page);
    $href     = $is_local ? (string)preg_replace('#\.php(?=[?\#]|$)#i', '', $page) : $page;
    $basename = basename((string)strtok($href, '?#'));

    $entry = [
        'href'   => $href,
        'icon'   => (string)($item['icon'] ?? 'fa-circle'),
        'label'  => (string)($item['label'] ?? 'Menu'),
        'active' => $is_local && $current_page_name !== '' && $current_page_name === $basename,
    ];

    if ($is_local && preg_match('/(^|-)dashboard$/i', $basename)) {
        $sidebar_pinned[] = $entry;
        continue;
    }

    $category = trim((string)($item['category'] ?? '')) ?: 'General';
    $sidebar_groups[$category][] = $entry;
}

// Preferred section order; categories not listed follow alphabetically.
$sidebar_group_order = [
    'General', 'Programs', 'Applications', 'People', 'Operations',
    'General Management', 'Reporting', 'Admin', 'Member', 'Applicant',
];
uksort($sidebar_groups, static function (string $a, string $b) use ($sidebar_group_order): int {
    $ia = array_search($a, $sidebar_group_order, true);
    $ib = array_search($b, $sidebar_group_order, true);
    $ia = $ia === false ? PHP_INT_MAX : $ia;
    $ib = $ib === false ? PHP_INT_MAX : $ib;
    return $ia <=> $ib ?: strcasecmp($a, $b);
});

$sidebar_group_icons = [
    'General'            => 'fa-th-large',
    'Programs'           => 'fa-project-diagram',
    'Applications'       => 'fa-file-signature',
    'People'             => 'fa-users',
    'Operations'         => 'fa-cogs',
    'General Management' => 'fa-briefcase',
    'Reporting'          => 'fa-chart-bar',
    'Admin'              => 'fa-user-shield',
    'Member'             => 'fa-id-card',
    'Applicant'          => 'fa-user-graduate',
];

// A single section (e.g. Member, Applicant roles) is shown as a flat list.
$sidebar_use_groups = count($sidebar_groups) > 1;

$sidebar_link = static function (array $e): string {
    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    return '<li class="' . ($e['active'] ? 'active' : '') . '">'
        . '<a href="' . $h($e['href']) . '"' . ($e['active'] ? ' aria-current="page"' : '') . '>'
        . '<i class="fas ' . $h($e['icon']) . '" aria-hidden="true"></i>'
        . '<span>' . $h($e['label']) . '</span>'
        . '</a></li>';
};
?>

<aside class="sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <h1><?= htmlspecialchars((string)(defined('SITE_NAME') ? SITE_NAME : 'Dashboard'), ENT_QUOTES, 'UTF-8') ?></h1>
        </div>

        <a href="user-profile" class="sidebar-user" title="My profile">
            <div class="user-avatar" aria-hidden="true">
                <i class="fas fa-user-circle"></i>
            </div>

            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="user-role"><?= htmlspecialchars($user_role_label, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </a>
    </div>

    <nav class="sidebar-nav" aria-label="Main menu">
        <ul class="sidebar-menu">
            <?php if (empty($sidebar_pinned) && empty($sidebar_groups)): ?>
                <li class="sidebar-empty">
                    No menu items available.
                </li>
            <?php else: ?>
                <?php foreach ($sidebar_pinned as $entry): ?>
                    <?= $sidebar_link($entry) ?>
                <?php endforeach; ?>

                <?php foreach ($sidebar_groups as $category => $entries): ?>
                    <?php if (!$sidebar_use_groups): ?>
                        <?php foreach ($entries as $entry): ?>
                            <?= $sidebar_link($entry) ?>
                        <?php endforeach; ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php
                    $group_active = (bool)array_filter($entries, static fn(array $e): bool => $e['active']);
                    $group_icon   = $sidebar_group_icons[$category] ?? 'fa-folder';
                    ?>
                    <li class="sidebar-group<?= $group_active ? ' has-active' : '' ?>">
                        <?php /* Same name = only one section open at a time (native accordion). */ ?>
                        <details name="sidebar-group"<?= $group_active ? ' open' : '' ?>>
                            <summary class="sidebar-group-toggle">
                                <i class="fas <?= htmlspecialchars($group_icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                <span><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="sidebar-group-count"><?= count($entries) ?></span>
                                <i class="fas fa-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
                            </summary>
                            <ul class="sidebar-submenu">
                                <?php foreach ($entries as $entry): ?>
                                    <?= $sidebar_link($entry) ?>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="logout" class="logout-btn">
            <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>
<script>
(function () {
    var groups = document.querySelectorAll('#appSidebar details[name="sidebar-group"]');
    // Fallback for browsers without native exclusive <details name>: keep one open.
    groups.forEach(function (d) {
        d.addEventListener('toggle', function () {
            if (!d.open) { return; }
            groups.forEach(function (other) { if (other !== d && other.open) { other.open = false; } });
        });
    });
    var current = document.querySelector('#appSidebar li.active > a');
    if (current && current.scrollIntoView) {
        current.scrollIntoView({ block: 'nearest' });
    }
})();
</script>