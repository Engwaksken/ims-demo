<?php
ob_start();

$page_title = 'Manage Delivery';
require_once 'includes/header.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant','Procurement Officer']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

if (!function_exists('e')) {
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

function redirect_delivery(int $po_id): never
{
    header('Location: manage_delivery?po_id=' . $po_id);
    exit;
}

$po_id = (int)($_GET['po_id'] ?? 0);

if ($po_id <= 0) {
    $_SESSION['error'] = 'Invalid purchase order selected.';
    header('Location: manage_procurement');
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        po.*,
        pr.title AS procurement_title,
        pr.procurement_id,
        s.name AS supplier_name,
        s.email AS supplier_email,
        s.phone AS supplier_phone
    FROM purchase_orders po
    JOIN procurement_requests pr ON pr.procurement_id = po.procurement_id
    JOIN suppliers s ON s.supplier_id = po.supplier_id
    WHERE po.po_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $po_id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$po) {
    $_SESSION['error'] = 'Purchase order not found.';
    header('Location: manage_procurement');
    exit;
}

$currentUserId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'record_delivery') {
        $delivery_date = trim((string)($_POST['delivery_date'] ?? ''));
        $notes         = trim((string)($_POST['notes'] ?? ''));

        if ($delivery_date === '') {
            $_SESSION['error'] = 'Delivery date is required.';
            redirect_delivery($po_id);
        }

        $stmt = $conn->prepare("
            INSERT INTO deliveries
                (po_id, received_by, delivery_date, notes, status)
            VALUES (?, ?, ?, ?, 'Received')
        ");
        $stmt->bind_param("iiss", $po_id, $currentUserId, $delivery_date, $notes);

        if ($stmt->execute()) {
            $stmt->close();

            $up = $conn->prepare("
                UPDATE purchase_orders
                SET status = 'Delivered'
                WHERE po_id = ?
                LIMIT 1
            ");
            $up->bind_param("i", $po_id);
            $up->execute();
            $up->close();

            $_SESSION['success'] = 'Delivery recorded successfully.';
        } else {
            $_SESSION['error'] = 'Failed to record delivery.';
            $stmt->close();
        }

        redirect_delivery($po_id);
    }

    if ($action === 'verify_delivery') {
        $delivery_id = (int)($_POST['delivery_id'] ?? 0);

        if ($delivery_id <= 0) {
            $_SESSION['error'] = 'Invalid delivery selected.';
            redirect_delivery($po_id);
        }

        $stmt = $conn->prepare("
            UPDATE deliveries
            SET status = 'Verified',
                verified_by = ?
            WHERE delivery_id = ?
              AND po_id = ?
              AND status = 'Received'
            LIMIT 1
        ");
        $stmt->bind_param("iii", $currentUserId, $delivery_id, $po_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();

            $up = $conn->prepare("
                UPDATE procurement_requests
                SET status = 'Completed'
                WHERE procurement_id = ?
                LIMIT 1
            ");
            $up->bind_param("i", $po['procurement_id']);
            $up->execute();
            $up->close();

            $_SESSION['success'] = 'Delivery verified and procurement completed.';
        } else {
            $_SESSION['error'] = 'Delivery could not be verified.';
            $stmt->close();
        }

        redirect_delivery($po_id);
    }

    if ($action === 'delete_delivery') {
        $delivery_id = (int)($_POST['delivery_id'] ?? 0);

        $stmt = $conn->prepare("
            DELETE FROM deliveries
            WHERE delivery_id = ?
              AND po_id = ?
              AND status <> 'Verified'
            LIMIT 1
        ");
        $stmt->bind_param("ii", $delivery_id, $po_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success'] = 'Delivery record deleted.';
        } else {
            $_SESSION['error'] = 'Verified deliveries cannot be deleted.';
        }

        $stmt->close();
        redirect_delivery($po_id);
    }
}

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$deliveries = [];
$stmt = $conn->prepare("
    SELECT 
        d.*,
        receiver.full_name AS received_by_name,
        verifier.full_name AS verified_by_name
    FROM deliveries d
    LEFT JOIN users receiver ON receiver.user_id = d.received_by
    LEFT JOIN users verifier ON verifier.user_id = d.verified_by
    WHERE d.po_id = ?
    ORDER BY d.delivery_date DESC, d.delivery_id DESC
");
$stmt->bind_param("i", $po_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $deliveries[] = $row;
}

$stmt->close();
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-truck"></i> Manage Delivery</h1>
            <p><?= e($po['po_number']) ?> - <?= e($po['procurement_title']) ?></p>
        </div>

        <div class="hero-actions">
            <a href="manage_procurement" class="btn">
                <i class="fas fa-arrow-left"></i> Procurement
            </a>

            <button type="button" class="btn btn-primary" onclick="openModal('deliveryModal')">
                <i class="fas fa-plus-circle"></i> Record Delivery
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
            <h3><i class="fas fa-file-invoice"></i> Purchase Order Details</h3>
        </div>

        <div class="panel-body">
            <p><strong>PO Number:</strong> <?= e($po['po_number']) ?></p>
            <p><strong>Supplier:</strong> <?= e($po['supplier_name']) ?></p>
            <p><strong>Amount:</strong> UGX <?= number_format((float)$po['total_amount']) ?></p>
            <p><strong>Status:</strong> <?= e($po['status']) ?></p>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-list-check"></i> Delivery Records</h3>
            <span class="note"><?= number_format(count($deliveries)) ?> delivery record(s)</span>
        </div>

        <div class="panel-body table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Delivery Date</th>
                    <th>Received By</th>
                    <th>Verified By</th>
                    <th>Notes</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($deliveries)): ?>
                    <tr><td colspan="6">No delivery records found.</td></tr>
                <?php endif; ?>

                <?php foreach ($deliveries as $delivery): ?>
                    <?php
                    $badge = match ($delivery['status']) {
                        'Verified' => 'badge-issued',
                        'Received' => 'badge-approved',
                        'Rejected' => 'badge-rejected',
                        default    => 'badge-pending',
                    };
                    ?>

                    <tr>
                        <td><?= !empty($delivery['delivery_date']) ? date('d M Y H:i', strtotime($delivery['delivery_date'])) : '-' ?></td>
                        <td><?= e($delivery['received_by_name'] ?? '-') ?></td>
                        <td><?= e($delivery['verified_by_name'] ?? '-') ?></td>
                        <td><?= nl2br(e($delivery['notes'] ?: '-')) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= e($delivery['status']) ?></span></td>
                        <td>
                            <div class="actions">
                                <?php if ($delivery['status'] === 'Received'): ?>
                                    <form method="POST" onsubmit="return confirm('Verify this delivery?');">
                                        <input type="hidden" name="action" value="verify_delivery">
                                        <input type="hidden" name="delivery_id" value="<?= (int)$delivery['delivery_id'] ?>">
                                        <button class="btn btn-sm btn-green">
                                            <i class="fas fa-check-circle"></i> Verify
                                        </button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Delete this delivery record?');">
                                        <input type="hidden" name="action" value="delete_delivery">
                                        <input type="hidden" name="delivery_id" value="<?= (int)$delivery['delivery_id'] ?>">
                                        <button class="btn btn-sm btn-red">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="note">Locked</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<div class="modal" id="deliveryModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Record Delivery</h3>
            <button type="button" class="modal-close" onclick="closeModal('deliveryModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="record_delivery">

                <div class="form-group full">
                    <label class="form-label">Delivery Date *</label>
                    <input type="datetime-local" name="delivery_date" class="form-control" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Delivery Notes</label>
                    <textarea name="notes" class="form-control"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('deliveryModal')">Cancel</button>
                    <button class="btn btn-dark">
                        <i class="fas fa-save"></i> Save Delivery
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