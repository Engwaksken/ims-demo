<?php
require_once 'includes/config.php';

/* ----------------------------------------------------------
   GET SURVEY BY TOKEN
---------------------------------------------------------- */
$token = $_GET['token'] ?? '';
if (!$token) {
    die("Invalid survey link.");
}

$stmt = $conn->prepare("
    SELECT *
    FROM surveys
    WHERE file_path = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$survey) {
    die("Survey not found or inactive.");
}

$surveyId = (int)$survey['survey_id'];

/* ----------------------------------------------------------
   LOAD QUESTIONS
---------------------------------------------------------- */
$questionsStmt = $conn->prepare("
    SELECT q.*, GROUP_CONCAT(o.option_label ORDER BY o.option_id SEPARATOR '||') AS options_list
    FROM survey_questions q
    LEFT JOIN survey_options o ON o.question_id = q.question_id
    WHERE q.survey_id = ?
    GROUP BY q.question_id
    ORDER BY q.question_order
");
$questionsStmt->bind_param("i", $surveyId);
$questionsStmt->execute();
$questionsResult = $questionsStmt->get_result();
$questionsStmt->close();
?>

<h2><?= htmlspecialchars($survey['survey_name'], ENT_QUOTES, 'UTF-8') ?></h2>

<form method="POST" action="includes/submit-survey.php">
    <input type="hidden" name="survey_id" value="<?= $surveyId ?>">

    <?php while ($q = $questionsResult->fetch_assoc()): 
        $options = $q['options_list'] ? explode('||', $q['options_list']) : [];
    ?>
        <div class="mb-3">
            <label class="form-label"><?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($q['is_required']): ?><span class="text-danger">*</span><?php endif; ?>
            </label>

            <?php if ($q['question_type'] === 'text'): ?>
                <input type="text" name="q<?= $q['question_id'] ?>" class="form-control" <?= $q['is_required'] ? 'required' : '' ?>>
            <?php elseif ($q['question_type'] === 'textarea'): ?>
                <textarea name="q<?= $q['question_id'] ?>" class="form-control" <?= $q['is_required'] ? 'required' : '' ?>></textarea>
            <?php elseif (in_array($q['question_type'], ['radio','checkbox','select'])): ?>
                <?php if ($q['question_type'] === 'select'): ?>
                    <select name="q<?= $q['question_id'] ?>" class="form-select" <?= $q['is_required'] ? 'required' : '' ?>>
                        <option value="">Select...</option>
                        <?php foreach ($options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php foreach ($options as $opt): ?>
                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="<?= $q['question_type'] ?>"
                                name="q<?= $q['question_id'] ?><?= $q['question_type']==='checkbox' ? '[]' : '' ?>"
                                value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $q['is_required'] && $q['question_type']==='radio' ? 'required' : '' ?>
                            >
                            <label class="form-check-label"><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php elseif ($q['question_type'] === 'number'): ?>
                <input type="number" name="q<?= $q['question_id'] ?>" class="form-control" <?= $q['is_required'] ? 'required' : '' ?>>
            <?php elseif ($q['question_type'] === 'date'): ?>
                <input type="date" name="q<?= $q['question_id'] ?>" class="form-control" <?= $q['is_required'] ? 'required' : '' ?>>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>

    <button type="submit" class="btn btn-primary">Submit</button>
</form>