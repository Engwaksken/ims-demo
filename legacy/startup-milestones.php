<?php
declare(strict_types=1);

$page_title = 'Manage Startup Milestones';
include 'includes/header.php';

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer',
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

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $refs = [&$types];

    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function badge_class(string $status): string
{
    return match ($status) {
        'Completed' => 'badge badge-completed',
        'In Progress' => 'badge badge-in-progress',
        'Delayed' => 'badge badge-delayed',
        'Cancelled' => 'badge badge-cancelled',
        default => 'badge badge-not-started',
    };
}

function progress_class(float $pct): string
{
    if ($pct >= 75) {
        return 'high';
    }

    if ($pct >= 50) {
        return 'mid';
    }

    return 'low';
}

function format_date(?string $date, string $fallback = '-'): string
{
    if (empty($date)) {
        return $fallback;
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d M Y', $timestamp) : $fallback;
}

if (!table_exists($conn, 'startup_milestones')) {
    die('startup_milestones table is missing. Run sql/startup_milestones.sql first.');
}

$hasFieldVisits = table_exists($conn, 'startup_field_visits');

$allowedStatuses = ['Not Started', 'In Progress', 'Completed', 'Delayed', 'Cancelled'];
$allowedTypes = [
    'Equity',
    'Video Intro',
    'Problem Depth TOC',
    'Product Quality Pedagogy',
    'Product Demo Link',
    'Scalability Traction Sustainability',
    'Team Capability Commitment',
    'URSB Registration',
    'General'
];

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

if (!in_array($filterType, $allowedTypes, true)) {
    $filterType = '';
}

/*
|--------------------------------------------------------------------------
| Application review dropdown
|--------------------------------------------------------------------------
*/
$reviewOptions = [];

$res = $conn->query("
    SELECT
        ar.review_id,
        ar.application_id,
        ar.overall_score,
        ar.recommendation,
        ar.created_at,
        COALESCE(a.startup_name, CONCAT('Application #', ar.application_id)) AS startup_name
    FROM application_reviews ar
    INNER JOIN (
        SELECT
            application_id,
            MAX(review_id) AS latest_review_id
        FROM application_reviews
        GROUP BY application_id
    ) latest_reviews
        ON latest_reviews.latest_review_id = ar.review_id
    LEFT JOIN applications a
        ON a.application_id = ar.application_id
    ORDER BY ar.created_at DESC, ar.review_id DESC
    LIMIT 500
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $reviewOptions[] = $row;
    }

    $res->close();
}

/*
|--------------------------------------------------------------------------
| Field team users dropdown
| Excludes members and applicants.
|--------------------------------------------------------------------------
*/
$fieldUsers = [];

$res = $conn->query("
    SELECT
        user_id,
        full_name,
        role
    FROM users
    WHERE full_name IS NOT NULL
      AND TRIM(full_name) <> ''
      AND (
            role IS NULL
            OR LOWER(TRIM(role)) NOT IN ('member', 'applicant')
      )
    ORDER BY full_name ASC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $fieldUsers[] = $row;
    }

    $res->close();
}

/*
|--------------------------------------------------------------------------
| Startup milestones query
|--------------------------------------------------------------------------
*/
$where = [];
$types = '';
$params = [];

if ($filterStatus !== '') {
    $where[] = 'sm.status = ?';
    $types .= 's';
    $params[] = $filterStatus;
}

if ($filterType !== '') {
    $where[] = 'sm.milestone_type = ?';
    $types .= 's';
    $params[] = $filterType;
}

if ($search !== '') {
    $like = '%' . $search . '%';

    $where[] = '(
        sm.milestone_title LIKE ?
        OR sm.milestone_description LIKE ?
        OR sm.responsible_person LIKE ?
        OR a.startup_name LIKE ?
        OR ar.recommendation LIKE ?
    )';

    $types .= 'sssss';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
    }
}

$fieldVisitSelect = $hasFieldVisits
    ? ",
        COUNT(sfv.visit_id) AS field_visit_count,
        MAX(sfv.visit_id) AS latest_visit_id,
        MAX(sfv.status) AS latest_visit_status,
        MAX(sfv.field_overall_score) AS latest_field_score,
        MAX(sfv.report_file) AS latest_report_file"
    : ",
        0 AS field_visit_count,
        NULL AS latest_visit_id,
        NULL AS latest_visit_status,
        NULL AS latest_field_score,
        NULL AS latest_report_file";

$sql = "
    SELECT
        sm.*,
        COALESCE(a.startup_name, CONCAT('Application #', sm.application_id)) AS startup_name,
        ar.overall_score,
        ar.recommendation
        {$fieldVisitSelect}
    FROM startup_milestones sm
    LEFT JOIN applications a ON a.application_id = sm.application_id
    LEFT JOIN application_reviews ar ON ar.review_id = sm.review_id
";

if ($hasFieldVisits) {
    $sql .= " LEFT JOIN startup_field_visits sfv ON sfv.milestone_id = sm.milestone_id";
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' GROUP BY sm.milestone_id ORDER BY sm.due_date ASC, sm.updated_at DESC, sm.milestone_id DESC';

$milestones = [];

$stmt = $conn->prepare($sql);

if ($stmt) {
    bind_params_dynamic($stmt, $types, $params);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $milestones[] = $row;
        }
    }

    $stmt->close();
}

$stats = [
    'total' => count($milestones),
    'in_progress' => count(array_filter($milestones, static fn($m) => $m['status'] === 'In Progress')),
    'completed' => count(array_filter($milestones, static fn($m) => $m['status'] === 'Completed')),
    'delayed' => count(array_filter($milestones, static fn($m) => $m['status'] === 'Delayed')),
    'overdue' => count(array_filter($milestones, static fn($m) => !in_array($m['status'], ['Completed', 'Cancelled'], true) && !empty($m['due_date']) && strtotime((string)$m['due_date']) < strtotime(date('Y-m-d')))),
    'avg' => count($milestones) ? array_sum(array_map(static fn($m) => (float)$m['progress_percentage'], $milestones)) / count($milestones) : 0,
    'field_forms' => array_sum(array_map(static fn($m) => (int)($m['field_visit_count'] ?? 0), $milestones)),
];
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">

<div class="milestones-wrap page-stack">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-rocket"></i> Manage Startup Milestones</h1>
            <p>Create milestones, generate field-visit forms, score visited startups, and upload startup field reports.</p>
        </div>

        <div class="hero-actions">
            <a href="startup-milestones-dashboard" class="btn">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>

            <?php if ($hasFieldVisits): ?>
                <a href="startup-field-visits" class="btn">
                    <i class="fas fa-clipboard-check"></i> Field Visits
                </a>
            <?php endif; ?>

            <button type="button" class="btn btn-primary" onclick="openStartupMilestoneModal()">
                <i class="fas fa-plus"></i> Add Milestone
            </button>
        </div>
    </div>

    <div class="stats-grid reports-stats milestones-stats">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-flag"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total</div>
                <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info">
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?php echo (int)$stats['in_progress']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?php echo (int)$stats['completed']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:var(--red-bg);color:var(--red-fg);">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Overdue</div>
                <div class="stat-value"><?php echo (int)$stats['overdue']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Average</div>
                <div class="stat-value"><?php echo number_format((float)$stats['avg'], 0); ?>%</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-clipboard-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Field Forms</div>
                <div class="stat-value"><?php echo (int)$stats['field_forms']; ?></div>
            </div>
        </div>
    </div>

    <?php if (!$hasFieldVisits): ?>
        <div class="callout note">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Field visit workflow is not active yet.</strong><br>
                Run <code>sql/startup_field_visits.sql</code> to enable Generate Field Visit Form and field report upload.
            </div>
        </div>
    <?php endif; ?>

    <form method="GET" class="filter-bar filters-bar">
        <div class="form-group search-group filters-grow">
            <label class="form-label">Search</label>
            <div class="search-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Startup, milestone, recommendation...">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Review Area</label>
            <select name="type" class="form-control">
                <option value="">All Areas</option>
                <?php foreach ($allowedTypes as $type): ?>
                    <option value="<?php echo h($type); ?>" <?php echo $filterType === $type ? 'selected' : ''; ?>>
                        <?php echo h($type); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?php echo h($status); ?>" <?php echo $filterStatus === $status ? 'selected' : ''; ?>>
                        <?php echo h($status); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button class="btn btn-dark" type="submit">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="startup-milestones" class="btn">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </form>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-list-check"></i> Startup Milestones</h3>
            <span class="badge badge-available"><?php echo count($milestones); ?> records</span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Startup</th>
                        <th>Milestone</th>
                        <th>Review Score</th>
                        <th>Area</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Field Visit</th>
                        <th>Responsible</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($milestones)): ?>
                        <tr>
                            <td colspan="10" class="empty-state">
                                <i class="fas fa-rocket"></i>
                                <span>No startup milestones found.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($milestones as $milestone): ?>
                            <?php
                            $pct = max(0, min(100, (float)$milestone['progress_percentage']));
                            $editPayload = [
                                'milestone_id' => (int)$milestone['milestone_id'],
                                'review_id' => (int)($milestone['review_id'] ?? 0),
                                'application_id' => (int)($milestone['application_id'] ?? 0),
                                'milestone_title' => (string)($milestone['milestone_title'] ?? ''),
                                'milestone_description' => (string)($milestone['milestone_description'] ?? ''),
                                'milestone_type' => (string)($milestone['milestone_type'] ?? 'General'),
                                'start_date' => (string)($milestone['start_date'] ?? ''),
                                'due_date' => (string)($milestone['due_date'] ?? ''),
                                'completion_date' => (string)($milestone['completion_date'] ?? ''),
                                'status' => (string)($milestone['status'] ?? 'Not Started'),
                                'progress_percentage' => (string)($milestone['progress_percentage'] ?? '0'),
                                'priority' => (string)($milestone['priority'] ?? 'Medium'),
                                'responsible_person' => (string)($milestone['responsible_person'] ?? ''),
                                'notes' => (string)($milestone['notes'] ?? ''),
                            ];
                            $fieldVisitPayload = [
                                'milestone_id' => (int)$milestone['milestone_id'],
                                'startup_name' => (string)($milestone['startup_name'] ?? ''),
                                'milestone_title' => (string)($milestone['milestone_title'] ?? ''),
                                'responsible_person' => (string)($milestone['responsible_person'] ?? ''),
                            ];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo h($milestone['startup_name']); ?></strong>
                                    <br>
                                    <small>App #<?php echo (int)$milestone['application_id']; ?></small>
                                </td>

                                <td>
                                    <div class="milestone-name"><?php echo h($milestone['milestone_title']); ?></div>
                                    <div class="milestone-desc"><?php echo h(mb_substr((string)$milestone['milestone_description'], 0, 80)); ?></div>
                                </td>

                                <td>
                                    <?php echo $milestone['overall_score'] !== null ? number_format((float)$milestone['overall_score'], 2) : '-'; ?>
                                    <br>
                                    <small><?php echo h($milestone['recommendation'] ?? ''); ?></small>
                                </td>

                                <td><?php echo h($milestone['milestone_type']); ?></td>

                                <td><?php echo format_date($milestone['due_date'] ?? null); ?></td>

                                <td>
                                    <span class="<?php echo h(badge_class((string)$milestone['status'])); ?>">
                                        <?php echo h($milestone['status']); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="progress-cell">
                                        <div class="progress-header">
                                            <span class="progress-pct"><?php echo number_format($pct, 0); ?>%</span>
                                        </div>
                                        <div class="prog-track">
                                            <div class="prog-fill <?php echo h(progress_class($pct)); ?>" style="width:<?php echo $pct; ?>%;"></div>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?php if ((int)($milestone['field_visit_count'] ?? 0) > 0): ?>
                                        <span class="badge badge-in-progress">
                                            <i class="fas fa-clipboard-check"></i>
                                            <?php echo (int)$milestone['field_visit_count']; ?> form<?php echo (int)$milestone['field_visit_count'] === 1 ? '' : 's'; ?>
                                        </span>

                                        <?php if (!empty($milestone['latest_visit_id'])): ?>
                                            <br>
                                            <a href="startup-field-visits?visit_id=<?php echo (int)$milestone['latest_visit_id']; ?>#score" style="font-size:12px;color:var(--brand-600);font-weight:700;">
                                                Open latest
                                            </a>
                                        <?php endif; ?>

                                        <?php if (!empty($milestone['latest_field_score'])): ?>
                                            <br>
                                            <small>Score: <?php echo number_format((float)$milestone['latest_field_score'], 1); ?>/10</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--ink-200);font-size:12px;">No form</span>
                                    <?php endif; ?>
                                </td>

                                <td><?php echo h($milestone['responsible_person'] ?? '-'); ?></td>

                                <td>
                                    <div class="actions">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-gray"
                                            title="Edit milestone"
                                            onclick='editStartupMilestone(<?php echo json_encode($editPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'
                                        >
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <?php if ($hasFieldVisits): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary"
                                                title="Generate field visit form"
                                                onclick='openFieldVisitModal(<?php echo json_encode($fieldVisitPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'
                                            >
                                                <i class="fas fa-clipboard-check"></i>
                                            </button>
                                        <?php endif; ?>

                                        <form method="POST" action="includes/startup-milestones-process.php" onsubmit="return confirm('Delete this startup milestone?');" style="display:inline;">
                                            <input type="hidden" name="milestone_id" value="<?php echo (int)$milestone['milestone_id']; ?>">
                                            <button type="submit" name="delete_startup_milestone" class="btn btn-sm btn-red" title="Delete milestone">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Startup Milestone Modal -->
<div id="startupMilestoneModal" class="modal">
    <div class="modal-content" style="max-width:900px;">
        <div class="modal-header">
            <h3>
                <i class="fas fa-rocket"></i>
                <span id="modalTitle">Add Startup Milestone</span>
            </h3>
            <span class="close" onclick="closeModal('startupMilestoneModal')">&times;</span>
        </div>

        <div class="modal-body">
            <form method="POST" action="includes/startup-milestones-process.php" id="startupMilestoneForm">
                <input type="hidden" name="milestone_id" id="milestone_id" value="0">
                <input type="hidden" name="application_id" id="application_id">

                <div class="form-group">
                    <label for="review_id" class="required">Application Review</label>
                    <select name="review_id" id="review_id" class="form-control" required>
                        <option value="">Select reviewed startup...</option>
                        <?php foreach ($reviewOptions as $review): ?>
                            <option
                                value="<?php echo (int)$review['review_id']; ?>"
                                data-application-id="<?php echo (int)$review['application_id']; ?>"
                            >
                                <?php echo h($review['startup_name']); ?>
                                - Latest Review #<?php echo (int)$review['review_id']; ?>
                                - Score: <?php echo number_format((float)$review['overall_score'], 2); ?>
                                - <?php echo h($review['recommendation'] ?? '-'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="milestone_title" class="required">Milestone Title</label>
                        <input type="text" name="milestone_title" id="milestone_title" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="milestone_type" class="required">Review Area</label>
                        <select name="milestone_type" id="milestone_type" class="form-control" required>
                            <?php foreach ($allowedTypes as $type): ?>
                                <option value="<?php echo h($type); ?>"><?php echo h($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="milestone_description">Description</label>
                    <textarea name="milestone_description" id="milestone_description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" name="start_date" id="start_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="due_date">Due Date</label>
                        <input type="date" name="due_date" id="due_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="completion_date">Completion Date</label>
                        <input type="date" name="completion_date" id="completion_date" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-control">
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option value="<?php echo h($status); ?>"><?php echo h($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="progress_percentage">Progress (%)</label>
                        <input type="number" name="progress_percentage" id="progress_percentage" class="form-control" min="0" max="100" step="0.01" value="0">
                    </div>

                    <div class="form-group">
                        <label for="priority">Priority</label>
                        <select name="priority" id="priority" class="form-control">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="responsible_person">Responsible Person</label>
                    <select name="responsible_person" id="responsible_person" class="form-control">
                        <option value="">Select responsible person...</option>
                        <?php foreach ($fieldUsers as $user): ?>
                            <option
                                value="<?php echo h($user['full_name']); ?>"
                                <?php echo (($user['full_name'] ?? '') === ($_SESSION['full_name'] ?? '')) ? 'selected' : ''; ?>
                            >
                                <?php echo h($user['full_name']); ?>
                                <?php if (!empty($user['role'])): ?>
                                    - <?php echo h($user['role']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('startupMilestoneModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="save_startup_milestone" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Milestone
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($hasFieldVisits): ?>
<!-- Generate Field Visit Form Modal -->
<div id="fieldVisitModal" class="modal">
    <div class="modal-content" style="max-width:720px;">
        <div class="modal-header">
            <h3><i class="fas fa-clipboard-check"></i> Generate Field Visit Form</h3>
            <span class="close" onclick="closeModal('fieldVisitModal')">&times;</span>
        </div>

        <div class="modal-body">
            <form method="POST" action="includes/startup-field-visits-process.php" id="fieldVisitForm">
                <input type="hidden" name="milestone_id" id="fv_milestone_id">

                <div class="soft-box" style="margin-bottom:14px;">
                    <strong id="fv_startup_name">Startup</strong>
                    <br>
                    <small id="fv_milestone_title">Milestone</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="fv_assigned_to">Assigned To</label>
                        <select name="assigned_to" id="fv_assigned_to" class="form-control">
                            <option value="">Select field team member...</option>
                            <?php foreach ($fieldUsers as $user): ?>
                                <option value="<?php echo h($user['full_name']); ?>">
                                    <?php echo h($user['full_name']); ?>
                                    <?php if (!empty($user['role'])): ?>
                                        - <?php echo h($user['role']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="fv_planned_visit_date">Planned Visit Date</label>
                        <input type="date" name="planned_visit_date" id="fv_planned_visit_date" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label for="fv_location">Location / Address</label>
                    <input type="text" name="location" id="fv_location" class="form-control" placeholder="Business location to visit">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="fv_contact_person">Contact Person</label>
                        <input type="text" name="contact_person" id="fv_contact_person" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="fv_contact_phone">Contact Phone</label>
                        <input type="text" name="contact_phone" id="fv_contact_phone" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('fieldVisitModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="generate_visit_form" class="btn btn-success">
                        <i class="fas fa-save"></i> Generate Form
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    const form = document.getElementById('startupMilestoneForm');
    const reviewSelect = document.getElementById('review_id');
    const applicationInput = document.getElementById('application_id');
    const statusSelect = document.getElementById('status');
    const progressInput = document.getElementById('progress_percentage');
    const completionInput = document.getElementById('completion_date');

    function setValue(id, value) {
        const element = document.getElementById(id);

        if (!element) {
            return;
        }

        const finalValue = value === null || value === undefined ? '' : String(value);

        element.value = finalValue;

        if (element.tagName === 'SELECT' && finalValue && element.value !== finalValue) {
            const option = document.createElement('option');
            option.value = finalValue;
            option.textContent = finalValue + ' - current value';
            element.appendChild(option);
            element.value = finalValue;
        }
    }

    function syncApplicationId() {
        if (!reviewSelect || !applicationInput) {
            return;
        }

        const selectedOption = reviewSelect.options[reviewSelect.selectedIndex];

        applicationInput.value = selectedOption ? (selectedOption.getAttribute('data-application-id') || '') : '';
    }

    if (reviewSelect) {
        reviewSelect.addEventListener('change', syncApplicationId);
    }

    window.openStartupMilestoneModal = function () {
        if (form) {
            form.reset();
        }

        document.getElementById('modalTitle').textContent = 'Add Startup Milestone';

        setValue('milestone_id', '0');
        setValue('application_id', '');
        setValue('progress_percentage', '0');
        setValue('priority', 'Medium');
        setValue('status', 'Not Started');
        setValue('responsible_person', <?php echo json_encode((string)($_SESSION['full_name'] ?? '')); ?>);

        syncApplicationId();

        openModal('startupMilestoneModal');
    };

    window.editStartupMilestone = function (milestone) {
        if (!milestone) {
            return;
        }

        document.getElementById('modalTitle').textContent = 'Edit Startup Milestone';

        setValue('milestone_id', milestone.milestone_id || '0');
        setValue('review_id', milestone.review_id || '');
        setValue('application_id', milestone.application_id || '');
        setValue('milestone_title', milestone.milestone_title || '');
        setValue('milestone_type', milestone.milestone_type || 'General');
        setValue('milestone_description', milestone.milestone_description || '');
        setValue('start_date', milestone.start_date || '');
        setValue('due_date', milestone.due_date || '');
        setValue('completion_date', milestone.completion_date || '');
        setValue('status', milestone.status || 'Not Started');
        setValue('progress_percentage', milestone.progress_percentage || '0');
        setValue('priority', milestone.priority || 'Medium');
        setValue('responsible_person', milestone.responsible_person || '');
        setValue('notes', milestone.notes || '');

        const beforeApplicationId = document.getElementById('application_id').value;
        syncApplicationId();

        if (!document.getElementById('application_id').value && beforeApplicationId) {
            setValue('application_id', beforeApplicationId);
        }

        openModal('startupMilestoneModal');
    };

    window.openFieldVisitModal = function (milestone) {
        if (!milestone) {
            return;
        }

        const fieldVisitForm = document.getElementById('fieldVisitForm');

        if (fieldVisitForm) {
            fieldVisitForm.reset();
        }

        setValue('fv_milestone_id', milestone.milestone_id || '');

        setValue('fv_assigned_to', milestone.responsible_person || '');
        setValue('fv_planned_visit_date', '');

        const startupName = document.getElementById('fv_startup_name');
        const milestoneTitle = document.getElementById('fv_milestone_title');

        if (startupName) {
            startupName.textContent = milestone.startup_name || 'Startup';
        }

        if (milestoneTitle) {
            milestoneTitle.textContent = milestone.milestone_title || 'Milestone';
        }

        openModal('fieldVisitModal');
    };

    if (statusSelect && progressInput) {
        statusSelect.addEventListener('change', function () {
            if (statusSelect.value === 'Completed') {
                progressInput.value = '100';

                if (completionInput && !completionInput.value) {
                    completionInput.value = new Date().toISOString().split('T')[0];
                }
            }

            if (statusSelect.value === 'Not Started') {
                progressInput.value = '0';

                if (completionInput) {
                    completionInput.value = '';
                }
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            const startDate = document.getElementById('start_date')?.value || '';
            const dueDate = document.getElementById('due_date')?.value || '';

            if (startDate && dueDate && dueDate < startDate) {
                event.preventDefault();
                alert('Due date cannot be earlier than start date.');
                return false;
            }

            syncApplicationId();
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
