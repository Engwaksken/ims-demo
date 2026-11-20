<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Activity/event request: approved concept note as PDF
|--------------------------------------------------------------------------
| activity-event-requests?concept_pdf=ID
| Available once the request is Approved, to its requester and to the
| reviewers who can see every request (supervisor/operations, procurement,
| finance, director, administrators).
|
| Layout follows the concept note the requester filled in:
|   1. Background and justification   2. Goal and objectives
|   3. Implementation details          4. Expected outcomes
|   5. Budget                          6. Approvals and signatures
*/

require_once __DIR__ . '/currency.php';

function aer_pdf_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function aer_pdf_text(string $text): string
{
    $text = trim($text);
    return $text === '' ? '<p class="muted">—</p>' : '<p>' . nl2br(aer_pdf_h($text)) . '</p>';
}

function aer_pdf_sig(?string $data, ?string $mime): string
{
    if ($data === null || $data === '' || !in_array($mime, ['image/png', 'image/jpeg'], true)) {
        return '<span class="muted">—</span>';
    }
    return '<img class="sig" alt="Signature" src="data:' . $mime . ';base64,' . base64_encode($data) . '">';
}

/** Streams the PDF and exits, or exits with an error status. */
function aer_concept_note_pdf(mysqli $conn, int $requestId, int $uid, string $role): never
{
    $seeAll = in_array($role, ['Administrator', 'Finance', 'Accountant', 'Operations/Admin', 'Chief of Operations',
        'Procurement Officer', 'Executive Director', 'Director', 'CEO'], true);

    $stmt = $conn->prepare("SELECT r.*, u.full_name requester, u.email requester_email, m.milestone_name,
            CASE WHEN r.entity_type='Project' THEN p.project_name ELSE pr.program_name END entity_name,
            CASE WHEN r.entity_type='Project' THEN p.project_code ELSE pr.program_code END entity_code
        FROM activity_event_requests r
        LEFT JOIN users u ON u.user_id = r.created_by
        LEFT JOIN milestones m ON m.milestone_id = r.milestone_id
        LEFT JOIN projects p ON r.entity_type='Project' AND p.project_id = r.entity_id
        LEFT JOIN programs pr ON r.entity_type='Program' AND pr.id = r.entity_id
        WHERE r.request_id = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        exit('The concept note could not be loaded.');
    }
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$r || ((int)$r['created_by'] !== $uid && !$seeAll)) {
        http_response_code(404);
        exit('Request not found.');
    }
    if ($r['status'] !== 'Approved') {
        http_response_code(409);
        exit('The concept note PDF is available once the request has been approved.');
    }

    $reviews = [];
    $hasSig = (bool)$conn->query('SELECT signature_data, signature_mime FROM activity_event_request_reviews LIMIT 0');
    $q = $conn->prepare('SELECT v.stage, v.action, v.comments, v.reviewed_at, ' . ($hasSig ? 'v.signature_data, v.signature_mime, ' : '')
        . 'u.full_name reviewer, u.role reviewer_role FROM activity_event_request_reviews v LEFT JOIN users u ON u.user_id = v.reviewed_by
           WHERE v.request_id = ? ORDER BY v.reviewed_at, v.review_id');
    if ($q) {
        $q->bind_param('i', $requestId);
        $q->execute();
        $reviews = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();
    }
    // The final approval of each stage (a request may have been returned and approved again).
    $approvals = [];
    foreach ($reviews as $v) {
        if ($v['action'] === 'Approved') {
            $approvals[$v['stage']] = $v;
        }
    }
    $approvedAt = $approvals['Director']['reviewed_at'] ?? ($reviews ? end($reviews)['reviewed_at'] : $r['updated_at']);

    $parts = function_exists('aer_parse_concept')
        ? aer_parse_concept((string)$r['concept'])
        : ['background' => (string)$r['concept'], 'goal' => '', 'objectives' => '', 'outcomes' => '', 'items' => []];
    $cur = strtoupper((string)$r['currency']);
    $amount = (float)$r['requested_amount'];
    $rate = ims_usd_ugx_rate($conn);
    $usd = $cur === 'UGX' ? ims_convert_amount($amount, 'UGX', 'USD', $rate) : null;
    $date = static fn($v, string $f = 'j F Y') => $v ? date($f, strtotime((string)$v)) : '—';
    $dates = $date($r['start_date']) . (!empty($r['end_date']) && $r['end_date'] !== $r['start_date'] ? ' to ' . $date($r['end_date']) : '');
    $org = function_exists('ims_brand') ? (string)(ims_brand()['name'] ?? '') : '';

    // Budget table
    $budgetRows = '';
    $sum = 0.0;
    foreach ($parts['items'] as $n => $it) {
        $line = round((float)$it['quantity'] * (float)$it['unit_cost'], 2);
        $sum += $line;
        $budgetRows .= '<tr><td class="c">' . ($n + 1) . '</td><td>' . aer_pdf_h($it['name']) . '</td><td class="num">'
            . rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.') . '</td><td class="num">' . number_format((float)$it['unit_cost'], 2)
            . '</td><td class="num">' . number_format($line, 2) . '</td></tr>';
    }
    if ($budgetRows === '') {
        $sum = $amount;
        $budgetRows = '<tr><td class="c">1</td><td>' . aer_pdf_h($r['title']) . ' (not itemised)</td><td class="num">1</td><td class="num">'
            . number_format($amount, 2) . '</td><td class="num">' . number_format($amount, 2) . '</td></tr>';
    }

    // Approvals table: requester, then each stage in workflow order.
    $stages = ['Supervisor' => 'Supervisor / Operations', 'Procurement' => 'Procurement', 'Finance' => 'Finance', 'Director' => 'Director'];
    $sigRows = '<tr><td><strong>Prepared by</strong><br><span class="muted">Requester</span></td><td>' . aer_pdf_h($r['requester'] ?? '—')
        . '</td><td>' . aer_pdf_h($date($r['created_at'], 'j M Y')) . '</td><td class="sigcell">'
        . aer_pdf_sig($r['requester_signature'] ?? null, $r['requester_signature_mime'] ?? null) . '</td></tr>';
    foreach ($stages as $key => $label) {
        $v = $approvals[$key] ?? null;
        $sigRows .= '<tr><td><strong>Approved by</strong><br><span class="muted">' . aer_pdf_h($label) . '</span></td><td>'
            . ($v ? aer_pdf_h($v['reviewer'] ?? '—') . (!empty($v['comments']) ? '<div class="comment">' . nl2br(aer_pdf_h($v['comments'])) . '</div>' : '') : '<span class="muted">—</span>')
            . '</td><td>' . ($v ? aer_pdf_h($date($v['reviewed_at'], 'j M Y')) : '—') . '</td><td class="sigcell">'
            . ($v ? aer_pdf_sig($v['signature_data'] ?? null, $v['signature_mime'] ?? null) : '<span class="muted">—</span>') . '</td></tr>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 66pt 32pt 40pt; } /* top clears the letterhead logo (pdf-branding.php) */
        tr { page-break-inside: avoid; }
        .keep { page-break-inside: avoid; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 10px; line-height: 1.5; }
        .top { border-bottom: 3px solid #c2410c; padding-bottom: 8px; margin-bottom: 14px; }
        .doc-type { font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #c2410c; font-weight: bold; }
        h1 { font-size: 18px; margin: 3px 0 2px; }
        .meta { color: #4b5563; font-size: 9px; }
        .approved { float: right; margin-top: 4px; padding: 4px 10px; border: 1.5px solid #15803d; color: #15803d; font-weight: bold; border-radius: 4px; font-size: 10px; }
        h2 { font-size: 11.5px; color: #c2410c; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #fed7aa; text-transform: uppercase; letter-spacing: .5px; }
        h3 { font-size: 10.5px; margin: 8px 0 3px; }
        p { margin: 0 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 5px 7px; border: 1px solid #e5e7eb; vertical-align: top; }
        .info td.k { width: 22%; background: #f9fafb; color: #4b5563; font-weight: bold; }
        .grid th, .grid td { padding: 5px 7px; border: 1px solid #d1d5db; vertical-align: top; }
        .grid th { background: #fff7ed; font-size: 8.5px; text-transform: uppercase; text-align: left; }
        .num { text-align: right; white-space: nowrap; } .c { text-align: center; width: 5%; }
        .total td { font-weight: bold; background: #f9fafb; }
        .sig { max-width: 150px; max-height: 50px; }
        .sigcell { width: 28%; text-align: center; }
        .comment { margin-top: 3px; font-size: 8.5px; color: #4b5563; font-style: italic; }
        .muted { color: #6b7280; }
        .foot { margin-top: 18px; font-size: 8px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style></head><body>'
        . '<div class="top"><span class="approved">APPROVED ' . aer_pdf_h($date($approvedAt, 'j M Y')) . '</span>'
        . '<div class="doc-type">' . ($org !== '' ? aer_pdf_h($org) . ' &middot; ' : '') . aer_pdf_h($r['event_type']) . ' concept note</div>'
        . '<h1>' . aer_pdf_h($r['title']) . '</h1>'
        . '<div class="meta">Reference ' . aer_pdf_h($r['reference_code']) . ' &nbsp;|&nbsp; Submitted ' . aer_pdf_h($date($r['created_at'])) . ' by ' . aer_pdf_h($r['requester'] ?? '—') . '</div></div>'

        . '<table class="info">'
        . '<tr><td class="k">' . aer_pdf_h($r['entity_type']) . '</td><td>' . aer_pdf_h(trim(($r['entity_code'] ? $r['entity_code'] . ' — ' : '') . ($r['entity_name'] ?? ''))) . '</td>'
        . '<td class="k">Workplan milestone</td><td>' . aer_pdf_h(($r['milestone_name'] ?? '') ?: '—') . '</td></tr>'
        . '<tr><td class="k">Activity</td><td>' . aer_pdf_h($r['activity_name'] ?: '—') . '</td>'
        . '<td class="k">Total budget</td><td><strong>' . aer_pdf_h($cur) . ' ' . number_format($amount, 2) . '</strong>'
        . ($usd !== null ? '<br><span class="muted">&asymp; USD ' . number_format($usd, 2) . ' at ' . number_format($rate, 2) . ' UGX/USD</span>' : '') . '</td></tr>'
        . '</table>'

        . '<h2>1. Background and justification</h2>' . aer_pdf_text($parts['background'])
        . '<h2>2. Goal and objectives</h2><h3>Goal</h3>' . aer_pdf_text($parts['goal']) . '<h3>Objectives</h3>' . aer_pdf_text($parts['objectives'])
        . '<h2>3. Implementation details</h2><table class="info">'
        . '<tr><td class="k">Type</td><td>' . aer_pdf_h($r['event_type']) . '</td><td class="k">Dates</td><td>' . aer_pdf_h($dates) . '</td></tr>'
        . '<tr><td class="k">Location</td><td>' . aer_pdf_h(($r['location'] ?? '') ?: '—') . '</td><td class="k">Participants</td><td>' . number_format((int)$r['participants']) . '</td></tr>'
        . '</table>'
        . '<h2>4. Expected outcomes</h2>' . aer_pdf_text($parts['outcomes'])
        . '<h2>5. Budget</h2><table class="grid"><thead><tr><th class="c">#</th><th>Item / budget line</th><th class="num">Qty</th><th class="num">Unit cost (' . aer_pdf_h($cur) . ')</th><th class="num">Total (' . aer_pdf_h($cur) . ')</th></tr></thead><tbody>'
        . $budgetRows
        . '<tr class="total"><td colspan="4">Total requested</td><td class="num">' . aer_pdf_h($cur) . ' ' . number_format($sum, 2) . '</td></tr>'
        . ($usd !== null ? '<tr><td colspan="4" class="muted">USD equivalent (1 USD = ' . number_format($rate, 2) . ' UGX)</td><td class="num muted">USD ' . number_format((float)ims_convert_amount($sum, 'UGX', 'USD', $rate), 2) . '</td></tr>' : '')
        . '</tbody></table>'
        . '<div class="keep"><h2>6. Approvals and signatures</h2><table class="grid"><thead><tr><th>Role</th><th>Name</th><th>Date</th><th class="sigcell">Signature</th></tr></thead><tbody>' . $sigRows . '</tbody></table></div>'
        . '<div class="foot">Generated ' . date('j M Y, H:i') . ' from the activity/event request ' . aer_pdf_h($r['reference_code']) . '. This document reflects the approved request.</div>'
        . '</body></html>';

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        http_response_code(500);
        exit('PDF export is unavailable: the PDF library is not installed.');
    }
    require_once $autoload;
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $pdf = new \Dompdf\Dompdf($options);
    $pdf->setPaper('A4', 'portrait');
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->render();
    if (is_file(__DIR__ . '/pdf-branding.php')) {
        require_once __DIR__ . '/pdf-branding.php';
        if (function_exists('ims_brand_pdf')) {
            ims_brand_pdf($pdf);
        }
    }
    $file = 'concept-note-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$r['reference_code']) . '.pdf';
    $pdf->stream($file, ['Attachment' => true]);
    exit;
}
