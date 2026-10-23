<?php
declare(strict_types=1);

$page_title = 'Startup Field Visits';
include 'includes/header.php';

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer',
     'Consultant',
    'Reviewer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->close();

    return $exists;
}

function badge_class(string $status): string
{
    return match ($status) {
        'Completed', 'Report Uploaded', 'Visited' => 'badge badge-completed',
        'Assigned', 'Generated' => 'badge badge-in-progress',
        'Cancelled', 'Closed' => 'badge badge-cancelled',
        'Could Not Locate', 'Needs Follow Up' => 'badge badge-delayed',
        default => 'badge badge-not-started',
    };
}

function progress_class(float $pct): string
{
    if ($pct >= 7.5) return 'high';
    if ($pct >= 5) return 'mid';
    return 'low';
}

if (!table_exists($conn, 'startup_field_visits')) {
    die('startup_field_visits table is missing. Run sql/startup_field_visits.sql first.');
}

$selectedVisitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

$visits = [];

$res = $conn->query("
    SELECT
        sfv.*,
        sm.milestone_title,
        sm.milestone_type,
        COALESCE(a.startup_name, CONCAT('Application #', sfv.application_id)) AS startup_name,
        ar.overall_score AS review_overall_score,
        ar.recommendation AS review_recommendation
    FROM startup_field_visits sfv
    LEFT JOIN startup_milestones sm ON sm.milestone_id = sfv.milestone_id
    LEFT JOIN applications a ON a.application_id = sfv.application_id
    LEFT JOIN application_reviews ar ON ar.review_id = sfv.review_id
    ORDER BY sfv.created_at DESC, sfv.visit_id DESC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $visits[] = $row;
    }

    $res->close();
}

$selectedVisit = null;

if ($selectedVisitId > 0) {
    foreach ($visits as $visit) {
        if ((int)$visit['visit_id'] === $selectedVisitId) {
            $selectedVisit = $visit;
            break;
        }
    }
}

if (!$selectedVisit && !empty($visits)) {
    $selectedVisit = $visits[0];
}

$scoreFields = [
    'equity_score' => 'Equity',
    'video_intro_score' => 'Video Intro',
    'problem_depth_toc_score' => 'Problem Depth / TOC',
    'product_quality_pedagogy_score' => 'Product Quality / Pedagogy',
    'product_demo_link_score' => 'Product Demo Link',
    'scalability_traction_sustainability_score' => 'Scalability / Traction / Sustainability',
    'team_capability_commitment_score' => 'Team Capability / Commitment',
    'ursb_registration_score' => 'URSB Registration',
];
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">

<div class="milestones-wrap page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-clipboard-check"></i> Startup Field Visits</h1>
            <p>Field teams can score visited startups, upload evidence/report files, and submit field recommendations.</p>
        </div>
        <div class="hero-actions">
            <a href="startup-milestones" class="btn">
                <i class="fas fa-arrow-left"></i> Startup Milestones
            </a>
        </div>
    </div>

    <div class="detail-tabs">
        <div class="tab-nav">
            <button type="button" class="tab-btn active" data-tab="visits">
                <i class="fas fa-list"></i> Visit Forms
            </button>
            <button type="button" class="tab-btn" data-tab="score">
                <i class="fas fa-star"></i> Score & Upload Report
            </button>
        </div>

        <section id="tab-visits" class="tab-panel active">
            <div class="section-title">
                <h3><i class="fas fa-clipboard-list"></i> Generated Field Visit Forms</h3>
                <span class="badge badge-available"><?php echo count($visits); ?> forms</span>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Visit Code</th>
                            <th>Startup</th>
                            <th>Milestone</th>
                            <th>Assigned To</th>
                            <th>Planned Date</th>
                            <th>Status</th>
                            <th>Field Score</th>
                            <th>Report</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($visits)): ?>
                            <tr>
                                <td colspan="9" class="empty-state">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span>No field visit forms generated yet.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($visits as $visit): ?>
                                <?php $score = (float)($visit['field_overall_score'] ?? 0); ?>
                                <tr>
                                    <td><strong><?php echo h($visit['visit_code']); ?></strong></td>
                                    <td>
                                        <strong><?php echo h($visit['startup_name']); ?></strong>
                                        <br><small>App #<?php echo (int)$visit['application_id']; ?></small>
                                    </td>
                                    <td>
                                        <div class="milestone-name"><?php echo h($visit['milestone_title'] ?? '-'); ?></div>
                                        <div class="milestone-desc"><?php echo h($visit['milestone_type'] ?? '-'); ?></div>
                                    </td>
                                    <td><?php echo h($visit['assigned_to'] ?? '-'); ?></td>
                                    <td><?php echo !empty($visit['planned_visit_date']) ? date('d M Y', strtotime((string)$visit['planned_visit_date'])) : '-'; ?></td>
                                    <td><span class="<?php echo h(badge_class((string)$visit['status'])); ?>"><?php echo h($visit['status']); ?></span></td>
                                    <td>
                                        <div class="progress-cell">
                                            <div class="progress-header">
                                                <span class="progress-pct"><?php echo number_format($score, 1); ?>/10</span>
                                            </div>
                                            <div class="prog-track">
                                                <div class="prog-fill <?php echo h(progress_class($score)); ?>" style="width:<?php echo min(100, $score * 10); ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($visit['report_file'])): ?>
                                            <a href="<?php echo h(ims_upload_url($visit['report_file'], true)); ?>" class="btn btn-sm btn-soft" download>
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--ink-200);font-size:12px;">No report</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="startup-field-visits?visit_id=<?php echo (int)$visit['visit_id']; ?>#score" class="btn btn-sm btn-primary">
                                            <i class="fas fa-star"></i> Score
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="tab-score" class="tab-panel">
            <?php if (!$selectedVisit): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-check"></i>
                    <strong>No visit selected.</strong>
                    <p>Generate a field visit form from Startup Milestones first.</p>
                </div>
            <?php else: ?>
                <div class="section-title">
                    <h3>
                        <i class="fas fa-star"></i>
                        Score Field Visit: <?php echo h($selectedVisit['visit_code']); ?>
                    </h3>
                    <span class="<?php echo h(badge_class((string)$selectedVisit['status'])); ?>">
                        <?php echo h($selectedVisit['status']); ?>
                    </span>
                </div>

                <div class="soft-box" style="margin-bottom:16px;">
                    <strong><?php echo h($selectedVisit['startup_name']); ?></strong>
                    <br>
                    <small>
                        Milestone: <?php echo h($selectedVisit['milestone_title'] ?? '-'); ?>
                        |
                        Review Score: <?php echo number_format((float)($selectedVisit['review_overall_score'] ?? 0), 2); ?>
                        |
                        Review Recommendation: <?php echo h($selectedVisit['review_recommendation'] ?? '-'); ?>
                    </small>
                </div>

                <form method="POST" action="includes/startup-field-visits-process.php" enctype="multipart/form-data">
                    <input type="hidden" name="visit_id" value="<?php echo (int)$selectedVisit['visit_id']; ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Actual Visit Date</label>
                            <input type="date" name="actual_visit_date" class="form-control" value="<?php echo h($selectedVisit['actual_visit_date'] ?? date('Y-m-d')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Business Status</label>
                            <select name="business_status" class="form-control">
                                <?php foreach (['Not Visited','Visited','Could Not Locate','Closed','Needs Follow Up'] as $status): ?>
                                    <option value="<?php echo h($status); ?>" <?php echo (($selectedVisit['business_status'] ?? '') === $status) ? 'selected' : ''; ?>>
                                        <?php echo h($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Location Visited</label>
                            <input type="text" name="location" class="form-control" value="<?php echo h($selectedVisit['location'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="section-title" style="margin-top:18px;">
                        <h3><i class="fas fa-star-half-alt"></i> Field Scores /10</h3>
                    </div>

                    <div class="form-row">
                        <?php foreach ($scoreFields as $field => $label): ?>
                            <div class="form-group">
                                <label><?php echo h($label); ?></label>
                                <input type="number" name="<?php echo h($field); ?>" class="form-control" min="0" max="10" step="0.01" value="<?php echo h($selectedVisit[$field] ?? '0'); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-group">
                        <label>Field Recommendation</label>
                        <select name="field_recommendation" class="form-control">
                            <?php foreach (['Pending','Strongly Recommend','Recommend','Needs Support','Do Not Recommend'] as $recommendation): ?>
                                <option value="<?php echo h($recommendation); ?>" <?php echo (($selectedVisit['field_recommendation'] ?? '') === $recommendation) ? 'selected' : ''; ?>>
                                    <?php echo h($recommendation); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Observations</label>
                            <textarea name="observations" class="form-control" rows="4"><?php echo h($selectedVisit['observations'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Risks</label>
                            <textarea name="risks" class="form-control" rows="4"><?php echo h($selectedVisit['risks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Support Needed</label>
                            <textarea name="support_needed" class="form-control" rows="4"><?php echo h($selectedVisit['support_needed'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Next Steps</label>
                            <textarea name="next_steps" class="form-control" rows="4"><?php echo h($selectedVisit['next_steps'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Upload Startup Field Report</label>
                        <input type="file" name="report_file" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
                        <small style="color:var(--ink-300);">Allowed: PDF, Word, Excel, images, ZIP. Max 15MB.</small>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" name="submit_field_visit" class="btn btn-success">
                            <i class="fas fa-save"></i> Submit Field Visit Report
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.tab-panel');

    function openTab(name) {
        buttons.forEach(btn => btn.classList.remove('active'));
        panels.forEach(panel => panel.classList.remove('active'));

        const btn = document.querySelector('.tab-btn[data-tab="' + name + '"]');
        const panel = document.getElementById('tab-' + name);

        if (btn) btn.classList.add('active');
        if (panel) panel.classList.add('active');
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            openTab(button.getAttribute('data-tab'));
        });
    });

    if (window.location.hash === '#score' || new URLSearchParams(window.location.search).has('visit_id')) {
        openTab('score');
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
