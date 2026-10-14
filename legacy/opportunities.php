<?php
declare(strict_types=1);

$page_title = 'Apply for Programs';

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


function opportunity_html(mixed $html): string
{
    $html = trim((string)($html ?? ''));

    if ($html === '') {
        return '';
    }

    $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><blockquote>';

    $html = strip_tags($html, $allowedTags);

    /*
     * Remove inline JavaScript/event handlers.
     */
    $html = preg_replace(
        '/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
        '',
        $html
    ) ?? $html;

    /*
     * Remove style attributes so saved content cannot alter the page layout.
     */
    $html = preg_replace(
        '/\s+style\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
        '',
        $html
    ) ?? $html;

    /*
     * Remove dangerous href protocols.
     */
    $html = preg_replace_callback(
        '/<a\b([^>]*)href\s*=\s*(["\'])(.*?)\2([^>]*)>/i',
        static function (array $matches): string {
            $before = $matches[1] ?? '';
            $href   = trim((string)($matches[3] ?? ''));
            $after  = $matches[4] ?? '';

            if (
                preg_match('/^\s*(?:javascript|data|vbscript):/i', $href)
            ) {
                $href = '#';
            }

            return '<a'
                . $before
                . 'href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                . $after
                . ' target="_blank" rel="noopener noreferrer">';
        },
        $html
    ) ?? $html;

    return $html;
}

function opportunity_text(mixed $html): string
{
    $text = strip_tags((string)($html ?? ''));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

function opportunity_timestamp(mixed $value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $timestamp = strtotime((string)$value);

    return $timestamp === false ? null : $timestamp;
}

function opportunity_date(
    mixed $value,
    string $format = 'd M Y',
    string $fallback = 'Not available'
): string {
    $timestamp = opportunity_timestamp($value);

    return $timestamp === null
        ? $fallback
        : date($format, $timestamp);
}

/*
|--------------------------------------------------------------------------
| LOAD OPEN OPPORTUNITIES
|--------------------------------------------------------------------------
*/

$opportunities = [];

$sql = "
    SELECT
        o.*,
        (
            SELECT COUNT(*)
            FROM applications a
            WHERE a.opportunity_id = o.opportunity_id
              AND a.status <> 'Draft'
        ) AS total_applications
    FROM application_opportunities o
    WHERE o.status = 'Published'
      AND (o.deadline IS NULL OR o.deadline >= CURDATE())
    ORDER BY
        o.is_featured DESC,
        CASE WHEN o.deadline IS NULL THEN 1 ELSE 0 END,
        o.deadline ASC,
        o.opportunity_id DESC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $opportunities[] = $row;
    }
}

$total = count($opportunities);

$featured = count(
    array_filter(
        $opportunities,
        static fn(array $opportunity): bool =>
            !empty($opportunity['is_featured'])
    )
);

$types = array_values(
    array_unique(
        array_filter(
            array_map(
                static fn(array $opportunity): string =>
                    trim((string)($opportunity['opportunity_type'] ?? '')),
                $opportunities
            )
        )
    )
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= e($page_title) ?> - Hive Colab</title>

<meta
    name="description"
    content="Apply to Hive Colab's open programs, accelerators, and opportunities. Join Africa's dynamic startup ecosystem."
>

<link rel="icon" type="image/png" href="/images/favicon.png">
<link rel="apple-touch-icon" href="/images/favicon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,700;0,9..40,800;1,9..40,400&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
 integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">

<link rel="stylesheet" href="/css/opportunities.css">

<style>
/*
|--------------------------------------------------------------------------
| RICH HTML INSIDE OPPORTUNITY CARDS
|--------------------------------------------------------------------------
*/

.opp-card-desc {
    color: #6b7280;
    font-size: 13.5px;
    line-height: 1.65;
    margin-top: 10px;
    overflow: hidden;
    position: relative;
}

/*
 * We render the saved HTML instead of escaping it.
 * Keep heading sizes compact inside cards.
 */
.opp-card-desc h2,
.opp-card-desc h3,
.opp-card-desc h4 {
    color: #374151;
    font-family: inherit;
    font-weight: 700;
    line-height: 1.35;
    margin: 0 0 7px;
}

.opp-card-desc h2 {
    font-size: 15px;
}

.opp-card-desc h3 {
    font-size: 14px;
}

.opp-card-desc h4 {
    font-size: 13.5px;
}

.opp-card-desc p {
    margin: 0 0 8px;
}

.opp-card-desc p:last-child {
    margin-bottom: 0;
}

.opp-card-desc strong,
.opp-card-desc b {
    color: #4b5563;
    font-weight: 700;
}

.opp-card-desc em,
.opp-card-desc i {
    font-style: italic;
}

.opp-card-desc ul,
.opp-card-desc ol {
    margin: 7px 0 8px 18px;
    padding: 0;
}

.opp-card-desc li {
    margin: 3px 0;
}

.opp-card-desc blockquote {
    margin: 8px 0;
    padding: 7px 10px;
    border-left: 3px solid #fb923c;
    background: #fff7ed;
    border-radius: 0 6px 6px 0;
}

.opp-card-desc a {
    color: #ea580c;
    text-decoration: none;
    text-underline-offset: 2px;
}

/*
 * Prevent unusually long HTML descriptions from making one card much taller
 * than the rest. The complete content remains available on the opportunity
 * details/application page.
 */
.opp-card-desc-rich {
    max-height: 150px;
    overflow: hidden;
}

.opp-card-desc-rich::after {
    content: '';
    pointer-events: none;
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 28px;
    background: linear-gradient(
        to bottom,
        rgba(255,255,255,0),
        rgba(255,255,255,1)
    );
}

.opp-card--featured .opp-card-desc-rich::after {
    background: linear-gradient(
        to bottom,
        rgba(255,253,247,0),
        rgba(255,253,247,1)
    );
}

.opp-card-desc-rich > :first-child {
    margin-top: 0;
}

.opp-card-desc-rich > :last-child {
    margin-bottom: 0;
}

@media (max-width: 640px) {
    .opp-card-desc-rich {
        max-height: 170px;
    }
}
</style>
<script src="/js/accessibility.js"></script>
</head>

<body>

<header class="opp-hero">
    <div class="opp-hero-inner">
        <div class="opp-hero-left">
            <div class="opp-eyebrow">
                <span class="opp-dot"></span>
                Open Applications
            </div>

            <h1>
                Apply to Our<br>
                <span class="opp-hero-accent">Programs</span>
            </h1>

            <p class="opp-hero-sub">
                Join Hive Colab's innovation ecosystem and take your startup
                to the next level. Applications are reviewed on a rolling basis.
            </p>
        </div>

        <div class="opp-hero-stats">
            <div class="opp-stat">
                <span class="opp-stat-num"><?= $total ?></span>
                <span class="opp-stat-label">Open Now</span>
            </div>

            <div class="opp-stat">
                <span class="opp-stat-num"><?= $featured ?></span>
                <span class="opp-stat-label">Featured</span>
            </div>

            <div class="opp-stat">
                <span class="opp-stat-num"><?= count($types) ?></span>
                <span class="opp-stat-label">Program Types</span>
            </div>
        </div>
    </div>
</header>

<main class="opp-main">

    <?php if (empty($opportunities)): ?>

        <div class="opp-empty">
            <div class="opp-empty-icon">
                <i class="fas fa-rocket"></i>
            </div>

            <h2>No Open Applications Right Now</h2>

            <p>
                We're always launching new programs. Check back soon or follow us
                to be the first to know.
            </p>

            <a href="/" class="opp-back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Home
            </a>
        </div>

    <?php else: ?>

        <div class="opp-filter-bar">

            <div class="opp-search-wrap">
                <i class="fas fa-search opp-search-icon"></i>

                <input
                    type="search"
                    id="oppSearch"
                    class="opp-search-input"
                    placeholder="Search programs..."
                    autocomplete="off"
                    aria-label="Search programs"
                >
            </div>

            <div class="opp-type-pills" id="oppTypePills">
                <button
                    type="button"
                    class="opp-pill active"
                    data-type="all"
                >
                    All
                </button>

                <?php foreach ($types as $type): ?>
                    <button
                        type="button"
                        class="opp-pill"
                        data-type="<?= e($type) ?>"
                    >
                        <?= e($type) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="opp-results-count">
                <span id="oppCount"><?= $total ?></span>
                <span id="oppCountLabel">
                    program<?= $total !== 1 ? 's' : '' ?>
                </span>
            </div>
        </div>

        <div class="opp-grid" id="oppGrid">

            <?php foreach ($opportunities as $index => $opportunity):

                $deadlineTimestamp = opportunity_timestamp(
                    $opportunity['deadline'] ?? null
                );

                $todayTimestamp = strtotime('today');

                $daysLeft = $deadlineTimestamp === null
                    ? null
                    : max(
                        0,
                        (int)floor(
                            ($deadlineTimestamp - $todayTimestamp) / 86400
                        )
                    );

                $isSoon = $daysLeft !== null && $daysLeft <= 7;

                $fillPercent = 0;
                $slotsFull = false;

                $maxApplicants = (int)($opportunity['max_applicants'] ?? 0);
                $totalApplications = (int)($opportunity['total_applications'] ?? 0);

                if ($maxApplicants > 0) {
                    $fillPercent = min(
                        100,
                        (int)round(
                            ($totalApplications / $maxApplicants) * 100
                        )
                    );

                    $slotsFull = $fillPercent >= 100;
                }

                $featuredClass = !empty($opportunity['is_featured'])
                    ? ' opp-card--featured'
                    : '';

                $descriptionHtml = opportunity_html(
                    $opportunity['description'] ?? ''
                );

                $searchText = strtolower(
                    trim(
                        (string)($opportunity['opportunity_title'] ?? '')
                        . ' '
                        . (string)($opportunity['opportunity_type'] ?? '')
                        . ' '
                        . opportunity_text($opportunity['description'] ?? '')
                    )
                );
            ?>

                <article
                    class="opp-card<?= $featuredClass ?>"
                    data-type="<?= e($opportunity['opportunity_type'] ?? '') ?>"
                    data-search="<?= e($searchText) ?>"
                    style="animation-delay:<?= e(number_format($index * 0.06, 2)) ?>s"
                >

                    <?php if (!empty($opportunity['is_featured'])): ?>
                        <div class="opp-feat-ribbon">
                            <i class="fas fa-star"></i>
                            Featured
                        </div>
                    <?php endif; ?>

                    <div class="opp-card-head">

                        <?php if (!empty($opportunity['opportunity_type'])): ?>
                            <span class="opp-type-badge">
                                <?= e($opportunity['opportunity_type']) ?>
                            </span>
                        <?php endif; ?>

                        <h2 class="opp-card-title">
                            <?= e($opportunity['opportunity_title'] ?? 'Opportunity') ?>
                        </h2>

                        <?php if ($descriptionHtml !== ''): ?>
                            <div class="opp-card-desc opp-card-desc-rich">
                                <?= $descriptionHtml ?>
                            </div>
                        <?php endif; ?>

                    </div>

                    <div class="opp-card-meta">

                        <div class="opp-meta-row <?= $isSoon ? 'opp-meta-row--urgent' : '' ?>">
                            <i class="fas fa-clock"></i>

                            <span>
                                <?php if ($deadlineTimestamp === null): ?>

                                    <strong>Open until further notice</strong>

                                <?php elseif ($daysLeft === 0): ?>

                                    <strong>Closes today!</strong>

                                <?php elseif ($isSoon): ?>

                                    <strong><?= (int)$daysLeft ?>d left</strong>
                                    - closes
                                    <?= e(opportunity_date($opportunity['deadline'], 'd M')) ?>

                                <?php else: ?>

                                    Closes
                                    <strong>
                                        <?= e(opportunity_date($opportunity['deadline'])) ?>
                                    </strong>

                                <?php endif; ?>
                            </span>
                        </div>

                        <?php if (!empty($opportunity['start_date'])): ?>
                            <div class="opp-meta-row">
                                <i class="fas fa-calendar-check"></i>

                                <span>
                                    Starts
                                    <strong>
                                        <?= e(
                                            opportunity_date(
                                                $opportunity['start_date']
                                            )
                                        ) ?>
                                    </strong>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (
                            isset($opportunity['available_slots'])
                            && $opportunity['available_slots'] !== null
                            && $opportunity['available_slots'] !== ''
                        ): ?>
                            <div class="opp-meta-row">
                                <i class="fas fa-users"></i>

                                <span>
                                    <strong>
                                        <?= (int)$opportunity['available_slots'] ?>
                                    </strong>
                                    slots available
                                </span>
                            </div>
                        <?php endif; ?>

                    </div>

                    <?php if ($maxApplicants > 0): ?>
                        <div class="opp-slots">

                            <div class="opp-slots-track">
                                <div
                                    class="opp-slots-fill <?= $fillPercent >= 80 ? 'opp-slots-fill--hot' : '' ?>"
                                    style="width:<?= (int)$fillPercent ?>%"
                                ></div>
                            </div>

                            <span class="opp-slots-label">
                                <?= $totalApplications ?>
                                /
                                <?= $maxApplicants ?>
                                applicants
                            </span>

                        </div>
                    <?php endif; ?>

                    <?php if ($slotsFull): ?>

                        <div class="opp-full-notice">
                            <i class="fas fa-ban"></i>
                            Applications closed - all slots filled
                        </div>

                    <?php else: ?>

                        <a
                            href="/submit-application?opportunity_id=<?= (int)($opportunity['opportunity_id'] ?? 0) ?>"
                            class="opp-apply-btn"
                        >
                            <i class="fas fa-paper-plane"></i>

                            Apply Now

                            <i class="fas fa-arrow-right opp-apply-arrow"></i>
                        </a>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

            <div
                class="opp-no-results"
                id="oppNoResults"
                style="display:none;"
            >
                <i class="fas fa-search"></i>

                <p>No programs match your search.</p>

                <button type="button" id="oppResetFilter">
                    Clear filter
                </button>
            </div>

        </div>

    <?php endif; ?>

</main>

<footer class="opp-footer">
    <p>
        &copy; <?= date('Y') ?>
        <a href="https://hivecolab.org/">Hive Colab</a>.
        All rights reserved.
    </p>
</footer>

<script>
(function () {
    'use strict';

    let activeType = 'all';

    const searchInput = document.getElementById('oppSearch');
    const pills = Array.from(document.querySelectorAll('.opp-pill'));
    const cards = Array.from(document.querySelectorAll('#oppGrid .opp-card'));
    const countElement = document.getElementById('oppCount');
    const countLabel = document.getElementById('oppCountLabel');
    const noResults = document.getElementById('oppNoResults');
    const resetButton = document.getElementById('oppResetFilter');

    function filterOpps() {
        const query = (searchInput?.value || '')
            .toLowerCase()
            .trim();

        let visibleCount = 0;

        cards.forEach(function (card) {
            const matchesType =
                activeType === 'all'
                || card.dataset.type === activeType;

            const matchesSearch =
                query === ''
                || (card.dataset.search || '').includes(query);

            const visible =
                matchesType
                && matchesSearch;

            card.style.display =
                visible
                    ? ''
                    : 'none';

            if (visible) {
                visibleCount++;
            }
        });

        if (countElement) {
            countElement.textContent = String(visibleCount);
        }

        if (countLabel) {
            countLabel.textContent =
                visibleCount === 1
                    ? 'program'
                    : 'programs';
        }

        if (noResults) {
            noResults.style.display =
                visibleCount === 0
                    ? 'flex'
                    : 'none';
        }
    }

    pills.forEach(function (button) {
        button.addEventListener('click', function () {
            pills.forEach(function (pill) {
                pill.classList.remove('active');
            });

            button.classList.add('active');

            activeType =
                button.dataset.type
                || 'all';

            filterOpps();
        });
    });

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            filterOpps
        );
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
            }

            activeType = 'all';

            pills.forEach(function (pill) {
                pill.classList.toggle(
                    'active',
                    pill.dataset.type === 'all'
                );
            });

            filterOpps();
        });
    }
})();
</script>

</body>
</html>
