<?php
ob_start();
session_start();
require_once 'includes/config.php';

/* ----------------------------------------------------------
   AUTH GUARD
---------------------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$uid     = (int)$_SESSION['user_id'];
$plan_id = (int)($_GET['id'] ?? 0);

if ($plan_id <= 0) {
    $_SESSION['activity_error'] = 'No activity plan specified.';
    header('Location: my-activities');
    exit();
}

/* ----------------------------------------------------------
   HELPERS
---------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ----------------------------------------------------------
   LOAD PLAN
---------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT ap.*, kc.category_name
    FROM activity_plans ap
    LEFT JOIN kpi_categories kc ON kc.category_id = ap.category_id
    WHERE ap.plan_id = ?
      AND ap.user_id = ?
    LIMIT 1
");
if (!$stmt) {
    die('Prepare failed: ' . h($conn->error));
}

$stmt->bind_param('ii', $plan_id, $uid);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$plan) {
    $_SESSION['activity_error'] = 'Activity plan not found or access denied.';
    header('Location: my-activities');
    exit();
}

if (!in_array(($plan['status'] ?? 'draft'), ['draft', 'rejected'], true)) {
    $_SESSION['activity_error'] = 'Only draft or rejected activity plans can be edited.';
    header('Location: view-activity?id=' . $plan_id);
    exit();
}

/* ----------------------------------------------------------
   LOAD CATEGORIES
---------------------------------------------------------- */
$categories = [];
$res = $conn->query("
    SELECT category_id, category_name
    FROM kpi_categories
    ORDER BY category_name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

/* ----------------------------------------------------------
   LOAD ACTIVITIES
---------------------------------------------------------- */
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

/* ----------------------------------------------------------
   LOAD WEEKLY ENTRIES
---------------------------------------------------------- */
$weekly_map = []; // [activity_id][quarter][w1..w12] = ['actual'=>..., 'comment'=>...]

if (!empty($activity_ids)) {
    $placeholders = implode(',', array_fill(0, count($activity_ids), '?'));
    $sql = "
        SELECT *
        FROM activity_weekly_entries
        WHERE plan_id = ?
          AND activity_id IN ($placeholders)
        ORDER BY activity_id ASC, quarter ASC, week_number ASC
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
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($entries as $entry) {
        $aid = (int)$entry['activity_id'];
        $quarter = (string)$entry['quarter'];
        $weekKey = 'w' . (int)$entry['week_number'];

        $weekly_map[$aid][$quarter][$weekKey] = [
            'actual'  => $entry['actual_value'],
            'comment' => $entry['comment'],
        ];
    }
}

/* ----------------------------------------------------------
   PAGE
---------------------------------------------------------- */
$page_title = 'Edit Activity Plan';
include 'includes/header.php';
?>
<style>
.edit-activity-page .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.edit-activity-page .grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.edit-activity-page .form-label{display:block;font-size:12px;font-weight:700;color:var(--ink-300);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.edit-activity-page textarea.form-control{min-height:90px;resize:vertical}
.edit-activity-page .activities-wrap{display:flex;flex-direction:column;gap:16px}
.edit-activity-page .activity-card{border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;background:var(--surface-card)}
.edit-activity-page .activity-head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--ink-50);border-bottom:1px solid var(--border)}
.edit-activity-page .activity-title{font-weight:700;color:var(--ink-700)}
.edit-activity-page .activity-body{padding:16px}
.edit-activity-page .row-actions{display:flex;gap:8px;flex-wrap:wrap}
.edit-activity-page .weekly-block{margin-top:16px;border-top:1px dashed var(--border);padding-top:16px}
.edit-activity-page .quarter-box{margin-bottom:18px;border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden}
.edit-activity-page .quarter-head{background:var(--ink-50);padding:10px 12px;font-weight:700;border-bottom:1px solid var(--border)}
.edit-activity-page .week-grid{overflow-x:auto}
.edit-activity-page .week-table{width:100%;border-collapse:collapse;font-size:13px}
.edit-activity-page .week-table th,.edit-activity-page .week-table td{border-bottom:1px solid var(--ink-50);padding:8px 10px;text-align:left;vertical-align:top}
.edit-activity-page .week-table th{background:var(--ink-50);font-size:11px;text-transform:uppercase;color:var(--ink-300)}
.edit-activity-page .week-input{width:100%;padding:8px 9px;border:1px solid var(--border);border-radius:var(--radius-sm)}
.edit-activity-page .form-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}
@media (max-width:900px){
    .edit-activity-page .grid,.edit-activity-page .grid-2{grid-template-columns:1fr}
}
</style>

<div class="page-stack edit-activity-page">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-pen-to-square" aria-hidden="true"></i> Edit Activity Plan</h1>
            <p>Update your draft plan, activities, weights, and weekly targets before submitting for review.</p>
        </div>
        <div class="hero-actions">
            <a href="view-activity?id=<?= $plan_id ?>" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Activity Plan</a>
        </div>
    </div>

    <?php if (!empty($_SESSION['activity_error'])): ?>
        <div class="alert alert-danger">
            <?= h($_SESSION['activity_error']) ?>
        </div>
        <?php unset($_SESSION['activity_error']); ?>
    <?php endif; ?>

    <form method="POST" action="includes/activity-update-process.php" id="activityForm">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="plan_id" value="<?= $plan_id ?>">
        <input type="hidden" name="department_id" value="<?= (int)$plan['department_id'] ?>">

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-sliders" aria-hidden="true"></i> Plan Details</h3></div>
            <div class="card-body">
                <div class="grid">
                    <div class="form-group">
                        <label class="form-label">Plan Title</label>
                        <input type="text" name="plan_title" class="form-control" value="<?= h($plan['plan_title']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['category_id'] ?>" <?= (int)$plan['category_id'] === (int)$cat['category_id'] ? 'selected' : '' ?>>
                                    <?= h($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fiscal Year</label>
                        <input type="number" name="fiscal_year" class="form-control" min="2000" max="2100" value="<?= (int)$plan['fiscal_year'] ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list-check" aria-hidden="true"></i> Activities</h3>
                <button type="button" class="btn btn-soft btn-sm" onclick="addActivity()"><i class="fas fa-plus" aria-hidden="true"></i> Add Activity</button>
            </div>
            <div class="card-body">
                <div id="activitiesWrap" class="activities-wrap"></div>
            </div>
        </div>

        <div class="form-actions">
            <a href="view-activity?id=<?= $plan_id ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-secondary">Save Draft</button>
            <button type="submit" name="submit_plan" value="1" class="btn btn-primary" onclick="return confirm('Submit this activity plan for review?')">Save & Submit</button>
        </div>
    </form>
</div>

<script>
const activitiesWrap = document.getElementById('activitiesWrap');
let activityIndex = 0;

const existingActivities = <?= json_encode($activities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const existingWeekly = <?= json_encode($weekly_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function weekRowHtml(idx, quarter, weekNo, weeklyData = {}) {
    const weekKey = 'w' + weekNo;
    const item = weeklyData[weekKey] || {};
    const actual = item.actual ?? '';
    const comment = item.comment ?? '';

    return `
        <tr>
            <td>Week ${weekNo}</td>
            <td>
                <input type="number" step="0.01"
                       name="weekly[${quarter}][${idx}][${weekKey}][actual]"
                       class="week-input"
                       value="${escapeHtml(String(actual))}">
            </td>
            <td>
                <input type="text"
                       name="weekly[${quarter}][${idx}][${weekKey}][comment]"
                       class="week-input"
                       value="${escapeHtml(String(comment))}">
            </td>
        </tr>
    `;
}

function quarterHtml(idx, quarter, weeklyData = {}) {
    let rows = '';
    for (let w = 1; w <= 12; w++) {
        rows += weekRowHtml(idx, quarter, w, weeklyData);
    }

    return `
        <div class="quarter-box">
            <div class="quarter-head">${quarter.toUpperCase()}</div>
            <div class="week-grid">
                <table class="week-table">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Actual</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>
    `;
}

function activityCardHtml(idx, data = {}, weeklyData = {}) {
    const description = data.description ?? '';
    const keyResult = data.key_result ?? '';
    const weight = data.weight ?? '';
    const unit = data.unit_of_measure ?? '';
    const annualTarget = data.annual_target ?? '';

    return `
        <div class="activity-card" data-idx="${idx}">
            <div class="activity-head">
                <div class="activity-title">Activity #${idx + 1}</div>
                <div class="row-actions">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeActivity(${idx})">Remove</button>
                </div>
            </div>
            <div class="activity-body">
                <div class="form-group">
                    <label class="form-label">Activity Description</label>
                    <textarea name="activities[${idx}][description]" class="form-control" required>${escapeHtml(String(description))}</textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Key Result</label>
                    <textarea name="activities[${idx}][key_result]" class="form-control" required>${escapeHtml(String(keyResult))}</textarea>
                </div>

                <div class="grid">
                    <div class="form-group">
                        <label class="form-label">Weight (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="activities[${idx}][weight]" class="form-control" value="${escapeHtml(String(weight))}" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Unit of Measure</label>
                        <input type="text" name="activities[${idx}][unit]" class="form-control" value="${escapeHtml(String(unit))}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Annual Target</label>
                        <input type="number" step="0.01" min="0" name="activities[${idx}][annual_target]" class="form-control" value="${escapeHtml(String(annualTarget))}">
                    </div>
                </div>

                <div class="weekly-block">
                    <div class="form-label">Weekly Entries / Targets</div>
                    ${quarterHtml(idx, 'q1', weeklyData.q1 || {})}
                    ${quarterHtml(idx, 'q2', weeklyData.q2 || {})}
                    ${quarterHtml(idx, 'q3', weeklyData.q3 || {})}
                    ${quarterHtml(idx, 'q4', weeklyData.q4 || {})}
                </div>
            </div>
        </div>
    `;
}

function renderActivities() {
    activitiesWrap.innerHTML = '';
    document.querySelectorAll('.activity-card').forEach((card, i) => {
        const title = card.querySelector('.activity-title');
        if (title) title.textContent = 'Activity #' + (i + 1);
    });
}

function addActivity(data = {}, weeklyData = {}) {
    const idx = activityIndex++;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = activityCardHtml(idx, data, weeklyData);
    activitiesWrap.appendChild(wrapper.firstElementChild);
    refreshActivityTitles();
}

function removeActivity(idx) {
    const card = activitiesWrap.querySelector(`.activity-card[data-idx="${idx}"]`);
    if (card) {
        card.remove();
        refreshActivityTitles();
    }
}

function refreshActivityTitles() {
    const cards = activitiesWrap.querySelectorAll('.activity-card');
    cards.forEach((card, i) => {
        const title = card.querySelector('.activity-title');
        if (title) title.textContent = 'Activity #' + (i + 1);
    });
}

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function () {
    if (existingActivities.length > 0) {
        existingActivities.forEach(act => {
            const aid = String(act.activity_id);
            addActivity(act, existingWeekly[aid] || {});
        });
    } else {
        addActivity();
    }
});
</script>

<?php
include 'includes/footer.php';
ob_end_flush();
?>