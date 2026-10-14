<?php
declare(strict_types=1);

ob_start();
session_start();

date_default_timezone_set('Africa/Nairobi');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function review_datetime(mixed $value): string
{
    if ($value === null || trim((string)$value) === '' || $value === '0000-00-00 00:00:00') {
        return '-';
    }

    try {
        $dt = new DateTime((string)$value, new DateTimeZone('Africa/Nairobi'));
        return $dt->format('d M Y \a\t h:i A');
    } catch (Throwable $e) {
        return '-';
    }
}

function review_date(mixed $value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '-';
    }

    $ts = strtotime((string)$value);

    return $ts === false ? '-' : date('d M Y', $ts);
}

function redirect_with_error(string $message, string $location = 'dashboard'): never
{
    $_SESSION['error'] = $message;
    header('Location: ' . $location);
    exit;
}

function fetch_all_assoc(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $error);
    }

    $rows = [];
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();

    return $rows;
}

function fetch_one_assoc(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): ?array {
    $rows = fetch_all_assoc($conn, $sql, $types, $params);

    return $rows[0] ?? null;
}

function json_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || trim((string)$value) === '') {
        return [];
    }

    $decoded = json_decode((string)$value, true);

    return is_array($decoded) ? $decoded : [];
}

function list_text(array $items): string
{
    $clean = [];

    foreach ($items as $item) {
        if (is_scalar($item) && trim((string)$item) !== '') {
            $clean[] = trim((string)$item);
        }
    }

    return implode(', ', $clean);
}

function status_color(string $status): string
{
    return match ($status) {
        'Draft'        => '#64748b',
        'Submitted'    => '#f59e0b',
        'Under Review' => '#0ea5e9',
        'Shortlisted'  => '#f97316',
        'Selected',
        'Approved',
        'Accepted'     => '#16a34a',
        'Rejected'     => '#dc2626',
        'Waitlisted'   => '#7c3aed',
        'Withdrawn'    => '#64748b',
        default        => '#64748b',
    };
}

/*
|--------------------------------------------------------------------------
| ACCESS CONTROL
|--------------------------------------------------------------------------
*/
if (!ims_allowed('reviews.open', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer', 'Donor/Partner', 'Staff', 'Reviewer'], 'Open the application review page')) {
    redirect_with_error('Access denied.');
}

$reviewer_id = (int)$_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| APPLICATION
|--------------------------------------------------------------------------
*/
$application_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if ($application_id < 1) {
    redirect_with_error('No application specified.', 'startups-shortlisting');
}

$success_msg = (string)($_SESSION['success'] ?? '');
$error_msg = (string)($_SESSION['error'] ?? '');

unset($_SESSION['success'], $_SESSION['error']);

/*
|--------------------------------------------------------------------------
| REVIEWER ASSIGNMENT
|--------------------------------------------------------------------------
*/
$assignments = fetch_all_assoc(
    $conn,
    "
        SELECT
            assignment_id,
            review_type_id,
            status,
            assigned_at
        FROM reviewer_assignments
        WHERE application_id = ?
          AND reviewer_id = ?
        ORDER BY assigned_at DESC, assignment_id DESC
    ",
    'ii',
    [$application_id, $reviewer_id]
);

$is_assigned = !empty($assignments);
$can_review_unassigned = ims_allowed('reviews.review_unassigned', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Reviewer', 'Consultant'], 'Review applications they are not assigned to');

if (!$is_assigned && !$can_review_unassigned) {
    redirect_with_error(
        'You are not assigned to this application.',
        'startups-shortlisting'
    );
}

/*
|--------------------------------------------------------------------------
| LOAD APPLICATION
|--------------------------------------------------------------------------
*/
$app = fetch_one_assoc(
    $conn,
    "
        SELECT
            a.*,
            ao.opportunity_title,
            ao.opportunity_type,
            ao.deadline,
            ao.start_date,
            ao.sectors_allowed,
            ao.eligibility_criteria,
            ao.required_documents,
            ao.available_slots,
            u.full_name AS applicant_name,
            u.email AS applicant_email
        FROM applications a
        JOIN application_opportunities ao
            ON ao.opportunity_id = a.opportunity_id
        LEFT JOIN users u
            ON u.user_id = a.submitted_by
        WHERE a.application_id = ?
        LIMIT 1
    ",
    'i',
    [$application_id]
);

if (!$app) {
    redirect_with_error(
        'Application not found.',
        'startups-shortlisting'
    );
}

/*
|--------------------------------------------------------------------------
| NEW APPLICATION JSON DATA
|--------------------------------------------------------------------------
*/
$sector_focus = json_array($app['sector_focus'] ?? null);
$heard_about = json_array($app['heard_about'] ?? null);
$regions_served = json_array($app['regions_served'] ?? null);
$revenue_models = json_array($app['revenue_models'] ?? null);
$data_uses = json_array($app['data_uses'] ?? null);
$founders = json_array($app['founders'] ?? null);
$other_team_members = json_array($app['other_team_members'] ?? null);
$incubation_programmes = json_array($app['incubation_programmes'] ?? null);
$external_funding_details = json_array($app['external_funding_details'] ?? null);

/*
|--------------------------------------------------------------------------
| REVIEW TYPES
|--------------------------------------------------------------------------
*/
$review_types = fetch_all_assoc(
    $conn,
    "
        SELECT review_type_id, name
        FROM review_types
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    "
);

$requested_review_type_id = (int)($_GET['rt'] ?? 0);
$current_app_review_type_id = (int)($app['review_type_id'] ?? 0);

$assigned_review_type_ids = [];

foreach ($assignments as $assignment) {
    $id = (int)($assignment['review_type_id'] ?? 0);

    if ($id > 0) {
        $assigned_review_type_ids[] = $id;
    }
}

$assigned_review_type_ids = array_values(array_unique($assigned_review_type_ids));

$valid_review_type_ids = array_map(
    static fn(array $row): int => (int)$row['review_type_id'],
    $review_types
);

$active_review_type_id = 0;

if ($requested_review_type_id > 0) {
    $active_review_type_id = $requested_review_type_id;
} elseif ($current_app_review_type_id > 0) {
    $active_review_type_id = $current_app_review_type_id;
} elseif ($assigned_review_type_ids) {
    $active_review_type_id = (int)$assigned_review_type_ids[0];
} elseif ($review_types) {
    $active_review_type_id = (int)$review_types[0]['review_type_id'];
}

if (
    $active_review_type_id > 0 &&
    !in_array($active_review_type_id, $valid_review_type_ids, true)
) {
    $active_review_type_id = $review_types
        ? (int)$review_types[0]['review_type_id']
        : 0;
}

/*
|--------------------------------------------------------------------------
| EXISTING REVIEW
|--------------------------------------------------------------------------
*/
$existing_reviews = fetch_all_assoc(
    $conn,
    "
        SELECT
            review_id,
            application_id,
            reviewer_id,
            review_type_id,
            overall_score,
            comments,
            recommendation,
            created_at,
            updated_at
        FROM application_reviews
        WHERE application_id = ?
          AND reviewer_id = ?
        ORDER BY updated_at DESC, created_at DESC, review_id DESC
    ",
    'ii',
    [$application_id, $reviewer_id]
);

$existing_review_by_type = [];

foreach ($existing_reviews as $review) {
    $typeId = (int)($review['review_type_id'] ?? 0);

    if ($typeId > 0 && !isset($existing_review_by_type[$typeId])) {
        $existing_review_by_type[$typeId] = $review;
    }
}

$existing = $existing_review_by_type[$active_review_type_id] ?? null;

/*
|--------------------------------------------------------------------------
| CRITERIA
|--------------------------------------------------------------------------
*/
$criteria = [];
$criteria_grouped = [];
$total_possible_weighted = 0.0;

if ($active_review_type_id > 0) {
    $criteria = fetch_all_assoc(
        $conn,
        "
            SELECT
                criteria_id,
                review_type_id,
                category,
                question,
                description,
                max_score,
                weight,
                is_active
            FROM review_criteria
            WHERE is_active = 1
              AND review_type_id = ?
            ORDER BY category ASC, criteria_id ASC
        ",
        'i',
        [$active_review_type_id]
    );
}

foreach ($criteria as $criterion) {
    $category = (string)($criterion['category'] ?? 'General');

    $criteria_grouped[$category][] = $criterion;

    $total_possible_weighted +=
        (float)$criterion['max_score']
        * (float)($criterion['weight'] ?? 1);
}

/*
|--------------------------------------------------------------------------
| EXISTING CRITERION SCORES
|--------------------------------------------------------------------------
*/
$existing_scores = [];

if ($active_review_type_id > 0) {
    $score_rows = fetch_all_assoc(
        $conn,
        "
            SELECT
                criteria_id,
                score,
                comments,
                created_at,
                updated_at
            FROM review_scores
            WHERE application_id = ?
              AND reviewer_id = ?
              AND review_type_id = ?
        ",
        'iii',
        [
            $application_id,
            $reviewer_id,
            $active_review_type_id,
        ]
    );

    foreach ($score_rows as $row) {
        $existing_scores[(int)$row['criteria_id']] = [
            'score' => (float)$row['score'],
            'comments' => (string)($row['comments'] ?? ''),
        ];
    }
}

$existing_weighted_total = 0.0;

foreach ($criteria as $criterion) {
    $criteriaId = (int)$criterion['criteria_id'];
    $score = (float)($existing_scores[$criteriaId]['score'] ?? 0);
    $weight = (float)($criterion['weight'] ?? 1);

    $existing_weighted_total += $score * $weight;
}

$existing_percent = $total_possible_weighted > 0
    ? round(
        ($existing_weighted_total / $total_possible_weighted) * 100,
        1
    )
    : 0;

/*
|--------------------------------------------------------------------------
| DOCUMENTS
|--------------------------------------------------------------------------
*/
$documents = [
    'legal_docs_path' => ['Legal / Registration Documents', 'fas fa-file-alt'],
    'safeguarding_policy_path' => ['Safeguarding Policy', 'fas fa-shield-alt'],
    'business_plan_path' => ['Business Plan', 'fas fa-file-pdf'],
    'pitch_deck_path' => ['Pitch Deck', 'fas fa-file-powerpoint'],
    'financial_projections_path' => ['Financial Projections', 'fas fa-file-excel'],
    'registration_certificate_path' => ['Registration Certificate', 'fas fa-file-alt'],
    'other_documents_path' => ['Other Documents', 'fas fa-file'],
];

$has_documents = false;

foreach ($documents as $field => $meta) {
    if (!empty($app[$field])) {
        $has_documents = true;
        break;
    }
}

$page_title = 'Review Applicant';
require_once 'includes/header.php';
?>

<style>
.review-page {
    width: 100%;
}

.review-header {
    background: linear-gradient(135deg,#ff5722,#ff9800);
    color: #fff;
    padding: 25px 28px;
    border-radius: 12px;
    margin-bottom: 18px;
}

.review-header h1 {
    margin: 0 0 5px;
    font-size: 24px;
}

.review-header p {
    margin: 0;
    opacity: .9;
    font-size: 13px;
}

.review-header-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}

.review-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #fff;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}

.review-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 390px;
    gap: 20px;
    align-items: start;
}

/*
|--------------------------------------------------------------------------
| LEFT: COMPACT APPLICATION READER
|--------------------------------------------------------------------------
*/
.app-reader {
    min-width: 0;
}

.reader-hint {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 11px 13px;
    margin-bottom: 12px;
    border-radius: 9px;
    background: #fff7ed;
    color: #9a3412;
    font-size: 12.5px;
    border: 1px solid #fed7aa;
}

.reader-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 11px;
    box-shadow: 0 2px 7px rgba(15,23,42,.05);
    margin-bottom: 10px;
    overflow: hidden;
}

.reader-card-header {
    width: 100%;
    appearance: none;
    border: 0;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    cursor: pointer;
    text-align: left;
}

.reader-card-header:hover {
    background: #fffaf5;
}

.reader-card-title {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
}

.reader-card-title i {
    width: 20px;
    color: #f97316;
    text-align: center;
}

.reader-card-title strong {
    color: #1f2937;
    font-size: 14px;
}

.reader-card-summary {
    color: #94a3b8;
    font-size: 11px;
    margin-left: 6px;
    font-weight: 600;
}

.reader-chevron {
    color: #94a3b8;
    transition: transform .2s ease;
}

.reader-card.open .reader-chevron {
    transform: rotate(180deg);
}

.reader-card-body {
    display: none;
    padding: 0 16px 16px;
    border-top: 1px solid #f1f5f9;
}

.reader-card.open .reader-card-body {
    display: block;
}

.reader-card-body-inner {
    padding-top: 14px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 10px;
}

.info-item {
    background: #f8fafc;
    border-radius: 8px;
    padding: 11px 12px;
    min-width: 0;
}

.info-item.full {
    grid-column: 1 / -1;
}

.info-item label {
    display: block;
    margin-bottom: 4px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #94a3b8;
    font-weight: 700;
}

.info-item .val {
    color: #334155;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.reader-text {
    background: #f8fafc;
    border-radius: 8px;
    padding: 12px 14px;
    color: #475569;
    font-size: 13px;
    line-height: 1.72;
    white-space: pre-wrap;
    margin-bottom: 10px;
}

.reader-text:last-child {
    margin-bottom: 0;
}

.reader-label {
    display: block;
    margin: 0 0 5px;
    color: #c2410c;
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.reader-table-wrap {
    overflow-x: auto;
}

.reader-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.reader-table th,
.reader-table td {
    padding: 8px 9px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    vertical-align: top;
}

.reader-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 10px;
    text-transform: uppercase;
}

.doc-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 12px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 8px;
}

.doc-item:last-child {
    margin-bottom: 0;
}

.doc-info {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
}

.doc-info i {
    color: #f97316;
    font-size: 18px;
}

.doc-info strong {
    display: block;
    color: #334155;
    font-size: 12.5px;
}

.doc-info small {
    color: #94a3b8;
    overflow-wrap: anywhere;
}

/*
|--------------------------------------------------------------------------
| REVIEW TYPE TABS
|--------------------------------------------------------------------------
*/
.type-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 14px;
}

.type-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #e5e7eb;
    color: #475569;
    text-decoration: none;
    font-size: 11.5px;
    font-weight: 700;
}

.type-tab.active {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #93c5fd;
}

.type-tab.assigned {
    box-shadow: inset 0 0 0 1px #fed7aa;
}

/*
|--------------------------------------------------------------------------
| RIGHT: SCORING FORM
|--------------------------------------------------------------------------
*/
.score-form {
    position: sticky;
    top: 16px;
}

.score-card {
    background: #fff;
    border-radius: 11px;
    box-shadow: 0 2px 8px rgba(15,23,42,.07);
    margin-bottom: 12px;
    overflow: hidden;
}

.score-card-head {
    padding: 13px 15px;
    border-bottom: 1px solid #eef2f7;
    font-size: 14px;
    font-weight: 800;
    color: #f97316;
}

.score-card-body {
    padding: 15px;
}

.overall-display {
    text-align: center;
}

.overall-circle {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: conic-gradient(#f97316 0% var(--pct,0%),#e5e7eb var(--pct,0%) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    position: relative;
}

.overall-circle::before {
    content: '';
    position: absolute;
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: #fff;
}

.oc-text {
    position: relative;
    z-index: 1;
    font-size: 20px;
    font-weight: 800;
    color: #f97316;
}

.overall-label {
    color: #64748b;
    font-size: 11.5px;
}

.criteria-group {
    background: #fff;
    border-radius: 11px;
    box-shadow: 0 2px 8px rgba(15,23,42,.06);
    margin-bottom: 10px;
    overflow: hidden;
}

.criteria-group-head {
    padding: 10px 13px;
    background: #f8fafc;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}

.criteria-group-head strong {
    color: #334155;
    font-size: 12.5px;
}

.criteria-score {
    font-size: 11px;
    font-weight: 800;
    color: #f97316;
}

.criterion-row {
    padding: 11px 13px;
    border-top: 1px solid #f1f5f9;
}

.criterion-q {
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 4px;
    line-height: 1.4;
}

.criterion-guide {
    color: #94a3b8;
    font-size: 10.5px;
    line-height: 1.45;
    margin-bottom: 7px;
}

.score-slider-wrap {
    display: flex;
    align-items: center;
    gap: 9px;
}

.score-slider {
    flex: 1;
    accent-color: #f97316;
}

.score-badge {
    min-width: 54px;
    text-align: center;
    padding: 3px 6px;
    border-radius: 7px;
    background: #f97316;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
}

.form-group {
    margin-bottom: 12px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
}

.form-control {
    width: 100%;
    padding: 9px 11px;
    border: 1.5px solid #e5e7eb;
    border-radius: 8px;
    font-size: 12.5px;
    font-family: inherit;
}

.form-control:focus {
    border-color: #fb923c;
    outline: none;
}

.rec-options {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 7px;
}

.rec-option {
    display: none;
}

.rec-label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    min-height: 38px;
    border: 1.5px solid #e5e7eb;
    border-radius: 8px;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.rec-option:checked + .rec-label {
    border-color: #f97316;
    background: #fff7ed;
    color: #c2410c;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 14px;
    border-radius: 8px;
    border: 0;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}

.btn-primary,
.btn-success {
    background: #f97316;
    color: #fff;
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
}

.btn-block {
    width: 100%;
}

.alert {
    padding: 11px 13px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 12.5px;
}

.alert-success {
    background: #ecfdf5;
    color: #166534;
    border-left: 4px solid #22c55e;
}

.alert-danger {
    background: #fef2f2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

.alert-info {
    background: #eff6ff;
    color: #1e40af;
    border-left: 4px solid #3b82f6;
}

.reviewed-banner {
    background: #ecfdf5;
    color: #166534;
    padding: 11px 13px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 12px;
    border: 1px solid #bbf7d0;
}


/* --------------------------------------------------------------------------
   RIGHT-SIDE REVIEW LIST CARDS
--------------------------------------------------------------------------- */
.review-list-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 2px 7px rgba(15,23,42,.05);
    margin-bottom: 9px;
    overflow: hidden;
}

.review-list-head {
    width: 100%;
    appearance: none;
    border: 0;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 13px;
    cursor: pointer;
    text-align: left;
}

.review-list-head:hover {
    background: #fffaf5;
}

.review-list-title {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.review-list-title i {
    width: 18px;
    color: #f97316;
    text-align: center;
}

.review-list-title strong {
    color: #334155;
    font-size: 12.5px;
}

.review-list-score {
    color: #f97316;
    font-size: 10.5px;
    font-weight: 800;
    white-space: nowrap;
}

.review-list-chevron {
    color: #94a3b8;
    font-size: 11px;
    transition: transform .2s ease;
}

.review-list-card.open .review-list-chevron {
    transform: rotate(180deg);
}

.review-list-body {
    display: none;
    border-top: 1px solid #f1f5f9;
}

.review-list-card.open .review-list-body {
    display: block;
}

.review-list-body-inner {
    padding: 4px 0;
}

.review-details-fixed {
    display: block;
}

@media (max-width: 980px) {
    .review-layout {
        grid-template-columns: 1fr;
    }

    .score-form {
        position: static;
    }
}

@media (max-width: 640px) {
    .info-grid {
        grid-template-columns: 1fr;
    }

    .reader-card-summary {
        display: none;
    }
}
</style>

<div class="review-page">

    <div class="review-header">
        <div class="review-header-row">
            <div>
                <h1><i class="fas fa-star-half-alt"></i> Review Application</h1>
                <p>
                    <strong><?= h($app['startup_name'] ?? 'Unnamed Venture') ?></strong>
                    &mdash;
                    <?= h($app['opportunity_title'] ?? '') ?>
                </p>
            </div>

            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span
                    class="review-status"
                    style="background:<?= h(status_color((string)($app['status'] ?? ''))) ?>"
                >
                    <?= h($app['status'] ?? 'Draft') ?>
                </span>

                <a href="startups-shortlisting" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back
                </a>
            </div>
        </div>
    </div>

    <?php if ($success_msg !== ''): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= h($success_msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($error_msg !== ''): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?= h($error_msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($review_types): ?>
        <div class="type-tabs">
            <?php foreach ($review_types as $review_type):
                $reviewTypeId = (int)$review_type['review_type_id'];
                $active = $reviewTypeId === $active_review_type_id;
                $assigned = in_array(
                    $reviewTypeId,
                    $assigned_review_type_ids,
                    true
                );
            ?>
                <a
                    href="review-applicant?id=<?= $application_id ?>&rt=<?= $reviewTypeId ?>"
                    class="type-tab <?= $active ? 'active' : '' ?> <?= $assigned ? 'assigned' : '' ?>"
                >
                    <i class="fas fa-layer-group"></i>
                    <?= h($review_type['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($existing): ?>
        <div class="reviewed-banner">
            <i class="fas fa-check-double"></i>
            Existing review loaded from
            <?= h(review_datetime($existing['updated_at'] ?? null)) ?>.
            You can update it below.
        </div>
    <?php endif; ?>

    <div class="review-layout">

        <!-- LEFT: compact clickable reader cards -->
        <div class="app-reader">

            <div class="reader-hint">
                <i class="fas fa-hand-pointer"></i>
                Click a section to expand and read it. Only one section opens at a time to reduce scrolling.
            </div>

            <!-- Snapshot -->
            <section class="reader-card open">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-id-card"></i>
                        <strong>Applicant Snapshot</strong>
                        <span class="reader-card-summary">Basic application details</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <div class="info-grid">
                            <?php foreach ([
                                'startup_name' => 'Startup Name',
                                'opportunity_title' => 'Opportunity',
                                'opportunity_type' => 'Opportunity Type',
                                'applicant_name' => 'Applicant',
                                'applicant_email' => 'Applicant Email',
                                'business_stage' => 'Business Stage',
                                'contact_person' => 'Contact Person',
                                'email' => 'Contact Email',
                                'phone' => 'Phone',
                                'district' => 'District',
                                'city_town' => 'City / Town',
                                'legal_status' => 'Legal Status',
                            ] as $field => $label):
                                $value = $app[$field] ?? null;
                                if ($value === null || trim((string)$value) === '') continue;
                            ?>
                                <div class="info-item">
                                    <label><?= h($label) ?></label>
                                    <div class="val"><?= h($value) ?></div>
                                </div>
                            <?php endforeach; ?>

                            <div class="info-item">
                                <label>Submitted</label>
                                <div class="val"><?= h(review_date($app['submitted_at'] ?? null)) ?></div>
                            </div>

                            <div class="info-item">
                                <label>Status</label>
                                <div class="val"><?= h($app['status'] ?? '-') ?></div>
                            </div>

                            <?php if ($sector_focus): ?>
                                <div class="info-item full">
                                    <label>Sector Focus</label>
                                    <div class="val"><?= h(list_text($sector_focus)) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Venture -->
            <section class="reader-card">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-rocket"></i>
                        <strong>Venture</strong>
                        <span class="reader-card-summary">Registration, location and programmes</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <div class="info-grid">
                            <?php foreach ([
                                'has_physical_location' => 'Physical Location',
                                'physical_address' => 'Physical Address',
                                'founded_month' => 'Founded',
                                'formally_registered' => 'Formally Registered',
                                'registration_authority' => 'Registration Authority',
                                'website' => 'Website',
                                'incubation_participated' => 'Incubation Participated',
                            ] as $field => $label):
                                $value = $app[$field] ?? null;
                                if ($value === null || trim((string)$value) === '') continue;
                            ?>
                                <div class="info-item <?= $field === 'physical_address' ? 'full' : '' ?>">
                                    <label><?= h($label) ?></label>
                                    <div class="val"><?= h($value) ?></div>
                                </div>
                            <?php endforeach; ?>

                            <?php if ($heard_about): ?>
                                <div class="info-item full">
                                    <label>How They Heard About the Opportunity</label>
                                    <div class="val"><?= h(list_text($heard_about)) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($incubation_programmes): ?>
                            <div style="margin-top:14px;">
                                <span class="reader-label">Incubation / Accelerator Programmes</span>
                                <div class="reader-table-wrap">
                                    <table class="reader-table">
                                        <thead>
                                            <tr>
                                                <th>Programme</th>
                                                <th>Organisation</th>
                                                <th>Year</th>
                                                <th>Outcomes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($incubation_programmes as $row): ?>
                                            <tr>
                                                <td><?= h($row['programme'] ?? '—') ?></td>
                                                <td><?= h($row['organisation'] ?? '—') ?></td>
                                                <td><?= h($row['year'] ?? '—') ?></td>
                                                <td><?= h($row['outcomes'] ?? '—') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Team -->
            <section class="reader-card">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-users"></i>
                        <strong>Team</strong>
                        <span class="reader-card-summary">Founders and other team members</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <div class="info-grid" style="margin-bottom:14px;">
                            <?php foreach ([
                                'full_time_staff' => 'Full-Time Staff',
                                'part_time_staff' => 'Part-Time Staff',
                                'contact_gender' => 'Contact Gender',
                            ] as $field => $label):
                                $value = $app[$field] ?? null;
                                if ($value === null || trim((string)$value) === '') continue;
                            ?>
                                <div class="info-item">
                                    <label><?= h($label) ?></label>
                                    <div class="val"><?= h($value) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($founders): ?>
                            <span class="reader-label">Founders</span>
                            <div class="reader-table-wrap" style="margin-bottom:14px;">
                                <table class="reader-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Role</th>
                                            <th>Gender</th>
                                            <th>Shareholding</th>
                                            <th>Full Time</th>
                                            <th>PWD</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($founders as $row): ?>
                                        <tr>
                                            <td><?= h($row['name'] ?? '—') ?></td>
                                            <td><?= h($row['role'] ?? '—') ?></td>
                                            <td><?= h($row['gender'] ?? '—') ?></td>
                                            <td><?= h($row['shareholding'] ?? '—') ?></td>
                                            <td><?= h($row['full_time'] ?? '—') ?></td>
                                            <td><?= h($row['pwd_status'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if ($other_team_members): ?>
                            <span class="reader-label">Other Team Members</span>
                            <div class="reader-table-wrap" style="margin-bottom:14px;">
                                <table class="reader-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Role</th>
                                            <th>Gender</th>
                                            <th>PWD</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($other_team_members as $row): ?>
                                        <tr>
                                            <td><?= h($row['name'] ?? '—') ?></td>
                                            <td><?= h($row['role'] ?? '—') ?></td>
                                            <td><?= h($row['gender'] ?? '—') ?></td>
                                            <td><?= h($row['pwd_status'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($app['team_positioning'])): ?>
                            <span class="reader-label">Team Positioning</span>
                            <div class="reader-text"><?= h($app['team_positioning']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Problem & Solution -->
            <section class="reader-card">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Problem &amp; Solution</strong>
                        <span class="reader-card-summary">Problem, users, product and reach</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <?php
                        $solution_fields = [
                            'problem_solution' => 'Problem & Solution',
                            'problem_statement' => 'Problem Statement',
                            'affected_population' => 'Affected Population',
                            'problem_evidence' => 'Problem Evidence',
                            'solution_description' => 'Solution Description',
                            'uniqueness' => 'Uniqueness',
                            'theory_of_change' => 'Theory of Change',
                            'primary_users' => 'Primary Users',
                            'solution_languages' => 'Solution Languages',
                            'platforms_devices' => 'Platforms / Devices',
                            'works_offline' => 'Works Offline',
                            'refugee_settlements_specify' => 'Refugee Settlements',
                            'regions_other' => 'Other Regions',
                            'demo_link' => 'Demo Link',
                            'video_link' => 'Video Link',
                        ];

                        $hasSolution = false;

                        foreach ($solution_fields as $field => $label):
                            if (empty($app[$field])) continue;
                            $hasSolution = true;
                        ?>
                            <span class="reader-label"><?= h($label) ?></span>
                            <div class="reader-text"><?= h($app[$field]) ?></div>
                        <?php endforeach; ?>

                        <?php if ($regions_served): $hasSolution = true; ?>
                            <span class="reader-label">Regions Served</span>
                            <div class="reader-text"><?= h(list_text($regions_served)) ?></div>
                        <?php endif; ?>

                        <?php if (!$hasSolution): ?>
                            <div class="reader-text">No information provided.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Traction -->
            <section class="reader-card">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-chart-line"></i>
                        <strong>Traction</strong>
                        <span class="reader-card-summary">Users, learners, customers and growth</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <div class="info-grid">
                            <?php foreach ([
                                'total_users_reached' => 'Total Users Reached',
                                'total_learners' => 'Total Learners',
                                'active_learners' => 'Active Learners',
                                'paying_customers' => 'Paying Customers',
                                'partner_organisations' => 'Partner Organisations',
                                'revenue_last_12_months' => 'Revenue - Last 12 Months',
                                'customers' => 'Customers',
                                'growth_rate' => 'Growth Rate',
                            ] as $field => $label):
                                $value = $app[$field] ?? null;
                                if ($value === null || trim((string)$value) === '') continue;
                            ?>
                                <div class="info-item">
                                    <label><?= h($label) ?></label>
                                    <div class="val"><?= h($value) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($app['other_traction_metrics'])): ?>
                            <div style="margin-top:14px;">
                                <span class="reader-label">Other Traction Metrics</span>
                                <div class="reader-text"><?= h($app['other_traction_metrics']) ?></div>
                            </div>
                        <?php elseif (!empty($app['traction'])): ?>
                            <div style="margin-top:14px;">
                                <span class="reader-label">Traction</span>
                                <div class="reader-text"><?= h($app['traction']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Business & Funding -->
            <section class="reader-card">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-briefcase"></i>
                        <strong>Business &amp; Funding</strong>
                        <span class="reader-card-summary">Revenue model, funding and grant use</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <?php if ($revenue_models): ?>
                            <span class="reader-label">Revenue Models</span>
                            <div class="reader-text"><?= h(list_text($revenue_models)) ?></div>
                        <?php endif; ?>

                        <?php foreach ([
                            'who_pays' => 'Who Pays',
                            'revenue_model_other' => 'Other Revenue Model',
                            'revenue_streams' => 'Revenue Streams',
                            'raised_external_funding' => 'Raised External Funding',
                            'fellowship_grant_use' => 'Planned Fellowship Grant Use',
                            'fellowship_growth_value' => 'How the Fellowship Will Support Growth',
                            'funding_use' => 'Funding Use',
                        ] as $field => $label):
                            if (empty($app[$field])) continue;
                        ?>
                            <span class="reader-label"><?= h($label) ?></span>
                            <div class="reader-text"><?= h($app[$field]) ?></div>
                        <?php endforeach; ?>

                        <?php if ($external_funding_details): ?>
                            <span class="reader-label">External Funding Details</span>
                            <div class="reader-table-wrap">
                                <table class="reader-table">
                                    <thead>
                                        <tr>
                                            <th>Funder</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Currency</th>
                                            <th>Year</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($external_funding_details as $row): ?>
                                        <tr>
                                            <td><?= h($row['funder'] ?? '—') ?></td>
                                            <td><?= h($row['type'] ?? '—') ?></td>
                                            <td><?= h($row['amount'] ?? '—') ?></td>
                                            <td><?= h($row['currency'] ?? '—') ?></td>
                                            <td><?= h($row['year'] ?? '—') ?></td>
                                            <td><?= h($row['status'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Safeguarding -->
            <section class="reader-card">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-shield-alt"></i>
                        <strong>Safeguarding &amp; Inclusion</strong>
                        <span class="reader-card-summary">Policy, minors and accessibility</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <?php
                        $safeguarding_fields = [
                            'has_safeguarding_policy' => 'Has Safeguarding Policy',
                            'safeguarding_measures' => 'Safeguarding Measures',
                            'has_reporting_procedures' => 'Has Reporting Procedures',
                            'reporting_procedures_description' => 'Reporting Procedures',
                            'users_include_minors' => 'Users Include Minors',
                            'parental_consent_process' => 'Parental Consent Process',
                            'safeguarding_focal_name' => 'Safeguarding Focal Person',
                            'safeguarding_focal_role' => 'Safeguarding Focal Role',
                            'safeguarding_focal_contact' => 'Safeguarding Focal Contact',
                            'accessibility_inclusion' => 'Accessibility & Inclusion',
                        ];

                        $hasSafeguarding = false;

                        foreach ($safeguarding_fields as $field => $label):
                            if (empty($app[$field])) continue;
                            $hasSafeguarding = true;
                        ?>
                            <span class="reader-label"><?= h($label) ?></span>
                            <div class="reader-text"><?= h($app[$field]) ?></div>
                        <?php endforeach; ?>

                        <?php if (!$hasSafeguarding): ?>
                            <div class="reader-text">No information provided.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- MEL -->
            <section class="reader-card">
                <button type="button" class="reader-card-header">
                    <span class="reader-card-title">
                        <i class="fas fa-chart-bar"></i>
                        <strong>Monitoring, Evaluation &amp; Learning</strong>
                        <span class="reader-card-summary">Impact, data use and evidence</span>
                    </span>
                    <i class="fas fa-chevron-down reader-chevron"></i>
                </button>

                <div class="reader-card-body">
                    <div class="reader-card-body-inner">
                        <?php foreach ([
                            'impact_measurement' => 'Impact Measurement',
                            'data_collection_frequency' => 'Data Collection Frequency',
                            'data_use_other' => 'Other Data Use',
                            'has_mel_framework' => 'Has MEL Framework',
                            'data_tools' => 'Data Tools',
                            'learning_outcomes_evidence' => 'Learning Outcomes Evidence',
                            'pdpo_status' => 'PDPO Status',
                        ] as $field => $label):
                            if (empty($app[$field])) continue;
                        ?>
                            <span class="reader-label"><?= h($label) ?></span>
                            <div class="reader-text"><?= h($app[$field]) ?></div>
                        <?php endforeach; ?>

                        <?php if ($data_uses): ?>
                            <span class="reader-label">How Data Is Used</span>
                            <div class="reader-text"><?= h(list_text($data_uses)) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Documents -->
            <?php if ($has_documents): ?>
                <section class="reader-card">
                    <button type="button" class="reader-card-header">
                        <span class="reader-card-title">
                            <i class="fas fa-paperclip"></i>
                            <strong>Supporting Documents</strong>
                            <span class="reader-card-summary">Open submitted files</span>
                        </span>
                        <i class="fas fa-chevron-down reader-chevron"></i>
                    </button>

                    <div class="reader-card-body">
                        <div class="reader-card-body-inner">
                            <?php foreach ($documents as $field => [$label, $icon]):
                                if (empty($app[$field])) continue;
                                $path = (string)$app[$field];
                            ?>
                                <div class="doc-item">
                                    <div class="doc-info">
                                        <i class="<?= h($icon) ?>"></i>
                                        <div>
                                            <strong><?= h($label) ?></strong>
                                            <small><?= h(basename($path)) ?></small>
                                        </div>
                                    </div>

                                    <a
                                        href="<?= h(ims_upload_url($path)) ?>"
                                        target="_blank"
                                        rel="noopener"
                                        class="btn btn-secondary"
                                    >
                                        <i class="fas fa-eye"></i>
                                        View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

        </div>

        <!-- RIGHT: score form -->
        <aside class="score-form">

            <?php if ($active_review_type_id <= 0): ?>

                <div class="alert alert-info">
                    No active review type found. Please select or assign a review type first.
                </div>

            <?php elseif (!$criteria): ?>

                <div class="alert alert-info">
                    No scoring criteria are defined for this review type.
                </div>

            <?php else: ?>

                <div class="score-card">
                    <div class="score-card-head">
                        <i class="fas fa-star"></i>
                        Overall Score
                    </div>

                    <div class="score-card-body">
                        <div class="overall-display">
                            <div
                                class="overall-circle"
                                id="overall-circle"
                                style="--pct:<?= $existing_percent ?>%;"
                            >
                                <span class="oc-text" id="overall-text">
                                    <?= number_format($existing_percent, 1) ?>%
                                </span>
                            </div>

                            <div class="overall-label" id="overall-label">
                                <?= number_format($existing_weighted_total, 1) ?>
                                /
                                <?= number_format($total_possible_weighted, 1) ?>
                                weighted pts
                            </div>
                        </div>
                    </div>
                </div>

                <form
                    method="POST"
                    action="includes/process_review.php"
                    id="review-form"
                >
                    <input
                        type="hidden"
                        name="application_id"
                        value="<?= $application_id ?>"
                    >

                    <input
                        type="hidden"
                        name="review_type_id"
                        value="<?= $active_review_type_id ?>"
                    >

                    <input
                        type="hidden"
                        name="overall_score"
                        id="hidden-overall"
                        value="<?= number_format($existing_percent, 1, '.', '') ?>"
                    >

                    <?php foreach ($criteria_grouped as $categoryIndex => $criteriaList):
                        $category = is_string($categoryIndex) ? $categoryIndex : 'General';

                        $categorySlug = preg_replace(
                            '/\W+/',
                            '-',
                            strtolower($category)
                        );

                        $categoryMax = 0.0;

                        foreach ($criteriaList as $criterion) {
                            $categoryMax +=
                                (float)$criterion['max_score']
                                * (float)($criterion['weight'] ?? 1);
                        }
                    ?>
                        <div class="review-list-card">
                            <button
                                type="button"
                                class="review-list-head"
                                aria-expanded="false"
                            >
                                <span class="review-list-title">
                                    <i class="fas fa-layer-group"></i>
                                    <strong><?= h($category) ?></strong>
                                </span>

                                <span style="display:flex;align-items:center;gap:8px;">
                                    <span
                                        class="review-list-score"
                                        id="cat-display-<?= h($categorySlug) ?>"
                                    >
                                        0 / <?= number_format($categoryMax, 1) ?>
                                    </span>
                                    <i class="fas fa-chevron-down review-list-chevron"></i>
                                </span>
                            </button>

                            <div class="review-list-body">
                                <div class="review-list-body-inner">

                            <?php foreach ($criteriaList as $criterion):
                                $criteriaId = (int)$criterion['criteria_id'];
                                $max = (float)$criterion['max_score'];
                                $weight = (float)($criterion['weight'] ?? 1);

                                $prefill = (float)(
                                    $existing_scores[$criteriaId]['score']
                                    ?? 0
                                );

                                $prefill = max(0, min($max, $prefill));
                            ?>
                                <div class="criterion-row">
                                    <div class="criterion-q">
                                        <?= h($criterion['question']) ?>
                                    </div>

                                    <?php if (!empty($criterion['description'])): ?>
                                        <div class="criterion-guide">
                                            <?= h($criterion['description']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="score-slider-wrap">
                                        <input
                                            type="range"
                                            class="score-slider"
                                            name="criteria[<?= $criteriaId ?>]"
                                            min="0"
                                            max="<?= $max ?>"
                                            step="1"
                                            value="<?= $prefill ?>"
                                            data-max="<?= $max ?>"
                                            data-weight="<?= $weight ?>"
                                            data-cat="<?= h($categorySlug) ?>"
                                            oninput="updateSlider(this)"
                                        >

                                        <span
                                            class="score-badge"
                                            id="badge-<?= $criteriaId ?>"
                                        >
                                            <?= number_format($prefill, 0) ?>
                                            /
                                            <?= number_format($max, 0) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="score-card review-details-fixed">
                        <div class="score-card-head">
                            <i class="fas fa-clipboard-check"></i>
                            Review Details
                        </div>

                        <div class="score-card-body">

                            <div class="form-group">
                                <label>Recommendation</label>

                                <div class="rec-options">
                                    <?php
                                    $recommendations = [
                                        'Accept' => 'fa-check-circle',
                                        'Reject' => 'fa-times-circle',
                                        'Shortlist' => 'fa-star',
                                        'Hold' => 'fa-pause-circle',
                                    ];

                                    foreach ($recommendations as $value => $icon):
                                        $checked = (
                                            $existing
                                            && ($existing['recommendation'] ?? '') === $value
                                        )
                                            ? 'checked'
                                            : '';
                                    ?>
                                        <div>
                                            <input
                                                type="radio"
                                                class="rec-option"
                                                id="rec-<?= h(strtolower($value)) ?>"
                                                name="recommendation"
                                                value="<?= h($value) ?>"
                                                <?= $checked ?>
                                                required
                                            >

                                            <label
                                                for="rec-<?= h(strtolower($value)) ?>"
                                                class="rec-label"
                                            >
                                                <i class="fas <?= h($icon) ?>"></i>
                                                <?= h($value) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>
                                    Reviewer Comments
                                    <span style="color:#dc2626;">*</span>
                                </label>

                                <textarea
                                    name="comments"
                                    class="form-control"
                                    rows="4"
                                    required
                                    placeholder="Provide detailed feedback on the application..."
                                ><?= h($existing['comments'] ?? '') ?></textarea>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-success btn-block"
                            >
                                <i class="fas fa-save"></i>
                                <?= $existing ? 'Update Review' : 'Submit Review' ?>
                            </button>

                        </div>
                    </div>
                </form>

            <?php endif; ?>

        </aside>

    </div>
</div>

<script>
const totalPossibleWeighted = <?= number_format($total_possible_weighted, 4, '.', '') ?>;

const catMeta = {};

<?php foreach ($criteria_grouped as $category => $criteriaList):
    $slug = preg_replace('/\W+/', '-', strtolower($category));
    $categoryMax = 0.0;
    $ids = [];

    foreach ($criteriaList as $criterion) {
        $categoryMax +=
            (float)$criterion['max_score']
            * (float)($criterion['weight'] ?? 1);

        $ids[] = (int)$criterion['criteria_id'];
    }
?>
catMeta['<?= h($slug) ?>'] = {
    max: <?= number_format($categoryMax, 4, '.', '') ?>,
    ids: [<?= implode(',', $ids) ?>]
};
<?php endforeach; ?>

function updateSlider(slider) {
    const value = parseFloat(slider.value || '0');
    const max = parseFloat(slider.dataset.max || '0');

    const match = slider.name.match(/\[(\d+)\]/);

    if (match) {
        const badge = document.getElementById(
            'badge-' + match[1]
        );

        if (badge) {
            badge.textContent =
                Math.round(value)
                + '/'
                + Math.round(max);
        }
    }

    recalcAll();
}

function recalcAll() {
    let grandWeighted = 0;

    Object.entries(catMeta).forEach(function (entry) {
        const slug = entry[0];
        const meta = entry[1];

        let categoryWeighted = 0;

        meta.ids.forEach(function (id) {
            const slider = document.querySelector(
                'input[name="criteria[' + id + ']"]'
            );

            if (!slider) return;

            const score = parseFloat(slider.value || '0');
            const weight = parseFloat(
                slider.dataset.weight || '1'
            );

            categoryWeighted += score * weight;
        });

        categoryWeighted = Math.max(
            0,
            Math.min(meta.max, categoryWeighted)
        );

        const display = document.getElementById(
            'cat-display-' + slug
        );

        if (display) {
            display.textContent =
                categoryWeighted.toFixed(1)
                + ' / '
                + meta.max.toFixed(1);
        }

        grandWeighted += categoryWeighted;
    });

    grandWeighted = Math.max(
        0,
        Math.min(totalPossibleWeighted, grandWeighted)
    );

    const overallPercent =
        totalPossibleWeighted > 0
            ? (grandWeighted / totalPossibleWeighted) * 100
            : 0;

    const overallText = document.getElementById(
        'overall-text'
    );

    const overallLabel = document.getElementById(
        'overall-label'
    );

    const hiddenOverall = document.getElementById(
        'hidden-overall'
    );

    const circle = document.getElementById(
        'overall-circle'
    );

    if (overallText) {
        overallText.textContent =
            overallPercent.toFixed(1) + '%';
    }

    if (overallLabel) {
        overallLabel.textContent =
            grandWeighted.toFixed(1)
            + ' / '
            + totalPossibleWeighted.toFixed(1)
            + ' weighted pts';
    }

    if (hiddenOverall) {
        hiddenOverall.value =
            overallPercent.toFixed(1);
    }

    if (circle) {
        circle.style.setProperty(
            '--pct',
            overallPercent.toFixed(1) + '%'
        );
    }
}

/*
|--------------------------------------------------------------------------
| LEFT READER ACCORDION
|--------------------------------------------------------------------------
| Only one application section stays open at a time.
|--------------------------------------------------------------------------
*/
document.querySelectorAll('.reader-card').forEach(function (card) {
    const header = card.querySelector('.reader-card-header');

    if (!header) return;

    header.addEventListener('click', function () {
        const opening = !card.classList.contains('open');

        document.querySelectorAll('.reader-card').forEach(function (other) {
            other.classList.remove('open');
        });

        if (opening) {
            card.classList.add('open');
        }
    });
});


/*
|--------------------------------------------------------------------------
| RIGHT-SIDE REVIEW CRITERIA ACCORDION
|--------------------------------------------------------------------------
| Review Details stays visible. Scoring categories are compact list cards.
| Only one scoring category opens at a time.
|--------------------------------------------------------------------------
*/
document.querySelectorAll('.review-list-card').forEach(function (card) {
    const header = card.querySelector('.review-list-head');

    if (!header) return;

    header.addEventListener('click', function () {
        const opening = !card.classList.contains('open');

        document.querySelectorAll('.review-list-card').forEach(function (other) {
            other.classList.remove('open');

            const otherHeader = other.querySelector('.review-list-head');
            if (otherHeader) {
                otherHeader.setAttribute('aria-expanded', 'false');
            }
        });

        if (opening) {
            card.classList.add('open');
            header.setAttribute('aria-expanded', 'true');
        }
    });
});


document.querySelectorAll('.score-slider').forEach(function (slider) {
    const value = parseFloat(slider.value || '0');
    const max = parseFloat(slider.dataset.max || '0');

    const match = slider.name.match(/\[(\d+)\]/);

    if (match) {
        const badge = document.getElementById(
            'badge-' + match[1]
        );

        if (badge) {
            badge.textContent =
                Math.round(value)
                + '/'
                + Math.round(max);
        }
    }
});

recalcAll();


let formDirty = false;

const reviewForm = document.getElementById('review-form');

if (reviewForm) {
    reviewForm.addEventListener('change', function () {
        formDirty = true;
    });

    reviewForm.addEventListener('submit', function () {
        formDirty = false;
    });
}

window.addEventListener('beforeunload', function (event) {
    if (!formDirty) return;

    event.preventDefault();
    event.returnValue = '';
});
</script>

<?php
require_once 'includes/footer.php';
ob_end_flush();
?>
