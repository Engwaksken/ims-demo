<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payment voucher signatures and approval
|--------------------------------------------------------------------------
| Shared by payment-voucher.php, includes/process_voucher.php,
| includes/get_voucher.php, voucher_print.php and download_voucher.php.
|
| Flow: Draft -> Pending Approval -> Approved (or Returned -> Pending ...).
| The preparer's signature defaults to their profile signature
| (employee_directory.signature_path) and can be redrawn or uploaded.
| The approver (Senior Finance / Head of Department) is notified and signs
| the same way. Signatures are copied into the voucher row so a later
| profile change never alters an issued voucher.
*/

const PVA_STATUSES = ['Draft', 'Pending Approval', 'Approved', 'Returned'];
const PVA_MAX_SIGNATURE_BYTES = 2097152; // 2 MB

/** Roles that are never offered as voucher approvers. */
const PVA_NON_STAFF_ROLES = ['Member', 'Applicant', 'Donor/Partner', 'Reviewer'];

if (!function_exists('pva_ensure_schema')) {
    /** Adds the approval columns if the migration has not been run yet. */
    function pva_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $columns = [
            'approval_status' => "VARCHAR(20) NOT NULL DEFAULT 'Draft'",
            'prepared_by' => 'INT NULL',
            'prepared_signature' => 'MEDIUMBLOB NULL',
            'prepared_signature_mime' => 'VARCHAR(40) NULL',
            'prepared_signed_at' => 'DATETIME NULL',
            'approver_id' => 'INT NULL',
            'submitted_at' => 'DATETIME NULL',
            'approved_signature' => 'MEDIUMBLOB NULL',
            'approved_signature_mime' => 'VARCHAR(40) NULL',
            'approved_at' => 'DATETIME NULL',
            'approval_comments' => 'TEXT NULL',
        ];

        try {
            $existing = [];
            $result = $conn->query('SHOW COLUMNS FROM payment_vouchers');

            if (!$result) {
                return $ready = false;
            }

            while ($row = $result->fetch_assoc()) {
                $existing[strtolower((string)$row['Field'])] = true;
            }

            foreach ($columns as $name => $definition) {
                if (!isset($existing[$name])) {
                    if (!$conn->query("ALTER TABLE payment_vouchers ADD COLUMN `$name` $definition")) {
                        error_log('[voucher-approval] could not add ' . $name . ': ' . $conn->error);
                        return $ready = false;
                    }
                }
            }

            return $ready = true;
        } catch (Throwable $e) {
            error_log('[voucher-approval] schema check failed: ' . $e->getMessage());
            return $ready = false;
        }
    }
}

if (!function_exists('pva_data_uri')) {
    function pva_data_uri(?string $bytes, ?string $mime): string
    {
        if ($bytes === null || $bytes === '') {
            return '';
        }

        $mime = in_array($mime, ['image/png', 'image/jpeg'], true) ? $mime : 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}

if (!function_exists('pva_validate_image')) {
    /** @return array{data:string,mime:string}|null */
    function pva_validate_image(string $bytes): ?array
    {
        if ($bytes === '' || strlen($bytes) > PVA_MAX_SIGNATURE_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';

        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return ['data' => $bytes, 'mime' => $mime];
    }
}

if (!function_exists('pva_profile_signature')) {
    /**
     * The signature saved on the user's employee profile (My Profile >
     * Certification), or null when they have none.
     *
     * @return array{data:string,mime:string}|null
     */
    function pva_profile_signature(mysqli $conn, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $conn->prepare('SELECT signature_path FROM employee_directory WHERE user_id = ? LIMIT 1');

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stored = trim((string)($row['signature_path'] ?? ''));

        if ($stored === '') {
            return null;
        }

        $relative = function_exists('ims_upload_relative')
            ? ims_upload_relative($stored)
            : null;

        $candidates = [];

        if ($relative !== null) {
            $candidates[] = dirname(__DIR__) . '/uploads/' . $relative;
            $candidates[] = dirname(__DIR__, 2) . '/public/uploads/' . $relative;
        }

        $candidates[] = dirname(__DIR__) . '/' . ltrim($stored, '/');

        foreach ($candidates as $path) {
            $real = realpath($path);

            if ($real === false || !is_file($real) || filesize($real) > PVA_MAX_SIGNATURE_BYTES) {
                continue;
            }

            $bytes = file_get_contents($real);

            if ($bytes !== false && ($image = pva_validate_image($bytes)) !== null) {
                return $image;
            }
        }

        return null;
    }
}

if (!function_exists('pva_read_signature_input')) {
    /**
     * Reads a signature from a drawn/prefilled data URL field, or an
     * uploaded PNG/JPEG. Returns null when nothing was supplied.
     *
     * @return array{data:string,mime:string}|null
     * @throws InvalidArgumentException when something invalid was supplied
     */
    function pva_read_signature_input(string $field, string $uploadField): ?array
    {
        $upload = $_FILES[$uploadField] ?? null;

        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
                throw new InvalidArgumentException('The signature upload failed. Please try again.');
            }

            $image = pva_validate_image((string)file_get_contents((string)$upload['tmp_name']));

            if ($image === null) {
                throw new InvalidArgumentException('Upload the signature as a PNG or JPEG image of at most 2 MB.');
            }

            return $image;
        }

        $encoded = trim((string)($_POST[$field] ?? ''));

        if ($encoded === '') {
            return null;
        }

        if (!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\s]+)$#', $encoded, $matches)) {
            throw new InvalidArgumentException('The signature is not a valid image. Please sign again.');
        }

        $image = pva_validate_image((string)base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true));

        if ($image === null) {
            throw new InvalidArgumentException('The signature is not a valid PNG/JPEG image of at most 2 MB.');
        }

        return $image;
    }
}

if (!function_exists('pva_default_approver')) {
    /** The user's line manager / head of department from their employee profile. */
    function pva_default_approver(mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare('SELECT supervisor_id FROM employee_directory WHERE user_id = ? LIMIT 1');

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $supervisor = (int)($row['supervisor_id'] ?? 0);

        return $supervisor !== $userId ? $supervisor : 0;
    }
}

if (!function_exists('pva_approvers')) {
    /**
     * Staff who can be chosen as approver (anyone except the preparer and
     * non-staff roles), Finance/leadership first.
     *
     * @return list<array{user_id:int,full_name:string,role:string,department:string}>
     */
    function pva_approvers(mysqli $conn, int $excludeUserId): array
    {
        $placeholders = implode(',', array_fill(0, count(PVA_NON_STAFF_ROLES), '?'));
        $stmt = $conn->prepare(
            "SELECT u.user_id, u.full_name, u.role, COALESCE(d.department_name, '') AS department
             FROM users u
             LEFT JOIN employee_directory ed ON ed.user_id = u.user_id
             LEFT JOIN departments d ON d.department_id = ed.department_id
             WHERE u.is_active = 1
               AND u.user_id <> ?
               AND u.role NOT IN ($placeholders)
             ORDER BY FIELD(u.role, 'Finance', 'Executive Director', 'Program Director', 'Accountant') = 0,
                      FIELD(u.role, 'Finance', 'Executive Director', 'Program Director', 'Accountant'),
                      u.full_name"
        );

        if (!$stmt) {
            return [];
        }

        $types = 'i' . str_repeat('s', count(PVA_NON_STAFF_ROLES));
        $params = array_merge([$excludeUserId], PVA_NON_STAFF_ROLES);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'user_id' => (int)$row['user_id'],
                'full_name' => (string)$row['full_name'],
                'role' => (string)$row['role'],
                'department' => (string)$row['department'],
            ];
        }

        $stmt->close();

        return $rows;
    }
}

if (!function_exists('pva_user_name')) {
    function pva_user_name(mysqli $conn, int $userId): string
    {
        static $cache = [];

        if ($userId <= 0) {
            return '';
        }

        if (!isset($cache[$userId])) {
            $stmt = $conn->prepare('SELECT full_name FROM users WHERE user_id = ? LIMIT 1');
            $name = '';

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $name = (string)($stmt->get_result()->fetch_assoc()['full_name'] ?? '');
                $stmt->close();
            }

            $cache[$userId] = $name;
        }

        return $cache[$userId];
    }
}

if (!function_exists('pva_user_email')) {
    function pva_user_email(mysqli $conn, int $userId): string
    {
        $stmt = $conn->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');

        if (!$stmt) {
            return '';
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $email = (string)($stmt->get_result()->fetch_assoc()['email'] ?? '');
        $stmt->close();

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}

if (!function_exists('pva_signature_cells')) {
    /**
     * The three signature boxes printed on a voucher (Admin/Finance,
     * H.O.Dept, Received By) as <td> HTML. $esc escapes text.
     */
    function pva_signature_cells(mysqli $conn, array $voucher, callable $esc, string $imgStyle = 'max-width:100%;max-height:13mm;'): string
    {
        $cell = static function (string $uri, string $name, ?string $date) use ($esc, $imgStyle): string {
            if ($uri === '' && $name === '') {
                return '<td></td>';
            }

            $html = '<td style="vertical-align:bottom;padding:2pt 3pt;">';

            if ($uri !== '') {
                $html .= '<img src="' . $esc($uri) . '" alt="Signature" style="' . $esc($imgStyle) . '"><br>';
            }

            if ($name !== '') {
                $html .= '<span style="font-size:7pt;">' . $esc($name)
                    . ($date ? ' &middot; ' . $esc(date('d M Y', (int)strtotime($date))) : '')
                    . '</span>';
            }

            return $html . '</td>';
        };

        $prepared = $cell(
            pva_data_uri($voucher['prepared_signature'] ?? null, $voucher['prepared_signature_mime'] ?? null),
            !empty($voucher['prepared_signature']) ? pva_user_name($conn, (int)($voucher['prepared_by'] ?? 0)) : '',
            $voucher['prepared_signed_at'] ?? null
        );

        $approved = ($voucher['approval_status'] ?? '') === 'Approved'
            ? $cell(
                pva_data_uri($voucher['approved_signature'] ?? null, $voucher['approved_signature_mime'] ?? null),
                pva_user_name($conn, (int)($voucher['approver_id'] ?? 0)),
                $voucher['approved_at'] ?? null
            )
            : '<td></td>';

        return $prepared . $approved . '<td></td>';
    }
}

if (!function_exists('pva_can_approve')) {
    /** The assigned approver, or anyone granted vouchers.approve_any. */
    function pva_can_approve(array $voucher, int $userId): bool
    {
        if (($voucher['approval_status'] ?? '') !== 'Pending Approval') {
            return false;
        }

        if ((int)($voucher['approver_id'] ?? 0) === $userId && $userId > 0) {
            return true;
        }

        return function_exists('ims_allowed')
            && ims_allowed('vouchers.approve_any', ['Administrator'], 'Approve payment vouchers assigned to someone else');
    }
}

if (!function_exists('pva_notify')) {
    /** In-app notification plus email (email failure is logged, not fatal). */
    function pva_notify(mysqli $conn, int $userId, string $title, string $message, string $type, int $voucherId): void
    {
        if ($userId <= 0) {
            return;
        }

        if (function_exists('notify_user')) {
            notify_user($userId, $title, $message, $type, $voucherId, 'payment_voucher');
        }

        $email = pva_user_email($conn, $userId);

        if ($email === '') {
            return;
        }

        if (!function_exists('sendEmail')) {
            $mailFile = __DIR__ . '/mail-function.php';

            if (is_file($mailFile)) {
                require_once $mailFile;
            }
        }

        if (!function_exists('sendEmail')) {
            return;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        $link = $host !== '' ? $scheme . '://' . $host . '/payment-voucher' : '';

        $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $body = '<h3>' . $safe($title) . '</h3><p>' . nl2br($safe($message)) . '</p>'
            . ($link !== '' ? '<p><a href="' . $safe($link) . '" class="btn">Open payment vouchers</a></p>' : '');

        if (function_exists('email_wrapper')) {
            $body = email_wrapper($body);
        }

        try {
            $result = sendEmail($email, $title, $body, $message . ($link !== '' ? "\n\n" . $link : ''));

            if ($result !== true) {
                error_log('[voucher-approval] email to user ' . $userId . ' failed: ' . (is_string($result) ? $result : 'unknown'));
            }
        } catch (Throwable $e) {
            error_log('[voucher-approval] email to user ' . $userId . ' failed: ' . $e->getMessage());
        }
    }
}
