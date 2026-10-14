<?php
declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }
require_once __DIR__ . '/../services/RiskRatingReportService.php';

/* -----------------------------------------------------------------------------
   Access control
----------------------------------------------------------------------------- */
if (!ims_allowed('risk_ratings.edit', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer'], 'Create and edit risk ratings')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ../dashboard');
    exit();
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../startups-shortlisting');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['error'] = 'Database connection not available.';
    header('Location: ../startups-shortlisting');
    exit();
}

mysqli_report(MYSQLI_REPORT_OFF);

/* -----------------------------------------------------------------------------
   Constants
----------------------------------------------------------------------------- */
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

const VALID_SCORES = [1.0, 2.0, 3.0, 5.0];
const VALID_RECOMMENDATIONS = ['Proceed', 'Proceed with Conditions', 'Hold', 'Decline'];
const VALID_ACTIONS = ['save', 'generate', 'generate_excel', 'generate_both'];

/* -----------------------------------------------------------------------------
   Helpers
----------------------------------------------------------------------------- */
function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function postStr(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function postInt(string $key, int $default = 0): int
{
    return (int)($_POST[$key] ?? $default);
}

function normalizeRiskLabel(float $score): string
{
    if ($score >= 5.0) {
        return 'Severe';
    }
    if ($score >= 3.0) {
        return 'Major';
    }
    if ($score >= 2.0) {
        return 'Moderate';
    }
    return 'Minor';
}

function redirectToRating(int $applicationId, int $reviewTypeId = 0): never
{
    $url = '../risk_rating?application_id=' . $applicationId;
    if ($reviewTypeId > 0) {
        $url .= '&review_type_id=' . $reviewTypeId;
    }
    header('Location: ' . $url);
    exit();
}

function dbFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $err);
    }

    $rows = [];
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();
    return $rows;
}

function dbFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = dbFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function dbExecute(mysqli $conn, string $sql, string $types = '', array $params = []): void
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $err);
    }

    $stmt->close();
}

function ensureFloat(mixed $value, float $default = 0.0): float
{
    if ($value === null || $value === '') {
        return $default;
    }
    return (float)$value;
}

/* -----------------------------------------------------------------------------
   Read and validate request inputs
----------------------------------------------------------------------------- */
$riskRatingId         = postInt('risk_rating_id');
$applicationId        = postInt('application_id');
$selectedReviewTypeId = postInt('selected_review_type_id');
$action               = postStr('action', 'save');

if (!in_array($action, VALID_ACTIONS, true)) {
    $action = 'save';
}

if ($riskRatingId <= 0 || $applicationId <= 0) {
    $_SESSION['error'] = 'Missing risk rating or application ID.';
    header('Location: ../startups-shortlisting');
    exit();
}

/* -----------------------------------------------------------------------------
   Main processing
----------------------------------------------------------------------------- */
try {
    $riskRating = dbFetchOne(
        $conn,
        "SELECT id, application_id FROM risk_ratings WHERE id = ? AND application_id = ? LIMIT 1",
        'ii',
        [$riskRatingId, $applicationId]
    );

    if (empty($riskRating)) {
        throw new RuntimeException('Risk rating record not found or does not belong to this application.');
    }

    $conn->begin_transaction();

    /* ------------------------------------------------------------------------
       1. Update risk rating header / summary
    ----------------------------------------------------------------------- */
    $reportTitle = postStr('report_title', 'Risk Rating Report');
    if ($reportTitle === '') {
        $reportTitle = 'Risk Rating Report';
    }

    $recommendation = postStr('recommendation', 'Proceed with Conditions');
    if (!in_array($recommendation, VALID_RECOMMENDATIONS, true)) {
        $recommendation = 'Proceed with Conditions';
    }

    dbExecute(
        $conn,
        "
        UPDATE risk_ratings
        SET
            report_title       = ?,
            recommendation     = ?,
            executive_summary  = ?,
            introduction_notes = ?,
            next_steps         = ?,
            prepared_by        = ?,
            updated_at         = NOW()
        WHERE id = ? AND application_id = ?
        ",
        'sssssiii',
        [
            $reportTitle,
            $recommendation,
            postStr('executive_summary'),
            postStr('introduction_notes'),
            postStr('next_steps'),
            $currentUserId,
            $riskRatingId,
            $applicationId,
        ]
    );

    /* ------------------------------------------------------------------------
       2. Update pillar sections
    ----------------------------------------------------------------------- */
    $submittedSectionIds = (array)post('section_ids', []);
    $submittedScores = (array)post('section_rating_score', []);
    $submittedFindings = (array)post('section_key_findings', []);
    $submittedRecs = (array)post('section_recommendations', []);
    $submittedFollowUps = (array)post('section_follow_up_actions', []);
    $submittedNotes = (array)post('section_notes', []);

    $validSectionRows = dbFetchAll(
        $conn,
        "SELECT id FROM risk_rating_sections WHERE risk_rating_id = ?",
        'i',
        [$riskRatingId]
    );
    $validSectionIds = array_map(static fn($r) => (int)$r['id'], $validSectionRows);

    $stmtSection = $conn->prepare("
        UPDATE risk_rating_sections
        SET
            rating_score      = ?,
            rating_label      = ?,
            key_findings      = ?,
            recommendations   = ?,
            follow_up_actions = ?,
            notes             = ?,
            updated_at        = NOW()
        WHERE id = ? AND risk_rating_id = ?
    ");
    if (!$stmtSection) {
        throw new RuntimeException('Prepare failed (sections): ' . $conn->error);
    }

    $pillarScoreTotal = 0.0;
    $pillarScoreCount = 0;

    foreach ($submittedSectionIds as $rawSectionId) {
        $sectionId = (int)$rawSectionId;
        if ($sectionId <= 0 || !in_array($sectionId, $validSectionIds, true)) {
            continue;
        }

        $score = ensureFloat($submittedScores[$sectionId] ?? 1.0, 1.0);
        if (!in_array($score, VALID_SCORES, true)) {
            $score = 1.0;
        }

        $label = normalizeRiskLabel($score);
        $findings = trim((string)($submittedFindings[$sectionId] ?? ''));
        $recs = trim((string)($submittedRecs[$sectionId] ?? ''));
        $followUps = trim((string)($submittedFollowUps[$sectionId] ?? ''));
        $notes = trim((string)($submittedNotes[$sectionId] ?? ''));

        $stmtSection->bind_param(
            'dsssssii',
            $score,
            $label,
            $findings,
            $recs,
            $followUps,
            $notes,
            $sectionId,
            $riskRatingId
        );

        if (!$stmtSection->execute()) {
            $err = $stmtSection->error;
            $stmtSection->close();
            throw new RuntimeException('Failed to update section ID ' . $sectionId . ': ' . $err);
        }

        $pillarScoreTotal += $score;
        $pillarScoreCount++;
    }
    $stmtSection->close();

    /* ------------------------------------------------------------------------
       3. Update documents checklist
    ----------------------------------------------------------------------- */
    $submittedCheckYes = (array)post('check_yes', []);
    $submittedCheckNo = (array)post('check_no', []);
    $submittedCheckRemarks = (array)post('check_remarks', []);

    $validCheckRows = dbFetchAll(
        $conn,
        "SELECT id FROM risk_rating_checks WHERE risk_rating_id = ?",
        'i',
        [$riskRatingId]
    );
    $validCheckIds = array_map(static fn($r) => (int)$r['id'], $validCheckRows);

    $stmtCheck = $conn->prepare("
        UPDATE risk_rating_checks
        SET
            is_yes     = ?,
            is_no      = ?,
            remarks    = ?,
            updated_at = NOW()
        WHERE id = ? AND risk_rating_id = ?
    ");
    if (!$stmtCheck) {
        throw new RuntimeException('Prepare failed (checks): ' . $conn->error);
    }

    foreach ($validCheckIds as $checkId) {
        $isYes = isset($submittedCheckYes[$checkId]) ? 1 : 0;
        $isNo = isset($submittedCheckNo[$checkId]) ? 1 : 0;
        $remarks = trim((string)($submittedCheckRemarks[$checkId] ?? ''));

        if ($isYes === 1) {
            $isNo = 0;
        } elseif ($isNo === 1) {
            $isYes = 0;
        }

        $stmtCheck->bind_param('iisii', $isYes, $isNo, $remarks, $checkId, $riskRatingId);

        if (!$stmtCheck->execute()) {
            $err = $stmtCheck->error;
            $stmtCheck->close();
            throw new RuntimeException('Failed to update check ID ' . $checkId . ': ' . $err);
        }
    }
    $stmtCheck->close();

    /* ------------------------------------------------------------------------
       4. Upsert criteria scores
    ----------------------------------------------------------------------- */
    $criteriaReviewTypeIds = (array)post('criteria_review_type_id', []);
    $criteriaMaxScores = (array)post('criteria_max_score', []);
    $criteriaWeights = (array)post('criteria_weight', []);
    $criteriaAwarded = (array)post('criteria_awarded_score', []);
    $criteriaComments = (array)post('criteria_comments', []);

    $weightedTotal = 0.0;
    $criteriaCount = 0;

    if (!empty($criteriaAwarded)) {
        $activeCriteria = dbFetchAll(
            $conn,
            "SELECT criteria_id, question, review_type_id FROM review_criteria WHERE is_active = 1"
        );

        $criteriaLookup = [];
        foreach ($activeCriteria as $ac) {
            $criteriaLookup[(int)$ac['criteria_id']] = [
                'question' => (string)($ac['question'] ?? ''),
                'review_type_id' => (int)($ac['review_type_id'] ?? 0),
            ];
        }

        $stmtCriteria = $conn->prepare("
            INSERT INTO risk_rating_criteria_scores (
                risk_rating_id,
                review_type_id,
                criteria_id,
                criteria_question,
                max_score,
                weight,
                awarded_score,
                weighted_score,
                comments,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                review_type_id    = VALUES(review_type_id),
                criteria_question = VALUES(criteria_question),
                max_score         = VALUES(max_score),
                weight            = VALUES(weight),
                awarded_score     = VALUES(awarded_score),
                weighted_score    = VALUES(weighted_score),
                comments          = VALUES(comments),
                updated_at        = NOW()
        ");
        if (!$stmtCriteria) {
            throw new RuntimeException('Prepare failed (criteria): ' . $conn->error);
        }

        foreach ($criteriaAwarded as $rawCriteriaId => $rawAwarded) {
            $criteriaId = (int)$rawCriteriaId;
            if ($criteriaId <= 0 || !isset($criteriaLookup[$criteriaId])) {
                continue;
            }

            $reviewTypeId = (int)($criteriaReviewTypeIds[$criteriaId] ?? $criteriaLookup[$criteriaId]['review_type_id']);
            $maxScore = max(0.0, ensureFloat($criteriaMaxScores[$criteriaId] ?? 0));
            $weight = max(0.0, ensureFloat($criteriaWeights[$criteriaId] ?? 1));
            $awarded = max(0.0, ensureFloat($rawAwarded, 0));
            $comments = trim((string)($criteriaComments[$criteriaId] ?? ''));
            $question = $criteriaLookup[$criteriaId]['question'];

            if ($maxScore > 0 && $awarded > $maxScore) {
                $awarded = $maxScore;
            }

            $weightedScore = round($awarded * $weight, 2);

            $stmtCriteria->bind_param(
                'iiisdddds',
                $riskRatingId,
                $reviewTypeId,
                $criteriaId,
                $question,
                $maxScore,
                $weight,
                $awarded,
                $weightedScore,
                $comments
            );

            if (!$stmtCriteria->execute()) {
                $err = $stmtCriteria->error;
                $stmtCriteria->close();
                throw new RuntimeException('Failed to save criteria score for criteria ID ' . $criteriaId . ': ' . $err);
            }

            $weightedTotal += $weightedScore;
            $criteriaCount++;
        }

        $stmtCriteria->close();
    }

    /* ------------------------------------------------------------------------
       5. Recalculate overall rating
    ----------------------------------------------------------------------- */
    if ($criteriaCount > 0) {
        $overallScore = round($weightedTotal / $criteriaCount, 2);
    } elseif ($pillarScoreCount > 0) {
        $overallScore = round($pillarScoreTotal / $pillarScoreCount, 2);
    } else {
        $overallScore = 1.00;
    }

    if ($overallScore < 1.0) {
        $overallScore = 1.0;
    }

    $overallLabel = normalizeRiskLabel($overallScore);

    dbExecute(
        $conn,
        "
        UPDATE risk_ratings
        SET
            overall_rating       = ?,
            overall_rating_label = ?,
            updated_at           = NOW()
        WHERE id = ?
        ",
        'dsi',
        [$overallScore, $overallLabel, $riskRatingId]
    );

    /* ------------------------------------------------------------------------
       6. Generate reports when requested
    ----------------------------------------------------------------------- */
    $service = new RiskRatingReportService($conn);

    $generatedPdfPath = '';
    $generatedPdfName = '';
    $generatedExcelPath = '';
    $generatedExcelName = '';

    if ($action === 'generate' || $action === 'generate_both') {
        $pdf = $service->generatePdf($riskRatingId, $currentUserId);
        $generatedPdfPath = (string)($pdf['path'] ?? '');
        $generatedPdfName = (string)($pdf['name'] ?? '');

        if ($generatedPdfPath === '' || $generatedPdfName === '') {
            throw new RuntimeException('PDF generation failed - no output path returned.');
        }
    }

    if ($action === 'generate_excel' || $action === 'generate_both') {
        $excel = $service->generateExcelFromTemplate($riskRatingId, $currentUserId);
        $generatedExcelPath = (string)($excel['path'] ?? '');
        $generatedExcelName = (string)($excel['name'] ?? '');

        if ($generatedExcelPath === '' || $generatedExcelName === '') {
            throw new RuntimeException('Excel generation failed - no output path returned.');
        }
    }

    /* ------------------------------------------------------------------------
       7. Persist generation status and paths
    ----------------------------------------------------------------------- */
    $newStatus = ($action === 'save') ? 'draft' : 'generated';

    $setParts = ['status = ?', 'updated_at = NOW()'];
    $setTypes = 's';
    $setValues = [$newStatus];

    if ($generatedPdfPath !== '') {
        $setParts[] = 'generated_pdf_path = ?';
        $setParts[] = 'generated_pdf_name = ?';
        $setTypes .= 'ss';
        $setValues[] = $generatedPdfPath;
        $setValues[] = $generatedPdfName;
    }

    if ($generatedExcelPath !== '') {
        $setParts[] = 'generated_excel_path = ?';
        $setParts[] = 'generated_excel_name = ?';
        $setTypes .= 'ss';
        $setValues[] = $generatedExcelPath;
        $setValues[] = $generatedExcelName;
    }

    $setParts[] = 'prepared_by = ?';
    $setTypes .= 'i';
    $setValues[] = $currentUserId;

    $setValues[] = $riskRatingId;
    $setTypes .= 'i';

    dbExecute(
        $conn,
        'UPDATE risk_ratings SET ' . implode(', ', $setParts) . ' WHERE id = ?',
        $setTypes,
        $setValues
    );

    $conn->commit();

    $_SESSION['success'] = match ($action) {
        'generate'       => 'Risk rating saved and PDF generated successfully.',
        'generate_excel' => 'Risk rating saved and Excel generated successfully.',
        'generate_both'  => 'Risk rating saved, PDF and Excel generated successfully.',
        default          => 'Risk rating saved successfully.',
    };

    redirectToRating($applicationId, $selectedReviewTypeId);

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignore) {
    }

    $_SESSION['error'] = 'Error: ' . $e->getMessage();
    redirectToRating($applicationId, $selectedReviewTypeId);
}