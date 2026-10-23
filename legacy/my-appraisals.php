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
        'draft'          => ['badge-secondary', 'Draft'],
        'submitted'      => ['badge-info',      'Submitted'],
        'pending_review' => ['badge-info',      'Pending Review'],
        'under_review'   => ['badge-warning',   'Under Review'],
        'reviewed'       => ['badge-purple',    'Reviewed'],
        'approved'       => ['badge-success',   'Approved'],
        'completed'      => ['badge-success',   'Completed'],
        'rejected'       => ['badge-danger',    'Rejected'],
    ];
    [$cls, $label] = $map[$s] ?? ['badge-secondary', ucfirst($s)];
    return "<span class='badge $cls'>$label</span>";
}

function fmt_date(?string $d): string {
    if (!$d) return '-';
    return date('M j, Y', strtotime($d));
}
?>

<style>
/* My Appraisals: page-only layout (prefix ap-) */
.ap-tab-count {
    background: var(--ink-50); color: var(--ink-400);
    padding: 1px 7px; border-radius: var(--radius-pill);
    font-size: 10px; font-weight: 700;
}
.tab-btn.active .ap-tab-count { background: var(--brand-50); color: var(--brand-600); }

.ap-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
}
.ap-count { font-size: 13px; color: var(--ink-300); }

.table .ap-c { text-align: center; }
.table .ap-index { width: 36px; color: var(--ink-300); font-size: 12px; }
.table .ap-date { font-size: 12px; color: var(--ink-300); white-space: nowrap; }
.ap-num { font-weight: 700; font-variant-numeric: tabular-nums; color: var(--ink-700); }
.ap-num.ap-weight-ok  { color: var(--green-fg); }
.ap-num.ap-weight-off { color: var(--amber-fg); }
.ap-dash { color: var(--ink-300); font-size: 12px; }

.ap-title { font-weight: 600; color: var(--ink-900); margin-bottom: 2px; }
.ap-sub   { font-size: 12px; font-weight: 400; color: var(--ink-300); }
.ap-meta  { font-size: 11px; color: var(--ink-300); }
.ap-meta span { margin-right: 10px; }
.ap-star  { font-size: 9px; }

.ap-actions { justify-content: center; }

/* Weekly entries bar (activities) */
.ap-week-fill { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 11px; }
.ap-week-bar { width: 60px; height: 5px; background: var(--ink-50); border-radius: var(--radius-pill); overflow: hidden; }
.ap-week-bar-fill { height: 100%; background: var(--green-fg); border-radius: var(--radius-pill); }

/* Completion ring (BC) */
.ap-ring-cell { display: flex; align-items: center; justify-content: center; }
.ap-ring { position: relative; width: 44px; height: 44px; flex-shrink: 0; }
.ap-ring svg { transform: rotate(-90deg); }
.ap-ring-track { stroke: var(--ink-50); }
.ap-ring-val {
    position: absolute; inset: 0; display: flex; align-items: center;
    justify-content: center; font-size: 10px; font-weight: 700;
}
.ap-ring-done { color: var(--green-fg); stroke: var(--green-fg); }
.ap-ring-mid  { color: var(--amber-fg); stroke: var(--amber-fg); }
.ap-ring-low  { color: var(--ink-200);  stroke: var(--ink-200); }

/* Delete confirm modal (opened by toggling .open) */
.ap-modal.open { display: block; }
.ap-modal-card { max-width: 420px; }
.ap-confirm { text-align: center; }
.ap-confirm-icon { font-size: 36px; color: var(--red-fg); margin-bottom: 12px; }
.ap-confirm h3 { font-size: 17px; font-weight: 700; margin-bottom: 10px; color: var(--ink-700); }
.ap-confirm p { font-size: 13px; color: var(--ink-300); }
.ap-confirm .modal-actions { justify-content: center; }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-clipboard-list" aria-hidden="true"></i> My Appraisals</h1>
        <p>Track your performance appraisals, activity plans, and behavioral assessments.</p>
    </div>
    <div class="hero-actions">
        <a href="performance-appraisal" class="btn btn-primary">
            <i class="fas fa-plus" aria-hidden="true"></i> New Appraisal
        </a>
        <a href="create-activity" class="btn">
            <i class="fas fa-tasks" aria-hidden="true"></i> New Activity Plan
        </a>
        <a href="employee-competency" class="btn">
            <i class="fas fa-brain" aria-hidden="true"></i> New BC Assessment
        </a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle" aria-hidden="true"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

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

<div class="detail-tabs">
    <div class="tab-nav">
        <button class="tab-btn <?php echo $active_tab==='appraisals'?'active':''; ?>"
                onclick="switchTab('appraisals')">
            <i class="fas fa-clipboard-check"></i> Performance Appraisals
            <span class="ap-tab-count"><?php echo $total_appraisals; ?></span>
        </button>
        <button class="tab-btn <?php echo $active_tab==='activities'?'active':''; ?>"
                onclick="switchTab('activities')">
            <i class="fas fa-tasks"></i> Activity Plans
            <span class="ap-tab-count"><?php echo $total_activities; ?></span>
        </button>
        <button class="tab-btn <?php echo $active_tab==='bc'?'active':''; ?>"
                onclick="switchTab('bc')">
            <i class="fas fa-brain"></i> BC Assessments
            <span class="ap-tab-count"><?php echo $total_bc; ?></span>
        </button>
    </div>

    <div class="tab-panels">

        <!-- ------------------------------------
             TAB 1 - Performance Appraisals
        ------------------------------------ -->
        <div class="tab-panel tab-content <?php echo $active_tab==='appraisals'?'active':''; ?>" id="tab-appraisals">
            <div class="ap-toolbar">
                <div class="ap-count">
                    <?php echo $total_appraisals; ?> appraisal<?php echo $total_appraisals !== 1 ? 's' : ''; ?> recorded
                </div>
                <a href="performance-appraisal" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus" aria-hidden="true"></i> New Appraisal
                </a>
            </div>

            <?php if (empty($appraisals)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                <h4>No appraisals yet</h4>
                <p>Start by creating your first performance appraisal to track KRAs and KPIs.</p>
                <a href="performance-appraisal" class="btn btn-primary">
                    <i class="fas fa-plus" aria-hidden="true"></i> Create First Appraisal
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th class="ap-index">#</th>
                        <th>Appraisal / Period</th>
                        <th class="ap-c">KRAs</th>
                        <th class="ap-c">KPIs</th>
                        <th class="ap-c">My Avg</th>
                        <th class="ap-c">Status</th>
                        <th class="ap-c">Created</th>
                        <th class="ap-c">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($appraisals as $i => $ap): ?>
                <tr>
                    <td class="ap-index"><?php echo $i+1; ?></td>
                    <td>
                        <div class="ap-title">
                            <?php echo htmlspecialchars($ap['job_title'] ?? 'Appraisal'); ?>
                            -&nbsp;&nbsp;
                            <span class="ap-sub"><?php echo htmlspecialchars($ap['department'] ?? ''); ?></span>
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
                    <td class="ap-c">
                        <span class="ap-num"><?php echo (int)$ap['kra_count']; ?></span>
                    </td>
                    <td class="ap-c">
                        <span class="ap-num"><?php echo (int)$ap['kpi_count']; ?></span>
                    </td>
                    <td class="ap-c">
                        <?php if ($ap['avg_emp']): ?>
                        <span class="badge badge-info">
                            <i class="fas fa-star ap-star"></i>
                            <?php echo number_format((float)$ap['avg_emp'], 1); ?>
                        </span>
                        <?php else: ?>
                        <span class="ap-dash">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="ap-c"><?php echo status_badge($ap['status']); ?></td>
                    <td class="ap-c ap-date">
                        <?php echo fmt_date($ap['created_at']); ?>
                    </td>
                    <td class="ap-c">
                        <div class="actions ap-actions">
                            <a href="view-appraisal?id=<?php echo $ap['appraisal_id']; ?>"
                               class="btn btn-info btn-sm" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php /* Edit/Delete hidden: edit-appraisal.php and appraisal-delete.php don't exist yet. */ ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ------------------------------------
             TAB 2 - Activity Plans
        ------------------------------------ -->
        <div class="tab-panel tab-content <?php echo $active_tab==='activities'?'active':''; ?>" id="tab-activities">
            <div class="ap-toolbar">
                <div class="ap-count">
                    <?php echo $total_activities; ?> plan<?php echo $total_activities !== 1 ? 's' : ''; ?> recorded
                </div>
                <a href="create-activity" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus" aria-hidden="true"></i> New Activity Plan
                </a>
            </div>

            <?php if (empty($activities)): ?>
            <div class="empty-state">
                <i class="fas fa-tasks" aria-hidden="true"></i>
                <h4>No activity plans yet</h4>
                <p>Create an activity plan to define your activities, key results, and weekly tracking targets.</p>
                <a href="create-activity" class="btn btn-primary">
                    <i class="fas fa-plus" aria-hidden="true"></i> Create First Activity Plan
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th class="ap-index">#</th>
                        <th>Plan Title / Category</th>
                        <th class="ap-c">Year</th>
                        <th class="ap-c">Activities</th>
                        <th class="ap-c">Weekly Entries</th>
                        <th class="ap-c">Weight</th>
                        <th class="ap-c">Status</th>
                        <th class="ap-c">Created</th>
                        <th class="ap-c">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($activities as $i => $act): ?>
                <tr>
                    <td class="ap-index"><?php echo $i+1; ?></td>
                    <td>
                        <div class="ap-title"><?php echo htmlspecialchars($act['plan_title']); ?></div>
                        <div class="ap-meta">
                            <?php if ($act['category_name']): ?>
                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($act['category_name']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-calendar"></i> FY <?php echo $act['fiscal_year']; ?></span>
                        </div>
                    </td>
                    <td class="ap-c">
                        <span class="ap-num"><?php echo $act['fiscal_year']; ?></span>
                    </td>
                    <td class="ap-c">
                        <span class="ap-num"><?php echo (int)$act['activity_count']; ?></span>
                    </td>
                    <td class="ap-c">
                        <div class="ap-week-fill">
                            <div class="ap-week-bar">
                                <?php $ep = min(100, (int)$act['entry_count'] * 2); ?>
                                <div class="ap-week-bar-fill" style="width:<?php echo $ep; ?>%"></div>
                            </div>
                            <span><?php echo (int)$act['entry_count']; ?></span>
                        </div>
                    </td>
                    <td class="ap-c">
                        <?php $w = (float)$act['total_weight']; ?>
                        <span class="ap-num <?php echo abs($w-100)<1 ? 'ap-weight-ok' : 'ap-weight-off'; ?>">
                            <?php echo number_format($w, 1); ?>%
                        </span>
                    </td>
                    <td class="ap-c"><?php echo status_badge($act['status']); ?></td>
                    <td class="ap-c ap-date">
                        <?php echo fmt_date($act['created_at']); ?>
                    </td>
                    <td class="ap-c">
                        <div class="actions ap-actions">
                            <a href="view-activity?id=<?php echo $act['plan_id']; ?>"
                               class="btn btn-info btn-sm" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($act['status'] === 'draft'): ?>
                            <a href="edit-activity?id=<?php echo $act['plan_id']; ?>"
                               class="btn btn-secondary btn-sm" title="Edit">
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
            </div>
            <?php endif; ?>
        </div>

        <!-- ------------------------------------
             TAB 3 - BC Assessments
        ------------------------------------ -->
        <div class="tab-panel tab-content <?php echo $active_tab==='bc'?'active':''; ?>" id="tab-bc">
            <div class="ap-toolbar">
                <div class="ap-count">
                    <?php echo $total_bc; ?> assessment<?php echo $total_bc !== 1 ? 's' : ''; ?> recorded
                </div>
                <a href="employee-competency" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus" aria-hidden="true"></i> New BC Assessment
                </a>
            </div>

            <?php if (empty($bc_submissions)): ?>
            <div class="empty-state">
                <i class="fas fa-brain" aria-hidden="true"></i>
                <h4>No BC assessments yet</h4>
                <p>Complete a behavioral competency assessment to evaluate yourself against key behaviors.</p>
                <a href="employee-competency" class="btn btn-primary">
                    <i class="fas fa-plus" aria-hidden="true"></i> Start BC Assessment
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th class="ap-index">#</th>
                        <th>Assessment / Period</th>
                        <th class="ap-c">Indicators</th>
                        <th class="ap-c">Completion</th>
                        <th class="ap-c">My Avg</th>
                        <th class="ap-c">Mgr Avg</th>
                        <th class="ap-c">Agreed</th>
                        <th class="ap-c">Status</th>
                        <th class="ap-c">Created</th>
                        <th class="ap-c">Actions</th>
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
                    $ring_class = $pct >= 100 ? 'ap-ring-done' : ($pct >= 50 ? 'ap-ring-mid' : 'ap-ring-low');
                ?>
                <tr>
                    <td class="ap-index"><?php echo $i+1; ?></td>
                    <td>
                        <div class="ap-title">
                            <?php echo htmlspecialchars($bc['job_title'] ?? 'BC Assessment'); ?>
                            <span class="ap-sub">
                                &nbsp;&middot;&nbsp; <?php echo htmlspecialchars($bc['department'] ?? ''); ?>
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
                    <td class="ap-c">
                        <span class="ap-num"><?php echo $total_ind; ?></span>
                    </td>
                    <td class="ap-c">
                        <!-- SVG completion ring -->
                        <div class="ap-ring-cell">
                            <div class="ap-ring <?php echo $ring_class; ?>">
                                <svg width="44" height="44" viewBox="0 0 44 44">
                                    <circle class="ap-ring-track" cx="22" cy="22" r="<?php echo $r; ?>"
                                            fill="none" stroke-width="4"/>
                                    <circle cx="22" cy="22" r="<?php echo $r; ?>"
                                            fill="none" stroke-width="4"
                                            stroke-dasharray="<?php echo round($dash,2); ?> <?php echo round($gap,2); ?>"
                                            stroke-linecap="round"/>
                                </svg>
                                <div class="ap-ring-val"><?php echo $pct; ?>%</div>
                            </div>
                        </div>
                    </td>
                    <td class="ap-c">
                        <?php if ($bc['avg_emp']): ?>
                        <span class="badge badge-info"><i class="fas fa-star ap-star"></i> <?php echo number_format((float)$bc['avg_emp'],1); ?></span>
                        <?php else: ?><span class="ap-dash">-</span><?php endif; ?>
                    </td>
                    <td class="ap-c">
                        <?php if ($bc['avg_mgr']): ?>
                        <span class="badge badge-success"><i class="fas fa-star ap-star"></i> <?php echo number_format((float)$bc['avg_mgr'],1); ?></span>
                        <?php else: ?><span class="ap-dash">-</span><?php endif; ?>
                    </td>
                    <td class="ap-c">
                        <?php if ($bc['avg_agr']): ?>
                        <span class="badge badge-warning"><i class="fas fa-star ap-star"></i> <?php echo number_format((float)$bc['avg_agr'],1); ?></span>
                        <?php else: ?><span class="ap-dash">-</span><?php endif; ?>
                    </td>
                    <td class="ap-c"><?php echo status_badge($bc['status']); ?></td>
                    <td class="ap-c ap-date">
                        <?php echo fmt_date($bc['created_at']); ?>
                    </td>
                    <td class="ap-c">
                        <div class="actions ap-actions">
                            <?php /* View/Edit/Delete hidden: view-bc.php, edit-bc.php and appraisal-delete.php don't exist yet. */ ?>
                            <span class="ap-dash">-</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /.tab-panels -->
</div><!-- /.detail-tabs -->
</div><!-- /.page-stack -->

<!-- -- Delete confirm modal (unused: appraisal-delete.php does not exist yet; all delete buttons are hidden) -- -->
<div class="modal ap-modal" id="deleteModal">
    <div class="modal-card ap-modal-card">
        <div class="modal-body ap-confirm">
            <div class="ap-confirm-icon">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            </div>
            <h3>Delete this record?</h3>
            <p id="deleteMsg">This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
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