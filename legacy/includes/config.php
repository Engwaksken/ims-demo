<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application runtime settings
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Kampala');

/*
|--------------------------------------------------------------------------
| Security helpers (.env loading, CSRF, headers, rate limiting, crypto)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/security.php';

ini_set('max_execution_time', '14400');
set_time_limit(14400);

/*
|--------------------------------------------------------------------------
| Error handling
|--------------------------------------------------------------------------
|
| Keep display_errors disabled in production. Errors are written to the
| server error log instead of being exposed to users.
|
*/

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

/*
|--------------------------------------------------------------------------
| Application paths
|--------------------------------------------------------------------------
*/

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', __DIR__);
}

if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', ROOT_PATH . '/uploads/');
}

if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', '/uploads/');
}

/*
|--------------------------------------------------------------------------
| HTTPS detection
|--------------------------------------------------------------------------
*/

if (!function_exists('is_https')) {
    function is_https(): bool
    {
        if (
            isset($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            && (string) $_SERVER['HTTPS'] !== ''
        ) {
            return true;
        }

        if (
            isset($_SERVER['SERVER_PORT'])
            && (int) $_SERVER['SERVER_PORT'] === 443
        ) {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $forwardedProtocol = strtolower(
                trim(
                    explode(
                        ',',
                        (string) $_SERVER['HTTP_X_FORWARDED_PROTO']
                    )[0]
                )
            );

            return $forwardedProtocol === 'https';
        }

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Session configuration
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        ini_set('session.gc_maxlifetime', '14400');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => 14400,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
}

/*
|--------------------------------------------------------------------------
| Request protection (web requests only)
|--------------------------------------------------------------------------
|
| - security headers (nosniff, framing, referrer policy, HSTS on HTTPS)
| - output buffer that injects the CSRF token into POST forms and
|   same-origin fetch/XHR calls (also lets late header() redirects work)
| - CSRF enforcement for every non-GET request by a logged-in user
|
*/

if (!ims_is_cli()) {
    ims_send_security_headers();
    ims_start_output_protection();
    ims_csrf_auto_enforce();
}

/*
|--------------------------------------------------------------------------
| Environment helper
|--------------------------------------------------------------------------
*/

if (!function_exists('environment_value')) {
    function environment_value(
        string $key,
        mixed $default = null
    ): mixed {
        $value = getenv($key);

        if ($value !== false && $value !== '') {
            return $value;
        }

        if (
            isset($_ENV[$key])
            && $_ENV[$key] !== ''
        ) {
            return $_ENV[$key];
        }

        if (
            isset($_SERVER[$key])
            && $_SERVER[$key] !== ''
        ) {
            return $_SERVER[$key];
        }

        return $default;
    }
}

/*
|--------------------------------------------------------------------------
| Application configuration
|--------------------------------------------------------------------------
*/

if (!defined('SITE_NAME')) {
    define(
        'SITE_NAME',
        (string) environment_value(
            'SITE_NAME',
            'Hive Colab IMS'
        )
    );
}

if (!defined('APP_URL')) {
    define(
        'APP_URL',
        rtrim(
            (string) environment_value(
                'APP_URL',
                'https://ims.hivecolab.com'
            ),
            '/'
        )
    );
}

/*
|--------------------------------------------------------------------------
| Database configuration
|--------------------------------------------------------------------------
*/

if (!defined('DB_HOST')) {
    define(
        'DB_HOST',
        (string) environment_value(
            'DB_HOST',
            'localhost'
        )
    );
}

if (!defined('DB_PORT')) {
    define(
        'DB_PORT',
        (int) environment_value(
            'DB_PORT',
            3306
        )
    );
}

if (!defined('DB_USER')) {
    define(
        'DB_USER',
        (string) environment_value(
            'DB_USERNAME',
            environment_value('DB_USER', '')
        )
    );
}

if (!defined('DB_PASS')) {
    define(
        'DB_PASS',
        (string) environment_value(
            'DB_PASSWORD',
            environment_value('DB_PASS', '')
        )
    );
}

if (!defined('DB_NAME')) {
    define(
        'DB_NAME',
        (string) environment_value(
            'DB_DATABASE',
            environment_value('DB_NAME', '')
        )
    );
}

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('db_connect')) {
    function db_connect(): mysqli
    {
        static $connection = null;

        /*
        |--------------------------------------------------------------------------
        | Return the existing valid connection
        |--------------------------------------------------------------------------
        */

        if (
            $connection instanceof mysqli
            && @$connection->ping()
        ) {
            return $connection;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate configuration
        |--------------------------------------------------------------------------
        */

        if (DB_NAME === '' || DB_USER === '') {
            throw new RuntimeException(
                'Database credentials are not configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Open connection
        |--------------------------------------------------------------------------
        */

        $connection = @new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME,
            DB_PORT
        );

        if ($connection->connect_errno) {
            $errorMessage = $connection->connect_error;

            error_log(
                'Database connection failed: '
                . $errorMessage
            );

            throw new RuntimeException(
                'The system could not connect to the database.'
            );
        }

        if (!$connection->set_charset('utf8mb4')) {
            $errorMessage = $connection->error;

            error_log(
                'Database charset error: '
                . $errorMessage
            );

            throw new RuntimeException(
                'The database character set could not be configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Set MySQL timezone to East Africa Time where supported
        |--------------------------------------------------------------------------
        */

        @$connection->query(
            "SET time_zone = '+03:00'"
        );

        return $connection;
    }
}

/*
|--------------------------------------------------------------------------
| Backward-compatible global database connection
|--------------------------------------------------------------------------
|
| Existing plain PHP modules can continue using:
|
|     global $conn;
|
| or:
|
|     $conn->prepare(...)
|
*/

try {
    if (
        !isset($conn)
        || !($conn instanceof mysqli)
        || !@$conn->ping()
    ) {
        $conn = db_connect();
    }
    // LegacyRunner requires pages inside a method, so $conn above is a local
    // there; publish it so `global $conn` in helper functions still works.
    $GLOBALS['conn'] = $conn;
} catch (Throwable $exception) {
    error_log(
        'Application database initialization failed: '
        . $exception->getMessage()
    );

    http_response_code(500);

    die(
        'The system could not connect to the database. '
        . 'Please contact the administrator.'
    );
}

/*
|--------------------------------------------------------------------------
| Upload configuration
|--------------------------------------------------------------------------
*/

if (!defined('MAX_FILE_SIZE')) {
    define(
        'MAX_FILE_SIZE',
        10 * 1024 * 1024
    );
}

if (!defined('ALLOWED_EXTENSIONS')) {
    define('ALLOWED_EXTENSIONS', [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'mp4',
        'avi',
    ]);
}

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(
        UPLOAD_DIR,
        0775,
        true
    );
}

/*
|--------------------------------------------------------------------------
| HTML escaping
|--------------------------------------------------------------------------
*/

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        if (
            is_array($value)
            || is_object($value)
        ) {
            return '';
        }

        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Input sanitization
|--------------------------------------------------------------------------
|
| This function normalizes input only. SQL protection must be provided by
| prepared statements. Output protection must be provided by h().
|
*/

if (!function_exists('sanitize_input')) {
    function sanitize_input(mixed $data): string
    {
        if (
            is_array($data)
            || is_object($data)
        ) {
            return '';
        }

        $value = trim(
            (string) ($data ?? '')
        );

        $value = str_replace(
            "\0",
            '',
            $value
        );

        return $value;
    }
}

/*
|--------------------------------------------------------------------------
| Current request path
|--------------------------------------------------------------------------
*/

if (!function_exists('current_request_path')) {
    function current_request_path(): string
    {
        $uri = (string) (
            $_SERVER['REQUEST_URI'] ?? '/'
        );

        $path = parse_url(
            $uri,
            PHP_URL_PATH
        );

        return is_string($path)
            ? $path
            : '/';
    }
}

/*
|--------------------------------------------------------------------------
| Login URL
|--------------------------------------------------------------------------
*/

if (!function_exists('login_url')) {
    function login_url(): string
    {
        return APP_URL . '/login';
    }
}

/*
|--------------------------------------------------------------------------
| Dashboard URL
|--------------------------------------------------------------------------
*/

if (!function_exists('dashboard_url')) {
    function dashboard_url(): string
    {
        return APP_URL . '/dashboard';
    }
}

/*
|--------------------------------------------------------------------------
| Authentication guard
|--------------------------------------------------------------------------
*/

if (!function_exists('check_login')) {
    function check_login(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (
            empty($_SESSION['user_id'])
            || empty($_SESSION['username'])
        ) {
            $_SESSION['intended_url'] =
                current_request_path();

            header(
                'Location: ' . login_url()
            );

            exit;
        }

        // Re-read the account at most once a minute so deactivating,
        // deleting or changing a user's role takes effect on their open
        // session instead of at their next login.
        $checkedAt = (int)($_SESSION['account_checked_at'] ?? 0);
        if (time() - $checkedAt < 60) {
            return;
        }

        try {
            $db = db_connect();
            $stmt = $db->prepare('SELECT is_active, role FROM users WHERE user_id = ? LIMIT 1');
            if (!$stmt) {
                return; // schema/DB problem: don't lock everyone out
            }
            $uid = (int)$_SESSION['user_id'];
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('check_login account re-check failed: ' . $e->getMessage());
            return;
        }

        if (!$account || (int)$account['is_active'] !== 1) {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: ' . login_url());
            exit;
        }

        $dbRole = (string)$account['role'];
        if ($dbRole !== (string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? '')) {
            require_once __DIR__ . '/roles.php';
            ims_set_session_role($db, $dbRole);
        }

        $_SESSION['account_checked_at'] = time();
    }
}

/*
|--------------------------------------------------------------------------
| Role guard
|--------------------------------------------------------------------------
*/

if (!function_exists('check_role')) {
    function check_role(
        array $allowedRoles
    ): void {
        check_login();

        // Database permissions decide (see includes/permissions.php); the
        // roles listed here are only the defaults. null = system unavailable.
        require_once __DIR__ . '/permissions.php';
        $caller = ims_perm_caller_file(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));
        $allowed = ims_perm_check($allowedRoles, (string) $caller);

        if ($allowed === null) {
            $currentRole = strtolower(
                trim(
                    (string) (
                        $_SESSION['role'] ?? ''
                    )
                )
            );

            $normalizedRoles = array_map(
                static fn (mixed $role): string =>
                    strtolower(
                        trim((string) $role)
                    ),
                $allowedRoles
            );

            $allowed = in_array(
                $currentRole,
                $normalizedRoles,
                true
            );
        }

        if (!$allowed) {
            $_SESSION['notification'] = [
                'user_id' => isset($_SESSION['user_id'])
                    ? (int) $_SESSION['user_id']
                    : null,
                'message' => 'You are not authorized to access that page.',
                'type'    => 'danger',
            ];

            header(
                'Location: ' . dashboard_url()
            );

            exit;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Audit logging
|--------------------------------------------------------------------------
*/

if (!function_exists('log_action')) {
    function log_action(
        mixed $userId,
        mixed $action,
        mixed $tableName = null,
        mixed $recordId = null,
        mixed $details = null
    ): void {
        try {
            $database = db_connect();
        } catch (Throwable $exception) {
            error_log(
                'Audit log database error: '
                . $exception->getMessage()
            );

            return;
        }

        $normalizedUserId = is_numeric($userId)
            ? (int) $userId
            : null;

        $normalizedRecordId = is_numeric($recordId)
            ? (int) $recordId
            : null;

        $normalizedAction = is_scalar($action)
            ? trim((string) $action)
            : '';

        $normalizedTableName = is_scalar($tableName)
            ? trim((string) $tableName)
            : null;

        $normalizedDetails = is_scalar($details)
            ? trim((string) $details)
            : null;

        $ipAddress = substr(
            (string) (
                $_SERVER['REMOTE_ADDR']
                ?? 'UNKNOWN'
            ),
            0,
            45
        );

        if ($normalizedAction === '') {
            return;
        }

        $sql = "
            INSERT INTO audit_log (
                user_id,
                action,
                table_name,
                record_id,
                details,
                ip_address
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $stmt = $database->prepare($sql);

        if (!$stmt) {
            error_log(
                'Audit log prepare error: '
                . $database->error
            );

            return;
        }

        $stmt->bind_param(
            'ississ',
            $normalizedUserId,
            $normalizedAction,
            $normalizedTableName,
            $normalizedRecordId,
            $normalizedDetails,
            $ipAddress
        );

        if (!$stmt->execute()) {
            error_log(
                'Audit log execute error: '
                . $stmt->error
            );
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Numeric helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('safe_number')) {
    function safe_number(
        mixed $value
    ): float {
        if (
            is_object($value)
            || is_array($value)
        ) {
            return 0.0;
        }

        if (is_string($value)) {
            $value = str_replace(
                [',', ' '],
                '',
                $value
            );
        }

        if (!is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }
}

if (!function_exists('format_currency')) {
    function format_currency(
        mixed $amount,
        string $currency = 'UGX',
        int $decimals = 2
    ): string {
        return trim($currency)
            . ' '
            . number_format(
                safe_number($amount),
                $decimals,
                '.',
                ','
            );
    }
}

if (!function_exists('calculate_percentage')) {
    function calculate_percentage(
        mixed $value,
        mixed $total
    ): float {
        $numericValue = safe_number($value);
        $numericTotal = safe_number($total);

        if ($numericTotal <= 0) {
            return 0.0;
        }

        return round(
            ($numericValue / $numericTotal) * 100,
            2
        );
    }
}

/*
|--------------------------------------------------------------------------
| Date and time helper
|--------------------------------------------------------------------------
*/

if (!function_exists('time_ago')) {
    function time_ago(
        mixed $datetime
    ): string {
        if (
            is_array($datetime)
            || is_object($datetime)
            || $datetime === null
            || $datetime === ''
        ) {
            return 'Just now';
        }

        $timestamp = strtotime(
            (string) $datetime
        );

        if ($timestamp === false) {
            return 'Just now';
        }

        $difference = time() - $timestamp;

        if ($difference <= 0) {
            return 'Just now';
        }

        $periods = [
            'year'   => 31536000,
            'month'  => 2592000,
            'week'   => 604800,
            'day'    => 86400,
            'hour'   => 3600,
            'minute' => 60,
            'second' => 1,
        ];

        foreach ($periods as $label => $seconds) {
            if ($difference >= $seconds) {
                $count = (int) floor(
                    $difference / $seconds
                );

                return $count
                    . ' '
                    . $label
                    . ($count === 1 ? '' : 's')
                    . ' ago';
            }
        }

        return 'Just now';
    }
}

/*
|--------------------------------------------------------------------------
| Project code generator
|--------------------------------------------------------------------------
*/

if (!function_exists('generate_project_code')) {
    function generate_project_code(
        mixed $projectName
    ): string {
        if (
            is_array($projectName)
            || is_object($projectName)
        ) {
            $projectName = 'PROJECT';
        }

        $projectName = trim(
            (string) $projectName
        );

        $words = preg_split(
            '/\s+/',
            $projectName
        ) ?: [];

        $prefix = '';

        foreach ($words as $word) {
            $word = trim($word);

            if ($word !== '') {
                $prefix .= strtoupper(
                    substr($word, 0, 1)
                );
            }
        }

        if ($prefix === '') {
            $prefix = 'PRJ';
        }

        try {
            $randomNumber = random_int(
                100,
                999
            );
        } catch (Throwable) {
            $randomNumber = mt_rand(
                100,
                999
            );
        }

        return $prefix
            . '-'
            . date('Y')
            . '-'
            . $randomNumber;
    }
}

/*
|--------------------------------------------------------------------------
| Flash notifications
|--------------------------------------------------------------------------
*/

if (!function_exists('send_notification')) {
    function send_notification(
        mixed $userId,
        mixed $message,
        string $type = 'info'
    ): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $allowedTypes = [
            'success',
            'info',
            'warning',
            'danger',
            'error',
        ];

        if (
            !in_array(
                $type,
                $allowedTypes,
                true
            )
        ) {
            $type = 'info';
        }

        $_SESSION['notification'] = [
            'user_id' => is_numeric($userId)
                ? (int) $userId
                : null,

            'message' => is_scalar($message)
                ? trim((string) $message)
                : '',

            'type' => $type,
        ];
    }
}

if (!function_exists('get_notification')) {
    function get_notification(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (
            empty($_SESSION['notification'])
            || !is_array(
                $_SESSION['notification']
            )
        ) {
            return null;
        }

        $notification =
            $_SESSION['notification'];

        unset(
            $_SESSION['notification']
        );

        return $notification;
    }
}

/*
|--------------------------------------------------------------------------
| CSRF helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return ims_csrf_session_token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . h(csrf_token())
            . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(
        ?string $token = null
    ): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return ims_csrf_valid($token);
    }
}

// Database-driven permissions: check_role() defaults and ims_allowed() for
// actions inside pages (see includes/permissions.php).
require_once __DIR__ . '/permissions.php';
