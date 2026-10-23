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
/* Applicant dashboard: application / opportunity cards, 3 per row on desktop. */
.ad-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.ad-panel-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: var(--ink-700); }
.ad-card-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; align-items: stretch; }
.ad-grid-footer { grid-column: 1 / -1; text-align: center; margin-top: 4px; }

.ad-card { display: flex; flex-direction: column; min-width: 0; height: 100%; }
.ad-card .card-body { display: flex; flex-direction: column; flex: 1; padding: 18px; }
.ad-card-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: auto; padding-top: 15px; border-top: 1px solid var(--ink-50); }

/* Application card: left accent follows the application status. */
.ad-app-card { border-left: 5px solid var(--brand-500); }
.ad-app-card.is-pending { border-left-color: var(--amber-fg); }
.ad-app-card.is-shortlisted { border-left-color: var(--blue-fg); }
.ad-app-card.is-accepted { border-left-color: var(--green-fg); }
.ad-app-card.is-rejected { border-left-color: var(--red-fg); }

.ad-card-head { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.ad-card-title { flex: 1; min-width: 0; }
.ad-card-title h3 { margin: 0 0 6px; font-size: 17px; color: var(--ink-700); }
.ad-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.ad-app-id { text-align: right; }
.ad-app-id code { font-size: 14px; }

.ad-info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.ad-info-item { background: var(--ink-50); padding: 12px; border-radius: var(--radius-sm); }
.ad-label { display: block; margin-bottom: 4px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-300); }
.ad-value { font-size: 14px; font-weight: 600; color: var(--ink-700); }

.ad-note { background: var(--ink-50); padding: 14px; border-radius: var(--radius-sm); margin-bottom: 15px; color: var(--ink-700); }
.ad-note.is-danger { background: var(--red-bg); border-left: 3px solid var(--red-fg); }
.ad-note.is-danger .ad-label { color: var(--red-fg); }
.ad-note.is-info { background: var(--blue-bg); border-left: 3px solid var(--blue-fg); font-size: 14px; }
.ad-note.is-info .ad-label { color: var(--blue-fg); }
.ad-note .cell-muted { margin-top: 8px; }

.ad-timeline { position: relative; padding-left: 30px; margin-bottom: 15px; }
.ad-timeline-item { position: relative; padding-bottom: 16px; }
.ad-timeline-item:last-child { padding-bottom: 0; }
.ad-timeline-item::before { content: ''; position: absolute; left: -30px; top: 5px; width: 12px; height: 12px; border-radius: 50%; background: var(--brand-500); }
.ad-timeline-item::after { content: ''; position: absolute; left: -25px; top: 17px; width: 2px; height: calc(100% - 12px); background: var(--ink-50); }
.ad-timeline-item:last-child::after { display: none; }
.ad-timeline-content { background: var(--ink-50); padding: 12px; border-radius: var(--radius-sm); }
.ad-timeline-content strong { display: block; margin-bottom: 3px; color: var(--ink-700); }
.ad-timeline-content strong.is-info { color: var(--blue-fg); }
.ad-timeline-content strong.is-success { color: var(--green-fg); }
.ad-timeline-content small { color: var(--ink-300); }

.ad-opp-desc { color: var(--ink-300); margin-bottom: 15px; line-height: 1.6; }
.ad-opp-details { display: flex; gap: 8px 20px; margin-bottom: 15px; flex-wrap: wrap; }
.ad-opp-detail { display: flex; align-items: center; gap: 5px; color: var(--ink-300); font-size: 14px; }

@media (max-width: 1200px) { .ad-card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 768px) {
    .ad-card-grid, .ad-info-grid { grid-template-columns: 1fr; }
    .ad-card-actions { flex-direction: column; }
    .ad-card-actions .btn { width: 100%; justify-content: center; }
}
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-rocket" aria-hidden="true"></i> Welcome, <?php echo h($applicant['full_name'] ?? 'Applicant'); ?>!</h1>
        <p>Track your applications and discover new opportunities.</p>
    </div>

    <div class="hero-actions">
        <a href="my-applications" class="btn">
            <i class="fas fa-list" aria-hidden="true"></i> All Applications
        </a>
        <a href="user-profile" class="btn">
            <i class="fas fa-user-edit" aria-hidden="true"></i> Update Profile
        </a>
        <a href="#available-opportunities" class="btn btn-primary">
            <i class="fas fa-plus-circle" aria-hidden="true"></i> Apply to Opportunity
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
<div class="tab-shell">
    <div class="tab-header" role="tablist" aria-label="Applicant dashboard sections">
        <button
            type="button"
            class="tab-btn active"
            data-dashboard-tab="recent"
            role="tab"
            aria-selected="true"
            aria-controls="dashboard-tab-recent"
        >
            <i class="fas fa-history" aria-hidden="true"></i>
            Recent Applications
            <span class="badge badge-light"><?php echo count($applications); ?></span>
        </button>

        <button
            type="button"
            class="tab-btn"
            data-dashboard-tab="available"
            role="tab"
            aria-selected="false"
            aria-controls="dashboard-tab-available"
        >
            <i class="fas fa-clipboard-check" aria-hidden="true"></i>
            Available Opportunities
            <span class="badge badge-light"><?php echo count($available_opportunities); ?></span>
        </button>
    </div>

    <div class="tab-panels">
    <section
        class="tab-panel active"
        id="dashboard-tab-recent"
        data-dashboard-panel="recent"
        role="tabpanel"
    >
        <div class="ad-panel-head">
            <h3><i class="fas fa-history" aria-hidden="true"></i> Recent Applications</h3>
            <a href="my-applications" class="btn btn-secondary btn-sm">
                <i class="fas fa-list" aria-hidden="true"></i> All Applications
            </a>
        </div>

        <?php if (empty($applications)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <h4>No Applications Yet</h4>
                <p>You haven't submitted any applications. Browse available opportunities below to get started!</p>
            </div>
        <?php else: ?>
            <div class="ad-card-grid">
            <?php
            $recent_apps = array_slice($applications, 0, 3);
            foreach ($recent_apps as $app):
                $status_class = strtolower(trim((string)($app['status'] ?? 'Draft')));
                $status_badges = [
                    'pending'     => 'pending',
                    'shortlisted' => 'assigned',
                    'accepted'    => 'available',
                    'rejected'    => 'rejected',
                    'withdrawn'   => 'returned'
                ];
                $badge = $status_badges[$status_class] ?? 'returned';

                $submitted_at = $app['submitted_at'] ?? null;
                if (safe_timestamp($submitted_at) === null) {
                    $submitted_at = $app['last_saved_at'] ?? $app['created_at'] ?? null;
                }
                $days_ago = safe_days_since($submitted_at);
            ?>
            <div class="card ad-card ad-app-card is-<?php echo h($status_class); ?>">
              <div class="card-body">
                <div class="ad-card-head">
                    <div class="ad-card-title">
                        <h3><?php echo h($app['opportunity_title'] ?? 'Opportunity'); ?></h3>
                        <div class="ad-badges">
                            <span class="badge badge-<?php echo $badge; ?>">
                                <?php echo h($app['status'] ?? 'Draft'); ?>
                            </span>
                            <?php if (!empty($app['opportunity_type'])): ?>
                                <span class="badge badge-light">
                                    <i class="fas fa-tag" aria-hidden="true"></i> <?php echo h($app['opportunity_type'] ?? ''); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($days_ago === 0): ?>
                                <span class="badge badge-purple">
                                    <i class="fas fa-star" aria-hidden="true"></i> New Today
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-app-id">
                        <span class="ad-label">Application ID</span>
                        <code>#<?php echo h($app['application_id'] ?? ''); ?></code>
                    </div>
                </div>

                <div class="ad-info-grid">
                    <div class="ad-info-item">
                        <span class="ad-label">Startup Name</span>
                        <div class="ad-value"><?php echo h(row_value($app, 'startup_name')); ?></div>
                    </div>

                    <div class="ad-info-item">
                        <span class="ad-label">Sector</span>
                        <div class="ad-value"><?php echo h(row_value($app, 'sector')); ?></div>
                    </div>

                    <div class="ad-info-item">
                        <span class="ad-label">Business Stage</span>
                        <div class="ad-value"><?php echo h(row_value($app, 'business_stage')); ?></div>
                    </div>

                    <div class="ad-info-item">
                        <span class="ad-label">Team Size</span>
                        <div class="ad-value"><?php echo isset($app['team_size']) && $app['team_size'] !== null && $app['team_size'] !== '' ? h($app['team_size']) . ' members' : 'Not provided'; ?></div>
                    </div>

                    <div class="ad-info-item">
                        <span class="ad-label">Submitted</span>
                        <div class="ad-value">
                            <?php echo h(safe_date($submitted_at)); ?>
                            <small class="cell-muted">
                                <?php echo $days_ago === null ? 'Date not available' : ($days_ago === 0 ? 'Today' : h($days_ago) . ' days ago'); ?>
                            </small>
                        </div>
                    </div>

                    <?php if (isset($app['score']) && $app['score'] !== null && $app['score'] !== ''): ?>
                    <div class="ad-info-item">
                        <span class="ad-label">Score</span>
                        <div class="ad-value"><?php echo h($app['score'] ?? ''); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($app['reviewer_notes'])): ?>
                <div class="ad-note">
                    <span class="ad-label">Reviewer Notes</span>
                    <div><?php echo nl2br(h($app['reviewer_notes'] ?? '')); ?></div>
                    <?php if (!empty($app['reviewer_name'])): ?>
                        <small class="cell-muted">
                            By <?php echo h($app['reviewer_name'] ?? ''); ?>
                            on <?php echo h(safe_date($app['reviewed_at'] ?? null)); ?>
                        </small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (($app['status'] ?? '') === 'Rejected' && !empty($app['rejection_reason'])): ?>
                <div class="ad-note is-danger">
                    <span class="ad-label">Rejection Reason</span>
                    <div><?php echo nl2br(h($app['rejection_reason'] ?? '')); ?></div>
                </div>
                <?php endif; ?>

                <!-- Timeline -->
                <div class="ad-timeline">
                    <div class="ad-timeline-item">
                        <div class="ad-timeline-content">
                            <strong>Application Submitted</strong>
                            <small><?php echo h(safe_date($submitted_at, 'd M Y, h:i A')); ?></small>
                        </div>
                    </div>

                    <?php if (!empty($app['reviewed_at'])): ?>
                    <div class="ad-timeline-item">
                        <div class="ad-timeline-content">
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
                    <div class="ad-timeline-item">
                        <div class="ad-timeline-content">
                            <strong class="is-info">Shortlisted</strong>
                            <small>Your application has been shortlisted for further review.</small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (($app['status'] ?? '') === 'Accepted'): ?>
                    <div class="ad-timeline-item">
                        <div class="ad-timeline-content">
                            <strong class="is-success">Application Accepted</strong>
                            <small>Congratulations on your acceptance!</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="ad-card-actions">
                    <a href="view-my-application?id=<?php echo $app['application_id']; ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-eye" aria-hidden="true"></i> View Details
                    </a>

                    <?php if (($app['status'] ?? '') === 'Draft'): ?>
                        <a href="submit-application?opportunity_id=<?php echo (int)($app['opportunity_id'] ?? 0); ?>&amp;draft_id=<?php echo (int)$app['application_id']; ?>" class="btn btn-warning btn-sm">
                            <i class="fas fa-edit" aria-hidden="true"></i> Continue Draft
                        </a>
                    <?php endif; ?>

                    <a href="contact-support?app_id=<?php echo $app['application_id']; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-envelope" aria-hidden="true"></i> Contact Support
                    </a>
                </div>
              </div>
            </div>
            <?php endforeach; ?>

            <?php if (count($applications) > 3): ?>
                <div class="ad-grid-footer">
                    <a href="my-applications" class="btn btn-primary">
                        <i class="fas fa-list" aria-hidden="true"></i> View All <?php echo count($applications); ?> Applications
                    </a>
                </div>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section
        class="tab-panel"
        id="dashboard-tab-available"
        data-dashboard-panel="available"
        role="tabpanel"
        hidden
    >
        <div class="ad-panel-head">
            <h3><i class="fas fa-clipboard-check" aria-hidden="true"></i> Available Opportunities</h3>
            <a href="opportunities" class="btn btn-secondary btn-sm">
                <i class="fas fa-search" aria-hidden="true"></i> Browse All
            </a>
        </div>

        <?php if (empty($available_opportunities)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <h4>No New Opportunities Available</h4>
                <p>You've applied to all available opportunities. Check back later for new ones!</p>
            </div>
        <?php else: ?>
            <div class="ad-card-grid">
            <?php foreach ($available_opportunities as $opportunity):
                $deadline = $opportunity['deadline'] ?? null;
                $deadline_ts = safe_timestamp($deadline);
                $days_left = safe_days_until($deadline);
                $is_urgent = $days_left !== null && $days_left >= 0 && $days_left <= 7;
            ?>
            <div class="card ad-card">
              <div class="card-body">
                <div class="ad-card-head">
                    <div class="ad-card-title">
                        <h3><?php echo h($opportunity['opportunity_title'] ?? 'Opportunity'); ?></h3>
                        <small class="cell-muted">
                            <i class="fas fa-tag" aria-hidden="true"></i> <?php echo h($opportunity['opportunity_type'] ?? ''); ?>
                            <?php if (!empty($opportunity['is_featured'])): ?>
                                &nbsp;<span class="badge badge-pending"><i class="fas fa-star" aria-hidden="true"></i> Featured</span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <span class="badge <?php echo $deadline_ts === null ? 'badge-returned' : ($is_urgent ? 'badge-rejected' : 'badge-pending'); ?>">
                        <i class="fas fa-clock" aria-hidden="true"></i>
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
                    </span>
                </div>

                <div class="ad-opp-desc">
                    <?php echo nl2br(h(mb_substr((string)($opportunity['description'] ?? ''), 0, 200))); ?>
                    <?php if (mb_strlen((string)($opportunity['description'] ?? '')) > 200): ?>...<?php endif; ?>
                </div>

                <div class="ad-opp-details">
                    <div class="ad-opp-detail">
                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                        <span>Deadline: <?php echo h(safe_date($deadline, 'd M Y', 'No deadline')); ?></span>
                    </div>

                    <?php if (!empty($opportunity['start_date'])): ?>
                    <div class="ad-opp-detail">
                        <i class="fas fa-play-circle" aria-hidden="true"></i>
                        <span>Starts: <?php echo h(safe_date($opportunity['start_date'] ?? null)); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($opportunity['available_slots']) && $opportunity['available_slots'] !== null && $opportunity['available_slots'] !== ''): ?>
                    <div class="ad-opp-detail">
                        <i class="fas fa-users" aria-hidden="true"></i>
                        <span>Slots Available: <?php echo h($opportunity['available_slots'] ?? ''); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($opportunity['sectors_allowed'])): ?>
                    <div class="ad-opp-detail">
                        <i class="fas fa-industry" aria-hidden="true"></i>
                        <span>Sectors: <?php echo h($opportunity['sectors_allowed'] ?? ''); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($opportunity['min_team_size']) || !empty($opportunity['max_team_size'])): ?>
                    <div class="ad-opp-detail">
                        <i class="fas fa-user-friends" aria-hidden="true"></i>
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
                <div class="ad-note is-info">
                    <span class="ad-label">Eligibility Criteria</span>
                    <div>
                        <?php echo nl2br(h(mb_substr((string)($opportunity['eligibility_criteria'] ?? ''), 0, 150))); ?>
                        <?php if (mb_strlen((string)($opportunity['eligibility_criteria'] ?? '')) > 150): ?>...<?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="ad-card-actions">
                    <a href="submit-application?opportunity_id=<?php echo (int)($opportunity['opportunity_id'] ?? 0); ?>" class="btn btn-primary">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i> Apply Now
                    </a>
                    <a href="view-opportunity?id=<?php echo $opportunity['opportunity_id']; ?>" class="btn btn-info">
                        <i class="fas fa-info-circle" aria-hidden="true"></i> Learn More
                    </a>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    </div>
</div>

</div><!-- /.page-stack -->

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