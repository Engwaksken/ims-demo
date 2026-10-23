<?php

$page_title = 'Behavioral Competency Assessment';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login"); exit();
}

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
    WHERE u.user_id = $uid LIMIT 1
");
$profile = $profile_q ? $profile_q->fetch_assoc() : [];

if (!$profile || !$profile['job_title']) {
    send_notification($uid,'Please complete your employee profile first.','warning');
    header("Location: my-profile"); exit();
}

// -- Fetch active competency groups + indicators --
$groups = [];
$gr = $conn->query("SELECT * FROM bc_groups WHERE is_active=1 ORDER BY sort_order, group_id");
while ($g = $gr->fetch_assoc()) {
    $g['indicators'] = [];
    $ir = $conn->prepare("SELECT * FROM bc_indicators WHERE group_id=? AND is_active=1 ORDER BY sort_order, indicator_id");
    $ir->bind_param('i', $g['group_id']);
    $ir->execute();
    $res = $ir->get_result();
    while ($ind = $res->fetch_assoc()) {
        $g['indicators'][] = $ind;
    }
    $ir->close();
    if (!empty($g['indicators'])) $groups[] = $g;
}

if (empty($groups)) {
    echo '<div class="page-stack"><div class="card"><div class="empty-state">
        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
        <p>No behavioral competency groups have been configured yet. Please contact your administrator.</p>
    </div></div></div>';
    include 'includes/footer.php';
    exit();
}

$current_year = date('Y');
?>

<style>
/* Behavioral competency form (bc-) */
.bc-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.bc-period { display: flex; gap: 8px; align-items: center; }
.bc-period .form-control { flex: 1; min-width: 0; }
.bc-period-sep { color: var(--ink-300); font-size: 12px; }

/* Competency matrix */
.bc-section { border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; }
.bc-section + .bc-section { margin-top: 20px; }
.bc-section .table-responsive { margin-top: 0; }

.bc-table { font-size: 13px; }
.bc-table thead th {
    padding: 9px 10px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px; color: var(--ink-300);
    border-bottom: 2px solid var(--border); background: var(--surface-hover);
    white-space: nowrap; text-align: center;
}
.bc-table thead th.th-indicator { text-align: left; min-width: 220px; }
.bc-table thead th.th-group {
    background: var(--ink-700); color: var(--ink-0); font-size: 13px;
    font-weight: 700; text-align: left; padding: 11px 14px;
    text-transform: none; letter-spacing: 0; white-space: normal;
}
.bc-group-desc { font-size: 11px; font-weight: 400; opacity: .7; margin-left: 10px; }
.bc-table thead th.th-rating-block { background: var(--purple-bg); color: var(--purple-fg); padding: 6px 8px; }
.bc-table thead th.th-mgr-block { background: var(--green-bg); color: var(--green-fg); }
.bc-table thead th.th-agr-block { background: var(--amber-bg); color: var(--amber-fg); }

.bc-table td { padding: 9px 10px; border-bottom: 1px solid var(--ink-50); vertical-align: middle; }
.bc-table tbody tr:last-child td { border-bottom: none; }
.bc-table td.td-indicator { padding-left: 16px; line-height: 1.5; }
.bc-table td.td-indicator::before { content: "\203A"; color: var(--ink-200); margin-right: 8px; font-size: 16px; }
.bc-table td.td-rating { text-align: center; width: 52px; }
.bc-table td.bc-cell-emp { background: var(--purple-bg); }
.bc-table td.bc-cell-mgr { background: var(--green-bg); }
.bc-table td.bc-cell-agr { background: var(--amber-bg); }

.bc-radio { cursor: pointer; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; margin: 0; }
.bc-radio.is-locked { cursor: not-allowed; opacity: .4; }
.bc-radio input { width: 16px; height: 16px; cursor: inherit; }
.bc-cell-emp input { accent-color: var(--purple-fg); }
.bc-cell-mgr input { accent-color: var(--green-fg); }
.bc-cell-agr input { accent-color: var(--amber-fg); }

/* Progress bar per group */
.group-progress {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 14px; background: var(--surface-hover);
    border-top: 1px solid var(--border);
    font-size: 12px; color: var(--ink-300);
}
.group-progress > i { color: var(--purple-fg); font-size: 11px; }
.gp-bar { flex: 1; height: 6px; background: var(--ink-50); border-radius: var(--radius-pill); overflow: hidden; }
.gp-fill { height: 100%; background: var(--purple-fg); border-radius: var(--radius-pill); transition: width .4s; width: 0; }
.gp-label { font-variant-numeric: tabular-nums; font-size: 11px; min-width: 60px; text-align: right; }

/* Overall comments */
.bc-comments { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; }
.bc-comments .form-group { margin-bottom: 0; }
.bc-icon-emp { color: var(--purple-fg); }
.bc-icon-mgr { color: var(--green-fg); }
.bc-comments textarea[readonly] { background: var(--green-bg); border-color: var(--green-border); color: var(--ink-300); }

.bc-actions { display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap; margin-top: 20px; }

/* Preview modal (toggled with .open by the script below) */
#previewModal.open { display: block; }
.bc-preview-card { max-width: 960px; }
.bc-preview-scroll { overflow-x: auto; }

/* Print table styles: print/preview template, keeps its own palette */
.pt { width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 11px; }
.pt td, .pt th { border: 1px solid #999; padding: 4px 7px; vertical-align: top; }
.pt .pt-group { background: #1e1b4b; color: #fff; font-weight: 700; font-size: 12px; }
.pt .pt-subhead { background: #ebebfc; font-weight: 700; font-size: 10px; text-align: center; }
.pt .pt-ind { padding-left: 12px; }
.pt .pt-rating { text-align: center; width: 36px; font-weight: 700; }
.pt .pt-comment-label { font-weight: 700; background: #f2f2f2; }

@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { display: block !important; position: fixed; top: 0; left: 0; width: 100%; }
}
#printArea { display: none; }

@media (max-width: 768px) {
    .bc-grid-3, .bc-comments { grid-template-columns: 1fr; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-brain" aria-hidden="true"></i> Behavioral Competency Assessment</h1>
        <p>Rate yourself on each behavioral indicator. Your supervisor will complete the Manager Rating.</p>
    </div>
    <div class="hero-actions">
        <a href="my-appraisals" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
        <button type="button" class="btn btn-primary" onclick="generatePreview()">
            <i class="fas fa-eye" aria-hidden="true"></i> Preview &amp; Print
        </button>
    </div>
</div>

<form method="POST" action="bc-process" id="bcForm" class="page-stack">
<input type="hidden" name="action" value="create_bc">

<!-- Personal Info -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-user" aria-hidden="true"></i> Personal Information</h3>
    </div>
    <div class="card-body">
        <div class="form-grid bc-grid-3">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($profile['full_name']); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Department</label>
                <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($profile['department_name'] ?? ''); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Job Title</label>
                <input type="text" name="job_title" class="form-control" value="<?php echo htmlspecialchars($profile['job_title'] ?? ''); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Supervisor</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($profile['supervisor_name'] ?? 'Not assigned'); ?>" readonly>
                <input type="hidden" name="supervisor_id" value="<?php echo $profile['supervisor_id'] ?? ''; ?>">
            </div>
            <div class="form-group">
                <label>Appraisal Period <span class="required-mark"></span></label>
                <div class="bc-period">
                    <input type="date" name="period_from" class="form-control" value="<?php echo $current_year; ?>-01-01" required>
                    <span class="bc-period-sep">to</span>
                    <input type="date" name="period_to" class="form-control" value="<?php echo $current_year; ?>-12-31" required>
                </div>
            </div>
            <div class="form-group">
                <label>Discussion Date</label>
                <input type="date" name="discussion_date" class="form-control">
            </div>
        </div>
    </div>
</div>

<!-- Score summary (live) -->
<div class="stats-grid reports-stats" id="scoreSummary">
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-user" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">My Avg Rating</span>
            <span class="stat-value" id="scoreEmp">-</span>
            <span class="stat-sub">Out of 5</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-user-tie" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Manager Avg</span>
            <span class="stat-value" id="scoreMgr">-</span>
            <span class="stat-sub">Out of 5</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-handshake" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Agreed Avg</span>
            <span class="stat-value" id="scoreAgr">-</span>
            <span class="stat-sub">Out of 5</span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list-check" aria-hidden="true"></i> Behavioral Competencies</h3>
    </div>
    <div class="card-body">

    <!-- Competency sections -->
    <?php foreach ($groups as $gi => $g): ?>
    <div class="bc-section" id="section-<?php echo $g['group_id']; ?>">
        <div class="table-responsive">
        <table class="table bc-table">
            <thead>
                <tr>
                    <th class="th-group" colspan="16">
                        <?php echo htmlspecialchars($g['group_name']); ?>
                        <?php if ($g['description']): ?>
                            <span class="bc-group-desc">
                               - <?php echo htmlspecialchars($g['description']); ?>
                            </span>
                        <?php endif; ?>
                    </th>
                </tr>
                <tr>
                    <th class="th-indicator">Behavioral Indicator</th>
                    <th class="th-rating-block" colspan="5">Employee Rating (1-5)</th>
                    <th class="th-rating-block th-mgr-block" colspan="5">Manager Rating (1-5)</th>
                    <th class="th-rating-block th-agr-block" colspan="5">Agreed Rating (1-5)</th>
                </tr>
                <tr>
                    <th class="th-indicator"></th>
                    <?php for ($r=1;$r<=5;$r++): ?><th class="th-rating-block"><?php echo $r; ?></th><?php endfor; ?>
                    <?php for ($r=1;$r<=5;$r++): ?><th class="th-rating-block th-mgr-block"><?php echo $r; ?></th><?php endfor; ?>
                    <?php for ($r=1;$r<=5;$r++): ?><th class="th-rating-block th-agr-block"><?php echo $r; ?></th><?php endfor; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($g['indicators'] as $ind): ?>
            <tr>
                <td class="td-indicator">
                    <?php echo htmlspecialchars($ind['indicator_text']); ?>
                </td>
                <!-- Employee rating (radio buttons shown as cells) -->
                <?php for ($r=1;$r<=5;$r++): ?>
                <td class="td-rating bc-cell-emp">
                    <label class="bc-radio">
                        <input type="radio"
                               name="bc[<?php echo $ind['indicator_id']; ?>][emp_rating]"
                               value="<?php echo $r; ?>"
                               onchange="updateScores()">
                    </label>
                </td>
                <?php endfor; ?>
                <!-- Manager rating (disabled for employee, supervisor fills in) -->
                <?php for ($r=1;$r<=5;$r++): ?>
                <td class="td-rating bc-cell-mgr">
                    <label class="bc-radio is-locked">
                        <input type="radio"
                               name="bc[<?php echo $ind['indicator_id']; ?>][mgr_rating]"
                               value="<?php echo $r; ?>"
                               disabled>
                    </label>
                </td>
                <?php endfor; ?>
                <!-- Agreed rating -->
                <?php for ($r=1;$r<=5;$r++): ?>
                <td class="td-rating bc-cell-agr">
                    <label class="bc-radio">
                        <input type="radio"
                               name="bc[<?php echo $ind['indicator_id']; ?>][agr_rating]"
                               value="<?php echo $r; ?>"
                               onchange="updateScores()">
                    </label>
                </td>
                <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Group progress -->
        <div class="group-progress">
            <i class="fas fa-user" aria-hidden="true"></i>
            <span>My completion:</span>
            <div class="gp-bar"><div class="gp-fill" id="gpFill-<?php echo $g['group_id']; ?>"></div></div>
            <span class="gp-label" id="gpLabel-<?php echo $g['group_id']; ?>">0 / <?php echo count($g['indicators']); ?></span>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Overall Comments -->
    <div class="bc-comments">
        <div class="form-group">
            <label><i class="fas fa-user bc-icon-emp" aria-hidden="true"></i> Employee Overall Comments</label>
            <textarea name="emp_overall_comment" rows="4" class="form-control"
                      placeholder="Provide an overall self-assessment and specific examples&hellip;"></textarea>
        </div>
        <div class="form-group">
            <label><i class="fas fa-user-tie bc-icon-mgr" aria-hidden="true"></i> Manager Overall Comments</label>
            <textarea name="mgr_overall_comment" rows="4" class="form-control"
                      placeholder="Supervisor completes this section during review&hellip;"
                      readonly></textarea>
        </div>
    </div>

    <!-- Actions -->
    <div class="bc-actions">
        <a href="my-appraisals" class="btn btn-secondary"><i class="fas fa-times" aria-hidden="true"></i> Cancel</a>
        <button type="submit" name="save_draft" class="btn btn-soft">
            <i class="fas fa-save" aria-hidden="true"></i> Save Draft
        </button>
        <button type="submit" name="submit_bc" class="btn btn-primary">
            <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit for Review
        </button>
    </div>
    </div>
</div>

</form>
</div><!-- /.page-stack -->

<!-- Print Preview Modal -->
<div class="modal" id="previewModal">
    <div class="modal-card bc-preview-card">
        <div class="modal-head">
            <h3><i class="fas fa-print" aria-hidden="true"></i> Behavioral Competency Preview</h3>
            <button type="button" class="modal-close" onclick="closePreview()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="bc-preview-scroll" id="modalBody"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closePreview()">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print" aria-hidden="true"></i> Print / Save PDF
                </button>
            </div>
        </div>
    </div>
</div>
<div id="printArea"></div>

<?php include 'includes/footer.php'; ?>

<script>
// -- Competency data from PHP --
const groups = <?php
    $jsGroups = array_map(function($g) {
        return [
            'group_id'   => $g['group_id'],
            'group_name' => $g['group_name'],
            'indicators' => array_map(function($i) {
                return ['indicator_id' => $i['indicator_id'], 'indicator_text' => $i['indicator_text']];
            }, $g['indicators'])
        ];
    }, $groups);
    echo json_encode($jsGroups);
?>;

const userInfo = {
    full_name:   <?php echo json_encode($profile['full_name'] ?? ''); ?>,
    department:  <?php echo json_encode($profile['department_name'] ?? ''); ?>,
    job_title:   <?php echo json_encode($profile['job_title'] ?? ''); ?>,
    supervisor:  <?php echo json_encode($profile['supervisor_name'] ?? ''); ?>
};

// -- Live score + progress --
function updateScores() {
    let empTotal = 0, empCount = 0;
    let agrTotal = 0, agrCount = 0;

    groups.forEach(g => {
        let gFilled = 0;
        g.indicators.forEach(ind => {
            const id = ind.indicator_id;
            const empEl = document.querySelector(`input[name="bc[${id}][emp_rating]"]:checked`);
            const agrEl = document.querySelector(`input[name="bc[${id}][agr_rating]"]:checked`);
            if (empEl) { empTotal += parseInt(empEl.value); empCount++; gFilled++; }
            if (agrEl) { agrTotal += parseInt(agrEl.value); agrCount++; }
        });

        // Group progress bar
        const fill  = document.getElementById('gpFill-' + g.group_id);
        const label = document.getElementById('gpLabel-' + g.group_id);
        const pct   = g.indicators.length > 0 ? (gFilled / g.indicators.length * 100) : 0;
        if (fill)  fill.style.width  = pct + '%';
        if (label) label.textContent = gFilled + ' / ' + g.indicators.length;
    });

    const eAvg = empCount > 0 ? (empTotal / empCount).toFixed(2) : '-';
    const aAvg = agrCount > 0 ? (agrTotal / agrCount).toFixed(2) : '-';
    document.getElementById('scoreEmp').textContent = eAvg;
    document.getElementById('scoreAgr').textContent = aAvg;
}

// -- Preview / Print --
function generatePreview() {
    const pdFrom = document.querySelector('input[name="period_from"]').value;
    const pdTo   = document.querySelector('input[name="period_to"]').value;
    const disc   = document.querySelector('input[name="discussion_date"]').value;
    const empCmt = document.querySelector('textarea[name="emp_overall_comment"]').value;

    function fmtDate(d) {
        if (!d) return '-';
        return new Date(d + 'T00:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
    }

    let html = `
    <table class="pt" width="100%">
      <tr>
        <td style="border:none;padding:4px 6px;width:50%">
          <b>Name:</b> ${esc(userInfo.full_name)}<br>
          <b>Department:</b> ${esc(userInfo.department)}<br>
          <b>Job Title:</b> ${esc(userInfo.job_title)}
        </td>
        <td style="border:none;padding:4px 6px;width:50%">
          <b>Supervisor:</b> ${esc(userInfo.supervisor)}<br>
          <b>Period:</b> ${fmtDate(pdFrom)} - ${fmtDate(pdTo)}<br>
          <b>Discussion Date:</b> ${fmtDate(disc)}
        </td>
      </tr>
    </table>
    <br>
    <table class="pt" width="100%">
      <tr>
        <td colspan="16" style="background:#1e1b4b;color:#fff;font-size:14px;font-weight:700;padding:8px 10px;">
          Behavioral Competencies
        </td>
      </tr>
      <!-- Column headers -->
      <tr>
        <th class="pt-subhead" style="text-align:left;width:30%">Indicator</th>
        <th class="pt-subhead" colspan="5" style="background:#ebebfc;color:#4338ca">Employee Rating</th>
        <th class="pt-subhead" colspan="5" style="background:#dcfce7;color:#166534">Manager Rating</th>
        <th class="pt-subhead" colspan="5" style="background:#fff7ed;color:#d97706">Agreed Rating</th>
      </tr>
      <tr>
        <th style="background:#f8fafc;border:1px solid #ccc"></th>
        ${[1,2,3,4,5].map(n=>`<th class="pt-rating" style="background:#ebebfc">${n}</th>`).join('')}
        ${[1,2,3,4,5].map(n=>`<th class="pt-rating" style="background:#dcfce7">${n}</th>`).join('')}
        ${[1,2,3,4,5].map(n=>`<th class="pt-rating" style="background:#fff7ed">${n}</th>`).join('')}
      </tr>
    `;

    groups.forEach(g => {
        html += `
      <tr>
        <td class="pt-group" colspan="16">${esc(g.group_name)}</td>
      </tr>`;

        g.indicators.forEach(ind => {
            const id  = ind.indicator_id;
            const emp = document.querySelector(`input[name="bc[${id}][emp_rating]"]:checked`);
            const mgr = document.querySelector(`input[name="bc[${id}][mgr_rating]"]:checked`);
            const agr = document.querySelector(`input[name="bc[${id}][agr_rating]"]:checked`);
            const ev  = emp ? parseInt(emp.value) : 0;
            const mv  = mgr ? parseInt(mgr.value) : 0;
            const av  = agr ? parseInt(agr.value) : 0;

            html += `<tr>
        <td class="pt-ind">${esc(ind.indicator_text)}</td>
        ${[1,2,3,4,5].map(n=>`<td class="pt-rating" style="background:#f5f5ff">${ev===n?'?':''}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="pt-rating" style="background:#f0fdf4">${mv===n?'?':''}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="pt-rating" style="background:#fffbeb">${av===n?'?':''}</td>`).join('')}
      </tr>`;
        });
    });

    html += `
      <tr>
        <td class="pt-comment-label">Manager Overall Comments</td>
        <td colspan="15" style="padding:6px 8px;font-style:italic;color:#94a3b8">- Supervisor completes after review -</td>
      </tr>
      <tr>
        <td class="pt-comment-label">Employee Overall Comments</td>
        <td colspan="15" style="padding:6px 8px;line-height:1.6">${esc(empCmt).replace(/\n/g,'<br>') || '-'}</td>
      </tr>
    </table>
    <table class="pt" width="100%" style="margin-top:14px">
      <tr>
        <td style="padding:22px 10px 6px;border:1px solid #ccc;width:33%">
          <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Employee Signature &amp; Date</div>
        </td>
        <td style="padding:22px 10px 6px;border:1px solid #ccc;width:33%">
          <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Supervisor Signature &amp; Date</div>
        </td>
        <td style="padding:22px 10px 6px;border:1px solid #ccc;width:34%">
          <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">HR Signature &amp; Date</div>
        </td>
      </tr>
    </table>`;

    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('printArea').innerHTML = html;
    document.getElementById('previewModal').classList.add('open');
}

function closePreview() {
    document.getElementById('previewModal').classList.remove('open');
}
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Form guard
document.getElementById('bcForm').addEventListener('submit', function(e) {
    const total = document.querySelectorAll('input[name*="[emp_rating]"]').length;
    const filled = document.querySelectorAll('input[name*="[emp_rating]"]:checked').length;
    if (filled === 0) {
        e.preventDefault();
        alert('Please rate at least one behavioral indicator before saving.');
        return false;
    }
    if (filled < total && e.submitter?.name === 'submit_bc') {
        if (!confirm(`You have rated ${filled} of ${total} indicators. Submit anyway?`)) {
            e.preventDefault(); return false;
        }
    }
});

// Init
updateScores();
</script>