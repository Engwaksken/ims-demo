<?php
declare(strict_types=1);

$page_title = 'Venture Milestone Evidence';
include 'includes/header.php';

check_role([
    'Administrator','Programs Lead','Program Director','Program Manager',
    'MEAL Lead','Project Officer','Reviewer','Applicant','applicant'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function table_exists(mysqli $conn, string $table): bool {
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$res) return false;
    $ok = $res->num_rows > 0;
    $res->close();
    return $ok;
}

function badge_class(string $status): string {
    return match ($status) {
        'Reviewed','Accepted' => 'badge badge-completed',
        'Needs Improvement' => 'badge badge-delayed',
        'Rejected' => 'badge badge-cancelled',
        default => 'badge badge-not-started',
    };
}

if (!table_exists($conn, 'startup_milestones')) {
    die('startup_milestones table is missing.');
}
if (!table_exists($conn, 'startup_milestone_evidence')) {
    die('startup_milestone_evidence table is missing. Run sql/startup_milestone_evidence.sql first.');
}

$applicationId = isset($_GET['application_id']) ? (int)$_GET['application_id'] : 0;
if ($applicationId <= 0) {
    foreach (['application_id', 'app_id', 'startup_application_id'] as $sessionKey) {
        if (!empty($_SESSION[$sessionKey])) {
            $applicationId = (int)$_SESSION[$sessionKey];
            break;
        }
    }
}

$milestones = [];
$sql = "
    SELECT sm.*, COALESCE(a.startup_name, CONCAT('Application #', sm.application_id)) AS startup_name
    FROM startup_milestones sm
    LEFT JOIN applications a ON a.application_id = sm.application_id
";
if ($applicationId > 0) $sql .= " WHERE sm.application_id = " . (int)$applicationId;
$sql .= " ORDER BY sm.due_date ASC, sm.created_at DESC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) $milestones[] = $row;
    $res->close();
}

$evidenceRows = [];
if ($applicationId > 0) {
    $stmt = $conn->prepare("
        SELECT sme.*, sm.milestone_title,
               COALESCE(a.startup_name, CONCAT('Application #', sme.application_id)) AS startup_name,
               u.full_name AS reviewer_name
        FROM startup_milestone_evidence sme
        LEFT JOIN startup_milestones sm ON sm.milestone_id = sme.milestone_id
        LEFT JOIN applications a ON a.application_id = sme.application_id
        LEFT JOIN users u ON u.user_id = sme.reviewer_id
        WHERE sme.application_id = ?
        ORDER BY sme.created_at DESC, sme.evidence_id DESC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($row = $res->fetch_assoc()) $evidenceRows[] = $row;
        $stmt->close();
    }
}
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">

<div class="milestones-wrap page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-upload"></i> Venture Milestone Evidence</h1>
            <p>Upload milestone evidence files for reviewers to view, comment, and score.</p>
        </div>
        <div class="hero-actions">
            <a href="startup-milestones-dashboard" class="btn">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
        </div>
    </div>

    <?php if ($applicationId <= 0): ?>
        <div class="callout note">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Select an application first.</strong><br>
                Use <code>venture-milestone-evidence?application_id=ID</code> or set <code>$_SESSION['application_id']</code>.
            </div>
        </div>
    <?php endif; ?>

    <div class="detail-tabs">
        <div class="tab-nav">
            <button type="button" class="tab-btn active" data-tab="upload"><i class="fas fa-upload"></i> Upload Evidence</button>
            <button type="button" class="tab-btn" data-tab="history"><i class="fas fa-folder-open"></i> My Evidence</button>
        </div>

        <section id="tab-upload" class="tab-panel active">
            <div class="section-title"><h3><i class="fas fa-file-upload"></i> Submit Milestone Evidence</h3></div>

            <form method="POST" action="includes/startup-milestone-evidence-process.php" enctype="multipart/form-data">
                <input type="hidden" name="application_id" value="<?php echo (int)$applicationId; ?>">

                <div class="form-group">
                    <label for="milestone_id" class="required">Milestone</label>
                    <select name="milestone_id" id="milestone_id" class="form-control" required>
                        <option value="">Select milestone...</option>
                        <?php foreach ($milestones as $m): ?>
                            <option value="<?php echo (int)$m['milestone_id']; ?>" data-application-id="<?php echo (int)$m['application_id']; ?>">
                                <?php echo h($m['startup_name']); ?> — <?php echo h($m['milestone_title']); ?> — <?php echo h($m['status']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="evidence_title" class="required">Evidence Title</label>
                        <input type="text" name="evidence_title" id="evidence_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="evidence_file" class="required">Evidence File</label>
                        <input type="file" name="evidence_file" id="evidence_file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.zip">
                        <small style="color:var(--ink-300);">Allowed: PDF, Word, Excel, PowerPoint, images, ZIP. Max 20MB.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="evidence_description">Description / Notes</label>
                    <textarea name="evidence_description" id="evidence_description" class="form-control" rows="4"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="submitted_name">Submitted By</label>
                        <input type="text" name="submitted_name" id="submitted_name" class="form-control" value="<?php echo h($_SESSION['full_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="submitted_email">Email</label>
                        <input type="email" name="submitted_email" id="submitted_email" class="form-control" value="<?php echo h($_SESSION['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="submitted_phone">Phone</label>
                        <input type="text" name="submitted_phone" id="submitted_phone" class="form-control" value="<?php echo h($_SESSION['phone'] ?? ''); ?>">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" name="upload_milestone_evidence" class="btn btn-success">
                        <i class="fas fa-upload"></i> Upload Evidence
                    </button>
                </div>
            </form>
        </section>

        <section id="tab-history" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-folder-open"></i> Submitted Evidence</h3>
                <span class="badge badge-available"><?php echo count($evidenceRows); ?> file<?php echo count($evidenceRows) === 1 ? '' : 's'; ?></span>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Milestone</th><th>Evidence</th><th>File</th><th>Review Status</th><th>Score</th><th>Reviewer Comments</th><th>Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($evidenceRows)): ?>
                            <tr><td colspan="7" class="empty-state"><i class="fas fa-folder-open"></i><span>No evidence uploaded yet.</span></td></tr>
                        <?php else: foreach ($evidenceRows as $row): ?>
                            <tr>
                                <td><strong><?php echo h($row['milestone_title'] ?? '-'); ?></strong><br><small><?php echo h($row['startup_name'] ?? ''); ?></small></td>
                                <td><strong><?php echo h($row['evidence_title']); ?></strong><br><small><?php echo h($row['evidence_description'] ?? ''); ?></small></td>
                                <td><a href="<?php echo h(ims_upload_url($row['evidence_file'], true)); ?>" class="btn btn-sm btn-soft" download><i class="fas fa-download"></i> Download</a></td>
                                <td><span class="<?php echo h(badge_class((string)$row['review_status'])); ?>"><?php echo h($row['review_status']); ?></span></td>
                                <td><?php echo $row['reviewer_score'] !== null ? number_format((float)$row['reviewer_score'], 1) . '/10' : '-'; ?></td>
                                <td><?php echo nl2br(h($row['reviewer_comments'] ?? '-')); ?></td>
                                <td><?php echo !empty($row['created_at']) ? date('d M Y, h:i A', strtotime((string)$row['created_at'])) : '-'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.tab-panel');

    function openTab(target) {
        buttons.forEach(btn => btn.classList.remove('active'));
        panels.forEach(panel => panel.classList.remove('active'));
        const button = document.querySelector('.tab-btn[data-tab="' + target + '"]');
        const panel = document.getElementById('tab-' + target);
        if (button) button.classList.add('active');
        if (panel) panel.classList.add('active');
    }

    buttons.forEach(button => button.addEventListener('click', () => openTab(button.getAttribute('data-tab'))));

    if (window.location.hash === '#history') openTab('history');

    const milestoneSelect = document.getElementById('milestone_id');
    const appInput = document.querySelector('input[name="application_id"]');
    if (milestoneSelect && appInput) {
        milestoneSelect.addEventListener('change', function () {
            const selected = milestoneSelect.options[milestoneSelect.selectedIndex];
            if (selected && selected.dataset.applicationId) appInput.value = selected.dataset.applicationId;
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
