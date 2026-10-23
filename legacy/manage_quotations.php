<?php
ob_start();

$page_title = 'Manage Quotations';
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

function redirect_quotations(int $rfq_id): never
{
    header('Location: manage_quotations?rfq_id=' . $rfq_id);
    exit;
}

function uploadQuotationFile(string $field = 'quotation_file'): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }

    $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024;

    $name = (string)$_FILES[$field]['name'];
    $tmp  = (string)$_FILES[$field]['tmp_name'];
    $size = (int)$_FILES[$field]['size'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Only PDF, DOC, DOCX, JPG, JPEG, and PNG files are allowed.');
    }

    if ($size > $maxSize) {
        throw new RuntimeException('Quotation file must not exceed 5MB.');
    }

    // Content (MIME) check + rejection of double extensions such as x.php.pdf
    $uploadCheck = ims_validate_upload($_FILES[$field], $allowedExt, $maxSize);
    if (!$uploadCheck['ok']) {
        throw new RuntimeException($uploadCheck['error']);
    }

    $dir = __DIR__ . '/uploads/quotations/';

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeName = 'quotation_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $dir . $safeName;

    if (!move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('Failed to upload quotation file.');
    }

    return 'uploads/quotations/' . $safeName;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_quotation') {
        $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
        $amount        = (float)($_POST['amount'] ?? 0);
        $delivery_days = (int)($_POST['delivery_days'] ?? 0);
        $notes         = trim((string)($_POST['notes'] ?? ''));

        if ($supplier_id <= 0 || $amount <= 0) {
            $_SESSION['error'] = 'Supplier and amount are required.';
            redirect_quotations($rfq_id);
        }

        try {
            $attachment = uploadQuotationFile('quotation_file');

            $stmt = $conn->prepare("
                INSERT INTO quotations
                    (rfq_id, supplier_id, amount, delivery_days, notes, attachment, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $stmt->bind_param("iidiss", $rfq_id, $supplier_id, $amount, $delivery_days, $notes, $attachment);

            if ($stmt->execute()) {
                $_SESSION['success'] = 'Quotation added successfully.';
            } else {
                $_SESSION['error'] = 'Failed to add quotation.';
            }

            $stmt->close();
        } catch (Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        redirect_quotations($rfq_id);
    }

    if ($action === 'select_quotation') {
        $quotation_id = (int)($_POST['quotation_id'] ?? 0);

        if ($quotation_id <= 0) {
            $_SESSION['error'] = 'Invalid quotation selected.';
            redirect_quotations($rfq_id);
        }

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT quotation_id, supplier_id, amount
                FROM quotations
                WHERE quotation_id = ? AND rfq_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("ii", $quotation_id, $rfq_id);
            $stmt->execute();
            $quotation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$quotation) {
                throw new RuntimeException('Quotation not found.');
            }

            $stmt = $conn->prepare("
                UPDATE quotations
                SET status = 'Rejected'
                WHERE rfq_id = ?
            ");
            $stmt->bind_param("i", $rfq_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                UPDATE quotations
                SET status = 'Selected'
                WHERE quotation_id = ?
            ");
            $stmt->bind_param("i", $quotation_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                UPDATE rfq
                SET status = 'Awarded'
                WHERE rfq_id = ?
            ");
            $stmt->bind_param("i", $rfq_id);
            $stmt->execute();
            $stmt->close();

            $po_number = 'PO-' . date('Ymd') . '-' . str_pad((string)$rfq['procurement_id'], 5, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("
                INSERT INTO purchase_orders
                    (procurement_id, supplier_id, po_number, total_amount, status)
                VALUES (?, ?, ?, ?, 'Draft')
            ");
            $stmt->bind_param(
                "iisd",
                $rfq['procurement_id'],
                $quotation['supplier_id'],
                $po_number,
                $quotation['amount']
            );
            $stmt->execute();
            $stmt->close();

            $emailStmt = $conn->prepare("
                SELECT u.email
                FROM procurement_requests pr
                JOIN users u ON u.user_id = pr.created_by
                WHERE pr.procurement_id = ?
                LIMIT 1
            ");
            $emailStmt->bind_param("i", $rfq['procurement_id']);
            $emailStmt->execute();
            $emailRow = $emailStmt->get_result()->fetch_assoc();
            $emailStmt->close();

            $user_email = $emailRow['email'] ?? '';

            send_po_created_email($user_email, [
                'title'     => $rfq['procurement_title'],
                'po_number' => $po_number,
                'amount'    => $quotation['amount']
            ]);

            $stmt = $conn->prepare("
                UPDATE procurement_requests
                SET status = 'PO Created'
                WHERE procurement_id = ?
            ");
            $stmt->bind_param("i", $rfq['procurement_id']);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $_SESSION['success'] = 'Quotation selected and purchase order created.';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = $e->getMessage();
        }

        redirect_quotations($rfq_id);
    }

    if ($action === 'delete_quotation') {
        $quotation_id = (int)($_POST['quotation_id'] ?? 0);

        $fileStmt = $conn->prepare("
            SELECT attachment
            FROM quotations
            WHERE quotation_id = ? AND rfq_id = ? AND status <> 'Selected'
            LIMIT 1
        ");
        $fileStmt->bind_param("ii", $quotation_id, $rfq_id);
        $fileStmt->execute();
        $fileRow = $fileStmt->get_result()->fetch_assoc();
        $fileStmt->close();

        $stmt = $conn->prepare("
            DELETE FROM quotations
            WHERE quotation_id = ? AND rfq_id = ? AND status <> 'Selected'
            LIMIT 1
        ");
        $stmt->bind_param("ii", $quotation_id, $rfq_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            if (!empty($fileRow['attachment'])) {
                $fullPath = __DIR__ . '/' . ltrim((string)$fileRow['attachment'], '/');
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }

            $_SESSION['success'] = 'Quotation deleted successfully.';
        } else {
            $_SESSION['error'] = 'Failed to delete quotation or selected quotation cannot be deleted.';
        }

        $stmt->close();
        redirect_quotations($rfq_id);
    }
}

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$suppliers = [];
$stmt = $conn->prepare("
    SELECT 
        s.supplier_id,
        s.name,
        s.email,
        s.phone
    FROM rfq_suppliers rs
    JOIN suppliers s ON s.supplier_id = rs.supplier_id
    WHERE rs.rfq_id = ?
    ORDER BY s.name ASC
");
$stmt->bind_param("i", $rfq_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $suppliers[] = $row;
}
$stmt->close();

$quotations = [];
$stmt = $conn->prepare("
    SELECT 
        q.*,
        s.name AS supplier_name,
        s.email,
        s.phone
    FROM quotations q
    JOIN suppliers s ON s.supplier_id = q.supplier_id
    WHERE q.rfq_id = ?
    ORDER BY q.amount ASC, q.delivery_days ASC
");
$stmt->bind_param("i", $rfq_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $quotations[] = $row;
}
$stmt->close();
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-scale-balanced"></i> Manage Quotations</h1>
            <p><?= e($rfq['procurement_title']) ?> - <?= e($rfq['rfq_number'] ?? 'RFQ') ?></p>
        </div>

        <div class="hero-actions">
            <a href="manage_rfq?procurement_id=<?= (int)$rfq['procurement_id'] ?>" class="btn">
                <i class="fas fa-arrow-left"></i> Back to RFQ
            </a>

            <button type="button" class="btn btn-primary" onclick="openModal('addQuotationModal')">
                <i class="fas fa-plus-circle"></i> Add Quotation
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

        <div>
            <h3><i class="fas fa-list-check"></i> Quotations</h3>
            <span class="note"><?= number_format(count($quotations)) ?> quotation(s)</span>
        </div>

        <div class="actions">
            <a href="manage_quotation_reviews?rfq_id=<?= (int)$rfq_id ?>" class="btn btn-soft">
                <i class="fas fa-user-check"></i> Reviews
            </a>
        </div>

    </div>
        <div class="panel-body table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Amount</th>
                    <th>Delivery Days</th>
                    <th>Notes</th>
                    <th>Attachment</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($quotations)): ?>
                    <tr><td colspan="8">No quotations added yet.</td></tr>
                <?php endif; ?>

                <?php foreach ($quotations as $quote): ?>
                    <?php
                    $badge = match ($quote['status']) {
                        'Selected' => 'badge-issued',
                        'Rejected' => 'badge-rejected',
                        default    => 'badge-pending',
                    };
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($quote['supplier_name']) ?></strong><br>
                            <span class="note"><?= e($quote['email'] ?: $quote['phone'] ?: '-') ?></span>
                        </td>

                        <td>UGX <?= number_format((float)$quote['amount']) ?></td>

                        <td><?= number_format((int)$quote['delivery_days']) ?> days</td>

                        <td><?= nl2br(e($quote['notes'] ?: '-')) ?></td>

                        <td>
                            <?php if (!empty($quote['attachment'])): ?>
                                <a href="<?= e(ims_upload_url($quote['attachment'])) ?>" target="_blank" class="btn btn-sm btn-soft">
                                    <i class="fas fa-file-alt"></i> View
                                </a>
                            <?php else: ?>
                                <span class="note">No file</span>
                            <?php endif; ?>
                        </td>

                        <td><span class="badge <?= $badge ?>"><?= e($quote['status']) ?></span></td>

                        <td><?= !empty($quote['created_at']) ? date('d M Y H:i', strtotime($quote['created_at'])) : '-' ?></td>

                        <td>
                            <div class="actions">
                                <?php if ($quote['status'] !== 'Selected'): ?>
                                    <form method="POST" onsubmit="return confirm('Select this quotation and create purchase order?');">
                                        <input type="hidden" name="action" value="select_quotation">
                                        <input type="hidden" name="quotation_id" value="<?= (int)$quote['quotation_id'] ?>">
                                        <button class="btn btn-sm btn-green">
                                            <i class="fas fa-check-circle"></i> Select
                                        </button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Delete this quotation?');">
                                        <input type="hidden" name="action" value="delete_quotation">
                                        <input type="hidden" name="quotation_id" value="<?= (int)$quote['quotation_id'] ?>">
                                        <button class="btn btn-sm btn-red">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="note">Selected</span>
                                <?php endif; ?>
                            </div>
                            
                            <a href="manage_quotation_reviews?rfq_id=<?= (int)$rfq_id ?>" class="btn btn-soft">
    <i class="fas fa-user-check"></i> Reviews
</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<div class="modal" id="addQuotationModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Add Supplier Quotation</h3>
            <button type="button" class="modal-close" onclick="closeModal('addQuotationModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_quotation">

                <div class="form-group full">
                    <label class="form-label">Supplier *</label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">Select invited supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int)$supplier['supplier_id'] ?>">
                                <?= e($supplier['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full">
                    <label class="form-label">Amount *</label>
                    <input type="number" step="0.01" min="0" name="amount" class="form-control" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Delivery Days</label>
                    <input type="number" min="0" name="delivery_days" class="form-control">
                </div>

                <div class="form-group full">
                    <label class="form-label">Supplier Quotation File</label>
                    <input type="file" name="quotation_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <small class="note">Allowed: PDF, DOC, DOCX, JPG, PNG. Max 5MB.</small>
                </div>

                <div class="form-group full">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('addQuotationModal')">Cancel</button>
                    <button class="btn btn-dark">
                        <i class="fas fa-save"></i> Save Quotation
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