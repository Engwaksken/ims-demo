<?php
ob_start();
session_start();

date_default_timezone_set('Africa/Nairobi');

require_once __DIR__ . '/config.php';

if (!ims_allowed('application_scores.manage', ['Administrator'], 'Manage and delete application review scores')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ../dashboard');
    exit();
}

function redirectBack(string $message, bool $success = false): never
{
    $_SESSION[$success ? 'success' : 'error'] = $message;
    header('Location: ../manage_application_scores');
    exit();
}

$action = trim((string)($_POST['action'] ?? ''));
$nairobiNow = date('Y-m-d H:i:s');

if (!isset($conn) || !($conn instanceof mysqli)) {
    redirectBack('Database connection not available.');
}

$conn->set_charset('utf8mb4');

if ($action === 'delete_review') {
    $review_id       = (int)($_POST['review_id'] ?? 0);
    $application_id  = (int)($_POST['application_id'] ?? 0);
    $reviewer_id     = (int)($_POST['reviewer_id'] ?? 0);
    $review_type_id  = (int)($_POST['review_type_id'] ?? 0);

    if ($review_id <= 0 || $application_id <= 0 || $reviewer_id <= 0 || $review_type_id <= 0) {
        redirectBack('Invalid delete request.');
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            DELETE FROM review_scores
            WHERE application_id = ?
              AND reviewer_id = ?
              AND review_type_id = ?
        ");
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('iii', $application_id, $reviewer_id, $review_type_id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception($err);
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM application_reviews
            WHERE review_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $review_id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception($err);
        }
        $stmt->close();

        $conn->commit();
        redirectBack('Review deleted successfully.', true);
    } catch (Throwable $e) {
        $conn->rollback();
        redirectBack('Failed to delete review: ' . $e->getMessage());
    }
}

if ($action === 'update_review') {
    $review_id          = (int)($_POST['review_id'] ?? 0);
    $application_id     = (int)($_POST['application_id'] ?? 0);
    $reviewer_id        = (int)($_POST['reviewer_id'] ?? 0);
    $review_type_id     = (int)($_POST['review_type_id'] ?? 0);
    $overall_score      = (float)($_POST['overall_score'] ?? 0);
    $recommendation     = trim((string)($_POST['recommendation'] ?? ''));
    $review_comments    = trim((string)($_POST['review_comments'] ?? ''));
    $scores             = $_POST['scores'] ?? [];
    $criterion_comments = $_POST['criterion_comments'] ?? [];

    $allowedRecommendations = ['Accept', 'Shortlist', 'Reject', 'Needs More Info'];

    if ($review_id <= 0 || $application_id <= 0 || $reviewer_id <= 0 || $review_type_id <= 0) {
        redirectBack('Invalid update request.');
    }

    if ($review_comments === '') {
        redirectBack('Review comments are required.');
    }

    if (!in_array($recommendation, $allowedRecommendations, true)) {
        redirectBack('Invalid recommendation.');
    }

    if (!is_array($scores)) {
        $scores = [];
    }

    if (!is_array($criterion_comments)) {
        $criterion_comments = [];
    }

    $conn->begin_transaction();

    try {
        foreach ($scores as $score_id => $score_value) {
            $score_id = (int)$score_id;
            if ($score_id <= 0) {
                continue;
            }

            $scoreVal = (float)$score_value;
            $comment  = trim((string)($criterion_comments[$score_id] ?? ''));

            $stmt = $conn->prepare("
                SELECT rs.score_id, rc.max_score
                FROM review_scores rs
                INNER JOIN review_criteria rc ON rc.criteria_id = rs.criteria_id
                WHERE rs.score_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param('i', $score_id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception($err);
            }

            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                continue;
            }

            $maxScore = (float)($row['max_score'] ?? 0);
            if ($maxScore > 0) {
                $scoreVal = max(0, min($scoreVal, $maxScore));
            }

            $stmt = $conn->prepare("
                UPDATE review_scores
                SET score = ?, comments = ?, updated_at = ?
                WHERE score_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param('dssi', $scoreVal, $comment, $nairobiNow, $score_id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception($err);
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("
            UPDATE application_reviews
            SET overall_score = ?,
                comments = ?,
                recommendation = ?,
                updated_at = ?
            WHERE review_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('dsssi', $overall_score, $review_comments, $recommendation, $nairobiNow, $review_id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception($err);
        }
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE reviewer_assignments
            SET updated_at = ?
            WHERE application_id = ?
              AND reviewer_id = ?
              AND review_type_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('siii', $nairobiNow, $application_id, $reviewer_id, $review_type_id);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        redirectBack('Review updated successfully.', true);
    } catch (Throwable $e) {
        $conn->rollback();
        redirectBack('Failed to update review: ' . $e->getMessage());
    }
}

redirectBack('Invalid action.');