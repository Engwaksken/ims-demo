<?php
declare(strict_types=1);
ob_start();

$page_title = 'Manage Procurement';
require_once 'includes/header.php';
require_once 'includes/process_procurement.php';
require_once 'includes/mail-function.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant', 'Staff', 'Procurement Officer']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}



$currentUserId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$currentRole   = (string)($user['role']    ?? ($_SESSION['role']    ?? ''));
$currentName   = (string)($user['full_name'] ?? ($_SESSION['full_name'] ?? 'Staff'));
$currentEmail  = (string)($user['email']   ?? ($_SESSION['email']   ?? ''));

$isManager = ims_allowed('procurement.manage', ['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer'], 'Review, approve and process all procurement requests');

/* -- Fetch procurement officers for email notification ---------------- */
$officers = [];
$res = $conn->query("SELECT full_name, email FROM users
    WHERE role IN ('Procurement Officer','Administrator','Operations/Admin')
    AND email IS NOT NULL AND email != ''
    ORDER BY role DESC, full_name ASC");
if ($res) while ($r = $res->fetch_assoc()) $officers[] = $r;

/* ----------------------------------------------------------------------
   ACTION HANDLERS
   ---------------------------------------------------------------------- */
handleProcurementActions($conn, $currentUserId, $isManager);

/* -- Create new request with multiple items --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_request_items') {

    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id   = !empty($_POST['category_id'])   ? (int)$_POST['category_id']   : null;
    $urgency       = $_POST['urgency'] ?? 'Medium';
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $needed_by     = trim($_POST['needed_by']   ?? '');
    $link_type     = $_POST['link_type']   ?? '';
    $link_project  = !empty($_POST['link_project_id']) ? (int)$_POST['link_project_id'] : null;
    $link_program  = !empty($_POST['link_program_id']) ? (int)$_POST['link_program_id'] : null;

    /* Resolve department name for email / legacy column */
    $dept = '';
    if ($department_id) {
        $dr = $conn->query("SELECT department_name FROM departments WHERE department_id = {$department_id} LIMIT 1")?->fetch_assoc();
        $dept = $dr['department_name'] ?? '';
    }

    /* Clear the unused link */
    if ($link_type !== 'project') $link_project = null;
    if ($link_type !== 'program') $link_program = null;

    /* Collect items */
    $item_names  = $_POST['item_name']  ?? [];
    $item_qtys   = $_POST['item_qty']   ?? [];
    $item_units  = $_POST['item_unit']  ?? [];
    $item_prices = $_POST['item_price'] ?? [];
    $item_specs  = $_POST['item_spec']  ?? [];

    /* Filter out blank item rows */
    $items = [];
    foreach ($item_names as $k => $name) {
        $name = trim($name);
        if ($name === '') continue;
        $items[] = [
            'name'  => $name,
            'qty'   => max(1, (int)($item_qtys[$k] ?? 1)),
            'unit'  => trim($item_units[$k]  ?? 'unit'),
            'price' => (float)($item_prices[$k] ?? 0),
            'spec'  => trim($item_specs[$k]  ?? ''),
        ];
    }

    if ($title === '' || empty($items)) {
        $_SESSION['error'] = 'Please provide a title and at least one item.';
        header('Location: manage_procurement');
        exit;
    }

    /* Estimated budget = sum of qty × unit price */
    $total_budget = array_sum(array_map(fn($i) => $i['qty'] * $i['price'], $items));

    /* Insert procurement request */
    $stmt = $conn->prepare("INSERT INTO procurement_requests
        (title, description, category_id, estimated_budget, urgency,
         department, department_id, needed_by, project_id, program_id, created_by, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', NOW())");
    $stmt->bind_param('ssidssisiis',
        $title, $description, $category_id, $total_budget,
        $urgency, $dept, $department_id, $needed_by,
        $link_project, $link_program, $currentUserId);
    $stmt->execute();
    $proc_id = $conn->insert_id;
    $stmt->close();

    /* Insert items */
    if ($proc_id > 0) {
        $stmt = $conn->prepare("INSERT INTO procurement_items
            (procurement_id, item_name, quantity, unit, estimated_unit_price, specifications)
            VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $stmt->bind_param('isisds',
                $proc_id, $item['name'], $item['qty'],
                $item['unit'], $item['price'], $item['spec']);
            $stmt->execute();
        }
        $stmt->close();

        /* -- Build email body -- */
        $ref        = 'PROC-' . str_pad((string)$proc_id, 5, '0', STR_PAD_LEFT);
        $items_html = '<table border="1" cellpadding="8" cellspacing="0" width="100%"
                             style="border-collapse:collapse;font-family:sans-serif;font-size:13px;">
            <thead style="background:#f3f4f6;">
                <tr>
                    <th style="text-align:left;">#</th>
                    <th style="text-align:left;">Item</th>
                    <th>Qty</th>
                    <th>Unit</th>
                    <th style="text-align:right;">Unit Price (UGX)</th>
                    <th style="text-align:right;">Total (UGX)</th>
                    <th style="text-align:left;">Specifications</th>
                </tr>
            </thead><tbody>';

        foreach ($items as $n => $item) {
            $line = $item['qty'] * $item['price'];
            $items_html .= '<tr>
                <td>' . ($n + 1) . '</td>
                <td>' . e($item['name']) . '</td>
                <td style="text-align:center;">' . $item['qty'] . '</td>
                <td>' . e($item['unit']) . '</td>
                <td style="text-align:right;">' . number_format($item['price'], 2) . '</td>
                <td style="text-align:right;">' . number_format($line, 2) . '</td>
                <td>' . e($item['spec']) . '</td>
            </tr>';
        }

        $items_html .= '<tr style="background:#f9fafb;font-weight:800;">
            <td colspan="5" style="text-align:right;">Estimated Total:</td>
            <td style="text-align:right;">UGX ' . number_format($total_budget, 2) . '</td>
            <td></td>
        </tr></tbody></table>';

        $email_body = '
<div style="font-family:sans-serif;max-width:680px;margin:0 auto;color:#374151;">
  <div style="background:#0f2942;color:#fff;padding:24px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:20px;">New Procurement Request</h2>
    <p style="margin:6px 0 0;opacity:.8;font-size:13px;">Reference: ' . $ref . '</p>
  </div>
  <div style="background:#ffffff;border:1px solid #e5e7eb;border-top:none;padding:24px 28px;border-radius:0 0 8px 8px;">
    <table style="width:100%;font-size:13px;margin-bottom:20px;">
      <tr><td style="color:#6b7280;padding:4px 0;width:140px;">Requested by</td><td><strong>' . e($currentName) . '</strong></td></tr>
      <tr><td style="color:#6b7280;padding:4px 0;">Title</td><td><strong>' . e($title) . '</strong></td></tr>
      <tr><td style="color:#6b7280;padding:4px 0;">Department</td><td>' . e($dept ?: 'N/A') . '</td></tr>
      <tr><td style="color:#6b7280;padding:4px 0;">Urgency</td><td><strong style="color:' . ($urgency==='High'?'#b91c1c':($urgency==='Medium'?'#b45309':'#374151')) . ';">' . e($urgency) . '</strong></td></tr>
      <tr><td style="color:#6b7280;padding:4px 0;">Needed By</td><td>' . e($needed_by ?: 'Not specified') . '</td></tr>
      <tr><td style="color:#6b7280;padding:4px 0;">Linked To</td><td>' . (function() use ($link_type,$link_project,$link_program,$conn){
          if ($link_type==='project'&&$link_project){$r=$conn->query("SELECT project_code,project_name FROM projects WHERE project_id={$link_project} LIMIT 1")?->fetch_assoc();return $r?'Project: '.htmlspecialchars($r['project_code'].' - '.$r['project_name'],ENT_QUOTES,'UTF-8'):'Project #'.$link_project;}
          if ($link_type==='program'&&$link_program){$r=$conn->query("SELECT program_code,program_name FROM programs WHERE id={$link_program} LIMIT 1")?->fetch_assoc();return $r?'Program: '.htmlspecialchars($r['program_code'].' - '.$r['program_name'],ENT_QUOTES,'UTF-8'):'Program #'.$link_program;}
          return 'N/A';})() . '</td></tr>
      <tr><td style="color:#6b7280;padding:4px 0;">Est. Budget</td><td><strong>UGX ' . number_format($total_budget, 2) . '</strong></td></tr>
    </table>
    ' . (!empty($description) ? '<p style="background:#f9fafb;border-left:4px solid #d1d5db;padding:10px 14px;border-radius:4px;font-size:13px;margin-bottom:20px;">' . nl2br(e($description)) . '</p>' : '') . '
    <h3 style="font-size:14px;margin:0 0 10px;color:#1e2530;">Requested Items</h3>
    ' . $items_html . '
    <div style="margin-top:24px;padding:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:13px;">
      Please log in to the IMS to review and process this request.
    </div>
  </div>
  <p style="font-size:11px;color:#9ca3af;text-align:center;margin-top:12px;">
    Sent by IMS Procurement Module &nbsp;·&nbsp; ' . date('d M Y H:i') . '
  </p>
</div>';

        /* Send to all procurement officers */
        $subject = "[Procurement] {$ref} - {$title} ({$urgency} Urgency)";
        foreach ($officers as $officer) {
            sendMail(
                $officer['email'],
                $officer['full_name'],
                $subject,
                $email_body
            );
        }

        /* Confirmation to requester */
        if ($currentEmail) {
            sendMail(
                $currentEmail,
                $currentName,
                "[Procurement] Your request {$ref} has been submitted",
                str_replace(
                    'Please log in to the IMS to review and process this request.',
                    'Your request has been submitted and is pending review by the Procurement team. Reference: <strong>' . $ref . '</strong>.',
                    $email_body
                )
            );
        }

        $_SESSION['success'] = "Request {$ref} submitted successfully. " . count($officers) . " officer(s) notified.";
    } else {
        $_SESSION['error'] = 'Failed to save the request. Please try again.';
    }

    header('Location: manage_procurement');
    exit;
}

/* -- Flash messages --------------------------------------------------- */
$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* -- Data ------------------------------------------------------------- */
$categories = getProcurementCategories($conn);
$requests   = getProcurementRequests($conn, $currentUserId, $isManager);

/* Projects dropdown */
$projects = [];
$res = $conn->query("SELECT project_id, project_code, project_name FROM projects ORDER BY project_name");
if ($res) while ($r = $res->fetch_assoc()) $projects[] = $r;

/* Programs dropdown */
$programs = [];
$res = $conn->query("SELECT id, program_code, program_name FROM programs ORDER BY program_name");
if ($res) while ($r = $res->fetch_assoc()) $programs[] = $r;

/* Departments dropdown */
$departments = [];
$res = $conn->query("SELECT department_id, department_name, department_code FROM departments WHERE is_active=1 ORDER BY sort_order, department_name");
if ($res) while ($r = $res->fetch_assoc()) $departments[] = $r;

/* Current user's department (pre-select if stored on user record) */
$userDeptId   = (int)($user['department_id'] ?? ($_SESSION['department_id'] ?? 0));
$userDeptName = (string)($user['department']   ?? ($_SESSION['department']   ?? ''));

/* Fetch items per request for expanded view */
$request_items = [];
if (!empty($requests)) {
    $ids = implode(',', array_map(fn($r) => (int)$r['procurement_id'], $requests));
    $res = $conn->query("SELECT * FROM procurement_items WHERE procurement_id IN ({$ids}) ORDER BY item_id");
    if ($res) while ($r = $res->fetch_assoc()) $request_items[(int)$r['procurement_id']][] = $r;
}

/* Fetch linked project/program names per request */
$request_links = [];
foreach ($requests as $req) {
    $pid = (int)$req['procurement_id'];
    $link_label = '';
    $link_icon  = '';
    if (!empty($req['project_id'])) {
        $r = $conn->query("SELECT project_code, project_name FROM projects WHERE project_id = " . (int)$req['project_id'] . " LIMIT 1")?->fetch_assoc();
        if ($r) { $link_label = $r['project_code'] . ' - ' . $r['project_name']; $link_icon = 'fa-project-diagram'; }
    } elseif (!empty($req['program_id'])) {
        $r = $conn->query("SELECT program_code, program_name FROM programs WHERE id = " . (int)$req['program_id'] . " LIMIT 1")?->fetch_assoc();
        if ($r) { $link_label = $r['program_code'] . ' - ' . $r['program_name']; $link_icon = 'fa-sitemap'; }
    }
    $request_links[$pid] = ['label' => $link_label, 'icon' => $link_icon];
}

/* Status ? badge class */
$status_badge = [
    'Draft'        => 'badge-returned',
    'Submitted'    => 'badge-pending',
    'Under Review' => 'badge-pending',
    'Approved'     => 'badge-issued',
    'Rejected'     => 'badge-rejected',
    'RFQ Created'  => 'badge-approved',
    'PO Created'   => 'badge-approved',
    'Completed'    => 'badge-issued',
];

/* Stats */
$stats = [
    'total'    => count($requests),
    'pending'  => count(array_filter($requests, fn($r) => in_array($r['status'],['Submitted','Under Review']))),
    'approved' => count(array_filter($requests, fn($r) => in_array($r['status'],['Approved','RFQ Created','PO Created']))),
    'done'     => count(array_filter($requests, fn($r) => $r['status']==='Completed')),
];
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <!-- -- Hero --------------------------------------------------------- -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-cart-shopping" style="margin-right:10px;opacity:.85;"></i>Procurement Requests</h1>
            <p><?= $isManager
                ? 'Review, approve and manage all procurement requests across the organisation.'
                : 'Submit multi-item procurement requests for review by the Procurement team.' ?>
            </p>
        </div>
        <div class="hero-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('requestModal')">
                <i class="fas fa-plus-circle"></i> New Request
            </button>
            <?php if ($isManager): ?>
                <a href="procurement_categories" class="btn btn-dark">
                    <i class="fas fa-tags"></i> Categories
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- -- Alerts ------------------------------------------------------- -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= e($success_msg) ?>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= e($error_msg) ?>
        </div>
    <?php endif; ?>

    <!-- -- Stats -------------------------------------------------------- -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Requests</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info">
                <div class="stat-label">Awaiting Review</div>
                <div class="stat-value"><?= $stats['pending'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Approved</div>
                <div class="stat-value"><?= $stats['approved'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-flag-checkered"></i></div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $stats['done'] ?></div>
            </div>
        </div>
    </div>

    <!-- -- Requests Panel ----------------------------------------------- -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-list-check" style="color:var(--brand-500);margin-right:8px;"></i>Procurement Requests</h3>
            <span class="note"><?= number_format(count($requests)) ?> request<?= count($requests)!==1?'s':'' ?></span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Title &amp; Items</th>
                        <th>Dept / Urgency</th>
                        <th>Est. Budget</th>
                        <th>Status</th>
                        <th>By</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:40px 16px;color:var(--ink-300);">
                                <i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4;"></i>
                                No procurement requests found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req):
                            $pid   = (int)$req['procurement_id'];
                            $ref   = 'PROC-' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT);
                            $bCls  = $status_badge[$req['status']] ?? 'badge-pending';
                            $isOwn = ((int)$req['created_by'] === $currentUserId);
                            $items = $request_items[$pid] ?? [];
                        ?>
                            <tr>
                                <!-- Ref -->
                                <td>
                                    <span class="mono" style="font-size:11px;"><?= $ref ?></span>
                                </td>

                                <!-- Title + items preview -->
                                <td style="max-width:280px;">
                                    <strong style="color:var(--ink-700);"><?= e($req['title']) ?></strong>
                                    <?php if (!empty($req['description'])): ?>
                                        <div class="note" style="margin-top:2px;"><?= e(mb_strimwidth($req['description'], 0, 80, '...')) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($items)): ?>
                                        <div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px;">
                                            <?php foreach (array_slice($items, 0, 4) as $it): ?>
                                                <span style="font-size:10px;background:var(--ink-50);border:1px solid var(--ink-100);border-radius:4px;padding:2px 7px;color:var(--ink-400);">
                                                    <?= e($it['item_name']) ?> ×<?= (int)$it['quantity'] ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($items) > 4): ?>
                                                <span style="font-size:10px;color:var(--ink-300);padding:2px 4px;">+<?= count($items)-4 ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Dept / Urgency / Link -->
                                <td>
                                    <?php if (!empty($req['department'])): ?>
                                        <div style="font-size:12px;font-weight:600;color:var(--ink-500);"><?= e($req['department']) ?></div>
                                    <?php endif; ?>
                                    <?php
                                    $uc = match($req['urgency'] ?? '') {
                                        'High'   => 'color:var(--red-fg);',
                                        'Medium' => 'color:var(--amber-fg);',
                                        default  => 'color:var(--slate-fg);',
                                    };
                                    ?>
                                    <span style="font-size:11px;font-weight:700;<?= $uc ?>">
                                        <?= e($req['urgency'] ?? '-') ?>
                                    </span>
                                    <?php if (!empty($request_links[$pid]['label'])): ?>
                                        <div style="margin-top:4px;display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;background:var(--blue-bg);color:var(--blue-fg);border:1px solid var(--blue-border);border-radius:999px;padding:2px 8px;white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis;" title="<?= e($request_links[$pid]['label']) ?>">
                                            <i class="fas <?= $request_links[$pid]['icon'] ?>"></i>
                                            <?= e(mb_strimwidth($request_links[$pid]['label'], 0, 28, '...')) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Budget -->
                                <td style="font-weight:700;white-space:nowrap;">
                                    UGX <?= number_format((float)$req['estimated_budget']) ?>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="badge <?= $bCls ?>"><?= e($req['status']) ?></span>
                                    <?php if ($req['status']==='Rejected' && !empty($req['rejection_reason'])): ?>
                                        <div class="note" style="margin-top:3px;max-width:160px;"><?= e($req['rejection_reason']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Creator -->
                                <td style="font-size:12px;color:var(--ink-400);"><?= e($req['creator_name'] ?? '-') ?></td>

                                <!-- Date -->
                                <td style="font-size:12px;white-space:nowrap;color:var(--ink-400);">
                                    <?= !empty($req['created_at']) ? date('d M Y', strtotime($req['created_at'])) : '-' ?>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div class="actions">

                                        <!-- Expand items -->
                                        <?php if (!empty($items)): ?>
                                            <button type="button" class="btn btn-sm btn-gray"
                                                    onclick="toggleItems('items-<?= $pid ?>')" title="View items">
                                                <i class="fas fa-boxes-stacked"></i> <?= count($items) ?>
                                            </button>
                                        <?php endif; ?>

                                        <!-- TOR -->
                                        <a class="btn btn-sm btn-gray" href="manage_tor?procurement_id=<?= $pid ?>">
                                            <i class="fas fa-file-alt"></i>
                                        </a>

                                        <!-- Submit -->
                                        <?php if ($isOwn && in_array($req['status'],['Draft','Rejected'],true)): ?>
                                            <form method="POST" style="display:contents;">
                                                <input type="hidden" name="action" value="submit_request">
                                                <input type="hidden" name="procurement_id" value="<?= $pid ?>">
                                                <button class="btn btn-sm btn-green" title="Submit for review">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Approve / Reject -->
                                        <?php if ($isManager && in_array($req['status'],['Submitted','Under Review'],true)): ?>
                                            <form method="POST" style="display:contents;">
                                                <input type="hidden" name="action" value="approve_request">
                                                <input type="hidden" name="procurement_id" value="<?= $pid ?>">
                                                <button class="btn btn-sm btn-green" title="Approve">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-red" title="Reject"
                                                    onclick='openRejectModal(<?= json_encode(['procurement_id'=>$pid,'title'=>$req['title']], JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                                <i class="fas fa-times-circle"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- RFQ -->
                                        <?php if ($isManager && in_array($req['status'],['Approved','RFQ Created','PO Created'],true)): ?>
                                            <a class="btn btn-sm btn-soft" href="manage_rfq?procurement_id=<?= $pid ?>">
                                                <i class="fas fa-file-signature"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Suppliers / Quotations -->
                                        <?php if ($isManager && !empty($req['rfq_id'])): ?>
                                            <a class="btn btn-sm btn-dark" href="manage_suppliers?rfq_id=<?= (int)$req['rfq_id'] ?>" title="Suppliers">
                                                <i class="fas fa-users"></i>
                                            </a>
                                            <a class="btn btn-sm btn-gray" href="manage_quotations?rfq_id=<?= (int)$req['rfq_id'] ?>" title="Quotations">
                                                <i class="fas fa-scale-balanced"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Delivery -->
                                        <?php if ($isManager && !empty($req['po_id'])): ?>
                                            <a class="btn btn-sm btn-green" href="manage_delivery?po_id=<?= (int)$req['po_id'] ?>" title="Delivery">
                                                <i class="fas fa-truck"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Completed badge -->
                                        <?php if ($req['status']==='Completed'): ?>
                                            <span class="badge badge-issued"><i class="fas fa-lock"></i> Closed</span>
                                        <?php endif; ?>

                                        <!-- Delete -->
                                        <?php if (($isManager && in_array($req['status'],['Draft','Submitted','Rejected'],true))
                                               || ($isOwn   && in_array($req['status'],['Draft','Rejected'],true))): ?>
                                            <form method="POST" style="display:contents;"
                                                  onsubmit="return confirm('Delete this request?');">
                                                <input type="hidden" name="action" value="delete_request">
                                                <input type="hidden" name="procurement_id" value="<?= $pid ?>">
                                                <button class="btn btn-sm btn-red" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>

                            <!-- -- Expandable items sub-row -- -->
                            <?php if (!empty($items)): ?>
                                <tr id="items-<?= $pid ?>" style="display:none;background:var(--ink-50);">
                                    <td colspan="8" style="padding:0;">
                                        <div style="padding:14px 20px;">
                                            <div style="font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-300);margin-bottom:10px;">
                                                Items for <?= $ref ?>
                                            </div>
                                            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                                                <thead>
                                                    <tr style="background:var(--ink-100);">
                                                        <th style="padding:7px 10px;text-align:left;font-weight:700;color:var(--ink-400);font-size:10px;text-transform:uppercase;">#</th>
                                                        <th style="padding:7px 10px;text-align:left;font-weight:700;color:var(--ink-400);font-size:10px;text-transform:uppercase;">Item Name</th>
                                                        <th style="padding:7px 10px;text-align:center;font-weight:700;color:var(--ink-400);font-size:10px;text-transform:uppercase;">Qty</th>
                                                        <th style="padding:7px 10px;text-align:left;font-weight:700;color:var(--ink-400);font-size:10px;text-transform:uppercase;">Unit</th>
                                                        <th style="padding:7px 10px;text-align:right;font-weight:700;color:var(--ink-400);font-size:10px;text-transform:uppercase;">Unit Price</th>
                                                        <th style="padding:7px 10px;text-align:right;font-weight:700;color:var(--ink-400);font-size:10px;text-transform:uppercase;">Line Total</th>
                                                        <th style="padding:7px 10px;text-align:left;font-weight:700;color:var(--ink-400);font-size:10px;text-transform:uppercase;">Specifications</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($items as $n => $it):
                                                        $line = (float)$it['quantity'] * (float)$it['estimated_unit_price'];
                                                    ?>
                                                        <tr style="border-bottom:1px solid var(--ink-100);">
                                                            <td style="padding:7px 10px;color:var(--ink-400);"><?= $n+1 ?></td>
                                                            <td style="padding:7px 10px;font-weight:700;color:var(--ink-700);"><?= e($it['item_name']) ?></td>
                                                            <td style="padding:7px 10px;text-align:center;"><?= (int)$it['quantity'] ?></td>
                                                            <td style="padding:7px 10px;"><?= e($it['unit']) ?></td>
                                                            <td style="padding:7px 10px;text-align:right;"><?= number_format((float)$it['estimated_unit_price'], 2) ?></td>
                                                            <td style="padding:7px 10px;text-align:right;font-weight:700;"><?= number_format($line, 2) ?></td>
                                                            <td style="padding:7px 10px;color:var(--ink-400);"><?= e($it['specifications'] ?? '-') ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr style="background:var(--surface-card);font-weight:800;">
                                                        <td colspan="5" style="padding:8px 10px;text-align:right;color:var(--ink-500);">Estimated Total:</td>
                                                        <td style="padding:8px 10px;text-align:right;color:var(--ink-700);">
                                                            UGX <?= number_format((float)$req['estimated_budget'], 2) ?>
                                                        </td>
                                                        <td></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.assets-wrap -->


<!-- ------------------------------------------------------------------
     NEW REQUEST MODAL - multi-item form
     ------------------------------------------------------------------ -->
<div class="modal" id="requestModal">
    <div class="modal-card" style="max-width:860px;">
        <div class="modal-head">
            <h3><i class="fas fa-plus-circle" style="color:var(--brand-500);margin-right:8px;"></i>New Procurement Request</h3>
            <button type="button" class="modal-close" onclick="closeModal('requestModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="requestForm">
                <input type="hidden" name="action" value="create_request_items">

                <!-- Section: Request details -->
                <div style="font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-300);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--ink-50);">
                    Request Details
                </div>

                <div class="form-grid" style="margin-bottom:14px;">
                    <div class="form-group full">
                        <label class="form-label">Title <span style="color:var(--red-fg);">*</span></label>
                        <input type="text" name="title" class="form-control"
                               placeholder="e.g. Office Supplies for Q2 2026" required>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Description / Justification</label>
                        <textarea name="description" class="form-control" style="min-height:72px;"
                                  placeholder="Why is this procurement needed?"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">Select category </option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['category_id'] ?>"><?= e($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department / Team</label>
                        <select name="department_id" class="form-control">
                            <option value="">Select Department </option>
                            <?php foreach ($departments as $dept_opt):
                                $sel = ($userDeptId && $userDeptId === (int)$dept_opt['department_id']) ? 'selected' : '';
                                // Also match by name if no ID stored
                                if (!$sel && $userDeptName && stripos($dept_opt['department_name'], $userDeptName) !== false) $sel = 'selected';
                            ?>
                                <option value="<?= (int)$dept_opt['department_id'] ?>" <?= $sel ?>>
                                    <?= e($dept_opt['department_name']) ?>
                                    <?php if ($dept_opt['department_code']): ?>
                                        (<?= e($dept_opt['department_code']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Needed By (Date)</label>
                        <input type="date" name="needed_by" class="form-control"
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Urgency</label>
                        <select name="urgency" class="form-control">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                        </select>
                    </div>

                    <!-- Link to project or program -->
                    <div class="form-group full">
                        <label class="form-label">Link to Project / Program <span style="color:var(--ink-300);font-weight:400;text-transform:none;letter-spacing:0;">- optional</span></label>
                        <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;align-items:start;">
                            <select name="link_type" id="link_type" class="form-control" onchange="toggleLinkSelect()">
                                <option value=""> None </option>
                                <option value="project">Project</option>
                                <?php if (!empty($programs)): ?>
                                    <option value="program">Program</option>
                                <?php endif; ?>
                            </select>

                            <!-- Project picker -->
                            <div id="link_project_wrap" style="display:none;">
                                <select name="link_project_id" id="link_project_id" class="form-control">
                                    <option value="">Select Project </option>
                                    <?php foreach ($projects as $pr): ?>
                                        <option value="<?= (int)$pr['project_id'] ?>">
                                            <?= e($pr['project_code'] . ' - ' . $pr['project_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Program picker -->
                            <div id="link_program_wrap" style="display:none;">
                                <select name="link_program_id" id="link_program_id" class="form-control">
                                    <option value="">Select Program </option>
                                    <?php foreach ($programs as $pg): ?>
                                        <option value="<?= (int)$pg['id'] ?>">
                                            <?= e($pg['program_code'] . ' - ' . $pg['program_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="link_none_wrap" style="padding:10px 0;">
                                <span class="note">No project or program link.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section: Items -->
                <div style="font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-300);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--ink-50);">
                    Requested Items <span style="color:var(--red-fg);">*</span>
                </div>

                <div id="items-container">
                    <!-- Item row template (first row, not removable) -->
                    <div class="item-row" style="display:grid;grid-template-columns:3fr 1fr 1fr 2fr 3fr auto;gap:8px;align-items:end;margin-bottom:10px;background:var(--ink-50);padding:12px;border-radius:var(--radius-lg);border:1px solid var(--ink-100);">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Item Name *</label>
                            <input type="text" name="item_name[]" class="form-control" required placeholder="e.g. A4 Printing Paper">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Qty *</label>
                            <input type="number" name="item_qty[]" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Unit</label>
                            <input type="text" name="item_unit[]" class="form-control" value="unit" placeholder="pcs, box...">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Est. Unit Price (UGX)</label>
                            <input type="number" name="item_price[]" class="form-control" min="0" step="0.01" value="0"
                                   oninput="calcTotal()">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Specifications / Notes</label>
                            <input type="text" name="item_spec[]" class="form-control" placeholder="Brand, colour, size...">
                        </div>
                        <div style="padding-bottom:2px;">
                            <button type="button" class="btn btn-sm" style="background:var(--ink-100);color:var(--ink-300);cursor:default;" disabled title="First row cannot be removed">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Add item button + estimated total -->
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:6px;">
                    <button type="button" class="btn btn-sm btn-gray" onclick="addItemRow()">
                        <i class="fas fa-plus"></i> Add Another Item
                    </button>
                    <div style="font-size:14px;font-weight:800;color:var(--ink-700);">
                        Estimated Total: <span id="estimated-total" style="color:var(--brand-600);">UGX 0</span>
                    </div>
                </div>

                <div class="modal-actions" style="margin-top:20px;">
                    <button type="button" class="btn btn-gray" onclick="closeModal('requestModal')">Cancel</button>
                    <button type="submit" class="btn btn-dark">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ------------------------------------------------------------------
     REJECT MODAL
     ------------------------------------------------------------------ -->
<div class="modal" id="rejectModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3><i class="fas fa-times-circle" style="color:var(--red-fg);margin-right:8px;"></i>Reject Request</h3>
            <button type="button" class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="reject_request">
                <input type="hidden" name="procurement_id" id="reject_procurement_id">

                <p style="font-size:13px;color:var(--ink-400);margin-bottom:14px;">
                    Rejecting: <strong id="reject_title" style="color:var(--ink-700);"></strong>
                </p>

                <div class="form-group">
                    <label class="form-label">Reason for Rejection <span style="color:var(--red-fg);">*</span></label>
                    <textarea name="rejection_reason" class="form-control" required
                              placeholder="Explain why this request is being rejected..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-red">
                        <i class="fas fa-times-circle"></i> Confirm Reject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* -- Link type toggle -- */
function toggleLinkSelect() {
    const val  = document.getElementById('link_type').value;
    const proj = document.getElementById('link_project_wrap');
    const prog = document.getElementById('link_program_wrap');
    const none = document.getElementById('link_none_wrap');

    proj.style.display = val === 'project' ? 'block' : 'none';
    prog.style.display = val === 'program' ? 'block' : 'none';
    none.style.display = val === ''        ? 'block' : 'none';

    /* Clear the hidden one */
    if (val !== 'project') document.getElementById('link_project_id').value = '';
    if (val !== 'program') document.getElementById('link_program_id').value = '';
}

/* -- Modal helpers -- */
function openModal(id)  { document.getElementById(id)?.classList.add('show');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); document.body.style.overflow=''; }

window.addEventListener('click', function(e) {
    document.querySelectorAll('.modal.show').forEach(function(m) {
        if (e.target === m) { m.classList.remove('show'); document.body.style.overflow=''; }
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal.show').forEach(function(m) { m.classList.remove('show'); document.body.style.overflow=''; });
});

/* -- Reject modal -- */
function openRejectModal(data) {
    document.getElementById('reject_procurement_id').value = data.procurement_id || '';
    document.getElementById('reject_title').textContent    = data.title || '';
    openModal('rejectModal');
}

/* -- Expand / collapse items sub-row -- */
function toggleItems(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}

/* -- Add item row -- */
function addItemRow() {
    const container = document.getElementById('items-container');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.style.cssText = 'display:grid;grid-template-columns:3fr 1fr 1fr 2fr 3fr auto;gap:8px;align-items:end;margin-bottom:10px;background:var(--ink-50);padding:12px;border-radius:16px;border:1px solid var(--ink-100);';
    row.innerHTML = `
        <div class="form-group" style="margin:0;">
            <label class="form-label">Item Name *</label>
            <input type="text" name="item_name[]" class="form-control" required placeholder="e.g. Whiteboard Markers">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Qty *</label>
            <input type="number" name="item_qty[]" class="form-control" min="1" value="1" required>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Unit</label>
            <input type="text" name="item_unit[]" class="form-control" value="unit" placeholder="pcs, box...">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Est. Unit Price (UGX)</label>
            <input type="number" name="item_price[]" class="form-control" min="0" step="0.01" value="0" oninput="calcTotal()">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Specifications / Notes</label>
            <input type="text" name="item_spec[]" class="form-control" placeholder="Brand, colour, size...">
        </div>
        <div style="padding-bottom:2px;">
            <button type="button" class="btn btn-sm btn-red" onclick="removeRow(this)" title="Remove item">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    `;
    container.appendChild(row);
    row.querySelector('input[name="item_name[]"]').focus();
}

function removeRow(btn) {
    btn.closest('.item-row')?.remove();
    calcTotal();
}

/* -- Calculate estimated total -- */
function calcTotal() {
    let total = 0;
    document.querySelectorAll('#items-container .item-row').forEach(function(row) {
        const qty   = parseFloat(row.querySelector('input[name="item_qty[]"]')?.value  || 0);
        const price = parseFloat(row.querySelector('input[name="item_price[]"]')?.value || 0);
        total += qty * price;
    });
    document.getElementById('estimated-total').textContent =
        'UGX ' + total.toLocaleString('en-UG', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

/* Recalculate when qty changes too */
document.addEventListener('input', function(e) {
    if (e.target.name === 'item_qty[]') calcTotal();
});
</script>

<?php
ob_end_flush();
require_once 'includes/footer.php';
?>