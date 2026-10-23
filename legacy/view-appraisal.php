<?php

$page_title = 'My Appraisals';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

$uid          = (int) $_SESSION['user_id'];
$appraisal_id = (int) ($_GET['id'] ?? 0);

if ($appraisal_id === 0) {
    send_notification($uid, 'No appraisal specified.', 'danger');
    header('Location: my-appraisals');
    exit();
}


$stmt = $conn->prepare("
    SELECT  pa.*,
            u.full_name  AS employee_display_name
    FROM    performance_appraisals pa
    LEFT JOIN users u ON u.user_id = pa.user_id
    WHERE   pa.appraisal_id = ?
      AND  (pa.user_id = ? OR pa.supervisor_id = ?)
    LIMIT 1
");
$stmt->bind_param('iii', $appraisal_id, $uid, $uid);
$stmt->execute();
$appraisal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appraisal) {
    send_notification($uid, 'Appraisal not found or access denied.', 'danger');
    header('Location: my-appraisals');
    exit();
}

$is_owner      = ($appraisal['user_id']      === $uid);
$is_supervisor = ($appraisal['supervisor_id'] === $uid);
$status        = $appraisal['status'];  


$stmt = $conn->prepare("
    SELECT * FROM appraisal_kras
    WHERE appraisal_id = ?
    ORDER BY sort_order ASC
");
$stmt->bind_param('i', $appraisal_id);
$stmt->execute();
$kras_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


$stmt = $conn->prepare("
    SELECT * FROM appraisal_kpis
    WHERE appraisal_id = ?
    ORDER BY kra_id ASC, sort_order ASC
");
$stmt->bind_param('i', $appraisal_id);
$stmt->execute();
$kpis_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$kpis_by_kra = [];
foreach ($kpis_raw as $kpi) {
    $kpis_by_kra[$kpi['kra_id']][] = $kpi;
}


$status_labels = [
    'draft'          => ['label' => 'Draft',          'class' => 'badge-secondary'],
    'pending_review' => ['label' => 'Pending Review',  'class' => 'badge-pending'],
    'reviewed'       => ['label' => 'Reviewed',        'class' => 'badge-info'],
    'approved'       => ['label' => 'Approved',        'class' => 'badge-success'],
];
$status_meta = $status_labels[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-secondary'];


function rating_badge(?int $r): string {
    if ($r === null) return '<span class="va-no-rating">-</span>';
    $cls = ($r >= 1 && $r <= 5) ? "va-rating-{$r}" : 'va-rating-x';
    return "<span class=\"va-rating {$cls}\">{$r}</span>";
}

function fmt_date(?string $d): string {
    if (!$d) return '-';
    return date('d M Y', strtotime($d));
}
?>
<style>
/* View Appraisal: page-only layout (prefix va-) */
.va-hero-meta { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }

/* Manager overall comment */
.va-mgr-comment { align-items: flex-start; }
.va-mgr-comment-label {
    font-size: 11px; font-weight: 700; letter-spacing: .06em;
    text-transform: uppercase; margin-bottom: 6px;
}
.va-mgr-comment p { color: var(--ink-500); line-height: 1.7; margin: 0; }

/* Details grid */
.va-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}
.va-info-label {
    font-size: 11px; font-weight: 600; letter-spacing: .06em;
    text-transform: uppercase; color: var(--ink-300); margin-bottom: 3px;
}
.va-info-value { font-size: 14px; font-weight: 500; color: var(--ink-900); }

.va-head-note { font-size: 12px; color: var(--ink-300); }

/* KRA blocks */
.va-kras { display: flex; flex-direction: column; gap: 16px; }
.va-kra {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}
.va-kra-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
    padding: 14px 20px;
    background: var(--surface-hover);
    border-bottom: 1px solid var(--border);
}
.va-kra-number {
    font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    color: var(--brand-600); background: var(--brand-50);
    padding: 3px 10px; border-radius: var(--radius-sm); flex-shrink: 0;
}
.va-kra-title { font-size: 15px; font-weight: 700; color: var(--ink-900); flex: 1; }
.va-kra-meta { display: flex; align-items: center; gap: 16px; flex-shrink: 0; flex-wrap: wrap; }
.va-kra-meta-item { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.va-kra-meta-label {
    font-size: 10px; font-weight: 600; letter-spacing: .06em;
    text-transform: uppercase; color: var(--ink-300);
}
.va-kra-meta-value { font-size: 13px; font-weight: 600; color: var(--ink-900); }

.va-seq { color: var(--ink-300); font-size: 12px; }
.va-kpi-title { font-weight: 500; color: var(--ink-900); }
.va-kpi-weight { font-weight: 600; color: var(--ink-300); font-size: 12px; }

/* Rating dot */
.va-rating {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    color: var(--ink-0); font-size: 12px; font-weight: 700;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.va-rating-1 { background: var(--red-fg); }
.va-rating-2 { background: var(--brand-600); }
.va-rating-3 { background: var(--amber-fg); }
.va-rating-4 { background: var(--green-fg); }
.va-rating-5 { background: var(--teal-fg); }
.va-rating-x { background: var(--slate-fg); }
.va-no-rating { color: var(--ink-300); font-size: 16px; }

/* KRA comments */
.va-comments {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
    padding: 16px 20px;
    background: var(--surface-hover);
    border-top: 1px solid var(--border);
}
.va-comment-label {
    font-size: 11px; font-weight: 700; letter-spacing: .06em;
    text-transform: uppercase; color: var(--ink-300); margin-bottom: 5px;
}
.va-comment-text { font-size: 13px; color: var(--ink-500); line-height: 1.6; min-height: 24px; }
.va-comment-empty { color: var(--ink-300); font-style: italic; }

/* Supervisor review form */
.va-review-sub { font-size: 12px; color: var(--ink-300); margin: 3px 0 0; }
.va-review-kra { font-size: 14px; font-weight: 700; color: var(--ink-900); margin: 0 0 10px; }
.va-review-kra.va-spaced { margin-top: 24px; }
.va-review-table { margin-bottom: 20px; }
.va-review-table .form-control { min-width: 80px; }
.va-review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 4px; }
.va-review-grid .form-group { margin-bottom: 0; }
.va-overall { margin-top: 24px; }
.va-review-actions {
    justify-content: flex-end;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

/* Timeline */
.va-timeline { display: flex; flex-direction: column; }
.va-tl-item { display: flex; gap: 14px; padding-bottom: 18px; position: relative; }
.va-tl-item:not(:last-child)::before {
    content: ''; position: absolute;
    left: 15px; top: 30px; bottom: 0; width: 1px;
    background: var(--border);
}
.va-tl-dot {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; z-index: 1;
    background: var(--brand-50); border: 2px solid var(--brand-500); color: var(--brand-600);
}
.va-tl-dot.done    { background: var(--green-bg); border-color: var(--green-fg); color: var(--green-fg); }
.va-tl-dot.pending { background: var(--amber-bg); border-color: var(--amber-fg); color: var(--amber-fg); }
.va-tl-title { font-weight: 600; font-size: 13px; color: var(--ink-900); }
.va-tl-time  { font-size: 12px; color: var(--ink-300); margin-top: 1px; }

@media (max-width: 600px) {
    .va-comments, .va-review-grid { grid-template-columns: 1fr; }
    .va-kra-header { padding: 12px 14px; }
}

@media print {
    .va-page .no-print { display: none !important; }
    .va-page .card, .va-kra { box-shadow: none; }
}
</style>

<div class="page-stack va-page">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-clipboard-check" aria-hidden="true"></i> <?= htmlspecialchars($appraisal['full_name']) ?></h1>
        <p class="va-hero-meta">
            <span>Performance Appraisal #<?= $appraisal_id ?></span>
            <span class="badge <?= $status_meta['class'] ?>"><?= $status_meta['label'] ?></span>
        </p>
    </div>
    <div class="hero-actions no-print">
        <a href="my-appraisals" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> My Appraisals</a>
        <button onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
        <?php if ($is_owner && $status === 'draft'): ?>
            <?php /* "Edit Draft" hidden: no edit page exists yet (performance-appraisal ignores ?edit= and would create a duplicate). */ ?>
            <form method="POST" action="includes/appraisal-process.php">
                <input type="hidden" name="action" value="submit_appraisal_existing">
                <input type="hidden" name="appraisal_id" value="<?= $appraisal_id ?>">
                <button type="submit" name="submit_appraisal" class="btn btn-primary">
                    <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit for Review
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Overall Score (if available) -->
<?php if ($appraisal['overall_score'] !== null): ?>
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-star" aria-hidden="true"></i></div>
        <div class="stat-info"><span class="stat-label">Overall Score</span><span class="stat-value"><?= number_format((float) $appraisal['overall_score'], 2) ?></span></div>
    </div>
    <?php if ($appraisal['total_kra_weight'] !== null): ?>
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-balance-scale" aria-hidden="true"></i></div>
        <div class="stat-info"><span class="stat-label">Total Weight</span><span class="stat-value"><?= number_format((float) $appraisal['total_kra_weight'], 1) ?>%</span></div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-bullseye" aria-hidden="true"></i></div>
        <div class="stat-info"><span class="stat-label">KRAs</span><span class="stat-value"><?= count($kras_raw) ?></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-tasks" aria-hidden="true"></i></div>
        <div class="stat-info"><span class="stat-label">KPIs</span><span class="stat-value"><?= count($kpis_raw) ?></span></div>
    </div>
</div>
<?php endif; ?>

<!-- Manager Overall Comment -->
<?php if (!empty($appraisal['mgr_overall_comment'])): ?>
<div class="alert alert-warning va-mgr-comment">
    <i class="fas fa-comment-dots" aria-hidden="true"></i>
    <div>
        <div class="va-mgr-comment-label">Manager's Overall Comment</div>
        <p><?= nl2br(htmlspecialchars($appraisal['mgr_overall_comment'])) ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Appraisal Details -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-id-card" aria-hidden="true"></i> Appraisal Details</h3>
    </div>
    <div class="card-body">
        <div class="va-info-grid">
            <div>
                <div class="va-info-label">Employee</div>
                <div class="va-info-value"><?= htmlspecialchars($appraisal['full_name']) ?></div>
            </div>
            <div>
                <div class="va-info-label">Department</div>
                <div class="va-info-value"><?= htmlspecialchars($appraisal['department'] ?: '-') ?></div>
            </div>
            <div>
                <div class="va-info-label">Job Title</div>
                <div class="va-info-value"><?= htmlspecialchars($appraisal['job_title'] ?: '-') ?></div>
            </div>
            <div>
                <div class="va-info-label">Supervisor</div>
                <div class="va-info-value"><?= htmlspecialchars($appraisal['supervisor_name'] ?: '-') ?></div>
            </div>
            <div>
                <div class="va-info-label">Appraisal Period</div>
                <div class="va-info-value"><?= fmt_date($appraisal['period_from']) ?> - <?= fmt_date($appraisal['period_to']) ?></div>
            </div>
            <div>
                <div class="va-info-label">Discussion Date</div>
                <div class="va-info-value"><?= fmt_date($appraisal['discussion_date']) ?></div>
            </div>
            <?php if ($appraisal['submitted_at']): ?>
            <div>
                <div class="va-info-label">Submitted</div>
                <div class="va-info-value"><?= fmt_date($appraisal['submitted_at']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($appraisal['reviewed_at']): ?>
            <div>
                <div class="va-info-label">Reviewed</div>
                <div class="va-info-value"><?= fmt_date($appraisal['reviewed_at']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- KRAs & KPIs -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bullseye" aria-hidden="true"></i> Key Result Areas &amp; KPIs</h3>
        <span class="va-head-note"><?= count($kras_raw) ?> KRAs - <?= count($kpis_raw) ?> KPIs</span>
    </div>
    <div class="card-body">

        <?php if (empty($kras_raw)): ?>
            <div class="empty-state">
                <i class="fas fa-bullseye" aria-hidden="true"></i>
                <p>No KRAs recorded for this appraisal.</p>
            </div>
        <?php else: ?>

        <div class="va-kras">
        <?php foreach ($kras_raw as $i => $kra): ?>
        <div class="va-kra">
            <div class="va-kra-header">
                <span class="va-kra-number">KRA <?= $i + 1 ?></span>
                <span class="va-kra-title"><?= htmlspecialchars($kra['title']) ?></span>
                <div class="va-kra-meta">
                    <?php if ($kra['weight'] !== null): ?>
                    <div class="va-kra-meta-item">
                        <span class="va-kra-meta-label">Weight</span>
                        <span class="va-kra-meta-value"><?= number_format((float)$kra['weight'], 1) ?>%</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($kra['kra_rating'] !== null): ?>
                    <div class="va-kra-meta-item">
                        <span class="va-kra-meta-label">KRA Rating</span>
                        <span class="va-kra-meta-value"><?= number_format((float)$kra['kra_rating'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($kra['agreed_rating'] !== null): ?>
                    <div class="va-kra-meta-item">
                        <span class="va-kra-meta-label">Agreed</span>
                        <span class="va-kra-meta-value"><?= (int)$kra['agreed_rating'] ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPIs table -->
            <?php $kpis = $kpis_by_kra[$kra['kra_id']] ?? []; ?>
            <?php if (!empty($kpis)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>KPI</th>
                            <th>Weight</th>
                            <th>Employee</th>
                            <th>Manager</th>
                            <th>Agreed</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kpis as $j => $kpi): ?>
                        <tr>
                            <td class="va-seq"><?= $j + 1 ?></td>
                            <td class="va-kpi-title"><?= htmlspecialchars($kpi['title']) ?></td>
                            <td class="va-kpi-weight"><?= $kpi['weight'] !== null ? number_format((float)$kpi['weight'], 1).'%' : '-' ?></td>
                            <td><?= rating_badge($kpi['emp_rating'] !== null ? (int)$kpi['emp_rating'] : null) ?></td>
                            <td><?= rating_badge($kpi['mgr_rating'] !== null ? (int)$kpi['mgr_rating'] : null) ?></td>
                            <td><?= rating_badge($kpi['agreed_rating'] !== null ? (int)$kpi['agreed_rating'] : null) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Comments -->
            <?php if (!empty($kra['emp_comments']) || !empty($kra['mgr_comments'])): ?>
            <div class="va-comments">
                <div>
                    <div class="va-comment-label">Employee Comments</div>
                    <div class="va-comment-text <?= empty($kra['emp_comments']) ? 'va-comment-empty' : '' ?>">
                        <?= !empty($kra['emp_comments']) ? nl2br(htmlspecialchars($kra['emp_comments'])) : 'No comment provided.' ?>
                    </div>
                </div>
                <div>
                    <div class="va-comment-label">Manager Comments</div>
                    <div class="va-comment-text <?= empty($kra['mgr_comments']) ? 'va-comment-empty' : '' ?>">
                        <?= !empty($kra['mgr_comments']) ? nl2br(htmlspecialchars($kra['mgr_comments'])) : 'No comment provided.' ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- -----------------------------------------------------------
     SUPERVISOR REVIEW FORM  (only when pending + is supervisor)
---------------------------------------------------------------- -->
<?php if ($is_supervisor && $status === 'pending_review'): ?>
<div class="card no-print">
    <div class="card-header">
        <div>
            <h3><i class="fas fa-user-check" aria-hidden="true"></i> Supervisor Review</h3>
            <p class="va-review-sub">Set manager ratings for each KPI, add KRA comments, and submit your review.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="includes/appraisal-review-process.php">
            <input type="hidden" name="action"        value="review_appraisal">
            <input type="hidden" name="appraisal_id"  value="<?= $appraisal_id ?>">

            <?php foreach ($kras_raw as $i => $kra): ?>
            <?php $kpis = $kpis_by_kra[$kra['kra_id']] ?? []; ?>
            <h4 class="va-review-kra<?= $i > 0 ? ' va-spaced' : '' ?>">
                KRA <?= $i + 1 ?>: <?= htmlspecialchars($kra['title']) ?>
            </h4>

            <?php if (!empty($kpis)): ?>
            <div class="table-responsive va-review-table">
                <table class="table">
                    <thead>
                        <tr>
                            <th>KPI</th>
                            <th>Employee Rating</th>
                            <th>Manager Rating</th>
                            <th>Agreed Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kpis as $kpi): ?>
                    <tr>
                        <td class="va-kpi-title"><?= htmlspecialchars($kpi['title']) ?></td>
                        <td><?= rating_badge($kpi['emp_rating'] !== null ? (int)$kpi['emp_rating'] : null) ?></td>
                        <td>
                            <select name="kpi[<?= $kpi['kpi_id'] ?>][mgr_rating]" class="form-control rating-select">
                                <option value="">-</option>
                                <?php for ($r = 1; $r <= 5; $r++): ?>
                                <option value="<?= $r ?>" <?= ((int)$kpi['mgr_rating'] === $r) ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        <td>
                            <select name="kpi[<?= $kpi['kpi_id'] ?>][agreed_rating]" class="form-control rating-select">
                                <option value="">-</option>
                                <?php for ($r = 1; $r <= 5; $r++): ?>
                                <option value="<?= $r ?>" <?= ((int)$kpi['agreed_rating'] === $r) ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- KRA-level comment & agreed rating -->
            <div class="va-review-grid">
                <div class="form-group">
                    <label class="form-label">Manager Comment (KRA <?= $i+1 ?>)</label>
                    <textarea name="kra[<?= $kra['kra_id'] ?>][mgr_comments]" class="form-control" rows="3"><?= htmlspecialchars($kra['mgr_comments'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">KRA Agreed Rating</label>
                    <select name="kra[<?= $kra['kra_id'] ?>][agreed_rating]" class="form-control rating-select">
                        <option value=""> Select </option>
                        <?php for ($r = 1; $r <= 5; $r++): ?>
                        <option value="<?= $r ?>" <?= ((int)($kra['agreed_rating'] ?? 0) === $r) ? 'selected' : '' ?>><?= $r ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Overall manager comment -->
            <div class="form-group va-overall">
                <label class="form-label">Overall Manager Comment</label>
                <textarea name="mgr_overall_comment" class="form-control" rows="4" placeholder="Summarise your overall assessment&hellip;"><?= htmlspecialchars($appraisal['mgr_overall_comment'] ?? '') ?></textarea>
            </div>

            <div class="actions va-review-actions">
                <button type="submit" name="save_review" class="btn btn-secondary">
                    <i class="fas fa-save" aria-hidden="true"></i> Save Draft Review
                </button>
                <button type="submit" name="approve_appraisal" class="btn btn-primary"
                    onclick="return confirm('Approve and finalise this appraisal?')">
                    <i class="fas fa-check" aria-hidden="true"></i> Approve Appraisal
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Audit / Timeline -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-history" aria-hidden="true"></i> Timeline</h3></div>
    <div class="card-body">
        <div class="va-timeline">
            <div class="va-tl-item">
                <div class="va-tl-dot done"><i class="fas fa-plus" aria-hidden="true"></i></div>
                <div>
                    <div class="va-tl-title">Appraisal Created</div>
                    <div class="va-tl-time"><?= fmt_date($appraisal['created_at']) ?></div>
                </div>
            </div>
            <?php if ($appraisal['submitted_at']): ?>
            <div class="va-tl-item">
                <div class="va-tl-dot done"><i class="fas fa-paper-plane" aria-hidden="true"></i></div>
                <div>
                    <div class="va-tl-title">Submitted for Review</div>
                    <div class="va-tl-time"><?= fmt_date($appraisal['submitted_at']) ?></div>
                </div>
            </div>
            <?php elseif ($status === 'draft'): ?>
            <div class="va-tl-item">
                <div class="va-tl-dot pending"><i class="fas fa-clock" aria-hidden="true"></i></div>
                <div>
                    <div class="va-tl-title">Awaiting Submission</div>
                    <div class="va-tl-time">Not yet submitted</div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($appraisal['reviewed_at']): ?>
            <div class="va-tl-item">
                <div class="va-tl-dot done"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
                <div>
                    <div class="va-tl-title">Reviewed by Supervisor</div>
                    <div class="va-tl-time"><?= fmt_date($appraisal['reviewed_at']) ?></div>
                </div>
            </div>
            <?php elseif (in_array($status, ['pending_review'])): ?>
            <div class="va-tl-item">
                <div class="va-tl-dot pending"><i class="fas fa-eye" aria-hidden="true"></i></div>
                <div>
                    <div class="va-tl-title">Pending Supervisor Review</div>
                    <div class="va-tl-time">In progress</div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($appraisal['updated_at'] && $appraisal['updated_at'] !== $appraisal['created_at']): ?>
            <div class="va-tl-item">
                <div class="va-tl-dot"><i class="fas fa-sync-alt" aria-hidden="true"></i></div>
                <div>
                    <div class="va-tl-title">Last Updated</div>
                    <div class="va-tl-time"><?= fmt_date($appraisal['updated_at']) ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>
