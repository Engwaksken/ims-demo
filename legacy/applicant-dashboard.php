<?php

$page_title = 'Applicant Dashboard';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}


$sessionRole = strtolower(trim((string)(
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? ''
)));

if ($sessionRole !== 'applicant') {
    $roleStmt = $conn->prepare("
        SELECT role
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $databaseRole = '';

    if ($roleStmt) {
        $roleStmt->bind_param('i', $user_id);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();

        $databaseRole = strtolower(trim((string)($roleRow['role'] ?? '')));
    }

    if ($databaseRole !== 'applicant') {
        $_SESSION['error'] = 'Access denied. This page is only for applicants.';
        header('Location: dashboard');
        exit;
    }

    // Repair session role for the rest of the applicant portal.
    $_SESSION['role'] = 'Applicant';
    $_SESSION['user_role'] = 'Applicant';
} else {
    // Keep both session keys consistent.
    $_SESSION['role'] = 'Applicant';
    $_SESSION['user_role'] = 'Applicant';
}

$user_id = (int)$_SESSION['user_id'];

function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function safe_timestamp(mixed $value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $timestamp = strtotime((string)$value);

    return $timestamp === false ? null : $timestamp;
}

function safe_date(mixed $value, string $format = 'd M Y', string $fallback = 'Not available'): string
{
    $timestamp = safe_timestamp($value);

    return $timestamp === null ? $fallback : date($format, $timestamp);
}

function safe_days_since(mixed $value): ?int
{
    $timestamp = safe_timestamp($value);

    if ($timestamp === null) {
        return null;
    }

    return max(0, (int)floor((time() - $timestamp) / 86400));
}

function safe_days_until(mixed $value): ?int
{
    $timestamp = safe_timestamp($value);

    if ($timestamp === null) {
        return null;
    }

    return (int)floor(($timestamp - time()) / 86400);
}

function row_value(array $row, string $key, string $fallback = 'Not provided'): string
{
    $value = $row[$key] ?? null;

    return ($value === null || trim((string)$value) === '')
        ? $fallback
        : (string)$value;
}

$applicant = [];

$stmt = $conn->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $applicant = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

if (!$applicant) {
    $_SESSION['error'] = 'Your applicant profile could not be found.';
    header('Location: logout');
    exit;
}

$applications = [];

$stmt = $conn->prepare("
    SELECT
        a.*,
        o.opportunity_title,
        o.opportunity_type,
        o.deadline,
        o.sectors_allowed,
        reviewer.full_name AS reviewer_name
    FROM applications a
    LEFT JOIN application_opportunities o
        ON a.opportunity_id = o.opportunity_id
    LEFT JOIN users reviewer
        ON a.reviewed_by = reviewer.user_id
    WHERE a.submitted_by = ?
    ORDER BY
        COALESCE(a.submitted_at, a.last_saved_at, a.created_at) DESC,
        a.application_id DESC
");

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }

    $stmt->close();
}

$stats = [
    'total'       => count($applications),
    'draft'       => 0,
    'pending'     => 0,
    'shortlisted' => 0,
    'accepted'    => 0,
    'rejected'    => 0,
];

foreach ($applications as $app) {
    $status = strtolower(trim((string)($app['status'] ?? '')));

    if (array_key_exists($status, $stats)) {
        $stats[$status]++;
    }
}

$available_opportunities = [];

$stmt = $conn->prepare("
    SELECT
        o.*,
        COUNT(a.application_id) AS application_count
    FROM application_opportunities o
    LEFT JOIN applications a
        ON o.opportunity_id = a.opportunity_id
       AND a.submitted_by = ?
    WHERE o.status IN ('Active', 'Published')
      AND (o.deadline IS NULL OR o.deadline >= CURDATE())
    GROUP BY o.opportunity_id
    HAVING application_count = 0
    ORDER BY
        CASE WHEN o.deadline IS NULL THEN 1 ELSE 0 END,
        o.deadline ASC,
        o.opportunity_id DESC
");

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $available_opportunities[] = $row;
    }

    $stmt->close();
}
?>

<style>
.dashboard-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.dashboard-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.welcome-text {
    font-size: 16px;
    opacity: 0.9;
}

.quick-actions {
    display: flex;
    gap: 15px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.quick-action-btn {
    background: rgba(255,255,255,0.2);
    color: white;
    padding: 12px 24px;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.3s;
    border: 1px solid rgba(255,255,255,0.3);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.quick-action-btn:hover {
    background: rgba(255,255,255,0.3);
    transform: translateY(-2px);
    color: white;
}

.application-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 5px solid #ff6b35;
    transition: transform 0.2s, box-shadow 0.2s;
}

.application-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.application-card.pending {
    border-left-color: #F39C12;
}

.application-card.shortlisted {
    border-left-color: #3498DB;
}

.application-card.accepted {
    border-left-color: #2ECC71;
}

.application-card.rejected {
    border-left-color: #E74C3C;
}

.app-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.app-title {
    flex: 1;
    min-width: 250px;
}

.app-title h3 {
    font-size: 22px;
    color: #2c3e50;
    margin-bottom: 5px;
}

.app-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.app-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}

.info-item label {
    font-size: 11px;
    color: #7f8c8d;
    text-transform: uppercase;
    display: block;
    margin-bottom: 5px;
}

.info-item .value {
    font-size: 14px;
    color: #2c3e50;
    font-weight: 600;
}

.app-actions {
    display: flex;
    gap: 10px;
    padding-top: 15px;
    border-top: 1px solid #ecf0f1;
    flex-wrap: wrap;
}

.opportunity-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.opportunity-card:hover {
    transform: translateY(-2px);
}

.opportunity-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
}

.opportunity-title {
    flex: 1;
}

.opportunity-title h3 {
    color: #2c3e50;
    margin-bottom: 5px;
}

.deadline-badge {
    background: #fff3cd;
    color: #856404;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}

.deadline-badge.urgent {
    background: #f8d7da;
    color: #721c24;
}

.opportunity-description {
    color: #7f8c8d;
    margin-bottom: 15px;
    line-height: 1.6;
}

.opportunity-details {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.opportunity-detail {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #7f8c8d;
    font-size: 14px;
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

.timeline {
    position: relative;
    padding-left: 30px;
    margin-top: 15px;
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
    padding: 12px;
    border-radius: 8px;
}

.timeline-content strong {
    color: #2c3e50;
    display: block;
    margin-bottom: 3px;
}

.timeline-content small {
    color: #7f8c8d;
}

@media (max-width: 768px) {
    .app-info-grid {
        grid-template-columns: 1fr;
    }
    
    .app-actions {
        flex-direction: column;
    }
    
    .app-actions a,
    .app-actions button {
        width: 100%;
    }
}

/* Dashboard application/opportunity tabs */
.dashboard-content-tabs {
    margin-top: 30px;
}

.dashboard-tab-nav {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
    padding: 6px;
    margin-bottom: 18px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    scrollbar-width: none;
}

.dashboard-tab-nav::-webkit-scrollbar {
    display: none;
}

.dashboard-tab-btn {
    appearance: none;
    border: 0;
    background: transparent;
    color: #64748b;
    min-height: 42px;
    padding: 10px 16px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: .2s ease;
}

.dashboard-tab-btn:hover {
    background: #f8fafc;
    color: #334155;
}

.dashboard-tab-btn.active {
    background: #fff7ed;
    color: #ea580c;
    box-shadow: inset 0 0 0 1px #fed7aa;
}

.dashboard-tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 7px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #64748b;
    font-size: 11px;
    font-weight: 800;
}

.dashboard-tab-btn.active .dashboard-tab-count {
    background: #ffedd5;
    color: #ea580c;
}

.dashboard-tab-panel {
    display: none;
}

.dashboard-tab-panel.active {
    display: block;
}

.dashboard-tab-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}

.dashboard-tab-card-header {
    padding: 16px 18px;
    border-bottom: 1px solid #edf0f2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.dashboard-tab-card-header h3 {
    margin: 0;
    font-size: 17px;
    color: #2c3e50;
}

.dashboard-tab-card-body {
    padding: 18px;
}

/* 3 cards per row on desktop */
.dashboard-card-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    align-items: stretch;
}

.dashboard-card-grid .application-card,
.dashboard-card-grid .opportunity-card {
    margin-bottom: 0;
    height: 100%;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.dashboard-card-grid .application-card .app-actions {
    margin-top: auto;
}

.dashboard-card-grid .opportunity-card > div:last-child {
    margin-top: auto;
}

.dashboard-card-grid .application-card {
    padding: 18px;
}

.dashboard-card-grid .opportunity-card {
    padding: 18px;
}

.dashboard-card-grid .app-info-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.dashboard-grid-footer {
    grid-column: 1 / -1;
    text-align: center;
    margin-top: 4px;
}

@media (max-width: 1200px) {
    .dashboard-card-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .dashboard-card-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-card-grid .app-info-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-tab-card-body {
        padding: 14px;
    }
}

</style>

<!-- Dashboard Header -->
<div class="dashboard-header">
    <h1><i class="fas fa-rocket"></i> Welcome, <?php echo h($applicant['full_name'] ?? 'Applicant'); ?>!</h1>
    <div class="welcome-text">
        Track your applications and discover new opportunities
    </div>
    
    <div class="quick-actions">
        <a href="#available-opportunities" class="quick-action-btn">
            <i class="fas fa-plus-circle"></i> Apply to Opportunity
        </a>
        <a href="user-profile" class="quick-action-btn">
            <i class="fas fa-user-edit"></i> Update Profile
        </a>
        <a href="my-applications" class="quick-action-btn">
            <i class="fas fa-list"></i> All Applications
        </a>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-clipboard-list" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total Applications</span>
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
            <span class="stat-label">Accepted</span>
            <span class="stat-value"><?php echo $stats['accepted']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-star" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Shortlisted</span>
            <span class="stat-value"><?php echo $stats['shortlisted']; ?></span>
        </div>
    </div>
</div>

<!-- Applications & Opportunities Tabs -->
<div class="dashboard-content-tabs">
    <div class="dashboard-tab-nav" role="tablist" aria-label="Applicant dashboard sections">
        <button
            type="button"
            class="dashboard-tab-btn active"
            data-dashboard-tab="recent"
            role="tab"
            aria-selected="true"
            aria-controls="dashboard-tab-recent"
        >
            <i class="fas fa-history"></i>
            Recent Applications
            <span class="dashboard-tab-count"><?php echo count($applications); ?></span>
        </button>

        <button
            type="button"
            class="dashboard-tab-btn"
            data-dashboard-tab="available"
            role="tab"
            aria-selected="false"
            aria-controls="dashboard-tab-available"
        >
            <i class="fas fa-clipboard-check"></i>
            Available Opportunities
            <span class="dashboard-tab-count"><?php echo count($available_opportunities); ?></span>
        </button>
    </div>

    <section
        class="dashboard-tab-panel active"
        id="dashboard-tab-recent"
        data-dashboard-panel="recent"
        role="tabpanel"
    >
        <div class="dashboard-tab-card">
            <div class="dashboard-tab-card-header">
                <h3><i class="fas fa-history"></i> Recent Applications</h3>
                <a href="my-applications" class="btn btn-secondary btn-sm">
                    <i class="fas fa-list"></i> All Applications
                </a>
            </div>
            <div class="dashboard-tab-card-body">

        <?php if (empty($applications)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Applications Yet</h3>
                <p style="color: #7f8c8d;">You haven't submitted any applications. Browse available opportunities below to get started!</p>
            </div>
        <?php else: ?>
            <div class="dashboard-card-grid">
            <?php 
            $recent_apps = array_slice($applications, 0, 3);
            foreach ($recent_apps as $app): 
                $status_class = strtolower(trim((string)($app['status'] ?? 'Draft')));
                $status_badges = [
                    'pending'     => 'warning',
                    'shortlisted' => 'info',
                    'accepted'    => 'success',
                    'rejected'    => 'danger',
                    'withdrawn'   => 'secondary'
                ];
                $badge = $status_badges[$status_class] ?? 'secondary';
                
                $submitted_at = $app['submitted_at'] ?? null;
                if (safe_timestamp($submitted_at) === null) {
                    $submitted_at = $app['last_saved_at'] ?? $app['created_at'] ?? null;
                }
                $days_ago = safe_days_since($submitted_at);
            ?>
            <div class="application-card <?php echo $status_class; ?>">
                <div class="app-header">
                    <div class="app-title">
                        <h3><?php echo h($app['opportunity_title'] ?? 'Opportunity'); ?></h3>
                        <div class="app-badges">
                            <span class="badge badge-<?php echo $badge; ?>">
                                <?php echo h($app['status'] ?? 'Draft'); ?>
                            </span>
                            <?php if (!empty($app['opportunity_type'])): ?>
                                <span class="badge badge-light">
                                    <i class="fas fa-tag"></i> <?php echo h($app['opportunity_type'] ?? ''); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($days_ago === 0): ?>
                                <span class="badge badge-primary">
                                    <i class="fas fa-star"></i> New Today
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="text-align: right;">
                        <div style="font-size: 12px; color: #7f8c8d;">Application ID</div>
                        <code style="font-size: 14px;">#<?php echo h($app['application_id'] ?? ''); ?></code>
                    </div>
                </div>
                
                <div class="app-info-grid">
                    <div class="info-item">
                        <label>Startup Name</label>
                        <div class="value"><?php echo h(row_value($app, 'startup_name')); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <label>Sector</label>
                        <div class="value"><?php echo h(row_value($app, 'sector')); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <label>Business Stage</label>
                        <div class="value"><?php echo h(row_value($app, 'business_stage')); ?></div>
                    </div>

                    <div class="info-item">
                        <label>Team Size</label>
                        <div class="value"><?php echo isset($app['team_size']) && $app['team_size'] !== null && $app['team_size'] !== '' ? h($app['team_size']) . ' members' : 'Not provided'; ?></div>
                    </div>

                    <div class="info-item">
                        <label>Submitted</label>
                        <div class="value">
                            <?php echo h(safe_date($submitted_at)); ?>
                            <small style="color: #7f8c8d; display: block; font-weight: normal;">
                                <?php echo $days_ago === null ? 'Date not available' : ($days_ago === 0 ? 'Today' : h($days_ago) . ' days ago'); ?>
                            </small>
                        </div>
                    </div>

                    <?php if (isset($app['score']) && $app['score'] !== null && $app['score'] !== ''): ?>
                    <div class="info-item">
                        <label>Score</label>
                        <div class="value"><?php echo h($app['score'] ?? ''); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($app['reviewer_notes'])): ?>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 5px; display: block;">
                        Reviewer Notes
                    </label>
                    <div style="color: #2c3e50;"><?php echo nl2br(h($app['reviewer_notes'] ?? '')); ?></div>
                    <?php if (!empty($app['reviewer_name'])): ?>
                        <small style="color: #7f8c8d; margin-top: 10px; display: block;">
                            By <?php echo h($app['reviewer_name'] ?? ''); ?>
                            on <?php echo h(safe_date($app['reviewed_at'] ?? null)); ?>
                        </small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (($app['status'] ?? '') === 'Rejected' && !empty($app['rejection_reason'])): ?>
                <div style="background: #fdf0f0; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 3px solid #E74C3C;">
                    <label style="font-size: 11px; color: #E74C3C; text-transform: uppercase; margin-bottom: 5px; display: block;">
                        Rejection Reason
                    </label>
                    <div style="color: #2c3e50;"><?php echo nl2br(h($app['rejection_reason'] ?? '')); ?></div>
                </div>
                <?php endif; ?>
                
                <!-- Timeline -->
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-content">
                            <strong>Application Submitted</strong>
                            <small><?php echo h(safe_date($submitted_at, 'd M Y, h:i A')); ?></small>
                        </div>
                    </div>
                    
                    <?php if (!empty($app['reviewed_at'])): ?>
                    <div class="timeline-item">
                        <div class="timeline-content">
                            <strong>Application Reviewed</strong>
                            <small>
                                <?php if (!empty($app['reviewer_name'])): ?>
                                    By <?php echo h($app['reviewer_name'] ?? ''); ?><br>
                                <?php endif; ?>
                                <?php echo h(safe_date($app['reviewed_at'] ?? null, 'd M Y, h:i A')); ?>
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (($app['status'] ?? '') === 'Shortlisted'): ?>
                    <div class="timeline-item">
                        <div class="timeline-content">
                            <strong style="color: #3498DB;">Shortlisted</strong>
                            <small>Your application has been shortlisted for further review.</small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (($app['status'] ?? '') === 'Accepted'): ?>
                    <div class="timeline-item">
                        <div class="timeline-content">
                            <strong style="color: #2ECC71;">Application Accepted</strong>
                            <small>Congratulations on your acceptance!</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="app-actions">
                    <a href="view-my-application?id=<?php echo $app['application_id']; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    
                    <?php if (($app['status'] ?? '') === 'Draft'): ?>
                        <a href="submit-application?opportunity_id=<?php echo (int)($app['opportunity_id'] ?? 0); ?>&amp;draft_id=<?php echo (int)$app['application_id']; ?>" class="btn btn-warning btn-sm">
                            <i class="fas fa-edit"></i> Continue Draft
                        </a>
                    <?php endif; ?>
                    
                    <a href="contact-support?app_id=<?php echo $app['application_id']; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-envelope"></i> Contact Support
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (count($applications) > 3): ?>
                <div class="dashboard-grid-footer">
                    <a href="my-applications" class="btn btn-primary">
                        <i class="fas fa-list"></i> View All <?php echo count($applications); ?> Applications
                    </a>
                </div>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    
            </div>
        </div>
    </section>

    <section
        class="dashboard-tab-panel"
        id="dashboard-tab-available"
        data-dashboard-panel="available"
        role="tabpanel"
        hidden
    >
        <div class="dashboard-tab-card">
            <div class="dashboard-tab-card-header">
                <h3><i class="fas fa-clipboard-check"></i> Available Opportunities</h3>
                <a href="opportunities" class="btn btn-secondary btn-sm">
                    <i class="fas fa-search"></i> Browse All
                </a>
            </div>
            <div class="dashboard-tab-card-body">

        <?php if (empty($available_opportunities)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>No New Opportunities Available</h3>
                <p style="color: #7f8c8d;">
                    You've applied to all available opportunities. Check back later for new ones!
                </p>
            </div>
        <?php else: ?>
            <div class="dashboard-card-grid">
            <?php foreach ($available_opportunities as $opportunity): 
                $deadline = $opportunity['deadline'] ?? null;
                $deadline_ts = safe_timestamp($deadline);
                $days_left = safe_days_until($deadline);
                $is_urgent = $days_left !== null && $days_left >= 0 && $days_left <= 7;
            ?>
            <div class="opportunity-card">
                <div class="opportunity-header">
                    <div class="opportunity-title">
                        <h3><?php echo h($opportunity['opportunity_title'] ?? 'Opportunity'); ?></h3>
                        <small style="color: #7f8c8d;">
                            <i class="fas fa-tag"></i> <?php echo h($opportunity['opportunity_type'] ?? ''); ?>
                            <?php if (!empty($opportunity['is_featured'])): ?>
                                &nbsp;<span class="badge badge-warning"><i class="fas fa-star"></i> Featured</span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="deadline-badge <?php echo $deadline_ts === null ? '' : ($is_urgent ? 'urgent' : ''); ?>">
                        <i class="fas fa-clock"></i>
                        <?php 
                        if ($days_left === null) {
                            echo 'No Deadline';
                        } elseif ($days_left < 0) {
                            echo 'Deadline Passed';
                        } elseif ($days_left === 0) {
                            echo 'Last Day!';
                        } elseif ($days_left === 1) {
                            echo '1 Day Left';
                        } else {
                            echo h($days_left) . ' Days Left';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="opportunity-description">
                    <?php echo nl2br(h(mb_substr((string)($opportunity['description'] ?? ''), 0, 200))); ?>
                    <?php if (mb_strlen((string)($opportunity['description'] ?? '')) > 200): ?>...<?php endif; ?>
                </div>
                
                <div class="opportunity-details">
                    <div class="opportunity-detail">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Deadline: <?php echo h(safe_date($deadline, 'd M Y', 'No deadline')); ?></span>
                    </div>

                    <?php if (!empty($opportunity['start_date'])): ?>
                    <div class="opportunity-detail">
                        <i class="fas fa-play-circle"></i>
                        <span>Starts: <?php echo h(safe_date($opportunity['start_date'] ?? null)); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($opportunity['available_slots']) && $opportunity['available_slots'] !== null && $opportunity['available_slots'] !== ''): ?>
                    <div class="opportunity-detail">
                        <i class="fas fa-users"></i>
                        <span>Slots Available: <?php echo h($opportunity['available_slots'] ?? ''); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($opportunity['sectors_allowed'])): ?>
                    <div class="opportunity-detail">
                        <i class="fas fa-industry"></i>
                        <span>Sectors: <?php echo h($opportunity['sectors_allowed'] ?? ''); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($opportunity['min_team_size']) || !empty($opportunity['max_team_size'])): ?>
                    <div class="opportunity-detail">
                        <i class="fas fa-user-friends"></i>
                        <span>Team Size: 
                            <?php 
                            if (!empty($opportunity['min_team_size']) && !empty($opportunity['max_team_size'])) {
                                echo $opportunity['min_team_size'] . ' - ' . $opportunity['max_team_size'] . ' members';
                            } elseif (!empty($opportunity['min_team_size'])) {
                                echo 'Min ' . $opportunity['min_team_size'] . ' members';
                            } else {
                                echo 'Max ' . $opportunity['max_team_size'] . ' members';
                            }
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($opportunity['eligibility_criteria'])): ?>
                <div style="background: #f0f9ff; padding: 12px 15px; border-radius: 8px; margin-bottom: 15px; border-left: 3px solid #3498DB;">
                    <label style="font-size: 11px; color: #3498DB; text-transform: uppercase; display: block; margin-bottom: 4px;">
                        Eligibility Criteria
                    </label>
                    <div style="color: #2c3e50; font-size: 14px;">
                        <?php echo nl2br(h(mb_substr((string)($opportunity['eligibility_criteria'] ?? ''), 0, 150))); ?>
                        <?php if (mb_strlen((string)($opportunity['eligibility_criteria'] ?? '')) > 150): ?>...<?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="submit-application?opportunity_id=<?php echo (int)($opportunity['opportunity_id'] ?? 0); ?>" class="btn btn-success">
                        <i class="fas fa-paper-plane"></i> Apply Now
                    </a>
                    <a href="view-opportunity?id=<?php echo $opportunity['opportunity_id']; ?>" class="btn btn-info">
                        <i class="fas fa-info-circle"></i> Learn More
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    const buttons = Array.from(document.querySelectorAll('[data-dashboard-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-dashboard-panel]'));

    function activateDashboardTab(name) {
        buttons.forEach(function (button) {
            const active = button.dataset.dashboardTab === name;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        panels.forEach(function (panel) {
            const active = panel.dataset.dashboardPanel === name;
            panel.classList.toggle('active', active);
            panel.hidden = !active;
        });
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            activateDashboardTab(button.dataset.dashboardTab || 'recent');
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>