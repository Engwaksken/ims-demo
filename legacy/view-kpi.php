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
.kpi-view-card {
    background: white;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.kpi-header-section {
    border-bottom: 3px solid var(--primary-color);
    padding-bottom: 20px;
    margin-bottom: 25px;
}

.kpi-main-title {
    font-size: 24px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 10px;
}

.kpi-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #7f8c8d;
    font-size: 14px;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #ecf0f1;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-row {
    display: flex;
    padding: 12px;
    border-bottom: 1px solid #ecf0f1;
}

.info-label {
    width: 200px;
    font-weight: 600;
    color: #7f8c8d;
    flex-shrink: 0;
}

.info-value {
    flex: 1;
    color: #2c3e50;
}

.quarterly-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.quarter-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border: 2px solid #ecf0f1;
    position: relative;
    transition: all 0.3s;
}

.quarter-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.quarter-header {
    font-size: 16px;
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.quarter-metric {
    margin-bottom: 12px;
}

.metric-label {
    font-size: 12px;
    color: #7f8c8d;
    text-transform: uppercase;
    margin-bottom: 3px;
}

.metric-value {
    font-size: 20px;
    font-weight: bold;
    color: #2c3e50;
}

.achievement-circle {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: conic-gradient(#27AE60 0% var(--progress), #ecf0f1 var(--progress) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 15px auto;
    position: relative;
}

.achievement-inner {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: bold;
    color: #27AE60;
}

.progress-bar-horizontal {
    height: 30px;
    background: #ecf0f1;
    border-radius: 15px;
    overflow: hidden;
    margin: 10px 0;
    position: relative;
}

.progress-fill-horizontal {
    height: 100%;
    background: linear-gradient(90deg, #27AE60, #2ECC71);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    transition: width 0.5s ease;
}

.comment-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    border-left: 4px solid var(--primary-color);
}

.comment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.comment-author {
    font-weight: 600;
    color: #2c3e50;
}

.comment-meta {
    font-size: 12px;
    color: #7f8c8d;
}

.comment-text {
    color: #2c3e50;
    line-height: 1.6;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #ecf0f1;
}

.timeline-item {
    position: relative;
    padding-bottom: 20px;
}

.timeline-dot {
    position: absolute;
    left: -24px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--primary-color);
    border: 3px solid white;
    box-shadow: 0 0 0 2px var(--primary-color);
}

.timeline-content {
    background: #f8f9fa;
    padding: 12px;
    border-radius: 8px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

@media print {
    .no-print {
        display: none !important;
    }
}
</style>

<!-- Action Bar -->
<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;" class="no-print">
    <div>
        <a href="<?php echo $is_owner ? 'my-kpis' : 'kpi-management'; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
    <div class="action-buttons">
        <?php if ($is_owner && in_array($kpi['status'], ['Draft', 'Rejected'])): ?>
            <a href="edit-kpi?id=<?php echo $kpi_id; ?>" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit KPI
            </a>
        <?php endif; ?>
        
        <?php if ($is_owner && $kpi['status'] == 'Approved'): ?>
            <button onclick="openUpdateProgressModal()" class="btn btn-primary">
                <i class="fas fa-chart-line"></i> Update Progress
            </button>
        <?php endif; ?>
        
        <?php if ($can_review_kpi && $kpi['status'] == 'Submitted'): ?>
            <button onclick="openModal('approveModal')" class="btn btn-success">
                <i class="fas fa-check"></i> Approve
            </button>
            <button onclick="openModal('rejectModal')" class="btn btn-danger">
                <i class="fas fa-times"></i> Reject
            </button>
        <?php endif; ?>
        
        <button onclick="window.print()" class="btn btn-info">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="exportPDF()" class="btn btn-danger">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
    </div>
</div>

<!-- KPI Header -->
<div class="kpi-view-card">
    <div class="kpi-header-section">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div style="flex: 1;">
                <div class="kpi-main-title"><?php echo htmlspecialchars($kpi['kpi_title']); ?></div>
                <div style="color: #7f8c8d; margin-bottom: 10px;"><?php echo htmlspecialchars($kpi['kpi_description']); ?></div>
            </div>
            <div>
                <span class="badge badge-<?php 
                    echo $kpi['status'] == 'Approved' ? 'success' : 
                        ($kpi['status'] == 'Submitted' ? 'info' : 
                        ($kpi['status'] == 'Under Review' ? 'warning' :
                        ($kpi['status'] == 'Rejected' ? 'danger' : 
                        ($kpi['status'] == 'Completed' ? 'success' : 'secondary')))); 
                ?>" style="font-size: 14px; padding: 8px 16px;">
                    <?php echo $kpi['status']; ?>
                </span>
            </div>
        </div>
        
        <div class="kpi-meta-grid">
            <div class="meta-item">
                <i class="fas fa-user"></i>
                <span><strong>Owner:</strong> <?php echo htmlspecialchars($kpi['owner_name']); ?></span>
            </div>
            <div class="meta-item">
                <i class="fas fa-building"></i>
                <span><strong>Department:</strong> <?php echo htmlspecialchars($kpi['department_name']); ?></span>
            </div>
            <div class="meta-item">
                <i class="fas fa-calendar"></i>
                <span><strong>Fiscal Year:</strong> <?php echo $kpi['fiscal_year']; ?></span>
            </div>
            <div class="meta-item">
                <i class="fas fa-tag"></i>
                <span><strong>Category:</strong> <?php echo htmlspecialchars($kpi['category_name']); ?></span>
            </div>
            <div class="meta-item">
                <i class="fas fa-weight"></i>
                <span><strong>Weight:</strong> <?php echo $kpi['weight_percentage']; ?>%</span>
            </div>
            <div class="meta-item">
                <i class="fas fa-bullseye"></i>
                <span><strong>Target:</strong> <?php echo $kpi['target_value']; ?> <?php echo htmlspecialchars($kpi['unit_of_measure']); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Overall Progress -->
    <?php if ($kpi['overall_achievement'] !== null): ?>
    <div style="background: linear-gradient(135deg, #ff6b35 0%, #ff6b35 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px;">
        <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Overall Achievement</div>
        <div style="font-size: 48px; font-weight: bold;"><?php echo $kpi['overall_achievement']; ?>%</div>
        <div style="font-size: 14px; opacity: 0.9; margin-top: 5px;">
            <?php echo $quarters_completed; ?> of 4 Quarters Completed
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- KPI Details -->
<div class="kpi-view-card">
    <div class="section-title">
        <i class="fas fa-info-circle"></i> KPI Details
    </div>
    
    <div class="info-row">
        <div class="info-label">Measurement Criteria</div>
        <div class="info-value"><?php echo nl2br(htmlspecialchars($kpi['measurement_criteria'])); ?></div>
    </div>
    
    <div class="info-row">
        <div class="info-label">Annual Target</div>
        <div class="info-value"><strong><?php echo $kpi['target_value']; ?></strong> <?php echo htmlspecialchars($kpi['unit_of_measure']); ?></div>
    </div>
    
    <div class="info-row">
        <div class="info-label">Weight Percentage</div>
        <div class="info-value"><strong><?php echo $kpi['weight_percentage']; ?>%</strong></div>
    </div>
    
    <div class="info-row">
        <div class="info-label">Created</div>
        <div class="info-value"><?php echo date('d M Y H:i', strtotime($kpi['created_at'])); ?></div>
    </div>
    
    <div class="info-row">
        <div class="info-label">Last Updated</div>
        <div class="info-value"><?php echo date('d M Y H:i', strtotime($kpi['updated_at'])); ?></div>
    </div>
    
    <?php if ($kpi['submitted_at']): ?>
    <div class="info-row">
        <div class="info-label">Submitted</div>
        <div class="info-value"><?php echo date('d M Y H:i', strtotime($kpi['submitted_at'])); ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Quarterly Progress -->
<div class="kpi-view-card">
    <div class="section-title">
        <i class="fas fa-calendar-alt"></i> Quarterly Progress
    </div>
    
    <div class="quarterly-grid">
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
            <div class="quarter-card">
                <div class="quarter-header">
                    <span><?php echo $q_info['name']; ?></span>
                    <span style="font-size: 12px; font-weight: normal;"><?php echo $q_info['period']; ?></span>
                </div>
                
                <div class="quarter-metric">
                    <div class="metric-label">Target</div>
                    <div class="metric-value"><?php echo $target ?: '-'; ?> <?php echo $target ? htmlspecialchars($kpi['unit_of_measure']) : ''; ?></div>
                </div>
                
                <div class="quarter-metric">
                    <div class="metric-label">Actual</div>
                    <div class="metric-value" style="color: <?php echo $actual !== null ? '#27AE60' : '#95A5A6'; ?>;">
                        <?php echo $actual !== null ? $actual . ' ' . htmlspecialchars($kpi['unit_of_measure']) : 'Not recorded'; ?>
                    </div>
                </div>
                
                <div class="quarter-metric">
                    <div class="metric-label">Status</div>
                    <span class="badge badge-<?php 
                        echo $status == 'Completed' ? 'success' : 
                            ($status == 'In Progress' ? 'info' : 
                            ($status == 'Delayed' ? 'danger' : 'secondary')); 
                    ?>">
                        <?php echo $status; ?>
                    </span>
                </div>
                
                <?php if ($achievement !== null): ?>
                    <div class="achievement-circle" style="--progress: <?php echo min($achievement, 100); ?>%;">
                        <div class="achievement-inner">
                            <?php echo round($achievement); ?>%
                        </div>
                    </div>
                    
                    <div style="text-align: center; font-size: 12px; color: <?php echo $achievement >= 100 ? '#27AE60' : ($achievement >= 80 ? '#F39C12' : '#E74C3C'); ?>;">
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

<!-- Rejection Reason -->
<?php if ($kpi['status'] == 'Rejected' && $kpi['rejection_reason']): ?>
<div class="kpi-view-card">
    <div class="alert alert-danger">
        <h4 style="margin-top: 0;"><i class="fas fa-exclamation-triangle"></i> Rejection Feedback</h4>
        <p style="margin: 0;"><strong>Reviewed by:</strong> <?php echo htmlspecialchars($kpi['reviewer_name']); ?></p>
        <p style="margin: 5px 0 0 0;"><strong>Date:</strong> <?php echo date('d M Y H:i', strtotime($kpi['reviewed_at'])); ?></p>
        <hr style="border-color: rgba(0,0,0,0.1);">
        <div><?php echo nl2br(htmlspecialchars($kpi['rejection_reason'])); ?></div>
    </div>
</div>
<?php endif; ?>

<!-- Approval Information -->
<?php if ($kpi['status'] == 'Approved' || $kpi['status'] == 'Completed'): ?>
<div class="kpi-view-card">
    <div style="background: #e8f5e9; padding: 20px; border-radius: 8px; border-left: 4px solid #27AE60;">
        <h4 style="margin-top: 0; color: #27AE60;"><i class="fas fa-check-circle"></i> Approval Information</h4>
        <div class="info-row" style="border: none; padding: 5px 0;">
            <div class="info-label">Approved By</div>
            <div class="info-value"><?php echo htmlspecialchars($kpi['approver_name']); ?></div>
        </div>
        <div class="info-row" style="border: none; padding: 5px 0;">
            <div class="info-label">Approval Date</div>
            <div class="info-value"><?php echo date('d M Y H:i', strtotime($kpi['approved_at'])); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Comments & Updates -->
<div class="kpi-view-card">
    <div class="section-title">
        <i class="fas fa-comments"></i> Comments & Updates
        <button onclick="openModal('addCommentModal')" class="btn btn-primary btn-sm" style="margin-left: auto;" <?php echo !$is_owner ? 'disabled' : ''; ?>>
            <i class="fas fa-plus"></i> Add Comment
        </button>
    </div>
    
    <?php if (empty($comments)): ?>
        <p style="text-align: center; color: #7f8c8d; padding: 30px;">No comments yet. Be the first to add an update!</p>
    <?php else: ?>
        <?php foreach ($comments as $comment): ?>
            <div class="comment-card">
                <div class="comment-header">
                    <div>
                        <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?></span>
                        <span class="badge badge-info" style="margin-left: 10px; font-size: 10px;">
                            <?php echo $comment['quarter']; ?>
                        </span>
                        <span class="badge badge-secondary" style="font-size: 10px;">
                            <?php echo $comment['comment_type']; ?>
                        </span>
                    </div>
                    <div class="comment-meta">
                        <?php echo date('d M Y H:i', strtotime($comment['created_at'])); ?>
                    </div>
                </div>
                <div class="comment-text">
                    <?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Review History -->
<?php if (!empty($history)): ?>
<div class="kpi-view-card">
    <div class="section-title">
        <i class="fas fa-history"></i> Review History
    </div>
    
    <div class="timeline">
        <?php foreach ($history as $item): ?>
            <div class="timeline-item">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <strong><?php echo $item['review_type']; ?></strong>
                        <span style="font-size: 12px; color: #7f8c8d;">
                            <?php echo date('d M Y H:i', strtotime($item['reviewed_at'])); ?>
                        </span>
                    </div>
                    <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 5px;">
                        by <?php echo htmlspecialchars($item['full_name']); ?>
                    </div>
                    <?php if ($item['review_comments']): ?>
                        <div style="color: #2c3e50; margin-top: 8px;">
                            <?php echo nl2br(htmlspecialchars($item['review_comments'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modals -->
<?php if ($is_owner): ?>
<!-- Add Comment Modal -->
<div id="addCommentModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-comment"></i> Add Comment</h3>
            <span class="close" onclick="closeModal('addCommentModal')">&times;</span>
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
                
                <div class="modal-footer">
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
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-chart-line"></i> Update Progress</h3>
            <span class="close" onclick="closeModal('updateProgressModal')">&times;</span>
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
                    <div class="hint-text">Unit: <?php echo htmlspecialchars($kpi['unit_of_measure']); ?></div>
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
                
                <div class="modal-footer">
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
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="color: #27AE60;"><i class="fas fa-check-circle"></i> Approve KPI</h3>
            <span class="close" onclick="closeModal('approveModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
                
                <p>Are you sure you want to approve this KPI?</p>
                <p style="font-size: 16px; font-weight: 600; color: #2c3e50;">
                    <?php echo htmlspecialchars($kpi['kpi_title']); ?>
                </p>
                
                <div class="form-group">
                    <label for="review_comments">Review Comments (Optional)</label>
                    <textarea id="review_comments" name="review_comments" class="form-control" rows="3" 
                              placeholder="Add feedback or encouragement..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('approveModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Approve KPI
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 style="color: #E74C3C;"><i class="fas fa-times-circle"></i> Reject KPI</h3>
            <span class="close" onclick="closeModal('rejectModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="kpi-process">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
                
                <p>Reject this KPI:</p>
                <p style="font-size: 16px; font-weight: 600; color: #2c3e50;">
                    <?php echo htmlspecialchars($kpi['kpi_title']); ?>
                </p>
                
                <div class="form-group">
                    <label for="rejection_reason" class="required">Reason for Rejection</label>
                    <textarea id="rejection_reason" name="rejection_reason" class="form-control" rows="4" 
                              placeholder="Provide clear feedback on what needs to be improved..." required></textarea>
                    <div class="hint-text">Be specific so the employee can make necessary corrections</div>
                </div>
                
                <div class="modal-footer">
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