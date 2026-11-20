<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Finance Budget Reports
|--------------------------------------------------------------------------
| Budget lines (manage-project-program-budgets) grouped as:
|   Code (project / program)
|     Milestone (title)
|       Activity = the budget line's code description (sub-items)
| Actual = approved activity/event requests; Concept amount = requests in
| review or returned. Requests are converted into the budget line's
| currency (UGX <-> USD at the System Settings rate, includes/currency.php).
| Burn rate = (Actual + Concept amount) / Original budget x 100.
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/currency.php';
check_login();

$role = (string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');
if (!in_array($role, ['Administrator', 'Finance', 'Accountant'], true)) {
    http_response_code(403);
    exit('Finance access required.');
}
if (!isset($conn) || !$conn instanceof mysqli) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT);
if ($year === false || $year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}
$type = (string)($_GET['entity_type'] ?? '');
if (!in_array($type, ['', 'Project', 'Program'], true)) {
    $type = '';
}

$fxRate = ims_usd_ugx_rate($conn);
$fx = ims_fx_sql('r.requested_amount', 'r.currency', 'b.currency', $fxRate);

// The milestone of a budget line: the workplan milestone whose activity
// (deliverable) has the same name as the line's code description, else the
// milestone chosen on the latest request for that activity.
$sql = "SELECT x.*, ms.milestone_name, ms.due_date AS milestone_due
          FROM (
            SELECT b.budget_line_id, b.entity_type, b.entity_id, b.activity_name, b.fiscal_year, b.currency, b.allocated_amount,
                   CASE WHEN b.entity_type = 'Project' THEN p.project_code ELSE pr.program_code END AS entity_code,
                   CASE WHEN b.entity_type = 'Project' THEN p.project_name ELSE pr.program_name END AS entity_name,
                   COALESCE(
                       (SELECT m.milestone_id FROM milestone_deliverables d JOIN milestones m ON m.milestone_id = d.milestone_id
                         WHERE m.entity_type = b.entity_type AND m.entity_id = b.entity_id AND d.deliverable_title = b.activity_name
                         ORDER BY m.due_date LIMIT 1),
                       (SELECT r.milestone_id FROM activity_event_requests r
                         WHERE r.entity_type = b.entity_type AND r.entity_id = b.entity_id AND r.activity_name = b.activity_name
                         ORDER BY r.created_at DESC LIMIT 1)
                   ) AS milestone_id,
                   COALESCE((SELECT SUM($fx) FROM activity_event_requests r
                              WHERE r.entity_type = b.entity_type AND r.entity_id = b.entity_id AND r.activity_name = b.activity_name
                                AND YEAR(r.start_date) = b.fiscal_year AND r.status IN ('Director Approval','Approved')), 0) AS actual_amount,
                   COALESCE((SELECT SUM($fx) FROM activity_event_requests r
                              WHERE r.entity_type = b.entity_type AND r.entity_id = b.entity_id AND r.activity_name = b.activity_name
                                AND YEAR(r.start_date) = b.fiscal_year AND r.status IN ('Supervisor Review','Procurement Review','Finance Review','Returned')), 0) AS concept_amount,
                   (SELECT COUNT(*) FROM activity_event_requests r
                     WHERE r.entity_type = b.entity_type AND r.entity_id = b.entity_id AND r.activity_name = b.activity_name
                       AND YEAR(r.start_date) = b.fiscal_year AND r.status <> 'Rejected') AS request_count
              FROM project_program_budget_lines b
              LEFT JOIN projects p ON b.entity_type = 'Project' AND p.project_id = b.entity_id
              LEFT JOIN programs pr ON b.entity_type = 'Program' AND pr.id = b.entity_id
             WHERE b.fiscal_year = ? AND (? = '' OR b.entity_type = ?)
          ) x
          LEFT JOIN milestones ms ON ms.milestone_id = x.milestone_id
         ORDER BY x.entity_type, x.entity_code, (ms.milestone_id IS NULL), ms.due_date, ms.milestone_name, x.activity_name";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('finance-reports: ' . $conn->error);
    http_response_code(500);
    exit('The finance report could not be loaded.');
}
$stmt->bind_param('iss', $year, $type, $type);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
|--------------------------------------------------------------------------
| Grouping: code -> milestone -> activities, with subtotals
|--------------------------------------------------------------------------
*/
function fr_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fr_blank_sum(): array
{
    return ['allocated' => 0.0, 'actual' => 0.0, 'concept' => 0.0, 'currencies' => []];
}

function fr_add(array &$sum, array $row): void
{
    $sum['allocated'] += (float)$row['allocated_amount'];
    $sum['actual'] += (float)$row['actual_amount'];
    $sum['concept'] += (float)$row['concept_amount'];
    $sum['currencies'][strtoupper((string)$row['currency'])] = true;
}

$groups = [];
$totals = [];
foreach ($rows as $row) {
    $codeKey = $row['entity_type'] . '#' . $row['entity_id'];
    $msKey = $row['milestone_id'] !== null && $row['milestone_name'] !== null ? 'm' . (int)$row['milestone_id'] : 'none';
    if (!isset($groups[$codeKey])) {
        $groups[$codeKey] = [
            'entity_type' => $row['entity_type'], 'code' => (string)($row['entity_code'] ?? ''), 'name' => (string)($row['entity_name'] ?? ''),
            'sum' => fr_blank_sum(), 'milestones' => [],
        ];
    }
    if (!isset($groups[$codeKey]['milestones'][$msKey])) {
        $groups[$codeKey]['milestones'][$msKey] = [
            'name' => $msKey === 'none' ? 'No workplan milestone linked' : (string)$row['milestone_name'],
            'linked' => $msKey !== 'none',
            'sum' => fr_blank_sum(), 'activities' => [],
        ];
    }
    $groups[$codeKey]['milestones'][$msKey]['activities'][] = $row;
    fr_add($groups[$codeKey]['milestones'][$msKey]['sum'], $row);
    fr_add($groups[$codeKey]['sum'], $row);

    $currency = strtoupper((string)$row['currency']);
    $totals[$currency] ??= ['allocated' => 0.0, 'actual' => 0.0, 'concept' => 0.0];
    $totals[$currency]['allocated'] += (float)$row['allocated_amount'];
    $totals[$currency]['actual'] += (float)$row['actual_amount'];
    $totals[$currency]['concept'] += (float)$row['concept_amount'];
}

/** The figure cells for one row; a mixed-currency subtotal shows dashes. */
function fr_cells(array $sum, ?string $currency): string
{
    if ($currency === null) {
        return '<td class="num" colspan="5"><span class="fr-muted">Mixed currencies, see the lines below</span></td>';
    }
    $remaining = $sum['allocated'] - $sum['actual'] - $sum['concept'];
    $burn = $sum['allocated'] > 0 ? ($sum['actual'] + $sum['concept']) / $sum['allocated'] * 100 : 0.0;
    $burnClass = $burn > 100 ? 'is-over' : ($burn >= 80 ? 'is-warn' : '');
    return '<td class="num">' . fr_h($currency) . ' ' . number_format($sum['allocated'], 2) . '</td>'
        . '<td class="num">' . number_format($sum['actual'], 2) . '</td>'
        . '<td class="num">' . number_format($sum['concept'], 2) . '</td>'
        . '<td class="num' . ($remaining < 0 ? ' fr-neg' : '') . '">' . number_format($remaining, 2) . '</td>'
        . '<td class="num"><span class="fr-burn ' . $burnClass . '">' . number_format($burn, 1) . '%</span></td>';
}

function fr_sum_currency(array $sum): ?string
{
    return count($sum['currencies']) === 1 ? (string)array_key_first($sum['currencies']) : null;
}

/** Table body rows shared by the page and the PDF. */
function fr_body(array $groups): string
{
    if (!$groups) {
        return '<tr><td colspan="7">No budget lines match these filters.</td></tr>';
    }
    $html = '';
    foreach ($groups as $g) {
        $html .= '<tr class="fr-code-row"><td><strong>' . fr_h($g['code']) . '</strong></td>'
            . '<td><strong>' . fr_h($g['name']) . '</strong> <span class="fr-muted">' . fr_h($g['entity_type']) . '</span></td>'
            . fr_cells($g['sum'], fr_sum_currency($g['sum'])) . '</tr>';
        foreach ($g['milestones'] as $ms) {
            $html .= '<tr class="fr-ms-row"><td></td><td><span class="fr-ms' . ($ms['linked'] ? '' : ' fr-muted') . '">' . fr_h($ms['name']) . '</span></td>'
                . fr_cells($ms['sum'], fr_sum_currency($ms['sum'])) . '</tr>';
            foreach ($ms['activities'] as $a) {
                $one = fr_blank_sum();
                fr_add($one, $a);
                $reqs = (int)$a['request_count'];
                $html .= '<tr class="fr-act-row"><td></td><td><span class="fr-act">' . fr_h($a['activity_name']) . '</span>'
                    . ($reqs ? ' <span class="fr-muted">(' . $reqs . ' request' . ($reqs === 1 ? '' : 's') . ')</span>' : '') . '</td>'
                    . fr_cells($one, strtoupper((string)$a['currency'])) . '</tr>';
            }
        }
    }
    return $html;
}

$rateNote = 'UGX requests are converted at 1 USD = ' . number_format($fxRate, 2) . ' UGX (System Settings).';

/*
|--------------------------------------------------------------------------
| Exports
|--------------------------------------------------------------------------
*/
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finance-budget-report-' . $year . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Code', 'Project/program', 'Milestone', 'Activity (code description)', 'Currency', 'Original budget', 'Actual', 'Concept amount', 'Remaining', 'Burn rate (%)']);
    foreach ($groups as $g) {
        foreach ($g['milestones'] as $ms) {
            foreach ($ms['activities'] as $a) {
                $budget = (float)$a['allocated_amount'];
                $actual = (float)$a['actual_amount'];
                $concept = (float)$a['concept_amount'];
                fputcsv($out, [$g['code'], $g['name'], $ms['name'], $a['activity_name'], $a['currency'], round($budget, 2), round($actual, 2), round($concept, 2),
                    round($budget - $actual - $concept, 2), $budget > 0 ? round(($actual + $concept) / $budget * 100, 1) : 0]);
            }
        }
    }
    fclose($out);
    exit;
}

$summaryHtml = '';
foreach ($totals as $currency => $t) {
    $summaryHtml .= '<div class="summary"><strong>' . number_format($t['allocated'], 2) . ' ' . fr_h($currency) . '</strong><span>Original budget</span>'
        . '<strong>' . number_format($t['actual'], 2) . ' ' . fr_h($currency) . '</strong><span>Actual</span>'
        . '<strong>' . number_format($t['concept'], 2) . ' ' . fr_h($currency) . '</strong><span>Concept amount</span>'
        . '<strong>' . number_format($t['allocated'] - $t['actual'] - $t['concept'], 2) . ' ' . fr_h($currency) . '</strong><span>Remaining</span></div>';
}

if (($_GET['export'] ?? '') === 'pdf') {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        http_response_code(500);
        exit('PDF export is unavailable: the PDF library is not installed.');
    }
    require_once $autoload;
    if (!class_exists(\Dompdf\Dompdf::class)) {
        http_response_code(500);
        exit('PDF export is unavailable: Dompdf is not installed.');
    }
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'body{font-family:DejaVu Sans,sans-serif;color:#0d1117;font-size:9px}h1{color:#c2410c;font-size:20px;margin-bottom:4px}.meta{color:#4b5563;margin-bottom:16px}'
        . '.summary{display:inline-block;width:31%;box-sizing:border-box;padding:10px;margin:0 1% 14px 0;background:#fff4ed;border:1px solid #ffc89e;border-radius:5px}.summary strong,.summary span{display:block;margin:3px 0}.summary span{font-size:8px;color:#4b5563}'
        . 'table{width:100%;border-collapse:collapse}th,td{padding:6px 7px;border:1px solid #d1d5db;text-align:left;vertical-align:top}th{background:#fff4ed;font-size:8px;text-transform:uppercase}td.num{text-align:right;white-space:nowrap}'
        . '.fr-code-row td{background:#f3f4f6}.fr-ms{font-weight:bold}.fr-act{padding-left:14px;display:block}.fr-muted{color:#6b7280}.fr-neg{color:#b42318}'
        . '</style></head><body><h1>Finance Budget Report</h1><div class="meta">Fiscal year: ' . $year . ' &nbsp;|&nbsp; Entity: ' . ($type === '' ? 'All' : fr_h($type))
        . ' &nbsp;|&nbsp; Generated: ' . date('Y-m-d H:i') . '<br>Burn rate = (Actual + Concept amount) / Original budget × 100. ' . fr_h($rateNote) . '</div>'
        . $summaryHtml
        . '<table><thead><tr><th>Code</th><th>Milestone / activity (code description)</th><th>Original budget</th><th>Actual</th><th>Concept amount</th><th>Remaining</th><th>Burn rate</th></tr></thead><tbody>'
        . fr_body($groups) . '</tbody></table></body></html>';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $pdf = new \Dompdf\Dompdf($options);
    $pdf->setPaper('A4', 'landscape');
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->render();
    require_once __DIR__ . '/includes/pdf-branding.php';
    ims_brand_pdf($pdf);
    $pdf->stream('finance-budget-report-' . $year . '.pdf', ['Attachment' => true]);
    exit;
}

$page_title = 'Finance Budget Reports';
require_once __DIR__ . '/includes/header.php';
$qs = 'year=' . $year . '&amp;entity_type=' . rawurlencode($type);
?>
<style>
.fr-page{max-width:1300px;margin:0 auto;padding:24px;display:grid;gap:22px;color:var(--ink-900)}
.fr-page .fr-card{background:var(--surface-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px;box-shadow:var(--shadow-sm)}
.fr-page form{display:flex;align-items:end;gap:14px;flex-wrap:wrap}
.fr-page label{display:grid;gap:6px;font-weight:600;min-width:160px;color:var(--ink-700)}
.fr-page input,.fr-page select{min-height:42px;padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface-card);color:var(--ink-900);font:inherit}
.fr-page button,.fr-page .fr-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:0;border-radius:var(--radius-sm);padding:9px 16px;background:var(--brand-600);color:var(--ink-0);font-weight:600;text-decoration:none;cursor:pointer}
.fr-page button:hover,.fr-page .fr-link:hover{background:var(--brand-700)}
.fr-page .fr-table-wrap{overflow:auto}
.fr-page table{border-collapse:collapse;width:100%;min-width:900px}
.fr-page th,.fr-page td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);color:var(--ink-700);vertical-align:top}
.fr-page th{font-size:.82rem;text-transform:uppercase;background:var(--surface-hover);color:var(--ink-900)}
.fr-page td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.fr-page .fr-code-row td{background:var(--surface-hover);color:var(--ink-900);border-top:2px solid var(--border)}
.fr-page .fr-ms-row td{padding-top:12px}
.fr-page .fr-ms{font-weight:700;color:var(--ink-900);font-size:.95rem}
.fr-page .fr-ms::before{content:"\f024";font-family:"Font Awesome 6 Free";font-weight:900;margin-right:8px;color:var(--brand-500);font-size:.8rem}
.fr-page .fr-act-row td{border-bottom-style:dashed}
.fr-page .fr-act{display:inline-block;padding-left:22px;position:relative}
.fr-page .fr-act::before{content:"";position:absolute;left:8px;top:.55em;width:6px;height:6px;border-radius:50%;background:var(--ink-200)}
.fr-page .fr-muted{color:var(--ink-300);font-size:.85em;font-weight:400}
.fr-page .fr-neg{color:var(--red-fg)}
.fr-page .fr-burn{font-weight:700}.fr-page .fr-burn.is-warn{color:var(--amber-fg)}.fr-page .fr-burn.is-over{color:var(--red-fg)}
@media(max-width:700px){.fr-page{padding:14px}.fr-page .fr-card{padding:16px}.fr-page form>*{width:100%}}
</style>
<div class="fr-page">
    <h1>Finance Budget Reports</h1>
    <section class="fr-card">
        <form method="get">
            <label>Fiscal year<input type="number" min="2000" max="2100" name="year" value="<?= $year ?>"></label>
            <label>Entity type
                <select name="entity_type">
                    <option value="" <?= $type === '' ? 'selected' : '' ?>>All</option>
                    <option value="Project" <?= $type === 'Project' ? 'selected' : '' ?>>Projects</option>
                    <option value="Program" <?= $type === 'Program' ? 'selected' : '' ?>>Programs</option>
                </select>
            </label>
            <button type="submit">Apply filters</button>
            <a class="fr-link" href="finance-reports?<?= $qs ?>&amp;export=csv">Download CSV</a>
            <a class="fr-link" href="finance-reports?<?= $qs ?>&amp;export=pdf">Download PDF</a>
        </form>
    </section>

    <section class="stats-grid reports-stats">
        <?php foreach ($totals as $currency => $t): ?>
            <div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-coins" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label"><?= $year ?> Original budget (<?= fr_h($currency) ?>)</span><span class="stat-value"><?= number_format($t['allocated'], 2) ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-hand-holding-dollar" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label"><?= $year ?> Actual (<?= fr_h($currency) ?>)</span><span class="stat-value"><?= number_format($t['actual'], 2) ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-file-alt" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label"><?= $year ?> Concept amount (<?= fr_h($currency) ?>)</span><span class="stat-value"><?= number_format($t['concept'], 2) ?></span></div></div>
            <div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-wallet" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label"><?= $year ?> Remaining (<?= fr_h($currency) ?>)</span><span class="stat-value"><?= number_format($t['allocated'] - $t['actual'] - $t['concept'], 2) ?></span></div></div>
        <?php endforeach; ?>
    </section>

    <section class="fr-card">
        <h2>Budget lines</h2>
        <p>Grouped by code, then workplan milestone, with each activity (code description) underneath. Burn rate = (Actual + Concept amount) / Original budget × 100. Actual is the total of approved activity requests; concept amount includes requests in review or returned for revision. <?= fr_h($rateNote) ?></p>
        <div class="fr-table-wrap">
            <table>
                <thead><tr><th>Code</th><th>Milestone / activity (code description)</th><th class="num">Original budget</th><th class="num">Actual</th><th class="num">Concept amount</th><th class="num">Remaining</th><th class="num">Burn rate</th></tr></thead>
                <tbody><?= fr_body($groups) ?></tbody>
            </table>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
