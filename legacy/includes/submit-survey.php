<?php
require_once __DIR__ . '/config.php';

$surveyId = (int)($_POST['survey_id'] ?? 0);
if ($surveyId <= 0) {
    die("Invalid survey submission.");
}

// Only accept answers to questions that belong to this survey.
$validQuestionIds = [];
$chk = $conn->prepare("SELECT q.question_id FROM survey_questions q JOIN surveys s ON s.survey_id = q.survey_id WHERE s.survey_id = ?");
if ($chk) {
    $chk->bind_param("i", $surveyId);
    $chk->execute();
    $chkRes = $chk->get_result();
    while ($chkRow = $chkRes->fetch_assoc()) {
        $validQuestionIds[(int)$chkRow['question_id']] = true;
    }
    $chk->close();
}
if (!$validQuestionIds) {
    die("Survey not found.");
}

$ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

$stmt = $conn->prepare("
    INSERT INTO survey_responses (survey_id, ip_address, submitted_at)
    VALUES (?, ?, NOW())
");
$stmt->bind_param("is", $surveyId, $ipAddress);
$stmt->execute();
$responseId = $stmt->insert_id;
$stmt->close();


foreach ($_POST as $key => $value) {
    if (str_starts_with($key, 'q')) {
        $questionId = (int)str_replace('q', '', $key);
        if (!isset($validQuestionIds[$questionId])) {
            continue;
        }

        if (is_array($value)) {
            $value = implode('||', array_map('trim', $value)); 
        }

        $stmt = $conn->prepare("
            INSERT INTO survey_answers
            (response_id, question_id, answer_text)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iis", $responseId, $questionId, $value);
        $stmt->execute();
        $stmt->close();
    }
}

echo "<div class='alert alert-success'>Thank you for submitting the survey.</div>";