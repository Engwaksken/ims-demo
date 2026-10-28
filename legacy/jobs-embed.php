<?php

header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors *');

require_once 'includes/config.php';

if (!function_exists('e')) {
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* -- URL params ------------------------------------------------ */
$theme      = in_array($_GET['theme'] ?? 'light', ['light','dark']) ? $_GET['theme'] : 'light';
$compact    = !empty($_GET['compact']);
$limit      = max(0, (int)($_GET['limit'] ?? 0));
$filterType = trim((string)($_GET['type'] ?? ''));
$filterDept = trim((string)($_GET['dept'] ?? ''));
$showSearch = ($_GET['show_search'] ?? '1') !== '0';
$showFilter = ($_GET['show_filter'] ?? '1') !== '0';
$showHeader = ($_GET['show_header'] ?? '1') !== '0';
$brand      = ims_brand();
$accentRaw  = trim((string)($_GET['accent'] ?? ''));
if (!preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $accentRaw)) $accentRaw = '';
// ?accent= wins; otherwise the configured primary colour (the original #f97316 while the theme is at its default).
$accent     = $accentRaw ?: ($brand['primary'] === IMS_THEME_DEFAULTS['theme_primary_color'] ? '#f97316' : $brand['primary']);
$applyBase  = rtrim((string)($_GET['apply_base'] ?? ''), '/');

/* -- Sanitise filter inputs via prepared statement ------------- */
$where  = [];
$params = [];
$types  = '';

$where[]  = "status = 'Published'";
$where[]  = "(deadline IS NULL OR deadline >= CURDATE())";

if ($filterType !== '') {
    $where[]  = "job_type = ?";
    $params[] = $filterType;
    $types   .= 's';
}
if ($filterDept !== '') {
    $where[]  = "department = ?";
    $params[] = $filterDept;
    $types   .= 's';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$limitSql = $limit > 0 ? "LIMIT $limit" : '';

$jobs = [];
$sql  = "SELECT * FROM jobs $whereSql ORDER BY is_featured DESC, is_urgent DESC, created_at DESC $limitSql";
if ($params) {
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $jobs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
    $jobs = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/* -- All job types for filter pills (unfiltered) --------------- */
$allTypes = $conn->query(
    "SELECT DISTINCT job_type FROM jobs WHERE status='Published' AND (deadline IS NULL OR deadline >= CURDATE()) ORDER BY job_type"
)->fetch_all(MYSQLI_ASSOC);
$allTypes = array_column($allTypes, 'job_type');

/* -- Dark-mode overrides injected as CSS custom properties ---- */
$darkOverrides = '
  :root {
    --surface-page: #0f0b07;
    --surface-card: #1a1410;
    --surface-2:    #221e18;
    --ink-900: #f5efe8;
    --ink-700: #e8dfd4;
    --ink-500: #b5a898;
    --ink-400: #9a8a78;
    --ink-300: #7a6e62;
    --ink-200: #5a5048;
    --ink-100: #3a3028;
    --ink-50:  #251f18;
    --ink-0:   #ffffff;
    --shadow-sm: 0 2px 10px rgba(0,0,0,.35);
    --shadow-md: 0 6px 24px rgba(0,0,0,.50);
    --shadow-lg: 0 12px 40px rgba(0,0,0,.60);
  }
  body   { background: var(--surface-page); color: var(--ink-500); }
  .job-row { border-color: rgba(255,255,255,.07); }
  .job-row:hover { border-color: color-mix(in srgb, var(--brand-500) 30%, transparent); }
  .sb-search-inner { background: var(--surface-2); border-color: rgba(255,255,255,.1); }
  .sb-search-inner input { color: var(--ink-700); }
  .sb-opt { color: var(--ink-400); }
  .sb-opt:hover { background: var(--surface-2); color: var(--ink-700); }
  .widget-header { color: var(--ink-700); }
  .widget-sub    { color: var(--ink-300); }
';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Career Opportunities - <?= e($brand['name']) ?></title>
<link rel="icon" href="<?= e($brand['favicon']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/jobs-listing.css">
<?= ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])) ?>
<style>
/* -- Accent colour override (?accent= param, else the site theme) -- */
html:root {
  --brand-500: <?= e($accent) ?>;
  --brand-600: <?= e($accent) ?>;
  --brand-400: <?= e($accent) ?>;
  --brand-50:  color-mix(in srgb, <?= e($accent) ?> 8%, white);
  --brand-100: color-mix(in srgb, <?= e($accent) ?> 18%, white);
  --brand-700: color-mix(in srgb, <?= e($accent) ?> 80%, #000);
}

/* -- Widget-specific layout overrides ---------------------------- */
/* Widget has no hero or sidebar - strip those structural classes */
body {
  background: transparent;   /* host page bg shows through */
  padding: <?= $compact ? '12px' : '20px' ?>;
  min-height: auto;
  overflow-x: hidden;
}

/* No hero in widget, no sidebar, no page-wrap grid */
/* Cards stack in a single column list */
.widget-wrap {
  display: flex;
  flex-direction: column;
  gap: 0;
  max-width: 100%;
}

/* -- Widget header ---------------------------------------------- */
.widget-header-block {
  margin-bottom: <?= $compact ? '14px' : '20px' ?>;
  <?= !$showHeader ? 'display:none;' : '' ?>
}

.widget-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .16em;
  text-transform: uppercase;
  color: var(--brand-500);
  background: var(--brand-50);
  border: 1px solid var(--brand-100);
  padding: 5px 14px;
  border-radius: var(--radius-pill);
  margin-bottom: 10px;
}

.widget-header {
  font-size: <?= $compact ? '20px' : '26px' ?>;
  font-weight: 800;
  color: var(--ink-700);
  line-height: 1.15;
  margin-bottom: 5px;
  letter-spacing: -.02em;
}

.widget-header em {
  font-style: normal;
  background: linear-gradient(135deg, var(--brand-600), var(--brand-400));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.widget-sub {
  font-size: 13px;
  color: var(--ink-300);
  font-weight: 400;
  line-height: 1.5;
}

/* -- Widget search + filter bar ----------------------------------
   One row (search grows, pills stay on one line; the bar scrolls sideways
   if it overflows). Wraps at <=768px. */
.widget-bar {
  display: flex;
  align-items: center;
  gap: 9px;
  flex-wrap: nowrap;
  overflow-x: auto;
  padding: 3px;
  scrollbar-width: thin;
  margin-bottom: <?= $compact ? '12px' : '16px' ?>;
  <?= (!$showSearch && !$showFilter) ? 'display:none;' : '' ?>
}

.widget-search {
  flex: 1 1 220px;
  min-width: 160px;
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--surface-card);
  border: 1.5px solid var(--ink-100);
  border-radius: var(--radius-md);
  padding: 9px 13px;
  transition: border-color .18s, box-shadow .18s;
  <?= !$showSearch ? 'display:none;' : '' ?>
}

.widget-search:focus-within {
  border-color: var(--brand-500);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-500) 12%, transparent);
}

.widget-search i { color: var(--ink-200); font-size: 12px; flex-shrink: 0; }

.widget-search input {
  border: none;
  background: transparent;
  outline: none;
  font-family: var(--font-body);
  font-size: 13px;
  color: var(--ink-700);
  width: 100%;
}

.widget-search input::placeholder { color: var(--ink-200); }

/* Type filter pills */
.widget-pills {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  <?= !$showFilter ? 'display:none;' : '' ?>
}

.widget-bar > .widget-pills { flex: 0 0 auto; flex-wrap: nowrap; }

@media (max-width: 768px) {
  .widget-bar { flex-wrap: wrap; overflow-x: visible; }
  .widget-bar > .widget-search { flex: 1 1 100%; min-width: 0; }
  .widget-bar > .widget-pills { flex: 1 1 100%; flex-wrap: wrap; }
}

.widget-pill {
  padding: 5px 13px;
  border-radius: var(--radius-pill);
  font-family: var(--font-body);
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  border: 1.5px solid var(--ink-100);
  color: var(--ink-400);
  background: transparent;
  white-space: nowrap;
  transition: all .16s var(--ease-out);
}

.widget-pill:hover {
  border-color: var(--brand-400);
  color: var(--brand-600);
}

.widget-pill.active {
  background: var(--brand-500);
  border-color: var(--brand-500);
  color: var(--ink-0);
}

/* -- Count bar ---------------------------------------------------- */
.widget-count {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  color: var(--ink-300);
  margin-bottom: 10px;
}

.widget-count strong { color: var(--ink-700); font-weight: 700; }

/* -- Card list ---------------------------------------------------- */
.widget-list {
  display: flex;
  flex-direction: column;
  gap: <?= $compact ? '8px' : '10px' ?>;
}


.widget-list .job-row {
  padding: <?= $compact ? '14px 16px' : '20px 22px' ?>;
  margin-bottom: 0;    /* gap on parent handles spacing */
  border-radius: var(--radius-lg);
}

/* Compact mode: hide description + expand */
<?php if ($compact): ?>
.widget-list .row-desc    { display: none; }
.widget-list .expand-btn  { display: none; }
.widget-list .row-details { display: none !important; }
.widget-list .apply-btn   { padding: 9px 16px; font-size: 12px; }
.widget-list .share-btn   { display: none; }
.widget-list .row-title   { font-size: 16px; }
<?php endif; ?>

/* -- Empty state (reuse .empty from jobs-listing.css) ----------- */
.widget-empty {
  background: var(--surface-card);
  border: 1.5px dashed var(--ink-100);
  border-radius: var(--radius-lg);
  padding: 48px 24px;
  text-align: center;
}

.widget-empty-icon {
  width: 56px; height: 56px;
  border-radius: 50%;
  background: var(--brand-50);
  display: grid;
  place-items: center;
  margin: 0 auto 16px;
  font-size: 22px;
  color: var(--brand-500);
  opacity: .55;
}

.widget-empty h4 {
  font-size: 17px;
  font-weight: 800;
  color: var(--ink-700);
  margin-bottom: 6px;
  letter-spacing: -.02em;
}

.widget-empty p {
  font-size: 13px;
  color: var(--ink-300);
  line-height: 1.55;
}

/* -- Footer ------------------------------------------------------- */
.widget-footer {
  margin-top: 18px;
  text-align: center;
  font-size: 11px;
  color: var(--ink-200);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}

.widget-footer a { color: var(--brand-500); font-weight: 600; }

/* -- Dark-mode token overrides ------------------------------------ */
<?= $theme === 'dark' ? $darkOverrides : '' ?>

/* -- Scrollbar --------------------------------------------------- */
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--ink-100); border-radius: 2px; }
</style>
</head>
<body>

<div class="widget-wrap">

    <!-- -- Header ----------------------------------------------- -->
    <?php if ($showHeader): ?>
    <div class="widget-header-block">
        <div class="widget-eyebrow"><i class="fas fa-briefcase"></i> Open Positions</div>
        <h2 class="widget-header">Join <em><?= e($brand['name']) ?></em></h2>
        <p class="widget-sub">Explore open roles and grow your career with us.</p>
    </div>
    <?php endif; ?>

    <!-- -- Search + filter bar ---------------------------------- -->
    <?php if ($showSearch || $showFilter): ?>
    <div class="widget-bar">
        <?php if ($showSearch): ?>
        <div class="widget-search">
            <i class="fas fa-search"></i>
            <input
                type="search"
                id="wSearch"
                placeholder="Search positions..."
                oninput="wFilter()"
                autocomplete="off"
            >
        </div>
        <?php endif; ?>

        <?php if ($showFilter && count($allTypes) > 1): ?>
        <div class="widget-pills" id="wPills">
            <button class="widget-pill active" data-t="all" onclick="wSetType(this)">All</button>
            <?php foreach ($allTypes as $t): ?>
                <button
                    class="widget-pill <?= ($filterType === $t) ? 'active' : '' ?>"
                    data-t="<?= e($t) ?>"
                    onclick="wSetType(this)"
                ><?= e($t) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- -- Count ------------------------------------------------ -->
    <div class="widget-count">
        <span><strong id="wCount"><?= count($jobs) ?></strong> open role<?= count($jobs) !== 1 ? 's' : '' ?></span>
    </div>

    <!-- -- Jobs list -------------------------------------------- -->
    <?php if (empty($jobs)): ?>
    <div class="widget-empty">
        <div class="widget-empty-icon"><i class="fas fa-briefcase"></i></div>
        <h4>No Open Positions</h4>
        <p>We're preparing exciting new roles. Check back soon!</p>
    </div>

    <?php else: ?>
    <div class="widget-list" id="wList">
        <?php foreach ($jobs as $idx => $j):
            $deadline = $j['deadline'] ? new DateTime($j['deadline']) : null;
            $today    = new DateTime();
            $daysLeft = $deadline ? (int)$today->diff($deadline)->days : null;
            $soon     = $daysLeft !== null && $daysLeft <= 7;

            // Salary
            $salaryAmt = '';
            $salaryPer = '';
            if (!empty($j['show_salary']) && ($j['salary_min'] || $j['salary_max'])) {
                $cur = $j['salary_currency'] ?? '';
                $per = $j['salary_period']   ?? '';
                $fmt = fn($v) => $cur . ' ' . number_format((float)$v, 0);
                if ($j['salary_min'] && $j['salary_max'])
                    $salaryAmt = $fmt($j['salary_min']) . ' - ' . $fmt($j['salary_max']);
                elseif ($j['salary_min'])
                    $salaryAmt = 'From ' . $fmt($j['salary_min']);
                else
                    $salaryAmt = 'Up to ' . $fmt($j['salary_max']);
                $salaryPer = 'per ' . $per;
            }

            // Apply URL
            if (!empty($j['apply_url'])) {
                $applyHref = e($j['apply_url']);
            } elseif ($applyBase !== '') {
                $applyHref = e($applyBase) . '/apply-job?job_id=' . (int)$j['job_id'];
            } else {
                $applyHref = 'apply-job?job_id=' . (int)$j['job_id'];
            }

            $toList   = fn($txt) => array_filter(array_map('trim', preg_split('/\r?\n/', $txt ?? '')));
            $hasDetail = !$compact && ($j['responsibilities'] || $j['requirements'] || $j['benefits']);

            $rowClass = 'job-row';
            if ($j['is_featured']) $rowClass .= ' featured';
            if ($j['is_urgent'])   $rowClass .= ' urgent';
        ?>
        <div
            class="<?= $rowClass ?>"
            data-type="<?= e($j['job_type'] ?? '') ?>"
            data-search="<?= e(strtolower($j['job_title'] . ' ' . ($j['department'] ?? '') . ' ' . ($j['location'] ?? ''))) ?>"
            style="animation-delay:<?= $idx * 0.05 ?>s"
        >
            <!-- Left: info -->
            <div class="row-left">
                <div class="row-tags">
                    <?php if ($j['job_type']): ?>
                        <span class="tag tag-type"><i class="fas fa-briefcase"></i><?= e($j['job_type']) ?></span>
                    <?php endif; ?>
                    <?php if ($j['department']): ?>
                        <span class="tag tag-dept"><i class="fas fa-building"></i><?= e($j['department']) ?></span>
                    <?php endif; ?>
                    <?php if (!$compact && $j['experience_level']): ?>
                        <span class="tag tag-lvl"><?= e($j['experience_level']) ?></span>
                    <?php endif; ?>
                    <?php if ($j['is_featured']): ?>
                        <span class="tag tag-feat"><i class="fas fa-star"></i>Featured</span>
                    <?php endif; ?>
                    <?php if ($j['is_urgent']): ?>
                        <span class="tag tag-urgent"><i class="fas fa-fire"></i>Urgent</span>
                    <?php endif; ?>
                </div>

                <h3 class="row-title"><?= e($j['job_title']) ?></h3>

                <div class="row-meta">
                    <?php if ($j['location']): ?>
                        <span class="meta-item"><i class="fas fa-location-dot"></i><?= e($j['location']) ?></span>
                    <?php endif; ?>
                    <?php if ($deadline): ?>
                        <span class="meta-item closing <?= $soon ? 'soon' : '' ?>">
                            <i class="fas fa-clock"></i>
                            <?= $soon
                                ? ($daysLeft === 0 ? 'Closes today' : $daysLeft . 'd left')
                                : 'Closes ' . date('d M Y', strtotime($j['deadline'])) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!$compact && $j['description']): ?>
                    <p class="row-desc">
                        <?= e(substr($j['description'], 0, 160)) . (strlen($j['description']) > 160 ? '...' : '') ?>
                    </p>
                <?php endif; ?>

                <?php if ($hasDetail): ?>
                    <button class="expand-btn" id="w-toggle-<?= (int)$j['job_id'] ?>"
                            onclick="wToggle(<?= (int)$j['job_id'] ?>)">
                        <i class="fas fa-chevron-down"></i> Full details
                    </button>
                <?php endif; ?>
            </div>

            <!-- Right: salary + apply -->
            <div class="row-right">
                <?php if ($salaryAmt): ?>
                    <div class="row-salary">
                        <span class="salary-amt"><?= e($salaryAmt) ?></span>
                        <?php if ($salaryPer): ?>
                            <span class="salary-per"><?= e($salaryPer) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <a href="<?= $applyHref ?>" class="apply-btn" target="_parent">
                    <i class="fas fa-paper-plane"></i> Apply
                </a>
            </div>

            <!-- Expandable details -->
            <?php if ($hasDetail): ?>
            <div class="row-details" id="w-detail-<?= (int)$j['job_id'] ?>">
                <?php if ($j['responsibilities']): ?>
                <div class="detail-block">
                    <h5><i class="fas fa-tasks"></i> Responsibilities</h5>
                    <ul>
                        <?php foreach ($toList($j['responsibilities']) as $line): ?>
                            <li><?= e(ltrim($line, '- ')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($j['requirements']): ?>
                <div class="detail-block">
                    <h5><i class="fas fa-check-double"></i> Requirements</h5>
                    <ul>
                        <?php foreach ($toList($j['requirements']) as $line): ?>
                            <li><?= e(ltrim($line, '- ')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($j['benefits']): ?>
                <div class="detail-block">
                    <h5><i class="fas fa-gift"></i> Benefits</h5>
                    <ul>
                        <?php foreach ($toList($j['benefits']) as $line): ?>
                            <li><?= e(ltrim($line, '- ')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- -- Footer ----------------------------------------------- -->
    <div class="widget-footer">
        Powered by <a href="<?= e($brand['website']) ?>" target="_blank" rel="noopener"><?= e($brand['name']) ?></a>
    </div>

</div><!-- /.widget-wrap -->

<script>
/* -- Filter state -------------------------------------------- */
var wActiveType = '<?= e(addslashes($filterType ?: 'all')) ?>';

function wSetType(btn) {
    document.querySelectorAll('.widget-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    wActiveType = btn.dataset.t;
    wFilter();
}

function wFilter() {
    var q = (document.getElementById('wSearch')?.value || '').toLowerCase().trim();
    var count = 0;

    document.querySelectorAll('#wList .job-row').forEach(function(card) {
        var matchT = wActiveType === 'all' || card.dataset.type === wActiveType;
        var matchQ = !q || card.dataset.search.includes(q);
        var show   = matchT && matchQ;
        card.style.display = show ? '' : 'none';
        if (show) count++;
    });

    var el = document.getElementById('wCount');
    if (el) el.textContent = count;
    postHeight();
}

/* -- Expand / collapse detail -------------------------------- */
function wToggle(id) {
    var det = document.getElementById('w-detail-' + id);
    var btn = document.getElementById('w-toggle-' + id);
    if (!det) return;
    var open = det.classList.toggle('open');
    btn.classList.toggle('open', open);
    btn.innerHTML = open
        ? '<i class="fas fa-chevron-down"></i> Hide details'
        : '<i class="fas fa-chevron-down"></i> Full details';
    postHeight();
}

/* -- Auto-resize host iframe --------------------------------- */
function postHeight() {
    var height = document.body.scrollHeight;
    // 'hivecolab-jobs-height' is kept for sites still running older copies of jobs-embed.js.
    window.parent.postMessage({ type: 'ims-jobs-height', height: height }, '*');
    window.parent.postMessage({ type: 'hivecolab-jobs-height', height: height }, '*');
}

postHeight();
window.addEventListener('resize', postHeight);

/* Also re-post after fonts/images load */
window.addEventListener('load', postHeight);
</script>
</body>
</html>