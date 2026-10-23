<?php
declare(strict_types=1);

$page_title = 'Hub Space Booking';

include 'includes/header.php';

require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('hub_book_e')) {
    function hub_book_e(mixed $value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        m.*,
        u.full_name,
        u.email
    FROM members m
    INNER JOIN users u
        ON u.user_id = m.user_id
    WHERE m.user_id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$member =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$member) {
    echo '
        <div class="alert alert-danger">
            Only registered Hive Colab members can access space booking.
        </div>
    ';

    include 'includes/footer.php';
    exit;
}

$isActive =
    strcasecmp(
        (string)(
            $member['membership_status']
            ?? ''
        ),
        'Active'
    )
    === 0;

/*
 * Space list:
 * 1. use configured hub_spaces/spaces table when available;
 * 2. otherwise reuse distinct spaces already known by bookings;
 * 3. ensure the common Hive space types remain available.
 */
$spaces = [];

$tableResult =
    $conn->query(
        "SHOW TABLES LIKE 'hub_spaces'"
    );

if (
    $tableResult
    &&
    $tableResult->num_rows > 0
) {
    $result = $conn->query("
        SELECT
            space_name,
            space_type
        FROM hub_spaces
        WHERE COALESCE(is_active, 1) = 1
        ORDER BY space_type, space_name
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $spaces[] = $row;
        }
    }
}

if (!$spaces) {
    $result = $conn->query("
        SELECT DISTINCT
            space_type,
            space_name
        FROM space_bookings
        WHERE space_type IS NOT NULL
          AND TRIM(space_type) <> ''
          AND space_name IS NOT NULL
          AND TRIM(space_name) <> ''
        ORDER BY space_type, space_name
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $spaces[] = $row;
        }
    }
}

if (!$spaces) {
    $spaces = [
        [
            'space_type' => 'Boardroom',
            'space_name' => 'Boardroom',
        ],
        [
            'space_type' => 'Meeting Room',
            'space_name' => 'Meeting Room',
        ],
        [
            'space_type' => 'Event Space',
            'space_name' => 'Event Space',
        ],
        [
            'space_type' => 'Private Office',
            'space_name' => 'Private Office',
        ],
        [
            'space_type' => 'Hot Desk',
            'space_name' => 'Hot Desk',
        ],
    ];
}

$myBookings = [];

$stmt = $conn->prepare("
    SELECT *
    FROM space_bookings
    WHERE member_id = ?
    ORDER BY
        booking_date DESC,
        start_time DESC
    LIMIT 100
");

$stmt->bind_param(
    'i',
    $member['member_id']
);

$stmt->execute();

$result =
    $stmt->get_result();

while (
    $row =
        $result->fetch_assoc()
) {
    $myBookings[] = $row;
}

$stmt->close();

$pendingCount = 0;
$confirmedCount = 0;

foreach ($myBookings as $booking) {
    if (
        ($booking['booking_status'] ?? '')
        === 'Pending'
    ) {
        $pendingCount++;
    }

    if (
        ($booking['booking_status'] ?? '')
        === 'Confirmed'
    ) {
        $confirmedCount++;
    }
}

$activeTab =
    (string)(
        $_GET['tab']
        ?? 'book'
    );

if (
    !in_array(
        $activeTab,
        [
            'book',
            'my-bookings',
        ],
        true
    )
) {
    $activeTab = 'book';
}
?>

<style>
/* Hub space booking: two-column request form and occupied-slot pills. */
.hb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--space-3)}
.hb-grid .full{grid-column:1/-1}
.hb-slots{margin-top:var(--space-2);display:flex;gap:6px;flex-wrap:wrap}
.hb-slot{padding:5px 10px;border-radius:var(--radius-pill);background:var(--red-bg);color:var(--red-fg);border:1px solid var(--red-border);font-size:var(--fs-xs);font-weight:700}
.hb-slot.hb-free{color:var(--green-fg);background:var(--green-bg);border-color:var(--green-border)}
@media(max-width:800px){.hb-grid{grid-template-columns:1fr}.hb-grid .full{grid-column:auto}}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1>
            <i class="fas fa-calendar-check" aria-hidden="true"></i>
            Hub Space Booking
        </h1>
        <p>
            Internal Hive Colab members can request only free dates and time slots.
            Operations/Admin reviews every request.
        </p>
    </div>

    <div class="hero-actions">
        <span class="badge badge-warning">
            <i class="fas fa-id-card" aria-hidden="true"></i>
            <?= hub_book_e($member['membership_number'] ?? 'Member') ?>
            ·
            <?= hub_book_e($member['membership_status'] ?? '') ?>
        </span>
    </div>
</div>

<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">My Requests</span>
            <span class="stat-value"><?= count($myBookings) ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-hourglass-half" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending Approval</span>
            <span class="stat-value"><?= $pendingCount ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Confirmed</span>
            <span class="stat-value"><?= $confirmedCount ?></span>
        </div>
    </div>
</div>

<div class="card">
<nav class="tab-nav" aria-label="Space booking sections">
    <a
        href="hub-events?tab=book"
        class="tab-btn <?= $activeTab === 'book' ? 'active' : '' ?>"
    >
        <i class="fas fa-plus-circle" aria-hidden="true"></i>
        Request Booking
    </a>

    <a
        href="hub-events?tab=my-bookings"
        class="tab-btn <?= $activeTab === 'my-bookings' ? 'active' : '' ?>"
    >
        <i class="fas fa-list" aria-hidden="true"></i>
        My Booking Requests
    </a>
</nav>

<?php if (!$isActive): ?>
    <div class="card-body">
        <div class="alert alert-danger">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            Your membership is not Active. Please contact Hub Operations before requesting a space.
        </div>
    </div>
<?php elseif ($activeTab === 'book'): ?>

    <div class="card-header">
        <h3>
            <i class="fas fa-calendar-plus" aria-hidden="true"></i>
            Request a Free Slot
        </h3>

        <span class="cell-muted">
            Pending requests reserve the slot until Operations decides.
        </span>
    </div>

    <div class="card-body">

        <div class="alert alert-info">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            Select a space, date and time. Existing Pending/Confirmed bookings are blocked.
            Hive Google Calendar events that use the same space/location are also blocked.
        </div>

        <form
            method="POST"
            action="includes/hub-events-process.php"
            id="hubBookingForm"
        >
            <div class="hb-grid">

                <div>
                    <label class="form-label required">
                        Space
                    </label>

                    <select
                        id="spaceSelect"
                        class="form-control"
                        required
                    >
                        <option value="">
                            Select a space...
                        </option>

                        <?php foreach ($spaces as $space): ?>
                            <option
                                value="<?= hub_book_e(
                                    ($space['space_type'] ?? '')
                                    . '||'
                                    . ($space['space_name'] ?? '')
                                ) ?>"
                            >
                                <?= hub_book_e($space['space_type'] ?? '') ?>
                                -
                                <?= hub_book_e($space['space_name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input
                        type="hidden"
                        name="space_type"
                        id="spaceType"
                    >

                    <input
                        type="hidden"
                        name="space_name"
                        id="spaceName"
                    >
                </div>

                <div>
                    <label class="form-label required">
                        Booking Date
                    </label>

                    <input
                        type="date"
                        name="booking_date"
                        id="bookingDate"
                        class="form-control"
                        min="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <div>
                    <label class="form-label required">
                        Start Time
                    </label>

                    <input
                        type="time"
                        name="start_time"
                        id="startTime"
                        class="form-control"
                        required
                    >
                </div>

                <div>
                    <label class="form-label required">
                        End Time
                    </label>

                    <input
                        type="time"
                        name="end_time"
                        id="endTime"
                        class="form-control"
                        required
                    >
                </div>

                <div class="full">
                    <label class="form-label">
                        Occupied Slots
                    </label>

                    <div
                        id="occupiedSlots"
                        class="hb-slots"
                    >
                        <span
                            class="hb-slot hb-free"
                        >
                            Select a space and date to check availability.
                        </span>
                    </div>
                </div>

                <div class="full">
                    <label class="form-label required">
                        Purpose of Booking
                    </label>

                    <input
                        type="text"
                        name="purpose"
                        class="form-control"
                        placeholder="e.g. Team meeting, client session, workshop"
                        required
                    >
                </div>

                <div>
                    <label class="form-label">
                        Number of Attendees
                    </label>

                    <input
                        type="number"
                        name="number_of_attendees"
                        class="form-control"
                        min="1"
                        value="1"
                    >
                </div>

                <div>
                    <label class="form-label">
                        Additional Notes
                    </label>

                    <input
                        type="text"
                        name="notes"
                        class="form-control"
                        placeholder="Optional"
                    >
                </div>

                <div class="full">
                    <button
                        type="submit"
                        name="request_booking"
                        class="btn btn-primary"
                    >
                        <i class="fas fa-paper-plane"></i>
                        Submit Booking Request
                    </button>
                </div>

            </div>
        </form>
    </div>

<?php else: ?>

    <div class="card-header">
        <h3>
            <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
            My Booking Requests
        </h3>
    </div>

    <div class="table-responsive">
        <table class="table table-stack">
            <thead>
                <tr>
                    <th>Space</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Purpose</th>
                    <th>Status</th>
                    <th>Decision</th>
                </tr>
            </thead>
            <tbody>

            <?php if (!$myBookings): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="fas fa-calendar-xmark" aria-hidden="true"></i>
                            <p>You have not submitted a booking request yet.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($myBookings as $booking): ?>
                <?php
                $bookingStatus =
                    (string)(
                        $booking['booking_status']
                        ?? 'Pending'
                    );

                $bookingBadge = match ($bookingStatus) {
                    'Confirmed' => 'badge-success',
                    'Rejected', 'Cancelled' => 'badge-danger',
                    'Pending' => 'badge-warning',
                    default => 'badge-secondary',
                };
                ?>
                <tr>
                    <td data-label="Space">
                        <strong>
                            <?= hub_book_e($booking['space_name'] ?? '') ?>
                        </strong>
                        <br>
                        <small>
                            <?= hub_book_e($booking['space_type'] ?? '') ?>
                        </small>
                    </td>

                    <td data-label="Date">
                        <?= !empty($booking['booking_date'])
                            ? date(
                                'd M Y',
                                strtotime((string)$booking['booking_date'])
                            )
                            : '—' ?>
                    </td>

                    <td data-label="Time">
                        <?= !empty($booking['start_time'])
                            ? date(
                                'h:i A',
                                strtotime((string)$booking['start_time'])
                            )
                            : '—' ?>
                        -
                        <?= !empty($booking['end_time'])
                            ? date(
                                'h:i A',
                                strtotime((string)$booking['end_time'])
                            )
                            : '—' ?>
                    </td>

                    <td data-label="Purpose">
                        <?= hub_book_e(
                            $booking['purpose']
                            ?? '—'
                        ) ?>
                    </td>

                    <td data-label="Status">
                        <span class="badge <?= $bookingBadge ?>">
                            <?= hub_book_e($bookingStatus) ?>
                        </span>
                    </td>

                    <td data-label="Decision">
                        <?php if (
                            $bookingStatus === 'Rejected'
                            &&
                            !empty($booking['rejection_reason'])
                        ): ?>
                            <?= hub_book_e($booking['rejection_reason']) ?>
                        <?php elseif (
                            $bookingStatus === 'Confirmed'
                        ): ?>
                            Approved by Operations
                        <?php elseif (
                            $bookingStatus === 'Pending'
                        ): ?>
                            Awaiting review
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
    </div>

<?php endif; ?>
</div>
</div><!-- /.page-stack -->

<script>
const spaceSelect =
    document.getElementById(
        'spaceSelect'
    );

const bookingDate =
    document.getElementById(
        'bookingDate'
    );

async function loadAvailability() {
    if (
        !spaceSelect
        ||
        !bookingDate
        ||
        !spaceSelect.value
        ||
        !bookingDate.value
    ) {
        return;
    }

    const [
        spaceType,
        spaceName
    ] =
        spaceSelect.value.split(
            '||'
        );

    document
        .getElementById(
            'spaceType'
        )
        .value =
        spaceType || '';

    document
        .getElementById(
            'spaceName'
        )
        .value =
        spaceName || '';

    const target =
        document.getElementById(
            'occupiedSlots'
        );

    target.innerHTML =
        '<span class="hb-slot hb-free">Checking...</span>';

    try {
        const url =
            'includes/hub-events-process.php'
            + '?action=availability'
            + '&space_type='
            + encodeURIComponent(
                spaceType
            )
            + '&space_name='
            + encodeURIComponent(
                spaceName
            )
            + '&booking_date='
            + encodeURIComponent(
                bookingDate.value
            );

        const response =
            await fetch(
                url,
                {
                    credentials:
                        'same-origin',
                }
            );

        const data =
            await response.json();

        if (
            !data.success
            ||
            !Array.isArray(
                data.slots
            )
        ) {
            throw new Error(
                data.message
                ||
                'Availability unavailable.'
            );
        }

        if (
            data.slots.length
            ===
            0
        ) {
            target.innerHTML =
                '<span class="hb-slot hb-free">No IMS bookings on this date — choose your preferred time.</span>';

            return;
        }

        target.innerHTML =
            data.slots
            .map(
                slot =>
                    '<span class="hb-slot">'
                    + String(
                        slot.start_time
                        || ''
                    ).substring(0, 5)
                    + ' - '
                    + String(
                        slot.end_time
                        || ''
                    ).substring(0, 5)
                    + ' · '
                    + (
                        slot.booking_status
                        || ''
                    )
                    + '</span>'
            )
            .join('');
    } catch (error) {
        target.innerHTML =
            '<span class="hb-slot">Could not load availability. The server will still validate before saving.</span>';
    }
}

if (spaceSelect) {
    spaceSelect.addEventListener(
        'change',
        loadAvailability
    );
}

if (bookingDate) {
    bookingDate.addEventListener(
        'change',
        loadAvailability
    );
}

const bookingForm =
    document.getElementById(
        'hubBookingForm'
    );

if (bookingForm) {
    bookingForm.addEventListener(
        'submit',
        function (event) {
            const start =
                document.getElementById(
                    'startTime'
                ).value;

            const end =
                document.getElementById(
                    'endTime'
                ).value;

            if (
                start
                &&
                end
                &&
                end <= start
            ) {
                event.preventDefault();

                alert(
                    'End time must be after start time.'
                );

                return;
            }

            if (
                spaceSelect
                &&
                spaceSelect.value
            ) {
                const [
                    type,
                    name
                ] =
                    spaceSelect.value.split(
                        '||'
                    );

                document
                    .getElementById(
                        'spaceType'
                    )
                    .value =
                    type || '';

                document
                    .getElementById(
                        'spaceName'
                    )
                    .value =
                    name || '';
            }
        }
    );
}
</script>

<?php include 'includes/footer.php'; ?>
