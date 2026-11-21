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

<?php
$vbStatusClass = [
    'Pending'   => 'status-pending',
    'Confirmed' => 'status-approved',
    'Completed' => 'status-completed',
    'Rejected'  => 'status-rejected',
    'Cancelled' => 'status-cancelled',
][$status] ?? 'status-draft';
?>

<style>
/* View booking: label/value field grid inside each section card. */
.vb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--space-3)}
.vb-grid .full{grid-column:1/-1}
.vb-field{background:var(--ink-50);border:1px solid var(--ink-100);border-radius:var(--radius-md);padding:var(--space-3)}
.vb-field.is-danger{border-left:4px solid var(--red-fg)}
.vb-field small{display:block;color:var(--ink-300);font-size:var(--fs-xs);text-transform:uppercase;font-weight:700;margin-bottom:4px}
.vb-field strong,.vb-field div{color:var(--ink-900);word-break:break-word}
.vb-stat-text{font-size:var(--fs-md)}
@media(max-width:700px){.vb-grid{grid-template-columns:1fr}.vb-grid .full{grid-column:auto}}
</style>

<div class="page-stack">
    <div class="page-hero">
        <div>
            <h1>
                <i class="fas fa-calendar-check" aria-hidden="true"></i>
                Booking #<?= (int)$booking['booking_id'] ?>
                &middot;
                <?= vb_e($booking['space_name'] ?? '') ?>
            </h1>
            <p>Full booking information and approval history.</p>
        </div>

        <div class="hero-actions">
            <a href="<?= vb_e($returnUrl) ?>" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                Back
            </a>

            <?php if (!empty($booking['member_email'])): ?>
                <a
                    href="mailto:<?= vb_e($booking['member_email']) ?>"
                    class="btn btn-primary"
                >
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    Email Member
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-flag" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Status</div>
                <div class="stat-value">
                    <span class="status-pill <?= vb_e($vbStatusClass) ?>">
                        <?= vb_e($status) ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Date</div>
                <div class="stat-value vb-stat-text">
                    <?= !empty($booking['booking_date'])
                        ? date(
                            'd M Y',
                            strtotime((string)$booking['booking_date'])
                        )
                        : '-' ?>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fas fa-clock" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Time</div>
                <div class="stat-value vb-stat-text">
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
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Amount</div>
                <div class="stat-value vb-stat-text">
                    UGX
                    <?= number_format(
                        (float)(
                            $booking['booking_amount']
                            ?? 0
                        )
                    ) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-shell vb-tabs" data-vb-tabs>
        <div class="tab-nav" role="tablist" aria-label="Booking details">
            <button type="button" class="tab-btn active" role="tab" id="vb-tab-booking" aria-controls="vb-booking" aria-selected="true" data-tab="booking"><i class="fas fa-door-open" aria-hidden="true"></i> Booking Information</button>
            <button type="button" class="tab-btn" role="tab" id="vb-tab-member" aria-controls="vb-member" aria-selected="false" tabindex="-1" data-tab="member"><i class="fas fa-user" aria-hidden="true"></i> Member Information</button>
            <button type="button" class="tab-btn" role="tab" id="vb-tab-workflow" aria-controls="vb-workflow" aria-selected="false" tabindex="-1" data-tab="workflow"><i class="fas fa-route" aria-hidden="true"></i> Workflow</button>
        </div>
        <div class="tab-panels">
        <div class="tab-panel active" role="tabpanel" id="vb-booking" aria-labelledby="vb-tab-booking">
            <div class="vb-grid">
                <div class="vb-field">
                    <small>Space</small>
                    <strong>
                        <?= vb_e($booking['space_name'] ?? '') ?>
                    </strong>
                    <div class="cell-muted">
                        <?= vb_e($booking['space_type'] ?? '') ?>
                    </div>
                </div>

                <div class="vb-field">
                    <small>Attendees</small>
                    <strong>
                        <?= (int)(
                            $booking['number_of_attendees']
                            ?? 0
                        ) ?>
                    </strong>
                </div>

                <div class="vb-field full">
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
                    <div class="vb-field full">
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

        <div class="tab-panel" role="tabpanel" id="vb-member" aria-labelledby="vb-tab-member" hidden>
            <div class="vb-grid">
                <div class="vb-field">
                    <small>Name</small>
                    <strong>
                        <?= vb_e($booking['member_name'] ?? '') ?>
                    </strong>
                </div>

                <div class="vb-field">
                    <small>Membership Number</small>
                    <strong>
                        <?= vb_e($booking['membership_number'] ?? '') ?>
                    </strong>
                </div>

                <div class="vb-field">
                    <small>Email</small>
                    <div>
                        <?= vb_e($booking['member_email'] ?? '') ?>
                    </div>
                </div>

                <div class="vb-field">
                    <small>Phone</small>
                    <div>
                        <?= vb_e($booking['member_phone'] ?? '-') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-panel" role="tabpanel" id="vb-workflow" aria-labelledby="vb-tab-workflow" hidden>
            <div class="vb-grid">
                <div class="vb-field">
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

                <div class="vb-field">
                    <small>Payment Status</small>
                    <strong>
                        <?= vb_e($booking['payment_status'] ?? 'Pending') ?>
                    </strong>
                </div>

                <?php if (!empty($booking['approved_by_name'])): ?>
                    <div class="vb-field">
                        <small>Approved By</small>
                        <strong>
                            <?= vb_e($booking['approved_by_name']) ?>
                        </strong>
                        <div class="cell-muted">
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
                    <div class="vb-field full is-danger">
                        <small>Rejection Reason</small>
                        <div>
                            <?= nl2br(
                                vb_e($booking['rejection_reason'])
                            ) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($booking['cancellation_reason'])): ?>
                    <div class="vb-field full is-danger">
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
        </div><!-- /.tab-panels -->
    </div><!-- /.tab-shell -->
</div><!-- /.page-stack -->

<script>
/* Booking / Member / Workflow tabs; ?tab= keeps the open tab on reload. */
(function () {
    var shell = document.querySelector('[data-vb-tabs]');
    if (!shell) return;
    var tabs = Array.prototype.slice.call(shell.querySelectorAll('[role="tab"]'));
    function show(name, focus) {
        tabs.forEach(function (t) {
            var on = t.dataset.tab === name;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            var panel = document.getElementById(t.getAttribute('aria-controls'));
            panel.classList.toggle('active', on);
            panel.hidden = !on;
            if (on && focus) t.focus();
        });
        try {
            var url = new URL(location.href);
            if (name === 'booking') url.searchParams.delete('tab'); else url.searchParams.set('tab', name);
            history.replaceState(null, '', url);
        } catch (e) {}
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { show(t.dataset.tab, false); });
        t.addEventListener('keydown', function (e) {
            var next = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
            if (next === null) return;
            e.preventDefault();
            show(tabs[(next + tabs.length) % tabs.length].dataset.tab, true);
        });
    });
    var start = new URLSearchParams(location.search).get('tab');
    if (start && tabs.some(function (t) { return t.dataset.tab === start; })) show(start, false);
})();
</script>

<?php include 'includes/footer.php'; ?>
