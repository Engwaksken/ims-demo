<?php
declare(strict_types=1);

ob_start();

require_once 'includes/config.php';
require_once 'includes/asset-categories.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !$conn instanceof mysqli) {
    $conn = db_connect();
}

// Authorise BEFORE processing any state-changing POST action.
check_role(['Administrator', 'Operations/Admin', 'Accountant']);

handleCategoryPostActions($conn);

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function prepare_or_die(mysqli $conn, string $sql, string $label): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die(
            '<h3>' . e($label) . '</h3>' .
            '<p>' . e($conn->error) . '</p>' .
            '<pre>' . e($sql) . '</pre>'
        );
    }

    return $stmt;
}

$search = trim((string)($_GET['search'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where .= "
        AND (
            c.category_name LIKE ?
            OR c.description LIKE ?
        )
    ";

    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$sqlBase = "
    FROM asset_categories c
    LEFT JOIN asset_requests ar ON ar.category_id = c.category_id
    LEFT JOIN assets a ON a.category_id = c.category_id
    $where
    GROUP BY c.category_id, c.category_name, c.description, c.created_at
";

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvSql = "
        SELECT 
            c.category_id,
            c.category_name,
            c.description,
            c.created_at,
            COUNT(DISTINCT ar.request_id) AS request_count,
            COUNT(DISTINCT a.asset_id) AS asset_count
        $sqlBase
        ORDER BY c.category_name ASC
    ";

    $stmt = prepare_or_die($conn, $csvSql, 'CSV prepare failed');

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=asset_categories.csv');

    $out = fopen('php://output', 'w');
    ims_fputcsv($out, ['ID', 'Category Name', 'Description', 'Asset Requests', 'Assets', 'Created At']);

    while ($row = $res->fetch_assoc()) {
        ims_fputcsv($out, [
            $row['category_id'],
            $row['category_name'],
            $row['description'],
            $row['request_count'],
            $row['asset_count'],
            $row['created_at'],
        ]);
    }

    fclose($out);
    $stmt->close();
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }

    $pdfSql = "
        SELECT 
            c.category_id,
            c.category_name,
            c.description,
            c.created_at,
            COUNT(DISTINCT ar.request_id) AS request_count,
            COUNT(DISTINCT a.asset_id) AS asset_count
        $sqlBase
        ORDER BY c.category_name ASC
    ";

    $stmt = prepare_or_die($conn, $pdfSql, 'PDF prepare failed');

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $html = '
    <style>
        body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
        h2{color:#ff5722;margin-bottom:4px}
        .muted{color:#666;font-size:10px;margin-bottom:14px}
        table{width:100%;border-collapse:collapse}
        th{background:#ff5722;color:#fff;padding:7px;text-align:left}
        td{border:1px solid #ddd;padding:6px;vertical-align:top}
    </style>

    <h2>Asset Categories</h2>
    <div class="muted">Generated on ' . date('d M Y, h:i A') . '</div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Category</th>
                <th>Description</th>
                <th>Requests</th>
                <th>Assets</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>';

    if (empty($rows)) {
        $html .= '<tr><td colspan="6">No categories found.</td></tr>';
    }

    foreach ($rows as $row) {
        $html .= '
            <tr>
                <td>CAT-' . str_pad((string)$row['category_id'], 4, '0', STR_PAD_LEFT) . '</td>
                <td>' . htmlspecialchars((string)$row['category_name'], ENT_QUOTES, 'UTF-8') . '</td>
                <td>' . nl2br(htmlspecialchars((string)($row['description'] ?: '-'), ENT_QUOTES, 'UTF-8')) . '</td>
                <td>' . number_format((int)$row['request_count']) . '</td>
                <td>' . number_format((int)$row['asset_count']) . '</td>
                <td>' . (!empty($row['created_at']) ? date('d M Y H:i', strtotime((string)$row['created_at'])) : '-') . '</td>
            </tr>';
    }

    $html .= '</tbody></table>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('asset_categories.pdf', ['Attachment' => true]);
    exit;
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM asset_categories c
    $where
";

$countStmt = prepare_or_die($conn, $countSql, 'Count prepare failed');

if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$totalRows = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));

$listSql = "
    SELECT 
        c.category_id,
        c.category_name,
        c.description,
        c.created_at,
        COUNT(DISTINCT ar.request_id) AS request_count,
        COUNT(DISTINCT a.asset_id) AS asset_count
    $sqlBase
    ORDER BY c.category_name ASC
    LIMIT $limit OFFSET $offset
";

$stmt = prepare_or_die($conn, $listSql, 'List prepare failed');

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$categories = [];

while ($row = $res->fetch_assoc()) {
    $categories[] = $row;
}

$stmt->close();

$page_title = 'Asset Categories';

require_once 'includes/header.php';

if (function_exists('check_role')) {
    check_role(['Administrator', 'Operations/Admin', 'Accountant']);
}

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-tags"></i> Asset Categories</h1>
            <p>Create, search, export, and manage asset request categories.</p>
        </div>

        <div class="hero-actions">
            <a href="manage_assets" class="btn">
                <i class="fas fa-boxes-stacked"></i> Manage Assets
            </a>

            <button type="button" class="btn btn-primary" onclick="openModal('addCategoryModal')">
                <i class="fas fa-plus-circle"></i> Add Category
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
                        placeholder="Search category or description..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="filters-actions">
                    <div class="actions">
                        <button type="submit" class="btn btn-dark">
                            <i class="fas fa-search"></i> Search
                        </button>

                        <a href="asset_categories" class="btn btn-gray">
                            <i class="fas fa-rotate-left"></i> Reset
                        </a>

                        <a href="asset_categories?search=<?= urlencode($search) ?>&export=csv" class="btn btn-green">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>

                        <a href="asset_categories?search=<?= urlencode($search) ?>&export=pdf" class="btn btn-red">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-list"></i> Categories</h3>
            <span class="note"><?= number_format($totalRows) ?> category(s)</span>
        </div>

        <div class="panel-body">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Requests</th>
                        <th>Assets</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="6">No categories found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td>
                                <strong><?= e($category['category_name']) ?></strong><br>
                                <span class="note">CAT-<?= str_pad((string)$category['category_id'], 4, '0', STR_PAD_LEFT) ?></span>
                            </td>

                            <td><?= nl2br(e($category['description'] ?: '-')) ?></td>

                            <td>
                                <span class="badge badge-approved">
                                    <?= number_format((int)$category['request_count']) ?> requests
                                </span>
                            </td>

                            <td>
                                <span class="badge badge-assigned">
                                    <?= number_format((int)$category['asset_count']) ?> assets
                                </span>
                            </td>

                            <td>
                                <?= !empty($category['created_at']) ? date('d M Y H:i', strtotime((string)$category['created_at'])) : '-' ?>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-gray"
                                        onclick='openEditCategoryModal(<?= json_encode($category, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    >
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <form method="POST" onsubmit="return confirm('Delete this category?');">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?= (int)$category['category_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-red">
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

<div class="modal" id="addCategoryModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Add Category</h3>
            <button type="button" class="modal-close" onclick="closeModal('addCategoryModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_category">

                <div class="form-group full">
                    <label class="form-label">Category Name *</label>
                    <input type="text" name="category_name" class="form-control" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('addCategoryModal')">Cancel</button>
                    <button type="submit" class="btn btn-dark">
                        <i class="fas fa-save"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="editCategoryModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Edit Category</h3>
            <button type="button" class="modal-close" onclick="closeModal('editCategoryModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_category">
                <input type="hidden" name="category_id" id="edit_category_id">

                <div class="form-group full">
                    <label class="form-label">Category Name *</label>
                    <input type="text" name="category_name" id="edit_category_name" class="form-control" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit_description" class="form-control"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('editCategoryModal')">Cancel</button>
                    <button type="submit" class="btn btn-dark">
                        <i class="fas fa-save"></i> Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('show');
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('show');
}

window.addEventListener('click', function (e) {
    document.querySelectorAll('.modal.show').forEach(function (modal) {
        if (e.target === modal) {
            modal.classList.remove('show');
        }
    });
});

function openEditCategoryModal(data) {
    document.getElementById('edit_category_id').value = data.category_id || '';
    document.getElementById('edit_category_name').value = data.category_name || '';
    document.getElementById('edit_description').value = data.description || '';

    openModal('editCategoryModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>