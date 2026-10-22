<?php
$page_title = 'My Appraisals';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$uid = (int)$_SESSION['user_id'];

// -- Flash messages --
$success = $_SESSION['appraisal_success'] ?? $_SESSION['activity_success'] ?? $_SESSION['bc_form_success'] ?? '';
$error   = $_SESSION['appraisal_error']   ?? $_SESSION['activity_error']   ?? $_SESSION['bc_form_error']   ?? '';
unset(
    $_SESSION['appraisal_success'], $_SESSION['activity_success'], $_SESSION['bc_form_success'],
    $_SESSION['appraisal_error'],   $_SESSION['activity_error'],   $_SESSION['bc_form_error']
);


$active_tab = in_array($_GET['tab'] ?? '', ['appraisals','activities','bc']) ? $_GET['tab'] : 'appraisals';


$appraisals = [];
$ar = $conn->prepare("
    SELECT pa.*,
           COUNT(DISTINCT k.kra_id)  AS kra_count,
           COUNT(DISTINCT p.kpi_id)  AS kpi_count,
           ROUND(AVG(p.emp_rating),1) AS avg_emp
    FROM performance_appraisals pa
    LEFT JOIN appraisal_kras k ON k.appraisal_id = pa.appraisal_id
    LEFT JOIN appraisal_kpis p ON p.appraisal_id = pa.appraisal_id
    WHERE pa.user_id = ?
    GROUP BY pa.appraisal_id
    ORDER BY pa.created_at DESC
");
$ar->bind_param('i', $uid);
$ar->execute();
$ar_res = $ar->get_result();
while ($row = $ar_res->fetch_assoc()) $appraisals[] = $row;
$ar->close();


$activities = [];
$act_r = $conn->prepare("
    SELECT ap.*,
           COUNT(DISTINCT pa.activity_id)   AS activity_count,
           COUNT(DISTINCT awe.entry_id)     AS entry_count,
           kc.category_name
    FROM activity_plans ap
    LEFT JOIN plan_activities pa          ON pa.plan_id    = ap.plan_id
    LEFT JOIN activity_weekly_entries awe ON awe.plan_id   = ap.plan_id
    LEFT JOIN kpi_categories kc           ON kc.category_id = ap.category_id
    WHERE ap.user_id = ?
    GROUP BY ap.plan_id
    ORDER BY ap.created_at DESC
");
$act_r->bind_param('i', $uid);
$act_r->execute();
$act_res = $act_r->get_result();
while ($row = $act_res->fetch_assoc()) $activities[] = $row;
$act_r->close();


$bc_submissions = [];
$bc_r = $conn->prepare("
    SELECT s.*,
           COUNT(r.rating_id)          AS total_indicators,
           COUNT(r.emp_rating)         AS emp_rated,
           ROUND(AVG(r.emp_rating),1)  AS avg_emp,
           ROUND(AVG(r.mgr_rating),1)  AS avg_mgr,
           ROUND(AVG(r.agr_rating),1)  AS avg_agr
    FROM bc_submissions s
    LEFT JOIN bc_ratings r ON r.submission_id = s.submission_id
    WHERE s.user_id = ?
    GROUP BY s.submission_id
    ORDER BY s.created_at DESC
");
$bc_r->bind_param('i', $uid);
$bc_r->execute();
$bc_res = $bc_r->get_result();
while ($row = $bc_res->fetch_assoc()) $bc_submissions[] = $row;
$bc_r->close();

// -- Summary counts --
$total_appraisals  = count($appraisals);
$total_activities  = count($activities);
$total_bc          = count($bc_submissions);

$pending_appraisals = count(array_filter($appraisals,  fn($r) => in_array($r['status'], ['draft','submitted','pending_review'])));
$pending_activities = count(array_filter($activities,  fn($r) => in_array($r['status'], ['draft','submitted'])));
$pending_bc         = count(array_filter($bc_submissions, fn($r) => in_array($r['status'], ['draft','submitted'])));

// -- Status helpers --
function status_badge(string $s): string {
    $map = [
        'draft'        => ['#f1f5f9','#64748b','Draft'],
        'submitted'    => ['#dbeafe','#1d4ed8','Submitted'],
        'pending_review' => ['#dbeafe','#1d4ed8','Pending Review'],
        'under_review' => ['#fef3c7','#d97706','Under Review'],
        'reviewed'     => ['#e0e7ff','#4338ca','Reviewed'],
        'approved'     => ['#dcfce7','#ff9800','Approved'],
        'completed'    => ['#dcfce7','#ff9800','Completed'],
        'rejected'     => ['#fee2e2','#dc2626','Rejected'],
    ];
    [$bg, $color, $label] = $map[$s] ?? ['#f1f5f9','#64748b', ucfirst($s)];
    return "<span style='background:$bg;color:$color;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;'>$label</span>";
}

function fmt_date(?string $d): string {
    if (!$d) return '-';
    return date('M j, Y', strtotime($d));
}
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
:root {
    --ink:        #0d1117;
    --surface:    #f0f2f5;
    --panel:      #fff;
    --accent:     #ff9800;
    --accent-lt:  #ededfd;
    --success:    #ff9800;
    --success-lt: #dcfce7;
    --warning:    #d97706;
    --warning-lt: #fef3c7;
    --danger:     #dc2626;
    --danger-lt:  #fee2e2;
    --border:     #e2e8f0;
    --muted:      #94a3b8;
    --sans:       'Plus Jakarta Sans', sans-serif;
    --mono:       'JetBrains Mono', monospace;
    --radius:     10px;
    --shadow:     0 1px 3px rgba(0,0,0,.06), 0 4px 12px rgba(0,0,0,.05);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--sans); background: var(--surface); color: var(--ink); font-size: 14px; }

/* -- Hero -- */
.hero {
    background: linear-gradient(135deg, #ff5722 0%,  #ff9800 100%);
    color: #fff; padding: 28px 32px; border-radius: var(--radius);
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; gap: 16px; position: relative; overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.hero-left { position: relative; }
.hero h2 { font-size: 22px; font-weight: 800; letter-spacing: -.4px; }
.hero p  { font-size: 13px; color: #ffffff; margin-top: 4px; }
.hero-actions { display: flex; gap: 10px; flex-wrap: wrap; position: relative; }

/* -- Buttons -- */
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 20px; border-radius: 8px;
    font-family: var(--sans); font-size: 13px; font-weight: 600;
    cursor: pointer; border: 2px solid transparent;
    transition: all .15s; text-decoration: none; white-space: nowrap;
}
.btn-primary  { background: var(--accent); color: #fff; }
.btn-primary:hover  { background: #ff5722; }
.btn-success  { background: var(--success); color: #fff; }
.btn-success:hover  { background: #15803d; }
.btn-ghost    { background: rgba(255,255,255,.12); color: #fff; border-color: rgba(255,255,255,.2); }
.btn-ghost:hover    { background: rgba(255,255,255,.22); }
.btn-outline  { background: #fff; color: var(--ink); border-color: var(--border); }
.btn-outline:hover  { border-color: #94a3b8; }
.btn-danger   { background: #fff; color: var(--danger); border-color: var(--border); }
.btn-danger:hover   { background: var(--danger-lt); border-color: var(--danger); }
.btn-sm { padding: 5px 13px; font-size: 12px; }
.btn-xs { padding: 3px 9px;  font-size: 11px; }

/* -- Flash -- */
.flash {
    padding: 12px 16px; border-radius: 8px; font-size: 13px;
    margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
}
.flash-success { background: var(--success-lt); color: #166534; border-left: 4px solid var(--success); }
.flash-error   { background: var(--danger-lt);  color: #991b1b; border-left: 4px solid var(--danger); }

/* -- Tabs -- */
.tab-nav {
    display: flex; gap: 4px; margin-bottom: 0;
    border-bottom: 2px solid var(--border);
    padding-bottom: 0;
}
.tab-btn {
    padding: 10px 20px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: none; background: none;
    color: var(--muted); border-bottom: 2px solid transparent;
    margin-bottom: -2px; transition: all .15s;
    display: flex; align-items: center; gap: 8px; font-family: var(--sans);
}
.tab-btn:hover { color: var(--ink); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
.tab-badge {
    background: var(--accent-lt); color: var(--accent);
    padding: 1px 7px; border-radius: 20px;
    font-size: 10px; font-weight: 700; font-family: var(--mono);
}
.tab-btn.active .tab-badge { background: var(--accent); color: #fff; }
.tab-content { display: none; padding-top: 20px; }
.tab-content.active { display: block; }

/* -- Section card -- */
.section-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 20px;
}
.section-head {
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    background: #fafbfc; display: flex; align-items: center;
    justify-content: space-between; gap: 12px; flex-wrap: wrap;
}
.section-head h4 {
    font-size: 14px; font-weight: 700;
    display: flex; align-items: center; gap: 8px; color: var(--ink);
}
.section-head h4 i { color: var(--accent); }

/* -- Table -- */
.ap-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ap-table thead th {
    padding: 10px 14px; text-align: left;
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .7px;
    color: var(--muted); border-bottom: 2px solid var(--border);
    background: #fafbfc; white-space: nowrap;
}
.ap-table thead th.th-c { text-align: center; }
.ap-table tbody tr { transition: background .1s; }
.ap-table tbody tr:hover { background: #fafbfc; }
.ap-table td {
    padding: 12px 14px; border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.ap-table tbody tr:last-child td { border-bottom: none; }
.ap-table td.td-c { text-align: center; }

.ap-title { font-weight: 600; color: var(--ink); margin-bottom: 2px; }
.ap-meta  { font-size: 11px; color: var(--muted); }
.ap-meta span { margin-right: 10px; }

/* -- Mini rating bar -- */
.rating-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    font-family: var(--mono); font-size: 12px; font-weight: 600;
}
.rating-pill.emp { background: var(--accent-lt); color: var(--accent); }
.rating-pill.mgr { background: var(--success-lt); color: var(--success); }
.rating-pill.agr { background: var(--warning-lt); color: var(--warning); }

/* -- Progress dots -- */
.prog-dots { display: flex; gap: 3px; align-items: center; }
.prog-dot  { width: 8px; height: 8px; border-radius: 50%; background: #e2e8f0; }
.prog-dot.filled { background: var(--accent); }

/* -- Empty state -- */
.empty-state {
    text-align: center; padding: 56px 24px; color: var(--muted);
}
.empty-state .empty-icon {
    width: 72px; height: 72px; border-radius: 18px;
    background: var(--accent-lt); color: var(--accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin: 0 auto 18px;
}
.empty-state h4 { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 8px; }
.empty-state p  { font-size: 13px; max-width: 340px; margin: 0 auto 20px; }

/* -- Action buttons in table -- */
.row-actions { display: flex; gap: 6px; justify-content: flex-end; }

/* -- Period range -- */
.period-range {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; color: var(--muted);
}
.period-range .sep { color: #cbd5e1; }

/* -- Week fill indicator (for activities) -- */
.week-fill {
    display: flex; align-items: center; gap: 6px; font-size: 12px;
}
.week-bar { width: 60px; height: 5px; background: #e2e8f0; border-radius: 99px; overflow: hidden; }
.week-bar-fill { height: 100%; background: var(--success); border-radius: 99px; }

/* -- Completion ring (SVG) -- */
.ring-wrap { position: relative; width: 44px; height: 44px; flex-shrink: 0; }
.ring-wrap svg { transform: rotate(-90deg); }
.ring-wrap .ring-val {
    position: absolute; inset: 0; display: flex; align-items: center;
    justify-content: center; font-size: 10px; font-weight: 700; font-family: var(--mono);
}

/* -- Delete confirm modal -- */
.modal-bd { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9999; align-items: center; justify-content: center; }
.modal-bd.open { display: flex; }
.modal-box { background: #fff; border-radius: var(--radius); max-width: 420px; width: 100%; padding: 28px; box-shadow: 0 20px 60px rgba(0,0,0,.25); text-align: center; }
.modal-box h3 { font-size: 17px; font-weight: 700; margin-bottom: 10px; }
.modal-box p  { font-size: 13px; color: var(--muted); margin-bottom: 24px; }
.modal-box .modal-actions { display: flex; gap: 10px; justify-content: center; }

@media(max-width: 900px) {
    .hero { flex-direction: column; }
    .hero-actions { width: 100%; }
    .ap-table { font-size: 12px; }
}
</style>

<!-- -- Hero -- -->
<div class="hero">
    <div class="hero-left">
        <h2><i class="fas fa-clipboard-list"></i> &nbsp;My Appraisals</h2>
        <p>Track your performance appraisals, activity plans, and behavioral assessments.</p>
    </div>
    <div class="hero-actions">
        <a href="performance-appraisal" class="btn btn-ghost">
            <i class="fas fa-plus"></i> New Appraisal
        </a>
        <a href="create-activity" class="btn btn-ghost">
            <i class="fas fa-tasks"></i> New Activity Plan
        </a>
        <a href="employee-competency" class="btn btn-ghost">
            <i class="fas fa-brain"></i> New BC Assessment
        </a>
    </div>
</div>

<!-- -- Flash messages -- -->
<?php if ($success): ?>
<div class="flash flash-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- -- Stat cards -- -->
<div class="stats-grid reports-stats">
    <a class="stat-card" onclick="switchTab('appraisals')" href="?tab=appraisals">
        <div class="stat-icon bg-blue"><i class="fas fa-clipboard-check"></i></div>
        <div class="stat-info"><span class="stat-label">Performance Appraisals</span><span class="stat-value"><?php echo $total_appraisals; ?></span></div>
    </a>
    <a class="stat-card" onclick="switchTab('activities')" href="?tab=activities">
        <div class="stat-icon bg-green"><i class="fas fa-tasks"></i></div>
        <div class="stat-info"><span class="stat-label">Activity Plans</span><span class="stat-value"><?php echo $total_activities; ?></span></div>
    </a>
    <a class="stat-card" onclick="switchTab('bc')" href="?tab=bc">
        <div class="stat-icon bg-amber"><i class="fas fa-brain"></i></div>
        <div class="stat-info"><span class="stat-label">BC Assessments</span><span class="stat-value"><?php echo $total_bc; ?></span></div>
    </a>
</div>

<!-- -- Tabs -- -->
<div class="section-card">
    <div style="padding: 0 20px; border-bottom: 2px solid var(--border);">
        <div class="tab-nav">
            <button class="tab-btn <?php echo $active_tab==='appraisals'?'active':''; ?>"
                    onclick="switchTab('appraisals')">
                <i class="fas fa-clipboard-check"></i> Performance Appraisals
                <span class="tab-badge"><?php echo $total_appraisals; ?></span>
            </button>
            <button class="tab-btn <?php echo $active_tab==='activities'?'active':''; ?>"
                    onclick="switchTab('activities')">
                <i class="fas fa-tasks"></i> Activity Plans
                <span class="tab-badge"><?php echo $total_activities; ?></span>
            </button>
            <button class="tab-btn <?php echo $active_tab==='bc'?'active':''; ?>"
                    onclick="switchTab('bc')">
                <i class="fas fa-brain"></i> BC Assessments
                <span class="tab-badge"><?php echo $total_bc; ?></span>
            </button>
        </div>
    </div>

    <div style="padding: 0 20px 20px;">

        <!-- ------------------------------------
             TAB 1 - Performance Appraisals
        ------------------------------------ -->
        <div class="tab-content <?php echo $active_tab==='appraisals'?'active':''; ?>" id="tab-appraisals">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                <div style="font-size:13px; color:var(--muted);">
                    <?php echo $total_appraisals; ?> appraisal<?php echo $total_appraisals !== 1 ? 's' : ''; ?> recorded
                </div>
                <a href="performance-appraisal" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> New Appraisal
                </a>
            </div>

            <?php if (empty($appraisals)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-clipboard-check"></i></div>
                <h4>No appraisals yet</h4>
                <p>Start by creating your first performance appraisal to track KRAs and KPIs.</p>
                <a href="performance-appraisal" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create First Appraisal
                </a>
            </div>
            <?php else: ?>
            <table class="ap-table">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Appraisal / Period</th>
                        <th class="th-c">KRAs</th>
                        <th class="th-c">KPIs</th>
                        <th class="th-c">My Avg</th>
                        <th class="th-c">Status</th>
                        <th class="th-c">Created</th>
                        <th class="th-c">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($appraisals as $i => $ap): ?>
                <tr>
                    <td style="color:var(--muted);font-family:var(--mono);font-size:12px"><?php echo $i+1; ?></td>
                    <td>
                        <div class="ap-title">
                            <?php echo htmlspecialchars($ap['job_title'] ?? 'Appraisal'); ?>
                            -&nbsp;&nbsp;
                            <span style="font-size:12px;color:var(--muted)"><?php echo htmlspecialchars($ap['department'] ?? ''); ?></span>
                        </div>
                        <div class="ap-meta">
                            <span><i class="fas fa-calendar-alt"></i>
                                <?php echo fmt_date($ap['period_from']); ?> - <?php echo fmt_date($ap['period_to']); ?>
                            </span>
                            <?php if ($ap['supervisor_name']): ?>
                            <span><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($ap['supervisor_name']); ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="td-c">
                        <span style="font-weight:700;font-family:var(--mono)"><?php echo (int)$ap['kra_count']; ?></span>
                    </td>
                    <td class="td-c">
                        <span style="font-weight:700;font-family:var(--mono)"><?php echo (int)$ap['kpi_count']; ?></span>
                    </td>
                    <td class="td-c">
                        <?php if ($ap['avg_emp']): ?>
                        <span class="rating-pill emp">
                            <i class="fas fa-star" style="font-size:9px"></i>
                            <?php echo number_format((float)$ap['avg_emp'], 1); ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--muted);font-size:12px">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-c"><?php echo status_badge($ap['status']); ?></td>
                    <td class="td-c" style="font-size:12px;color:var(--muted);white-space:nowrap">
                        <?php echo fmt_date($ap['created_at']); ?>
                    </td>
                    <td class="td-c">
                        <div class="row-actions">
                            <a href="view-appraisal?id=<?php echo $ap['appraisal_id']; ?>"
                               class="btn btn-outline btn-xs" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php /* Edit/Delete hidden: edit-appraisal.php and appraisal-delete.php don't exist yet. */ ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ------------------------------------
             TAB 2 - Activity Plans
        ------------------------------------ -->
        <div class="tab-content <?php echo $active_tab==='activities'?'active':''; ?>" id="tab-activities">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                <div style="font-size:13px; color:var(--muted);">
                    <?php echo $total_activities; ?> plan<?php echo $total_activities !== 1 ? 's' : ''; ?> recorded
                </div>
                <a href="create-activity" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i> New Activity Plan
                </a>
            </div>

            <?php if (empty($activities)): ?>
            <div class="empty-state">
                <div class="empty-icon" style="background:var(--success-lt);color:var(--success)">
                    <i class="fas fa-tasks"></i>
                </div>
                <h4>No activity plans yet</h4>
                <p>Create an activity plan to define your activities, key results, and weekly tracking targets.</p>
                <a href="create-activity" class="btn btn-success">
                    <i class="fas fa-plus"></i> Create First Activity Plan
                </a>
            </div>
            <?php else: ?>
            <table class="ap-table">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Plan Title / Category</th>
                        <th class="th-c">Year</th>
                        <th class="th-c">Activities</th>
                        <th class="th-c">Weekly Entries</th>
                        <th class="th-c">Weight</th>
                        <th class="th-c">Status</th>
                        <th class="th-c">Created</th>
                        <th class="th-c">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($activities as $i => $act): ?>
                <tr>
                    <td style="color:var(--muted);font-family:var(--mono);font-size:12px"><?php echo $i+1; ?></td>
                    <td>
                        <div class="ap-title"><?php echo htmlspecialchars($act['plan_title']); ?></div>
                        <div class="ap-meta">
                            <?php if ($act['category_name']): ?>
                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($act['category_name']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-calendar"></i> FY <?php echo $act['fiscal_year']; ?></span>
                        </div>
                    </td>
                    <td class="td-c">
                        <span style="font-family:var(--mono);font-weight:700"><?php echo $act['fiscal_year']; ?></span>
                    </td>
                    <td class="td-c">
                        <span style="font-weight:700;font-family:var(--mono)"><?php echo (int)$act['activity_count']; ?></span>
                    </td>
                    <td class="td-c">
                        <div class="week-fill">
                            <div class="week-bar">
                                <?php $ep = min(100, (int)$act['entry_count'] * 2); ?>
                                <div class="week-bar-fill" style="width:<?php echo $ep; ?>%"></div>
                            </div>
                            <span style="font-family:var(--mono);font-size:11px"><?php echo (int)$act['entry_count']; ?></span>
                        </div>
                    </td>
                    <td class="td-c">
                        <?php $w = (float)$act['total_weight']; ?>
                        <span style="font-family:var(--mono);font-weight:700;color:<?php echo abs($w-100)<1 ? 'var(--success)' : 'var(--warning)'; ?>">
                            <?php echo number_format($w, 1); ?>%
                        </span>
                    </td>
                    <td class="td-c"><?php echo status_badge($act['status']); ?></td>
                    <td class="td-c" style="font-size:12px;color:var(--muted);white-space:nowrap">
                        <?php echo fmt_date($act['created_at']); ?>
                    </td>
                    <td class="td-c">
                        <div class="row-actions">
                            <a href="view-activity?id=<?php echo $act['plan_id']; ?>"
                               class="btn btn-outline btn-xs" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($act['status'] === 'draft'): ?>
                            <a href="edit-activity?id=<?php echo $act['plan_id']; ?>"
                               class="btn btn-outline btn-xs" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php /* Delete hidden: appraisal-delete.php doesn't exist yet. */ ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ------------------------------------
             TAB 3 - BC Assessments
        ------------------------------------ -->
        <div class="tab-content <?php echo $active_tab==='bc'?'active':''; ?>" id="tab-bc">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                <div style="font-size:13px; color:var(--muted);">
                    <?php echo $total_bc; ?> assessment<?php echo $total_bc !== 1 ? 's' : ''; ?> recorded
                </div>
                <a href="employee-competency" class="btn btn-sm" style="background:var(--warning-lt);color:var(--warning);border-color:var(--warning);border-width:1.5px;font-weight:600">
                    <i class="fas fa-plus"></i> New BC Assessment
                </a>
            </div>

            <?php if (empty($bc_submissions)): ?>
            <div class="empty-state">
                <div class="empty-icon" style="background:var(--warning-lt);color:var(--warning)">
                    <i class="fas fa-brain"></i>
                </div>
                <h4>No BC assessments yet</h4>
                <p>Complete a behavioral competency assessment to evaluate yourself against key behaviors.</p>
                <a href="employee-competency" class="btn" style="background:var(--warning);color:#fff;">
                    <i class="fas fa-plus"></i> Start BC Assessment
                </a>
            </div>
            <?php else: ?>
            <table class="ap-table">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Assessment / Period</th>
                        <th class="th-c">Indicators</th>
                        <th class="th-c">Completion</th>
                        <th class="th-c">My Avg</th>
                        <th class="th-c">Mgr Avg</th>
                        <th class="th-c">Agreed</th>
                        <th class="th-c">Status</th>
                        <th class="th-c">Created</th>
                        <th class="th-c">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bc_submissions as $i => $bc): ?>
                <?php
                    $total_ind = (int)$bc['total_indicators'];
                    $rated     = (int)$bc['emp_rated'];
                    $pct       = $total_ind > 0 ? round($rated / $total_ind * 100) : 0;
                    // SVG ring
                    $r = 18; $circ = 2 * M_PI * $r;
                    $dash = $circ * ($pct / 100);
                    $gap  = $circ - $dash;
                    $ring_color = $pct >= 100 ? '#ff9800' : ($pct >= 50 ? '#d97706' : '#94a3b8');
                ?>
                <tr>
                    <td style="color:var(--muted);font-family:var(--mono);font-size:12px"><?php echo $i+1; ?></td>
                    <td>
                        <div class="ap-title">
                            <?php echo htmlspecialchars($bc['job_title'] ?? 'BC Assessment'); ?>
                            <span style="font-size:12px;font-weight:400;color:var(--muted)">
                                &nbsp;·&nbsp; <?php echo htmlspecialchars($bc['department'] ?? ''); ?>
                            </span>
                        </div>
                        <div class="ap-meta">
                            <span><i class="fas fa-calendar-alt"></i>
                                <?php echo fmt_date($bc['period_from']); ?> - <?php echo fmt_date($bc['period_to']); ?>
                            </span>
                            <?php if (!empty($bc['supervisor_name']) || !empty($bc['supervisor_id'])): ?>
                            <span><i class="fas fa-user-tie"></i>
                                <?php
                                    // Fetch supervisor name if not in result set
                                    if (empty($bc['supervisor_name']) && !empty($bc['supervisor_id'])) {
                                        $snq = $conn->query("SELECT full_name AS n FROM users WHERE user_id=".(int)$bc['supervisor_id']." LIMIT 1");
                                        echo $snq ? htmlspecialchars($snq->fetch_assoc()['n'] ?? '') : '';
                                    } else {
                                        echo htmlspecialchars($bc['supervisor_name'] ?? '');
                                    }
                                ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="td-c">
                        <span style="font-weight:700;font-family:var(--mono)"><?php echo $total_ind; ?></span>
                    </td>
                    <td class="td-c">
                        <!-- SVG completion ring -->
                        <div style="display:flex;align-items:center;justify-content:center;gap:6px">
                            <div class="ring-wrap">
                                <svg width="44" height="44" viewBox="0 0 44 44">
                                    <circle cx="22" cy="22" r="<?php echo $r; ?>"
                                            fill="none" stroke="#e2e8f0" stroke-width="4"/>
                                    <circle cx="22" cy="22" r="<?php echo $r; ?>"
                                            fill="none" stroke="<?php echo $ring_color; ?>" stroke-width="4"
                                            stroke-dasharray="<?php echo round($dash,2); ?> <?php echo round($gap,2); ?>"
                                            stroke-linecap="round"/>
                                </svg>
                                <div class="ring-val" style="color:<?php echo $ring_color; ?>"><?php echo $pct; ?>%</div>
                            </div>
                        </div>
                    </td>
                    <td class="td-c">
                        <?php if ($bc['avg_emp']): ?>
                        <span class="rating-pill emp"><i class="fas fa-star" style="font-size:9px"></i> <?php echo number_format((float)$bc['avg_emp'],1); ?></span>
                        <?php else: ?><span style="color:var(--muted);font-size:12px">-</span><?php endif; ?>
                    </td>
                    <td class="td-c">
                        <?php if ($bc['avg_mgr']): ?>
                        <span class="rating-pill mgr"><i class="fas fa-star" style="font-size:9px"></i> <?php echo number_format((float)$bc['avg_mgr'],1); ?></span>
                        <?php else: ?><span style="color:var(--muted);font-size:12px">-</span><?php endif; ?>
                    </td>
                    <td class="td-c">
                        <?php if ($bc['avg_agr']): ?>
                        <span class="rating-pill agr"><i class="fas fa-star" style="font-size:9px"></i> <?php echo number_format((float)$bc['avg_agr'],1); ?></span>
                        <?php else: ?><span style="color:var(--muted);font-size:12px">-</span><?php endif; ?>
                    </td>
                    <td class="td-c"><?php echo status_badge($bc['status']); ?></td>
                    <td class="td-c" style="font-size:12px;color:var(--muted);white-space:nowrap">
                        <?php echo fmt_date($bc['created_at']); ?>
                    </td>
                    <td class="td-c">
                        <div class="row-actions">
                            <?php /* View/Edit/Delete hidden: view-bc.php, edit-bc.php and appraisal-delete.php don't exist yet. */ ?>
                            <span style="color:var(--muted);font-size:12px">-</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- /tab panels -->
</div><!-- /section-card -->

<!-- -- Delete confirm modal (unused: appraisal-delete.php does not exist yet; all delete buttons are hidden) -- -->
<div class="modal-bd" id="deleteModal">
    <div class="modal-box">
        <div style="font-size:36px;color:var(--danger);margin-bottom:12px">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h3>Delete this record?</h3>
        <p id="deleteMsg">This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <form method="POST" action="appraisal-delete.php" id="deleteForm">
                <input type="hidden" name="type" id="deleteType">
                <input type="hidden" name="id"   id="deleteId">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Yes, Delete
                </button>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// -- Tab switching --
const TABS = ['appraisals','activities','bc'];
function switchTab(name) {
    TABS.forEach(t => {
        document.querySelectorAll('.tab-btn').forEach((btn, i) => {
            if (TABS[i] === t) btn.classList.toggle('active', t === name);
        });
        const el = document.getElementById('tab-' + t);
        if (el) el.classList.toggle('active', t === name);
    });
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', name);
    history.replaceState({}, '', url);
}

// Re-wire tab buttons (they may be in any order)
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const target = this.getAttribute('onclick').match(/'(\w+)'/)?.[1];
        if (target) switchTab(target);
    });
});

// Init active tab
switchTab('<?php echo $active_tab; ?>');

// -- Delete modal --
function confirmDelete(type, id, label) {
    document.getElementById('deleteType').value = type;
    document.getElementById('deleteId').value   = id;
    document.getElementById('deleteMsg').textContent =
        `"${label}" will be permanently deleted. This cannot be undone.`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
</script>