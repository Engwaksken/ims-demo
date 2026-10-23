<?php
ob_start();

$page_title = 'Quotation Reviews';
require_once 'includes/header.php';
require_once 'includes/mail-function.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer', 'Programs Lead', 'Program Manager', 'Program Officer', 'Staff']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

if (!function_exists('e')) {
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

function redirect_reviews(int $rfq_id): never
{
    header('Location: manage_quotation_reviews?rfq_id=' . $rfq_id);
    exit;
}

$currentUserId = (int)($user['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$currentRole   = (string)($user['role'] ?? ($_SESSION['role'] ?? ''));

$isManager = ims_allowed('quotations.manage', ['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer'], 'Open any RFQ and see its quotations and reviewers');
$canInviteReviewers = ims_allowed('quotations.invite_reviewers', ['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer'], 'Invite staff to review quotations');
$canAwardQuotation = ims_allowed('quotations.award', ['Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer'], 'Award quotations to suppliers');

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

$invitedCheck = $conn->prepare("
    SELECT id
    FROM quotation_reviewers
    WHERE rfq_id = ? AND reviewer_id = ?
    LIMIT 1
");
$invitedCheck->bind_param("ii", $rfq_id, $currentUserId);
$invitedCheck->execute();
$isInvitedReviewer = (bool)$invitedCheck->get_result()->fetch_assoc();
$invitedCheck->close();

if (!$isManager && !$isInvitedReviewer) {
    $_SESSION['error'] = 'You are not invited to review this RFQ.';
    header('Location: manage_procurement');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'invite_reviewer' && $canInviteReviewers) {
        $reviewer_id = (int)($_POST['reviewer_id'] ?? 0);

        if ($reviewer_id <= 0) {
            $_SESSION['error'] = 'Select a valid reviewer.';
            redirect_reviews($rfq_id);
        }

        $stmt = $conn->prepare("
            INSERT INTO quotation_reviewers
                (rfq_id, reviewer_id, invited_by, status)
            VALUES (?, ?, ?, 'Invited')
            ON DUPLICATE KEY UPDATE invited_by = VALUES(invited_by)
        ");
        $stmt->bind_param("iii", $rfq_id, $reviewer_id, $currentUserId);

        if ($stmt->execute()) {
            $userStmt = $conn->prepare("
                SELECT full_name, email
                FROM users
                WHERE user_id = ?
                LIMIT 1
            ");
            $userStmt->bind_param("i", $reviewer_id);
            $userStmt->execute();
            $reviewer = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if ($reviewer) {
                send_quotation_review_invite_email($reviewer['email'] ?? '', [
                    'name'       => $reviewer['full_name'] ?? 'Reviewer',
                    'rfq_id'     => $rfq_id,
                    'rfq_number' => $rfq['rfq_number'] ?? 'RFQ',
                    'title'      => $rfq['procurement_title'],
                ]);
            }

            $_SESSION['success'] = 'Reviewer invited successfully.';
        } else {
            $_SESSION['error'] = 'Failed to invite reviewer.';
        }

        $stmt->close();
        redirect_reviews($rfq_id);
    }

    if ($action === 'submit_review') {
        $quotation_id = (int)($_POST['quotation_id'] ?? 0);
        $score        = (int)($_POST['score'] ?? 0);
        $comments     = trim((string)($_POST['comments'] ?? ''));

        if ($quotation_id <= 0 || $score < 1 || $score > 100 || $comments === '') {
            $_SESSION['error'] = 'Quotation, score between 1 and 100, and comments are required.';
            redirect_reviews($rfq_id);
        }

        $check = $conn->prepare("
            SELECT quotation_id
            FROM quotations
            WHERE quotation_id = ? AND rfq_id = ?
            LIMIT 1
        ");
        $check->bind_param("ii", $quotation_id, $rfq_id);
        $check->execute();
        $quoteExists = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$quoteExists) {
            $_SESSION['error'] = 'Quotation not found.';
            redirect_reviews($rfq_id);
        }

        $stmt = $conn->prepare("
            INSERT INTO quotation_reviews
                (rfq_id, quotation_id, reviewer_id, score, comments)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                comments = VALUES(comments),
                reviewed_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("iiiis", $rfq_id, $quotation_id, $currentUserId, $score, $comments);

        if ($stmt->execute()) {
            $up = $conn->prepare("
                UPDATE quotation_reviewers
                SET status = 'Reviewed'
                WHERE rfq_id = ? AND reviewer_id = ?
                LIMIT 1
            ");
            $up->bind_param("ii", $rfq_id, $currentUserId);
            $up->execute();
            $up->close();

            $_SESSION['success'] = 'Review submitted successfully.';
        } else {
            $_SESSION['error'] = 'Failed to submit review.';
        }

        $stmt->close();
        redirect_reviews($rfq_id);
    }

    if ($action === 'award_quotation' && $canAwardQuotation) {
        $quotation_id = (int)($_POST['quotation_id'] ?? 0);

        if ($quotation_id <= 0) {
            $_SESSION['error'] = 'Invalid quotation selected.';
            redirect_reviews($rfq_id);
        }

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT 
                    q.quotation_id,
                    q.supplier_id,
                    q.amount,
                    s.name AS supplier_name,
                    s.email AS supplier_email
                FROM quotations q
                JOIN suppliers s ON s.supplier_id = q.supplier_id
                WHERE q.quotation_id = ? AND q.rfq_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("ii", $quotation_id, $rfq_id);
            $stmt->execute();
            $quotation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$quotation) {
                throw new RuntimeException('Quotation not found.');
            }

            $stmt = $conn->prepare("UPDATE quotations SET status = 'Rejected' WHERE rfq_id = ?");
            $stmt->bind_param("i", $rfq_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE quotations SET status = 'Selected' WHERE quotation_id = ?");
            $stmt->bind_param("i", $quotation_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE rfq SET status = 'Awarded' WHERE rfq_id = ?");
            $stmt->bind_param("i", $rfq_id);
            $stmt->execute();
            $stmt->close();

            $existingPo = null;
            $poCheck = $conn->prepare("
                SELECT po_id, po_number
                FROM purchase_orders
                WHERE procurement_id = ?
                LIMIT 1
            ");
            $poCheck->bind_param("i", $rfq['procurement_id']);
            $poCheck->execute();
            $existingPo = $poCheck->get_result()->fetch_assoc();
            $poCheck->close();

            if ($existingPo) {
                $po_number = $existingPo['po_number'];
            } else {
                $po_number = 'PO-' . date('Ymd') . '-' . str_pad((string)$rfq['procurement_id'], 5, '0', STR_PAD_LEFT);

                $po = $conn->prepare("
                    INSERT INTO purchase_orders
                        (procurement_id, supplier_id, po_number, total_amount, status)
                    VALUES (?, ?, ?, ?, 'Approved')
                ");
                $po->bind_param(
                    "iisd",
                    $rfq['procurement_id'],
                    $quotation['supplier_id'],
                    $po_number,
                    $quotation['amount']
                );
                $po->execute();
                $po->close();
            }

            $stmt = $conn->prepare("
                UPDATE procurement_requests
                SET status = 'PO Created'
                WHERE procurement_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $rfq['procurement_id']);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            send_supplier_award_delivery_email($quotation['supplier_email'] ?? '', [
                'supplier_name' => $quotation['supplier_name'],
                'title'         => $rfq['procurement_title'],
                'po_number'     => $po_number,
                'amount'        => $quotation['amount'],
            ]);

            $_SESSION['success'] = 'Quotation awarded. Selected supplier has been emailed for delivery.';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = $e->getMessage();
        }

        redirect_reviews($rfq_id);
    }
}

$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$staff = [];
if ($canInviteReviewers) {
    $res = $conn->query("
        SELECT user_id, full_name, email, role
        FROM users
        WHERE is_active = 1
          AND role IN ('Administrator','Operations/Admin','Accountant','Procurement Officer','Programs Lead','Program Manager','Program Officer','Staff')
        ORDER BY full_name ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $staff[] = $row;
        }
    }
}

$reviewers = [];
$stmt = $conn->prepare("
    SELECT 
        qr.*,
        u.full_name,
        u.email,
        u.role
    FROM quotation_reviewers qr
    JOIN users u ON u.user_id = qr.reviewer_id
    WHERE qr.rfq_id = ?
    ORDER BY qr.invited_at DESC
");
$stmt->bind_param("i", $rfq_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $reviewers[] = $row;
}
$stmt->close();

$quotations = [];
$stmt = $conn->prepare("
    SELECT 
        q.*,
        s.name AS supplier_name,
        s.email,
        s.phone,
        COALESCE(AVG(rv.score), 0) AS avg_score,
        COUNT(rv.review_id) AS review_count
    FROM quotations q
    JOIN suppliers s ON s.supplier_id = q.supplier_id
    LEFT JOIN quotation_reviews rv ON rv.quotation_id = q.quotation_id
    WHERE q.rfq_id = ?
    GROUP BY q.quotation_id
    ORDER BY avg_score DESC, q.amount ASC
");
$stmt->bind_param("i", $rfq_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $quotations[] = $row;
}
$stmt->close();

$myReviews = [];
$stmt = $conn->prepare("
    SELECT quotation_id, score, comments
    FROM quotation_reviews
    WHERE rfq_id = ? AND reviewer_id = ?
");
$stmt->bind_param("ii", $rfq_id, $currentUserId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $myReviews[(int)$row['quotation_id']] = $row;
}
$stmt->close();

$allReviews = [];
$stmt = $conn->prepare("
    SELECT 
        rv.*,
        u.full_name,
        s.name AS supplier_name
    FROM quotation_reviews rv
    JOIN users u ON u.user_id = rv.reviewer_id
    JOIN quotations q ON q.quotation_id = rv.quotation_id
    JOIN suppliers s ON s.supplier_id = q.supplier_id
    WHERE rv.rfq_id = ?
    ORDER BY rv.reviewed_at DESC
");
$stmt->bind_param("i", $rfq_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $allReviews[] = $row;
}
$stmt->close();
?>

<link rel="stylesheet" href="css/assets.css">



<div class="assets-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-user-check"></i> Quotation Reviews</h1>
            <p><?= e($rfq['procurement_title']) ?> - <?= e($rfq['rfq_number'] ?? 'RFQ') ?></p>
        </div>

        <div class="hero-actions">
            <a href="manage_quotations?rfq_id=<?= (int)$rfq_id ?>" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Quotations
            </a>

            <?php if ($canInviteReviewers): ?>
                <button class="btn btn-primary" onclick="openModal('inviteReviewerModal')">
                    <i class="fas fa-user-plus"></i> Invite Reviewer
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success_msg) ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error_msg) ?></div>
    <?php endif; ?>

    <!-- -- Tab Navigation -------------------------------------- -->
    <nav class="tab-nav" role="tablist">
        <button
            class="tab-btn active"
            role="tab"
            aria-selected="true"
            aria-controls="tab-quotations"
            onclick="switchTab('quotations', this)"
        >
            <i class="fas fa-scale-balanced"></i>
            Review Quotations
            <span class="tab-badge"><?= count($quotations) ?></span>
        </button>

        <?php if ($isManager): ?>
            <button
                class="tab-btn"
                role="tab"
                aria-selected="false"
                aria-controls="tab-reviewers"
                onclick="switchTab('reviewers', this)"
            >
                <i class="fas fa-users"></i>
                Invited Reviewers
                <span class="tab-badge"><?= count($reviewers) ?></span>
            </button>

            <button
                class="tab-btn"
                role="tab"
                aria-selected="false"
                aria-controls="tab-comments"
                onclick="switchTab('comments', this)"
            >
                <i class="fas fa-comments"></i>
                All Comments
                <span class="tab-badge"><?= count($allReviews) ?></span>
            </button>
        <?php endif; ?>
    </nav>

    <!-- -- Tab: Review Quotations ------------------------------ -->
    <div id="tab-quotations" class="tab-panel active" role="tabpanel">

        <?php
        // Determine the top-scored quotation (must have at least 1 review)
        $topQuote = null;
        foreach ($quotations as $q) {
            if ((int)$q['review_count'] > 0) {
                if ($topQuote === null || (float)$q['avg_score'] > (float)$topQuote['avg_score']) {
                    $topQuote = $q;
                }
            }
        }
        ?>

        <?php if ($topQuote): ?>
            <div class="top-scorer-banner">
                <div class="trophy"><i class="fas fa-trophy"></i></div>
                <div class="banner-body">
                    <div class="banner-label">Top Scored Supplier</div>
                    <div class="banner-name"><?= e($topQuote['supplier_name']) ?></div>
                    <div class="banner-meta">
                        UGX <?= number_format((float)$topQuote['amount']) ?>
                        &nbsp;.&nbsp;
                        <?= number_format((int)$topQuote['delivery_days']) ?> day delivery
                        &nbsp;.&nbsp;
                        <?= number_format((int)$topQuote['review_count']) ?> review(s)
                    </div>
                </div>
                <div class="banner-score">
                    <div class="score-value"><?= number_format((float)$topQuote['avg_score'], 1) ?></div>
                    <div class="score-label">avg score / 100</div>
                </div>
            </div>
        <?php elseif (!empty($quotations)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No reviews submitted yet - the top scorer will appear automatically once reviewers submit scores.
            </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-body table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Amount</th>
                        <th>Delivery Days</th>
                        <th>Attachment</th>
                        <th>Average Score</th>
                        <th>My Review</th>
                        <?php if ($canAwardQuotation): ?><th>Award</th><?php endif; ?>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($quotations)): ?>
                        <tr><td colspan="<?= $canAwardQuotation ? 7 : 6 ?>">No quotations found.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($quotations as $quote): ?>
                        <?php
                            $mine   = $myReviews[(int)$quote['quotation_id']] ?? null;
                            $isBest = $topQuote && (int)$quote['quotation_id'] === (int)$topQuote['quotation_id'];
                        ?>
                        <tr<?= $isBest ? ' class="best-quotation"' : '' ?>>
                            <td>
                                <strong><?= e($quote['supplier_name']) ?></strong><br>
                                <span class="note"><?= e($quote['email'] ?: $quote['phone'] ?: '-') ?></span>
                                <?php if ($isBest): ?>
                                    <div class="best-tag"><i class="fas fa-trophy"></i> Top Scorer</div>
                                <?php endif; ?>
                            </td>

                            <td>UGX <?= number_format((float)$quote['amount']) ?></td>
                            <td><?= number_format((int)$quote['delivery_days']) ?> days</td>

                            <td>
                                <?php if (!empty($quote['attachment'])): ?>
                                    <a href="<?= e(ims_upload_url($quote['attachment'])) ?>" target="_blank" class="btn btn-sm btn-soft">
                                        <i class="fas fa-file-alt"></i> View
                                    </a>
                                <?php else: ?>
                                    <span class="note">No file</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <strong><?= number_format((float)$quote['avg_score'], 1) ?>%</strong><br>
                                <span class="note"><?= number_format((int)$quote['review_count']) ?> review(s)</span>
                            </td>

                            <td>
                                <form method="POST">
                                    <input type="hidden" name="action" value="submit_review">
                                    <input type="hidden" name="quotation_id" value="<?= (int)$quote['quotation_id'] ?>">

                                    <input
                                        type="number"
                                        name="score"
                                        min="1"
                                        max="100"
                                        class="form-control"
                                        placeholder="Score /100"
                                        value="<?= e($mine['score'] ?? '') ?>"
                                        required
                                    >

                                    <textarea
                                        name="comments"
                                        class="form-control"
                                        placeholder="Reviewer comments..."
                                        required
                                    ><?= e($mine['comments'] ?? '') ?></textarea>

                                    <button class="btn btn-sm btn-dark">
                                        <i class="fas fa-save"></i> Save Review
                                    </button>
                                </form>
                            </td>

                            <?php if ($canAwardQuotation): ?>
                                <td>
                                    <?php if ($quote['status'] === 'Selected'): ?>
                                        <span class="badge badge-issued">Selected</span>
                                    <?php else: ?>
                                        <form method="POST" onsubmit="return confirm('Award this supplier and email them for delivery?');">
                                            <input type="hidden" name="action" value="award_quotation">
                                            <input type="hidden" name="quotation_id" value="<?= (int)$quote['quotation_id'] ?>">
                                            <button class="btn btn-sm btn-green">
                                                <i class="fas fa-check-circle"></i> Award
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($isManager): ?>

    <!-- -- Tab: Invited Reviewers ------------------------------ -->
    <div id="tab-reviewers" class="tab-panel" role="tabpanel">
        <div class="panel">
            <div class="panel-body table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Reviewer</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Invited</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($reviewers)): ?>
                        <tr><td colspan="5">No reviewers invited yet.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($reviewers as $reviewer): ?>
                        <tr>
                            <td><?= e($reviewer['full_name']) ?></td>
                            <td><?= e($reviewer['email']) ?></td>
                            <td><?= e($reviewer['role']) ?></td>
                            <td><?= e($reviewer['status']) ?></td>
                            <td><?= date('d M Y H:i', strtotime($reviewer['invited_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- -- Tab: All Review Comments ---------------------------- -->
    <div id="tab-comments" class="tab-panel" role="tabpanel">
        <div class="panel">
            <div class="panel-body table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Reviewer</th>
                        <th>Supplier</th>
                        <th>Score</th>
                        <th>Comments</th>
                        <th>Reviewed</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($allReviews)): ?>
                        <tr><td colspan="5">No review comments yet.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($allReviews as $review): ?>
                        <tr>
                            <td><?= e($review['full_name']) ?></td>
                            <td><?= e($review['supplier_name']) ?></td>
                            <td><?= number_format((int)$review['score']) ?>%</td>
                            <td><?= nl2br(e($review['comments'])) ?></td>
                            <td><?= date('d M Y H:i', strtotime($review['reviewed_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div>

<?php if ($canInviteReviewers): ?>
<div class="modal" id="inviteReviewerModal">
    <div class="modal-card modal-sm">
        <div class="modal-head">
            <h3>Invite Staff Reviewer</h3>
            <button class="modal-close" onclick="closeModal('inviteReviewerModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="invite_reviewer">

                <div class="form-group full">
                    <label class="form-label">Staff Reviewer</label>
                    <select name="reviewer_id" class="form-control" required>
                        <option value="">Select staff</option>
                        <?php foreach ($staff as $member): ?>
                            <option value="<?= (int)$member['user_id'] ?>">
                                <?= e($member['full_name'] . ' - ' . $member['role']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('inviteReviewerModal')">Cancel</button>
                    <button class="btn btn-dark">
                        <i class="fas fa-paper-plane"></i> Send Invite
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

window.addEventListener('click', function (e) {
    document.querySelectorAll('.modal.show').forEach(function (modal) {
        if (e.target === modal) modal.classList.remove('show');
    });
});

function switchTab(name, btn) {
    // Hide all panels
    document.querySelectorAll('.tab-panel').forEach(function (p) {
        p.classList.remove('active');
    });

    // Deactivate all tab buttons
    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });

    // Activate the selected panel and button
    var panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');

    btn.classList.add('active');
    btn.setAttribute('aria-selected', 'true');
}
</script>

<?php require_once 'includes/footer.php'; ?>