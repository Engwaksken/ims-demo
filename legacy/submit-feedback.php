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
.feedback-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.feedback-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.feedback-form {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.feedback-form h3 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 24px;
}

.form-section {
    margin-bottom: 25px;
}

.form-section label {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
    display: block;
}

.rating-stars {
    display: flex;
    gap: 10px;
    font-size: 32px;
    cursor: pointer;
}

.rating-stars i {
    color: #ddd;
    transition: color 0.2s;
}

.rating-stars i.active,
.rating-stars i:hover {
    color: #F39C12;
}

.feedback-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    border-left: 4px solid #ff6b35;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.feedback-card.suggestion {
    border-left-color: #3498DB;
}

.feedback-card.complaint {
    border-left-color: #E74C3C;
}

.feedback-card.general {
    border-left-color: #95A5A6;
}

.feedback-header-row {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
    flex-wrap: wrap;
    gap: 10px;
}

.feedback-meta {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    margin-top: 10px;
    font-size: 13px;
    color: #7f8c8d;
}

.feedback-response {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
    border-left: 3px solid #2ECC71;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #7f8c8d;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    color: #bdc3c7;
}

.feedback-types {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.type-card {
    text-align: center;
    padding: 20px;
    border: 2px solid #ecf0f1;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

.type-card:hover {
    border-color: #ff6b35;
    background: #f8f9fa;
}

.type-card.selected {
    border-color: #ff6b35;
    background: #ff6b35;
    color: white;
}

.type-card i {
    font-size: 32px;
    margin-bottom: 10px;
}

.type-card h4 {
    font-size: 14px;
    margin: 0;
}
</style>

<!-- Header -->
<div class="feedback-header">
    <h1><i class="fas fa-comment-dots"></i> Submit Feedback</h1>
    <p style="margin: 0; opacity: 0.9;">We value your opinion. Help us improve our services</p>
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
<div class="feedback-form">
    <h3><i class="fas fa-edit"></i> Share Your Feedback</h3>
    
    <form method="POST" action="includes/process-feedback.php" enctype="multipart/form-data" id="feedbackForm">
        <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">
        
        <!-- Feedback Type -->
        <div class="form-section">
            <label class="required">Feedback Type</label>
            <div class="feedback-types" id="feedbackTypes">
                <div class="type-card" data-type="General">
                    <i class="fas fa-comment"></i>
                    <h4>General</h4>
                </div>
                <div class="type-card" data-type="Facilities">
                    <i class="fas fa-building"></i>
                    <h4>Facilities</h4>
                </div>
                <div class="type-card" data-type="Services">
                    <i class="fas fa-concierge-bell"></i>
                    <h4>Services</h4>
                </div>
                <div class="type-card" data-type="Events">
                    <i class="fas fa-calendar-alt"></i>
                    <h4>Events</h4>
                </div>
                <div class="type-card" data-type="Complaint">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h4>Complaint</h4>
                </div>
                <div class="type-card" data-type="Suggestion">
                    <i class="fas fa-lightbulb"></i>
                    <h4>Suggestion</h4>
                </div>
            </div>
            <input type="hidden" name="feedback_type" id="feedbackType" required>
        </div>
        
        <!-- Subject -->
        <div class="form-section">
            <label for="subject" class="required">Subject</label>
            <input type="text" id="subject" name="subject" class="form-control" 
                   placeholder="Brief description of your feedback" required maxlength="200">
        </div>
        
        <!-- Message -->
        <div class="form-section">
            <label for="feedback_message" class="required">Your Feedback</label>
            <textarea id="feedback_message" name="feedback_message" class="form-control" rows="6" 
                      placeholder="Please provide detailed feedback..." required></textarea>
            <small style="color: #7f8c8d;">Minimum 10 characters</small>
        </div>
        
        <!-- Rating -->
        <div class="form-section">
            <label>Rate Your Experience (Optional)</label>
            <div class="rating-stars" id="ratingStars">
                <i class="fas fa-star" data-rating="1"></i>
                <i class="fas fa-star" data-rating="2"></i>
                <i class="fas fa-star" data-rating="3"></i>
                <i class="fas fa-star" data-rating="4"></i>
                <i class="fas fa-star" data-rating="5"></i>
            </div>
            <input type="hidden" name="rating" id="rating" value="0">
            <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                <span id="ratingText">Click to rate</span>
            </small>
        </div>
        
        <!-- Priority (Auto-set based on type, but allow override) -->
        <div class="form-section">
            <label for="priority">Priority Level</label>
            <select id="priority" name="priority" class="form-control">
                <option value="Low">Low - General feedback or suggestion</option>
                <option value="Medium" selected>Medium - Needs attention</option>
                <option value="High">High - Important issue</option>
                <option value="Urgent">Urgent - Requires immediate attention</option>
            </select>
        </div>
        
        <!-- Attachments -->
        <div class="form-section">
            <label for="attachments">Attachments (Optional)</label>
            <input type="file" id="attachments" name="attachments[]" class="form-control" 
                   accept="image/*,.pdf,.doc,.docx" multiple>
            <small style="color: #7f8c8d;">
                You can attach images or documents (Max 5MB per file, up to 5 files)
            </small>
        </div>
        
        <!-- Anonymous Option -->
        <div class="form-section">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                <input type="checkbox" name="is_anonymous" id="is_anonymous" value="1">
                <span>Submit anonymously (Your identity will not be shown to staff)</span>
            </label>
        </div>
        
        <!-- Submit Button -->
        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" name="submit_feedback" class="btn btn-primary btn-lg">
                <i class="fas fa-paper-plane"></i> Submit Feedback
            </button>
            <button type="reset" class="btn btn-secondary btn-lg" onclick="resetForm()">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
    </form>
</div>

<!-- Recent Feedback -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Your Recent Feedback</h3>
    </div>
    <div class="card-body">
        <?php if (empty($recent_feedback)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>You haven't submitted any feedback yet</p>
            </div>
        <?php else: ?>
            <?php foreach ($recent_feedback as $feedback): 
                $type_class = strtolower($feedback['feedback_type']);
            ?>
                <div class="feedback-card <?php echo $type_class; ?>">
                    <div class="feedback-header-row">
                        <div>
                            <h4 style="margin: 0 0 5px 0; color: #2c3e50;">
                                <?php echo htmlspecialchars($feedback['subject']); ?>
                            </h4>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <span class="badge badge-primary">
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
                                <div style="color: #F39C12;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star" style="color: <?php echo $i <= $feedback['rating'] ? '#F39C12' : '#ddd'; ?>;"></i>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <p style="color: #555; margin: 10px 0;">
                        <?php echo nl2br(htmlspecialchars(substr($feedback['feedback_message'], 0, 200))); ?>
                        <?php if (strlen($feedback['feedback_message']) > 200): ?>
                            <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>">Read more...</a>
                        <?php endif; ?>
                    </p>
                    
                    <?php if ($feedback['admin_response']): ?>
                        <div class="feedback-response">
                            <strong style="color: #2c3e50;">
                                <i class="fas fa-reply"></i> Response:
                            </strong>
                            <p style="margin: 5px 0 0 0; color: #555;">
                                <?php echo nl2br(htmlspecialchars($feedback['admin_response'])); ?>
                            </p>
                            <?php if ($feedback['responded_at']): ?>
                                <small style="color: #7f8c8d;">
                                    Responded on <?php echo date('d M Y, h:i A', strtotime($feedback['responded_at'])); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="feedback-meta">
                        <span>
                            <i class="fas fa-calendar"></i>
                            <?php echo date('d M Y, h:i A', strtotime($feedback['created_at'])); ?>
                        </span>
                        <?php if ($feedback['is_anonymous']): ?>
                            <span>
                                <i class="fas fa-user-secret"></i> Anonymous
                            </span>
                        <?php endif; ?>
                        <a href="view-feedback?id=<?php echo $feedback['feedback_id']; ?>" 
                           style="color: #ff6b35; text-decoration: none;">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="my-feedback" class="btn btn-info">
                    <i class="fas fa-list"></i> View All Feedback
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

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
                s.style.color = '#F39C12';
            } else {
                s.style.color = '#ddd';
            }
        });
    });
});

// Reset hover effect
document.querySelector('.rating-stars').addEventListener('mouseleave', function() {
    const currentRating = ratingInput.value;
    stars.forEach(s => {
        if (s.dataset.rating <= currentRating) {
            s.style.color = '#F39C12';
        } else {
            s.style.color = '#ddd';
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