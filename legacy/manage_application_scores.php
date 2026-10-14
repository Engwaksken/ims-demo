<?php
ob_start();


date_default_timezone_set('Africa/Nairobi');

require_once 'includes/config.php';

$page_title = 'Manage Application Scores';

if (!ims_allowed('application_scores.manage', ['Administrator'], 'Manage and delete application review scores')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard');
    exit();
}

require_once 'includes/header.php';



function fetchA(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException('Prepare failed: ' . $conn->error);
    if ($types !== '' && $params) $st->bind_param($types, ...$params);
    if (!$st->execute()) { $e=$st->error; $st->close(); throw new RuntimeException('Execute: '.$e); }
    $res  = $st->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $st->close();
    return $rows;
}
function fetchO(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    return fetchA($conn,$sql,$types,$params)[0] ?? null;
}
function fmt_dt(?string $dt): string {
    if (empty($dt) || $dt === '0000-00-00 00:00:00') return '-';
    try {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', trim($dt), new DateTimeZone('Africa/Nairobi'))
          ?: new DateTime($dt, new DateTimeZone('Africa/Nairobi'));
        return $d->format('d M Y h:i A');
    } catch (Throwable) { return '-'; }
}
function rec_pill(string $rec): string {
    $map = ['Accept'=>'green','Shortlist'=>'blue','Reject'=>'red','Needs More Info'=>'yellow'];
    $cls = $map[$rec] ?? 'slate';
    return '<span class="pill '.$cls.'">'.e($rec).'</span>';
}
function pgRange(int $cur, int $total): array {
    $r = [];
    for ($i=max(1,$cur-2);$i<=min($total,$cur+2);$i++) $r[]=$i;
    if (($r[0]??1)>2) array_unshift($r,'...');
    if (($r[0]??1)>1) array_unshift($r,1);
    $last=end($r);
    if ($last<$total-1) $r[]='...';
    if ($last<$total)   $r[]=$total;
    return $r;
}

/* -- Session messages ------------------------------------------ */
$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* -- Filter inputs --------------------------------------------- */
$f_type = max(0,(int)($_GET['review_type_id'] ?? 0));
$f_rev  = max(0,(int)($_GET['reviewer_id']    ?? 0));
$f_app  = max(0,(int)($_GET['application_id'] ?? 0));
$q      = trim((string)($_GET['q']            ?? ''));
$page   = max(1,(int)($_GET['page']           ?? 1));
$pp     = (int)($_GET['per_page'] ?? 20);
if (!in_array($pp,[10,20,50,100],true)) $pp = 20;

/* Active tab: 'scores' | 'edit' */
$activeTab = 'scores';
$edit_review_id = max(0,(int)($_GET['edit_review_id'] ?? 0));
if ($edit_review_id > 0) $activeTab = 'edit';

/* -- Dropdown data --------------------------------------------- */
$review_types = fetchA($conn,"SELECT review_type_id,name FROM review_types WHERE is_active=1 ORDER BY sort_order ASC,name ASC");
$reviewers    = fetchA($conn,"SELECT user_id,full_name FROM users WHERE role IN ('Administrator','Reviewer','Programs Lead','MEAL Lead','Project Officer') ORDER BY full_name ASC");
$apps_list    = fetchA($conn,"SELECT application_id,startup_name FROM applications ORDER BY startup_name ASC LIMIT 300");

/* -- Filter SQL ------------------------------------------------ */
$where  = [];
$params = [];
$types  = '';

if ($f_type > 0) { $where[]="ar.review_type_id=?"; $types.='i'; $params[]=$f_type; }
if ($f_rev  > 0) { $where[]="ar.reviewer_id=?";    $types.='i'; $params[]=$f_rev;  }
if ($f_app  > 0) { $where[]="ar.application_id=?"; $types.='i'; $params[]=$f_app;  }
if ($q !== '') {
    $where[]="(a.startup_name LIKE ? OR COALESCE(u.full_name,'') LIKE ? OR COALESCE(rt.name,'') LIKE ? OR COALESCE(ar.recommendation,'') LIKE ?)";
    $types.='ssss'; $lk='%'.$q.'%';
    array_push($params,$lk,$lk,$lk,$lk);
}

$wSql  = $where ? 'WHERE '.implode(' AND ',$where) : '';
$joins = "FROM application_reviews ar
          INNER JOIN applications a ON a.application_id=ar.application_id
          LEFT JOIN users u ON u.user_id=ar.reviewer_id
          LEFT JOIN review_types rt ON rt.review_type_id=ar.review_type_id";

/* -- Count ----------------------------------------------------- */
$cr = fetchO($conn,"SELECT COUNT(*) AS n $joins $wSql",$types,$params);
$total_rows  = (int)($cr['n'] ?? 0);
$total_pages = max(1,(int)ceil($total_rows/$pp));
$page        = min($page,$total_pages);
$offset      = ($page-1)*$pp;

/* -- Stats ----------------------------------------------------- */
$stats = fetchO($conn,"
    SELECT COUNT(*) AS total_reviews,
           COUNT(DISTINCT ar.application_id) AS total_apps,
           COUNT(DISTINCT ar.reviewer_id) AS total_reviewers,
           AVG(ar.overall_score) AS avg_score
    $joins
") ?? [];

/* -- Paged rows ------------------------------------------------ */
$lT = $types.'ii';
$lP = array_merge($params,[$pp,$offset]);

$rows = fetchA($conn,"
    SELECT ar.review_id,ar.application_id,ar.reviewer_id,ar.review_type_id,
           ar.overall_score,ar.comments,ar.recommendation,ar.created_at,ar.updated_at,
           a.startup_name,
           COALESCE(u.full_name,CONCAT('User #',ar.reviewer_id)) AS reviewer_name,
           COALESCE(rt.name,CONCAT('Type #',ar.review_type_id)) AS rt_name,
           (SELECT COUNT(*) FROM review_scores rs
            WHERE rs.application_id=ar.application_id AND rs.reviewer_id=ar.reviewer_id AND rs.review_type_id=ar.review_type_id) AS criteria_count
    $joins $wSql
    ORDER BY ar.updated_at DESC, ar.created_at DESC, ar.review_id DESC
    LIMIT ? OFFSET ?
",$lT,$lP);

/* -- Edit data ------------------------------------------------- */
$editReview = null;
$editScores = [];

if ($edit_review_id > 0) {
    $editReview = fetchO($conn,"
        SELECT ar.review_id,ar.application_id,ar.reviewer_id,ar.review_type_id,
               ar.overall_score,ar.comments,ar.recommendation,
               a.startup_name,
               COALESCE(u.full_name,CONCAT('User #',ar.reviewer_id)) AS reviewer_name,
               COALESCE(rt.name,CONCAT('Type #',ar.review_type_id)) AS rt_name
        FROM application_reviews ar
        INNER JOIN applications a ON a.application_id=ar.application_id
        LEFT JOIN users u ON u.user_id=ar.reviewer_id
        LEFT JOIN review_types rt ON rt.review_type_id=ar.review_type_id
        WHERE ar.review_id=? LIMIT 1
    ",'i',[$edit_review_id]);

    if ($editReview) {
        $editScores = fetchA($conn,"
            SELECT rs.score_id,rs.criteria_id,rs.score,rs.comments AS sc_comments,
                   rc.category,rc.question,rc.description,rc.max_score,rc.weight
            FROM review_scores rs
            INNER JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id
            WHERE rs.application_id=? AND rs.reviewer_id=? AND rs.review_type_id=?
            ORDER BY rc.category ASC,rc.criteria_id ASC
        ",'iii',[(int)$editReview['application_id'],(int)$editReview['reviewer_id'],(int)$editReview['review_type_id']]);
    }
}

/* -- URL builder ----------------------------------------------- */
function pgUrl(int $p): string {
    $q = array_filter([
        'review_type_id' => (int)($_GET['review_type_id'] ?? 0),
        'reviewer_id'    => (int)($_GET['reviewer_id']    ?? 0),
        'application_id' => (int)($_GET['application_id'] ?? 0),
        'q'              => trim($_GET['q'] ?? ''),
        'per_page'       => (int)($_GET['per_page']       ?? 20),
        'page'           => $p,
    ], fn($v) => $v !== '' && $v !== 0 && $v !== '0');
    return 'manage_application_scores' . ($q ? '?'.http_build_query($q) : '');
}
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/progress_board.css">
<style>
/* -- Page-specific --------------------------------------------- */

/* Criteria score item in the edit form */
.score-item {
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-md);
    padding: 14px 16px;
}

.score-item-head {
    font-size: 13px;
    font-weight: 700;
    color: var(--ink-700);
    margin-bottom: 3px;
}

.score-item-sub {
    font-size: 12px;
    color: var(--ink-300);
    margin-bottom: 10px;
    line-height: 1.5;
}

.score-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 14px;
}

/* Comment preview row */
.comment-row td {
    background: var(--brand-50);
    border-bottom: 1px solid var(--brand-100);
    font-size: 12.5px;
    color: var(--ink-400);
    line-height: 1.6;
    padding: 8px 14px 10px 32px;
}

/* Rec pill colours already in progress_board.css */

/* Per-page select in toolbar */
.filters-bar > .pp-select { min-width: 100px; }
.pp-select {
    height: 38px;
    padding: 0 32px 0 10px;
    border: 1.5px solid var(--ink-100);
    border-radius: var(--radius-md);
    font-family: var(--font-body);
    font-size: 12px;
    color: var(--ink-500);
    background: var(--surface-card)
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")
        no-repeat right 8px center;
    appearance: none;
    cursor: pointer;
    outline: none;
}

.pp-select:focus {
    border-color: var(--brand-500);
    box-shadow: 0 0 0 3px rgba(249,115,22,.12);
}

@media (max-width: 768px) {
    .score-grid { grid-template-columns: 1fr; }
}
</style>

<div class="assets-wrap pb-wrap">

    <!-- -- Page Hero ------------------------------------------------ -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-star-half-stroke"></i> Manage Application Scores</h1>
            <p>Review, edit, and audit all submitted evaluation scores</p>
        </div>
        <div class="hero-actions">
            <a href="progress-board" class="btn btn-primary">
                <i class="fas fa-chart-line"></i> Progress Report
            </a>
        </div>
    </div>

    <!-- -- Alerts --------------------------------------------------- -->
    <?php if ($success_msg !== ''): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg !== ''): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error_msg) ?></div>
    <?php endif; ?>

    <!-- -- Stats strip ---------------------------------------------- -->
    <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total Reviews</span>
                <span class="stat-value"><?= number_format((int)($stats['total_reviews']??0)) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-building"></i></div>
            <div class="stat-info">
                <span class="stat-label">Applications</span>
                <span class="stat-value"><?= number_format((int)($stats['total_apps']??0)) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <span class="stat-label">Reviewers</span>
                <span class="stat-value"><?= number_format((int)($stats['total_reviewers']??0)) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-percent"></i></div>
            <div class="stat-info">
                <span class="stat-label">Avg Score</span>
                <span class="stat-value"><?= number_format((float)($stats['avg_score']??0),1) ?>%</span>
            </div>
        </div>
    </div>

    <!-- -- Tab navigation ------------------------------------------- -->
    <nav class="tab-nav" role="tablist">
        <a href="manage_application_scores?<?= e(http_build_query(array_filter(['review_type_id'=>$f_type,'reviewer_id'=>$f_rev,'application_id'=>$f_app,'q'=>$q,'per_page'=>$pp,'page'=>$page],fn($v)=>$v!==''&&$v!==0&&$v!=='0'))) ?>"
           class="tab-btn<?= $activeTab==='scores'?' active':'' ?>" role="tab">
            <i class="fas fa-table-list"></i> All Reviews
            <span class="tab-badge"><?= number_format($total_rows) ?></span>
        </a>
        <?php if ($editReview): ?>
        <a href="#" class="tab-btn active" role="tab">
            <i class="fas fa-pen-to-square"></i>
            Editing: <?= e($editReview['startup_name']) ?> - <?= e($editReview['rt_name']) ?>
        </a>
        <?php endif; ?>
    </nav>

    <!-- --------------------------------------------------------------
         TAB: ALL REVIEWS
    --------------------------------------------------------------- -->
    <?php if ($activeTab === 'scores'): ?>
    <div class="panel">

        <!-- Filter toolbar -->
        <div class="pb-toolbar">
            <form method="GET" class="filters-bar" id="filterForm" role="search" aria-label="Filter reviews">

                <select name="review_type_id" class="form-control" style="height:38px;padding:0 32px 0 10px;font-size:13px;min-width:160px;" onchange="this.form.submit()">
                    <option value="0">All review types</option>
                    <?php foreach ($review_types as $rt): ?>
                        <option value="<?= (int)$rt['review_type_id'] ?>" <?= $f_type===(int)$rt['review_type_id']?'selected':'' ?>><?= e($rt['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="reviewer_id" class="form-control" style="height:38px;padding:0 32px 0 10px;font-size:13px;min-width:160px;" onchange="this.form.submit()">
                    <option value="0">All reviewers</option>
                    <?php foreach ($reviewers as $rv): ?>
                        <option value="<?= (int)$rv['user_id'] ?>" <?= $f_rev===(int)$rv['user_id']?'selected':'' ?>><?= e($rv['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="application_id" class="form-control" style="height:38px;padding:0 32px 0 10px;font-size:13px;min-width:160px;" onchange="this.form.submit()">
                    <option value="0">All applications</option>
                    <?php foreach ($apps_list as $ap): ?>
                        <option value="<?= (int)$ap['application_id'] ?>" <?= $f_app===(int)$ap['application_id']?'selected':'' ?>><?= e($ap['startup_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Search -->
                <div class="pb-toolbar-search">
                    <i class="fas fa-search search-icon"></i>
                    <input type="search" name="q" id="qInput" value="<?= e($q) ?>"
                           placeholder="Startup, reviewer, recommendation..." autocomplete="off">
                </div>

                <!-- Per-page -->
                <select name="per_page" class="pp-select" onchange="this.form.submit()">
                    <?php foreach ([10,20,50,100] as $n): ?>
                        <option value="<?= $n ?>" <?= $pp===$n?'selected':'' ?>><?= $n ?> / page</option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-sm btn-dark">
                    <i class="fas fa-search"></i> Search
                </button>

                <?php if ($f_type||$f_rev||$f_app||$q): ?>
                    <a href="manage_application_scores" class="btn btn-sm btn-gray">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Active filter chips -->
        <?php if ($f_type||$f_rev||$f_app||$q): ?>
        <div class="active-filters">
            <?php
            if ($f_type) {
                $tn = '';
                foreach ($review_types as $rt) if ((int)$rt['review_type_id']===$f_type) { $tn=$rt['name']; break; }
                $u = http_build_query(array_filter(['reviewer_id'=>$f_rev,'application_id'=>$f_app,'q'=>$q,'per_page'=>$pp],fn($v)=>$v!==''&&$v!==0));
                echo '<span class="filter-chip">Type: '.e($tn).' <a href="manage_application_scores'.($u?'?'.$u:'').'">×</a></span>';
            }
            if ($f_rev) {
                $rn = '';
                foreach ($reviewers as $rv) if ((int)$rv['user_id']===$f_rev) { $rn=$rv['full_name']; break; }
                $u = http_build_query(array_filter(['review_type_id'=>$f_type,'application_id'=>$f_app,'q'=>$q,'per_page'=>$pp],fn($v)=>$v!==''&&$v!==0));
                echo '<span class="filter-chip">Reviewer: '.e($rn).' <a href="manage_application_scores'.($u?'?'.$u:'').'">×</a></span>';
            }
            if ($f_app) {
                $an = '';
                foreach ($apps_list as $ap) if ((int)$ap['application_id']===$f_app) { $an=$ap['startup_name']; break; }
                $u = http_build_query(array_filter(['review_type_id'=>$f_type,'reviewer_id'=>$f_rev,'q'=>$q,'per_page'=>$pp],fn($v)=>$v!==''&&$v!==0));
                echo '<span class="filter-chip">App: '.e($an).' <a href="manage_application_scores'.($u?'?'.$u:'').'">×</a></span>';
            }
            if ($q) {
                $u = http_build_query(array_filter(['review_type_id'=>$f_type,'reviewer_id'=>$f_rev,'application_id'=>$f_app,'per_page'=>$pp],fn($v)=>$v!==''&&$v!==0));
                echo '<span class="filter-chip">Search: "'.e($q).'" <a href="manage_application_scores'.($u?'?'.$u:'').'">×</a></span>';
            }
            ?>
        </div>
        <?php endif; ?>

        <!-- Meta bar -->
        <div class="pb-meta">
            <span><strong><?= number_format($total_rows) ?></strong> review<?= $total_rows!==1?'s':'' ?></span>
            <span>
                <?= number_format(($page-1)*$pp+1) ?>-<?= number_format(min($page*$pp,$total_rows)) ?>
                &middot; Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong>
            </span>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Application</th>
                    <th>Reviewer</th>
                    <th>Review Type</th>
                    <th>Score</th>
                    <th>Recommendation</th>
                    <th>Criteria</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--ink-200);">
                        <i class="fas fa-inbox" style="display:block;font-size:1.8rem;margin-bottom:10px;opacity:.3;"></i>
                        No review records found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row):
                        $pct = (float)$row['overall_score'];
                        $sc  = $pct>=70?'high':($pct>=40?'mid':'low');
                        $editHref = 'manage_application_scores?'.http_build_query(array_filter([
                            'edit_review_id' => (int)$row['review_id'],
                            'review_type_id' => $f_type,
                            'reviewer_id'    => $f_rev,
                            'application_id' => $f_app,
                            'q'              => $q,
                            'page'           => $page,
                            'per_page'       => $pp,
                        ],fn($v)=>$v!==''&&$v!==0&&$v!=='0'));
                    ?>
                    <tr>
                        <td class="note"><?= $offset + $i + 1 ?></td>
                        <td>
                            <strong><?= e($row['startup_name']) ?></strong><br>
                            <span class="note">App #<?= (int)$row['application_id'] ?></span>
                        </td>
                        <td><?= e($row['reviewer_name']) ?></td>
                        <td><span class="pill blue"><?= e($row['rt_name']) ?></span></td>
                        <td>
                            <div class="score-bar-wrap" style="min-width:110px;">
                                <div class="score-bar-track">
                                    <div class="score-bar-fill <?= $sc ?>" style="width:<?= min(100,$pct) ?>%"></div>
                                </div>
                                <span class="score-val"><?= number_format($pct,2) ?>%</span>
                            </div>
                        </td>
                        <td><?= rec_pill((string)($row['recommendation']??'')) ?></td>
                        <td><span class="pill slate"><?= (int)$row['criteria_count'] ?></span></td>
                        <td class="note" style="font-size:11px;"><?= e(fmt_dt($row['created_at']??null)) ?></td>
                        <td class="note" style="font-size:11px;"><?= e(fmt_dt($row['updated_at']??null)) ?></td>
                        <td>
                            <div class="tbl-actions">
                                <a href="<?= e($editHref) ?>" class="btn btn-sm btn-soft" title="Edit review">
                                    <i class="fas fa-pen-to-square"></i>
                                </a>
                                <form method="POST" action="includes/process_manage_application_scores.php"
                                      onsubmit="return confirm('Delete this review and all its criterion scores? This cannot be undone.');"
                                      style="display:inline;">
                                    <input type="hidden" name="action"         value="delete_review">
                                    <input type="hidden" name="review_id"      value="<?= (int)$row['review_id'] ?>">
                                    <input type="hidden" name="application_id" value="<?= (int)$row['application_id'] ?>">
                                    <input type="hidden" name="reviewer_id"    value="<?= (int)$row['reviewer_id'] ?>">
                                    <input type="hidden" name="review_type_id" value="<?= (int)$row['review_type_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-red" title="Delete review">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <!-- Comment preview row -->
                    <?php if (!empty(trim((string)($row['comments']??'')))): ?>
                    <tr class="comment-row">
                        <td></td>
                        <td colspan="9">
                            <i class="fas fa-comment-dots" style="color:var(--brand-400);margin-right:6px;"></i>
                            <?= nl2br(e(trim((string)$row['comments']))) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pb-pagination">
            <span class="subtle-note">
                Showing <?= number_format(($page-1)*$pp+1) ?>-<?= number_format(min($page*$pp,$total_rows)) ?>
                of <?= number_format($total_rows) ?>
            </span>
            <div class="pg-links">
                <a href="<?= e(pgUrl($page-1)) ?>" class="pg-btn <?= $page<=1?'pg-disabled':'' ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php foreach (pgRange($page,$total_pages) as $pn): ?>
                    <?php if ($pn==='...'): ?>
                        <span class="pg-ellipsis">...</span>
                    <?php elseif ($pn===$page): ?>
                        <span class="pg-btn pg-active"><?= $pn ?></span>
                    <?php else: ?>
                        <a href="<?= e(pgUrl((int)$pn)) ?>" class="pg-btn"><?= $pn ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a href="<?= e(pgUrl($page+1)) ?>" class="pg-btn <?= $page>=$total_pages?'pg-disabled':'' ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.panel scores tab -->

    <!-- --------------------------------------------------------------
         TAB: EDIT REVIEW
    --------------------------------------------------------------- -->
    <?php elseif ($activeTab === 'edit' && $editReview): ?>
    <div class="panel">

        <!-- Edit panel head -->
        <div class="panel-head">
            <div>
                <h3><i class="fas fa-pen-to-square"></i> Edit Review</h3>
                <div class="note" style="margin-top:4px;">
                    <strong><?= e($editReview['startup_name']) ?></strong>
                    &middot; Reviewer: <?= e($editReview['reviewer_name']) ?>
                    &middot; Type: <span class="pill blue"><?= e($editReview['rt_name']) ?></span>
                </div>
            </div>
            <a href="manage_application_scores?<?= e(http_build_query(array_filter(['review_type_id'=>$f_type,'reviewer_id'=>$f_rev,'application_id'=>$f_app,'q'=>$q,'per_page'=>$pp,'page'=>$page],fn($v)=>$v!==''&&$v!==0&&$v!=='0'))) ?>"
               class="btn btn-gray">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <div class="panel-body">
            <form method="POST" action="includes/process_manage_application_scores.php">
                <input type="hidden" name="action"         value="update_review">
                <input type="hidden" name="review_id"      value="<?= (int)$editReview['review_id'] ?>">
                <input type="hidden" name="application_id" value="<?= (int)$editReview['application_id'] ?>">
                <input type="hidden" name="reviewer_id"    value="<?= (int)$editReview['reviewer_id'] ?>">
                <input type="hidden" name="review_type_id" value="<?= (int)$editReview['review_type_id'] ?>">

                <!-- Overall score + recommendation -->
                <div class="form-grid" style="margin-bottom:20px;">
                    <div class="form-group">
                        <label class="form-label">Overall Score (%) <sup style="color:var(--red-fg)">*</sup></label>
                        <input type="number" step="0.01" min="0" max="100" name="overall_score"
                               class="form-control" value="<?= e($editReview['overall_score']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Recommendation <sup style="color:var(--red-fg)">*</sup></label>
                        <select name="recommendation" class="form-control" required>
                            <?php foreach (['Accept','Shortlist','Reject','Needs More Info'] as $rec): ?>
                                <option value="<?= e($rec) ?>" <?= (($editReview['recommendation']??'')===$rec)?'selected':'' ?>>
                                    <?= e($rec) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Review Comments <sup style="color:var(--red-fg)">*</sup></label>
                        <textarea name="review_comments" class="form-control" rows="4" required><?= e($editReview['comments']??'') ?></textarea>
                    </div>
                </div>

                <!-- Criteria scores -->
                <?php if (!empty($editScores)): ?>
                <div style="margin-bottom:14px;">
                    <div class="panel-head" style="background:var(--ink-50);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:14px;">
                        <h3 style="font-size:13px;"><i class="fas fa-list-check"></i> Criterion Scores</h3>
                        <span class="note"><?= count($editScores) ?> criteria</span>
                    </div>
                    <div class="score-grid">
                        <?php foreach ($editScores as $sc): ?>
                        <div class="score-item">
                            <div class="score-item-head">
                                <span class="pill slate" style="font-size:9px;margin-right:6px;"><?= e($sc['category']) ?></span>
                                <?= e($sc['question']) ?>
                            </div>
                            <?php if (!empty($sc['description'])): ?>
                                <div class="score-item-sub"><?= e($sc['description']) ?></div>
                            <?php endif; ?>

                            <input type="hidden" name="score_ids[]" value="<?= (int)$sc['score_id'] ?>">

                            <div class="form-grid" style="grid-template-columns:1fr 2fr;gap:10px;margin-top:10px;">
                                <div class="form-group">
                                    <label class="form-label">Score <small style="font-weight:400;text-transform:none;letter-spacing:0;">(Max <?= e($sc['max_score']) ?>)</small></label>
                                    <input type="number" step="0.01" min="0" max="<?= e($sc['max_score']) ?>"
                                           name="scores[<?= (int)$sc['score_id'] ?>]"
                                           class="form-control" value="<?= e($sc['score']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Criterion Comment</label>
                                    <textarea name="criterion_comments[<?= (int)$sc['score_id'] ?>]"
                                              class="form-control" style="min-height:70px;"><?= e($sc['sc_comments']??'') ?></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="actions" style="display:flex;gap:10px;margin-top:4px;">
                    <button type="submit" class="btn btn-dark">
                        <i class="fas fa-save"></i> Update Review
                    </button>
                    <a href="manage_application_scores?<?= e(http_build_query(array_filter(['review_type_id'=>$f_type,'reviewer_id'=>$f_rev,'application_id'=>$f_app,'q'=>$q,'per_page'=>$pp,'page'=>$page],fn($v)=>$v!==''&&$v!==0&&$v!=='0'))) ?>"
                       class="btn btn-gray">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.pb-wrap -->

<script>
/* Debounce search input */
(function() {
    var inp = document.getElementById('qInput');
    if (!inp) return;
    var timer;
    inp.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(function() {
            document.getElementById('filterForm').submit();
        }, 420);
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>