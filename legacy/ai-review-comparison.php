<?php
session_start();
date_default_timezone_set('Africa/Nairobi');

require_once 'includes/config.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $res = $stmt->get_result();

    $out = [];

    while ($r = $res->fetch_assoc()) {
        $out[] = $r;
    }

    $stmt->close();

    return $out;
}

if (!ims_allowed('ai_review.compare', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer', 'Reviewer', 'Program Manager', 'Program Director'], 'Compare AI and human reviews of an application')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard');
    exit;
}

$applicationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$reviewTypeId  = filter_input(INPUT_GET, 'rt', FILTER_VALIDATE_INT) ?: 0;

if ($applicationId <= 0 || $reviewTypeId <= 0) {
    header('Location: startups-shortlisting');
    exit;
}

/* =========================================================
   APPLICATION
========================================================= */
$app = rows($conn, "
    SELECT
        a.application_id,
        a.startup_name,
        a.sector,
        a.business_stage,
        a.status,
        a.funding_sought,
        ao.opportunity_title,
        rt.name AS review_type_name
    FROM applications a
    LEFT JOIN application_opportunities ao
        ON ao.opportunity_id = a.opportunity_id
    LEFT JOIN review_types rt
        ON rt.review_type_id = a.review_type_id
    WHERE a.application_id = ?
    LIMIT 1
", 'i', [$applicationId])[0] ?? null;

if (!$app) {
    $_SESSION['error'] = 'Application not found.';
    header('Location: startups-shortlisting');
    exit;
}

/* =========================================================
   REVIEW SUMMARY
========================================================= */
$summary = rows($conn, "
    SELECT
        ROUND(AVG(ar.overall_score),2) reviewer_avg,
        MIN(ar.overall_score) reviewer_min,
        MAX(ar.overall_score) reviewer_max,
        COUNT(ar.review_id) reviewer_count,

        (
            SELECT air.overall_score
            FROM ai_application_reviews air
            WHERE air.application_id = ?
              AND air.review_type_id = ?
              AND air.status = 'Completed'
            ORDER BY air.ai_review_id DESC
            LIMIT 1
        ) ai_score,

        (
            SELECT air.recommendation
            FROM ai_application_reviews air
            WHERE air.application_id = ?
              AND air.review_type_id = ?
              AND air.status = 'Completed'
            ORDER BY air.ai_review_id DESC
            LIMIT 1
        ) ai_recommendation,

        (
            SELECT air.summary
            FROM ai_application_reviews air
            WHERE air.application_id = ?
              AND air.review_type_id = ?
              AND air.status = 'Completed'
            ORDER BY air.ai_review_id DESC
            LIMIT 1
        ) ai_summary

    FROM application_reviews ar
    WHERE ar.application_id = ?
      AND ar.review_type_id = ?
", 'iiiiiiii', [
    $applicationId,
    $reviewTypeId,
    $applicationId,
    $reviewTypeId,
    $applicationId,
    $reviewTypeId,
    $applicationId,
    $reviewTypeId
])[0] ?? [];

/* =========================================================
   CRITERIA COMPARISON
========================================================= */
$criteria = rows($conn, "
    SELECT
        rc.criteria_id,
        rc.category,
        rc.question,
        rc.max_score,
        rc.weight,

        ROUND(AVG(rs.score),2) reviewer_avg_score,

        MAX(airs.score) ai_score,
        MAX(airs.comments) ai_comments

    FROM review_criteria rc

    LEFT JOIN review_scores rs
        ON rs.criteria_id = rc.criteria_id
       AND rs.application_id = ?
       AND rs.review_type_id = ?

    LEFT JOIN ai_application_reviews air
        ON air.application_id = ?
       AND air.review_type_id = ?
       AND air.status = 'Completed'

    LEFT JOIN ai_application_review_scores airs
        ON airs.ai_review_id = air.ai_review_id
       AND airs.criteria_id = rc.criteria_id

    WHERE rc.review_type_id = ?
      AND rc.is_active = 1

    GROUP BY rc.criteria_id

    ORDER BY rc.category ASC, rc.criteria_id ASC
", 'iiiii', [
    $applicationId,
    $reviewTypeId,
    $applicationId,
    $reviewTypeId,
    $reviewTypeId
]);

$page_title = 'AI vs Reviewer Comparison';

require_once 'includes/header.php';
?>

<style>
/* AI vs reviewer comparison: two detail cards side by side, stacked below 900px. */
.ac-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
.ac-meta { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-bottom: 14px; color: var(--ink-400); font-size: 13px; }
.ac-meta i { color: var(--brand-500); margin-right: 4px; }
.ac-badges { display: flex; flex-wrap: wrap; gap: 6px; }
.ac-summary { white-space: pre-wrap; line-height: 1.8; color: var(--ink-500); }
.ac-stat-text { font-size: 18px; letter-spacing: 0; }
.ac-criterion { min-width: 250px; }
.ac-comment { min-width: 280px; line-height: 1.6; color: var(--ink-400); }
.ac-card-body-flush { padding: 0; }
.ac-card-body-flush .table-responsive { margin-top: 0; }
@media (max-width: 900px) { .ac-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-stack">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-robot" aria-hidden="true"></i> AI vs Reviewer Comparison</h1>
            <p>
                <?= h($app['startup_name'] ?? 'Application') ?>
                -
                <?= h($app['opportunity_title'] ?? '-') ?>
            </p>
        </div>

        <div class="hero-actions">
            <a href="startups-shortlisting" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
            </a>
            <a href="review-applicant?id=<?= (int)$applicationId ?>" class="btn">
                <i class="fas fa-user-check" aria-hidden="true"></i> Manual Reviews
            </a>
            <a href="ai-review-applicant?id=<?= (int)$applicationId ?>" class="btn btn-primary">
                <i class="fas fa-robot" aria-hidden="true"></i> AI Reviews
            </a>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-robot" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">AI Score</span>
                <span class="stat-value"><?= number_format((float)($summary['ai_score'] ?? 0),1) ?>%</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-users" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Reviewer Average</span>
                <span class="stat-value"><?= number_format((float)($summary['reviewer_avg'] ?? 0),1) ?>%</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-user-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Reviewers</span>
                <span class="stat-value"><?= (int)($summary['reviewer_count'] ?? 0) ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-lightbulb" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">AI Recommendation</span>
                <span class="stat-value ac-stat-text"><?= h($summary['ai_recommendation'] ?? '-') ?></span>
            </div>
        </div>
    </div>

    <!-- APPLICATION DETAILS -->
    <div class="ac-grid">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-building" aria-hidden="true"></i> Application Details</h3>
            </div>
            <div class="card-body">
                <div class="ac-meta">
                    <span><i class="fas fa-layer-group" aria-hidden="true"></i><?= h($app['sector'] ?? '-') ?></span>
                    <span><i class="fas fa-chart-line" aria-hidden="true"></i><?= h($app['business_stage'] ?? '-') ?></span>
                    <span><i class="fas fa-money-bill-wave" aria-hidden="true"></i>UGX <?= number_format((float)($app['funding_sought'] ?? 0),2) ?></span>
                </div>

                <div class="ac-badges">
                    <span class="badge badge-assigned"><?= h($app['review_type_name'] ?? '-') ?></span>
                    <span class="badge badge-available"><?= h($app['status'] ?? '-') ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-align-left" aria-hidden="true"></i> AI Summary</h3>
            </div>
            <div class="card-body">
                <div class="ac-summary"><?= nl2br(h($summary['ai_summary'] ?? 'No AI review generated yet.')) ?></div>
            </div>
        </div>
    </div>

    <!-- COMPARISON TABLE -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-balance-scale" aria-hidden="true"></i> AI vs Human Reviewer Comparison</h3>
        </div>

        <div class="card-body ac-card-body-flush">
            <?php if (empty($criteria)): ?>
                <div class="empty-state">
                    <i class="fas fa-balance-scale" aria-hidden="true"></i>
                    <p>No comparison criteria found.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Criterion</th>
                            <th>AI Score</th>
                            <th>Reviewer Avg</th>
                            <th>Difference</th>
                            <th>Weight</th>
                            <th>AI Comment</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($criteria as $c): ?>
                        <?php
                            $ai   = (float)($c['ai_score'] ?? 0);
                            $rv   = (float)($c['reviewer_avg_score'] ?? 0);
                            $diff = $ai - $rv;

                            $diffClass = abs($diff) >= 2
                                ? 'badge-rejected'
                                : 'badge-available';
                        ?>
                        <tr>
                            <td>
                                <span class="badge badge-purple"><?= h($c['category']) ?></span>
                            </td>

                            <td class="ac-criterion">
                                <strong><?= h($c['question']) ?></strong>
                            </td>

                            <td>
                                <span class="badge badge-assigned">
                                    <?= number_format($ai,1) ?>
                                    /
                                    <?= number_format((float)$c['max_score'],0) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge badge-available">
                                    <?= number_format($rv,1) ?>
                                    /
                                    <?= number_format((float)$c['max_score'],0) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= $diffClass ?>">
                                    <?= ($diff > 0 ? '+' : '') . number_format($diff,1) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge badge-returned"><?= h($c['weight']) ?></span>
                            </td>

                            <td class="ac-comment">
                                <?= nl2br(h($c['ai_comments'] ?? '-')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.page-stack -->

<?php require_once 'includes/footer.php'; ?>
