<?php
// Frontend - Contact Support
$page_title = 'Contact Support';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Get user information
$user_query = "SELECT * FROM users WHERE user_id = $user_id";
$user_result = $conn->query($user_query);
$user = $user_result->fetch_assoc();

// Get application ID if provided
$app_id = isset($_GET['app_id']) ? intval($_GET['app_id']) : 0;
$application = null;

if ($app_id > 0) {
    // Fetch application details
    $app_query = "SELECT a.*, p.opportunity_title
                  FROM applications a
                  LEFT JOIN application_opportunities p ON a.opportunity_id = p.opportunity_id
                  WHERE a.application_id = $app_id AND a.submitted_by = $user_id";
    $app_result = $conn->query($app_query);

    if ($app_result && $app_result->num_rows > 0) {
        $application = $app_result->fetch_assoc();
    }
}
?>

<style>
/* Contact support: form + sidebar grid, contact methods and the FAQ list. */
.cs-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
.cs-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.cs-method { display: flex; align-items: flex-start; gap: 15px; padding: 15px; background: var(--surface-hover); border-radius: var(--radius-md); margin-bottom: 15px; }
.cs-method:last-child { margin-bottom: 0; }
.cs-method-icon { font-size: 24px; color: var(--brand-500); width: 40px; text-align: center; }
.cs-method h4 { color: var(--ink-700); margin: 0 0 5px; font-size: 16px; }
.cs-method p { color: var(--ink-300); margin: 0; font-size: 14px; }
.cs-hours { font-size: 14px; color: var(--ink-700); line-height: 1.8; }
.cs-faq-item { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--ink-50); }
.cs-faq-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
.cs-faq-q { color: var(--ink-700); font-weight: 600; margin-bottom: 10px; display: flex; align-items: flex-start; gap: 10px; }
.cs-faq-q i { color: var(--brand-500); margin-top: 3px; }
.cs-faq-a { color: var(--ink-300); line-height: 1.6; margin-left: 25px; }
.cs-char-counter { font-size: 12px; color: var(--ink-300); float: right; margin-top: 5px; }
.cs-char-counter.is-near-limit { color: var(--red-fg); }
.cs-form-actions { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 30px; }
@media (max-width: 968px) { .cs-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-headset" aria-hidden="true"></i> Contact Support</h1>
        <p>We're here to help! Get in touch with our support team.</p>
    </div>
    <div class="hero-actions">
        <a href="<?php echo $app_id ? 'view-my-application?id='.$app_id : 'dashboard'; ?>" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
    </div>
</div>

<div class="cs-grid">
    <!-- Main Form -->
    <div class="cs-col">
        <?php if ($application): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            <div>
                <strong>Inquiry regarding:</strong> Application #<?php echo htmlspecialchars($application['application_id']); ?>
                <br>
                <small>Program: <?php echo htmlspecialchars($application['opportunity_title']); ?></small>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-envelope" aria-hidden="true"></i> Send us a Message</h3></div>
            <div class="card-body">

            <form method="POST" action="process-support" id="supportForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit">
                <?php if ($app_id): ?>
                <input type="hidden" name="application_id" value="<?php echo $app_id; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name" class="required">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control"
                               value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="email" class="required">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number" class="form-control"
                           placeholder="+256 700 000 000"
                           value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="inquiry_type" class="required">Inquiry Type</label>
                    <select id="inquiry_type" name="inquiry_type" class="form-control" required>
                        <option value="">Select inquiry type</option>
                        <option value="Application Status" <?php echo $app_id ? 'selected' : ''; ?>>Application Status</option>
                        <option value="Application Issue">Application Issue/Error</option>
                        <option value="Program Information">Program Information</option>
                        <option value="Technical Support">Technical Support</option>
                        <option value="Account Issue">Account Issue</option>
                        <option value="Payment/Subscription">Payment/Subscription</option>
                        <option value="General Inquiry">General Inquiry</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="subject" class="required">Subject</label>
                    <input type="text" id="subject" name="subject" class="form-control"
                           placeholder="Brief description of your inquiry" required
                           maxlength="200">
                </div>

                <div class="form-group">
                    <label for="message" class="required">Message</label>
                    <textarea id="message" name="message" class="form-control" rows="8" required
                              placeholder="Please provide as much detail as possible about your inquiry..."
                              maxlength="2000" onkeyup="updateCharCount('message', 2000)"></textarea>
                    <span class="cs-char-counter" id="message_counter">0 / 2000</span>
                </div>

                <div class="form-group">
                    <label for="attachment">Attachment (Optional)</label>
                    <input type="file" id="attachment" name="attachment" class="form-control"
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <small class="cell-muted">
                        Accepted formats: PDF, JPG, PNG, DOC, DOCX (Max 5MB)
                    </small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="urgent" value="1">
                        Mark as urgent
                    </label>
                </div>

                <div class="cs-form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                    <a href="<?php echo $app_id ? 'view-my-application?id='.$app_id : 'dashboard'; ?>"
                       class="btn btn-secondary btn-lg">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="cs-col">
        <!-- Contact Information -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-info-circle" aria-hidden="true"></i> Contact Information</h3></div>
            <div class="card-body">

            <div class="cs-method">
                <div class="cs-method-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="cs-method-body">
                    <h4>Email Support</h4>
                    <p><a href="mailto:info@hivecolab.org">info@hivecolab.org</a></p>
                    <small class="cell-muted">Response within 24 hours</small>
                </div>
            </div>

            <div class="cs-method">
                <div class="cs-method-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <div class="cs-method-body">
                    <h4>Phone Support</h4>
                    <p><a href="tel:+256392177978">+256 39 2177978</a></p>
                    <small class="cell-muted">Mon-Fri, 9:00 AM - 5:00 PM</small>
                </div>
            </div>

            <div class="cs-method">
                <div class="cs-method-icon">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <div class="cs-method-body">
                    <h4>Visit Us</h4>
                    <p>Hive Colab<br>
                    Kampala, Uganda</p>
                    <small class="cell-muted">By appointment only</small>
                </div>
            </div>

            <div class="cs-method">
                <div class="cs-method-icon">
                    <i class="fab fa-twitter"></i>
                </div>
                <div class="cs-method-body">
                    <h4>Social Media</h4>
                    <p>@HiveColab</p>
                    <small class="cell-muted">Follow us for updates</small>
                </div>
            </div>
            </div>
        </div>

        <!-- Office Hours -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-clock" aria-hidden="true"></i> Office Hours</h3></div>
            <div class="card-body cs-hours">
                <strong>Monday - Friday:</strong><br>
                9:00 AM - 5:00 PM EAT<br><br>
                <strong>Saturday - Sunday:</strong><br>
                Closed<br><br>
                <small class="cell-muted">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    Emergency support available via email
                </small>
            </div>
        </div>
        </div>
        <!-- FAQ -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-question-circle" aria-hidden="true"></i> Frequently Asked Questions</h3></div>
            <div class="card-body">

            <div class="cs-faq-item">
                <div class="cs-faq-q">
                    <i class="fas fa-chevron-right"></i>
                    <span>How long does it take to review my application?</span>
                </div>
                <div class="cs-faq-a">
                    Applications are typically reviewed within 7-14 business days after submission.
                    You'll receive an email notification once your application has been reviewed.
                </div>
            </div>

            <div class="cs-faq-item">
                <div class="cs-faq-q">
                    <i class="fas fa-chevron-right"></i>
                    <span>Can I edit my application after submission?</span>
                </div>
                <div class="cs-faq-a">
                    Yes, you can edit your application while it's in "Pending" status.
                    Once it's been reviewed or moved to another status, editing is not allowed.
                </div>
            </div>

            <div class="cs-faq-item">
                <div class="cs-faq-q">
                    <i class="fas fa-chevron-right"></i>
                    <span>What happens after I'm shortlisted?</span>
                </div>
                <div class="cs-faq-a">
                    If you're shortlisted, our team will contact you via email with details
                    about the next steps, which may include an interview or pitch presentation.
                </div>
            </div>

            <div class="cs-faq-item">
                <div class="cs-faq-q">
                    <i class="fas fa-chevron-right"></i>
                    <span>Can I apply to multiple programs?</span>
                </div>
                <div class="cs-faq-a">
                    Yes, you can apply to multiple programs. Each application is reviewed
                    independently based on the specific program's criteria.
                </div>
            </div>

            <div class="cs-faq-item">
                <div class="cs-faq-q">
                    <i class="fas fa-chevron-right"></i>
                    <span>How do I reset my password?</span>
                </div>
                <div class="cs-faq-a">
                    Click on "Forgot Password" on the login page and follow the instructions
                    sent to your registered email address.
                </div>
            </div>
            </div>
        </div>
    </div>
</div><!-- /.page-stack -->


<?php include 'includes/footer.php'; ?>

<script>
// Character counter
function updateCharCount(fieldId, maxLength) {
    const field = document.getElementById(fieldId);
    const counter = document.getElementById(fieldId + '_counter');
    const currentLength = field.value.length;
    counter.textContent = currentLength + ' / ' + maxLength;

    if (currentLength > maxLength * 0.9) {
        counter.classList.add('is-near-limit');
    } else {
        counter.classList.remove('is-near-limit');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCharCount('message', 2000);
});

// File upload validation
document.getElementById('attachment').addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const fileSize = this.files[0].size / 1024 / 1024; // Size in MB

        if (fileSize > 5) {
            alert('File size exceeds 5MB limit. Please choose a smaller file.');
            this.value = '';
            return false;
        }
    }
});

// Form validation
document.getElementById('supportForm').addEventListener('submit', function(e) {
    const message = document.getElementById('message').value.trim();
    const subject = document.getElementById('subject').value.trim();
    const inquiryType = document.getElementById('inquiry_type').value;

    if (!inquiryType) {
        alert('Please select an inquiry type.');
        e.preventDefault();
        return false;
    }

    if (!subject) {
        alert('Please provide a subject.');
        e.preventDefault();
        return false;
    }

    if (!message || message.length < 10) {
        alert('Please provide a detailed message (at least 10 characters).');
        e.preventDefault();
        return false;
    }

    return true;
});
</script>