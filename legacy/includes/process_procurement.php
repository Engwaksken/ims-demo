<?php
declare(strict_types=1);

/**
 * includes/process_procurement.php
 *
 * Backend handler for all procurement actions.
 * Called from manage-procurement.php.
 *
 * Email notifications use sendMail() from mail-function.php.
 * mail-function.php must be included by the calling page BEFORE this file.
 */

/* -- Helpers ---------------------------------------------------------- */

function procurement_redirect(string $page = 'manage_procurement'): never
{
    header("Location: {$page}");
    exit;
}

function procurement_flash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

/** Thin wrapper so calls stay short */
function h_val(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ----------------------------------------------------------------------
   EMAIL BUILDERS
   All return a complete HTML string ready to pass to sendMail().
   ---------------------------------------------------------------------- */

function _email_wrap(string $header_color, string $header_html, string $body_html): string
{
    return '
<div style="font-family:\'Helvetica Neue\',Arial,sans-serif;max-width:680px;margin:0 auto;color:#374151;">
  <div style="background:' . $header_color . ';color:#fff;padding:22px 28px;border-radius:8px 8px 0 0;">
    ' . $header_html . '
  </div>
  <div style="background:#ffffff;border:1px solid #e5e7eb;border-top:none;padding:24px 28px;border-radius:0 0 8px 8px;">
    ' . $body_html . '
  </div>
  <p style="font-size:11px;color:#9ca3af;text-align:center;margin-top:14px;">
    IMS Procurement Module &nbsp;·&nbsp; ' . date('d M Y H:i') . '
  </p>
</div>';
}

function _meta_table(array $rows): string
{
    $html = '<table style="width:100%;font-size:13px;border-collapse:collapse;margin-bottom:18px;">';
    foreach ($rows as [$label, $value, $style]) {
        $html .= '<tr>
            <td style="padding:5px 0;color:#6b7280;width:160px;vertical-align:top;">' . h_val($label) . '</td>
            <td style="padding:5px 0;' . ($style ?? '') . '">' . $value . '</td>
        </tr>';
    }
    return $html . '</table>';
}

function _items_table(array $items): string
{
    if (empty($items)) return '';
    $html = '
<h3 style="font-size:14px;margin:0 0 10px;color:#1e2530;">Requested Items</h3>
<table border="1" cellpadding="7" cellspacing="0" width="100%"
       style="border-collapse:collapse;font-size:12px;border-color:#e5e7eb;">
  <thead style="background:#f3f4f6;">
    <tr>
      <th style="text-align:left;padding:7px 10px;">#</th>
      <th style="text-align:left;padding:7px 10px;">Item</th>
      <th style="text-align:center;padding:7px 10px;">Qty</th>
      <th style="text-align:left;padding:7px 10px;">Unit</th>
      <th style="text-align:right;padding:7px 10px;">Unit Price (UGX)</th>
      <th style="text-align:right;padding:7px 10px;">Line Total (UGX)</th>
      <th style="text-align:left;padding:7px 10px;">Specifications</th>
    </tr>
  </thead>
  <tbody>';

    $grand = 0.0;
    foreach ($items as $n => $item) {
        $line  = (float)$item['quantity'] * (float)$item['estimated_unit_price'];
        $grand += $line;
        $html .= '<tr>
          <td style="padding:6px 10px;">' . ($n + 1) . '</td>
          <td style="padding:6px 10px;font-weight:600;">' . h_val($item['item_name']) . '</td>
          <td style="padding:6px 10px;text-align:center;">' . (int)$item['quantity'] . '</td>
          <td style="padding:6px 10px;">' . h_val($item['unit'] ?? 'unit') . '</td>
          <td style="padding:6px 10px;text-align:right;">' . number_format((float)$item['estimated_unit_price'], 2) . '</td>
          <td style="padding:6px 10px;text-align:right;font-weight:700;">' . number_format($line, 2) . '</td>
          <td style="padding:6px 10px;color:#6b7280;">' . h_val($item['specifications'] ?? '—') . '</td>
        </tr>';
    }

    $html .= '<tr style="background:#f9fafb;font-weight:800;">
        <td colspan="5" style="padding:8px 10px;text-align:right;color:#374151;">Estimated Total:</td>
        <td style="padding:8px 10px;text-align:right;color:#1e2530;">UGX ' . number_format($grand, 2) . '</td>
        <td></td>
      </tr>
    </tbody>
  </table>';

    return $html;
}

/* -- Build a rich request-summary email ------------------------------- */
function buildRequestEmail(
    mysqli $conn,
    array  $req,
    string $event,          // 'Submitted' | 'Approved' | 'Rejected'
    string $extra_html = '' // optional extra block (e.g. rejection reason)
): string {
    $ref = 'PROC-' . str_pad((string)$req['procurement_id'], 5, '0', STR_PAD_LEFT);

    $colors = [
        'Submitted' => '#0f2942',
        'Approved'  => '#15803d',
        'Rejected'  => '#b91c1c',
    ];
    $icons = [
        'Submitted' => '??',
        'Approved'  => '?',
        'Rejected'  => '?',
    ];
    $header_color = $colors[$event] ?? '#374151';

    $header_html = '
        <h2 style="margin:0 0 4px;font-size:20px;">
            ' . ($icons[$event] ?? '') . ' Procurement Request ' . h_val($event) . '
        </h2>
        <p style="margin:0;opacity:.8;font-size:13px;">Reference: ' . h_val($ref) . '</p>';

    /* Urgency colour */
    $urg_color = match($req['urgency'] ?? '') {
        'High'   => '#b91c1c',
        'Medium' => '#b45309',
        default  => '#374151',
    };

    /* Linked project / program */
    $linked = 'N/A';
    if (!empty($req['project_id'])) {
        $r = $conn->query("SELECT project_code, project_name FROM projects WHERE project_id=" . (int)$req['project_id'] . " LIMIT 1")?->fetch_assoc();
        if ($r) $linked = 'Project: ' . h_val($r['project_code'] . ' — ' . $r['project_name']);
    } elseif (!empty($req['program_id'])) {
        $r = $conn->query("SELECT program_code, program_name FROM programs WHERE id=" . (int)$req['program_id'] . " LIMIT 1")?->fetch_assoc();
        if ($r) $linked = 'Program: ' . h_val($r['program_code'] . ' — ' . $r['program_name']);
    }

    $meta = _meta_table([
        ['Reference',   '<strong>' . h_val($ref) . '</strong>',                                null],
        ['Title',       '<strong>' . h_val($req['title']) . '</strong>',                       null],
        ['Requested By',h_val($req['creator_name'] ?? '—'),                                   null],
        ['Department',  h_val($req['department'] ?? '—'),                                      null],
        ['Category',    h_val($req['category_name'] ?? '—'),                                   null],
        ['Urgency',     '<strong style="color:' . $urg_color . ';">' . h_val($req['urgency'] ?? '—') . '</strong>', null],
        ['Needed By',   h_val(!empty($req['needed_by']) ? date('d M Y', strtotime($req['needed_by'])) : 'Not specified'), null],
        ['Linked To',   $linked,                                                               null],
        ['Est. Budget', '<strong>UGX ' . number_format((float)$req['estimated_budget'], 2) . '</strong>', null],
        ['Status',      '<strong style="color:' . $header_color . ';">' . h_val($event) . '</strong>', null],
    ]);

    /* Fetch items */
    $items = [];
    $res = $conn->query("SELECT * FROM procurement_items WHERE procurement_id=" . (int)$req['procurement_id'] . " ORDER BY item_id");
    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

    $desc_block = !empty($req['description'])
        ? '<p style="background:#f9fafb;border-left:4px solid #d1d5db;padding:10px 14px;border-radius:4px;font-size:13px;margin-bottom:20px;">'
          . nl2br(h_val($req['description'])) . '</p>'
        : '';

    $cta = '<div style="margin-top:22px;padding:14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:13px;">
        Please log in to the IMS to review this request.
    </div>';

    $body_html = $meta . $desc_block . _items_table($items) . $extra_html . $cta;

    return _email_wrap($header_color, $header_html, $body_html);
}

/* -- Fetch procurement officers (used by multiple actions) ------------- */
function getNotificationRecipients(mysqli $conn): array
{
    $recipients = [];
    $res = $conn->query("SELECT full_name, email FROM users
        WHERE role IN ('Procurement Officer','Administrator','Operations/Admin')
          AND email IS NOT NULL AND email != ''
        ORDER BY role DESC, full_name ASC");
    if ($res) while ($r = $res->fetch_assoc()) $recipients[] = $r;
    return $recipients;
}

/* -- Fetch full request row with creator + category names -------------- */
function fetchFullRequest(mysqli $conn, int $procurement_id): ?array
{
    $stmt = $conn->prepare("
        SELECT pr.*,
            pc.category_name,
            u.full_name  AS creator_name,
            u.email      AS creator_email,
            au.full_name AS approver_name,
            au.email     AS approver_email
        FROM procurement_requests pr
        LEFT JOIN procurement_categories pc ON pc.category_id  = pr.category_id
        LEFT JOIN users u                   ON u.user_id       = pr.created_by
        LEFT JOIN users au                  ON au.user_id      = pr.approved_by
        WHERE pr.procurement_id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('i', $procurement_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* ----------------------------------------------------------------------
   MAIN DISPATCHER
   ---------------------------------------------------------------------- */

function handleProcurementActions(mysqli $conn, int $userId, bool $isManager): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $action = trim((string)($_POST['action'] ?? ''));

    match ($action) {
        'create_request'  => createProcurementRequest($conn, $userId),
        'submit_request'  => submitProcurementRequest($conn, $userId),
        'approve_request' => approveProcurementRequest($conn, $userId, $isManager),
        'reject_request'  => rejectProcurementRequest($conn, $userId, $isManager),
        'delete_request'  => deleteProcurementRequest($conn, $userId, $isManager),
        default           => null,
    };
}

/* ----------------------------------------------------------------------
   ACTION FUNCTIONS
   ---------------------------------------------------------------------- */

/**
 * Create a bare-minimum Draft request (legacy simple form).
 * Multi-item requests use the inline handler in manage-procurement.php.
 */
function createProcurementRequest(mysqli $conn, int $userId): never
{
    $title            = trim((string)($_POST['title']             ?? ''));
    $description      = trim((string)($_POST['description']       ?? ''));
    $category_id      = (int)($_POST['category_id']              ?? 0);
    $estimated_budget = (float)($_POST['estimated_budget']        ?? 0);
    $urgency          = trim((string)($_POST['urgency']           ?? 'Medium'));
    $department_id    = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $needed_by        = trim((string)($_POST['needed_by']         ?? '')) ?: null;
    $link_project     = !empty($_POST['link_project_id']) ? (int)$_POST['link_project_id'] : null;
    $link_program     = !empty($_POST['link_program_id']) ? (int)$_POST['link_program_id'] : null;

    if ($title === '') {
        procurement_flash('error', 'Procurement title is required.');
        procurement_redirect();
    }

    if (!in_array($urgency, ['Low','Medium','High'], true)) $urgency = 'Medium';
    $link_type = $_POST['link_type'] ?? '';
    if ($link_type !== 'project') $link_project = null;
    if ($link_type !== 'program') $link_program = null;

    /* Resolve department name */
    $dept_name = null;
    if ($department_id) {
        $dr = $conn->query("SELECT department_name FROM departments WHERE department_id={$department_id} LIMIT 1")?->fetch_assoc();
        $dept_name = $dr['department_name'] ?? null;
    }

    $cat_param = $category_id > 0 ? $category_id : null;

    $stmt = $conn->prepare("
        INSERT INTO procurement_requests
            (title, description, category_id, estimated_budget, urgency,
             department, department_id, needed_by, project_id, program_id,
             status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?, NOW())
    ");

    if (!$stmt) {
        procurement_flash('error', 'DB error: ' . $conn->error);
        procurement_redirect();
    }

    $stmt->bind_param('ssidsssiiii',
        $title, $description, $cat_param, $estimated_budget, $urgency,
        $dept_name, $department_id, $needed_by,
        $link_project, $link_program, $userId);

    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    procurement_flash($ok ? 'success' : 'error',
        $ok ? 'Procurement request saved as draft.' : "Failed to save: {$err}");
    procurement_redirect();
}

/* -- Submit (Draft/Rejected ? Submitted) ------------------------------- */
function submitProcurementRequest(mysqli $conn, int $userId): never
{
    $procurement_id = (int)($_POST['procurement_id'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE procurement_requests
        SET    status = 'Submitted', updated_at = NOW()
        WHERE  procurement_id = ?
          AND  created_by = ?
          AND  status IN ('Draft','Rejected')
        LIMIT  1
    ");
    $stmt->bind_param('ii', $procurement_id, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        /* -- Email: notify officers -- */
        $req = fetchFullRequest($conn, $procurement_id);
        if ($req) {
            $subject   = '[Procurement] ' . 'PROC-' . str_pad((string)$procurement_id, 5, '0', STR_PAD_LEFT)
                       . ' — ' . $req['title'] . ' (' . ($req['urgency'] ?? 'Medium') . ' Urgency)';
            $body      = buildRequestEmail($conn, $req, 'Submitted');
            $officers  = getNotificationRecipients($conn);

            foreach ($officers as $o) {
                sendMail($o['email'], $o['full_name'], $subject, $body);
            }

            /* Confirmation to requester */
            if (!empty($req['creator_email'])) {
                $confirm_body = buildRequestEmail($conn, $req, 'Submitted',
                    '<div style="margin-top:14px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:13px;">
                        Your request has been submitted and is awaiting review by the Procurement team.
                    </div>');
                sendMail($req['creator_email'], $req['creator_name'] ?? '',
                    '[Procurement] Your request has been submitted', $confirm_body);
            }
        }

        procurement_flash('success', 'Request submitted. Procurement team notified.');
    } else {
        procurement_flash('error', 'Only your draft or rejected requests can be submitted.');
    }

    procurement_redirect();
}

/* -- Approve ----------------------------------------------------------- */
function approveProcurementRequest(mysqli $conn, int $userId, bool $isManager): never
{
    if (!$isManager) {
        procurement_flash('error', 'Access denied.');
        procurement_redirect();
    }

    $procurement_id = (int)($_POST['procurement_id'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE procurement_requests
        SET    status           = 'Approved',
               approved_by     = ?,
               approved_at     = NOW(),
               rejection_reason = NULL,
               updated_at      = NOW()
        WHERE  procurement_id  = ?
          AND  status IN ('Submitted','Under Review')
          AND  created_by <> ?
        LIMIT  1
    ");
    // Segregation of duties: nobody approves their own request.
    $stmt->bind_param('iii', $userId, $procurement_id, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        $req = fetchFullRequest($conn, $procurement_id);
        if ($req && !empty($req['creator_email'])) {
            $ref     = 'PROC-' . str_pad((string)$procurement_id, 5, '0', STR_PAD_LEFT);
            $subject = "[Procurement] {$ref} Approved — " . $req['title'];
            $body    = buildRequestEmail($conn, $req, 'Approved',
                '<div style="margin-top:14px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:13px;">
                    ?? Your procurement request has been <strong>approved</strong>. The Procurement team will now proceed with sourcing.
                </div>');
            sendMail($req['creator_email'], $req['creator_name'] ?? '', $subject, $body);
        }
        procurement_flash('success', 'Request approved. Requester has been notified.');
    } else {
        procurement_flash('error', 'Request could not be approved (wrong status, not found, or it is your own request).');
    }

    procurement_redirect();
}

/* -- Reject ------------------------------------------------------------ */
function rejectProcurementRequest(mysqli $conn, int $userId, bool $isManager): never
{
    if (!$isManager) {
        procurement_flash('error', 'Access denied.');
        procurement_redirect();
    }

    $procurement_id = (int)($_POST['procurement_id'] ?? 0);
    $reason         = trim((string)($_POST['rejection_reason'] ?? ''));

    if ($reason === '') {
        procurement_flash('error', 'A rejection reason is required.');
        procurement_redirect();
    }

    $stmt = $conn->prepare("
        UPDATE procurement_requests
        SET    status            = 'Rejected',
               approved_by      = ?,
               approved_at      = NOW(),
               rejection_reason = ?,
               updated_at       = NOW()
        WHERE  procurement_id   = ?
          AND  status IN ('Submitted','Under Review')
        LIMIT  1
    ");
    $stmt->bind_param('isi', $userId, $reason, $procurement_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        $req = fetchFullRequest($conn, $procurement_id);
        if ($req && !empty($req['creator_email'])) {
            $ref     = 'PROC-' . str_pad((string)$procurement_id, 5, '0', STR_PAD_LEFT);
            $subject = "[Procurement] {$ref} Rejected — " . $req['title'];
            $reason_block = '<div style="margin-top:14px;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #b91c1c;border-radius:6px;font-size:13px;color:#7f1d1d;">
                <strong>Reason for rejection:</strong><br><br>'
                . nl2br(h_val($reason)) .
            '</div>
            <div style="margin-top:12px;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;">
                You may revise and resubmit your request after addressing the reason above.
            </div>';
            $body = buildRequestEmail($conn, $req, 'Rejected', $reason_block);
            sendMail($req['creator_email'], $req['creator_name'] ?? '', $subject, $body);
        }
        procurement_flash('success', 'Request rejected. Requester has been notified.');
    } else {
        procurement_flash('error', 'Request could not be rejected (wrong status or not found).');
    }

    procurement_redirect();
}

/* -- Delete ------------------------------------------------------------ */
function deleteProcurementRequest(mysqli $conn, int $userId, bool $isManager): never
{
    $procurement_id = (int)($_POST['procurement_id'] ?? 0);

    if ($isManager) {
        $stmt = $conn->prepare("DELETE FROM procurement_requests WHERE procurement_id = ? LIMIT 1");
        $stmt->bind_param('i', $procurement_id);
    } else {
        $stmt = $conn->prepare("
            DELETE FROM procurement_requests
            WHERE procurement_id = ?
              AND created_by     = ?
              AND status IN ('Draft','Rejected')
            LIMIT 1
        ");
        $stmt->bind_param('ii', $procurement_id, $userId);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    /* CASCADE DELETE on procurement_items handles child rows automatically */
    procurement_flash(
        $affected > 0 ? 'success' : 'error',
        $affected > 0 ? 'Request deleted successfully.' : 'Request could not be deleted (check permissions or status).'
    );

    procurement_redirect();
}

/* ----------------------------------------------------------------------
   DATA FETCH HELPERS  (used by manage-procurement.php)
   ---------------------------------------------------------------------- */

function getProcurementCategories(mysqli $conn): array
{
    $rows = [];
    $res = $conn->query("SELECT category_id, category_name FROM procurement_categories ORDER BY category_name ASC");
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function getProcurementRequests(mysqli $conn, int $userId, bool $isManager): array
{
    $sql = "
        SELECT
            pr.*,
            pc.category_name,
            u.full_name   AS creator_name,
            u.email       AS creator_email,
            au.full_name  AS approver_name,
            r.rfq_id,
            r.status      AS rfq_status,
            po.po_id,
            po.status     AS po_status
        FROM procurement_requests pr
        LEFT JOIN procurement_categories pc ON pc.category_id = pr.category_id
        LEFT JOIN users u                   ON u.user_id      = pr.created_by
        LEFT JOIN users au                  ON au.user_id     = pr.approved_by
        LEFT JOIN rfq r                     ON r.procurement_id  = pr.procurement_id
        LEFT JOIN purchase_orders po        ON po.procurement_id = pr.procurement_id
    ";

    if ($isManager) {
        $res = $conn->query($sql . " ORDER BY pr.created_at DESC");
    } else {
        $stmt = $conn->prepare($sql . " WHERE pr.created_by = ? ORDER BY pr.created_at DESC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
    }

    $rows = [];
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    if (isset($stmt)) $stmt->close();
    return $rows;
}