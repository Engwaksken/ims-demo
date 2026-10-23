<?php
// Frontend - Submit Feedback (Member Portal)
$page_title = 'Submit Feedback';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get member details
$member_query = "SELECT * FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    echo "<div class='alert alert-danger'>Member profile not found. Please contact administrator.</div>";
    include 'includes/footer.php';
    exit();
}

$member = $member_result->fetch_assoc();
$member_id = $member['member_id'];

// Get feedback statistics
$stats = [];

// Total feedback submitted
$stats['total'] = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                                WHERE member_id = $member_id")->fetch_assoc()['count'];

// Pending feedback
$stats['pending'] = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                                  WHERE member_id = $member_id 
                                  AND feedback_status IN ('New', 'In Review')")->fetch_assoc()['count'];

// Resolved feedback
$stats['resolved'] = $conn->query("SELECT COUNT(*) as count FROM member_feedback 
                                   WHERE member_id = $member_id 
                                   AND feedback_status IN ('Resolved', 'Closed')")->fetch_assoc()['count'];

// Get recent feedback
$recent_feedback = [];
$query = "SELECT * FROM member_feedback 
          WHERE member_id = $member_id 
          ORDER BY created_at DESC 
          LIMIT 5";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $recent_feedback[] = $row;
}
?>

<style>
/* Submit Feedback: page-only layout (sf- prefix) */
.sf-section { margin-bottom: 22px; }
.sf-section > label { font-weight: 600; color: var(--ink-700); margin-bottom: 8px; display: block; }
.sf-hint { display: block; margin-top: 5px; font-size: var(--fs-xs); color: var(--ink-300); }
.sf-section > label.sf-check { display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 500; }
.sf-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 28px; }

/* Rating stars (JS toggles .active and sets inline hover colour) */
.rating-stars { display: flex; gap: 10px; font-size: 32px; cursor: pointer; }
.rating-stars i { color: var(--ink-100); transition: color .2s; }
.rating-stars i.active,
.rating-stars i:hover { color: var(--amber-fg); }

/* Feedback type picker (JS toggles .type-card.selected) */
.sf-types { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
.sf-types .type-card {
    text-align: center; padding: 20px;
    border: 2px solid var(--ink-50); border-radius: var(--radius-md);
    background: var(--surface-card); cursor: pointer; transition: all .2s;
}
.sf-types .type-card:hover { border-color: var(--brand-400); background: var(--surface-hover); }
.sf-types .type-card.selected { border-color: var(--brand-600); background: var(--brand-600); color: var(--ink-0); }
.sf-types .type-card i { font-size: 32px; margin-bottom: 10px; }
.sf-types .type-card h4 { font-size: var(--fs-base); margin: 0; }

/* Recent feedback list */
.sf-recent { display: flex; flex-direction: column; gap: 15px; }
.sf-item {
    padding: 18px 20px; border: 1px solid var(--ink-50); border-left: 4px solid var(--brand-500);
    border-radius: var(--radius-md); background: var(--surface-card);
}
.sf-item.suggestion { border-left-color: var(--blue-fg); }
.sf-item.complaint  { border-left-color: var(--red-fg); }
.sf-item.general    { border-left-color: var(--slate-fg); }
.sf-item-head { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.sf-item-head h4 { margin: 0 0 5px; color: var(--ink-700); }
.sf-badges { display: flex; gap: 10px; flex-wrap: wrap; }
.sf-rating { color: var(--ink-100); }
.sf-rating .is-on { color: var(--amber-fg); }
.sf-message { color: var(--ink-400); margin: 10px 0; }
.sf-response { margin: 15px 0 0; }
.sf-response p { margin: 5px 0 0; }
.sf-response small { display: block; margin-top: 4px; opacity: .85; }
.sf-meta { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px; font-size: var(--fs-sm); color: var(--ink-300); }
.sf-meta a { color: var(--brand-600); text-decoration: none; }
.sf-meta a:hover { text-decoration: underline; }
.sf-view-all { text-align: center; margin-top: 20px; }
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-comment-dots" aria-hidden="true"></i> Submit Feedback</h1>
        <p>We value your opinion. Help us improve our services.</p>
    </div>
    <div class="hero-actions">
        <a href="my-feedback" class="btn"><i class="fas fa-list" aria-hidden="true"></i> My Feedback</a>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-comments" aria-hidden="true"></i></div>
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
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Resolved</span>
            <span class="stat-value"><?php echo $stats['resolved']; ?></span>
        </div>
    </div>
</div>

<!-- Feedback Form -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-edit" aria-hidden="true"></i> Share Your Feedback</h3>
    </div>
    <div class="card-body">
    <form method="POST" action="includes/process-feedback.php" enctype="multipart/form-data" id="feedbackForm">
        <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">

        <!-- Feedback Type -->
        <div class="sf-section">
            <label class="required">Feedback Type</label>
            <div class="sf-types" id="feedbackTypes">
                <div class="type-card" data-type="General">
                    <i class="fas fa-comment" aria-hidden="true"></i>
                    <h4>General</h4>
                </div>
                <div class="type-card" data-type="Facilities">
                    <i class="fas fa-building" aria-hidden="true"></i>
                    <h4>Facilities</h4>
                </div>
                <div class="type-card" data-type="Services">
                    <i class="fas fa-concierge-bell" aria-hidden="true"></i>
                    <h4>Services</h4>
                </div>
                <div class="type-card" data-type="Events">
                    <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                    <h4>Events</h4>
                </div>
                <div class="type-card" data-type="Complaint">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    <h4>Complaint</h4>
                </div>
                <div class="type-card" data-type="Suggestion">
                    <i class="fas fa-lightbulb" aria-hidden="true"></i>
                    <h4>Suggestion</h4>
                </div>
            </div>
            <input type="hidden" name="feedback_type" id="feedbackType" required>
        </div>

        <!-- Subject -->
        <div class="sf-section">
            <label for="subject" class="required">Subject</label>
            <input type="text" id="subject" name="subject" class="form-control"
                   placeholder="Brief description of your feedback" required maxlength="200">
        </div>

        <!-- Message -->
        <div class="sf-section">
            <label for="feedback_message" class="required">Your Feedback</label>
            <textarea id="feedback_message" name="feedback_message" class="form-control" rows="6"
                      placeholder="Please provide detailed feedback..." required></textarea>
            <small class="sf-hint">Minimum 10 characters</small>
        </div>

        <!-- Rating -->
        <div class="sf-section">
            <label>Rate Your Experience (Optional)</label>
            <div class="rating-stars" id="ratingStars">
                <i class="fas fa-star" data-rating="1"></i>
                <i class="fas fa-star" data-rating="2"></i>
                <i class="fas fa-star" data-rating="3"></i>
                <i class="fas fa-star" data-rating="4"></i>
                <i class="fas fa-star" data-rating="5"></i>
            </div>
            <input type="hidden" name="rating" id="rating" value="0">
            <small class="sf-hint">
                <span id="ratingText">Click to rate</span>
            </small>
        </div>

        <!-- Priority (Auto-set based on type, but allow override) -->
        <div class="sf-section">
            <label for="priority">Priority Level</label>
            <select id="priority" name="priority" class="form-control">
                <option value="Low">Low - General feedback or suggestion</option>
                <option value="Medium" selected>Medium - Needs attention</option>
                <option value="High">High - Important issue</option>
                <option value="Urgent">Urgent - Requires immediate attention</option>
            </select>
        </div>

        <!-- Attachments -->
        <div class="sf-section">
            <label for="attachments">Attachments (Optional)</label>
            <input type="file" id="attachments" name="attachments[]" class="form-control"
                   accept="image/*,.pdf,.doc,.docx" multiple>
            <small class="sf-hint">
                You can attach images or documents (Max 5MB per file, up to 5 files)
            </small>
        </div>

        <!-- Anonymous Option -->
        <div class="sf-section">
            <label class="sf-check">
                <input type="checkbox" name="is_anonymous" id="is_anonymous" value="1">
                <span>Submit anonymously (Your identity will not be shown to staff)</span>
            </label>
        </div>

        <!-- Submit Button -->
        <div class="sf-actions">
            <button type="submit" name="submit_feedback" class="btn btn-primary">
                <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit Feedback
            </button>
            <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                <i class="fas fa-redo" aria-hidden="true"></i> Reset
            </button>
        </div>
    </form>
    </div>
</div>

<!-- Recent Feedback -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history" aria-hidden="true"></i> Your Recent Feedback</h3>
    </div>
    <div class="card-body">
        <?php if (empty($recent_feedback)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <p>You haven't submitted any feedback yet</p>
            </div>
        <?php else: ?>
            <div class="sf-recent">
            <?php foreach ($recent_feedback as $feedback):
                $type_class = strtolower($feedback['feedback_type']);
            ?>
                <div class="sf-item <?php echo $type_class; ?>">
                    <div class="sf-item-head">
                        <div>
                            <h4>
                                <?php echo htmlspecialchars($feedback['subject']); ?>
                            </h4>
                            <div class="sf-badges">
                                <span class="badge badge-info">
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
                                $badge = $status_badges[$feedback['feedback_status']] ?? 'secondary';
                                ?>
                                <span class="badge badge-<?php echo $badge; ?>">
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
                                    ?>
                                    <span class="badge badge-<?php echo $priority_badge; ?>">
                                        <?php echo $feedback['priority']; ?> Priority
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php if ($feedback['rating']): ?>
                                <div class="sf-rating" aria-label="Rating <?php echo (int)$feedback['rating']; ?> of 5">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $feedback['rating'] ? ' is-on' : ''; ?>" aria-hidden="true"></i>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="sf-message">
                        <?php echo nl2br(htmlspecialchars(substr($feedback['feedback_message'], 0, 200))); ?>
                        <?php if (strlen($feedback['feedback_message']) > 200): ?>
                            <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>">Read more...</a>
                        <?php endif; ?>
                    </p>

                    <?php if ($feedback['admin_response']): ?>
                        <div class="alert alert-success sf-response">
                            <div>
                                <strong>
                                    <i class="fas fa-reply" aria-hidden="true"></i> Response:
                                </strong>
                                <p>
                                    <?php echo nl2br(htmlspecialchars($feedback['admin_response'])); ?>
                                </p>
                                <?php if ($feedback['responded_at']): ?>
                                    <small>
                                        Responded on <?php echo date('d M Y, h:i A', strtotime($feedback['responded_at'])); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="sf-meta">
                        <span>
                            <i class="fas fa-calendar" aria-hidden="true"></i>
                            <?php echo date('d M Y, h:i A', strtotime($feedback['created_at'])); ?>
                        </span>
                        <?php if ($feedback['is_anonymous']): ?>
                            <span>
                                <i class="fas fa-user-secret" aria-hidden="true"></i> Anonymous
                            </span>
                        <?php endif; ?>
                        <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>">
                            <i class="fas fa-eye" aria-hidden="true"></i> View Details
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="sf-view-all">
                <a href="my-feedback" class="btn btn-secondary">
                    <i class="fas fa-list" aria-hidden="true"></i> View All Feedback
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<script>
// Feedback type selection
const typeCards = document.querySelectorAll('.type-card');
const feedbackTypeInput = document.getElementById('feedbackType');
const prioritySelect = document.getElementById('priority');

typeCards.forEach(card => {
    card.addEventListener('click', function() {
        // Remove selected class from all
        typeCards.forEach(c => c.classList.remove('selected'));
        
        // Add selected class to clicked
        this.classList.add('selected');
        
        // Set hidden input value
        const type = this.dataset.type;
        feedbackTypeInput.value = type;
        
        // Auto-set priority based on type
        if (type === 'Complaint') {
            prioritySelect.value = 'High';
        } else if (type === 'Suggestion') {
            prioritySelect.value = 'Low';
        } else {
            prioritySelect.value = 'Medium';
        }
    });
});

// Rating stars
const stars = document.querySelectorAll('.rating-stars i');
const ratingInput = document.getElementById('rating');
const ratingText = document.getElementById('ratingText');

const ratingLabels = {
    1: 'Poor',
    2: 'Fair',
    3: 'Good',
    4: 'Very Good',
    5: 'Excellent'
};

stars.forEach(star => {
    star.addEventListener('click', function() {
        const rating = this.dataset.rating;
        ratingInput.value = rating;
        
        // Update star colors
        stars.forEach(s => {
            if (s.dataset.rating <= rating) {
                s.classList.add('active');
            } else {
                s.classList.remove('active');
            }
        });
        
        // Update text
        ratingText.textContent = ratingLabels[rating] || 'Click to rate';
    });
    
    // Hover effect
    star.addEventListener('mouseenter', function() {
        const rating = this.dataset.rating;
        stars.forEach(s => {
            if (s.dataset.rating <= rating) {
                s.style.color = 'var(--amber-fg)';
            } else {
                s.style.color = 'var(--ink-100)';
            }
        });
    });
});

// Reset hover effect
document.querySelector('.rating-stars').addEventListener('mouseleave', function() {
    const currentRating = ratingInput.value;
    stars.forEach(s => {
        if (s.dataset.rating <= currentRating) {
            s.style.color = 'var(--amber-fg)';
        } else {
            s.style.color = 'var(--ink-100)';
        }
    });
});

// Form validation
document.getElementById('feedbackForm').addEventListener('submit', function(e) {
    const feedbackType = feedbackTypeInput.value;
    const message = document.getElementById('feedback_message').value;
    
    if (!feedbackType) {
        e.preventDefault();
        alert('Please select a feedback type');
        return false;
    }
    
    if (message.length < 10) {
        e.preventDefault();
        alert('Please provide more detailed feedback (at least 10 characters)');
        return false;
    }
    
    // File size validation
    const files = document.getElementById('attachments').files;
    if (files.length > 5) {
        e.preventDefault();
        alert('Maximum 5 files allowed');
        return false;
    }
    
    for (let file of files) {
        if (file.size > 5 * 1024 * 1024) { // 5MB
            e.preventDefault();
            alert(`File ${file.name} is too large. Maximum size is 5MB`);
            return false;
        }
    }
});

// Reset form function
function resetForm() {
    typeCards.forEach(c => c.classList.remove('selected'));
    feedbackTypeInput.value = '';
    ratingInput.value = '0';
    stars.forEach(s => s.classList.remove('active'));
    ratingText.textContent = 'Click to rate';
    prioritySelect.value = 'Medium';
}
</script>