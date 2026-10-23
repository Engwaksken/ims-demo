<?php
declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';

$page_title = 'Risk Rating';

if (!ims_allowed('risk_ratings.edit', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer'], 'Create and edit risk ratings')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard');
    exit();
}

require_once 'includes/header.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$applicationId = (int)($_GET['application_id'] ?? $_GET['id'] ?? 0);
$reviewTypeId  = (int)($_GET['review_type_id'] ?? 0);

$successMsg = (string)($_SESSION['success'] ?? '');
$errorMsg   = (string)($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

if ($applicationId <= 0) {
    $_SESSION['error'] = 'Invalid application ID.';
    header('Location: startups-shortlisting');
    exit();
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function fetchAllAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $error);
    }

    $rows = [];
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }

    $stmt->close();
    return $rows;
}

function fetchOneAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = fetchAllAssoc($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function riskLabelFromScore(float $score): string
{
    if ($score >= 5.0) return 'Severe';
    if ($score >= 3.0) return 'Major';
    if ($score >= 2.0) return 'Moderate';
    return 'Minor';
}

function riskBadgeClass(string $label): string
{
    return match (strtolower(trim($label))) {
        'severe'   => 'danger',
        'major'    => 'warning',
        'moderate' => 'info',
        default    => 'success',
    };
}

function ensureRiskRatingExists(mysqli $conn, array $app, int $applicationId, int $preparedBy): int
{
    $existing = fetchOneAssoc(
        $conn,
        "SELECT id FROM risk_ratings WHERE application_id = ? LIMIT 1",
        'i',
        [$applicationId]
    );

    if (!empty($existing['id'])) {
        return (int)$existing['id'];
    }

    $reportTitle = 'Risk Rating Report - ' . (($app['startup_name'] ?? '') !== '' ? $app['startup_name'] : ('Application #' . $applicationId));
    $executiveSummary = 'Startup: ' . ($app['startup_name'] ?? '');
    $introNotes = 'Due diligence risk rating for ' . (($app['startup_name'] ?? '') ?: 'the applicant') . '.';
    $nextSteps = "1. Complete scoring.\n2. Complete risk findings.\n3. Save and generate report.";
    $opportunityId = isset($app['opportunity_id']) && $app['opportunity_id'] !== '' ? (int)$app['opportunity_id'] : null;

    $stmt = $conn->prepare("
        INSERT INTO risk_ratings (
            application_id,
            opportunity_id,
            report_title,
            overall_rating,
            overall_rating_label,
            recommendation,
            executive_summary,
            introduction_notes,
            next_steps,
            prepared_by,
            status,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, 1.00, 'Minor', 'Proceed with Conditions', ?, ?, ?, ?, 'draft', NOW(), NOW())
    ");
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'iissssi',
        $applicationId,
        $opportunityId,
        $reportTitle,
        $executiveSummary,
        $introNotes,
        $nextSteps,
        $preparedBy
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Failed to create risk rating: ' . $error);
    }

    $riskRatingId = (int)$stmt->insert_id;
    $stmt->close();

    $defaultPillars = [
        'governance'   => 'Governance Risks Pillar',
        'operational'  => 'Operational Risks Pillar',
        'delivery'     => 'Delivery Risks Pillar',
        'fiduciary'    => 'Fiduciary Risks Pillar',
        'safeguarding' => 'Safeguarding Risks Pillar',
        'reputational' => 'Reputational Risks Pillar',
    ];

    $insertSection = $conn->prepare("
        INSERT INTO risk_rating_sections (
            risk_rating_id,
            pillar_key,
            pillar_label,
            rating_score,
            rating_label,
            key_findings,
            recommendations,
            follow_up_actions,
            notes,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, 1, 'Minor', '', '', '', '', NOW(), NOW())
    ");
    if (!$insertSection) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    foreach ($defaultPillars as $pillarKey => $pillarLabel) {
        $insertSection->bind_param('iss', $riskRatingId, $pillarKey, $pillarLabel);
        if (!$insertSection->execute()) {
            $error = $insertSection->error;
            $insertSection->close();
            throw new RuntimeException('Failed to create default risk sections: ' . $error);
        }
    }
    $insertSection->close();

    $defaultChecks = [
        '1a' => 'Tax exemption certificate',
        '1b' => 'Articles of Incorporation, Constitution, etc.',
        '1c' => 'Organization in-country registration certificate',
        '1d' => 'Business Certificates',
        '2a' => 'Governing Body member information',
        '2b' => 'Code of conduct policy',
        '2c' => 'Conflict of interest policy',
        '2d' => 'Fraud, corruption, bribery policy',
        '3'  => 'Legal claims information',
        '4a' => 'Auditors report',
        '4b' => 'Annual financial statements',
        '4c' => 'Financial policies & procedures',
        '4d' => 'Procurement policy',
        '4e' => 'Record retention policy',
        '4f' => 'Delegation of authority policy',
        '5a' => 'Executive Management Information',
        '5b' => 'Key personnel information',
        '5c' => 'Timesheet form (blank copy)',
        '6'  => 'Child Safe-Guarding Policy',
    ];

    $insertCheck = $conn->prepare("
        INSERT INTO risk_rating_checks (
            risk_rating_id,
            item_code,
            item_label,
            is_yes,
            is_no,
            remarks,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, 0, 0, '', NOW(), NOW())
    ");
    if (!$insertCheck) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    foreach ($defaultChecks as $itemCode => $itemLabel) {
        $insertCheck->bind_param('iss', $riskRatingId, $itemCode, $itemLabel);
        if (!$insertCheck->execute()) {
            $error = $insertCheck->error;
            $insertCheck->close();
            throw new RuntimeException('Failed to create default risk checks: ' . $error);
        }
    }
    $insertCheck->close();

    return $riskRatingId;
}

try {
    $app = fetchOneAssoc($conn, "
        SELECT
            a.application_id,
            a.opportunity_id,
            a.startup_name,
            o.opportunity_title
        FROM applications a
        LEFT JOIN application_opportunities o ON o.opportunity_id = a.opportunity_id
        WHERE a.application_id = ?
        LIMIT 1
    ", 'i', [$applicationId]);

    if (empty($app)) {
        throw new RuntimeException('Application not found.');
    }

    $riskRatingId = ensureRiskRatingExists($conn, $app, $applicationId, $currentUserId);

    $riskRating = fetchOneAssoc(
        $conn,
        "SELECT * FROM risk_ratings WHERE id = ? LIMIT 1",
        'i',
        [$riskRatingId]
    );

    if (empty($riskRating)) {
        throw new RuntimeException('Risk rating record not found.');
    }

    $sections = fetchAllAssoc($conn, "
        SELECT *
        FROM risk_rating_sections
        WHERE risk_rating_id = ?
        ORDER BY FIELD(pillar_key, 'governance','operational','delivery','fiduciary','safeguarding','reputational'), id ASC
    ", 'i', [$riskRatingId]);

    $checks = fetchAllAssoc($conn, "
        SELECT *
        FROM risk_rating_checks
        WHERE risk_rating_id = ?
        ORDER BY id ASC
    ", 'i', [$riskRatingId]);

    $reviewTypes = fetchAllAssoc($conn, "
        SELECT review_type_id, name, description
        FROM review_types
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");

    if ($reviewTypeId <= 0 && !empty($reviewTypes)) {
        $reviewTypeId = (int)$reviewTypes[0]['review_type_id'];
    }

    $criteriaRows = [];
    if ($reviewTypeId > 0) {
        $criteriaRows = fetchAllAssoc($conn, "
            SELECT
                rc.criteria_id,
                rc.review_type_id,
                rc.category,
                rc.question,
                rc.description,
                rc.max_score,
                rc.weight,
                COALESCE(rcs.awarded_score, 0) AS awarded_score,
                COALESCE(rcs.weighted_score, 0) AS weighted_score,
                COALESCE(rcs.comments, '') AS comments
            FROM review_criteria rc
            LEFT JOIN risk_rating_criteria_scores rcs
                ON rcs.criteria_id = rc.criteria_id
               AND rcs.risk_rating_id = ?
            WHERE rc.is_active = 1
              AND rc.review_type_id = ?
            ORDER BY rc.category ASC, rc.criteria_id ASC
        ", 'ii', [$riskRatingId, $reviewTypeId]);
    }

    $overallRatingValue = (float)($riskRating['overall_rating'] ?? 1);
    $overallLabel = (string)($riskRating['overall_rating_label'] ?? riskLabelFromScore($overallRatingValue));
    $overallBadgeClass = riskBadgeClass($overallLabel);

} catch (Throwable $e) {
    echo '<div class="container py-4"><div class="alert alert-danger">' . h($e->getMessage()) . '</div></div>';
    require_once 'includes/footer.php';
    exit();
}
?>

<style>
    /* risk_rating.php: layout unique to this page. Components come from reports.css. */
    .rr-overall .stat-card { gap: 16px; }
    .rr-overall .stat-info { flex: 1 1 auto; }
    .rr-overall-badge { flex-shrink: 0; }
    .rr-pills { display: flex; gap: 8px; flex-wrap: wrap; }
    .rr-guide { display: flex; align-items: center; min-height: 42px; color: var(--ink-400); font-size: var(--fs-sm); }
    .rr-col-code { width: 70px; }
    .rr-col-check { width: 90px; }
    .rr-col-remarks { min-width: 220px; }
    .rr-sticky {
        position: sticky;
        bottom: 12px;
        z-index: 50;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        padding: 14px 18px;
        background: var(--surface-card);
        border: 1px solid var(--ink-100);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-lg);
    }
    .rr-sticky h3 { margin: 0; font-size: 15px; font-weight: 800; color: var(--ink-700); }
    .rr-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    #riskRatingForm { display: flex; flex-direction: column; gap: 20px; }
</style>

<div class="page-stack">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-shield-alt" aria-hidden="true"></i> Risk Rating</h1>
            <p>Startup: <strong><?= h($app['startup_name'] ?: 'Unnamed Startup') ?></strong>. Score each risk pillar, complete the checklist, then save or generate the report.</p>
        </div>

        <div class="hero-actions">
            <a href="startups-shortlisting" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
            </a>

            <?php if (!empty($riskRating['generated_pdf_path'])): ?>
                <a href="<?= h(ims_upload_url($riskRating['generated_pdf_path'])) ?>" class="btn" target="_blank">
                    <i class="fas fa-file-pdf" aria-hidden="true"></i> Open PDF
                </a>
            <?php endif; ?>

            <?php if (!empty($riskRating['generated_excel_path'])): ?>
                <a href="<?= h(ims_upload_url($riskRating['generated_excel_path'])) ?>" class="btn" target="_blank">
                    <i class="fas fa-file-excel" aria-hidden="true"></i> Open Excel
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success" role="alert">
            <?= h($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= h($errorMsg) ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid reports-stats rr-overall">
        <div class="stat-card stat-card-wide">
            <div class="stat-icon bg-primary" aria-hidden="true"><i class="fas fa-shield-alt"></i></div>
            <div class="stat-info">
                <span class="stat-label">Overall Risk Preview</span>
                <span id="overallRiskScore" class="stat-value"><?= number_format($overallRatingValue, 2) ?></span>
                <span id="overallRiskLabel" class="cell-muted"><?= h($overallLabel) ?></span>
            </div>
            <div class="rr-overall-badge">
                <span id="overallRiskBadge" class="badge badge-<?= h($overallBadgeClass) ?>">
                    <?= h($overallLabel) ?>
                </span>
            </div>
        </div>
    </div>

    <form action="includes/process_risk_rating.php" method="post" id="riskRatingForm">
        <input type="hidden" name="risk_rating_id" value="<?= (int)$riskRating['id'] ?>">
        <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
        <input type="hidden" name="selected_review_type_id" id="selectedReviewTypeId" value="<?= (int)$reviewTypeId ?>">

        <!-- <ul class="nav nav-tabs rr-tabs-nav" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-summary" type="button" role="tab">
                    <i class="fas fa-file-alt me-1"></i> Summary
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-scoring" type="button" role="tab">
                    <i class="fas fa-calculator me-1"></i> Scoring
                </button>
            </li>

            <?php foreach ($sections as $index => $sec): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-<?= (int)$sec['id'] ?>" type="button" role="tab">
                        <?= ($index + 1) . '. ' . h($sec['pillar_key']) ?>
                    </button>
                </li>
            <?php endforeach; ?>

            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button" role="tab">
                    <i class="fas fa-check-square me-1"></i> Documents
                </button>
            </li>
        </ul> -->

        <div class="card tab-pane" id="tab-summary" role="tabpanel">
            <div class="card-header">
                <h3><i class="fas fa-file-alt" aria-hidden="true"></i> Summary</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Report Title</label>
                        <input type="text" name="report_title" class="form-control" value="<?= h($riskRating['report_title'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Recommendation</label>
                        <select name="recommendation" class="form-control">
                            <?php foreach (['Proceed', 'Proceed with Conditions', 'Hold', 'Decline'] as $rec): ?>
                                <option value="<?= h($rec) ?>" <?= (($riskRating['recommendation'] ?? '') === $rec ? 'selected' : '') ?>>
                                    <?= h($rec) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Executive Summary</label>
                        <textarea name="executive_summary" rows="5" class="form-control"><?= h($riskRating['executive_summary'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group full">
                        <label>Introduction Notes</label>
                        <textarea name="introduction_notes" rows="4" class="form-control"><?= h($riskRating['introduction_notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group full">
                        <label>Next Steps</label>
                        <textarea name="next_steps" rows="4" class="form-control"><?= h($riskRating['next_steps'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card tab-pane" id="tab-scoring" role="tabpanel">
            <div class="card-header">
                <h3><i class="fas fa-calculator" aria-hidden="true"></i> Scoring</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="reviewTypeSelect">Review Type</label>
                        <select id="reviewTypeSelect" class="form-control">
                            <?php foreach ($reviewTypes as $rt): ?>
                                <option value="<?= (int)$rt['review_type_id'] ?>" <?= ((int)$rt['review_type_id'] === $reviewTypeId ? 'selected' : '') ?>>
                                    <?= h($rt['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="cell-muted">Select the review type to score this startup.</span>
                    </div>
                    <div class="alert alert-info" data-persist>
                        <strong>Scoring Notes</strong><br>
                        Enter awarded score for each criterion. Weighted score is calculated automatically.
                    </div>
                </div>

                <!--<div class="rr-box">
                    <div class="rr-box-head">
                        <h5>Criteria Scoring</h5>
                        <span class="rr-pill">
                            Total Weighted:
                            <span id="criteriaTotalWeighted">0.00</span>
                        </span>
                    </div>

                    <?php if (empty($criteriaRows)): ?>
                        <div class="alert alert-warning mb-0">
                            No active criteria found for the selected review type.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered criteria-table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:120px;">Category</th>
                                        <th>Criteria</th>
                                        <th style="width:90px;">Max</th>
                                        <th style="width:90px;">Weight</th>
                                        <th style="width:120px;">Awarded</th>
                                        <th style="width:110px;">Weighted</th>
                                        <th style="min-width:220px;">Comments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($criteriaRows as $cr): ?>
                                        <?php
                                        $criteriaId = (int)$cr['criteria_id'];
                                        $maxScore = (float)$cr['max_score'];
                                        $weight = (float)$cr['weight'];
                                        $awarded = (float)$cr['awarded_score'];
                                        $weighted = (float)$cr['weighted_score'];
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="cat-chip"><?= h($cr['category'] ?: 'General') ?></span>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?= h($cr['question']) ?></div>
                                                <?php if (!empty($cr['description'])): ?>
                                                    <div class="rr-muted mt-1"><?= h($cr['description']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= number_format($maxScore, 2) ?>
                                                <input type="hidden" name="criteria_max_score[<?= $criteriaId ?>]" value="<?= h((string)$maxScore) ?>">
                                            </td>
                                            <td>
                                                <?= number_format($weight, 2) ?>
                                                <input type="hidden" name="criteria_weight[<?= $criteriaId ?>]" value="<?= h((string)$weight) ?>">
                                            </td>
                                            <td>
                                                <input type="hidden" name="criteria_review_type_id[<?= $criteriaId ?>]" value="<?= (int)$cr['review_type_id'] ?>">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    max="<?= h((string)$maxScore) ?>"
                                                    class="form-control criteria-awarded"
                                                    name="criteria_awarded_score[<?= $criteriaId ?>]"
                                                    value="<?= h((string)$awarded) ?>"
                                                    data-max="<?= h((string)$maxScore) ?>"
                                                    data-weight="<?= h((string)$weight) ?>">
                                            </td>
                                            <td class="weighted-cell">
                                                <span class="criteria-weighted-text"><?= number_format($weighted, 2) ?></span>
                                                <input type="hidden" class="criteria-weighted-input" value="<?= h((string)$weighted) ?>">
                                            </td>
                                            <td>
                                                <textarea
                                                    name="criteria_comments[<?= $criteriaId ?>]"
                                                    rows="2"
                                                    class="form-control"
                                                    placeholder="Comments"><?= h($cr['comments']) ?></textarea>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div> -->
            </div>
        </div>

        <?php foreach ($sections as $index => $sec): ?>
            <?php
            $sectionId = (int)$sec['id'];
            $scoreValue = (float)($sec['rating_score'] ?? 1);
            $currentLabel = (string)(($sec['rating_label'] ?? '') !== '' ? $sec['rating_label'] : riskLabelFromScore($scoreValue));
            ?>
            <div class="card tab-pane" id="tab-<?= $sectionId ?>" role="tabpanel">
                <div class="card-header">
                    <h3><?= ($index + 1) . '. ' . h($sec['pillar_label']) ?></h3>
                    <div class="rr-pills">
                        <span class="badge badge-secondary">Score: <span class="section-score-badge"><?= h((string)$scoreValue) ?></span></span>
                        <span class="badge badge-secondary section-label-badge"><?= h($currentLabel) ?></span>
                    </div>
                </div>

                <div class="card-body">
                    <input type="hidden" name="section_ids[]" value="<?= $sectionId ?>">

                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Risk Score</label>
                            <select name="section_rating_score[<?= $sectionId ?>]" class="form-control section-score">
                                <option value="1" <?= ($scoreValue == 1.0 ? 'selected' : '') ?>>1 - Minor</option>
                                <option value="2" <?= ($scoreValue == 2.0 ? 'selected' : '') ?>>2 - Moderate</option>
                                <option value="3" <?= ($scoreValue == 3.0 ? 'selected' : '') ?>>3 - Major</option>
                                <option value="5" <?= ($scoreValue == 5.0 ? 'selected' : '') ?>>5 - Severe</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Risk Label</label>
                            <input type="text" readonly class="form-control section-label" value="<?= h($currentLabel) ?>">
                        </div>

                        <div class="form-group">
                            <label>Guide</label>
                            <div class="rr-guide">1 Minor, 2 Moderate, 3 Major, 5 Severe</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Key Findings</label>
                        <textarea name="section_key_findings[<?= $sectionId ?>]" rows="5" class="form-control"><?= h($sec['key_findings'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Recommendations</label>
                        <textarea name="section_recommendations[<?= $sectionId ?>]" rows="4" class="form-control"><?= h($sec['recommendations'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Follow Up Actions</label>
                        <textarea name="section_follow_up_actions[<?= $sectionId ?>]" rows="4" class="form-control"><?= h($sec['follow_up_actions'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="section_notes[<?= $sectionId ?>]" rows="3" class="form-control"><?= h($sec['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card tab-pane" id="tab-docs" role="tabpanel">
            <div class="card-header">
                <h3><i class="fas fa-check-square" aria-hidden="true"></i> Documents</h3>
                <span class="cell-muted">Only one of YES or NO should remain checked for each item.</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="rr-col-code">Code</th>
                            <th>Description</th>
                            <th class="rr-col-check text-center">YES</th>
                            <th class="rr-col-check text-center">NO</th>
                            <th class="rr-col-remarks">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($checks as $check): ?>
                            <?php
                            $checkId = (int)$check['id'];
                            $isYes = (int)($check['is_yes'] ?? 0) === 1;
                            $isNo  = (int)($check['is_no'] ?? 0) === 1;
                            if ($isYes && $isNo) {
                                $isNo = false;
                            }
                            ?>
                            <tr>
                                <td><strong><?= h($check['item_code']) ?></strong></td>
                                <td><?= h($check['item_label']) ?></td>
                                <td class="text-center">
                                    <input
                                        type="checkbox"
                                        class="form-check-input yes-check"
                                        name="check_yes[<?= $checkId ?>]"
                                        value="1"
                                        data-check-id="<?= $checkId ?>"
                                        aria-label="Yes"
                                        <?= $isYes ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input
                                        type="checkbox"
                                        class="form-check-input no-check"
                                        name="check_no[<?= $checkId ?>]"
                                        value="1"
                                        data-check-id="<?= $checkId ?>"
                                        aria-label="No"
                                        <?= $isNo ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="check_remarks[<?= $checkId ?>]"
                                        value="<?= h($check['remarks'] ?? '') ?>"
                                        placeholder="Remarks">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rr-sticky">
            <div>
                <h3>Risk Rating Details</h3>
                <span class="cell-muted">Complete scoring, pillar findings, and checklist, then save or generate the report.</span>
            </div>

            <div class="rr-actions">
                <button type="submit" name="action" value="save" class="btn btn-primary">
                    <i class="fas fa-save" aria-hidden="true"></i> Save Risk Rating
                </button>
                <button type="submit" name="action" value="generate" class="btn btn-danger">
                    <i class="fas fa-file-pdf" aria-hidden="true"></i> Save & Generate PDF
                </button>
               <!-- <button type="submit" name="action" value="generate_excel" class="btn btn-success">
                    <i class="fas fa-file-excel me-1"></i> Save & Generate Excel
                </button>
                <button type="submit" name="action" value="generate_both" class="btn btn-dark">
                    <i class="fas fa-file-export me-1"></i> Generate PDF + Excel
                </button> -->
            </div>
        </div>
    </form>

</div><!-- /.page-stack -->

<script>
(function () {
    const scoreSelectors = document.querySelectorAll('.section-score');
    const overallScoreEl = document.getElementById('overallRiskScore');
    const overallLabelEl = document.getElementById('overallRiskLabel');
    const overallBadgeEl = document.getElementById('overallRiskBadge');
    const reviewTypeSelect = document.getElementById('reviewTypeSelect');
    const selectedReviewTypeInput = document.getElementById('selectedReviewTypeId');

    function labelFromScore(score) {
        score = parseFloat(score || 0);
        if (score >= 5) return 'Severe';
        if (score >= 3) return 'Major';
        if (score >= 2) return 'Moderate';
        return 'Minor';
    }

    function badgeClassFromLabel(label) {
        label = String(label || '').toLowerCase().trim();
        if (label === 'severe') return 'badge-danger';
        if (label === 'major') return 'badge-warning';
        if (label === 'moderate') return 'badge-info';
        return 'badge-success';
    }

    function refreshSection(selectEl) {
        const sectionPane = selectEl.closest('.tab-pane');
        const val = parseFloat(selectEl.value || 1);
        const label = labelFromScore(val);

        const labelInput = sectionPane ? sectionPane.querySelector('.section-label') : null;
        const badgeValue = sectionPane ? sectionPane.querySelector('.section-score-badge') : null;
        const badgeLabel = sectionPane ? sectionPane.querySelector('.section-label-badge') : null;

        if (labelInput) labelInput.value = label;
        if (badgeValue) badgeValue.textContent = val.toFixed(0);
        if (badgeLabel) badgeLabel.textContent = label;
    }

    function refreshOverall() {
        let total = 0;
        let count = 0;

        scoreSelectors.forEach(function (sel) {
            const val = parseFloat(sel.value || 0);
            if (!isNaN(val)) {
                total += val;
                count++;
            }
        });

        const avg = count > 0 ? (total / count) : 1;
        const avgFixed = avg.toFixed(2);
        const label = labelFromScore(avg);

        if (overallScoreEl) overallScoreEl.textContent = avgFixed;
        if (overallLabelEl) overallLabelEl.textContent = label;

        if (overallBadgeEl) {
            overallBadgeEl.textContent = label;
            overallBadgeEl.className = 'badge ' + badgeClassFromLabel(label);
        }
    }

    function refreshCriteriaTotals() {
        let totalWeighted = 0;

        document.querySelectorAll('.criteria-awarded').forEach(function (input) {
            let awarded = parseFloat(input.value || 0);
            const max = parseFloat(input.getAttribute('data-max') || 0);
            const weight = parseFloat(input.getAttribute('data-weight') || 0);

            if (isNaN(awarded) || awarded < 0) awarded = 0;
            if (!isNaN(max) && awarded > max) {
                awarded = max;
                input.value = max;
            }

            const weighted = awarded * weight;

            const row = input.closest('tr');
            if (row) {
                const weightedText = row.querySelector('.criteria-weighted-text');
                const weightedInput = row.querySelector('.criteria-weighted-input');

                if (weightedText) weightedText.textContent = weighted.toFixed(2);
                if (weightedInput) weightedInput.value = weighted.toFixed(2);
            }

            totalWeighted += weighted;
        });

        const totalEl = document.getElementById('criteriaTotalWeighted');
        if (totalEl) totalEl.textContent = totalWeighted.toFixed(2);
    }

    scoreSelectors.forEach(function (sel) {
        refreshSection(sel);
        sel.addEventListener('change', function () {
            refreshSection(sel);
            refreshOverall();
        });
    });

    document.querySelectorAll('.criteria-awarded').forEach(function (input) {
        input.addEventListener('input', function () {
            refreshCriteriaTotals();
        });
    });

    refreshOverall();
    refreshCriteriaTotals();

    document.querySelectorAll('.yes-check').forEach(function (yesBox) {
        yesBox.addEventListener('change', function () {
            if (!yesBox.checked) return;
            const id = yesBox.getAttribute('data-check-id');
            const noBox = document.querySelector('.no-check[data-check-id="' + id + '"]');
            if (noBox) noBox.checked = false;
        });
    });

    document.querySelectorAll('.no-check').forEach(function (noBox) {
        noBox.addEventListener('change', function () {
            if (!noBox.checked) return;
            const id = noBox.getAttribute('data-check-id');
            const yesBox = document.querySelector('.yes-check[data-check-id="' + id + '"]');
            if (yesBox) yesBox.checked = false;
        });
    });

    if (reviewTypeSelect) {
        reviewTypeSelect.addEventListener('change', function () {
            if (selectedReviewTypeInput) {
                selectedReviewTypeInput.value = reviewTypeSelect.value;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('application_id', '<?= (int)$applicationId ?>');
            url.searchParams.set('review_type_id', reviewTypeSelect.value);
            window.location.href = url.toString();
        });
    }
})();
</script>

<?php require_once 'includes/footer.php'; ?>