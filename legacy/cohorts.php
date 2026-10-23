<?php

declare(strict_types=1);

$page_title = 'Cohorts';

require_once __DIR__ . '/includes/header.php';

check_role([
    'Administrator',
    'Programs Lead',
    'MEAL Lead',
    'Project Officer',
    'Program Director',
    'Program Manager',
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bind_params_dynamic')) {
    function bind_params_dynamic(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '' || $params === []) {
            return;
        }

        $references = [];

        foreach ($params as $index => &$value) {
            $references[$index] = &$value;
        }

        $stmt->bind_param($types, ...$references);
    }
}

if (!function_exists('format_date_safe')) {
    function format_date_safe(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return 'Not set';
        }

        $timestamp = strtotime($date);

        return $timestamp === false
            ? 'Not set'
            : date('d M Y', $timestamp);
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    'active',
    'draft',
    'inactive',
];

$allowedApplicationStatuses = [
    'open',
    'closed',
    'coming_soon',
];

$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterApplicationStatus = trim(
    (string) ($_GET['application_status'] ?? '')
);
$search = trim((string) ($_GET['search'] ?? ''));

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

if (!in_array(
    $filterApplicationStatus,
    $allowedApplicationStatuses,
    true
)) {
    $filterApplicationStatus = '';
}

/*
|--------------------------------------------------------------------------
| Overall statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'active' => 0,
    'draft' => 0,
    'inactive' => 0,
    'applications_open' => 0,
    'applications' => 0,
];

$statsSql = "
    SELECT
        COUNT(c.id) AS total,
        COALESCE(SUM(c.status = 'active'), 0) AS active,
        COALESCE(SUM(c.status = 'draft'), 0) AS draft,
        COALESCE(SUM(c.status = 'inactive'), 0) AS inactive,
        COALESCE(SUM(c.application_status = 'open'), 0) AS applications_open,
        (
            SELECT COUNT(a.application_id)
            FROM applications AS a
            WHERE a.cohort_id IS NOT NULL
        ) AS applications
    FROM cohorts AS c
";

$statsResult = $conn->query($statsSql);

if ($statsResult instanceof mysqli_result) {
    $statsRow = $statsResult->fetch_assoc();

    if (is_array($statsRow)) {
        foreach (array_keys($stats) as $key) {
            $stats[$key] = (int) ($statsRow[$key] ?? 0);
        }
    }

    $statsResult->free();
} else {
    error_log('Cohort statistics query failed: ' . $conn->error);
}



$sql = "
    SELECT
        c.id,
        c.name,
        c.slug,
        c.tagline,
        c.description,
        c.start_date,
        c.end_date,
        c.application_status,
        c.application_link,
        c.status,
        c.sort_order,
        c.created_at,
        (
            SELECT COUNT(a.application_id)
            FROM applications AS a
            WHERE a.cohort_id = c.id
        ) AS application_count
    FROM cohorts AS c
";

$where = [];
$types = '';
$params = [];

if ($filterStatus !== '') {
    $where[] = 'c.status = ?';
    $types .= 's';
    $params[] = $filterStatus;
}

if ($filterApplicationStatus !== '') {
    $where[] = 'c.application_status = ?';
    $types .= 's';
    $params[] = $filterApplicationStatus;
}

if ($search !== '') {
    $like = '%' . $search . '%';

    $where[] = "
        (
            c.name LIKE ?
            OR c.slug LIKE ?
            OR c.tagline LIKE ?
            OR c.description LIKE ?
        )
    ";

    $types .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= "
    ORDER BY
        c.sort_order ASC,
        c.created_at DESC,
        c.id DESC
";

$cohorts = [];
$queryError = '';

$stmt = $conn->prepare($sql);

if (!$stmt) {
    $queryError = 'The cohort records could not be loaded.';
    error_log('Cohorts query preparation failed: ' . $conn->error);
} else {
    bind_params_dynamic($stmt, $types, $params);

    if (!$stmt->execute()) {
        $queryError = 'The cohort records could not be loaded.';
        error_log('Cohorts query execution failed: ' . $stmt->error);
    } else {
        /*
         * Do not use mysqli_stmt::get_result() here. Some shared-hosting
         * servers do not have mysqlnd enabled, causing get_result() to fail
         * even when the SQL statement executed successfully.
         */
        $stmt->bind_result(
            $rowId,
            $rowName,
            $rowSlug,
            $rowTagline,
            $rowDescription,
            $rowStartDate,
            $rowEndDate,
            $rowApplicationStatus,
            $rowApplicationLink,
            $rowStatus,
            $rowSortOrder,
            $rowCreatedAt,
            $rowApplicationCount
        );

        while ($stmt->fetch()) {
            $cohorts[] = [
                'id' => $rowId,
                'name' => $rowName,
                'slug' => $rowSlug,
                'tagline' => $rowTagline,
                'description' => $rowDescription,
                'start_date' => $rowStartDate,
                'end_date' => $rowEndDate,
                'application_status' => $rowApplicationStatus,
                'application_link' => $rowApplicationLink,
                'status' => $rowStatus,
                'sort_order' => $rowSortOrder,
                'created_at' => $rowCreatedAt,
                'application_count' => $rowApplicationCount,
            ];
        }
    }

    $stmt->close();
}

$statusClasses = [
    'active' => 'badge badge-success',
    'draft' => 'badge badge-secondary',
    'inactive' => 'badge badge-danger',
];

$applicationStatusClasses = [
    'open' => 'badge badge-success',
    'closed' => 'badge badge-danger',
    'coming_soon' => 'badge badge-warning',
];

$autoOpenEdit = null;

if (isset($_GET['edit'])) {
    $editId = max(0, (int) $_GET['edit']);

    foreach ($cohorts as $cohort) {
        if ((int) ($cohort['id'] ?? 0) === $editId) {
            $autoOpenEdit = $cohort;
            break;
        }
    }
}
?>

<link rel="stylesheet" href="/css/reports.css">

<div class="reports-wrap page-stack">

    <div class="page-hero">
        <div>
            <h1>
                <i class="fas fa-layer-group" aria-hidden="true"></i>
                Cohorts
            </h1>

            <p>
                Manage programme cohorts, application periods,
                statuses and display order.
            </p>
        </div>

        <div class="hero-actions">
            <button
                type="button"
                class="btn btn-primary"
                id="cohortAddButton"
            >
                <i class="fas fa-plus" aria-hidden="true"></i>
                Add Cohort
            </button>
        </div>
    </div>

    <?php if (function_exists('show_flash')): ?>
        <?php show_flash('cohorts'); ?>
    <?php endif; ?>

    <?php if ($queryError !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= h($queryError) ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid reports-stats">

        <article class="stat-card">
            <div class="stat-icon bg-blue">
                <i class="fas fa-layer-group" aria-hidden="true"></i>
            </div>

            <div class="stat-info">
                <span class="stat-label">Total Cohorts</span>
                <strong class="stat-value">
                    <?= number_format($stats['total']) ?>
                </strong>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon bg-green">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
            </div>

            <div class="stat-info">
                <span class="stat-label">Active</span>
                <strong class="stat-value">
                    <?= number_format($stats['active']) ?>
                </strong>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon bg-slate">
                <i class="fas fa-pen" aria-hidden="true"></i>
            </div>

            <div class="stat-info">
                <span class="stat-label">Draft</span>
                <strong class="stat-value">
                    <?= number_format($stats['draft']) ?>
                </strong>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon bg-amber">
                <i class="fas fa-door-open" aria-hidden="true"></i>
            </div>

            <div class="stat-info">
                <span class="stat-label">Applications Open</span>
                <strong class="stat-value">
                    <?= number_format($stats['applications_open']) ?>
                </strong>
            </div>
        </article>

    </section>

    <form method="get" action="cohorts" class="controls-bar filters-bar">

        <div class="form-group grow filters-grow">
            <label for="cohortSearch">Search</label>

            <input
                type="search"
                name="search"
                id="cohortSearch"
                class="form-control"
                value="<?= h($search) ?>"
                placeholder="Name, slug, tagline or description"
            >
        </div>

        <div class="form-group">
            <label for="cohortStatusFilter">Status</label>

            <select
                name="status"
                id="cohortStatusFilter"
                class="form-control"
            >
                <option value="">All statuses</option>

                <?php foreach ($allowedStatuses as $status): ?>
                    <option
                        value="<?= h($status) ?>"
                        <?= $filterStatus === $status ? 'selected' : '' ?>
                    >
                        <?= h(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="cohortApplicationFilter">
                Application Status
            </label>

            <select
                name="application_status"
                id="cohortApplicationFilter"
                class="form-control"
            >
                <option value="">All application statuses</option>

                <?php foreach (
                    $allowedApplicationStatuses as $applicationStatus
                ): ?>
                    <option
                        value="<?= h($applicationStatus) ?>"
                        <?= $filterApplicationStatus === $applicationStatus
                            ? 'selected'
                            : '' ?>
                    >
                        <?= h(ucwords(str_replace(
                            '_',
                            ' ',
                            $applicationStatus
                        ))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ctrl-actions">
            <button type="submit" class="btn btn-dark">
                <i class="fas fa-filter" aria-hidden="true"></i>
                Filter
            </button>

            <a href="cohorts" class="btn btn-gray">
                <i class="fas fa-times" aria-hidden="true"></i>
                Clear
            </a>
        </div>

    </form>

    <section class="panel">

        <header class="panel-head">
            <h3>
                <i class="fas fa-layer-group" aria-hidden="true"></i>
                Cohort Records
            </h3>

            <span class="badge badge-secondary">
                <?= number_format(count($cohorts)) ?>
                <?= count($cohorts) === 1 ? 'record' : 'records' ?>
            </span>
        </header>

        <div class="table-wrap">

            <table class="table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Cohort</th>
                        <th>Dates</th>
                        <th>Applications</th>
                        <th>Application Status</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($cohorts === []): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i
                                        class="fas fa-layer-group"
                                        aria-hidden="true"
                                    ></i>

                                    <span>No cohorts found</span>

                                    <p>
                                        Adjust the filters or add a new cohort.
                                    </p>

                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        id="cohortEmptyAddButton"
                                    >
                                        <i
                                            class="fas fa-plus"
                                            aria-hidden="true"
                                        ></i>
                                        Add Cohort
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($cohorts as $cohort): ?>
                            <?php
                            $status = (string) (
                                $cohort['status'] ?? 'draft'
                            );

                            $applicationStatus = (string) (
                                $cohort['application_status'] ?? 'closed'
                            );

                            $applicationCount = (int) (
                                $cohort['application_count'] ?? 0
                            );

                            $encodedCohort = json_encode(
                                $cohort,
                                JSON_HEX_TAG
                                | JSON_HEX_APOS
                                | JSON_HEX_AMP
                                | JSON_HEX_QUOT
                            );
                            ?>

                            <tr>
                                <td>
                                    <?= (int) ($cohort['sort_order'] ?? 0) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= h($cohort['name'] ?? '') ?>
                                    </strong>

                                    <?php if (!empty($cohort['slug'])): ?>
                                        <div class="note">
                                            /<?= h($cohort['slug']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($cohort['tagline'])): ?>
                                        <div class="note">
                                            <?= h(mb_strimwidth(
                                                (string) $cohort['tagline'],
                                                0,
                                                90,
                                                '…'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= h(format_date_safe(
                                        $cohort['start_date'] ?? null
                                    )) ?>

                                    <div class="note">
                                        to
                                        <?= h(format_date_safe(
                                            $cohort['end_date'] ?? null
                                        )) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge badge-info">
                                        <?= number_format($applicationCount) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="<?= h(
                                        $applicationStatusClasses[
                                            $applicationStatus
                                        ] ?? 'badge badge-secondary'
                                    ) ?>">
                                        <?= h(ucwords(str_replace(
                                            '_',
                                            ' ',
                                            $applicationStatus
                                        ))) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="<?= h(
                                        $statusClasses[$status]
                                        ?? 'badge badge-secondary'
                                    ) ?>">
                                        <?= h(ucfirst($status)) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="actions">

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-gray cohort-edit-button"
                                            data-cohort="<?= h(
                                                $encodedCohort ?: '{}'
                                            ) ?>"
                                            title="Edit cohort"
                                        >
                                            <i
                                                class="fas fa-edit"
                                                aria-hidden="true"
                                            ></i>
                                        </button>

                                        <form
                                            method="post"
                                            action="/includes/process-cohorts.php"
                                            class="cohort-delete-form"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) (
                                                    $cohort['id'] ?? 0
                                                ) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-danger"
                                                title="<?= $applicationCount > 0
                                                    ? 'Move applications before deleting'
                                                    : 'Delete cohort' ?>"
                                                <?= $applicationCount > 0
                                                    ? 'disabled'
                                                    : '' ?>
                                            >
                                                <i
                                                    class="fas fa-trash"
                                                    aria-hidden="true"
                                                ></i>
                                            </button>
                                        </form>

                                    </div>

                                    <?php if ($applicationCount > 0): ?>
                                        <div class="note">
                                            Move applications before deleting.
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>
            </table>

        </div>
    </section>

</div>

<div
    class="modal"
    id="cohortModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cohortModalTitle"
    aria-hidden="true"
>
    <div class="modal-content">

        <header class="modal-header">
            <h3 id="cohortModalTitle">Add Cohort</h3>

            <button
                type="button"
                class="modal-close"
                id="cohortModalClose"
                aria-label="Close cohort modal"
            >
                &times;
            </button>
        </header>

        <form
            method="post"
            action="/includes/process-cohorts.php"
            id="cohortForm"
        >
            <div class="modal-body">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="cohortId">

                <div class="form-grid">

                    <div class="form-group full">
                        <label for="cohortName" class="required">
                            Cohort Name
                        </label>

                        <input
                            type="text"
                            name="name"
                            id="cohortName"
                            class="form-control"
                            maxlength="250"
                            required
                            autocomplete="off"
                        >
                    </div>

                    <div class="form-group">
                        <label for="cohortSlug">Slug</label>

                        <input
                            type="text"
                            name="slug"
                            id="cohortSlug"
                            class="form-control"
                            maxlength="250"
                            placeholder="Generated automatically"
                            pattern="[a-z0-9-]+"
                        >
                    </div>

                    <div class="form-group">
                        <label for="cohortSortOrder">Sort Order</label>

                        <input
                            type="number"
                            name="sort_order"
                            id="cohortSortOrder"
                            class="form-control"
                            value="0"
                            min="0"
                            step="1"
                        >
                    </div>

                    <div class="form-group full">
                        <label for="cohortTagline">Tagline</label>

                        <input
                            type="text"
                            name="tagline"
                            id="cohortTagline"
                            class="form-control"
                            maxlength="600"
                        >
                    </div>

                    <div class="form-group full">
                        <label for="cohortDescription">Description</label>

                        <textarea
                            name="description"
                            id="cohortDescription"
                            class="form-control"
                            rows="5"
                        ></textarea>
                    </div>

                    <div class="form-group">
                        <label for="cohortStartDate">Start Date</label>

                        <input
                            type="date"
                            name="start_date"
                            id="cohortStartDate"
                            class="form-control"
                        >
                    </div>

                    <div class="form-group">
                        <label for="cohortEndDate">End Date</label>

                        <input
                            type="date"
                            name="end_date"
                            id="cohortEndDate"
                            class="form-control"
                        >
                    </div>

                    <div class="form-group">
                        <label for="cohortApplicationStatus">
                            Application Status
                        </label>

                        <select
                            name="application_status"
                            id="cohortApplicationStatus"
                            class="form-control"
                        >
                            <option value="closed">Closed</option>
                            <option value="open">Open</option>
                            <option value="coming_soon">
                                Coming Soon
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cohortStatus">Cohort Status</label>

                        <select
                            name="status"
                            id="cohortStatus"
                            class="form-control"
                        >
                            <option value="active">Active</option>
                            <option value="draft">Draft</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label for="cohortApplicationLink">
                            Application Link
                        </label>

                        <input
                            type="url"
                            name="application_link"
                            id="cohortApplicationLink"
                            class="form-control"
                            maxlength="600"
                            placeholder="https://example.com/apply"
                        >

                        <span class="note">
                            Leave empty when applications are handled internally.
                        </span>
                    </div>

                </div>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="btn btn-gray"
                        id="cohortModalCancel"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                        id="cohortSubmitButton"
                    >
                        <i class="fas fa-save" aria-hidden="true"></i>

                        <span id="cohortSubmitText">
                            Save Cohort
                        </span>
                    </button>
                </div>

            </div>
        </form>

    </div>
</div>

<script>
(function () {
    'use strict';

    const modal = document.getElementById('cohortModal');
    const form = document.getElementById('cohortForm');

    if (!modal || !form) {
        return;
    }

    const modalTitle =
        document.getElementById('cohortModalTitle');

    const submitText =
        document.getElementById('cohortSubmitText');

    const submitButton =
        document.getElementById('cohortSubmitButton');

    const addButton =
        document.getElementById('cohortAddButton');

    const emptyAddButton =
        document.getElementById('cohortEmptyAddButton');

    const closeButton =
        document.getElementById('cohortModalClose');

    const cancelButton =
        document.getElementById('cohortModalCancel');

    const fields = {
        id: document.getElementById('cohortId'),
        name: document.getElementById('cohortName'),
        slug: document.getElementById('cohortSlug'),
        tagline: document.getElementById('cohortTagline'),
        description:
            document.getElementById('cohortDescription'),
        startDate:
            document.getElementById('cohortStartDate'),
        endDate:
            document.getElementById('cohortEndDate'),
        applicationStatus:
            document.getElementById('cohortApplicationStatus'),
        applicationLink:
            document.getElementById('cohortApplicationLink'),
        status:
            document.getElementById('cohortStatus'),
        sortOrder:
            document.getElementById('cohortSortOrder')
    };

    let previousFocus = null;

    function setValue(element, value) {
        if (element) {
            element.value =
                value == null ? '' : String(value);
        }
    }

    function makeSlug(value) {
        return value
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function resetForm() {
        form.reset();

        setValue(fields.id, '');
        setValue(fields.slug, '');
        setValue(fields.sortOrder, '0');
        setValue(fields.applicationStatus, 'closed');
        setValue(fields.status, 'active');

        fields.slug.dataset.manuallyEdited = 'false';

        modalTitle.textContent = 'Add Cohort';
        submitText.textContent = 'Save Cohort';
        submitButton.disabled = false;
    }

    function openModal(cohort) {
        previousFocus = document.activeElement;

        resetForm();

        if (cohort && typeof cohort === 'object') {
            modalTitle.textContent = 'Edit Cohort';
            submitText.textContent = 'Update Cohort';

            setValue(fields.id, cohort.id);
            setValue(fields.name, cohort.name);
            setValue(fields.slug, cohort.slug);
            setValue(fields.tagline, cohort.tagline);
            setValue(fields.description, cohort.description);
            setValue(fields.startDate, cohort.start_date);
            setValue(fields.endDate, cohort.end_date);

            setValue(
                fields.applicationStatus,
                cohort.application_status || 'closed'
            );

            setValue(
                fields.applicationLink,
                cohort.application_link
            );

            setValue(
                fields.status,
                cohort.status || 'active'
            );

            setValue(
                fields.sortOrder,
                cohort.sort_order || 0
            );

            fields.slug.dataset.manuallyEdited = 'true';
        }

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        window.setTimeout(function () {
            fields.name?.focus();
        }, 50);
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        if (
            previousFocus
            && typeof previousFocus.focus === 'function'
        ) {
            previousFocus.focus();
        }
    }

    function parseCohort(button) {
        try {
            return JSON.parse(
                button.dataset.cohort || '{}'
            );
        } catch (error) {
            console.error(
                'Unable to parse cohort data.',
                error
            );

            return null;
        }
    }

    addButton?.addEventListener(
        'click',
        function () {
            openModal(null);
        }
    );

    emptyAddButton?.addEventListener(
        'click',
        function () {
            openModal(null);
        }
    );

    closeButton?.addEventListener(
        'click',
        closeModal
    );

    cancelButton?.addEventListener(
        'click',
        closeModal
    );

    document.querySelectorAll(
        '.cohort-edit-button'
    ).forEach(function (button) {
        button.addEventListener(
            'click',
            function () {
                const cohort = parseCohort(button);

                if (cohort) {
                    openModal(cohort);
                }
            }
        );
    });

    document.querySelectorAll(
        '.cohort-delete-form'
    ).forEach(function (deleteForm) {
        deleteForm.addEventListener(
            'submit',
            function (event) {
                const confirmed = window.confirm(
                    'Delete this cohort? This action cannot be undone.'
                );

                if (!confirmed) {
                    event.preventDefault();
                }
            }
        );
    });

    modal.addEventListener(
        'click',
        function (event) {
            if (event.target === modal) {
                closeModal();
            }
        }
    );

    document.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key === 'Escape'
                && modal.classList.contains('show')
            ) {
                closeModal();
            }
        }
    );

    fields.name?.addEventListener(
        'input',
        function () {
            if (
                fields.id.value !== ''
                || fields.slug.dataset.manuallyEdited === 'true'
            ) {
                return;
            }

            fields.slug.value =
                makeSlug(fields.name.value);
        }
    );

    fields.slug?.addEventListener(
        'input',
        function () {
            fields.slug.value =
                makeSlug(fields.slug.value);

            fields.slug.dataset.manuallyEdited =
                fields.slug.value === ''
                    ? 'false'
                    : 'true';
        }
    );

    form.addEventListener(
        'submit',
        function (event) {
            fields.endDate.setCustomValidity('');

            if (
                fields.startDate.value
                && fields.endDate.value
                && fields.endDate.value
                    < fields.startDate.value
            ) {
                event.preventDefault();

                fields.endDate.setCustomValidity(
                    'End date cannot be earlier than the start date.'
                );

                fields.endDate.reportValidity();
                return;
            }

            submitButton.disabled = true;

            submitText.textContent =
                fields.id.value
                    ? 'Updating…'
                    : 'Saving…';
        }
    );

    <?php if ($autoOpenEdit !== null): ?>
    openModal(
        <?= json_encode(
            $autoOpenEdit,
            JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
        ) ?>
    );
    <?php endif; ?>
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
