<?php
ob_start();
session_start();
$page_title = 'View Review';


require_once 'includes/config.php';

/* ---------------------------------------------------------------
   Access control
--------------------------------------------------------------- */
if (!ims_allowed('reviews.view', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer', 'Donor/Partner', 'Staff', 'Reviewer', 'HR'], 'View submitted reviews')) {
    $_SESSION['error'] = "Access denied.";
    header("Location: dashboard");
    exit();
}

$review_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($review_id <= 0) {
    $_SESSION['error'] = "No review specified.";
    header("Location: dashboard");
    exit();
}

$viewer_id   = (int)($_SESSION['user_id'] ?? 0);
$viewer_role = (string)($_SESSION['role'] ?? '');

/* ---------------------------------------------------------------
   Load review
--------------------------------------------------------------- */
$review = null;
$st = $conn->prepare("
    SELECT  ar.*,
            a.startup_name, a.sector, a.business_stage,
            a.team_size, a.contact_person, a.submitted_at, a.status AS app_status,
            a.website, a.current_revenue, a.funding_sought,
            ao.opportunity_title, ao.opportunity_type,
            u_rev.full_name  AS reviewer_name,
            u_rev.email      AS reviewer_email,
            u_app.full_name  AS applicant_name
    FROM    application_reviews ar
    JOIN    applications a               ON a.application_id  = ar.application_id
    JOIN    application_opportunities ao ON ao.opportunity_id = a.opportunity_id
    JOIN    users u_rev                  ON u_rev.user_id     = ar.reviewer_id
    JOIN    users u_app                  ON u_app.user_id     = a.submitted_by
    WHERE   ar.review_id = ?
    LIMIT 1
");
if (!$st) {
    $_SESSION['error'] = "Database error: " . $conn->error;
    header("Location: dashboard");
    exit();
}
$st->bind_param("i", $review_id);
$st->execute();
$review = $st->get_result()->fetch_assoc();
$st->close();

if (!$review) {
    $_SESSION['error'] = "Review not found.";
    header("Location: dashboard");
    exit();
}

/* Reviewers may only view their own reviews */
if ($viewer_role === 'Reviewer' && (int)$review['reviewer_id'] !== $viewer_id) {
    $_SESSION['error'] = "You can only view your own reviews.";
    header("Location: dashboard");
    exit();
}

/* ---------------------------------------------------------------
   Load scoring criteria
--------------------------------------------------------------- */
$criteria = [];
$cr = $conn->prepare("
    SELECT
        rc.criteria_id,
        rc.category,
        rc.question,
        rc.description,
        rc.max_score,
        COALESCE(arc.score, 0) AS given_score
    FROM   review_criteria rc
    LEFT JOIN application_review_criteria arc
           ON arc.criteria_id = rc.criteria_id
          AND arc.review_id   = ?
    WHERE  rc.is_active = 1
    ORDER  BY rc.category, rc.criteria_id
");
if (!$cr) {
    $_SESSION['error'] = "Database error: " . $conn->error;
    header("Location: dashboard");
    exit();
}
$cr->bind_param("i", $review_id);
$cr->execute();
$res = $cr->get_result();
while ($row = $res->fetch_assoc()) {
    $row['max_score']   = (int)$row['max_score'];
    $row['given_score'] = (float)$row['given_score'];
    // clamp to valid range
    if ($row['given_score'] < 0)                       $row['given_score'] = 0;
    if ($row['given_score'] > $row['max_score'])        $row['given_score'] = $row['max_score'];
    $criteria[] = $row;
}
$cr->close();

$criteria_grouped = [];
foreach ($criteria as $c) {
    $criteria_grouped[$c['category']][] = $c;
}

/* ---------------------------------------------------------------
   Calculate totals
--------------------------------------------------------------- */
$cat_totals   = [];
$total_scored = 0.0;
$total_max    = 0;

if (!empty($criteria)) {
    foreach ($criteria_grouped as $cat => $list) {
        $cat_scored = 0.0;
        $cat_max    = 0;
        foreach ($list as $ci) {
            $cat_scored += (float)$ci['given_score'];
            $cat_max    += (int)$ci['max_score'];
        }
        $cat_totals[$cat] = ['scored' => $cat_scored, 'max' => $cat_max];
        $total_scored += $cat_scored;
        $total_max    += $cat_max;
    }
} else {
    // Fall back to stored overall score when no criteria rows exist
    $total_scored = (float)($review['overall_score'] ?? 0);
    $total_max    = 0;
}

$overall_pct = ($total_max > 0) ? round(($total_scored / $total_max) * 100) : 0;
$overall_pts = ($total_max > 0)
    ? (int)round($total_scored)
    : (int)round((float)($review['overall_score'] ?? 0));

/* ---------------------------------------------------------------
   Helpers
--------------------------------------------------------------- */
/**
 * Return a colour hex string based on percentage score.
 */
function score_color(float $pct): string {
    return $pct >= 70 ? '#2ECC71' : ($pct >= 40 ? '#F39C12' : '#E74C3C');
}

$rec_styles = [
    'Accept'    => ['#2ECC71', 'fa-check-circle',  '#d4edda', '#155724'],
    'Reject'    => ['#E74C3C', 'fa-times-circle',  '#f8d7da', '#721c24'],
    'Shortlist' => ['#ff9800', 'fa-star',          '#d1ecf1', '#0c5460'],
    'Hold'      => ['#F39C12', 'fa-pause-circle',  '#fff3cd', '#856404'],
];
$rec = $review['recommendation'] ?? 'Hold';
$rs  = $rec_styles[$rec] ?? $rec_styles['Hold'];

$app_status_colors = [
    'Pending'     => '#F39C12', 'Shortlisted' => '#ff9800',
    'Accepted'    => '#2ECC71', 'Rejected'    => '#E74C3C',
    'Withdrawn'   => '#95a5a6',
];

$page_title = 'View Applicant Review';
require_once 'includes/header.php';
?>
<!----------------- STYLES ----------------------------------------->
<style>
/* -- Design tokens ----------------------------------------------- */
:root {
    --blue:    #ff9800;
    --green:   #2ECC71;
    --red:     #E74C3C;
    --amber:   #F39C12;
    --text:    #1e293b;
    --sub:     #475569;
    --muted:   #94a3b8;
    --border:  #e2e8f0;
    --bg:      #f8fafc;
    --surface: #ffffff;
    --r:       12px;
    --sh:      0 1px 4px rgba(0,0,0,.07), 0 4px 18px rgba(0,0,0,.07);
}

/* -- Layout ------------------------------------------------------- */
.vr-wrap { max-width:1100px; margin:0 auto; padding-bottom:60px; }
.vr-grid { display:grid; grid-template-columns:1fr 340px; gap:22px; align-items:start; }
@media(max-width:900px){ .vr-grid { grid-template-columns:1fr; } }

/* -- Page header -------------------------------------------------  */
.vr-hdr {
    background: linear-gradient(130deg,#ff5722 0%,#ff9800 100%);
    color:#fff; padding:26px 32px; border-radius:14px; margin-bottom:24px;
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:14px; flex-wrap:wrap;
    box-shadow:0 6px 28px rgba(255,87,34,.3);
}
.vr-hdr h1 {
    font-size:21px; font-weight:800; margin:0 0 4px;
    display:flex; align-items:center; gap:10px;
}
.vr-hdr p  { font-size:13px; opacity:.78; margin:0; }
.vr-hdr .meta { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
.meta-pill {
    background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
    border-radius:20px; padding:4px 13px; font-size:12px; font-weight:600;
    display:flex; align-items:center; gap:6px;
}

/* -- Cards -------------------------------------------------------  */
.card {
    background:var(--surface); border-radius:var(--r);
    box-shadow:var(--sh); border:1px solid var(--border); margin-bottom:20px;
    overflow:hidden;
}
.card-hdr {
    padding:14px 20px; border-bottom:1px solid var(--border);
    background:#fafbfd; display:flex; align-items:center;
    justify-content:space-between; gap:10px;
}
.card-hdr h3 {
    font-size:14px; font-weight:700; color:var(--text);
    display:flex; align-items:center; gap:8px; margin:0;
}
.card-hdr h3 i { color:var(--blue); }
.card-body { padding:20px; }

/* -- Recommendation hero -----------------------------------------  */
.rec-hero {
    border-radius:var(--r); padding:22px 24px; margin-bottom:20px;
    display:flex; align-items:center; gap:18px;
    border:2px solid; box-shadow:var(--sh);
}
.rec-icon-wrap {
    width:60px; height:60px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:26px; flex-shrink:0;
}
.rec-label {
    font-size:11px; font-weight:700; text-transform:uppercase;
    letter-spacing:.5px; opacity:.7; margin-bottom:3px;
}
.rec-value { font-size:26px; font-weight:800; line-height:1; }
.rec-type  { font-size:13px; margin-top:6px; opacity:.75; }

/* -- Overall score dial ------------------------------------------  */
.score-dial-wrap { text-align:center; padding:18px 0 10px; }
.score-dial {
    position:relative; width:110px; height:110px;
    border-radius:50%; margin:0 auto 10px;
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 4px 18px rgba(0,0,0,.12);
}
.score-dial::before {
    content:''; position:absolute; inset:8px;
    border-radius:50%; background:#fff;
}
.score-dial-inner {
    position:relative; z-index:1;
    display:flex; flex-direction:column; align-items:center;
}
.score-dial .sd-text { font-size:22px; font-weight:800; color:var(--text); line-height:1; }
.score-dial .sd-sub  { font-size:11px; color:var(--muted); margin-top:3px; font-weight:700; }

/* -- Category score bars -----------------------------------------  */
.cat-bar-wrap { margin-bottom:14px; }
.cat-bar-row  {
    display:flex; align-items:center; justify-content:space-between;
    font-size:12.5px; color:var(--sub); margin-bottom:4px; font-weight:600;
}
.cat-bar-track { height:9px; background:#e9eef5; border-radius:5px; overflow:hidden; }
.cat-bar-fill  { height:100%; border-radius:5px; transition:width .5s ease; }
.cat-score-badge {
    font-size:11.5px; font-weight:700;
    padding:2px 8px; border-radius:12px;
    background:#f0f7ff; color:#ff9800; border:1px solid #bfdbfe;
    white-space:nowrap;
}

/* -- Criteria list -----------------------------------------------  */
.crit-item { padding:13px 0; border-bottom:1px solid var(--border); }
.crit-item:last-child { border-bottom:none; }
.crit-q    { font-size:13px; font-weight:600; color:var(--text); margin-bottom:3px; }
.crit-desc { font-size:12px; color:var(--muted); margin-bottom:8px; line-height:1.5; }
.crit-score-row { display:flex; align-items:center; gap:10px; }
.crit-bar-track { flex:1; height:7px; background:#e9eef5; border-radius:4px; overflow:hidden; }
.crit-bar-fill  { height:100%; border-radius:4px; transition:width .35s ease; }
.crit-badge {
    font-size:12.5px; font-weight:700; padding:3px 10px; border-radius:8px;
    color:#fff; min-width:72px; text-align:center; white-space:nowrap;
}

/* -- Info grid ---------------------------------------------------  */
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
@media(max-width:600px){ .info-grid { grid-template-columns:1fr; } }
.info-item { background:var(--bg); border-radius:8px; padding:11px 13px; }
.info-item .il {
    font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.5px; color:var(--muted); margin-bottom:3px;
}
.info-item .iv { font-size:13px; font-weight:600; color:var(--text); }

/* -- Reviewer card -----------------------------------------------  */
.reviewer-pill {
    display:flex; align-items:center; gap:12px;
    padding:14px 16px; background:var(--bg);
    border-radius:10px; border:1px solid var(--border);
}
.reviewer-avatar {
    width:42px; height:42px; border-radius:50%;
    background:linear-gradient(135deg,#ff9800,#e65100);
    color:#fff; font-size:17px; font-weight:700;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.reviewer-name  { font-size:14px; font-weight:700; color:var(--text); }
.reviewer-email { font-size:12px; color:var(--muted); margin-top:1px; }

/* -- Comments box ------------------------------------------------  */
.comments-box {
    background:var(--bg); border-radius:9px; padding:16px;
    font-size:13.5px; color:var(--text); line-height:1.75;
    border:1px solid var(--border); white-space:pre-wrap; word-break:break-word;
}
.no-comments { font-size:13px; color:var(--muted); font-style:italic; }

/* -- Stat chips --------------------------------------------------  */
.chip-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.chip {
    padding:4px 13px; border-radius:20px; font-size:12px; font-weight:700;
    border:1.5px solid; display:flex; align-items:center; gap:5px;
}

/* -- Timeline ----------------------------------------------------  */
.timeline { list-style:none; padding:0; margin:0; }
.tl-item  {
    display:flex; gap:12px; padding-bottom:16px; position:relative;
}
.tl-item:last-child { padding-bottom:0; }
.tl-dot {
    width:12px; height:12px; border-radius:50%; flex-shrink:0;
    margin-top:3px; border:2px solid #fff; box-shadow:0 0 0 2px currentColor;
}
.tl-item::before {
    content:''; position:absolute;
    left:5px; top:16px; bottom:0; width:2px;
    background:var(--border);
}
.tl-item:last-child::before { display:none; }
/* FIX: these classes were referenced in the HTML but never defined */
.tl-label { font-size:13px; font-weight:600; color:var(--text); margin-bottom:2px; }
.tl-date  { font-size:12px; color:var(--muted); }

/* -- Buttons -----------------------------------------------------  */
.btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 18px; border:none; border-radius:8px;
    font-size:13px; font-weight:600; cursor:pointer;
    text-decoration:none; transition:all .17s; font-family:inherit;
}
.btn-ghost {
    background:rgba(255,255,255,.15); color:#fff;
    border:1.5px solid rgba(255,255,255,.3);
}
.btn-ghost:hover { background:rgba(255,255,255,.25); color:#fff; }
.btn-white { background:#fff; color:var(--blue); border:1.5px solid var(--border); }
.btn-white:hover { background:#eff6ff; }

/* -- Print -------------------------------------------------------  */
@media print {
    .vr-hdr-actions, .no-print { display:none !important; }
    .card { box-shadow:none !important; break-inside:avoid; }
    .vr-grid { grid-template-columns:1fr !important; }
}
</style>

<div class="vr-wrap">

<!-- -- Page Header ------------------------------------------------ -->
<div class="vr-hdr">
    <div>
        <h1><i class="fas fa-clipboard-check"></i> Review Details</h1>
        <p>
            <strong><?= htmlspecialchars($review['startup_name']) ?></strong>
            &mdash; <?= htmlspecialchars($review['opportunity_title']) ?>
        </p>
        <div class="meta">
            <span class="meta-pill">
                <i class="fas fa-calendar-alt"></i>
                Reviewed: <?= !empty($review['created_at']) ? date('d M Y', strtotime($review['created_at'])) : '-' ?>
            </span>
            <span class="meta-pill">
                <i class="fas fa-tag"></i>
                <?= htmlspecialchars($review['review_type'] ?? '-') ?>
            </span>
            <span class="meta-pill" style="background:<?= $rs[0] ?>33; border-color:<?= $rs[0] ?>66;">
                <i class="fas <?= $rs[1] ?>" style="color:<?= $rs[0] ?>;"></i>
                <?= htmlspecialchars($rec) ?>
            </span>
        </div>
    </div>
    <div class="vr-hdr-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
        <button onclick="window.print()" class="btn btn-ghost">
            <i class="fas fa-print"></i> Print
        </button>
        <a href="manage-reviewers?tab=status" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <?php if (ims_allowed('reviews.edit', ['Administrator', 'Programs Lead', 'MEAL Lead'], 'See Edit Review buttons on the View Review page')): ?>
            <a href="review-applicant?id=<?= $review_id ?>" class="btn btn-white">
                <i class="fas fa-edit"></i> Edit Review
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- -- Two-column grid -------------------------------------------- -->
<div class="vr-grid">

<!-- ---------------- LEFT COLUMN -------------------------------- -->
<div>

    <!-- Recommendation hero -->
    <div class="rec-hero"
         style="background:<?= $rs[2] ?>;border-color:<?= $rs[0] ?>;color:<?= $rs[3] ?>;">
        <div class="rec-icon-wrap" style="background:<?= $rs[0] ?>22;">
            <i class="fas <?= $rs[1] ?>" style="color:<?= $rs[0] ?>;"></i>
        </div>
        <div>
            <div class="rec-label" style="color:<?= $rs[3] ?>;">Recommendation</div>
            <div class="rec-value" style="color:<?= $rs[0] ?>;"><?= htmlspecialchars($rec) ?></div>
            <div class="rec-type" style="color:<?= $rs[3] ?>;">
                <?= htmlspecialchars($review['review_type'] ?? '-') ?>
                &nbsp;&middot;&nbsp;
                Reviewed on <?= !empty($review['created_at'])
                    ? date('d M Y \a\t h:i A', strtotime($review['created_at'])) : '-' ?>
            </div>
        </div>
    </div>

    <!-- Score breakdown by category -->
    <div class="card">
        <div class="card-hdr">
            <h3><i class="fas fa-layer-group"></i> Score by Category</h3>
            <span style="font-size:13px;font-weight:700;color:var(--text);">
                <?= (int)round($total_scored) ?><?= $total_max > 0 ? " / {$total_max} pts" : "" ?>
                <span style="color:var(--muted);font-weight:400;">
                    <?= $total_max > 0 ? "({$overall_pct}%)" : "" ?>
                </span>
            </span>
        </div>
        <div class="card-body">
            <?php if (empty($cat_totals)): ?>
                <p style="color:var(--muted);font-size:13px;margin:0;">
                    No per-criteria scores found for this review. Showing stored overall score only.
                </p>
            <?php else: ?>
                <?php foreach ($cat_totals as $cat => $ct):
                    $cpct = ($ct['max'] > 0) ? round(($ct['scored'] / $ct['max']) * 100) : 0;
                    $cclr = score_color((float)$cpct);
                ?>
                <div class="cat-bar-wrap">
                    <div class="cat-bar-row">
                        <span><?= htmlspecialchars($cat) ?></span>
                        <span class="cat-score-badge">
                            <?= (int)round($ct['scored']) ?> / <?= (int)$ct['max'] ?>
                            &nbsp;&middot;&nbsp; <?= (int)$cpct ?>%
                        </span>
                    </div>
                    <div class="cat-bar-track"
                         role="progressbar" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="<?= (int)$cpct ?>">
                        <div class="cat-bar-fill"
                             style="width:<?= (int)$cpct ?>%; background:<?= $cclr ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Detailed criteria scores -->
    <?php if (!empty($criteria_grouped)):
        foreach ($criteria_grouped as $cat => $clist):
            $ct   = $cat_totals[$cat] ?? ['scored' => 0, 'max' => 0];
            $cpct = ($ct['max'] > 0) ? round(($ct['scored'] / $ct['max']) * 100) : 0;
    ?>
    <div class="card">
        <div class="card-hdr">
            <h3><i class="fas fa-list-ul"></i> <?= htmlspecialchars($cat) ?></h3>
            <span class="cat-score-badge">
                <?= (int)round($ct['scored']) ?> / <?= (int)$ct['max'] ?> &mdash; <?= (int)$cpct ?>%
            </span>
        </div>
        <div class="card-body" style="padding-top:6px;">
            <?php foreach ($clist as $ci):
                $gs  = (float)($ci['given_score'] ?? 0);
                $mx  = (int)($ci['max_score']   ?? 0);
                $pct = ($mx > 0) ? round(($gs / $mx) * 100) : 0;
                $clr = score_color((float)$pct);
            ?>
            <div class="crit-item">
                <div class="crit-q"><?= htmlspecialchars($ci['question']) ?></div>
                <?php if (!empty($ci['description'])): ?>
                    <div class="crit-desc">
                        <i class="fas fa-info-circle" style="color:#ff9800;"></i>
                        <?= htmlspecialchars($ci['description']) ?>
                    </div>
                <?php endif; ?>
                <div class="crit-score-row">
                    <div class="crit-bar-track"
                         role="progressbar" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="<?= (int)$pct ?>">
                        <div class="crit-bar-fill"
                             style="width:<?= (int)$pct ?>%; background:<?= $clr ?>;"></div>
                    </div>
                    <span class="crit-badge" style="background:<?= $clr ?>;">
                        <?= (int)round($gs) ?>/<?= (int)$mx ?> (<?= (int)$pct ?>%)
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
        endforeach;
    endif; ?>

    <!-- Reviewer comments -->
    <div class="card">
        <div class="card-hdr">
            <h3><i class="fas fa-comment-alt"></i> Reviewer Comments</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($review['comments'])): ?>
                <div class="comments-box"><?= htmlspecialchars($review['comments']) ?></div>
            <?php else: ?>
                <p class="no-comments"><i class="fas fa-minus-circle"></i> No comments provided.</p>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /left -->

<!-- ---------------- RIGHT COLUMN ------------------------------- -->
<div>

    <!-- Overall score dial -->
    <div class="card">
        <div class="card-hdr">
            <h3><i class="fas fa-star"></i> Overall Score</h3>
        </div>
        <div class="card-body" style="text-align:center;">
            <?php
            $dial_pct = (int)$overall_pct;
            $dial_clr = score_color((float)$dial_pct);
            ?>
            <div class="score-dial-wrap">
                <div class="score-dial"
                     style="background:conic-gradient(<?= $dial_clr ?> 0% <?= $dial_pct ?>%,
                            #e9eef5 <?= $dial_pct ?>% 100%);">
                    <div class="score-dial-inner">
                        <span class="sd-text"><?= (int)$overall_pts ?></span>
                        <span class="sd-sub">
                            <?= $total_max > 0
                                ? "of {$total_max} &middot; {$dial_pct}%"
                                : "overall" ?>
                        </span>
                    </div>
                </div>
                <div style="font-size:13px;color:var(--sub);">
                    <?php if ($total_max > 0): ?>
                        <strong style="color:var(--text);"><?= (int)$overall_pts ?></strong>
                        out of <?= (int)$total_max ?> points
                    <?php else: ?>
                        <strong style="color:var(--text);"><?= (int)$overall_pts ?></strong>
                        (no criteria totals found)
                    <?php endif; ?>
                </div>
            </div>

            <div class="chip-row" style="justify-content:center;margin-top:14px;">
                <?php $app_sc = $app_status_colors[$review['app_status']] ?? '#95a5a6'; ?>
                <span class="chip"
                      style="color:<?= $app_sc ?>;border-color:<?= $app_sc ?>22;background:<?= $app_sc ?>11;">
                    <i class="fas fa-circle" style="font-size:8px;"></i>
                    App: <?= htmlspecialchars($review['app_status']) ?>
                </span>
                <span class="chip"
                      style="color:<?= $rs[0] ?>;border-color:<?= $rs[0] ?>33;background:<?= $rs[0] ?>11;">
                    <i class="fas <?= $rs[1] ?>"></i>
                    <?= htmlspecialchars($rec) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Reviewer -->
    <div class="card">
        <div class="card-hdr">
            <h3><i class="fas fa-user-check"></i> Reviewer</h3>
        </div>
        <div class="card-body">
            <div class="reviewer-pill">
                <div class="reviewer-avatar">
                    <?= strtoupper(substr((string)($review['reviewer_name'] ?? 'R'), 0, 1)) ?>
                </div>
                <div>
                    <div class="reviewer-name"><?= htmlspecialchars($review['reviewer_name'] ?? '-') ?></div>
                    <div class="reviewer-email"><?= htmlspecialchars($review['reviewer_email'] ?? '-') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Application info -->
    <div class="card">
        <div class="card-hdr">
            <h3><i class="fas fa-building"></i> Application Info</h3>
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <div class="il">Startup</div>
                    <div class="iv"><?= htmlspecialchars($review['startup_name'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="il">Applicant</div>
                    <div class="iv"><?= htmlspecialchars($review['applicant_name'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="il">Sector</div>
                    <div class="iv"><?= htmlspecialchars($review['sector'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="il">Stage</div>
                    <div class="iv"><?= htmlspecialchars($review['business_stage'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="il">Team Size</div>
                    <div class="iv"><?= htmlspecialchars($review['team_size'] ?? '-') ?> members</div>
                </div>
                <div class="info-item">
                    <div class="il">Submitted</div>
                    <div class="iv">
                        <?= !empty($review['submitted_at'])
                            ? date('d M Y', strtotime($review['submitted_at'])) : '-' ?>
                    </div>
                </div>
                <?php if (!empty($review['current_revenue'])): ?>
                <div class="info-item">
                    <div class="il">Revenue</div>
                    <div class="iv">UGX <?= number_format((float)$review['current_revenue']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($review['funding_sought'])): ?>
                <div class="info-item">
                    <div class="il">Funding Sought</div>
                    <div class="iv">UGX <?= number_format((float)$review['funding_sought']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($website_url)): ?>
                <div style="margin-top:12px;">
                    <div class="il" style="font-size:10.5px;font-weight:700;text-transform:uppercase;
                         letter-spacing:.5px;color:var(--muted);margin-bottom:4px;">Website</div>
                    <!-- FIX: URL sanitised above; both href and display text are properly escaped -->
                    <a href="<?= htmlspecialchars($website_url) ?>"
                       target="_blank" rel="noopener noreferrer"
                       style="font-size:13px;color:var(--blue);word-break:break-all;">
                        <?= htmlspecialchars($website_url) ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Review timeline -->
    <div class="card">
        <div class="card-hdr">
            <h3><i class="fas fa-history"></i> Timeline</h3>
        </div>
        <div class="card-body">
            <ul class="timeline">
                <li class="tl-item">
                    <span class="tl-dot" style="color:#ff9800;"></span>
                    <div>
                        <div class="tl-label">Application Submitted</div>
                        <div class="tl-date">
                            <?= !empty($review['submitted_at'])
                                ? date('d M Y', strtotime($review['submitted_at'])) : '-' ?>
                        </div>
                    </div>
                </li>
                <li class="tl-item">
                    <span class="tl-dot" style="color:#F39C12;"></span>
                    <div>
                        <div class="tl-label">Review Created</div>
                        <div class="tl-date">
                            <?= !empty($review['created_at'])
                                ? date('d M Y \a\t h:i A', strtotime($review['created_at'])) : '-' ?>
                        </div>
                    </div>
                </li>
                <?php if (!empty($review['updated_at']) && $review['updated_at'] !== $review['created_at']): ?>
                <li class="tl-item">
                    <span class="tl-dot" style="color:#9b59b6;"></span>
                    <div>
                        <div class="tl-label">Last Updated</div>
                        <div class="tl-date">
                            <?= date('d M Y \a\t h:i A', strtotime($review['updated_at'])) ?>
                        </div>
                    </div>
                </li>
                <?php endif; ?>
                <li class="tl-item">
                    <span class="tl-dot" style="color:<?= $rs[0] ?>;"></span>
                    <div>
                        <div class="tl-label">
                            Recommendation: <?= htmlspecialchars($rec) ?>
                        </div>
                        <div class="tl-date">
                            Score: <?= (int)$overall_pts ?>
                            <?= $total_max > 0 ? "/{$total_max} ({$overall_pct}%)" : "" ?>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="card no-print">
        <div class="card-hdr">
            <h3><i class="fas fa-bolt"></i> Actions</h3>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
            <!-- FIX: "View Application" and "Edit Review" now point to different pages -->
            <a href="view-application?id=<?= (int)$review['application_id'] ?>"
               class="btn"
               style="background:#eff6ff;color:#ff9800;border:1.5px solid #bfdbfe;justify-content:center;">
                <i class="fas fa-file-alt"></i> View Full Application
            </a>
            <?php if (ims_allowed('reviews.edit', ['Administrator', 'Programs Lead', 'MEAL Lead'], 'See Edit Review buttons on the View Review page')): ?>
            <a href="review-applicant?id=<?= $review_id ?>"
               class="btn"
               style="background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0;justify-content:center;">
                <i class="fas fa-edit"></i> Edit / Update Review
            </a>
            <?php endif; ?>
            <button onclick="window.print()"
                    class="btn"
                    style="background:#fafafa;color:var(--sub);border:1.5px solid var(--border);justify-content:center;">
                <i class="fas fa-print"></i> Print Review
            </button>
        </div>
    </div>

</div><!-- /right -->
</div><!-- /vr-grid -->
</div><!-- /vr-wrap -->

<?php
ob_end_flush();
require_once 'includes/footer.php';
?>