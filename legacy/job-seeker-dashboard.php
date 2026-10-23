<?php
$page_title = 'Job Seeker Dashboard';

require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
session_start();

// Must be logged in
require_login();

// Only Job Seekers allowed
if ($_SESSION['role'] !== 'job_seeker') {
    redirect_by_role($_SESSION['role']);
}

require_once 'includes/header.php';

$user_id = (int)$_SESSION['user_id'];
?>
<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-briefcase" aria-hidden="true"></i> Job Seeker Dashboard</h1>
            <p>Your overview as a job seeker.</p>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
