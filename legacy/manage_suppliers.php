<?php
ob_start();

$page_title = 'Manage RFQ Suppliers';
require_once 'includes/header.php';
require_once 'includes/mail-function.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

if (!function_exists('e')) {
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

$rfq_id = (int)($_GET['rfq_id'] ?? 0);

if ($rfq_id <= 0) {
    $_SESSION['error'] = 'Invalid RFQ selected.';
    header('Location: manage_procurement');
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        r.*,
        pr.procurement_id,
        pr.title AS procurement_title
    FROM rfq r
    JOIN procurement_requests pr ON pr.procurement_id = r.procurement_id
    WHERE r.rfq_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $rfq_id);
$stmt->execute();
$rfq = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rfq) {
    $_SESSION['error'] = 'RFQ not found.';
    header('Location: manage_procurement');
    exit;
}

function redirect_suppliers(int $rfq_id): never
{
    header('Location: manage_suppliers?rfq_id=' . $rfq_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_supplier') {
        $name    = trim((string)($_POST['name'] ?? ''));
        $email   = trim((string)($_POST['email'] ?? ''));
        $phone   = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));

        if ($name === '') {
            $_SESSION['error'] = 'Supplier name is required.';
            redirect_suppliers($rfq_id);
        }

        $stmt = $conn->prepare("
            INSERT INTO suppliers (name, email, phone, address)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssss", $name, $email, $phone, $address);

        $_SESSION[$stmt->execute() ? 'success' : 'error'] =
            $stmt->affected_rows > 0 ? 'Supplier added successfully.' : 'Failed to add supplier.';

        $stmt->close();
        redirect_suppliers($rfq_id);
    }

    if ($action === 'invite_supplier') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);

        if ($supplier_id <= 0) {
            $_SESSION['error'] = 'Invalid supplier selected.';
            redirect_suppliers($rfq_id);
        }

        $supplierStmt = $conn->prepare("
            SELECT supplier_id, name, email
            FROM suppliers
            WHERE supplier_id = ?
            LIMIT 1
        ");
        $supplierStmt->bind_param("i", $supplier_id);
        $supplierStmt->execute();
        $supplier = $supplierStmt->get_result()->fetch_assoc();
        $supplierStmt->close();

        if (!$supplier) {
            $_SESSION['error'] = 'Supplier not found.';
            redirect_suppliers($rfq_id);
        }

        $check = $conn->prepare("
            SELECT id
            FROM rfq_suppliers
            WHERE rfq_id = ? AND supplier_id = ?
            LIMIT 1
        ");
        $check->bind_param("ii", $rfq_id, $supplier_id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $_SESSION['error'] = 'Supplier already invited to this RFQ.';
            redirect_suppliers($rfq_id);
        }

        $stmt = $conn->prepare("
            INSERT INTO rfq_suppliers (rfq_id, supplier_id, sent_at, status)
            VALUES (?, ?, NOW(), 'Sent')
        ");
        $stmt->bind_param("ii", $rfq_id, $supplier_id);

        if ($stmt->execute()) {
            if (!empty($supplier['email'])) {
                send_supplier_rfq_invitation_email($supplier['email'], [
                    'supplier_name'      => $supplier['name'],
                    'rfq_number'         => $rfq['rfq_number'] ?? 'RFQ-' . $rfq_id,
                    'procurement_title'  => $rfq['procurement_title'],
                    'rfq_id'             => $rfq_id,
                ]);
            }

            $_SESSION['success'] = 'Supplier invited successfully.';
        } else {
            $_SESSION['error'] = 'Failed to invite supplier.';
        }

        $stmt->close();
        redirect_suppliers($rfq_id);
    }

    if ($action === 'remove_invitation') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid invitation selected.';
            redirect_suppliers($rfq_id);
        }

        $stmt = $conn->prepare("
            DELETE FROM rfq_suppliers
            WHERE id = ? AND rfq_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $id, $rfq_id);
        $stmt->execute();

        $_SESSION[$stmt->affected_rows > 0 ? 'success' : 'error'] =
            $stmt->affected_rows > 0 ? 'Supplier invitation removed.' : 'Invitation not found.';

        $stmt->close();
        redirect_suppliers($rfq_id);
    }
}
$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$suppliers = [];
$res = $conn->query("
    SELECT supplier_id, name, email, phone, address, created_at
    FROM suppliers
    ORDER BY name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

$invited = [];
$stmt = $conn->prepare("
    SELECT 
        rs.*,
        s.name,
        s.email,
        s.phone
    FROM rfq_suppliers rs
    JOIN suppliers s ON s.supplier_id = rs.supplier_id
    WHERE rs.rfq_id = ?
    ORDER BY rs.sent_at DESC
");
$stmt->bind_param("i", $rfq_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $invited[] = $row;
}
$stmt->close();
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-users"></i> RFQ Suppliers</h1>
            <p><?= e($rfq['procurement_title']) ?> - <?= e($rfq['rfq_number'] ?? 'RFQ') ?></p>
        </div>

        <div class="hero-actions">
            <a href="manage_rfq?procurement_id=<?= (int)$rfq['procurement_id'] ?>" class="btn">
                <i class="fas fa-arrow-left"></i> Back to RFQ
            </a>

            <button type="button" class="btn btn-primary" onclick="openModal('addSupplierModal')">
                <i class="fas fa-plus-circle"></i> Add Supplier
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success_msg) ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error_msg) ?></div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-paper-plane"></i> Invite Supplier</h3>
        </div>

        <div class="panel-body">
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="invite_supplier">

                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int)$supplier['supplier_id'] ?>">
                                <?= e($supplier['name']) ?> <?= $supplier['email'] ? ' - ' . e($supplier['email']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="justify-content:flex-end;">
                    <button class="btn btn-dark">
                        <i class="fas fa-paper-plane"></i> Invite
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-list-check"></i> Invited Suppliers</h3>
            <span class="note"><?= number_format(count($invited)) ?> invited</span>
        </div>

        <div class="panel-body table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Sent At</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($invited)): ?>
                    <tr><td colspan="6">No suppliers invited yet.</td></tr>
                <?php endif; ?>

                <?php foreach ($invited as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td><?= e($row['email'] ?: '-') ?></td>
                        <td><?= e($row['phone'] ?: '-') ?></td>
                        <td><span class="badge badge-pending"><?= e($row['status']) ?></span></td>
                        <td><?= !empty($row['sent_at']) ? date('d M Y H:i', strtotime($row['sent_at'])) : '-' ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Remove this invitation?');">
                                <input type="hidden" name="action" value="remove_invitation">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-sm btn-red">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<div class="modal" id="addSupplierModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Add Supplier</h3>
            <button type="button" class="modal-close" onclick="closeModal('addSupplierModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_supplier">

                <div class="form-group full">
                    <label class="form-label">Supplier Name *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>

                <div class="form-group full">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>

                <div class="form-group full">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('addSupplierModal')">Cancel</button>
                    <button class="btn btn-dark">
                        <i class="fas fa-save"></i> Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id)?.classList.add('show')}
function closeModal(id){document.getElementById(id)?.classList.remove('show')}

window.addEventListener('click', function(e){
    document.querySelectorAll('.modal.show').forEach(function(modal){
        if(e.target === modal) modal.classList.remove('show');
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>