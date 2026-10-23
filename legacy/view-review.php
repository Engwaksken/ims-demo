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
    return $pct >= 70 ? 'high' : ($pct >= 40 ? 'mid' : 'low');
}

// Recommendation => [tone, icon]; tone maps to the vr-tone-* / is-* classes below.
$rec_styles = [
    'Accept'    => ['green', 'fa-check-circle'],
    'Reject'    => ['red',   'fa-times-circle'],
    'Shortlist' => ['blue',  'fa-star'],
    'Hold'      => ['amber', 'fa-pause-circle'],
];
$rec = $review['recommendation'] ?? 'Hold';
$rs  = $rec_styles[$rec] ?? $rec_styles['Hold'];

$app_status_colors = [
    'Pending'     => 'badge-warning', 'Shortlisted' => 'badge-info',
    'Accepted'    => 'badge-success', 'Rejected'    => 'badge-danger',
    'Withdrawn'   => 'badge-secondary',
];

$page_title = 'View Applicant Review';
require_once 'includes/header.php';
?>
<!----------------- STYLES ----------------------------------------->
<style>
/* -- view-review page-specific layout -- */
.vr-grid { display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }
.vr-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
@media (max-width: 900px) { .vr-grid { grid-template-columns: 1fr; } }

/* Tone helpers */
.vr-tone-high { --vr-tone: var(--green-fg); }
.vr-tone-mid  { --vr-tone: var(--amber-fg); }
.vr-tone-low  { --vr-tone: var(--red-fg); }
.is-green  { --vr-bg: var(--green-bg);  --vr-fg: var(--green-fg);  --vr-border: var(--green-border); }
.is-red    { --vr-bg: var(--red-bg);    --vr-fg: var(--red-fg);    --vr-border: var(--red-border); }
.is-blue   { --vr-bg: var(--blue-bg);   --vr-fg: var(--blue-fg);   --vr-border: var(--blue-border); }
.is-amber  { --vr-bg: var(--amber-bg);  --vr-fg: var(--amber-fg);  --vr-border: var(--amber-border); }

/* Recommendation banner */
.vr-rec {
    border-radius: var(--radius-xl);
    padding: 22px 24px;
    display: flex;
    align-items: center;
    gap: 18px;
    border: 2px solid var(--vr-border);
    background: var(--vr-bg);
    color: var(--vr-fg);
    box-shadow: var(--shadow-sm);
}
.vr-rec-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    flex-shrink: 0;
    background: var(--surface-card);
}
.vr-rec-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; opacity: .75; margin-bottom: 3px; }
.vr-rec-value { font-size: 26px; font-weight: 800; line-height: 1; }
.vr-rec-type { font-size: 13px; margin-top: 6px; opacity: .8; }

.vr-total { font-size: 13px; font-weight: 700; color: var(--ink-900); }
.vr-total .cell-muted { font-weight: 400; }

/* Category score bars */
.cat-bar-wrap { margin-bottom: 14px; }
.cat-bar-wrap:last-child { margin-bottom: 0; }
.cat-bar-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12.5px; color: var(--ink-600); margin-bottom: 4px; font-weight: 600; }
.cat-bar-track,
.crit-bar-track { background: var(--ink-100); border-radius: var(--radius-pill); overflow: hidden; }
.cat-bar-track { height: 9px; }
.cat-bar-fill,
.crit-bar-fill { height: 100%; border-radius: var(--radius-pill); background: var(--vr-tone); transition: width .4s ease; }

/* Criteria list */
.vr-crit-body { padding-top: 6px; }
.crit-item { padding: 13px 0; border-bottom: 1px solid var(--ink-100); }
.crit-item:last-child { border-bottom: none; }
.crit-q { font-size: 13px; font-weight: 600; color: var(--ink-900); margin-bottom: 3px; }
.crit-desc { font-size: 12px; color: var(--ink-300); margin-bottom: 8px; line-height: 1.5; }
.crit-desc i { color: var(--brand-500); }
.crit-score-row { display: flex; align-items: center; gap: 10px; }
.crit-bar-track { flex: 1; height: 7px; }
.crit-badge {
    font-size: 12.5px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: var(--radius-sm);
    color: var(--ink-0);
    background: var(--vr-tone);
    min-width: 72px;
    text-align: center;
    white-space: nowrap;
}

/* Overall score dial */
.vr-center { text-align: center; }
.score-dial-wrap { text-align: center; padding: 18px 0 10px; }
.score-dial {
    position: relative;
    width: 110px;
    height: 110px;
    border-radius: 50%;
    margin: 0 auto 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-md);
    background: conic-gradient(var(--vr-tone) 0% var(--vr-pct), var(--ink-100) var(--vr-pct) 100%);
}
.score-dial::before { content: ''; position: absolute; inset: 8px; border-radius: 50%; background: var(--surface-card); }
.score-dial-inner { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; }
.score-dial .sd-text { font-size: 22px; font-weight: 800; color: var(--ink-900); line-height: 1; }
.score-dial .sd-sub { font-size: 11px; color: var(--ink-300); margin-top: 3px; font-weight: 700; }
.vr-dial-caption { font-size: 13px; color: var(--ink-600); }
.vr-dial-caption strong { color: var(--ink-900); }
.vr-chips { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 14px; }

/* Info grid */
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 600px) { .info-grid { grid-template-columns: 1fr; } }
.info-item { background: var(--ink-50); border-radius: var(--radius-md); padding: 11px 13px; }
.info-item .il,
.vr-website .il { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--ink-300); margin-bottom: 3px; }
.info-item .iv { font-size: 13px; font-weight: 600; color: var(--ink-900); }
.vr-website { margin-top: 12px; }
.vr-website a { font-size: 13px; word-break: break-all; }

/* Reviewer */
.reviewer-pill { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: var(--ink-50); border-radius: var(--radius-lg); border: 1px solid var(--ink-100); }
.reviewer-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: var(--brand-500);
    color: var(--ink-0);
    font-size: 17px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.reviewer-name { font-size: 14px; font-weight: 700; color: var(--ink-900); }
.reviewer-email { font-size: 12px; color: var(--ink-300); margin-top: 1px; }

/* Comments */
.comments-box {
    background: var(--ink-50);
    border-radius: var(--radius-md);
    padding: 16px;
    font-size: 13.5px;
    color: var(--ink-900);
    line-height: 1.75;
    border: 1px solid var(--ink-100);
    white-space: pre-wrap;
    word-break: break-word;
}

/* Timeline */
.timeline { list-style: none; padding: 0; margin: 0; }
.tl-item { display: flex; gap: 12px; padding-bottom: 16px; position: relative; }
.tl-item:last-child { padding-bottom: 0; }
.tl-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 3px;
    border: 2px solid var(--surface-card);
    color: var(--brand-500);
    box-shadow: 0 0 0 2px currentColor;
}
.tl-dot.is-amber  { color: var(--amber-fg); }
.tl-dot.is-purple { color: var(--purple-fg); }
.tl-dot.is-green  { color: var(--green-fg); }
.tl-dot.is-red    { color: var(--red-fg); }
.tl-dot.is-blue   { color: var(--blue-fg); }
.tl-item::before { content: ''; position: absolute; left: 5px; top: 16px; bottom: 0; width: 2px; background: var(--ink-100); }
.tl-item:last-child::before { display: none; }
.tl-label { font-size: 13px; font-weight: 600; color: var(--ink-900); margin-bottom: 2px; }
.tl-date { font-size: 12px; color: var(--ink-300); }

.vr-actions { display: flex; flex-direction: column; gap: 8px; }
.vr-actions .btn { justify-content: center; }

@media print {
    .page-hero .hero-actions, .no-print { display: none !important; }
    .card { box-shadow: none !important; break-inside: avoid; }
    .vr-grid { grid-template-columns: 1fr !important; }
}
</style>

<div class="page-stack">

<!-- -- Page Header ------------------------------------------------ -->
<div class="page-hero">
    <div>
        <h1><i class="fas fa-clipboard-check" aria-hidden="true"></i> Review Details</h1>
        <p>
            <strong><?= htmlspecialchars($review['startup_name']) ?></strong>
            &mdash; <?= htmlspecialchars($review['opportunity_title']) ?>
            &middot; <?= htmlspecialchars($review['review_type'] ?? '-') ?>
            &middot; Reviewed <?= !empty($review['created_at']) ? date('d M Y', strtotime($review['created_at'])) : '-' ?>
            &middot; <?= htmlspecialchars($rec) ?>
        </p>
    </div>
    <div class="hero-actions">
        <a href="manage-reviewers?tab=status" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
        </a>
        <button type="button" onclick="window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </button>
        <?php if (ims_allowed('reviews.edit', ['Administrator', 'Programs Lead', 'MEAL Lead'], 'See Edit Review buttons on the View Review page')): ?>
            <a href="review-applicant?id=<?= $review_id ?>" class="btn btn-primary">
                <i class="fas fa-edit" aria-hidden="true"></i> Edit Review
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- -- Two-column grid -------------------------------------------- -->
<div class="vr-grid">

<!-- ---------------- LEFT COLUMN -------------------------------- -->
<div class="vr-col">

    <!-- Recommendation banner -->
    <div class="vr-rec is-<?= $rs[0] ?>">
        <div class="vr-rec-icon">
            <i class="fas <?= $rs[1] ?>"></i>
        </div>
        <div>
            <div class="vr-rec-label">Recommendation</div>
            <div class="vr-rec-value"><?= htmlspecialchars($rec) ?></div>
            <div class="vr-rec-type">
                <?= htmlspecialchars($review['review_type'] ?? '-') ?>
                &nbsp;&middot;&nbsp;
                Reviewed on <?= !empty($review['created_at'])
                    ? date('d M Y \a\t h:i A', strtotime($review['created_at'])) : '-' ?>
            </div>
        </div>
    </div>

    <!-- Score breakdown by category -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-layer-group"></i> Score by Category</h3>
            <span class="vr-total">
                <?= (int)round($total_scored) ?><?= $total_max > 0 ? " / {$total_max} pts" : "" ?>
                <span class="cell-muted">
                    <?= $total_max > 0 ? "({$overall_pct}%)" : "" ?>
                </span>
            </span>
        </div>
        <div class="card-body">
            <?php if (empty($cat_totals)): ?>
                <p class="cell-muted">
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
                        <span class="badge badge-info">
                            <?= (int)round($ct['scored']) ?> / <?= (int)$ct['max'] ?>
                            &nbsp;&middot;&nbsp; <?= (int)$cpct ?>%
                        </span>
                    </div>
                    <div class="cat-bar-track"
                         role="progressbar" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="<?= (int)$cpct ?>">
                        <div class="cat-bar-fill vr-tone-<?= $cclr ?>"
                             style="width:<?= (int)$cpct ?>%;"></div>
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
        <div class="card-header">
            <h3><i class="fas fa-list-ul"></i> <?= htmlspecialchars($cat) ?></h3>
            <span class="badge badge-info">
                <?= (int)round($ct['scored']) ?> / <?= (int)$ct['max'] ?> &mdash; <?= (int)$cpct ?>%
            </span>
        </div>
        <div class="card-body vr-crit-body">
            <?php foreach ($clist as $ci):
                $gs  = (float)($ci['given_score'] ?? 0);
                $mx  = (int)($ci['max_score']   ?? 0);
                $pct = ($mx > 0) ? round(($gs / $mx) * 100) : 0;
                $clr = score_color((float)$pct);
            ?>
            <div class="crit-item vr-tone-<?= $clr ?>">
                <div class="crit-q"><?= htmlspecialchars($ci['question']) ?></div>
                <?php if (!empty($ci['description'])): ?>
                    <div class="crit-desc">
                        <i class="fas fa-info-circle"></i>
                        <?= htmlspecialchars($ci['description']) ?>
                    </div>
                <?php endif; ?>
                <div class="crit-score-row">
                    <div class="crit-bar-track"
                         role="progressbar" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="<?= (int)$pct ?>">
                        <div class="crit-bar-fill"
                             style="width:<?= (int)$pct ?>%;"></div>
                    </div>
                    <span class="crit-badge">
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
        <div class="card-header">
            <h3><i class="fas fa-comment-alt"></i> Reviewer Comments</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($review['comments'])): ?>
                <div class="comments-box"><?= htmlspecialchars($review['comments']) ?></div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-comment-slash" aria-hidden="true"></i>
                    <p>No comments provided.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /left -->

<!-- ---------------- RIGHT COLUMN ------------------------------- -->
<div class="vr-col">

    <!-- Overall score dial -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-star"></i> Overall Score</h3>
        </div>
        <div class="card-body vr-center">
            <?php
            $dial_pct = (int)$overall_pct;
            $dial_clr = score_color((float)$dial_pct);
            ?>
            <div class="score-dial-wrap">
                <div class="score-dial vr-tone-<?= $dial_clr ?>" style="--vr-pct: <?= $dial_pct ?>%;">
                    <div class="score-dial-inner">
                        <span class="sd-text"><?= (int)$overall_pts ?></span>
                        <span class="sd-sub">
                            <?= $total_max > 0
                                ? "of {$total_max} &middot; {$dial_pct}%"
                                : "overall" ?>
                        </span>
                    </div>
                </div>
                <div class="vr-dial-caption">
                    <?php if ($total_max > 0): ?>
                        <strong><?= (int)$overall_pts ?></strong>
                        out of <?= (int)$total_max ?> points
                    <?php else: ?>
                        <strong><?= (int)$overall_pts ?></strong>
                        (no criteria totals found)
                    <?php endif; ?>
                </div>
            </div>

            <div class="vr-chips">
                <?php $app_sc = $app_status_colors[$review['app_status']] ?? 'badge-secondary'; ?>
                <span class="badge <?= $app_sc ?>">
                    App: <?= htmlspecialchars($review['app_status']) ?>
                </span>
                <?php $rec_badge = ['green' => 'badge-success', 'red' => 'badge-danger', 'blue' => 'badge-info', 'amber' => 'badge-warning'][$rs[0]]; ?>
                <span class="badge <?= $rec_badge ?>">
                    <i class="fas <?= $rs[1] ?>"></i>
                    <?= htmlspecialchars($rec) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Reviewer -->
    <div class="card">
        <div class="card-header">
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
        <div class="card-header">
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
                <div class="vr-website">
                    <div class="il">Website</div>
                    <!-- FIX: URL sanitised above; both href and display text are properly escaped -->
                    <a href="<?= htmlspecialchars($website_url) ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?= htmlspecialchars($website_url) ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Review timeline -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Timeline</h3>
        </div>
        <div class="card-body">
            <ul class="timeline">
                <li class="tl-item">
                    <span class="tl-dot"></span>
                    <div>
                        <div class="tl-label">Application Submitted</div>
                        <div class="tl-date">
                            <?= !empty($review['submitted_at'])
                                ? date('d M Y', strtotime($review['submitted_at'])) : '-' ?>
                        </div>
                    </div>
                </li>
                <li class="tl-item">
                    <span class="tl-dot is-amber"></span>
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
                    <span class="tl-dot is-purple"></span>
                    <div>
                        <div class="tl-label">Last Updated</div>
                        <div class="tl-date">
                            <?= date('d M Y \a\t h:i A', strtotime($review['updated_at'])) ?>
                        </div>
                    </div>
                </li>
                <?php endif; ?>
                <li class="tl-item">
                    <span class="tl-dot is-<?= $rs[0] ?>"></span>
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
        <div class="card-header">
            <h3><i class="fas fa-bolt"></i> Actions</h3>
        </div>
        <div class="card-body vr-actions">
            <!-- FIX: "View Application" and "Edit Review" now point to different pages -->
            <a href="view-application?id=<?= (int)$review['application_id'] ?>" class="btn btn-info">
                <i class="fas fa-file-alt"></i> View Full Application
            </a>
            <?php if (ims_allowed('reviews.edit', ['Administrator', 'Programs Lead', 'MEAL Lead'], 'See Edit Review buttons on the View Review page')): ?>
            <a href="review-applicant?id=<?= $review_id ?>" class="btn btn-success">
                <i class="fas fa-edit"></i> Edit / Update Review
            </a>
            <?php endif; ?>
            <button type="button" onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print Review
            </button>
        </div>
    </div>

</div><!-- /right -->
</div><!-- /vr-grid -->
</div><!-- /.page-stack -->

<?php
ob_end_flush();
require_once 'includes/footer.php';
?>