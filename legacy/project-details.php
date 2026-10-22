<?php
$page_title = 'Project Details';
require_once __DIR__ . '/includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Staff']);

function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* -- Validate project ID ---------------------------------------------- */
$project_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$project_id) {
    send_notification($_SESSION['user_id'], 'Invalid project ID', 'danger');
    header("Location: projects"); exit();
}

/* -- Project + donor -------------------------------------------------- */
$result = $conn->query("
    SELECT p.*, d.donor_name, d.email AS donor_email,
           d.phone AS donor_phone, d.website AS donor_website
    FROM projects p
    LEFT JOIN donors d ON p.donor_id = d.donor_id
    WHERE p.project_id = $project_id
");
if ($result->num_rows === 0) {
    send_notification($_SESSION['user_id'], 'Project not found', 'danger');
    header("Location: projects"); exit();
}
$project = $result->fetch_assoc();

/* -- Partners --------------------------------------------------------- */
$partners = [];
$result = $conn->query("
    SELECT p.*, pp.role FROM partners p
    JOIN project_partners pp ON p.partner_id = pp.partner_id
    WHERE pp.project_id = $project_id
    ORDER BY p.partner_name
");
while ($row = $result->fetch_assoc()) { $partners[] = $row; }

/* -- Indicators ------------------------------------------------------- */
$indicators = [];
$result = $conn->query("
    SELECT i.*,
        CASE WHEN i.target_value > 0
             THEN (i.current_value / i.target_value * 100)
             ELSE 0 END AS achievement_percentage
    FROM indicators i
    WHERE i.project_id = $project_id
    ORDER BY i.indicator_type, i.indicator_name
");
while ($row = $result->fetch_assoc()) { $indicators[] = $row; }

/* -- Milestones ------------------------------------------------------- */
$milestones = [];
$result = $conn->query("SELECT * FROM milestones WHERE project_id = $project_id ORDER BY due_date");
while ($row = $result->fetch_assoc()) { $milestones[] = $row; }

/* -- Beneficiaries (latest 10) ---------------------------------------- */
$beneficiaries = [];
$result = $conn->query("
    SELECT b.*, pb.enrollment_date, pb.participation_type, pb.status AS enrollment_status
    FROM beneficiaries b
    JOIN project_beneficiaries pb ON b.beneficiary_id = pb.beneficiary_id
    WHERE pb.project_id = $project_id
    ORDER BY pb.enrollment_date DESC
    LIMIT 10
");
while ($row = $result->fetch_assoc()) { $beneficiaries[] = $row; }

/* -- Documents (latest 5) --------------------------------------------- */
$documents = [];
$result = $conn->query("SELECT * FROM documents WHERE project_id = $project_id ORDER BY upload_date DESC LIMIT 5");
while ($row = $result->fetch_assoc()) { $documents[] = $row; }

/* -- Progress updates (latest 3) ------------------------------------- */
$progress_updates = [];
$result = $conn->query("SELECT * FROM progress_updates WHERE project_id = $project_id ORDER BY update_date DESC LIMIT 3");
while ($row = $result->fetch_assoc()) { $progress_updates[] = $row; }

/* -- Counts & calculations -------------------------------------------- */
$total_beneficiaries  = (int) $conn->query("SELECT COUNT(*) AS c FROM project_beneficiaries WHERE project_id = $project_id")->fetch_assoc()['c'];
$active_beneficiaries = (int) $conn->query("SELECT COUNT(*) AS c FROM project_beneficiaries WHERE project_id = $project_id AND status = 'Active'")->fetch_assoc()['c'];
$total_documents      = (int) $conn->query("SELECT COUNT(*) AS c FROM documents WHERE project_id = $project_id")->fetch_assoc()['c'];

$total_indicators   = count($indicators);
$achieved_indicators = count(array_filter($indicators, fn($i) => $i['achievement_percentage'] >= 100));
$total_milestones    = count($milestones);
$completed_milestones = count(array_filter($milestones, fn($m) => $m['status'] === 'Completed'));
$milestone_completion = $total_milestones > 0 ? ($completed_milestones / $total_milestones * 100) : 0;

$overall_achievement = 0;
if ($total_indicators > 0) {
    $overall_achievement = array_sum(array_column($indicators, 'achievement_percentage')) / $total_indicators;
}

$start_date  = new DateTime($project['start_date']);
$end_date    = new DateTime($project['end_date']);
$today       = new DateTime();
$total_days  = $start_date->diff($end_date)->days;
$elapsed_days = $start_date->diff($today)->days;
$time_progress = $total_days > 0 ? min(($elapsed_days / $total_days * 100), 100) : 0;
$duration = $start_date->diff($end_date);

/* -- Helpers ---------------------------------------------------------- */
$status_badge = [
    'Ongoing'   => 'badge badge-ongoing',
    'Completed' => 'badge badge-completed',
    'Pending'   => 'badge badge-pending',
    'Cancelled' => 'badge badge-cancelled',
];

function achievement_class(float $pct): string {
    if ($pct >= 75) return 'high';
    if ($pct >= 50) return 'mid';
    return 'low';
}

$ms_class = [
    'Completed'   => 'ms-completed',
    'In Progress' => 'ms-inprogress',
    'Delayed'     => 'ms-delayed',
    'Pending'     => 'ms-pending',
];

$ms_badge = [
    'Completed'   => 'badge-available',
    'In Progress' => 'badge-assigned',
    'Delayed'     => 'badge-rejected',
    'Pending'     => 'badge-pending',
];

$doc_icons = [
    'pdf'  => ['class' => 'pdf',   'icon' => 'fa-file-pdf'],
    'doc'  => ['class' => 'word',  'icon' => 'fa-file-word'],
    'docx' => ['class' => 'word',  'icon' => 'fa-file-word'],
    'xls'  => ['class' => 'excel', 'icon' => 'fa-file-excel'],
    'xlsx' => ['class' => 'excel', 'icon' => 'fa-file-excel'],
    'ppt'  => ['class' => 'ppt',   'icon' => 'fa-file-powerpoint'],
    'pptx' => ['class' => 'ppt',   'icon' => 'fa-file-powerpoint'],
];
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/projects.css">

<div class="projects-wrap">

    <!-- -- Detail Hero ------------------------------------------------- -->
    <div class="detail-hero">

        <div class="detail-hero-top">
            <div>
                <div class="detail-hero-title"><?= h($project['project_name']) ?></div>
                <div class="detail-hero-code">
                    <i class="fas fa-tag"></i><?= h($project['project_code']) ?>
                </div>
            </div>
            <div class="detail-hero-actions">
                <span class="<?= $status_badge[$project['status']] ?? 'badge badge-returned' ?>" style="font-size:12px;padding:6px 14px;">
                    <?= h($project['status']) ?>
                </span>
                <a href="projects" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <button onclick="window.print()" class="btn btn-primary" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <!-- Key tiles -->
        <div class="detail-hero-tiles">
            <div class="detail-tile">
                <div class="detail-tile-label"><i class="fas fa-hand-holding-usd"></i> Donor</div>
                <div class="detail-tile-value"><?= h($project['donor_name'] ?? 'N/A') ?></div>
            </div>
            <div class="detail-tile">
                <div class="detail-tile-label"><i class="fas fa-dollar-sign"></i> Budget</div>
                <div class="detail-tile-value"><?= format_currency($project['budget'], $project['currency']) ?></div>
            </div>
            <div class="detail-tile">
                <div class="detail-tile-label"><i class="fas fa-calendar-alt"></i> Duration</div>
                <div class="detail-tile-value">
                    <?= $duration->y > 0 ? $duration->y . 'yr ' : '' ?><?= $duration->m ?>mo
                </div>
            </div>
            <div class="detail-tile">
                <div class="detail-tile-label"><i class="fas fa-map-marker-alt"></i> Scope</div>
                <div class="detail-tile-value"><?= h($project['geographic_scope'] ?? 'N/A') ?></div>
            </div>
        </div>

        <!-- Timeline progress bar -->
        <div class="progress-wrap">
            <div class="progress-meta">
                <div>
                    <span class="prog-label">
                        <i class="fas fa-clock"></i> Timeline
                    </span>
                    <span style="font-size:11px;color:rgba(255,255,255,.55);margin-left:10px;">
                        <?= date('d M Y', strtotime($project['start_date'])) ?>
                        &rarr;
                        <?= date('d M Y', strtotime($project['end_date'])) ?>
                    </span>
                </div>
                <div style="text-align:right;">
                    <span class="prog-value"><?= number_format($time_progress, 1) ?>%</span>
                    <span class="prog-remaining" style="margin-left:8px;">
                        <?php if ($today < $end_date):
                            echo $today->diff($end_date)->days . ' days remaining';
                        else: ?>project ended<?php endif; ?>
                    </span>
                </div>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width:<?= $time_progress ?>%;"></div>
            </div>
        </div>

    </div><!-- /.detail-hero -->

    <!-- -- Performance Stats ------------------------------------------- -->
    <div class="projects-stats">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <div class="stat-label">Active / Total Participants</div>
                <div class="stat-value"><?= $active_beneficiaries ?><span style="font-size:16px;color:var(--ink-200);font-weight:500;"> / <?= $total_beneficiaries ?></span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Indicators Achieved</div>
                <div class="stat-value"><?= $achieved_indicators ?><span style="font-size:16px;color:var(--ink-200);font-weight:500;"> / <?= $total_indicators ?></span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-tasks"></i></div>
            <div class="stat-info">
                <div class="stat-label">Milestones Completed</div>
                <div class="stat-value"><?= $completed_milestones ?><span style="font-size:16px;color:var(--ink-200);font-weight:500;"> / <?= $total_milestones ?></span></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-folder"></i></div>
            <div class="stat-info">
                <div class="stat-label">Documents</div>
                <div class="stat-value"><?= $total_documents ?></div>
            </div>
        </div>
    </div>

    <!-- -- Description & Objectives ----------------------------------- -->
    <div class="detail-grid-2">

        <div class="info-panel">
            <div class="info-panel-head">
                <h3><i class="fas fa-align-left"></i> Description</h3>
            </div>
            <div class="info-panel-body">
                <?php if ($project['description']): ?>
                    <p class="prose-text"><?= nl2br(h($project['description'])) ?></p>
                <?php else: ?>
                    <p class="prose-empty">No description available.</p>
                <?php endif; ?>

                <?php if ($project['value_chain']): ?>
                    <div class="value-chain-tag">
                        <i class="fas fa-link"></i>
                        <strong>Value Chain:</strong> <?= h($project['value_chain']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="info-panel">
            <div class="info-panel-head">
                <h3><i class="fas fa-bullseye"></i> Objectives</h3>
            </div>
            <div class="info-panel-body">
                <?php if ($project['objectives']): ?>
                    <div class="prose-text"><?= nl2br(h($project['objectives'])) ?></div>
                <?php else: ?>
                    <p class="prose-empty">No objectives specified.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- -- Donor & Partners -------------------------------------------- -->
    <div class="detail-grid-2">

        <!-- Donor -->
        <div class="info-panel">
            <div class="info-panel-head">
                <h3><i class="fas fa-hand-holding-usd"></i> Donor Information</h3>
            </div>
            <div class="info-panel-body">
                <div style="font-size:15px;font-weight:800;color:var(--ink-700);margin-bottom:14px;">
                    <?= h($project['donor_name'] ?? 'N/A') ?>
                </div>
                <div class="donor-contact-list">
                    <?php if ($project['donor_email']): ?>
                        <div class="donor-contact-row">
                            <div class="dc-icon"><i class="fas fa-envelope"></i></div>
                            <div class="dc-label">Email</div>
                            <div class="dc-value">
                                <a href="mailto:<?= h($project['donor_email']) ?>"><?= h($project['donor_email']) ?></a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($project['donor_phone']): ?>
                        <div class="donor-contact-row">
                            <div class="dc-icon"><i class="fas fa-phone"></i></div>
                            <div class="dc-label">Phone</div>
                            <div class="dc-value"><?= h($project['donor_phone']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($project['donor_website']): ?>
                        <div class="donor-contact-row">
                            <div class="dc-icon"><i class="fas fa-globe"></i></div>
                            <div class="dc-label">Website</div>
                            <div class="dc-value">
                                <a href="<?= h($project['donor_website']) ?>" target="_blank" rel="noopener">
                                    <?= h($project['donor_website']) ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$project['donor_email'] && !$project['donor_phone'] && !$project['donor_website']): ?>
                        <p class="prose-empty">No contact details on record.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Partners -->
        <div class="info-panel">
            <div class="info-panel-head">
                <h3><i class="fas fa-handshake"></i> Project Partners</h3>
                <span class="badge badge-available"><?= count($partners) ?></span>
            </div>
            <div class="info-panel-body">
                <?php if (empty($partners)): ?>
                    <p class="prose-empty">No partners assigned to this project.</p>
                <?php else: ?>
                    <?php foreach ($partners as $p): ?>
                        <div class="partner-card">
                            <div class="partner-avatar"><i class="fas fa-building"></i></div>
                            <div style="flex:1;min-width:0;">
                                <div class="partner-name"><?= h($p['partner_name']) ?></div>
                                <div class="partner-meta">
                                    <span class="badge badge-assigned" style="font-size:9px;"><?= h($p['partner_type']) ?></span>
                                    <?php if ($p['role']): ?>
                                        <span class="partner-role"><i class="fas fa-user-tag"></i> <?= h($p['role']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- -- Indicators -------------------------------------------------- -->
    <div class="info-panel">
        <div class="info-panel-head">
            <h3><i class="fas fa-chart-line"></i> Project Indicators</h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <span class="badge badge-available"><?= $total_indicators ?> total</span>
                <?php if (ims_allowed('indicators.add_link', ['Administrator', 'MEAL Lead'], 'See the Add indicator button on program/project pages')): ?>
                    <a href="indicators?project=<?= $project_id ?>" class="btn btn-sm btn-soft">
                        <i class="fas fa-plus"></i> Add
                    </a>
                <?php endif; ?>
                <a href="indicators?project=<?= $project_id ?>" class="btn btn-sm btn-gray">
                    View All
                </a>
            </div>
        </div>
        <div class="info-panel-body">
            <?php if (empty($indicators)): ?>
                <p class="prose-empty" style="text-align:center;padding:20px 0;">No indicators defined yet.</p>
            <?php else: ?>

                <!-- Overall achievement bar -->
                <?php $ach_class = achievement_class($overall_achievement); ?>
                <div class="achievement-bar-wrap">
                    <span class="ach-label">Overall Achievement</span>
                    <div class="ach-track">
                        <div class="ach-fill <?= $ach_class ?>" style="width:<?= min($overall_achievement, 100) ?>%;"></div>
                    </div>
                    <span class="ach-pct <?= $ach_class ?>"><?= number_format($overall_achievement, 1) ?>%</span>
                </div>

                <?php foreach (['Output', 'Outcome', 'Impact'] as $type):
                    $type_inds = array_values(array_filter($indicators, fn($i) => $i['indicator_type'] === $type));
                    if (empty($type_inds)) continue;
                ?>
                    <div class="indicator-type-heading">
                        <?= $type ?> Indicators
                        <span class="type-count"><?= count($type_inds) ?></span>
                    </div>

                    <?php foreach ($type_inds as $ind):
                        $pct       = (float) $ind['achievement_percentage'];
                        $ind_class = achievement_class($pct);
                    ?>
                        <div class="indicator-row">
                            <div class="indicator-row-head">
                                <div class="indicator-name">
                                    <?= h($ind['indicator_name']) ?>
                                    <?php if ($ind['unit_of_measure']): ?>
                                        <span class="indicator-unit">(<?= h($ind['unit_of_measure']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="indicator-values">
                                    <span class="current"><?= number_format((float)$ind['current_value'], 2) ?></span>
                                    <span class="target"> / <?= number_format((float)$ind['target_value'], 2) ?></span>
                                </div>
                            </div>
                            <div class="ind-track">
                                <div class="ind-fill <?= $ind_class ?>" style="width:<?= min($pct, 100) ?>%;"></div>
                            </div>
                            <div class="ind-pct-label"><?= number_format($pct, 1) ?>% achieved</div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </div>

    <!-- -- Milestones & Beneficiaries ---------------------------------- -->
    <div class="detail-grid-2">

        <!-- Milestones -->
        <div class="info-panel">
            <div class="info-panel-head">
                <h3><i class="fas fa-tasks"></i> Milestones</h3>
                <span class="badge badge-<?= $milestone_completion >= 100 ? 'available' : 'pending' ?>">
                    <?= number_format($milestone_completion, 0) ?>% done
                </span>
            </div>
            <div class="info-panel-body">
                <?php if (empty($milestones)): ?>
                    <p class="prose-empty">No milestones defined.</p>
                <?php else: ?>
                    <?php foreach (array_slice($milestones, 0, 5) as $ms):
                        $mc = $ms_class[$ms['status']] ?? 'ms-pending';
                        $mb = $ms_badge[$ms['status']] ?? 'badge-pending';
                    ?>
                        <div class="milestone-item <?= $mc ?>">
                            <div class="milestone-body">
                                <div class="milestone-name"><?= h($ms['milestone_name']) ?></div>
                                <div class="milestone-dates">
                                    Due: <?= date('d M Y', strtotime($ms['due_date'])) ?>
                                    <?php if ($ms['completion_date']): ?>
                                        &nbsp;·&nbsp; Completed: <?= date('d M Y', strtotime($ms['completion_date'])) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($ms['progress_percentage'] > 0): ?>
                                    <div class="milestone-mini-bar">
                                        <div class="milestone-mini-fill" style="width:<?= (int)$ms['progress_percentage'] ?>%;"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $mb ?>"><?= h($ms['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($milestones) > 5): ?>
                        <p class="note" style="text-align:center;margin-top:12px;">
                            Showing 5 of <?= count($milestones) ?> milestones
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Beneficiaries -->
        <div class="info-panel">
            <div class="info-panel-head">
                <h3><i class="fas fa-users"></i> Recent Participants</h3>
                <a href="participants?project=<?= $project_id ?>" class="btn btn-sm btn-gray">View All</a>
            </div>
            <div class="info-panel-body">
                <?php if (empty($beneficiaries)): ?>
                    <p class="prose-empty">No participants enrolled yet.</p>
                <?php else: ?>
                    <?php foreach ($beneficiaries as $b):
                        $initials = strtoupper(substr($b['first_name'], 0, 1) . substr($b['last_name'], 0, 1));
                    ?>
                        <div class="beneficiary-row">
                            <div class="beneficiary-avatar"><?= $initials ?></div>
                            <div class="beneficiary-info">
                                <div class="beneficiary-name"><?= h($b['first_name'] . ' ' . $b['last_name']) ?></div>
                                <div class="beneficiary-meta">
                                    <?= h($b['participation_type']) ?> &nbsp;·&nbsp;
                                    Enrolled <?= date('M Y', strtotime($b['enrollment_date'])) ?>
                                </div>
                            </div>
                            <span class="badge <?= $b['enrollment_status'] === 'Active' ? 'badge-available' : 'badge-returned' ?>">
                                <?= h($b['enrollment_status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- -- Documents --------------------------------------------------- -->
    <div class="info-panel">
        <div class="info-panel-head">
            <h3><i class="fas fa-folder-open"></i> Project Documents</h3>
            <a href="documents?project=<?= $project_id ?>" class="btn btn-sm btn-gray">View All</a>
        </div>
        <div class="info-panel-body">
            <?php if (empty($documents)): ?>
                <p class="prose-empty" style="text-align:center;padding:20px 0;">No documents uploaded yet.</p>
            <?php else: ?>
                <div class="doc-grid">
                    <?php foreach ($documents as $doc):
                        $ext  = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                        $di   = $doc_icons[$ext] ?? ['class' => 'file', 'icon' => 'fa-file'];
                    ?>
                        <div class="doc-card">
                            <div class="doc-icon <?= $di['class'] ?>">
                                <i class="fas <?= $di['icon'] ?>"></i>
                            </div>
                            <div class="doc-name" title="<?= h($doc['document_name']) ?>">
                                <?= h($doc['document_name']) ?>
                            </div>
                            <div class="doc-type"><?= h($doc['document_type'] ?? $ext) ?></div>
                            <a href="includes/documents-handler.php?action=view&amp;id=<?= (int) $doc['document_id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-soft">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- -- Progress Updates -------------------------------------------- -->
    <?php if (!empty($progress_updates)): ?>
        <div class="info-panel">
            <div class="info-panel-head">
                <h3><i class="fas fa-file-alt"></i> Recent Progress Updates</h3>
            </div>
            <div class="info-panel-body">
                <?php foreach ($progress_updates as $upd): ?>
                    <div class="update-card">
                        <div class="update-period"><?= h($upd['reporting_period']) ?></div>
                        <div class="update-date">
                            <i class="fas fa-calendar-alt" style="margin-right:5px;opacity:.5;"></i>
                            <?= date('d M Y', strtotime($upd['update_date'])) ?>
                        </div>

                        <?php if ($upd['achievements']): ?>
                            <div class="update-section">
                                <div class="update-section-label achievements">
                                    <i class="fas fa-check-circle"></i> Achievements
                                </div>
                                <div class="update-text"><?= nl2br(h($upd['achievements'])) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($upd['challenges']): ?>
                            <div class="update-section">
                                <div class="update-section-label challenges">
                                    <i class="fas fa-exclamation-circle"></i> Challenges
                                </div>
                                <div class="update-text"><?= nl2br(h($upd['challenges'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /.projects-wrap -->

<?php include 'includes/footer.php'; ?>