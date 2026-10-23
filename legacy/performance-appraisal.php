<?php

$page_title = 'Performance Appraisal';
include 'includes/header.php';

$uid = (int)$_SESSION['user_id'];

$profile_q = $conn->query("
    SELECT 
        u.full_name,
        d.department_name,
        ed.job_title,
       
        CONCAT(su.full_name) AS supervisor_name,
        su.user_id AS supervisor_id
    FROM users u
    LEFT JOIN employee_directory ed ON ed.user_id = u.user_id
    LEFT JOIN departments d ON d.department_id = ed.department_id
    LEFT JOIN users su ON su.user_id = ed.supervisor_id
    WHERE u.user_id = $uid
    LIMIT 1
");
$profile = $profile_q ? $profile_q->fetch_assoc() : [];

if (!$profile || !$profile['job_title']) {
    send_notification($uid, 'Please complete your employee profile before creating an appraisal.', 'warning');
    header("Location: my-profile");
    exit();
}

$current_year = date('Y');
?>
<style>
/* Performance Appraisal: page-only layout (prefix pa-, scoped to .pa-page) */
.pa-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.pa-grid-3 .form-group { margin-bottom: 0; }
.pa-grid-3 + .pa-grid-3 { margin-top: 16px; }
.pa-page .form-control[readonly],
.pa-page .form-control:disabled { background: var(--surface-hover); color: var(--ink-300); cursor: not-allowed; }
.pa-period { display: flex; align-items: center; gap: 10px; }
.pa-period span { font-size: 13px; color: var(--ink-300); }
.pa-period .form-control { flex: 1; min-width: 0; }
.pa-warn { color: var(--red-fg); }
.pa-head-note { font-size: 12px; color: var(--ink-300); }
.pa-page .alert.pa-flush { margin-bottom: 0; }

/* KRA builder */
#kraContainer { display: flex; flex-direction: column; gap: 20px; }
.pa-page .kra-block {
    background: var(--surface-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.pa-kra-header {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px;
    background: var(--brand-50);
    border-bottom: 1px solid var(--brand-100);
}
.pa-page .kra-num {
    white-space: nowrap; font-size: 12px; font-weight: 700;
    color: var(--brand-700); background: var(--surface-card);
    border: 1px solid var(--brand-100); border-radius: var(--radius-sm);
    padding: 4px 10px;
}
.pa-kra-header .form-control { flex: 1; font-weight: 600; }
.pa-kra-header .btn { flex-shrink: 0; }
.pa-kra-meta {
    display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px; padding: 14px 16px 0;
    background: var(--surface-hover); border-bottom: 1px solid var(--border);
}
.pa-kpi-wrap { padding: 14px 16px; }
.pa-page .table .pa-c { text-align: center; }
.pa-page .table .pa-seq { width: 36px; text-align: center; font-size: 11px; color: var(--ink-200); }
.pa-page .table .pa-del { width: 36px; text-align: center; }
.pa-col-kpi { min-width: 220px; }
.pa-col-weight { width: 80px; }
.pa-col-rate { width: 180px; }
.pa-col-n { width: 36px; }
.pa-add-kpi { margin: 0 16px 14px; }
.pa-comments {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; padding: 14px 16px 0;
    border-top: 1px solid var(--border);
}
.pa-label-note { font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; }
.pa-add-kra {
    display: flex; align-items: center; justify-content: center;
    gap: 8px; width: 100%; padding: 14px;
    background: var(--surface-hover); border: 2px dashed var(--ink-100);
    border-radius: var(--radius-lg); font: inherit; font-size: 14px; font-weight: 600;
    color: var(--ink-300); cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
}
.pa-add-kra:hover { background: var(--brand-50); border-color: var(--brand-500); color: var(--brand-600); }
.pa-form-actions { justify-content: flex-end; }

/* Preview modal (opened by toggling .open) */
.pa-modal.open { display: block; }
.pa-modal-card { max-width: 900px; }
.pa-modal-foot { margin: 0; padding: 14px 24px; border-top: 1px solid var(--ink-50); }

/* -----------------------------------------------
   PRINT TEMPLATE (preview + print). Print output keeps its own
   paper styling (Arial, grey rules) by design.
----------------------------------------------- */
#printArea { display: none; }

@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { display: block !important; position: fixed; top: 0; left: 0; width: 100%; }
}

.pa-print .ap-table {
    width: 100%; border-collapse: collapse;
    font-family: Arial, sans-serif; font-size: 11px;
}
.pa-print .ap-table td, .pa-print .ap-table th {
    border: 1px solid #999;
    padding: 4px 6px;
    vertical-align: top;
}
.pa-print .ap-table .ap-header-info td { border: none; padding: 3px 6px; }
.pa-print .ap-kra-title {
    background: #1e3a5f; color: #fff;
    font-weight: 700; font-size: 11.5px;
}
.pa-print .ap-section-heading {
    background: #bdd7ee; font-weight: 700;
    font-size: 11px; text-align: center;
}
.pa-print .ap-rating-cell { text-align: center; width: 30px; }
.pa-print .ap-label { font-weight: 600; background: #f2f2f2; }
.pa-print .ap-comment-label { font-weight: 700; font-size: 10px; color: #333; }

@media (max-width: 768px) {
    .pa-grid-3 { grid-template-columns: 1fr 1fr; }
    .pa-kra-meta, .pa-comments { grid-template-columns: 1fr; }
}
</style>

<div class="page-stack pa-page">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-clipboard-check" aria-hidden="true"></i> Performance Appraisal</h1>
        <p>Complete your self-assessment against Key Result Areas (KRAs).</p>
    </div>
    <div class="hero-actions">
        <a href="my-kpis" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
        <button type="button" class="btn btn-primary" onclick="generatePreview()">
            <i class="fas fa-eye" aria-hidden="true"></i> Preview &amp; Print
        </button>
    </div>
</div>

<form method="POST" action="includes/appraisal-process.php" id="appraisalForm" class="page-stack">
<input type="hidden" name="action" value="create_appraisal">

<!-- -- Personal Information -- -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-user" aria-hidden="true"></i> Personal &amp; Appraisal Information</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            Your profile and supervisor details are pre-filled. Complete the appraisal period and discussion date.
        </div>

        <div class="pa-grid-3">
            <div class="form-group">
                <label>Name (Last, First, MI)</label>
                <input type="text" name="full_name" class="form-control"
                       value="<?php echo htmlspecialchars($profile['full_name'] ?? ''); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Department</label>
                <input type="text" name="department" class="form-control"
                       value="<?php echo htmlspecialchars(($profile['department_name'] ?? '')); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Appraisal Period <span class="required-mark">*</span></label>
                <div class="pa-period">
                    <span>From</span>
                    <input type="date" name="period_from" class="form-control"
                           value="<?php echo $current_year; ?>-01-01" required>
                    <span>To</span>
                    <input type="date" name="period_to"   class="form-control"
                           value="<?php echo $current_year; ?>-12-31" required>
                </div>
            </div>
        </div>

        <div class="pa-grid-3">
            <div class="form-group">
                <label>Job Title</label>
                <input type="text" name="job_title" class="form-control"
                       value="<?php echo htmlspecialchars($profile['job_title'] ?? ''); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Supervisor</label>
                <input type="text" name="supervisor_name" class="form-control"
                       value="<?php echo htmlspecialchars($profile['supervisor_name'] ?? 'No supervisor assigned'); ?>" readonly>
                <input type="hidden" name="supervisor_id" value="<?php echo $profile['supervisor_id'] ?? ''; ?>">
                <div class="cell-muted">
                    <?php if (empty($profile['supervisor_name'])): ?>
                        <span class="pa-warn"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                        No supervisor assigned &mdash; contact HR.</span>
                    <?php else: ?>
                        Assigned from your employee profile.
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-group">
                <label>Discussion Date <span class="required-mark">*</span></label>
                <input type="date" name="discussion_date" class="form-control" required>
            </div>
        </div>
    </div>
</div>

<!-- -- KRA Builder -- -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bullseye" aria-hidden="true"></i> Assessment against Key Result Areas</h3>
        <span class="pa-head-note">Add KRAs and their KPIs below</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info pa-flush">
            <i class="fas fa-lightbulb" aria-hidden="true"></i>
            For each KRA, add KPI indicators. Rate yourself (1-5) per KPI. Your supervisor will complete Manager Rating after review.
        </div>
    </div>
</div>

<!-- KRA container -->
<div id="kraContainer"></div>

<!-- Add KRA btn -->
<button type="button" class="pa-add-kra" id="btnAddKra">
    <i class="fas fa-plus-circle" aria-hidden="true"></i> Add Key Result Area (KRA)
</button>

<!-- -- Submit -- -->
<div class="card">
    <div class="card-body">
        <div class="actions pa-form-actions">
            <button type="submit" name="save_draft" class="btn btn-secondary">
                <i class="fas fa-save" aria-hidden="true"></i> Save Draft
            </button>
            <button type="submit" name="submit_appraisal" class="btn btn-primary">
                <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit for Review
            </button>
            <a href="my-kpis" class="btn btn-danger"><i class="fas fa-times" aria-hidden="true"></i> Cancel</a>
        </div>
    </div>
</div>

</form>
</div><!-- /.page-stack -->

<!-- ------------------------------------------
     PREVIEW MODAL
------------------------------------------ -->
<div class="modal pa-modal" id="previewModal">
    <div class="modal-card pa-modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-print" aria-hidden="true"></i> Appraisal Preview</h3>
            <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body pa-print" id="modalBody"></div>
        <div class="modal-actions pa-modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
            <button type="button" class="btn btn-primary" onclick="doPrint()">
                <i class="fas fa-print" aria-hidden="true"></i> Print / Save PDF
            </button>
        </div>
    </div>
</div>

<!-- hidden print area -->
<div id="printArea" class="pa-print"></div>

<?php include 'includes/footer.php'; ?>

<script>
/* -----------------------------------------------
   STATE
----------------------------------------------- */
let kraCounter = 0;

const userInfo = {
    full_name:   <?php echo json_encode($profile['full_name'] ?? ''); ?>,
    department:  <?php echo json_encode(($profile['department_name'] ?? '')); ?>,
    job_title:   <?php echo json_encode($profile['job_title'] ?? ''); ?>,
    supervisor:  <?php echo json_encode($profile['supervisor_name'] ?? ''); ?>
};

/* -----------------------------------------------
   BUILD KRA BLOCK
----------------------------------------------- */
function addKra() {
    kraCounter++;
    const kid = kraCounter;
    const wrap = document.getElementById('kraContainer');

    const div = document.createElement('div');
    div.className = 'kra-block';
    div.dataset.kra = kid;

    div.innerHTML = `
    <!-- KRA header bar -->
    <div class="pa-kra-header">
        <span class="kra-num">KRA ${kid}</span>
        <input type="text" name="kra[${kid}][title]" class="form-control"
               placeholder="e.g., Key Result Area 1: Internet Management"
               required>
        <button type="button" class="btn btn-danger btn-sm"
                onclick="removeKra(${kid})">
            <i class="fas fa-trash"></i>
        </button>
    </div>

    <!-- KRA-level meta -->
    <div class="pa-kra-meta">
        <div class="form-group">
            <label>KRA Weight (%)</label>
            <input type="number" name="kra[${kid}][weight]" class="form-control" min="0" max="100" step="0.1"
                   placeholder="e.g., 30" oninput="updateTotalWeight()">
        </div>
        <div class="form-group">
            <label>KRA Rating (Computed)</label>
            <input type="text" name="kra[${kid}][kra_rating]" class="form-control" readonly placeholder="Auto-calculated"
                   id="kraRating${kid}"
                   title="Average of KPI employee ratings x weight">
        </div>
        <div class="form-group">
            <label>Agreed Rating (Overall)</label>
            <select name="kra[${kid}][agreed_rating]" class="form-control">
                <option value="">Select Agreed Rate</option>
                ${[1,2,3,4,5].map(n=>`<option value="${n}">${n}</option>`).join('')}
            </select>
        </div>
    </div>

    <!-- KPI rows -->
    <div class="pa-kpi-wrap table-responsive">
        <table class="table kpi-table" id="kpiTable${kid}">
            <thead>
                <tr>
                    <th class="pa-seq">#</th>
                    <th class="pa-col-kpi">KPI Indicator <span class="required-mark">*</span></th>
                    <th class="pa-col-weight">Weight (%)</th>
                    <th class="pa-c pa-col-rate" colspan="5">Employee Rating (1-5)</th>
                    <th class="pa-c pa-col-rate" colspan="5">Manager Rating (1-5)</th>
                    <th class="pa-c pa-col-rate" colspan="5">Agreed Rating (1-5)</th>
                    <th class="pa-del"></th>
                </tr>
                <tr>
                    <th></th><th></th><th></th>
                    ${[1,2,3,4,5].map(n=>`<th class="pa-c pa-col-n">${n}</th>`).join('')}
                    ${[1,2,3,4,5].map(n=>`<th class="pa-c pa-col-n">${n}</th>`).join('')}
                    ${[1,2,3,4,5].map(n=>`<th class="pa-c pa-col-n">${n}</th>`).join('')}
                    <th></th>
                </tr>
            </thead>
            <tbody id="kpiBody${kid}"></tbody>
        </table>
    </div>
    <button type="button" class="btn btn-soft btn-sm pa-add-kpi" onclick="addKpi(${kid})">
        <i class="fas fa-plus"></i> Add KPI
    </button>

    <!-- Comments -->
    <div class="pa-comments">
        <div class="form-group">
            <label>Employee Comments <span class="pa-label-note">(specific examples)</span></label>
            <textarea name="kra[${kid}][emp_comments]" class="form-control" rows="3"
                      placeholder="Describe specific examples of your performance..."></textarea>
        </div>
        <div class="form-group">
            <label>Manager Comments <span class="pa-label-note">(specific examples)</span></label>
            <textarea name="kra[${kid}][mgr_comments]" class="form-control" rows="3"
                      placeholder="Supervisor will complete this section.."></textarea>
        </div>
    </div>
    `;

    wrap.appendChild(div);
    addKpi(kid); // start with one KPI
    renumberKras();
}

/* -----------------------------------------------
   BUILD KPI ROW
----------------------------------------------- */
let kpiCounters = {};

function addKpi(kid) {
    if (!kpiCounters[kid]) kpiCounters[kid] = 0;
    kpiCounters[kid]++;
    const pid = kpiCounters[kid];
    const tbody = document.getElementById('kpiBody' + kid);

    const tr = document.createElement('tr');
    tr.dataset.pid = pid;

    // Rating radio groups
    function ratingGroup(prefix) {
        return [1,2,3,4,5].map(n => `
            <td class="td-rating">
                <input type="radio" name="${prefix}[${n}]" value="${n}"
                       style="cursor:pointer;width:auto;"
                       onchange="calcKraRating(${kid})">
            </td>`).join('');
    }
    // Actually use radio per row: name = kra[kid][kpi][pid][emp_rating], value = chosen number
    // Simpler: single select per rating column
    function ratingSelect(fname, disabled) {
        return `
            <td class="pa-c" colspan="5">
                <select name="${fname}" class="form-control rating-select"
                        ${disabled ? 'disabled' : ''}
                        onchange="calcKraRating(${kid})">
                    <option value="">Select Rate</option>
                    ${[1,2,3,4,5].map(n=>`<option value="${n}">${n}</option>`).join('')}
                </select>
            </td>`;
    }

    tr.innerHTML = `
        <td class="pa-seq kpi-seq"></td>
        <td><input type="text" name="kra[${kid}][kpi][${pid}][title]" class="form-control"
                   placeholder="e.g., KPI 1: Ensure Network Works Seamlessly" required></td>
        <td><input type="number" name="kra[${kid}][kpi][${pid}][weight]" class="form-control"
                   min="0" max="100" step="0.1" placeholder="10"
                   oninput="calcKraRating(${kid})"></td>
        ${ratingSelect(`kra[${kid}][kpi][${pid}][emp_rating]`, false)}
        ${ratingSelect(`kra[${kid}][kpi][${pid}][mgr_rating]`, true)}
        ${ratingSelect(`kra[${kid}][kpi][${pid}][agreed_rating]`, false)}
        <td class="pa-del">
            <button type="button" class="btn btn-secondary btn-sm" onclick="removeKpi(this, ${kid})" aria-label="Remove KPI">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    tbody.appendChild(tr);
    renumberKpis(kid);
}

function removeKpi(btn, kid) {
    const rows = document.querySelectorAll(`#kpiBody${kid} tr`);
    if (rows.length <= 1) { alert('At least one KPI is required per KRA.'); return; }
    btn.closest('tr').remove();
    renumberKpis(kid);
    calcKraRating(kid);
}

function removeKra(kid) {
    const blocks = document.querySelectorAll('.kra-block');
    if (blocks.length <= 1) { alert('At least one KRA is required.'); return; }
    document.querySelector(`.kra-block[data-kra="${kid}"]`).remove();
    renumberKras();
    updateTotalWeight();
}

function renumberKpis(kid) {
    document.querySelectorAll(`#kpiBody${kid} .kpi-seq`).forEach((td, i) => {
        td.textContent = i + 1;
    });
}

function renumberKras() {
    document.querySelectorAll('.kra-block').forEach((div, i) => {
        div.querySelector('.kra-num').textContent = 'KRA ' + (i + 1);
    });
}

/* -----------------------------------------------
   WEIGHT & RATING CALCULATIONS
----------------------------------------------- */
function updateTotalWeight() {
    let total = 0;
    document.querySelectorAll('input[name*="[weight]"]').forEach(el => {
        if (el.name.match(/kra\[\d+\]\[weight\]/)) {
            total += parseFloat(el.value) || 0;
        }
    });
}

function calcKraRating(kid) {
    const rows = document.querySelectorAll(`#kpiBody${kid} tr`);
    let weightedSum = 0, totalW = 0;
    rows.forEach(tr => {
        const wInp = tr.querySelector('input[name*="[weight]"]');
        const rSel = tr.querySelector('select[name*="[emp_rating]"]');
        const w = parseFloat(wInp ? wInp.value : 0) || 0;
        const r = parseFloat(rSel ? rSel.value : 0) || 0;
        weightedSum += w * r;
        totalW += w;
    });
    const rating = totalW > 0 ? (weightedSum / totalW).toFixed(2) : '';
    const el = document.getElementById('kraRating' + kid);
    if (el) el.value = rating ? rating : '';
}

/* -----------------------------------------------
   PREVIEW GENERATION
----------------------------------------------- */
function generatePreview() {
    const pd_from = document.querySelector('input[name="period_from"]').value;
    const pd_to   = document.querySelector('input[name="period_to"]').value;
    const disc    = document.querySelector('input[name="discussion_date"]').value;

    function fmtDate(d) {
        if (!d) return '-';
        const dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', year: 'numeric', day: 'numeric' });
    }

    let html = `
    <table class="ap-table" width="100%">
      <!-- -- Header info -- -->
      <tr class="ap-header-info">
        <td colspan="4" width="55%">
          <table width="100%">
            <tr>
              <td style="width:160px"><b>Name (Last, First, MI):</b></td>
              <td style="border-bottom:1px solid #999;min-width:180px">${esc(userInfo.full_name)}</td>
            </tr>
            <tr>
              <td><b>Department:</b></td>
              <td style="border-bottom:1px solid #999">${esc(userInfo.department)}</td>
            </tr>
            <tr>
              <td><b>Job Title:</b></td>
              <td style="border-bottom:1px solid #999">${esc(userInfo.job_title)}</td>
            </tr>
            <tr>
              <td><b>Supervisor:</b></td>
              <td style="border-bottom:1px solid #999">${esc(userInfo.supervisor)}</td>
            </tr>
          </table>
        </td>
        <td colspan="2" width="45%">
          <table width="100%">
            <tr>
              <td style="width:130px"><b>Appraisal Period:</b></td>
              <td>From: <b>${fmtDate(pd_from)}</b> &nbsp; To: <b>${fmtDate(pd_to)}</b></td>
            </tr>
            <tr>
              <td><b>Discussion Date:</b></td>
              <td style="border-bottom:1px solid #999">${fmtDate(disc)} </td>
            </tr>
          </table>
        </td>
      </tr>
      <tr><td colspan="6" style="padding:6px 0;border:none"></td></tr>
    </table>

    <!-- -- Section heading -- -->
    <table class="ap-table" width="100%" style="margin-top:10px;">
      <tr>
        <td colspan="20"
            style="background:#1e3a5f;color:#fff;font-size:14px;font-weight:700;padding:8px 10px;text-align:left;">
          Assessment against Key Result Areas
        </td>
      </tr>
    `;

    // Each KRA
    document.querySelectorAll('.kra-block').forEach((kraDiv, ki) => {
        const kraTitle  = kraDiv.querySelector('input[name*="[title]"]').value || `KRA ${ki+1}`;
        const kraWeight = kraDiv.querySelector('input[name*="[weight]"]').value || '';
        const kraRating = kraDiv.querySelector('input[name*="[kra_rating]"]').value || '';
        const kraAgreed = kraDiv.querySelector('select[name*="kra_rating\\]"]') ? '' :
                          (kraDiv.querySelector('select[name*="[agreed_rating]"]').value || '');
        const empCmt    = kraDiv.querySelector('textarea[name*="[emp_comments]"]').value || '';
        const mgrCmt    = kraDiv.querySelector('textarea[name*="[mgr_comments]"]').value || '';

        // Column spans: Activity | Weight | KRA Rating | 5 emp | 5 mgr | 5 agreed
        const ratingCols = 5;

        html += `
      <!-- KRA header row -->
      <tr>
        <td class="ap-kra-title" colspan="3">${esc(kraTitle)}</td>
        <td class="ap-section-heading" style="width:55px">Weight</td>
        <td class="ap-section-heading" style="width:65px">KRA Rating</td>
        <td class="ap-section-heading" colspan="${ratingCols}">Employee Rating</td>
        <td class="ap-section-heading" colspan="${ratingCols}">Manager Rating</td>
        <td class="ap-section-heading" colspan="${ratingCols}">Agreed Rating</td>
      </tr>
      <!-- Sub-heading with 1-5 columns -->
      <tr>
        <td colspan="3" style="background:#f2f2f2;font-weight:600;font-size:10px;">KPI Indicator</td>
        <td class="ap-rating-cell" style="background:#f2f2f2"></td>
        <td class="ap-rating-cell" style="background:#f2f2f2"></td>
        ${[1,2,3,4,5].map(n=>`<td class="ap-rating-cell" style="background:#dbe5f1;font-weight:700">${n}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="ap-rating-cell" style="background:#d9ead3;font-weight:700">${n}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="ap-rating-cell" style="background:#fce5cd;font-weight:700">${n}</td>`).join('')}
      </tr>
        `;

        // KPI rows
        kraDiv.querySelectorAll(`#kpiBody${kraDiv.dataset.kra} tr`).forEach((tr, pi) => {
            const kpiTitle  = tr.querySelector('input[name*="[title]"]').value || `KPI ${pi+1}`;
            const kpiWeight = tr.querySelector('input[name*="[weight]"]').value || '';
            const empRating = tr.querySelector('select[name*="[emp_rating]"]').value || '';
            const mgrRating = tr.querySelector('select[name*="[mgr_rating]"]').value || '';
            const agrRating = tr.querySelector('select[name*="kpi\\]"]') ? '' :
                              (tr.querySelector('select[name*="[agreed_rating]"]') ?
                               tr.querySelector('select[name*="[agreed_rating]"]').value : '');

            function ratingCells(chosen, bg) {
                return [1,2,3,4,5].map(n => `
                    <td class="ap-rating-cell" style="background:${bg}">
                        ${parseInt(chosen) === n ? '\u2713' : ''}
                    </td>`).join('');
            }

            html += `
      <tr>
        <td colspan="3" style="padding-left:10px">${esc(kpiTitle)}</td>
        <td class="ap-rating-cell">${esc(kpiWeight)}${kpiWeight ? '%' : ''}</td>
        <td class="ap-rating-cell"></td>
        ${ratingCells(empRating, '#eef3fa')}
        ${ratingCells(mgrRating, '#eaf4e6')}
        ${ratingCells(agrRating, '#fef5ec')}
      </tr>
            `;
        });

        // KRA totals row
        html += `
      <tr>
        <td colspan="3" style="font-weight:700;background:#f2f2f2;text-align:right;padding-right:8px;">KRA Totals</td>
        <td class="ap-rating-cell" style="font-weight:700;background:#f2f2f2">${esc(kraWeight)}${kraWeight?'%':''}</td>
        <td class="ap-rating-cell" style="font-weight:700;background:#f2f2f2">${esc(kraRating)}</td>
        <td colspan="${ratingCols}" style="background:#f2f2f2"></td>
        <td colspan="${ratingCols}" style="background:#f2f2f2"></td>
        <td colspan="${ratingCols}" style="text-align:center;font-weight:700;background:#fef5ec">${esc(kraAgreed)}</td>
      </tr>
        `;

        // Comments rows
        html += `
      <tr>
        <td style="font-weight:700;font-size:10px;white-space:nowrap;width:120px">Employee<br>Comments<br><span style="font-weight:400;color:#555">(specific examples)</span></td>
        <td colspan="${3 + 1 + 1 + ratingCols + ratingCols + ratingCols - 1}" style="padding:6px 8px;line-height:1.5">
            ${esc(empCmt).replace(/\n/g,'<br>') || '<span style="color:#aaa;font-style:italic">-</span>'}
        </td>
      </tr>
      <tr>
        <td style="font-weight:700;font-size:10px;white-space:nowrap">Manager<br>Comments<br><span style="font-weight:400;color:#555">(specific examples)</span></td>
        <td colspan="${3 + 1 + 1 + ratingCols + ratingCols + ratingCols - 1}" style="padding:6px 8px;line-height:1.5">
            ${esc(mgrCmt).replace(/\n/g,'<br>') || '<span style="color:#aaa;font-style:italic">-</span>'}
        </td>
      </tr>
        `;
    });

    html += `</table>`;

    // Signature block
    html += `
    <table class="ap-table" width="100%" style="margin-top:16px;">
      <tr>
        <td colspan="3" style="font-weight:700;background:#1e3a5f;color:#fff;padding:6px 10px">Signatures</td>
      </tr>
      <tr>
        <td style="width:33%;padding:20px 10px 6px">
            <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Employee Signature &amp; Date</div>
        </td>
        <td style="width:33%;padding:20px 10px 6px">
            <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Supervisor Signature &amp; Date</div>
        </td>
        <td style="width:33%;padding:20px 10px 6px">
            <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">HR / Management Signature &amp; Date</div>
        </td>
      </tr>
    </table>
    `;

    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('printArea').innerHTML = html;
    document.getElementById('previewModal').classList.add('open');
}

function closeModal() {
    document.getElementById('previewModal').classList.remove('open');
}

function doPrint() {
    window.print();
}

function esc(str) {
    return String(str || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

/* -----------------------------------------------
   INIT
----------------------------------------------- */
document.getElementById('btnAddKra').addEventListener('click', addKra);

// Close modal on overlay click
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Form submit guard
document.getElementById('appraisalForm').addEventListener('submit', function(e) {
    const kras = document.querySelectorAll('.kra-block');
    if (kras.length === 0) {
        e.preventDefault();
        alert('Please add at least one KRA before submitting.');
        return false;
    }
});

// Start with one KRA
addKra();
</script>