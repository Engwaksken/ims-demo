<?php
require_once __DIR__ . '/config.php';
check_login();

$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? '';
$page_title = $page_title ?? 'Dashboard';

// Presentation-only helpers for the layout chrome.
$hdr_title   = htmlspecialchars((string)$page_title, ENT_QUOTES, 'UTF-8');
$hdr_site    = htmlspecialchars((string)SITE_NAME, ENT_QUOTES, 'UTF-8');
$hdr_initial = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr((string)$user_name, 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr((string)$user_name, 0, 1));

// Unread in-app notifications (written by notify_user() in security.php).
// Read-only; any failure (no connection, table missing) just hides the badge.
$hdr_unread = 0;
if (!empty($_SESSION['user_id']) && isset($conn) && $conn instanceof mysqli) {
    try {
        $hdr_stmt = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        if ($hdr_stmt) {
            $hdr_uid = (int)$_SESSION['user_id'];
            $hdr_stmt->bind_param('i', $hdr_uid);
            if ($hdr_stmt->execute()) {
                $hdr_stmt->bind_result($hdr_unread);
                $hdr_stmt->fetch();
            }
            $hdr_stmt->close();
        }
    } catch (Throwable $e) {
        $hdr_unread = 0;
    }
}
$hdr_unread = (int)$hdr_unread;
$hdr_unread_label = $hdr_unread > 99 ? '99+' : (string)$hdr_unread;
$hdr_bell_label = $hdr_unread > 0
    ? 'Notifications: ' . $hdr_unread . ' unread'
    : 'Notifications: none unread';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $hdr_title; ?> | <?php echo $hdr_site; ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../images/favicon.png">

    <!-- Fonts (DM Sans is requested by reports.css; preconnect speeds it up) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Styles: global tokens/components first, then the shared design language.
         Per-page stylesheets are linked by each page after this header. -->
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/reports.css">
    <!-- Accessibility widget (bottom-right): applies saved settings before paint, loads its own CSS. -->
    <script src="../js/accessibility.js"></script>
    <!-- Font Awesome: the one copy for the whole app (pages must not load their own). -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<?php if (!empty($load_chartjs)): ?>
    <!-- Chart.js for pages that draw charts in inline scripts (set $load_chartjs = true
         before including this header). Loaded here, not in footer.php, so it exists
         before the page's inline <script> runs; footer.php then skips its copy. -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif; ?>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileMenu()" aria-hidden="true"></div>

    <script>
    /* Fallback for the few pages that never include footer.php (and so never
       load main.js). main.js replaces this with the full version. */
    if (typeof window.toggleMobileMenu !== 'function') {
        window.toggleMobileMenu = function () {
            var s = document.querySelector('.sidebar'),
                o = document.getElementById('sidebarOverlay'),
                t = document.getElementById('menuToggle');
            if (!s) return;
            var open = !s.classList.contains('active');
            s.classList.toggle('active', open);
            if (o) o.classList.toggle('active', open);
            document.body.classList.toggle('sidebar-open', open);
            if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
    }
    </script>

    <!-- Sidebar (included separately) -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Header -->
    <header class="main-header">
        <div class="top-bar">
            <div class="top-bar-left">
                <!-- Mobile Menu Toggle -->
                <button type="button" class="menu-toggle" id="menuToggle" onclick="toggleMobileMenu()"
                        title="Toggle menu" aria-label="Toggle navigation menu"
                        aria-controls="appSidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>

                <!-- Page Title -->
                <h2 class="page-title">
                    <i class="fas fa-tachometer-alt" aria-hidden="true"></i>
                    <span class="page-title-text"><?php echo $hdr_title; ?></span>
                </h2>
            </div>

            <div class="top-bar-right">
                <!-- Notifications -->
                <div class="notifications">
                    <a href="notifications" class="btn-icon" title="<?php echo $hdr_bell_label; ?>" aria-label="<?php echo $hdr_bell_label; ?>">
                        <i class="fas fa-bell" aria-hidden="true"></i>
                        <?php if ($hdr_unread > 0): ?>
                        <span class="badge-notification" aria-hidden="true"><?php echo $hdr_unread_label; ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- User Info -->
                <div class="user-info">
                    <div class="user-avatar" aria-hidden="true">
                        <?php echo htmlspecialchars($hdr_initial, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="user-details">
                        <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role"><?php echo htmlspecialchars($role); ?></span>
                    </div>
                    <a href="logout" class="btn btn-danger btn-sm" title="Logout" aria-label="Logout">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                        <span class="logout-text">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content" id="main-content" tabindex="-1">
        <!-- Notifications Display -->
        <?php if (isset($_SESSION['notification'])): ?>
            <?php
            $hdr_note_type = (string)($_SESSION['notification']['type'] ?? 'info');
            if ($hdr_note_type === 'error') {
                $hdr_note_type = 'danger'; // set_notification() allows 'error'; there is no .alert-error style
            }
            if (!in_array($hdr_note_type, ['success', 'danger', 'warning', 'info'], true)) {
                $hdr_note_type = 'info';
            }
            ?>
            <div class="alert alert-<?php echo $hdr_note_type; ?> alert-flash" data-autohide="true"
                 role="<?php echo $hdr_note_type === 'danger' ? 'alert' : 'status'; ?>" style="margin-bottom: 20px;">
                <i class="fas fa-<?php echo $hdr_note_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>" aria-hidden="true"></i>
                <?php
                // Escape flash text (it can contain user/CSV data); keep only intentional <br> line breaks.
                echo str_ireplace(
                    ['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'],
                    '<br>',
                    nl2br(htmlspecialchars((string)($_SESSION['notification']['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                );
                ?>
                <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Dismiss notification">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>