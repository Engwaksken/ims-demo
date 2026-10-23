<?php
declare(strict_types=1);

$page_title = 'Progress Report';

require_once __DIR__ . '/includes/config.php';
check_role(['Administrator', 'Programs Lead', 'MEAL Lead','Executive Director', 'Program Director', 'Program Manager']);

/* ---------------------------------------------------------------
   HELPERS
--------------------------------------------------------------- */
function fetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $st = $conn->prepare($sql);
    if (!$st) throw new mysqli_sql_exception($conn->error);
    if ($types !== '' && $params) $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $st->close();
    return $rows;
}
function fetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    return fetchAll($conn, $sql, $types, $params)[0] ?? [];
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function score_class(float $s): string { return $s >= 70 ? 'high' : ($s >= 40 ? 'mid' : 'low'); }
function rank_class(int $r): string { return match(true) { $r === 1 => 'gold', $r === 2 => 'silver', $r === 3 => 'bronze', $r <= 10 => 'top10', default => '' }; }
function calibration(float $diff): array { return abs($diff) < 5 ? ['Calibrated','green'] : ($diff > 0 ? ['High Scorer','yellow'] : ['Low Scorer','red']); }
function limit_words(string $t, int $max = 20): string {
    $t = trim(preg_replace('/\s+/', ' ', $t));
    $w = preg_split('/\s+/', $t) ?: [];
    return count($w) <= $max ? $t : implode(' ', array_slice($w, 0, $max)) . '...';
}
function pgRange(int $cur, int $total): array {
    $r = [];
    for ($i = max(1,$cur-2); $i <= min($total,$cur+2); $i++) $r[] = $i;
    if (($r[0]??1)>2) array_unshift($r,'...');
    if (($r[0]??1)>1) array_unshift($r,1);
    $last = end($r);
    if ($last<$total-1) $r[] = '...';
    if ($last<$total)   $r[] = $total;
    return $r;
}

/* ---------------------------------------------------------------
   INPUTS
--------------------------------------------------------------- */
$topN      = min(100, max(1, (int)($_GET['top_n'] ?? 12)));
$selType   = trim((string)($_GET['review_type'] ?? 'all'));
$curType   = (int)($_GET['current_review_type'] ?? 0);
$export    = trim((string)($_GET['export'] ?? ''));
$activeTab = trim((string)($_GET['tab'] ?? 'overview'));
$perPage   = 15;

// per-tab pagination & search
$rankPage   = max(1,(int)($_GET['rank_page']   ?? 1));
$revPage    = max(1,(int)($_GET['rev_page']    ?? 1));
$cmtPage    = max(1,(int)($_GET['cmt_page']    ?? 1));

$rankSearch = trim((string)($_GET['rank_q'] ?? ''));
$revSearch  = trim((string)($_GET['rev_q']  ?? ''));
$cmtSearch  = trim((string)($_GET['cmt_q']  ?? ''));

$cmtScoreMin  = isset($_GET['cmt_min'])  && $_GET['cmt_min']  !== '' ? (float)$_GET['cmt_min']  : null;
$cmtScoreMax  = isset($_GET['cmt_max'])  && $_GET['cmt_max']  !== '' ? (float)$_GET['cmt_max']  : null;
$cmtRec       = trim((string)($_GET['cmt_rec'] ?? ''));

/* ---------------------------------------------------------------
   REVIEW TYPES
--------------------------------------------------------------- */
$reviewTypes = fetchAll($conn, "SELECT review_type_id, name FROM review_types WHERE is_active=1 ORDER BY name ASC");
$rtMap = [];
$validIds = [];
foreach ($reviewTypes as $rt) { $rtMap[(int)$rt['review_type_id']] = $rt['name']; $validIds[] = (int)$rt['review_type_id']; }

$selId = null;
if ($selType !== 'all') { $tmp=(int)$selType; if (in_array($tmp,$validIds,true)) $selId=$tmp; else $selType='all'; }
if ($selId !== null) $curType = $selId;
elseif ($curType<=0 || !in_array($curType,$validIds,true)) $curType = $validIds[0] ?? 0;

$selLabel = $selId !== null ? ($rtMap[$selId] ?? 'Selected') : 'All Review Types';
$curLabel = $rtMap[$curType] ?? 'N/A';

/* Score / type filter SQL fragments — returns [sql, types_string, params_array] */
function scoreFilter(?int $id, string $alias = 'rs'): array {
    if ($id !== null && $id > 0) {
        return [" AND {$alias}.review_type_id = ? ", 'i', [$id]];
    }
    return ['', '', []];
}
[$inF,  $inT,  $inP]  = scoreFilter($selId, 'rs');
[$outF, $outT, $outP] = scoreFilter($selId, 'rt');

/* ---------------------------------------------------------------
   OVERVIEW STATS
--------------------------------------------------------------- */
$totals = fetchOne($conn,"
    SELECT COUNT(DISTINCT x.application_id) AS total_startups,
           COUNT(*) AS total_reviews, AVG(x.pct) AS global_avg
    FROM (SELECT rs.application_id,rs.reviewer_id,rs.review_type_id,
                 (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct
          FROM review_scores rs
          JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
          WHERE 1=1 {$inF}
          GROUP BY rs.application_id,rs.reviewer_id,rs.review_type_id) x
",$inT,$inP);

$totalStartups = (int)($totals['total_startups'] ?? 0);
$totalReviews  = (int)($totals['total_reviews']  ?? 0);
$globalAvg     = (float)($totals['global_avg']   ?? 0);

/* Completion */
$progressRows = fetchAll($conn,"
    SELECT x.application_id,x.reviewer_id,x.review_type_id,x.scored,COALESCE(rc.required,0) AS required
    FROM (SELECT rs.application_id,rs.reviewer_id,rs.review_type_id,COUNT(DISTINCT rs.criteria_id) AS scored
          FROM review_scores rs WHERE 1=1 {$inF}
          GROUP BY rs.application_id,rs.reviewer_id,rs.review_type_id) x
    LEFT JOIN (SELECT review_type_id,COUNT(*) AS required FROM review_criteria WHERE is_active=1 GROUP BY review_type_id) rc
        ON rc.review_type_id=x.review_type_id
",$inT,$inP);

$totalAsgn=$compAsgn=$inpAsgn=$pendAsgn=0;
foreach ($progressRows as $row) {
    $totalAsgn++;
    $s=(int)$row['scored']; $r=(int)$row['required'];
    if ($s<=0) $pendAsgn++;
    elseif ($r>0&&$s>=$r) $compAsgn++;
    else $inpAsgn++;
}
$compRate = $totalAsgn>0 ? ($compAsgn/$totalAsgn)*100 : 0;

/* Score distribution */
$distRow = fetchOne($conn,"
    SELECT SUM(pct BETWEEN 0 AND 19.99) AS b0,SUM(pct BETWEEN 20 AND 39.99) AS b20,
           SUM(pct BETWEEN 40 AND 59.99) AS b40,SUM(pct BETWEEN 60 AND 79.99) AS b60,
           SUM(pct BETWEEN 80 AND 99.99) AS b80,SUM(pct>=100) AS b100
    FROM (SELECT (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct
          FROM review_scores rs
          JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
          WHERE 1=1 {$inF}
          GROUP BY rs.application_id,rs.reviewer_id,rs.review_type_id) z
",$inT,$inP) ?: array_fill_keys(['b0','b20','b40','b60','b80','b100'],0);

/* Chart data (top 12) */
$chartRows = fetchAll($conn,"
    SELECT rt.review_type_id,rt.name AS rt_name,
           CONCAT(a.startup_name,' (',rt.name,')') AS label,
           AVG(ar.pct) AS avg_score
    FROM review_types rt
    JOIN (SELECT rs.application_id,rs.reviewer_id,rs.review_type_id,
                 (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct
          FROM review_scores rs
          JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
          WHERE 1=1 {$inF} GROUP BY rs.application_id,rs.reviewer_id,rs.review_type_id) ar
        ON ar.review_type_id=rt.review_type_id
    JOIN applications a ON a.application_id=ar.application_id
    WHERE rt.is_active=1 {$outF}
    GROUP BY rt.review_type_id,rt.name,a.application_id,a.startup_name
    ORDER BY avg_score DESC
",$inT.$outT,array_merge(is_array($inP) ? $inP : [], is_array($outP) ? $outP : []));

usort($chartRows, function($a, $b) use ($curType) {
    $aCurrent = (int)$a['review_type_id'] === $curType ? 1 : 0;
    $bCurrent = (int)$b['review_type_id'] === $curType ? 1 : 0;
    if ($bCurrent !== $aCurrent) return $bCurrent - $aCurrent;
    return (float)$b['avg_score'] <=> (float)$a['avg_score'];
});
$chartRows = array_slice($chartRows,0,12);
$chartLabels = json_encode(array_map(fn($r) => mb_strimwidth((string)$r['label'],0,26,'...'),$chartRows),JSON_UNESCAPED_UNICODE);
$chartScores = json_encode(array_map(fn($r) => round((float)$r['avg_score'],2),$chartRows),JSON_UNESCAPED_UNICODE);

/* ---------------------------------------------------------------
   RANKINGS TAB DATA
--------------------------------------------------------------- */
$rankBaseSql = "
    SELECT rt.review_type_id,rt.name AS rt_name,a.application_id,a.startup_name,
           COUNT(*) AS total_reviews,AVG(ar.pct) AS avg_score,
           MIN(ar.pct) AS min_score,MAX(ar.pct) AS max_score
    FROM review_types rt
    JOIN (SELECT rs.application_id,rs.reviewer_id,rs.review_type_id,
                 (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct
          FROM review_scores rs
          JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
          WHERE 1=1 {$inF} GROUP BY rs.application_id,rs.reviewer_id,rs.review_type_id) ar
        ON ar.review_type_id=rt.review_type_id
    JOIN applications a ON a.application_id=ar.application_id
    WHERE rt.is_active=1 {$outF}
";
$rankGroupSql = " GROUP BY rt.review_type_id,rt.name,a.application_id,a.startup_name HAVING total_reviews>0";
$rankTypes = $inT.$outT;
$rankParams = array_merge(is_array($inP) ? $inP : [], is_array($outP) ? $outP : []);

// Search filter for rankings
$rankSearchSql = '';
$rankSearchTypes = '';
$rankSearchParams = [];
if ($rankSearch !== '') {
    $rankSearchSql = " AND a.startup_name LIKE ? ";
    $rankSearchTypes = 's';
    $rankSearchParams = ['%'.$rankSearch.'%'];
}

$allRankRows = fetchAll($conn,$rankBaseSql.$rankSearchSql.$rankGroupSql." ORDER BY rt.name ASC,avg_score DESC",$rankTypes.$rankSearchTypes,array_merge(is_array($rankParams) ? $rankParams : [], is_array($rankSearchParams) ? $rankSearchParams : []));

// Group into per-type buckets with rank
$rankGroups = [];
foreach ($reviewTypes as $rt) {
    $rid = (int)$rt['review_type_id'];
    $rankGroups[$rid] = ['rt_name'=>$rt['name'],'rows'=>[]];
}
foreach ($allRankRows as $row) {
    $rid = (int)$row['review_type_id'];
    if (isset($rankGroups[$rid]) && count($rankGroups[$rid]['rows']) < $topN)
        $rankGroups[$rid]['rows'][] = $row;
}
foreach ($rankGroups as &$g) { $rn=1; foreach ($g['rows'] as &$r) $r['rank']=$rn++; unset($r); } unset($g);

$rankFlatTotal = count($allRankRows);
$rankPages = max(1,(int)ceil($rankFlatTotal/$perPage));
$rankPage  = min($rankPage,$rankPages);

/* ---------------------------------------------------------------
   REVIEWERS TAB DATA
--------------------------------------------------------------- */
$revSearchSql = '';
$revSearchTypes = '';
$revSearchParams = [];
if ($revSearch !== '') {
    $revSearchSql = " AND COALESCE(u.full_name,CONCAT('User #',x.reviewer_id)) LIKE ? ";
    $revSearchTypes = 's';
    $revSearchParams = ['%'.$revSearch.'%'];
}

$revCountRow = fetchOne($conn,"
    SELECT COUNT(*) AS cnt FROM (
        SELECT x.reviewer_id FROM (
            SELECT rs.reviewer_id,rs.application_id,rs.review_type_id,
                   (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct
            FROM review_scores rs
            JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
            WHERE 1=1 {$inF} GROUP BY rs.reviewer_id,rs.application_id,rs.review_type_id) x
        LEFT JOIN users u ON u.user_id=x.reviewer_id
        WHERE 1=1 {$revSearchSql}
        GROUP BY x.reviewer_id) sub
",$inT.$revSearchTypes,array_merge(is_array($inP) ? $inP : [], is_array($revSearchParams) ? $revSearchParams : []));

$revTotal = (int)($revCountRow['cnt'] ?? 0);
$revPages = max(1,(int)ceil($revTotal/$perPage));
$revPage  = min($revPage,$revPages);
$revOffset = ($revPage-1)*$perPage;

$reviewerRows = fetchAll($conn,"
    SELECT x.reviewer_id,COALESCE(u.full_name,CONCAT('User #',x.reviewer_id)) AS reviewer_name,
           COUNT(*) AS reviews_done,AVG(x.pct) AS avg_given,MIN(x.pct) AS min_given,MAX(x.pct) AS max_given
    FROM (SELECT rs.reviewer_id,rs.application_id,rs.review_type_id,
                 (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct
          FROM review_scores rs
          JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
          WHERE 1=1 {$inF} GROUP BY rs.reviewer_id,rs.application_id,rs.review_type_id) x
    LEFT JOIN users u ON u.user_id=x.reviewer_id
    WHERE 1=1 {$revSearchSql}
    GROUP BY x.reviewer_id,reviewer_name
    ORDER BY reviews_done DESC,reviewer_name ASC
    LIMIT {$perPage} OFFSET {$revOffset}
",$inT.$revSearchTypes,array_merge(is_array($inP) ? $inP : [], is_array($revSearchParams) ? $revSearchParams : []));

/* ---------------------------------------------------------------
   COMMENTS TAB DATA
--------------------------------------------------------------- */
$cmtWhere = "WHERE TRIM(COALESCE(ar.comments,''))<>''";
$cmtTypes = '';
$cmtParams = [];

if ($selId !== null) { $cmtWhere .= " AND ar.review_type_id=?"; $cmtTypes .= 'i'; $cmtParams[] = $selId; }
if ($cmtScoreMin !== null) { $cmtWhere .= " AND COALESCE(ar.overall_score,0)>=?"; $cmtTypes .= 'd'; $cmtParams[] = $cmtScoreMin; }
if ($cmtScoreMax !== null) { $cmtWhere .= " AND COALESCE(ar.overall_score,0)<=?"; $cmtTypes .= 'd'; $cmtParams[] = $cmtScoreMax; }
if ($cmtRec !== '') { $cmtWhere .= " AND ar.recommendation=?"; $cmtTypes .= 's'; $cmtParams[] = $cmtRec; }
if ($cmtSearch !== '') {
    $cmtWhere .= " AND (a.startup_name LIKE ? OR COALESCE(u.full_name,CONCAT('User #',ar.reviewer_id)) LIKE ? OR COALESCE(rt.name,'') LIKE ? OR COALESCE(ar.comments,'') LIKE ?)";
    $sl = '%'.$cmtSearch.'%';
    $cmtTypes .= 'ssss';
    array_push($cmtParams,$sl,$sl,$sl,$sl);
}

$cmtCountRow = fetchOne($conn,"SELECT COUNT(*) AS cnt FROM application_reviews ar JOIN applications a ON a.application_id=ar.application_id LEFT JOIN users u ON u.user_id=ar.reviewer_id LEFT JOIN review_types rt ON rt.review_type_id=ar.review_type_id {$cmtWhere}",$cmtTypes,$cmtParams);
$cmtTotal  = (int)($cmtCountRow['cnt'] ?? 0);
$cmtPages  = max(1,(int)ceil($cmtTotal/$perPage));
$cmtPage   = min($cmtPage,$cmtPages);
$cmtOffset = ($cmtPage-1)*$perPage;

$cmtRows = fetchAll($conn,"
    SELECT ar.review_id,ar.application_id,a.startup_name,ar.reviewer_id,
           COALESCE(u.full_name,CONCAT('User #',ar.reviewer_id)) AS reviewer_name,
           ar.review_type_id,rt.name AS rt_name,ar.overall_score,ar.recommendation,ar.comments,
           COALESCE(ar.updated_at,ar.created_at) AS ts
    FROM application_reviews ar
    JOIN applications a ON a.application_id=ar.application_id
    LEFT JOIN users u ON u.user_id=ar.reviewer_id
    LEFT JOIN review_types rt ON rt.review_type_id=ar.review_type_id
    {$cmtWhere}
    ORDER BY ts DESC,ar.review_id DESC
    LIMIT {$perPage} OFFSET {$cmtOffset}
",$cmtTypes,$cmtParams);

// Recommendation options for filter dropdown
$recRows = fetchAll($conn,
    "SELECT DISTINCT TRIM(COALESCE(recommendation,'')) AS rec
     FROM application_reviews
     WHERE TRIM(COALESCE(recommendation,'')) <> ''
     ORDER BY rec"
);
$recOptions = array_column($recRows, 'rec');

/* ---------------------------------------------------------------
   CSV EXPORT  (before any output)
--------------------------------------------------------------- */
if ($export === 'csv') {
    $csvTab = trim((string)($_GET['csv_tab'] ?? 'rankings'));
    while (ob_get_level()>0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="progress_'.$csvTab.'_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');

    if ($csvTab === 'rankings') {
        ims_fputcsv($out,['Review Type','Rank','Startup','Avg %','Reviews','Min %','Max %']);
        foreach ($rankGroups as $g) {
            foreach ($g['rows'] as $r)
                ims_fputcsv($out,[$g['rt_name'],$r['rank'],$r['startup_name'],number_format((float)$r['avg_score'],2),(int)$r['total_reviews'],number_format((float)$r['min_score'],1),number_format((float)$r['max_score'],1)]);
        }
    } elseif ($csvTab === 'reviewers') {
        $allRev = fetchAll($conn,"
            SELECT x.reviewer_id,COALESCE(u.full_name,CONCAT('User #',x.reviewer_id)) AS reviewer_name,
                   COUNT(*) AS reviews_done,AVG(x.pct) AS avg_given,MIN(x.pct) AS min_given,MAX(x.pct) AS max_given
            FROM (SELECT rs.reviewer_id,rs.application_id,rs.review_type_id,
                         (SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct
                  FROM review_scores rs JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id
                  WHERE 1=1 {$inF} GROUP BY rs.reviewer_id,rs.application_id,rs.review_type_id) x
            LEFT JOIN users u ON u.user_id=x.reviewer_id
            GROUP BY x.reviewer_id,reviewer_name ORDER BY reviews_done DESC
        ",$inT,$inP);
        ims_fputcsv($out,['Reviewer','Reviews Done','Avg % Given','Min %','Max %','Calibration']);
        foreach ($allRev as $r) {
            [$label] = calibration((float)$r['avg_given'] - $globalAvg);
            ims_fputcsv($out,[$r['reviewer_name'],(int)$r['reviews_done'],number_format((float)$r['avg_given'],2),number_format((float)$r['min_given'],1),number_format((float)$r['max_given'],1),$label]);
        }
    } else { // comments
        $allCmt = fetchAll($conn,"
            SELECT a.startup_name,COALESCE(u.full_name,CONCAT('User #',ar.reviewer_id)) AS reviewer_name,
                   rt.name AS rt_name,ar.overall_score,ar.recommendation,ar.comments,
                   COALESCE(ar.updated_at,ar.created_at) AS ts
            FROM application_reviews ar
            JOIN applications a ON a.application_id=ar.application_id
            LEFT JOIN users u ON u.user_id=ar.reviewer_id LEFT JOIN review_types rt ON rt.review_type_id=ar.review_type_id
            {$cmtWhere} ORDER BY ts DESC
        ",$cmtTypes,$cmtParams);
        ims_fputcsv($out,['Startup','Reviewer','Review Type','Score','Recommendation','Comment','Date']);
        foreach ($allCmt as $r)
            ims_fputcsv($out,[$r['startup_name'],$r['reviewer_name'],$r['rt_name']??'-',$r['overall_score']??'-',$r['recommendation']??'-',trim((string)$r['comments']),$r['ts']]);
    }
    fclose($out);
    exit;
}

/* ---------------------------------------------------------------
   PDF EXPORT  (before header.php output)
--------------------------------------------------------------- */
if ($export === 'pdf') {
    while (ob_get_level()>0) ob_end_clean();
    foreach ([__DIR__.'/vendor/autoload.php', dirname(__DIR__).'/vendor/autoload.php'] as $al)
        if (file_exists($al)) { require_once $al; break; }
    if (!class_exists('\Dompdf\Dompdf')) die('Dompdf is not installed.');

    $pdfTab = trim((string)($_GET['pdf_tab'] ?? 'rankings'));
    $logo = __DIR__.'/assets/img/logo.png';
    $logoUri = '';
    if (is_file($logo)) { $d=@file_get_contents($logo); if ($d) $logoUri='data:image/'.pathinfo($logo,PATHINFO_EXTENSION).';base64,'.base64_encode($d); }

    ob_start();
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
    @page{margin:90px 24px 56px}
    body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}
    .hdr{position:fixed;top:-70px;left:0;right:0;border-bottom:2px solid #f97316;padding-bottom:6px;font-size:9px;color:#555}
    .ftr{position:fixed;bottom:-36px;left:0;right:0;border-top:1px solid #ddd;font-size:8px;color:#888;text-align:center;padding-top:5px}
    h1{font-size:14px;margin:0 0 4px}
    h2{font-size:11px;margin:14px 0 6px;color:#f97316;border-bottom:1px solid #ddd;padding-bottom:4px}
    table{width:100%;border-collapse:collapse;margin-bottom:14px}
    th{background:#fff4ed;color:#c2410c;font-size:8px;text-align:left;padding:5px 7px;border:1px solid #fde68a}
    td{padding:5px 7px;border:1px solid #e5e7eb;font-size:9px;vertical-align:top}
    tr:nth-child(even) td{background:#fafafa}
    .meta{font-size:9px;color:#666;margin-bottom:14px}
    </style></head><body>';

    if ($logoUri) echo '<img src="'.$logoUri.'" style="width:48px;float:left;margin-right:10px">';
    echo '<h1>Progress Report — '.h($selLabel).'</h1>';
    echo '<div class="meta">Generated '.date('d M Y, H:i').' | Top '.$topN.' | Filter: '.h($selLabel).'</div>';

    if ($pdfTab === 'rankings') {
        echo '<h2>Startup Rankings</h2>';
        foreach ($rankGroups as $g) {
            if (empty($g['rows'])) continue;
            echo '<h3 style="font-size:10px;margin:10px 0 4px">'.h($g['rt_name']).'</h3><table><tr><th>Rank</th><th>Startup</th><th>Avg %</th><th>Reviews</th><th>Min-Max</th></tr>';
            foreach ($g['rows'] as $r)
                echo '<tr><td>'.(int)$r['rank'].'</td><td>'.h($r['startup_name']).'</td><td>'.number_format((float)$r['avg_score'],2).'%</td><td>'.(int)$r['total_reviews'].'</td><td>'.number_format((float)$r['min_score'],1).'-'.number_format((float)$r['max_score'],1).'%</td></tr>';
            echo '</table>';
        }
    } elseif ($pdfTab === 'reviewers') {
        echo '<h2>Reviewer Activity</h2><table><tr><th>Reviewer</th><th>Reviews</th><th>Avg %</th><th>Calibration</th></tr>';
        $allRev2 = fetchAll($conn,"SELECT x.reviewer_id,COALESCE(u.full_name,CONCAT('User #',x.reviewer_id)) AS rn,COUNT(*) AS rd,AVG(x.pct) AS avg FROM (SELECT rs.reviewer_id,rs.application_id,rs.review_type_id,(SUM(rs.score*COALESCE(rc.weight,1))/NULLIF(SUM(rc.max_score*COALESCE(rc.weight,1)),0))*100 AS pct FROM review_scores rs JOIN review_criteria rc ON rc.criteria_id=rs.criteria_id AND rc.review_type_id=rs.review_type_id WHERE 1=1 {$inF} GROUP BY rs.reviewer_id,rs.application_id,rs.review_type_id) x LEFT JOIN users u ON u.user_id=x.reviewer_id GROUP BY x.reviewer_id,rn ORDER BY rd DESC",$inT,$inP);
        foreach ($allRev2 as $r) {
            [$cl] = calibration((float)$r['avg'] - $globalAvg);
            echo '<tr><td>'.h($r['rn']).'</td><td>'.(int)$r['rd'].'</td><td>'.number_format((float)$r['avg'],2).'%</td><td>'.h($cl).'</td></tr>';
        }
        echo '</table>';
    } else {
        echo '<h2>Comments</h2><table><tr><th>Startup</th><th>Reviewer</th><th>Type</th><th>Score</th><th>Recommendation</th><th>Comment</th></tr>';
        $allCmt2 = fetchAll($conn,"SELECT a.startup_name,COALESCE(u.full_name,CONCAT('User #',ar.reviewer_id)) AS rn,rt.name AS rtn,ar.overall_score,ar.recommendation,ar.comments FROM application_reviews ar JOIN applications a ON a.application_id=ar.application_id LEFT JOIN users u ON u.user_id=ar.reviewer_id LEFT JOIN review_types rt ON rt.review_type_id=ar.review_type_id {$cmtWhere} ORDER BY ar.review_id DESC",$cmtTypes,$cmtParams);
        foreach ($allCmt2 as $r)
            echo '<tr><td>'.h($r['startup_name']).'</td><td>'.h($r['rn']).'</td><td>'.h($r['rtn']??'-').'</td><td>'.($r['overall_score']!==null?number_format((float)$r['overall_score'],2):'-').'</td><td>'.h($r['recommendation']??'-').'</td><td>'.nl2br(h(trim((string)$r['comments']))).'</td></tr>';
        echo '</table>';
    }
    echo '</body></html>';
    $html = ob_get_clean();

    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled',true);
    $opt->set('defaultFont','DejaVu Sans');
    $dp = new \Dompdf\Dompdf($opt);
    $dp->loadHtml($html);
    $dp->setPaper('A4','landscape');
    $dp->render();
    $dp->stream('progress_'.$pdfTab.'_'.date('Ymd').'.pdf',['Attachment'=>true]);
    exit;
}

/* ---------------------------------------------------------------
   BUILD BASE URL HELPERS
--------------------------------------------------------------- */
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once __DIR__ . '/includes/header.php';

$basePath = strtok(
    (string) ($_SERVER['REQUEST_URI'] ?? '/progress-board'),
    '?'
);

if ($basePath === false || $basePath === '') {
    $basePath = '/progress-board';
}

$baseQuery = [
    'top_n'       => $topN,
    'review_type' => $selType,
];

if ($selType === 'all' && $curType > 0) {
    $baseQuery['current_review_type'] = $curType;
}

/*
|--------------------------------------------------------------------------
| LegacyRunner-safe URL helpers
|--------------------------------------------------------------------------
|
| This legacy page is executed inside LegacyRunner::run(), so page variables
| may live in the runner method scope instead of PHP's global scope. The old
| tabUrl() helper used `global $baseQuery`, which returned null and caused
| array_merge() to throw a TypeError. Store the array explicitly in $GLOBALS.
|
*/

$GLOBALS['progress_board_base_query'] = $baseQuery;
$GLOBALS['progress_board_base_path']  = $basePath;

if (!function_exists('progressBoardBaseQuery')) {
    function progressBoardBaseQuery(): array
    {
        $query = $GLOBALS['progress_board_base_query'] ?? [];

        return is_array($query) ? $query : [];
    }
}

if (!function_exists('tabUrl')) {
    function tabUrl(string $tab, array $extra = []): string
    {
        $query = array_merge(
            progressBoardBaseQuery(),
            ['tab' => $tab],
            $extra
        );

        return '?' . http_build_query($query);
    }
}

if (!function_exists('pgUrl')) {
    function pgUrl(string $pgKey, int $page): string
    {
        $query = isset($_GET) && is_array($_GET) ? $_GET : [];
        $query[$pgKey] = max(1, $page);

        return '?' . http_build_query($query);
    }
}

if (!function_exists('exportUrl')) {
    function exportUrl(string $type, string $tab): string
    {
        $query = isset($_GET) && is_array($_GET) ? $_GET : [];
        $query['export'] = $type;
        $query[$type . '_tab'] = $tab;

        return '?' . http_build_query($query);
    }
}

$tabs = [
    'overview'  => 'Overview',
    'rankings'  => 'Rankings',
    'reviewers' => 'Reviewers',
    'comments'  => 'Comments',
];

if (!array_key_exists($activeTab, $tabs)) {
    $activeTab = 'overview';
}
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/progress_board.css">

<style>
/* Progress Board: page-only layout (pb- prefix; shared rules live in css/progress_board.css) */
.pb-hero-filters { position: relative; z-index: 1; }
.pb-hero-filters .form-control,
.pb-hero-filters .btn { height: 38px; font-size: var(--fs-sm); }
.pb-hero-filters .form-control { padding: 0 10px; }
.pb-hero-filters select.form-control { padding: 0 36px 0 12px; }
.pb-ctl { height: 38px; padding: 0 10px; font-size: var(--fs-sm); }
select.pb-ctl { padding: 0 36px 0 10px; }
.pb-in-topn { width: 90px; }
.pb-in-score { width: 110px; }
.pb-sel-type { min-width: 180px; }
.pb-sel-current { min-width: 200px; }
.pb-sel-rec { min-width: 160px; }
.pb-ring-stat { padding: 16px 20px; }
.pb-comp-note { padding: 0 20px 16px; }
.pb-rank-panel { margin-bottom: 16px; }
.pb-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pb-empty-row { text-align: center; padding: 32px; color: var(--ink-200); }
.pb-strong { font-weight: 700; }
.pb-reviewer-link { color: var(--brand-600); text-decoration: none; }
.pb-reviewer-link:hover { text-decoration: underline; }
.pb-brand { color: var(--brand-600); }
.pb-num { font-variant-numeric: tabular-nums; }
.pb-ts { font-size: 11px; }
</style>

<div class="page-stack pb-wrap">

<!-- -- Page Hero ------------------------------------------------ -->
<div class="page-hero">
    <div>
        <h1><i class="fas fa-chart-line" aria-hidden="true"></i> Progress Report</h1>
        <p>Startup Evaluation Dashboard &mdash; <?= h($selLabel) ?></p>
    </div>
    <div class="hero-actions">
        <!-- Global filter form -->
        <form method="GET" class="filters-bar pb-hero-filters" aria-label="Report filters">
            <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
            <input type="number" name="top_n" value="<?= $topN ?>" min="1" max="100" class="form-control pb-in-topn" title="Top N per type">
            <select name="review_type" class="form-control pb-sel-type" onchange="this.form.submit()">
                <option value="all" <?= $selType==='all'?'selected':'' ?>>All Review Types</option>
                <?php foreach ($reviewTypes as $rt): ?>
                    <option value="<?= (int)$rt['review_type_id'] ?>" <?= ((string)(int)$rt['review_type_id']===$selType)?'selected':'' ?>><?= h($rt['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($selType==='all'): ?>
            <select name="current_review_type" class="form-control pb-sel-current" title="Show this type's startups first in charts">
                <?php foreach ($reviewTypes as $rt): ?>
                    <option value="<?= (int)$rt['review_type_id'] ?>" <?= ((int)$rt['review_type_id']===$curType)?'selected':'' ?>>Current first: <?= h($rt['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="<?= h($basePath) ?>" class="btn">Clear</a>
            
             <a href="manage_application_scores" class="btn">Scores</a>
            
        </form>
    </div>
</div>

<!-- -- Stats strip ---------------------------------------------- -->
<div class="stats-grid pb-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-building"></i></div>
        <div class="stat-info"><span class="stat-label">Startups</span><span class="stat-value"><?= $totalStartups ?></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-star"></i></div>
        <div class="stat-info"><span class="stat-label">Review Sets</span><span class="stat-value"><?= $totalReviews ?></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-percent"></i></div>
        <div class="stat-info"><span class="stat-label">Avg Score</span><span class="stat-value"><?= number_format($globalAvg,1) ?>%</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-layer-group"></i></div>
        <div class="stat-info"><span class="stat-label">Review Types</span><span class="stat-value"><?= $selId!==null?1:count($reviewTypes) ?></span></div>
    </div>
    <!-- Completion ring -->
    <div class="stat-card pb-ring-stat">
        <?php
        $pct  = round($compRate);
        $r    = 28;
        $circ = 2*M_PI*$r;
        $dash = ($pct/100)*$circ;
        ?>
        <div class="ring-card">
            <div class="ring-svg-wrap">
                <svg viewBox="0 0 64 64" width="64" height="64">
                    <circle cx="32" cy="32" r="<?= $r ?>" fill="none" stroke="var(--ink-100)" stroke-width="6"/>
                    <circle cx="32" cy="32" r="<?= $r ?>" fill="none" stroke="var(--green-fg)" stroke-width="6"
                            stroke-dasharray="<?= round($dash,2) ?> <?= round($circ,2) ?>"
                            stroke-linecap="round" transform="rotate(-90 32 32)"/>
                </svg>
                <div class="ring-pct"><?= $pct ?>%</div>
            </div>
            <div>
                <div class="ring-info-label">Completion</div>
                <div class="ring-info-value"><?= $compAsgn ?> / <?= $totalAsgn ?></div>
                <div class="ring-info-sub">fully scored sets</div>
            </div>
        </div>
    </div>
</div>

<!-- -- Tab navigation ------------------------------------------- -->
<nav class="tab-nav" role="tablist">
    <?php foreach ($tabs as $key=>$label): ?>
    <a href="<?= h(tabUrl($key)) ?>" class="tab-btn<?= $activeTab===$key?' active':'' ?>" role="tab" aria-selected="<?= $activeTab===$key?'true':'false' ?>">
        <?php
        $icons=['overview'=>'fa-gauge-high','rankings'=>'fa-trophy','reviewers'=>'fa-users','comments'=>'fa-comments'];
        ?>
        <i class="fas <?= $icons[$key] ?>"></i>
        <?= $label ?>
        <?php if ($key==='rankings'): ?><span class="tab-badge"><?= array_sum(array_map(fn($g)=>count($g['rows']),$rankGroups)) ?></span><?php endif; ?>
        <?php if ($key==='reviewers'): ?><span class="tab-badge"><?= $revTotal ?></span><?php endif; ?>
        <?php if ($key==='comments'): ?><span class="tab-badge"><?= $cmtTotal ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</nav>

<!-- --------------------------------------------------------------
     TAB: OVERVIEW
-------------------------------------------------------------- -->
<?php if ($activeTab==='overview'): ?>
<div class="tab-panel active">

    <!-- Completion breakdown -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-tasks"></i> Review Completion Breakdown</h3>
            <span class="note"><?= number_format($compRate,1) ?>% complete &mdash; <?= $totalAsgn ?> total assignments</span>
        </div>
        <div class="comp-grid">
            <?php foreach ([['Completed',$compAsgn,'green'],['In Progress',$inpAsgn,'yellow'],['Pending',$pendAsgn,'blue']] as [$lbl,$val,$cls]):
                $pctI = $totalAsgn>0?round(($val/$totalAsgn)*100):0; ?>
            <div class="comp-item">
                <div class="comp-label"><span><?= $lbl ?></span><span><?= $val ?> / <?= $totalAsgn ?> (<?= $pctI ?>%)</span></div>
                <div class="comp-track"><div class="comp-fill <?= $cls ?>" style="width:<?= $pctI ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="pb-comp-note"><span class="subtle-note">Completion is based on actual scored criteria vs active required criteria per review type.</span></div>
    </div>

    <!-- Charts -->
    <div class="pb-charts">
        <div class="pb-chart-card">
            <h3>Score Distribution</h3>
            <div class="pb-chart-wrap"><canvas id="distChart"></canvas></div>
        </div>
        <div class="pb-chart-card">
            <h3><?= $selId!==null?'Top Startups - '.h($selLabel):'Top 12 - Current Review Type First' ?></h3>
            <div class="pb-chart-wrap"><canvas id="rankChart"></canvas></div>
        </div>
    </div>

</div>

<!-- --------------------------------------------------------------
     TAB: RANKINGS
-------------------------------------------------------------- -->
<?php elseif ($activeTab==='rankings'): ?>
<div class="tab-panel active">
    <?php foreach ($rankGroups as $rid=>$group):
        if (empty($group['rows'])) continue; ?>
    <div class="panel pb-rank-panel">
        <div class="panel-head">
            <h3><i class="fas fa-trophy"></i> <?= h($group['rt_name']) ?></h3>
            <div class="pb-head-actions">
                <span class="note">Showing <?= count($group['rows']) ?> of top <?= $topN ?></span>
                <a href="<?= h(exportUrl('csv','rankings')) ?>&rt_id=<?= $rid ?>" class="btn-export csv"><i class="fas fa-file-csv"></i> CSV</a>
                <a href="<?= h(exportUrl('pdf','rankings')) ?>&rt_id=<?= $rid ?>" class="btn-export pdf"><i class="fas fa-file-pdf"></i> PDF</a>
            </div>
        </div>

        <!-- Search -->
        <div class="pb-toolbar">
            <form method="GET" class="filters-bar" role="search">
                <?php foreach (array_merge(is_array($baseQuery) ? $baseQuery : [], ['tab'=>'rankings']) as $k=>$v): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>">
                <?php endforeach; ?>
                <div class="pb-toolbar-search">
                    <i class="fas fa-search search-icon"></i>
                    <input type="search" name="rank_q" value="<?= h($rankSearch) ?>" placeholder="Search startup..." autocomplete="off">
                </div>
                <button type="submit" class="btn btn-sm btn-dark"><i class="fas fa-search"></i></button>
                <?php if ($rankSearch!==''): ?>
                    <a href="<?= h(tabUrl('rankings')) ?>" class="btn btn-sm btn-gray"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Rank</th><th>Startup</th><th>Avg %</th><th>Reviews</th><th>Score Range</th></tr></thead>
                <tbody>
                <?php if (empty($group['rows'])): ?>
                    <tr><td colspan="5" class="pb-empty-row">No results.</td></tr>
                <?php else: ?>
                    <?php foreach ($group['rows'] as $row):
                        $score = (float)$row['avg_score'];
                        $fc    = score_class($score);
                        $rc    = rank_class((int)$row['rank']);
                    ?>
                    <tr>
                        <td><span class="rank-badge <?= $rc ?>"><?= (int)$row['rank'] ?></span></td>
                        <td class="pb-strong"><?= h($row['startup_name']) ?></td>
                        <td>
                            <div class="score-bar-wrap">
                                <div class="score-bar-track"><div class="score-bar-fill <?= $fc ?>" style="width:<?= min(100,$score) ?>%"></div></div>
                                <span class="score-val"><?= number_format($score,2) ?>%</span>
                            </div>
                        </td>
                        <td><span class="pill blue"><?= (int)$row['total_reviews'] ?></span></td>
                        <td class="note"><?= number_format((float)$row['min_score'],1) ?>% - <?= number_format((float)$row['max_score'],1) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- --------------------------------------------------------------
     TAB: REVIEWERS
-------------------------------------------------------------- -->
<?php elseif ($activeTab==='reviewers'): ?>
<div class="tab-panel active">
<div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-users"></i> Reviewer Activity</h3>
        <div class="pb-head-actions">
            <span class="note"><?= $revTotal ?> reviewer<?= $revTotal!==1?'s':'' ?></span>
            <a href="<?= h(exportUrl('csv','reviewers')) ?>" class="btn-export csv"><i class="fas fa-file-csv"></i> CSV</a>
            <a href="<?= h(exportUrl('pdf','reviewers')) ?>" class="btn-export pdf"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <div class="pb-toolbar">
        <form method="GET" class="filters-bar" role="search">
            <?php foreach (array_merge(is_array($baseQuery) ? $baseQuery : [], ['tab'=>'reviewers']) as $k=>$v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>">
            <?php endforeach; ?>
            <div class="pb-toolbar-search">
                <i class="fas fa-search search-icon"></i>
                <input type="search" name="rev_q" value="<?= h($revSearch) ?>" placeholder="Search reviewer..." autocomplete="off">
            </div>
            <button type="submit" class="btn btn-sm btn-dark"><i class="fas fa-search"></i></button>
            <?php if ($revSearch!==''): ?>
                <a href="<?= h(tabUrl('reviewers')) ?>" class="btn btn-sm btn-gray"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="pb-meta">
        <span><strong><?= $revTotal ?></strong> reviewer<?= $revTotal!==1?'s':'' ?> &mdash; global avg <?= number_format($globalAvg,1) ?>%</span>
        <span>Page <strong><?= $revPage ?></strong> of <strong><?= $revPages ?></strong></span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Reviewer</th><th>Reviews Done</th><th>Avg % Given</th><th>Range</th><th>vs Global Avg</th><th>Calibration</th></tr></thead>
            <tbody>
            <?php if (empty($reviewerRows)): ?>
                <tr><td colspan="6" class="pb-empty-row">No reviewer activity found.</td></tr>
            <?php else: ?>
                <?php
                $maxDone = max(array_map(fn($r)=>(int)$r['reviews_done'],$reviewerRows)?:[1]);
                foreach ($reviewerRows as $r):
                    $avg  = (float)$r['avg_given'];
                    $diff = $avg - $globalAvg;
                    [$biasLbl,$biasCls] = calibration($diff);
                    $doneW = $maxDone>0?((int)$r['reviews_done']/$maxDone)*100:0;
                ?>
                <tr>
                    <td class="pb-strong">
                        <a href="manage_reviewer_scores?reviewer_id=<?= (int)$r['reviewer_id'] ?>" class="pb-reviewer-link"><?= h($r['reviewer_name']) ?></a>
                    </td>
                    <td>
                        <div class="score-bar-wrap">
                            <div class="score-bar-track"><div class="score-bar-fill" style="width:<?= $doneW ?>%"></div></div>
                            <span class="score-val pb-brand"><?= (int)$r['reviews_done'] ?></span>
                        </div>
                    </td>
                    <td><span class="note pb-num"><?= number_format($avg,2) ?>%</span></td>
                    <td class="note"><?= number_format((float)$r['min_given'],1) ?>% - <?= number_format((float)$r['max_given'],1) ?>%</td>
                    <td><span class="<?= $diff>0?'diff-pos':($diff<0?'diff-neg':'diff-neu') ?>"><?= $diff>=0?'+':'' ?><?= number_format($diff,2) ?>%</span></td>
                    <td><span class="pill <?= $biasCls ?>"><?= $biasLbl ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($revPages>1): ?>
    <div class="pb-pagination">
        <span class="subtle-note">Page <?= $revPage ?> of <?= $revPages ?></span>
        <div class="pg-links">
            <a href="<?= h(pgUrl('rev_page',$revPage-1)) ?>" class="pg-btn <?= $revPage<=1?'pg-disabled':'' ?>"><i class="fas fa-chevron-left"></i></a>
            <?php foreach (pgRange($revPage,$revPages) as $pn): ?>
                <?php if ($pn==='...'): ?><span class="pg-ellipsis">...</span>
                <?php elseif ($pn===$revPage): ?><span class="pg-btn pg-active"><?= $pn ?></span>
                <?php else: ?><a href="<?= h(pgUrl('rev_page',(int)$pn)) ?>" class="pg-btn"><?= $pn ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a href="<?= h(pgUrl('rev_page',$revPage+1)) ?>" class="pg-btn <?= $revPage>=$revPages?'pg-disabled':'' ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- --------------------------------------------------------------
     TAB: COMMENTS
-------------------------------------------------------------- -->
<?php elseif ($activeTab==='comments'): ?>
<div class="tab-panel active">
<div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-comments"></i> Detailed Comments</h3>
        <div class="pb-head-actions">
            <span class="note"><?= $cmtTotal ?> comment<?= $cmtTotal!==1?'s':'' ?></span>
            <a href="<?= h(exportUrl('csv','comments')) ?>" class="btn-export csv"><i class="fas fa-file-csv"></i> CSV</a>
            <a href="<?= h(exportUrl('pdf','comments')) ?>" class="btn-export pdf"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <!-- Comment filters -->
    <div class="pb-toolbar">
        <form method="GET" class="filters-bar" role="search">
            <?php foreach (array_merge(is_array($baseQuery) ? $baseQuery : [], ['tab'=>'comments']) as $k=>$v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>">
            <?php endforeach; ?>
            <div class="pb-toolbar-search">
                <i class="fas fa-search search-icon"></i>
                <input type="search" name="cmt_q" value="<?= h($cmtSearch) ?>" placeholder="Search startup, reviewer, comment..." autocomplete="off">
            </div>
            <input type="number" name="cmt_min" value="<?= $cmtScoreMin!==null?h((string)$cmtScoreMin):'' ?>" placeholder="Score min" step="0.01" class="form-control pb-ctl pb-in-score">
            <input type="number" name="cmt_max" value="<?= $cmtScoreMax!==null?h((string)$cmtScoreMax):'' ?>" placeholder="Score max" step="0.01" class="form-control pb-ctl pb-in-score">
            <select name="cmt_rec" class="form-control pb-ctl pb-sel-rec">
                <option value="">All recommendations</option>
                <?php foreach ($recOptions as $rec): ?>
                    <option value="<?= h($rec) ?>" <?= $cmtRec===$rec?'selected':'' ?>><?= h($rec) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-dark"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($cmtSearch!==''||$cmtScoreMin!==null||$cmtScoreMax!==null||$cmtRec!==''): ?>
                <a href="<?= h(tabUrl('comments')) ?>" class="btn btn-sm btn-gray"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Active filter chips -->
    <?php if ($cmtSearch!==''||$cmtScoreMin!==null||$cmtScoreMax!==null||$cmtRec!==''): ?>
    <div class="active-filters">
        <?php if ($cmtSearch!==''): ?><span class="filter-chip">Search: "<?= h($cmtSearch) ?>" <a href="<?= h(tabUrl('comments',['cmt_q'=>''])) ?>">x</a></span><?php endif; ?>
        <?php if ($cmtScoreMin!==null): ?><span class="filter-chip">Min: <?= h((string)$cmtScoreMin) ?> <a href="<?= h(tabUrl('comments',['cmt_min'=>''])) ?>">x</a></span><?php endif; ?>
        <?php if ($cmtScoreMax!==null): ?><span class="filter-chip">Max: <?= h((string)$cmtScoreMax) ?> <a href="<?= h(tabUrl('comments',['cmt_max'=>''])) ?>">x</a></span><?php endif; ?>
        <?php if ($cmtRec!==''): ?><span class="filter-chip">Rec: <?= h($cmtRec) ?> <a href="<?= h(tabUrl('comments',['cmt_rec'=>''])) ?>">x</a></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="pb-meta">
        <span><strong><?= $cmtTotal ?></strong> filtered comment<?= $cmtTotal!==1?'s':'' ?></span>
        <span>Page <strong><?= $cmtPage ?></strong> of <strong><?= $cmtPages ?></strong></span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>Startup</th><th>Reviewer</th><th>Review Type</th>
                <th>Score</th><th>Recommendation</th><th>Comment</th><th>Date</th>
            </tr></thead>
            <tbody>
            <?php if (empty($cmtRows)): ?>
                <tr><td colspan="7" class="pb-empty-row">No comments match your filters.</td></tr>
            <?php else: ?>
                <?php foreach ($cmtRows as $row): ?>
                <tr>
                    <td class="pb-strong"><?= h($row['startup_name']) ?></td>
                    <td><?= h($row['reviewer_name']) ?></td>
                    <td><span class="pill green"><?= h($row['rt_name']??'—') ?></span></td>
                    <td class="note"><?= $row['overall_score']!==null&&$row['overall_score']!==''?number_format((float)$row['overall_score'],2):'—' ?></td>
                    <td><?= h((string)($row['recommendation']??'—')) ?></td>
                    <td class="comment-cell" title="<?= h((string)($row['comments']??'')) ?>">
                        <?= h(limit_words((string)($row['comments']??''))) ?>
                    </td>
                    <td class="note pb-num pb-ts"><?= h((string)$row['ts']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($cmtPages>1): ?>
    <div class="pb-pagination">
        <span class="subtle-note">Page <?= $cmtPage ?> of <?= $cmtPages ?></span>
        <div class="pg-links">
            <a href="<?= h(pgUrl('cmt_page',$cmtPage-1)) ?>" class="pg-btn <?= $cmtPage<=1?'pg-disabled':'' ?>"><i class="fas fa-chevron-left"></i></a>
            <?php foreach (pgRange($cmtPage,$cmtPages) as $pn): ?>
                <?php if ($pn==='...'): ?><span class="pg-ellipsis">...</span>
                <?php elseif ($pn===$cmtPage): ?><span class="pg-btn pg-active"><?= $pn ?></span>
                <?php else: ?><a href="<?= h(pgUrl('cmt_page',(int)$pn)) ?>" class="pg-btn"><?= $pn ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a href="<?= h(pgUrl('cmt_page',$cmtPage+1)) ?>" class="pg-btn <?= $cmtPage>=$cmtPages?'pg-disabled':'' ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>

</div><!-- /.page-stack -->

<script>
<?php if ($activeTab==='overview'): ?>
Chart.defaults.color       = '#374151';
Chart.defaults.font.family = "'DM Sans',system-ui,sans-serif";
Chart.defaults.font.size   = 11;

const gridColor = 'rgba(209,213,219,.5)';

/* Score distribution chart */
new Chart(document.getElementById('distChart'),{
    type:'bar',
    data:{
        labels:['0-20%','20-40%','40-60%','60-80%','80-100%','100%+'],
        datasets:[{
            label:'Reviews',
            data:[<?= (int)($distRow['b0']??0) ?>,<?= (int)($distRow['b20']??0) ?>,<?= (int)($distRow['b40']??0) ?>,<?= (int)($distRow['b60']??0) ?>,<?= (int)($distRow['b80']??0) ?>,<?= (int)($distRow['b100']??0) ?>],
            backgroundColor:['#fecaca','#fde68a','#fde68a','#bfdbfe','#bbf7d0','#6ee7b7'],
            borderRadius:6,borderSkipped:false
        }]
    },
    options:{
        responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false}},
        scales:{x:{grid:{color:gridColor}},y:{grid:{color:gridColor},beginAtZero:true,ticks:{precision:0}}}
    }
});

/* Top startups horizontal bar */
new Chart(document.getElementById('rankChart'),{
    type:'bar',
    data:{
        labels:<?= $chartLabels?:'[]' ?>,
        datasets:[{
            label:'Avg %',
            data:<?= $chartScores?:'[]' ?>,
            backgroundColor:(ctx)=>{const g=ctx.chart.ctx.createLinearGradient(0,0,ctx.chart.width,0);g.addColorStop(0,'rgba(249,115,22,.85)');g.addColorStop(1,'rgba(251,191,36,.85)');return g;},
            borderRadius:6,borderSkipped:false
        }]
    },
    options:{
        indexAxis:'y',responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false}},
        scales:{
            x:{grid:{color:gridColor},max:100,beginAtZero:true},
            y:{grid:{display:false},ticks:{font:{size:10}}}
        }
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>