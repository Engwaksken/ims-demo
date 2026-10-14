<?php

$page_title = 'Feedback Details';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get feedback ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Feedback ID not provided.";
    header("Location: my-feedback");
    exit();
}

$feedback_id = intval($_GET['id']);

// Check if user has admin/operations access
$is_admin = ims_allowed('feedback.view_all', ['Administrator', 'Operations/Admin'], 'See all feedback (not just their own)');

// Get member_id
$member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);
$member_id = null;

if ($member_result->num_rows > 0) {
    $member_id = $member_result->fetch_assoc()['member_id'];
}

// Fetch feedback details
$query = "SELECT f.*, 
          u.full_name as member_name, 
          u.email as member_email,
          m.membership_number,
          resp.full_name as responded_by_name,
          assigned.full_name as assigned_to_name
          FROM member_feedback f
          LEFT JOIN members m ON f.member_id = m.member_id
          LEFT JOIN users u ON m.user_id = u.user_id
          LEFT JOIN users resp ON f.responded_by = resp.user_id
          LEFT JOIN users assigned ON f.assigned_to = assigned.user_id
          WHERE f.feedback_id = $feedback_id";

$result = $conn->query($query);

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Feedback not found.";
    header("Location: my-feedback");
    exit();
}

$feedback = $result->fetch_assoc();

// Check if user has permission to view this feedback
// (strict: a non-member user must not match feedback whose member_id is NULL)
if (!$is_admin && ((int)$member_id <= 0 || (int)$feedback['member_id'] !== (int)$member_id)) {
    $_SESSION['error'] = "You don't have permission to view this feedback.";
    header("Location: my-feedback");
    exit();
}

// Decode attachments
$attachments = [];
if ($feedback['attachments']) {
    $attachments = json_decode($feedback['attachments'], true);
    if (!is_array($attachments)) {
        $attachments = [];
    }
}
?>

<style>
.feedback-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
    position: relative;
    overflow: hidden;
}

.feedback-header h1 {
    font-size: 28px;
    margin-bottom: 10px;
}

.feedback-header .feedback-id {
    font-size: 14px;
    opacity: 0.9;
    font-family: monospace;
}

.status-badge-large {
    position: absolute;
    top: 30px;
    right: 30px;
    padding: 12px 24px;
    border-radius: 25px;
    font-size: 16px;
    font-weight: 600;
}

.feedback-content {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
}

.main-section {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.info-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.info-card h3 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-card h3 i {
    color: #ff6b35;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.info-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.info-item label {
    font-size: 12px;
    color: #7f8c8d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
    display: block;
}

.info-item .value {
    font-size: 16px;
    color: #2c3e50;
    font-weight: 600;
}

.feedback-message {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 12px;
    line-height: 1.8;
    color: #2c3e50;
    font-size: 15px;
}

.rating-display {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: linear-gradient(135deg, #F39C12 0%, #E67E22 100%);
    border-radius: 12px;
    color: white;
}

.stars-large {
    display: flex;
    gap: 8px;
    font-size: 32px;
}

.rating-text {
    font-size: 18px;
    font-weight: 600;
}

.priority-display {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
}

.priority-display.low {
    background: #ecf0f1;
    color: #7f8c8d;
}

.priority-display.medium {
    background: #d1ecf1;
    color: #0c5460;
}

.priority-display.high {
    background: #fff3cd;
    color: #856404;
}

.priority-display.urgent {
    background: #f8d7da;
    color: #721c24;
}

.priority-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.priority-indicator.low { background: #95A5A6; }
.priority-indicator.medium { background: #3498DB; }
.priority-indicator.high { background: #F39C12; }
.priority-indicator.urgent { background: #E74C3C; }

.attachments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
}

.attachment-card {
    background: #f8f9fa;
    border: 2px solid #ecf0f1;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s;
    cursor: pointer;
}

.attachment-card:hover {
    border-color: #ff6b35;
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.attachment-card i {
    font-size: 48px;
    color: #ff6b35;
    margin-bottom: 10px;
}

.attachment-card .filename {
    font-size: 13px;
    color: #2c3e50;
    margin-top: 10px;
    word-break: break-all;
}

.response-section {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-left: 5px solid #2ECC71;
    padding: 25px;
    border-radius: 12px;
}

.response-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
    color: #155724;
}

.response-header i {
    font-size: 32px;
}

.response-header h4 {
    margin: 0;
    font-size: 20px;
}

.response-content {
    background: white;
    padding: 20px;
    border-radius: 8px;
    color: #2c3e50;
    line-height: 1.8;
    margin-bottom: 15px;
}

.response-meta {
    display: flex;
    gap: 20px;
    font-size: 14px;
    color: #155724;
}

.feedback-aside {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.action-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.action-card h3 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 18px;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 20px;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -30px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #ff6b35;
}

.timeline-item::after {
    content: '';
    position: absolute;
    left: -24px;
    top: 17px;
    width: 2px;
    height: calc(100% - 12px);
    background: #ecf0f1;
}

.timeline-item:last-child::after {
    display: none;
}

.timeline-content {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}

.timeline-content strong {
    color: #2c3e50;
    display: block;
    margin-bottom: 5px;
}

.timeline-content small {
    color: #7f8c8d;
}

@media (max-width: 968px) {
    .feedback-content {
        grid-template-columns: 1fr;
    }
    
    .status-badge-large {
        position: static;
        display: inline-block;
        margin-top: 15px;
    }
}

@media print {
    .feedback-aside, .no-print {
        display: none !important;
    }
    
    .main-section {
        width: 100%;
    }
}
</style>

<!-- Header -->
<div class="feedback-header">
    <h1><i class="fas fa-comment-dots"></i> <?php echo htmlspecialchars($feedback['subject']); ?></h1>
    <div class="feedback-id">Feedback ID: #<?php echo $feedback['feedback_id']; ?></div>
    
    <?php
    $status_badges = [
        'New' => 'primary',
        'In Review' => 'info',
        'Responded' => 'warning',
        'Resolved' => 'success',
        'Closed' => 'secondary'
    ];
    $badge = $status_badges[$feedback['feedback_status']] ?? 'secondary';
    ?>
    <span class="badge badge-<?php echo $badge; ?> status-badge-large">
        <?php echo $feedback['feedback_status']; ?>
    </span>
</div>

<!-- Main Content -->
<div class="feedback-content">
    <!-- Main Section -->
    <div class="main-section">
        <!-- Feedback Details -->
        <div class="info-card">
            <h3><i class="fas fa-info-circle"></i> Feedback Information</h3>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>Type</label>
                    <div class="value">
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
                            <?php echo htmlspecialchars($feedback['feedback_type']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>Status</label>
                    <div class="value">
                        <span class="badge badge-<?php echo $badge; ?>">
                            <?php echo $feedback['feedback_status']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>Priority</label>
                    <div class="value">
                        <?php
                        $priority_class = strtolower($feedback['priority']);
                        ?>
                        <div class="priority-display <?php echo $priority_class; ?>">
                            <span class="priority-indicator <?php echo $priority_class; ?>"></span>
                            <?php echo $feedback['priority']; ?>
                        </div>
                    </div>
                </div>
                
                <div class="info-item">
                    <label>Submitted</label>
                    <div class="value"><?php echo date('d M Y', strtotime($feedback['created_at'])); ?></div>
                    <small style="color: #7f8c8d;"><?php echo date('h:i A', strtotime($feedback['created_at'])); ?></small>
                </div>
                
                <?php if ($feedback['is_anonymous']): ?>
                <div class="info-item">
                    <label>Visibility</label>
                    <div class="value">
                        <span class="badge badge-secondary">
                            <i class="fas fa-user-secret"></i> Anonymous
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Rating -->
        <?php if ($feedback['rating']): ?>
        <div class="info-card">
            <h3><i class="fas fa-star"></i> Rating</h3>
            <div class="rating-display">
                <div class="stars-large">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star" style="color: <?php echo $i <= $feedback['rating'] ? '#fff' : 'rgba(255,255,255,0.3)'; ?>;"></i>
                    <?php endfor; ?>
                </div>
                <div class="rating-text">
                    <?php
                    $rating_labels = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'];
                    echo $rating_labels[$feedback['rating']] ?? $feedback['rating'] . ' stars';
                    ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Feedback Message -->
        <div class="info-card">
            <h3><i class="fas fa-comment"></i> Message</h3>
            <div class="feedback-message">
                <?php echo nl2br(htmlspecialchars($feedback['feedback_message'])); ?>
            </div>
        </div>
        
        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="info-card">
            <h3><i class="fas fa-paperclip"></i> Attachments (<?php echo count($attachments); ?>)</h3>
            <div class="attachments-grid">
                <?php foreach ($attachments as $index => $attachment): 
                    $filename = basename($attachment);
                    $extension = strtolower(pathinfo($attachment, PATHINFO_EXTENSION));
                    
                    $icon = 'file';
                    $color = '#ff6b35';
                    
                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $icon = 'file-image';
                        $color = '#3498DB';
                    } elseif ($extension == 'pdf') {
                        $icon = 'file-pdf';
                        $color = '#E74C3C';
                    } elseif (in_array($extension, ['doc', 'docx'])) {
                        $icon = 'file-word';
                        $color = '#2980B9';
                    } elseif (in_array($extension, ['xls', 'xlsx'])) {
                        $icon = 'file-excel';
                        $color = '#27AE60';
                    }
                ?>
                    <a href="<?php echo htmlspecialchars(ims_upload_url($attachment)); ?>" target="_blank" class="attachment-card">
                        <i class="fas fa-<?php echo $icon; ?>" style="color: <?php echo $color; ?>;"></i>
                        <div class="filename"><?php echo htmlspecialchars($filename); ?></div>
                        <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                            <?php echo strtoupper($extension); ?>
                        </small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Admin Response -->
        <?php if ($feedback['admin_response']): ?>
        <div class="response-section">
            <div class="response-header">
                <i class="fas fa-reply"></i>
                <h4>Response from Team</h4>
            </div>
            <div class="response-content">
                <?php echo nl2br(htmlspecialchars($feedback['admin_response'])); ?>
            </div>
            <div class="response-meta">
                <div>
                    <i class="fas fa-user"></i>
                    <strong><?php echo $feedback['responded_by_name'] ? htmlspecialchars($feedback['responded_by_name']) : 'Team Member'; ?></strong>
                </div>
                <div>
                    <i class="fas fa-clock"></i>
                    <?php echo date('d M Y, h:i A', strtotime($feedback['responded_at'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Member Information (Admin View) -->
        <?php if ($is_admin && !$feedback['is_anonymous']): ?>
        <div class="info-card">
            <h3><i class="fas fa-user"></i> Member Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <label>Member Name</label>
                    <div class="value"><?php echo htmlspecialchars($feedback['member_name']); ?></div>
                </div>
                
                <div class="info-item">
                    <label>Membership Number</label>
                    <div class="value"><code><?php echo htmlspecialchars($feedback['membership_number']); ?></code></div>
                </div>
                
                <div class="info-item">
                    <label>Email</label>
                    <div class="value">
                        <a href="mailto:<?php echo htmlspecialchars($feedback['member_email']); ?>">
                            <?php echo htmlspecialchars($feedback['member_email']); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="feedback-aside"><!-- right column (was .sidebar, which collided with the app sidebar) -->
    
    
    </div>
</div>

<!-- Respond Modal (Admin Only) -->
<?php if (ims_allowed('feedback.respond', ['Administrator', 'Operations/Admin'], 'Respond to member feedback')): ?>
<div id="respondModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Respond to Feedback</h3>
            <span class="close" onclick="closeModal('respondModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="respond-feedback">
                <input type="hidden" name="action" value="respond">
                <input type="hidden" name="feedback_id" value="<?php echo $feedback['feedback_id']; ?>">
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    Your response will be visible to the member and will update the feedback status.
                </div>
                
                <div class="form-group">
                    <label for="admin_response" class="required">Response Message</label>
                    <textarea id="admin_response" name="admin_response" class="form-control" 
                              rows="6" required 
                              placeholder="Thank you for your feedback..."></textarea>
                </div>
                
                <div class="form-group">
                    <label for="new_status" class="required">Update Status To</label>
                    <select id="new_status" name="new_status" class="form-control" required>
                        <option value="Responded">Responded</option>
                        <option value="Resolved">Resolved</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('respondModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane"></i> Send Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>

<script>
function confirmDelete(feedbackId) {
    if (confirm('Are you sure you want to delete this feedback?\n\nNote: Only feedback with "New" status can be deleted.')) {
        window.location.href = `includes/process-feedback.php?delete_feedback=${feedbackId}&csrf_token=<?= urlencode(csrf_token()) ?>`;
    }
}
</script>