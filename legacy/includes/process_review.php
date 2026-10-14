<?php
declare(strict_types=1);

session_start();
require_once 'config.php';

if (!ims_allowed('reviews.submit', ['Reviewer', 'MEAL Lead', 'Programs Lead', 'Program Director', 'Consultant', 'Project Officer', 'Administrator'], 'Submit application reviews')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ../dashboard');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['error'] = 'Database connection not available.';
    header('Location: ../dashboard');
    exit();
}

$conn->set_charset('utf8mb4');

$reviewer_id      = (int)($_SESSION['user_id'] ?? 0);
$application_id   = (int)($_POST['application_id'] ?? 0);
$review_type_id   = (int)($_POST['review_type_id'] ?? 0);
$recommendation   = trim((string)($_POST['recommendation'] ?? ''));
$comments         = trim((string)($_POST['comments'] ?? ''));
$overall_score_in = (float)($_POST['overall_score'] ?? 0);
$criteria_scores  = $_POST['criteria'] ?? [];
$ajax_action      = trim((string)($_POST['ajax_action'] ?? ''));
$is_ajax          = ($ajax_action !== '') ||
                    (
                        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                        strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                    );

$back = "../review-applicant?id={$application_id}&rt={$review_type_id}";

function respond(bool $success, string $message, array $extra = []): never
{
    global $is_ajax, $back;

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            array_merge([
                'success' => $success,
                'message' => $message,
            ], $extra),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit();
    }

    if ($success) {
        $_SESSION['success'] = $message;
    } else {
        $_SESSION['error'] = $message;
    }

    header('Location: ' . $back);
    exit();
}

function normalizeCategory(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = strtoupper($value);
    $value = str_replace(' AND ', ' & ', $value);
    $value = preg_replace('/\s*&\s*/', ' & ', $value) ?? $value;
    $value = preg_replace('/\s*,\s*/', ', ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value, " \t\n\r\0\x0B./-");
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

    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();
    return $rows;
}

function fetchOneAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = fetchAllAssoc($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

if ($application_id <= 0) {
    respond(false, 'Invalid application.');
}

if ($review_type_id <= 0) {
    respond(false, 'Invalid review type.');
}

if (!is_array($criteria_scores)) {
    $criteria_scores = [];
}

$is_final_submit = ($ajax_action === 'final_submit') || (!$is_ajax);

$valid_recommendations = ['Accept', 'Reject', 'Needs More Info', 'Shortlist', 'Hold', ''];
if (!in_array($recommendation, $valid_recommendations, true)) {
    $recommendation = '';
}

if ($is_final_submit && $recommendation === '') {
    respond(false, 'Please select a valid recommendation.');
}

$category_column_map = [
    'EQUITY, INCLUSION & ACCESSIBILITY' => 'equity_score',
    'EQUITY, INCLUSION AND ACCESSIBILITY' => 'equity_score',
    'LINK TO A SHORT VIDEO INTRODUCING YOUR SOLUTION, THE EDUCATION ISSUE YOU ADDRESS, & YOUR TEAM' => 'video_intro_score',
    'LINK TO A SHORT VIDEO INTRODUCING YOUR SOLUTION, THE EDUCATION ISSUE YOU ADDRESS, AND YOUR TEAM' => 'video_intro_score',
    'PROBLEM DEPTH AND THEORY OF CHANGE' => 'problem_depth_toc_score',
    'PROBLEM DEPTH & THEORY OF CHANGE' => 'problem_depth_toc_score',
    'PRODUCT QUALITY & PEDAGOGICAL ALIGNMENT' => 'product_quality_pedagogy_score',
    'PRODUCT WEBSITE OR DEMO LINK' => 'product_demo_link_score',
    'SCALABILITY, TRACTION & SUSTAINABILITY' => 'scalability_traction_sustainability_score',
    'TEAM CAPABILITY & COMMITMENT' => 'team_capability_commitment_score',
    'URSB REGISTRATION' => 'ursb_registration_score',
];

$summary_scores = [
    'equity_score'                              => 0.00,
    'video_intro_score'                         => 0.00,
    'problem_depth_toc_score'                   => 0.00,
    'product_quality_pedagogy_score'            => 0.00,
    'product_demo_link_score'                   => 0.00,
    'scalability_traction_sustainability_score' => 0.00,
    'team_capability_commitment_score'          => 0.00,
    'ursb_registration_score'                   => 0.00,
];

$conn->begin_transaction();

try {
    $application = fetchOneAssoc(
        $conn,
        "SELECT application_id FROM applications WHERE application_id = ? LIMIT 1",
        'i',
        [$application_id]
    );

    if (!$application) {
        throw new RuntimeException('Application not found.');
    }

    $review_type = fetchOneAssoc(
        $conn,
        "SELECT review_type_id, name
         FROM review_types
         WHERE review_type_id = ? AND is_active = 1
         LIMIT 1",
        'i',
        [$review_type_id]
    );

    if (!$review_type) {
        throw new RuntimeException('Selected review type is invalid or inactive.');
    }

    $can_review_unassigned = ims_allowed('reviews.review_unassigned', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Reviewer', 'Consultant'], 'Review applications they are not assigned to');

    $assignment = fetchOneAssoc(
        $conn,
        "SELECT assignment_id, status
         FROM reviewer_assignments
         WHERE application_id = ?
           AND reviewer_id = ?
           AND review_type_id = ?
         LIMIT 1",
        'iii',
        [$application_id, $reviewer_id, $review_type_id]
    );

    if (!$can_review_unassigned && !$assignment) {
        throw new RuntimeException('You are not assigned to review this application for the selected review type.');
    }

    $criteria_rows = fetchAllAssoc(
        $conn,
        "SELECT criteria_id, category, question, max_score, weight
         FROM review_criteria
         WHERE is_active = 1
           AND review_type_id = ?
         ORDER BY category ASC, criteria_id ASC",
        'i',
        [$review_type_id]
    );

    if (empty($criteria_rows)) {
        throw new RuntimeException('No active criteria found for the selected review type.');
    }

    $criteria_meta = [];
    $total_possible_weighted = 0.0;

    foreach ($criteria_rows as $row) {
        $criteria_id = (int)$row['criteria_id'];
        $max_score   = (float)($row['max_score'] ?? 0);
        $weight      = (float)($row['weight'] ?? 1);

        if ($weight <= 0) {
            $weight = 1.0;
        }

        $criteria_meta[$criteria_id] = [
            'category'  => normalizeCategory((string)($row['category'] ?? '')),
            'question'  => (string)($row['question'] ?? ''),
            'max_score' => $max_score,
            'weight'    => $weight,
        ];

        $total_possible_weighted += ($max_score * $weight);
    }

    $normalized_scores = [];
    $weighted_earned_total = 0.0;
    $filled_scores_count = 0;

    foreach ($criteria_meta as $criteria_id => $meta) {
        $raw_score = $criteria_scores[$criteria_id] ?? 0;
        $score = is_numeric($raw_score) ? (float)$raw_score : 0.0;

        if ($score < 0) {
            $score = 0.0;
        }

        if ($score > $meta['max_score']) {
            $score = (float)$meta['max_score'];
        }

        $score = round($score, 2);
        $normalized_scores[$criteria_id] = $score;
        $weighted_earned_total += ($score * (float)$meta['weight']);

        if ($score > 0) {
            $filled_scores_count++;
        }

        $normalized_category = $meta['category'];
        if (isset($category_column_map[$normalized_category])) {
            $column = $category_column_map[$normalized_category];
            $summary_scores[$column] += $score;
        }
    }

    foreach ($summary_scores as $key => $value) {
        $summary_scores[$key] = round((float)$value, 2);
    }

    if ($total_possible_weighted > 0) {
        $overall = round(($weighted_earned_total / $total_possible_weighted) * 100, 1);
    } else {
        $overall = round(max(0.0, $overall_score_in), 1);
    }

    if ($overall < 0) {
        $overall = 0.0;
    }
    if ($overall > 100) {
        $overall = 100.0;
    }

    $existing_summary = fetchOneAssoc(
        $conn,
        "SELECT review_id
         FROM application_reviews
         WHERE application_id = ?
           AND reviewer_id = ?
           AND review_type_id = ?
         LIMIT 1",
        'iii',
        [$application_id, $reviewer_id, $review_type_id]
    );

    $equity_score                              = $summary_scores['equity_score'];
    $video_intro_score                         = $summary_scores['video_intro_score'];
    $problem_depth_toc_score                   = $summary_scores['problem_depth_toc_score'];
    $product_quality_pedagogy_score            = $summary_scores['product_quality_pedagogy_score'];
    $product_demo_link_score                   = $summary_scores['product_demo_link_score'];
    $scalability_traction_sustainability_score = $summary_scores['scalability_traction_sustainability_score'];
    $team_capability_commitment_score          = $summary_scores['team_capability_commitment_score'];
    $ursb_registration_score                   = $summary_scores['ursb_registration_score'];

    if ($existing_summary) {
        $review_id = (int)$existing_summary['review_id'];

        $update_summary = $conn->prepare(
            "UPDATE application_reviews
             SET review_type_id = ?,
                 equity_score = ?,
                 video_intro_score = ?,
                 problem_depth_toc_score = ?,
                 product_quality_pedagogy_score = ?,
                 product_demo_link_score = ?,
                 scalability_traction_sustainability_score = ?,
                 team_capability_commitment_score = ?,
                 ursb_registration_score = ?,
                 overall_score = ?,
                 comments = ?,
                 recommendation = ?,
                 updated_at = NOW()
             WHERE review_id = ?
             LIMIT 1"
        );

        if (!$update_summary) {
            throw new RuntimeException('Prepare failed (update review summary): ' . $conn->error);
        }

        $update_summary->bind_param(
            'idddddddddssi',
            $review_type_id,
            $equity_score,
            $video_intro_score,
            $problem_depth_toc_score,
            $product_quality_pedagogy_score,
            $product_demo_link_score,
            $scalability_traction_sustainability_score,
            $team_capability_commitment_score,
            $ursb_registration_score,
            $overall,
            $comments,
            $recommendation,
            $review_id
        );

        if (!$update_summary->execute()) {
            $error = $update_summary->error;
            $update_summary->close();
            throw new RuntimeException('Execute failed (update review summary): ' . $error);
        }

        $update_summary->close();
    } else {
        $insert_summary = $conn->prepare(
            "INSERT INTO application_reviews
            (
                application_id,
                reviewer_id,
                review_type_id,
                equity_score,
                video_intro_score,
                problem_depth_toc_score,
                product_quality_pedagogy_score,
                product_demo_link_score,
                scalability_traction_sustainability_score,
                team_capability_commitment_score,
                ursb_registration_score,
                overall_score,
                comments,
                recommendation,
                created_at,
                updated_at
            )
            VALUES
            (
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                NOW(), NOW()
            )"
        );

        if (!$insert_summary) {
            throw new RuntimeException('Prepare failed (insert review summary): ' . $conn->error);
        }

        $insert_summary->bind_param(
            'iiidddddddddss',
            $application_id,
            $reviewer_id,
            $review_type_id,
            $equity_score,
            $video_intro_score,
            $problem_depth_toc_score,
            $product_quality_pedagogy_score,
            $product_demo_link_score,
            $scalability_traction_sustainability_score,
            $team_capability_commitment_score,
            $ursb_registration_score,
            $overall,
            $comments,
            $recommendation
        );

        if (!$insert_summary->execute()) {
            $error = $insert_summary->error;
            $insert_summary->close();
            throw new RuntimeException('Execute failed (insert review summary): ' . $error);
        }

        $insert_summary->close();
    }

    $select_score_stmt = $conn->prepare(
        "SELECT score_id
         FROM review_scores
         WHERE application_id = ?
           AND reviewer_id = ?
           AND review_type_id = ?
           AND criteria_id = ?
         LIMIT 1"
    );

    $update_score_stmt = $conn->prepare(
        "UPDATE review_scores
         SET score = ?,
             comments = '',
             updated_at = NOW()
         WHERE score_id = ?"
    );

    $insert_score_stmt = $conn->prepare(
        "INSERT INTO review_scores
        (
            application_id,
            reviewer_id,
            review_type_id,
            criteria_id,
            score,
            comments,
            scored_at,
            created_at,
            updated_at
        )
        VALUES
        (
            ?, ?, ?, ?, ?, '', NOW(), NOW(), NOW()
        )"
    );

    if (!$select_score_stmt || !$update_score_stmt || !$insert_score_stmt) {
        throw new RuntimeException('Prepare failed for review_scores upsert.');
    }

    foreach ($normalized_scores as $criteria_id => $score) {
        $criteria_id = (int)$criteria_id;
        $score = round((float)$score, 2);

        $select_score_stmt->bind_param(
            'iiii',
            $application_id,
            $reviewer_id,
            $review_type_id,
            $criteria_id
        );
        $select_score_stmt->execute();
        $score_lookup_res = $select_score_stmt->get_result();
        $score_row = $score_lookup_res ? $score_lookup_res->fetch_assoc() : null;

        if ($score_row) {
            $score_id = (int)$score_row['score_id'];
            $update_score_stmt->bind_param('di', $score, $score_id);

            if (!$update_score_stmt->execute()) {
                throw new RuntimeException('Execute failed (update review_scores): ' . $update_score_stmt->error);
            }
        } else {
            $insert_score_stmt->bind_param(
                'iiiid',
                $application_id,
                $reviewer_id,
                $review_type_id,
                $criteria_id,
                $score
            );

            if (!$insert_score_stmt->execute()) {
                throw new RuntimeException('Execute failed (insert review_scores): ' . $insert_score_stmt->error);
            }
        }
    }

    $select_score_stmt->close();
    $update_score_stmt->close();
    $insert_score_stmt->close();

    $assignment_status = 'Pending';
    if ($filled_scores_count === count($criteria_meta) && $recommendation !== '' && $comments !== '') {
        $assignment_status = 'Completed';
    } elseif ($filled_scores_count > 0 || $recommendation !== '' || $comments !== '') {
        $assignment_status = 'In Progress';
    }

    $update_assignment = $conn->prepare(
        "UPDATE reviewer_assignments
         SET status = ?
         WHERE application_id = ?
           AND reviewer_id = ?
           AND review_type_id = ?"
    );

    if ($update_assignment) {
        $update_assignment->bind_param('siii', $assignment_status, $application_id, $reviewer_id, $review_type_id);
        $update_assignment->execute();
        $update_assignment->close();
    }

    $conn->commit();

    $message = $is_final_submit
        ? 'Review saved successfully.'
        : 'Draft autosaved successfully.';

    respond(true, $message, [
        'overall_score' => $overall,
        'weighted_total' => round($weighted_earned_total, 2),
        'weighted_possible' => round($total_possible_weighted, 2),
        'assignment_status' => $assignment_status,
        'filled_scores' => $filled_scores_count,
        'total_criteria' => count($criteria_meta),
        'saved_at' => date('Y-m-d H:i:s'),
        'mode' => $is_final_submit ? 'final_submit' : 'autosave',
    ]);
} catch (Throwable $e) {
    $conn->rollback();

    error_log(
        sprintf(
            'process_review error: %s | application_id=%d | reviewer_id=%d | review_type_id=%d',
            $e->getMessage(),
            $application_id,
            $reviewer_id,
            $review_type_id
        )
    );

    respond(false, 'Failed to save review. ' . $e->getMessage());
}