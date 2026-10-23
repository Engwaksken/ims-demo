<?php
ob_start();

date_default_timezone_set('Africa/Nairobi');

$page_title = 'Internet Subscriptions';
require_once 'includes/header.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+03:00'");

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function receipt_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return ims_upload_url(ltrim($path, '/'));
}

$currentPage = basename($_SERVER['PHP_SELF']);

/* Fetch plans */
$plans = [];
$planRes = $conn->query("
    SELECT id, plan_name, duration_minutes, final_price
    FROM internet_plans
    WHERE status = 'active'
    ORDER BY final_price ASC
");
if ($planRes) {
    while ($p = $planRes->fetch_assoc()) {
        $plans[] = $p;
    }
}

/* Fetch members */
$members = [];
$memberRes = $conn->query("
    SELECT
        m.member_id,
        m.membership_number,
        m.company_name,
        u.full_name,
        u.phone,
        u.email
    FROM members m
    JOIN users u ON u.user_id = m.user_id
    WHERE u.is_active = 1
      AND LOWER(u.role) = 'member'
    ORDER BY COALESCE(NULLIF(m.company_name, ''), u.full_name) ASC
");
if ($memberRes) {
    while ($m = $memberRes->fetch_assoc()) {
        $members[] = $m;
    }
}

/* Fetch subscriptions */
$sql = "
    SELECT
        s.*,
        p.plan_name,
        p.duration_minutes,
        m.membership_number,
        m.company_name,
        u.full_name,
        u.phone,
        u.email
    FROM internet_subscriptions s
    JOIN internet_plans p ON p.id = s.plan_id
    JOIN members m ON m.member_id = s.member_id
    JOIN users u ON u.user_id = m.user_id
    ORDER BY
        FIELD(s.status, 'pending', 'active', 'expired', 'rejected'),
        s.created_at DESC
";

$res = $conn->query($sql);
$rows = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'active' => 0,
    'expired' => 0,
    'rejected' => 0
];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
        $stats['total']++;

        $status = strtolower((string)($row['status'] ?? 'pending'));
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }
}

/* PDF EXPORT */
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $autoload = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoload)) {
        if (ob_get_length()) ob_end_clean();
        die('DomPDF is not installed. Run: composer require dompdf/dompdf');
    }

    require_once $autoload;

    $logoPath = __DIR__ . '/images/logo.png';
    $logoHtml = '';

    if (is_file($logoPath)) {
        $logoData = base64_encode((string)file_get_contents($logoPath));
        $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" style="height:55px;margin-bottom:5px;">';
    }

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 22px; }
            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 10px;
                color: #111827;
            }
            .header {
                text-align: center;
                border-bottom: 2px solid #0D1321;
                padding-bottom: 10px;
                margin-bottom: 14px;
            }
            .header h2 {
                margin: 4px 0 2px;
                color: #0D1321;
                font-size: 18px;
            }
            .header p {
                margin: 0;
                color: #6B7280;
                font-size: 10px;
            }
            .stats {
                margin: 10px 0 14px;
                padding: 8px;
                background: #F3F4F6;
                border: 1px solid #E5E7EB;
                font-size: 10px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th {
                background: #0D1321;
                color: #fff;
                padding: 7px;
                text-align: left;
                font-size: 8.5px;
            }
            td {
                border: 1px solid #E5E7EB;
                padding: 6px;
                font-size: 8.5px;
                vertical-align: top;
            }
            tr:nth-child(even) td {
                background: #F9FAFB;
            }
            .badge {
                font-weight: bold;
                text-transform: uppercase;
            }
            .active { color: #059669; }
            .pending { color: #D97706; }
            .expired { color: #64748B; }
            .rejected { color: #DC2626; }
            .footer {
                margin-top: 14px;
                text-align: right;
                font-size: 9px;
                color: #6B7280;
            }
        </style>
    </head>
    <body>
        <div class="header">
            ' . $logoHtml . '
            <h2>HiveColab Internet Subscriptions</h2>
            <p>Generated on ' . h(date('d M Y, g:i A')) . ' EAT</p>
        </div>

        <div class="stats">
            <strong>Total:</strong> ' . (int)$stats['total'] . ' |
            <strong>Pending:</strong> ' . (int)$stats['pending'] . ' |
            <strong>Active:</strong> ' . (int)$stats['active'] . ' |
            <strong>Expired:</strong> ' . (int)$stats['expired'] . ' |
            <strong>Rejected:</strong> ' . (int)$stats['rejected'] . '
        </div>

        <table>
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Contact</th>
                    <th>Plan</th>
                    <th>Amount</th>
                    <th>MAC Address</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Expires</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($rows)) {
        $html .= '<tr><td colspan="8" style="text-align:center;">No subscriptions found.</td></tr>';
    }

    foreach ($rows as $row) {
        $displayName = trim((string)($row['company_name'] ?: $row['full_name']));
        $status = strtolower((string)($row['status'] ?? 'pending'));

        $html .= '
            <tr>
                <td>
                    <strong>' . h($displayName) . '</strong><br>
                    ' . h($row['membership_number'] ?? '-') . '
                </td>
                <td>
                    ' . h($row['phone'] ?? '-') . '<br>
                    ' . h($row['email'] ?? '') . '
                </td>
                <td>' . h($row['plan_name'] ?? '-') . '</td>
                <td>UGX ' . number_format((float)($row['amount_paid'] ?? 0)) . '</td>
                <td>' . h($row['mac_address'] ?? '-') . '</td>
                <td class="badge ' . h($status) . '">' . h(ucfirst($status)) . '</td>
                <td>' . (!empty($row['created_at']) ? h(date('d M Y, g:i A', strtotime($row['created_at']))) : '-') . '</td>
                <td>' . (!empty($row['expires_at']) ? h(date('d M Y, g:i A', strtotime($row['expires_at']))) : '-') . '</td>
            </tr>';
    }

    $html .= '
            </tbody>
        </table>

        <div class="footer">
            HiveColab Internet Subscription Report
        </div>
    </body>
    </html>';

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => true,
        'defaultFont' => 'DejaVu Sans'
    ]);

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'internet-subscriptions-' . date('Ymd-His') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}
?>

<link rel="stylesheet" href="css/subscriptions-admin.css">

<div class="page-internet-subs">
<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-wifi" aria-hidden="true"></i> Internet Subscriptions</h1>
        <p>Review, approve, reject, or manually add member devices to internet access.</p>
    </div>

    <div class="hero-actions">
        <a href="internet_connection_logs" class="btn"><i class="fas fa-bars" aria-hidden="true"></i> Connection Logs</a>

        <a href="javascript:window.print()" class="btn">
            <i class="fas fa-print" aria-hidden="true"></i> Print
        </a>

        <a href="<?= h($currentPage) ?>?export=pdf" class="btn">
            <i class="fas fa-file-pdf" aria-hidden="true"></i> PDF
        </a>

        <button type="button" class="btn btn-primary" id="openAddDeviceBtn">
            <i class="fas fa-laptop-medical" aria-hidden="true"></i> Add Device
        </button>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?>
<div class="alert alert-success">
    <i class="fas fa-circle-check" aria-hidden="true"></i>
    <?= h($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
<div class="alert alert-danger">
    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
    <?= h($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<div class="stats-grid reports-stats">
    <div class="stat-card stat-card-wide s-total" data-filter="all">
        <div class="stat-icon bg-primary"><i class="fas fa-list" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Total</span>
            <span class="stat-value"><?= (int)$stats['total'] ?></span>
        </div>
    </div>

    <div class="stat-card s-pending" data-filter="pending">
        <div class="stat-icon bg-amber"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Pending</span>
            <span class="stat-value"><?= (int)$stats['pending'] ?></span>
        </div>
    </div>

    <div class="stat-card s-active" data-filter="active">
        <div class="stat-icon bg-green"><i class="fas fa-wifi" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Active</span>
            <span class="stat-value"><?= (int)$stats['active'] ?></span>
        </div>
    </div>

    <div class="stat-card s-expired" data-filter="expired">
        <div class="stat-icon bg-slate"><i class="fas fa-calendar-xmark" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Expired</span>
            <span class="stat-value"><?= (int)$stats['expired'] ?></span>
        </div>
    </div>

    <div class="stat-card s-rejected" data-filter="rejected">
        <div class="stat-icon bg-red"><i class="fas fa-ban" aria-hidden="true"></i></div>
        <div class="stat-info">
            <span class="stat-label">Rejected</span>
            <span class="stat-value"><?= (int)$stats['rejected'] ?></span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
<div class="toolbar filters-bar" role="search" aria-label="Filter subscriptions">
    <div class="filter-tabs" id="filterTabs">
        <button type="button" class="filter-tab active" data-filter="all">All</button>
        <button type="button" class="filter-tab" data-filter="pending">Pending</button>
        <button type="button" class="filter-tab" data-filter="active">Active</button>
        <button type="button" class="filter-tab" data-filter="expired">Expired</button>
        <button type="button" class="filter-tab" data-filter="rejected">Rejected</button>
    </div>

    <div class="search-wrap filters-grow">
        <i class="fas fa-magnifying-glass"></i>
        <input type="text"
               class="form-control search-input"
               id="searchInput"
               placeholder="Search member, plan, receipt, MAC...">
    </div>
</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-list" aria-hidden="true"></i> Subscriptions</h3></div>
    <div class="card-body">
    <div class="table-responsive">
        <table class="table" id="subsTable">
            <thead>
            <tr>
                <th>Member</th>
                <th>Contact</th>
                <th>Plan</th>
                <th>Amount</th>
                <th>Receipt</th>
                <th>MAC Address</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Expires</th>
                <th class="isub-actions-col">Actions</th>
            </tr>
            </thead>

            <tbody>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="10">
                    <div class="empty-state">
                        <i class="fas fa-inbox" aria-hidden="true"></i>
                        <p>No subscriptions found.</p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <?php
                $status = strtolower((string)($row['status'] ?? 'pending'));
                $displayName = trim((string)($row['company_name'] ?: $row['full_name']));
                $membershipNumber = (string)($row['membership_number'] ?? '');
                $receiptPhoto = receipt_url($row['receipt_photo'] ?? '');
                $hasPhoto = $receiptPhoto !== '';

                $words = preg_split('/\s+/', trim($displayName));
                $initials = '';

                foreach (array_slice($words ?: [], 0, 2) as $word) {
                    $initials .= mb_substr($word, 0, 1);
                }

                $createdAt = $row['created_at'] ?? null;
                $expiresAt = $row['expires_at'] ?? null;

                $searchText = strtolower(
                    $displayName . ' ' .
                    $membershipNumber . ' ' .
                    ($row['plan_name'] ?? '') . ' ' .
                    ($row['phone'] ?? '') . ' ' .
                    ($row['email'] ?? '') . ' ' .
                    ($row['mac_address'] ?? '')
                );
                ?>

                <tr data-status="<?= h($status) ?>" data-search="<?= h($searchText) ?>">
                    <td>
                        <div class="member-cell">
                            <div class="member-avatar"><?= h($initials ?: '?') ?></div>
                            <div>
                                <div class="member-name"><?= h($displayName) ?></div>
                                <div class="member-number"><?= h($membershipNumber) ?></div>
                            </div>
                        </div>
                    </td>

                    <td>
                        <div><?= h($row['phone'] ?? '-') ?></div>
                        <?php if (!empty($row['email'])): ?>
                        <div class="isub-sub">
                            <?= h($row['email']) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="plan-chip">
                            <i class="fas fa-wifi"></i>
                            <?= h($row['plan_name']) ?>
                        </span>
                    </td>

                    <td>
                        <span class="currency">UGX</span>
                        <span class="amount"><?= number_format((float)$row['amount_paid']) ?></span>
                    </td>

                    <td>
                        <?php if ($hasPhoto): ?>
                            <button type="button"
                                    class="btn btn-sm btn-soft js-lightbox"
                                    data-photo="<?= h($receiptPhoto) ?>"
                                    data-member="<?= h($membershipNumber) ?>">
                                <i class="fas fa-image"></i> View Image
                            </button>
                        <?php else: ?>
                            <span class="cell-muted">No Image</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="mac-code"><?= h($row['mac_address'] ?? '-') ?></span>
                    </td>

                    <td>
                        <?php $isub_pill = ['pending' => 'status-pending', 'active' => 'status-approved', 'expired' => 'status-cancelled', 'rejected' => 'status-rejected'][$status] ?? 'status-draft'; ?>
                        <span class="status-pill <?= $isub_pill ?>">
                            <?= h(ucfirst($status)) ?>
                        </span>
                    </td>

                    <td class="date-cell">
                        <?php if ($createdAt): ?>
                            <?= h(date('d M Y', strtotime($createdAt))) ?>
                            <small><?= h(date('g:i A', strtotime($createdAt))) ?></small>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td class="date-cell">
                        <?php if ($expiresAt): ?>
                            <?= h(date('d M Y', strtotime($expiresAt))) ?>
                            <small><?= h(date('g:i A', strtotime($expiresAt))) ?></small>
                        <?php else: ?>
                            <span class="cell-muted">-</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if ($status === 'pending'): ?>
                        <div class="action-wrap">
                            <button type="button"
                                    class="btn btn-sm btn-success js-approve"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= h($displayName) ?>"
                                    data-plan="<?= h($row['plan_name']) ?>"
                                    data-amount="<?= h(number_format((float)$row['amount_paid'])) ?>">
                                <i class="fas fa-check"></i> Approve
                            </button>

                            <button type="button"
                                    class="btn btn-sm btn-danger js-reject"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= h($displayName) ?>">
                                <i class="fas fa-xmark"></i> Reject
                            </button>
                        </div>
                        <?php else: ?>
                            <div class="cell-muted isub-actions-col">-</div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>

    <div class="isub-table-footer">
        <span id="rowCountLabel">
            Showing <strong><?= count($rows) ?></strong> record<?= count($rows) !== 1 ? 's' : '' ?>
        </span>
        <span>
            Last updated: <?= date('d M Y, g:i A') ?> EAT
        </span>
    </div>
</div>

</div><!-- /.page-stack -->

<!-- Add Device Modal -->
<div class="modal-overlay" id="addDeviceOverlay">
   <div class="modal add-device-modal" role="dialog" aria-modal="true">
        <form method="post" action="includes/internet-subscription.php" id="addDeviceForm">
            <input type="hidden" name="action" value="add_device">
            <input type="hidden" name="member_id" id="addMemberId">

            <div class="modal-header">
                <span class="modal-title">
                    <i class="fas fa-laptop-medical isub-ic-brand"></i>
                    Add Device to Internet
                </span>
                <button type="button" class="modal-close" data-close-modal="addDeviceOverlay">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label>Search Member</label>
                    <div class="member-search-box">
                        <input type="text"
                               id="memberSearchInput"
                               class="form-control"
                               placeholder="Search by name, company, membership number, phone, or email..."
                               autocomplete="off">

                        <div class="member-results" id="memberResults">
                            <?php foreach ($members as $member): ?>
                                <?php
                                $memberName = trim((string)($member['company_name'] ?: $member['full_name']));
                                $memberSearch = strtolower(
                                    $memberName . ' ' .
                                    ($member['membership_number'] ?? '') . ' ' .
                                    ($member['phone'] ?? '') . ' ' .
                                    ($member['email'] ?? '')
                                );
                                ?>
                                <div class="member-result-item"
                                     data-member-id="<?= (int)$member['member_id'] ?>"
                                     data-member-name="<?= h($memberName) ?>"
                                     data-member-number="<?= h($member['membership_number']) ?>"
                                     data-member-phone="<?= h($member['phone'] ?? '') ?>"
                                     data-search="<?= h($memberSearch) ?>">
                                    <div class="member-result-name"><?= h($memberName) ?></div>
                                    <div class="member-result-meta">
                                        <?= h($member['membership_number']) ?>
                                        <?php if (!empty($member['phone'])): ?>
                                            - <?= h($member['phone']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="selected-member-card" id="selectedMemberCard">
                        <strong id="selectedMemberName">-</strong><br>
                        <span id="selectedMemberMeta">-</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Device MAC Address</label>
                    <input type="text"
                           name="mac_address"
                           class="form-control"
                           id="macAddressInput"
                           placeholder="Example: AA:BB:CC:DD:EE:FF"
                           required>
                </div>

                <div class="form-group">
                    <label>Internet Plan</label>
                    <select name="plan_id" class="form-control" required>
                        <option value="">Select internet plan</option>
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= (int)$plan['id'] ?>">
                                <?= h($plan['plan_name']) ?> - UGX <?= number_format((float)$plan['final_price']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Receipt / Reference <span class="cell-muted">(optional)</span></label>
                    <input type="text"
                           name="receipt_number"
                           class="form-control"
                           placeholder="Receipt number, payment reference, or admin note">
                </div>

                <div class="alert alert-warning">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>
                        This will create and activate an internet subscription for the selected member and MAC address.
                    </span>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="addDeviceOverlay">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Add & Activate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal-overlay" id="approveOverlay">
    <div class="modal" role="dialog" aria-modal="true">
        <form method="post" action="includes/internet-subscription.php" id="approveForm">
            <input type="hidden" name="subscription_id" id="approveSubId">
            <input type="hidden" name="action" value="approve">

            <div class="modal-header">
                <span class="modal-title">
                    <i class="fas fa-circle-check isub-ic-green"></i>
                    Approve Subscription
                </span>
                <button type="button" class="modal-close" data-close-modal="approveOverlay">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="modal-info-row">
                    <span class="modal-info-label">Member</span>
                    <span class="modal-info-value" id="approveName">-</span>
                </div>
                <div class="modal-info-row">
                    <span class="modal-info-label">Plan</span>
                    <span class="modal-info-value" id="approvePlan">-</span>
                </div>
                <div class="modal-info-row">
                    <span class="modal-info-label">Amount Paid</span>
                    <span class="modal-info-value" id="approveAmount">-</span>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="approveOverlay">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Confirm Approve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectOverlay">
    <div class="modal" role="dialog" aria-modal="true">
        <form method="post" action="includes/internet-subscription.php" id="rejectForm">
            <input type="hidden" name="subscription_id" id="rejectSubId">
            <input type="hidden" name="action" value="reject">

            <div class="modal-header">
                <span class="modal-title">
                    <i class="fas fa-ban isub-ic-red"></i>
                    Reject Subscription
                </span>
                <button type="button" class="modal-close" data-close-modal="rejectOverlay">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="modal-info-row">
                    <span class="modal-info-label">Member</span>
                    <span class="modal-info-value" id="rejectName">-</span>
                </div>

                <label class="form-label" for="rejectReasonInput">
                    Rejection Reason <span class="cell-muted">(optional)</span>
                </label>

                <textarea class="form-control reason-input"
                          id="rejectReasonInput"
                          name="rejection_reason"
                          placeholder="e.g. Receipt image is unclear..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="rejectOverlay">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Confirm Reject
                </button>
            </div>
        </form>
    </div>
</div>

<div class="lightbox" id="lightbox">
    <div class="lightbox-header">
        <span class="lightbox-title" id="lightboxTitle">Receipt Photo</span>
        <button type="button" class="lightbox-close" id="closeLightboxBtn">
            <i class="fas fa-xmark"></i>
        </button>
    </div>
    <img id="lightboxImg" src="" alt="Receipt">
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var activeFilter = 'all';
    var activeSearch = '';

    function lockBody(lock) {
        document.body.style.overflow = lock ? 'hidden' : '';
    }

    function openOverlay(id) {
        var el = document.getElementById(id);
        if (!el) {
            alert('Modal not found: ' + id);
            return;
        }

        el.classList.add('open');
        lockBody(true);
    }

    function closeOverlay(id) {
        var el = document.getElementById(id);
        if (!el) return;

        el.classList.remove('open');

        if (!document.querySelector('.modal-overlay.open') && !document.querySelector('.lightbox.open')) {
            lockBody(false);
        }
    }

    function closeLightbox() {
        var lb = document.getElementById('lightbox');
        var img = document.getElementById('lightboxImg');

        if (lb) lb.classList.remove('open');
        if (img) img.src = '';

        if (!document.querySelector('.modal-overlay.open')) {
            lockBody(false);
        }
    }

    function applyFilters() {
        var rows = document.querySelectorAll('#subsTable tbody tr[data-status]');
        var visible = 0;

        rows.forEach(function (tr) {
            var statusOk = activeFilter === 'all' || tr.dataset.status === activeFilter;
            var searchOk = !activeSearch || (tr.dataset.search || '').includes(activeSearch);

            tr.classList.toggle('row-hidden', !(statusOk && searchOk));
            if (statusOk && searchOk) visible++;
        });

        var lbl = document.getElementById('rowCountLabel');
        if (lbl) {
            lbl.innerHTML = 'Showing <strong>' + visible + '</strong> record' + (visible !== 1 ? 's' : '');
        }
    }

    function setFilter(status) {
        activeFilter = status;

        document.querySelectorAll('.filter-tab').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.filter === status);
        });

        applyFilters();
    }

    function normalizeMacInput(value) {
        value = String(value || '').replace(/[^a-fA-F0-9]/g, '').toUpperCase();

        if (value.length > 12) {
            value = value.substring(0, 12);
        }

        return value.match(/.{1,2}/g)?.join(':') || value;
    }

    document.addEventListener('click', function (e) {
        var addDeviceBtn = e.target.closest('#openAddDeviceBtn');
        if (addDeviceBtn) {
            e.preventDefault();
            openOverlay('addDeviceOverlay');
            return;
        }

        var memberItem = e.target.closest('.member-result-item');
        if (memberItem) {
            document.getElementById('addMemberId').value = memberItem.dataset.memberId || '';
            document.getElementById('memberSearchInput').value = memberItem.dataset.memberName || '';

            document.getElementById('selectedMemberName').textContent = memberItem.dataset.memberName || '-';
            document.getElementById('selectedMemberMeta').textContent =
                (memberItem.dataset.memberNumber || '') +
                (memberItem.dataset.memberPhone ? ' - ' + memberItem.dataset.memberPhone : '');

            document.getElementById('selectedMemberCard').classList.add('visible');
            document.getElementById('memberResults').classList.remove('open');
            return;
        }

        var filterBtn = e.target.closest('.filter-tab');
        if (filterBtn && filterBtn.dataset.filter) {
            setFilter(filterBtn.dataset.filter);
            return;
        }

        var statCard = e.target.closest('.stat-card[data-filter]');
        if (statCard) {
            setFilter(statCard.dataset.filter);
            return;
        }

        var approveBtn = e.target.closest('.js-approve');
        if (approveBtn) {
            document.getElementById('approveSubId').value = approveBtn.dataset.id || '';
            document.getElementById('approveName').textContent = approveBtn.dataset.name || '-';
            document.getElementById('approvePlan').textContent = approveBtn.dataset.plan || '-';
            document.getElementById('approveAmount').textContent = 'UGX ' + (approveBtn.dataset.amount || '0');
            openOverlay('approveOverlay');
            return;
        }

        var rejectBtn = e.target.closest('.js-reject');
        if (rejectBtn) {
            document.getElementById('rejectSubId').value = rejectBtn.dataset.id || '';
            document.getElementById('rejectName').textContent = rejectBtn.dataset.name || '-';

            var reason = document.getElementById('rejectReasonInput');
            if (reason) reason.value = '';

            openOverlay('rejectOverlay');
            return;
        }

        var lightboxBtn = e.target.closest('.js-lightbox');
        if (lightboxBtn) {
            var photo = lightboxBtn.dataset.photo || '';
            var member = lightboxBtn.dataset.member || '';

            if (!photo) {
                alert('Receipt image was not found.');
                return;
            }

            document.getElementById('lightboxImg').src = photo;
            document.getElementById('lightboxTitle').textContent = 'Receipt - ' + member;
            openOverlay('lightbox');
            return;
        }

        var closeBtn = e.target.closest('[data-close-modal]');
        if (closeBtn) {
            closeOverlay(closeBtn.dataset.closeModal);
            return;
        }

        if (e.target.classList.contains('modal-overlay')) {
            closeOverlay(e.target.id);
            return;
        }

        if (e.target.id === 'lightbox') {
            closeLightbox();
        }
    });

    var memberSearchInput = document.getElementById('memberSearchInput');
    var memberResults = document.getElementById('memberResults');

    if (memberSearchInput && memberResults) {
        memberSearchInput.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            var shown = 0;

            document.querySelectorAll('.member-result-item').forEach(function (item) {
                var ok = q && (item.dataset.search || '').includes(q);
                item.style.display = ok ? 'block' : 'none';
                if (ok) shown++;
            });

            memberResults.classList.toggle('open', shown > 0);
            document.getElementById('addMemberId').value = '';
            document.getElementById('selectedMemberCard').classList.remove('visible');
        });
    }

    var macInput = document.getElementById('macAddressInput');
    if (macInput) {
        macInput.addEventListener('input', function () {
            this.value = normalizeMacInput(this.value);
        });
    }

    var addDeviceForm = document.getElementById('addDeviceForm');
    if (addDeviceForm) {
        addDeviceForm.addEventListener('submit', function (e) {
            var memberId = document.getElementById('addMemberId').value;
            var mac = document.getElementById('macAddressInput').value.replace(/[^a-fA-F0-9]/g, '');

            if (!memberId) {
                e.preventDefault();
                alert('Please search and select a member first.');
                return;
            }

            if (mac.length !== 12) {
                e.preventDefault();
                alert('Please enter a valid MAC address.');
            }
        });
    }

    var closeLb = document.getElementById('closeLightboxBtn');
    if (closeLb) {
        closeLb.addEventListener('click', closeLightbox);
    }

    var searchEl = document.getElementById('searchInput');
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            activeSearch = this.value.trim().toLowerCase();
            applyFilters();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;

        closeOverlay('addDeviceOverlay');
        closeOverlay('approveOverlay');
        closeOverlay('rejectOverlay');
        closeLightbox();
    });

    applyFilters();
});
</script>

</div><!-- /.page-internet-subs -->

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>