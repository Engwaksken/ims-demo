<?php
declare(strict_types=1);

$page_title = 'Indicators Management';
require_once 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ── Edit / Progress pre-loads ──────────────────────────────────────── */
$edit_indicator     = null;
$progress_indicator = null;

if (!empty($_GET['edit'])) {
    $mid  = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM indicators WHERE indicator_id = ?");
    $stmt->bind_param('i', $mid);
    $stmt->execute();
    $edit_indicator = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!empty($_GET['progress'])) {
    $mid  = (int)$_GET['progress'];
    $stmt = $conn->prepare("
        SELECT i.*, p.project_name, p.project_code, pr.program_name, pr.program_code
        FROM indicators i
        LEFT JOIN projects p  ON p.project_id = i.project_id
        LEFT JOIN programs pr ON pr.id         = i.program_id
        WHERE i.indicator_id = ?
    ");
    $stmt->bind_param('i', $mid);
    $stmt->execute();
    $progress_indicator = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* ── Filters ────────────────────────────────────────────────────────── */
$filter_type        = $_GET['type']        ?? '';
$filter_context     = $_GET['context']     ?? '';
$filter_project     = !empty($_GET['project']) ? (int)$_GET['project'] : 0;
$filter_program     = !empty($_GET['program']) ? (int)$_GET['program'] : 0;
$filter_achievement = $_GET['achievement'] ?? '';
$search             = $_GET['search']      ?? '';

$where  = [];
$params = [];
$types  = '';

if ($filter_type !== '') { $where[] = "i.indicator_type = ?"; $params[] = $filter_type; $types .= 's'; }
if ($filter_context === 'project') { $where[] = "i.project_id IS NOT NULL"; }
elseif ($filter_context === 'program') { $where[] = "i.program_id IS NOT NULL"; }
if ($filter_project > 0) { $where[] = "i.project_id = ?"; $params[] = $filter_project; $types .= 'i'; }
if ($filter_program > 0) { $where[] = "i.program_id = ?"; $params[] = $filter_program; $types .= 'i'; }
if ($search !== '') { $where[] = "i.indicator_name LIKE ?"; $params[] = "%{$search}%"; $types .= 's'; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ── Main query ─────────────────────────────────────────────────────── */
$query = "
    SELECT i.*,
        p.project_name, p.project_code,
        pr.program_name, pr.program_code,
        CASE WHEN i.project_id IS NOT NULL THEN 'Project'
             WHEN i.program_id IS NOT NULL THEN 'Program'
             ELSE 'Unknown' END AS context_type,
        CASE WHEN i.project_id IS NOT NULL THEN CONCAT(p.project_code,' - ',p.project_name)
             WHEN i.program_id IS NOT NULL THEN CONCAT(pr.program_code,' - ',pr.program_name)
             ELSE 'N/A' END AS context_name,
        CASE WHEN i.project_id IS NOT NULL THEN p.project_code
             WHEN i.program_id IS NOT NULL THEN pr.program_code
             ELSE 'N/A' END AS context_code,
        CASE WHEN i.target_value > 0
             THEN ROUND(i.current_value / i.target_value * 100, 2)
             ELSE 0 END AS achievement_percentage,
        (SELECT COUNT(*) FROM indicator_progress ip WHERE ip.indicator_id = i.indicator_id) AS progress_count
    FROM indicators i
    LEFT JOIN projects p  ON p.project_id = i.project_id
    LEFT JOIN programs pr ON pr.id         = i.program_id
    {$where_sql}
";

if ($filter_achievement === 'high')   $query .= " HAVING achievement_percentage >= 100";
elseif ($filter_achievement === 'medium') $query .= " HAVING achievement_percentage BETWEEN 50 AND 99";
elseif ($filter_achievement === 'low') $query .= " HAVING achievement_percentage < 50";

$query .= " ORDER BY context_type, context_code, i.indicator_type, i.indicator_name";

$stmt = $conn->prepare($query);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$indicators = [];
while ($row = $res->fetch_assoc()) $indicators[] = $row;
$stmt->close();

/* ── Dropdown data ──────────────────────────────────────────────────── */
$projects = [];
$res = $conn->query("SELECT project_id, project_name, project_code FROM projects ORDER BY project_name");
while ($r = $res->fetch_assoc()) $projects[] = $r;

$programs = [];
$res = $conn->query("SELECT id, program_name, program_code FROM programs ORDER BY program_name");
while ($r = $res->fetch_assoc()) $programs[] = $r;

/* ── Stats ──────────────────────────────────────────────────────────── */
$stats = [
    'total'         => count($indicators),
    'achieved'      => count(array_filter($indicators, fn($i) => $i['achievement_percentage'] >= 100)),
    'on_track'      => count(array_filter($indicators, fn($i) => $i['achievement_percentage'] >= 50 && $i['achievement_percentage'] < 100)),
    'below_target'  => count(array_filter($indicators, fn($i) => $i['achievement_percentage'] < 50)),
    'outputs'       => count(array_filter($indicators, fn($i) => $i['indicator_type'] === 'Output')),
    'outcomes'      => count(array_filter($indicators, fn($i) => $i['indicator_type'] === 'Outcome')),
    'impacts'       => count(array_filter($indicators, fn($i) => $i['indicator_type'] === 'Impact')),
    'project_based' => count(array_filter($indicators, fn($i) => $i['context_type'] === 'Project')),
    'program_based' => count(array_filter($indicators, fn($i) => $i['context_type'] === 'Program')),
];

/* ── Helpers ────────────────────────────────────────────────────────── */
function ach_class(float $pct): string {
    if ($pct >= 100) return 'high';
    if ($pct >= 50)  return 'mid';
    return 'low';
}
function ach_icon(float $pct): string {
    if ($pct >= 100) return 'fa-check-circle';
    if ($pct >= 50)  return 'fa-chart-line';
    return 'fa-exclamation-triangle';
}
function type_badge(string $type): string {
    return match($type) {
        'Output'  => 'badge badge-info',
        'Outcome' => 'badge badge-warning',
        'Impact'  => 'badge badge-purple',
        default   => 'badge badge-returned',
    };
}
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/indicators.css">

<div class="page-stack">

    <!-- ── Hero ───────────────────────────────────────────────────────── -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-chart-line" aria-hidden="true"></i> Indicators</h1>
            <p>Track output, outcome and impact indicators across all projects and programmes.</p>
        </div>
        <div class="hero-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('addIndicatorModal')">
                <i class="fas fa-plus"></i> Add Indicator
            </button>
            <a href="export?type=indicators" class="btn">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>
    </div>

    <!-- ── Performance Stats ──────────────────────────────────────────── -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Indicators</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Achieved (≥ 100%)</div>
                <div class="stat-value"><?= $stats['achieved'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-chart-bar"></i></div>
            <div class="stat-info">
                <div class="stat-label">On Track (50 - 99%)</div>
                <div class="stat-value"><?= $stats['on_track'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-red"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Below Target (&lt; 50%)</div>
                <div class="stat-value"><?= $stats['below_target'] ?></div>
            </div>
        </div>
    </div>

    <!-- ── Type & Context Stats ───────────────────────────────────────── -->
    <div class="stats-grid reports-stats ind-stats-5">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info">
                <div class="stat-label">Output Indicators</div>
                <div class="stat-value"><?= $stats['outputs'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-bullseye"></i></div>
            <div class="stat-info">
                <div class="stat-label">Outcome Indicators</div>
                <div class="stat-value"><?= $stats['outcomes'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <div class="stat-label">Impact Indicators</div>
                <div class="stat-value"><?= $stats['impacts'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-project-diagram"></i></div>
            <div class="stat-info">
                <div class="stat-label">Project Indicators</div>
                <div class="stat-value"><?= $stats['project_based'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-sitemap"></i></div>
            <div class="stat-info">
                <div class="stat-label">Program Indicators</div>
                <div class="stat-value"><?= $stats['program_based'] ?></div>
            </div>
        </div>
    </div>

    <!-- ── Filter Bar ─────────────────────────────────────────────────── -->
    <form method="GET" action="" class="controls-bar filters-bar">

        <div class="form-group search-group filters-grow">
            <label class="form-label">Search</label>
            <div class="search-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="form-control"
                       placeholder="Indicator name…" value="<?= h($search) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Context</label>
            <select name="context" id="filter_context" class="form-control" onchange="toggleContextFilters()">
                <option value="">All Contexts</option>
                <option value="project" <?= $filter_context==='project'?'selected':'' ?>>Projects Only</option>
                <option value="program" <?= $filter_context==='program'?'selected':'' ?>>Programs Only</option>
            </select>
        </div>

        <div class="form-group" id="filter_project_group"
             style="display:<?= $filter_context==='program'?'none':'flex' ?>;">
            <label class="form-label">Project</label>
            <select name="project" class="form-control">
                <option value="">All Projects</option>
                <?php foreach ($projects as $pr): ?>
                    <option value="<?= (int)$pr['project_id'] ?>"
                        <?= $filter_project===$pr['project_id']?'selected':'' ?>>
                        <?= h($pr['project_code'].' - '.$pr['project_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="filter_program_group"
             style="display:<?= $filter_context==='project'?'none':'flex' ?>;">
            <label class="form-label">Program</label>
            <select name="program" class="form-control">
                <option value="">All Programs</option>
                <?php foreach ($programs as $pg): ?>
                    <option value="<?= (int)$pg['id'] ?>"
                        <?= $filter_program===$pg['id']?'selected':'' ?>>
                        <?= h($pg['program_code'].' - '.$pg['program_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Type</label>
            <select name="type" class="form-control">
                <option value="">All Types</option>
                <?php foreach (['Output','Outcome','Impact'] as $t): ?>
                    <option value="<?= $t ?>" <?= $filter_type===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Achievement</label>
            <select name="achievement" class="form-control">
                <option value="">All Levels</option>
                <option value="high"   <?= $filter_achievement==='high'  ?'selected':'' ?>>Achieved (≥ 100%)</option>
                <option value="medium" <?= $filter_achievement==='medium'?'selected':'' ?>>On Track (50-99%)</option>
                <option value="low"    <?= $filter_achievement==='low'   ?'selected':'' ?>>Below Target (&lt; 50%)</option>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="indicators" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
        </div>

    </form>

    <!-- ── Indicators Table ───────────────────────────────────────────── -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-chart-line" aria-hidden="true"></i> Indicators</h3>
            <span class="badge <?= $stats['total']>0?'badge-available':'badge-pending' ?>">
                <?= $stats['total'] ?> record<?= $stats['total']!==1?'s':'' ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Context</th>
                        <th>Programme / Project</th>
                        <th>Indicator</th>
                        <th>Type</th>
                        <th class="text-right">Baseline</th>
                        <th class="text-right">Target</th>
                        <th class="text-right">Current</th>
                        <th>Achievement</th>
                        <th>Progress</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($indicators)): ?>
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <i class="fas fa-chart-line" aria-hidden="true"></i>
                                    <p>No indicators found. Adjust your filters or add a new indicator.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($indicators as $ind):
                            $pct     = (float)$ind['achievement_percentage'];
                            $ac      = ach_class($pct);
                            $is_proj = $ind['context_type'] === 'Project';
                        ?>
                            <tr>
                                <!-- Context type chip -->
                                <td>
                                    <span class="ctx-chip <?= $is_proj?'project':'program' ?>">
                                        <i class="fas <?= $is_proj?'fa-project-diagram':'fa-sitemap' ?>"></i>
                                        <?= h($ind['context_type']) ?>
                                    </span>
                                </td>

                                <!-- Code + name -->
                                <td>
                                    <div class="ctx-cell">
                                        <span class="ctx-code" title="<?= h($ind['context_name']) ?>">
                                            <?= h($ind['context_code']) ?>
                                        </span>
                                        <span class="ctx-name">
                                            <?= h($is_proj ? ($ind['project_name']??'') : ($ind['program_name']??'')) ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Indicator name + unit -->
                                <td class="ind-name-td">
                                    <div class="ind-name-cell">
                                        <span class="ind-name"><?= h($ind['indicator_name']) ?></span>
                                        <?php if ($ind['unit_of_measure']): ?>
                                            <span class="ind-unit"><?= h($ind['unit_of_measure']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Type badge -->
                                <td>
                                    <span class="<?= type_badge($ind['indicator_type']) ?>">
                                        <?= h($ind['indicator_type']) ?>
                                    </span>
                                </td>

                                <!-- Values -->
                                <td class="num-cell"><?= number_format((float)$ind['baseline_value'], 2) ?></td>
                                <td class="num-cell"><?= number_format((float)$ind['target_value'],   2) ?></td>
                                <td class="num-cell current"><?= number_format((float)$ind['current_value'], 2) ?></td>

                                <!-- Achievement -->
                                <td>
                                    <div class="ach-cell <?= $ac ?>">
                                        <i class="fas <?= ach_icon($pct) ?> ach-icon"></i>
                                        <span class="ach-pct"><?= number_format($pct, 1) ?>%</span>
                                    </div>
                                </td>

                                <!-- Progress bar -->
                                <td>
                                    <div class="prog-cell">
                                        <div class="prog-track">
                                            <div class="prog-fill <?= $ac ?>" style="width:<?= min($pct,100) ?>%;"></div>
                                        </div>
                                        <div class="prog-updates"><?= (int)$ind['progress_count'] ?> update<?= $ind['progress_count']!=1?'s':'' ?></div>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div class="actions">
                                        <a href="?progress=<?= (int)$ind['indicator_id'] ?>"
                                           class="btn btn-sm btn-soft" title="Update Progress">
                                            <i class="fas fa-plus-circle"></i>
                                        </a>
                                        <a href="indicator-details?id=<?= (int)$ind['indicator_id'] ?>"
                                           class="btn btn-sm btn-secondary" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?edit=<?= (int)$ind['indicator_id'] ?>"
                                           class="btn btn-sm btn-secondary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (ims_allowed('indicators.delete', ['Administrator', 'MEAL Lead'], 'Delete indicators')): ?>
                                            <button class="btn btn-sm btn-danger" title="Delete"
                                                    onclick="openDeleteModal(<?= (int)$ind['indicator_id'] ?>, <?= json_encode((string)$ind['indicator_name']) ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div><!-- /.table-responsive -->
    </div><!-- /.panel -->

</div><!-- /.page-stack -->


<!-- ══════════════════════════════════════════════════════════════════
     ADD INDICATOR MODAL
     ══════════════════════════════════════════════════════════════════ -->
<div class="modal" id="addIndicatorModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-plus-circle ind-modal-icon" aria-hidden="true"></i> Add New Indicator</h3>
            <button class="modal-close" onclick="closeModal('addIndicatorModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/indicator-process.php">

                <div class="modal-section-label">Context</div>
                <div class="form-group ind-field-gap">
                    <label class="form-label" for="context_type">
                        Indicator Context <span class="required-mark">*</span>
                    </label>
                    <select id="context_type" name="context_type" class="form-control" required
                            onchange="toggleContextSelect('project_select','program_select','project_id','program_id',this.value)">
                        <option value="">- Select Context -</option>
                        <option value="project">Project</option>
                        <option value="program">Program</option>
                    </select>
                </div>

                <div class="form-group ind-field-gap" id="project_select" style="display:none;">
                    <label class="form-label" for="project_id">
                        Project <span class="required-mark">*</span>
                    </label>
                    <select id="project_id" name="project_id" class="form-control">
                        <option value="">- Select Project -</option>
                        <?php foreach ($projects as $pr): ?>
                            <option value="<?= (int)$pr['project_id'] ?>"><?= h($pr['project_code'].' - '.$pr['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group ind-field-gap" id="program_select" style="display:none;">
                    <label class="form-label" for="program_id">
                        Program <span class="required-mark">*</span>
                    </label>
                    <select id="program_id" name="program_id" class="form-control">
                        <option value="">- Select Program -</option>
                        <?php foreach ($programs as $pg): ?>
                            <option value="<?= (int)$pg['id'] ?>"><?= h($pg['program_code'].' - '.$pg['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-section-label">Indicator Details</div>
                <div class="form-group full ind-field-gap">
                    <label class="form-label" for="indicator_name">
                        Indicator Name <span class="required-mark">*</span>
                    </label>
                    <textarea id="indicator_name" name="indicator_name" class="form-control"
                              rows="2" required placeholder="Describe the indicator…"></textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="indicator_type">
                            Type <span class="required-mark">*</span>
                        </label>
                        <select id="indicator_type" name="indicator_type" class="form-control" required>
                            <option value="">- Select Type -</option>
                            <option value="Output">Output</option>
                            <option value="Outcome">Outcome</option>
                            <option value="Impact">Impact</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="unit_of_measure">Unit of Measure</label>
                        <input type="text" id="unit_of_measure" name="unit_of_measure" class="form-control"
                               placeholder="e.g., Number, %, UGX">
                    </div>
                </div>

                <div class="modal-section-label">Values</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="baseline_value">Baseline Value</label>
                        <input type="number" id="baseline_value" name="baseline_value" class="form-control" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="target_value">
                            Target Value <span class="required-mark">*</span>
                        </label>
                        <input type="number" id="target_value" name="target_value" class="form-control" step="0.01" required>
                    </div>
                </div>

                <div class="modal-section-label">Data Collection</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="data_source">Data Source</label>
                        <input type="text" id="data_source" name="data_source" class="form-control"
                               placeholder="e.g., Databases, reports">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="collection_method">Collection Method</label>
                        <input type="text" id="collection_method" name="collection_method" class="form-control"
                               placeholder="e.g., Surveys, interviews">
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="reporting_frequency">Reporting Frequency</label>
                        <select id="reporting_frequency" name="reporting_frequency" class="form-control">
                            <option value="">- Select Frequency -</option>
                            <?php foreach (['Monthly','Quarterly','Semi-Annually','Annually'] as $f): ?>
                                <option value="<?= $f ?>"><?= $f ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addIndicatorModal')">Cancel</button>
                    <button type="submit" name="add_indicator" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Indicator
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════
     EDIT INDICATOR MODAL
     ══════════════════════════════════════════════════════════════════ -->
<div class="modal" id="editIndicatorModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-edit ind-modal-icon" aria-hidden="true"></i> Edit Indicator</h3>
            <button class="modal-close" onclick="closeModal('editIndicatorModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($edit_indicator): ?>
                <form method="POST" action="includes/indicator-process.php">
                    <input type="hidden" name="indicator_id" value="<?= (int)$edit_indicator['indicator_id'] ?>">

                    <div class="modal-section-label">Context</div>
                    <div class="form-group ind-field-gap">
                        <label class="form-label" for="e_context_type">
                            Indicator Context <span class="required-mark">*</span>
                        </label>
                        <select id="e_context_type" name="context_type" class="form-control" required
                                onchange="toggleContextSelect('e_project_select','e_program_select','e_project_id','e_program_id',this.value)">
                            <option value="">- Select Context -</option>
                            <option value="project" <?= $edit_indicator['project_id']?'selected':'' ?>>Project</option>
                            <option value="program" <?= $edit_indicator['program_id']?'selected':'' ?>>Program</option>
                        </select>
                    </div>

                    <div class="form-group ind-field-gap" id="e_project_select"
                         style="display:<?= $edit_indicator['project_id']?'flex':'none' ?>;">
                        <label class="form-label" for="e_project_id">Project <span class="required-mark">*</span></label>
                        <select id="e_project_id" name="project_id" class="form-control">
                            <option value="">- Select Project -</option>
                            <?php foreach ($projects as $pr): ?>
                                <option value="<?= (int)$pr['project_id'] ?>"
                                    <?= $edit_indicator['project_id']==$pr['project_id']?'selected':'' ?>>
                                    <?= h($pr['project_code'].' - '.$pr['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group ind-field-gap" id="e_program_select"
                         style="display:<?= $edit_indicator['program_id']?'flex':'none' ?>;">
                        <label class="form-label" for="e_program_id">Program <span class="required-mark">*</span></label>
                        <select id="e_program_id" name="program_id" class="form-control">
                            <option value="">- Select Program -</option>
                            <?php foreach ($programs as $pg): ?>
                                <option value="<?= (int)$pg['id'] ?>"
                                    <?= $edit_indicator['program_id']==$pg['id']?'selected':'' ?>>
                                    <?= h($pg['program_code'].' - '.$pg['program_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="modal-section-label">Indicator Details</div>
                    <div class="form-group full ind-field-gap">
                        <label class="form-label" for="e_indicator_name">
                            Indicator Name <span class="required-mark">*</span>
                        </label>
                        <textarea id="e_indicator_name" name="indicator_name" class="form-control"
                                  rows="2" required><?= h($edit_indicator['indicator_name']) ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="e_indicator_type">Type <span class="required-mark">*</span></label>
                            <select id="e_indicator_type" name="indicator_type" class="form-control" required>
                                <?php foreach (['Output','Outcome','Impact'] as $t): ?>
                                    <option value="<?= $t ?>" <?= $edit_indicator['indicator_type']===$t?'selected':'' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="e_unit">Unit of Measure</label>
                            <input type="text" id="e_unit" name="unit_of_measure" class="form-control"
                                   value="<?= h($edit_indicator['unit_of_measure']??'') ?>">
                        </div>
                    </div>

                    <div class="modal-section-label">Values</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="e_baseline">Baseline Value</label>
                            <input type="number" id="e_baseline" name="baseline_value" class="form-control"
                                   step="0.01" value="<?= h($edit_indicator['baseline_value']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="e_target">Target Value <span class="required-mark">*</span></label>
                            <input type="number" id="e_target" name="target_value" class="form-control"
                                   step="0.01" value="<?= h($edit_indicator['target_value']) ?>" required>
                        </div>
                    </div>

                    <div class="modal-section-label">Data Collection</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="e_source">Data Source</label>
                            <input type="text" id="e_source" name="data_source" class="form-control"
                                   value="<?= h($edit_indicator['data_source']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="e_method">Collection Method</label>
                            <input type="text" id="e_method" name="collection_method" class="form-control"
                                   value="<?= h($edit_indicator['collection_method']??'') ?>">
                        </div>
                        <div class="form-group full">
                            <label class="form-label" for="e_freq">Reporting Frequency</label>
                            <select id="e_freq" name="reporting_frequency" class="form-control">
                                <option value="">- Select Frequency -</option>
                                <?php foreach (['Monthly','Quarterly','Semi-Annually','Annually'] as $f): ?>
                                    <option value="<?= $f ?>"
                                        <?= ($edit_indicator['reporting_frequency']??'')===$f?'selected':'' ?>>
                                        <?= $f ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editIndicatorModal')">Cancel</button>
                        <button type="submit" name="edit_indicator" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Indicator
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p class="note ind-note">No indicator selected for editing.</p>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════
     UPDATE PROGRESS MODAL
     ══════════════════════════════════════════════════════════════════ -->
<div class="modal" id="progressModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3><i class="fas fa-plus-circle ind-modal-icon" aria-hidden="true"></i> Update Progress</h3>
            <button class="modal-close" onclick="closeModal('progressModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($progress_indicator): ?>
                <!-- Indicator info box -->
                <div class="progress-info-box">
                    <div class="progress-info-title"><?= h($progress_indicator['indicator_name']) ?></div>
                    <div class="progress-info-context">
                        <?php if ($progress_indicator['project_id']): ?>
                            <i class="fas fa-project-diagram ind-ctx-icon project" aria-hidden="true"></i>
                            <?= h($progress_indicator['project_code'].' - '.$progress_indicator['project_name']) ?>
                        <?php else: ?>
                            <i class="fas fa-sitemap ind-ctx-icon program" aria-hidden="true"></i>
                            <?= h($progress_indicator['program_code'].' - '.$progress_indicator['program_name']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="progress-vals">
                        <div class="progress-val-cell">
                            <div class="pv-label">Baseline</div>
                            <div class="pv-value"><?= number_format((float)$progress_indicator['baseline_value'], 2) ?></div>
                        </div>
                        <div class="progress-val-cell">
                            <div class="pv-label">Target</div>
                            <div class="pv-value target"><?= number_format((float)$progress_indicator['target_value'], 2) ?></div>
                        </div>
                        <div class="progress-val-cell">
                            <div class="pv-label">Current</div>
                            <div class="pv-value current"><?= number_format((float)$progress_indicator['current_value'], 2) ?></div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="includes/indicator-process.php">
                    <input type="hidden" name="indicator_id" value="<?= (int)$progress_indicator['indicator_id'] ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="reporting_period">
                                Reporting Period <span class="required-mark">*</span>
                            </label>
                            <input type="month" id="reporting_period" name="reporting_period" class="form-control"
                                   value="<?= date('Y-m') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="actual_value">
                                Actual Value <span class="required-mark">*</span>
                            </label>
                            <input type="number" id="actual_value" name="actual_value" class="form-control"
                                   step="0.01" required>
                        </div>
                        <div class="form-group full">
                            <label class="form-label" for="responsible_person">Responsible Person</label>
                            <input type="text" id="responsible_person" name="responsible_person" class="form-control"
                                   value="<?= h($_SESSION['full_name'] ?? '') ?>">
                        </div>
                        <div class="form-group full">
                            <label class="form-label" for="progress_notes">Progress Notes</label>
                            <textarea id="progress_notes" name="progress_notes" class="form-control"
                                      rows="3" placeholder="Any observations about this update…"></textarea>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('progressModal')">Cancel</button>
                        <button type="submit" name="update_progress" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Progress
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════
     DELETE MODAL
     ══════════════════════════════════════════════════════════════════ -->
<div class="modal" id="deleteModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3><i class="fas fa-trash ind-modal-icon danger" aria-hidden="true"></i> Delete Indicator</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/indicator-process.php">
                <input type="hidden" name="indicator_id" id="deleteIndicatorId">

                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    <p>You are about to permanently delete <strong id="deleteIndicatorName"></strong>. This action cannot be undone.</p>
                </div>

                <div class="alert alert-warning">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <span>All progress records for this indicator must be removed first.</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" name="delete_indicator" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
(function () {
    'use strict';

    /* ── Modal open / close ── */
    window.openModal = function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
    };
    window.closeModal = function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('show');
        document.body.style.overflow = '';
    };
    document.querySelectorAll('.modal').forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) window.closeModal(m.id); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal.show').forEach(function (m) { window.closeModal(m.id); });
    });

    /* ── Context select toggle (shared by Add and Edit modals) ── */
    window.toggleContextSelect = function (projDivId, progDivId, projInputId, progInputId, value) {
        const projDiv   = document.getElementById(projDivId);
        const progDiv   = document.getElementById(progDivId);
        const projInput = document.getElementById(projInputId);
        const progInput = document.getElementById(progInputId);

        if (value === 'project') {
            if (projDiv)   { projDiv.style.display = 'flex'; projInput.required = true; }
            if (progDiv)   { progDiv.style.display = 'none';  progInput.required = false; progInput.value = ''; }
        } else if (value === 'program') {
            if (projDiv)   { projDiv.style.display = 'none';  projInput.required = false; projInput.value = ''; }
            if (progDiv)   { progDiv.style.display = 'flex'; progInput.required = true; }
        } else {
            if (projDiv)   { projDiv.style.display = 'none';  projInput.required = false; projInput.value = ''; }
            if (progDiv)   { progDiv.style.display = 'none';  progInput.required = false; progInput.value = ''; }
        }
    };

    /* ── Filter context toggle ── */
    window.toggleContextFilters = function () {
        const val  = document.getElementById('filter_context').value;
        const proj = document.getElementById('filter_project_group');
        const prog = document.getElementById('filter_program_group');
        if (proj) proj.style.display = val === 'program' ? 'none' : 'flex';
        if (prog) prog.style.display = val === 'project' ? 'none' : 'flex';
    };

    /* ── Delete modal ── */
    window.openDeleteModal = function (id, name) {
        document.getElementById('deleteIndicatorId').value  = id;
        document.getElementById('deleteIndicatorName').textContent = '"' + name + '"';
        window.openModal('deleteModal');
    };

    /* ── Auto-open modals ── */
    <?php if ($edit_indicator): ?>
    document.addEventListener('DOMContentLoaded', function () { window.openModal('editIndicatorModal'); });
    <?php endif; ?>
    <?php if ($progress_indicator): ?>
    document.addEventListener('DOMContentLoaded', function () { window.openModal('progressModal'); });
    <?php endif; ?>

})();
</script>

<?php include 'includes/footer.php'; ?>