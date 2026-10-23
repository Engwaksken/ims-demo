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
/* Feedback Details: page-only layout (vf- prefix) */
.vf-hero-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 8px; font-size: var(--fs-base); opacity: .95; }
.vf-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; }
.vf-main { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.vf-aside { display: flex; flex-direction: column; gap: 20px; }

.vf-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
.vf-info-item { padding: 15px; background: var(--surface-hover); border: 1px solid var(--ink-50); border-radius: var(--radius-md); }
.vf-info-item > label { display: block; margin-bottom: 5px; font-size: 11px; font-weight: 700; color: var(--ink-300); text-transform: uppercase; letter-spacing: .05em; }
.vf-info-item .value { font-size: var(--fs-md); color: var(--ink-700); font-weight: 600; }

.vf-message { background: var(--surface-hover); padding: 22px; border-radius: var(--radius-md); line-height: 1.8; color: var(--ink-600); font-size: 15px; }

.vf-rating {
    display: flex; align-items: center; gap: 15px; flex-wrap: wrap;
    padding: 18px 20px; border-radius: var(--radius-md);
    background: var(--amber-bg); border: 1px solid var(--amber-border); color: var(--amber-fg);
}
.vf-stars { display: flex; gap: 8px; font-size: 32px; color: var(--amber-border); }
.vf-stars .is-on { color: var(--amber-fg); }
.vf-rating-text { font-size: var(--fs-lg); font-weight: 700; }

.vf-priority-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: currentColor; }

.vf-attachments { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
.vf-attachment {
    display: block; padding: 20px; text-align: center; text-decoration: none;
    background: var(--surface-hover); border: 2px solid var(--ink-50); border-radius: var(--radius-md);
    transition: all .2s;
}
.vf-attachment:hover { border-color: var(--brand-400); transform: translateY(-3px); box-shadow: var(--shadow-md); }
.vf-attachment i { font-size: 48px; margin-bottom: 10px; }
.vf-attachment .filename { font-size: var(--fs-sm); color: var(--ink-700); margin-top: 10px; word-break: break-all; }
.vf-attachment small { display: block; margin-top: 5px; color: var(--ink-300); }

.vf-response-content {
    background: var(--surface-card); color: var(--ink-600);
    padding: 18px 20px; border-radius: var(--radius-md); line-height: 1.8; margin-bottom: 15px;
}
.vf-response-meta { display: flex; flex-wrap: wrap; gap: 20px; font-size: var(--fs-base); color: var(--green-fg); }
.vf-response-card .card-header h3 i { color: var(--green-fg); }
.vf-response-card .card-body { background: var(--green-bg); }

@media (max-width: 968px) {
    .vf-layout { grid-template-columns: 1fr; }
}

@media print {
    .vf-aside, .no-print { display: none !important; }
    .vf-main { width: 100%; }
}
</style>

<?php
$status_badges = [
    'New' => 'info',
    'In Review' => 'purple',
    'Responded' => 'warning',
    'Resolved' => 'success',
    'Closed' => 'secondary'
];
$badge = $status_badges[$feedback['feedback_status']] ?? 'secondary';
?>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-comment-dots" aria-hidden="true"></i> <?php echo htmlspecialchars($feedback['subject']); ?></h1>
        <div class="vf-hero-meta">
            <span>Feedback ID: #<?php echo $feedback['feedback_id']; ?></span>
            <span class="badge badge-<?php echo $badge; ?>">
                <?php echo $feedback['feedback_status']; ?>
            </span>
        </div>
    </div>
    <div class="hero-actions">
        <a href="my-feedback" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
    </div>
</div>

<!-- Main Content -->
<div class="vf-layout">
    <!-- Main Section -->
    <div class="vf-main">
        <!-- Feedback Details -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-info-circle" aria-hidden="true"></i> Feedback Information</h3></div>
            <div class="card-body">
            <div class="vf-info-grid">
                <div class="vf-info-item">
                    <label>Type</label>
                    <div class="value">
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
                            <?php echo htmlspecialchars($feedback['feedback_type']); ?>
                        </span>
                    </div>
                </div>

                <div class="vf-info-item">
                    <label>Status</label>
                    <div class="value">
                        <span class="badge badge-<?php echo $badge; ?>">
                            <?php echo $feedback['feedback_status']; ?>
                        </span>
                    </div>
                </div>

                <div class="vf-info-item">
                    <label>Priority</label>
                    <div class="value">
                        <?php
                        $priority_class = strtolower($feedback['priority']);
                        $priority_badges = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
                        ?>
                        <span class="badge badge-<?php echo $priority_badges[$priority_class] ?? 'secondary'; ?>">
                            <span class="vf-priority-dot <?php echo $priority_class; ?>"></span>
                            <?php echo $feedback['priority']; ?>
                        </span>
                    </div>
                </div>

                <div class="vf-info-item">
                    <label>Submitted</label>
                    <div class="value"><?php echo date('d M Y', strtotime($feedback['created_at'])); ?></div>
                    <small class="cell-muted"><?php echo date('h:i A', strtotime($feedback['created_at'])); ?></small>
                </div>

                <?php if ($feedback['is_anonymous']): ?>
                <div class="vf-info-item">
                    <label>Visibility</label>
                    <div class="value">
                        <span class="badge badge-secondary">
                            <i class="fas fa-user-secret" aria-hidden="true"></i> Anonymous
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </div>

        <!-- Rating -->
        <?php if ($feedback['rating']): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-star" aria-hidden="true"></i> Rating</h3></div>
            <div class="card-body">
            <div class="vf-rating">
                <div class="vf-stars" aria-label="Rating <?php echo (int)$feedback['rating']; ?> of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star<?php echo $i <= $feedback['rating'] ? ' is-on' : ''; ?>" aria-hidden="true"></i>
                    <?php endfor; ?>
                </div>
                <div class="vf-rating-text">
                    <?php
                    $rating_labels = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'];
                    echo $rating_labels[$feedback['rating']] ?? $feedback['rating'] . ' stars';
                    ?>
                </div>
            </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Feedback Message -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-comment" aria-hidden="true"></i> Message</h3></div>
            <div class="card-body">
            <div class="vf-message">
                <?php echo nl2br(htmlspecialchars($feedback['feedback_message'])); ?>
            </div>
            </div>
        </div>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-paperclip" aria-hidden="true"></i> Attachments (<?php echo count($attachments); ?>)</h3></div>
            <div class="card-body">
            <div class="vf-attachments">
                <?php foreach ($attachments as $index => $attachment):
                    $filename = basename($attachment);
                    $extension = strtolower(pathinfo($attachment, PATHINFO_EXTENSION));

                    $icon = 'file';
                    $color = 'var(--brand-600)';

                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $icon = 'file-image';
                        $color = 'var(--blue-fg)';
                    } elseif ($extension == 'pdf') {
                        $icon = 'file-pdf';
                        $color = 'var(--red-fg)';
                    } elseif (in_array($extension, ['doc', 'docx'])) {
                        $icon = 'file-word';
                        $color = 'var(--blue-fg)';
                    } elseif (in_array($extension, ['xls', 'xlsx'])) {
                        $icon = 'file-excel';
                        $color = 'var(--green-fg)';
                    }
                ?>
                    <a href="<?php echo htmlspecialchars(ims_upload_url($attachment)); ?>" target="_blank" class="vf-attachment">
                        <i class="fas fa-<?php echo $icon; ?>" style="color: <?php echo $color; ?>;" aria-hidden="true"></i>
                        <div class="filename"><?php echo htmlspecialchars($filename); ?></div>
                        <small>
                            <?php echo strtoupper($extension); ?>
                        </small>
                    </a>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Admin Response -->
        <?php if ($feedback['admin_response']): ?>
        <div class="card vf-response-card">
            <div class="card-header">
                <h3><i class="fas fa-reply" aria-hidden="true"></i> Response from Team</h3>
            </div>
            <div class="card-body">
            <div class="vf-response-content">
                <?php echo nl2br(htmlspecialchars($feedback['admin_response'])); ?>
            </div>
            <div class="vf-response-meta">
                <div>
                    <i class="fas fa-user" aria-hidden="true"></i>
                    <strong><?php echo $feedback['responded_by_name'] ? htmlspecialchars($feedback['responded_by_name']) : 'Team Member'; ?></strong>
                </div>
                <div>
                    <i class="fas fa-clock" aria-hidden="true"></i>
                    <?php echo date('d M Y, h:i A', strtotime($feedback['responded_at'])); ?>
                </div>
            </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Member Information (Admin View) -->
        <?php if ($is_admin && !$feedback['is_anonymous']): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user" aria-hidden="true"></i> Member Information</h3></div>
            <div class="card-body">
            <div class="vf-info-grid">
                <div class="vf-info-item">
                    <label>Member Name</label>
                    <div class="value"><?php echo htmlspecialchars($feedback['member_name']); ?></div>
                </div>

                <div class="vf-info-item">
                    <label>Membership Number</label>
                    <div class="value"><code><?php echo htmlspecialchars($feedback['membership_number']); ?></code></div>
                </div>

                <div class="vf-info-item">
                    <label>Email</label>
                    <div class="value">
                        <a href="mailto:<?php echo htmlspecialchars($feedback['member_email']); ?>">
                            <?php echo htmlspecialchars($feedback['member_email']); ?>
                        </a>
                    </div>
                </div>
            </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="vf-aside feedback-aside"><!-- right column (was .sidebar, which collided with the app sidebar) -->


    </div>
</div>

</div><!-- /.page-stack -->

<!-- Respond Modal (Admin Only) -->
<?php if (ims_allowed('feedback.respond', ['Administrator', 'Operations/Admin'], 'Respond to member feedback')): ?>
<div id="respondModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="respondModalTitle">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="respondModalTitle"><i class="fas fa-reply" aria-hidden="true"></i> Respond to Feedback</h3>
            <button type="button" class="modal-close" onclick="closeModal('respondModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="respond-feedback">
                <input type="hidden" name="action" value="respond">
                <input type="hidden" name="feedback_id" value="<?php echo $feedback['feedback_id']; ?>">

                <div class="alert alert-info">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
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

                <div class="modal-actions">
                    <button type="button" onclick="closeModal('respondModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i> Send Response
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