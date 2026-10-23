<?php
declare(strict_types=1);

$page_title = 'Opportunity Details';
require_once 'includes/header.php';

check_role([
    'Administrator',
    'MEAL Lead',
    'Programs Lead',
    'Program Manager',
    'Program Director',
]);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function decode_json_array(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function safe_saved_html(?string $html): string
{
    $html = trim((string)$html);

    if ($html === '') {
        return '';
    }

    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><blockquote>';

    $html = strip_tags($html, $allowed);

    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/\son\w+\s*=\s*[^\s>]+/isu', '', $html) ?? $html;
    $html = preg_replace('/javascript\s*:/iu', '', $html) ?? $html;
    $html = preg_replace('/data\s*:/iu', '', $html) ?? $html;

    return $html;
}

function safe_inline_html(?string $html): string
{
    $html = trim((string)$html);

    if ($html === '') {
        return '';
    }

    $html = strip_tags($html, '<strong><b><em><i><u><a>');
    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/javascript\s*:/iu', '', $html) ?? $html;
    $html = preg_replace('/data\s*:/iu', '', $html) ?? $html;

    return $html;
}

function format_date_value(mixed $value, string $format = 'd M Y'): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '' || str_starts_with($value, '0000')) {
        return '-';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date($format, $timestamp) : '-';
}

function app_status_badge(string $status): string
{
    return match ($status) {
        'Selected', 'Accepted' => 'badge-available',
        'Rejected'             => 'badge-rejected',
        'Shortlisted'          => 'badge-pending',
        'Under Review'         => 'badge-purple',
        'Draft'                => 'badge-returned',
        default                => 'badge-assigned',
    };
}

function application_sector_label(array $application): string
{
    $focus = decode_json_array($application['sector_focus'] ?? null);

    if ($focus !== []) {
        return implode(', ', array_slice(array_map('strval', $focus), 0, 2));
    }

    return trim((string)($application['sector'] ?? '')) ?: '-';
}

/*
|--------------------------------------------------------------------------
| LOAD OPPORTUNITY + COHORT
|--------------------------------------------------------------------------
*/
$opportunity_id = (int)($_GET['id'] ?? 0);

if ($opportunity_id < 1) {
    $_SESSION['error'] = 'Invalid opportunity ID.';
    header('Location: application-opportunities');
    exit;
}

$stmt = $conn->prepare("
    SELECT
        o.*,
        u.full_name AS created_by_name,
        c.id AS cohort_record_id,
        c.name AS cohort_name,
        c.slug AS cohort_slug,
        c.start_date AS cohort_start_date,
        c.end_date AS cohort_end_date,
        c.application_status AS cohort_application_status,
        c.status AS cohort_status
    FROM application_opportunities o
    LEFT JOIN users u
        ON u.user_id = o.created_by
    LEFT JOIN cohorts c
        ON c.id = o.cohort_id
    WHERE o.opportunity_id = ?
    LIMIT 1
");

if (!$stmt) {
    $_SESSION['error'] = 'Unable to load opportunity details.';
    header('Location: application-opportunities');
    exit;
}

$stmt->bind_param('i', $opportunity_id);
$stmt->execute();
$opportunity = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$opportunity) {
    $_SESSION['error'] = 'Opportunity not found.';
    header('Location: application-opportunities');
    exit;
}

/*
|--------------------------------------------------------------------------
| APPLICATION STATISTICS
|--------------------------------------------------------------------------
*/
$stats = [
    'total'        => 0,
    'Draft'        => 0,
    'Submitted'    => 0,
    'Under Review' => 0,
    'Shortlisted'  => 0,
    'Selected'     => 0,
    'Accepted'     => 0,
    'Rejected'     => 0,
];

$stmt = $conn->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM applications
    WHERE opportunity_id = ?
    GROUP BY status
");

if ($stmt) {
    $stmt->bind_param('i', $opportunity_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $statusKey = (string)($row['status'] ?? '');
        $count = (int)($row['cnt'] ?? 0);

        $stats['total'] += $count;
        $stats[$statusKey] = $count;
    }

    $stmt->close();
}

$selectedCount = (int)($stats['Selected'] ?? 0) + (int)($stats['Accepted'] ?? 0);

/*
|--------------------------------------------------------------------------
| RECENT APPLICATIONS
|--------------------------------------------------------------------------
*/
$recent_apps = [];

$stmt = $conn->prepare("
    SELECT
        application_id,
        reference_number,
        startup_name,
        contact_person,
        email,
        status,
        sector_focus,
        sector,
        submitted_at,
        last_saved_at,
        created_at
    FROM applications
    WHERE opportunity_id = ?
    ORDER BY COALESCE(submitted_at, last_saved_at, created_at) DESC
    LIMIT 5
");

if ($stmt) {
    $stmt->bind_param('i', $opportunity_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recent_apps[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| NEW RICH OPPORTUNITY CONTENT
|--------------------------------------------------------------------------
*/
$content_sections = decode_json_array($opportunity['content_sections'] ?? null);
$eligibility_bullets = decode_json_array($opportunity['eligibility_bullets'] ?? null);

$sectors = [];

if (!empty($opportunity['sectors_allowed'])) {
    $sectors = array_values(
        array_filter(
            array_map(
                'trim',
                explode(',', (string)$opportunity['sectors_allowed'])
            )
        )
    );
}

/*
|--------------------------------------------------------------------------
| DERIVED VALUES
|--------------------------------------------------------------------------
*/
$status = (string)($opportunity['status'] ?? 'Draft');

$badgeMap = [
    'Published' => 'badge-available',
    'Draft'     => 'badge-pending',
    'Closed'    => 'badge-returned',
    'Completed' => 'badge-purple',
];

$badgeClass = $badgeMap[$status] ?? 'badge-returned';

$days_remaining = null;

if (!empty($opportunity['deadline'])) {
    $deadlineTimestamp = strtotime((string)$opportunity['deadline']);

    if ($deadlineTimestamp) {
        $today = new DateTimeImmutable('today');
        $deadlineDate = (new DateTimeImmutable())->setTimestamp($deadlineTimestamp)->setTime(0, 0);
        $days_remaining = (int)$today->diff($deadlineDate)->format('%r%a');
    }
}

$percentage_filled = 0.0;

if (!empty($opportunity['max_applicants']) && (int)$opportunity['max_applicants'] > 0) {
    $percentage_filled = min(
        ($stats['total'] / (int)$opportunity['max_applicants']) * 100,
        100
    );
}

$titleJs = h(addslashes((string)($opportunity['opportunity_title'] ?? '')));
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
/* View opportunity: two-column layout, info tiles, rich text, capacity bar,
   timeline and recent-application list. */
.vo-layout { display: grid; grid-template-columns: minmax(0, 1fr) 350px; gap: 20px; align-items: start; }
.vo-stack { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.vo-hero-meta { display: flex; gap: 8px 18px; flex-wrap: wrap; align-items: center; }
.vo-actionbar { display: flex; gap: 8px; flex-wrap: wrap; }
.vo-alert-body { flex: 1; line-height: 1.5; }
.vo-alert-body strong { display: block; font-size: 14px; }
.vo-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.vo-info-item { background: var(--ink-50); border-radius: var(--radius-md); padding: 14px 16px; border-left: 3px solid var(--brand-400); }
.vo-info-label { font-size: 10px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; color: var(--ink-300); margin-bottom: 4px; }
.vo-info-value { font-size: 14px; font-weight: 700; color: var(--ink-700); line-height: 1.45; }
.vo-section { padding-top: 20px; margin-top: 20px; border-top: 1px solid var(--ink-100); }
.tab-panel > .vo-section:first-child { padding-top: 0; margin-top: 0; border-top: 0; }
.vo-section-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 800; color: var(--ink-700); margin-bottom: 12px; }
.vo-section-title i { color: var(--brand-500); }
.vo-rich { font-size: 14px; color: var(--ink-400); line-height: 1.8; }
.vo-rich p { margin: 0 0 12px; }
.vo-rich ul, .vo-rich ol { padding-left: 22px; margin: 10px 0; }
.vo-rich li { margin: 7px 0; }
.vo-rich h2, .vo-rich h3, .vo-rich h4 { color: var(--ink-700); margin: 18px 0 8px; }
.vo-rich a { color: var(--brand-600); text-decoration: none; }
.vo-rich blockquote { border-left: 4px solid var(--brand-200); margin: 14px 0; padding: 10px 14px; background: var(--brand-50); border-radius: 0 var(--radius-sm) var(--radius-sm) 0; }
.vo-content-card { border: 1px solid var(--ink-100); border-radius: var(--radius-lg); padding: 18px; background: var(--surface-card); margin-bottom: 14px; }
.vo-content-card:last-child { margin-bottom: 0; }
.vo-content-card h3 { font-size: 15px; margin: 0 0 10px; color: var(--ink-700); }
.vo-bullets { padding-left: 20px; margin: 10px 0 0; color: var(--ink-400); line-height: 1.7; }
.vo-bullets li { margin: 7px 0; }
.vo-sectors { display: flex; flex-wrap: wrap; gap: 8px; }
.vo-sector-tag { display: inline-flex; align-items: center; padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--brand-50); color: var(--brand-700); border: 1px solid var(--brand-100); }
.vo-capacity { display: flex; flex-direction: column; gap: 8px; }
.vo-capacity-row { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--ink-400); }
.vo-capacity-row strong { color: var(--ink-700); }
.vo-capacity-track { height: 10px; background: var(--ink-100); border-radius: 999px; overflow: hidden; }
.vo-capacity-fill { height: 100%; background: var(--green-fg); border-radius: 999px; }
.vo-capacity-fill.hot { background: var(--amber-fg); }
.vo-capacity-fill.full { background: var(--red-fg); }
.vo-timeline { position: relative; padding-left: 28px; }
.vo-timeline::before { content: ''; position: absolute; left: 8px; top: 8px; bottom: 8px; width: 2px; background: var(--ink-100); }
.vo-tl-item { position: relative; padding: 14px 0 14px 16px; }
.vo-tl-item::before { content: ''; position: absolute; left: -20px; top: 18px; width: 12px; height: 12px; border-radius: 50%; background: var(--brand-500); border: 3px solid var(--surface-card); box-shadow: 0 0 0 2px var(--brand-400); }
.vo-tl-title { font-size: 13px; font-weight: 700; color: var(--ink-700); margin-bottom: 3px; }
.vo-tl-sub { font-size: 12px; color: var(--ink-300); line-height: 1.55; }
.vo-list-body { padding-top: 8px; padding-bottom: 8px; }
.vo-app-list { list-style: none; padding: 0; margin: 0; }
.vo-app-item { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 13px 0; border-bottom: 1px solid var(--ink-50); }
.vo-app-item:last-child { border-bottom: 0; }
.vo-app-name { font-size: 13.5px; font-weight: 700; color: var(--ink-700); }
.vo-app-sub { font-size: 12px; color: var(--ink-300); margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
.vo-app-sub i { color: var(--brand-400); }
.vo-app-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.vo-cohort-box { background: var(--brand-50); border: 1px solid var(--brand-100); border-radius: var(--radius-lg); padding: 16px; }
.vo-cohort-name { font-size: 15px; font-weight: 800; color: var(--ink-700); margin-bottom: 8px; }
.vo-cohort-meta { font-size: 12px; color: var(--ink-300); display: flex; gap: 12px; flex-wrap: wrap; }
.vo-modal-sm { max-width: 500px; }
@media (max-width: 1050px) {
    .vo-layout { grid-template-columns: 1fr; }
    .vo-sidebar { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
}
@media (max-width: 720px) {
    .vo-sidebar { grid-template-columns: 1fr; }
    .vo-app-item { flex-direction: column; align-items: flex-start; }
    .vo-app-actions { align-self: flex-end; }
}
@media (max-width: 460px) { .vo-info-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1>
            <i class="fas fa-bullhorn" aria-hidden="true"></i>
            <?= h($opportunity['opportunity_title']) ?>

            <?php if (!empty($opportunity['is_featured'])): ?>
                <span class="badge badge-pending"><i class="fas fa-star" aria-hidden="true"></i> Featured</span>
            <?php endif; ?>
        </h1>

        <p class="vo-hero-meta">
            <span class="badge <?= h($badgeClass) ?>"><?= h($status) ?></span>

            <span><i class="fas fa-tag" aria-hidden="true"></i> <?= h($opportunity['opportunity_type']) ?></span>

            <?php if (!empty($opportunity['cohort_name'])): ?>
                <span><i class="fas fa-layer-group" aria-hidden="true"></i> <?= h($opportunity['cohort_name']) ?></span>
            <?php endif; ?>

            <span><i class="fas fa-calendar" aria-hidden="true"></i> Created <?= h(format_date_value($opportunity['created_at'])) ?></span>

            <?php if (!empty($opportunity['created_by_name'])): ?>
                <span><i class="fas fa-user" aria-hidden="true"></i> <?= h($opportunity['created_by_name']) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <div class="hero-actions">
        <a href="application-opportunities" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
        </a>

        <?php if ($status === 'Published'): ?>
            <a href="submit-application?opportunity_id=<?= $opportunity_id ?>" target="_blank" class="btn">
                <i class="fas fa-up-right-from-square" aria-hidden="true"></i> Applicant View
            </a>
        <?php endif; ?>

        <a href="manage-applications?opportunity_id=<?= $opportunity_id ?>" class="btn btn-primary">
            <i class="fas fa-file-alt" aria-hidden="true"></i>
            Applications
            <span class="badge badge-light"><?= (int)$stats['total'] ?></span>
        </a>
    </div>
</div>

<div class="controls-bar vo-actionbar">
    <?php if ($status === 'Draft'): ?>
        <button type="button" onclick="publishOpportunity(<?= $opportunity_id ?>)" class="btn btn-primary btn-sm">
            <i class="fas fa-check" aria-hidden="true"></i> Publish
        </button>
    <?php endif; ?>

    <?php if ($status === 'Published'): ?>
        <button type="button" onclick="closeOpportunity(<?= $opportunity_id ?>)" class="btn btn-warning btn-sm">
            <i class="fas fa-lock" aria-hidden="true"></i> Close Applications
        </button>

        <button type="button" onclick="openModal('extendDeadlineModal')" class="btn btn-secondary btn-sm">
            <i class="fas fa-clock" aria-hidden="true"></i> Extend Deadline
        </button>
    <?php endif; ?>

    <?php if ($status === 'Closed'): ?>
        <button type="button" onclick="reopenOpportunity(<?= $opportunity_id ?>)" class="btn btn-primary btn-sm">
            <i class="fas fa-unlock" aria-hidden="true"></i> Reopen
        </button>

        <button type="button" onclick="completeOpportunity(<?= $opportunity_id ?>)" class="btn btn-dark btn-sm">
            <i class="fas fa-flag-checkered" aria-hidden="true"></i> Mark Completed
        </button>
    <?php endif; ?>

    <a href="edit-opportunity?id=<?= $opportunity_id ?>" class="btn btn-secondary btn-sm">
        <i class="fas fa-edit" aria-hidden="true"></i> Edit
    </a>

    <button type="button" onclick="toggleFeatured(<?= $opportunity_id ?>)" class="btn btn-secondary btn-sm">
        <i class="fas fa-star" aria-hidden="true"></i>
        <?= !empty($opportunity['is_featured']) ? 'Unfeature' : 'Feature' ?>
    </button>

    <?php if ($status === 'Draft'): ?>
        <button
            type="button"
            onclick="confirmDelete('<?= $titleJs ?>','includes/opportunity-process.php?action=delete&id=<?= $opportunity_id ?>&csrf_token=<?= h(csrf_token()) ?>')"
            class="btn btn-danger btn-sm">
            <i class="fas fa-trash" aria-hidden="true"></i> Delete
        </button>
    <?php endif; ?>
</div>

<?php if ($status === 'Published' && $days_remaining !== null): ?>
    <?php
    $alertClass = $days_remaining < 0
        ? 'alert-danger'
        : ($days_remaining <= 7 ? 'alert-warning' : 'alert-success');

    $alertIcon = $days_remaining < 0
        ? 'exclamation-triangle'
        : ($days_remaining <= 7 ? 'exclamation-circle' : 'clock');
    ?>

    <div class="alert <?= h($alertClass) ?>">
        <i class="fas fa-<?= h($alertIcon) ?>" aria-hidden="true"></i>
        <div class="vo-alert-body">
            <?php if ($days_remaining < 0): ?>
                <strong>Deadline Passed</strong>
                The deadline passed <?= abs($days_remaining) ?> day<?= abs($days_remaining) === 1 ? '' : 's' ?> ago.
            <?php elseif ($days_remaining === 0): ?>
                <strong>Applications Close Today</strong>
                Deadline: <?= h(format_date_value($opportunity['deadline'], 'l, d M Y')) ?>
            <?php elseif ($days_remaining <= 7): ?>
                <strong><?= $days_remaining ?> Day<?= $days_remaining === 1 ? '' : 's' ?> Remaining</strong>
                Deadline: <?= h(format_date_value($opportunity['deadline'], 'l, d M Y')) ?>
            <?php else: ?>
                <strong><?= $days_remaining ?> Days Remaining</strong>
                Deadline: <?= h(format_date_value($opportunity['deadline'], 'l, d M Y')) ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-file-alt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total</span>
            <span class="stat-value"><?= (int)$stats['total'] ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-paper-plane" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Submitted</span>
            <span class="stat-value"><?= (int)$stats['Submitted'] ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-eye" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Reviewing</span>
            <span class="stat-value"><?= (int)$stats['Under Review'] ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-purple"><i class="fas fa-star" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Shortlisted</span>
            <span class="stat-value"><?= (int)$stats['Shortlisted'] ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Selected</span>
            <span class="stat-value"><?= $selectedCount ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-red"><i class="fas fa-times-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Rejected</span>
            <span class="stat-value"><?= (int)$stats['Rejected'] ?></span>
        </div>
    </div>
</div>

<div class="vo-layout">
    <div class="vo-stack">

        <div class="tab-shell">
            <div class="tab-header">
                <button type="button" class="tab-btn vo-tab-btn active" data-tab="overview">
                    <i class="fas fa-circle-info" aria-hidden="true"></i> Overview
                </button>
                <button type="button" class="tab-btn vo-tab-btn" data-tab="details">
                    <i class="fas fa-layer-group" aria-hidden="true"></i> Application Details
                </button>
                <button type="button" class="tab-btn vo-tab-btn" data-tab="eligibility">
                    <i class="fas fa-list-check" aria-hidden="true"></i> Eligibility
                </button>
                <button type="button" class="tab-btn vo-tab-btn" data-tab="documents">
                    <i class="fas fa-file-alt" aria-hidden="true"></i> Documents
                </button>
                <button type="button" class="tab-btn vo-tab-btn" data-tab="sectors">
                    <i class="fas fa-industry" aria-hidden="true"></i> Sectors
                </button>
            </div>

            <div class="tab-panels">
                <div class="tab-panel vo-tab-panel active" id="vo-tab-overview">
                <div class="vo-info-grid">
                    <div class="vo-info-item">
                        <div class="vo-info-label">Cohort</div>
                        <div class="vo-info-value"><?= h($opportunity['cohort_name'] ?: 'Not assigned') ?></div>
                    </div>

                    <div class="vo-info-item">
                        <div class="vo-info-label">Start Date</div>
                        <div class="vo-info-value"><?= h(format_date_value($opportunity['start_date'])) ?></div>
                    </div>

                    <div class="vo-info-item">
                        <div class="vo-info-label">Deadline</div>
                        <div class="vo-info-value"><?= h(format_date_value($opportunity['deadline'])) ?></div>
                    </div>

                    <?php if (!empty($opportunity['announcement_date'])): ?>
                        <div class="vo-info-item">
                            <div class="vo-info-label">Announcement Date</div>
                            <div class="vo-info-value"><?= h(format_date_value($opportunity['announcement_date'])) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($opportunity['max_applicants'])): ?>
                        <div class="vo-info-item">
                            <div class="vo-info-label">Maximum Applicants</div>
                            <div class="vo-info-value"><?= (int)$opportunity['max_applicants'] ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($opportunity['available_slots'])): ?>
                        <div class="vo-info-item">
                            <div class="vo-info-label">Available Slots</div>
                            <div class="vo-info-value"><?= (int)$opportunity['available_slots'] ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="vo-info-item">
                        <div class="vo-info-label">Team Size</div>
                        <div class="vo-info-value">
                            <?= max(1, (int)($opportunity['min_team_size'] ?? 1)) ?>
                            <?= !empty($opportunity['max_team_size']) ? ' &ndash; ' . (int)$opportunity['max_team_size'] : '+' ?>
                            members
                        </div>
                    </div>

                    <div class="vo-info-item">
                        <div class="vo-info-label">Featured</div>
                        <div class="vo-info-value"><?= !empty($opportunity['is_featured']) ? 'Yes' : 'No' ?></div>
                    </div>
                </div>

                <div class="vo-section">
                    <div class="vo-section-title">
                        <i class="fas fa-align-left" aria-hidden="true"></i>
                        Description
                    </div>

                    <div class="vo-rich">
                        <?= safe_saved_html($opportunity['description'] ?? '') ?>
                    </div>
                </div>

                </div>

                <div class="tab-panel vo-tab-panel" id="vo-tab-details">
                <?php if ($content_sections !== []): ?>
                    <div class="vo-section">
                        <div class="vo-section-title">
                            <i class="fas fa-layer-group" aria-hidden="true"></i>
                            Application Details
                        </div>

                        <?php foreach ($content_sections as $section): ?>
                            <div class="vo-content-card">
                                <?php if (!empty($section['heading'])): ?>
                                    <h3><?= h($section['heading']) ?></h3>
                                <?php endif; ?>

                                <?php if (!empty($section['description_html'])): ?>
                                    <div class="vo-rich">
                                        <?= safe_saved_html((string)$section['description_html']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($section['bullets']) && is_array($section['bullets'])): ?>
                                    <ul class="vo-bullets">
                                        <?php foreach ($section['bullets'] as $bullet): ?>
                                            <li><?= safe_inline_html((string)$bullet) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-layer-group" aria-hidden="true"></i><p>No application detail sections have been added.</p></div>
                <?php endif; ?>
                </div>

                <div class="tab-panel vo-tab-panel" id="vo-tab-eligibility">
                <?php if (
                    !empty($opportunity['eligibility_heading']) ||
                    !empty($opportunity['eligibility_description_html']) ||
                    $eligibility_bullets !== []
                ): ?>
                    <div class="vo-section">
                        <div class="vo-section-title">
                            <i class="fas fa-list-check" aria-hidden="true"></i>
                            <?= h($opportunity['eligibility_heading'] ?: 'Eligibility Criteria') ?>
                        </div>

                        <?php if (!empty($opportunity['eligibility_description_html'])): ?>
                            <div class="vo-rich">
                                <?= safe_saved_html($opportunity['eligibility_description_html']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($eligibility_bullets !== []): ?>
                            <ul class="vo-bullets">
                                <?php foreach ($eligibility_bullets as $bullet): ?>
                                    <li><?= safe_inline_html((string)$bullet) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-list-check" aria-hidden="true"></i><p>No eligibility criteria have been added.</p></div>
                <?php endif; ?>
                </div>

                <div class="tab-panel vo-tab-panel" id="vo-tab-documents">
                <?php if (!empty($opportunity['required_documents'])): ?>
                    <div class="vo-section">
                        <div class="vo-section-title">
                            <i class="fas fa-file-alt" aria-hidden="true"></i>
                            <?= h($opportunity['documents_heading'] ?: 'Required Documents') ?>
                        </div>

                        <div class="vo-rich">
                            <?= safe_saved_html($opportunity['required_documents']) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-file-alt" aria-hidden="true"></i><p>No required documents have been added.</p></div>
                <?php endif; ?>
                </div>

                <div class="tab-panel vo-tab-panel" id="vo-tab-sectors">
                <?php if ($sectors !== []): ?>
                    <div class="vo-section">
                        <div class="vo-section-title">
                            <i class="fas fa-industry" aria-hidden="true"></i>
                            Allowed Sectors
                        </div>

                        <div class="vo-sectors">
                            <?php foreach ($sectors as $sector): ?>
                                <span class="vo-sector-tag"><?= h($sector) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-industry" aria-hidden="true"></i><p>No sector restrictions have been added.</p></div>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history" aria-hidden="true"></i> Recent Applications</h3>
                <a href="manage-applications?opportunity_id=<?= $opportunity_id ?>" class="btn btn-primary btn-sm">
                    View All
                </a>
            </div>

            <div class="card-body vo-list-body">
                <?php if ($recent_apps === []): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox" aria-hidden="true"></i>
                        <p>No applications have been received yet.</p>
                    </div>
                <?php else: ?>
                    <ul class="vo-app-list">
                        <?php foreach ($recent_apps as $app): ?>
                            <?php
                            $appStatus = (string)($app['status'] ?? '');
                            $displayDate = $app['submitted_at']
                                ?: ($app['last_saved_at'] ?: $app['created_at']);
                            ?>
                            <li class="vo-app-item">
                                <div>
                                    <div class="vo-app-name"><?= h($app['startup_name'] ?: 'Untitled Application') ?></div>

                                    <div class="vo-app-sub">
                                        <?php if (!empty($app['reference_number'])): ?>
                                            <span><i class="fas fa-hashtag" aria-hidden="true"></i> <?= h($app['reference_number']) ?></span>
                                        <?php endif; ?>

                                        <span><i class="fas fa-tag" aria-hidden="true"></i> <?= h(application_sector_label($app)) ?></span>

                                        <?php if (!empty($displayDate)): ?>
                                            <span><i class="fas fa-calendar" aria-hidden="true"></i> <?= h(format_date_value($displayDate)) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="vo-app-actions">
                                    <span class="badge <?= h(app_status_badge($appStatus)) ?>">
                                        <?= h($appStatus ?: 'Unknown') ?>
                                    </span>

                                    <a href="view-application?id=<?= (int)$app['application_id'] ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-eye" aria-hidden="true"></i> View
                                    </a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside class="vo-stack vo-sidebar">
        <?php if (!empty($opportunity['cohort_name'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group" aria-hidden="true"></i> Cohort</h3>
                </div>
                <div class="card-body">
                    <div class="vo-cohort-box">
                        <div class="vo-cohort-name"><?= h($opportunity['cohort_name']) ?></div>

                        <div class="vo-cohort-meta">
                            <?php if (!empty($opportunity['cohort_start_date'])): ?>
                                <span><i class="fas fa-calendar-plus" aria-hidden="true"></i> <?= h(format_date_value($opportunity['cohort_start_date'])) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($opportunity['cohort_end_date'])): ?>
                                <span><i class="fas fa-calendar-check" aria-hidden="true"></i> <?= h(format_date_value($opportunity['cohort_end_date'])) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($opportunity['cohort_status'])): ?>
                                <span><i class="fas fa-circle" aria-hidden="true"></i> <?= h($opportunity['cohort_status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($opportunity['max_applicants'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users" aria-hidden="true"></i> Capacity</h3>
                </div>

                <div class="card-body">
                    <div class="vo-capacity">
                        <div class="vo-capacity-row">
                            <span>Applications received</span>
                            <strong>
                                <?= (int)$stats['total'] ?> /
                                <?= (int)$opportunity['max_applicants'] ?>
                            </strong>
                        </div>

                        <?php
                        $fillClass = $percentage_filled >= 100
                            ? 'full'
                            : ($percentage_filled >= 80 ? 'hot' : '');
                        ?>

                        <div class="vo-capacity-track">
                            <div
                                class="vo-capacity-fill <?= h($fillClass) ?>"
                                style="width:<?= (int)round($percentage_filled) ?>%">
                            </div>
                        </div>

                        <div class="vo-capacity-row">
                            <span><?= (int)round($percentage_filled) ?>% filled</span>
                            <span>
                                <?= max(
                                    0,
                                    (int)$opportunity['max_applicants'] -
                                    (int)$stats['total']
                                ) ?>
                                remaining
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-stream" aria-hidden="true"></i> Timeline</h3>
            </div>

            <div class="card-body">
                <div class="vo-timeline">
                    <div class="vo-tl-item">
                        <div class="vo-tl-title">Created</div>
                        <div class="vo-tl-sub">
                            <?= h(format_date_value($opportunity['created_at'], 'd M Y, g:i A')) ?>

                            <?php if (!empty($opportunity['created_by_name'])): ?>
                                <br>By <?= h($opportunity['created_by_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($opportunity['published_at'])): ?>
                        <div class="vo-tl-item">
                            <div class="vo-tl-title">Published</div>
                            <div class="vo-tl-sub">
                                <?= h(format_date_value($opportunity['published_at'], 'd M Y, g:i A')) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="vo-tl-item">
                        <div class="vo-tl-title">Application Window</div>
                        <div class="vo-tl-sub">
                            <?= h(format_date_value($opportunity['start_date'])) ?>
                            &rarr;
                            <?= h(format_date_value($opportunity['deadline'])) ?>
                        </div>
                    </div>

                    <?php if (!empty($opportunity['announcement_date'])): ?>
                        <div class="vo-tl-item">
                            <div class="vo-tl-title">Results Announcement</div>
                            <div class="vo-tl-sub">
                                <?= h(format_date_value($opportunity['announcement_date'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </aside>
</div>

</div><!-- /.page-stack -->

<div id="extendDeadlineModal" class="modal">
    <div class="modal-content vo-modal-sm">
        <div class="modal-header">
            <h3>
                <i class="fas fa-clock" aria-hidden="true"></i>
                Extend Deadline
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('extendDeadlineModal')"
                aria-label="Close">
                &times;
            </button>
        </div>

        <form method="POST" action="includes/opportunity-process.php">
            <input type="hidden" name="action" value="extend_deadline">
            <input type="hidden" name="opportunity_id" value="<?= $opportunity_id ?>">

            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <span>
                        Current deadline:
                        <strong><?= h(format_date_value($opportunity['deadline'])) ?></strong>
                    </span>
                </div>

                <div class="form-group">
                    <label class="form-label required" for="new_deadline">New Deadline</label>
                    <input
                        type="date"
                        id="new_deadline"
                        name="new_deadline"
                        class="form-control"
                        min="<?= h(date('Y-m-d', strtotime((string)$opportunity['deadline'] . ' +1 day'))) ?>"
                        required>
                    <div class="form-hint">
                        Must be after the current deadline.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    onclick="closeModal('extendDeadlineModal')"
                    class="btn btn-secondary">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check" aria-hidden="true"></i> Extend Deadline
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>

document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('.vo-tab-btn');
    const panels = document.querySelectorAll('.vo-tab-panel');

    function openOpportunityTab(name) {
        buttons.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === name);
        });

        panels.forEach(panel => {
            panel.classList.toggle('active', panel.id === 'vo-tab-' + name);
        });

        if (history.replaceState) {
            history.replaceState(null, '', '#tab-' + name);
        }
    }

    buttons.forEach(btn => {
        btn.addEventListener('click', function () {
            openOpportunityTab(this.dataset.tab);
        });
    });

    const hash = window.location.hash.replace('#tab-', '');
    if (['overview', 'details', 'eligibility', 'documents', 'sectors'].includes(hash)) {
        openOpportunityTab(hash);
    }
});

function publishOpportunity(id) {
    if (confirm('Publish this opportunity? It will become visible to applicants.')) {
        window.location.href = 'includes/opportunity-process.php?action=publish&id=' + encodeURIComponent(id) + '&csrf_token=<?= h(csrf_token()) ?>';
    }
}

function closeOpportunity(id) {
    if (confirm('Close this opportunity? No new applications will be accepted.')) {
        window.location.href = 'includes/opportunity-process.php?action=close&id=' + encodeURIComponent(id) + '&csrf_token=<?= h(csrf_token()) ?>';
    }
}

function reopenOpportunity(id) {
    if (confirm('Reopen this opportunity? Applications will be accepted again.')) {
        window.location.href = 'includes/opportunity-process.php?action=reopen&id=' + encodeURIComponent(id) + '&csrf_token=<?= h(csrf_token()) ?>';
    }
}

function completeOpportunity(id) {
    if (confirm('Mark this opportunity as completed?')) {
        window.location.href = 'includes/opportunity-process.php?action=complete&id=' + encodeURIComponent(id) + '&csrf_token=<?= h(csrf_token()) ?>';
    }
}

function toggleFeatured(id) {
    if (confirm('Change the featured status for this opportunity?')) {
        window.location.href = 'includes/opportunity-process.php?action=toggle_featured&id=' + encodeURIComponent(id) + '&csrf_token=<?= h(csrf_token()) ?>';
    }
}
</script>
