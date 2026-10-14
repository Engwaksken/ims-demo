<?php
declare(strict_types=1);

$page_title = 'Booking Details';

include 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

if (
    !isset($conn)
    ||
    !($conn instanceof mysqli)
) {
    die('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

function vb_e(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function vb_col(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $result = $conn->query(
        "SHOW COLUMNS FROM `"
        . $conn->real_escape_string($table)
        . "` LIKE '"
        . $conn->real_escape_string($column)
        . "'"
    );

    return $result instanceof mysqli_result
        &&
        $result->num_rows > 0;
}

$bookingId = max(
    0,
    (int)(
        $_GET['id']
        ?? 0
    )
);

if ($bookingId <= 0) {
    $_SESSION['error'] =
        'Booking ID not provided.';

    header(
        'Location: my-bookings'
    );

    exit;
}

$isAdmin =
    ims_allowed('bookings.view_all', ['Administrator', 'Operations/Admin'], 'See all bookings (not just their own)');

$memberId = null;

if (!$isAdmin) {
    $stmt = $conn->prepare("
        SELECT member_id
        FROM members
        WHERE user_id = ?
        LIMIT 1
    ");

    $userId =
        (int)$_SESSION['user_id'];

    $stmt->bind_param(
        'i',
        $userId
    );

    $stmt->execute();

    $memberRow =
        $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    $memberId =
        $memberRow
        ? (int)$memberRow['member_id']
        : 0;
}

$rejectedSelect =
    vb_col(
        $conn,
        'space_bookings',
        'rejected_by'
    )
        ? 'rejector.full_name AS rejected_by_name'
        : 'NULL AS rejected_by_name';

$rejectedJoin =
    vb_col(
        $conn,
        'space_bookings',
        'rejected_by'
    )
        ? 'LEFT JOIN users rejector ON sb.rejected_by = rejector.user_id'
        : '';

$stmt =
    $conn->prepare("
        SELECT
            sb.*,
            m.membership_number,
            u.full_name AS member_name,
            u.email AS member_email,
            u.phone AS member_phone,
            approver.full_name AS approved_by_name,
            canceller.full_name AS cancelled_by_name,
            {$rejectedSelect}
        FROM space_bookings sb
        LEFT JOIN members m
            ON sb.member_id = m.member_id
        LEFT JOIN users u
            ON m.user_id = u.user_id
        LEFT JOIN users approver
            ON sb.confirmed_by = approver.user_id
        LEFT JOIN users canceller
            ON sb.cancelled_by = canceller.user_id
        {$rejectedJoin}
        WHERE sb.booking_id = ?
        LIMIT 1
    ");

$stmt->bind_param(
    'i',
    $bookingId
);

$stmt->execute();

$booking =
    $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$booking) {
    $_SESSION['error'] =
        'Booking not found.';

    header(
        $isAdmin
            ? 'Location: manage-bookings'
            : 'Location: my-bookings'
    );

    exit;
}

if (
    !$isAdmin
    &&
    (int)$booking['member_id']
    !==
    (int)$memberId
) {
    $_SESSION['error'] =
        "You don't have permission to view this booking.";

    header(
        'Location: my-bookings'
    );

    exit;
}

$returnUrl =
    $isAdmin
        ? 'manage-bookings'
        : 'my-bookings';

$status =
    (string)(
        $booking['booking_status']
        ?? 'Pending'
    );
?>

<style>
.vb-overlay{min-height:calc(100vh - 120px);display:flex;align-items:center;justify-content:center;padding:20px;background:linear-gradient(180deg,#f8fafc,#eef2f7)}
.vb-modal-card{width:min(100%,850px);background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 25px 70px rgba(15,23,42,.18);overflow:hidden}
.vb-head{padding:16px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.vb-head h2{margin:0;color:#0f172a;font-size:17px}.vb-head p{margin:4px 0 0;color:#64748b;font-size:10px}
.vb-close{display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:8px;background:#f1f5f9;color:#475569;text-decoration:none;font-size:18px}
.vb-body{padding:18px}.vb-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:15px}
.vb-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px}.vb-box small{display:block;color:#64748b;font-size:8px;text-transform:uppercase;font-weight:800;margin-bottom:4px}.vb-box strong,.vb-box div{font-size:10.5px;color:#0f172a;word-break:break-word}
.vb-section{margin-top:14px}.vb-section h3{margin:0 0 8px;font-size:11px;color:#0f172a}.vb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.vb-grid .full{grid-column:1/-1}
.vb-status{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:8.5px;font-weight:800}.vb-status.Pending{background:#fff7ed;color:#9a3412}.vb-status.Confirmed{background:#ecfdf5;color:#166534}.vb-status.Rejected,.vb-status.Cancelled{background:#fef2f2;color:#991b1b}.vb-status.Completed{background:#eff6ff;color:#1d4ed8}
.vb-actions{padding:12px 18px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:7px;flex-wrap:wrap}
@media(max-width:700px){.vb-summary,.vb-grid{grid-template-columns:1fr}.vb-grid .full{grid-column:auto}}
</style>

<div class="vb-overlay">
    <div class="vb-modal-card">
        <div class="vb-head">
            <div>
                <h2>
                    <i class="fas fa-calendar-check"></i>
                    Booking #<?= (int)$booking['booking_id'] ?>
                    .
                    <?= vb_e($booking['space_name'] ?? '') ?>
                </h2>

                <p>
                    Full booking information and approval history
                </p>
            </div>

            <a
                href="<?= vb_e($returnUrl) ?>"
                class="vb-close"
                title="Close"
            >
                &times;
            </a>
        </div>

        <div class="vb-body">

            <div class="vb-summary">
                <div class="vb-box">
                    <small>Status</small>
                    <span class="vb-status <?= vb_e($status) ?>">
                        <?= vb_e($status) ?>
                    </span>
                </div>

                <div class="vb-box">
                    <small>Date</small>
                    <strong>
                        <?= !empty($booking['booking_date'])
                            ? date(
                                'd M Y',
                                strtotime((string)$booking['booking_date'])
                            )
                            : '-' ?>
                    </strong>
                </div>

                <div class="vb-box">
                    <small>Time</small>
                    <strong>
                        <?= !empty($booking['start_time'])
                            ? date(
                                'h:i A',
                                strtotime((string)$booking['start_time'])
                            )
                            : '-' ?>
                        -
                        <?= !empty($booking['end_time'])
                            ? date(
                                'h:i A',
                                strtotime((string)$booking['end_time'])
                            )
                            : '-' ?>
                    </strong>
                </div>

                <div class="vb-box">
                    <small>Amount</small>
                    <strong>
                        UGX
                        <?= number_format(
                            (float)(
                                $booking['booking_amount']
                                ?? 0
                            )
                        ) ?>
                    </strong>
                </div>
            </div>

            <div class="vb-section">
                <h3>
                    <i class="fas fa-door-open"></i>
                    Booking Information
                </h3>

                <div class="vb-grid">
                    <div class="vb-box">
                        <small>Space</small>
                        <strong>
                            <?= vb_e($booking['space_name'] ?? '') ?>
                        </strong>
                        <div>
                            <?= vb_e($booking['space_type'] ?? '') ?>
                        </div>
                    </div>

                    <div class="vb-box">
                        <small>Attendees</small>
                        <strong>
                            <?= (int)(
                                $booking['number_of_attendees']
                                ?? 0
                            ) ?>
                        </strong>
                    </div>

                    <div class="vb-box full">
                        <small>Purpose</small>
                        <div>
                            <?= nl2br(
                                vb_e(
                                    $booking['purpose']
                                    ?? '-'
                                )
                            ) ?>
                        </div>
                    </div>

                    <?php if (!empty($booking['notes'])): ?>
                        <div class="vb-box full">
                            <small>Notes</small>
                            <div>
                                <?= nl2br(
                                    vb_e($booking['notes'])
                                ) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="vb-section">
                <h3>
                    <i class="fas fa-user"></i>
                    Member Information
                </h3>

                <div class="vb-grid">
                    <div class="vb-box">
                        <small>Name</small>
                        <strong>
                            <?= vb_e($booking['member_name'] ?? '') ?>
                        </strong>
                    </div>

                    <div class="vb-box">
                        <small>Membership Number</small>
                        <strong>
                            <?= vb_e($booking['membership_number'] ?? '') ?>
                        </strong>
                    </div>

                    <div class="vb-box">
                        <small>Email</small>
                        <div>
                            <?= vb_e($booking['member_email'] ?? '') ?>
                        </div>
                    </div>

                    <div class="vb-box">
                        <small>Phone</small>
                        <div>
                            <?= vb_e($booking['member_phone'] ?? '-') ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="vb-section">
                <h3>
                    <i class="fas fa-route"></i>
                    Workflow
                </h3>

                <div class="vb-grid">
                    <div class="vb-box">
                        <small>Created</small>
                        <strong>
                            <?= !empty($booking['created_at'])
                                ? date(
                                    'd M Y, h:i A',
                                    strtotime((string)$booking['created_at'])
                                )
                                : '-' ?>
                        </strong>
                    </div>

                    <div class="vb-box">
                        <small>Payment Status</small>
                        <strong>
                            <?= vb_e($booking['payment_status'] ?? 'Pending') ?>
                        </strong>
                    </div>

                    <?php if (!empty($booking['approved_by_name'])): ?>
                        <div class="vb-box">
                            <small>Approved By</small>
                            <strong>
                                <?= vb_e($booking['approved_by_name']) ?>
                            </strong>
                            <div>
                                <?= !empty($booking['confirmed_at'])
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime((string)$booking['confirmed_at'])
                                    )
                                    : '' ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($booking['rejection_reason'])): ?>
                        <div
                            class="vb-box full"
                            style="border-left:4px solid #dc2626;"
                        >
                            <small>Rejection Reason</small>
                            <div>
                                <?= nl2br(
                                    vb_e($booking['rejection_reason'])
                                ) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($booking['cancellation_reason'])): ?>
                        <div
                            class="vb-box full"
                            style="border-left:4px solid #dc2626;"
                        >
                            <small>Cancellation Reason</small>
                            <div>
                                <?= nl2br(
                                    vb_e($booking['cancellation_reason'])
                                ) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="vb-actions">
            <?php if (!empty($booking['member_email'])): ?>
                <a
                    href="mailto:<?= vb_e($booking['member_email']) ?>"
                    class="btn btn-secondary"
                >
                    <i class="fas fa-envelope"></i>
                    Email Member
                </a>
            <?php endif; ?>

            <a
                href="<?= vb_e($returnUrl) ?>"
                class="btn btn-gray"
            >
                Close
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
