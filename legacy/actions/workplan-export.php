<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../includes/header.php';
ob_end_clean();

require_once __DIR__ . '/../includes/workplan-stats.php';


require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
     'Project Officer',
    'consultant'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not found.');
}

$workplanId = (int)($_GET['workplan_id'] ?? 0);
$format     = strtolower((string)($_GET['format'] ?? 'pdf'));

$workplan = $workplanId ? wp_get_full_workplan($conn, $workplanId) : null;

if (!$workplan) {
    http_response_code(404);
    die('Workplan not found.');
}

$stats = wp_compute_stats($workplan);

// Resolve a readable entity label for the title.
$entityLabel = $workplan['entity_type'] . ' #' . $workplan['entity_id'];
if ($workplan['entity_type'] === 'Project') {
    $stmt = $conn->prepare("SELECT project_code, project_name FROM projects WHERE project_id = ?");
    $stmt->bind_param('i', $workplan['entity_id']);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($p) {
        $entityLabel = trim(($p['project_code'] ?? '') . ' - ' . ($p['project_name'] ?? ''));
    }
} else {
    $stmt = $conn->prepare("SELECT program_code, program_name FROM programs WHERE id = ?");
    $stmt->bind_param('i', $workplan['entity_id']);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($p) {
        $entityLabel = trim(($p['program_code'] ?? '') . ' - ' . ($p['program_name'] ?? ''));
    }
}

$year = (int)$workplan['workplan_year'];
$currentMonth = (int)date('n');

$filenameBase = 'Workplan_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $entityLabel) . '_' . $year;

if ($format === 'csv') {
    wp_export_csv($workplan, $stats, $entityLabel, $year, $currentMonth, $filenameBase);
    exit;
}

wp_export_pdf($workplan, $stats, $entityLabel, $year, $currentMonth, $filenameBase);
exit;


function wp_export_csv(array $workplan, array $stats, string $entityLabel, int $year, int $currentMonth, string $filenameBase): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');

    $out = fopen('php://output', 'w');

    // --- Stats summary block ---
    ims_fputcsv($out, ['Workplan Summary - ' . $entityLabel . ' (' . $year . ')']);
    ims_fputcsv($out, ['Total Milestones', $stats['milestone_count']]);
    ims_fputcsv($out, ['Total Activities / Deliverables', $stats['deliverable_count']]);
    ims_fputcsv($out, ['Average Progress (%)', $stats['completion_pct']]);
    ims_fputcsv($out, ['Completed Deliverables', $stats['completed_deliverables']]);
    ims_fputcsv($out, ['Overdue Items (deliverable or monthly)', $stats['overdue_count']]);
    foreach (WP_MONTHLY_STATUSES as $st) {
        ims_fputcsv($out, ['Monthly cells marked "' . $st . '"', $stats['monthly_status_counts'][$st]]);
    }
    ims_fputcsv($out, []);

    // --- Grid header ---
    $header = ['Milestone', 'Activity / Deliverable', 'Actor(s)', 'End Date', 'On Track (Y/N)'];
    foreach (WP_MONTHS as $mo => $label) {
        $header[] = $label . '-' . substr((string)$year, -2);
    }
    ims_fputcsv($out, $header);

    foreach ($workplan['milestones'] as $m) {
        foreach ($m['deliverables'] as $d) {
            $row = [
                $m['milestone_name'],
                $d['title'] . ($d['notes'] ? ' - ' . $d['notes'] : ''),
                $d['responsible_person'],
                $d['end_date'] ?: '',
                wp_deliverable_on_track($d, $currentMonth) ? 'Y' : 'N',
            ];
            foreach (WP_MONTHS as $mo => $label) {
                $entry = $d['monthly_status'][$mo] ?? ['status' => 'Planned', 'comment' => ''];
                $cell = $entry['status'];
                if (!empty($entry['comment'])) {
                    $cell .= ' (' . $entry['comment'] . ')';
                }
                $row[] = $cell;
            }
            ims_fputcsv($out, $row);
        }
       
        if (empty($m['deliverables'])) {
            $row = [$m['milestone_name'], '(no activities added)', '', '', ''];
            foreach (WP_MONTHS as $mo => $label) {
                $row[] = '';
            }
            ims_fputcsv($out, $row);
        }
    }

    fclose($out);
}

function wp_export_pdf(array $workplan, array $stats, string $entityLabel, int $year, int $currentMonth, string $filenameBase): void
{
    $html = wp_build_pdf_html($workplan, $stats, $entityLabel, $year, $currentMonth);

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A3', 'landscape');
    $dompdf->loadHtml($html);
    $dompdf->render();
require_once __DIR__ . '/../includes/pdf-branding.php';
ims_brand_pdf($dompdf);
    $dompdf->stream($filenameBase . '.pdf', ['Attachment' => true]);
}

function wp_status_color(string $status): string
{
    return WP_STATUS_COLORS[$status] ?? '#e2e8f0';
}

function wp_build_pdf_html(array $workplan, array $stats, string $entityLabel, int $year, int $currentMonth): string
{
    ob_start();
    ?>
    <html>
    <head>
        <style>
            @page { margin: 18px; }
            body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }
            h1 { font-size: 16px; margin: 0 0 2px 0; }
            .subtitle { font-size: 10px; color: #475569; margin-bottom: 10px; }

            .stats-row { width: 100%; margin-bottom: 12px; }
            .stat-card {
                display: inline-block; width: 16%; padding: 6px 8px; margin-right: 6px;
                border: 1px solid #cbd5e1; border-radius: 4px; background: #f8fafc; vertical-align: top;
            }
            .stat-card .num { font-size: 14px; font-weight: bold; color: #0f766e; }
            .stat-card .lbl { font-size: 7px; color: #64748b; text-transform: uppercase; }

            table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
            table.grid th, table.grid td {
                border: 1px solid #cbd5e1; padding: 3px 4px; vertical-align: top; word-wrap: break-word;
            }
            table.grid th { background: #0f766e; color: #fff; font-size: 7.5px; text-align: left; }
            .milestone-row td {
                background: #d1fae5; font-weight: bold; font-size: 8.5px; color: #065f46;
            }
            .month-cell { text-align: center; font-size: 6.5px; color: #fff; font-weight: bold; }
            .col-milestone { width: 10%; }
            .col-activity { width: 16%; }
            .col-actor { width: 7%; }
            .col-end { width: 5%; }
            .col-track { width: 4%; text-align: center; }
            .col-month { width: 4.4%; }

            .legend { margin-top: 10px; }
            .legend-item { display: inline-block; margin-right: 10px; font-size: 7.5px; }
            .legend-swatch {
                display: inline-block; width: 9px; height: 9px; margin-right: 3px;
                border-radius: 2px; vertical-align: middle;
            }
        </style>
    </head>
    <body>
        <h1><?php echo htmlspecialchars($entityLabel); ?> - Annual Workplan <?php echo $year; ?></h1>
        <div class="subtitle">Generated <?php echo date('d M Y'); ?> � Statuses updated monthly by the M&amp;E lead by the 15th.</div>

        <div class="stats-row">
            <div class="stat-card"><div class="num"><?php echo $stats['milestone_count']; ?></div><div class="lbl">Milestones</div></div>
            <div class="stat-card"><div class="num"><?php echo $stats['deliverable_count']; ?></div><div class="lbl">Activities</div></div>
            <div class="stat-card"><div class="num"><?php echo $stats['completion_pct']; ?>%</div><div class="lbl">Avg. Progress</div></div>
            <div class="stat-card"><div class="num"><?php echo $stats['completed_deliverables']; ?></div><div class="lbl">Completed</div></div>
            <div class="stat-card"><div class="num"><?php echo $stats['overdue_count']; ?></div><div class="lbl">Overdue Flags</div></div>
            <div class="stat-card"><div class="num"><?php echo $stats['on_track_count']; ?></div><div class="lbl">On Track</div></div>
        </div>

        <table class="grid">
            <thead>
                <tr>
                    <th class="col-milestone">Milestone</th>
                    <th class="col-activity">Activity / Deliverable</th>
                    <th class="col-actor">Actor(s)</th>
                    <th class="col-end">End Date</th>
                    <th class="col-track">On Track</th>
                    <?php foreach (WP_MONTHS as $mo => $label): ?>
                        <th class="col-month"><?php echo $label . '-' . substr((string)$year, -2); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workplan['milestones'] as $m): ?>
                    <tr class="milestone-row">
                        <td colspan="<?php echo 5 + count(WP_MONTHS); ?>">
                            <?php echo htmlspecialchars($m['milestone_name']); ?>
                            <?php if (!empty($m['responsible_person'])): ?>
                                &nbsp;&mdash;&nbsp;Overall: <?php echo htmlspecialchars($m['responsible_person']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php foreach ($m['deliverables'] as $d): ?>
                        <tr>
                            <td class="col-milestone"></td>
                            <td class="col-activity">
                                <?php echo htmlspecialchars($d['title']); ?>
                                <?php if (!empty($d['notes'])): ?>
                                    <br><span style="color:#64748b;"><?php echo htmlspecialchars($d['notes']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-actor"><?php echo htmlspecialchars($d['responsible_person']); ?></td>
                            <td class="col-end"><?php echo htmlspecialchars($d['end_date'] ?: ''); ?></td>
                            <td class="col-track">
                                <?php echo wp_deliverable_on_track($d, $currentMonth) ? 'Y' : 'N'; ?>
                            </td>
                            <?php foreach (WP_MONTHS as $mo => $label):
                                $entry = $d['monthly_status'][$mo] ?? ['status' => 'Planned', 'comment' => ''];
                                $color = wp_status_color($entry['status']);
                            ?>
                                <td class="col-month month-cell" style="background: <?php echo $color; ?>;"
                                    title="<?php echo htmlspecialchars($entry['comment']); ?>">
                                    <?php echo htmlspecialchars(substr($entry['status'], 0, 4)); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="legend">
            <?php foreach (WP_MONTHLY_STATUSES as $st): ?>
                <span class="legend-item">
                    <span class="legend-swatch" style="background: <?php echo wp_status_color($st); ?>;"></span>
                    <?php echo $st; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </body>
    </html>
    <?php
    return (string)ob_get_clean();
}