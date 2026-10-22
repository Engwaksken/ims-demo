<?php
ob_start();

$page_title = 'My Asset Requests';
require_once 'includes/header.php';
require_once 'includes/process_my_requests.php';

check_role(['Administrator', 'Operations/Admin','Finance', 'Accountant', 'Programs Lead', 'Staff','Procurement Officer']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

$currentUserId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));

if ($currentUserId <= 0) {
    die('User session not found.');
}



handleMyRequestPostActions($conn, $currentUserId);

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$categories = getMyRequestCategories($conn);
$pageData   = getMyRequestsPageData($conn, $currentUserId);

$requests   = $pageData['requests'];
$search     = $pageData['search'];
$page       = $pageData['page'];
$totalRows  = $pageData['totalRows'];
$totalPages = $pageData['totalPages'];


$available_assets = [];
$res = $conn->query("
    SELECT
        a.asset_id,
        a.asset_code,
        a.asset_name,
        a.category_id,
        c.category_name
    FROM assets a
    LEFT JOIN asset_categories c ON c.category_id = a.category_id
    WHERE a.availability_status = 'Available'
    ORDER BY a.asset_name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $available_assets[] = $row;
    }
}
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-list-check"></i> My Requests</h1>
            <p>View, edit, resubmit, or cancel your  requests.</p>
        </div>

        <div class="hero-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('newRequestModal')">
                <i class="fas fa-paper-plane"></i> New Request
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
            <h3><i class="fas fa-box-open"></i> Request History</h3>
            <span class="note"><?= number_format($totalRows) ?> request(s)</span>
        </div>

        <div class="panel-body">
            <div class="table-wrap">

                <form method="GET" class="filter-bar filters-bar" role="search" style="margin-bottom:15px;">
                    <input
                        type="text"
                        name="search"
                        class="form-control filters-grow"
                        placeholder="Search asset, status, urgency, reason..."
                        value="<?= e($search) ?>"
                    >
                    <button class="btn btn-dark">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="my_requests" class="btn btn-gray">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                    <a href="my_requests?search=<?= urlencode($search) ?>&export=pdf" class="btn btn-red">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </a>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Requested Item</th>
                            <th>Category</th>
                            <th>Reason</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Assigned Asset</th>
                            <th>Feedback</th>
                            <th>Requested At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="9">You have not submitted any Item requests yet.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($requests as $request): ?>
                            <?php
                            $statusClass = match ($request['status']) {
                                'Pending'  => 'badge-pending',
                                'Approved' => 'badge-approved',
                                'Issued'   => 'badge-issued',
                                'Rejected' => 'badge-rejected',
                                'Returned' => 'badge-returned',
                                default    => 'badge-pending',
                            };

                            $canModify = in_array($request['status'], ['Pending', 'Rejected'], true);
                            ?>

                            <tr>
                                <td>
                                    <strong><?= e($request['asset_name']) ?></strong><br>
                                    <span class="note">REQ-<?= str_pad((string)$request['request_id'], 5, '0', STR_PAD_LEFT) ?></span>
                                </td>

                                <td><?= e($request['category_name'] ?? '-') ?></td>

                                <td><?= nl2br(e($request['request_reason'])) ?></td>

                                <td><?= e($request['urgency_level']) ?></td>

                                <td>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= e($request['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (!empty($request['assigned_asset'])): ?>
                                        <strong><?= e($request['assigned_asset']) ?></strong><br>
                                        <span class="note"><?= e($request['asset_code'] ?? '') ?></span>
                                    <?php else: ?>
                                        <span class="note">Not assigned yet</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($request['approval_notes'])): ?>
                                        <?= nl2br(e($request['approval_notes'])) ?><br>
                                        <span class="note">
                                            <?= !empty($request['approved_by_name']) ? 'By ' . e($request['approved_by_name']) : '' ?>
                                            <?= !empty($request['approved_at']) ? ' on ' . nbi_date($request['approved_at']) : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="note">No feedback yet</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= nbi_date($request['requested_at'] ?? '') ?></td>

                                <td>
                                    <?php if ($canModify): ?>
                                        <div class="actions">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-gray"
                                                onclick='openEditRequestModal(<?= json_encode($request, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <form method="POST" onsubmit="return confirm('Cancel this request?');">
                                                <input type="hidden" name="action" value="cancel_request">
                                                <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-red">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="note">Locked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                    <div class="modal-actions" style="justify-content:center;">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-gray" href="?search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <span class="btn btn-soft">
                            Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                        </span>

                        <?php if ($page < $totalPages): ?>
                            <a class="btn btn-gray" href="?search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</div>

<!-- -------------- NEW REQUEST MODAL -------------- -->
<div class="modal" id="newRequestModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3><i class="fas fa-paper-plane"></i> New Procurement Request</h3>
            <button type="button" class="modal-close" onclick="closeModal('newRequestModal')">&times;</button>
        </div>

        <div class="modal-body">
            <!-- Posts to self; action=submit_request routes to submitMyAssetRequest() -->
            <form method="POST" action="my_requests">
                <input type="hidden" name="action" value="submit_request">

                <div class="form-grid">

                   
                    <div class="form-group full">
                        <label class="form-label">Item Name *</label>
                        <input type="text" name="asset_name" id="new_asset_name" class="form-control" required>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Category *</label>
                        <select name="category_id" id="new_category_id" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int)$category['category_id'] ?>">
                                    <?= e($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Urgency Level</label>
                        <select name="urgency_level" class="form-control">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Request Reason *</label>
                        <textarea name="request_reason" class="form-control" required
                            placeholder="Explain why you need this asset..."></textarea>
                    </div>

                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('newRequestModal')">Cancel</button>
                    <button type="submit" class="btn btn-dark">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- -------------- EDIT REQUEST MODAL -------------- -->
<div class="modal" id="editRequestModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Edit Asset Request</h3>
            <button type="button" class="modal-close" onclick="closeModal('editRequestModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" action="my_requests">
                <input type="hidden" name="action" value="update_request">
                <input type="hidden" name="request_id" id="edit_request_id">

                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Item Name *</label>
                        <input type="text" name="asset_name" id="edit_asset_name" class="form-control" required>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Category *</label>
                        <select name="category_id" id="edit_category_id" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int)$category['category_id'] ?>">
                                    <?= e($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Urgency *</label>
                        <select name="urgency_level" id="edit_urgency_level" class="form-control" required>
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Reason *</label>
                        <textarea name="request_reason" id="edit_request_reason" class="form-control" required></textarea>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('editRequestModal')">Cancel</button>
                    <button type="submit" class="btn btn-dark">
                        <i class="fas fa-save"></i> Update & Resubmit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* -- Modal helpers -- */
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

window.addEventListener('click', function (e) {
    document.querySelectorAll('.modal.show').forEach(function (modal) {
        if (e.target === modal) modal.classList.remove('show');
    });
});


function fillNewAssetDetails() {
    const select = document.getElementById('new_asset_id');
    const option = select.options[select.selectedIndex];

    if (!option || !option.value) {
        document.getElementById('new_asset_name').value  = '';
        document.getElementById('new_category_id').value = '';
        return;
    }

    document.getElementById('new_asset_name').value  = option.dataset.name       || '';
    document.getElementById('new_category_id').value = option.dataset.categoryId || '';
}

/* -- Edit request: populate modal fields -- */
function openEditRequestModal(data) {
    document.getElementById('edit_request_id').value     = data.request_id     || '';
    document.getElementById('edit_asset_name').value     = data.asset_name     || '';
    document.getElementById('edit_category_id').value    = data.category_id    || '';
    document.getElementById('edit_urgency_level').value  = data.urgency_level  || 'Medium';
    document.getElementById('edit_request_reason').value = data.request_reason || '';
    openModal('editRequestModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>