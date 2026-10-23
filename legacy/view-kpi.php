<?php

$page_title = 'View KPI';
include 'includes/header.php';

// Get KPI ID
$kpi_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$kpi_id) {
    send_notification($_SESSION['user_id'], 'Invalid KPI ID', 'danger');
    header("Location: my-kpis");
    exit();
}

// Fetch KPI details
$query = "SELECT k.*, c.category_name, c.category_code, d.department_name,
    u.full_name as owner_name, u.email as owner_email,
    reviewer.full_name as reviewer_name,
    approver.full_name as approver_name
    FROM kpis k
    LEFT JOIN kpi_categories c ON k.category_id = c.category_id
    LEFT JOIN departments d ON k.department_id = d.department_id
    LEFT JOIN users u ON k.user_id = u.user_id
    LEFT JOIN users reviewer ON k.reviewed_by = reviewer.user_id
    LEFT JOIN users approver ON k.approved_by = approver.user_id
    WHERE k.kpi_id = $kpi_id";
$result = $conn->query($query);

if (!$result || $result->num_rows == 0) {
    send_notification($_SESSION['user_id'], 'KPI not found', 'danger');
    header("Location: my-kpis");
    exit();
}

$kpi = $result->fetch_assoc();

// Check permissions (owner or HR can view)
$is_owner = $kpi['user_id'] == $_SESSION['user_id'];
$is_hr = ims_allowed('kpis.view_all', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR'], "View any staff member's KPI (not just their own)");
$can_review_kpi = ims_allowed('kpis.approve', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR'], 'Approve or reject KPIs');

if (!$is_owner && !$is_hr) {
    send_notification($_SESSION['user_id'], 'You do not have permission to view this KPI', 'danger');
    header("Location: my-kpis");
    exit();
}

// Fetch comments
$comments = [];
$comment_query = "SELECT c.*, u.full_name, u.email 
    FROM kpi_comments c
    LEFT JOIN users u ON c.user_id = u.user_id
    WHERE c.kpi_id = $kpi_id
    ORDER BY c.created_at DESC";
$comment_result = $conn->query($comment_query);
while ($row = $comment_result->fetch_assoc()) {
    $comments[] = $row;
}

// Fetch review history
$history = [];
$history_query = "SELECT h.*, u.full_name 
    FROM kpi_review_history h
    LEFT JOIN users u ON h.reviewer_id = u.user_id
    WHERE h.kpi_id = $kpi_id
    ORDER BY h.reviewed_at DESC";
$history_result = $conn->query($history_query);
while ($row = $history_result->fetch_assoc()) {
    $history[] = $row;
}

// Calculate progress
$quarters_completed = 0;
$total_achievement = 0;
foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
    if ($kpi["{$q}_achievement"] !== null) {
        $quarters_completed++;
        $total_achievement += $kpi["{$q}_achievement"];
    }
}
$avg_achievement = $quarters_completed > 0 ? round($total_achievement / $quarters_completed, 1) : 0;
?>

<style>
.vk-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.vk-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--ink-400);
    font-size: 14px;
}

.vk-info-row {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--ink-50);
}

.vk-info-row:last-child { border-bottom: none; }

.vk-info-label {
    width: 200px;
    font-weight: 600;
    color: var(--ink-400);
    flex-shrink: 0;
}

.vk-info-value {
    flex: 1;
    min-width: 0;
    color: var(--ink-900);
    overflow-wrap: anywhere;
}

.alert .vk-info-row {
    border: none;
    padding: 5px 0;
}

.alert .vk-info-label,
.alert .vk-info-value { color: inherit; }

.vk-alert-body { flex: 1; min-width: 0; }

.vk-alert-body h4 { margin: 0 0 8px; }

.vk-alert-body p { margin: 0 0 5px; }

.vk-alert-body hr {
    border: none;
    border-top: 1px solid currentColor;
    opacity: 0.2;
    margin: 10px 0;
}

.vk-quarter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.vk-quarter {
    background: var(--surface-hover);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 20px;
    transition: box-shadow 0.2s, transform 0.2s;
}

.vk-quarter:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.vk-quarter-head {
    font-size: 16px;
    font-weight: 600;
    color: var(--brand-600);
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.vk-quarter-period {
    font-size: 12px;
    font-weight: 400;
    color: var(--ink-300);
}

.vk-metric { margin-bottom: 12px; }

.vk-metric-label {
    font-size: 12px;
    color: var(--ink-300);
    text-transform: uppercase;
    margin-bottom: 3px;
}

.vk-metric-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--ink-900);
}

.vk-metric-value.is-recorded { color: var(--green-fg); }
.vk-metric-value.is-missing { color: var(--ink-200); }

.vk-ring {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: conic-gradient(var(--green-fg) 0% var(--progress), var(--ink-50) var(--progress) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 15px auto;
}

.vk-ring-inner {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: var(--surface-card);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 700;
    color: var(--green-fg);
}

.vk-verdict {
    text-align: center;
    font-size: 12px;
}

.vk-verdict.is-exceeded { color: var(--green-fg); }
.vk-verdict.is-on-track { color: var(--amber-fg); }
.vk-verdict.is-below { color: var(--red-fg); }

.vk-comment {
    background: var(--surface-hover);
    border: 1px solid var(--border);
    border-left: 4px solid var(--brand-500);
    border-radius: var(--radius-sm);
    padding: 15px;
}

.vk-comment + .vk-comment { margin-top: 15px; }

.vk-comment-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.vk-comment-author {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-weight: 600;
    color: var(--ink-900);
}

.vk-comment-meta {
    font-size: 12px;
    color: var(--ink-300);
}

.vk-comment-text {
    color: var(--ink-700);
    line-height: 1.6;
}

.vk-timeline {
    position: relative;
    padding-left: 30px;
}

.vk-timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--ink-50);
}

.vk-timeline-item {
    position: relative;
    padding-bottom: 20px;
}

.vk-timeline-item:last-child { padding-bottom: 0; }

.vk-timeline-dot {
    position: absolute;
    left: -24px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--brand-500);
    border: 3px solid var(--surface-card);
    box-shadow: 0 0 0 2px var(--brand-500);
}

.vk-timeline-content {
    background: var(--surface-hover);
    border: 1px solid var(--border);
    padding: 12px;
    border-radius: var(--radius-sm);
}

.vk-timeline-head {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 5px;
}

.vk-timeline-date {
    font-size: 12px;
    color: var(--ink-300);
}

.vk-timeline-by {
    font-size: 14px;
    color: var(--ink-400);
    margin-bottom: 5px;
}

.vk-timeline-notes {
    color: var(--ink-700);
    margin-top: 8px;
}

.vk-modal-sm { max-width: 500px; }
.vk-modal-md { max-width: 600px; }

.vk-modal-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--ink-900);
}

.vk-approve-head h3 i { color: var(--green-fg); }
.vk-reject-head h3 i { color: var(--red-fg); }

@media (max-width: 640px) {
    .vk-info-row { flex-direction: column; gap: 4px; }
    .vk-info-label { width: auto; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-bullseye" aria-hidden="true"></i> <?php echo htmlspecialchars($kpi['kpi_title']); ?></h1>
        <p><?php echo $kpi['kpi_description'] !== null && $kpi['kpi_description'] !== '' ? htmlspecialchars($kpi['kpi_description']) : 'KPI details, quarterly progress and review history.'; ?></p>
    </div>
    <div class="hero-actions">
        <a href="<?php echo $is_owner ? 'my-kpis' : 'kpi-management'; ?>" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
        </a>
        <?php if ($is_owner && in_array($kpi['status'], ['Draft', 'Rejected'])): ?>
            <a href="edit-kpi?id=<?php echo $kpi_id; ?>" class="btn btn-primary">
                <i class="fas fa-edit" aria-hidden="true"></i> Edit KPI
            </a>
        <?php endif; ?>

        <?php if ($is_owner && $kpi['status'] == 'Approved'): ?>
            <button type="button" onclick="openUpdateProgressModal()" class="btn btn-primary">
                <i class="fas fa-chart-line" aria-hidden="true"></i> Update Progress
            </button>
        <?php endif; ?>

        <?php if ($can_review_kpi && $kpi['status'] == 'Submitted'): ?>
            <button type="button" onclick="openModal('approveModal')" class="btn btn-primary">
                <i class="fas fa-check" aria-hidden="true"></i> Approve
            </button>
            <button type="button" onclick="openModal('rejectModal')" class="btn">
                <i class="fas fa-times" aria-hidden="true"></i> Reject
            </button>
        <?php endif; ?>

        <button type="button" onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
        <button type="button" onclick="exportPDF()" class="btn">
            <i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF
        </button>
    </div>
</div>

<!-- Overall Progress -->
<?php if ($kpi['overall_achievement'] !== null): ?>
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Overall Achievement</span>
            <span class="stat-value"><?php echo $kpi['overall_achievement']; ?>%</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Quarters Completed</span>
            <span class="stat-value"><?php echo $quarters_completed; ?> of 4</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- KPI Overview -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-id-card" aria-hidden="true"></i> KPI Overview</h3>
        <span class="badge badge-<?php
            echo $kpi['status'] == 'Approved' ? 'success' :
                ($kpi['status'] == 'Submitted' ? 'info' :
                ($kpi['status'] == 'Under Review' ? 'warning' :
                ($kpi['status'] == 'Rejected' ? 'danger' :
                ($kpi['status'] == 'Completed' ? 'success' : 'secondary'))));
        ?>">
            <?php echo $kpi['status']; ?>
        </span>
    </div>
    <div class="card-body">
        <div class="vk-meta-grid">
            <div class="vk-meta-item">
                <i class="fas fa-user"></i>
                <span><strong>Owner:</strong> <?php echo htmlspecialchars($kpi['owner_name']); ?></span>
            </div>
            <div class="vk-meta-item">
                <i class="fas fa-building"></i>
                <span><strong>Department:</strong> <?php echo htmlspecialchars($kpi['department_name']); ?></span>
            </div>
            <div class="vk-meta-item">
                <i class="fas fa-calendar"></i>
                <span><strong>Fiscal Year:</strong> <?php echo $kpi['fiscal_year']; ?></span>
            </div>
            <div class="vk-meta-item">
                <i class="fas fa-tag"></i>
                <span><strong>Category:</strong> <?php echo htmlspecialchars($kpi['category_name']); ?></span>
            </div>
            <div class="vk-meta-item">
                <i class="fas fa-weight"></i>
                <span><strong>Weight:</strong> <?php echo $kpi['weight_percentage']; ?>%</span>
            </div>
            <div class="vk-meta-item">
                <i class="fas fa-bullseye"></i>
                <span><strong>Target:</strong> <?php echo $kpi['target_value']; ?> <?php echo htmlspecialchars($kpi['unit_of_measure']); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- KPI Details -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-info-circle" aria-hidden="true"></i> KPI Details</h3></div>
    <div class="card-body">
        <div class="vk-info-row">
            <div class="vk-info-label">Measurement Criteria</div>
            <div class="vk-info-value"><?php echo nl2br(htmlspecialchars($kpi['measurement_criteria'])); ?></div>
        </div>

        <div class="vk-info-row">
            <div class="vk-info-label">Annual Target</div>
            <div class="vk-info-value"><strong><?php echo $kpi['target_value']; ?></strong> <?php echo htmlspecialchars($kpi['unit_of_measure']); ?></div>
        </div>

        <div class="vk-info-row">
            <div class="vk-info-label">Weight Percentage</div>
            <div class="vk-info-value"><strong><?php echo $kpi['weight_percentage']; ?>%</strong></div>
        </div>

        <div class="vk-info-row">
            <div class="vk-info-label">Created</div>
            <div class="vk-info-value"><?php echo date('d M Y H:i', strtotime($kpi['created_at'])); ?></div>
        </div>

        <div class="vk-info-row">
            <div class="vk-info-label">Last Updated</div>
            <div class="vk-info-value"><?php echo date('d M Y H:i', strtotime($kpi['updated_at'])); ?></div>
        </div>

        <?php if ($kpi['submitted_at']): ?>
        <div class="vk-info-row">
            <div class="vk-info-label">Submitted</div>
            <div class="vk-info-value"><?php echo date('d M Y H:i', strtotime($kpi['submitted_at'])); ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quarterly Progress -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-calendar-alt" aria-hidden="true"></i> Quarterly Progress</h3></div>
    <div class="card-body">
    <div class="vk-quarter-grid">
        <?php
        $quarters = [
            'q1' => ['name' => 'Q1', 'period' => 'Jan - Mar'],
            'q2' => ['name' => 'Q2', 'period' => 'Apr - Jun'],
            'q3' => ['name' => 'Q3', 'period' => 'Jul - Sep'],
            'q4' => ['name' => 'Q4', 'period' => 'Oct - Dec']
        ];

        foreach ($quarters as $q_key => $q_info):
            $target = $kpi["{$q_key}_target"];
            $actual = $kpi["{$q_key}_actual"];
            $status = $kpi["{$q_key}_status"];
            $achievement = $kpi["{$q_key}_achievement"];
        ?>
            <div class="vk-quarter">
                <div class="vk-quarter-head">
                    <span><?php echo $q_info['name']; ?></span>
                    <span class="vk-quarter-period"><?php echo $q_info['period']; ?></span>
                </div>

                <div class="vk-metric">
                    <div class="vk-metric-label">Target</div>
                    <div class="vk-metric-value"><?php echo $target ?: '-'; ?> <?php echo $target ? htmlspecialchars($kpi['unit_of_measure']) : ''; ?></div>
                </div>

                <div class="vk-metric">
                    <div class="vk-metric-label">Actual</div>
                    <div class="vk-metric-value <?php echo $actual !== null ? 'is-recorded' : 'is-missing'; ?>">
                        <?php echo $actual !== null ? $actual . ' ' . htmlspecialchars($kpi['unit_of_measure']) : 'Not recorded'; ?>
                    </div>
                </div>

                <div class="vk-metric">
                    <div class="vk-metric-label">Status</div>
                    <span class="badge badge-<?php
                        echo $status == 'Completed' ? 'success' :
                            ($status == 'In Progress' ? 'info' :
                            ($status == 'Delayed' ? 'danger' : 'secondary'));
                    ?>">
                        <?php echo $status; ?>
                    </span>
                </div>

                <?php if ($achievement !== null): ?>
                    <div class="vk-ring" style="--progress: <?php echo min($achievement, 100); ?>%;">
                        <div class="vk-ring-inner">
                            <?php echo round($achievement); ?>%
                        </div>
                    </div>

                    <div class="vk-verdict <?php echo $achievement >= 100 ? 'is-exceeded' : ($achievement >= 80 ? 'is-on-track' : 'is-below'); ?>">
                        <strong>
                            <?php
                            if ($achievement >= 100) echo 'Target Exceeded!';
                            elseif ($achievement >= 80) echo 'On Track';
                            else echo 'Below Target';
                            ?>
                        </strong>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    </div>
</div>

<!-- Rejection Reason -->
<?php if ($kpi['status'] == 'Rejected' && $kpi['rejection_reason']): ?>
<div class="alert alert-danger">
    <div class="vk-alert-body">
        <h4><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Rejection Feedback</h4>
        <p><strong>Reviewed by:</strong> <?php echo htmlspecialchars($kpi['reviewer_name']); ?></p>
        <p><strong>Date:</strong> <?php echo date('d M Y H:i', strtotime($kpi['reviewed_at'])); ?></p>
        <hr>
        <div><?php echo nl2br(htmlspecialchars($kpi['rejection_reason'])); ?></div>
    </div>
</div>
<?php endif; ?>

<!-- Approval Information -->
<?php if ($kpi['status'] == 'Approved' || $kpi['status'] == 'Completed'): ?>
<div class="alert alert-success">
    <div class="vk-alert-body">
        <h4><i class="fas fa-check-circle" aria-hidden="true"></i> Approval Information</h4>
        <div class="vk-info-row">
            <div class="vk-info-label">Approved By</div>
            <div class="vk-info-value"><?php echo htmlspecialchars($kpi['approver_name']); ?></div>
        </div>
        <div class="vk-info-row">
            <div class="vk-info-label">Approval Date</div>
            <div class="vk-info-value"><?php echo date('d M Y H:i', strtotime($kpi['approved_at'])); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Comments & Updates -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-comments" aria-hidden="true"></i> Comments &amp; Updates</h3>
        <button type="button" onclick="openModal('addCommentModal')" class="btn btn-primary btn-sm" <?php echo !$is_owner ? 'disabled' : ''; ?>>
            <i class="fas fa-plus"></i> Add Comment
        </button>
    </div>
    <div class="card-body">
    <?php if (empty($comments)): ?>
        <div class="empty-state">
            <i class="fas fa-comments" aria-hidden="true"></i>
            <p>No comments yet. Be the first to add an update!</p>
        </div>
    <?php else: ?>
        <?php foreach ($comments as $comment): ?>
            <div class="vk-comment">
                <div class="vk-comment-head">
                    <div class="vk-comment-author">
                        <span><?php echo htmlspecialchars($comment['full_name']); ?></span>
                        <span class="badge badge-info">
                            <?php echo $comment['quarter']; ?>
                        </span>
                        <span class="badge badge-secondary">
                            <?php echo $comment['comment_type']; ?>
                        </span>
                    </div>
                    <div class="vk-comment-meta">
                        <?php echo date('d M Y H:i', strtotime($comment['created_at'])); ?>
                    </div>
                </div>
                <div class="vk-comment-text">
                    <?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<!-- Review History -->
<?php if (!empty($history)): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-history" aria-hidden="true"></i> Review History</h3></div>
    <div class="card-body">
    <div class="vk-timeline">
        <?php foreach ($history as $item): ?>
            <div class="vk-timeline-item">
                <div class="vk-timeline-dot"></div>
                <div class="vk-timeline-content">
                    <div class="vk-timeline-head">
                        <strong><?php echo $item['review_type']; ?></strong>
                        <span class="vk-timeline-date">
                            <?php echo date('d M Y H:i', strtotime($item['reviewed_at'])); ?>
                        </span>
                    </div>
                    <div class="vk-timeline-by">
                        by <?php echo htmlspecialchars($item['full_name']); ?>
                    </div>
                    <?php if ($item['review_comments']): ?>
                        <div class="vk-timeline-notes">
                            <?php echo nl2br(htmlspecialchars($item['review_comments'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    </div>
</div>
<?php endif; ?>
</div><!-- /.page-stack -->

<!-- Modals -->
<?php if ($is_owner): ?>
<!-- Add Comment Modal -->
<div id="addCommentModal" class="modal">
    <div class="modal-card vk-modal-md">
        <div class="modal-head">
            <h3><i class="fas fa-comment" aria-hidden="true"></i> Add Comment</h3>
            <button type="button" class="modal-close" onclick="closeModal('addCommentModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="add_comment">
                <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
                
                <div class="form-group">
                    <label for="quarter" class="required">Quarter</label>
                    <select id="quarter" name="quarter" class="form-control" required>
                        <option value="Q1">Q1 (Jan-Mar)</option>
                        <option value="Q2">Q2 (Apr-Jun)</option>
                        <option value="Q3">Q3 (Jul-Sep)</option>
                        <option value="Q4">Q4 (Oct-Dec)</option>
                        <option value="Annual">Annual</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="comment_type" class="required">Comment Type</label>
                    <select id="comment_type" name="comment_type" class="form-control" required>
                        <option value="Progress Update">Progress Update</option>
                        <option value="Challenge">Challenge</option>
                        <option value="Achievement">Achievement</option>
                        <option value="Feedback">Feedback</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="comment_text" class="required">Comment</label>
                    <textarea id="comment_text" name="comment_text" class="form-control" rows="4" 
                              placeholder="Share your update, challenge, or achievement..." required></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('addCommentModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Comment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Progress Modal -->
<div id="updateProgressModal" class="modal">
    <div class="modal-card vk-modal-md">
        <div class="modal-head">
            <h3><i class="fas fa-chart-line" aria-hidden="true"></i> Update Progress</h3>
            <button type="button" class="modal-close" onclick="closeModal('updateProgressModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="update_progress">
                <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
                
                <div class="form-group">
                    <label for="progress_quarter" class="required">Quarter</label>
                    <select id="progress_quarter" name="quarter" class="form-control" required>
                        <option value="Q1">Q1 (Jan-Mar) - Target: <?php echo $kpi['q1_target']; ?></option>
                        <option value="Q2">Q2 (Apr-Jun) - Target: <?php echo $kpi['q2_target']; ?></option>
                        <option value="Q3">Q3 (Jul-Sep) - Target: <?php echo $kpi['q3_target']; ?></option>
                        <option value="Q4">Q4 (Oct-Dec) - Target: <?php echo $kpi['q4_target']; ?></option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="actual" class="required">Actual Value Achieved</label>
                    <input type="number" id="actual" name="actual" class="form-control" 
                           step="0.01" placeholder="Enter actual value" required>
                    <div class="form-hint">Unit: <?php echo htmlspecialchars($kpi['unit_of_measure']); ?></div>
                </div>
                
                <div class="form-group">
                    <label for="progress_status" class="required">Status</label>
                    <select id="progress_status" name="status" class="form-control" required>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Delayed">Delayed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="progress_comment">Comment (Optional)</label>
                    <textarea id="progress_comment" name="comment" class="form-control" rows="3" 
                              placeholder="Add any notes or challenges..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('updateProgressModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Progress
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_review_kpi && $kpi['status'] == 'Submitted'): ?>
<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-card vk-modal-sm">
        <div class="modal-head vk-approve-head">
            <h3><i class="fas fa-check-circle" aria-hidden="true"></i> Approve KPI</h3>
            <button type="button" class="modal-close" onclick="closeModal('approveModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
                
                <p>Are you sure you want to approve this KPI?</p>
                <p class="vk-modal-title">
                    <?php echo htmlspecialchars($kpi['kpi_title']); ?>
                </p>
                
                <div class="form-group">
                    <label for="review_comments">Review Comments (Optional)</label>
                    <textarea id="review_comments" name="review_comments" class="form-control" rows="3" 
                              placeholder="Add feedback or encouragement..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('approveModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i> Approve KPI
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-card vk-modal-md">
        <div class="modal-head vk-reject-head">
            <h3><i class="fas fa-times-circle" aria-hidden="true"></i> Reject KPI</h3>
            <button type="button" class="modal-close" onclick="closeModal('rejectModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
                
                <p>Reject this KPI:</p>
                <p class="vk-modal-title">
                    <?php echo htmlspecialchars($kpi['kpi_title']); ?>
                </p>
                
                <div class="form-group">
                    <label for="rejection_reason" class="required">Reason for Rejection</label>
                    <textarea id="rejection_reason" name="rejection_reason" class="form-control" rows="4" 
                              placeholder="Provide clear feedback on what needs to be improved..." required></textarea>
                    <div class="form-hint">Be specific so the employee can make necessary corrections</div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('rejectModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times"></i> Reject KPI
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>

<script>
function openUpdateProgressModal() {
    openModal('updateProgressModal');
}

function exportPDF() {
    window.location.href = 'export-kpi-pdf?id=<?php echo $kpi_id; ?>';
}
</script>