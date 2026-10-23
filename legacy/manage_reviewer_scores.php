<?php
$page_title = 'Manage Reviewer Scores';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth.php';

check_role(IMS_STAFF_ROLES); // was IMS_ALL_ROLES: applicants/members could read any application by id
/* -- Helpers --------------------------------------------------- */
if (!function_exists('e')) {
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function fetchA(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $st = $conn->prepare($sql);
    if (!$st) throw new mysqli_sql_exception($conn->error);
    if ($types !== '' && $params) $st->bind_param($types, ...$params);
    $st->execute();
    $res  = $st->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $st->close();
    return $rows;
}
function fetchO(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    return fetchA($conn, $sql, $types, $params)[0] ?? [];
}
function tw(string $text, int $limit = 20): string {
    $text  = trim(preg_replace('/\s+/', ' ', $text));
    $words = preg_split('/\s+/', $text) ?: [];
    return count($words) <= $limit ? $text : implode(' ', array_slice($words, 0, $limit)) . '...';
}
function sc(float $s): string { return $s >= 70 ? 'high'  : ($s >= 40 ? 'mid'    : 'low');   }
function pc(float $s): string { return $s >= 70 ? 'badge-success' : ($s >= 40 ? 'badge-warning' : 'badge-danger'); }
function pgRange(int $cur, int $total): array {
    $r = [];
    for ($i = max(1,$cur-2); $i <= min($total,$cur+2); $i++) $r[] = $i;
    if (($r[0]??1)>2)  array_unshift($r,'...');
    if (($r[0]??1)>1)  array_unshift($r,1);
    $last = end($r);
    if ($last < $total-1) $r[] = '...';
    if ($last < $total)   $r[] = $total;
    return $r;
}

/* -- Inputs ---------------------------------------------------- */
$reviewerId = max(0, (int)($_GET['reviewer_id'] ?? 0));
$perPage    = 10;
$search     = trim((string)($_GET['search'] ?? ''));

// Active tab = review_type_id (0 = "all" overview tab)
$activeTab  = max(0, (int)($_GET['tab'] ?? 0));
$page       = max(1, (int)($_GET['page'] ?? 1));

if ($reviewerId <= 0) {
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Invalid reviewer selected.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/* -- Reviewer -------------------------------------------------- */
$reviewer = fetchO($conn, "SELECT user_id, full_name, email FROM users WHERE user_id=? LIMIT 1", 'i', [$reviewerId]);
if (!$reviewer) {
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Reviewer not found.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/* -- Active review types for THIS reviewer --------------------- */
$reviewTypes = fetchA($conn,"
    SELECT DISTINCT rt.review_type_id, rt.name,
        COUNT(DISTINCT rs.application_id) AS startup_count
    FROM review_types rt
    JOIN review_scores rs ON rs.review_type_id = rt.review_type_id
    WHERE rt.is_active = 1 AND rs.reviewer_id = ?
    GROUP BY rt.review_type_id, rt.name
    ORDER BY rt.name ASC
",'i',[$reviewerId]);

// If no review types active for this reviewer default tab to "all"
$validTabIds = array_column($reviewTypes,'review_type_id');
if ($activeTab > 0 && !in_array($activeTab, $validTabIds, true)) $activeTab = 0;

/* -- Global KPI summary (all types) --------------------------- */
$globalSummary = fetchO($conn,"
    SELECT COUNT(DISTINCT rs.application_id) AS total_startups,
           COUNT(DISTINCT CONCAT(rs.application_id,'-',rs.review_type_id)) AS total_sets,
           COUNT(rs.score_id) AS total_criteria,
           AVG((rs.score/NULLIF(rc.max_score,0))*100) AS avg_pct
    FROM review_scores rs
    JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
    WHERE rs.reviewer_id=? AND rc.review_type_id=rs.review_type_id
",'i',[$reviewerId]);

$gStartups = (int)($globalSummary['total_startups'] ?? 0);
$gSets     = (int)($globalSummary['total_sets']     ?? 0);
$gCrit     = (int)($globalSummary['total_criteria'] ?? 0);
$gAvg      = (float)($globalSummary['avg_pct']      ?? 0);

/* -- Per-tab (current active type) data ----------------------- */
// Build filter SQL for the active tab
$fSql = '';
$fT   = 'i';
$fP   = [$reviewerId];

if ($activeTab > 0) {
    $fSql .= " AND rs.review_type_id=? ";
    $fT   .= 'i';
    $fP[]  = $activeTab;
}
if ($search !== '') {
    $fSql .= " AND (a.startup_name LIKE ? OR CAST(a.application_id AS CHAR) LIKE ?) ";
    $fT   .= 'ss';
    $sl    = '%'.$search.'%';
    $fP[]  = $sl;
    $fP[]  = $sl;
}

// Tab-level KPI (only when a specific type tab is active)
$tabSummary  = [];
$tabAvg      = 0.0;
if ($activeTab > 0) {
    $tabSummary = fetchO($conn,"
        SELECT COUNT(DISTINCT rs.application_id) AS total_startups,
               COUNT(DISTINCT CONCAT(rs.application_id,'-',rs.review_type_id)) AS total_sets,
               COUNT(rs.score_id) AS total_criteria,
               AVG((rs.score/NULLIF(rc.max_score,0))*100) AS avg_pct
        FROM review_scores rs
        JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
        WHERE rs.reviewer_id=? AND rs.review_type_id=? AND rc.review_type_id=rs.review_type_id
    ","ii",[$reviewerId,$activeTab]);
    $tabAvg = (float)($tabSummary['avg_pct'] ?? 0);
}

// Count
$countRow = fetchO($conn,"
    SELECT COUNT(*) AS total_rows FROM (
        SELECT rs.application_id,rs.review_type_id
        FROM review_scores rs
        JOIN applications a ON a.application_id=rs.application_id
        JOIN review_types rt ON rt.review_type_id=rs.review_type_id
        JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
        WHERE rs.reviewer_id=? {$fSql}
        GROUP BY rs.application_id,rs.review_type_id
    ) x
",$fT,$fP);

$totalRows  = (int)($countRow['total_rows'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Paged score rows
$sT = $fT.'ii';
$sP = array_merge($fP, [$perPage, $offset]);

$scoreRows = fetchA($conn,"
    SELECT rs.application_id, a.startup_name, a.sector, rs.review_type_id, rt.name AS rt_name,
           COUNT(rs.score_id) AS criteria_scored,
           SUM(rs.score*COALESCE(rc.weight,1)) AS earned,
           SUM(rc.max_score*COALESCE(rc.weight,1)) AS max_w,
           (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct,
           MIN(rs.updated_at) AS first_at, MAX(rs.updated_at) AS last_at
    FROM review_scores rs
    JOIN applications a ON a.application_id=rs.application_id
    JOIN review_types rt ON rt.review_type_id=rs.review_type_id
    JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
    WHERE rs.reviewer_id=? {$fSql}
    GROUP BY rs.application_id, a.startup_name, a.sector, rs.review_type_id, rt.name
    ORDER BY rt.name ASC, pct DESC, a.startup_name ASC
    LIMIT ? OFFSET ?
",$sT,$sP);

// Criteria breakdown for current page rows
$criteriaMap = [];
if (!empty($scoreRows)) {
    $conds = []; $cP = []; $cT = '';
    foreach ($scoreRows as $r) {
        $conds[] = "(rs.application_id=? AND rs.review_type_id=?)";
        $cP[] = (int)$r['application_id'];
        $cP[] = (int)$r['review_type_id'];
        $cT  .= 'ii';
    }
    $criteriaRows = fetchA($conn,"
        SELECT rs.application_id, rs.review_type_id,
               rc.criteria_id, rc.category, rc.question, rc.description,
               rc.max_score, rc.weight,
               rs.score, ((rs.score/NULLIF(rc.max_score,0))*100) AS cp,
               rs.comments, rs.updated_at
        FROM review_scores rs
        JOIN applications a ON a.application_id=rs.application_id
        JOIN review_types rt ON rt.review_type_id=rs.review_type_id
        JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
        WHERE rs.reviewer_id=? AND (".implode(' OR ',$conds).")
        ORDER BY rt.name ASC, a.startup_name ASC, rc.category ASC, rc.criteria_id ASC
    ","i".$cT,array_merge([$reviewerId],$cP));

    foreach ($criteriaRows as $r) {
        $criteriaMap[$r['review_type_id'].'_'.$r['application_id']][] = $r;
    }
}

/* -- URL helpers ----------------------------------------------- */
function tabUrl(int $rid, int $tabId, string $q = '', int $pg = 1): string {
    $p = ['reviewer_id' => $rid, 'tab' => $tabId, 'page' => $pg];
    if ($q !== '') $p['search'] = $q;
    return 'manage_reviewer_scores?' . http_build_query($p);
}
function pgUrl(int $rid, int $tabId, int $pg, string $q): string {
    return tabUrl($rid, $tabId, $q, $pg);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow   = min($offset + $perPage, $totalRows);

// Active tab label
$activeTabName = 'All Types';
foreach ($reviewTypes as $rt) {
    if ((int)$rt['review_type_id'] === $activeTab) { $activeTabName = $rt['name']; break; }
}
?>

<style>
/* -- manage_reviewer_scores page-specific layout -- */
.mrs-tab-nav .badge { padding: 1px 7px; font-size: 10px; }
.mrs-filters.filters-bar { margin-bottom: 0; }

/* Score tone helpers (text, bar fill and ring stroke) */
.mrs-tone-high { color: var(--green-fg); }
.mrs-tone-mid  { color: var(--amber-fg); }
.mrs-tone-low  { color: var(--red-fg); }

/* Score bar */
.mrs-bar { display: flex; align-items: center; gap: 8px; min-width: 110px; }
.mrs-bar.is-sm { min-width: 90px; }
.mrs-bar-track { flex: 1; height: 6px; background: var(--ink-100); border-radius: var(--radius-pill); overflow: hidden; }
.mrs-bar-fill { height: 100%; border-radius: var(--radius-pill); }
.mrs-bar-fill.high { background: var(--green-fg); }
.mrs-bar-fill.mid  { background: var(--amber-fg); }
.mrs-bar-fill.low  { background: var(--red-fg); }
.mrs-bar-val { font-size: 12px; font-weight: 700; color: var(--ink-700); white-space: nowrap; }
.mrs-bar.is-sm .mrs-bar-val { font-size: 11px; }
.mrs-num { font-size: 12px; font-variant-numeric: tabular-nums; }
.mrs-earned { margin-top: 3px; font-size: 11px; }
.mrs-mt-4 { margin-top: 4px; }

/* Tab-level summary strip */
.mrs-summary { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
.mrs-summary-item { display: flex; align-items: center; gap: 8px; }
.mrs-summary-item i { color: var(--brand-400); font-size: 13px; }
.mrs-summary-label { font-size: 13px; color: var(--ink-300); }
.mrs-summary-value { font-size: 15px; color: var(--ink-700); }
.mrs-summary-ring { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.mrs-ring-track { stroke: var(--ink-100); }
.mrs-ring-arc.high { stroke: var(--green-fg); }
.mrs-ring-arc.mid  { stroke: var(--amber-fg); }
.mrs-ring-arc.low  { stroke: var(--red-fg); }
.mrs-ring-label { font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-300); }
.mrs-ring-value { font-size: 18px; font-weight: 800; line-height: 1; }

/* Active filter chip + meta bar */
.mrs-chips { display: flex; gap: 8px; flex-wrap: wrap; padding: 0 22px 14px; }
.mrs-chips .badge a { color: inherit; text-decoration: none; margin-left: 2px; opacity: .7; }
.mrs-chips .badge a:hover { opacity: 1; }
.mrs-meta {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 10px 22px;
    background: var(--ink-50);
    border-top: 1px solid var(--ink-100);
    border-bottom: 1px solid var(--ink-100);
    font-size: 12px;
    color: var(--ink-400);
}
.mrs-meta strong { color: var(--ink-700); }

/* Criteria breakdown sub-table */
.mrs-breakdown-cell { padding: 0; background: var(--brand-50); }
.criteria-block { background: var(--brand-50); border-top: 1px solid var(--brand-100); padding: 16px 20px 20px; }
.criteria-block-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--ink-300);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.criteria-inner { background: var(--surface-card); border: 1px solid var(--ink-100); border-radius: var(--radius-md); overflow: hidden; }
.criteria-inner .table { min-width: 680px; }
.criteria-inner thead th { font-size: 10px; }
.criteria-inner tbody td { font-size: 12.5px; padding: 10px 14px; }
.mrs-comment { max-width: 220px; cursor: help; }

/* Tooltip */
.tip-cell { position: relative; max-width: 280px; cursor: help; line-height: 1.5; }
.tip-bubble {
    position: absolute;
    left: 0;
    top: calc(100% + 6px);
    width: 340px;
    max-width: 90vw;
    background: var(--ink-900);
    color: var(--ink-0);
    padding: 11px 14px;
    border-radius: var(--radius-md);
    font-size: 12px;
    line-height: 1.55;
    box-shadow: var(--shadow-lg);
    opacity: 0;
    visibility: hidden;
    transform: translateY(6px);
    transition: opacity .16s, transform .16s, visibility .16s;
    z-index: 200;
    white-space: normal;
    pointer-events: none;
}
.tip-bubble::before {
    content: '';
    position: absolute;
    top: -6px; left: 14px;
    border: 6px solid transparent;
    border-bottom-color: var(--ink-900);
    border-top: none;
}
.tip-cell:hover .tip-bubble { opacity: 1; visibility: visible; transform: translateY(0); }

/* Overview tab: per-type summary cards */
.type-summary-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
.type-summary-card {
    background: var(--surface-card);
    border: 1.5px solid var(--ink-100);
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transition: border-color .15s, box-shadow .15s;
    text-decoration: none;
    color: inherit;
}
.type-summary-card:hover { border-color: var(--brand-200); box-shadow: var(--shadow-md); }
.type-summary-card .tc-name { font-size: 14px; font-weight: 800; color: var(--ink-700); }
.type-summary-card .tc-meta { font-size: 12px; color: var(--ink-300); display: flex; gap: 12px; }
.type-summary-card .tc-meta i { color: var(--brand-400); margin-right: 4px; }
.type-summary-card .tc-score { font-size: 22px; font-weight: 800; line-height: 1; }
.type-summary-card .tc-link { font-size: 11px; color: var(--ink-300); }

/* Pagination row */
.mrs-pager { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 22px; border-top: 1px solid var(--ink-50); }
.mrs-pager .pagination { margin: 0; }

@media (max-width: 640px) {
    .criteria-block { padding: 12px 14px 16px; }
    .type-summary-grid { grid-template-columns: 1fr; }
    .mrs-summary-ring { margin-left: 0; }
}
</style>

<div class="page-stack">

    <!-- -- Page Hero ------------------------------------------------ -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-user-check" aria-hidden="true"></i>
                <?= e($reviewer['full_name'] ?: ('User #' . $reviewerId)) ?>
            </h1>
            <p>Reviewer Score Detail &mdash; <?= e($activeTabName) ?> &middot; Reviewer #<?= $reviewerId ?> &middot; <?= e($reviewer['email'] ?? '-') ?></p>
        </div>
        <div class="hero-actions">
            <a href="progress-board" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Report
            </a>
        </div>
    </div>

    <!-- -- Global KPI stats (always shown) ------------------------- -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-building"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total Startups</span>
                <span class="stat-value"><?= $gStartups ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info">
                <span class="stat-label">Review Sets</span>
                <span class="stat-value"><?= $gSets ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-list-check"></i></div>
            <div class="stat-info">
                <span class="stat-label">Criteria Scored</span>
                <span class="stat-value"><?= $gCrit ?></span>
            </div>
        </div>
        <!-- Overall avg -->
        <?php
        $ap     = round($gAvg);
        $apTone = sc((float)$ap);
        $apIcon = ['high' => 'bg-green', 'mid' => 'bg-amber', 'low' => 'bg-red'][$apTone];
        ?>
        <div class="stat-card">
            <div class="stat-icon <?= $apIcon ?>"><i class="fas fa-percent"></i></div>
            <div class="stat-info">
                <span class="stat-label">Overall Avg</span>
                <span class="stat-value mrs-tone-<?= $apTone ?>"><?= number_format($gAvg,1) ?>%</span>
                <span class="stat-sub">all review types</span>
            </div>
        </div>
    </div>

    <!-- -- Tab navigation ------------------------------------------- -->
    <div class="tab-shell">
    <nav class="tab-nav mrs-tab-nav" role="tablist">
        <!-- Overview tab -->
        <a href="<?= e(tabUrl($reviewerId, 0)) ?>"
           class="tab-btn<?= $activeTab === 0 ? ' active' : '' ?>"
           role="tab" aria-selected="<?= $activeTab === 0 ? 'true' : 'false' ?>">
            <i class="fas fa-gauge-high"></i> Overview
            <span class="badge badge-secondary"><?= count($reviewTypes) ?></span>
        </a>

        <!-- One tab per active review type this reviewer has scored -->
        <?php foreach ($reviewTypes as $rt):
            $rtId    = (int)$rt['review_type_id'];
            $isActive = $activeTab === $rtId;
        ?>
        <a href="<?= e(tabUrl($reviewerId, $rtId)) ?>"
           class="tab-btn<?= $isActive ? ' active' : '' ?>"
           role="tab" aria-selected="<?= $isActive ? 'true' : 'false' ?>">
            <i class="fas fa-star-half-stroke"></i> <?= e($rt['name']) ?>
            <span class="badge badge-secondary"><?= (int)$rt['startup_count'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    </div>

    <!-- --------------------------------------------------------------
         OVERVIEW TAB - summary cards per review type
    --------------------------------------------------------------- -->
    <?php if ($activeTab === 0): ?>
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-gauge-high"></i> Review Type Summary</h3>
            <span class="note"><?= count($reviewTypes) ?> active review type<?= count($reviewTypes) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($reviewTypes)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <p>This reviewer has not submitted any scores yet.</p>
            </div>
        <?php else: ?>
        <div class="panel-body">
        <div class="type-summary-grid">
            <?php foreach ($reviewTypes as $rt):
                // Per-type avg
                $rtAvgRow = fetchO($conn,"
                    SELECT AVG((rs.score/NULLIF(rc.max_score,0))*100) AS avg_pct,
                           COUNT(rs.score_id) AS scored_count
                    FROM review_scores rs
                    JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
                    WHERE rs.reviewer_id=? AND rs.review_type_id=? AND rc.review_type_id=rs.review_type_id
                ","ii",[$reviewerId,(int)$rt['review_type_id']]);
                $rtAvg   = round((float)($rtAvgRow['avg_pct'] ?? 0), 1);
                $rtCrit  = (int)($rtAvgRow['scored_count'] ?? 0);
                $fillCls = sc($rtAvg);
                $scoreCl = pc($rtAvg);
            ?>
            <a href="<?= e(tabUrl($reviewerId,(int)$rt['review_type_id'])) ?>" class="type-summary-card">
                <div class="tc-name"><?= e($rt['name']) ?></div>
                <div class="tc-meta">
                    <span><i class="fas fa-building"></i><?= (int)$rt['startup_count'] ?> startup<?= (int)$rt['startup_count'] !== 1 ? 's' : '' ?></span>
                    <span><i class="fas fa-list-check"></i><?= $rtCrit ?> criteria</span>
                </div>
                <div class="tc-score mrs-tone-<?= $fillCls ?>"><?= $rtAvg ?>%</div>
                <div class="mrs-bar-track">
                    <div class="mrs-bar-fill <?= $fillCls ?>" style="width:<?= min(100,$rtAvg) ?>%"></div>
                </div>
                <div class="tc-link">
                    Click to view detailed scores <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- --------------------------------------------------------------
         REVIEW TYPE TAB - searchable, paginated score table
    --------------------------------------------------------------- -->

    <!-- Tab-level avg mini-stat -->
    <?php if ($activeTab > 0 && !empty($tabSummary)): ?>
    <div class="panel">
        <div class="panel-body mrs-summary">
            <?php foreach ([
                ['Startups', (int)($tabSummary['total_startups'] ?? 0), 'fa-building'],
                ['Review Sets', (int)($tabSummary['total_sets'] ?? 0), 'fa-layer-group'],
                ['Criteria Scored', (int)($tabSummary['total_criteria'] ?? 0), 'fa-list-check'],
            ] as [$lbl,$val,$icon]): ?>
            <div class="mrs-summary-item">
                <i class="fas <?= $icon ?>"></i>
                <span class="mrs-summary-label"><?= $lbl ?>:</span>
                <strong class="mrs-summary-value"><?= $val ?></strong>
            </div>
            <?php endforeach; ?>
            <!-- Avg score inline ring -->
            <?php
            $tap   = round($tabAvg);
            $tcirc = 2 * M_PI * 22;
            $tdash = ($tap / 100) * $tcirc;
            $ttone = sc((float)$tap);
            ?>
            <div class="mrs-summary-ring">
                <svg viewBox="0 0 50 50" width="44" height="44" aria-hidden="true">
                    <circle class="mrs-ring-track" cx="25" cy="25" r="22" fill="none" stroke-width="5"/>
                    <circle class="mrs-ring-arc <?= $ttone ?>" cx="25" cy="25" r="22" fill="none" stroke-width="5"
                            stroke-dasharray="<?= round($tdash,2) ?> <?= round($tcirc,2) ?>"
                            stroke-linecap="round" transform="rotate(-90 25 25)"/>
                </svg>
                <div>
                    <div class="mrs-ring-label">Avg Score</div>
                    <div class="mrs-ring-value mrs-tone-<?= $ttone ?>"><?= number_format($tabAvg,1) ?>%</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel">
        <!-- Toolbar: search -->
        <div class="panel-body">
            <form method="GET" class="filters-bar mrs-filters" role="search">
                <input type="hidden" name="reviewer_id" value="<?= $reviewerId ?>">
                <input type="hidden" name="tab" value="<?= $activeTab ?>">
                <input type="hidden" name="page" value="1">

                <div class="search-input-wrap">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" name="search" value="<?= e($search) ?>" class="form-control"
                           placeholder="Search startup or application ID..." autocomplete="off">
                </div>

                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>

                <?php if ($search !== ''): ?>
                    <a href="<?= e(tabUrl($reviewerId,$activeTab)) ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Active filter chip -->
        <?php if ($search !== ''): ?>
        <div class="mrs-chips">
            <span class="badge badge-secondary">
                <i class="fas fa-search"></i> "<?= e($search) ?>"
                <a href="<?= e(tabUrl($reviewerId,$activeTab)) ?>">×</a>
            </span>
        </div>
        <?php endif; ?>

        <!-- Meta bar -->
        <div class="mrs-meta">
            <span><strong><?= $totalRows ?></strong> startup<?= $totalRows !== 1 ? 's' : '' ?><?= $search !== '' ? ' matching "'.e($search).'"' : '' ?> in <em><?= e($activeTabName) ?></em></span>
            <span>Showing <?= $fromRow ?>-<?= $toRow ?> &middot; Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong></span>
        </div>

        <!-- Score table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>Startup</th>
                    <th>Sector</th>
                    <th>Criteria Scored</th>
                    <th>Weighted Score</th>
                    <th>First Scored</th>
                    <th>Last Updated</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($scoreRows)): ?>
                    <tr><td colspan="6">
                        <div class="empty-state">
                            <i class="fas fa-inbox" aria-hidden="true"></i>
                            <p>No scores found<?= $search !== '' ? ' for "'.e($search).'"' : '' ?>.</p>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($scoreRows as $row):
                        $pct    = (float)$row['pct'];
                        $fillC  = sc($pct);
                        $pillC  = pc($pct);
                        $mapKey = $row['review_type_id'].'_'.$row['application_id'];
                    ?>
                    <!-- Summary row -->
                    <tr>
                        <td>
                            <strong><?= e($row['startup_name']) ?></strong><br>
                            <span class="cell-muted">App #<?= (int)$row['application_id'] ?></span>
                        </td>
                        <td class="cell-muted"><?= e($row['sector'] ?: '-') ?></td>
                        <td><span class="badge badge-secondary"><?= (int)$row['criteria_scored'] ?></span></td>
                        <td>
                            <div class="mrs-bar">
                                <div class="mrs-bar-track">
                                    <div class="mrs-bar-fill <?= $fillC ?>" style="width:<?= min(100,$pct) ?>%"></div>
                                </div>
                                <span class="mrs-bar-val"><?= number_format($pct,2) ?>%</span>
                            </div>
                            <div class="cell-muted mrs-num mrs-earned">
                                <?= number_format((float)$row['earned'],2) ?> / <?= number_format((float)$row['max_w'],2) ?>
                            </div>
                        </td>
                        <td class="cell-muted"><?= !empty($row['first_at']) ? date('d M Y H:i', strtotime($row['first_at'])) : '-' ?></td>
                        <td class="cell-muted"><?= !empty($row['last_at'])  ? date('d M Y H:i', strtotime($row['last_at']))  : '-' ?></td>
                    </tr>

                    <!-- Criteria breakdown row -->
                    <tr>
                        <td colspan="6" class="mrs-breakdown-cell">
                            <div class="criteria-block">
                                <div class="criteria-block-title">
                                    <i class="fas fa-table-list"></i>
                                    Criteria breakdown - <?= e($row['startup_name']) ?>
                                    <span class="badge <?= $pillC ?>"><?= number_format($pct,2) ?>%</span>
                                </div>

                                <div class="criteria-inner">
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Question</th>
                                                <th>Score</th>
                                                <th>Max</th>
                                                <th>Weight</th>
                                                <th>%</th>
                                                <th>Comments</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php if (!empty($criteriaMap[$mapKey])): ?>
                                                <?php foreach ($criteriaMap[$mapKey] as $cr):
                                                    $cp   = (float)$cr['cp'];
                                                    $cfc  = sc($cp);
                                                    $full = trim((string)($cr['question'] ?? ''));
                                                    $short = tw($full, 18);
                                                ?>
                                                <tr>
                                                    <td class="cell-muted"><?= e($cr['category']) ?></td>
                                                    <td class="tip-cell">
                                                        <?= e($short) ?>
                                                        <?php if ($full !== ''): ?>
                                                            <div class="tip-bubble"><?= e($full) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($cr['description'])): ?>
                                                            <div class="note mrs-mt-4"><?= e($cr['description']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="mrs-num"><?= number_format((float)$cr['score'],2) ?></td>
                                                    <td class="mrs-num"><?= number_format((float)$cr['max_score'],2) ?></td>
                                                    <td class="mrs-num"><?= number_format((float)$cr['weight'],2) ?></td>
                                                    <td>
                                                        <div class="mrs-bar is-sm">
                                                            <div class="mrs-bar-track">
                                                                <div class="mrs-bar-fill <?= $cfc ?>" style="width:<?= min(100,$cp) ?>%"></div>
                                                            </div>
                                                            <span class="mrs-bar-val"><?= number_format($cp,1) ?>%</span>
                                                        </div>
                                                    </td>
                                                    <td class="mrs-comment" title="<?= e((string)($cr['comments']??'')) ?>">
                                                        <?php $cmtTxt = tw((string)($cr['comments']??''),12); ?>
                                                        <?= $cmtTxt !== '' ? e($cmtTxt) : '<span class="cell-muted">-</span>' ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="7" class="text-center cell-muted">No criteria breakdown found.</td></tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="mrs-pager">
            <span class="cell-muted">Showing <?= $fromRow ?>-<?= $toRow ?> of <?= $totalRows ?></span>
            <div class="pagination">
                <a href="<?= e(pgUrl($reviewerId,$activeTab,$page-1,$search)) ?>"
                   class="<?= $page <= 1 ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php foreach (pgRange($page,$totalPages) as $pn): ?>
                    <?php if ($pn === '...'): ?>
                        <span class="disabled">...</span>
                    <?php elseif ($pn === $page): ?>
                        <span class="current"><?= $pn ?></span>
                    <?php else: ?>
                        <a href="<?= e(pgUrl($reviewerId,$activeTab,(int)$pn,$search)) ?>"><?= $pn ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a href="<?= e(pgUrl($reviewerId,$activeTab,$page+1,$search)) ?>"
                   class="<?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.panel -->
    <?php endif; ?>

</div><!-- /.page-stack -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>