<?php
declare(strict_types=1);

require_once 'includes/header.php';
check_role(['Administrator', 'MEAL Lead', 'Programs Lead','Executive Director']);

function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function clean(mixed $v): string { return trim((string) $v); }

$survey_id = (int) ($_GET['id'] ?? 0);
if ($survey_id <= 0) die("Invalid survey.");

// Flash messages
$success = $_SESSION['builder_success'] ?? '';
$error   = $_SESSION['builder_error']   ?? '';
unset($_SESSION['builder_success'], $_SESSION['builder_error']);

// Load questions with their options
$questions = [];
$res = $conn->query("
    SELECT q.*,
           GROUP_CONCAT(o.option_label ORDER BY o.option_id ASC) AS options
    FROM survey_questions q
    LEFT JOIN survey_options o ON o.question_id = q.question_id
    WHERE q.survey_id = $survey_id
    GROUP BY q.question_id
    ORDER BY q.question_order ASC
");
while ($row = $res->fetch_assoc()) {
    $row['options'] = $row['options'] ? explode(',', $row['options']) : [];
    $questions[] = $row;
}

// Type display helpers
$type_icons = [
    'text'     => 'fa-font',
    'textarea' => 'fa-align-left',
    'number'   => 'fa-hashtag',
    'radio'    => 'fa-dot-circle',
    'checkbox' => 'fa-check-square',
    'select'   => 'fa-chevron-circle-down',
    'date'     => 'fa-calendar-alt',
];

$type_labels = [
    'text'     => 'Short Text',
    'textarea' => 'Paragraph',
    'number'   => 'Number',
    'radio'    => 'Multiple Choice',
    'checkbox' => 'Checkbox',
    'select'   => 'Dropdown',
    'date'     => 'Date',
];
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/survey.css">

<div class="surveys-wrap page-stack">

    <!-- -- Page Hero ---------------------------------------------------- -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-tools" aria-hidden="true"></i> Survey Builder</h1>
            <p>Add, arrange and manage questions for this survey.</p>
        </div>
        <div class="hero-actions">
            <a href="surveys" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Surveys
            </a>
        </div>
    </div>

    <!-- -- Flash Messages ----------------------------------------------- -->
    <?php if ($success): ?>
        <div class="alert alert-success" role="alert">
            <span><i class="fas fa-check-circle" style="margin-right:8px;"></i><?= h($success) ?></span>
            <button class="alert-close" onclick="this.closest('.alert').remove()" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <span><i class="fas fa-exclamation-circle" style="margin-right:8px;"></i><?= h($error) ?></span>
            <button class="alert-close" onclick="this.closest('.alert').remove()" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- -- Two-column builder layout ------------------------------------ -->
    <div class="builder-split">

        <!-- -- LEFT: Add Question Form -------------------------------- -->
        <div class="add-q-panel">
            <div class="panel">
                <div class="panel-head">
                    <h3><i class="fas fa-plus-circle" style="color:var(--brand-500);margin-right:8px;"></i>Add Question</h3>
                </div>
                <div class="panel-body">
                    <form method="POST" id="add-question-form" action="includes/process-survey-builder.php?id=<?= (int)$survey_id ?>">
                        <input type="hidden" name="action"    value="add_question">
                        <input type="hidden" name="survey_id" value="<?= $survey_id ?>">

                        <!-- Question text -->
                        <div class="form-group" style="margin-bottom:14px;">
                            <label class="form-label" for="question_text">Question</label>
                            <input
                                type="text"
                                id="question_text"
                                name="question_text"
                                class="form-control"
                                placeholder="Enter your question..."
                                required>
                        </div>

                        <!-- Question type -->
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="question_type">Answer Type</label>
                            <select id="question_type" name="question_type" class="form-control" required>
                                <option value="text">Short Text</option>
                                <option value="textarea">Paragraph</option>
                                <option value="number">Number</option>
                                <option value="radio">Multiple Choice (Radio)</option>
                                <option value="checkbox">Checkbox</option>
                                <option value="select">Dropdown</option>
                                <option value="date">Date</option>
                            </select>
                        </div>

                        <!-- Options builder (shown for radio / checkbox / select) -->
                        <div class="options-builder" id="options-builder">
                            <div class="options-builder-label">Answer Options</div>
                            <div id="options-container">
                                <div class="option-row">
                                    <input type="text" name="options[]" class="form-control" placeholder="Option text...">
                                    <button type="button" class="btn btn-remove-option remove-option" aria-label="Remove">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="button" id="add-option-btn" class="btn btn-gray btn-sm" style="align-self:flex-start;">
                                <i class="fas fa-plus"></i> Add Option
                            </button>
                        </div>

                        <!-- Required toggle -->
                        <div class="required-row">
                            <label class="toggle-switch" for="is_required">
                                <input type="checkbox" id="is_required" name="is_required" value="1">
                                <span class="toggle-track"></span>
                            </label>
                            <label class="required-label" for="is_required">Mark as required</label>
                        </div>

                        <!-- Submit -->
                        <div style="margin-top:18px;">
                            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                                <i class="fas fa-plus"></i> Add Question
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- -- RIGHT: Questions List ------------------------------------ -->
        <div class="panel questions-panel">
            <div class="panel-head">
                <h3>
                    <i class="fas fa-list-ul" style="color:var(--brand-500);margin-right:8px;"></i>
                    Survey Questions
                </h3>
                <span class="badge <?= empty($questions) ? 'badge-pending' : 'badge-available' ?>">
                    <?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($questions)): ?>
                <div class="panel-body">
                    <div class="empty-state">
                        <i class="fas fa-question-circle" aria-hidden="true"></i>
                        <h4>No questions yet</h4>
                        <p>Use the form on the left to add your first question.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="questions-list" id="questions-list">
                    <?php foreach ($questions as $i => $q): ?>
                        <?php
                            $qid   = (int) $q['question_id'];
                            $tkey  = $q['question_type'];
                            $icon  = $type_icons[$tkey]  ?? 'fa-circle';
                            $label = $type_labels[$tkey] ?? $tkey;
                        ?>
                        <div class="question-item" data-id="<?= $qid ?>">

                            <!-- drag handle -->
                            <div class="drag-handle" title="Drag to reorder">
                                <i class="fas fa-grip-vertical"></i>
                            </div>

                            <!-- order badge -->
                            <div class="order-badge"><?= $i + 1 ?></div>

                            <!-- body -->
                            <div class="question-item-body">
                                <div class="question-text"><?= h($q['question_text']) ?></div>

                                <div class="question-chips">
                                    <span class="chip-type">
                                        <i class="fas <?= $icon ?>"></i> <?= $label ?>
                                    </span>
                                    <?php if ($q['is_required']): ?>
                                        <span class="chip-required">
                                            <i class="fas fa-asterisk"></i> Required
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($q['options'])): ?>
                                    <div class="question-options-preview">
                                        <?php foreach ($q['options'] as $opt): ?>
                                            <span class="option-pill"><?= h(trim($opt)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- delete -->
                            <div class="question-item-actions">
                                <a
                                    href="includes/process-survey-builder.php?id=<?= (int)$survey_id ?>&delete=<?= (int)$qid ?>&csrf_token=<?= h(csrf_token()) ?>"
                                    class="btn btn-sm btn-red"
                                    title="Delete question"
                                    onclick="return confirm('Delete this question? This cannot be undone.')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="padding:0 20px 16px;">
                    <p class="note">
                        <i class="fas fa-grip-vertical" style="margin-right:5px;opacity:.5;"></i>
                        Drag questions to reorder - order is saved automatically.
                    </p>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.builder-split -->

</div><!-- /.surveys-wrap -->

<!-- -- Reorder saved toast --------------------------------------------- -->
<div class="reorder-toast" id="reorder-toast">
    <i class="fas fa-check-circle"></i> Order saved
</div>

<!-- -- Scripts --------------------------------------------------------- -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" integrity="sha384-eeLEhtwdMwD3X9y+8P3Cn7Idl/M+w8H4uZqkgD/2eJVkWIN1yKzEj6XegJ9dL3q0" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    'use strict';

    /* -- Options builder -- */
    const typeSelect      = document.getElementById('question_type');
    const optionsBuilder  = document.getElementById('options-builder');
    const optionsContainer = document.getElementById('options-container');
    const addOptionBtn    = document.getElementById('add-option-btn');
    const MULTI_TYPES     = ['radio', 'checkbox', 'select'];

    function refreshOptionsVisibility() {
        optionsBuilder.classList.toggle('visible', MULTI_TYPES.includes(typeSelect.value));
    }

    typeSelect.addEventListener('change', refreshOptionsVisibility);

    addOptionBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'option-row';
        row.innerHTML = `
            <input type="text" name="options[]" class="form-control" placeholder="Option text...">
            <button type="button" class="btn btn-remove-option remove-option" aria-label="Remove">
                <i class="fas fa-times"></i>
            </button>`;
        optionsContainer.appendChild(row);
        row.querySelector('input').focus();
    });

    optionsContainer.addEventListener('click', function (e) {
        const btn = e.target.closest('.remove-option');
        if (!btn) return;
        const rows = optionsContainer.querySelectorAll('.option-row');
        if (rows.length > 1) {
            btn.closest('.option-row').remove();
        }
    });

    /* -- Drag-to-reorder -- */
    const list = document.getElementById('questions-list');
    const toast = document.getElementById('reorder-toast');
    let toastTimer = null;

    function showToast(ok) {
        toast.querySelector('i').className = ok
            ? 'fas fa-check-circle'
            : 'fas fa-exclamation-circle';
        toast.firstChild.textContent = ' ';
        toast.lastChild  = undefined;
        toast.innerHTML  = ok
            ? '<i class="fas fa-check-circle"></i> Order saved'
            : '<i class="fas fa-exclamation-circle" style="color:var(--red-border)"></i> Failed to save order';
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
    }

    if (list) {
        Sortable.create(list, {
            handle: '.drag-handle',
            animation: 180,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: function () {
                /* update order badges */
                list.querySelectorAll('.question-item').forEach(function (item, idx) {
                    const badge = item.querySelector('.order-badge');
                    if (badge) badge.textContent = idx + 1;
                });

                const order = Array.from(
                    list.querySelectorAll('.question-item')
                ).map(function (item, idx) {
                    return { id: item.dataset.id, position: idx + 1 };
                });

                fetch('includes/survey-reorder.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ survey_id: <?= $survey_id ?>, order: order })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) { showToast(!!data.success); })
                .catch(function ()   { showToast(false); });
            }
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>