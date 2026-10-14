<?php
$page_title = 'Projects Management';
include 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Program Director','Program Manager']);

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

/* -- Edit pre-load ---------------------------------------------------- */
$edit_project = null;
if (isset($_GET['edit'])) {
    $pid    = (int) $_GET['edit'];
    $result = $conn->query("SELECT * FROM projects WHERE project_id = $pid");
    $edit_project = $result->fetch_assoc();
}

/* -- Filters ---------------------------------------------------------- */
$filter_status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$filter_donor  = isset($_GET['donor'])  ? (int) $_GET['donor']           : 0;
$search        = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';

$where = "1=1";
if ($filter_status) $where .= " AND p.status = '" . $conn->real_escape_string($filter_status) . "'";
if ($filter_donor)  $where .= " AND p.donor_id = $filter_donor";
if ($search)        $where .= " AND (p.project_name LIKE '%" . $conn->real_escape_string($search) . "%' OR p.project_code LIKE '%" . $conn->real_escape_string($search) . "%')";

/* -- Fetch projects --------------------------------------------------- */
$projects = [];
$result = $conn->query("
    SELECT p.*, d.donor_name,
        (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.project_id = p.project_id) AS beneficiary_count,
        (SELECT COUNT(*) FROM indicators i WHERE i.project_id = p.project_id)              AS indicator_count
    FROM projects p
    LEFT JOIN donors d ON p.donor_id = d.donor_id
    WHERE $where
    ORDER BY p.created_at DESC
");
while ($row = $result->fetch_assoc()) { $projects[] = $row; }

/* -- Fetch donors ----------------------------------------------------- */
$donors = [];
$result = $conn->query("SELECT * FROM donors ORDER BY donor_name");
while ($row = $result->fetch_assoc()) { $donors[] = $row; }

/* -- Stats ------------------------------------------------------------ */
$stats = [
    'total'     => count($projects),
    'ongoing'   => count(array_filter($projects, fn($p) => $p['status'] === 'Ongoing')),
    'pending'   => count(array_filter($projects, fn($p) => $p['status'] === 'Pending')),
    'completed' => count(array_filter($projects, fn($p) => $p['status'] === 'Completed')),
];

/* -- Status ? badge class map ----------------------------------------- */
$status_badge = [
    'Ongoing'   => 'badge badge-ongoing',
    'Completed' => 'badge badge-completed',
    'Pending'   => 'badge badge-pending',
    'Cancelled' => 'badge badge-cancelled',
];
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/projects.css">

<div class="projects-wrap">

    <!-- -- Hero --------------------------------------------------------- -->
    <div class="projects-hero">
        <div class="projects-hero-text">
            <h1><i class="fas fa-project-diagram" style="margin-right:10px;opacity:.85;"></i>Projects</h1>
            <p>Manage your organisation's projects, budgets, donors, and timelines.</p>
        </div>
        <div class="hero-actions">
            <button class="btn btn-primary" onclick="openModal('addProjectModal')">
                <i class="fas fa-plus"></i> Add Project
            </button>
        </div>
    </div>

    <!-- -- Stats -------------------------------------------------------- -->
    <div class="projects-stats">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-project-diagram"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Projects</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-play-circle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Ongoing</div>
                <div class="stat-value"><?= $stats['ongoing'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= $stats['pending'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $stats['completed'] ?></div>
            </div>
        </div>
    </div>

    <!-- -- Filter Bar --------------------------------------------------- -->
    <form method="GET" action="" class="filter-bar filters-bar">

        <div class="form-group search-group filters-grow">
            <label class="form-label">Search</label>
            <div class="search-input-wrap">
                <i class="fas fa-search"></i>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Project name or code..."
                    value="<?= h($search) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <?php foreach (['Pending','Ongoing','Completed','Cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Donor</label>
            <select name="donor" class="form-control">
                <option value="">All Donors</option>
                <?php foreach ($donors as $d): ?>
                    <option value="<?= (int)$d['donor_id'] ?>" <?= $filter_donor === (int)$d['donor_id'] ? 'selected' : '' ?>>
                        <?= h($d['donor_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-dark">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="projects" class="btn btn-gray">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>

    </form>

    <!-- -- Projects Table ----------------------------------------------- -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-table" style="color:var(--brand-500);margin-right:8px;"></i>Project List</h3>
            <span class="badge <?= $stats['total'] > 0 ? 'badge-available' : 'badge-pending' ?>">
                <?= $stats['total'] ?> project<?= $stats['total'] !== 1 ? 's' : '' ?>
            </span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Donor</th>
                        <th>Duration</th>
                        <th>Budget</th>
                        <th style="text-align:center;">Participants</th>
                        <th style="text-align:center;">Indicators</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:40px 16px;">
                                <div style="display:flex;flex-direction:column;align-items:center;gap:8px;color:var(--ink-200);">
                                    <i class="fas fa-folder-open" style="font-size:28px;"></i>
                                    <span style="font-size:13px;">No projects found. Try adjusting your filters.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects as $p): ?>
                            <tr>
                                <!-- Project name + code -->
                                <td>
                                    <div class="project-name-cell">
                                        <span class="name"><?= h($p['project_name']) ?></span>
                                        <span class="project-code"><?= h($p['project_code']) ?></span>
                                    </div>
                                </td>

                                <!-- Donor -->
                                <td>
                                    <div class="donor-chip">
                                        <i class="fas fa-building"></i>
                                        <?= h($p['donor_name'] ?? 'N/A') ?>
                                    </div>
                                </td>

                                <!-- Duration -->
                                <td>
                                    <div class="duration-cell">
                                        <span class="range">
                                            <?= date('M Y', strtotime($p['start_date'])) ?>
                                            &rarr;
                                            <?= date('M Y', strtotime($p['end_date'])) ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Budget -->
                                <td>
                                    <div class="budget-cell">
                                        <?= format_currency($p['budget']) ?>
                                        <span class="currency"><?= h($p['currency'] ?? '') ?></span>
                                    </div>
                                </td>

                                <!-- Participants -->
                                <td style="text-align:center;">
                                    <span class="count-badge participants"><?= (int)$p['beneficiary_count'] ?></span>
                                </td>

                                <!-- Indicators -->
                                <td style="text-align:center;">
                                    <span class="count-badge indicators"><?= (int)$p['indicator_count'] ?></span>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="<?= $status_badge[$p['status']] ?? 'badge badge-returned' ?>">
                                        <?= h($p['status']) ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div class="actions">
                                        <a href="project-details?id=<?= (int)$p['project_id'] ?>"
                                           class="btn btn-sm btn-soft" title="View details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?edit=<?= (int)$p['project_id'] ?>"
                                           class="btn btn-sm btn-gray" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (ims_allowed('projects.delete', ['Administrator'], 'Delete projects')): ?>
                                            <button
                                                class="btn btn-sm btn-red"
                                                title="Delete"
                                                onclick="openDeleteModal(<?= (int)$p['project_id'] ?>, '<?= h($p['project_name']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div><!-- /.table-wrap -->
    </div><!-- /.panel -->

</div><!-- /.projects-wrap -->


<!-- ------------------------------------------------------------------
     ADD PROJECT MODAL
     ------------------------------------------------------------------ -->
<div class="modal" id="addProjectModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-plus-circle" style="color:var(--brand-500);margin-right:8px;"></i>Add New Project</h3>
            <button class="modal-close" onclick="closeModal('addProjectModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/projects-process.php">

                <div class="modal-section-label">Identity</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="project_code">
                            Project Code <span class="required-mark">*</span>
                        </label>
                        <input type="text" id="project_code" name="project_code" class="form-control" placeholder="e.g. PRJ-2024-01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="project_name">
                            Project Name <span class="required-mark">*</span>
                        </label>
                        <input type="text" id="project_name" name="project_name" class="form-control" required>
                    </div>
                </div>

                <div class="modal-section-label">Linkage &amp; Status</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="donor_id">
                            Donor <span class="required-mark">*</span>
                        </label>
                        <select id="donor_id" name="donor_id" class="form-control" required>
                            <option value="">Select Donor </option>
                            <?php foreach ($donors as $d): ?>
                                <option value="<?= (int)$d['donor_id'] ?>"><?= h($d['donor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="status">
                            Status <span class="required-mark">*</span>
                        </label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="Pending">Pending</option>
                            <option value="Ongoing" selected>Ongoing</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="modal-section-label">Timeline</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="start_date">
                            Start Date <span class="required-mark">*</span>
                        </label>
                        <input type="date" id="start_date" name="start_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">
                            End Date <span class="required-mark">*</span>
                        </label>
                        <input type="date" id="end_date" name="end_date" class="form-control" required>
                    </div>
                </div>

                <div class="modal-section-label">Budget</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="budget">
                            Amount <span class="required-mark">*</span>
                        </label>
                        <input type="number" id="budget" name="budget" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="currency">
                            Currency <span class="required-mark">*</span>
                        </label>
                        <select id="currency" name="currency" class="form-control" required>
                            <option value="UGX" selected>UGX - Ugandan Shilling</option>
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                        </select>
                    </div>
                </div>

                <div class="modal-section-label">Details</div>
                <div class="form-group full" style="margin-bottom:14px;">
                    <label class="form-label" for="objectives">Objectives</label>
                    <textarea id="objectives" name="objectives" class="form-control" placeholder="Key objectives of this project..."></textarea>
                </div>
                <div class="form-group full">
                    <label class="form-label" for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" placeholder="Optional background or context..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('addProjectModal')">Cancel</button>
                    <button type="submit" name="add_project" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Project
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ------------------------------------------------------------------
     EDIT PROJECT MODAL
     ------------------------------------------------------------------ -->
<div class="modal" id="editProjectModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-edit" style="color:var(--brand-500);margin-right:8px;"></i>Edit Project</h3>
            <button class="modal-close" onclick="closeModal('editProjectModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($edit_project): ?>
                <form method="POST" action="includes/projects-process.php">
                    <input type="hidden" name="project_id" value="<?= (int)$edit_project['project_id'] ?>">

                    <div class="modal-section-label">Identity</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="e_code">Project Code <span class="required-mark">*</span></label>
                            <input type="text" id="e_code" name="project_code" class="form-control"
                                   value="<?= h($edit_project['project_code']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="e_name">Project Name <span class="required-mark">*</span></label>
                            <input type="text" id="e_name" name="project_name" class="form-control"
                                   value="<?= h($edit_project['project_name']) ?>" required>
                        </div>
                    </div>

                    <div class="modal-section-label">Linkage &amp; Status</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="e_donor">Donor <span class="required-mark">*</span></label>
                            <select id="e_donor" name="donor_id" class="form-control" required>
                                <option value="">- Select Donor -</option>
                                <?php foreach ($donors as $d): ?>
                                    <option value="<?= (int)$d['donor_id'] ?>"
                                            <?= (int)$edit_project['donor_id'] === (int)$d['donor_id'] ? 'selected' : '' ?>>
                                        <?= h($d['donor_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="e_status">Status <span class="required-mark">*</span></label>
                            <select id="e_status" name="status" class="form-control" required>
                                <?php foreach (['Pending','Ongoing','Completed','Cancelled'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $edit_project['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="modal-section-label">Timeline</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="e_start">Start Date <span class="required-mark">*</span></label>
                            <input type="date" id="e_start" name="start_date" class="form-control"
                                   value="<?= h($edit_project['start_date']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="e_end">End Date <span class="required-mark">*</span></label>
                            <input type="date" id="e_end" name="end_date" class="form-control"
                                   value="<?= h($edit_project['end_date']) ?>" required>
                        </div>
                    </div>

                    <div class="modal-section-label">Budget</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="e_budget">Amount <span class="required-mark">*</span></label>
                            <input type="number" id="e_budget" name="budget" class="form-control" step="0.01" min="0"
                                   value="<?= h($edit_project['budget']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="e_currency">Currency <span class="required-mark">*</span></label>
                            <select id="e_currency" name="currency" class="form-control" required>
                                <?php foreach (['UGX'=>'Ugandan Shilling','USD'=>'US Dollar','EUR'=>'Euro','GBP'=>'British Pound'] as $code => $label): ?>
                                    <option value="<?= $code ?>" <?= ($edit_project['currency'] ?? '') === $code ? 'selected' : '' ?>>
                                        <?= $code ?> - <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="modal-section-label">Details</div>
                    <div class="form-group full" style="margin-bottom:14px;">
                        <label class="form-label" for="e_obj">Objectives</label>
                        <textarea id="e_obj" name="objectives" class="form-control"><?= h($edit_project['objectives'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="e_desc">Description</label>
                        <textarea id="e_desc" name="description" class="form-control"><?= h($edit_project['description'] ?? '') ?></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-gray" onclick="closeModal('editProjectModal')">Cancel</button>
                        <button type="submit" name="edit_project" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Project
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p class="note" style="padding:16px 0;">No project selected for editing.</p>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ------------------------------------------------------------------
     DELETE CONFIRMATION MODAL
     ------------------------------------------------------------------ -->
<div class="modal" id="deleteModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3><i class="fas fa-trash" style="color:var(--red-fg);margin-right:8px;"></i>Delete Project</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/projects-process.php">
                <input type="hidden" name="project_id" id="deleteProjectId">

                <div class="danger-zone" style="margin-bottom:16px;">
                    <div class="danger-zone-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <p>
                        You are about to permanently delete
                        <strong id="deleteProjectName"></strong>.
                        This action cannot be undone.
                    </p>
                </div>

                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <span>The project must have no linked participants or indicators before it can be deleted.</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" name="delete_project" class="btn btn-red">
                        <i class="fas fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- -- Modal JS --------------------------------------------------------- -->
<script>
(function () {
    'use strict';

    window.openModal = function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
    };

    window.closeModal = function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('show');
        document.body.style.overflow = '';
    };

    /* Backdrop click closes modal */
    document.querySelectorAll('.modal').forEach(function (m) {
        m.addEventListener('click', function (e) {
            if (e.target === m) window.closeModal(m.id);
        });
    });

    /* Escape key */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal.show').forEach(function (m) {
            window.closeModal(m.id);
        });
    });

    /* Delete modal helper */
    window.openDeleteModal = function (id, name) {
        document.getElementById('deleteProjectId').value = id;
        document.getElementById('deleteProjectName').textContent = '"' + name + '"';
        window.openModal('deleteModal');
    };

    /* Auto-open edit modal when ?edit= is present */
    <?php if ($edit_project): ?>
    document.addEventListener('DOMContentLoaded', function () {
        window.openModal('editProjectModal');
    });
    <?php endif; ?>
})();
</script>

<?php include 'includes/footer.php'; ?>