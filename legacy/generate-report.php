<?php
$page_title = 'Generate Report';
require_once __DIR__ . '/includes/header.php';

$dompdf_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($dompdf_autoload)) { require_once $dompdf_autoload; }
use Dompdf\Dompdf;
use Dompdf\Options;

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin']);

function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* -- Dropdowns -------------------------------------------------------- */
$projects = [];
$result = $conn->query("SELECT project_id, project_name, project_code FROM projects ORDER BY project_name");
while ($row = $result->fetch_assoc()) { $projects[] = $row; }

$programs = [];
$result = $conn->query("SELECT id, program_name, program_code FROM programs ORDER BY program_name");
while ($row = $result->fetch_assoc()) { $programs[] = $row; }

/* -- Quick stats ------------------------------------------------------ */
$stats = [
    'total_programs'     => (int)$conn->query("SELECT COUNT(*) AS c FROM programs")->fetch_assoc()['c'],
    'active_programs'    => (int)$conn->query("SELECT COUNT(*) AS c FROM programs WHERE status='Ongoing'")->fetch_assoc()['c'],
    'total_projects'     => (int)$conn->query("SELECT COUNT(*) AS c FROM projects")->fetch_assoc()['c'],
    'ongoing_projects'   => (int)$conn->query("SELECT COUNT(*) AS c FROM projects WHERE status='Ongoing'")->fetch_assoc()['c'],
    'total_participants' => (int)$conn->query("SELECT COUNT(*) AS c FROM beneficiaries")->fetch_assoc()['c'],
    'total_indicators'   => (int)$conn->query("SELECT COUNT(*) AS c FROM indicators")->fetch_assoc()['c'],
    'total_events'       => (int)$conn->query("SELECT COUNT(*) AS c FROM hub_events WHERE YEAR(event_date)=YEAR(CURDATE())")->fetch_assoc()['c'],
];

/* -- Recent exports --------------------------------------------------- */
$recent_exports = [];
if (is_dir('exports')) {
    $files = glob('exports/*.{csv,pdf}', GLOB_BRACE);
    if ($files) {
        rsort($files);
        foreach (array_slice($files, 0, 5) as $file) {
            $base = basename($file);
            if ($base !== 'README.md' && !preg_match('/^backup_/', $base)) {
                $recent_exports[] = [
                    'filename' => $base,
                    'path'     => 'download-export.php?file=' . rawurlencode($base),
                    'size'     => filesize($file),
                    'date'     => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
        }
    }
}

/* -- Report catalogue ------------------------------------------------- */
$tab_groups = [
    'standard' => [
        'label'   => 'Standard',
        'icon'    => 'fa-file-alt',
        'reports' => [
            ['type'=>'program_summary',      'icon'=>'fa-sitemap',         'bg'=>'bg-amber',   'label'=>'Program Summary',       'desc'=>'Overview of all programs including status, participants, key indicators, and outcomes.'],
            ['type'=>'project_summary',      'icon'=>'fa-project-diagram', 'bg'=>'bg-blue',    'label'=>'Project Summary',       'desc'=>'Overview of all projects including status, budget, participants, and key indicators.'],
            ['type'=>'participant_report',   'icon'=>'fa-users',           'bg'=>'bg-green',   'label'=>'Participants Analysis',  'desc'=>'Demographics, participation, outcomes, and impact on participants by program/project.'],
            ['type'=>'indicator_performance','icon'=>'fa-chart-bar',       'bg'=>'bg-purple',  'label'=>'Indicator Performance', 'desc'=>'Achievement rates, trends, and progress tracking for all MEL indicators.'],
            ['type'=>'events_report',        'icon'=>'fa-calendar-alt',    'bg'=>'bg-slate',   'label'=>'Events & Training',     'desc'=>'Event attendance, participation rates, feedback scores, and event outcomes.'],
        ],
    ],
    'advanced' => [
        'label'   => 'Advanced',
        'icon'    => 'fa-chart-pie',
        'reports' => [
            ['type'=>'hub_operations','icon'=>'fa-building',         'bg'=>'bg-blue',  'label'=>'Hub Operations','desc'=>'Visitor statistics, membership trends, space utilisation, and revenue reports.'],
            ['type'=>'donor_report',  'icon'=>'fa-hand-holding-usd','bg'=>'bg-green', 'label'=>'Donor Report',  'desc'=>'Program/project outcomes, achievements, spending, and impact by funding source.'],
            ['type'=>'pwd_report',    'icon'=>'fa-wheelchair',       'bg'=>'bg-amber', 'label'=>'PWD Report',    'desc'=>'Persons with disabilities statistics, participation, and inclusion metrics.'],
        ],
    ],
    'periodic' => [
        'label'   => 'Periodic',
        'icon'    => 'fa-calendar-check',
        'reports' => [
            ['type'=>'quarterly_report','icon'=>'fa-calendar-check','bg'=>'bg-primary','label'=>'Quarterly Report','desc'=>'Comprehensive quarterly performance report including all key metrics and achievements.'],
            ['type'=>'annual_report',   'icon'=>'fa-file-invoice',  'bg'=>'bg-purple', 'label'=>'Annual Report',   'desc'=>'Year-end comprehensive report covering all programs, projects, and organisational impact.'],
        ],
    ],
];

$quick_exports = [
    ['type'=>'programs',     'icon'=>'fa-sitemap',         'label'=>'All Programs'],
    ['type'=>'projects',     'icon'=>'fa-project-diagram', 'label'=>'All Projects'],
    ['type'=>'participants', 'icon'=>'fa-users',           'label'=>'Participants'],
    ['type'=>'indicators',   'icon'=>'fa-chart-line',      'label'=>'Indicators'],
    ['type'=>'events',       'icon'=>'fa-calendar',        'label'=>'Events'],
    ['type'=>'documents',    'icon'=>'fa-folder-open',     'label'=>'Documents'],
    ['type'=>'hub_visitors', 'icon'=>'fa-user-friends',    'label'=>'Hub Visitors'],
    ['type'=>'memberships',  'icon'=>'fa-id-badge',        'label'=>'Memberships'],
];
?>

<link rel="stylesheet" href="css/reports.css">
<style>
/* -- Report-builder additions ------------------------------------------ */
.report-row {
  display: flex; align-items: center; gap: 16px;
  background: var(--surface-card);
  border: 1.5px solid var(--ink-100);
  border-radius: var(--radius-lg);
  padding: 14px 18px; cursor: pointer; margin-bottom: 10px;
  transition: border-color .2s var(--ease-out), box-shadow .2s var(--ease-out), background .2s;
}
.report-row:last-child { margin-bottom: 0; }
.report-row:hover      { border-color: var(--brand-400); box-shadow: var(--shadow-sm); }
.report-row.selected   { border-color: var(--brand-500); background: var(--brand-50); }

.report-row-icon {
  width: 42px; height: 42px; border-radius: var(--radius-md);
  display: grid; place-items: center; font-size: 17px; flex-shrink: 0;
}
.report-row-body         { flex: 1; min-width: 0; }
.report-row-body strong  { font-size: 14px; font-weight: 800; color: var(--ink-700); display: block; margin-bottom: 2px; }
.report-row-body span    { font-size: 12px; color: var(--ink-300); line-height: 1.5; }
.report-row-check        { flex-shrink: 0; font-size: 18px; color: var(--brand-500); opacity: 0; transition: opacity .18s; }
.report-row.selected .report-row-check { opacity: 1; }

.params-section {
  background: var(--ink-50); border: 1px solid var(--ink-100);
  border-radius: var(--radius-xl); padding: 22px; margin-top: 22px;
}
.params-section > .section-heading {
  font-size: 11px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase;
  color: var(--ink-400); display: flex; align-items: center; gap: 8px;
  margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--ink-100);
}
.params-grid    { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group     { display: flex; flex-direction: column; gap: 6px; }

.filter-panel {
  background: var(--surface-card); border: 1.5px solid var(--ink-100);
  border-radius: var(--radius-lg); padding: 16px 18px; margin-top: 14px;
}
.filter-panel-head {
  font-size: 11px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase;
  color: var(--ink-400); margin-bottom: 14px; display: flex; align-items: center; gap: 6px;
}
.filter-info {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 10px 14px; background: var(--blue-bg); border: 1px solid var(--blue-border);
  border-radius: var(--radius-md); font-size: 12px; color: var(--blue-fg); margin-top: 12px; line-height: 1.6;
}

.format-options { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.format-pill {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 18px; background: var(--surface-card);
  border: 1.5px solid var(--ink-100); border-radius: var(--radius-lg);
  cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ink-500);
  transition: border-color .18s, background .18s, color .18s;
}
.format-pill:hover  { border-color: var(--ink-300); }
.format-pill.active { border-color: var(--brand-500); background: var(--brand-50); color: var(--brand-700); }
.format-pill input  { display: none; }

.generate-row {
  display: flex; justify-content: center; gap: 12px; margin-top: 26px; flex-wrap: wrap;
}

.tip-box {
  display: flex; gap: 12px; align-items: flex-start;
  padding: 14px 18px; border-radius: var(--radius-md); border-left: 4px solid transparent;
  font-size: 13px; line-height: 1.7; margin-top: 12px;
}
.tip-box i    { flex-shrink: 0; margin-top: 2px; }
.tip-box.green { background: var(--green-bg); border-left-color: var(--green-fg); color: var(--green-fg); }
.tip-box.amber { background: var(--amber-bg); border-left-color: var(--amber-fg); color: var(--amber-fg); }

.guide-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; }
.guide-grid ul { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.guide-grid li { display: flex; align-items: flex-start; gap: 10px; font-size: 13px; color: var(--ink-500); line-height: 1.6; }
.guide-dot { flex-shrink: 0; width: 6px; height: 6px; border-radius: 50%; background: var(--brand-500); margin-top: 8px; }

.exp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.exp-table th {
  background: var(--ink-50); color: var(--ink-300); text-align: left;
  padding: 10px 16px; font-size: 11px; font-weight: 700; letter-spacing: .07em;
  text-transform: uppercase; border-bottom: 1px solid var(--ink-100);
}
.exp-table td { padding: 12px 16px; border-bottom: 1px solid var(--ink-50); color: var(--ink-500); vertical-align: middle; }
.exp-table tbody tr:hover { background: var(--surface-hover); }
.exp-table tbody tr:last-child td { border-bottom: none; }
.mono { font-family: var(--font-mono); font-size: .88em; background: var(--ink-50); padding: 2px 6px; border-radius: 5px; color: var(--ink-500); }

@media (max-width: 640px) {
  .params-grid { grid-template-columns: 1fr; }
  .guide-grid  { grid-template-columns: 1fr; }
  .generate-row .btn { flex: 1; justify-content: center; }
}
</style>

<div class="reports-wrap">

    <!-- -- Hero --------------------------------------------------------- -->
    <div class="reports-hero">
        <div class="reports-hero-text">
            <h1><i class="fas fa-file-alt" style="margin-right:10px;opacity:.85;"></i>Generate Report</h1>
            <p>Select a report type, set your parameters, and export in your preferred format.</p>
        </div>
        <div class="hero-actions">
            <a href="reports" class="btn btn-primary"
               style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);">
                <i class="fas fa-arrow-left"></i> Back to Reports
            </a>
        </div>
    </div>

    <!-- -- Quick Stats -------------------------------------------------- -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-sitemap" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Active Programs</span>
                <span class="stat-value"><?= $stats['active_programs'] ?><span class="stat-sub">/ <?= $stats['total_programs'] ?></span></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-project-diagram" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Ongoing Projects</span>
                <span class="stat-value"><?= $stats['ongoing_projects'] ?><span class="stat-sub">/ <?= $stats['total_projects'] ?></span></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-users" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Participants</span>
                <span class="stat-value"><?= number_format($stats['total_participants']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Indicators</span>
                <span class="stat-value"><?= $stats['total_indicators'] ?></span>
            </div>
        </div>
        <div class="stat-card stat-card-wide">
            <div class="stat-icon bg-purple"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Events This Year</span>
                <span class="stat-value"><?= $stats['total_events'] ?></span>
            </div>
        </div>
    </div>

    <!-- -- Report Builder ----------------------------------------------- -->
    <div class="tab-shell">
        <div class="panel-head">
            <h3><i class="fas fa-clipboard-list" style="color:var(--brand-500);margin-right:8px;"></i>Build Your Report</h3>
        </div>

        <div style="padding:22px;">
            <form method="POST" action="includes/report-process.php" id="reportForm">
                <input type="hidden" name="report_type" id="report_type">

                <!-- Step 1: report type -->
                <div class="form-label" style="margin-bottom:14px;">
                    <i class="fas fa-list-ol" style="margin-right:6px;color:var(--brand-500);"></i>
                    Step 1 - Select Report Type
                </div>

                <!-- tabs inside builder -->
                <div class="tab-header" style="border-radius:var(--radius-lg) var(--radius-lg) 0 0;background:var(--ink-50);padding:0 14px;">
                    <?php $first=true; foreach($tab_groups as $key=>$group): ?>
                        <button type="button"
                                class="tab-btn <?= $first?'active':'' ?>"
                                data-rtab="<?= $key ?>"
                                role="tab">
                            <i class="fas <?= $group['icon'] ?>"></i><?= h($group['label']) ?>
                        </button>
                    <?php $first=false; endforeach; ?>
                </div>

                <div style="background:var(--surface-card);border:1.5px solid var(--ink-100);border-top:none;
                            border-radius:0 0 var(--radius-lg) var(--radius-lg);padding:16px 16px 12px;">
                    <?php $first=true; foreach($tab_groups as $key=>$group): ?>
                        <div id="rtab-<?= $key ?>" class="tab-panel <?= $first?'active':'' ?>">
                            <?php foreach($group['reports'] as $r): ?>
                                <div class="report-row"
                                     data-type="<?= $r['type'] ?>"
                                     onclick="selectReport('<?= $r['type'] ?>',this)">
                                    <div class="report-row-icon <?= $r['bg'] ?>">
                                        <i class="fas <?= $r['icon'] ?>"></i>
                                    </div>
                                    <div class="report-row-body">
                                        <strong><?= h($r['label']) ?></strong>
                                        <span><?= h($r['desc']) ?></span>
                                    </div>
                                    <div class="report-row-check"><i class="fas fa-check-circle"></i></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php $first=false; endforeach; ?>
                </div>

                <!-- Step 2: parameters -->
                <div class="params-section">
                    <div class="section-heading">
                        <i class="fas fa-sliders-h" style="color:var(--brand-500);"></i>
                        Step 2 - Set Parameters
                    </div>

                    <div class="params-grid">
                        <div class="form-group">
                            <label class="form-label" for="start_date">
                                Start Date <span style="color:var(--red-fg);">*</span>
                            </label>
                            <input type="date" id="start_date" name="start_date" class="form-control"
                                   value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="end_date">
                                End Date <span style="color:var(--red-fg);">*</span>
                            </label>
                            <input type="date" id="end_date" name="end_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Scope filter -->
                    <div class="filter-panel">
                        <div class="filter-panel-head">
                            <i class="fas fa-filter"></i> Scope Filter
                            <span style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--ink-300);">- optional</span>
                        </div>
                        <div class="params-grid">
                            <div class="form-group">
                                <label class="form-label" for="program_id">
                                    <i class="fas fa-sitemap" style="color:var(--amber-fg);margin-right:4px;"></i>Program
                                </label>
                                <select id="program_id" name="program_id" class="form-control" onchange="handleProgram()">
                                    <option value="">All Programs</option>
                                    <?php foreach($programs as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>"><?= h($p['program_code'].' - '.$p['program_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="project_id">
                                    <i class="fas fa-project-diagram" style="color:var(--blue-fg);margin-right:4px;"></i>Project
                                </label>
                                <select id="project_id" name="project_id" class="form-control" onchange="handleProject()">
                                    <option value="">All Projects</option>
                                    <?php foreach($projects as $pr): ?>
                                        <option value="<?= (int)$pr['project_id'] ?>"><?= h($pr['project_code'].' - '.$pr['project_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-info">
                            <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
                            <span>Filter by a program <em>or</em> a project - not both. Leave both empty for an organisation-wide report.</span>
                        </div>
                    </div>

                    <!-- Output format -->
                    <div class="form-group" style="margin-top:18px;">
                        <label class="form-label">Output Format <span style="color:var(--red-fg);">*</span></label>
                        <div class="format-options">
                            <label class="format-pill active" onclick="setFmt(this)">
                                <input type="radio" name="format" value="html" checked>
                                <i class="fas fa-desktop" style="color:var(--blue-fg);"></i> View Online
                            </label>
                            <label class="format-pill" onclick="setFmt(this)">
                                <input type="radio" name="format" value="pdf">
                                <i class="fas fa-file-pdf" style="color:var(--red-fg);"></i> PDF
                            </label>
                            <label class="format-pill" onclick="setFmt(this)">
                                <input type="radio" name="format" value="excel">
                                <i class="fas fa-file-excel" style="color:var(--green-fg);"></i> Excel
                            </label>
                        </div>
                    </div>
                </div>

                <div class="generate-row">
                    <button type="button" class="btn btn-gray" onclick="resetForm()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button type="submit" name="generate_report" class="btn btn-dark" style="padding:12px 36px;font-size:14px;">
                        <i class="fas fa-chart-pie"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- -- Quick Exports ------------------------------------------------ -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-download" style="color:var(--brand-500);margin-right:8px;"></i>Quick Exports</h3>
            <span class="note">Excel format &middot; opens in new tab</span>
        </div>
        <div class="panel-body">
            <div class="exports-grid">
                <?php foreach($quick_exports as $ex): ?>
                    <a href="export?type=<?= $ex['type'] ?>" class="export-item" target="_blank" rel="noopener">
                        <div class="export-icon excel"><i class="fas <?= $ex['icon'] ?>"></i></div>
                        <div class="export-item-body">
                            <div class="export-item-name"><?= $ex['label'] ?></div>
                            <div class="export-item-desc">Export all <?= strtolower($ex['label']) ?> as Excel</div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- -- Recent Exports ----------------------------------------------- -->
    <?php if(!empty($recent_exports)): ?>
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-history" style="color:var(--brand-500);margin-right:8px;"></i>Recent Exports</h3>
            </div>
            <div style="overflow-x:auto;">
                <table class="exp-table">
                    <thead>
                        <tr><th>File</th><th>Size</th><th>Generated</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_exports as $exp):
                            $ext   = strtolower(pathinfo($exp['filename'], PATHINFO_EXTENSION));
                            $icon  = $ext==='pdf' ? 'fa-file-pdf' : ($ext==='csv' ? 'fa-file-csv' : 'fa-file');
                            $color = $ext==='pdf' ? 'var(--red-fg)' : ($ext==='csv' ? 'var(--green-fg)' : 'var(--ink-300)');
                        ?>
                            <tr>
                                <td>
                                    <span style="display:flex;align-items:center;gap:9px;">
                                        <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:15px;"></i>
                                        <strong style="color:var(--ink-700);"><?= h($exp['filename']) ?></strong>
                                    </span>
                                </td>
                                <td><span class="mono"><?= number_format($exp['size']/1024,1) ?> KB</span></td>
                                <td style="font-size:12px;color:var(--ink-300);"><?= date('d M Y H:i',strtotime($exp['date'])) ?></td>
                                <td>
                                    <a href="<?= h($exp['path']) ?>" class="btn btn-sm btn-soft" download>
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- -- Guide -------------------------------------------------------- -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-info-circle" style="color:var(--brand-500);margin-right:8px;"></i>Report Templates Guide</h3>
        </div>
        <div class="panel-body">
            <div class="guide-grid">
                <div>
                    <div class="form-label" style="margin-bottom:12px;">Standard Reports</div>
                    <ul>
                        <?php foreach([
                            ['Program Summary',       'Status, participants and key outcomes per programme.'],
                            ['Project Summary',       'Status, budget utilisation and deliverables per project.'],
                            ['Participants Analysis', 'Demographics, participation patterns and outcome tracking.'],
                            ['Indicator Performance', 'MEL indicators with achievement rates and trends.'],
                            ['Events & Training',     'Attendance, feedback and capacity-building outcomes.'],
                        ] as [$t,$d]): ?>
                            <li><div class="guide-dot"></div><span><strong><?= $t ?></strong> - <?= $d ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:12px;">Advanced &amp; Periodic</div>
                    <ul>
                        <?php foreach([
                            ['Hub Operations',   'Visitor trends, membership revenue and space utilisation.'],
                            ['Donor Report',     'Impact and outcomes broken down by funding source.'],
                            ['PWD Report',       'Persons with disabilities participation and inclusion metrics.'],
                            ['Quarterly Report', 'Comprehensive 3-month performance review.'],
                            ['Annual Report',    'Year-end organisational impact and achievements.'],
                        ] as [$t,$d]): ?>
                            <li><div class="guide-dot"></div><span><strong><?= $t ?></strong> - <?= $d ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="tip-box green">
                <i class="fas fa-lightbulb"></i>
                <span><strong>Tip:</strong> For best results select a specific date range and program/project filter. Quarterly and annual reports work best with standardised periods (Q1: Jan-Mar, Q2: Apr-Jun, etc.).</span>
            </div>
            <!--<div class="tip-box amber">
                <i class="fas fa-info-circle"></i>
               <span><strong>Note:</strong> PDF output is rendered via <strong>Dompdf</strong>. When filtering by program or project, only data for that selection is included. Leave both filters empty for organisation-wide reports.</span> 
            </div> -->
        </div>
    </div>

</div><!-- /.reports-wrap -->

<script>
(function(){
    'use strict';

    /* builder tabs */
    document.querySelectorAll('.tab-btn[data-rtab]').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.tab-btn[data-rtab]').forEach(function(b){ b.classList.remove('active'); });
            document.querySelectorAll('[id^="rtab-"]').forEach(function(p){ p.classList.remove('active'); });
            btn.classList.add('active');
            const panel = document.getElementById('rtab-'+btn.dataset.rtab);
            if(panel) panel.classList.add('active');
        });
    });

    /* report row selection */
    window.selectReport = function(type, el){
        document.querySelectorAll('.report-row').forEach(function(r){ r.classList.remove('selected'); });
        el.classList.add('selected');
        document.getElementById('report_type').value = type;
        if(type==='quarterly_report'||type==='annual_report') autoFillDates(type);
    };

    function autoFillDates(type){
        const today=new Date(), s=document.getElementById('start_date'), e=document.getElementById('end_date');
        if(type==='quarterly_report'){
            const q=Math.floor(today.getMonth()/3);
            s.value=new Date(today.getFullYear(),q*3,    1).toISOString().split('T')[0];
            e.value=new Date(today.getFullYear(),q*3+3,  0).toISOString().split('T')[0];
        }else{
            s.value=new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
            e.value=new Date(today.getFullYear(),11,31).toISOString().split('T')[0];
        }
    }

    /* mutual exclusivity */
    window.handleProgram = function(){
        const prog=document.getElementById('program_id'), proj=document.getElementById('project_id');
        proj.disabled=!!prog.value; proj.style.opacity=prog.value?'.45':'1';
        if(prog.value) proj.value='';
    };
    window.handleProject = function(){
        const prog=document.getElementById('program_id'), proj=document.getElementById('project_id');
        prog.disabled=!!proj.value; prog.style.opacity=proj.value?'.45':'1';
        if(proj.value) prog.value='';
    };

    /* format pills */
    window.setFmt = function(label){
        document.querySelectorAll('.format-pill').forEach(function(l){ l.classList.remove('active'); });
        label.classList.add('active');
        label.querySelector('input[type="radio"]').checked=true;
    };

    /* reset */
    window.resetForm = function(){
        document.querySelectorAll('.report-row').forEach(function(r){ r.classList.remove('selected'); });
        document.getElementById('report_type').value='';
        document.getElementById('start_date').value='<?= date('Y-m-01') ?>';
        document.getElementById('end_date').value='<?= date('Y-m-d') ?>';
        ['program_id','project_id'].forEach(function(id){
            const el=document.getElementById(id);
            el.value=''; el.disabled=false; el.style.opacity='1';
        });
        document.querySelectorAll('.format-pill').forEach(function(l){ l.classList.remove('active'); });
        document.querySelector('.format-pill').classList.add('active');
        document.querySelector('.format-pill input').checked=true;
    };

    /* submit validation */
    document.getElementById('reportForm').addEventListener('submit',function(e){
        if(!document.getElementById('report_type').value){
            e.preventDefault(); alert('Please select a report type from the tabs above.'); return;
        }
        if(new Date(document.getElementById('end_date').value)<new Date(document.getElementById('start_date').value)){
            e.preventDefault(); alert('End date must be after start date.');
        }
    });
})();
</script>

<?php include 'includes/footer.php'; ?>