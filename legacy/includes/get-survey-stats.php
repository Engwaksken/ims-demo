<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

// Survey results are internal data: same roles as survey-analytics.php.
if (!ims_allowed('surveys.view_stats', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Executive Director', 'Program Director'], 'View survey results and statistics')) {
    http_response_code(auth_is_logged_in() ? 403 : 401);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

$surveyId = isset($_GET['survey_id']) ? (int)$_GET['survey_id'] : 0;
if ($surveyId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid survey ID']);
    exit;
}

// Fetch questions with options
$qStmt = $conn->prepare("
    SELECT q.question_id, q.question_text, q.question_type,
           GROUP_CONCAT(o.option_label ORDER BY o.option_id SEPARATOR '||') AS options_list
    FROM survey_questions q
    LEFT JOIN survey_options o ON o.question_id = q.question_id
    WHERE q.survey_id = ?
    GROUP BY q.question_id
    ORDER BY q.question_order ASC
");
$qStmt->bind_param("i", $surveyId);
$qStmt->execute();
$qResult = $qStmt->get_result();

$questions = [];

while ($q = $qResult->fetch_assoc()) {
    $q['options'] = $q['options_list'] ? explode('||', $q['options_list']) : [];

    // Fetch answers counts
    $aStmt = $conn->prepare("
        SELECT answer_text, COUNT(*) AS cnt
        FROM survey_answers
        WHERE question_id = ?
        GROUP BY answer_text
    ");
    $aStmt->bind_param("i", $q['question_id']);
    $aStmt->execute();
    $aResult = $aStmt->get_result();

    $labels = [];
    $data   = [];

    while ($row = $aResult->fetch_assoc()) {
        $labels[] = $row['answer_text'];
        $data[]   = (int)$row['cnt'];
    }
    $aStmt->close();

    // For text-based questions, limit to top 5 answers
    if (in_array($q['question_type'], ['text', 'textarea'], true)) {
        array_multisort($data, SORT_DESC, $labels);
        $labels = array_slice($labels, 0, 5);
        $data   = array_slice($data, 0, 5);
    }

    // For multiple choice, ensure all options are represented even if count=0
    if (in_array($q['question_type'], ['radio','checkbox','select'], true)) {
        $counts = array_combine($q['options'], array_fill(0, count($q['options']), 0));
        foreach ($labels as $i => $label) {
            $counts[$label] = $data[$i] ?? 0;
        }
        $labels = array_keys($counts);
        $data   = array_values($counts);
    }

    $q['labels'] = $labels;
    $q['data']   = $data;
    unset($q['options_list']); // cleanup
    $questions[] = $q;
}

$qStmt->close();

echo json_encode([
    'success'   => true,
    'questions' => $questions
]);