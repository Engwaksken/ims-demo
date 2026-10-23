<?php
ob_start();

date_default_timezone_set('Africa/Nairobi');

$page_title = 'Internet Connection Logs';
require_once 'includes/header.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+03:00'");

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$q        = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));
$export   = trim((string)($_GET['export'] ?? ''));

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $where[] = "(
        l.mac_address LIKE ?
        OR l.ip_address LIKE ?
        OR m.membership_number LIKE ?
        OR u.full_name LIKE ?
        OR p.plan_name LIKE ?
    )";

    $like = '%' . $q . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($dateFrom !== '') {
    $where[] = "DATE(l.connected_at) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = "DATE(l.connected_at) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| BASE QUERY
|--------------------------------------------------------------------------
*/
$baseSql = "
    FROM internet_connection_logs l
    LEFT JOIN internet_subscriptions s ON s.id = l.subscription_id
    LEFT JOIN internet_plans p ON p.id = s.plan_id
    LEFT JOIN members m ON m.member_id = l.member_id
    LEFT JOIN users u ON u.user_id = m.user_id
    $whereSql
";

/*
|--------------------------------------------------------------------------
| CSV EXPORT
|--------------------------------------------------------------------------
*/
if ($export === 'csv') {
    $sql = "
        SELECT
            l.connected_at,
            l.mac_address,
            l.ip_address,
            p.plan_name,
            m.membership_number,
            u.full_name
        $baseSql
        ORDER BY l.connected_at DESC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt && $types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=internet_connection_logs_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');

        ims_fputcsv($out, [
            'Connected At',
            'MAC Address',
            'IP Address',
            'Plan',
            'Membership Number',
            'Member Name',
        ]);

        while ($row = $res->fetch_assoc()) {
            ims_fputcsv($out, [
                $row['connected_at'] ?? '',
                $row['mac_address'] ?? '',
                $row['ip_address'] ?? '',
                $row['plan_name'] ?? '',
                $row['membership_number'] ?? '',
                $row['full_name'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| KPI COUNTS
|--------------------------------------------------------------------------
*/
$totalConnections = 0;
$todayConnections = 0;
$uniqueDevices = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM internet_connection_logs");
if ($res) {
    $totalConnections = (int)$res->fetch_assoc()['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM internet_connection_logs WHERE DATE(connected_at) = CURDATE()");
if ($res) {
    $todayConnections = (int)$res->fetch_assoc()['total'];
}

$res = $conn->query("SELECT COUNT(DISTINCT mac_address) AS total FROM internet_connection_logs");
if ($res) {
    $uniqueDevices = (int)$res->fetch_assoc()['total'];
}

/*
|--------------------------------------------------------------------------
| TOTAL FILTERED
|--------------------------------------------------------------------------
*/
$countSql = "SELECT COUNT(*) AS total $baseSql";
$countStmt = $conn->prepare($countSql);

if ($countStmt && $types !== '') {
    $countStmt->bind_param($types, ...$params);
}

$totalRows = 0;

if ($countStmt) {
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
}

$totalPages = max(1, (int)ceil($totalRows / $limit));

/*
|--------------------------------------------------------------------------
| FETCH LOGS
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        l.id,
        l.subscription_id,
        l.member_id,
        l.mac_address,
        l.ip_address,
        l.user_agent,
        l.connected_at,
        p.plan_name,
        p.duration_minutes,
        s.expires_at,
        s.status AS subscription_status,
        m.membership_number,
        u.full_name,
        u.phone
    $baseSql
    ORDER BY l.connected_at DESC
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listTypes = $types . 'ii';
$listParams[] = $limit;
$listParams[] = $offset;

$stmt = $conn->prepare($sql);

$logs = [];

if ($stmt) {
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }

    $stmt->close();
}

function remainingTime(?string $expiresAt): string {
    if (empty($expiresAt)) {
        return 'Not set';
    }

    $left = strtotime($expiresAt) - time();

    if ($left <= 0) {
        return 'Expired';
    }

    $minutes = (int)ceil($left / 60);

    if ($minutes < 60) {
        return $minutes . ' min';
    }

    if ($minutes < 1440) {
        return round($minutes / 60, 1) . ' hrs';
    }

    return round($minutes / 1440, 1) . ' days';
}

$queryString = $_GET;
unset($queryString['page']);
$baseLink = '?' . http_build_query($queryString);
$baseLink .= $baseLink === '?' ? '' : '&';
?>
<style>
        .page-conn-logs .table { min-width: 1000px; }
        .page-conn-logs .pagination { justify-content: flex-end; align-items: center; gap: 8px; margin-top: 0; }
        .page-conn-logs .pagination .current {
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            background: var(--brand-500);
            color: var(--ink-0);
            border: 1px solid var(--brand-500);
        }
    </style>

<div class="page-stack page-conn-logs">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-network-wired" aria-hidden="true"></i> Internet Connection Logs</h1>
            <p>Monitor connected devices, active sessions, and connection history.</p>
        </div>

        <div class="hero-actions">
            <a class="btn btn-primary" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
                <i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV
            </a>
        </div>
    </div>

    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-plug" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total Connections</span>
                <span class="stat-value"><?= number_format($totalConnections) ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Today Connections</span>
                <span class="stat-value"><?= number_format($todayConnections) ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-laptop" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Unique Devices</span>
                <span class="stat-value"><?= number_format($uniqueDevices) ?></span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list" aria-hidden="true"></i> Connection History</h3>
        </div>
        <div class="card-body">
    <form method="get" class="filters">
        <div class="filter-grid filters-bar" role="search">
            <div class="form-group filters-grow">
                <label>Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Search MAC, IP, member, plan...">
            </div>

            <div class="form-group">
                <label>Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>

            <div class="form-group">
                <label>Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>

            <button class="btn btn-primary" type="submit">Apply</button>

            <a href="internet_connection_logs" class="btn btn-secondary">Clear</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Connected At</th>
                    <th>Member</th>
                    <th>Membership No.</th>
                    <th>MAC Address</th>
                    <th>IP Address</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Remaining</th>
                    <th>User Agent</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="10"><div class="empty-state"><i class="fas fa-inbox" aria-hidden="true"></i><span>No connection logs found.</span></div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $index => $log): ?>
                        <?php
                            $remaining = remainingTime($log['expires_at'] ?? null);
                            $isActive = strtolower((string)($log['subscription_status'] ?? '')) === 'active'
                                && $remaining !== 'Expired';
                        ?>
                        <tr>
                            <td><?= $offset + $index + 1 ?></td>
                            <td><?= h($log['connected_at'] ?? '') ?></td>
                            <td><?= h($log['full_name'] ?? '-') ?></td>
                            <td><?= h($log['membership_number'] ?? '-') ?></td>
                            <td><?= h($log['mac_address'] ?? '-') ?></td>
                            <td><?= h($log['ip_address'] ?? '-') ?></td>
                            <td><?= h($log['plan_name'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= $isActive ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $isActive ? 'Active' : 'Expired' ?>
                                </span>
                            </td>
                            <td><?= h($remaining) ?></td>
                            <td class="cell-muted"><?= h(substr((string)($log['user_agent'] ?? '-'), 0, 80)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
        </div>
    </div>

    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= h($baseLink . 'page=' . ($page - 1)) ?>">Prev</a>
        <?php endif; ?>

        <span class="current">
            Page <?= $page ?> of <?= $totalPages ?>
        </span>

        <?php if ($page < $totalPages): ?>
            <a href="<?= h($baseLink . 'page=' . ($page + 1)) ?>">Next</a>
        <?php endif; ?>
    </div>

</div><!-- /.page-conn-logs -->

<?php include 'includes/footer.php'; ?>
