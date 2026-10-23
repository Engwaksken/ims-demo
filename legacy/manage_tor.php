<?php
ob_start();

$page_title = 'Terms of Reference';
require_once 'includes/header.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

if (!function_exists('e')) {
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

function redirect_tor(int $procurement_id): never {
    header('Location: manage_tor?procurement_id=' . $procurement_id);
    exit;
}

$procurement_id = (int)($_GET['procurement_id'] ?? 0);

if ($procurement_id <= 0) {
    $_SESSION['error'] = 'Invalid procurement selected.';
    header('Location: manage_procurement');
    exit;
}

/* FETCH PROCUREMENT */
$stmt = $conn->prepare("
    SELECT pr.*, pc.category_name
    FROM procurement_requests pr
    LEFT JOIN procurement_categories pc ON pc.category_id = pr.category_id
    WHERE pr.procurement_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    $_SESSION['error'] = 'Procurement not found.';
    header('Location: manage_procurement');
    exit;
}

$currentUserId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));

/* HANDLE ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_tor') {

        $scope          = trim($_POST['scope'] ?? '');
        $specifications = trim($_POST['specifications'] ?? '');
        $deliverables   = trim($_POST['deliverables'] ?? '');
        $timeline       = trim($_POST['timeline'] ?? '');

        $check = $conn->prepare("
            SELECT tor_id FROM terms_of_reference
            WHERE procurement_id = ?
            LIMIT 1
        ");
        $check->bind_param("i", $procurement_id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE terms_of_reference
                SET scope = ?, specifications = ?, deliverables = ?, timeline = ?
                WHERE procurement_id = ?
            ");
            $stmt->bind_param("ssssi", $scope, $specifications, $deliverables, $timeline, $procurement_id);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO terms_of_reference
                (procurement_id, scope, specifications, deliverables, timeline, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issssi", $procurement_id, $scope, $specifications, $deliverables, $timeline, $currentUserId);
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = 'Terms of Reference saved successfully.';
        } else {
            $_SESSION['error'] = 'Failed to save TOR.';
        }

        $stmt->close();
        redirect_tor($procurement_id);
    }
}

/* FETCH TOR */
$stmt = $conn->prepare("
    SELECT *
    FROM terms_of_reference
    WHERE procurement_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$tor = $stmt->get_result()->fetch_assoc();
$stmt->close();

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<link rel="stylesheet" href="css/assets.css">

<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-file-alt"></i> Terms of Reference</h1>
            <p><?= e($procurement['title']) ?></p>
        </div>

        <div class="hero-actions">
            <a href="manage_procurement" class="btn">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= e($success_msg) ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?= e($error_msg) ?></div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head">
            <h3>Define Terms of Reference</h3>
        </div>

        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_tor">

                <div class="form-group">
                    <label>Scope</label>
                    <textarea name="scope" class="form-control"><?= e($tor['scope'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Specifications</label>
                    <textarea name="specifications" class="form-control"><?= e($tor['specifications'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Deliverables</label>
                    <textarea name="deliverables" class="form-control"><?= e($tor['deliverables'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Timeline</label>
                    <input type="text" name="timeline" class="form-control" value="<?= e($tor['timeline'] ?? '') ?>">
                </div>

                <div class="modal-actions">
                    <button class="btn btn-dark">
                        <i class="fas fa-save"></i> Save TOR
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<?php require_once 'includes/footer.php'; ?>