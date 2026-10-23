<?php
declare(strict_types=1);

ob_start();

$page_title = 'My Applications';
require_once 'includes/header.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in to view your applications.';
    header('Location: login');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
if (!function_exists('ma_h')) {
    function ma_h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function ma_timestamp(mixed $value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $ts = strtotime((string)$value);

    return $ts === false ? null : $ts;
}

function ma_date(mixed $value, string $format = 'd M Y', string $fallback = 'Not available'): string
{
    $ts = ma_timestamp($value);

    return $ts === null ? $fallback : date($format, $ts);
}

function ma_app_ref(int $id, string $prefix = 'APP'): string
{
    return $prefix . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function ma_status_meta(string $status): array
{
    return match ($status) {
        'Accepted', 'Approved' => [
            'label' => $status,
            'class' => 'accepted',
            'icon'  => 'fa-check-circle',
        ],
        'Rejected' => [
            'label' => 'Rejected',
            'class' => 'rejected',
            'icon'  => 'fa-times-circle',
        ],
        'Shortlisted' => [
            'label' => 'Shortlisted',
            'class' => 'shortlisted',
            'icon'  => 'fa-star',
        ],
        'Waitlisted' => [
            'label' => 'Waitlisted',
            'class' => 'waitlisted',
            'icon'  => 'fa-clock',
        ],
        'Under Review' => [
            'label' => 'Under Review',
            'class' => 'review',
            'icon'  => 'fa-search',
        ],
        'Submitted' => [
            'label' => 'Submitted',
            'class' => 'submitted',
            'icon'  => 'fa-paper-plane',
        ],
        'Pending' => [
            'label' => 'Pending',
            'class' => 'pending',
            'icon'  => 'fa-hourglass-half',
        ],
        'Withdrawn' => [
            'label' => 'Withdrawn',
            'class' => 'withdrawn',
            'icon'  => 'fa-undo',
        ],
        default => [
            'label' => $status !== '' ? $status : 'Pending',
            'class' => 'pending',
            'icon'  => 'fa-hourglass-half',
        ],
    };
}

function ma_filter_status(string $status): string
{
    return match ($status) {
        'Accepted', 'Approved' => 'accepted',
        'Shortlisted'          => 'shortlisted',
        'Rejected'             => 'rejected',
        'Waitlisted'           => 'waitlisted',
        'Submitted',
        'Under Review',
        'Pending'              => 'pending',
        'Withdrawn'            => 'withdrawn',
        default                => strtolower(trim($status)),
    };
}

function ma_is_draft_open(array $draft): bool
{
    $opportunityStatus = (string)($draft['opportunity_status'] ?? '');
    $deadline = ma_timestamp($draft['deadline'] ?? null);

    if (!in_array($opportunityStatus, ['Published', 'Active'], true)) {
        return false;
    }

    /*
     * An opportunity without a deadline is treated as open.
     */
    if ($deadline === null) {
        return true;
    }

    return $deadline >= strtotime('today');
}

/*
|--------------------------------------------------------------------------
| FETCH APPLICATIONS
|--------------------------------------------------------------------------
*/
$applications = [];

$sql = "
    SELECT
        a.*,
        o.opportunity_title,
        o.opportunity_type,
        o.deadline,
        o.status AS opportunity_status,
        COALESCE(r.review_count, 0) AS review_count,
        COALESCE(r.avg_score, 0) AS avg_score
    FROM applications a
    LEFT JOIN application_opportunities o
        ON a.opportunity_id = o.opportunity_id
    LEFT JOIN (
        SELECT
            application_id,
            COUNT(*) AS review_count,
            ROUND(AVG(overall_score), 1) AS avg_score
        FROM application_reviews
        GROUP BY application_id
    ) r
        ON a.application_id = r.application_id
    WHERE a.submitted_by = ?
    ORDER BY
        COALESCE(a.submitted_at, a.last_saved_at, a.updated_at, a.created_at) DESC,
        a.application_id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('my-applications prepare failed: ' . $conn->error);
    $_SESSION['error'] = 'Unable to load your applications at the moment.';
} else {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| PARTITION APPLICATIONS
|--------------------------------------------------------------------------
*/
$drafts = [];
$submitted = [];

foreach ($applications as $application) {
    $status = strtoupper(trim((string)($application['status'] ?? '')));

    if ($status === 'DRAFT') {
        $drafts[] = $application;
    } else {
        $submitted[] = $application;
    }
}

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/
$stats = [
    'total'       => count($submitted),
    'draft'       => count($drafts),
    'pending'     => 0,
    'shortlisted' => 0,
    'accepted'    => 0,
];

foreach ($submitted as $application) {
    $filterStatus = ma_filter_status((string)($application['status'] ?? ''));

    if ($filterStatus === 'pending') {
        $stats['pending']++;
    } elseif ($filterStatus === 'shortlisted') {
        $stats['shortlisted']++;
    } elseif ($filterStatus === 'accepted') {
        $stats['accepted']++;
    }
}
?>

<style>
/* my-applications.php: page-only layout (filter stat buttons, card grid). */

/* Stat cards double as filter buttons. */
.ma-stat {
    width: 100%;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.ma-stat:focus-visible {
    outline: var(--focus-outline);
    outline-offset: 2px;
}

.ma-stat.active {
    border-color: var(--brand-400);
    box-shadow: var(--focus-ring);
}

.ma-filter-head {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
}

.ma-section + .ma-section {
    margin-top: var(--space-6);
}

.ma-section-head {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin: 0 0 var(--space-3);
}

.ma-section-head h4 {
    margin: 0;
    color: var(--ink-700);
    font-size: var(--fs-md);
}

/* 3 cards per row on desktop */
.ma-app-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--space-4);
    align-items: stretch;
}

.ma-app-card {
    min-width: 0;
    height: 100%;
    display: flex;
    flex-direction: column;
    border-top: 4px solid var(--ink-100);
}

.ma-app-card.status-accepted { border-top-color: var(--green-fg); }
.ma-app-card.status-rejected { border-top-color: var(--red-fg); }
.ma-app-card.status-shortlisted { border-top-color: var(--brand-500); }
.ma-app-card.status-review,
.ma-app-card.status-pending { border-top-color: var(--blue-fg); }
.ma-app-card.status-waitlisted,
.ma-app-card.status-draft { border-top-color: var(--purple-fg); }

.ma-app-card > .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.ma-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 13px;
}

.ma-card-title-wrap {
    min-width: 0;
    flex: 1;
}

.ma-card-title {
    margin: 0;
    color: var(--ink-700);
    font-size: var(--fs-md);
    font-weight: 800;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.ma-card-ref {
    margin-top: 4px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .06em;
}

.ma-card-meta {
    display: grid;
    gap: var(--space-2);
    margin-bottom: 15px;
}

.ma-meta {
    min-width: 0;
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    color: var(--ink-300);
    font-size: var(--fs-sm);
    line-height: 1.45;
}

.ma-meta i {
    width: 14px;
    margin-top: 2px;
    color: var(--brand-500);
    text-align: center;
}

.ma-meta strong {
    color: var(--ink-500);
    overflow-wrap: anywhere;
}

.ma-score {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: auto;
    margin-bottom: 15px;
    padding: 11px 12px;
    border-radius: var(--radius-sm);
    background: var(--surface-hover);
}

.ma-score-icon {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    flex: 0 0 34px;
    border-radius: 50%;
    color: var(--brand-600);
    background: var(--brand-50);
}

.ma-score strong {
    display: block;
    color: var(--ink-700);
    font-size: var(--fs-sm);
}

.ma-score span {
    color: var(--ink-300);
    font-size: var(--fs-xs);
}

.ma-card-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    padding-top: 14px;
    border-top: 1px solid var(--ink-50);
}

.ma-card-actions .btn {
    flex: 1 1 auto;
}

.ma-btn-disabled {
    cursor: not-allowed;
    opacity: .7;
}

.ma-no-filter-results {
    display: none;
}

.ma-no-filter-results.show {
    display: flex;
}

.ma-hidden {
    display: none !important;
}

@media (max-width: 1180px) {
    .ma-app-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .ma-app-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 420px) {
    .ma-card-head {
        flex-direction: column;
    }
}
</style>

<?php
// Status class (from ma_status_meta) -> global badge modifier.
$ma_badge_class = [
    'accepted'    => 'badge-success',
    'rejected'    => 'badge-danger',
    'shortlisted' => 'badge-warning',
    'review'      => 'badge-info',
    'pending'     => 'badge-info',
    'submitted'   => 'badge-info',
    'waitlisted'  => 'badge-purple',
    'withdrawn'   => 'badge-secondary',
    'draft'       => 'badge-secondary',
];
?>

<div class="page-stack">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-briefcase" aria-hidden="true"></i> My Applications</h1>
            <p>Track submitted applications, continue saved drafts and filter applications by status.</p>
        </div>
        <div class="hero-actions">
            <a href="opportunities" class="btn btn-primary">
                <i class="fas fa-search" aria-hidden="true"></i>
                Browse Opportunities
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success" id="flashMessage">
            <?= ma_h($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger" id="flashMessage">
            <?= ma_h($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="stats-grid reports-stats" aria-label="Application filters">
        <button type="button" class="stat-card ma-stat active" data-filter="all">
            <span class="stat-icon bg-primary"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
            <span class="stat-info">
                <span class="stat-label">Total Submitted</span>
                <span class="stat-value"><?= (int)$stats['total'] ?></span>
            </span>
        </button>

        <button type="button" class="stat-card ma-stat pending" data-filter="pending">
            <span class="stat-icon bg-blue"><i class="fas fa-clock" aria-hidden="true"></i></span>
            <span class="stat-info">
                <span class="stat-label">Pending Review</span>
                <span class="stat-value"><?= (int)$stats['pending'] ?></span>
            </span>
        </button>

        <button type="button" class="stat-card ma-stat shortlisted" data-filter="shortlisted">
            <span class="stat-icon bg-amber"><i class="fas fa-star" aria-hidden="true"></i></span>
            <span class="stat-info">
                <span class="stat-label">Shortlisted</span>
                <span class="stat-value"><?= (int)$stats['shortlisted'] ?></span>
            </span>
        </button>

        <button type="button" class="stat-card ma-stat accepted" data-filter="accepted">
            <span class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></span>
            <span class="stat-info">
                <span class="stat-label">Accepted</span>
                <span class="stat-value"><?= (int)$stats['accepted'] ?></span>
            </span>
        </button>

        <button type="button" class="stat-card ma-stat drafts" data-filter="draft">
            <span class="stat-icon bg-purple"><i class="fas fa-edit" aria-hidden="true"></i></span>
            <span class="stat-info">
                <span class="stat-label">Saved Drafts</span>
                <span class="stat-value"><?= (int)$stats['draft'] ?></span>
            </span>
        </button>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="ma-filter-head">
                <h3><i class="fas fa-list" aria-hidden="true"></i> Applications</h3>
                <span class="badge badge-warning" id="applicationFilterLabel">Showing all submitted applications</span>
            </div>

            <button type="button" class="btn btn-secondary btn-sm" id="clearApplicationFilter">
                <i class="fas fa-undo" aria-hidden="true"></i>
                Clear Filter
            </button>
        </div>

        <div class="card-body">
        <?php if (empty($applications)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open" aria-hidden="true"></i>
                <h4>No Applications Yet</h4>
                <p>Browse available opportunities and submit your first application.</p>

                <a href="opportunities" class="btn btn-primary">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    Browse Opportunities
                </a>
            </div>
        <?php else: ?>

            <?php if (!empty($drafts)): ?>
                <section class="ma-section ma-application-section" data-section="draft">
                    <div class="ma-section-head">
                        <h4>Saved Drafts</h4>
                        <span class="badge badge-purple"><?= count($drafts) ?></span>
                    </div>

                    <div class="ma-app-grid">
                        <?php foreach ($drafts as $draft):
                            $draftId = (int)($draft['application_id'] ?? 0);
                            $ref = ma_app_ref($draftId, 'DRAFT');
                            $open = ma_is_draft_open($draft);

                            $savedAt = $draft['last_saved_at']
                                ?? $draft['updated_at']
                                ?? $draft['created_at']
                                ?? null;
                        ?>
                            <article
                                class="card ma-app-card status-draft ma-filter-card"
                                data-filter-status="draft"
                            >
                                <div class="card-body">
                                    <div class="ma-card-head">
                                        <div class="ma-card-title-wrap">
                                            <h3 class="ma-card-title">
                                                <?= ma_h($draft['opportunity_title'] ?? 'Opportunity') ?>
                                            </h3>
                                            <div class="ma-card-ref cell-muted"><?= ma_h($ref) ?></div>
                                        </div>

                                        <span class="badge badge-secondary">
                                            <i class="fas fa-edit" aria-hidden="true"></i>
                                            Draft
                                        </span>
                                    </div>

                                    <div class="ma-card-meta">
                                        <div class="ma-meta">
                                            <i class="fas fa-building" aria-hidden="true"></i>
                                            <strong>
                                                <?= ma_h(($draft['startup_name'] ?? '') !== '' ? $draft['startup_name'] : 'Unnamed venture') ?>
                                            </strong>
                                        </div>

                                        <div class="ma-meta">
                                            <i class="fas fa-save" aria-hidden="true"></i>
                                            <span>
                                                Last saved <?= ma_h(ma_date($savedAt, 'd M Y, h:i A')) ?>
                                            </span>
                                        </div>

                                        <div class="ma-meta">
                                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                            <span>
                                                Deadline:
                                                <?= ma_h(ma_date($draft['deadline'] ?? null, 'd M Y', 'No deadline')) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="ma-score">
                                        <span class="ma-score-icon">
                                            <i class="fas fa-file-alt" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <strong>Application not yet submitted</strong>
                                            <span>
                                                <?= $open ? 'Continue editing before submission.' : 'This opportunity is currently closed.' ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="ma-card-actions">
                                        <?php if ($open): ?>
                                            <a
                                                href="submit-application?opportunity_id=<?= (int)($draft['opportunity_id'] ?? 0) ?>&draft_id=<?= $draftId ?>"
                                                class="btn btn-soft btn-sm"
                                            >
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                                Continue
                                            </a>
                                        <?php else: ?>
                                            <span class="btn btn-secondary btn-sm ma-btn-disabled" aria-disabled="true">
                                                <i class="fas fa-lock" aria-hidden="true"></i>
                                                Closed
                                            </span>
                                        <?php endif; ?>

                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm js-delete-draft"
                                            data-id="<?= $draftId ?>"
                                        >
                                            <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                            Discard
                                        </button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($submitted)): ?>
                <section class="ma-section ma-application-section" data-section="submitted">
                    <div class="ma-section-head">
                        <h4>Submitted Applications</h4>
                        <span class="badge badge-warning"><?= count($submitted) ?></span>
                    </div>

                    <div class="ma-app-grid">
                        <?php foreach ($submitted as $app):
                            $applicationId = (int)($app['application_id'] ?? 0);
                            $status = trim((string)($app['status'] ?? 'Pending'));
                            $meta = ma_status_meta($status);
                            $filterStatus = ma_filter_status($status);
                            $ref = ma_app_ref($applicationId);

                            $submittedAt = $app['submitted_at']
                                ?? $app['last_saved_at']
                                ?? $app['created_at']
                                ?? null;

                            $reviewCount = (int)($app['review_count'] ?? 0);
                            $avgScore = (float)($app['avg_score'] ?? 0);
                        ?>
                            <article
                                class="card ma-app-card status-<?= ma_h($meta['class']) ?> ma-filter-card"
                                data-filter-status="<?= ma_h($filterStatus) ?>"
                            >
                                <div class="card-body">
                                    <div class="ma-card-head">
                                        <div class="ma-card-title-wrap">
                                            <h3 class="ma-card-title">
                                                <?= ma_h($app['opportunity_title'] ?? 'Opportunity') ?>
                                            </h3>
                                            <div class="ma-card-ref cell-muted"><?= ma_h($ref) ?></div>
                                        </div>

                                        <span class="badge <?= ma_h($ma_badge_class[$meta['class']] ?? 'badge-secondary') ?>">
                                            <i class="fas <?= ma_h($meta['icon']) ?>" aria-hidden="true"></i>
                                            <?= ma_h($meta['label']) ?>
                                        </span>
                                    </div>

                                    <div class="ma-card-meta">
                                        <div class="ma-meta">
                                            <i class="fas fa-building" aria-hidden="true"></i>
                                            <strong>
                                                <?= ma_h(($app['startup_name'] ?? '') !== '' ? $app['startup_name'] : 'Unnamed venture') ?>
                                            </strong>
                                        </div>

                                        <div class="ma-meta">
                                            <i class="fas fa-paper-plane" aria-hidden="true"></i>
                                            <span>
                                                Submitted <?= ma_h(ma_date($submittedAt)) ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($app['opportunity_type'])): ?>
                                            <div class="ma-meta">
                                                <i class="fas fa-tag" aria-hidden="true"></i>
                                                <span><?= ma_h($app['opportunity_type']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="ma-score">
                                        <span class="ma-score-icon">
                                            <i class="fas <?= $reviewCount > 0 ? 'fa-star-half-alt' : 'fa-hourglass-half' ?>" aria-hidden="true"></i>
                                        </span>

                                        <div>
                                            <?php if ($reviewCount > 0): ?>
                                                <strong><?= number_format($avgScore, 1) ?> average score</strong>
                                                <span>
                                                    <?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?>
                                                </span>
                                            <?php else: ?>
                                                <strong>Awaiting review</strong>
                                                <span>No reviewer score has been recorded yet.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="ma-card-actions">
                                        <a
                                            href="view-my-application?id=<?= $applicationId ?>"
                                            class="btn btn-primary btn-sm"
                                        >
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                            View
                                        </a>

                                        <?php if ($status === 'Submitted'): ?>
                                            <button
                                                type="button"
                                                class="btn btn-danger btn-sm js-withdraw-application"
                                                data-id="<?= $applicationId ?>"
                                            >
                                                <i class="fas fa-times-circle" aria-hidden="true"></i>
                                                Withdraw
                                            </button>
                                        <?php endif; ?>

                                        <a
                                            href="mailto:info@hivecolab.com?subject=<?= rawurlencode("Application {$ref} Inquiry") ?>"
                                            class="btn btn-secondary btn-sm"
                                        >
                                            <i class="fas fa-envelope" aria-hidden="true"></i>
                                            Support
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="empty-state ma-no-filter-results" id="noApplicationResults">
                <i class="fas fa-filter" aria-hidden="true"></i>
                <h4>No applications match this filter</h4>
                <p>Select another statistics card or clear the current filter.</p>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div><!-- /.page-stack -->

<?php
include 'includes/footer.php';
ob_end_flush();
?>

<script>
(function () {
    const statButtons = Array.from(document.querySelectorAll('.ma-stat[data-filter]'));
    const cards = Array.from(document.querySelectorAll('.ma-filter-card'));
    const sections = Array.from(document.querySelectorAll('.ma-application-section'));
    const label = document.getElementById('applicationFilterLabel');
    const clearButton = document.getElementById('clearApplicationFilter');
    const noResults = document.getElementById('noApplicationResults');

    const labels = {
        all: 'Showing all submitted applications',
        pending: 'Showing pending review applications',
        shortlisted: 'Showing shortlisted applications',
        accepted: 'Showing accepted applications',
        draft: 'Showing saved drafts'
    };

    function applyFilter(filter) {
        let visibleCount = 0;

        cards.forEach(function (card) {
            const status = card.dataset.filterStatus || '';

            let visible;

            if (filter === 'all') {
                visible = status !== 'draft';
            } else {
                visible = status === filter;
            }

            card.classList.toggle('ma-hidden', !visible);

            if (visible) {
                visibleCount++;
            }
        });

        sections.forEach(function (section) {
            const visibleCards = section.querySelectorAll('.ma-filter-card:not(.ma-hidden)');
            section.classList.toggle('ma-hidden', visibleCards.length === 0);
        });

        statButtons.forEach(function (button) {
            const active = button.dataset.filter === filter;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        if (label) {
            label.textContent = labels[filter] || 'Filtered applications';
        }

        if (noResults) {
            noResults.classList.toggle('show', visibleCount === 0);
        }
    }

    statButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            applyFilter(button.dataset.filter || 'all');
        });
    });

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            applyFilter('all');
        });
    }

    function postApplicationAction(action, id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'includes/my-application-actions.php';
        [['action', action], ['application_id', String(id)], ['csrf_token', <?= json_encode(csrf_token()) ?>]].forEach(function (pair) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = pair[0];
            input.value = pair[1];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    }

    document.querySelectorAll('.js-withdraw-application').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = Number(button.dataset.id || 0);

            if (!id) {
                return;
            }

            if (!window.confirm('Withdraw this application? It will be removed from review and moved back to your drafts, where you can resubmit it before the deadline or discard it.')) {
                return;
            }

            postApplicationAction('withdraw', id);
        });
    });

    document.querySelectorAll('.js-delete-draft').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = Number(button.dataset.id || 0);

            if (!id) {
                return;
            }

            if (!window.confirm('Permanently discard this draft? This action cannot be undone.')) {
                return;
            }

            postApplicationAction('delete_draft', id);
        });
    });

    const flash = document.getElementById('flashMessage');

    if (flash) {
        window.setTimeout(function () {
            flash.style.transition = 'opacity .3s ease, transform .3s ease';
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-6px)';

            window.setTimeout(function () {
                flash.remove();
            }, 320);
        }, 5000);
    }

    /*
     * Default page state: submitted applications.
     */
    applyFilter('all');
})();
</script>
