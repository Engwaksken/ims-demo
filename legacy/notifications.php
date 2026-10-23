<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| My Notifications
|--------------------------------------------------------------------------
| Lists the signed-in user's in-app notifications (written by notify_user()
| in includes/security.php), newest first, and lets the user mark one or all
| as read. Every query is limited to the session user's own rows.
| CSRF: config.php enforces a token on every POST by a logged-in user and
| injects it into forms; csrf_field() is also added explicitly.
*/

require_once __DIR__ . '/includes/config.php';
check_login();

$ntUserId = (int)($_SESSION['user_id'] ?? 0);

if ($ntUserId <= 0 || !isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Notifications are unavailable.');
}

$ntPerPage = 20;
$ntFilter  = (($_GET['filter'] ?? '') === 'unread') ? 'unread' : 'all';
$ntPage    = max(1, (int)($_GET['page'] ?? 1));

/** Same-page URL with the current filter/page (only known keys, so it is safe to redirect to). */
function nt_self_url(string $filter, int $page): string
{
    $query = [];
    if ($filter === 'unread') {
        $query['filter'] = 'unread';
    }
    if ($page > 1) {
        $query['page'] = $page;
    }
    return 'notifications' . ($query ? '?' . http_build_query($query) : '');
}

/*
|--------------------------------------------------------------------------
| POST: mark one / all as read (Post/Redirect/Get)
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action  = (string)($_POST['action'] ?? '');
    $changed = 0;

    try {
        if ($action === 'mark_read') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $conn->prepare(
                    'UPDATE notifications SET is_read = 1, read_at = NOW()
                     WHERE id = ? AND user_id = ? AND is_read = 0'
                );
                if ($stmt) {
                    $stmt->bind_param('ii', $id, $ntUserId);
                    $stmt->execute();
                    $changed = $stmt->affected_rows;
                    $stmt->close();
                }
            }
        } elseif ($action === 'mark_all_read') {
            $stmt = $conn->prepare(
                'UPDATE notifications SET is_read = 1, read_at = NOW()
                 WHERE user_id = ? AND is_read = 0'
            );
            if ($stmt) {
                $stmt->bind_param('i', $ntUserId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
            }
        }

        if ($action === 'mark_all_read') {
            send_notification(
                $ntUserId,
                $changed > 0 ? $changed . ' notification(s) marked as read.' : 'No unread notifications.',
                $changed > 0 ? 'success' : 'info'
            );
        } elseif ($action === 'mark_read' && $changed > 0) {
            send_notification($ntUserId, 'Notification marked as read.', 'success');
        }
    } catch (Throwable $e) {
        error_log('notifications.php: ' . $e->getMessage());
        send_notification($ntUserId, 'Could not update notifications. Please try again.', 'danger');
    }

    $backFilter = (($_POST['filter'] ?? '') === 'unread') ? 'unread' : 'all';
    $backPage   = max(1, (int)($_POST['page'] ?? 1));
    header('Location: ' . nt_self_url($backFilter, $backPage));
    exit;
}

/*
|--------------------------------------------------------------------------
| GET: counts + one page of rows
|--------------------------------------------------------------------------
*/
$ntTotalAll = 0;
$ntUnread   = 0;
$ntRows     = [];
$ntError    = false;

try {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total, COALESCE(SUM(is_read = 0), 0) AS unread
         FROM notifications WHERE user_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('i', $ntUserId);
        $stmt->execute();
        $stmt->bind_result($ntTotalAll, $ntUnread);
        $stmt->fetch();
        $stmt->close();
    }
    $ntTotalAll = (int)$ntTotalAll;
    $ntUnread   = (int)$ntUnread;

    $ntTotal = $ntFilter === 'unread' ? $ntUnread : $ntTotalAll;
    $ntPages = max(1, (int)ceil($ntTotal / $ntPerPage));
    $ntPage  = min($ntPage, $ntPages);
    $offset  = ($ntPage - 1) * $ntPerPage;

    $sql = 'SELECT id, title, message, type, reference_id, reference_type, is_read, read_at, created_at
            FROM notifications
            WHERE user_id = ?' . ($ntFilter === 'unread' ? ' AND is_read = 0' : '') . '
            ORDER BY created_at DESC, id DESC
            LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('iii', $ntUserId, $ntPerPage, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ntRows[] = $row;
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('notifications.php: ' . $e->getMessage());
    $ntError = true;
    $ntPages = 1;
}

$ntIcons = [
    'info'    => 'fa-circle-info',
    'success' => 'fa-circle-check',
    'warning' => 'fa-triangle-exclamation',
    'danger'  => 'fa-circle-exclamation',
];

$page_title = 'Notifications';
require_once __DIR__ . '/includes/header.php';
?>
<style>
/* Notifications: the filter tabs are links, and each row is icon + text + action. */
.page-notifications .nt-card .tab-btn { text-decoration:none; }
.page-notifications .nt-list { list-style:none; margin:0; padding:0; }
.page-notifications .nt-item { display:flex; gap:var(--space-3); align-items:flex-start; padding:var(--space-4) 22px; border-bottom:1px solid var(--ink-50); }
.page-notifications .nt-item:last-child { border-bottom:0; }
.page-notifications .nt-item.is-unread { background:var(--brand-50); }
.page-notifications .nt-icon { flex:0 0 36px; height:36px; border-radius:50%; display:grid; place-items:center; font-size:16px; }
.page-notifications .nt-icon.t-info    { background:var(--blue-bg);  color:var(--blue-fg); }
.page-notifications .nt-icon.t-success { background:var(--green-bg); color:var(--green-fg); }
.page-notifications .nt-icon.t-warning { background:var(--amber-bg); color:var(--amber-fg); }
.page-notifications .nt-icon.t-danger  { background:var(--red-bg);   color:var(--red-fg); }
.page-notifications .nt-body { flex:1 1 auto; min-width:0; }
.page-notifications .nt-title { margin:0; font-size:var(--fs-base); font-weight:700; color:var(--ink-700); }
.page-notifications .nt-message { margin:4px 0 0; color:var(--ink-500); font-size:var(--fs-sm); overflow-wrap:anywhere; }
.page-notifications .nt-meta { margin-top:6px; font-size:var(--fs-xs); color:var(--ink-300); display:flex; gap:var(--space-3); flex-wrap:wrap; }
.page-notifications .nt-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--brand-500); margin-right:6px; vertical-align:middle; }
.page-notifications .nt-actions { flex:0 0 auto; }
.page-notifications .pagination { margin:0; padding:var(--space-4); border-top:1px solid var(--ink-50); }
.page-notifications .pagination span.is-current { padding:8px 12px; border-radius:var(--radius-sm); background:var(--brand-500); color:var(--ink-0); border:1px solid var(--brand-500); }
.page-notifications .pagination .is-disabled { padding:8px 12px; color:var(--ink-200); }
@media (max-width: 600px) {
    .page-notifications .nt-item { flex-wrap:wrap; }
    .page-notifications .nt-actions { width:100%; padding-left:48px; }
}
</style>

<div class="page-stack page-notifications">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-bell" aria-hidden="true"></i> Notifications</h1>
            <p>
                <?= $ntUnread > 0 ? h((string)$ntUnread) . ' unread of ' . h((string)$ntTotalAll) : 'You are all caught up.' ?>
            </p>
        </div>
        <?php if ($ntUnread > 0): ?>
        <div class="hero-actions">
            <form method="post" action="notifications">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="filter" value="<?= h($ntFilter) ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($ntError): ?>
        <div class="alert alert-danger" data-persist>Notifications could not be loaded. Please try again later.</div>
    <?php endif; ?>

    <div class="card nt-card">
    <nav class="tab-nav" aria-label="Filter notifications">
        <a class="tab-btn<?= $ntFilter === 'all' ? ' active' : '' ?>" href="<?= h(nt_self_url('all', 1)) ?>"
           <?= $ntFilter === 'all' ? 'aria-current="page"' : '' ?>><i class="fas fa-inbox" aria-hidden="true"></i> All (<?= h((string)$ntTotalAll) ?>)</a>
        <a class="tab-btn<?= $ntFilter === 'unread' ? ' active' : '' ?>" href="<?= h(nt_self_url('unread', 1)) ?>"
           <?= $ntFilter === 'unread' ? 'aria-current="page"' : '' ?>><i class="fas fa-envelope" aria-hidden="true"></i> Unread (<?= h((string)$ntUnread) ?>)</a>
    </nav>

<?php if ($ntError): ?>
        <div class="alert alert-danger" data-persist>Notifications could not be loaded. Please try again later.</div>
    <?php elseif (!$ntRows): ?>
        <div class="nt-list">
            <div class="nt-empty">
                <i class="fas fa-bell-slash" aria-hidden="true"></i>
                <?= $ntFilter === 'unread' ? 'No unread notifications.' : 'You have no notifications yet.' ?>
            </div>
        </div>
    <?php else: ?>
        <ul class="nt-list">
            <?php foreach ($ntRows as $n): ?>
                <?php
                $type     = isset($ntIcons[$n['type']]) ? (string)$n['type'] : 'info';
                $isUnread = (int)$n['is_read'] === 0;
                $created  = strtotime((string)$n['created_at']);
                $refType  = trim((string)($n['reference_type'] ?? ''));
                $refId    = (int)($n['reference_id'] ?? 0);
                ?>
                <li class="nt-item<?= $isUnread ? ' is-unread' : '' ?>">
                    <span class="nt-icon t-<?= h($type) ?>" aria-hidden="true"><i class="fas <?= h($ntIcons[$type]) ?>"></i></span>
                    <div class="nt-body">
                        <p class="nt-title">
                            <?php if ($isUnread): ?><span class="nt-dot" aria-hidden="true"></span><span class="sr-only">Unread: </span><?php endif; ?>
                            <?= h($n['title']) ?>
                        </p>
                        <p class="nt-message"><?= nl2br(h($n['message'])) ?></p>
                        <div class="nt-meta">
                            <?php if ($created): ?>
                                <time datetime="<?= h(date('c', $created)) ?>"><?= h(date('d M Y, H:i', $created)) ?></time>
                            <?php endif; ?>
                            <?php if ($refType !== '' || $refId > 0): ?>
                                <span>Ref: <?= h($refType !== '' ? ucfirst($refType) : 'Item') ?><?= $refId > 0 ? ' #' . h((string)$refId) : '' ?></span>
                            <?php endif; ?>
                            <?php if (!$isUnread && !empty($n['read_at'])): ?>
                                <span>Read <?= h(date('d M Y, H:i', (int)strtotime((string)$n['read_at']))) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isUnread): ?>
                    <div class="nt-actions">
                        <form method="post" action="notifications">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="id" value="<?= h((string)(int)$n['id']) ?>">
                            <input type="hidden" name="filter" value="<?= h($ntFilter) ?>">
                            <input type="hidden" name="page" value="<?= h((string)$ntPage) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm"
                                    aria-label="Mark &quot;<?= h($n['title']) ?>&quot; as read">
                                <i class="fas fa-check" aria-hidden="true"></i> Mark read
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($ntPages > 1): ?>
        <nav class="pagination" aria-label="Notification pages">
            <?php if ($ntPage > 1): ?>
                <a href="<?= h(nt_self_url($ntFilter, $ntPage - 1)) ?>" rel="prev">&laquo; Prev</a>
            <?php else: ?>
                <span class="is-disabled">&laquo; Prev</span>
            <?php endif; ?>

            <span class="is-current" aria-current="page">Page <?= h((string)$ntPage) ?> of <?= h((string)$ntPages) ?></span>

            <?php if ($ntPage < $ntPages): ?>
                <a href="<?= h(nt_self_url($ntFilter, $ntPage + 1)) ?>" rel="next">Next &raquo;</a>
            <?php else: ?>
                <span class="is-disabled">Next &raquo;</span>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
    </div><!-- /.card -->
</div><!-- /.page-stack -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
