<?php
ob_start();
session_start();
date_default_timezone_set('Africa/Nairobi');

require_once 'includes/config.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirectWithError(string $msg, string $url = 'startups-shortlisting'): void {
    $_SESSION['error'] = $msg;
    header("Location: {$url}");
    exit;
}

if (!ims_allowed('ai_review.view', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer', 'Program Manager', 'Program Director', 'Reviewer'], 'Open the AI review page for an application')) {
    redirectWithError('Access denied.', 'dashboard');
}

$application_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$application_id) {
    redirectWithError('Application not specified.');
}

function fetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function fetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

$app = fetchOne($conn, "
    SELECT 
        a.*,
        ao.opportunity_title,
        ao.opportunity_type,
        ao.eligibility_criteria,
        rt.name AS review_type_name
    FROM applications a
    LEFT JOIN application_opportunities ao 
        ON ao.opportunity_id = a.opportunity_id
    LEFT JOIN review_types rt
        ON rt.review_type_id = a.review_type_id
    WHERE a.application_id = ?
    LIMIT 1
", 'i', [$application_id]);

if (!$app) {
    redirectWithError('Application not found.');
}

$review_type_id = (int)($app['review_type_id'] ?? 0);

$aiApis = fetchAll($conn, "
    SELECT 
        ai_api_id,
        provider_name,
        api_name,
        model_name,
        api_key_last4,
        is_default,
        is_active
    FROM ai_api
    WHERE is_active = 1
    ORDER BY is_default DESC, provider_name ASC, api_name ASC
");

$reviewerSummary = fetchOne($conn, "
    SELECT
        COUNT(*) AS total_reviews,
        ROUND(AVG(overall_score), 2) AS avg_score,
        MIN(overall_score) AS min_score,
        MAX(overall_score) AS max_score
    FROM application_reviews
    WHERE application_id = ?
      AND review_type_id = ?
", 'ii', [$application_id, $review_type_id]);

$criteriaComparison = fetchAll($conn, "
    SELECT
        rc.criteria_id,
        rc.category,
        rc.question,
        rc.max_score,
        rc.weight,
        ROUND(AVG(rs.score), 2) AS reviewer_avg_score
    FROM review_criteria rc
    LEFT JOIN review_scores rs
        ON rs.criteria_id = rc.criteria_id
       AND rs.application_id = ?
       AND rs.review_type_id = ?
    WHERE rc.review_type_id = ?
      AND rc.is_active = 1
    GROUP BY rc.criteria_id
    ORDER BY rc.category ASC, rc.criteria_id ASC
", 'iii', [$application_id, $review_type_id, $review_type_id]);

$aiReviews = fetchAll($conn, "
    SELECT
        ar.*,
        api.provider_name,
        api.api_name,
        api.model_name
    FROM ai_application_reviews ar
    LEFT JOIN ai_api api 
        ON api.ai_api_id = ar.ai_api_id
    WHERE ar.application_id = ?
      AND ar.review_type_id = ?
    ORDER BY ar.created_at DESC
", 'ii', [$application_id, $review_type_id]);

$aiCriteriaScores = fetchAll($conn, "
    SELECT
        ars.ai_score_id,
        ars.ai_review_id,
        ars.application_id,
        ars.review_type_id,
        ars.criteria_id,
        ars.score,
        ars.max_score,
        ars.weight,
        ars.comments,
        ars.created_at,
        rc.category,
        rc.question
    FROM ai_application_review_scores ars
    LEFT JOIN review_criteria rc
        ON rc.criteria_id = ars.criteria_id
    WHERE ars.application_id = ?
      AND ars.review_type_id = ?
    ORDER BY ars.ai_review_id DESC, rc.category ASC, rc.criteria_id ASC
", 'ii', [$application_id, $review_type_id]);

$aiScoresByReview = [];
foreach ($aiCriteriaScores as $row) {
    $rid = (int)$row['ai_review_id'];
    if (!isset($aiScoresByReview[$rid])) {
        $aiScoresByReview[$rid] = [];
    }
    $aiScoresByReview[$rid][] = $row;
}

$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$page_title = 'AI Review Applicant';
require_once 'includes/header.php';
?>
<style>
/* AI review applicant: main column + run panel side by side, stacked below 980px. */
.ar-grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 20px; align-items: start; }
.ar-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.ar-info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.ar-info-item { background: var(--ink-50); border-radius: var(--radius-md); padding: 12px; }
.ar-info-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-300); font-weight: 800; margin-bottom: 4px; }
.ar-info-value { font-weight: 700; color: var(--ink-700); font-size: 14px; }
.ar-text-block { background: var(--ink-50); border-radius: var(--radius-md); padding: 14px; line-height: 1.65; color: var(--ink-500); font-size: 14px; }
.ar-section-title { display: block; margin: 16px 0 6px; font-weight: 800; color: var(--ink-500); }
.ar-score-big { text-align: center; padding: 18px; margin-bottom: 16px; background: var(--brand-50); border-radius: var(--radius-lg); }
.ar-score-big strong { font-size: 38px; color: var(--brand-600); }
.ar-score-big span { display: block; color: var(--ink-300); font-size: 12px; font-weight: 800; }
.ar-card-body-flush { padding: 0; }
.ar-card-body-flush .table-responsive { margin-top: 0; }
.ar-table-block { margin-top: 16px; }
.ar-section-title + .table-responsive { margin-top: 0; }
.card-body > .ar-section-title:first-child { margin-top: 0; }
.ar-submit { width: 100%; justify-content: center; }
@media (max-width: 980px) { .ar-grid { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .ar-info-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-stack">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-robot" aria-hidden="true"></i> AI Review Applicant</h1>
            <p>
                <?= h($app['startup_name']) ?> -
                <?= h($app['opportunity_title'] ?? '-') ?>
            </p>
        </div>

        <div class="hero-actions">
            <a href="startups-shortlisting" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
            </a>
            <a href="review-applicant?id=<?= (int)$application_id ?>" class="btn">
                <i class="fas fa-star-half-alt" aria-hidden="true"></i> Manual Review
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Human reviewer summary -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-users" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Human Reviews</span>
                <span class="stat-value"><?= (int)($reviewerSummary['total_reviews'] ?? 0) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Average</span>
                <span class="stat-value"><?= h($reviewerSummary['avg_score'] ?? '0') ?>%</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-arrow-down" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Lowest</span>
                <span class="stat-value"><?= h($reviewerSummary['min_score'] ?? '0') ?>%</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-arrow-up" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Highest</span>
                <span class="stat-value"><?= h($reviewerSummary['max_score'] ?? '0') ?>%</span>
            </div>
        </div>
    </div>

    <div class="ar-grid">
        <div class="ar-col">
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-building" aria-hidden="true"></i> Application Summary</h3></div>
                <div class="card-body">
                    <div class="ar-info-grid">
                        <div class="ar-info-item"><span class="ar-info-label">Startup</span><div class="ar-info-value"><?= h($app['startup_name']) ?></div></div>
                        <div class="ar-info-item"><span class="ar-info-label">Sector</span><div class="ar-info-value"><?= h($app['sector'] ?? '-') ?></div></div>
                        <div class="ar-info-item"><span class="ar-info-label">Business Stage</span><div class="ar-info-value"><?= h($app['business_stage'] ?? '-') ?></div></div>
                        <div class="ar-info-item"><span class="ar-info-label">Review Type</span><div class="ar-info-value"><?= h($app['review_type_name'] ?? '-') ?></div></div>
                        <div class="ar-info-item"><span class="ar-info-label">Funding Sought</span><div class="ar-info-value"><?= number_format((float)($app['funding_sought'] ?? 0), 2) ?></div></div>
                        <div class="ar-info-item"><span class="ar-info-label">Status</span><div class="ar-info-value"><?= h($app['status'] ?? '-') ?></div></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i class="fas fa-balance-scale" aria-hidden="true"></i> Human Criteria Average</h3></div>
                <div class="card-body ar-card-body-flush">
                    <?php if (empty($criteriaComparison)): ?>
                        <div class="empty-state"><i class="fas fa-list-check" aria-hidden="true"></i><p>No criteria found for this review type.</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Criteria</th>
                                    <th>Reviewer Avg</th>
                                    <th>Max</th>
                                    <th>Weight</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($criteriaComparison as $c): ?>
                                    <tr>
                                        <td><?= h($c['category']) ?></td>
                                        <td><?= h($c['question']) ?></td>
                                        <td><span class="badge badge-assigned"><?= h($c['reviewer_avg_score'] ?? '0') ?></span></td>
                                        <td><?= h($c['max_score']) ?></td>
                                        <td><?= h($c['weight']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach ($aiReviews as $ai): ?>
                <?php
                    $aiReviewId = (int)$ai['ai_review_id'];
                    $reviewScores = $aiScoresByReview[$aiReviewId] ?? [];
                    $status = (string)($ai['status'] ?? '');
                ?>

                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-robot" aria-hidden="true"></i>
                            AI Review Result -
                            <?= h($ai['provider_name'] ?? 'AI') ?>
                        </h3>

                        <?php if ($status === 'Completed'): ?>
                            <span class="badge badge-available">Completed</span>
                        <?php elseif ($status === 'Failed'): ?>
                            <span class="badge badge-rejected">Failed</span>
                        <?php else: ?>
                            <span class="badge badge-returned"><?= h($status ?: 'Pending') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <div class="ar-score-big">
                            <strong><?= h($ai['overall_score'] ?? '0') ?>%</strong>
                            <span>
                                <?= h($ai['api_name'] ?? '-') ?> /
                                <?= h($ai['model_name'] ?? '-') ?>
                            </span>
                        </div>

                        <div class="ar-info-grid">
                            <div class="ar-info-item">
                                <span class="ar-info-label">Recommendation</span>
                                <div class="ar-info-value"><?= h($ai['recommendation'] ?? '-') ?></div>
                            </div>

                            <div class="ar-info-item">
                                <span class="ar-info-label">Reviewed At</span>
                                <div class="ar-info-value"><?= h($ai['created_at'] ?? '-') ?></div>
                            </div>
                        </div>

                        <?php if (!empty($ai['error_message'])): ?>
                            <span class="ar-section-title">Error Message</span>
                            <div class="ar-text-block"><?= nl2br(h($ai['error_message'])) ?></div>
                        <?php endif; ?>

                        <span class="ar-section-title">AI Summary / Comments</span>
                        <div class="ar-text-block">
                            <?= nl2br(h($ai['summary'] ?? '-')) ?>
                        </div>

                        <?php if (!empty($ai['strengths'])): ?>
                            <span class="ar-section-title">Strengths</span>
                            <div class="ar-text-block"><?= nl2br(h($ai['strengths'])) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($ai['weaknesses'])): ?>
                            <span class="ar-section-title">Weaknesses</span>
                            <div class="ar-text-block"><?= nl2br(h($ai['weaknesses'])) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($ai['risks'])): ?>
                            <span class="ar-section-title">Risks</span>
                            <div class="ar-text-block"><?= nl2br(h($ai['risks'])) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($ai['suggested_questions'])): ?>
                            <span class="ar-section-title">Suggested Questions</span>
                            <div class="ar-text-block"><?= nl2br(h($ai['suggested_questions'])) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($reviewScores)): ?>
                            <span class="ar-section-title">AI Criteria Scores &amp; Comments</span>

                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Criteria</th>
                                            <th>AI Score</th>
                                            <th>Weight</th>
                                            <th>AI Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reviewScores as $rs): ?>
                                            <tr>
                                                <td><?= h($rs['category'] ?? '-') ?></td>
                                                <td><?= h($rs['question'] ?? '-') ?></td>
                                                <td>
                                                    <span class="badge badge-assigned">
                                                        <?= h($rs['score']) ?> / <?= h($rs['max_score']) ?>
                                                    </span>
                                                </td>
                                                <td><?= h($rs['weight']) ?></td>
                                                <td><?= nl2br(h($rs['comments'] ?? '-')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning ar-table-block">
                                No detailed AI criteria scores/comments were saved for this AI review.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($aiReviews)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-robot" aria-hidden="true"></i>
                        <p>No AI reviews yet. Run an AI review from the panel on the right.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="ar-col">
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-play-circle" aria-hidden="true"></i> Run AI Review</h3></div>
                <div class="card-body">
                    <?php if (empty($aiApis)): ?>
                        <div class="alert alert-danger">
                            No active AI API found. Please configure one in Manage AI APIs.
                        </div>
                    <?php else: ?>
                        <form method="post" action="includes/process_ai_review.php">
                            <input type="hidden" name="application_id" value="<?= (int)$application_id ?>">
                            <input type="hidden" name="review_type_id" value="<?= (int)$review_type_id ?>">

                            <div class="form-group">
                                <label for="ar_ai_api_id">Select AI API</label>
                                <select name="ai_api_id" id="ar_ai_api_id" class="form-control" required>
                                    <?php foreach ($aiApis as $api): ?>
                                        <option value="<?= (int)$api['ai_api_id'] ?>" <?= ((int)$api['is_default'] === 1) ? 'selected' : '' ?>>
                                            <?= h($api['provider_name']) ?> -
                                            <?= h($api['api_name']) ?>
                                            <?= ((int)$api['is_default'] === 1) ? ' (Default)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="ar_ai_instruction">AI Instruction</label>
                                <textarea name="ai_instruction" id="ar_ai_instruction" class="form-control" rows="5">Review this startup application using the defined review criteria. Give an objective score, recommendation, strengths, weaknesses, risks, suggested questions, and concise comments for every criterion.</textarea>
                            </div>

                            <button type="submit" class="btn btn-primary ar-submit">
                                <i class="fas fa-magic" aria-hidden="true"></i> Generate AI Review
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i class="fas fa-info-circle" aria-hidden="true"></i> Key Application Text</h3></div>
                <div class="card-body">
                    <span class="ar-section-title">Problem</span>
                    <div class="ar-text-block"><?= nl2br(h($app['problem_statement'] ?? '-')) ?></div>

                    <span class="ar-section-title">Solution</span>
                    <div class="ar-text-block"><?= nl2br(h($app['solution_description'] ?? '-')) ?></div>

                    <span class="ar-section-title">Traction</span>
                    <div class="ar-text-block"><?= nl2br(h($app['traction'] ?? '-')) ?></div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.page-stack -->

<?php
require_once 'includes/footer.php';
ob_end_flush();
?>
