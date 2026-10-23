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
/* My Feedback: page-only layout (mf- prefix) */
.mf-list { display: flex; flex-direction: column; gap: 16px; }
.mf-card { border-left: 4px solid var(--brand-500); transition: transform .2s, box-shadow .2s; }
.mf-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
.mf-card.general    { border-left-color: var(--slate-fg); }
.mf-card.facilities { border-left-color: var(--blue-fg); }
.mf-card.services   { border-left-color: var(--green-fg); }
.mf-card.events     { border-left-color: var(--amber-fg); }
.mf-card.complaint  { border-left-color: var(--red-fg); }
.mf-card.suggestion { border-left-color: var(--purple-fg); }

.mf-head-row { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
.mf-title { flex: 1; }
.mf-title h4 { font-size: var(--fs-lg); color: var(--ink-700); margin: 0 0 8px; }
.mf-badges { display: flex; flex-wrap: wrap; gap: 8px; }
.mf-content { color: var(--ink-400); line-height: 1.6; margin: 15px 0; }
.mf-meta {
    display: flex; flex-wrap: wrap; gap: 20px;
    font-size: var(--fs-sm); color: var(--ink-300);
    margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--ink-50);
}
.mf-meta-item { display: flex; align-items: center; gap: 8px; }
.mf-rating { display: flex; gap: 5px; font-size: var(--fs-lg); color: var(--ink-100); }
.mf-rating .is-on { color: var(--amber-fg); }
.mf-response { margin: 15px 0 0; }
.mf-response-body { flex: 1; }
.mf-response-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; font-weight: 600; }
.mf-response-content { line-height: 1.6; margin-bottom: 10px; }
.mf-response-meta { font-size: var(--fs-sm); opacity: .85; }
.mf-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px; }
.mf-priority-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: currentColor; }
.mf-attachments { margin-top: 15px; display: flex; flex-wrap: wrap; gap: 10px; }

@media (max-width: 768px) {
    .mf-head-row { flex-direction: column; }
}
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-comment-dots" aria-hidden="true"></i> My Feedback</h1>
        <p>View and track your submitted feedback.</p>
    </div>
    <div class="hero-actions">
        <a href="submit-feedback" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Submit New Feedback</a>
    </div>
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

<!-- Filter Bar -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="" class="filters-bar" role="search" aria-label="Filter feedback">
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
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter" aria-hidden="true"></i> Filter
                </button>
                <a href="my-feedback" class="btn btn-secondary">
                    <i class="fas fa-times" aria-hidden="true"></i> Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Feedback List -->
<?php if (empty($feedback_list)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <p>
                    <?php if ($filter_type || $filter_status): ?>
                        No feedback matches your filter criteria.
                    <?php else: ?>
                        You haven't submitted any feedback yet.
                    <?php endif; ?>
                </p>
                <a href="submit-feedback" class="btn btn-primary">
                    <i class="fas fa-plus" aria-hidden="true"></i> Submit Feedback
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="mf-list">
    <?php foreach ($feedback_list as $feedback):
        $type_class = strtolower($feedback['feedback_type']);
    ?>
    <div class="card mf-card <?php echo $type_class; ?>">
        <div class="card-body">
        <div class="mf-head-row">
            <div class="mf-title">
                <h4><?php echo htmlspecialchars($feedback['subject']); ?></h4>
                <div class="mf-badges">
                    <?php
                    $type_badges = [
                        'General' => 'secondary',
                        'Facilities' => 'info',
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
                        'New' => 'info',
                        'In Review' => 'purple',
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
                            <span class="mf-priority-dot <?php echo $priority_class; ?>"></span>
                            <?php echo $feedback['priority']; ?> Priority
                        </span>
                    <?php endif; ?>

                    <?php if ($feedback['is_anonymous']): ?>
                        <span class="badge badge-secondary">
                            <i class="fas fa-user-secret" aria-hidden="true"></i> Anonymous
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <?php if ($feedback['rating']): ?>
                    <div class="mf-rating" aria-label="Rating <?php echo (int)$feedback['rating']; ?> of 5">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star<?php echo $i <= $feedback['rating'] ? ' is-on' : ''; ?>" aria-hidden="true"></i>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mf-content">
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
            <div class="mf-attachments">
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
                    <a href="<?php echo htmlspecialchars(ims_upload_url($attachment)); ?>" target="_blank" class="btn btn-soft btn-sm">
                        <i class="fas fa-<?php echo $icon; ?>" aria-hidden="true"></i>
                        Attachment <?php echo $index + 1; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($feedback['admin_response']): ?>
            <div class="alert alert-success mf-response">
                <div class="mf-response-body">
                    <div class="mf-response-header">
                        <i class="fas fa-reply" aria-hidden="true"></i>
                        <span>Response from Team</span>
                    </div>
                    <div class="mf-response-content">
                        <?php echo nl2br(htmlspecialchars($feedback['admin_response'])); ?>
                    </div>
                    <div class="mf-response-meta">
                        <i class="fas fa-user" aria-hidden="true"></i>
                        <?php echo $feedback['responded_by_name'] ? htmlspecialchars($feedback['responded_by_name']) : 'Team Member'; ?>
                        |
                        <i class="fas fa-clock" aria-hidden="true"></i>
                        <?php echo date('d M Y, h:i A', strtotime($feedback['responded_at'])); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="mf-meta">
            <div class="mf-meta-item">
                <i class="fas fa-calendar" aria-hidden="true"></i>
                Submitted: <?php echo date('d M Y, h:i A', strtotime($feedback['created_at'])); ?>
            </div>

            <?php if ($feedback['assigned_to']): ?>
            <div class="mf-meta-item">
                <i class="fas fa-user-tag" aria-hidden="true"></i>
                Assigned to staff
            </div>
            <?php endif; ?>

            <div class="mf-meta-item">
                <i class="fas fa-hashtag" aria-hidden="true"></i>
                ID: <?php echo $feedback['feedback_id']; ?>
            </div>
        </div>

        <div class="mf-actions">
            <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>" class="btn btn-info btn-sm">
                <i class="fas fa-eye" aria-hidden="true"></i> View Details
            </a>

            <?php if (in_array($feedback['feedback_status'], ['New', 'In Review'])): ?>
                <a href="edit-feedback.php?id=<?php echo $feedback['feedback_id']; ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit" aria-hidden="true"></i> Edit
                </a>
            <?php endif; ?>

            <?php if ($feedback['feedback_status'] == 'New'): ?>
                <button onclick="confirmDelete(<?php echo $feedback['feedback_id']; ?>)" class="btn btn-danger btn-sm">
                    <i class="fas fa-trash" aria-hidden="true"></i> Delete
                </button>
            <?php endif; ?>

            <?php if (strlen($feedback['feedback_message']) > 300): ?>
                <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-ellipsis-h" aria-hidden="true"></i> Read More
                </a>
            <?php endif; ?>
        </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<script>
function confirmDelete(feedbackId) {
    if (confirm('Are you sure you want to delete this feedback?\n\nNote: Only feedback with "New" status can be deleted.')) {
        window.location.href = `includes/process-feedback.php?delete_feedback=${feedbackId}&csrf_token=<?= urlencode(csrf_token()) ?>`;
    }
}
</script>