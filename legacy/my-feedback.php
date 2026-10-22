<?php

$page_title = 'My Feedback';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get member details
$member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    echo "<div class='alert alert-danger'>Member profile not found. Please contact administrator.</div>";
    include 'includes/footer.php';
    exit();
}

$member_id = $member_result->fetch_assoc()['member_id'];

// Get filter parameters
$filter_type = isset($_GET['type']) ? $conn->real_escape_string(sanitize_input($_GET['type'])) : '';
$filter_status = isset($_GET['status']) ? $conn->real_escape_string(sanitize_input($_GET['status'])) : '';

// Build WHERE clause
$where = "member_id = $member_id";

if ($filter_type) {
    $where .= " AND feedback_type = '$filter_type'";
}

if ($filter_status) {
    $where .= " AND feedback_status = '$filter_status'";
}

// Fetch feedback
$feedback_list = [];
$query = "SELECT f.*, 
          u.full_name as responded_by_name
          FROM member_feedback f
          LEFT JOIN users u ON f.responded_by = u.user_id
          WHERE $where
          ORDER BY f.created_at DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $feedback_list[] = $row;
}

// Calculate statistics
$stats = [];

// Total feedback
$stats['total'] = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                                WHERE member_id = $member_id")->fetch_assoc()['count'];

// Pending (New + In Review)
$stats['pending'] = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                                  WHERE member_id = $member_id 
                                  AND feedback_status IN ('New', 'In Review')")->fetch_assoc()['count'];

// Responded
$stats['responded'] = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                                    WHERE member_id = $member_id 
                                    AND feedback_status = 'Responded'")->fetch_assoc()['count'];

// Resolved
$stats['resolved'] = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                                   WHERE member_id = $member_id 
                                   AND feedback_status IN ('Resolved', 'Closed')")->fetch_assoc()['count'];
?>

<style>
.feedback-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.feedback-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.filter-bar {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.filter-bar > .filters-bar {
    margin-bottom: 0;
}

.feedback-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 5px solid #ff6b35;
    transition: transform 0.2s, box-shadow 0.2s;
}

.feedback-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.feedback-card.general {
    border-left-color: #95A5A6;
}

.feedback-card.facilities {
    border-left-color: #3498DB;
}

.feedback-card.services {
    border-left-color: #2ECC71;
}

.feedback-card.events {
    border-left-color: #F39C12;
}

.feedback-card.complaint {
    border-left-color: #E74C3C;
}

.feedback-card.suggestion {
    border-left-color: #9B59B6;
}

.feedback-header-row {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
    flex-wrap: wrap;
    gap: 15px;
}

.feedback-title {
    flex: 1;
}

.feedback-title h4 {
    font-size: 20px;
    color: #2c3e50;
    margin-bottom: 8px;
}

.feedback-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.feedback-content {
    color: #555;
    line-height: 1.6;
    margin: 15px 0;
}

.feedback-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    font-size: 14px;
    color: #7f8c8d;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #ecf0f1;
}

.feedback-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.feedback-rating {
    display: flex;
    gap: 5px;
    font-size: 18px;
}

.feedback-response {
    background: #f0f8ff;
    border-left: 4px solid #2ECC71;
    padding: 20px;
    border-radius: 8px;
    margin-top: 15px;
}

.feedback-response-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    color: #2c3e50;
    font-weight: 600;
}

.feedback-response-content {
    color: #555;
    line-height: 1.6;
    margin-bottom: 10px;
}

.feedback-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 15px;
}

.priority-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
}

.priority-indicator.low {
    background: #95A5A6;
}

.priority-indicator.medium {
    background: #3498DB;
}

.priority-indicator.high {
    background: #F39C12;
}

.priority-indicator.urgent {
    background: #E74C3C;
}

.attachment-list {
    margin-top: 15px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.attachment-badge {
    background: #ecf0f1;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 13px;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 8px;
}

.attachment-badge i {
    color: #ff6b35;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i {
    font-size: 64px;
    color: #bdc3c7;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .feedback-header-row {
        flex-direction: column;
        align-items: start;
    }
}
</style>

<!-- Header -->
<div class="feedback-header">
    <h1><i class="fas fa-comment-dots"></i> My Feedback</h1>
    <p style="margin: 0; opacity: 0.9;">View and track your submitted feedback</p>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-comments" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Feedback</span>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Review</span>
            <span class="stat-value"><?php echo $stats['pending']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-reply" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Responded</span>
            <span class="stat-value"><?php echo $stats['responded']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Resolved</span>
            <span class="stat-value"><?php echo $stats['resolved']; ?></span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div style="margin-bottom: 25px;">
    <a href="submit-feedback" class="btn btn-primary">
        <i class="fas fa-plus"></i> Submit New Feedback
    </a>
</div>

<!-- Filter Bar -->
<form method="GET" action="" class="filter-bar">
    <div class="filters-bar" role="search" aria-label="Filter feedback">
        <div class="form-group">
            <label for="type">Feedback Type</label>
            <select name="type" id="type" class="form-control">
                <option value="">All Types</option>
                <option value="General" <?php echo $filter_type == 'General' ? 'selected' : ''; ?>>General</option>
                <option value="Facilities" <?php echo $filter_type == 'Facilities' ? 'selected' : ''; ?>>Facilities</option>
                <option value="Services" <?php echo $filter_type == 'Services' ? 'selected' : ''; ?>>Services</option>
                <option value="Events" <?php echo $filter_type == 'Events' ? 'selected' : ''; ?>>Events</option>
                <option value="Complaint" <?php echo $filter_type == 'Complaint' ? 'selected' : ''; ?>>Complaint</option>
                <option value="Suggestion" <?php echo $filter_type == 'Suggestion' ? 'selected' : ''; ?>>Suggestion</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="status">Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Status</option>
                <option value="New" <?php echo $filter_status == 'New' ? 'selected' : ''; ?>>New</option>
                <option value="In Review" <?php echo $filter_status == 'In Review' ? 'selected' : ''; ?>>In Review</option>
                <option value="Responded" <?php echo $filter_status == 'Responded' ? 'selected' : ''; ?>>Responded</option>
                <option value="Resolved" <?php echo $filter_status == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                <option value="Closed" <?php echo $filter_status == 'Closed' ? 'selected' : ''; ?>>Closed</option>
            </select>
        </div>
        
        <div class="filters-actions">
            <button type="submit" class="btn btn-info">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="my-feedback" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </div>
</form>

<!-- Feedback List -->
<?php if (empty($feedback_list)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No feedback found</h3>
                <p style="color: #7f8c8d; margin-bottom: 20px;">
                    <?php if ($filter_type || $filter_status): ?>
                        No feedback matches your filter criteria.
                    <?php else: ?>
                        You haven't submitted any feedback yet.
                    <?php endif; ?>
                </p>
                <a href="submit-feedback" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Submit Feedback
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($feedback_list as $feedback): 
        $type_class = strtolower($feedback['feedback_type']);
    ?>
    <div class="feedback-card <?php echo $type_class; ?>">
        <div class="feedback-header-row">
            <div class="feedback-title">
                <h4><?php echo htmlspecialchars($feedback['subject']); ?></h4>
                <div class="feedback-badges">
                    <?php
                    $type_badges = [
                        'General' => 'secondary',
                        'Facilities' => 'primary',
                        'Services' => 'success',
                        'Events' => 'warning',
                        'Complaint' => 'danger',
                        'Suggestion' => 'purple'
                    ];
                    $type_badge = $type_badges[$feedback['feedback_type']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?php echo $type_badge; ?>">
                        <?php echo $feedback['feedback_type']; ?>
                    </span>
                    
                    <?php
                    $status_badges = [
                        'New' => 'primary',
                        'In Review' => 'info',
                        'Responded' => 'warning',
                        'Resolved' => 'success',
                        'Closed' => 'secondary'
                    ];
                    $status_badge = $status_badges[$feedback['feedback_status']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?php echo $status_badge; ?>">
                        <?php echo $feedback['feedback_status']; ?>
                    </span>
                    
                    <?php if ($feedback['priority']): ?>
                        <?php
                        $priority_badges = [
                            'Low' => 'secondary',
                            'Medium' => 'info',
                            'High' => 'warning',
                            'Urgent' => 'danger'
                        ];
                        $priority_badge = $priority_badges[$feedback['priority']] ?? 'secondary';
                        $priority_class = strtolower($feedback['priority']);
                        ?>
                        <span class="badge badge-<?php echo $priority_badge; ?>">
                            <span class="priority-indicator <?php echo $priority_class; ?>"></span>
                            <?php echo $feedback['priority']; ?> Priority
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($feedback['is_anonymous']): ?>
                        <span class="badge badge-secondary">
                            <i class="fas fa-user-secret"></i> Anonymous
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <?php if ($feedback['rating']): ?>
                    <div class="feedback-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star" style="color: <?php echo $i <= $feedback['rating'] ? '#F39C12' : '#ddd'; ?>;"></i>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="feedback-content">
            <?php 
            $message = $feedback['feedback_message'];
            $preview_length = 300;
            if (strlen($message) > $preview_length):
                echo nl2br(htmlspecialchars(substr($message, 0, $preview_length))) . '...';
            else:
                echo nl2br(htmlspecialchars($message));
            endif;
            ?>
        </div>
        
        <?php if ($feedback['attachments']): ?>
            <?php
            $attachments = json_decode($feedback['attachments'], true);
            if ($attachments && is_array($attachments)):
            ?>
            <div class="attachment-list">
                <?php foreach ($attachments as $index => $attachment): 
                    $filename = basename($attachment);
                    $extension = pathinfo($attachment, PATHINFO_EXTENSION);
                    $icon = 'file';
                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $icon = 'file-image';
                    } elseif ($extension == 'pdf') {
                        $icon = 'file-pdf';
                    } elseif (in_array($extension, ['doc', 'docx'])) {
                        $icon = 'file-word';
                    }
                ?>
                    <a href="<?php echo htmlspecialchars(ims_upload_url($attachment)); ?>" target="_blank" class="attachment-badge">
                        <i class="fas fa-<?php echo $icon; ?>"></i>
                        Attachment <?php echo $index + 1; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($feedback['admin_response']): ?>
            <div class="feedback-response">
                <div class="feedback-response-header">
                    <i class="fas fa-reply" style="color: #2ECC71;"></i>
                    <span>Response from Team</span>
                </div>
                <div class="feedback-response-content">
                    <?php echo nl2br(htmlspecialchars($feedback['admin_response'])); ?>
                </div>
                <div style="font-size: 13px; color: #7f8c8d;">
                    <i class="fas fa-user"></i> 
                    <?php echo $feedback['responded_by_name'] ? htmlspecialchars($feedback['responded_by_name']) : 'Team Member'; ?>
                    | 
                    <i class="fas fa-clock"></i>
                    <?php echo date('d M Y, h:i A', strtotime($feedback['responded_at'])); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="feedback-meta">
            <div class="feedback-meta-item">
                <i class="fas fa-calendar"></i>
                Submitted: <?php echo date('d M Y, h:i A', strtotime($feedback['created_at'])); ?>
            </div>
            
            <?php if ($feedback['assigned_to']): ?>
            <div class="feedback-meta-item">
                <i class="fas fa-user-tag"></i>
                Assigned to staff
            </div>
            <?php endif; ?>
            
            <div class="feedback-meta-item">
                <i class="fas fa-hashtag"></i>
                ID: <?php echo $feedback['feedback_id']; ?>
            </div>
        </div>
        
        <div class="feedback-actions">
            <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>" class="btn btn-info btn-sm">
                <i class="fas fa-eye"></i> View Details
            </a>
            
            <?php if (in_array($feedback['feedback_status'], ['New', 'In Review'])): ?>
                <a href="edit-feedback.php?id=<?php echo $feedback['feedback_id']; ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit"></i> Edit
                </a>
            <?php endif; ?>
            
            <?php if ($feedback['feedback_status'] == 'New'): ?>
                <button onclick="confirmDelete(<?php echo $feedback['feedback_id']; ?>)" class="btn btn-danger btn-sm">
                    <i class="fas fa-trash"></i> Delete
                </button>
            <?php endif; ?>
            
            <?php if (strlen($feedback['feedback_message']) > 300): ?>
                <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-ellipsis-h"></i> Read More
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>

<script>
function confirmDelete(feedbackId) {
    if (confirm('Are you sure you want to delete this feedback?\n\nNote: Only feedback with "New" status can be deleted.')) {
        window.location.href = `includes/process-feedback.php?delete_feedback=${feedbackId}&csrf_token=<?= urlencode(csrf_token()) ?>`;
    }
}
</script>