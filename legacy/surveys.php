<?php

declare(strict_types=1);

// Must be set before header.php, which renders the <title> and page heading.
$page_title = 'Surveys';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/process-surveys.php';

// Per-row Edit/Delete modals are collected here and printed after the
// table: a <div> inside <tbody> is invalid HTML.
$surveyRowModals = '';

/*
|--------------------------------------------------------------------------
| HTML escape helper
|--------------------------------------------------------------------------
*/

if (!function_exists('survey_h')) {
    function survey_h(mixed $value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/survey.css">

<div class="surveys-wrap page-stack">

    <!-- Page Hero -->
    <div class="page-hero">
        <div>
            <h1>
                <i class="fas fa-poll" aria-hidden="true"></i>
                Surveys
            </h1>

            <p>
                Build, manage and analyse your project and programme surveys.
            </p>
        </div>

        <div class="hero-actions">
            <button
                type="button"
                class="btn btn-primary"
                onclick="openModal('createSurveyModal')"
            >
                <i class="fas fa-plus"></i>
                Create Survey
            </button>
        </div>
    </div>

    <!-- Success Message -->
    <?php if (!empty($_SESSION['survey_success'])): ?>
        <div
            class="alert alert-success"
            role="alert"
        >
            <span>
                <i class="fas fa-check-circle"></i>

                <?= survey_h($_SESSION['survey_success']) ?>
            </span>

            <button
                type="button"
                class="alert-close"
                onclick="this.closest('.alert').remove()"
                aria-label="Dismiss message"
            >
                &times;
            </button>
        </div>

        <?php unset($_SESSION['survey_success']); ?>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if (!empty($_SESSION['survey_error'])): ?>
        <div
            class="alert alert-danger"
            role="alert"
        >
            <span>
                <i class="fas fa-exclamation-circle"></i>

                <?= survey_h($_SESSION['survey_error']) ?>
            </span>

            <button
                type="button"
                class="alert-close"
                onclick="this.closest('.alert').remove()"
                aria-label="Dismiss message"
            >
                &times;
            </button>
        </div>

        <?php unset($_SESSION['survey_error']); ?>
    <?php endif; ?>

    <?php if (empty($surveys)): ?>

        <!-- Empty State -->
        <div class="card">
        <div class="empty-state">
            <i class="fas fa-poll" aria-hidden="true"></i>

            <h4>No surveys yet</h4>

            <p>
                Create your first survey and link it to a project or
                programme to begin collecting responses.
            </p>

            <button
                type="button"
                class="btn btn-primary"
                onclick="openModal('createSurveyModal')"
            >
                <i class="fas fa-plus"></i>
                Create Survey
            </button>
        </div>
        </div>

    <?php else: ?>

        <!-- Surveys Table -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3>
                        <i class="fas fa-list" aria-hidden="true"></i>
                        All Surveys
                    </h3>

                    <p class="cell-muted">
                        Manage your available surveys and access their
                        questions and analytics.
                    </p>
                </div>

                <span class="badge survey-brand-badge">
                    <?= number_format(count($surveys)) ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table survey-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Survey Name</th>
                            <th>Survey Type</th>
                            <th>Project</th>
                            <th>Programme</th>
                            <th class="survey-actions-heading">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($surveys as $index => $survey): ?>
                            <?php
                            $surveyId = (int) (
                                $survey['survey_id'] ?? 0
                            );

                            $surveyName = trim(
                                (string) (
                                    $survey['survey_name'] ?? ''
                                )
                            );

                            $surveyType = trim(
                                (string) (
                                    $survey['survey_type'] ?? ''
                                )
                            );

                            $projectName = trim(
                                (string) (
                                    $survey['project_name'] ?? ''
                                )
                            );

                            $programmeName = trim(
                                (string) (
                                    $survey['program_name'] ?? ''
                                )
                            );

                            $selectedProjectId = (int) (
                                $survey['project_id'] ?? 0
                            );

                            $selectedProgrammeId = (int) (
                                $survey['program_id'] ?? 0
                            );
                            ?>

                            <tr>
                                <td class="survey-number">
                                    <?= number_format($index + 1) ?>
                                </td>

                                <td class="survey-name-cell">
                                    <span class="survey-name">
                                        <?= survey_h(
                                            $surveyName !== ''
                                                ? $surveyName
                                                : 'Untitled Survey'
                                        ) ?>
                                    </span>

                                    <span class="survey-id">
                                        ID: <?= $surveyId ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($surveyType !== ''): ?>
                                        <span class="badge survey-brand-badge">
                                            <i class="fas fa-tag"></i>

                                            <?= survey_h($surveyType) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="survey-empty-value">
                                            Not specified
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($projectName !== ''): ?>
                                        <span class="survey-link-value">
                                            <i class="fas fa-folder-open"></i>

                                            <?= survey_h($projectName) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="survey-empty-value">
                                            Not linked
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($programmeName !== ''): ?>
                                        <span class="survey-link-value">
                                            <i class="fas fa-layer-group"></i>

                                            <?= survey_h($programmeName) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="survey-empty-value">
                                            Not linked
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="survey-actions">
                                        <a
                                            href="survey-builder?id=<?= $surveyId ?>"
                                            class="btn btn-soft btn-sm"
                                            title="Build survey"
                                        >
                                            <i class="fas fa-pencil-alt"></i>
                                            Build
                                        </a>

                                        <a
                                            href="survey-analytics?id=<?= $surveyId ?>"
                                            class="btn btn-dark btn-sm"
                                            title="View analytics"
                                        >
                                            <i class="fas fa-chart-bar"></i>
                                            Analytics
                                        </a>

                                        <button
                                            type="button"
                                            class="btn btn-gray btn-sm"
                                            onclick="openModal(
                                                'editSurveyModal<?= $surveyId ?>'
                                            )"
                                            title="Edit survey"
                                        >
                                            <i class="fas fa-edit"></i>
                                            Edit
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-red btn-sm btn-icon"
                                            onclick="openModal(
                                                'deleteSurveyModal<?= $surveyId ?>'
                                            )"
                                            title="Delete survey"
                                            aria-label="Delete survey"
                                        >
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <?php ob_start(); ?>
                            <!-- Edit Survey Modal -->
                            <div
                                class="modal"
                                id="editSurveyModal<?= $surveyId ?>"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="editSurveyTitle<?= $surveyId ?>"
                            >
                                <div class="modal-card">
                                    <div class="modal-head">
                                        <h3 id="editSurveyTitle<?= $surveyId ?>">
                                            <i class="fas fa-edit"></i>
                                            Edit Survey
                                        </h3>

                                        <button
                                            type="button"
                                            class="modal-close"
                                            onclick="closeModal(
                                                'editSurveyModal<?= $surveyId ?>'
                                            )"
                                            aria-label="Close modal"
                                        >
                                            &times;
                                        </button>
                                    </div>

                                    <div class="modal-body">
                                        <form
                                            method="POST"
                                            action="includes/process-surveys.php"
                                        >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="edit_survey"
                                            >

                                            <input
                                                type="hidden"
                                                name="survey_id"
                                                value="<?= $surveyId ?>"
                                            >

                                            <div class="modal-section-label">
                                                Survey Details
                                            </div>

                                            <div class="form-grid">
                                                <div class="form-group full">
                                                    <label
                                                        class="form-label"
                                                        for="edit_name_<?= $surveyId ?>"
                                                    >
                                                        Survey Name
                                                    </label>

                                                    <input
                                                        type="text"
                                                        id="edit_name_<?= $surveyId ?>"
                                                        name="survey_name"
                                                        class="form-control"
                                                        value="<?= survey_h($surveyName) ?>"
                                                        required
                                                    >
                                                </div>

                                                <div class="form-group full">
                                                    <label
                                                        class="form-label"
                                                        for="edit_type_<?= $surveyId ?>"
                                                    >
                                                        Survey Type
                                                    </label>

                                                    <input
                                                        type="text"
                                                        id="edit_type_<?= $surveyId ?>"
                                                        name="survey_type"
                                                        class="form-control"
                                                        value="<?= survey_h($surveyType) ?>"
                                                        placeholder="For example: Baseline, Midterm or Endline"
                                                    >
                                                </div>
                                            </div>

                                            <div class="modal-section-label">
                                                Survey Linkage
                                            </div>

                                            <div class="form-grid">
                                                <div class="form-group">
                                                    <label
                                                        class="form-label"
                                                        for="edit_project_<?= $surveyId ?>"
                                                    >
                                                        Project
                                                    </label>

                                                    <select
                                                        id="edit_project_<?= $surveyId ?>"
                                                        name="project_id"
                                                        class="form-control"
                                                    >
                                                        <option value="">
                                                            None
                                                        </option>

                                                        <?php foreach ($projects as $project): ?>
                                                            <?php
                                                            $projectId = (int) (
                                                                $project['project_id']
                                                                ?? 0
                                                            );
                                                            ?>

                                                            <option
                                                                value="<?= $projectId ?>"
                                                                <?= $projectId === $selectedProjectId
                                                                    ? 'selected'
                                                                    : '' ?>
                                                            >
                                                                <?= survey_h(
                                                                    $project['project_name']
                                                                    ?? ''
                                                                ) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="form-group">
                                                    <label
                                                        class="form-label"
                                                        for="edit_programme_<?= $surveyId ?>"
                                                    >
                                                        Programme
                                                    </label>

                                                    <select
                                                        id="edit_programme_<?= $surveyId ?>"
                                                        name="program_id"
                                                        class="form-control"
                                                    >
                                                        <option value="">
                                                            None
                                                        </option>

                                                        <?php foreach ($programs as $program): ?>
                                                            <?php
                                                            $programId = (int) (
                                                                $program['id']
                                                                ?? 0
                                                            );
                                                            ?>

                                                            <option
                                                                value="<?= $programId ?>"
                                                                <?= $programId === $selectedProgrammeId
                                                                    ? 'selected'
                                                                    : '' ?>
                                                            >
                                                                <?= survey_h(
                                                                    $program['program_name']
                                                                    ?? ''
                                                                ) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="modal-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-gray"
                                                    onclick="closeModal(
                                                        'editSurveyModal<?= $surveyId ?>'
                                                    )"
                                                >
                                                    Cancel
                                                </button>

                                                <button
                                                    type="submit"
                                                    class="btn btn-primary"
                                                >
                                                    <i class="fas fa-save"></i>
                                                    Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Delete Survey Modal -->
                            <div
                                class="modal"
                                id="deleteSurveyModal<?= $surveyId ?>"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="deleteSurveyTitle<?= $surveyId ?>"
                            >
                                <div class="modal-card modal-sm">
                                    <div class="modal-head">
                                        <h3 id="deleteSurveyTitle<?= $surveyId ?>">
                                            <i class="fas fa-trash"></i>
                                            Delete Survey
                                        </h3>

                                        <button
                                            type="button"
                                            class="modal-close"
                                            onclick="closeModal(
                                                'deleteSurveyModal<?= $surveyId ?>'
                                            )"
                                            aria-label="Close modal"
                                        >
                                            &times;
                                        </button>
                                    </div>

                                    <div class="modal-body">
                                        <form
                                            method="POST"
                                            action="includes/process-surveys.php"
                                        >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete_survey"
                                            >

                                            <input
                                                type="hidden"
                                                name="survey_id"
                                                value="<?= $surveyId ?>"
                                            >

                                            <div class="danger-zone">
                                                <div class="danger-zone-icon">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </div>

                                                <p>
                                                    You are about to permanently
                                                    delete

                                                    <strong>
                                                        “<?= survey_h($surveyName) ?>”
                                                    </strong>.

                                                    All related questions and
                                                    responses will also be
                                                    removed. This action cannot
                                                    be undone.
                                                </p>
                                            </div>

                                            <div class="modal-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-gray"
                                                    onclick="closeModal(
                                                        'deleteSurveyModal<?= $surveyId ?>'
                                                    )"
                                                >
                                                    Cancel
                                                </button>

                                                <button
                                                    type="submit"
                                                    class="btn btn-red"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                    Yes, Delete
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php $surveyRowModals .= (string) ob_get_clean(); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Edit / Delete Survey Modals (one pair per survey) -->
<?= $surveyRowModals ?>

<!-- Create Survey Modal -->
<?php include __DIR__ . '/partials/create-survey-modal.php'; ?>
<script src="js/survey-modal.js"></script>
<!-- openModal()/closeModal(), backdrop click and Escape come from main.js (footer). -->

<?php include __DIR__ . '/includes/footer.php'; ?>