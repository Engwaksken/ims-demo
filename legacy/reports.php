<?php
declare(strict_types=1);


require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

// Organisation-wide reports/exports: internal staff only (not applicants,
// members or external partners).
require_once __DIR__ . '/includes/auth.php';
check_role(IMS_STAFF_ROLES);

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$powerbi_settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM powerbi_settings");
if ($res) while ($r = $res->fetch_assoc()) $powerbi_settings[$r['setting_key']] = $r['setting_value'];

/* Programs by status */
$programs_data = [];
$res = $conn->query("SELECT status, COUNT(*) AS cnt FROM programs GROUP BY status ORDER BY status");
if ($res) while ($r = $res->fetch_assoc()) $programs_data[$r['status']] = (int)$r['cnt'];

/* Projects by status */
$projects_data = [];
$res = $conn->query("SELECT status, COUNT(*) AS cnt FROM projects GROUP BY status ORDER BY status");
if ($res) while ($r = $res->fetch_assoc()) $projects_data[$r['status']] = (int)$r['cnt'];

/* Beneficiaries by gender */
$gender_data = [];
$res = $conn->query("SELECT gender, COUNT(*) AS cnt FROM beneficiaries GROUP BY gender ORDER BY gender");
if ($res) while ($r = $res->fetch_assoc()) $gender_data[$r['gender']] = (int)$r['cnt'];

/* Indicators achievement */
$ind_data = ['total'=>0,'achieved'=>0,'partial'=>0,'low'=>0];
$res = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN (current_value/target_value*100)>=100  THEN 1 ELSE 0 END) AS achieved,
    SUM(CASE WHEN (current_value/target_value*100) BETWEEN 50 AND 99 THEN 1 ELSE 0 END) AS partial,
    SUM(CASE WHEN (current_value/target_value*100)<50    THEN 1 ELSE 0 END) AS low
    FROM indicators WHERE target_value>0");
if ($res && $r = $res->fetch_assoc()) $ind_data = array_map('intval', $r);

/* PWD */
$pwd_data = ['pwd'=>0,'non_pwd'=>0];
$res = $conn->query("SELECT
    SUM(CASE WHEN is_pwd=1 THEN 1 ELSE 0 END) AS pwd,
    SUM(CASE WHEN is_pwd=0 THEN 1 ELSE 0 END) AS non_pwd
    FROM beneficiaries");
if ($res && $r = $res->fetch_assoc()) $pwd_data = array_map('intval', $r);

/* Events */
$events_data = ['total'=>0,'recent'=>0];
$res = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN event_date>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent
    FROM hub_events");
if ($res && $r = $res->fetch_assoc()) $events_data = array_map('intval', $r);

/* Derived totals */
$total_programs      = array_sum($programs_data);
$total_projects      = array_sum($projects_data);
$total_beneficiaries = array_sum($gender_data);
$total_indicators    = $ind_data['total'];
$pwd_total           = $pwd_data['pwd'] + $pwd_data['non_pwd'];
$pwd_pct             = $pwd_total > 0 ? round($pwd_data['pwd'] / $pwd_total * 100, 1) : 0;

/* ══════════════════════════════════════════════════════════════════════
   EXPORT HANDLERS  — must run before any HTML output
   ══════════════════════════════════════════════════════════════════════ */
$export = $_GET['export'] ?? '';

/* ── CSV helper ─────────────────────────────────────────────────────── */
function stream_csv(string $filename, array $headers, array $rows): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    ims_fputcsv($out, $headers);
    foreach ($rows as $row) ims_fputcsv($out, $row);
    fclose($out);
    exit;
}

/* ── CSV export ─────────────────────────────────────────────────────── */
if ($export === 'csv') {
    $section = $_GET['section'] ?? 'overview';
    $rows    = [];

    switch ($section) {
        case 'programs':
            stream_csv('programs_summary_' . date('Ymd') . '.csv',
                ['Status', 'Count'],
                array_map(fn($s,$c) => [$s,$c], array_keys($programs_data), $programs_data)
            );
            break;

        case 'projects':
            stream_csv('projects_summary_' . date('Ymd') . '.csv',
                ['Status', 'Count'],
                array_map(fn($s,$c) => [$s,$c], array_keys($projects_data), $projects_data)
            );
            break;

        case 'participants':
            $res = $conn->query("SELECT first_name, last_name, gender, date_of_birth,
                phone, district, is_pwd FROM beneficiaries ORDER BY last_name, first_name LIMIT 5000");
            while ($r = $res->fetch_assoc()) $rows[] = [
                $r['first_name'].' '.$r['last_name'],
                $r['gender'], $r['date_of_birth'],
                $r['phone'], $r['district'],
                $r['is_pwd'] ? 'Yes' : 'No',
            ];
            stream_csv('participants_' . date('Ymd') . '.csv',
                ['Full Name','Gender','Date of Birth','Phone','District','PWD'],
                $rows
            );
            break;

        case 'indicators':
            $res = $conn->query("SELECT indicator_name, indicator_type, unit_of_measure,
                baseline_value, target_value, current_value,
                CASE WHEN target_value>0 THEN ROUND(current_value/target_value*100,1) ELSE 0 END AS pct
                FROM indicators ORDER BY indicator_type, indicator_name");
            while ($r = $res->fetch_assoc()) $rows[] = [
                $r['indicator_name'], $r['indicator_type'], $r['unit_of_measure'],
                $r['baseline_value'], $r['target_value'], $r['current_value'],
                $r['pct'].'%',
            ];
            stream_csv('indicators_' . date('Ymd') . '.csv',
                ['Indicator','Type','Unit','Baseline','Target','Current','Achievement %'],
                $rows
            );
            break;

        case 'pwd':
            $rows[] = ['Persons with Disabilities', $pwd_data['pwd']];
            $rows[] = ['Non-PWD',                   $pwd_data['non_pwd']];
            $rows[] = ['PWD Percentage',             $pwd_pct.'%'];
            stream_csv('pwd_statistics_' . date('Ymd') . '.csv',
                ['Category','Value'], $rows);
            break;

        case 'events':
            $res = $conn->query("SELECT event_name, event_date, event_type, location,
                expected_participants, actual_participants, status
                FROM hub_events ORDER BY event_date DESC LIMIT 5000");
            while ($r = $res->fetch_assoc()) $rows[] = array_values($r);
            stream_csv('events_' . date('Ymd') . '.csv',
                ['Event Name','Date','Type','Location','Expected','Actual','Status'],
                $rows
            );
            break;

        default: // overview
            // Programs
            foreach ($programs_data as $s => $c) $rows[] = ['Programs', $s, $c];
            // Projects
            foreach ($projects_data as $s => $c) $rows[] = ['Projects', $s, $c];
            // Gender
            foreach ($gender_data as $g => $c) $rows[] = ['Participants by Gender', $g, $c];
            // Indicators
            $rows[] = ['Indicators', 'Achieved (≥100%)',  $ind_data['achieved']];
            $rows[] = ['Indicators', 'Partial (50–99%)',  $ind_data['partial']];
            $rows[] = ['Indicators', 'Low (<50%)',         $ind_data['low']];
            // PWD
            $rows[] = ['PWD', 'Persons with Disabilities', $pwd_data['pwd']];
            $rows[] = ['PWD', 'Non-PWD',                   $pwd_data['non_pwd']];
            $rows[] = ['PWD', 'PWD %',                     $pwd_pct.'%'];
            // Events
            $rows[] = ['Events', 'Total',          $events_data['total']];
            $rows[] = ['Events', 'Last 30 days',   $events_data['recent']];

            stream_csv('analytics_overview_' . date('Ymd') . '.csv',
                ['Category', 'Label', 'Value'], $rows);
            break;
    }
}

/* ── PDF export (Dompdf) ─────────────────────────────────────────────── */
if ($export === 'pdf') {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        die('<p style="font-family:sans-serif;color:red;padding:20px;">
            Dompdf not installed. Run: <code>composer require dompdf/dompdf</code></p>');
    }
    require_once $autoload;
    if (!class_exists('\Dompdf\Dompdf')) {
        die('<p style="font-family:sans-serif;color:red;padding:20px;">Dompdf class not found.</p>');
    }

    /* Build self-contained HTML for the PDF */
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <title>Analytics Overview</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 12px; color: #1a1a1a; background: #fff; line-height: 1.5;
            padding: 24px;
        }

        /* Cover bar */
        .cover {
            background: #fc7f10; color: #fff;
            padding: 18px 22px; border-radius: 6px; margin-bottom: 22px;
        }
        .cover h1  { font-size: 20px; font-weight: 800; margin-bottom: 4px; }
        .cover p   { font-size: 11px; opacity: .8; }

        /* Stats row */
        .stats-row {
            display: table; width: 100%; border-collapse: separate;
            border-spacing: 8px 0; margin-bottom: 20px;
        }
        .stat-cell {
            display: table-cell; width: 25%;
            background: #f3f4f6; border: 1px solid #e5e7eb;
            border-radius: 6px; padding: 12px 14px; vertical-align: top;
        }
        .stat-cell .lbl { font-size: 9px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: #6b7280; margin-bottom: 4px; }
        .stat-cell .val { font-size: 26px; font-weight: 800; color: #1e2530; line-height: 1; }

        /* Section */
        .section { margin-bottom: 20px; page-break-inside: avoid; }
        .section-head {
            background: #f3f4f6; border: 1px solid #d1d5db; border-bottom: none;
            padding: 8px 14px; border-radius: 6px 6px 0 0;
            font-size: 11px; font-weight: 800; text-transform: uppercase;
            letter-spacing: .05em; color: #374151;
        }
        .section-body {
            border: 1px solid #d1d5db; border-radius: 0 0 6px 6px;
            padding: 12px 14px;
        }

        /* Tables */
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th {
            background: #f9fafb; color: #6b7280; font-size: 10px;
            font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
            padding: 6px 10px; border-bottom: 1px solid #e5e7eb; text-align: left;
        }
        td { padding: 7px 10px; border-bottom: 1px solid #f3f4f6; color: #374151; }
        tr:last-child td { border-bottom: none; }
        tr:nth-child(even) td { background: #f9fafb; }

        /* Badges */
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 999px;
            font-size: 9px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; border: 1px solid transparent;
        }
        .b-green  { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
        .b-blue   { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .b-amber  { background:#fffbeb; color:#b45309; border-color:#fde68a; }
        .b-red    { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
        .b-slate  { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }

        /* Progress bar */
        .prog-wrap { background:#e5e7eb; border-radius:999px; height:7px;
            overflow:hidden; margin-top:4px; }
        .prog-fill { height:100%; border-radius:999px; }
        .c-high { background:#15803d; } .c-mid { background:#b45309; } .c-low { background:#b91c1c; }

        /* Two column grid */
        .two-col { display: table; width: 100%; border-collapse: separate; border-spacing: 12px 0; }
        .col     { display: table-cell; width: 50%; vertical-align: top; }

        /* Footer */
        .pdf-footer {
            margin-top: 28px; padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px; color: #9ca3af; text-align: center;
        }
    </style>
    </head>
    <body>

    <!-- Cover -->
    <div class="cover">
        <h1>Analytics &amp; Reports Overview</h1>
        <p>Generated: <?= date('d M Y H:i') ?> &nbsp;·&nbsp; All data as of today</p>
    </div>

    <!-- Summary stats -->
    <div class="stats-row">
        <div class="stat-cell">
            <div class="lbl">Programs</div>
            <div class="val"><?= $total_programs ?></div>
        </div>
        <div class="stat-cell">
            <div class="lbl">Projects</div>
            <div class="val"><?= $total_projects ?></div>
        </div>
        <div class="stat-cell">
            <div class="lbl">Participants</div>
            <div class="val"><?= number_format($total_beneficiaries) ?></div>
        </div>
        <div class="stat-cell">
            <div class="lbl">Indicators</div>
            <div class="val"><?= $total_indicators ?></div>
        </div>
    </div>

    <div class="two-col">
        <!-- Left column -->
        <div class="col">

            <!-- Programs -->
            <div class="section">
                <div class="section-head">&#9724; Programs by Status</div>
                <div class="section-body">
                    <?php if (empty($programs_data)): ?>
                        <p style="color:#9ca3af;font-style:italic;">No programs yet.</p>
                    <?php else: ?>
                        <table>
                            <tr><th>Status</th><th>Count</th></tr>
                            <?php foreach ($programs_data as $s => $c):
                                $bc = match(strtolower($s)){
                                    'ongoing' => 'b-green', 'completed' => 'b-blue',
                                    'pending' => 'b-amber', 'cancelled' => 'b-red', default => 'b-slate'
                                };
                            ?>
                                <tr>
                                    <td><span class="badge <?= $bc ?>"><?= h($s) ?></span></td>
                                    <td><strong><?= $c ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td><strong>Total</strong></td>
                                <td><strong><?= $total_programs ?></strong></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Projects -->
            <div class="section">
                <div class="section-head">&#9724; Projects by Status</div>
                <div class="section-body">
                    <?php if (empty($projects_data)): ?>
                        <p style="color:#9ca3af;font-style:italic;">No projects yet.</p>
                    <?php else: ?>
                        <table>
                            <tr><th>Status</th><th>Count</th></tr>
                            <?php foreach ($projects_data as $s => $c):
                                $bc = match(strtolower($s)){
                                    'ongoing' => 'b-green', 'completed' => 'b-blue',
                                    'pending' => 'b-amber', 'cancelled' => 'b-red', default => 'b-slate'
                                };
                            ?>
                                <tr>
                                    <td><span class="badge <?= $bc ?>"><?= h($s) ?></span></td>
                                    <td><strong><?= $c ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td><strong>Total</strong></td>
                                <td><strong><?= $total_projects ?></strong></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Events -->
            <div class="section">
                <div class="section-head">&#9724; Events Summary</div>
                <div class="section-body">
                    <table>
                        <tr><th>Metric</th><th>Value</th></tr>
                        <tr><td>Total Events</td><td><strong><?= $events_data['total'] ?></strong></td></tr>
                        <tr><td>Last 30 Days</td><td><strong style="color:#15803d;"><?= $events_data['recent'] ?></strong></td></tr>
                    </table>
                </div>
            </div>

        </div><!-- /left col -->

        <!-- Right column -->
        <div class="col">

            <!-- Participants by gender -->
            <div class="section">
                <div class="section-head">&#9724; Participants by Gender</div>
                <div class="section-body">
                    <?php if (empty($gender_data)): ?>
                        <p style="color:#9ca3af;font-style:italic;">No participant data.</p>
                    <?php else: ?>
                        <table>
                            <tr><th>Gender</th><th>Count</th><th>%</th></tr>
                            <?php foreach ($gender_data as $g => $c):
                                $pct2 = $total_beneficiaries > 0 ? round($c/$total_beneficiaries*100,1) : 0;
                            ?>
                                <tr>
                                    <td><?= h($g) ?></td>
                                    <td><strong><?= number_format($c) ?></strong></td>
                                    <td><?= $pct2 ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td><strong>Total</strong></td>
                                <td><strong><?= number_format($total_beneficiaries) ?></strong></td>
                                <td>100%</td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Indicators -->
            <div class="section">
                <div class="section-head">&#9724; Indicators Performance</div>
                <div class="section-body">
                    <table>
                        <tr><th>Category</th><th>Count</th><th>Progress</th></tr>
                        <?php
                        $ind_rows = [
                            ['Achieved (≥100%)', $ind_data['achieved'], 'c-high'],
                            ['Partial (50–99%)', $ind_data['partial'],  'c-mid'],
                            ['Low (<50%)',        $ind_data['low'],      'c-low'],
                        ];
                        foreach ($ind_rows as [$label, $cnt, $cls]):
                            $bar_w = $total_indicators > 0 ? min(round($cnt/$total_indicators*100), 100) : 0;
                        ?>
                            <tr>
                                <td><?= $label ?></td>
                                <td><strong><?= $cnt ?></strong></td>
                                <td style="width:80px;">
                                    <div class="prog-wrap">
                                        <div class="prog-fill <?= $cls ?>" style="width:<?= $bar_w ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><strong>Total</strong></td>
                            <td><strong><?= $total_indicators ?></strong></td>
                            <td></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- PWD -->
            <div class="section">
                <div class="section-head">&#9724; PWD Statistics</div>
                <div class="section-body">
                    <table>
                        <tr><th>Category</th><th>Count</th></tr>
                        <tr>
                            <td>Persons with Disabilities</td>
                            <td><strong style="color:#b45309;"><?= number_format($pwd_data['pwd']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Non-PWD</td>
                            <td><strong><?= number_format($pwd_data['non_pwd']) ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>PWD Inclusion Rate</strong></td>
                            <td><strong style="color:#b45309;"><?= $pwd_pct ?>%</strong></td>
                        </tr>
                    </table>
                </div>
            </div>

        </div><!-- /right col -->
    </div>

    <div class="pdf-footer">
        Confidential &nbsp;·&nbsp; Analytics Overview &nbsp;·&nbsp; <?= date('Y') ?>
    </div>

    </body>
    </html>
    <?php
    $pdf_html = ob_get_clean();

    /* Dompdf render */
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isPhpEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($pdf_html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'analytics_overview_' . date('Ymd_His') . '.pdf';

    /* Save to exports/ */
    $exports_dir = __DIR__ . '/exports';
    if (!is_dir($exports_dir)) @mkdir($exports_dir, 0755, true);
    if (is_dir($exports_dir) && is_writable($exports_dir)) {
        file_put_contents($exports_dir . '/' . $filename, $dompdf->output());
    }

    while (ob_get_level()) ob_end_clean();
    $dompdf->stream($filename, ['Attachment' => false]);
    exit;
}


$page_title = 'Reports & Analytics';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="css/reports.css">

<div class="reports-wrap">

    <!-- ── Hero ───────────────────────────────────────────────────────── -->
    <div class="reports-hero">
        <div class="reports-hero-text">
            <h1><i class="fas fa-chart-bar" style="margin-right:10px;opacity:.85;"></i>Reports &amp; Analytics</h1>
            <p>Generate, explore and export organisation-wide reports across programmes, projects and participants.</p>
        </div>
        <div class="hero-actions">
            <!-- PDF export -->
            <a href="reports?export=pdf" target="_blank"
               class="btn btn-primary"
               style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
            <!-- CSV overview export -->
            <a href="reports?export=csv&section=overview"
               class="btn btn-primary"
               style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.28);">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <button onclick="window.print()"
                    class="btn btn-primary"
                    style="background:rgba(255,255,255,.10);color:#fff;border:1px solid rgba(255,255,255,.2);">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- ── Summary Stats ──────────────────────────────────────────────── -->
    <div class="reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-sitemap"></i></div>
            <div class="stat-info">
                <div class="stat-label">Programs</div>
                <div class="stat-value"><?= $total_programs ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-project-diagram"></i></div>
            <div class="stat-info">
                <div class="stat-label">Projects</div>
                <div class="stat-value"><?= $total_projects ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <div class="stat-label">Participants</div>
                <div class="stat-value"><?= number_format($total_beneficiaries) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Indicators</div>
                <div class="stat-value"><?= $total_indicators ?></div>
            </div>
        </div>
    </div>

    <!-- ── Report Controls ────────────────────────────────────────────── -->
    <div class="controls-bar filters-bar" role="group" aria-label="Report controls">
        <div class="form-group grow filters-grow">
            <label class="form-label" for="reportType">Report Type</label>
            <select id="reportType" class="form-control">
                <option value="overview">Overview Dashboard</option>
                <option value="programs">Programs Report</option>
                <option value="projects">Projects Report</option>
                <option value="beneficiaries">Participants Report</option>
                <option value="indicators">Indicators Performance</option>
                <option value="financial">Financial Report</option>
                <option value="events">Events Report</option>
                <option value="hub">Hub Operations</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="timePeriod">Time Period</label>
            <select id="timePeriod" class="form-control">
                <option value="all">All Time</option>
                <option value="year">This Year</option>
                <option value="quarter">This Quarter</option>
                <option value="month">This Month</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="reportFormat">Format</label>
            <select id="reportFormat" class="form-control">
                <option value="html">Web View</option>
                <option value="pdf">PDF</option>
                <option value="excel">Excel</option>
                <option value="powerbi">Power BI</option>
            </select>
        </div>
        <div class="ctrl-actions">
            <button onclick="generateReport()" class="btn btn-dark">
                <i class="fas fa-chart-line"></i> Generate
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         TABS
         ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-shell">

        <div class="tab-header" role="tablist">
            <button class="tab-btn active" data-tab="overview"     role="tab"><i class="fas fa-th-large"></i>    Overview</button>
            <button class="tab-btn"        data-tab="programs"     role="tab"><i class="fas fa-sitemap"></i>      Programs</button>
            <button class="tab-btn"        data-tab="projects"     role="tab"><i class="fas fa-project-diagram"></i> Projects</button>
            <button class="tab-btn"        data-tab="participants" role="tab"><i class="fas fa-users"></i>        Participants</button>
            <button class="tab-btn"        data-tab="indicators"   role="tab"><i class="fas fa-chart-line"></i>   Indicators</button>
            <button class="tab-btn"        data-tab="powerbi"      role="tab"><i class="fas fa-chart-pie"></i>    Power BI</button>
            <button class="tab-btn"        data-tab="exports"      role="tab"><i class="fas fa-download"></i>     Exports</button>
        </div>

        <div class="tab-panels">

            <!-- ── OVERVIEW ─────────────────────────────────────────────── -->
            <div class="tab-panel active" id="tab-overview">
                <div class="report-grid">

                    <!-- Programs -->
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-amber"><i class="fas fa-sitemap"></i></div>
                            <span class="report-card-title">Programs Summary</span>
                            <a href="reports?export=csv&section=programs" class="btn btn-sm btn-gray" style="margin-left:auto;" title="Download CSV">
                                <i class="fas fa-file-csv"></i>
                            </a>
                        </div>
                        <div class="report-card-chart"><canvas id="programsChart"></canvas></div>
                        <div class="report-card-breakdown">
                            <?php if (empty($programs_data)): ?>
                                <div class="empty-state"><i class="fas fa-folder-open"></i><span>No programs yet</span></div>
                            <?php else: foreach ($programs_data as $s => $c): ?>
                                <div class="breakdown-row">
                                    <span class="breakdown-label"><?= h($s) ?></span>
                                    <span class="breakdown-value"><?= $c ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>

                    <!-- Projects -->
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-blue"><i class="fas fa-project-diagram"></i></div>
                            <span class="report-card-title">Projects Summary</span>
                            <a href="reports?export=csv&section=projects" class="btn btn-sm btn-gray" style="margin-left:auto;" title="Download CSV">
                                <i class="fas fa-file-csv"></i>
                            </a>
                        </div>
                        <div class="report-card-chart"><canvas id="projectsChart"></canvas></div>
                        <div class="report-card-breakdown">
                            <?php if (empty($projects_data)): ?>
                                <div class="empty-state"><i class="fas fa-folder-open"></i><span>No projects yet</span></div>
                            <?php else: foreach ($projects_data as $s => $c): ?>
                                <div class="breakdown-row">
                                    <span class="breakdown-label"><?= h($s) ?></span>
                                    <span class="breakdown-value"><?= $c ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>

                    <!-- Participants by Gender -->
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-green"><i class="fas fa-users"></i></div>
                            <span class="report-card-title">Participants by Gender</span>
                            <a href="reports?export=csv&section=participants" class="btn btn-sm btn-gray" style="margin-left:auto;" title="Download CSV">
                                <i class="fas fa-file-csv"></i>
                            </a>
                        </div>
                        <div class="report-card-chart"><canvas id="genderChart"></canvas></div>
                        <div class="report-card-breakdown">
                            <?php foreach ($gender_data as $g => $c): ?>
                                <div class="breakdown-row">
                                    <span class="breakdown-label"><?= h($g) ?></span>
                                    <span class="breakdown-value blue"><?= number_format($c) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="breakdown-row" style="border-top:2px solid var(--ink-100);margin-top:4px;">
                                <span class="breakdown-label" style="font-weight:800;">Total</span>
                                <span class="breakdown-value"><?= number_format($total_beneficiaries) ?></span>
                            </div>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>

                    <!-- Indicators Performance -->
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-primary"><i class="fas fa-chart-line"></i></div>
                            <span class="report-card-title">Indicators Performance</span>
                            <a href="reports?export=csv&section=indicators" class="btn btn-sm btn-gray" style="margin-left:auto;" title="Download CSV">
                                <i class="fas fa-file-csv"></i>
                            </a>
                        </div>
                        <div class="report-card-chart"><canvas id="indicatorsChart"></canvas></div>
                        <div class="report-card-breakdown">
                            <div class="breakdown-row">
                                <span class="breakdown-label">Achieved (≥ 100%)</span>
                                <span class="breakdown-value green"><?= $ind_data['achieved'] ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Partial (50 – 99%)</span>
                                <span class="breakdown-value amber"><?= $ind_data['partial'] ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Low (&lt; 50%)</span>
                                <span class="breakdown-value red"><?= $ind_data['low'] ?></span>
                            </div>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>

                    <!-- PWD -->
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-amber"><i class="fas fa-wheelchair"></i></div>
                            <span class="report-card-title">PWD Statistics</span>
                            <a href="reports?export=csv&section=pwd" class="btn btn-sm btn-gray" style="margin-left:auto;" title="Download CSV">
                                <i class="fas fa-file-csv"></i>
                            </a>
                        </div>
                        <div class="report-card-chart"><canvas id="pwdChart"></canvas></div>
                        <div class="report-card-breakdown">
                            <div class="breakdown-row">
                                <span class="breakdown-label">Persons with Disabilities</span>
                                <span class="breakdown-value amber"><?= number_format($pwd_data['pwd']) ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Non-PWD</span>
                                <span class="breakdown-value"><?= number_format($pwd_data['non_pwd']) ?></span>
                            </div>
                            <div class="breakdown-row" style="border-top:2px solid var(--ink-100);margin-top:4px;">
                                <span class="breakdown-label" style="font-weight:800;">PWD Inclusion Rate</span>
                                <span class="breakdown-value amber"><?= $pwd_pct ?>%</span>
                            </div>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>

                    <!-- Events -->
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-purple"><i class="fas fa-calendar-check"></i></div>
                            <span class="report-card-title">Events Summary</span>
                            <a href="reports?export=csv&section=events" class="btn btn-sm btn-gray" style="margin-left:auto;" title="Download CSV">
                                <i class="fas fa-file-csv"></i>
                            </a>
                        </div>
                        <div class="big-number-block">
                            <div class="big-number" style="color:var(--purple-fg);"><?= $events_data['total'] ?></div>
                            <div class="big-number-label">Total Events</div>
                        </div>
                        <div class="sub-number-block">
                            <div class="sub-number"><?= $events_data['recent'] ?></div>
                            <div class="sub-number-label">In the last 30 days</div>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>

                </div><!-- /.report-grid -->
            </div><!-- /#tab-overview -->

            <!-- ── PROGRAMS ──────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-programs">
                <div class="report-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="report-card" style="grid-column:1/-1;">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-amber"><i class="fas fa-sitemap"></i></div>
                            <span class="report-card-title">Programs by Status</span>
                            <div class="actions" style="margin-left:auto;">
                                <a href="reports?export=csv&section=programs" class="btn btn-sm btn-gray"><i class="fas fa-file-csv"></i> CSV</a>
                                <a href="reports?export=pdf" target="_blank" class="btn btn-sm btn-soft"><i class="fas fa-file-pdf"></i> PDF</a>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
                            <div class="report-card-chart" style="padding:24px 28px;"><canvas id="programsChartFull"></canvas></div>
                            <div class="report-card-breakdown" style="padding:24px 28px;">
                                <?php foreach ($programs_data as $s => $c): ?>
                                    <div class="breakdown-row" style="font-size:13px;padding:10px 0;">
                                        <span class="breakdown-label"><?= h($s) ?></span>
                                        <span class="breakdown-value"><?= $c ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div class="breakdown-row" style="border-top:2px solid var(--ink-100);font-size:13px;padding:10px 0;">
                                    <span class="breakdown-label" style="font-weight:800;">Total</span>
                                    <span class="breakdown-value"><?= $total_programs ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── PROJECTS ──────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-projects">
                <div class="report-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="report-card" style="grid-column:1/-1;">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-blue"><i class="fas fa-project-diagram"></i></div>
                            <span class="report-card-title">Projects by Status</span>
                            <div class="actions" style="margin-left:auto;">
                                <a href="reports?export=csv&section=projects" class="btn btn-sm btn-gray"><i class="fas fa-file-csv"></i> CSV</a>
                                <a href="reports?export=pdf" target="_blank" class="btn btn-sm btn-soft"><i class="fas fa-file-pdf"></i> PDF</a>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
                            <div class="report-card-chart" style="padding:24px 28px;"><canvas id="projectsChartFull"></canvas></div>
                            <div class="report-card-breakdown" style="padding:24px 28px;">
                                <?php foreach ($projects_data as $s => $c): ?>
                                    <div class="breakdown-row" style="font-size:13px;padding:10px 0;">
                                        <span class="breakdown-label"><?= h($s) ?></span>
                                        <span class="breakdown-value"><?= $c ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div class="breakdown-row" style="border-top:2px solid var(--ink-100);font-size:13px;padding:10px 0;">
                                    <span class="breakdown-label" style="font-weight:800;">Total</span>
                                    <span class="breakdown-value"><?= $total_projects ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── PARTICIPANTS ───────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-participants">
                <div class="report-grid">
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-green"><i class="fas fa-venus-mars"></i></div>
                            <span class="report-card-title">By Gender</span>
                            <a href="reports?export=csv&section=participants" class="btn btn-sm btn-gray" style="margin-left:auto;"><i class="fas fa-file-csv"></i> CSV</a>
                        </div>
                        <div class="report-card-chart"><canvas id="genderChartFull"></canvas></div>
                        <div class="report-card-breakdown">
                            <?php foreach ($gender_data as $g => $c): ?>
                                <div class="breakdown-row">
                                    <span class="breakdown-label"><?= h($g) ?></span>
                                    <span class="breakdown-value blue"><?= number_format($c) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>
                    <div class="report-card">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-amber"><i class="fas fa-wheelchair"></i></div>
                            <span class="report-card-title">PWD vs Non-PWD</span>
                            <a href="reports?export=csv&section=pwd" class="btn btn-sm btn-gray" style="margin-left:auto;"><i class="fas fa-file-csv"></i> CSV</a>
                        </div>
                        <div class="report-card-chart"><canvas id="pwdChartFull"></canvas></div>
                        <div class="report-card-breakdown">
                            <div class="breakdown-row">
                                <span class="breakdown-label">Persons with Disabilities</span>
                                <span class="breakdown-value amber"><?= number_format($pwd_data['pwd']) ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Non-PWD</span>
                                <span class="breakdown-value"><?= number_format($pwd_data['non_pwd']) ?></span>
                            </div>
                            <div class="breakdown-row" style="border-top:2px solid var(--ink-100);margin-top:4px;">
                                <span class="breakdown-label" style="font-weight:800;">Inclusion Rate</span>
                                <span class="breakdown-value amber"><?= $pwd_pct ?>%</span>
                            </div>
                        </div>
                        <div class="report-card-foot">
                            <a href="generate-report" class="btn btn-sm btn-soft"><i class="fas fa-eye"></i> Full Report</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── INDICATORS ─────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-indicators">
                <div class="report-grid">
                    <div class="report-card" style="grid-column:1/-1;">
                        <div class="report-card-head">
                            <div class="report-card-icon bg-primary"><i class="fas fa-chart-bar"></i></div>
                            <span class="report-card-title">Indicators Achievement Overview</span>
                            <div class="actions" style="margin-left:auto;">
                                <a href="reports?export=csv&section=indicators" class="btn btn-sm btn-gray"><i class="fas fa-file-csv"></i> CSV</a>
                                <a href="reports?export=pdf" target="_blank" class="btn btn-sm btn-soft"><i class="fas fa-file-pdf"></i> PDF</a>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
                            <div class="report-card-chart" style="padding:24px 28px;"><canvas id="indicatorsChartFull"></canvas></div>
                            <div class="report-card-breakdown" style="padding:24px 28px;">
                                <div class="breakdown-row" style="font-size:13px;padding:12px 0;">
                                    <span class="breakdown-label">Total Indicators</span>
                                    <span class="breakdown-value"><?= $total_indicators ?></span>
                                </div>
                                <div class="breakdown-row" style="font-size:13px;padding:12px 0;">
                                    <span class="breakdown-label">Achieved (≥ 100%)</span>
                                    <span class="breakdown-value green"><?= $ind_data['achieved'] ?></span>
                                </div>
                                <div class="breakdown-row" style="font-size:13px;padding:12px 0;">
                                    <span class="breakdown-label">Partial (50 – 99%)</span>
                                    <span class="breakdown-value amber"><?= $ind_data['partial'] ?></span>
                                </div>
                                <div class="breakdown-row" style="font-size:13px;padding:12px 0;">
                                    <span class="breakdown-label">Low (&lt; 50%)</span>
                                    <span class="breakdown-value red"><?= $ind_data['low'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── POWER BI ───────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-powerbi">
                <?php if (!empty($powerbi_settings['embed_url'])): ?>
                    <div class="powerbi-controls">
                        <span style="font-size:13px;font-weight:700;color:var(--ink-500);">
                            <i class="fas fa-chart-pie" style="color:var(--blue-fg);margin-right:6px;"></i>Power BI Live Dashboard
                        </span>
                        <button onclick="document.getElementById('powerBIFrame').requestFullscreen()" class="btn btn-sm btn-gray">
                            <i class="fas fa-expand"></i> Fullscreen
                        </button>
                    </div>
                    <div class="powerbi-frame-wrap">
                        <iframe id="powerBIFrame" src="<?= h($powerbi_settings['embed_url']) ?>" allowfullscreen></iframe>
                    </div>
                <?php else: ?>
                    <div class="powerbi-unconfigured">
                        <div class="powerbi-icon-wrap"><i class="fas fa-chart-pie"></i></div>
                        <h3>Power BI Not Configured</h3>
                        <p>Connect your Power BI workspace to visualise real-time analytics.</p>
                        <?php if (ims_allowed('reports.configure_powerbi', ['Administrator'], 'See the Configure Power BI link')): ?>
                            <a href="/settings?tab=powerbi" class="btn btn-dark"><i class="fas fa-cog"></i> Configure Power BI</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── EXPORTS ────────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-exports">
                <p class="note" style="margin-bottom:18px;">
                    <i class="fas fa-info-circle" style="margin-right:5px;"></i>
                    CSV files download immediately. PDF opens in a new tab via Dompdf. Detailed reports open in the browser.
                </p>
                <div class="exports-grid">
                    <!-- PDF exports -->
                    <a href="reports?export=pdf" target="_blank" class="export-item">
                        <div class="export-icon pdf"><i class="fas fa-file-pdf"></i></div>
                        <div class="export-item-body">
                            <div class="export-item-name">Analytics Overview (PDF)</div>
                            <div class="export-item-desc">Full overview report rendered via Dompdf</div>
                        </div>
                    </a>
                    <!-- CSV exports -->
                    <?php
                    $csv_exports = [
                        ['overview',     'fa-table',         'Overview Summary (CSV)',     'All categories combined'],
                        ['programs',     'fa-sitemap',       'Programs List (CSV)',         'Programs by status'],
                        ['projects',     'fa-project-diagram','Projects List (CSV)',         'Projects by status'],
                        ['participants', 'fa-users',         'Participants (CSV)',          'Full participant register'],
                        ['indicators',   'fa-chart-line',    'Indicators (CSV)',            'Target vs actual values'],
                        ['pwd',          'fa-wheelchair',    'PWD Statistics (CSV)',       'Disability inclusion data'],
                        ['events',       'fa-calendar',      'Events (CSV)',               'Hub events and attendance'],
                    ];
                    foreach ($csv_exports as [$sec, $ico, $name, $desc]):
                    ?>
                        <a href="reports?export=csv&section=<?= $sec ?>" class="export-item">
                            <div class="export-icon excel"><i class="fas <?= $ico ?>"></i></div>
                            <div class="export-item-body">
                                <div class="export-item-name"><?= $name ?></div>
                                <div class="export-item-desc"><?= $desc ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <!-- Detailed web reports -->
                    <a href="generate-report" class="export-item">
                        <div class="export-icon web"><i class="fas fa-globe"></i></div>
                        <div class="export-item-body">
                            <div class="export-item-name">Custom Report Builder</div>
                            <div class="export-item-desc">Build detailed reports with filters</div>
                        </div>
                    </a>
                </div>
            </div>

        </div><!-- /.tab-panels -->
    </div><!-- /.tab-shell -->

</div><!-- /.reports-wrap -->

<script>
(function(){
    'use strict';

    const FONT = "'DM Sans', system-ui, sans-serif";

    const BG = [
        'rgba(21,128,61,.75)',   // green
        'rgba(29,78,216,.75)',   // blue
        'rgba(180,83,9,.75)',    // amber
        'rgba(185,28,28,.75)',   // red
        'rgba(71,85,105,.75)',   // slate
        'rgba(124,58,237,.75)',  // purple
        'rgba(249,115,22,.75)',  // brand
    ];
    const BD = BG.map(c => c.replace(/[\d.]+\)$/,'1)'));

    const OPTS = {
        responsive:true, maintainAspectRatio:true,
        plugins:{
            legend:{ position:'bottom', labels:{ font:{family:FONT,size:11}, padding:14, usePointStyle:true }},
            tooltip:{ bodyFont:{family:FONT}, titleFont:{family:FONT,weight:'700'}},
        },
    };

    function donut(id, labels, values) {
        const el = document.getElementById(id);
        if (!el || !labels.length) return;
        new Chart(el, {
            type:'doughnut',
            data:{ labels, datasets:[{ data:values, backgroundColor:BG.slice(0,values.length),
                borderColor:BD.slice(0,values.length), borderWidth:1.5, hoverOffset:6 }]},
            options:{ ...OPTS, cutout:'62%' },
        });
    }

    function bar(id, labels, values, colors) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type:'bar',
            data:{ labels, datasets:[{ data:values, backgroundColor:colors,
                borderRadius:6, borderSkipped:false }]},
            options:{
                ...OPTS,
                plugins:{ ...OPTS.plugins, legend:{ display:false }},
                scales:{
                    y:{ beginAtZero:true, ticks:{ stepSize:1, font:{family:FONT,size:11}},
                        grid:{ color:'rgba(0,0,0,.06)'}},
                    x:{ ticks:{ font:{family:FONT,size:11}}, grid:{ display:false }},
                },
            },
        });
    }

    /* Data from PHP */
    const PD  = <?= json_encode($programs_data,  JSON_THROW_ON_ERROR) ?>;
    const PRD = <?= json_encode($projects_data,  JSON_THROW_ON_ERROR) ?>;
    const GD  = <?= json_encode($gender_data,    JSON_THROW_ON_ERROR) ?>;
    const ID  = <?= json_encode($ind_data,       JSON_THROW_ON_ERROR) ?>;
    const PWD = <?= json_encode($pwd_data,       JSON_THROW_ON_ERROR) ?>;

    /* Build all charts */
    donut('programsChart',     Object.keys(PD),  Object.values(PD));
    donut('projectsChart',     Object.keys(PRD), Object.values(PRD));
    donut('genderChart',       Object.keys(GD),  Object.values(GD));
    donut('pwdChart',          ['PWD','Non-PWD'], [PWD.pwd||0, PWD.non_pwd||0]);
    donut('programsChartFull', Object.keys(PD),  Object.values(PD));
    donut('projectsChartFull', Object.keys(PRD), Object.values(PRD));
    donut('genderChartFull',   Object.keys(GD),  Object.values(GD));
    donut('pwdChartFull',      ['PWD','Non-PWD'], [PWD.pwd||0, PWD.non_pwd||0]);

    bar('indicatorsChart',
        ['Achieved','Partial','Low'],
        [ID.achieved||0, ID.partial||0, ID.low||0],
        ['rgba(21,128,61,.75)','rgba(180,83,9,.75)','rgba(185,28,28,.75)']
    );
    bar('indicatorsChartFull',
        ['Achieved','Partial','Low'],
        [ID.achieved||0, ID.partial||0, ID.low||0],
        ['rgba(21,128,61,.75)','rgba(180,83,9,.75)','rgba(185,28,28,.75)']
    );

    /* Tab switching */
    const btns   = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.tab-panel');
    btns.forEach(function(btn){
        btn.addEventListener('click', function(){
            btns.forEach(function(b){ b.classList.remove('active'); });
            panels.forEach(function(p){ p.classList.remove('active'); });
            btn.classList.add('active');
            const t = document.getElementById('tab-'+btn.dataset.tab);
            if(t) t.classList.add('active');
        });
    });

    /* Generate report button */
    window.generateReport = function(){
        const type   = document.getElementById('reportType').value;
        const period = document.getElementById('timePeriod').value;
        const format = document.getElementById('reportFormat').value;

        if(format === 'powerbi'){
            document.querySelector('[data-tab="powerbi"]').click(); return;
        }
        if(format === 'pdf'){
            window.open('reports?export=pdf','_blank'); return;
        }
        if(format === 'excel'){
            window.location.href = 'reports?export=csv&section=' + type; return;
        }
        window.location.href = 'generate-report?type='+type+'&period='+period+'&format=html';
    };
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>