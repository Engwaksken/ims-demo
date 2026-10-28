<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

$conn = $conn ?? null;

if (!$conn instanceof mysqli) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function normalizeMac(string $mac): string {
    $mac = strtolower(trim($mac));
    $mac = preg_replace('/[^a-f0-9]/', '', $mac);

    if (strlen($mac) !== 12) {
        return '';
    }

    return implode(':', str_split($mac, 2));
}

function minutesLeft(?string $expiresAt): int {
    if (empty($expiresAt)) {
        return 0;
    }

    $left = strtotime($expiresAt) - time();
    return max(0, (int)ceil($left / 60));
}

function formatRemaining(int $minutes): string {
    if ($minutes <= 0) {
        return 'Unlimited / Not set';
    }

    if ($minutes < 60) {
        return $minutes . ' minute(s)';
    }

    if ($minutes < 1440) {
        return round($minutes / 60, 1) . ' hour(s)';
    }

    return round($minutes / 1440, 1) . ' day(s)';
}

$status = $_GET['status'] ?? '';
$mac    = normalizeMac((string)($_GET['mac'] ?? $_GET['client_mac'] ?? ''));

$brand       = ims_brand();
$redirectUrl = preg_match('/\Ahttps?:\/\//i', $brand['website']) ? $brand['website'] : '/';

$device = null;
$error  = '';

if ($status === 'active' && $mac !== '') {
    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.member_id,
            s.mac_address,
            s.status,
            s.starts_at,
            s.expires_at,
            s.created_at,
            p.plan_name,
            p.duration_minutes,
            p.bandwidth_limit,
            m.membership_number,
            m.company_name,
            u.full_name,
            u.phone
        FROM internet_subscriptions s
        INNER JOIN internet_plans p ON p.id = s.plan_id
        INNER JOIN members m ON m.member_id = s.member_id
        INNER JOIN users u ON u.user_id = m.user_id
        WHERE LOWER(REPLACE(REPLACE(s.mac_address, ':', ''), '-', '')) =
              LOWER(REPLACE(REPLACE(?, ':', ''), '-', ''))
          AND s.status = 'active'
          AND (
                s.expires_at IS NULL
                OR s.expires_at >= NOW()
              )
        ORDER BY s.id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('s', $mac);
        $stmt->execute();
        $device = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($device) {
        $logTable = $conn->query("SHOW TABLES LIKE 'internet_connection_logs'");

        if ($logTable && $logTable->num_rows > 0) {
            $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $logStmt = $conn->prepare("
                INSERT INTO internet_connection_logs (
                    subscription_id,
                    member_id,
                    mac_address,
                    ip_address,
                    user_agent,
                    connected_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");

            if ($logStmt) {
                $subId    = (int)$device['id'];
                $memberId = (int)$device['member_id'];

                $logStmt->bind_param(
                    'iisss',
                    $subId,
                    $memberId,
                    $mac,
                    $ip,
                    $userAgent
                );

                $logStmt->execute();
                $logStmt->close();
            }
        }
    } else {
        $error = 'Your internet access could not be verified or has expired.';
    }
} else {
    $error = 'Invalid connection request.';
}

$remainingMinutes = $device ? minutesLeft($device['expires_at'] ?? null) : 0;
$remainingText    = $device ? formatRemaining($remainingMinutes) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Internet Connected - <?= h($brand['name']) ?></title>
    <link rel="icon" href="<?= h($brand['favicon']) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php if ($device): ?>
        <meta http-equiv="refresh" content="5;url=<?= h($redirectUrl) ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">

    <style>
/* Links: no underlines (matches css/style.css); visible keyboard focus. */
a, a:hover, a:focus, a:active, a:visited { text-decoration: none; }
/* Accent colours follow the configured site theme, with the original palette as fallback. */
:root {
    --ic-accent: var(--brand-600, #ff6b35);
    --ic-accent-hover: var(--brand-secondary, var(--brand-500, #ff9800));
    --ic-page-bg: var(--brand-100, #ff6b002b);
}
:where(a):focus-visible { outline: 2px solid var(--ic-accent); outline-offset: 2px; }
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: var(--ic-page-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .box {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 20px 45px rgba(0,0,0,.25);
            text-align: center;
        }

        .icon {
            font-size: 58px;
            color: #16a34a;
            margin-bottom: 14px;
        }

        .icon.error {
            color: #dc2626;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 25px;
            color: #111827;
        }

        p {
            color: #4b5563;
            font-size: 15px;
            margin: 8px 0 20px;
        }

        .details {
            text-align: left;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            margin: 20px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px dashed #d1d5db;
            font-size: 14px;
        }

        .row:last-child {
            border-bottom: none;
        }

        .label {
            color: #64748b;
            font-weight: 600;
        }

        .value {
            color: #111827;
            font-weight: 700;
            text-align: right;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--ic-accent);
            color: #fff;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 10px;
            font-weight: 700;
        }

        .btn:hover {
            background: var(--ic-accent-hover);
        }

        .muted {
            margin-top: 14px;
            font-size: 13px;
            color: #64748b;
        }
    </style>
    <?= ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])) ?>
<script src="js/accessibility.js"></script>
</head>
<body>

<div class="box">
    <?php if ($device): ?>
        <div class="icon">
            <i class="fas fa-check-circle"></i>
        </div>

        <h1>Connected Successfully</h1>
        <p>Your device has been authorized. Redirecting to the <?= h($brand['name']) ?> website...</p>

       <div class="details">

    <div class="row">
        <span class="label">Connection Status</span>
        <span class="value" style="color:#16a34a;">Active</span>
    </div>

    <div class="row">
        <span class="label">Plan</span>
        <span class="value"><?= h($device['plan_name'] ?? 'Internet Plan') ?></span>
    </div>

    <div class="row">
        <span class="label">Remaining Time</span>
        <span class="value"><?= h($remainingText) ?></span>
    </div>

</div>
        <a href="<?= h($redirectUrl) ?>" class="btn">
            <i class="fas fa-arrow-right"></i>
            Continue Now
        </a>

        <div class="muted">
            Auto redirecting in 5 seconds...
        </div>

    <?php else: ?>
        <div class="icon error">
            <i class="fas fa-times-circle"></i>
        </div>

        <h1>Connection Failed</h1>
        <p><?= h($error) ?></p>

        <a href="internet_portal" class="btn">
            <i class="fas fa-redo"></i>
            Try Again
        </a>
    <?php endif; ?>
</div>

</body>
</html>