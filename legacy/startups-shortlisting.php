<?php
declare(strict_types=1);

ob_start();
//session_start();

date_default_timezone_set('Africa/Nairobi');

require_once 'includes/config.php';

$page_title = 'Shortlisting';

if (!ims_allowed('shortlisting.view', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer', 'Program Manager', 'Program Director'], 'Open the startups shortlisting page')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard');
    exit();
}

require_once 'includes/header.php';

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeStatus(?string $status): string
{
    $status = strtolower(trim((string)$status));
    $status = preg_replace('/\s+/', ' ', $status);

    return match ($status) {
        'accepted', 'accept'      => 'accepted',
        'shortlisted', 'shortlist'=> 'shortlisted',
        'rejected', 'reject'      => 'rejected',
        'withdrawn', 'withdraw'   => 'withdrawn',
        'reviewed'                => 'reviewed',
        default                   => 'pending',
    };
}

function prettyStatus(?string $status): string
{
    return match (normalizeStatus($status)) {
        'accepted'    => 'Accepted',
        'shortlisted' => 'Shortlisted',
        'rejected'    => 'Rejected',
        'withdrawn'   => 'Withdrawn',
        'reviewed'    => 'Reviewed',
        default       => 'Pending',
    };
}

function recommendationClass(?string $recommendation): string
{
    $rec = strtolower(trim((string)$recommendation));

    return match ($rec) {
        'accept'    => 'accept',
        'shortlist' => 'shortlist',
        'reject'    => 'reject',
        default     => 'nmi',
    };
}

function formatNairobiDateTime(?string $datetime): string
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return 'N/A';
    }

    try {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime, new DateTimeZone('Africa/Nairobi'));
        if (!$dt) {
            $dt = new DateTime($datetime, new DateTimeZone('Africa/Nairobi'));
        }
        return $dt->format('d M Y \a\t h:i A');
    } catch (Throwable $e) {
        return (string)$datetime;
    }
}

function fetchAllAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $err);
    }

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();
    return $rows;
}

function fetchOneAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = fetchAllAssoc($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

/* =============================================================================
   FILTERS
============================================================================= */
$filter_opp = (int)($_GET['opportunity_id'] ?? 0);
$filter_review_type = isset($_GET['review_type_id']) && $_GET['review_type_id'] !== ''
    ? (int)$_GET['review_type_id']
    : 0;
$filter_status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$filter_rec = trim((string)($_GET['recommendation'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$sort = trim((string)($_GET['sort'] ?? 'score_desc'));
if (!in_array($sort, ['score_desc', 'score_asc', 'name_asc', 'date_desc'], true)) {
    $sort = 'score_desc';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? 10);
if (!in_array($per_page, [5, 10, 20, 50], true)) {
    $per_page = 10;
}
$offset = ($page - 1) * $per_page;

/* =============================================================================
   OPPORTUNITIES
============================================================================= */
$opportunities = fetchAllAssoc($conn, "
    SELECT DISTINCT ao.opportunity_id, ao.opportunity_title
    FROM application_opportunities ao
    INNER JOIN applications a ON a.opportunity_id = ao.opportunity_id
    ORDER BY ao.opportunity_title ASC
");

/* =============================================================================
   REVIEW TYPES
============================================================================= */
$review_types = fetchAllAssoc($conn, "
    SELECT review_type_id, name
    FROM review_types
    WHERE is_active = 1
    ORDER BY sort_order DESC, review_type_id ASC, name ASC
");

if ($filter_review_type <= 0 && !empty($review_types)) {
    $filter_review_type = (int)$review_types[0]['review_type_id'];
}

/* =============================================================================
   BASE QUERY
============================================================================= */
$baseFrom = "
    FROM applications a
    INNER JOIN application_opportunities ao
        ON ao.opportunity_id = a.opportunity_id
    LEFT JOIN users u
        ON u.user_id = a.submitted_by
    LEFT JOIN review_types rt
        ON rt.review_type_id = a.review_type_id

    LEFT JOIN (
        SELECT
            reviewer_set.application_id,
            reviewer_set.review_type_id,
            COUNT(*) AS review_count,
            ROUND(AVG(reviewer_set.review_percent), 1) AS avg_score,
            ROUND(MAX(reviewer_set.review_percent), 1) AS max_score,
            ROUND(MIN(reviewer_set.review_percent), 1) AS min_score,
            ROUND(AVG(reviewer_set.earned_points), 2) AS avg_points,
            ROUND(MAX(reviewer_set.earned_points), 2) AS max_points,
            ROUND(MIN(reviewer_set.earned_points), 2) AS min_points,
            ROUND(MAX(reviewer_set.possible_points), 2) AS possible_points
        FROM (
            SELECT
                rs.application_id,
                rs.review_type_id,
                rs.reviewer_id,
                SUM(rs.score * COALESCE(rc.weight, 1)) AS earned_points,
                SUM(rc.max_score * COALESCE(rc.weight, 1)) AS possible_points,
                (
                    SUM(rs.score * COALESCE(rc.weight, 1))
                    / NULLIF(SUM(rc.max_score * COALESCE(rc.weight, 1)), 0)
                ) * 100 AS review_percent
            FROM review_scores rs
            INNER JOIN review_criteria rc
                ON rc.criteria_id = rs.criteria_id
            WHERE rc.is_active = 1
              AND rs.review_type_id IS NOT NULL
            GROUP BY rs.application_id, rs.review_type_id, rs.reviewer_id
        ) reviewer_set
        GROUP BY reviewer_set.application_id, reviewer_set.review_type_id
    ) agg
        ON agg.application_id = a.application_id
       AND agg.review_type_id = a.review_type_id

    LEFT JOIN (
        SELECT
            ar.application_id,
            ar.review_type_id,
            SUM(ar.recommendation = 'Accept') AS accept_count,
            SUM(ar.recommendation = 'Shortlist') AS shortlist_count,
            SUM(ar.recommendation = 'Reject') AS reject_count,
            SUM(ar.recommendation = 'Needs More Info') AS nmi_count,
            (
                SELECT ar2.recommendation
                FROM application_reviews ar2
                WHERE ar2.application_id = ar.application_id
                  AND ar2.review_type_id = ar.review_type_id
                GROUP BY ar2.recommendation
                ORDER BY COUNT(*) DESC, ar2.recommendation ASC
                LIMIT 1
            ) AS top_rec
        FROM application_reviews ar
        WHERE ar.review_type_id IS NOT NULL
        GROUP BY ar.application_id, ar.review_type_id
    ) recagg
        ON recagg.application_id = a.application_id
       AND recagg.review_type_id = a.review_type_id
";

$where  = [];
$params = [];
$types  = '';

if ($filter_opp > 0) {
    $where[] = 'ao.opportunity_id = ?';
    $types .= 'i';
    $params[] = $filter_opp;
}

if ($filter_review_type > 0) {
    $where[] = 'a.review_type_id = ?';
    $types .= 'i';
    $params[] = $filter_review_type;
}

switch ($filter_status) {
    case 'shortlisted':
        $where[] = "UPPER(TRIM(COALESCE(a.status, ''))) = 'SHORTLISTED'";
        break;
    case 'accepted':
        $where[] = "UPPER(TRIM(COALESCE(a.status, ''))) = 'ACCEPTED'";
        break;
    case 'reviewed':
        $where[] = 'COALESCE(agg.review_count, 0) > 0';
        break;
    case 'unreviewed':
        $where[] = 'COALESCE(agg.review_count, 0) = 0';
        break;
    case 'all':
    default:
        break;
}

if ($filter_rec !== '') {
    $where[] = "COALESCE(recagg.top_rec, 'Needs More Info') = ?";
    $types .= 's';
    $params[] = $filter_rec;
}

if ($q !== '') {
    $where[] = "(
        a.startup_name LIKE ?
        OR COALESCE(u.full_name, '') LIKE ?
        OR COALESCE(a.email, '') LIKE ?
        OR COALESCE(rt.name, '') LIKE ?
    )";
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$order_sql = match ($sort) {
    'score_asc' => "
        CASE WHEN a.review_type_id IS NULL THEN 1 ELSE 0 END ASC,
        COALESCE(rt.name, '') ASC,
        COALESCE(agg.avg_score, 0) ASC,
        a.startup_name ASC
    ",
    'name_asc' => "
        CASE WHEN a.review_type_id IS NULL THEN 1 ELSE 0 END ASC,
        COALESCE(rt.name, '') ASC,
        a.startup_name ASC
    ",
    'date_desc' => "
        CASE WHEN a.review_type_id IS NULL THEN 1 ELSE 0 END ASC,
        COALESCE(rt.name, '') ASC,
        a.submitted_at DESC
    ",
    default => "
        CASE WHEN a.review_type_id IS NULL THEN 1 ELSE 0 END ASC,
        COALESCE(rt.name, '') ASC,
        COALESCE(agg.avg_score, 0) DESC,
        a.startup_name ASC
    ",
};

/* =============================================================================
   COUNT
============================================================================= */
$count_sql = "
    SELECT COUNT(*) AS total_rows
    {$baseFrom}
    {$where_sql}
";
$count_row = fetchOneAssoc($conn, $count_sql, $types, $params);
$total_rows = (int)($count_row['total_rows'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/* =============================================================================
   MAIN DATA
============================================================================= */
$sql = "
    SELECT
        a.application_id,
        a.startup_name,
        a.sector,
        a.business_stage,
        a.team_size,
        a.status,
        a.submitted_at,
        a.contact_person,
        a.email,
        a.phone,
        a.funding_sought,
        a.reminder_date,
        a.reviewer_notes,
        a.review_type_id,
        ao.opportunity_title,
        ao.opportunity_id,
        ao.opportunity_type,
        COALESCE(u.full_name, 'N/A') AS applicant_name,
        COALESCE(rt.name, 'Unassigned') AS review_type_name,
        COALESCE(agg.review_count, 0) AS review_count,
        COALESCE(agg.avg_score, 0) AS avg_score,
        COALESCE(agg.max_score, 0) AS max_score,
        COALESCE(agg.min_score, 0) AS min_score,
        COALESCE(agg.avg_points, 0) AS avg_points,
        COALESCE(agg.max_points, 0) AS max_points,
        COALESCE(agg.min_points, 0) AS min_points,
        COALESCE(agg.possible_points, 0) AS possible_points,
        COALESCE(recagg.top_rec, 'Needs More Info') AS top_rec,
        COALESCE(recagg.accept_count, 0) AS accept_count,
        COALESCE(recagg.shortlist_count, 0) AS shortlist_count,
        COALESCE(recagg.reject_count, 0) AS reject_count,
        COALESCE(recagg.nmi_count, 0) AS nmi_count
    {$baseFrom}
    {$where_sql}
    ORDER BY {$order_sql}
    LIMIT ? OFFSET ?
";

$main_params = $params;
$main_types  = $types . 'ii';
$main_params[] = $per_page;
$main_params[] = $offset;

$applications = fetchAllAssoc($conn, $sql, $main_types, $main_params);

/* =============================================================================
   CURRENT-STAGE DETAIL DATA
============================================================================= */
$criteriaByEntry = [];
$reviewsByEntry  = [];
$historyByEntry  = [];

$appStageMap    = [];
$applicationIds = [];
$reviewTypeIds  = [];

foreach ($applications as $appRow) {
    $appId = (int)($appRow['application_id'] ?? 0);
    $rtId  = (int)($appRow['review_type_id'] ?? 0);

    if ($appId > 0) {
        $applicationIds[] = $appId;
    }

    if ($appId > 0 && $rtId > 0) {
        $appStageMap[$appId] = $rtId;
        $reviewTypeIds[] = $rtId;
    }
}

$applicationIds = array_values(array_unique($applicationIds));
$reviewTypeIds  = array_values(array_unique($reviewTypeIds));

if (!empty($applicationIds) && !empty($reviewTypeIds)) {
    $appPlaceholders = implode(',', array_fill(0, count($applicationIds), '?'));
    $rtPlaceholders  = implode(',', array_fill(0, count($reviewTypeIds), '?'));
    $criteriaTypes   = str_repeat('i', count($applicationIds) + count($reviewTypeIds));
    $criteriaParams  = array_merge($applicationIds, $reviewTypeIds);

    $criteriaSql = "
        SELECT
            rs.application_id,
            rs.review_type_id,
            rc.criteria_id,
            rc.category,
            rc.question,
            rc.description,
            rc.max_score,
            rc.weight,
            ROUND(AVG(rs.score), 2) AS avg_score,
            COUNT(rs.score_id) AS response_count
        FROM review_scores rs
        INNER JOIN review_criteria rc
            ON rc.criteria_id = rs.criteria_id
        WHERE rs.application_id IN ({$appPlaceholders})
          AND rs.review_type_id IN ({$rtPlaceholders})
          AND rc.is_active = 1
        GROUP BY
            rs.application_id,
            rs.review_type_id,
            rc.criteria_id,
            rc.category,
            rc.question,
            rc.description,
            rc.max_score,
            rc.weight
        ORDER BY rs.review_type_id DESC, rc.category ASC, rc.criteria_id ASC
    ";

    $criteriaRows = fetchAllAssoc($conn, $criteriaSql, $criteriaTypes, $criteriaParams);

    foreach ($criteriaRows as $row) {
        $appId = (int)$row['application_id'];
        $rtId  = (int)$row['review_type_id'];

        if (($appStageMap[$appId] ?? 0) !== $rtId) {
            continue;
        }

        $criteriaByEntry[$appId . '_' . $rtId][] = $row;
    }

    $reviewSql = "
        SELECT
            x.application_id,
            x.review_type_id,
            x.reviewer_id,
            COALESCE(u.full_name, CONCAT('User #', x.reviewer_id)) AS reviewer_name,
            ROUND(x.review_percent, 2) AS overall_score,
            COALESCE(ar.recommendation, 'Needs More Info') AS recommendation,
            COALESCE(NULLIF(TRIM(ar.comments), ''), NULLIF(TRIM(x.score_comments), '')) AS comments,
            COALESCE(ar.created_at, x.last_scored_at) AS created_at
        FROM (
            SELECT
                rs.application_id,
                rs.review_type_id,
                rs.reviewer_id,
                (
                    SUM(rs.score * COALESCE(rc.weight, 1))
                    / NULLIF(SUM(rc.max_score * COALESCE(rc.weight, 1)), 0)
                ) * 100 AS review_percent,
                GROUP_CONCAT(DISTINCT NULLIF(TRIM(rs.comments), '') SEPARATOR '\n\n') AS score_comments,
                MAX(COALESCE(rs.updated_at, rs.scored_at, rs.created_at)) AS last_scored_at
            FROM review_scores rs
            INNER JOIN review_criteria rc
                ON rc.criteria_id = rs.criteria_id
            WHERE rs.application_id IN ({$appPlaceholders})
              AND rs.review_type_id IN ({$rtPlaceholders})
              AND rc.is_active = 1
            GROUP BY rs.application_id, rs.review_type_id, rs.reviewer_id
        ) x
        LEFT JOIN users u
            ON u.user_id = x.reviewer_id
        LEFT JOIN application_reviews ar
            ON ar.application_id = x.application_id
           AND ar.review_type_id = x.review_type_id
           AND ar.reviewer_id = x.reviewer_id
        ORDER BY x.review_type_id DESC, x.review_percent DESC, created_at DESC
    ";

    $reviewRows = fetchAllAssoc($conn, $reviewSql, $criteriaTypes, $criteriaParams);

    foreach ($reviewRows as $row) {
        $appId = (int)$row['application_id'];
        $rtId  = (int)$row['review_type_id'];

        if (($appStageMap[$appId] ?? 0) !== $rtId) {
            continue;
        }

        $row['created_at_formatted'] = formatNairobiDateTime($row['created_at'] ?? null);
        $reviewsByEntry[$appId . '_' . $rtId][] = $row;
    }

    $historySql = "
        SELECT
            h.id,
            h.application_id,
            h.review_type_id,
            h.previous_status,
            h.new_status,
            h.reviewer_notes,
            h.reminder_date,
            h.action_type,
            h.acted_at,
            COALESCE(u.full_name, CONCAT('User #', h.acted_by)) AS actor_name
        FROM application_shortlisting_history h
        LEFT JOIN users u
            ON u.user_id = h.acted_by
        WHERE h.application_id IN ({$appPlaceholders})
          AND (h.review_type_id IN ({$rtPlaceholders}) OR h.review_type_id IS NULL)
        ORDER BY h.acted_at DESC, h.id DESC
    ";

    $historyRows = fetchAllAssoc($conn, $historySql, $criteriaTypes, $criteriaParams);

    foreach ($historyRows as $row) {
        $appId = (int)$row['application_id'];
        $rtId  = (int)($row['review_type_id'] ?? 0);

        if ($rtId > 0 && ($appStageMap[$appId] ?? 0) !== $rtId) {
            continue;
        }

        $entryKey = $appId . '_' . (($rtId > 0) ? $rtId : ($appStageMap[$appId] ?? 0));
        $row['acted_at_formatted'] = formatNairobiDateTime($row['acted_at'] ?? null);
        $historyByEntry[$entryKey][] = $row;
    }
}

/* =============================================================================
   STATS
============================================================================= */
$total         = $total_rows;
$shortlisted_n = 0;
$accepted_n    = 0;
$avg_score_all = 0.0;

if ($total > 0) {
    $stat_sql = "
        SELECT
            COUNT(*) AS applicants_n,
            SUM(CASE WHEN UPPER(TRIM(COALESCE(a.status, ''))) = 'SHORTLISTED' THEN 1 ELSE 0 END) AS shortlisted_n,
            SUM(CASE WHEN UPPER(TRIM(COALESCE(a.status, ''))) = 'ACCEPTED' THEN 1 ELSE 0 END) AS accepted_n,
            ROUND(AVG(COALESCE(agg.avg_score, 0)), 1) AS avg_score_all
        {$baseFrom}
        {$where_sql}
    ";

    $statRow = fetchOneAssoc($conn, $stat_sql, $types, $params);
    $shortlisted_n = (int)($statRow['shortlisted_n'] ?? 0);
    $accepted_n    = (int)($statRow['accepted_n'] ?? 0);
    $avg_score_all = (float)($statRow['avg_score_all'] ?? 0);
}

$redirect_filters = h(http_build_query(array_filter([
    'opportunity_id' => $filter_opp,
    'review_type_id' => $filter_review_type,
    'status'         => $filter_status,
    'recommendation' => $filter_rec,
    'sort'           => $sort,
    'q'              => $q,
    'page'           => $page,
    'per_page'       => $per_page,
], fn($v) => $v !== '' && $v !== 0 && $v !== '0')));

function build_page_url(int $pageNo): string
{
    global $filter_opp, $filter_review_type, $filter_status, $filter_rec, $sort, $q, $per_page;

    $qs = http_build_query(array_filter([
        'opportunity_id' => $filter_opp,
        'review_type_id' => $filter_review_type,
        'status'         => $filter_status,
        'recommendation' => $filter_rec,
        'sort'           => $sort,
        'q'              => $q,
        'page'           => $pageNo,
        'per_page'       => $per_page,
    ], fn($v) => $v !== '' && $v !== 0 && $v !== '0'));

    return 'startups-shortlisting' . ($qs ? '?' . $qs : '');
}

/* =============================================================================
   PRESENTATION HELPERS
============================================================================= */
function slInitials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string)$name)) ?: [];
    $out = '';
    foreach ($parts as $part) {
        if ($part === '' || !preg_match('/[A-Za-z0-9]/', $part[0])) {
            continue;
        }
        $out .= strtoupper($part[0]);
        if (strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : '?';
}

function slScoreTier(float $pct, int $reviewCount): string
{
    if ($reviewCount <= 0) {
        return 'none';
    }
    return $pct >= 70 ? 'high' : ($pct >= 45 ? 'mid' : 'low');
}

$has_active_filters = ($filter_opp || $filter_review_type || $filter_rec || $filter_status !== 'all' || $q !== '' || $per_page !== 10);
?>
<link rel="stylesheet" href="css/startups-shortlisting.css">

<?php
// Page status -> global .status-pill modifier (global has no shortlisted /
// accepted / reviewed / withdrawn variants, so map to the nearest ones).
$slPillMap = [
    'accepted'    => 'approved',
    'shortlisted' => 'carried-over',
    'rejected'    => 'rejected',
    'withdrawn'   => 'draft',
    'reviewed'    => 'in-progress',
    'pending'     => 'pending',
];
// recommendationClass() -> global badge modifier.
$slRecBadge = [
    'accept'    => 'badge-success',
    'shortlist' => 'badge-purple',
    'reject'    => 'badge-danger',
    'nmi'       => 'badge-warning',
];
?>

<div class="page-stack sl-page">

        <!-- Hero -->
        <div class="page-hero">
            <div>
                <h1><i class="fas fa-list-check" aria-hidden="true"></i> Shortlisting Panel</h1>
                <p>Review scores by review type, compare applicants, and make shortlisting decisions.</p>
            </div>
            <div class="hero-actions">
                <a href="manage-reviewers?tab=status" class="btn btn-primary">
                    <i class="fas fa-user-check" aria-hidden="true"></i> Reviewer Status
                </a>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success" id="flashAlert" role="status">
                <i class="fas fa-check-circle" aria-hidden="true"></i> <?= h($success_msg) ?>
            </div>
        <?php elseif ($error_msg): ?>
            <div class="alert alert-danger" id="flashAlert" role="alert">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= h($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid reports-stats">
            <div class="stat-card">
                <div class="stat-icon bg-blue"><i class="fas fa-users" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Applicants</span>
                    <span class="stat-value"><?= $total ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-primary"><i class="fas fa-star" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Shortlisted</span>
                    <span class="stat-value"><?= $shortlisted_n ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Accepted</span>
                    <span class="stat-value"><?= $accepted_n ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-purple"><i class="fas fa-gauge-high" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Avg Score</span>
                    <span class="stat-value"><?= number_format($avg_score_all, 1) ?><span class="stat-unit">%</span></span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="card-body">
        <form method="GET" action="startups-shortlisting" id="filter-form" class="filters-bar" role="search" aria-label="Filter applicants">
                <div class="form-group filters-grow">
                    <label class="form-label" for="sl-q">Search</label>
                    <div class="search-input-wrap sl-search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="text" id="sl-q" name="q" placeholder="Search startups..." value="<?= h($q) ?>" class="form-control">
                        <button type="submit" class="sl-search-btn" aria-label="Search">
                            <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sl-opp">Opportunity</label>
                    <select id="sl-opp" name="opportunity_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Opportunities</option>
                        <?php foreach ($opportunities as $o): ?>
                            <option value="<?= (int)$o['opportunity_id'] ?>" <?= $filter_opp === (int)$o['opportunity_id'] ? 'selected' : '' ?>>
                                <?= h($o['opportunity_title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sl-rt">Review Type</label>
                    <select id="sl-rt" name="review_type_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Review Types</option>
                        <?php foreach ($review_types as $rt): ?>
                            <option value="<?= (int)$rt['review_type_id'] ?>" <?= $filter_review_type === (int)$rt['review_type_id'] ? 'selected' : '' ?>>
                                <?= h($rt['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sl-status">Status</label>
                    <select id="sl-status" name="status" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Applicants</option>
                        <option value="reviewed" <?= $filter_status === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                        <option value="unreviewed" <?= $filter_status === 'unreviewed' ? 'selected' : '' ?>>Not Yet Reviewed</option>
                        <option value="shortlisted" <?= $filter_status === 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                        <option value="accepted" <?= $filter_status === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sl-rec">Recommendation</label>
                    <select id="sl-rec" name="recommendation" class="form-control" onchange="this.form.submit()">
                        <option value="">All Recommendations</option>
                        <?php foreach (['Accept', 'Shortlist', 'Needs More Info', 'Reject'] as $r): ?>
                            <option value="<?= h($r) ?>" <?= $filter_rec === $r ? 'selected' : '' ?>>
                                <?= h($r) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sl-sort">Sort by</label>
                    <select id="sl-sort" name="sort" class="form-control" onchange="this.form.submit()">
                        <option value="score_desc" <?= $sort === 'score_desc' ? 'selected' : '' ?>>Highest Score First</option>
                        <option value="score_asc" <?= $sort === 'score_asc' ? 'selected' : '' ?>>Lowest Score First</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Newest First</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sl-pp">Show</label>
                    <select id="sl-pp" name="per_page" class="form-control" onchange="this.form.submit()">
                        <?php foreach ([5, 10, 20, 50] as $pp): ?>
                            <option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?> / page</option>
                        <?php endforeach; ?>
                    </select>
                </div>
        </form>
            </div>
        </div>

        <!-- Batch form: fields live here, checkboxes and buttons join it via form="batch-form" -->
        <form method="POST" action="includes/startups-process-shortlisting.php" id="batch-form" hidden>
            <input type="hidden" name="action" value="batch_update">
            <input type="hidden" name="redirect_filters" value="<?= $redirect_filters ?>">
        </form>

        <section class="sl-list" aria-labelledby="sl-list-title">
            <div class="sl-listbar">
                <div class="sl-listbar-left">
                    <h2 id="sl-list-title">Applications</h2>
                    <span class="badge badge-secondary"><?= $total ?> result<?= $total !== 1 ? 's' : '' ?></span>
                </div>
                <div class="sl-listbar-right">
                    <?php if (!empty($applications)): ?>
                        <label class="select-row" for="select-all">
                            <input type="checkbox" class="ch" id="select-all" onchange="toggleAll(this)">
                            <span>Select all on page</span>
                            <span id="selected-count" aria-live="polite"></span>
                        </label>
                    <?php endif; ?>
                    <?php if ($has_active_filters): ?>
                        <a href="startups-shortlisting" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times" aria-hidden="true"></i> Clear filters
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($applications)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-inbox" aria-hidden="true"></i>
                        <h4>No applications match these filters</h4>
                        <p>Try adjusting your filters above or wait for reviewers to complete their assessments.</p>
                        <?php if ($has_active_filters): ?>
                            <a href="startups-shortlisting" class="btn btn-secondary btn-sm">
                                <i class="fas fa-rotate-left" aria-hidden="true"></i> Reset filters
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($applications as $app): ?>
                    <?php
                    $aid         = (int)$app['application_id'];
                    $rtid        = isset($app['review_type_id']) ? (int)$app['review_type_id'] : 0;
                    $entryKey    = $aid . '_' . $rtid;
                    $cardKey     = $aid . '-' . $rtid;
                    $status_cls  = normalizeStatus($app['status'] ?? 'Pending');
                    $status_text = prettyStatus($app['status'] ?? 'Pending');
                    $pct         = max(0, min(100, round((float)$app['avg_score'])));
                    $review_n    = (int)$app['review_count'];
                    $score_tier  = slScoreTier((float)$pct, $review_n);
                    $reviews     = ($rtid > 0) ? ($reviewsByEntry[$entryKey] ?? []) : [];
                    $criteria    = ($rtid > 0) ? ($criteriaByEntry[$entryKey] ?? []) : [];
                    $historyRows = ($rtid > 0) ? ($historyByEntry[$entryKey] ?? []) : [];
                    $notes_count = count(array_filter($reviews, fn($r) => trim((string)($r['comments'] ?? '')) !== ''));
                    $has_notes   = $notes_count > 0;
                    $top_rec     = (string)($app['top_rec'] ?? 'Needs More Info');
                    $con_cls     = recommendationClass($top_rec);
                    $has_tally   = ((int)$app['accept_count'] + (int)$app['shortlist_count'] + (int)$app['nmi_count'] + (int)$app['reject_count']) > 0;
                    ?>
                    <article class="app-card" id="card-<?= $aid ?>-<?= $rtid ?>" data-status="<?= h($status_cls) ?>" aria-labelledby="name-<?= $cardKey ?>">
                        <div class="card-top">
                            <label class="card-check" for="check-<?= $cardKey ?>">
                                <input
                                    type="checkbox"
                                    class="ch app-check"
                                    id="check-<?= $cardKey ?>"
                                    name="selected_entries[]"
                                    value="<?= $aid ?>:<?= $rtid ?>"
                                    form="batch-form"
                                    onchange="updateBatch()"
                                >
                                <span class="sr-only">Select <?= h($app['startup_name']) ?></span>
                            </label>

                            <div class="card-main-meta">
                                <div class="card-badges">
                                    <span class="status-pill status-<?= h($slPillMap[$status_cls] ?? 'pending') ?>"><?= h($status_text) ?></span>
                                    <span class="badge badge-secondary">
                                        <i class="fas fa-layer-group" aria-hidden="true"></i>
                                        <?= h($app['review_type_name']) ?>
                                    </span>
                                    <?php if ($review_n > 0): ?>
                                        <span class="badge <?= $slRecBadge[$con_cls] ?? 'badge-warning' ?>" title="Most common reviewer recommendation">
                                            <i class="fas fa-thumbs-up" aria-hidden="true"></i> Consensus: <?= h($top_rec) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="startup-name" id="name-<?= $cardKey ?>"><?= h($app['startup_name']) ?></h3>

                                <div class="startup-sub">
                                    <span><i class="fas fa-user" aria-hidden="true"></i> <?= h($app['applicant_name']) ?></span>
                                    <span><i class="fas fa-bullhorn" aria-hidden="true"></i> <?= h($app['opportunity_title']) ?></span>
                                </div>

                                <ul class="startup-meta" aria-label="Application facts">
                                    <li><i class="fas fa-industry" aria-hidden="true"></i> <?= h($app['sector'] ?? '-') ?></li>
                                    <li><i class="fas fa-seedling" aria-hidden="true"></i> <?= h($app['business_stage'] ?? '-') ?></li>
                                    <li><i class="fas fa-users" aria-hidden="true"></i> <?= (int)$app['team_size'] ?> members</li>
                                    <li><i class="fas fa-calendar" aria-hidden="true"></i> <?= !empty($app['submitted_at']) ? date('d M Y', strtotime($app['submitted_at'])) : '-' ?></li>
                                    <?php if (!empty($app['funding_sought'])): ?>
                                        <li><i class="fas fa-money-bill-wave" aria-hidden="true"></i> UGX <?= number_format((float)$app['funding_sought']) ?></li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <div class="score-cluster score-<?= $score_tier ?>">
                                <div class="score-ring" style="--pct:<?= $pct ?>;" role="img" aria-label="<?= $review_n > 0 ? 'Average score ' . number_format((float)$app['avg_score'], 1) . ' percent' : 'Not scored yet' ?>">
                                    <?php if ($review_n > 0): ?>
                                        <span class="score-num"><?= number_format((float)$app['avg_score'], 1) ?><small>%</small></span>
                                    <?php else: ?>
                                        <span class="score-num">&ndash;</span>
                                    <?php endif; ?>
                                </div>
                                <div class="score-lbl">
                                    <span class="score-title">Avg score</span>
                                    <span><?= number_format((float)$app['avg_points'], 1) ?> / <?= number_format((float)$app['possible_points'], 1) ?> pts</span>
                                    <span><?= $review_n ?> review<?= $review_n !== 1 ? 's' : '' ?></span>
                                    <?php if ($review_n > 1): ?>
                                        <span class="score-range">Range <?= number_format((float)$app['min_score'], 0) ?>&ndash;<?= number_format((float)$app['max_score'], 0) ?>%</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card-reviews">
                            <?php if ($has_tally): ?>
                                <div class="rec-tally" aria-label="Recommendation tally">
                                    <?php if ((int)$app['accept_count'] > 0): ?>
                                        <span class="badge badge-success"><i class="fas fa-check" aria-hidden="true"></i> <?= (int)$app['accept_count'] ?> Accept</span>
                                    <?php endif; ?>
                                    <?php if ((int)$app['shortlist_count'] > 0): ?>
                                        <span class="badge badge-purple"><i class="fas fa-star" aria-hidden="true"></i> <?= (int)$app['shortlist_count'] ?> Shortlist</span>
                                    <?php endif; ?>
                                    <?php if ((int)$app['nmi_count'] > 0): ?>
                                        <span class="badge badge-warning"><i class="fas fa-circle-info" aria-hidden="true"></i> <?= (int)$app['nmi_count'] ?> Needs Info</span>
                                    <?php endif; ?>
                                    <?php if ((int)$app['reject_count'] > 0): ?>
                                        <span class="badge badge-danger"><i class="fas fa-times" aria-hidden="true"></i> <?= (int)$app['reject_count'] ?> Reject</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($reviews)): ?>
                                <ul class="reviewers-wrap" aria-label="Reviewers">
                                    <?php foreach ($reviews as $rv): ?>
                                        <?php $rec = (string)($rv['recommendation'] ?? 'Needs More Info'); ?>
                                        <li class="reviewer-pill">
                                            <span class="reviewer-avatar" aria-hidden="true"><?= h(slInitials($rv['reviewer_name'] ?? '')) ?></span>
                                            <span class="reviewer-name"><?= h($rv['reviewer_name']) ?></span>
                                            <span class="rp-score"><?= number_format((float)($rv['overall_score'] ?? 0), 1) ?>%</span>
                                            <span class="badge <?= $slRecBadge[recommendationClass($rec)] ?? 'badge-warning' ?>"><?= h($rec) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php elseif (!$has_tally): ?>
                                <p class="reviews-empty"><i class="fas fa-hourglass-half" aria-hidden="true"></i> No reviews submitted for this stage yet.</p>
                            <?php endif; ?>
                        </div>

                        <div class="card-actions">
                            <div class="card-actions-main">
                                <?php if ($rtid > 0): ?>
                                    <a href="review-applicant?id=<?= $aid ?>&rt=<?= $rtid ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-star-half-alt" aria-hidden="true"></i> Review Applicant
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-primary btn-sm" aria-controls="assign-type-<?= $aid ?>-<?= $rtid ?>" aria-expanded="false" onclick="toggleAssignType('assign-type-<?= $aid ?>-<?= $rtid ?>')">
                                        <i class="fas fa-layer-group" aria-hidden="true"></i> Assign Review Type First
                                    </button>
                                <?php endif; ?>

                                <a href="view-application?id=<?= $aid ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye" aria-hidden="true"></i> View Application
                                </a>

                                <?php if ($rtid > 0): ?>
                                    <a href="risk_rating?id=<?= $aid ?>&rt=<?= $rtid ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-chart-line" aria-hidden="true"></i> Risk Rating
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="card-actions-more">
                                <button type="button" class="btn btn-secondary btn-sm" aria-controls="assign-type-<?= $aid ?>-<?= $rtid ?>" aria-expanded="false" onclick="toggleAssignType('assign-type-<?= $aid ?>-<?= $rtid ?>')">
                                    <i class="fas fa-layer-group" aria-hidden="true"></i> Assign Review Type
                                </button>

                                <a href="ai-review-applicant?id=<?= $aid ?>&rt=<?= $rtid ?>" class="btn btn-soft btn-sm">
                                    <i class="fas fa-robot" aria-hidden="true"></i> AI Review
                                </a>

                                <a href="ai-review-comparison?id=<?= $aid ?>&rt=<?= $rtid ?>" class="btn btn-soft btn-sm">
                                    <i class="fas fa-code-compare" aria-hidden="true"></i> AI Vs Human Review
                                </a>
                            </div>
                        </div>

                        <div id="assign-type-<?= $aid ?>-<?= $rtid ?>" class="assign-panel">
                            <form method="POST" action="includes/startups-process-shortlisting.php" class="assign-form">
                                <input type="hidden" name="action" value="assign_review_type">
                                <input type="hidden" name="application_id" value="<?= $aid ?>">
                                <input type="hidden" name="redirect_filters" value="<?= $redirect_filters ?>">

                                <div class="form-group assign-field">
                                    <label class="form-label" for="assign-select-<?= $cardKey ?>">Assign Review Type</label>
                                    <select name="review_type_id" id="assign-select-<?= $cardKey ?>" class="form-control" required>
                                        <option value="">Select review type</option>
                                        <?php foreach ($review_types as $rt): ?>
                                            <option value="<?= (int)$rt['review_type_id'] ?>" <?= $rtid === (int)$rt['review_type_id'] ? 'selected' : '' ?>>
                                                <?= h($rt['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-save" aria-hidden="true"></i> Save Review Type
                                </button>
                            </form>
                        </div>

                        <div class="card-tabs-wrap">
                            <div class="tab-nav" role="tablist" aria-label="<?= h($app['startup_name']) ?> details">
                                <button type="button" class="tab-btn active" role="tab" aria-selected="true" aria-controls="tab-status-<?= $aid ?>-<?= $rtid ?>" onclick="switchTab(this, 'tab-status-<?= $aid ?>-<?= $rtid ?>')">
                                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                                    <span>Status &amp; Reminder</span>
                                </button>

                                <button type="button" class="tab-btn" role="tab" aria-selected="false" aria-controls="tab-criteria-<?= $aid ?>-<?= $rtid ?>" onclick="switchTab(this, 'tab-criteria-<?= $aid ?>-<?= $rtid ?>')">
                                    <i class="fas fa-list" aria-hidden="true"></i>
                                    <span>Criteria</span>
                                    <?php if (!empty($criteria)): ?>
                                        <span class="tab-badge"><?= count($criteria) ?></span>
                                    <?php endif; ?>
                                </button>

                                <button type="button" class="tab-btn" role="tab" aria-selected="false" aria-controls="tab-details-<?= $aid ?>-<?= $rtid ?>" onclick="switchTab(this, 'tab-details-<?= $aid ?>-<?= $rtid ?>')">
                                    <i class="fas fa-file-lines" aria-hidden="true"></i>
                                    <span>Details</span>
                                </button>

                                <button type="button" class="tab-btn" role="tab" aria-selected="false" aria-controls="tab-history-<?= $aid ?>-<?= $rtid ?>" onclick="switchTab(this, 'tab-history-<?= $aid ?>-<?= $rtid ?>')">
                                    <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
                                    <span>History</span>
                                    <?php if (!empty($historyRows)): ?>
                                        <span class="tab-badge"><?= count($historyRows) ?></span>
                                    <?php endif; ?>
                                </button>
                            </div>

                            <div class="tab-panels">
                            <div class="tab-panel active" id="tab-status-<?= $aid ?>-<?= $rtid ?>" role="tabpanel">
                                <form method="POST" action="includes/startups-process-shortlisting.php" onsubmit="return confirmStatus(this)">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="application_id" value="<?= $aid ?>">
                                    <input type="hidden" name="review_type_id" value="<?= $rtid ?>">
                                    <input type="hidden" name="redirect_filters" value="<?= $redirect_filters ?>">

                                    <div class="tab-status-grid">
                                        <div class="form-group">
                                            <label class="form-label" for="status-<?= $cardKey ?>">Application Status</label>
                                            <select name="new_status" id="status-<?= $cardKey ?>" class="form-control">
                                                <?php foreach (['Pending', 'Shortlisted', 'Accepted', 'Rejected', 'Withdrawn'] as $s): ?>
                                                    <option value="<?= h($s) ?>" <?= strcasecmp(trim((string)($app['status'] ?? '')), $s) === 0 ? 'selected' : '' ?>>
                                                        <?= h($s) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label" for="reminder-<?= $cardKey ?>">Follow-up Reminder</label>
                                            <input type="date" name="reminder_date" id="reminder-<?= $cardKey ?>" class="form-control" value="<?= h($app['reminder_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>">
                                        </div>

                                        <div class="form-group sl-field-notes">
                                            <label class="form-label" for="decision-note-<?= $cardKey ?>">Decision Note</label>
                                            <textarea name="reviewer_notes" id="decision-note-<?= $cardKey ?>" class="form-control" placeholder="Add a decision note or reason (optional)..." rows="2"><?= h($app['reviewer_notes'] ?? '') ?></textarea>
                                        </div>
                                    </div>

                                    <div class="tab-footer">
                                        <div>
                                            <?php if ($has_notes): ?>
                                                <button type="button" class="btn btn-info btn-sm notes-toggle-btn" aria-controls="notes-<?= $entryKey ?>" aria-expanded="false" onclick="toggleNotes('<?= $entryKey ?>', this)">
                                                    <i class="fas fa-comments" aria-hidden="true"></i>
                                                    Reviewer Notes
                                                    <span class="note-count">(<?= $notes_count ?>)</span>
                                                    <i class="fas fa-chevron-down notes-chevron" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <button type="submit" class="btn btn-dark btn-sm">
                                            <i class="fas fa-save" aria-hidden="true"></i> Save Status
                                        </button>
                                    </div>
                                </form>

                                <?php if ($has_notes): ?>
                                    <div class="notes-section" id="notes-<?= $entryKey ?>">
                                        <?php foreach ($reviews as $rv): ?>
                                            <?php $note = trim((string)($rv['comments'] ?? '')); ?>
                                            <?php if ($note === '') continue; ?>
                                            <div class="note-card">
                                                <div class="note-hdr">
                                                    <span class="note-author">
                                                        <span class="reviewer-avatar" aria-hidden="true"><?= h(slInitials($rv['reviewer_name'] ?? '')) ?></span>
                                                        <?= h($rv['reviewer_name']) ?>
                                                    </span>
                                                    <span class="note-meta">
                                                        <?= !empty($rv['created_at']) ? date('d M Y', strtotime($rv['created_at'])) : '-' ?>
                                                        &middot; <?= number_format((float)($rv['overall_score'] ?? 0), 1) ?>%
                                                        &middot; <?= h($rv['recommendation'] ?? '-') ?>
                                                    </span>
                                                </div>
                                                <div class="note-body"><?= nl2br(h($note)) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="tab-panel" id="tab-criteria-<?= $aid ?>-<?= $rtid ?>" role="tabpanel">
                                <?php if ($rtid <= 0): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-layer-group" aria-hidden="true"></i>
                                        <p>This applicant has not been assigned or reviewed under any review type yet.</p>
                                    </div>
                                <?php elseif (empty($criteria)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-list" aria-hidden="true"></i>
                                        <p>No criteria scores found for this review type.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table criteria-table table-stack">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Category</th>
                                                    <th scope="col">Criterion</th>
                                                    <th scope="col">Avg Score</th>
                                                    <th scope="col">Responses</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($criteria as $cr): ?>
                                                    <?php
                                                    $maxScore = (float)$cr['max_score'];
                                                    $avgScore = (float)$cr['avg_score'];
                                                    $pctScore = $maxScore > 0 ? max(0, min(100, round(($avgScore / $maxScore) * 100))) : 0;
                                                    $barTier  = slScoreTier((float)$pctScore, 1);
                                                    ?>
                                                    <tr>
                                                        <td data-label="Category"><span class="criteria-cat"><?= h($cr['category']) ?></span></td>
                                                        <td data-label="Criterion"><?= h($cr['question']) ?></td>
                                                        <td data-label="Avg Score">
                                                            <div class="criteria-score-box score-<?= $barTier ?>">
                                                                <div class="criteria-score-track" aria-hidden="true">
                                                                    <div class="criteria-score-fill" style="width:<?= $pctScore ?>%;"></div>
                                                                </div>
                                                                <span><?= number_format($avgScore, 2) ?> / <?= number_format($maxScore, 1) ?></span>
                                                            </div>
                                                        </td>
                                                        <td data-label="Responses"><?= (int)$cr['response_count'] ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="tab-panel" id="tab-details-<?= $aid ?>-<?= $rtid ?>" role="tabpanel">
                                <dl class="details-grid">
                                    <div><dt>Applicant Name</dt><dd><?= h($app['applicant_name'] ?? 'N/A') ?></dd></div>
                                    <div><dt>Startup Name</dt><dd><?= h($app['startup_name'] ?? '-') ?></dd></div>
                                    <div><dt>Opportunity</dt><dd><?= h($app['opportunity_title'] ?? '-') ?></dd></div>
                                    <div><dt>Review Type</dt><dd><?= h($app['review_type_name'] ?? 'Unassigned') ?></dd></div>
                                    <div><dt>Sector</dt><dd><?= h($app['sector'] ?? '-') ?></dd></div>
                                    <div><dt>Business Stage</dt><dd><?= h($app['business_stage'] ?? '-') ?></dd></div>
                                    <div><dt>Team Size</dt><dd><?= (int)($app['team_size'] ?? 0) ?></dd></div>
                                    <div><dt>Funding Sought</dt><dd>UGX <?= number_format((float)($app['funding_sought'] ?? 0)) ?></dd></div>
                                    <div><dt>Email</dt><dd><?= h($app['email'] ?? '-') ?></dd></div>
                                    <div><dt>Phone</dt><dd><?= h($app['phone'] ?? '-') ?></dd></div>
                                    <div><dt>Submitted At</dt><dd><?= !empty($app['submitted_at']) ? date('d M Y', strtotime($app['submitted_at'])) : '-' ?></dd></div>
                                    <div><dt>Current Status</dt><dd><span class="status-pill status-<?= h($slPillMap[$status_cls] ?? 'pending') ?>"><?= h($status_text) ?></span></dd></div>
                                </dl>
                            </div>

                            <div class="tab-panel" id="tab-history-<?= $aid ?>-<?= $rtid ?>" role="tabpanel">
                                <?php if (empty($historyRows)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
                                        <p>No decision history found for this application stage yet.</p>
                                    </div>
                                <?php else: ?>
                                    <ol class="history-timeline">
                                        <?php foreach ($historyRows as $hr): ?>
                                            <?php
                                            $prevStatus = trim((string)($hr['previous_status'] ?? ''));
                                            $newStatus  = trim((string)($hr['new_status'] ?? ''));
                                            $noteText   = trim((string)($hr['reviewer_notes'] ?? ''));
                                            $actionType = trim((string)($hr['action_type'] ?? 'single_update'));
                                            ?>
                                            <li class="history-item">
                                                <div class="history-dot" aria-hidden="true"></div>
                                                <div class="history-card">
                                                    <div class="history-head">
                                                        <div class="history-title">
                                                            <?php if ($actionType === 'assign_review_type'): ?>
                                                                <i class="fas fa-layer-group" aria-hidden="true"></i> Review Type Assignment
                                                            <?php elseif ($actionType === 'batch_update'): ?>
                                                                <i class="fas fa-layer-group" aria-hidden="true"></i> Batch Status Update
                                                            <?php else: ?>
                                                                <i class="fas fa-flag" aria-hidden="true"></i> Status Update
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="history-meta">
                                                            <?= h($hr['actor_name'] ?? 'System') ?> &middot; <?= h($hr['acted_at_formatted'] ?? 'N/A') ?>
                                                        </div>
                                                    </div>

                                                    <div class="history-body">
                                                        <div class="history-status-line">
                                                            <?php if ($prevStatus !== '' && strcasecmp($prevStatus, $newStatus) !== 0): ?>
                                                                <span class="status-pill status-<?= h($slPillMap[normalizeStatus($prevStatus)] ?? 'pending') ?>"><?= h(prettyStatus($prevStatus)) ?></span>
                                                                <span class="history-arrow"><i class="fas fa-arrow-right" aria-hidden="true"></i><span class="sr-only">changed to</span></span>
                                                            <?php endif; ?>

                                                            <span class="status-pill status-<?= h($slPillMap[normalizeStatus($newStatus)] ?? 'pending') ?>"><?= h(prettyStatus($newStatus)) ?></span>
                                                        </div>

                                                        <?php if (!empty($hr['reminder_date'])): ?>
                                                            <div class="history-extra">
                                                                <i class="fas fa-bell" aria-hidden="true"></i> <strong>Reminder:</strong> <?= h(date('d M Y', strtotime((string)$hr['reminder_date']))) ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if ($noteText !== ''): ?>
                                                            <div class="history-note"><?= nl2br(h($noteText)) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </div>
                            </div><!-- /.tab-panels -->
                        </div>
                    </article>
                <?php endforeach; ?>

                <nav class="pagination-wrap" aria-label="Applications pages">
                    <div class="pagination-info">
                        Showing <strong><?= $total_rows > 0 ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $per_page, $total_rows) ?></strong> of <strong><?= $total_rows ?></strong>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a class="page-link" href="<?= h(build_page_url($page - 1)) ?>" aria-label="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page   = min($total_pages, $page + 2);
                            for ($p = $start_page; $p <= $end_page; $p++):
                            ?>
                                <a class="page-link <?= $p === $page ? 'active' : '' ?>" href="<?= h(build_page_url($p)) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>>
                                    <?= $p ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a class="page-link" href="<?= h(build_page_url($page + 1)) ?>" aria-label="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>

        <div class="batch-bar" id="batch-bar" role="region" aria-label="Bulk actions">
            <span class="batch-count" id="batch-count" aria-live="polite">0 selected</span>
            <div class="batch-sep" aria-hidden="true"></div>
            <span class="batch-label">Move to:</span>

            <div class="batch-actions">
                <button type="submit" form="batch-form" name="batch_status" value="Shortlisted" class="btn btn-primary btn-sm">
                    <i class="fas fa-star" aria-hidden="true"></i> Shortlist
                </button>
                <button type="submit" form="batch-form" name="batch_status" value="Accepted" class="btn btn-green btn-sm">
                    <i class="fas fa-check-circle" aria-hidden="true"></i> Accept
                </button>
                <button type="submit" form="batch-form" name="batch_status" value="Rejected" class="btn btn-danger btn-sm">
                    <i class="fas fa-times-circle" aria-hidden="true"></i> Reject
                </button>
                <button type="submit" form="batch-form" name="batch_status" value="Pending" class="btn btn-secondary btn-sm batch-reset-btn">
                    <i class="fas fa-undo" aria-hidden="true"></i> Reset
                </button>
            </div>
        </div>
</div><!-- /.page-stack -->

<script>
function toggleAll(master) {
    const checks = document.querySelectorAll('.app-check');
    checks.forEach(cb => {
        cb.checked = master.checked;
    });
    updateBatch();
}

function updateBatch() {
    const checks = document.querySelectorAll('.app-check');
    const checked = document.querySelectorAll('.app-check:checked');
    const batchBar = document.getElementById('batch-bar');
    const countEl = document.getElementById('batch-count');
    const selectedCount = document.getElementById('selected-count');
    const selectAll = document.getElementById('select-all');

    if (countEl) {
        countEl.textContent = checked.length + ' selected';
    }

    if (selectedCount) {
        selectedCount.textContent = checked.length > 0 ? '(' + checked.length + ')' : '';
    }

    if (batchBar) {
        batchBar.classList.toggle('visible', checked.length > 0);
    }

    if (selectAll) {
        selectAll.checked = checks.length > 0 && checked.length === checks.length;
    }

    checks.forEach(cb => {
        const card = cb.closest('.app-card');
        if (card) card.classList.toggle('is-selected', cb.checked);
    });
}

function switchTab(btn, panelId) {
    const wrap = btn.closest('.card-tabs-wrap');
    if (!wrap) return;

    wrap.querySelectorAll('.tab-btn').forEach(el => {
        el.classList.remove('active');
        el.setAttribute('aria-selected', 'false');
    });
    wrap.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));

    btn.classList.add('active');
    btn.setAttribute('aria-selected', 'true');
    const panel = document.getElementById(panelId);
    if (panel) panel.classList.add('active');
}

function toggleAssignType(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
    const open = el.style.display === 'block';
    document.querySelectorAll('[aria-controls="' + id + '"]').forEach(b => b.setAttribute('aria-expanded', open ? 'true' : 'false'));
}

function toggleNotes(entryKey, btn) {
    const box = document.getElementById('notes-' + entryKey);
    if (!box) return;
    box.classList.toggle('open');
    if (btn) btn.setAttribute('aria-expanded', box.classList.contains('open') ? 'true' : 'false');
}

function confirmStatus(form) {
    const select = form.querySelector('select[name="new_status"]');
    if (!select) return true;
    return confirm('Save application status as "' + select.value + '"?');
}

document.addEventListener('DOMContentLoaded', function () {
    updateBatch();

    const flash = document.getElementById('flashAlert');
    if (flash) {
        setTimeout(() => {
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-8px)';
            setTimeout(() => flash.remove(), 300);
        }, 3500);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>