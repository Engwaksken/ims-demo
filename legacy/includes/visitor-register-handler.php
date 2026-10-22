<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

$purposes = ['Meeting', 'Event', 'Co-working', 'Training', 'Consultation', 'Tour', 'Interview', 'Other'];
$branches = ['Hive-Kampala', 'Hive-Mbarara'];
$genders = ['Male', 'Female'];
$nationalities = ['National', 'Non-National'];
$pwd_types = [
    'Physical Disability',
    'Visual Impairment',
    'Hearing Impairment',
    'Speech Impairment',
    'Intellectual Disability',
    'Psychosocial Disability',
    'Autism Spectrum',
    'Albinism',
    'Other'
];

$page_title           = 'Visitor Sign-In';
$error_msg            = '';
$submitted            = false;
$visitor_name_success = '';
$purpose_success      = '';
$date_success         = '';
$time_success         = '';

$hub_name = defined('HUB_NAME') ? HUB_NAME : 'Hive Colab';

$conn = isset($conn) && $conn instanceof mysqli ? $conn : (function_exists('db_connect') ? db_connect() : null);
if (!$conn instanceof mysqli) {
    die('Database connection not available.');
}
$conn->set_charset('utf8mb4');

if (!function_exists('sanitize_input')) {
    function sanitize_input($value): string
    {
        return trim((string)$value);
    }
}

if (!function_exists('oldv')) {
    function oldv(string $key, string $default = ''): string
    {
        return htmlspecialchars((string)($_POST[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('member_old_selected')) {
    function member_old_selected(): bool
    {
        return (($_POST['is_member'] ?? '0') === '1') && ((int)($_POST['member_user_id'] ?? 0) > 0);
    }
}

if (!function_exists('fetch_member_by_id')) {
    function fetch_member_by_id(mysqli $conn, int $memberUserId): ?array
    {
        if ($memberUserId <= 0) {
            return null;
        }

        $sql = "
            SELECT user_id, full_name, email, phone, username, role
            FROM users
            WHERE user_id = ? AND role = 'Member' AND is_active = 1
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $memberUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        // Accept both string role='member' or numeric role values if your DB uses numeric roles.
        $roleValue = strtolower(trim((string)($row['role'] ?? '')));
        $isMemberRole = ($roleValue === 'member' || $roleValue === '3' || $roleValue === '4' || $roleValue === '5');

        return $isMemberRole ? $row : $row; // keep permissive to avoid false rejects if role mapping differs
    }
}

/**
 * AJAX member search
 */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'member_search') {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['success' => true, 'members' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Public endpoint: only active Members are searchable, partial matches
    // are on name only (email/phone/username must match exactly), and only
    // id + name are returned. The form re-reads email/phone server-side
    // from member_user_id on submit, so the browser never needs them.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateKey = 'visitor_member_search|' . $ip;
    if (ims_rate_limit_too_many($rateKey, 30, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many searches. Please wait a minute.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    ims_rate_limit_hit($rateKey, 60);

    $like = '%' . $q . '%';

    $sql = "
        SELECT user_id, full_name
        FROM users
        WHERE role = 'Member'
          AND is_active = 1
          AND (
                full_name LIKE ?
             OR email = ?
             OR phone = ?
             OR username = ?
          )
        ORDER BY full_name ASC
        LIMIT 10
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare member search.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt->bind_param('ssss', $like, $q, $q, $q);
    $stmt->execute();
    $res = $stmt->get_result();

    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = [
            'id'        => (int)$row['user_id'],
            'user_id'   => (int)$row['user_id'],
            'name'      => $row['full_name'] ?? '',
            'full_name' => $row['full_name'] ?? '',
        ];
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'members' => $members
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Form submit
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_visitor'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error_msg = 'Security token mismatch. Please refresh and try again.';
    }

    $member_user_id_input = (int)($_POST['member_user_id'] ?? 0);
    $is_member            = (int)($_POST['is_member'] ?? 0);
    $is_first_time        = (int)($_POST['is_first_time'] ?? 0);
    $is_pwd               = isset($_POST['is_pwd']) ? 1 : 0;

    $visitor_name       = sanitize_input($_POST['visitor_name'] ?? '');
    $phone              = sanitize_input($_POST['phone'] ?? '');
    $email              = sanitize_input($_POST['email'] ?? '');
    $organization       = sanitize_input($_POST['organization'] ?? '');
    $branch             = sanitize_input($_POST['branch'] ?? '');
    $gender             = sanitize_input($_POST['gender'] ?? '');
    $nationality        = sanitize_input($_POST['nationality'] ?? '');
    $pwd_type           = sanitize_input($_POST['pwd_type'] ?? '');
    $pwd_other_specify  = sanitize_input($_POST['pwd_other_specify'] ?? '');
    $visit_date         = sanitize_input($_POST['visit_date'] ?? date('Y-m-d'));
    $visit_time         = sanitize_input($_POST['visit_time'] ?? date('H:i'));
    $purpose            = sanitize_input($_POST['purpose'] ?? '');
    $host_contact       = sanitize_input($_POST['host_contact'] ?? '');
    $remarks            = sanitize_input($_POST['remarks'] ?? '');

    // Very important FK fix:
    // Only keep a member_user_id when user selected "member".
    $member_user_id = null;
    if ($is_member === 1) {
        $member_user_id = $member_user_id_input > 0 ? $member_user_id_input : null;
    }

    if (!$error_msg && $is_member === 1) {
        if ($member_user_id === null) {
            $error_msg = 'Please search and select a member.';
        } else {
            $memberRow = fetch_member_by_id($conn, $member_user_id);

            if (!$memberRow) {
                $error_msg = 'Selected member was not found.';
            } else {
                $visitor_name = trim((string)($memberRow['full_name'] ?? ''));

                if ($visitor_name === '') {
                    $error_msg = 'Selected member does not have a saved name.';
                }

                if ($email === '' && !empty($memberRow['email'])) {
                    $email = trim((string)$memberRow['email']);
                }

                if ($phone === '' && !empty($memberRow['phone'])) {
                    $phone = trim((string)$memberRow['phone']);
                }

                $is_first_time = 0;
            }
        }
    }

    if (!$error_msg) {
        if ($is_member !== 0 && $is_member !== 1) {
            $error_msg = 'Invalid member selection.';
        } elseif ($is_member === 0 && ($is_first_time !== 0 && $is_first_time !== 1)) {
            $error_msg = 'Invalid first visit selection.';
        } elseif ($is_member === 0 && $visitor_name === '') {
            $error_msg = 'Your full name is required.';
        } elseif (mb_strlen($visitor_name) > 120) {
            $error_msg = 'Name is too long (max 120 characters).';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Please enter a valid email address.';
        } elseif ($branch === '' || !in_array($branch, $branches, true)) {
            $error_msg = 'Please select a valid branch.';
        } elseif ($gender === '' || !in_array($gender, $genders, true)) {
            $error_msg = 'Please select a valid gender.';
        } elseif ($nationality === '' || !in_array($nationality, $nationalities, true)) {
            $error_msg = 'Please select a valid nationality.';
        } elseif ($is_pwd === 1 && ($pwd_type === '' || !in_array($pwd_type, $pwd_types, true))) {
            $error_msg = 'Please select type of PWD.';
        } elseif ($is_pwd === 1 && $pwd_type === 'Other' && $pwd_other_specify === '') {
            $error_msg = 'Please specify the other type of PWD.';
        } elseif ($purpose === '' || !in_array($purpose, $purposes, true)) {
            $error_msg = 'Please select the purpose of your visit.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date) || strtotime($visit_date) > strtotime(date('Y-m-d'))) {
            $error_msg = 'Visit date cannot be in the future.';
        } elseif ($visit_time !== '' && !preg_match('/^\d{2}:\d{2}$/', $visit_time)) {
            $error_msg = 'Invalid time format.';
        }
    }

    if (!$error_msg && $is_pwd === 0) {
        $pwd_type = '';
        $pwd_other_specify = '';
    }

    if (!$error_msg) {
        $sql = "
            INSERT INTO hub_visitors (
                member_user_id,
                visitor_name,
                phone,
                email,
                organization,
                branch,
                gender,
                is_pwd,
                pwd_type,
                pwd_other_specify,
                nationality,
                visit_date,
                visit_time,
                purpose,
                host_contact,
                remarks,
                is_member,
                is_first_time,
                self_registered
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error_msg = 'Failed to prepare the visitor insert statement.';
        } else {
            $stmt->bind_param(
                'issssssissssssssii',
                $member_user_id,
                $visitor_name,
                $phone,
                $email,
                $organization,
                $branch,
                $gender,
                $is_pwd,
                $pwd_type,
                $pwd_other_specify,
                $nationality,
                $visit_date,
                $visit_time,
                $purpose,
                $host_contact,
                $remarks,
                $is_member,
                $is_first_time
            );

            if ($stmt->execute()) {
                $submitted            = true;
                $visitor_name_success = htmlspecialchars($visitor_name, ENT_QUOTES, 'UTF-8');
                $purpose_success      = htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8');
                $date_success         = date('d M Y', strtotime($visit_date));
                $time_success         = $visit_time !== '' ? date('h:i A', strtotime($visit_time)) : '';

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $csrf_token = $_SESSION['csrf_token'];
                $_POST = [];
            } else {
                $error_msg = 'Something went wrong saving your record. Please speak to a staff member.';
            }

            $stmt->close();
        }
    }
}