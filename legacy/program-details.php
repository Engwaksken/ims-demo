<?php
$page_title = 'Program Details';
require_once __DIR__ . '/includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Staff']);

function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* -- Validate ID ------------------------------------------------------ */
$program_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$program_id) {
    send_notification($_SESSION['user_id'], 'Invalid program ID', 'danger');
    header("Location: programs"); exit();
}

/* -- Program + donor -------------------------------------------------- */
$result = $conn->query("
    SELECT p.*, d.donor_name, d.email AS donor_email,
           d.phone AS donor_phone, d.website AS donor_website
    FROM programs p
    LEFT JOIN donors d ON p.donor_id = d.donor_id
    WHERE p.id = $program_id
");
if ($result->num_rows === 0) {
    send_notification($_SESSION['user_id'], 'Program not found', 'danger');
    header("Location: programs"); exit();
}
$program = $result->fetch_assoc();

/* -- Partners --------------------------------------------------------- */
$partners = [];
$result = $conn->query("
    SELECT p.*, pp.role FROM partners p
    JOIN program_partners pp ON p.partner_id = pp.partner_id
    WHERE pp.program_id = $program_id
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
    WHERE i.program_id = $program_id
    ORDER BY i.indicator_type, i.indicator_name
");
while ($row = $result->fetch_assoc()) { $indicators[] = $row; }

/* -- Milestones ------------------------------------------------------- */
$milestones = [];
$result = $conn->query("SELECT * FROM milestones WHERE program_id = $program_id ORDER BY due_date");
while ($row = $result->fetch_assoc()) { $milestones[] = $row; }

/* -- Beneficiaries (latest 10) ---------------------------------------- */
$beneficiaries = [];
$result = $conn->query("
    SELECT b.*, pb.enrollment_date, pb.participation_type, pb.status AS enrollment_status
    FROM beneficiaries b
    JOIN programs_beneficiaries pb ON b.beneficiary_id = pb.beneficiary_id
    WHERE pb.program_id = $program_id
    ORDER BY pb.enrollment_date DESC
    LIMIT 10
");
while ($row = $result->fetch_assoc()) { $beneficiaries[] = $row; }

/* -- Documents (latest 5) --------------------------------------------- */
$documents = [];
$result = $conn->query("SELECT * FROM documents WHERE program_id = $program_id ORDER BY upload_date DESC LIMIT 5");
while ($row = $result->fetch_assoc()) { $documents[] = $row; }

/* -- Progress updates (latest 3) ------------------------------------- */
$progress_updates = [];
$result = $conn->query("SELECT * FROM progress_updates WHERE program_id = $program_id ORDER BY update_date DESC LIMIT 3");
while ($row = $result->fetch_assoc()) { $progress_updates[] = $row; }

/* -- Counts & calculations -------------------------------------------- */
$total_beneficiaries  = (int) $conn->query("SELECT COUNT(*) AS c FROM programs_beneficiaries WHERE program_id = $program_id")->fetch_assoc()['c'];
$active_beneficiaries = (int) $conn->query("SELECT COUNT(*) AS c FROM programs_beneficiaries WHERE program_id = $program_id AND status = 'Active'")->fetch_assoc()['c'];
$total_documents      = (int) $conn->query("SELECT COUNT(*) AS c FROM documents WHERE program_id = $program_id")->fetch_assoc()['c'];

$total_indicators     = count($indicators);
$achieved_indicators  = count(array_filter($indicators, fn($i) => $i['achievement_percentage'] >= 100));
$total_milestones     = count($milestones);
$completed_milestones = count(array_filter($milestones, fn($m) => $m['status'] === 'Completed'));
$milestone_completion = $total_milestones > 0 ? ($completed_milestones / $total_milestones * 100) : 0;

$overall_achievement = 0;
if ($total_indicators > 0) {
    $overall_achievement = array_sum(array_column($indicators, 'achievement_percentage')) / $total_indicators;
}

$start_date   = new DateTime($program['start_date']);
$end_date     = new DateTime($program['end_date']);
$today        = new DateTime();
$total_days   = $start_date->diff($end_date)->days;
$elapsed_days = $start_date->diff($today)->days;
$time_progress = $total_days > 0 ? min(($elapsed_days / $total_days * 100), 100) : 0;
$duration     = $start_date->diff($end_date);

/* -- Lookup maps ------------------------------------------------------ */
$status_badge = [
    'Ongoing'   => 'badge badge-in-progress',
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
$detailsTab = (string)($_GET['tab'] ?? 'overview');
$allowedDetailsTabs = ['overview', 'snapshot', 'details', 'indicators', 'delivery', 'records'];
if (!in_array($detailsTab, $allowedDetailsTabs, true)) $detailsTab = 'overview';
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/projects.css">

<div class="page-stack">

    <!-- -- Hero ---------------------------------------------------------- -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-sitemap" aria-hidden="true"></i> <?= h($program['program_name']) ?></h1>
            <p>
                <i class="fas fa-tag" aria-hidden="true"></i> <?= h($program['program_code']) ?>
                &nbsp;&middot;&nbsp;
                <span class="<?= $status_badge[$program['status']] ?? 'badge badge-returned' ?>"><?= h($program['status']) ?></span>
            </p>
        </div>
        <div class="hero-actions">
            <a href="programs" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
            </a>
            <button type="button" onclick="window.print()" class="btn">
                <i class="fas fa-print" aria-hidden="true"></i> Print
            </button>
        </div>
    </div>

    <nav class="pd-tabs" role="tablist" aria-label="Program detail sections">
        <?php foreach (['overview' => 'Overview', 'snapshot' => 'Snapshot', 'details' => 'Details', 'indicators' => 'Indicators', 'delivery' => 'Delivery', 'records' => 'Records'] as $tabKey => $tabLabel): ?>
            <a role="tab" aria-controls="program-panel-<?= h($tabKey) ?>" aria-selected="<?= $detailsTab === $tabKey ? 'true' : 'false' ?>" class="pd-tab <?= $detailsTab === $tabKey ? 'active' : '' ?>" href="program-details?id=<?= $program_id ?>&amp;tab=<?= h($tabKey) ?>"><?= h($tabLabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <!-- -- Overview ------------------------------------------------------ -->
    <section class="pd-panel" id="program-panel-overview" role="tabpanel" aria-label="Overview" <?= $detailsTab !== 'overview' ? 'hidden' : '' ?>>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-circle-info" aria-hidden="true"></i> Overview</h3>
        </div>
        <div class="card-body">
            <div class="pj-facts">
                <div class="pj-fact">
                    <div class="pj-fact-label"><i class="fas fa-hand-holding-usd" aria-hidden="true"></i> Donor</div>
                    <div class="pj-fact-value"><?= h($program['donor_name'] ?? 'N/A') ?></div>
                </div>
                <div class="pj-fact">
                    <div class="pj-fact-label"><i class="fas fa-dollar-sign" aria-hidden="true"></i> Budget</div>
                    <div class="pj-fact-value"><?= format_currency($program['budget'], $program['currency']) ?></div>
                </div>
                <div class="pj-fact">
                    <div class="pj-fact-label"><i class="fas fa-calendar-alt" aria-hidden="true"></i> Duration</div>
                    <div class="pj-fact-value">
                        <?= $duration->y > 0 ? $duration->y . 'yr ' : '' ?><?= $duration->m ?>mo
                    </div>
                </div>
                <div class="pj-fact">
                    <div class="pj-fact-label"><i class="fas fa-map-marker-alt" aria-hidden="true"></i> Scope</div>
                    <div class="pj-fact-value"><?= h($program['geographic_scope'] ?? 'N/A') ?></div>
                </div>
            </div>

            <!-- Timeline progress bar -->
            <div class="pj-timeline">
                <div class="pj-timeline-meta">
                    <div>
                        <span class="pj-timeline-label"><i class="fas fa-clock" aria-hidden="true"></i> Timeline</span>
                        <span class="pj-timeline-dates">
                            <?= date('d M Y', strtotime($program['start_date'])) ?>
                            &rarr;
                            <?= date('d M Y', strtotime($program['end_date'])) ?>
                        </span>
                    </div>
                    <div class="text-right">
                        <span class="pj-timeline-value"><?= number_format($time_progress, 1) ?>%</span>
                        <span class="pj-timeline-remaining">
                            <?php if ($today < $end_date):
                                echo $today->diff($end_date)->days . ' days remaining';
                            else: ?>program ended<?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="ach-track">
                    <div class="ach-fill pj-timeline-fill" style="width:<?= $time_progress ?>%;"></div>
                </div>
            </div>
        </div>
    </div>
    </section>

    <!-- -- Performance Stats ------------------------------------------- -->
    <section class="pd-panel" id="program-panel-snapshot" role="tabpanel" aria-label="Snapshot" <?= $detailsTab !== 'snapshot' ? 'hidden' : '' ?>>
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <div class="stat-label">Active / Total Participants</div>
                <div class="stat-value">
                    <?= $active_beneficiaries ?> <span class="stat-sub">/ <?= $total_beneficiaries ?></span>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Indicators Achieved</div>
                <div class="stat-value">
                    <?= $achieved_indicators ?> <span class="stat-sub">/ <?= $total_indicators ?></span>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-tasks"></i></div>
            <div class="stat-info">
                <div class="stat-label">Milestones Completed</div>
                <div class="stat-value">
                    <?= $completed_milestones ?> <span class="stat-sub">/ <?= $total_milestones ?></span>
                </div>
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
    </section>

    <!-- -- Description & Objectives ----------------------------------- -->
    <section class="pd-panel" id="program-panel-details" role="tabpanel" aria-label="Details" <?= $detailsTab !== 'details' ? 'hidden' : '' ?>>
    <div class="detail-grid-2">

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-align-left" aria-hidden="true"></i> Description</h3>
            </div>
            <div class="card-body">
                <?php if ($program['description']): ?>
                    <p class="prose-text"><?= nl2br(h($program['description'])) ?></p>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-align-left" aria-hidden="true"></i><p>No description available.</p></div>
                <?php endif; ?>
                <?php if ($program['value_chain']): ?>
                    <div class="value-chain-tag">
                        <i class="fas fa-link"></i>
                        <strong>Value Chain:</strong> <?= h($program['value_chain']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-bullseye" aria-hidden="true"></i> Objectives</h3>
            </div>
            <div class="card-body">
                <?php if ($program['objectives']): ?>
                    <div class="prose-text"><?= nl2br(h($program['objectives'])) ?></div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-bullseye" aria-hidden="true"></i><p>No objectives specified.</p></div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- -- Donor & Partners -------------------------------------------- -->
    <div class="detail-grid-2">

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-hand-holding-usd" aria-hidden="true"></i> Donor Information</h3>
            </div>
            <div class="card-body">
                <div class="pj-donor-name">
                    <?= h($program['donor_name'] ?? 'N/A') ?>
                </div>
                <div class="donor-contact-list">
                    <?php if ($program['donor_email']): ?>
                        <div class="donor-contact-row">
                            <div class="dc-icon"><i class="fas fa-envelope"></i></div>
                            <div class="dc-label">Email</div>
                            <div class="dc-value">
                                <a href="mailto:<?= h($program['donor_email']) ?>"><?= h($program['donor_email']) ?></a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($program['donor_phone']): ?>
                        <div class="donor-contact-row">
                            <div class="dc-icon"><i class="fas fa-phone"></i></div>
                            <div class="dc-label">Phone</div>
                            <div class="dc-value"><?= h($program['donor_phone']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($program['donor_website']): ?>
                        <div class="donor-contact-row">
                            <div class="dc-icon"><i class="fas fa-globe"></i></div>
                            <div class="dc-label">Website</div>
                            <div class="dc-value">
                                <a href="<?= h($program['donor_website']) ?>" target="_blank" rel="noopener">
                                    <?= h($program['donor_website']) ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$program['donor_email'] && !$program['donor_phone'] && !$program['donor_website']): ?>
                        <div class="empty-state"><i class="fas fa-address-card" aria-hidden="true"></i><p>No contact details on record.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-handshake" aria-hidden="true"></i> Program Partners</h3>
                <span class="badge badge-available"><?= count($partners) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($partners)): ?>
                    <div class="empty-state"><i class="fas fa-handshake" aria-hidden="true"></i><p>No partners assigned to this program.</p></div>
                <?php else: ?>
                    <?php foreach ($partners as $p): ?>
                        <div class="partner-card">
                            <div class="partner-avatar"><i class="fas fa-building"></i></div>
                            <div class="partner-body">
                                <div class="partner-name"><?= h($p['partner_name']) ?></div>
                                <div class="partner-meta">
                                    <span class="badge badge-assigned"><?= h($p['partner_type']) ?></span>
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
    </section>

    <!-- -- Indicators -------------------------------------------------- -->
    <section class="pd-panel" id="program-panel-indicators" role="tabpanel" aria-label="Indicators" <?= $detailsTab !== 'indicators' ? 'hidden' : '' ?>>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line" aria-hidden="true"></i> Program Indicators</h3>
            <div class="actions">
                <span class="badge badge-available"><?= $total_indicators ?> total</span>
                <?php if (ims_allowed('indicators.add_link', ['Administrator', 'MEAL Lead'], 'See the Add indicator button on program/project pages')): ?>
                    <a href="indicators?program=<?= $program_id ?>" class="btn btn-sm btn-soft">
                        <i class="fas fa-plus"></i> Add
                    </a>
                <?php endif; ?>
                <a href="indicators?program=<?= $program_id ?>" class="btn btn-sm btn-secondary">View All</a>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($indicators)): ?>
                <div class="empty-state"><i class="fas fa-chart-line" aria-hidden="true"></i><p>No indicators defined yet.</p></div>
            <?php else: ?>

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
    </section>

    <!-- -- Milestones & Beneficiaries ---------------------------------- -->
    <section class="pd-panel" id="program-panel-delivery" role="tabpanel" aria-label="Delivery" <?= $detailsTab !== 'delivery' ? 'hidden' : '' ?>>
    <div class="detail-grid-2">

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tasks" aria-hidden="true"></i> Milestones</h3>
                <span class="badge badge-<?= $milestone_completion >= 100 ? 'available' : 'pending' ?>">
                    <?= number_format($milestone_completion, 0) ?>% done
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($milestones)): ?>
                    <div class="empty-state"><i class="fas fa-tasks" aria-hidden="true"></i><p>No milestones defined.</p></div>
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
                                <?php
                                // programs table uses `percentage_complete`; fall back gracefully
                                $ms_pct = (int) ($ms['percentage_complete'] ?? $ms['progress_percentage'] ?? 0);
                                if ($ms_pct > 0): ?>
                                    <div class="milestone-mini-bar">
                                        <div class="milestone-mini-fill" style="width:<?= $ms_pct ?>%;"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $mb ?>"><?= h($ms['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($milestones) > 5): ?>
                        <p class="note text-center pj-more-note">
                            Showing 5 of <?= count($milestones) ?> milestones
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-users" aria-hidden="true"></i> Recent Participants</h3>
                <a href="participants?program=<?= $program_id ?>" class="btn btn-sm btn-secondary">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($beneficiaries)): ?>
                    <div class="empty-state"><i class="fas fa-users" aria-hidden="true"></i><p>No participants enrolled yet.</p></div>
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
    </section>

    <!-- -- Documents --------------------------------------------------- -->
    <section class="pd-panel" id="program-panel-records" role="tabpanel" aria-label="Records" <?= $detailsTab !== 'records' ? 'hidden' : '' ?>>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-folder-open" aria-hidden="true"></i> Program Documents</h3>
            <a href="documents?program=<?= $program_id ?>" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($documents)): ?>
                <div class="empty-state"><i class="fas fa-folder-open" aria-hidden="true"></i><p>No documents uploaded yet.</p></div>
            <?php else: ?>
                <div class="doc-grid">
                    <?php foreach ($documents as $doc):
                        $ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                        $di  = $doc_icons[$ext] ?? ['class' => 'file', 'icon' => 'fa-file'];
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
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-file-alt" aria-hidden="true"></i> Recent Progress Updates</h3>
            </div>
            <div class="card-body">
                <?php foreach ($progress_updates as $upd): ?>
                    <div class="update-card">
                        <div class="update-period"><?= h($upd['reporting_period']) ?></div>
                        <div class="update-date">
                            <i class="fas fa-calendar-alt pj-muted-icon" aria-hidden="true"></i>
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
    </section>

</div><!-- /.page-stack -->

<style>
.pd-tabs{display:flex;gap:6px;overflow-x:auto;border-bottom:1px solid var(--border,#e5e7eb);margin:0 0 16px;padding:0 2px}
.pd-tab{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;color:var(--ink-500,#64748b);text-decoration:none;font-weight:700;white-space:nowrap;border-bottom:3px solid transparent;border-radius:8px 8px 0 0}
.pd-tab:hover{background:var(--surface-hover,#f5f7fa);color:var(--ink-700,#1f2937)}
.pd-tab.active{color:var(--brand-600,#ea580c);border-bottom-color:var(--brand-600,#ea580c)}
.pd-panel[hidden]{display:none!important}
@media print{.pd-tabs{display:none}.pd-panel[hidden]{display:block!important}}
</style>

<?php include 'includes/footer.php'; ?>
