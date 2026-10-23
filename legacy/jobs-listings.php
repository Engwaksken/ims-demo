<?php
require_once 'includes/config.php';

if (!function_exists('e')) {
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$jobs = [];
$res  = $conn->query("
    SELECT *
    FROM jobs
    WHERE status = 'Published'
      AND (deadline IS NULL OR deadline >= CURDATE())
    ORDER BY is_featured DESC, is_urgent DESC, created_at DESC
");
while ($r = $res->fetch_assoc()) $jobs[] = $r;

$types       = array_unique(array_filter(array_column($jobs, 'job_type')));
$departments = array_unique(array_filter(array_column($jobs, 'department')));
$locations   = array_unique(array_filter(array_column($jobs, 'location')));

$total    = count($jobs);
$featured = count(array_filter($jobs, fn($j) => $j['is_featured']));
$urgent   = count(array_filter($jobs, fn($j) => $j['is_urgent']));

$page_title = 'Career Opportunities';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($page_title) ?> - Hive Colab</title>
<meta name="description" content="Explore open positions at Hive Colab and join Africa's most dynamic startup ecosystem.">
<link rel="icon" type="image/png" href="images/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/jobs-listing.css">
<script src="js/accessibility.js"></script>
</head>
<body>

<!-- -- HERO ---------------------------------------------------- -->
<section class="hero">
  <div class="hero-inner">
    <div class="hero-left">
      <div class="hero-tag">
        <span class="dot"></span>
        Now Hiring
      </div>
      <h1>
        Shape Africa's
        <span class="accent">Startup Future</span>
        <span class="outline">With Us</span>
      </h1>
      <p class="hero-sub">
        Join a team of builders, designers, and dreamers accelerating
        the continent's most ambitious founders from Kampala and beyond.
      </p>
    </div><!-- /.hero-left -->

    <div class="hero-stats">
      <div class="hero-stat">
        <div class="hero-stat-num" id="heroTotal"><?= $total ?></div>
        <div class="hero-stat-label">Open Roles</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-num"><?= count($types) ?></div>
        <div class="hero-stat-label">Job Types</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-num"><?= $featured ?></div>
        <div class="hero-stat-label">Featured</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-num"><?= $urgent ?></div>
        <div class="hero-stat-label">Urgent Hire</div>
      </div>
    </div>

  </div><!-- /.hero-inner -->
</section>

<!-- -- MAIN ---------------------------------------------------- -->
<div class="page-wrap">

  <!-- -- Sidebar ----------------------------------------------- -->
  <aside class="sidebar" id="sidebar">

    <!-- Search -->
    <div class="sidebar-panel">
      <div class="sidebar-head">
        <span>Search</span>
      </div>
      <div class="sb-search">
        <div class="sb-search-inner">
          <i class="fas fa-search"></i>
          <input
            type="search"
            id="searchInput"
            placeholder="Title, department..."
            oninput="runFilter()"
            autocomplete="off"
          >
        </div>
      </div>
    </div>

    <!-- Job type filter -->
    <?php if (!empty($types)): ?>
    <div class="sidebar-panel">
      <div class="sidebar-head">
        <span>Job Type</span>
        <button onclick="clearGroup('type')">Clear</button>
      </div>
      <div style="padding:8px 10px;">
        <button class="sb-opt active" data-group="type" data-val="all" onclick="setFilter(this)">
          All Types
          <span class="cnt"><?= $total ?></span>
        </button>
        <?php
        $typeCounts = array_count_values(array_column($jobs, 'job_type'));
        foreach ($types as $t): ?>
          <button class="sb-opt" data-group="type" data-val="<?= e($t) ?>" onclick="setFilter(this)">
            <?= e($t) ?>
            <span class="cnt"><?= $typeCounts[$t] ?? 0 ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Department filter -->
    <?php if (!empty($departments)): ?>
    <div class="sidebar-panel">
      <div class="sidebar-head">
        <span>Department</span>
        <button onclick="clearGroup('dept')">Clear</button>
      </div>
      <div style="padding:8px 10px;">
        <button class="sb-opt active" data-group="dept" data-val="all" onclick="setFilter(this)">
          All Departments
          <span class="cnt"><?= $total ?></span>
        </button>
        <?php
        $deptCounts = array_count_values(array_column($jobs, 'department'));
        foreach ($departments as $d): ?>
          <button class="sb-opt" data-group="dept" data-val="<?= e($d) ?>" onclick="setFilter(this)">
            <?= e($d) ?>
            <span class="cnt"><?= $deptCounts[$d] ?? 0 ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Location filter -->
    <?php if (!empty($locations)): ?>
    <div class="sidebar-panel">
      <div class="sidebar-head">
        <span>Location</span>
        <button onclick="clearGroup('loc')">Clear</button>
      </div>
      <div style="padding:8px 10px;">
        <button class="sb-opt active" data-group="loc" data-val="all" onclick="setFilter(this)">
          All Locations
          <span class="cnt"><?= $total ?></span>
        </button>
        <?php
        $locCounts = array_count_values(array_column($jobs, 'location'));
        foreach ($locations as $l): ?>
          <button class="sb-opt" data-group="loc" data-val="<?= e($l) ?>" onclick="setFilter(this)">
            <?= e($l) ?>
            <span class="cnt"><?= $locCounts[$l] ?? 0 ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick toggles -->
    <div class="sidebar-panel">
      <div class="sidebar-head"><span>Quick Filters</span></div>
      <div class="sb-toggles">
        <label class="sb-toggle">
          <input type="checkbox" id="chkFeatured" onchange="runFilter()">
          <span><i class="fas fa-star" style="color:var(--gold);margin-right:5px;font-size:10px;"></i> Featured only</span>
        </label>
        <label class="sb-toggle">
          <input type="checkbox" id="chkUrgent" onchange="runFilter()">
          <span><i class="fas fa-fire" style="color:var(--red);margin-right:5px;font-size:10px;"></i> Urgent hire only</span>
        </label>
      </div>
    </div>

  </aside>

  <!-- -- Job list ---------------------------------------------- -->
  <div class="jobs-col">

    <div class="list-head">
      <div class="list-count">
        <strong id="listCount"><?= $total ?></strong> open position<?= $total !== 1 ? 's' : '' ?>
      </div>
      <div class="sort-wrap">
        Sort by
        <select id="sortSel" onchange="runFilter()">
          <option value="default">Relevance</option>
          <option value="deadline">Closing Soon</option>
          <option value="title">A - Z</option>
        </select>
      </div>
    </div>

    <!-- Active filter chips -->
    <div class="active-filters" id="activeFilters"></div>

    <?php if (empty($jobs)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-briefcase"></i></div>
        <h3>No Open Positions</h3>
        <p>We're always growing. Check back soon or follow us to be first to know.</p>
      </div>
    <?php else: ?>
      <div id="jobsList">
        <?php foreach ($jobs as $j):
          $deadline = $j['deadline'] ? new DateTime($j['deadline']) : null;
          $today    = new DateTime();
          $daysLeft = $deadline ? (int)$today->diff($deadline)->days : null;
          $soon     = $daysLeft !== null && $daysLeft <= 7;

          // Salary
          $salary = '';
          if (!empty($j['show_salary']) && ($j['salary_min'] || $j['salary_max'])) {
            $cur    = $j['salary_currency'] ?? '';
            $period = $j['salary_period']   ?? '';
            $fmt    = fn($v) => $cur . ' ' . number_format((float)$v, 0);
            if ($j['salary_min'] && $j['salary_max'])
              $salary = $fmt($j['salary_min']) . ' - ' . $fmt($j['salary_max']);
            elseif ($j['salary_min'])
              $salary = 'From ' . $fmt($j['salary_min']);
            else
              $salary = 'Up to ' . $fmt($j['salary_max']);
          }

          $toList  = fn($txt) => array_filter(array_map('trim', preg_split('/\r?\n/', $txt ?? '')));
          $classes = trim(
            'job-row' .
            ($j['is_featured'] ? ' featured' : '') .
            ($j['is_urgent']   ? ' urgent'   : '')
          );

          $applyHref   = !empty($j['apply_url']) ? e($j['apply_url']) : 'apply-job?job_id=' . (int)$j['job_id'];
          $applyTarget = !empty($j['apply_url']) ? '_blank' : '_self';
        ?>
        <div class="<?= $classes ?>"
             id="job-<?= (int)$j['job_id'] ?>"
             data-type="<?= e($j['job_type'] ?? '') ?>"
             data-dept="<?= e($j['department'] ?? '') ?>"
             data-loc="<?= e($j['location'] ?? '') ?>"
             data-featured="<?= $j['is_featured'] ? '1' : '0' ?>"
             data-urgent="<?= $j['is_urgent'] ? '1' : '0' ?>"
             data-deadline="<?= e($j['deadline'] ?? '') ?>"
             data-search="<?= e(strtolower($j['job_title'] . ' ' . ($j['department'] ?? '') . ' ' . ($j['location'] ?? ''))) ?>">

          <!-- Left column -->
          <div class="row-left">
            <div class="row-tags">
              <?php if ($j['job_type']): ?>
                <span class="tag tag-type"><i class="fas fa-briefcase"></i><?= e($j['job_type']) ?></span>
              <?php endif; ?>
              <?php if ($j['department']): ?>
                <span class="tag tag-dept"><i class="fas fa-building"></i><?= e($j['department']) ?></span>
              <?php endif; ?>
              <?php if ($j['experience_level']): ?>
                <span class="tag tag-lvl"><?= e($j['experience_level']) ?></span>
              <?php endif; ?>
              <?php if ($j['is_featured']): ?>
                <span class="tag tag-feat"><i class="fas fa-star"></i>Featured</span>
              <?php endif; ?>
              <?php if ($j['is_urgent']): ?>
                <span class="tag tag-urgent"><i class="fas fa-fire"></i>Urgent</span>
              <?php endif; ?>
            </div>

            <h2 class="row-title"><?= e($j['job_title']) ?></h2>

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
              <?php if ($j['max_applicants']): ?>
                <span class="meta-item"><i class="fas fa-users"></i><?= (int)$j['max_applicants'] ?> slots</span>
              <?php endif; ?>
            </div>

            <?php if ($j['description']): ?>
              <p class="row-desc"><?= e(substr($j['description'], 0, 240)) . (strlen($j['description']) > 240 ? '...' : '') ?></p>
            <?php endif; ?>
          </div>

          <!-- Right column: salary + CTAs -->
          <div class="row-right">
            <?php if ($salary): ?>
              <div class="row-salary">
                <span class="salary-amt"><?= e($salary) ?></span>
                <?php if (!empty($j['salary_period'])): ?>
                  <span class="salary-per">per <?= e($j['salary_period']) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <a href="<?= $applyHref ?>" target="<?= $applyTarget ?>" class="apply-btn">
              <i class="fas fa-paper-plane"></i> Apply Now
            </a>
            <button class="share-btn" onclick="shareJob(<?= (int)$j['job_id'] ?>, <?= htmlspecialchars(json_encode((string)($j['job_title'])), ENT_QUOTES, 'UTF-8') ?>)">
              <i class="fas fa-share-alt"></i> Share Role
            </button>
          </div>

          <!-- Expand button -->
          <?php if ($j['responsibilities'] || $j['requirements'] || $j['benefits']): ?>
            <button class="expand-btn" id="toggle-<?= (int)$j['job_id'] ?>"
                    onclick="toggleDetails(<?= (int)$j['job_id'] ?>)">
              <i class="fas fa-chevron-down"></i> View full details
            </button>
          <?php endif; ?>

          <!-- Expandable details -->
          <?php if ($j['responsibilities'] || $j['requirements'] || $j['benefits']): ?>
          <div class="row-details" id="details-<?= (int)$j['job_id'] ?>">
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
              <h5><i class="fas fa-gift"></i> What We Offer</h5>
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

  </div><!-- /.jobs-col -->
</div><!-- /.page-wrap -->

<!-- -- FOOTER -------------------------------------------------- -->
<footer class="footer">
  <p>&copy; <?= date('Y') ?> <a href="https://hivecolab.org/">Hive Colab</a>. All rights reserved. &nbsp;.&nbsp; Built to accelerate Africa's builders.</p>
</footer>

<script>
/* ----------------------------------------
   STATE
---------------------------------------- */
const state = { type: 'all', dept: 'all', loc: 'all' };

/* ----------------------------------------
   FILTER BUTTONS
---------------------------------------- */
function setFilter(btn) {
  const group = btn.dataset.group;
  const val   = btn.dataset.val;
  state[group] = val;

  // Toggle active in this group
  document.querySelectorAll(`.sb-opt[data-group="${group}"]`).forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  runFilter();
}

function clearGroup(group) {
  state[group] = 'all';
  document.querySelectorAll(`.sb-opt[data-group="${group}"]`).forEach(b => b.classList.remove('active'));
  const allBtn = document.querySelector(`.sb-opt[data-group="${group}"][data-val="all"]`);
  if (allBtn) allBtn.classList.add('active');
  runFilter();
}

/* ----------------------------------------
   MAIN FILTER + SORT
---------------------------------------- */
function runFilter() {
  const q        = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
  const onlyFeat = document.getElementById('chkFeatured')?.checked;
  const onlyUrg  = document.getElementById('chkUrgent')?.checked;
  const sort     = document.getElementById('sortSel')?.value || 'default';

  const cards = [...document.querySelectorAll('#jobsList .job-row')];

  // Filter
  let visible = [];
  cards.forEach(card => {
    const matchType = state.type === 'all' || card.dataset.type === state.type;
    const matchDept = state.dept === 'all' || card.dataset.dept === state.dept;
    const matchLoc  = state.loc  === 'all' || card.dataset.loc  === state.loc;
    const matchQ    = !q || card.dataset.search.includes(q);
    const matchFeat = !onlyFeat || card.dataset.featured === '1';
    const matchUrg  = !onlyUrg  || card.dataset.urgent   === '1';

    const show = matchType && matchDept && matchLoc && matchQ && matchFeat && matchUrg;
    card.style.display = show ? '' : 'none';
    if (show) visible.push(card);
  });

  // Sort visible rows
  const list = document.getElementById('jobsList');
  if (sort === 'deadline') {
    visible.sort((a, b) => {
      const da = a.dataset.deadline || '9999-12-31';
      const db = b.dataset.deadline || '9999-12-31';
      return da.localeCompare(db);
    });
  } else if (sort === 'title') {
    visible.sort((a, b) => {
      const ta = a.querySelector('.row-title')?.textContent.trim() || '';
      const tb = b.querySelector('.row-title')?.textContent.trim() || '';
      return ta.localeCompare(tb);
    });
  }

  // Re-append in sorted order
  visible.forEach(c => list.appendChild(c));

  // Update count
  document.getElementById('listCount').textContent = visible.length;

  // Active filter chips
  updateChips(q, onlyFeat, onlyUrg);
}

/* ----------------------------------------
   ACTIVE FILTER CHIPS
---------------------------------------- */
function updateChips(q, feat, urg) {
  const wrap = document.getElementById('activeFilters');
  wrap.innerHTML = '';

  if (state.type !== 'all') addChip(wrap, 'Type: ' + state.type, () => clearGroup('type'));
  if (state.dept !== 'all') addChip(wrap, 'Dept: ' + state.dept, () => clearGroup('dept'));
  if (state.loc  !== 'all') addChip(wrap, 'Loc: '  + state.loc,  () => clearGroup('loc'));
  if (q)                     addChip(wrap, 'Search: ' + q, () => { document.getElementById('searchInput').value=''; runFilter(); });
  if (feat)                  addChip(wrap, 'Featured', () => { document.getElementById('chkFeatured').checked=false; runFilter(); });
  if (urg)                   addChip(wrap, 'Urgent',   () => { document.getElementById('chkUrgent').checked=false;  runFilter(); });
}

function addChip(wrap, label, onRemove) {
  const chip = document.createElement('span');
  chip.className = 'filter-chip';
  chip.innerHTML = `${escHtml(label)} <button title="Remove filter">×</button>`;
  chip.querySelector('button').addEventListener('click', onRemove);
  wrap.appendChild(chip);
}

/* ----------------------------------------
   EXPAND DETAILS
---------------------------------------- */
function toggleDetails(id) {
  const det = document.getElementById('details-' + id);
  const btn = document.getElementById('toggle-' + id);
  if (!det) return;
  const open = det.classList.toggle('open');
  btn.classList.toggle('open', open);
  btn.innerHTML = open
    ? '<i class="fas fa-chevron-down"></i> Hide details'
    : '<i class="fas fa-chevron-down"></i> View full details';
}

/* ----------------------------------------
   SHARE
---------------------------------------- */
function shareJob(id, title) {
  const url = location.origin + location.pathname + '#job-' + id;
  const shareData = { title: title + ' - Hive Colab', url };

  if (navigator.share) {
    navigator.share(shareData).catch(() => {});
  } else {
    navigator.clipboard.writeText(url).then(() => {
      const btn = event.currentTarget;
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
      btn.style.color = 'var(--green)';
      btn.style.borderColor = 'var(--green)';
      setTimeout(() => {
        btn.innerHTML = orig;
        btn.style.color = '';
        btn.style.borderColor = '';
      }, 2200);
    });
  }
}

/* ----------------------------------------
   ANCHOR HIGHLIGHT
---------------------------------------- */
window.addEventListener('load', () => {
  const hash = location.hash;
  if (hash && hash.startsWith('#job-')) {
    const card = document.getElementById(hash.slice(1));
    if (card) {
      setTimeout(() => {
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.style.borderColor = 'var(--orange)';
        card.style.boxShadow   = '0 0 0 3px rgba(255,92,26,.2)';
        setTimeout(() => {
          card.style.borderColor = '';
          card.style.boxShadow   = '';
        }, 2400);
      }, 350);
    }
  }
});

/* ----------------------------------------
   UTILS
---------------------------------------- */
function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>