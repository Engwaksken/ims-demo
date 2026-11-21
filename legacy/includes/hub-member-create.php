<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Create a hub member (company) outside Hub Operations
|--------------------------------------------------------------------------
| Used by Hub Team & Internet > Guests > Make member > "New company". Same
| result as Hub Operations > Add member: a Member login (temporary password
| emailed), a members row with a membership number, plan, dates and an
| optional workspace package. The member then shows in Hub Operations and
| in Hub Team & Internet > Companies & devices.
*/

const HUBMEM_TYPES = ['Individual', 'Startup', 'Corporate', 'Student', 'Freelancer'];
const HUBMEM_PLANS = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Annual'];

if (!function_exists('hubmem_temp_password')) {
    function hubmem_temp_password(int $length = 14): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
}

if (!function_exists('hubmem_send_credentials')) {
    /** Login details for the new member (same wording as Hub Operations). */
    function hubmem_send_credentials(string $email, string $fullName, string $membershipNo, string $plainPassword): string|bool
    {
        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/mail-function.php';
        }
        if (!function_exists('sendEmail') || !function_exists('email_wrapper')) {
            return 'Mail functions unavailable';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        $loginUrl = $host !== '' ? $scheme . '://' . $host . '/login' : (function_exists('email_app_url') ? email_app_url() . 'login' : '/login');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $orgName = function_exists('email_brand') ? (string)email_brand()['name'] : 'the hub';

        $content = "
            <h3>Welcome to " . $e($orgName) . "</h3>
            <p>Hi {$e($fullName)},</p>
            <p>Your hub membership ({$e($membershipNo)}) has been registered and a member account has been created for you.</p>
            <div class='cred-box'>
                <h4>Your Login Credentials</h4>
                <p style='margin:0 0 8px;font-size:14px;'><strong>Email:</strong><br>{$e($email)}</p>
                <p style='margin:8px 0 4px;font-size:14px;'><strong>Temporary Password:</strong></p>
                <div class='password'>{$e($plainPassword)}</div>
                <div class='warning'><strong>Important:</strong> Please change this password after your first login.</div>
            </div>
            <a href='{$e($loginUrl)}' class='btn'>Log In Now</a>
            <p>If the button above does not work, use this link:</p>
            <p><a href='{$e($loginUrl)}'>{$e($loginUrl)}</a></p>
            " . (function_exists('email_signoff') ? email_signoff() : '') . "
        ";
        $alt = "Welcome to {$orgName}\nMembership: {$membershipNo}\nEmail: {$email}\nTemporary Password: {$plainPassword}\n\n"
            . "Login here: {$loginUrl}\nPlease change your password after logging in.";

        return sendEmail($email, 'Your ' . $orgName . ' Member Account', email_wrapper($content), $alt);
    }
}

if (!function_exists('hubmem_create')) {
    /**
     * Creates a hub member. $d: full_name, email, phone, company_name, member_type,
     * subscription_plan, subscription_start_date, subscription_end_date, space_package_id.
     *
     * @return array{ok:bool,message:string,member_id?:int,membership_no?:string}
     */
    function hubmem_create(mysqli $conn, array $d, int $uid): array
    {
        $name = mb_substr(trim((string)($d['full_name'] ?? '')), 0, 150);
        $email = mb_substr(trim((string)($d['email'] ?? '')), 0, 190);
        $phone = mb_substr(trim((string)($d['phone'] ?? '')), 0, 40);
        $company = mb_substr(trim((string)($d['company_name'] ?? '')), 0, 190);
        $type = in_array($d['member_type'] ?? '', HUBMEM_TYPES, true) ? (string)$d['member_type'] : 'Startup';
        $plan = in_array($d['subscription_plan'] ?? '', HUBMEM_PLANS, true) ? (string)$d['subscription_plan'] : '';
        $date = static fn($v): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) && strtotime((string)$v) ? (string)$v : null;
        $start = $date($d['subscription_start_date'] ?? '') ?? date('Y-m-d');
        $end = $date($d['subscription_end_date'] ?? '');
        $packageId = (int)($d['space_package_id'] ?? 0);

        if ($company === '' && $type !== 'Individual' && $type !== 'Freelancer' && $type !== 'Student') {
            return ['ok' => false, 'message' => 'Enter the company name.'];
        }
        if ($name === '') {
            return ['ok' => false, 'message' => 'Enter the contact person\'s name.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter a valid email address for the member login.'];
        }
        if ($plan === '') {
            return ['ok' => false, 'message' => 'Choose the subscription plan.'];
        }
        if ($end !== null && $end < $start) {
            return ['ok' => false, 'message' => 'The end date must be after the start date.'];
        }

        $exists = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $exists->bind_param('s', $email);
        $exists->execute();
        $taken = (bool)$exists->get_result()->fetch_assoc();
        $exists->close();
        if ($taken) {
            return ['ok' => false, 'message' => 'A user with the email ' . $email . ' already exists. Choose that member under "Existing company", or use another email.'];
        }
        if ($packageId > 0) {
            $pk = $conn->prepare('SELECT package_id FROM hub_space_packages WHERE package_id = ? LIMIT 1');
            $pk->bind_param('i', $packageId);
            $pk->execute();
            $packageId = $pk->get_result()->fetch_assoc() ? $packageId : 0;
            $pk->close();
        }

        $plain = hubmem_temp_password();
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 'Member', 1)");
            $stmt->bind_param('ssss', $name, $name, $email, $hash);
            if (!$stmt || !$stmt->execute()) {
                throw new RuntimeException($conn->error ?: 'insert failed');
            }
            $userId = (int)$stmt->insert_id;
            $stmt->close();

            // Next free number for this year (MEM-2026-0042).
            $prefix = 'MEM-' . date('Y') . '-';
            $res = $conn->query("SELECT MAX(CAST(SUBSTRING(membership_number, 10) AS UNSIGNED)) n FROM members WHERE membership_number LIKE '" . $conn->real_escape_string($prefix) . "%'");
            $next = (int)($res ? ($res->fetch_assoc()['n'] ?? 0) : 0) + 1;
            $membershipNo = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("INSERT INTO members (user_id, membership_number, member_type, company_name, registration_date, membership_status,
                                        subscription_plan, subscription_amount, subscription_start_date, subscription_end_date, emergency_contact)
                                    VALUES (?, ?, ?, NULLIF(?, ''), CURDATE(), 'Active', ?, 0, ?, ?, NULLIF(?, ''))");
            $stmt->bind_param('isssssss', $userId, $membershipNo, $type, $company, $plan, $start, $end, $phone);
            if (!$stmt || !$stmt->execute()) {
                throw new RuntimeException($conn->error ?: 'insert failed');
            }
            $memberId = (int)$stmt->insert_id;
            $stmt->close();

            if ($packageId > 0) {
                $stmt = $conn->prepare('UPDATE members SET space_package_id = ? WHERE member_id = ?');
                $stmt->bind_param('ii', $packageId, $memberId);
                if (!$stmt || !$stmt->execute()) {
                throw new RuntimeException($conn->error ?: 'insert failed');
            }
                $stmt->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[hub-member-create] ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The member could not be created. Nothing was saved.'];
        }

        if (function_exists('log_action')) {
            log_action($uid, 'Add Member', 'members', $memberId, 'Added member ' . $membershipNo . ' (' . ($company ?: $name) . ') from Hub Team & Internet');
        }
        $mail = hubmem_send_credentials($email, $name, $membershipNo, $plain);
        unset($plain);

        return ['ok' => true, 'member_id' => $memberId, 'membership_no' => $membershipNo,
            'message' => 'Member ' . $membershipNo . ' (' . ($company ?: $name) . ') was created'
                . ($mail === true ? ' and the login details were emailed.' : ', but the login email could not be sent (reset the password from Users).')];
    }
}
