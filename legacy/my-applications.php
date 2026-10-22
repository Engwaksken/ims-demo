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
:root {
    --ma-ink: #0f172a;
    --ma-ink-2: #334155;
    --ma-muted: #64748b;
    --ma-light: #94a3b8;
    --ma-rule: #e2e8f0;
    --ma-bg: #f8fafc;
    --ma-white: #ffffff;
    --ma-orange: #f97316;
    --ma-orange-soft: #fff7ed;
    --ma-green: #16a34a;
    --ma-green-soft: #f0fdf4;
    --ma-blue: #2563eb;
    --ma-blue-soft: #eff6ff;
    --ma-red: #dc2626;
    --ma-red-soft: #fef2f2;
    --ma-purple: #7c3aed;
    --ma-purple-soft: #f5f3ff;
    --ma-radius: 14px;
    --ma-shadow: 0 3px 12px rgba(15, 23, 42, .07);
    --ma-shadow-hover: 0 14px 30px rgba(15, 23, 42, .12);
}

.ma-page,
.ma-page * {
    box-sizing: border-box;
}

.ma-page {
    width: 100%;
    padding: 0 0 60px;
    color: var(--ma-ink);
}

/* Full-width page like the other IMS pages */
.ma-shell {
    width: 100%;
    max-width: none;
    margin: 0;
    padding: 0 22px;
}

.ma-hero {
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    padding: 30px 32px;
    margin-bottom: 22px;
    color: #fff;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 65%, #431407 100%);
    border-radius: 16px;
}

.ma-hero::after {
    content: '';
    position: absolute;
    width: 260px;
    height: 260px;
    right: -80px;
    top: -110px;
    border-radius: 50%;
    border: 45px solid rgba(249, 115, 22, .12);
}

.ma-hero-copy,
.ma-hero-actions {
    position: relative;
    z-index: 1;
}

.ma-eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 7px;
    color: #fdba74;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .11em;
    text-transform: uppercase;
}

.ma-hero h1 {
    margin: 0 0 6px;
    color: #fff;
    font-size: clamp(26px, 3vw, 36px);
    line-height: 1.2;
}

.ma-hero p {
    max-width: 640px;
    margin: 0;
    color: rgba(255,255,255,.68);
    font-size: 14px;
    line-height: 1.6;
}

.ma-hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.ma-btn {
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 9px 15px;
    border: 1px solid transparent;
    border-radius: 9px;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: .2s ease;
}

.ma-btn:hover {
    transform: translateY(-1px);
}

.ma-btn-primary {
    background: var(--ma-orange);
    color: #fff;
}

.ma-btn-dark {
    background: var(--ma-ink);
    color: #fff;
}

.ma-btn-light {
    background: #fff;
    color: var(--ma-ink-2);
    border-color: var(--ma-rule);
}

.ma-btn-soft {
    background: var(--ma-orange-soft);
    color: var(--ma-orange);
    border-color: #fed7aa;
}

.ma-btn-danger {
    background: var(--ma-red-soft);
    color: var(--ma-red);
    border-color: #fecaca;
}

/* Statistics */
.ma-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}

.ma-stat {
    width: 100%;
    min-height: 94px;
    appearance: none;
    border: 1px solid var(--ma-rule);
    border-left: 4px solid var(--ma-orange);
    border-radius: 12px;
    background: var(--ma-white);
    padding: 15px 16px;
    text-align: left;
    box-shadow: var(--ma-shadow);
    cursor: pointer;
    transition: .2s ease;
}

.ma-stat:hover,
.ma-stat:focus-visible {
    transform: translateY(-2px);
    box-shadow: var(--ma-shadow-hover);
    outline: none;
}

.ma-stat.active {
    border-color: var(--ma-orange);
    background: var(--ma-orange-soft);
    box-shadow: 0 0 0 2px rgba(249,115,22,.1);
}

.ma-stat.pending { border-left-color: var(--ma-blue); }
.ma-stat.shortlisted { border-left-color: var(--ma-orange); }
.ma-stat.accepted { border-left-color: var(--ma-green); }
.ma-stat.drafts { border-left-color: var(--ma-purple); }

.ma-stat-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.ma-stat-icon {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    flex: 0 0 34px;
    border-radius: 9px;
    background: var(--ma-orange-soft);
    color: var(--ma-orange);
}

.ma-stat.pending .ma-stat-icon {
    background: var(--ma-blue-soft);
    color: var(--ma-blue);
}

.ma-stat.shortlisted .ma-stat-icon {
    background: var(--ma-orange-soft);
    color: var(--ma-orange);
}

.ma-stat.accepted .ma-stat-icon {
    background: var(--ma-green-soft);
    color: var(--ma-green);
}

.ma-stat.drafts .ma-stat-icon {
    background: var(--ma-purple-soft);
    color: var(--ma-purple);
}

.ma-stat-value {
    margin-top: 6px;
    font-size: 25px;
    font-weight: 800;
    line-height: 1;
}

.ma-stat-label {
    margin-top: 5px;
    color: var(--ma-muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}

/* Filter toolbar */
.ma-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
    padding: 13px 15px;
    background: var(--ma-white);
    border: 1px solid var(--ma-rule);
    border-radius: 12px;
}

.ma-toolbar-left {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.ma-toolbar-title {
    font-size: 14px;
    font-weight: 800;
}

.ma-filter-result {
    padding: 4px 9px;
    border-radius: 999px;
    color: var(--ma-orange);
    background: var(--ma-orange-soft);
    font-size: 11px;
    font-weight: 800;
}

.ma-clear-filter {
    border: 0;
    background: transparent;
    color: var(--ma-muted);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.ma-clear-filter:hover {
    color: var(--ma-orange);
}

/* 3 cards per row desktop */
.ma-app-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    align-items: stretch;
}

.ma-app-card {
    min-width: 0;
    height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--ma-white);
    border: 1px solid var(--ma-rule);
    border-top: 4px solid #cbd5e1;
    border-radius: var(--ma-radius);
    box-shadow: var(--ma-shadow);
    transition: transform .2s ease, box-shadow .2s ease;
}

.ma-app-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--ma-shadow-hover);
}

.ma-app-card.status-accepted { border-top-color: var(--ma-green); }
.ma-app-card.status-rejected { border-top-color: var(--ma-red); }
.ma-app-card.status-shortlisted { border-top-color: var(--ma-orange); }
.ma-app-card.status-review,
.ma-app-card.status-pending { border-top-color: var(--ma-blue); }
.ma-app-card.status-waitlisted { border-top-color: var(--ma-purple); }
.ma-app-card.status-draft { border-top-color: var(--ma-purple); }

.ma-card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 18px;
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
    color: var(--ma-ink);
    font-size: 16px;
    font-weight: 800;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.ma-card-ref {
    margin-top: 4px;
    color: var(--ma-light);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .06em;
}

.ma-status {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 800;
    white-space: nowrap;
}

.ma-status.accepted { color: var(--ma-green); background: var(--ma-green-soft); }
.ma-status.rejected { color: var(--ma-red); background: var(--ma-red-soft); }
.ma-status.shortlisted { color: var(--ma-orange); background: var(--ma-orange-soft); }
.ma-status.review,
.ma-status.pending,
.ma-status.submitted { color: var(--ma-blue); background: var(--ma-blue-soft); }
.ma-status.waitlisted { color: var(--ma-purple); background: var(--ma-purple-soft); }
.ma-status.withdrawn,
.ma-status.draft { color: var(--ma-muted); background: var(--ma-bg); }

.ma-card-meta {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
    margin-bottom: 15px;
}

.ma-meta {
    min-width: 0;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: var(--ma-muted);
    font-size: 12.5px;
    line-height: 1.45;
}

.ma-meta i {
    width: 14px;
    margin-top: 2px;
    color: var(--ma-orange);
    text-align: center;
}

.ma-meta strong {
    color: var(--ma-ink-2);
    font-weight: 700;
    overflow-wrap: anywhere;
}

.ma-score {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: auto;
    margin-bottom: 15px;
    padding: 11px 12px;
    border-radius: 9px;
    background: var(--ma-bg);
}

.ma-score-icon {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    flex: 0 0 34px;
    border-radius: 50%;
    color: var(--ma-orange);
    background: var(--ma-orange-soft);
}

.ma-score strong {
    display: block;
    font-size: 12.5px;
}

.ma-score span {
    color: var(--ma-muted);
    font-size: 11px;
}

.ma-card-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    padding-top: 14px;
    border-top: 1px solid var(--ma-rule);
}

.ma-card-actions .ma-btn {
    flex: 1 1 auto;
    padding-left: 11px;
    padding-right: 11px;
}

.ma-section {
    margin-top: 26px;
}

.ma-section-head {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 14px;
}

.ma-section-head h2 {
    margin: 0;
    font-size: 19px;
}

.ma-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 24px;
    padding: 0 8px;
    border-radius: 999px;
    background: var(--ma-orange-soft);
    color: var(--ma-orange);
    font-size: 11px;
    font-weight: 800;
}

.ma-empty {
    grid-column: 1 / -1;
    padding: 52px 20px;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    background: var(--ma-white);
    text-align: center;
}

.ma-empty i {
    margin-bottom: 13px;
    color: var(--ma-orange);
    font-size: 36px;
}

.ma-empty h3 {
    margin: 0 0 5px;
    font-size: 18px;
}

.ma-empty p {
    margin: 0 0 16px;
    color: var(--ma-muted);
    font-size: 13px;
}

.ma-no-filter-results {
    display: none;
    padding: 42px 20px;
    margin-top: 16px;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    background: #fff;
    text-align: center;
}

.ma-no-filter-results.show {
    display: block;
}

.ma-hidden {
    display: none !important;
}

@media (max-width: 1180px) {
    .ma-app-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ma-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .ma-shell {
        padding: 0 12px;
    }

    .ma-hero {
        padding: 24px 20px;
    }

    .ma-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ma-app-grid {
        grid-template-columns: 1fr;
    }

    .ma-toolbar {
        align-items: flex-start;
    }
}

@media (max-width: 420px) {
    .ma-stats {
        grid-template-columns: 1fr;
    }

    .ma-hero-actions,
    .ma-hero-actions .ma-btn {
        width: 100%;
    }

    .ma-card-head {
        flex-direction: column;
    }
}
</style>

<div class="ma-page">
    <div class="ma-shell">

        <section class="ma-hero">
            <div class="ma-hero-copy">
                <div class="ma-eyebrow">
                    <i class="fas fa-briefcase"></i>
                    Application Portal
                </div>

                <h1>My Applications</h1>

                <p>
                    Track submitted applications, continue saved drafts and quickly filter applications by status.
                </p>
            </div>

            <div class="ma-hero-actions">
                <a href="opportunities" class="ma-btn ma-btn-primary">
                    <i class="fas fa-search"></i>
                    Browse Opportunities
                </a>
            </div>
        </section>

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

        <div class="ma-stats" aria-label="Application filters">
            <button type="button" class="ma-stat active" data-filter="all">
                <div class="ma-stat-top">
                    <div>
                        <div class="ma-stat-value"><?= (int)$stats['total'] ?></div>
                        <div class="ma-stat-label">Total Submitted</div>
                    </div>
                    <span class="ma-stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </span>
                </div>
            </button>

            <button type="button" class="ma-stat pending" data-filter="pending">
                <div class="ma-stat-top">
                    <div>
                        <div class="ma-stat-value"><?= (int)$stats['pending'] ?></div>
                        <div class="ma-stat-label">Pending Review</div>
                    </div>
                    <span class="ma-stat-icon">
                        <i class="fas fa-clock"></i>
                    </span>
                </div>
            </button>

            <button type="button" class="ma-stat shortlisted" data-filter="shortlisted">
                <div class="ma-stat-top">
                    <div>
                        <div class="ma-stat-value"><?= (int)$stats['shortlisted'] ?></div>
                        <div class="ma-stat-label">Shortlisted</div>
                    </div>
                    <span class="ma-stat-icon">
                        <i class="fas fa-star"></i>
                    </span>
                </div>
            </button>

            <button type="button" class="ma-stat accepted" data-filter="accepted">
                <div class="ma-stat-top">
                    <div>
                        <div class="ma-stat-value"><?= (int)$stats['accepted'] ?></div>
                        <div class="ma-stat-label">Accepted</div>
                    </div>
                    <span class="ma-stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </span>
                </div>
            </button>

            <button type="button" class="ma-stat drafts" data-filter="draft">
                <div class="ma-stat-top">
                    <div>
                        <div class="ma-stat-value"><?= (int)$stats['draft'] ?></div>
                        <div class="ma-stat-label">Saved Drafts</div>
                    </div>
                    <span class="ma-stat-icon">
                        <i class="fas fa-edit"></i>
                    </span>
                </div>
            </button>
        </div>

        <div class="ma-toolbar">
            <div class="ma-toolbar-left">
                <span class="ma-toolbar-title">Applications</span>
                <span class="ma-filter-result" id="applicationFilterLabel">Showing all submitted applications</span>
            </div>

            <button type="button" class="ma-clear-filter" id="clearApplicationFilter">
                <i class="fas fa-undo"></i>
                Clear Filter
            </button>
        </div>

        <?php if (empty($applications)): ?>
            <div class="ma-empty">
                <i class="fas fa-folder-open"></i>
                <h3>No Applications Yet</h3>
                <p>Browse available opportunities and submit your first application.</p>

                <a href="opportunities" class="ma-btn ma-btn-dark">
                    <i class="fas fa-search"></i>
                    Browse Opportunities
                </a>
            </div>
        <?php else: ?>

            <?php if (!empty($drafts)): ?>
                <section class="ma-section ma-application-section" data-section="draft">
                    <div class="ma-section-head">
                        <h2>Saved Drafts</h2>
                        <span class="ma-count"><?= count($drafts) ?></span>
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
                                class="ma-app-card status-draft ma-filter-card"
                                data-filter-status="draft"
                            >
                                <div class="ma-card-body">
                                    <div class="ma-card-head">
                                        <div class="ma-card-title-wrap">
                                            <h3 class="ma-card-title">
                                                <?= ma_h($draft['opportunity_title'] ?? 'Opportunity') ?>
                                            </h3>
                                            <div class="ma-card-ref"><?= ma_h($ref) ?></div>
                                        </div>

                                        <span class="ma-status draft">
                                            <i class="fas fa-edit"></i>
                                            Draft
                                        </span>
                                    </div>

                                    <div class="ma-card-meta">
                                        <div class="ma-meta">
                                            <i class="fas fa-building"></i>
                                            <strong>
                                                <?= ma_h(($draft['startup_name'] ?? '') !== '' ? $draft['startup_name'] : 'Unnamed venture') ?>
                                            </strong>
                                        </div>

                                        <div class="ma-meta">
                                            <i class="fas fa-save"></i>
                                            <span>
                                                Last saved <?= ma_h(ma_date($savedAt, 'd M Y, h:i A')) ?>
                                            </span>
                                        </div>

                                        <div class="ma-meta">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span>
                                                Deadline:
                                                <?= ma_h(ma_date($draft['deadline'] ?? null, 'd M Y', 'No deadline')) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="ma-score">
                                        <span class="ma-score-icon">
                                            <i class="fas fa-file-alt"></i>
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
                                                class="ma-btn ma-btn-soft"
                                            >
                                                <i class="fas fa-pen"></i>
                                                Continue
                                            </a>
                                        <?php else: ?>
                                            <span class="ma-btn ma-btn-light" style="cursor:not-allowed;">
                                                <i class="fas fa-lock"></i>
                                                Closed
                                            </span>
                                        <?php endif; ?>

                                        <button
                                            type="button"
                                            class="ma-btn ma-btn-danger js-delete-draft"
                                            data-id="<?= $draftId ?>"
                                        >
                                            <i class="fas fa-trash-alt"></i>
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
                        <h2>Submitted Applications</h2>
                        <span class="ma-count"><?= count($submitted) ?></span>
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
                                class="ma-app-card status-<?= ma_h($meta['class']) ?> ma-filter-card"
                                data-filter-status="<?= ma_h($filterStatus) ?>"
                            >
                                <div class="ma-card-body">
                                    <div class="ma-card-head">
                                        <div class="ma-card-title-wrap">
                                            <h3 class="ma-card-title">
                                                <?= ma_h($app['opportunity_title'] ?? 'Opportunity') ?>
                                            </h3>
                                            <div class="ma-card-ref"><?= ma_h($ref) ?></div>
                                        </div>

                                        <span class="ma-status <?= ma_h($meta['class']) ?>">
                                            <i class="fas <?= ma_h($meta['icon']) ?>"></i>
                                            <?= ma_h($meta['label']) ?>
                                        </span>
                                    </div>

                                    <div class="ma-card-meta">
                                        <div class="ma-meta">
                                            <i class="fas fa-building"></i>
                                            <strong>
                                                <?= ma_h(($app['startup_name'] ?? '') !== '' ? $app['startup_name'] : 'Unnamed venture') ?>
                                            </strong>
                                        </div>

                                        <div class="ma-meta">
                                            <i class="fas fa-paper-plane"></i>
                                            <span>
                                                Submitted <?= ma_h(ma_date($submittedAt)) ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($app['opportunity_type'])): ?>
                                            <div class="ma-meta">
                                                <i class="fas fa-tag"></i>
                                                <span><?= ma_h($app['opportunity_type']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="ma-score">
                                        <span class="ma-score-icon">
                                            <i class="fas <?= $reviewCount > 0 ? 'fa-star-half-alt' : 'fa-hourglass-half' ?>"></i>
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
                                            class="ma-btn ma-btn-dark"
                                        >
                                            <i class="fas fa-eye"></i>
                                            View
                                        </a>

                                        <?php if ($status === 'Submitted'): ?>
                                            <button
                                                type="button"
                                                class="ma-btn ma-btn-danger js-withdraw-application"
                                                data-id="<?= $applicationId ?>"
                                            >
                                                <i class="fas fa-times-circle"></i>
                                                Withdraw
                                            </button>
                                        <?php endif; ?>

                                        <a
                                            href="mailto:info@hivecolab.com?subject=<?= rawurlencode("Application {$ref} Inquiry") ?>"
                                            class="ma-btn ma-btn-light"
                                        >
                                            <i class="fas fa-envelope"></i>
                                            Support
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="ma-no-filter-results" id="noApplicationResults">
                <i class="fas fa-filter" style="font-size:28px;color:var(--ma-orange);margin-bottom:10px;"></i>
                <h3 style="margin:0 0 5px;">No applications match this filter</h3>
                <p style="margin:0;color:var(--ma-muted);font-size:13px;">
                    Select another statistics card or clear the current filter.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

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
