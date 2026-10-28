<?php
declare(strict_types=1);

if (!defined('PORTAL_ACCESS')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

date_default_timezone_set('Africa/Nairobi');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatDuration')) {
    function formatDuration(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        if ($minutes < 1440) return round($minutes / 60, 1) . ' hr';
        return round($minutes / 1440, 1) . ' day(s)';
    }
}

if (!function_exists('eventIcon')) {
    function eventIcon(string $type): string {
        $map = [
            'workshop'   => 'fa-chalkboard-teacher',
            'meetup'     => 'fa-users',
            'hackathon'  => 'fa-laptop-code',
            'conference' => 'fa-microphone',
            'networking' => 'fa-handshake',
            'training'   => 'fa-graduation-cap',
            'seminar'    => 'fa-book-open',
            'pitch'      => 'fa-rocket',
            'demo'       => 'fa-desktop',
            'social'     => 'fa-glass-cheers',
        ];

        return $map[strtolower(trim($type))] ?? 'fa-calendar-day';
    }
}

if (!function_exists('normalizeMac')) {
    function normalizeMac(string $mac): string {
        $mac = strtolower(trim($mac));
        $mac = preg_replace('/[^a-f0-9]/', '', $mac);

        if (strlen((string)$mac) !== 12) {
            return '';
        }

        return implode(':', str_split((string)$mac, 2));
    }
}

if (!function_exists('portalDefaultRedirectUrl')) {
    /** The organisation website (Settings > General), used when no redirect URL is given. */
    function portalDefaultRedirectUrl(): string {
        $site = function_exists('ims_brand') ? trim((string)ims_brand()['website']) : '';
        return preg_match('/^https?:\/\//i', $site) ? $site : '/';
    }
}

if (!function_exists('portalRedirectUrl')) {
    function portalRedirectUrl(string $url): string {
        $url = trim($url);

        if ($url === '' || strtolower($url) === 'null') {
            return portalDefaultRedirectUrl();
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            return portalDefaultRedirectUrl();
        }

        return $url;
    }
}

if (!function_exists('pfsenseAuthorizeUrl')) {
    function pfsenseAuthorizeUrl(string $clientMac, string $zone, string $redirurl): string {
        $clientMac = normalizeMac($clientMac);
        $zone      = trim($zone) !== '' ? trim($zone) : 'lan';
        $redirurl  = portalRedirectUrl($redirurl);

        return 'http://192.168.1.1:8000/index.php?' . http_build_query([
            'zone'      => $zone,
            'auth_user' => $clientMac,
            'auth_pass' => $clientMac,
            'redirurl'  => $redirurl,
        ]);
    }
}

if (!function_exists('autoAuthorizeInternetClient')) {
    function autoAuthorizeInternetClient(
        string $clientMac,
        string $apMac,
        string $site,
        int $minutes,
        string $zone = 'lan',
        string $redirurl = ''
    ): bool {
        $clientMac = normalizeMac($clientMac);

        if ($clientMac === '') {
            return false;
        }

        /*
         * UniFi support, if your UniFi helper exists.
         */
        if ($apMac !== '' && function_exists('authorizeUniFiClient')) {
            return (bool) authorizeUniFiClient($clientMac, $apMac, $site, $minutes);
        }

        /*
         * pfSense captive portal login redirect.
         * We return true because the actual authorization happens by redirecting
         * the browser to pfSense login URL.
         */
        return true;
    }
}

if (!function_exists('saveReceiptPhoto')) {
    function saveReceiptPhoto(array $file, string &$errorOut): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory is missing.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A server extension stopped the upload.',
            ];

            $errorOut = $uploadErrors[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Unknown upload error.';
            return null;
        }

        if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
            $errorOut = 'Receipt image must be under 5 MB.';
            return null;
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errorOut = 'Invalid uploaded file.';
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)$finfo->file($file['tmp_name']);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
        ];

        if (!isset($allowedMimes[$mimeType])) {
            $errorOut = 'Only receipt images are accepted: JPG, PNG, WEBP, GIF, HEIC.';
            return null;
        }

        $uploadDir = defined('PORTAL_RECEIPTS_DIR')
            ? rtrim((string)PORTAL_RECEIPTS_DIR, '/\\') . DIRECTORY_SEPARATOR
            : __DIR__ . '/../uploads/receipts/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $errorOut = 'Could not create receipt upload folder.';
            return null;
        }

        $ext = $allowedMimes[$mimeType];
        $filename = 'rcpt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $errorOut = 'Failed to save receipt image.';
            return null;
        }

        return 'uploads/receipts/' . $filename;
    }
}

if (!isset($conn) || !$conn instanceof mysqli) {
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+03:00'");

/*
|--------------------------------------------------------------------------
| CAPTURE pfSense / UniFi PARAMS
|--------------------------------------------------------------------------
*/
$clientMac = normalizeMac((string)(
    $_GET['clientmac']
    ?? $_GET['client_mac']
    ?? $_GET['id']
    ?? $_GET['mac']
    ?? ''
));

$clientIp = trim((string)(
    $_GET['clientip']
    ?? $_GET['client_ip']
    ?? $_GET['ip']
    ?? ''
));

$apMac = normalizeMac((string)(
    $_GET['ap']
    ?? $_GET['ap_mac']
    ?? ''
));

$site = trim((string)(
    $_GET['site']
    ?? $_GET['zone']
    ?? 'default'
));

$zone = trim((string)(
    $_GET['zone']
    ?? 'lan'
));

$redirurl = portalRedirectUrl((string)(
    $_GET['redirurl']
    ?? $_GET['redir_url']
    ?? ''
));

$error = '';
$success = false;

/*
|--------------------------------------------------------------------------
| AUTO CONNECT EXISTING ACTIVE MAC
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $clientMac !== '') {
    $autoStmt = $conn->prepare("
        SELECT
            s.id,
            s.member_id,
            s.mac_address,
            s.status,
            s.starts_at,
            s.expires_at,
            p.duration_minutes,
            u.is_active
        FROM internet_subscriptions s
        INNER JOIN internet_plans p ON p.id = s.plan_id
        INNER JOIN members m ON m.member_id = s.member_id
        INNER JOIN users u ON u.user_id = m.user_id
        WHERE LOWER(REPLACE(REPLACE(s.mac_address, ':', ''), '-', '')) =
              LOWER(REPLACE(REPLACE(?, ':', ''), '-', ''))
          AND s.status = 'active'
          AND u.is_active = 1
          AND (
                s.expires_at IS NULL
                OR s.expires_at >= NOW()
              )
        ORDER BY s.id DESC
        LIMIT 1
    ");

    if ($autoStmt) {
        $autoStmt->bind_param('s', $clientMac);
        $autoStmt->execute();
        $activeSub = $autoStmt->get_result()->fetch_assoc();
        $autoStmt->close();

        if ($activeSub) {
            $minutes = (int)($activeSub['duration_minutes'] ?? 1440);
            if ($minutes <= 0) {
                $minutes = 1440;
            }

            $connected = autoAuthorizeInternetClient($clientMac, $apMac, $site, $minutes, $zone, $redirurl);

            if ($connected) {
                header('Location: ' . pfsenseAuthorizeUrl($clientMac, $zone, $redirurl));
                exit;
            }

            $error = 'Your subscription is active, but automatic internet authorization failed. Please contact support.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUBMIT NEW INTERNET REQUEST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $membershipNumber = strtoupper(trim((string)($_POST['membership_number'] ?? '')));
    $planId           = (int)($_POST['plan_id'] ?? 0);

    $clientMac = normalizeMac((string)(
        $_POST['client_mac']
        ?? $clientMac
    ));

    $apMac = normalizeMac((string)(
        $_POST['ap_mac']
        ?? $apMac
    ));

    $site = trim((string)(
        $_POST['site']
        ?? $site
    ));

    $zone = trim((string)(
        $_POST['zone']
        ?? $zone
        ?? 'lan'
    ));

    $redirurl = portalRedirectUrl((string)(
        $_POST['redirurl']
        ?? $redirurl
        ?? ''
    ));

    $receiptPhotoPath = null;

    if ($membershipNumber === '') {
        $error = 'Please enter your membership number.';
    } elseif (!preg_match('/^MEM-\d{4}-\d{4}$/', $membershipNumber)) {
        $error = 'Invalid membership number format. Example: MEM-2026-0001.';
    } elseif ($planId <= 0) {
        $error = 'Please select an internet plan.';
    } elseif ($clientMac === '') {
        $error = 'Device MAC address was not detected. Please reconnect through the WiFi portal.';
    } else {
        $photoFile = $_FILES['receipt_photo'] ?? null;

        if (!$photoFile || !isset($photoFile['error']) || $photoFile['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please take a photo or upload an image of your receipt.';
        } else {
            $uploadError = '';
            $receiptPhotoPath = saveReceiptPhoto($photoFile, $uploadError);

            if ($receiptPhotoPath === null) {
                $error = $uploadError ?: 'Receipt photo upload failed.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING ACTIVE MAC
    |--------------------------------------------------------------------------
    */
    if ($error === '') {
        $existingStmt = $conn->prepare("
            SELECT
                s.id,
                s.status,
                s.expires_at,
                p.duration_minutes,
                u.is_active
            FROM internet_subscriptions s
            INNER JOIN internet_plans p ON p.id = s.plan_id
            INNER JOIN members m ON m.member_id = s.member_id
            INNER JOIN users u ON u.user_id = m.user_id
            WHERE LOWER(REPLACE(REPLACE(s.mac_address, ':', ''), '-', '')) =
                  LOWER(REPLACE(REPLACE(?, ':', ''), '-', ''))
            ORDER BY s.id DESC
            LIMIT 1
        ");

        if (!$existingStmt) {
            $error = 'Database error: ' . $conn->error;
        } else {
            $existingStmt->bind_param('s', $clientMac);
            $existingStmt->execute();
            $existingMac = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();

            if (
                $existingMac &&
                strtolower((string)$existingMac['status']) === 'active' &&
                (int)$existingMac['is_active'] === 1 &&
                (
                    empty($existingMac['expires_at']) ||
                    strtotime((string)$existingMac['expires_at']) >= time()
                )
            ) {
                if ($receiptPhotoPath !== null) {
                    $absPath = __DIR__ . '/../' . $receiptPhotoPath;
                    if (is_file($absPath)) {
                        @unlink($absPath);
                    }
                }

                header('Location: ' . pfsenseAuthorizeUrl($clientMac, $zone, $redirurl));
                exit;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE MEMBER + PLAN + INSERT REQUEST
    |--------------------------------------------------------------------------
    */
    if ($error === '') {
        $memberStmt = $conn->prepare("
            SELECT
                m.member_id,
                m.membership_number,
                m.company_name,
                u.user_id,
                u.full_name,
                u.role,
                u.phone,
                u.is_active
            FROM members m
            INNER JOIN users u ON u.user_id = m.user_id
            WHERE m.membership_number = ?
            LIMIT 1
        ");

        if (!$memberStmt) {
            $error = 'Database error: ' . $conn->error;
        } else {
            $memberStmt->bind_param('s', $membershipNumber);
            $memberStmt->execute();
            $member = $memberStmt->get_result()->fetch_assoc();
            $memberStmt->close();

            if (!$member) {
                $error = 'Member account was not found. Please check your membership number.';
            } elseif (strtolower((string)($member['role'] ?? '')) !== 'member') {
                $error = 'Only registered members can request internet access.';
            } elseif ((int)($member['is_active'] ?? 0) !== 1) {
                $error = 'Your account is inactive. Please contact the administrator.';
            } else {
                $memberId = (int)$member['member_id'];

                $planStmt = $conn->prepare("
                    SELECT
                        id,
                        plan_name,
                        duration_type,
                        duration_minutes,
                        price,
                        discount_percent,
                        final_price,
                        bandwidth_limit,
                        device_limit,
                        status
                    FROM internet_plans
                    WHERE id = ?
                      AND status = 'active'
                    LIMIT 1
                ");

                if (!$planStmt) {
                    $error = 'Database error: ' . $conn->error;
                } else {
                    $planStmt->bind_param('i', $planId);
                    $planStmt->execute();
                    $plan = $planStmt->get_result()->fetch_assoc();
                    $planStmt->close();

                    if (!$plan) {
                        $error = 'Selected internet plan is not available.';
                    } else {
                        $pendingStmt = $conn->prepare("
                            SELECT id
                            FROM internet_subscriptions
                            WHERE member_id = ?
                              AND status = 'pending'
                            LIMIT 1
                        ");

                        if (!$pendingStmt) {
                            $error = 'Database error: ' . $conn->error;
                        } else {
                            $pendingStmt->bind_param('i', $memberId);
                            $pendingStmt->execute();
                            $pending = $pendingStmt->get_result()->fetch_assoc();
                            $pendingStmt->close();

                            if ($pending) {
                                $error = 'You already have a pending request. Please wait for admin approval.';

                                if ($receiptPhotoPath !== null) {
                                    $absPath = __DIR__ . '/../' . $receiptPhotoPath;
                                    if (is_file($absPath)) {
                                        @unlink($absPath);
                                    }
                                }
                            } else {
                                $amountPaid = (float)$plan['final_price'];

                                $insert = $conn->prepare("
                                    INSERT INTO internet_subscriptions (
                                        member_id,
                                        mac_address,
                                        plan_id,
                                        amount_paid,
                                        starts_at,
                                        expires_at,
                                        status,
                                        receipt_number,
                                        receipt_photo,
                                        approved_by,
                                        approved_at,
                                        rejection_reason,
                                        created_at
                                    ) VALUES (
                                        ?, ?, ?, ?, NULL, NULL, 'pending', NULL, ?, NULL, NULL, NULL, NOW()
                                    )
                                ");

                                if (!$insert) {
                                    $error = 'Database error: ' . $conn->error;
                                } else {
                                    $insert->bind_param(
                                        'isids',
                                        $memberId,
                                        $clientMac,
                                        $planId,
                                        $amountPaid,
                                        $receiptPhotoPath
                                    );

                                    if ($insert->execute()) {
                                        $success = true;
                                    } else {
                                        $error = 'Failed to submit request. Please try again.';

                                        if ($receiptPhotoPath !== null) {
                                            $absPath = __DIR__ . '/../' . $receiptPhotoPath;
                                            if (is_file($absPath)) {
                                                @unlink($absPath);
                                            }
                                        }
                                    }

                                    $insert->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| FETCH ACTIVE PLANS
|--------------------------------------------------------------------------
*/
$plans = [];

$plansRes = $conn->query("
    SELECT
        id,
        plan_name,
        duration_type,
        duration_minutes,
        price,
        discount_percent,
        final_price,
        bandwidth_limit,
        device_limit
    FROM internet_plans
    WHERE status = 'active'
    ORDER BY FIELD(duration_type, 'daily', 'monthly', 'annual'), final_price ASC
");

if ($plansRes) {
    while ($row = $plansRes->fetch_assoc()) {
        $plans[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| FETCH EVENTS
|--------------------------------------------------------------------------
*/
$events = [];

$evRes = $conn->query("
    SELECT
        event_title,
        event_type,
        description,
        event_date,
        venue,
        start_time,
        end_time
    FROM hub_events
    WHERE event_date >= CURDATE()
    ORDER BY event_date ASC, start_time ASC
    LIMIT 6
");

if ($evRes) {
    while ($row = $evRes->fetch_assoc()) {
        $events[] = $row;
    }
}

if (empty($events)) {
    $evResFallback = $conn->query("
        SELECT
            event_title,
            event_type,
            description,
            event_date,
            venue,
            start_time,
            end_time
        FROM hub_events
        ORDER BY event_date DESC
        LIMIT 4
    ");

    if ($evResFallback) {
        while ($row = $evResFallback->fetch_assoc()) {
            $events[] = $row;
        }
    }
}

$gradients = [
    'linear-gradient(135deg, #0D1321 0%, #1E3A5F 100%)',
    'linear-gradient(135deg, #1A0533 0%, #3B0764 100%)',
    'linear-gradient(135deg, #0C2340 0%, #1C4B82 100%)',
    'linear-gradient(135deg, #0D2818 0%, #14532D 100%)',
    'linear-gradient(135deg, #2D1515 0%, #7F1D1D 100%)',
    'linear-gradient(135deg, #1A1A2E 0%, #16213E 50%, #0F3460 100%)',
];

$planIconMap = [
    'daily'   => 'fa-calendar-day',
    'monthly' => 'fa-calendar-alt',
    'annual'  => 'fa-crown',
];
?>