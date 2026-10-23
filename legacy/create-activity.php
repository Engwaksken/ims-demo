<?php

$page_title = 'Create Activity Plan';
include 'includes/header.php';

// Get user's department
$user_dept_query = $conn->query("SELECT ed.department_id, d.department_name 
    FROM employee_directory ed 
    LEFT JOIN departments d ON ed.department_id = d.department_id 
    WHERE ed.user_id = {$_SESSION['user_id']}");
$user_dept = $user_dept_query->fetch_assoc();

if (!$user_dept || !$user_dept['department_id']) {
    send_notification($_SESSION['user_id'], 'Please complete your employee profile first', 'warning');
    header("Location: my-profile");
    exit();
}

$categories = [];
$result = $conn->query("SELECT * FROM kpi_categories ORDER BY category_name");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

$current_year = date('Y');
?>

<style>
/* Create Activity Plan: page-only layout (ca- prefix) */
.ca-page .ca-meta-grid { display: grid; grid-template-columns: 180px 1fr 1fr; gap: 16px; }
.ca-page .ca-meta-grid .form-group { margin-bottom: 0; }
.ca-weight-total { font-size: var(--fs-sm); font-weight: 600; color: var(--ink-300); }
.ca-mono { font-variant-numeric: tabular-nums; }

/* Weight indicator */
.ca-weight-bar {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; margin-bottom: 16px;
    background: var(--brand-50); border-radius: var(--radius-sm);
    font-size: var(--fs-sm);
}
.ca-weight-bar .ca-weight-title { font-size: var(--fs-xs); color: var(--brand-700); font-weight: 600; }
.ca-weight-bar .bar-wrap { flex: 1; height: 8px; background: var(--brand-100); border-radius: var(--radius-pill); overflow: hidden; }
.ca-weight-bar .bar-fill { height: 100%; width: 0%; background: var(--brand-500); border-radius: var(--radius-pill); transition: width .3s; }
.ca-weight-bar .bar-fill.over { background: var(--red-fg); }
.ca-weight-bar .ca-weight-label { font-weight: 600; min-width: 70px; text-align: right; }

/* Activity entry table: compact inputs inside table cells */
.ca-page .ca-activity-table { min-width: 900px; }
.ca-page .ca-activity-table td { padding: 8px; }
.ca-page .ca-activity-table td .form-control,
.ca-page .ca-week-table td .form-control { padding: 7px 9px; font-size: var(--fs-sm); }
.ca-page .ca-activity-table td.td-num { width: 36px; text-align: center; font-size: var(--fs-xs); color: var(--ink-300); }
.ca-page .ca-activity-table td.td-del { width: 48px; text-align: center; }
.ca-page .ca-activity-table td.td-weight { width: 90px; }
.ca-page .ca-col-num { width: 36px; }
.ca-page .ca-col-wide { min-width: 200px; }
.ca-page .ca-col-weight { width: 90px; }
.ca-page .ca-col-unit { min-width: 120px; }
.ca-page .ca-col-target { min-width: 100px; }
.ca-page .ca-col-del { width: 48px; }
.ca-req { color: var(--red-fg); }
.ca-add-row { margin-top: 14px; }

/* Quarter tabs (JS toggles .q-tab / .q-tab-content .active) */
.ca-tabs-wrap { padding: 20px 22px 0; }
.ca-page .quarter-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.ca-page .q-tab {
    padding: 8px 18px; border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    font-size: var(--fs-sm); font-weight: 600; cursor: pointer; user-select: none;
    border: 1px solid var(--ink-100); border-bottom: none;
    background: var(--ink-50); color: var(--ink-300); transition: all .15s;
}
.ca-page .q-tab.active { background: var(--surface-card); color: var(--brand-600); position: relative; z-index: 1; }
.ca-page .q-tab .ca-q-months { font-size: 10px; font-weight: 400; opacity: .7; }
.ca-page .q-tab-content { display: none; padding: 0 22px 22px; }
.ca-page .q-tab-content.active { display: block; }
.ca-quarter-panel {
    border: 1px solid var(--ink-100);
    border-radius: 0 var(--radius-md) var(--radius-md) var(--radius-md);
    background: var(--surface-card); overflow: hidden;
}

/* Week selector chips (JS toggles .week-chip.selected) */
.ca-week-selector {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    padding: 12px 16px; background: var(--surface-hover); border-bottom: 1px solid var(--ink-100);
}
.ca-week-selector > label { font-size: var(--fs-xs); font-weight: 600; color: var(--ink-300); margin: 0 4px 0 0; }
.ca-week-selector .ca-chip-all { margin-left: auto; }
.ca-page .week-chip {
    padding: 4px 11px; border-radius: var(--radius-pill);
    border: 1px solid var(--ink-100); background: var(--surface-card);
    font-size: var(--fs-xs); font-weight: 600; cursor: pointer; user-select: none;
    color: var(--ink-300); transition: all .12s;
}
.ca-page .week-chip.selected { background: var(--brand-600); border-color: var(--brand-600); color: var(--ink-0); }
.ca-no-weeks { padding: 32px; }

/* Weekly entry table */
.ca-page .ca-week-table th { text-align: center; }
.ca-page .ca-week-table th.th-activity { text-align: left; min-width: 180px; }
.ca-page .ca-week-table th.th-week { min-width: 110px; }
.ca-page .ca-week-table td { padding: 8px; }
.ca-page .ca-week-table td.td-activity-name { font-weight: 500; color: var(--ink-700); padding-left: 16px; border-left: 3px solid var(--brand-500); }
.ca-page .ca-week-table td.td-activity-name .key-result { display: block; margin-top: 2px; font-size: 11px; font-weight: 400; color: var(--ink-300); }
.ca-page .ca-week-table td.td-actual { min-width: 100px; }
.ca-page .ca-week-table td.td-comment { min-width: 180px; }
.ca-page .ca-week-table tr.ca-week-subhead td {
    background: var(--surface-hover); padding: 4px 8px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
    color: var(--ink-300); text-align: center;
}

/* Form actions */
.ca-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

@media (max-width: 768px) {
    .ca-page .ca-meta-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="page-stack ca-page">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-tasks" aria-hidden="true"></i> Create Activity Plan</h1>
        <p>Define activities, key results, weightings, and weekly tracking targets for <?php echo htmlspecialchars($user_dept['department_name']); ?>.</p>
    </div>
    <div class="hero-actions">
        <a href="my-kpis" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
    </div>
</div>

<form method="POST" action="activity-process" id="activityForm" class="page-stack">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="department_id" value="<?php echo $user_dept['department_id']; ?>">

    <!-- -- Meta -- -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-sliders-h" aria-hidden="true"></i> Plan Details</h3>
        </div>
        <div class="card-body">
            <div class="ca-meta-grid">
                <div class="form-group">
                    <label>Fiscal Year <span class="ca-req">*</span></label>
                    <select name="fiscal_year" class="form-control" required>
                        <option value="<?php echo $current_year; ?>"><?php echo $current_year; ?></option>
                        <option value="<?php echo $current_year+1; ?>"><?php echo $current_year+1; ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Category <span class="ca-req">*</span></label>
                    <select name="category_id" class="form-control" required>
                        <option value="">Select category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Plan Title <span class="ca-req">*</span></label>
                    <input type="text" name="plan_title" class="form-control" placeholder="e.g., Q1 Operational Goals" required maxlength="200">
                </div>
            </div>
        </div>
    </div>

    <!-- -- Activities -- -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list-check" aria-hidden="true"></i> Activities</h3>
            <div id="weightDisplay" class="ca-weight-total">
                Total Weight: <span id="totalWeightVal" class="ca-mono">0%</span>
            </div>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
                Add each activity with its key result and relative weight. Weights should sum to 100%.
            </div>

            <!-- Weight progress bar -->
            <div class="ca-weight-bar">
                <span class="ca-weight-title">Weight Total</span>
                <div class="bar-wrap"><div class="bar-fill" id="weightBar"></div></div>
                <span class="ca-weight-label ca-mono"><span id="weightPct">0</span> / 100%</span>
            </div>

            <div class="table-responsive">
                <table class="table ca-activity-table" id="activityTable">
                    <thead>
                        <tr>
                            <th class="ca-col-num">#</th>
                            <th class="ca-col-wide">Activity Description <span class="ca-req">*</span></th>
                            <th class="ca-col-wide">Key Result <span class="ca-req">*</span></th>
                            <th class="ca-col-weight">Weight (%) <span class="ca-req">*</span></th>
                            <th class="ca-col-unit">Unit of Measure</th>
                            <th class="ca-col-target">Annual Target</th>
                            <th class="ca-col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="activityRows">
                        <!-- rows injected by JS -->
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-soft ca-add-row" id="btnAddActivity">
                <i class="fas fa-plus" aria-hidden="true"></i> Add Activity
            </button>
        </div>
    </div>

    <!-- -- Weekly Tracking -- -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-week" aria-hidden="true"></i> Weekly Tracking by Quarter</h3>
        </div>

        <!-- Quarter tabs -->
        <div class="ca-tabs-wrap">
            <div class="quarter-tabs">
                <div class="q-tab active" data-q="q1">Q1 <span class="ca-q-months">Jan-Mar</span></div>
                <div class="q-tab" data-q="q2">Q2 <span class="ca-q-months">Apr-Jun</span></div>
                <div class="q-tab" data-q="q3">Q3 <span class="ca-q-months">Jul-Sep</span></div>
                <div class="q-tab" data-q="q4">Q4 <span class="ca-q-months">Oct-Dec</span></div>
            </div>
        </div>

        <!-- Q panels -->
        <?php
        $quarters = [
            'q1' => ['label'=>'Q1','months'=>'Jan - Mar','weeks'=>12],
            'q2' => ['label'=>'Q2','months'=>'Apr - Jun','weeks'=>12],
            'q3' => ['label'=>'Q3','months'=>'Jul - Sep','weeks'=>12],
            'q4' => ['label'=>'Q4','months'=>'Oct - Dec','weeks'=>12],
        ];
        foreach ($quarters as $qk => $qv): ?>
        <div class="q-tab-content <?php echo $qk==='q1'?'active':''; ?>" id="tab-<?php echo $qk; ?>">
            <div class="ca-quarter-panel">
                <!-- Week selector -->
                <div class="ca-week-selector" id="chipWrap-<?php echo $qk; ?>">
                    <label>Select weeks to track:</label>
                    <?php for ($w = 1; $w <= $qv['weeks']; $w++): ?>
                    <div class="week-chip" data-q="<?php echo $qk; ?>" data-week="<?php echo $w; ?>" onclick="toggleWeek(this)">W<?php echo $w; ?></div>
                    <?php endfor; ?>
                    <button type="button" class="btn btn-sm btn-secondary ca-chip-all" onclick="selectAllWeeks('<?php echo $qk; ?>')">All</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="clearAllWeeks('<?php echo $qk; ?>')">Clear</button>
                </div>

                <!-- Week entry table -->
                <div class="week-entry-area" id="weekArea-<?php echo $qk; ?>">
                    <div class="empty-state ca-no-weeks" id="noWeeksMsg-<?php echo $qk; ?>">
                        <i class="fas fa-mouse-pointer" aria-hidden="true"></i>
                        <p>Select weeks above to begin entering tracking data.</p>
                    </div>
                    <div class="table-responsive" id="weekTableWrap-<?php echo $qk; ?>" style="display:none;">
                        <table class="table ca-week-table">
                            <thead>
                                <tr id="weekTableHead-<?php echo $qk; ?>">
                                    <th class="th-activity">Activity</th>
                                    <!-- week columns injected -->
                                </tr>
                            </thead>
                            <tbody id="weekTableBody-<?php echo $qk; ?>">
                                <!-- rows injected -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- -- Form Actions -- -->
    <div class="card">
        <div class="card-body ca-actions">
            <button type="submit" name="save_draft" class="btn btn-secondary">
                <i class="fas fa-save" aria-hidden="true"></i> Save Draft
            </button>
            <button type="submit" name="submit_plan" class="btn btn-primary">
                <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit for Review
            </button>
            <a href="my-kpis" class="btn btn-danger">
                <i class="fas fa-times" aria-hidden="true"></i> Cancel
            </a>
        </div>
    </div>

</form>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<script>
// -- State --
let activities = [];
let selectedWeeks = { q1: new Set(), q2: new Set(), q3: new Set(), q4: new Set() };

// -- Quarter tab switching --
document.querySelectorAll('.q-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.q-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.q-tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.q).classList.add('active');
    });
});

// -- Activity row management --
let rowCounter = 0;

function addActivityRow() {
    rowCounter++;
    const id = rowCounter;
    const tbody = document.getElementById('activityRows');
    const tr = document.createElement('tr');
    tr.dataset.id = id;
    tr.innerHTML = `
        <td class="td-num">${id}</td>
        <td><input type="text" class="form-control" name="activities[${id}][description]" placeholder="e.g., Complete monthly report" required></td>
        <td><input type="text" class="form-control" name="activities[${id}][key_result]" placeholder="e.g., 12 reports submitted on time" required></td>
        <td class="td-weight"><input type="number" class="form-control" name="activities[${id}][weight]" min="0" max="100" step="0.1" placeholder="20" oninput="updateWeightBar()" required></td>
        <td><input type="text" class="form-control" name="activities[${id}][unit]" placeholder="%, count, hrs..."></td>
        <td><input type="number" class="form-control" name="activities[${id}][annual_target]" step="0.01" placeholder="100"></td>
        <td class="td-del"><button type="button" class="btn btn-sm btn-danger btn-del-row" onclick="removeActivityRow(this, ${id})" aria-label="Remove activity"><i class="fas fa-trash-alt" aria-hidden="true"></i></button></td>
    `;
    tbody.appendChild(tr);
    activities.push({ id, description: '', key_result: '' });

    // listen for label changes to sync to week tables
    tr.querySelector('input[name*="[description]"]').addEventListener('input', function() {
        syncActivityLabel(id, this.value, tr.querySelector('input[name*="[key_result]"]').value);
    });
    tr.querySelector('input[name*="[key_result]"]').addEventListener('input', function() {
        syncActivityLabel(id, tr.querySelector('input[name*="[description]"]').value, this.value);
    });

    refreshWeekTables();
    updateWeightBar();
    renumberRows();
}

function removeActivityRow(btn, id) {
    if (document.querySelectorAll('#activityRows tr').length <= 1) {
        alert('You must have at least one activity.');
        return;
    }
    btn.closest('tr').remove();
    activities = activities.filter(a => a.id !== id);
    refreshWeekTables();
    updateWeightBar();
    renumberRows();
}

function renumberRows() {
    document.querySelectorAll('#activityRows tr').forEach((tr, i) => {
        tr.querySelector('.td-num').textContent = i + 1;
    });
}

function syncActivityLabel(id, desc, kr) {
    const act = activities.find(a => a.id === id);
    if (act) { act.description = desc; act.key_result = kr; }
    refreshWeekTables();
}

// -- Weight bar --
function updateWeightBar() {
    let total = 0;
    document.querySelectorAll('input[name*="[weight]"]').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    total = Math.min(total, 999);
    const pct = Math.min(total, 100);
    const bar = document.getElementById('weightBar');
    bar.style.width = pct + '%';
    bar.className = 'bar-fill' + (total > 100 ? ' over' : '');
    document.getElementById('weightPct').textContent = total.toFixed(1);
    document.getElementById('totalWeightVal').textContent = total.toFixed(1) + '%';
}

// -- Week chip toggling --
function toggleWeek(chip) {
    const q = chip.dataset.q;
    const w = parseInt(chip.dataset.week);
    if (selectedWeeks[q].has(w)) {
        selectedWeeks[q].delete(w);
        chip.classList.remove('selected');
    } else {
        selectedWeeks[q].add(w);
        chip.classList.add('selected');
    }
    refreshWeekTables();
}

function selectAllWeeks(q) {
    document.querySelectorAll(`.week-chip[data-q="${q}"]`).forEach(chip => {
        selectedWeeks[q].add(parseInt(chip.dataset.week));
        chip.classList.add('selected');
    });
    refreshWeekTables();
}

function clearAllWeeks(q) {
    document.querySelectorAll(`.week-chip[data-q="${q}"]`).forEach(chip => {
        chip.classList.remove('selected');
    });
    selectedWeeks[q].clear();
    refreshWeekTables();
}

// -- Build week tables --
function refreshWeekTables() {
    ['q1', 'q2', 'q3', 'q4'].forEach(q => buildWeekTable(q));
}

function buildWeekTable(q) {
    const weeks = Array.from(selectedWeeks[q]).sort((a,b) => a-b);
    const noMsg = document.getElementById('noWeeksMsg-' + q);
    const wrap = document.getElementById('weekTableWrap-' + q);
    const head = document.getElementById('weekTableHead-' + q);
    const body = document.getElementById('weekTableBody-' + q);
    const rows = document.querySelectorAll('#activityRows tr');

    if (weeks.length === 0 || rows.length === 0) {
        noMsg.style.display = 'block';
        wrap.style.display = 'none';
        return;
    }

    noMsg.style.display = 'none';
    wrap.style.display = 'block';

    // Build header
    // Preserve existing input values before rebuild
    const savedValues = {};
    body.querySelectorAll('input, select, textarea').forEach(el => {
        if (el.name) savedValues[el.name] = el.value;
    });

    // Header
    head.innerHTML = `<th class="th-activity">Activity / Key Result</th>`;
    weeks.forEach(w => {
        head.innerHTML += `
            <th class="th-week" colspan="2">
                Week ${w}
            </th>
        `;
    });

    // Sub-header row
    let subRow = `<tr class="ca-week-subhead">
        <td></td>`;
    weeks.forEach(() => {
        subRow += `
            <td>Actual (Target)</td>
            <td>Comments</td>
        `;
    });
    subRow += '</tr>';

    // Activity rows
    let bodyHtml = subRow;
    rows.forEach((tr, idx) => {
        const rowId = tr.dataset.id;
        const desc = tr.querySelector('input[name*="[description]"]').value || `Activity ${idx+1}`;
        const kr = tr.querySelector('input[name*="[key_result]"]').value || '';

        bodyHtml += `<tr>
            <td class="td-activity-name">
                ${escHtml(desc)}
                ${kr ? `<span class="key-result"> ${escHtml(kr)}</span>` : ''}
            </td>`;

        weeks.forEach(w => {
            const nameActual = `weekly[${q}][${rowId}][w${w}][actual]`;
            const nameComment = `weekly[${q}][${rowId}][w${w}][comment]`;
            const valActual = savedValues[nameActual] || '';
            const valComment = savedValues[nameComment] || '';

            bodyHtml += `
                <td class="td-actual">
                    <input type="number" class="form-control" step="0.01" name="${nameActual}" placeholder="-" value="${escHtml(valActual)}">
                </td>
                <td class="td-comment">
                    <input type="text" class="form-control" name="${nameComment}" placeholder="Add comment..." value="${escHtml(valComment)}" maxlength="200">
                </td>
            `;
        });

        bodyHtml += '</tr>';
    });

    body.innerHTML = bodyHtml;
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// -- Form validation --
document.getElementById('activityForm').addEventListener('submit', function(e) {
    const rows = document.querySelectorAll('#activityRows tr');
    if (rows.length === 0) {
        e.preventDefault();
        alert('Please add at least one activity.');
        return false;
    }

    let totalWeight = 0;
    document.querySelectorAll('input[name*="[weight]"]').forEach(inp => {
        totalWeight += parseFloat(inp.value) || 0;
    });

    if (Math.abs(totalWeight - 100) > 0.1) {
        if (!confirm(`Warning: Total weight is ${totalWeight.toFixed(1)}% (should be 100%). Continue anyway?`)) {
            e.preventDefault();
            return false;
        }
    }
});

// Add button
document.getElementById('btnAddActivity').addEventListener('click', addActivityRow);

// Initialise with one empty row
addActivityRow();
</script>