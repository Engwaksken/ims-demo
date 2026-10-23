<?php
ob_start();
session_start();
require_once 'includes/config.php';

/* -----------------------------------------------
   AUTH GUARD
----------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

$uid     = (int)$_SESSION['user_id'];
$plan_id = (int)($_GET['id'] ?? 0);

if ($plan_id <= 0) {
    $_SESSION['activity_error'] = 'No activity plan specified.';
    header('Location: my-activities');
    exit();
}

/* -----------------------------------------------
   HELPERS
----------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date(?string $d): string
{
    if (!$d || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') {
        return '-';
    }
    return date('d M Y', strtotime($d));
}

function progress_color(int $pct): string
{
    if ($pct >= 100) return 'var(--green-fg)';
    if ($pct >= 60)  return 'var(--brand-500)';
    if ($pct >= 30)  return 'var(--amber-fg)';
    return 'var(--red-fg)';
}

function quarter_label(string $q): string
{
    return strtoupper($q);
}

/* -----------------------------------------------
   LOAD PLAN
----------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        ap.*,
        kc.category_name,
        u.full_name AS owner_display_name,
        ed.supervisor_id,
        su.full_name AS supervisor_name
    FROM activity_plans ap
    LEFT JOIN kpi_categories kc
        ON kc.category_id = ap.category_id
    LEFT JOIN users u
        ON u.user_id = ap.user_id
    LEFT JOIN employee_directory ed
        ON ed.user_id = ap.user_id
    LEFT JOIN users su
        ON su.user_id = ed.supervisor_id
    WHERE ap.plan_id = ?
      AND (
            ap.user_id = ?
         OR ed.supervisor_id = ?
      )
    LIMIT 1
");
if (!$stmt) {
    die('Prepare failed: ' . h($conn->error));
}

$stmt->bind_param('iii', $plan_id, $uid, $uid);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$plan) {
    $_SESSION['activity_error'] = 'Activity plan not found or access denied.';
    header('Location: my-activities');
    exit();
}

$is_owner      = ((int)$plan['user_id'] === $uid);
$is_supervisor = ((int)($plan['supervisor_id'] ?? 0) === $uid);
$status        = (string)($plan['status'] ?? 'draft');

/* -----------------------------------------------
   FETCH ACTIVITIES
----------------------------------------------- */
$stmt = $conn->prepare("
    SELECT *
    FROM plan_activities
    WHERE plan_id = ?
    ORDER BY sort_order ASC, activity_id ASC
");
if (!$stmt) {
    die('Prepare failed: ' . h($conn->error));
}

$stmt->bind_param('i', $plan_id);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activity_ids = array_map('intval', array_column($activities, 'activity_id'));

/* -----------------------------------------------
   FETCH WEEKLY ENTRIES
----------------------------------------------- */
$entries_by_activity = [];
$all_entries = [];

if (!empty($activity_ids)) {
    $placeholders = implode(',', array_fill(0, count($activity_ids), '?'));
    $sql = "
        SELECT *
        FROM activity_weekly_entries
        WHERE plan_id = ?
          AND activity_id IN ($placeholders)
        ORDER BY activity_id ASC, quarter ASC, week_number ASC, entry_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Prepare failed: ' . h($conn->error));
    }

    $types = 'i' . str_repeat('i', count($activity_ids));
    $params = array_merge([$types, $plan_id], $activity_ids);

    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $all_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($all_entries as $entry) {
        $aid = (int)$entry['activity_id'];
        $entries_by_activity[$aid][] = $entry;
    }
}

/* -----------------------------------------------
   AGGREGATE STATS PER ACTIVITY
----------------------------------------------- */
foreach ($activities as &$act) {
    $aid = (int)$act['activity_id'];
    $entries = $entries_by_activity[$aid] ?? [];

    $act['entry_count'] = count($entries);
    $act['total_actual'] = 0;

    foreach ($entries as $entry) {
        $act['total_actual'] += (float)($entry['actual_value'] ?? 0);
    }

    $target = (float)($act['annual_target'] ?? 0);
    $actual = (float)$act['total_actual'];

    $act['progress_pct'] = $target > 0
        ? min(100, (int)round(($actual / $target) * 100))
        : 0;
}
unset($act);

/* -----------------------------------------------
   PLAN LEVEL STATS
----------------------------------------------- */
$total_activities = count($activities);
$total_entries    = count($all_entries);
$avg_progress     = $total_activities > 0
    ? (int)round(array_sum(array_column($activities, 'progress_pct')) / $total_activities)
    : 0;

$total_weight = 0.0;
foreach ($activities as $act) {
    $total_weight += (float)($act['weight'] ?? 0);
}

/* -----------------------------------------------
   STATUS META
----------------------------------------------- */
$status_map = [
    'draft'      => ['Draft', 'status-draft'],
    'submitted'  => ['Submitted', 'status-submitted'],
    'approved'   => ['Approved', 'status-approved'],
    'completed'  => ['Completed', 'status-completed'],
    'rejected'   => ['Rejected', 'status-rejected'],
];
$status_meta = $status_map[$status] ?? [ucfirst($status), 'status-draft'];

$page_title = 'View Activity Plan';
include 'includes/header.php';
?>
<style>
/* View Activity Plan: page-only layout (va- prefix) */
.va-hero-meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 8px; font-size: var(--fs-sm); opacity: .9; }
.va-hero-meta .status-pill { opacity: 1; }
.va-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.va-info-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--ink-300); margin-bottom: 3px; display: block; }
.va-info-value { font-size: var(--fs-base); font-weight: 600; color: var(--ink-700); }
.va-mgr-comment { margin: 18px 0 0; }
.va-mgr-comment .va-comment-body { margin-top: 4px; line-height: 1.65; }
.va-good { color: var(--green-fg); }
.va-warn { color: var(--amber-fg); }

.va-page .activity-list { display: flex; flex-direction: column; gap: 12px; padding: 16px; }
.va-page .activity-card { border: 1px solid var(--ink-100); border-radius: var(--radius-md); overflow: hidden; }
.va-page .activity-head {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    padding: 14px 16px; background: var(--surface-hover);
    border-bottom: 1px solid var(--ink-100); cursor: pointer;
}
.va-act-num {
    width: 28px; height: 28px; border-radius: var(--radius-sm);
    background: var(--brand-50); color: var(--brand-600);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 800; flex-shrink: 0;
}
.va-act-title-block { flex: 1; min-width: 180px; }
.va-act-title { font-weight: 700; color: var(--ink-700); }
.va-act-meta { font-size: 11px; color: var(--ink-300); display: flex; flex-wrap: wrap; gap: 10px; margin-top: 3px; }
.va-progress { display: flex; align-items: center; gap: 10px; }
.va-page .progress-bar-wrap { width: 110px; height: 6px; background: var(--ink-100); border-radius: var(--radius-pill); overflow: hidden; }
.va-page .progress-bar-fill { height: 100%; border-radius: var(--radius-pill); transition: width .4s ease; }
.va-progress-label { font-size: var(--fs-xs); font-weight: 700; min-width: 38px; text-align: right; }
.va-page .activity-detail { display: none; }
.va-page .activity-detail.open { display: block; }
.va-detail-section { padding: 16px; border-bottom: 1px solid var(--ink-100); }
.va-detail-section:last-child { border-bottom: none; }
.va-detail-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--ink-300); margin-bottom: 6px; }
.va-detail-text { font-size: var(--fs-sm); color: var(--ink-500); line-height: 1.65; }
.va-page .act-chevron { transition: transform .2s; color: var(--ink-300); }
.va-page .act-chevron.open { transform: rotate(180deg); }
.va-review-table { margin-bottom: 20px; }
.va-review-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
.va-inline-form { display: inline; }

@media (max-width: 560px) { .va-page .progress-bar-wrap { width: 70px; } }
</style>

<div class="page-stack va-page">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-clipboard-list" aria-hidden="true"></i> <?= h($plan['plan_title']) ?></h1>
            <p>Activity Plan #<?= $plan_id ?>: activities, weights and weekly progress.</p>
            <div class="va-hero-meta">
                <span class="status-pill <?= h($status_meta[1]) ?>"><?= h($status_meta[0]) ?></span>
                <?php if (!empty($plan['category_name'])): ?>
                    <span><?= h($plan['category_name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($plan['fiscal_year'])): ?>
                    <span>FY <?= h($plan['fiscal_year']) ?></span>
                <?php endif; ?>
                <span><?= number_format($total_weight, 1) ?>% total weight</span>
            </div>
        </div>

        <div class="hero-actions">
            <a href="my-activities" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Activity Plans</a>
            <?php if ($is_owner && $status === 'draft'): ?>
                <a href="edit-activity?id=<?= $plan_id ?>" class="btn"><i class="fas fa-pen" aria-hidden="true"></i> Edit Plan</a>

                <form method="POST" action="includes/activity-process.php" class="va-inline-form">
                    <input type="hidden" name="action" value="submit_activity_plan">
                    <input type="hidden" name="plan_id" value="<?= $plan_id ?>">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this activity plan for supervisor review?')">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit for Review
                    </button>
                </form>
            <?php endif; ?>

            <button onclick="window.print()" class="btn"><i class="fas fa-print" aria-hidden="true"></i> Print</button>
        </div>
    </div>

    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-list-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Activities</div>
                <div class="stat-value"><?= $total_activities ?></div>
                <div class="stat-sub">defined in plan</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-calendar-week" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Weekly Entries</div>
                <div class="stat-value"><?= $total_entries ?></div>
                <div class="stat-sub">recorded updates</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon <?= abs($total_weight - 100) < 1 ? 'bg-green' : 'bg-amber' ?>"><i class="fas fa-scale-balanced" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Weight</div>
                <div class="stat-value <?= abs($total_weight - 100) < 1 ? 'va-good' : 'va-warn' ?>"><?= number_format($total_weight, 1) ?>%</div>
                <div class="stat-sub"><?= abs($total_weight - 100) < 1 ? 'balanced' : 'target 100%' ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Avg Progress</div>
                <div class="stat-value" style="color:<?= progress_color($avg_progress) ?>"><?= $avg_progress ?>%</div>
                <div class="stat-sub">overall completion</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-circle-info" aria-hidden="true"></i> Plan Details</h3></div>
        <div class="card-body">
            <div class="va-info-grid">
                <div>
                    <div class="va-info-label">Plan Owner</div>
                    <div class="va-info-value"><?= h($plan['owner_display_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="va-info-label">Department</div>
                    <div class="va-info-value"><?= h($plan['department_id'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="va-info-label">Category</div>
                    <div class="va-info-value"><?= h($plan['category_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="va-info-label">Fiscal Year</div>
                    <div class="va-info-value"><?= h($plan['fiscal_year'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="va-info-label">Supervisor</div>
                    <div class="va-info-value"><?= h($plan['supervisor_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="va-info-label">Created</div>
                    <div class="va-info-value"><?= fmt_date($plan['created_at'] ?? null) ?></div>
                </div>
                <?php if (!empty($plan['submitted_at'])): ?>
                    <div>
                        <div class="va-info-label">Submitted</div>
                        <div class="va-info-value"><?= fmt_date($plan['submitted_at']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($plan['updated_at'])): ?>
                    <div>
                        <div class="va-info-label">Updated</div>
                        <div class="va-info-value"><?= fmt_date($plan['updated_at']) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($plan['mgr_overall_comment'])): ?>
                <div class="alert alert-warning va-mgr-comment">
                    <div>
                        <strong>Manager Overall Comment</strong>
                        <div class="va-comment-body"><?= nl2br(h($plan['mgr_overall_comment'])) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list-check" aria-hidden="true"></i> Activities &amp; Weekly Progress</h3>
        </div>

        <?php if (empty($activities)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard" aria-hidden="true"></i>
                <p>No activities recorded for this plan.</p>
            </div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($activities as $i => $act): ?>
                    <?php
                    $pct = (int)$act['progress_pct'];
                    $pcolor = progress_color($pct);
                    $entries = $entries_by_activity[(int)$act['activity_id']] ?? [];
                    ?>
                    <div class="activity-card">
                        <div class="activity-head" onclick="toggleActivity(this)">
                            <span class="va-act-num"><?= $i + 1 ?></span>

                            <div class="va-act-title-block">
                                <div class="va-act-title"><?= h($act['description']) ?></div>
                                <div class="va-act-meta">
                                    <?php if (!empty($act['unit_of_measure'])): ?>
                                        <span>Unit: <?= h($act['unit_of_measure']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((float)($act['annual_target'] ?? 0) > 0): ?>
                                        <span>Target: <strong><?= number_format((float)$act['annual_target'], 1) ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ((float)$act['total_actual'] > 0): ?>
                                        <span class="va-good">Actual: <strong><?= number_format((float)$act['total_actual'], 1) ?></strong></span>
                                    <?php endif; ?>
                                    <span><?= (int)$act['entry_count'] ?> entr<?= (int)$act['entry_count'] !== 1 ? 'ies' : 'y' ?></span>
                                </div>
                            </div>

                            <?php if ((float)($act['weight'] ?? 0) > 0): ?>
                                <span class="badge badge-warning">
                                    <?= number_format((float)$act['weight'], 1) ?>%
                                </span>
                            <?php endif; ?>

                            <div class="va-progress">
                                <div class="progress-bar-wrap">
                                    <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $pcolor ?>"></div>
                                </div>
                                <span class="va-progress-label" style="color:<?= $pcolor ?>"><?= $pct ?>%</span>
                            </div>

                            <span class="act-chevron"><i class="fa fa-arrow-down" aria-hidden="true"></i></span>
                        </div>

                        <div class="activity-detail">
                            <?php if (!empty($act['key_result'])): ?>
                                <div class="va-detail-section">
                                    <div class="va-detail-label">Key Result</div>
                                    <div class="va-detail-text"><?= nl2br(h($act['key_result'])) ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($act['mgr_comment'])): ?>
                                <div class="va-detail-section">
                                    <div class="va-detail-label">Manager Comment</div>
                                    <div class="va-detail-text"><?= nl2br(h($act['mgr_comment'])) ?></div>
                                </div>
                            <?php endif; ?>

                            <div class="va-detail-section">
                                <div class="va-detail-label">Weekly Entries (<?= count($entries) ?>)</div>

                                <?php if (empty($entries)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-calendar-xmark" aria-hidden="true"></i>
                                        <p>No weekly entries recorded yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Quarter</th>
                                                    <th>Week</th>
                                                    <th>Actual Value</th>
                                                    <th>Comment</th>
                                                    <th>Logged</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($entries as $ei => $entry): ?>
                                                <tr>
                                                    <td><?= $ei + 1 ?></td>
                                                    <td><?= h(quarter_label((string)$entry['quarter'])) ?></td>
                                                    <td>Week <?= (int)$entry['week_number'] ?></td>
                                                    <td>
                                                        <?= number_format((float)$entry['actual_value'], 1) ?>
                                                        <?php if (!empty($act['unit_of_measure'])): ?>
                                                            <?= h($act['unit_of_measure']) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($entry['comment'])): ?>
                                                            <?= h($entry['comment']) ?>
                                                        <?php else: ?>
                                                            <span class="cell-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= fmt_date($entry['created_at'] ?? null) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($is_supervisor && $status === 'submitted'): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user-check" aria-hidden="true"></i> Supervisor Review</h3></div>
            <div class="card-body">
                <form method="POST" action="includes/activity-review-process.php">
                    <input type="hidden" name="action" value="review_activity_plan">
                    <input type="hidden" name="plan_id" value="<?= $plan_id ?>">

                    <?php if (!empty($activities)): ?>
                        <div class="table-responsive va-review-table">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Activity</th>
                                        <th>Target</th>
                                        <th>Actual</th>
                                        <th>Progress</th>
                                        <th>Manager Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td><?= h($act['description']) ?></td>
                                        <td><?= (float)($act['annual_target'] ?? 0) > 0 ? number_format((float)$act['annual_target'], 1) : '-' ?></td>
                                        <td><?= number_format((float)$act['total_actual'], 1) ?></td>
                                        <td><?= (int)$act['progress_pct'] ?>%</td>
                                        <td>
                                            <textarea name="activity[<?= (int)$act['activity_id'] ?>][mgr_comment]" class="form-control" rows="2"><?= h($act['mgr_comment'] ?? '') ?></textarea>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="va-info-label">Overall Manager Comment</label>
                        <textarea name="mgr_overall_comment" class="form-control" rows="4"><?= h($plan['mgr_overall_comment'] ?? '') ?></textarea>
                    </div>

                    <div class="va-review-actions">
                        <button type="submit" name="save_review" class="btn btn-secondary">Save Draft Review</button>
                        <button type="submit" name="request_changes" class="btn btn-warning">Request Changes</button>
                        <button type="submit" name="approve_plan" class="btn btn-primary" onclick="return confirm('Approve this activity plan?')">Approve Plan</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<script>
function toggleActivity(headerEl) {
    const card = headerEl.closest('.activity-card');
    const detail = card.querySelector('.activity-detail');
    const chev = card.querySelector('.act-chevron');
    const isOpen = detail.classList.contains('open');

    detail.classList.toggle('open', !isOpen);
    chev.classList.toggle('open', !isOpen);
}

document.addEventListener('DOMContentLoaded', function () {
    const cards = document.querySelectorAll('.activity-card');
    if (cards.length === 1) {
        const head = cards[0].querySelector('.activity-head');
        if (head) toggleActivity(head);
    }

    document.querySelectorAll('.progress-bar-fill').forEach(bar => {
        const target = bar.style.width;
        bar.style.width = '0';
        requestAnimationFrame(() => {
            setTimeout(() => {
                bar.style.width = target;
            }, 80);
        });
    });
});
</script>
<?php ob_end_flush(); ?>