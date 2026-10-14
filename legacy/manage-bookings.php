<?php
declare(strict_types=1);

$page_title = 'Manage Bookings';

include 'includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (
    !isset($_SESSION['user_id'])
    ||
    !ims_allowed('bookings.manage', ['Administrator', 'Operations/Admin'], 'Open the Manage Bookings page')
) {
    $_SESSION['error'] =
        'Access denied. Administrator or Operations/Admin role required.';

    header('Location: dashboard');
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

function mb_e(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function mb_bind(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if (
        $types === ''
        ||
        !$params
    ) {
        return;
    }

    $refs = [$types];

    foreach (
        $params
        as
        $key => &$value
    ) {
        $refs[] = &$value;
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $refs
    );
}

function mb_col(
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

function mb_page_url(int $targetPage): string
{
    $query = $_GET;
    unset($query['view']);
    $query['page'] = max(1, $targetPage);

    return '?' . http_build_query($query);
}

$filter_status = trim(
    (string)(
        $_GET['status']
        ?? ''
    )
);

$filter_space = trim(
    (string)(
        $_GET['space']
        ?? ''
    )
);

$filter_date = trim(
    (string)(
        $_GET['date']
        ?? ''
    )
);

$filter_member = trim(
    (string)(
        $_GET['member']
        ?? ''
    )
);

$page = max(
    1,
    (int)(
        $_GET['page']
        ?? 1
    )
);

$records_per_page = 15;

$where = [
    '1=1',
];

$params = [];
$types = '';

if ($filter_status !== '') {
    $where[] =
        'sb.booking_status = ?';

    $params[] =
        $filter_status;

    $types .= 's';
}

if ($filter_space !== '') {
    $where[] =
        'sb.space_name = ?';

    $params[] =
        $filter_space;

    $types .= 's';
}

if ($filter_date !== '') {
    $where[] =
        'sb.booking_date = ?';

    $params[] =
        $filter_date;

    $types .= 's';
}

if ($filter_member !== '') {
    $where[] = "
        (
            m.membership_number LIKE ?
            OR u.full_name LIKE ?
            OR u.email LIKE ?
        )
    ";

    $like =
        '%'
        . $filter_member
        . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= 'sss';
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

$countSql = "
    SELECT
        COUNT(*) AS total
    FROM space_bookings sb
    LEFT JOIN members m
        ON sb.member_id = m.member_id
    LEFT JOIN users u
        ON m.user_id = u.user_id
    WHERE {$whereSql}
";

$countStmt =
    $conn->prepare(
        $countSql
    );

mb_bind(
    $countStmt,
    $types,
    $params
);

$countStmt->execute();

$total_records =
    (int)(
        $countStmt
        ->get_result()
        ->fetch_assoc()['total']
        ?? 0
    );

$countStmt->close();

$total_pages = max(
    1,
    (int)ceil(
        $total_records
        /
        $records_per_page
    )
);

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset =
    ($page - 1)
    *
    $records_per_page;

$optionalSelect = [];

$optionalSelect[] =
    mb_col(
        $conn,
        'space_bookings',
        'purpose'
    )
        ? 'sb.purpose'
        : 'NULL AS purpose';

$optionalSelect[] =
    mb_col(
        $conn,
        'space_bookings',
        'notes'
    )
        ? 'sb.notes'
        : 'NULL AS notes';

$optionalSelect[] =
    mb_col(
        $conn,
        'space_bookings',
        'number_of_attendees'
    )
        ? 'sb.number_of_attendees'
        : 'NULL AS number_of_attendees';

$optionalSelect[] =
    mb_col(
        $conn,
        'space_bookings',
        'rejection_reason'
    )
        ? 'sb.rejection_reason'
        : 'NULL AS rejection_reason';

$optionalSelect[] =
    mb_col(
        $conn,
        'space_bookings',
        'rejected_at'
    )
        ? 'sb.rejected_at'
        : 'NULL AS rejected_at';

$optionalSelect[] =
    mb_col(
        $conn,
        'space_bookings',
        'rejected_by'
    )
        ? 'rejector.full_name AS rejected_by_name'
        : 'NULL AS rejected_by_name';

$joinRejector =
    mb_col(
        $conn,
        'space_bookings',
        'rejected_by'
    )
        ? 'LEFT JOIN users rejector ON sb.rejected_by = rejector.user_id'
        : '';

$sql = "
    SELECT
        sb.*,
        "
        . implode(
            ', ',
            $optionalSelect
        )
        . ",
        m.membership_number,
        u.full_name AS member_name,
        u.email AS member_email,
        u.phone AS member_phone,
        approver.full_name AS approved_by_name,
        canceller.full_name AS cancelled_by_name
    FROM space_bookings sb
    LEFT JOIN members m
        ON sb.member_id = m.member_id
    LEFT JOIN users u
        ON m.user_id = u.user_id
    LEFT JOIN users approver
        ON sb.confirmed_by = approver.user_id
    LEFT JOIN users canceller
        ON sb.cancelled_by = canceller.user_id
    {$joinRejector}
    WHERE {$whereSql}
    ORDER BY
        CASE
            WHEN sb.booking_status = 'Pending' THEN 0
            ELSE 1
        END,
        sb.booking_date DESC,
        sb.start_time DESC,
        sb.booking_id DESC
    LIMIT ?
    OFFSET ?
";

$queryParams = $params;
$queryTypes = $types;

$queryParams[] =
    $records_per_page;

$queryParams[] =
    $offset;

$queryTypes .= 'ii';

$stmt =
    $conn->prepare(
        $sql
    );

mb_bind(
    $stmt,
    $queryTypes,
    $queryParams
);

$stmt->execute();

$bookings = [];

$result =
    $stmt->get_result();

while (
    $row =
        $result->fetch_assoc()
) {
    $bookings[] =
        $row;
}

$stmt->close();

$stats = [
    'total' => (int)(
        $conn->query("
            SELECT COUNT(*) AS c
            FROM space_bookings
        ")->fetch_assoc()['c']
        ?? 0
    ),

    'pending' => (int)(
        $conn->query("
            SELECT COUNT(*) AS c
            FROM space_bookings
            WHERE booking_status = 'Pending'
        ")->fetch_assoc()['c']
        ?? 0
    ),

    'confirmed' => (int)(
        $conn->query("
            SELECT COUNT(*) AS c
            FROM space_bookings
            WHERE booking_status = 'Confirmed'
        ")->fetch_assoc()['c']
        ?? 0
    ),

    'today' => (int)(
        $conn->query("
            SELECT COUNT(*) AS c
            FROM space_bookings
            WHERE booking_date = CURDATE()
              AND booking_status NOT IN (
                  'Cancelled',
                  'Rejected',
                  'Completed'
              )
        ")->fetch_assoc()['c']
        ?? 0
    ),
];

$spaces = [];

$result =
    $conn->query("
        SELECT DISTINCT
            space_name
        FROM space_bookings
        WHERE space_name IS NOT NULL
          AND TRIM(space_name) <> ''
        ORDER BY space_name
    ");

if ($result) {
    while (
        $row =
            $result->fetch_assoc()
    ) {
        $spaces[] =
            (string)$row['space_name'];
    }
}

include 'includes/header.php';
?>

<style>
.mb-wrap{width:100%}
.mb-hero{background:#fff;border:1px solid #e2e8f0;border-left:5px solid #ff6b35;border-radius:11px;padding:18px 20px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}
.mb-hero h1{margin:0;color:#0f172a;font-size:21px}.mb-hero p{margin:5px 0 0;color:#64748b;font-size:11px}
.mb-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:14px}
.mb-stat{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #2563eb;border-radius:8px;padding:12px}
.mb-stat:nth-child(2){border-left-color:#f59e0b}.mb-stat:nth-child(3){border-left-color:#16a34a}.mb-stat:nth-child(4){border-left-color:#9333ea}
.mb-stat small{display:block;color:#64748b;font-size:8.5px;font-weight:800;text-transform:uppercase}.mb-stat strong{display:block;color:#0f172a;font-size:19px;margin-top:3px}
.mb-filter{background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:11px;margin-bottom:13px;display:grid;grid-template-columns:repeat(4,minmax(130px,1fr)) auto;gap:8px;align-items:end}
.mb-filter label{display:block;margin-bottom:4px;color:#64748b;font-size:8px;font-weight:800;text-transform:uppercase}
.mb-panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
.mb-panel-head{padding:12px 14px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.mb-panel-head h3{margin:0;font-size:13px;color:#0f172a}.mb-table-wrap{overflow-x:auto}
.mb-table{width:100%;border-collapse:collapse;min-width:1050px}.mb-table th{padding:9px;background:#f8fafc;color:#64748b;border-bottom:1px solid #e2e8f0;text-align:left;font-size:8px;text-transform:uppercase}.mb-table td{padding:9px;border-bottom:1px solid #f1f5f9;color:#334155;font-size:9.5px;vertical-align:top}
.mb-table tr.pending{background:#fffdf5}.mb-status{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:8px;font-weight:800}.mb-status.Pending{background:#fff7ed;color:#9a3412}.mb-status.Confirmed{background:#ecfdf5;color:#166534}.mb-status.Rejected,.mb-status.Cancelled{background:#fef2f2;color:#991b1b}.mb-status.Completed{background:#eff6ff;color:#1d4ed8}
.mb-actions{display:flex;gap:4px;flex-wrap:wrap}.mb-pagination{display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;padding:14px;border-top:1px solid #e2e8f0}.mb-pagination a,.mb-pagination span.page{min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;border-radius:7px;text-decoration:none;color:#334155;font-size:9px;font-weight:700}.mb-pagination a:hover,.mb-pagination span.active{background:#ff6b35;border-color:#ff6b35;color:#fff}.mb-pagination .summary{color:#64748b;font-size:9px;margin-left:5px}
.mb-modal{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.58);overflow-y:auto}.mb-modal.show{display:flex}.mb-modal-card{width:min(100%,760px);max-height:calc(100vh - 40px);overflow:auto;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.3)}
.mb-modal-head{position:sticky;top:0;z-index:2;background:#fff;padding:14px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}.mb-modal-head h3{margin:0;font-size:14px}.mb-modal-close{width:32px;height:32px;border:0;border-radius:8px;background:#f1f5f9;color:#475569;cursor:pointer;font-size:18px}
.mb-modal-body{padding:16px}.mb-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.mb-detail{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px}.mb-detail.full{grid-column:1/-1}.mb-detail small{display:block;color:#64748b;font-size:8px;text-transform:uppercase;font-weight:800;margin-bottom:4px}.mb-detail strong,.mb-detail div{font-size:10px;color:#0f172a;word-break:break-word}
.mb-section-title{margin:16px 0 8px;font-size:11px;color:#0f172a}.mb-modal-actions{padding:12px 16px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:7px;flex-wrap:wrap}
body.mb-modal-open{overflow:hidden}
@media(max-width:900px){.mb-stats{grid-template-columns:repeat(2,1fr)}.mb-filter{grid-template-columns:repeat(2,1fr)}.mb-detail-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.mb-stats,.mb-filter,.mb-detail-grid{grid-template-columns:1fr}.mb-detail.full{grid-column:auto}}
</style>

<div class="mb-wrap">

    <div class="mb-hero">
        <div>
            <h1>
                <i class="fas fa-calendar-check"></i>
                Manage Bookings
            </h1>
            <p>
                Review member space bookings, approve requests and inspect full booking details.
            </p>
        </div>

        <a
            href="hub-operations?tab=bookings"
            class="btn btn-secondary"
        >
            <i class="fas fa-arrow-left"></i>
            Hub Operations
        </a>
    </div>

    <div class="mb-stats">
        <div class="mb-stat">
            <small>Total Bookings</small>
            <strong><?= $stats['total'] ?></strong>
        </div>

        <div class="mb-stat">
            <small>Pending Approval</small>
            <strong><?= $stats['pending'] ?></strong>
        </div>

        <div class="mb-stat">
            <small>Confirmed</small>
            <strong><?= $stats['confirmed'] ?></strong>
        </div>

        <div class="mb-stat">
            <small>Today's Bookings</small>
            <strong><?= $stats['today'] ?></strong>
        </div>
    </div>

    <form
        method="GET"
        class="mb-filter"
    >
        <div>
            <label>Status</label>
            <select
                name="status"
                class="form-control"
            >
                <option value="">All Statuses</option>

                <?php foreach (
                    [
                        'Pending',
                        'Confirmed',
                        'Rejected',
                        'Cancelled',
                        'Completed',
                    ]
                    as
                    $status
                ): ?>
                    <option
                        value="<?= mb_e($status) ?>"
                        <?= $filter_status === $status
                            ? 'selected'
                            : '' ?>
                    >
                        <?= mb_e($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Space</label>
            <select
                name="space"
                class="form-control"
            >
                <option value="">All Spaces</option>

                <?php foreach ($spaces as $space): ?>
                    <option
                        value="<?= mb_e($space) ?>"
                        <?= $filter_space === $space
                            ? 'selected'
                            : '' ?>
                    >
                        <?= mb_e($space) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Date</label>
            <input
                type="date"
                name="date"
                class="form-control"
                value="<?= mb_e($filter_date) ?>"
            >
        </div>

        <div>
            <label>Member</label>
            <input
                type="search"
                name="member"
                class="form-control"
                placeholder="Name, email or membership #"
                value="<?= mb_e($filter_member) ?>"
            >
        </div>

        <div style="display:flex;gap:6px;">
            <button
                type="submit"
                class="btn btn-info"
            >
                <i class="fas fa-filter"></i>
                Filter
            </button>

            <a
                href="manage-bookings"
                class="btn btn-secondary"
            >
                Clear
            </a>
        </div>
    </form>

    <div class="mb-panel">
        <div class="mb-panel-head">
            <h3>
                <i class="fas fa-table-list"></i>
                Space Bookings
            </h3>

            <span style="font-size:9px;color:#64748b;">
                <?= number_format($total_records) ?>
                record(s)
            </span>
        </div>

        <div class="mb-table-wrap">
            <table class="mb-table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Member</th>
                        <th>Space</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Purpose</th>
                        <th>Attendees</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$bookings): ?>
                    <tr>
                        <td
                            colspan="10"
                            style="text-align:center;padding:28px;"
                        >
                            No bookings match the selected filters.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($bookings as $booking): ?>
                    <?php
                    $status =
                        (string)(
                            $booking['booking_status']
                            ?? 'Pending'
                        );

                    $payload = [
                        'booking_id' =>
                            (int)$booking['booking_id'],

                        'member_name' =>
                            (string)(
                                $booking['member_name']
                                ?? 'N/A'
                            ),

                        'membership_number' =>
                            (string)(
                                $booking['membership_number']
                                ?? ''
                            ),

                        'member_email' =>
                            (string)(
                                $booking['member_email']
                                ?? ''
                            ),

                        'member_phone' =>
                            (string)(
                                $booking['member_phone']
                                ?? ''
                            ),

                        'space_type' =>
                            (string)(
                                $booking['space_type']
                                ?? ''
                            ),

                        'space_name' =>
                            (string)(
                                $booking['space_name']
                                ?? ''
                            ),

                        'booking_date' =>
                            (string)(
                                $booking['booking_date']
                                ?? ''
                            ),

                        'start_time' =>
                            (string)(
                                $booking['start_time']
                                ?? ''
                            ),

                        'end_time' =>
                            (string)(
                                $booking['end_time']
                                ?? ''
                            ),

                        'duration_hours' =>
                            (string)(
                                $booking['duration_hours']
                                ?? ''
                            ),

                        'number_of_attendees' =>
                            (int)(
                                $booking['number_of_attendees']
                                ?? 0
                            ),

                        'purpose' =>
                            (string)(
                                $booking['purpose']
                                ?? ''
                            ),

                        'notes' =>
                            (string)(
                                $booking['notes']
                                ?? ''
                            ),

                        'booking_amount' =>
                            (float)(
                                $booking['booking_amount']
                                ?? 0
                            ),

                        'payment_status' =>
                            (string)(
                                $booking['payment_status']
                                ?? ''
                            ),

                        'booking_status' =>
                            $status,

                        'created_at' =>
                            (string)(
                                $booking['created_at']
                                ?? ''
                            ),

                        'approved_by_name' =>
                            (string)(
                                $booking['approved_by_name']
                                ?? ''
                            ),

                        'confirmed_at' =>
                            (string)(
                                $booking['confirmed_at']
                                ?? ''
                            ),

                        'rejected_by_name' =>
                            (string)(
                                $booking['rejected_by_name']
                                ?? ''
                            ),

                        'rejected_at' =>
                            (string)(
                                $booking['rejected_at']
                                ?? ''
                            ),

                        'rejection_reason' =>
                            (string)(
                                $booking['rejection_reason']
                                ?? ''
                            ),

                        'cancelled_by_name' =>
                            (string)(
                                $booking['cancelled_by_name']
                                ?? ''
                            ),

                        'cancelled_at' =>
                            (string)(
                                $booking['cancelled_at']
                                ?? ''
                            ),

                        'cancellation_reason' =>
                            (string)(
                                $booking['cancellation_reason']
                                ?? ''
                            ),
                    ];
                    ?>
                    <tr class="<?= $status === 'Pending' ? 'pending' : '' ?>">
                        <td>
                            <strong>
                                #<?= (int)$booking['booking_id'] ?>
                            </strong>
                        </td>

                        <td>
                            <strong>
                                <?= mb_e($booking['member_name'] ?? 'N/A') ?>
                            </strong>
                            <br>
                            <small>
                                <?= mb_e($booking['membership_number'] ?? '') ?>
                            </small>
                        </td>

                        <td>
                            <strong>
                                <?= mb_e($booking['space_name'] ?? '') ?>
                            </strong>
                            <br>
                            <small>
                                <?= mb_e($booking['space_type'] ?? '') ?>
                            </small>
                        </td>

                        <td>
                            <?= !empty($booking['booking_date'])
                                ? date(
                                    'd M Y',
                                    strtotime((string)$booking['booking_date'])
                                )
                                : '—' ?>
                        </td>

                        <td>
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

                        <td>
                            <?= mb_e(
                                mb_strimwidth(
                                    (string)(
                                        $booking['purpose']
                                        ?? '—'
                                    ),
                                    0,
                                    45,
                                    '…'
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= (int)(
                                $booking['number_of_attendees']
                                ?? 0
                            ) ?>
                        </td>

                        <td>
                            <span class="mb-status <?= mb_e($status) ?>">
                                <?= mb_e($status) ?>
                            </span>
                        </td>

                        <td>
                            <?= mb_e(
                                $booking['payment_status']
                                ?? 'Pending'
                            ) ?>
                        </td>

                        <td>
                            <div class="mb-actions">
                                <button
                                    type="button"
                                    class="btn btn-info btn-sm"
                                    title="View booking"
                                    onclick='openBookingModal(<?= json_encode(
                                        $payload,
                                        JSON_HEX_TAG
                                        |
                                        JSON_HEX_AMP
                                        |
                                        JSON_HEX_APOS
                                        |
                                        JSON_HEX_QUOT
                                    ) ?>)'
                                >
                                    <i class="fas fa-eye"></i>
                                </button>

                                <?php if ($status === 'Pending'): ?>
                                    <button
                                        type="button"
                                        class="btn btn-success btn-sm"
                                        title="Approve"
                                        onclick="quickApprove(<?= (int)$booking['booking_id'] ?>)"
                                    >
                                        <i class="fas fa-check"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm"
                                        title="Reject"
                                        onclick="openRejectModal(<?= (int)$booking['booking_id'] ?>)"
                                    >
                                        <i class="fas fa-times"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if (
                                    $status === 'Confirmed'
                                    &&
                                    strtotime(
                                        (string)$booking['booking_date']
                                        . ' '
                                        . (string)$booking['end_time']
                                    )
                                    >
                                    time()
                                ): ?>
                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm"
                                        title="Cancel"
                                        onclick="confirmCancel(<?= (int)$booking['booking_id'] ?>)"
                                    >
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if (
                                    $status === 'Confirmed'
                                    &&
                                    strtotime(
                                        (string)$booking['booking_date']
                                        . ' '
                                        . (string)$booking['end_time']
                                    )
                                    <
                                    time()
                                ): ?>
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm"
                                        title="Mark completed"
                                        onclick="markComplete(<?= (int)$booking['booking_id'] ?>)"
                                    >
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="mb-pagination">

                <?php if ($page > 1): ?>
                    <a href="<?= mb_e(mb_page_url(1)) ?>">
                        <i class="fas fa-angles-left"></i>
                    </a>

                    <a href="<?= mb_e(mb_page_url($page - 1)) ?>">
                        <i class="fas fa-angle-left"></i>
                    </a>
                <?php endif; ?>

                <?php
                $startPage =
                    max(
                        1,
                        $page - 2
                    );

                $endPage =
                    min(
                        $total_pages,
                        $page + 2
                    );
                ?>

                <?php for (
                    $i = $startPage;
                    $i <= $endPage;
                    $i++
                ): ?>
                    <?php if ($i === $page): ?>
                        <span class="page active">
                            <?= $i ?>
                        </span>
                    <?php else: ?>
                        <a href="<?= mb_e(mb_page_url($i)) ?>">
                            <?= $i ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= mb_e(mb_page_url($page + 1)) ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>

                    <a href="<?= mb_e(mb_page_url($total_pages)) ?>">
                        <i class="fas fa-angles-right"></i>
                    </a>
                <?php endif; ?>

                <span class="summary">
                    Page <?= $page ?> of <?= $total_pages ?>
                </span>

            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Booking details modal -->
<div
    class="mb-modal"
    id="bookingViewModal"
    aria-hidden="true"
>
    <div class="mb-modal-card">
        <div class="mb-modal-head">
            <h3>
                <i class="fas fa-calendar-check"></i>
                Booking Details
            </h3>

            <button
                type="button"
                class="mb-modal-close"
                onclick="closeBookingModal('bookingViewModal')"
            >
                &times;
            </button>
        </div>

        <div
            class="mb-modal-body"
            id="bookingViewBody"
        ></div>

        <div class="mb-modal-actions">
            <button
                type="button"
                id="bookingEmailButton"
                class="btn btn-secondary"
                onclick="openEmailMemberModal()"
            >
                <i class="fas fa-envelope"></i>
                Email Member
            </button>

            <button
                type="button"
                class="btn btn-gray"
                onclick="closeBookingModal('bookingViewModal')"
            >
                Close
            </button>
        </div>
    </div>
</div>


<!-- Email member modal -->
<div
    class="mb-modal"
    id="emailMemberModal"
    aria-hidden="true"
>
    <div
        class="mb-modal-card"
        style="max-width:560px;"
    >
        <div class="mb-modal-head">
            <h3>
                <i class="fas fa-envelope"></i>
                Email Member
            </h3>

            <button
                type="button"
                class="mb-modal-close"
                onclick="closeBookingModal('emailMemberModal')"
            >
                &times;
            </button>
        </div>

        <div class="mb-modal-body">
            <div class="form-group">
                <label>To</label>

                <input
                    type="email"
                    id="emailMemberTo"
                    class="form-control"
                    readonly
                >
            </div>

            <div class="form-group">
                <label>Subject</label>

                <input
                    type="text"
                    id="emailMemberSubject"
                    class="form-control"
                >
            </div>

            <div class="form-group">
                <label>Message</label>

                <textarea
                    id="emailMemberMessage"
                    class="form-control"
                    rows="7"
                ></textarea>
            </div>

            <div
                id="emailMemberError"
                style="
                    display:none;
                    margin-top:10px;
                    padding:9px 10px;
                    border-left:4px solid #dc2626;
                    border-radius:7px;
                    background:#fef2f2;
                    color:#991b1b;
                    font-size:10px;
                "
            ></div>

            <div
                id="emailMemberSuccess"
                style="
                    display:none;
                    margin-top:10px;
                    padding:9px 10px;
                    border-left:4px solid #16a34a;
                    border-radius:7px;
                    background:#ecfdf5;
                    color:#166534;
                    font-size:10px;
                "
            ></div>
        </div>

        <div class="mb-modal-actions">
            <button
                type="button"
                class="btn btn-gray"
                onclick="closeBookingModal('emailMemberModal')"
            >
                Cancel
            </button>

            <button
                type="button"
                class="btn btn-primary"
                id="sendMemberEmailButton"
                onclick="sendMemberEmail()"
            >
                <i class="fas fa-paper-plane"></i>
                Send Email
            </button>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div
    class="mb-modal"
    id="rejectBookingModal"
    aria-hidden="true"
>
    <div
        class="mb-modal-card"
        style="max-width:520px;"
    >
        <div class="mb-modal-head">
            <h3>
                <i class="fas fa-times-circle"></i>
                Reject Booking
            </h3>

            <button
                type="button"
                class="mb-modal-close"
                onclick="closeBookingModal('rejectBookingModal')"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/manage-bookings-process.php"
        >
            <div class="mb-modal-body">
                <input
                    type="hidden"
                    name="action"
                    value="reject"
                >

                <input
                    type="hidden"
                    name="booking_id"
                    id="rejectBookingId"
                >

                <div class="form-group">
                    <label class="required">
                        Rejection Reason
                    </label>

                    <textarea
                        name="rejection_reason"
                        class="form-control"
                        rows="4"
                        required
                        placeholder="Explain why this request cannot be approved..."
                    ></textarea>
                </div>
            </div>

            <div class="mb-modal-actions">
                <button
                    type="button"
                    class="btn btn-gray"
                    onclick="closeBookingModal('rejectBookingModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-danger"
                >
                    <i class="fas fa-times"></i>
                    Reject Booking
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function bookingEsc(value) {
    const div =
        document.createElement(
            'div'
        );

    div.textContent =
        value
        ??
        '';

    return div.innerHTML;
}

function formatDateValue(value) {
    if (!value) {
        return '—';
    }

    const date =
        new Date(
            value.replace(
                ' ',
                'T'
            )
        );

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return value;
    }

    return date.toLocaleString(
        undefined,
        {
            dateStyle: 'medium',
            timeStyle:
                value.length > 10
                ?
                'short'
                :
                undefined,
        }
    );
}

function formatTimeValue(value) {
    if (!value) {
        return '—';
    }

    const parts =
        value.split(':');

    let hours =
        parseInt(
            parts[0],
            10
        );

    const minutes =
        parts[1]
        ||
        '00';

    const suffix =
        hours >= 12
        ?
        'PM'
        :
        'AM';

    hours =
        hours % 12
        ||
        12;

    return `${hours}:${minutes} ${suffix}`;
}

function openBookingModal(data) {
    const modal =
        document.getElementById(
            'bookingViewModal'
        );

    const body =
        document.getElementById(
            'bookingViewBody'
        );

    const amount =
        Number(
            data.booking_amount
            ||
            0
        )
        .toLocaleString();

    let decision = 'Awaiting review';

    if (
        data.booking_status
        ===
        'Confirmed'
    ) {
        decision =
            'Approved'
            +
            (
                data.approved_by_name
                ?
                ' by '
                +
                bookingEsc(
                    data.approved_by_name
                )
                :
                ''
            );
    }

    if (
        data.booking_status
        ===
        'Rejected'
    ) {
        decision =
            'Rejected'
            +
            (
                data.rejected_by_name
                ?
                ' by '
                +
                bookingEsc(
                    data.rejected_by_name
                )
                :
                ''
            );
    }

    if (
        data.booking_status
        ===
        'Cancelled'
    ) {
        decision =
            'Cancelled'
            +
            (
                data.cancelled_by_name
                ?
                ' by '
                +
                bookingEsc(
                    data.cancelled_by_name
                )
                :
                ''
            );
    }

    body.innerHTML = `
        <div class="mb-detail-grid">
            <div class="mb-detail">
                <small>Booking Reference</small>
                <strong>#${bookingEsc(data.booking_id)}</strong>
            </div>

            <div class="mb-detail">
                <small>Status</small>
                <strong>${bookingEsc(data.booking_status)}</strong>
            </div>

            <div class="mb-detail">
                <small>Payment</small>
                <strong>${bookingEsc(data.payment_status || 'Pending')}</strong>
            </div>

            <div class="mb-detail">
                <small>Space</small>
                <strong>${bookingEsc(data.space_name)}</strong>
                <div>${bookingEsc(data.space_type)}</div>
            </div>

            <div class="mb-detail">
                <small>Date</small>
                <strong>${bookingEsc(data.booking_date)}</strong>
            </div>

            <div class="mb-detail">
                <small>Time</small>
                <strong>
                    ${formatTimeValue(data.start_time)}
                    -
                    ${formatTimeValue(data.end_time)}
                </strong>
            </div>

            <div class="mb-detail">
                <small>Member</small>
                <strong>${bookingEsc(data.member_name)}</strong>
                <div>${bookingEsc(data.membership_number)}</div>
            </div>

            <div class="mb-detail">
                <small>Email</small>
                <strong>${bookingEsc(data.member_email || '—')}</strong>
            </div>

            <div class="mb-detail">
                <small>Phone</small>
                <strong>${bookingEsc(data.member_phone || '—')}</strong>
            </div>

            <div class="mb-detail">
                <small>Attendees</small>
                <strong>${bookingEsc(data.number_of_attendees || 0)}</strong>
            </div>

            <div class="mb-detail">
                <small>Amount</small>
                <strong>UGX ${amount}</strong>
            </div>

            <div class="mb-detail">
                <small>Created</small>
                <strong>${bookingEsc(formatDateValue(data.created_at))}</strong>
            </div>

            <div class="mb-detail full">
                <small>Purpose</small>
                <div>${bookingEsc(data.purpose || '—')}</div>
            </div>

            ${
                data.notes
                ?
                `
                <div class="mb-detail full">
                    <small>Notes</small>
                    <div>${bookingEsc(data.notes)}</div>
                </div>
                `
                :
                ''
            }

            <div class="mb-detail full">
                <small>Decision / Workflow</small>
                <div>${decision}</div>
            </div>

            ${
                data.rejection_reason
                ?
                `
                <div class="mb-detail full" style="border-left:4px solid #dc2626;">
                    <small>Rejection Reason</small>
                    <div>${bookingEsc(data.rejection_reason)}</div>
                </div>
                `
                :
                ''
            }

            ${
                data.cancellation_reason
                ?
                `
                <div class="mb-detail full" style="border-left:4px solid #dc2626;">
                    <small>Cancellation Reason</small>
                    <div>${bookingEsc(data.cancellation_reason)}</div>
                </div>
                `
                :
                ''
            }
        </div>
    `;

    window.currentBookingEmailData = {
        email:
            String(
                data.member_email
                ||
                ''
            ).trim(),

        member_name:
            String(
                data.member_name
                ||
                'Member'
            ).trim(),

        booking_id:
            String(
                data.booking_id
                ||
                ''
            ).trim(),

        space_name:
            String(
                data.space_name
                ||
                ''
            ).trim(),

        booking_date:
            String(
                data.booking_date
                ||
                ''
            ).trim(),

        start_time:
            String(
                data.start_time
                ||
                ''
            ).trim(),

        end_time:
            String(
                data.end_time
                ||
                ''
            ).trim()
    };

    const emailButton =
        document.getElementById(
            'bookingEmailButton'
        );

    if (emailButton) {
        emailButton.disabled =
            !window.currentBookingEmailData.email;

        emailButton.title =
            window.currentBookingEmailData.email
            ?
            'Email this member'
            :
            'No member email address is available';
    }

    modal.classList.add(
        'show'
    );

    modal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.classList.add(
        'mb-modal-open'
    );
}

function closeBookingModal(id) {
    const modal =
        document.getElementById(
            id
        );

    if (!modal) {
        return;
    }

    modal.classList.remove(
        'show'
    );

    modal.setAttribute(
        'aria-hidden',
        'true'
    );

    if (
        !document.querySelector(
            '.mb-modal.show'
        )
    ) {
        document.body.classList.remove(
            'mb-modal-open'
        );
    }
}


window.currentBookingEmailData = null;

function openEmailMemberModal() {
    const booking =
        window.currentBookingEmailData;

    const errorBox =
        document.getElementById(
            'emailMemberError'
        );

    if (
        !booking
        ||
        !booking.email
    ) {
        if (errorBox) {
            errorBox.textContent =
                'This booking does not have a member email address.';

            errorBox.style.display =
                'block';
        }

        return;
    }

    if (errorBox) {
        errorBox.textContent = '';
        errorBox.style.display =
            'none';
    }

    const successBox =
        document.getElementById(
            'emailMemberSuccess'
        );

    if (successBox) {
        successBox.textContent = '';
        successBox.style.display =
            'none';
    }

    const to =
        document.getElementById(
            'emailMemberTo'
        );

    const subject =
        document.getElementById(
            'emailMemberSubject'
        );

    const message =
        document.getElementById(
            'emailMemberMessage'
        );

    to.value =
        booking.email;

    subject.value =
        'Hive Colab Booking #'
        +
        booking.booking_id
        +
        ' - '
        +
        booking.space_name;

    message.value =
        'Dear '
        +
        booking.member_name
        +
        ',\n\n'
        +
        'This message is regarding your Hive Colab booking request.\n\n'
        +
        'Booking ID: #'
        +
        booking.booking_id
        +
        '\n'
        +
        'Space: '
        +
        booking.space_name
        +
        '\n'
        +
        'Date: '
        +
        booking.booking_date
        +
        '\n'
        +
        'Time: '
        +
        formatTimeValue(
            booking.start_time
        )
        +
        ' - '
        +
        formatTimeValue(
            booking.end_time
        )
        +
        '\n\n'
        +
        'Regards,\nHive Colab Operations';

    closeBookingModal(
        'bookingViewModal'
    );

    const modal =
        document.getElementById(
            'emailMemberModal'
        );

    modal.classList.add(
        'show'
    );

    modal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.classList.add(
        'mb-modal-open'
    );

    setTimeout(
        function() {
            message.focus();
        },
        50
    );
}

async function sendMemberEmail() {
    const booking =
        window.currentBookingEmailData;

    const subject =
        document
        .getElementById(
            'emailMemberSubject'
        )
        .value
        .trim();

    const message =
        document
        .getElementById(
            'emailMemberMessage'
        )
        .value
        .trim();

    const errorBox =
        document.getElementById(
            'emailMemberError'
        );

    const successBox =
        document.getElementById(
            'emailMemberSuccess'
        );

    const sendButton =
        document.getElementById(
            'sendMemberEmailButton'
        );

    errorBox.style.display =
        'none';

    successBox.style.display =
        'none';

    if (
        !booking
        ||
        !booking.booking_id
    ) {
        errorBox.textContent =
            'Booking information is missing. Please close the modal and try again.';

        errorBox.style.display =
            'block';

        return;
    }

    if (
        subject === ''
        ||
        message === ''
    ) {
        errorBox.textContent =
            'Subject and message are required.';

        errorBox.style.display =
            'block';

        return;
    }

    const originalHtml =
        sendButton.innerHTML;

    sendButton.disabled =
        true;

    sendButton.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Sending...';

    try {
        const formData =
            new FormData();

        formData.append(
            'booking_id',
            booking.booking_id
        );

        formData.append(
            'subject',
            subject
        );

        formData.append(
            'message',
            message
        );

        const response =
            await fetch(
                'includes/booking-email-process.php',
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

        const raw =
            await response.text();

        let data;

        try {
            data =
                JSON.parse(raw);
        } catch (error) {
            throw new Error(
                raw
                ||
                'Invalid response from the email server.'
            );
        }

        if (
            !response.ok
            ||
            !data.success
        ) {
            throw new Error(
                data.message
                ||
                'The email could not be sent.'
            );
        }

        successBox.textContent =
            data.message
            ||
            'Email sent successfully.';

        successBox.style.display =
            'block';

        setTimeout(
            function() {
                closeBookingModal(
                    'emailMemberModal'
                );
            },
            1400
        );
    } catch (error) {
        errorBox.textContent =
            error.message
            ||
            'The email could not be sent.';

        errorBox.style.display =
            'block';
    } finally {
        sendButton.disabled =
            false;

        sendButton.innerHTML =
            originalHtml;
    }
}

function openRejectModal(bookingId) {
    document
        .getElementById(
            'rejectBookingId'
        )
        .value =
        bookingId;

    const modal =
        document.getElementById(
            'rejectBookingModal'
        );

    modal.classList.add(
        'show'
    );

    modal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.classList.add(
        'mb-modal-open'
    );
}

function quickApprove(bookingId) {
    if (
        !confirm(
            'Approve this booking request?'
        )
    ) {
        return;
    }

    const form =
        document.createElement(
            'form'
        );

    form.method =
        'POST';

    form.action =
        'includes/manage-bookings-process.php';

    form.innerHTML = `
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="booking_id" value="${bookingId}">
    `;

    document.body.appendChild(
        form
    );

    form.submit();
}

function confirmCancel(bookingId) {
    if (
        confirm(
            'Cancel this booking? The member will be notified.'
        )
    ) {
        window.location.href =
            'includes/manage-bookings-process.php?action=admin_cancel&csrf_token=<?= h(csrf_token()) ?>&booking_id='
            +
            encodeURIComponent(
                bookingId
            );
    }
}

function markComplete(bookingId) {
    if (
        confirm(
            'Mark this booking as completed?'
        )
    ) {
        window.location.href =
            'includes/manage-bookings-process.php?action=mark_complete&csrf_token=<?= h(csrf_token()) ?>&booking_id='
            +
            encodeURIComponent(
                bookingId
            );
    }
}

document.addEventListener(
    'keydown',
    function(event) {
        if (
            event.key
            ===
            'Escape'
        ) {
            document
                .querySelectorAll(
                    '.mb-modal.show'
                )
                .forEach(
                    modal =>
                        closeBookingModal(
                            modal.id
                        )
                );
        }
    }
);

document.addEventListener(
    'click',
    function(event) {
        document
            .querySelectorAll(
                '.mb-modal.show'
            )
            .forEach(
                modal => {
                    if (
                        event.target
                        ===
                        modal
                    ) {
                        closeBookingModal(
                            modal.id
                        );
                    }
                }
            );
    }
);
</script>

<?php include 'includes/footer.php'; ?>
