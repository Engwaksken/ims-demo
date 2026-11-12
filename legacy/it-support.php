<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IT Support
|--------------------------------------------------------------------------
| Staff raise IT support requests; IT Officers (and Administrators) work the
| queue. See includes/it-support.php for the workflow and notifications.
| Staff only ever see their own requests; internal notes are IT-only.
*/

$page_title = 'IT Support';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/it-support.php';
check_role(IMS_STAFF_ROLES);

$uid = (int)($_SESSION['user_id'] ?? 0);
$isHandler = its_is_handler((string)($_SESSION['role'] ?? ''));
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!its_ensure_tables($conn)) {
    include 'includes/header.php'; ?>
    <div class="card"><div class="card-body"><div class="alert alert-warning">IT Support is unavailable: the database tables could not be created. Ask an Administrator to run database/migrations/2026_11_12_it_support.sql.</div></div></div>
    <?php include 'includes/footer.php';
    exit;
}

$views = $isHandler
    ? ['queue' => 'Active queue', 'mine' => 'Assigned to me', 'unassigned' => 'Unassigned', 'done' => 'Resolved & closed', 'requests' => 'My requests']
    : ['requests' => 'My open requests', 'done' => 'Resolved & closed'];
$view = array_key_exists((string)($_GET['view'] ?? ''), $views) ? (string)$_GET['view'] : array_key_first($views);

/*
|--------------------------------------------------------------------------
| POST (Post/Redirect/Get)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $result = match ($action) {
        'create' => its_create($conn, $uid, $_POST, $_FILES['attachment'] ?? null),
        'reply' => its_reply($conn, $uid, $isHandler, $ticketId, (string)($_POST['body'] ?? ''), !empty($_POST['internal'])),
        'update' => $isHandler ? its_update($conn, $uid, $ticketId, $_POST) : ['ok' => false, 'message' => 'Only IT staff can update requests.', 'id' => $ticketId],
        'close', 'reopen' => its_requester_action($conn, $uid, $ticketId, $action, (string)($_POST['note'] ?? '')),
        default => ['ok' => false, 'message' => 'Unknown action.', 'id' => 0],
    };
    $_SESSION['notification'] = ['message' => $result['message'], 'type' => $result['ok'] ? 'success' : 'danger'];

    $target = (int)$result['id'] > 0 && ($result['ok'] || $action !== 'create')
        ? 'it-support?id=' . (int)$result['id']
        : 'it-support' . (array_key_exists((string)($_POST['return_view'] ?? ''), $views) ? '?view=' . $_POST['return_view'] : '');
    header('Location: ' . $target, true, 303);
    exit;
}

$categories = its_categories();
$priorities = its_priorities();
$statuses = its_statuses();
$handlers = $isHandler ? its_users_in_roles($conn, its_handler_roles()) : [];
$statusBadge = static fn(string $s): string => '<span class="badge ' . ($statuses[$s] ?? 'badge-secondary') . '">' . htmlspecialchars($s) . '</span>';
$priorityBadge = static fn(string $p): string => '<span class="badge ' . ($priorities[$p][1] ?? 'badge-secondary') . '">' . htmlspecialchars($p) . '</span>';
$ago = static function (string $datetime): string {
    $seconds = time() - (int)strtotime($datetime);
    return match (true) {
        $seconds < 60 => 'just now',
        $seconds < 3600 => intdiv($seconds, 60) . ' min ago',
        $seconds < 86400 => intdiv($seconds, 3600) . ' h ago',
        $seconds < 7 * 86400 => intdiv($seconds, 86400) . ' d ago',
        default => date('j M Y', (int)strtotime($datetime)),
    };
};

/*
|--------------------------------------------------------------------------
| DETAIL ?id=N
|--------------------------------------------------------------------------
*/
$ticket = null;
if (isset($_GET['id'])) {
    $ticket = its_find($conn, (int)$_GET['id']);
    if (!$ticket || !its_can_view($ticket, $uid, $isHandler)) {
        $_SESSION['notification'] = ['message' => 'Request not found.', 'type' => 'danger'];
        header('Location: it-support', true, 303);
        exit;
    }
    $timeline = its_comments($conn, (int)$ticket['ticket_id'], $isHandler);
}

/*
|--------------------------------------------------------------------------
| LIST DATA
|--------------------------------------------------------------------------
*/
$rows = [];
$counts = [];
if (!$ticket) {
    if ($isHandler) {
        $stmt = $conn->prepare(
            "SELECT
                COALESCE(SUM(status IN ('Open','In Progress','On Hold')), 0) AS queue,
                COALESCE(SUM(status IN ('Open','In Progress','On Hold') AND assigned_to = ?), 0) AS mine,
                COALESCE(SUM(status IN ('Open','In Progress','On Hold') AND assigned_to IS NULL), 0) AS unassigned,
                COALESCE(SUM(status IN ('Open','In Progress','On Hold') AND priority IN ('High','Critical')), 0) AS urgent,
                COALESCE(SUM(status IN ('Resolved','Closed') AND resolved_at >= ?), 0) AS resolved_week,
                COALESCE(SUM(requester_id = ? AND status NOT IN ('Closed')), 0) AS requests
             FROM it_support_tickets"
        );
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $stmt->bind_param('isi', $uid, $weekAgo, $uid);
    } else {
        $stmt = $conn->prepare(
            "SELECT
                COALESCE(SUM(status IN ('Open','In Progress','On Hold')), 0) AS requests,
                COALESCE(SUM(status = 'Resolved'), 0) AS awaiting,
                COALESCE(SUM(status = 'Closed'), 0) AS closed
             FROM it_support_tickets WHERE requester_id = ?"
        );
        $stmt->bind_param('i', $uid);
    }
    $stmt->execute();
    $counts = array_map('intval', $stmt->get_result()->fetch_assoc() ?: []);
    $stmt->close();

    $filters = [
        'q' => its_clean($_GET['q'] ?? '', 100),
        'priority' => array_key_exists((string)($_GET['priority'] ?? ''), $priorities) ? (string)$_GET['priority'] : '',
        'category' => array_key_exists((string)($_GET['category'] ?? ''), $categories) ? (string)$_GET['category'] : '',
    ];

    $where = match ($view) {
        'queue' => ["t.status IN ('Open','In Progress','On Hold')"],
        'mine' => ["t.status IN ('Open','In Progress','On Hold')", 't.assigned_to = ?'],
        'unassigned' => ["t.status IN ('Open','In Progress','On Hold')", 't.assigned_to IS NULL'],
        'done' => ["t.status IN ('Resolved','Closed')"],
        'requests' => ['t.requester_id = ?', $isHandler ? "t.status <> 'Closed'" : "t.status IN ('Open','In Progress','On Hold')"],
    };
    $types = '';
    $params = [];
    if (in_array($view, ['mine', 'requests'], true)) {
        $types .= 'i';
        $params[] = $uid;
    }
    if (!$isHandler && $view === 'done') {
        $where[] = 't.requester_id = ?';
        $types .= 'i';
        $params[] = $uid;
    }
    if ($filters['q'] !== '') {
        $where[] = '(t.subject LIKE ? OR t.description LIKE ? OR r.full_name LIKE ? OR t.ticket_id = ?)';
        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $types .= 'sssi';
        array_push($params, $like, $like, $like, (int)preg_replace('/\D/', '', $filters['q']));
    }
    foreach (['priority', 'category'] as $field) {
        if ($filters[$field] !== '') {
            $where[] = "t.$field = ?";
            $types .= 's';
            $params[] = $filters[$field];
        }
    }
    $order = $view === 'done'
        ? 't.updated_at DESC'
        : "FIELD(t.priority, 'Critical','High','Medium','Low'), FIELD(t.status, 'Open','In Progress','On Hold','Resolved','Closed'), t.created_at";
    $stmt = $conn->prepare(
        'SELECT t.*, r.full_name AS requester_name, a.full_name AS assignee_name,
                (SELECT COUNT(*) FROM it_support_comments c WHERE c.ticket_id = t.ticket_id AND c.kind = \'comment\') AS replies
         FROM it_support_tickets t
         JOIN users r ON r.user_id = t.requester_id
         LEFT JOIN users a ON a.user_id = t.assigned_to
         WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 200'
    );
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

include 'includes/header.php';
?>

<style>
.its-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.its-cat{position:relative;margin:0}
.its-cat input{position:absolute;opacity:0;pointer-events:none}
.its-cat span{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-md);font-size:13px;font-weight:600;cursor:pointer;height:100%}
.its-cat span i{color:var(--brand-500);width:16px;text-align:center}
.its-cat input:checked + span{border-color:var(--brand-500);background:var(--brand-50);color:var(--brand-700)}
.its-cat input:focus-visible + span{outline:2px solid var(--brand-500);outline-offset:2px}
.its-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 14px}
.its-form-grid .is-wide{grid-column:1/-1}
.its-subject{font-weight:700;color:var(--ink-900);text-decoration:none}
.its-subject:hover{text-decoration:underline}
.its-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:20px;align-items:start}
.its-col{display:flex;flex-direction:column;gap:20px;min-width:0}
.its-description{white-space:pre-wrap;line-height:1.6;color:var(--ink-700)}
.its-timeline{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:14px}
.its-entry{border:1px solid var(--border);border-radius:var(--radius-md);padding:12px 14px}
.its-entry.is-it{border-color:var(--blue-border);background:var(--blue-bg)}
.its-entry.is-note{border-style:dashed;border-color:var(--amber-border);background:var(--amber-bg)}
.its-entry.is-event{border:0;padding:0 2px;font-size:12px;color:var(--ink-300)}
.its-entry-head{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:var(--ink-300);margin-bottom:6px}
.its-entry-head strong{color:var(--ink-900)}
.its-entry-body{white-space:pre-wrap;line-height:1.55;color:var(--ink-700)}
.its-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
@media(max-width:900px){.its-layout{grid-template-columns:1fr}}
@media(max-width:640px){.its-form-grid{grid-template-columns:1fr}}
</style>

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> IT Support</h1>
            <p><?= $isHandler ? 'Work the IT support queue: assign requests, update their status and keep requesters informed.' : 'Report a problem or request help from the IT department. You will be notified as your request progresses.' ?></p>
        </div>
        <div class="hero-actions">
            <?php if ($ticket): ?>
                <a href="it-support" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> All requests</a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" onclick="openModal('itsCreateModal')"><i class="fas fa-plus" aria-hidden="true"></i> New request</button>
        </div>
    </div>

<?php if ($ticket):
    $tid = (int)$ticket['ticket_id'];
    $isRequester = (int)$ticket['requester_id'] === $uid;
    $active = in_array($ticket['status'], ['Open', 'In Progress', 'On Hold'], true);
?>
    <div class="its-layout">
        <div class="its-col">
            <div class="card">
                <div class="card-header">
                    <h3><span class="cell-muted"><?= $escape(its_ref($tid)) ?></span> <?= $escape($ticket['subject']) ?></h3>
                    <div class="its-actions"><?= $priorityBadge((string)$ticket['priority']) ?> <?= $statusBadge((string)$ticket['status']) ?></div>
                </div>
                <div class="card-body">
                    <div class="its-description"><?= $escape($ticket['description']) ?></div>
                    <?php if (!empty($ticket['attachment_path'])): ?>
                        <p style="margin-top:14px"><a class="btn btn-secondary btn-sm" href="<?= $escape(ims_upload_url((string)$ticket['attachment_path'])) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip" aria-hidden="true"></i> <?= $escape($ticket['attachment_name'] ?: 'Attachment') ?></a></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i class="fas fa-comments" aria-hidden="true"></i> Activity</h3></div>
                <div class="card-body">
                    <?php if (!$timeline): ?>
                        <p class="cell-muted">No replies yet. <?= $isRequester ? 'IT support will reply here.' : '' ?></p>
                    <?php else: ?>
                        <ol class="its-timeline">
                            <?php foreach ($timeline as $c):
                                $fromIt = (int)$c['user_id'] !== (int)$ticket['requester_id'];
                                $class = $c['kind'] === 'event' ? 'is-event' : ($c['kind'] === 'note' ? 'is-note' : ($fromIt ? 'is-it' : ''));
                                $when = date('j M Y, H:i', (int)strtotime((string)$c['created_at']));
                            ?>
                                <li class="its-entry <?= $class ?>">
                                    <?php if ($c['kind'] === 'event'): ?>
                                        <i class="fas fa-circle-info" aria-hidden="true"></i> <?= $escape($c['body']) ?> · <?= $escape($c['full_name'] ?? '') ?> · <time datetime="<?= $escape(date('c', (int)strtotime((string)$c['created_at']))) ?>"><?= $escape($when) ?></time>
                                    <?php else: ?>
                                        <div class="its-entry-head">
                                            <span><strong><?= $escape((int)$c['user_id'] === $uid ? 'You' : ($c['full_name'] ?? 'Unknown')) ?></strong><?= $fromIt ? ' · IT support' : '' ?><?= $c['kind'] === 'note' ? ' · <i class="fas fa-lock" aria-hidden="true"></i> internal note' : '' ?></span>
                                            <time datetime="<?= $escape(date('c', (int)strtotime((string)$c['created_at']))) ?>"><?= $escape($when) ?></time>
                                        </div>
                                        <div class="its-entry-body"><?= $escape($c['body']) ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i class="fas fa-reply" aria-hidden="true"></i> <?= $isRequester ? 'Add a message' : 'Reply' ?></h3></div>
                <div class="card-body">
                    <form method="POST" action="it-support">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="ticket_id" value="<?= $tid ?>">
                        <div class="form-group">
                            <label for="itsReply" class="sr-only">Message</label>
                            <textarea id="itsReply" name="body" class="form-control" rows="4" maxlength="5000" required placeholder="<?= $isRequester ? 'Add details, answer a question from IT, or tell them it is still not working.' : 'Reply to ' . $escape($ticket['requester_name']) . '…' ?>"></textarea>
                            <?php if ($isRequester && !$active): ?><p class="form-hint">Sending a message reopens this request.</p><?php endif; ?>
                        </div>
                        <div class="its-actions">
                            <?php if ($isHandler && !$isRequester): ?>
                                <label class="its-actions" style="margin:0;font-weight:400"><input type="checkbox" name="internal" value="1"> Internal note (not shown to the requester)</label>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" style="margin-left:auto"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="its-col">
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-circle-info" aria-hidden="true"></i> Details</h3></div>
                <div class="card-body">
                    <dl class="detail-grid">
                        <div class="detail-item is-wide"><dt class="detail-label">Requested by</dt><dd class="detail-value"><?= $escape($ticket['requester_name']) ?> <span class="cell-muted">(<?= $escape($ticket['requester_role']) ?>)</span></dd></div>
                        <div class="detail-item"><dt class="detail-label">Category</dt><dd class="detail-value"><?= $escape($ticket['category']) ?></dd></div>
                        <div class="detail-item"><dt class="detail-label">Priority</dt><dd class="detail-value"><?= $escape($ticket['priority']) ?></dd></div>
                        <?php if (!empty($ticket['location'])): ?><div class="detail-item"><dt class="detail-label">Location</dt><dd class="detail-value"><?= $escape($ticket['location']) ?></dd></div><?php endif; ?>
                        <?php if (!empty($ticket['device'])): ?><div class="detail-item"><dt class="detail-label">Device / asset</dt><dd class="detail-value"><?= $escape($ticket['device']) ?></dd></div><?php endif; ?>
                        <div class="detail-item"><dt class="detail-label">Assigned to</dt><dd class="detail-value"><?= $escape($ticket['assignee_name'] ?: 'Not yet assigned') ?></dd></div>
                        <div class="detail-item"><dt class="detail-label">Raised</dt><dd class="detail-value"><?= $escape(date('j M Y, H:i', (int)strtotime((string)$ticket['created_at']))) ?></dd></div>
                        <?php if (!empty($ticket['resolved_at'])): ?><div class="detail-item"><dt class="detail-label">Resolved</dt><dd class="detail-value"><?= $escape(date('j M Y, H:i', (int)strtotime((string)$ticket['resolved_at']))) ?></dd></div><?php endif; ?>
                    </dl>
                </div>
            </div>

            <?php if ($isHandler): ?>
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-sliders" aria-hidden="true"></i> Manage</h3></div>
                    <div class="card-body">
                        <form method="POST" action="it-support">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="ticket_id" value="<?= $tid ?>">
                            <div class="form-group">
                                <label for="itsStatus">Status</label>
                                <select id="itsStatus" name="status" class="form-control">
                                    <?php foreach (array_keys($statuses) as $s): ?>
                                        <option value="<?= $escape($s) ?>"<?= $s === $ticket['status'] ? ' selected' : '' ?>><?= $escape($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="itsAssignee">Assigned to</label>
                                <select id="itsAssignee" name="assigned_to" class="form-control">
                                    <option value="0">Unassigned</option>
                                    <?php foreach ($handlers as $h): ?>
                                        <option value="<?= (int)$h['user_id'] ?>"<?= (int)$h['user_id'] === (int)$ticket['assigned_to'] ? ' selected' : '' ?>><?= $escape($h['full_name']) ?><?= (int)$h['user_id'] === $uid ? ' (me)' : '' ?> · <?= $escape($h['role']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="itsResolution">Message to the requester (optional)</label>
                                <textarea id="itsResolution" name="resolution" class="form-control" rows="3" maxlength="2000" placeholder="e.g. Replaced the network cable; please confirm it works."></textarea>
                                <p class="form-hint">Sent with a status change. Setting a request to In Progress assigns it to you if nobody has it.</p>
                            </div>
                            <div class="its-actions">
                                <?php if ((int)$ticket['assigned_to'] !== $uid && $active): ?>
                                    <button type="submit" class="btn btn-secondary" onclick="this.form.assigned_to.value='<?= $uid ?>'; if (this.form.status.value === 'Open') this.form.status.value = 'In Progress';"><i class="fas fa-hand" aria-hidden="true"></i> Take it</button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary" style="margin-left:auto"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Update</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isRequester): ?>
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-flag-checkered" aria-hidden="true"></i> <?= $ticket['status'] === 'Resolved' ? 'Is it fixed?' : 'Your request' ?></h3></div>
                    <div class="card-body">
                        <?php if ($ticket['status'] === 'Resolved'): ?>
                            <p>IT support marked this request as resolved. Please confirm, or reopen it if the problem is still there.</p>
                        <?php elseif ($ticket['status'] === 'Closed'): ?>
                            <p class="cell-muted">This request is closed. Reopen it if the problem comes back.</p>
                        <?php else: ?>
                            <p class="cell-muted">Fixed it yourself or no longer need help? Close the request.</p>
                        <?php endif; ?>
                        <div class="its-actions">
                            <?php if ($ticket['status'] !== 'Closed'): ?>
                                <form method="POST" action="it-support">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="close">
                                    <input type="hidden" name="ticket_id" value="<?= $tid ?>">
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check" aria-hidden="true"></i> <?= $ticket['status'] === 'Resolved' ? 'Yes, it is fixed' : 'Close request' ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($ticket['status'], ['Resolved', 'Closed'], true)): ?>
                                <form method="POST" action="it-support">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reopen">
                                    <input type="hidden" name="ticket_id" value="<?= $tid ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left" aria-hidden="true"></i> Still not fixed, reopen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <div class="stats-grid reports-stats">
        <?php if ($isHandler): ?>
            <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-inbox" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Active requests</span><span class="stat-value"><?= $counts['queue'] ?? 0 ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-red"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">High / critical</span><span class="stat-value"><?= $counts['urgent'] ?? 0 ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-user-clock" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Unassigned</span><span class="stat-value"><?= $counts['unassigned'] ?? 0 ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Resolved in 7 days</span><span class="stat-value"><?= $counts['resolved_week'] ?? 0 ?></span></div></div>
        <?php else: ?>
            <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-inbox" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Open requests</span><span class="stat-value"><?= $counts['requests'] ?? 0 ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Resolved, awaiting your confirmation</span><span class="stat-value"><?= $counts['awaiting'] ?? 0 ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-slate"><i class="fas fa-box-archive" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Closed</span><span class="stat-value"><?= $counts['closed'] ?? 0 ?></span></div></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <nav class="tab-nav" aria-label="IT support views">
            <?php foreach ($views as $key => $label):
                $count = match ($key) { 'queue' => $counts['queue'] ?? 0, 'mine' => $counts['mine'] ?? 0, 'unassigned' => $counts['unassigned'] ?? 0, 'requests' => $counts['requests'] ?? 0, default => 0 };
            ?>
                <a class="tab-btn<?= $view === $key ? ' active' : '' ?>" href="it-support?view=<?= $key ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= $escape($label) ?><?php if ($count): ?> <span class="badge"><?= $count ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($isHandler): ?>
            <form method="GET" action="it-support" class="filters-bar card-body">
                <input type="hidden" name="view" value="<?= $escape($view) ?>">
                <label class="sr-only" for="itsQ">Search</label>
                <input id="itsQ" type="search" name="q" class="form-control" value="<?= $escape($filters['q']) ?>" placeholder="Search subject, requester or IT-number">
                <label class="sr-only" for="itsPriorityFilter">Priority</label>
                <select id="itsPriorityFilter" name="priority" class="form-control">
                    <option value="">All priorities</option>
                    <?php foreach (array_keys($priorities) as $p): ?><option value="<?= $escape($p) ?>"<?= $filters['priority'] === $p ? ' selected' : '' ?>><?= $escape($p) ?></option><?php endforeach; ?>
                </select>
                <label class="sr-only" for="itsCategoryFilter">Category</label>
                <select id="itsCategoryFilter" name="category" class="form-control">
                    <option value="">All categories</option>
                    <?php foreach (array_keys($categories) as $c): ?><option value="<?= $escape($c) ?>"<?= $filters['category'] === $c ? ' selected' : '' ?>><?= $escape($c) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary"><i class="fas fa-filter" aria-hidden="true"></i> Filter</button>
                <?php if ($filters['q'] !== '' || $filters['priority'] !== '' || $filters['category'] !== ''): ?><a class="btn btn-soft" href="it-support?view=<?= $escape($view) ?>">Clear</a><?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="card-body">
            <?php if (!$rows): ?>
                <div class="empty-state">
                    <i class="fas fa-mug-hot" aria-hidden="true"></i>
                    <p><?= match ($view) {
                        'queue' => 'The queue is empty. Nice work.',
                        'mine' => 'Nothing is assigned to you right now.',
                        'unassigned' => 'Every active request has an owner.',
                        'done' => 'No resolved or closed requests yet.',
                        default => 'You have no open IT requests. Use “New request” if something is not working.',
                    } ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">Request</th>
                                <th scope="col">Priority</th>
                                <th scope="col">Status</th>
                                <?php if ($isHandler): ?><th scope="col">Requester</th><?php endif; ?>
                                <th scope="col">Assigned to</th>
                                <th scope="col">Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td data-label="Request">
                                        <a class="its-subject" href="it-support?id=<?= (int)$r['ticket_id'] ?>"><?= $escape($r['subject']) ?></a>
                                        <div class="cell-muted"><?= $escape(its_ref((int)$r['ticket_id'])) ?> · <i class="fas <?= $escape($categories[$r['category']] ?? 'fa-circle-question') ?>" aria-hidden="true"></i> <?= $escape($r['category']) ?><?php if ((int)$r['replies'] > 0): ?> · <i class="fas fa-comment" aria-hidden="true"></i> <?= (int)$r['replies'] ?><?php endif; ?><?php if (!empty($r['attachment_path'])): ?> · <i class="fas fa-paperclip" aria-label="Has attachment"></i><?php endif; ?></div>
                                    </td>
                                    <td data-label="Priority"><?= $priorityBadge((string)$r['priority']) ?></td>
                                    <td data-label="Status"><?= $statusBadge((string)$r['status']) ?></td>
                                    <?php if ($isHandler): ?><td data-label="Requester"><?= $escape($r['requester_name']) ?></td><?php endif; ?>
                                    <td data-label="Assigned to"><?= $r['assignee_name'] ? $escape($r['assignee_name']) : '<span class="cell-muted">Unassigned</span>' ?></td>
                                    <td data-label="Updated"><span title="<?= $escape(date('j M Y, H:i', (int)strtotime((string)$r['updated_at']))) ?>"><?= $escape($ago((string)$r['updated_at'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- New request Modal -->
<div id="itsCreateModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="itsCreateTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="itsCreateTitle"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> New IT support request</h3>
            <button type="button" class="close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="it-support" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="return_view" value="<?= $escape($view) ?>">

                <fieldset class="form-group">
                    <legend class="required">What is it about?</legend>
                    <div class="its-cats">
                        <?php $first = true; foreach ($categories as $name => $icon): ?>
                            <label class="its-cat">
                                <input type="radio" name="category" value="<?= $escape($name) ?>"<?= $first ? ' required' : '' ?>>
                                <span><i class="fas <?= $escape($icon) ?>" aria-hidden="true"></i> <?= $escape($name) ?></span>
                            </label>
                        <?php $first = false; endforeach; ?>
                    </div>
                </fieldset>

                <div class="its-form-grid">
                    <div class="form-group is-wide">
                        <label for="itsSubject" class="required">Subject</label>
                        <input id="itsSubject" name="subject" class="form-control" maxlength="200" required placeholder="e.g. Laptop will not connect to the office Wi-Fi">
                    </div>
                    <div class="form-group is-wide">
                        <label for="itsDescription" class="required">Describe the problem</label>
                        <textarea id="itsDescription" name="description" class="form-control" rows="5" minlength="10" maxlength="5000" required placeholder="What happened, when it started, any error message, and what you have already tried."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="itsPriority">Priority</label>
                        <select id="itsPriority" name="priority" class="form-control" aria-describedby="itsPriorityHint">
                            <?php foreach ($priorities as $p => [$hint]): ?>
                                <option value="<?= $escape($p) ?>" data-hint="<?= $escape($hint) ?>"<?= $p === 'Medium' ? ' selected' : '' ?>><?= $escape($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint" id="itsPriorityHint"><?= $escape($priorities['Medium'][0]) ?></p>
                    </div>
                    <div class="form-group">
                        <label for="itsLocation">Where are you?</label>
                        <input id="itsLocation" name="location" class="form-control" maxlength="150" placeholder="e.g. 2nd floor, Finance office">
                    </div>
                    <div class="form-group">
                        <label for="itsDevice">Device or asset tag</label>
                        <input id="itsDevice" name="device" class="form-control" maxlength="150" placeholder="e.g. Dell Latitude, tag IMS-0042">
                    </div>
                    <div class="form-group">
                        <label for="itsAttachment">Screenshot or file</label>
                        <input id="itsAttachment" name="attachment" type="file" class="form-control" accept=".<?= implode(',.', ITS_ATTACHMENT_TYPES) ?>">
                        <p class="form-hint">Optional, up to 5 MB.</p>
                    </div>
                </div>
                <p class="form-hint">The IT department is notified straight away. You can follow progress and reply from this page.</p>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('itsCreateModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send to IT</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    // Opening, closing, Escape and backdrop clicks use the global modal helpers in public/js/main.js.
    const priority = document.getElementById('itsPriority');
    const hint = document.getElementById('itsPriorityHint');
    priority.addEventListener('change', () => { hint.textContent = priority.selectedOptions[0].dataset.hint || ''; });
    <?php if (isset($_GET['new'])): ?>openModal('itsCreateModal');<?php endif; ?>
})();
</script>
<?php include 'includes/footer.php'; ?>
