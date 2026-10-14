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
<link rel="stylesheet" href="css/reports-page.css">
<?php
/* Display helpers (presentation only). */
$rp_pct = static function (int $n, int $total): float {
    return $total > 0 ? round($n / $total * 100, 1) : 0.0;
};
$rp_plural = static function (int $n, string $one, string $many): string {
    return number_format($n) . ' ' . ($n === 1 ? $one : $many);
};
?>

<div class="reports-wrap rp-page">

    <!-- ── Hero ───────────────────────────────────────────────────────── -->
    <header class="reports-hero rp-hero">
        <div class="reports-hero-text">
            <p class="rp-hero-eyebrow">
                <i class="fas fa-clock" aria-hidden="true"></i> Live data &middot; <?= date('d M Y') ?>
            </p>
            <h1><i class="fas fa-chart-bar rp-hero-icon" aria-hidden="true"></i>Reports &amp; Analytics</h1>
            <p>Generate, explore and export organisation-wide reports across programmes, projects and participants.</p>
        </div>
        <div class="hero-actions">
            <!-- PDF export -->
            <a href="reports?export=pdf" target="_blank" rel="noopener" class="btn rp-hero-btn rp-hero-btn-solid">
                <i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF
            </a>
            <!-- CSV overview export -->
            <a href="reports?export=csv&amp;section=overview" class="btn rp-hero-btn">
                <i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV
            </a>
            <button type="button" onclick="window.print()" class="btn rp-hero-btn">
                <i class="fas fa-print" aria-hidden="true"></i> Print
            </button>
        </div>
    </header>

    <!-- ── Summary Stats ──────────────────────────────────────────────── -->
    <section class="reports-stats" aria-label="Summary totals">
        <div class="stat-card">
            <div class="stat-icon bg-amber" aria-hidden="true"><i class="fas fa-sitemap"></i></div>
            <div class="stat-info">
                <div class="stat-label">Programs</div>
                <div class="stat-value"><?= number_format($total_programs) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue" aria-hidden="true"><i class="fas fa-project-diagram"></i></div>
            <div class="stat-info">
                <div class="stat-label">Projects</div>
                <div class="stat-value"><?= number_format($total_projects) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green" aria-hidden="true"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <div class="stat-label">Participants</div>
                <div class="stat-value"><?= number_format($total_beneficiaries) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary" aria-hidden="true"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Indicators</div>
                <div class="stat-value"><?= number_format($total_indicators) ?></div>
            </div>
        </div>
    </section>

    <!-- ── Report Controls ────────────────────────────────────────────── -->
    <section class="rp-builder" aria-labelledby="rpBuilderTitle">
        <div class="rp-builder-head">
            <span class="rp-builder-icon bg-primary" aria-hidden="true"><i class="fas fa-sliders"></i></span>
            <div>
                <h2 class="rp-section-title" id="rpBuilderTitle">Report builder</h2>
                <p class="rp-section-sub">Choose a report, time period and output format, then generate.</p>
            </div>
        </div>
        <div class="controls-bar filters-bar" role="group" aria-labelledby="rpBuilderTitle">
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
                <button type="button" onclick="generateReport()" class="btn btn-primary">
                    <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Generate report
                </button>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════════════════════════
         TABS
         ══════════════════════════════════════════════════════════════════ -->
    <section class="tab-shell rp-tabs" aria-label="Report views">

        <div class="tab-header" role="tablist" aria-label="Report sections">
            <button type="button" class="tab-btn active" data-tab="overview"     role="tab" id="tabbtn-overview"     aria-controls="tab-overview"     aria-selected="true"><i class="fas fa-th-large" aria-hidden="true"></i> Overview</button>
            <button type="button" class="tab-btn"        data-tab="programs"     role="tab" id="tabbtn-programs"     aria-controls="tab-programs"     aria-selected="false"><i class="fas fa-sitemap" aria-hidden="true"></i> Programs</button>
            <button type="button" class="tab-btn"        data-tab="projects"     role="tab" id="tabbtn-projects"     aria-controls="tab-projects"     aria-selected="false"><i class="fas fa-project-diagram" aria-hidden="true"></i> Projects</button>
            <button type="button" class="tab-btn"        data-tab="participants" role="tab" id="tabbtn-participants" aria-controls="tab-participants" aria-selected="false"><i class="fas fa-users" aria-hidden="true"></i> Participants</button>
            <button type="button" class="tab-btn"        data-tab="indicators"   role="tab" id="tabbtn-indicators"   aria-controls="tab-indicators"   aria-selected="false"><i class="fas fa-chart-line" aria-hidden="true"></i> Indicators</button>
            <button type="button" class="tab-btn"        data-tab="powerbi"      role="tab" id="tabbtn-powerbi"      aria-controls="tab-powerbi"      aria-selected="false"><i class="fas fa-chart-pie" aria-hidden="true"></i> Power BI</button>
            <button type="button" class="tab-btn"        data-tab="exports"      role="tab" id="tabbtn-exports"      aria-controls="tab-exports"      aria-selected="false"><i class="fas fa-download" aria-hidden="true"></i> Exports</button>
        </div>

        <div class="tab-panels">

            <!-- ── OVERVIEW ─────────────────────────────────────────────── -->
            <div class="tab-panel active" id="tab-overview" role="tabpanel" aria-labelledby="tabbtn-overview">
                <div class="rp-grid">

                    <!-- Programs -->
                    <article class="rp-card" aria-labelledby="rpOvPrograms">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-amber" aria-hidden="true"><i class="fas fa-sitemap"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpOvPrograms">Programs Summary</h3>
                                <p class="rp-card-sub"><?= $rp_plural($total_programs, 'program', 'programs') ?> by status</p>
                            </div>
                            <a href="reports?export=csv&amp;section=programs" class="rp-icon-btn" title="Download CSV" aria-label="Download programs CSV">
                                <i class="fas fa-file-csv" aria-hidden="true"></i>
                            </a>
                        </header>
                        <div class="rp-card-body">
                            <?php if (empty($programs_data)): ?>
                                <div class="empty-state rp-empty"><i class="fas fa-folder-open" aria-hidden="true"></i><span>No programs yet</span></div>
                            <?php else: ?>
                                <div class="rp-chart"><canvas id="programsChart" role="img" aria-label="Doughnut chart of programs by status"></canvas></div>
                                <ul class="rp-list">
                                    <?php $i = 0; foreach ($programs_data as $s => $c): ?>
                                        <li class="rp-row">
                                            <span class="rp-dot rp-c-<?= $i++ % 7 ?>" aria-hidden="true"></span>
                                            <span class="rp-row-label"><?= h((string)$s) ?></span>
                                            <span class="rp-row-value"><?= number_format($c) ?></span>
                                            <span class="rp-row-pct"><?= $rp_pct($c, $total_programs) ?>%</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>

                    <!-- Projects -->
                    <article class="rp-card" aria-labelledby="rpOvProjects">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-blue" aria-hidden="true"><i class="fas fa-project-diagram"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpOvProjects">Projects Summary</h3>
                                <p class="rp-card-sub"><?= $rp_plural($total_projects, 'project', 'projects') ?> by status</p>
                            </div>
                            <a href="reports?export=csv&amp;section=projects" class="rp-icon-btn" title="Download CSV" aria-label="Download projects CSV">
                                <i class="fas fa-file-csv" aria-hidden="true"></i>
                            </a>
                        </header>
                        <div class="rp-card-body">
                            <?php if (empty($projects_data)): ?>
                                <div class="empty-state rp-empty"><i class="fas fa-folder-open" aria-hidden="true"></i><span>No projects yet</span></div>
                            <?php else: ?>
                                <div class="rp-chart"><canvas id="projectsChart" role="img" aria-label="Doughnut chart of projects by status"></canvas></div>
                                <ul class="rp-list">
                                    <?php $i = 0; foreach ($projects_data as $s => $c): ?>
                                        <li class="rp-row">
                                            <span class="rp-dot rp-c-<?= $i++ % 7 ?>" aria-hidden="true"></span>
                                            <span class="rp-row-label"><?= h((string)$s) ?></span>
                                            <span class="rp-row-value"><?= number_format($c) ?></span>
                                            <span class="rp-row-pct"><?= $rp_pct($c, $total_projects) ?>%</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>

                    <!-- Participants by Gender -->
                    <article class="rp-card" aria-labelledby="rpOvGender">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-green" aria-hidden="true"><i class="fas fa-users"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpOvGender">Participants by Gender</h3>
                                <p class="rp-card-sub"><?= $rp_plural($total_beneficiaries, 'participant', 'participants') ?></p>
                            </div>
                            <a href="reports?export=csv&amp;section=participants" class="rp-icon-btn" title="Download CSV" aria-label="Download participants CSV">
                                <i class="fas fa-file-csv" aria-hidden="true"></i>
                            </a>
                        </header>
                        <div class="rp-card-body">
                            <?php if (empty($gender_data)): ?>
                                <div class="empty-state rp-empty"><i class="fas fa-user-slash" aria-hidden="true"></i><span>No participant data yet</span></div>
                            <?php else: ?>
                                <div class="rp-chart"><canvas id="genderChart" role="img" aria-label="Doughnut chart of participants by gender"></canvas></div>
                                <ul class="rp-list">
                                    <?php $i = 0; foreach ($gender_data as $g => $c): ?>
                                        <li class="rp-row">
                                            <span class="rp-dot rp-c-<?= $i++ % 7 ?>" aria-hidden="true"></span>
                                            <span class="rp-row-label"><?= h((string)$g) ?></span>
                                            <span class="rp-row-value"><?= number_format($c) ?></span>
                                            <span class="rp-row-pct"><?= $rp_pct($c, $total_beneficiaries) ?>%</span>
                                        </li>
                                    <?php endforeach; ?>
                                    <li class="rp-row rp-row-total">
                                        <span class="rp-row-label">Total</span>
                                        <span class="rp-row-value"><?= number_format($total_beneficiaries) ?></span>
                                        <span class="rp-row-pct">100%</span>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>

                    <!-- Indicators Performance -->
                    <article class="rp-card" aria-labelledby="rpOvIndicators">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-primary" aria-hidden="true"><i class="fas fa-chart-line"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpOvIndicators">Indicators Performance</h3>
                                <p class="rp-card-sub"><?= $rp_plural($total_indicators, 'indicator', 'indicators') ?> with a target</p>
                            </div>
                            <a href="reports?export=csv&amp;section=indicators" class="rp-icon-btn" title="Download CSV" aria-label="Download indicators CSV">
                                <i class="fas fa-file-csv" aria-hidden="true"></i>
                            </a>
                        </header>
                        <div class="rp-card-body">
                            <?php if ($total_indicators === 0): ?>
                                <div class="empty-state rp-empty"><i class="fas fa-bullseye" aria-hidden="true"></i><span>No indicators with targets yet</span></div>
                            <?php else: ?>
                                <div class="rp-chart rp-chart-bar"><canvas id="indicatorsChart" role="img" aria-label="Bar chart of indicators by achievement level"></canvas></div>
                                <ul class="rp-list">
                                    <li class="rp-row">
                                        <span class="rp-dot rp-c-0" aria-hidden="true"></span>
                                        <span class="rp-row-label">Achieved (≥ 100%)</span>
                                        <span class="rp-row-value"><?= $ind_data['achieved'] ?></span>
                                        <span class="rp-row-pct"><?= $rp_pct($ind_data['achieved'], $total_indicators) ?>%</span>
                                    </li>
                                    <li class="rp-row">
                                        <span class="rp-dot rp-c-2" aria-hidden="true"></span>
                                        <span class="rp-row-label">Partial (50 – 99%)</span>
                                        <span class="rp-row-value"><?= $ind_data['partial'] ?></span>
                                        <span class="rp-row-pct"><?= $rp_pct($ind_data['partial'], $total_indicators) ?>%</span>
                                    </li>
                                    <li class="rp-row">
                                        <span class="rp-dot rp-c-3" aria-hidden="true"></span>
                                        <span class="rp-row-label">Low (&lt; 50%)</span>
                                        <span class="rp-row-value"><?= $ind_data['low'] ?></span>
                                        <span class="rp-row-pct"><?= $rp_pct($ind_data['low'], $total_indicators) ?>%</span>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>

                    <!-- PWD -->
                    <article class="rp-card" aria-labelledby="rpOvPwd">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-amber" aria-hidden="true"><i class="fas fa-wheelchair"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpOvPwd">PWD Statistics</h3>
                                <p class="rp-card-sub">Disability inclusion</p>
                            </div>
                            <a href="reports?export=csv&amp;section=pwd" class="rp-icon-btn" title="Download CSV" aria-label="Download PWD statistics CSV">
                                <i class="fas fa-file-csv" aria-hidden="true"></i>
                            </a>
                        </header>
                        <div class="rp-card-body">
                            <?php if ($pwd_total === 0): ?>
                                <div class="empty-state rp-empty"><i class="fas fa-wheelchair" aria-hidden="true"></i><span>No participant data yet</span></div>
                            <?php else: ?>
                                <div class="rp-chart"><canvas id="pwdChart" role="img" aria-label="Doughnut chart of PWD and non-PWD participants"></canvas></div>
                                <ul class="rp-list">
                                    <li class="rp-row">
                                        <span class="rp-dot rp-c-0" aria-hidden="true"></span>
                                        <span class="rp-row-label">Persons with Disabilities</span>
                                        <span class="rp-row-value"><?= number_format($pwd_data['pwd']) ?></span>
                                        <span class="rp-row-pct"><?= $pwd_pct ?>%</span>
                                    </li>
                                    <li class="rp-row">
                                        <span class="rp-dot rp-c-1" aria-hidden="true"></span>
                                        <span class="rp-row-label">Non-PWD</span>
                                        <span class="rp-row-value"><?= number_format($pwd_data['non_pwd']) ?></span>
                                        <span class="rp-row-pct"><?= $rp_pct($pwd_data['non_pwd'], $pwd_total) ?>%</span>
                                    </li>
                                    <li class="rp-row rp-row-total">
                                        <span class="rp-row-label">PWD Inclusion Rate</span>
                                        <span class="rp-row-value rp-accent"><?= $pwd_pct ?>%</span>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>

                    <!-- Events -->
                    <article class="rp-card" aria-labelledby="rpOvEvents">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-purple" aria-hidden="true"><i class="fas fa-calendar-check"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpOvEvents">Events Summary</h3>
                                <p class="rp-card-sub">Hub events and activity</p>
                            </div>
                            <a href="reports?export=csv&amp;section=events" class="rp-icon-btn" title="Download CSV" aria-label="Download events CSV">
                                <i class="fas fa-file-csv" aria-hidden="true"></i>
                            </a>
                        </header>
                        <div class="rp-card-body">
                            <div class="rp-metrics">
                                <div class="rp-metric rp-metric-purple">
                                    <span class="rp-metric-value"><?= number_format($events_data['total']) ?></span>
                                    <span class="rp-metric-label">Total events</span>
                                </div>
                                <div class="rp-metric rp-metric-green">
                                    <span class="rp-metric-value"><?= number_format($events_data['recent']) ?></span>
                                    <span class="rp-metric-label">In the last 30 days</span>
                                </div>
                            </div>
                            <?php if ($events_data['total'] === 0): ?>
                                <p class="rp-hint"><i class="fas fa-circle-info" aria-hidden="true"></i> No events have been recorded yet.</p>
                            <?php else: ?>
                                <div class="rp-meter" aria-hidden="true">
                                    <span class="rp-meter-fill" style="width:<?= min(100, $rp_pct($events_data['recent'], $events_data['total'])) ?>%;"></span>
                                </div>
                                <p class="rp-hint"><?= $rp_pct($events_data['recent'], $events_data['total']) ?>% of all events took place in the last 30 days.</p>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>

                </div><!-- /.rp-grid -->
            </div><!-- /#tab-overview -->

            <!-- ── PROGRAMS ──────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-programs" role="tabpanel" aria-labelledby="tabbtn-programs">
                <section class="rp-card rp-card-wide" aria-labelledby="rpPrograms">
                    <header class="rp-card-head">
                        <span class="report-card-icon bg-amber" aria-hidden="true"><i class="fas fa-sitemap"></i></span>
                        <div class="rp-card-titles">
                            <h3 class="rp-card-title" id="rpPrograms">Programs by Status</h3>
                            <p class="rp-card-sub"><?= $rp_plural($total_programs, 'program', 'programs') ?> in total</p>
                        </div>
                        <div class="rp-card-actions">
                            <a href="reports?export=csv&amp;section=programs" class="btn btn-sm btn-gray"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
                            <a href="reports?export=pdf" target="_blank" rel="noopener" class="btn btn-sm btn-soft"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
                        </div>
                    </header>
                    <?php if (empty($programs_data)): ?>
                        <div class="empty-state rp-empty"><i class="fas fa-folder-open" aria-hidden="true"></i><span>No programs yet</span></div>
                    <?php else: ?>
                        <div class="rp-split">
                            <div class="rp-chart rp-chart-lg"><canvas id="programsChartFull" role="img" aria-label="Doughnut chart of programs by status"></canvas></div>
                            <div class="table-wrap">
                                <table class="table rp-table">
                                    <caption class="sr-only">Programs by status</caption>
                                    <thead><tr><th scope="col">Status</th><th scope="col" class="rp-num">Count</th><th scope="col">Share</th></tr></thead>
                                    <tbody>
                                    <?php $i = 0; foreach ($programs_data as $s => $c): $p = $rp_pct($c, $total_programs); $k = $i++ % 7; ?>
                                        <tr>
                                            <th scope="row"><span class="rp-dot rp-c-<?= $k ?>" aria-hidden="true"></span><?= h((string)$s) ?></th>
                                            <td class="rp-num"><?= number_format($c) ?></td>
                                            <td><span class="rp-share"><span class="rp-bar" aria-hidden="true"><span class="rp-bar-fill rp-c-<?= $k ?>" style="width:<?= $p ?>%;"></span></span><span class="rp-share-pct"><?= $p ?>%</span></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr><th scope="row">Total</th><td class="rp-num"><?= number_format($total_programs) ?></td><td><span class="rp-share-pct">100%</span></td></tr></tfoot>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ── PROJECTS ──────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-projects" role="tabpanel" aria-labelledby="tabbtn-projects">
                <section class="rp-card rp-card-wide" aria-labelledby="rpProjects">
                    <header class="rp-card-head">
                        <span class="report-card-icon bg-blue" aria-hidden="true"><i class="fas fa-project-diagram"></i></span>
                        <div class="rp-card-titles">
                            <h3 class="rp-card-title" id="rpProjects">Projects by Status</h3>
                            <p class="rp-card-sub"><?= $rp_plural($total_projects, 'project', 'projects') ?> in total</p>
                        </div>
                        <div class="rp-card-actions">
                            <a href="reports?export=csv&amp;section=projects" class="btn btn-sm btn-gray"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
                            <a href="reports?export=pdf" target="_blank" rel="noopener" class="btn btn-sm btn-soft"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
                        </div>
                    </header>
                    <?php if (empty($projects_data)): ?>
                        <div class="empty-state rp-empty"><i class="fas fa-folder-open" aria-hidden="true"></i><span>No projects yet</span></div>
                    <?php else: ?>
                        <div class="rp-split">
                            <div class="rp-chart rp-chart-lg"><canvas id="projectsChartFull" role="img" aria-label="Doughnut chart of projects by status"></canvas></div>
                            <div class="table-wrap">
                                <table class="table rp-table">
                                    <caption class="sr-only">Projects by status</caption>
                                    <thead><tr><th scope="col">Status</th><th scope="col" class="rp-num">Count</th><th scope="col">Share</th></tr></thead>
                                    <tbody>
                                    <?php $i = 0; foreach ($projects_data as $s => $c): $p = $rp_pct($c, $total_projects); $k = $i++ % 7; ?>
                                        <tr>
                                            <th scope="row"><span class="rp-dot rp-c-<?= $k ?>" aria-hidden="true"></span><?= h((string)$s) ?></th>
                                            <td class="rp-num"><?= number_format($c) ?></td>
                                            <td><span class="rp-share"><span class="rp-bar" aria-hidden="true"><span class="rp-bar-fill rp-c-<?= $k ?>" style="width:<?= $p ?>%;"></span></span><span class="rp-share-pct"><?= $p ?>%</span></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr><th scope="row">Total</th><td class="rp-num"><?= number_format($total_projects) ?></td><td><span class="rp-share-pct">100%</span></td></tr></tfoot>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ── PARTICIPANTS ───────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-participants" role="tabpanel" aria-labelledby="tabbtn-participants">
                <div class="rp-grid rp-grid-2">
                    <article class="rp-card" aria-labelledby="rpPartGender">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-green" aria-hidden="true"><i class="fas fa-venus-mars"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpPartGender">By Gender</h3>
                                <p class="rp-card-sub"><?= $rp_plural($total_beneficiaries, 'participant', 'participants') ?></p>
                            </div>
                            <a href="reports?export=csv&amp;section=participants" class="btn btn-sm btn-gray"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
                        </header>
                        <div class="rp-card-body">
                            <?php if (empty($gender_data)): ?>
                                <div class="empty-state rp-empty"><i class="fas fa-user-slash" aria-hidden="true"></i><span>No participant data yet</span></div>
                            <?php else: ?>
                                <div class="rp-chart"><canvas id="genderChartFull" role="img" aria-label="Doughnut chart of participants by gender"></canvas></div>
                                <ul class="rp-list">
                                    <?php $i = 0; foreach ($gender_data as $g => $c): ?>
                                        <li class="rp-row">
                                            <span class="rp-dot rp-c-<?= $i++ % 7 ?>" aria-hidden="true"></span>
                                            <span class="rp-row-label"><?= h((string)$g) ?></span>
                                            <span class="rp-row-value"><?= number_format($c) ?></span>
                                            <span class="rp-row-pct"><?= $rp_pct($c, $total_beneficiaries) ?>%</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>
                    <article class="rp-card" aria-labelledby="rpPartPwd">
                        <header class="rp-card-head">
                            <span class="report-card-icon bg-amber" aria-hidden="true"><i class="fas fa-wheelchair"></i></span>
                            <div class="rp-card-titles">
                                <h3 class="rp-card-title" id="rpPartPwd">PWD vs Non-PWD</h3>
                                <p class="rp-card-sub">Disability inclusion</p>
                            </div>
                            <a href="reports?export=csv&amp;section=pwd" class="btn btn-sm btn-gray"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
                        </header>
                        <div class="rp-card-body">
                            <?php if ($pwd_total === 0): ?>
                                <div class="empty-state rp-empty"><i class="fas fa-wheelchair" aria-hidden="true"></i><span>No participant data yet</span></div>
                            <?php else: ?>
                                <div class="rp-chart"><canvas id="pwdChartFull" role="img" aria-label="Doughnut chart of PWD and non-PWD participants"></canvas></div>
                                <ul class="rp-list">
                                    <li class="rp-row">
                                        <span class="rp-dot rp-c-0" aria-hidden="true"></span>
                                        <span class="rp-row-label">Persons with Disabilities</span>
                                        <span class="rp-row-value"><?= number_format($pwd_data['pwd']) ?></span>
                                        <span class="rp-row-pct"><?= $pwd_pct ?>%</span>
                                    </li>
                                    <li class="rp-row">
                                        <span class="rp-dot rp-c-1" aria-hidden="true"></span>
                                        <span class="rp-row-label">Non-PWD</span>
                                        <span class="rp-row-value"><?= number_format($pwd_data['non_pwd']) ?></span>
                                        <span class="rp-row-pct"><?= $rp_pct($pwd_data['non_pwd'], $pwd_total) ?>%</span>
                                    </li>
                                    <li class="rp-row rp-row-total">
                                        <span class="rp-row-label">Inclusion Rate</span>
                                        <span class="rp-row-value rp-accent"><?= $pwd_pct ?>%</span>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <footer class="rp-card-foot">
                            <a href="generate-report" class="rp-foot-link">View full report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </footer>
                    </article>
                </div>
            </div>

            <!-- ── INDICATORS ─────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-indicators" role="tabpanel" aria-labelledby="tabbtn-indicators">
                <section class="rp-card rp-card-wide" aria-labelledby="rpIndicators">
                    <header class="rp-card-head">
                        <span class="report-card-icon bg-primary" aria-hidden="true"><i class="fas fa-chart-bar"></i></span>
                        <div class="rp-card-titles">
                            <h3 class="rp-card-title" id="rpIndicators">Indicators Achievement Overview</h3>
                            <p class="rp-card-sub">Current value against target</p>
                        </div>
                        <div class="rp-card-actions">
                            <a href="reports?export=csv&amp;section=indicators" class="btn btn-sm btn-gray"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
                            <a href="reports?export=pdf" target="_blank" rel="noopener" class="btn btn-sm btn-soft"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
                        </div>
                    </header>
                    <?php if ($total_indicators === 0): ?>
                        <div class="empty-state rp-empty"><i class="fas fa-bullseye" aria-hidden="true"></i><span>No indicators with targets yet</span></div>
                    <?php else: ?>
                        <div class="rp-split">
                            <div class="rp-chart rp-chart-bar rp-chart-lg"><canvas id="indicatorsChartFull" role="img" aria-label="Bar chart of indicators by achievement level"></canvas></div>
                            <div class="table-wrap">
                                <table class="table rp-table">
                                    <caption class="sr-only">Indicators by achievement level</caption>
                                    <thead><tr><th scope="col">Achievement</th><th scope="col" class="rp-num">Count</th><th scope="col">Share</th></tr></thead>
                                    <tbody>
                                    <?php
                                    $rp_ind_rows = [
                                        ['Achieved (≥ 100%)',  $ind_data['achieved'], 0],
                                        ['Partial (50 – 99%)', $ind_data['partial'],  2],
                                        ['Low (< 50%)',        $ind_data['low'],      3],
                                    ];
                                    foreach ($rp_ind_rows as [$label, $cnt, $k]): $p = $rp_pct($cnt, $total_indicators);
                                    ?>
                                        <tr>
                                            <th scope="row"><span class="rp-dot rp-c-<?= $k ?>" aria-hidden="true"></span><?= h($label) ?></th>
                                            <td class="rp-num"><?= number_format($cnt) ?></td>
                                            <td><span class="rp-share"><span class="rp-bar" aria-hidden="true"><span class="rp-bar-fill rp-c-<?= $k ?>" style="width:<?= $p ?>%;"></span></span><span class="rp-share-pct"><?= $p ?>%</span></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr><th scope="row">Total Indicators</th><td class="rp-num"><?= number_format($total_indicators) ?></td><td><span class="rp-share-pct">100%</span></td></tr></tfoot>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ── POWER BI ───────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-powerbi" role="tabpanel" aria-labelledby="tabbtn-powerbi">
                <?php if (!empty($powerbi_settings['embed_url'])): ?>
                    <div class="powerbi-controls">
                        <h3 class="rp-section-title">
                            <i class="fas fa-chart-pie rp-pbi-icon" aria-hidden="true"></i> Power BI Live Dashboard
                        </h3>
                        <button type="button" onclick="document.getElementById('powerBIFrame').requestFullscreen()" class="btn btn-sm btn-gray">
                            <i class="fas fa-expand" aria-hidden="true"></i> Fullscreen
                        </button>
                    </div>
                    <div class="powerbi-frame-wrap">
                        <iframe id="powerBIFrame" title="Power BI dashboard" src="<?= h($powerbi_settings['embed_url']) ?>" allowfullscreen></iframe>
                    </div>
                <?php else: ?>
                    <div class="powerbi-unconfigured">
                        <div class="powerbi-icon-wrap" aria-hidden="true"><i class="fas fa-chart-pie"></i></div>
                        <h3>Power BI Not Configured</h3>
                        <p>Connect your Power BI workspace to visualise real-time analytics.</p>
                        <?php if (ims_allowed('reports.configure_powerbi', ['Administrator'], 'See the Configure Power BI link')): ?>
                            <a href="/settings?tab=powerbi" class="btn btn-dark"><i class="fas fa-cog" aria-hidden="true"></i> Configure Power BI</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── EXPORTS ────────────────────────────────────────────────── -->
            <div class="tab-panel" id="tab-exports" role="tabpanel" aria-labelledby="tabbtn-exports">
                <div class="rp-callout">
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                    <p>CSV files download immediately. PDF opens in a new tab via Dompdf. Detailed reports open in the browser.</p>
                </div>

                <h3 class="rp-group-title">Reports</h3>
                <div class="exports-grid rp-exports">
                    <!-- PDF exports -->
                    <a href="reports?export=pdf" target="_blank" rel="noopener" class="export-item">
                        <span class="export-icon pdf" aria-hidden="true"><i class="fas fa-file-pdf"></i></span>
                        <span class="export-item-body">
                            <span class="export-item-name">Analytics Overview (PDF)</span>
                            <span class="export-item-desc">Full overview report rendered via Dompdf</span>
                        </span>
                        <span class="rp-export-tag rp-tag-pdf">PDF</span>
                    </a>
                    <!-- Detailed web reports -->
                    <a href="generate-report" class="export-item">
                        <span class="export-icon web" aria-hidden="true"><i class="fas fa-globe"></i></span>
                        <span class="export-item-body">
                            <span class="export-item-name">Custom Report Builder</span>
                            <span class="export-item-desc">Build detailed reports with filters</span>
                        </span>
                        <span class="rp-export-tag rp-tag-web">Web</span>
                    </a>
                </div>

                <h3 class="rp-group-title">Data exports</h3>
                <div class="exports-grid rp-exports">
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
                        <a href="reports?export=csv&amp;section=<?= $sec ?>" class="export-item">
                            <span class="export-icon excel" aria-hidden="true"><i class="fas <?= $ico ?>"></i></span>
                            <span class="export-item-body">
                                <span class="export-item-name"><?= $name ?></span>
                                <span class="export-item-desc"><?= $desc ?></span>
                            </span>
                            <span class="rp-export-tag rp-tag-csv"><i class="fas fa-download" aria-hidden="true"></i><span class="sr-only">Download</span></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        </div><!-- /.tab-panels -->
    </section><!-- /.tab-shell -->

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
            /* The breakdown list beside each chart doubles as its legend. */
            options:{ ...OPTS, cutout:'64%', plugins:{ ...OPTS.plugins, legend:{ display:false }}},
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
            btns.forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-selected','false'); });
            panels.forEach(function(p){ p.classList.remove('active'); });
            btn.classList.add('active');
            btn.setAttribute('aria-selected','true');
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