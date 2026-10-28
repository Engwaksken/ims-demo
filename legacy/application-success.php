<?php

require_once 'includes/config.php';
require_once 'includes/auth.php';

// Get application ID
$application_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$application_id) {
    header("Location: submit-application");
    exit();
}

// IDOR guard: ids are sequential and this page shows the applicant's email.
// Only the browser session that just submitted the application (or staff)
// may view it.
$isStaffViewer = !empty($_SESSION['user_id'])
    && ims_allowed('applications.view_confirmations', IMS_STAFF_ROLES, 'Open any applicant confirmation page');
if ((int)($_SESSION['application_id'] ?? 0) !== $application_id && !$isStaffViewer) {
    header("Location: submit-application");
    exit();
}

// Fetch application details
$query = "SELECT a.*, o.opportunity_title, o.opportunity_type, o.deadline, o.announcement_date
    FROM applications a
    LEFT JOIN application_opportunities o ON a.opportunity_id = o.opportunity_id
    WHERE a.application_id = $application_id";
$result = $conn->query($query);

if ($result->num_rows == 0) {
    header("Location: submit-application");
    exit();
}

$application = $result->fetch_assoc();
$page_title = 'Application Submitted Successfully';

// Generate application reference number
$reference = 'APP-' . str_pad($application_id, 6, '0', STR_PAD_LEFT);
$brand = ims_brand();
$brandName = $brand['name'];
$brandEmail = ims_brand_email();
$brandPhoneHref = preg_replace('/[^0-9+]/', '', $brand['phone']);
$jsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($brand['favicon'], ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($brand['favicon'], ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<style>
/* Brand tokens (defaults); the configured site theme overrides them below. */
:root {
    --brand-50: #ffeed6;
    --brand-100: #ffecd1;
    --brand-600: #ff9800;
    --brand-secondary: #F39C12;
    --brand-secondary-50: #fff3cd;
    --brand-secondary-700: #e67e22;
}
/* Links: no underlines (matches css/style.css); visible keyboard focus. */
a, a:hover, a:focus, a:active, a:visited { text-decoration: none; }
:where(a):focus-visible { outline: 2px solid var(--brand-600); outline-offset: 2px; }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Outfit', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--brand-50) 0%, var(--brand-100) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            
        }
        
        .success-container {
            max-width: 700px;
            width: 100%;
        }
        
        .success-card {
            background: white;
            border-radius: 16px;
            padding: 50px 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            text-align: center;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #27AE60, #2ECC71);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.6s ease 0.2s both;
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
        
        .success-icon i {
            font-size: 60px;
            color: white;
        }
        
        .checkmark {
            animation: drawCheck 0.5s ease 0.5s both;
        }
        
        h1 {
            font-size: 32px;
            color: #2c3e50;
            margin-bottom: 15px;
        }
        
        .subtitle {
            font-size: 18px;
            color: #7f8c8d;
            margin-bottom: 30px;
        }
        
        .reference-box {
            background: var(--brand-600);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin: 30px 0;
        }
        
        .reference-label {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .reference-number {
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 2px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin: 30px 0;
            text-align: left;
        }
        
        .info-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--brand-600);
        }
        
        .info-label {
            font-size: 12px;
            font-weight: 600;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .next-steps {
            background: #e8f4f8;
            border: 2px solid #3498DB;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            text-align: left;
        }
        
        .next-steps h3 {
            color: #2980B9;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .next-steps ol {
            margin-left: 20px;
            color: #555;
        }
        
        .next-steps li {
            margin-bottom: 10px;
            line-height: 1.6;
        }
        
        .contact-info {
            background: var(--brand-secondary-50);
            border: 2px solid var(--brand-secondary);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .contact-info h4 {
            color: var(--brand-secondary-700);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .contact-details {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 14px;
            color: #555;
        }
        
        .contact-details a {
            color: #2980B9;
            text-decoration: none;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: var(--brand-600);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: white;
            color: var(--brand-600);
            border: 2px solid var(--brand-600);
        }
        
        .btn-secondary:hover {
            background: #f8f9fa;
        }
        
        .btn-success {
            background: #27AE60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
            transform: translateY(-2px);
        }
        
        .social-share {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #ecf0f1;
        }
        
        .social-share h4 {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 15px;
        }
        
        .social-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .social-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .social-btn:hover {
            transform: scale(1.1);
        }
        
        .social-btn.twitter {
            background: #1DA1F2;
        }
        
        .social-btn.facebook {
            background: #1877F2;
        }
        
        .social-btn.linkedin {
            background: #0A66C2;
        }
        
        .social-btn.whatsapp {
            background: #25D366;
        }
        
        @media (max-width: 768px) {
            .success-card {
                padding: 40px 25px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            h1 {
                font-size: 26px;
            }
            
            .reference-number {
                font-size: 22px;
            }
        }
       
        
        @media print {
            body {
                background: white;
            }
            
            .action-buttons,
            .social-share {
                display: none;
            }
        }
    </style>
    <?php echo ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])); ?>
<script src="js/accessibility.js"></script>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <!-- Success Icon -->
            <div class="success-icon">
                <i class="fas fa-check checkmark"></i>
            </div>
            
            <!-- Main Message -->
            <h1>Application Submitted Successfully!</h1>
            <p class="subtitle">Thank you for applying to our program</p>
            
            <!-- Reference Number -->
            <div class="reference-box">
                <div class="reference-label">Your Application Reference Number</div>
                <div class="reference-number"><?php echo $reference; ?></div>
            </div>
            
            <!-- Application Details -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Startup Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($application['startup_name']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Program</div>
                    <div class="info-value"><?php echo htmlspecialchars($application['opportunity_title']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Submission Date</div>
                    <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($application['submitted_at'])); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span style="color: #3498DB;">
                            <i class="fas fa-clock"></i> Under Review
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Next Steps -->
            <div class="next-steps">
                <h3>
                    <i class="fas fa-list-check"></i> What Happens Next?
                </h3>
                <ol>
                    <li><strong>Application Review:</strong> Our team will carefully review your application within 5-7 business days.</li>
                    <li><strong>Shortlisting:</strong> If shortlisted, you'll receive an email with further instructions.</li>
                    <li><strong>Interview/Pitch:</strong> Shortlisted applicants may be invited for an interview or pitch session.</li>
                    <?php if ($application['announcement_date']): ?>
                        <li><strong>Final Results:</strong> Winners will be announced on <strong><?php echo date('d M Y', strtotime($application['announcement_date'])); ?></strong>.</li>
                    <?php else: ?>
                        <li><strong>Final Results:</strong> Winners will be announced after the selection process is complete.</li>
                    <?php endif; ?>
                    <li><strong>Confirmation:</strong> A confirmation email has been sent to <strong><?php echo htmlspecialchars($application['email']); ?></strong></li>
                </ol>
            </div>
            
            <!-- Contact Information -->
            <div class="contact-info">
                <h4>
                    <i class="fas fa-question-circle"></i> Need Help?
                </h4>
                <div class="contact-details">
                    <?php if ($brandEmail !== ''): ?>
                    <div>
                        <i class="fas fa-envelope"></i> Email: 
                        <a href="mailto:<?php echo htmlspecialchars($brandEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($brandEmail, ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($brand['phone'] !== ''): ?>
                    <div>
                        <i class="fas fa-phone"></i> Phone: 
                        <a href="tel:<?php echo htmlspecialchars($brandPhoneHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($brand['phone'], ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($brand['address'] !== ''): ?>
                    <div>
                        <i class="fas fa-map-marker-alt"></i> Visit us at <?php echo htmlspecialchars($brand['address'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($brand['website'] !== ''): ?>
                    <div>
                        <i class="fas fa-globe"></i> Website: 
                        <a href="<?php echo htmlspecialchars($brand['website'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($brand['website_label'], ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <button onclick="window.print()" class="btn btn-success">
                    <i class="fas fa-print"></i> Print Confirmation
                </button>
                
                <a href="apply-embed" class="btn btn-secondary">
                    <i class="fas fa-search"></i> More Opportunities
                </a>
                
                <a href="<?php echo htmlspecialchars($brand['website'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
            
            <!-- Social Share -->
            <div class="social-share">
                <h4>Share Your Achievement!</h4>
                <div class="social-buttons">
                    <div class="social-btn twitter" onclick="shareTwitter()" title="Share on Twitter">
                        <i class="fab fa-twitter"></i>
                    </div>
                    <div class="social-btn facebook" onclick="shareFacebook()" title="Share on Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </div>
                    <div class="social-btn linkedin" onclick="shareLinkedIn()" title="Share on LinkedIn">
                        <i class="fab fa-linkedin-in"></i>
                    </div>
                    <div class="social-btn whatsapp" onclick="shareWhatsApp()" title="Share on WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer Note -->
        <div style="text-align: center; color: #000000; margin-top: 20px; opacity: 0.9;">
            <!-- <p style="font-size: 14px;">
                <i class="fas fa-info-circle"></i> 
                Please save your reference number: <strong><?php echo $reference; ?></strong>
            </p> -->
            <p style="font-size: 12px; margin-top: 10px; opacity: 0.8;">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.
            </p>
        </div>
    </div>
    
    <script>
    // Social Share Functions
    function shareTwitter() {
        const text = encodeURIComponent(<?php echo json_encode('I just applied to ' . $application['opportunity_title'] . ' at ' . $brandName . '! #Entrepreneurship #Innovation', $jsFlags); ?>);
        const url = "https://twitter.com/intent/tweet?text=" + text;
        window.open(url, '_blank', 'width=600,height=400');
    }
    
    function shareFacebook() {
        const url = encodeURIComponent(window.location.href);
        window.open("https://www.facebook.com/sharer/sharer.php?u=" + url, '_blank', 'width=600,height=400');
    }
    
    function shareLinkedIn() {
        const url = encodeURIComponent(window.location.href);
        const title = encodeURIComponent("Application Submitted - <?php echo htmlspecialchars($application['opportunity_title']); ?>");
        window.open("https://www.linkedin.com/sharing/share-offsite/?url=" + url, '_blank', 'width=600,height=400');
    }
    
    function shareWhatsApp() {
        const text = encodeURIComponent(<?php echo json_encode('I just applied to ' . $application['opportunity_title'] . ' at ' . $brandName . '! Reference: ' . $reference, $jsFlags); ?>);
        window.open("https://wa.me/?text=" + text, '_blank');
    }
    
    // Auto-scroll to top on load
    window.addEventListener('load', function() {
        window.scrollTo(0, 0);
    });
    
    </script>
</body>
</html>