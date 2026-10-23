<?php
ob_start();

$page_title = 'Manage RFQ';
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

$procurement_id = (int)($_GET['procurement_id'] ?? 0);

if ($procurement_id <= 0) {
    $_SESSION['error'] = 'Invalid procurement request selected.';
    header('Location: manage_procurement');
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        pr.*,
        pc.category_name,
        u.full_name AS creator_name
    FROM procurement_requests pr
    LEFT JOIN procurement_categories pc ON pc.category_id = pr.category_id
    LEFT JOIN users u ON u.user_id = pr.created_by
    WHERE pr.procurement_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    $_SESSION['error'] = 'Procurement request not found.';
    header('Location: manage_procurement');
    exit;
}

if ($procurement['status'] !== 'Approved' && $procurement['status'] !== 'RFQ Created') {
    $_SESSION['error'] = 'RFQ can only be created for approved procurement requests.';
    header('Location: manage_procurement');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create_rfq') {
        $rfq_number = 'RFQ-' . date('Ymd') . '-' . str_pad((string)$procurement_id, 5, '0', STR_PAD_LEFT);

        $check = $conn->prepare("SELECT rfq_id FROM rfq WHERE procurement_id = ? LIMIT 1");
        $check->bind_param("i", $procurement_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $_SESSION['error'] = 'RFQ already exists for this procurement request.';
            header('Location: manage_rfq?procurement_id=' . $procurement_id);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO rfq (procurement_id, rfq_number, status, created_by)
            VALUES (?, ?, 'Open', ?)
        ");
        $stmt->bind_param("isi", $procurement_id, $rfq_number, $user['user_id']);

        if ($stmt->execute()) {
            $stmt->close();

            $up = $conn->prepare("
                UPDATE procurement_requests
                SET status = 'RFQ Created'
                WHERE procurement_id = ?
                LIMIT 1
            ");
            $up->bind_param("i", $procurement_id);
            $up->execute();
            $up->close();

            $emails = [];
            $emailRes = $conn->query("
                SELECT email 
                FROM users 
                WHERE is_active =1
                  AND email <> ''
                  AND role IN ('Administrator', 'Operations/Admin', 'Accountant')
            ");

            if ($emailRes) {
                while ($row = $emailRes->fetch_assoc()) {
                    $emails[] = $row['email'];
                }
            }

            send_rfq_created_email($emails, [
                'title'          => $procurement['title'],
                'rfq_number'     => $rfq_number,
                'procurement_id' => $procurement_id
            ]);

            $_SESSION['success'] = 'RFQ created successfully.';
        } else {
            $_SESSION['error'] = 'Failed to create RFQ.';
            $stmt->close();
        }

        header('Location: manage_rfq?procurement_id=' . $procurement_id);
        exit;
    }
}
$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$rfq = null;
$stmt = $conn->prepare("
    SELECT *
    FROM rfq
    WHERE procurement_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$rfq = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-file-signature"></i> Manage RFQ</h1>
            <p>Create and manage request for quotation for this procurement.</p>
        </div>

        <div class="hero-actions">
            <a href="manage_procurement" class="btn">
                <i class="fas fa-arrow-left"></i> Back
            </a>
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
            <h3><i class="fas fa-cart-shopping"></i> Procurement Details</h3>
        </div>

        <div class="panel-body">
            <p><strong>Title:</strong> <?= e($procurement['title']) ?></p>
            <p><strong>Category:</strong> <?= e($procurement['category_name'] ?? '-') ?></p>
            <p><strong>Budget:</strong> UGX <?= number_format((float)$procurement['estimated_budget']) ?></p>
            <p><strong>Urgency:</strong> <?= e($procurement['urgency']) ?></p>
            <p><strong>Status:</strong> <?= e($procurement['status']) ?></p>
            <p><strong>Description:</strong><br><?= nl2br(e($procurement['description'] ?? '-')) ?></p>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-file-invoice"></i> RFQ</h3>
        </div>

        <div class="panel-body">
            <?php if (!$rfq): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="create_rfq">
                    <button class="btn btn-dark">
                        <i class="fas fa-plus-circle"></i> Create RFQ
                    </button>
                </form>
            <?php else: ?>
                <p><strong>RFQ Number:</strong> <?= e($rfq['rfq_number']) ?></p>
                <p><strong>Status:</strong> <?= e($rfq['status']) ?></p>
                <p><strong>Created:</strong> <?= date('d M Y H:i', strtotime($rfq['created_at'])) ?></p>

                <div class="actions">
                    <a href="manage_suppliers?rfq_id=<?= (int)$rfq['rfq_id'] ?>" class="btn btn-soft">
                        <i class="fas fa-users"></i> Invite Suppliers
                    </a>

                    <a href="manage_quotations?rfq_id=<?= (int)$rfq['rfq_id'] ?>" class="btn btn-dark">
                        <i class="fas fa-scale-balanced"></i> Quotations
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once 'includes/footer.php'; ?>