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
<link rel="stylesheet" href="css/opportunities.css">
<style>
.ai-wrap{max-width:1240px;margin:0 auto;padding:20px}
.ai-head{background:linear-gradient(135deg,#ff5722,#ff9800);color:#fff;border-radius:14px;padding:26px 30px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;gap:15px;flex-wrap:wrap}
.ai-head h1{margin:0 0 6px;font-size:25px}
.ai-head p{margin:0;opacity:.9}
.ai-grid{display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.08);padding:20px;margin-bottom:18px}
.card h3{margin:0 0 15px;color:#ff5722;font-size:17px;display:flex;align-items:center;gap:8px}
.info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.info-item{background:#f8fafc;border-radius:10px;padding:12px}
.info-item label{display:block;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:800;margin-bottom:4px}
.info-item div{font-weight:700;color:#1e293b;font-size:14px}
.text-block{background:#f8fafc;border-radius:10px;padding:14px;line-height:1.65;color:#334155;font-size:14px}
.btn{border:0;border-radius:9px;padding:10px 15px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;font-size:13px}
.btn-primary{background:#ff9800;color:#fff}
.btn-secondary{background:#e2e8f0;color:#1e293b}
.fc{width:100%;border:1px solid #dfe6e9;border-radius:9px;padding:10px 12px;font-size:13px;box-sizing:border-box}
.fg{margin-bottom:13px}
.fg label{display:block;font-weight:800;font-size:12px;color:#334155;margin-bottom:6px}
.alert{padding:12px 15px;border-radius:9px;margin-bottom:14px;font-size:14px}
.alert-success{background:#dcfce7;color:#166534;border-left:4px solid #22c55e}
.alert-danger{background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.stat{background:#f8fafc;border-radius:12px;padding:14px;text-align:center}
.stat strong{display:block;font-size:22px;color:#ff5722}
.stat span{font-size:12px;color:#64748b;font-weight:700}
.table-wrap{overflow:auto}
.tbl{width:100%;border-collapse:collapse;min-width:760px}
.tbl th{background:#f8fafc;text-align:left;color:#475569;font-size:12px;text-transform:uppercase;padding:11px;border-bottom:1px solid #e2e8f0}
.tbl td{padding:11px;border-bottom:1px solid #eef2f7;font-size:13px;vertical-align:top}
.badge{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:800}
.badge.blue{background:#dbeafe;color:#1d4ed8}
.badge.green{background:#dcfce7;color:#166534}
.badge.red{background:#fee2e2;color:#991b1b}
.badge.gray{background:#f1f5f9;color:#475569}
.score-big{text-align:center;padding:18px;background:#fff7ed;border-radius:14px}
.score-big strong{font-size:38px;color:#ff5722}
.score-big span{display:block;color:#64748b;font-size:12px;font-weight:800}
.section-title{font-weight:800;color:#334155;display:block;margin-top:10px;margin-bottom:6px}
@media(max-width:980px){.ai-grid{grid-template-columns:1fr}.info-grid,.stats{grid-template-columns:1fr 1fr}}
</style>

<div class="ai-wrap">

    <div class="ai-head">
        <div>
            <h1><i class="fas fa-robot"></i> AI Review Applicant</h1>
            <p>
                <?= h($app['startup_name']) ?> -
                <?= h($app['opportunity_title'] ?? '-') ?>
            </p>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="review-applicant?id=<?= (int)$application_id ?>" class="btn btn-secondary">
                <i class="fas fa-star-half-alt"></i> Manual Review
            </a>
            <a href="startups-shortlisting" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="ai-grid">
        <div>
            <div class="card">
                <h3><i class="fas fa-building"></i> Application Summary</h3>

                <div class="info-grid">
                    <div class="info-item"><label>Startup</label><div><?= h($app['startup_name']) ?></div></div>
                    <div class="info-item"><label>Sector</label><div><?= h($app['sector'] ?? '-') ?></div></div>
                    <div class="info-item"><label>Business Stage</label><div><?= h($app['business_stage'] ?? '-') ?></div></div>
                    <div class="info-item"><label>Review Type</label><div><?= h($app['review_type_name'] ?? '-') ?></div></div>
                    <div class="info-item"><label>Funding Sought</label><div><?= number_format((float)($app['funding_sought'] ?? 0), 2) ?></div></div>
                    <div class="info-item"><label>Status</label><div><?= h($app['status'] ?? '-') ?></div></div>
                </div>
            </div>

            <div class="card">
                <h3><i class="fas fa-users"></i> Human Reviewer Summary</h3>

                <div class="stats">
                    <div class="stat"><strong><?= (int)($reviewerSummary['total_reviews'] ?? 0) ?></strong><span>Reviews</span></div>
                    <div class="stat"><strong><?= h($reviewerSummary['avg_score'] ?? '0') ?>%</strong><span>Average</span></div>
                    <div class="stat"><strong><?= h($reviewerSummary['min_score'] ?? '0') ?>%</strong><span>Lowest</span></div>
                    <div class="stat"><strong><?= h($reviewerSummary['max_score'] ?? '0') ?>%</strong><span>Highest</span></div>
                </div>
            </div>

            <div class="card">
                <h3><i class="fas fa-balance-scale"></i> Human Criteria Average</h3>

                <div class="table-wrap">
                    <table class="tbl">
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
                                    <td><span class="badge blue"><?= h($c['reviewer_avg_score'] ?? '0') ?></span></td>
                                    <td><?= h($c['max_score']) ?></td>
                                    <td><?= h($c['weight']) ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (empty($criteriaComparison)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;color:#64748b;padding:25px">
                                        No criteria found for this review type.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php foreach ($aiReviews as $ai): ?>
                <?php
                    $aiReviewId = (int)$ai['ai_review_id'];
                    $reviewScores = $aiScoresByReview[$aiReviewId] ?? [];
                    $status = (string)($ai['status'] ?? '');
                ?>

                <div class="card">
                    <h3>
                        <i class="fas fa-robot"></i>
                        AI Review Result -
                        <?= h($ai['provider_name'] ?? 'AI') ?>

                        <?php if ($status === 'Completed'): ?>
                            <span class="badge green">Completed</span>
                        <?php elseif ($status === 'Failed'): ?>
                            <span class="badge red">Failed</span>
                        <?php else: ?>
                            <span class="badge gray"><?= h($status ?: 'Pending') ?></span>
                        <?php endif; ?>
                    </h3>

                    <div class="score-big">
                        <strong><?= h($ai['overall_score'] ?? '0') ?>%</strong>
                        <span>
                            <?= h($ai['api_name'] ?? '-') ?> /
                            <?= h($ai['model_name'] ?? '-') ?>
                        </span>
                    </div>

                    <br>

                    <div class="info-grid">
                        <div class="info-item">
                            <label>Recommendation</label>
                            <div><?= h($ai['recommendation'] ?? '-') ?></div>
                        </div>

                        <div class="info-item">
                            <label>Reviewed At</label>
                            <div><?= h($ai['created_at'] ?? '-') ?></div>
                        </div>
                    </div>

                    <?php if (!empty($ai['error_message'])): ?>
                        <br>
                        <label class="section-title">Error Message</label>
                        <div class="text-block"><?= nl2br(h($ai['error_message'])) ?></div>
                    <?php endif; ?>

                    <label class="section-title">AI Summary / Comments</label>
                    <div class="text-block">
                        <?= nl2br(h($ai['summary'] ?? '-')) ?>
                    </div>

                    <?php if (!empty($ai['strengths'])): ?>
                        <label class="section-title">Strengths</label>
                        <div class="text-block"><?= nl2br(h($ai['strengths'])) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($ai['weaknesses'])): ?>
                        <label class="section-title">Weaknesses</label>
                        <div class="text-block"><?= nl2br(h($ai['weaknesses'])) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($ai['risks'])): ?>
                        <label class="section-title">Risks</label>
                        <div class="text-block"><?= nl2br(h($ai['risks'])) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($ai['suggested_questions'])): ?>
                        <label class="section-title">Suggested Questions</label>
                        <div class="text-block"><?= nl2br(h($ai['suggested_questions'])) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($reviewScores)): ?>
                        <br>
                        <label class="section-title">AI Criteria Scores & Comments</label>

                        <div class="table-wrap">
                            <table class="tbl">
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
                                                <span class="badge blue">
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
                        <br>
                        <div class="alert alert-danger">
                            No detailed AI criteria scores/comments were saved for this AI review.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($aiReviews)): ?>
                <div class="card">
                    <h3><i class="fas fa-info-circle"></i> No AI Reviews Yet</h3>
                    <p style="color:#64748b;margin:0;">Run an AI review from the panel on the right.</p>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <h3><i class="fas fa-play-circle"></i> Run AI Review</h3>

                <?php if (empty($aiApis)): ?>
                    <div class="alert alert-danger">
                        No active AI API found. Please configure one in Manage AI APIs.
                    </div>
                <?php else: ?>
                    <form method="post" action="includes/process_ai_review.php">
                        <input type="hidden" name="application_id" value="<?= (int)$application_id ?>">
                        <input type="hidden" name="review_type_id" value="<?= (int)$review_type_id ?>">

                        <div class="fg">
                            <label>Select AI API</label>
                            <select name="ai_api_id" class="fc" required>
                                <?php foreach ($aiApis as $api): ?>
                                    <option value="<?= (int)$api['ai_api_id'] ?>" <?= ((int)$api['is_default'] === 1) ? 'selected' : '' ?>>
                                        <?= h($api['provider_name']) ?> -
                                        <?= h($api['api_name']) ?>
                                        <?= ((int)$api['is_default'] === 1) ? ' (Default)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fg">
                            <label>AI Instruction</label>
                            <textarea name="ai_instruction" class="fc" rows="5">Review this startup application using the defined review criteria. Give an objective score, recommendation, strengths, weaknesses, risks, suggested questions, and concise comments for every criterion.</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                            <i class="fas fa-magic"></i> Generate AI Review
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3><i class="fas fa-info-circle"></i> Key Application Text</h3>

                <label class="section-title">Problem</label>
                <div class="text-block"><?= nl2br(h($app['problem_statement'] ?? '-')) ?></div>

                <label class="section-title">Solution</label>
                <div class="text-block"><?= nl2br(h($app['solution_description'] ?? '-')) ?></div>

                <label class="section-title">Traction</label>
                <div class="text-block"><?= nl2br(h($app['traction'] ?? '-')) ?></div>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
ob_end_flush();
?>