<?php
ob_start();

$page_title = 'Asset Maintenance';
require_once 'includes/header.php';
require_once 'includes/asset_maintenance.php';

check_role(['Administrator', 'Operations/Admin', 'Procurement Officer', 'IT Officer']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

$currentUserId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));

if (!function_exists('e')) {
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

handleMaintenanceActions($conn, $currentUserId);

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$assets = getMaintenanceAssets($conn);
$pageData = getMaintenanceRecords($conn);

$records    = $pageData['records'];
$search     = $pageData['search'];
$status     = $pageData['status'];
$page       = $pageData['page'];
$totalRows  = $pageData['totalRows'];
$totalPages = $pageData['totalPages'];
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-screwdriver-wrench"></i> Asset Maintenance</h1>
            <p>Record maintenance issues, update repair progress, and restore assets after service.</p>
        </div>

        <div class="hero-actions">
            <a href="manage_assets" class="btn">
                <i class="fas fa-boxes-stacked"></i> Manage Assets
            </a>

            <button type="button" class="btn btn-primary" onclick="openModal('maintenanceModal')">
                <i class="fas fa-plus-circle"></i> Add Maintenance
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= e($success_msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= e($error_msg) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-list-check"></i> Maintenance Records</h3>
           <?php if ($totalPages > 1): ?>
    <div class="modal-actions" style="justify-content:center;">
        <?php if ($page > 1): ?>
            <a class="btn btn-gray" href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&page=<?= $page - 1 ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
        <?php endif; ?>

        <span class="btn btn-soft">
            Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
        </span>

        <?php if ($page < $totalPages): ?>
            <a class="btn btn-gray" href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&page=<?= $page + 1 ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
        </div>

        <div class="panel-body table-wrap">
        <div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-filter"></i> Search & Export</h3>
    </div>

    <div class="panel-body">
        <form method="GET" class="filters-bar" role="search">
            <div class="form-group filters-grow">
                <label class="form-label">Search</label>
                <input 
                    type="text" 
                    name="search" 
                    class="form-control" 
                    placeholder="Search asset, issue, reporter, handler..."
                    value="<?= e($search) ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <?php foreach (['Pending','In Progress','Completed','Cancelled'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>>
                            <?= e($st) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filters-actions">
                <div class="actions">
                    <button class="btn btn-dark">
                        <i class="fas fa-search"></i> Search
                    </button>

                    <a href="asset_maintenance" class="btn btn-gray">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>

                    <a href="asset_maintenance?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&export=csv" class="btn btn-green">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>

                    <a href="asset_maintenance?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&export=pdf" class="btn btn-red">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>
            <table class="table">
                <thead>
                <tr>
                    <th>Asset</th>
                    <th>Issue</th>
                    <th>Type</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>Reported By</th>
                    <th>Handled By</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="9">No maintenance records found.</td></tr>
                <?php endif; ?>

                <?php foreach ($records as $record): ?>
                    <?php
                    $badge = match ($record['status']) {
                        'Completed'   => 'badge-issued',
                        'In Progress' => 'badge-approved',
                        'Cancelled'   => 'badge-rejected',
                        default       => 'badge-pending',
                    };
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($record['asset_name']) ?></strong><br>
                            <span class="note"><?= e($record['asset_code']) ?></span>
                        </td>

                        <td>
                            <strong><?= e($record['issue_title']) ?></strong><br>
                            <span class="note"><?= nl2br(e($record['issue_description'] ?: '-')) ?></span>
                            <?php if (!empty($record['resolution_notes'])): ?>
                                <br><span class="note"><strong>Resolution:</strong> <?= nl2br(e($record['resolution_notes'])) ?></span>
                            <?php endif; ?>
                        </td>

                        <td><?= e($record['maintenance_type']) ?></td>

                        <td>UGX <?= number_format((float)$record['cost']) ?></td>

                        <td>
                            <span class="badge <?= $badge ?>">
                                <?= e($record['status']) ?>
                            </span>
                        </td>

                        <td><?= e($record['reported_by_name'] ?? '-') ?></td>

                        <td><?= e($record['handled_by_name'] ?? '-') ?></td>

                        <td><?= !empty($record['created_at']) ? date('d M Y H:i', strtotime($record['created_at'])) : '-' ?></td>

                        <td>
                            <div class="actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-gray"
                                    onclick='openUpdateMaintenanceModal(<?= json_encode($record, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                >
                                    <i class="fas fa-edit"></i>
                                </button>

                                <form method="POST" onsubmit="return confirm('Delete this maintenance record?');">
                                    <input type="hidden" name="action" value="delete_maintenance">
                                    <input type="hidden" name="maintenance_id" value="<?= (int)$record['maintenance_id'] ?>">
                                    <button class="btn btn-sm btn-red">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<div class="modal" id="maintenanceModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Add Asset Maintenance</h3>
            <button type="button" class="modal-close" onclick="closeModal('maintenanceModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_maintenance">

                <div class="form-group full">
                    <label class="form-label">Asset *</label>
                    <select name="asset_id" class="form-control" required>
                        <option value="">Select asset</option>
                        <?php foreach ($assets as $asset): ?>
                            <option value="<?= (int)$asset['asset_id'] ?>">
                                <?= e($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['availability_status'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full">
                    <label class="form-label">Issue Title *</label>
                    <input type="text" name="issue_title" class="form-control" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Maintenance Type</label>
                    <select name="maintenance_type" class="form-control">
                        <option value="Repair">Repair</option>
                        <option value="Service">Service</option>
                        <option value="Inspection">Inspection</option>
                        <option value="Upgrade">Upgrade</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label class="form-label">Issue Description</label>
                    <textarea name="issue_description" class="form-control"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('maintenanceModal')">Cancel</button>
                    <button class="btn btn-dark">
                        <i class="fas fa-save"></i> Save Maintenance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="updateMaintenanceModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Update Maintenance</h3>
            <button type="button" class="modal-close" onclick="closeModal('updateMaintenanceModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_maintenance">
                <input type="hidden" name="maintenance_id" id="edit_maintenance_id">

                <p class="note">
                    Asset: <strong id="edit_maintenance_asset"></strong>
                </p>

                <div class="form-group full">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_maintenance_status" class="form-control">
                        <option value="Pending">Pending</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label class="form-label">Maintenance Cost</label>
                    <input type="number" step="0.01" min="0" name="cost" id="edit_maintenance_cost" class="form-control">
                </div>

                <div class="form-group full">
                    <label class="form-label">Resolution Notes</label>
                    <textarea name="resolution_notes" id="edit_resolution_notes" class="form-control"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('updateMaintenanceModal')">Cancel</button>
                    <button class="btn btn-dark">
                        <i class="fas fa-save"></i> Update Maintenance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id)?.classList.add('show');
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('show');
}

function openUpdateMaintenanceModal(data) {
    document.getElementById('edit_maintenance_id').value = data.maintenance_id || '';
    document.getElementById('edit_maintenance_asset').textContent = (data.asset_code || '') + ' - ' + (data.asset_name || '');
    document.getElementById('edit_maintenance_status').value = data.status || 'Pending';
    document.getElementById('edit_maintenance_cost').value = data.cost || 0;
    document.getElementById('edit_resolution_notes').value = data.resolution_notes || '';

    openModal('updateMaintenanceModal');
}

window.addEventListener('click', function (e) {
    document.querySelectorAll('.modal.show').forEach(function (modal) {
        if (e.target === modal) {
            modal.classList.remove('show');
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>