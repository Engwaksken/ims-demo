<?php

ob_start();
session_start();

date_default_timezone_set('Africa/Nairobi');

require_once 'includes/config.php';

header('Content-Type: application/json; charset=UTF-8');

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/* ==========================
   AUTH
========================== */
if (!ims_allowed('reviews.save_scores', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer'], 'Save review scores and comments')) {
    jsonResponse(403, ['message' => 'Access denied']);
}

$reviewer_id = (int)($_SESSION['user_id'] ?? 0);
if ($reviewer_id <= 0) {
    jsonResponse(401, ['message' => 'Not logged in']);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    jsonResponse(500, ['message' => 'Database connection not available']);
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
| Use PHP Nairobi time only.
| Do not use:
|   $conn->query("SET time_zone = '+03:00'");
|   NOW()
|   CONVERT_TZ()
*/
$nairobiNow = date('Y-m-d H:i:s');

/* ==========================
   INPUT
========================== */
$application_id = (int)($_POST['application_id'] ?? 0);
$review_type_id = (int)($_POST['review_type_id'] ?? 0);
$scores         = $_POST['scores'] ?? [];
$comments       = $_POST['comments'] ?? [];
$final          = (int)($_POST['final'] ?? 0);
$final_comments = trim((string)($_POST['final_comments'] ?? ''));
$recommendation = trim((string)($_POST['recommendation'] ?? ''));

$allowedRecommendations = ['Accept', 'Shortlist', 'Reject', 'Needs More Info'];

/* ==========================
   VALIDATION
========================== */
if ($application_id <= 0) {
    jsonResponse(422, ['message' => 'Invalid application_id']);
}

if ($review_type_id <= 0) {
    jsonResponse(422, ['message' => 'Invalid review_type_id']);
}

if (!is_array($scores)) {
    $scores = [];
}

if (!is_array($comments)) {
    $comments = [];
}

if ($final_comments === '') {
    jsonResponse(422, ['message' => 'Reviewer comments are required']);
}

if (mb_strlen($final_comments) < 10) {
    jsonResponse(422, ['message' => 'Please provide more detailed reviewer comments (at least 10 characters).']);
}

if ($recommendation === '') {
    jsonResponse(422, ['message' => 'Recommendation is required']);
}

if (!in_array($recommendation, $allowedRecommendations, true)) {
    jsonResponse(422, ['message' => 'Invalid recommendation']);
}

/* ==========================
   AUTHORIZATION
========================== */
$stmt = $conn->prepare("
    SELECT assignment_id, status
    FROM reviewer_assignments
    WHERE application_id = ?
      AND reviewer_id = ?
    LIMIT 1
");
if (!$stmt) {
    jsonResponse(500, ['message' => 'Failed to prepare reviewer assignment check']);
}

$stmt->bind_param("ii", $application_id, $reviewer_id);

if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(500, ['message' => 'Failed to verify reviewer assignment']);
}

$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assignment) {
    jsonResponse(403, ['message' => 'Not authorized']);
}

/* ==========================
   VERIFY APPLICATION + CURRENT REVIEW TYPE
========================== */
$stmt = $conn->prepare("
    SELECT application_id, review_type_id
    FROM applications
    WHERE application_id = ?
    LIMIT 1
");
if (!$stmt) {
    jsonResponse(500, ['message' => 'Failed to prepare application check']);
}

$stmt->bind_param("i", $application_id);

if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(500, ['message' => 'Failed to verify application']);
}

$appRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appRow) {
    jsonResponse(404, ['message' => 'Application not found']);
}

$current_app_review_type_id = (int)($appRow['review_type_id'] ?? 0);
if ($current_app_review_type_id > 0 && $current_app_review_type_id !== $review_type_id) {
    jsonResponse(422, ['message' => 'This application is not currently in the selected review type']);
}

/* ==========================
   SAVE REVIEW
========================== */
$conn->begin_transaction();

try {
    $hasAnyScore = false;

    foreach ($scores as $criteria_id => $score) {
        $criteria_id = (int)$criteria_id;
        if ($criteria_id <= 0 || $score === '' || $score === null) {
            continue;
        }

        $scoreVal = (float)$score;
        $comment  = trim((string)($comments[$criteria_id] ?? ''));

        $critStmt = $conn->prepare("
            SELECT criteria_id, max_score
            FROM review_criteria
            WHERE criteria_id = ?
              AND is_active = 1
            LIMIT 1
        ");
        if (!$critStmt) {
            throw new Exception("Prepare failed (review_criteria): " . $conn->error);
        }

        $critStmt->bind_param("i", $criteria_id);

        if (!$critStmt->execute()) {
            $err = $critStmt->error;
            $critStmt->close();
            throw new Exception("Execute failed (review_criteria): " . $err);
        }

        $critRow = $critStmt->get_result()->fetch_assoc();
        $critStmt->close();

        if (!$critRow) {
            continue;
        }

        $maxScore = (float)($critRow['max_score'] ?? 0);
        if ($maxScore > 0) {
            $scoreVal = max(0, min($scoreVal, $maxScore));
        }

        $createdAt = $nairobiNow;
        $updatedAt = $nairobiNow;

        $st = $conn->prepare("
            INSERT INTO review_scores
                (
                    application_id,
                    reviewer_id,
                    review_type_id,
                    criteria_id,
                    score,
                    comments,
                    created_at,
                    updated_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                review_type_id = VALUES(review_type_id),
                score          = VALUES(score),
                comments       = VALUES(comments),
                updated_at     = VALUES(updated_at)
        ");
        if (!$st) {
            throw new Exception("Prepare failed (review_scores): " . $conn->error);
        }

        $st->bind_param(
            "iiiidsss",
            $application_id,
            $reviewer_id,
            $review_type_id,
            $criteria_id,
            $scoreVal,
            $comment,
            $createdAt,
            $updatedAt
        );

        if (!$st->execute()) {
            $err = $st->error;
            $st->close();
            throw new Exception("Execute failed (review_scores): " . $err);
        }

        $st->close();
        $hasAnyScore = true;
    }

    if (!$hasAnyScore) {
        throw new Exception("Please score at least one criterion.");
    }

    /* ==========================
       CALCULATE OVERALL SCORE
    ========================== */
    $totalWeightedPercent = 0.0;

    $st = $conn->prepare("
        SELECT
            rs.score,
            rc.max_score,
            COALESCE(rc.weight, 1) AS weight
        FROM review_scores rs
        INNER JOIN review_criteria rc
            ON rc.criteria_id = rs.criteria_id
        WHERE rs.application_id = ?
          AND rs.reviewer_id    = ?
          AND rs.review_type_id = ?
          AND rc.is_active = 1
    ");
    if (!$st) {
        throw new Exception("Prepare failed (calc): " . $conn->error);
    }

    $st->bind_param("iii", $application_id, $reviewer_id, $review_type_id);

    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new Exception("Execute failed (calc): " . $err);
    }

    $res = $st->get_result();

    while ($row = $res->fetch_assoc()) {
        $s  = (float)($row['score'] ?? 0);
        $mx = (float)($row['max_score'] ?? 0);
        $w  = (float)($row['weight'] ?? 1);

        if ($mx > 0) {
            $totalWeightedPercent += ($s / $mx) * $w;
        }
    }
    $st->close();

    $overall = round($totalWeightedPercent, 2);

    /* ==========================
       SAVE FINAL / DRAFT REVIEW
    ========================== */
    $createdAt = $nairobiNow;
    $updatedAt = $nairobiNow;

    $st = $conn->prepare("
        INSERT INTO application_reviews
            (
                application_id,
                reviewer_id,
                review_type_id,
                overall_score,
                comments,
                recommendation,
                created_at,
                updated_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            overall_score   = VALUES(overall_score),
            comments        = VALUES(comments),
            recommendation  = VALUES(recommendation),
            updated_at      = VALUES(updated_at)
    ");
    if (!$st) {
        throw new Exception("Prepare failed (application_reviews): " . $conn->error);
    }

    $st->bind_param(
        "iiidssss",
        $application_id,
        $reviewer_id,
        $review_type_id,
        $overall,
        $final_comments,
        $recommendation,
        $createdAt,
        $updatedAt
    );

    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new Exception("Execute failed (application_reviews): " . $err);
    }
    $st->close();

    /* ==========================
       UPDATE ASSIGNMENT STATUS
    ========================== */
    $new_status = $final ? 'Completed' : 'In Progress';

    $st = $conn->prepare("
        UPDATE reviewer_assignments
        SET status = ?,
            updated_at = ?
        WHERE application_id = ?
          AND reviewer_id    = ?
    ");
    if (!$st) {
        throw new Exception("Prepare failed (assignments): " . $conn->error);
    }

    $st->bind_param("ssii", $new_status, $updatedAt, $application_id, $reviewer_id);

    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        throw new Exception("Execute failed (assignments): " . $err);
    }
    $st->close();

    $conn->commit();

    jsonResponse(200, [
        'message'        => $final ? 'Review submitted successfully.' : 'Draft saved.',
        'overall'        => $overall,
        'timezone'       => 'Africa/Nairobi',
        'saved_at'       => $nairobiNow,
        'recommendation' => $recommendation,
        'status'         => $new_status,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    error_log("save_review error: " . $e->getMessage());
    jsonResponse(500, [
        'message' => 'Failed to save review',
        'error'   => $e->getMessage(),
    ]);
}