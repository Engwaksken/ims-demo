<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IMS shared security helpers
|--------------------------------------------------------------------------
|
| Loaded automatically by includes/config.php. Every function is guarded
| with function_exists() so this file can be included more than once and
| alongside app/Core/helpers.php.
|
| Provides:
|   - .env loading for legacy entry points (secrets live only in .env)
|   - CLI detection / CLI-only guard for cron scripts
|   - security response headers
|   - CSRF token verification + automatic token injection into HTML forms
|     and same-origin fetch()/XMLHttpRequest calls
|   - simple file-based rate limiting (login / 2FA brute force)
|   - upload validation (extension + MIME allowlist)
|   - encryption at rest for secrets stored in the DB (sodium / openssl)
|   - in-app notification helper for messaging *other* users
|
*/

if (defined('IMS_SECURITY_LOADED')) {
    return;
}

define('IMS_SECURITY_LOADED', true);

if (!defined('IMS_PROJECT_ROOT')) {
    // legacy/includes -> legacy -> project root
    define('IMS_PROJECT_ROOT', dirname(__DIR__, 2));
}

/*
|--------------------------------------------------------------------------
| Environment
|--------------------------------------------------------------------------
*/

if (!function_exists('ims_load_env_file')) {
    function ims_load_env_file(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $length = strlen($value);

            if (
                $length >= 2
                && (($value[0] === '"' && $value[$length - 1] === '"')
                    || ($value[0] === "'" && $value[$length - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Never override variables already provided by the server.
            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

ims_load_env_file(IMS_PROJECT_ROOT . '/.env');

if (!function_exists('ims_env')) {
    function ims_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return ($value === null || $value === '') ? $default : $value;
    }
}

if (!function_exists('ims_env_bool')) {
    function ims_env_bool(string $key, bool $default = false): bool
    {
        $value = ims_env($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}

/*
|--------------------------------------------------------------------------
| CLI helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('ims_is_cli')) {
    function ims_is_cli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}

if (!function_exists('ims_require_cli')) {
    /**
     * Abort with 404 unless running from the command line.
     * Use at the very top of every cron/maintenance script.
     */
    function ims_require_cli(): void
    {
        if (ims_is_cli()) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo 'Not Found';
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Request helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('ims_request_wants_json')) {
    function ims_request_wants_json(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        return $requestedWith === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || str_contains($script, '/ajax/')
            || str_contains($script, '/actions/');
    }
}

if (!function_exists('ims_script_name')) {
    function ims_script_name(): string
    {
        return strtolower(basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? '')));
    }
}

/*
|--------------------------------------------------------------------------
| Security headers
|--------------------------------------------------------------------------
|
| Embeddable widgets (*-embed.php, *widget*.php) are allowed to be framed
| by other sites; everything else is restricted to same-origin framing.
|
*/

if (!function_exists('ims_is_embeddable_script')) {
    function ims_is_embeddable_script(): bool
    {
        return (bool) preg_match('/(embed|widget)/i', ims_script_name());
    }
}

if (!function_exists('ims_send_security_headers')) {
    function ims_send_security_headers(): void
    {
        static $sent = false;

        if ($sent || ims_is_cli() || headers_sent()) {
            return;
        }

        $sent = true;

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self), payment=()');

        $csp = "base-uri 'self'; object-src 'none'";

        if (!ims_is_embeddable_script()) {
            header('X-Frame-Options: SAMEORIGIN');
            $csp .= "; frame-ancestors 'self'";
        }

        header('Content-Security-Policy: ' . $csp);

        if (function_exists('is_https') && is_https()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
|
| One token per session, stored in $_SESSION['csrf_token'] and mirrored to
| $_SESSION['_token'] for the small MVC layer (app/Core/Controller.php).
|
| Accepted from: POST csrf_token, POST _token, X-CSRF-Token header,
| or (for legacy GET action links only) the csrf_token query parameter.
|
*/

if (!function_exists('ims_csrf_session_token')) {
    function ims_csrf_session_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return (string) ($_SESSION['csrf_token'] ?? '');
        }

        $token = $_SESSION['csrf_token'] ?? ($_SESSION['_token'] ?? '');

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
        }

        $_SESSION['csrf_token'] = $token;
        $_SESSION['_token'] = $token;

        return $token;
    }
}

if (!function_exists('ims_csrf_rotate')) {
    /** Call after a successful login. */
    function ims_csrf_rotate(): void
    {
        unset($_SESSION['csrf_token'], $_SESSION['_token']);
        ims_csrf_session_token();
    }
}

if (!function_exists('ims_csrf_request_token')) {
    function ims_csrf_request_token(bool $allowQuery = false): string
    {
        $candidates = [
            $_POST['csrf_token'] ?? null,
            $_POST['_token'] ?? null,
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
            $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null,
        ];

        if ($allowQuery) {
            $candidates[] = $_GET['csrf_token'] ?? null;
        }

        // JSON bodies: {"csrf_token": "..."}
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

            if (is_array($json)) {
                $candidates[] = $json['csrf_token'] ?? ($json['_token'] ?? null);
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('ims_csrf_valid')) {
    function ims_csrf_valid(?string $submitted = null, bool $allowQuery = false): bool
    {
        $submitted ??= ims_csrf_request_token($allowQuery);

        $stored = (string) ($_SESSION['csrf_token'] ?? ($_SESSION['_token'] ?? ''));

        return $submitted !== ''
            && $stored !== ''
            && hash_equals($stored, $submitted);
    }
}

if (!function_exists('ims_csrf_fail')) {
    function ims_csrf_fail(): never
    {
        error_log(sprintf(
            '[security] CSRF validation failed: %s %s user=%s ip=%s',
            (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            (string) ($_SESSION['user_id'] ?? '-'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '-')
        ));

        $message = 'Your session token has expired or is invalid. Please reload the page and try again.';

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(419);
        }

        if (ims_request_wants_json()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }

            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $refererHost = (string) parse_url($referer, PHP_URL_HOST);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = (string) preg_replace('/:\d+$/', '', $host);

        if ($referer !== '' && $refererHost !== '' && strcasecmp($refererHost, $host) === 0 && !headers_sent()) {
            $_SESSION['notification'] = [
                'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                'message' => $message,
                'type'    => 'danger',
            ];
            $_SESSION['error'] = $message;

            header('Location: ' . $referer, true, 303);
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo $message;
        exit;
    }
}

if (!function_exists('csrf_protect')) {
    /**
     * Reject state-changing requests that do not carry a valid token.
     * Safe methods (GET/HEAD/OPTIONS) pass unless $includeGet is true, in
     * which case a csrf_token query parameter is required (for legacy
     * GET links that change state, e.g. ?action=delete&id=1).
     */
    function csrf_protect(bool $includeGet = false): void
    {
        if (ims_is_cli()) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (!$includeGet && in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if (ims_csrf_valid(null, $includeGet)) {
            return;
        }

        ims_csrf_fail();
    }
}

if (!function_exists('ims_csrf_auto_enforce')) {
    /**
     * Global guard called from config.php: every non-safe request made by
     * an authenticated user must carry the session CSRF token.
     * Disable in an emergency with CSRF_ENFORCE=false in .env.
     */
    function ims_csrf_auto_enforce(): void
    {
        if (ims_is_cli() || empty($_SESSION['user_id'])) {
            return;
        }

        if (!ims_env_bool('CSRF_ENFORCE', true)) {
            return;
        }

        $exempt = array_filter(array_map(
            'trim',
            explode(',', strtolower((string) ims_env('CSRF_EXEMPT_SCRIPTS', '')))
        ));

        if ($exempt && in_array(ims_script_name(), $exempt, true)) {
            return;
        }

        // pfSense hub internet agent: HMAC-signed body (legacy/hub-network-agent.php).
        if (ims_script_name() === 'hub-network-agent.php') {
            return;
        }

        csrf_protect();
    }
}

if (!function_exists('ims_csrf_client_script')) {
    function ims_csrf_client_script(string $token): string
    {
        $t = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Adds X-CSRF-Token to every same-origin fetch/XHR call and a
        // hidden csrf_token field to POST forms (including forms created
        // and submitted from JavaScript).
        $js = <<<'JS'
(function(){var m=document.querySelector('meta[name="csrf-token"]');if(!m)return;var t=m.getAttribute('content')||'';if(!t)return;
function same(u){try{return new URL(u,location.href).origin===location.origin;}catch(e){return false;}}
if(window.fetch&&!window.fetch.__csrf){var of=window.fetch;var nf=function(i,o){try{var url=(typeof i==='string'||(window.URL&&i instanceof URL))?String(i):((i&&i.url)||'');if(same(url)){o=o||{};var h=new Headers((o&&o.headers)||((window.Request&&i instanceof Request)?i.headers:undefined));if(!h.has('X-CSRF-Token')){h.set('X-CSRF-Token',t);}o.headers=h;}}catch(e){}return of.call(this,i,o);};nf.__csrf=true;window.fetch=nf;}
var X=window.XMLHttpRequest&&XMLHttpRequest.prototype;if(X&&!X.__csrf){var op=X.open,sd=X.send;X.open=function(me,u){try{this.__csrfAdd=same(u);}catch(e){this.__csrfAdd=false;}return op.apply(this,arguments);};X.send=function(){if(this.__csrfAdd){try{this.setRequestHeader('X-CSRF-Token',t);}catch(e){}}return sd.apply(this,arguments);};X.__csrf=true;}
function add(f){try{if(!f||String(f.getAttribute('method')||'').toLowerCase()!=='post')return;var a=f.getAttribute('action');if(a&&!same(a))return;if(f.querySelector('input[name="csrf_token"]'))return;var x=document.createElement('input');x.type='hidden';x.name='csrf_token';x.value=t;f.appendChild(x);}catch(e){}}
document.addEventListener('submit',function(e){add(e.target);},true);
if(window.HTMLFormElement){var ps=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){add(this);return ps.apply(this,arguments);};}
if(window.jQuery&&jQuery.ajaxSetup){jQuery.ajaxSetup({headers:{'X-CSRF-Token':t}});}
})();
JS;

        return '<meta name="csrf-token" content="' . $t . '">'
            . '<script>' . $js . '</script>';
    }
}

if (!function_exists('ims_csrf_output_filter')) {
    /**
     * Output-buffer callback: injects the CSRF token into every same-origin
     * POST <form> and adds the meta tag + client script before </head>.
     * Non-HTML responses (JSON, PDF, CSV, files) pass through untouched.
     */
    function ims_csrf_output_filter(string $buffer, int $phase = 0): string
    {
        static $headInjected = false;

        if ($buffer === '') {
            return $buffer;
        }

        foreach (headers_list() as $header) {
            if (
                stripos($header, 'content-type:') === 0
                && stripos($header, 'text/html') === false
            ) {
                return $buffer;
            }

            if (stripos($header, 'content-disposition:') === 0) {
                return $buffer;
            }
        }

        $hasForm = stripos($buffer, '<form') !== false;
        $hasHead = !$headInjected && stripos($buffer, '</head>') !== false;

        if (!$hasForm && !$hasHead) {
            return $buffer;
        }

        $token = (string) ($_SESSION['csrf_token'] ?? '');

        if ($token === '') {
            return $buffer;
        }

        $escaped = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));

        if ($hasForm) {
            // Never touch markup inside <script> / <textarea> blocks.
            $parts = preg_split(
                '/(<script\b[^>]*>.*?<\/script\s*>|<textarea\b[^>]*>.*?<\/textarea\s*>)/is',
                $buffer,
                -1,
                PREG_SPLIT_DELIM_CAPTURE
            );

            if (is_array($parts)) {
                foreach ($parts as $index => $part) {
                    if ($index % 2 === 1 || stripos($part, '<form') === false) {
                        continue;
                    }

                    $parts[$index] = (string) preg_replace_callback(
                        '/<form\b[^>]*>/i',
                        static function (array $match) use ($escaped, $host): string {
                            $tag = $match[0];

                            if (!preg_match('/\bmethod\s*=\s*["\']?\s*post\b/i', $tag)) {
                                return $tag;
                            }

                            if (
                                preg_match('/\baction\s*=\s*["\']?\s*(?:https?:)?\/\/([^\/"\'\s>:]+)/i', $tag, $action)
                                && strcasecmp($action[1], $host) !== 0
                            ) {
                                return $tag; // never leak the token to another host
                            }

                            return $tag . '<input type="hidden" name="csrf_token" value="' . $escaped . '">';
                        },
                        $part
                    );
                }

                $buffer = implode('', $parts);
            }
        }

        if ($hasHead && stripos($buffer, 'name="csrf-token"') === false) {
            $position = stripos($buffer, '</head>');

            if ($position !== false) {
                $buffer = substr($buffer, 0, $position)
                    . ims_csrf_client_script($token)
                    . substr($buffer, $position);
                $headInjected = true;
            }
        }

        return $buffer;
    }
}

if (!function_exists('ims_start_output_protection')) {
    function ims_start_output_protection(): void
    {
        static $started = false;

        if ($started || ims_is_cli()) {
            return;
        }

        $started = true;

        // Make sure a token exists before any markup is rendered.
        if (session_status() === PHP_SESSION_ACTIVE) {
            ims_csrf_session_token();
        }

        ob_start('ims_csrf_output_filter');
    }
}

/*
|--------------------------------------------------------------------------
| Rate limiting (file based, no schema change required)
|--------------------------------------------------------------------------
*/

if (!function_exists('ims_rate_limit_file')) {
    function ims_rate_limit_file(string $key): string
    {
        static $directory = null;

        if ($directory === null) {
            $candidates = [
                IMS_PROJECT_ROOT . '/storage/cache/ratelimit',
                rtrim(sys_get_temp_dir(), '/\\') . '/ims_ratelimit',
            ];

            foreach ($candidates as $candidate) {
                if (!is_dir($candidate)) {
                    @mkdir($candidate, 0770, true);
                }

                if (is_dir($candidate) && is_writable($candidate)) {
                    $directory = $candidate;
                    break;
                }
            }

            $directory ??= sys_get_temp_dir();
        }

        return $directory . '/' . hash('sha256', $key) . '.json';
    }
}

if (!function_exists('ims_rate_limit_attempts')) {
    /** @return int[] timestamps inside the window */
    function ims_rate_limit_attempts(string $key, int $windowSeconds): array
    {
        $file = ims_rate_limit_file($key);

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($file), true);
        $cutoff = time() - $windowSeconds;

        return array_values(array_filter(
            is_array($data) ? $data : [],
            static fn ($ts): bool => is_int($ts) && $ts > $cutoff
        ));
    }
}

if (!function_exists('ims_rate_limit_too_many')) {
    function ims_rate_limit_too_many(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        return count(ims_rate_limit_attempts($key, $windowSeconds)) >= $maxAttempts;
    }
}

if (!function_exists('ims_rate_limit_hit')) {
    function ims_rate_limit_hit(string $key, int $windowSeconds): void
    {
        $file = ims_rate_limit_file($key);
        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            return;
        }

        if (flock($handle, LOCK_EX)) {
            $data = json_decode((string) stream_get_contents($handle), true);
            $cutoff = time() - $windowSeconds;
            $data = array_values(array_filter(
                is_array($data) ? $data : [],
                static fn ($ts): bool => is_int($ts) && $ts > $cutoff
            ));
            $data[] = time();

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);
    }
}

if (!function_exists('ims_rate_limit_clear')) {
    function ims_rate_limit_clear(string $key): void
    {
        $file = ims_rate_limit_file($key);

        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if (!function_exists('ims_client_ip')) {
    function ims_client_ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}

/*
|--------------------------------------------------------------------------
| Upload validation
|--------------------------------------------------------------------------
*/

if (!function_exists('ims_upload_mime_map')) {
    function ims_upload_mime_map(): array
    {
        return [
            'pdf'  => ['application/pdf', 'application/x-pdf'],
            'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
            'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'],
            'txt'  => ['text/plain'],
            'jpg'  => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'mp4'  => ['video/mp4'],
            'avi'  => ['video/x-msvideo', 'video/avi', 'video/msvideo'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
        ];
    }
}

if (!function_exists('ims_validate_upload')) {
    /**
     * Validate a single $_FILES entry.
     *
     * @return array{ok:bool,error:string,extension:string,mime:string,safe_name:string}
     */
    function ims_validate_upload(array $file, ?array $allowedExtensions = null, ?int $maxBytes = null): array
    {
        $fail = static fn (string $error): array => [
            'ok' => false, 'error' => $error, 'extension' => '', 'mime' => '', 'safe_name' => '',
        ];

        $allowedExtensions ??= defined('ALLOWED_EXTENSIONS') ? ALLOWED_EXTENSIONS : ['pdf'];
        $allowedExtensions = array_map('strtolower', $allowedExtensions);
        $maxBytes ??= defined('MAX_FILE_SIZE') ? (int) MAX_FILE_SIZE : 10 * 1024 * 1024;

        if (!isset($file['error']) || is_array($file['error'])) {
            return $fail('Invalid upload.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return $fail('The file could not be uploaded (error ' . (int) $file['error'] . ').');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $fail('Invalid upload.');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > $maxBytes) {
            return $fail('The file is empty or larger than the allowed size.');
        }

        $original = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        // Reject double extensions such as shell.php.jpg and any script type.
        if (preg_match('/\.(php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|asp|aspx|jsp|exe|bat|cmd|htaccess|html?|svg|js)(\.|$)/i', $original)) {
            return $fail('This file type is not allowed.');
        }

        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            return $fail('This file type is not allowed.');
        }

        $mime = '';

        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($tmp);
        }

        $map = ims_upload_mime_map();

        if ($mime !== '' && isset($map[$extension]) && !in_array($mime, $map[$extension], true)) {
            return $fail('The file content does not match its extension.');
        }

        return [
            'ok'        => true,
            'error'     => '',
            'extension' => $extension,
            'mime'      => $mime,
            'safe_name' => bin2hex(random_bytes(16)) . '.' . $extension,
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Encryption at rest
|--------------------------------------------------------------------------
|
| Key: DATA_ENCRYPTION_KEY in .env (base64:... or 64 hex chars), falling back
| to a key derived from APP_KEY. Values are prefixed "enc:v1:" so plaintext
| legacy values keep working and are encrypted the next time they are saved.
|
*/

if (!function_exists('ims_encryption_key')) {
    function ims_encryption_key(): ?string
    {
        static $key = false;

        if ($key !== false) {
            return $key;
        }

        $raw = (string) ims_env('DATA_ENCRYPTION_KEY', '');

        if ($raw !== '') {
            if (str_starts_with($raw, 'base64:')) {
                $decoded = base64_decode(substr($raw, 7), true);
                $raw = $decoded === false ? '' : $decoded;
            } elseif (ctype_xdigit($raw) && strlen($raw) === 64) {
                $raw = (string) hex2bin($raw);
            }

            if (strlen($raw) === 32) {
                return $key = $raw;
            }

            if ($raw !== '') {
                return $key = hash('sha256', $raw, true);
            }
        }

        $appKey = (string) ims_env('APP_KEY', '');

        if ($appKey === '') {
            return $key = null;
        }

        return $key = hash_hmac('sha256', 'ims-data-encryption-v1', $appKey, true);
    }
}

if (!function_exists('ims_is_encrypted')) {
    function ims_is_encrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, 'enc:v1:');
    }
}

if (!function_exists('ims_encrypt')) {
    function ims_encrypt(string $plain): string
    {
        if ($plain === '' || ims_is_encrypted($plain)) {
            return $plain;
        }

        $key = ims_encryption_key();

        if ($key === null) {
            error_log('[security] ims_encrypt: no APP_KEY / DATA_ENCRYPTION_KEY configured; storing plaintext.');
            return $plain;
        }

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

            return 'enc:v1:s:' . base64_encode($nonce . $cipher);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($cipher !== false) {
                return 'enc:v1:o:' . base64_encode($iv . $tag . $cipher);
            }
        }

        error_log('[security] ims_encrypt: no sodium/openssl available; storing plaintext.');

        return $plain;
    }
}

if (!function_exists('ims_decrypt')) {
    /** Returns plaintext; non-encrypted legacy values are returned unchanged. */
    function ims_decrypt(?string $value): string
    {
        $value = (string) $value;

        if (!ims_is_encrypted($value)) {
            return $value;
        }

        $key = ims_encryption_key();

        if ($key === null) {
            error_log('[security] ims_decrypt: encryption key missing.');
            return '';
        }

        $mode = substr($value, 7, 1);
        $payload = base64_decode(substr($value, 9), true);

        if ($payload === false) {
            return '';
        }

        if ($mode === 's' && function_exists('sodium_crypto_secretbox_open')) {
            $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

            return $plain === false ? '' : $plain;
        }

        if ($mode === 'o' && function_exists('openssl_decrypt')) {
            $iv = substr($payload, 0, 12);
            $tag = substr($payload, 12, 16);
            $cipher = substr($payload, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

            return $plain === false ? '' : $plain;
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Sensitive system_settings values (encrypted at rest)
|--------------------------------------------------------------------------
|
| Used by settings_upsert()/settings_get(), gcal_setting*() and
| backup_setting*(). Existing plaintext values keep working and are
| encrypted the next time they are saved. Keep APP_KEY (or
| DATA_ENCRYPTION_KEY) stable: changing it makes these values unreadable
| (Google would then need to be reconnected and secrets re-entered).
|
*/

if (!function_exists('ims_sensitive_setting_keys')) {
    function ims_sensitive_setting_keys(): array
    {
        return [
            'google_client_secret',
            'google_access_token',
            'google_refresh_token',
            'smtp_pass',
            'smtp_password',
            'backup_google_service_account_json',
        ];
    }
}

if (!function_exists('ims_setting_encode')) {
    function ims_setting_encode(string $key, string $value): string
    {
        return in_array($key, ims_sensitive_setting_keys(), true) ? ims_encrypt($value) : $value;
    }
}

if (!function_exists('ims_setting_decode')) {
    function ims_setting_decode(string $key, string $value): string
    {
        return in_array($key, ims_sensitive_setting_keys(), true) ? ims_decrypt($value) : $value;
    }
}

/*
|--------------------------------------------------------------------------
| Notify another user (persistent, in-app)
|--------------------------------------------------------------------------
|
| send_notification() is a *session flash* for the current browser only.
| Calling it with another user's id overwrites the current user's flash.
| Use notify_user() to message someone else.
|
*/

if (!function_exists('notify_user')) {
    function notify_user(
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        int $referenceId = 0,
        ?string $referenceType = null
    ): bool {
        if ($userId <= 0) {
            return false;
        }

        if (!in_array($type, ['info', 'success', 'warning', 'danger'], true)) {
            $type = 'info';
        }

        try {
            $database = function_exists('db_connect') ? db_connect() : null;

            if (!$database instanceof mysqli) {
                return false;
            }

            $stmt = $database->prepare(
                'INSERT INTO notifications (user_id, title, message, type, reference_id, reference_type)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('isssis', $userId, $title, $message, $type, $referenceId, $referenceType);
            $ok = $stmt->execute();
            $stmt->close();

            return $ok;
        } catch (Throwable $exception) {
            error_log('notify_user failed: ' . $exception->getMessage());

            return false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Protected uploads
|--------------------------------------------------------------------------
|
| legacy/uploads/ and public/uploads/ are not web-readable. Every stored
| upload path ("uploads/x/y.pdf", "../uploads/x/y.pdf", "/uploads/...")
| is served through the authenticated endpoint legacy/download-file.php,
| which checks login + per-category ownership/role rules.
|
|   <a href="<?= h(ims_upload_url($row['file_path'])) ?>">
|
*/

if (!function_exists('ims_upload_relative')) {
    /**
     * Normalise a stored upload path to a path relative to an uploads root
     * ("receipts/9/x.png"). Returns null for anything that is not a local
     * upload path (external URLs, data URIs, traversal attempts).
     */
    function ims_upload_relative(?string $stored): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $stored));

        if ($path === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return null;
        }

        $path = (string) preg_replace('#[?\#].*$#', '', $path);
        $position = strrpos('/' . $path, '/uploads/');

        if ($position === false) {
            return null;
        }

        $relative = substr('/' . $path, $position + strlen('/uploads/'));
        $relative = rawurldecode($relative);
        $relative = (string) preg_replace('#/+#', '/', $relative);
        $relative = trim($relative, '/');

        if (
            $relative === ''
            || str_contains($relative, "\0")
            || preg_match('#(?:^|/)\.#', $relative)
        ) {
            return null;
        }

        return $relative;
    }
}

if (!function_exists('ims_upload_url')) {
    /**
     * URL for displaying/downloading a stored upload. Non-upload values
     * (http(s) links, empty strings) are returned unchanged.
     */
    function ims_upload_url(?string $stored, bool $forceDownload = false): string
    {
        $relative = ims_upload_relative($stored);

        if ($relative === null) {
            $value = trim((string) $stored);

            // Never emit script-capable URLs (raster data: images are fine).
            if (preg_match('#^\s*data:image/(?:png|jpe?g|gif|webp)[;,]#i', $value)) {
                return $value;
            }

            return preg_match('#^\s*(?:javascript|vbscript|data):#i', $value) ? '' : $value;
        }

        return 'download-file.php?path=' . rawurlencode($relative)
            . ($forceDownload ? '&download=1' : '');
    }
}

/*
|--------------------------------------------------------------------------
| Output hardening helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('ims_csv_safe')) {
    /**
     * Neutralise spreadsheet formula injection: a cell that starts with
     * = + - @ TAB or CR is prefixed with a single quote.
     */
    function ims_csv_safe(mixed $value): mixed
    {
        // Plain numbers (e.g. "-12.50") cannot carry a formula: leave them as numbers.
        if (!is_string($value) || $value === '' || $value === '-' || is_numeric($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}

if (!function_exists('ims_fputcsv')) {
    /** fputcsv() with every cell passed through ims_csv_safe(). */
    function ims_fputcsv(mixed $handle, array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\'): int|false
    {
        return fputcsv($handle, array_map('ims_csv_safe', $fields), $separator, $enclosure, $escape);
    }
}

if (!function_exists('ims_is_safe_link')) {
    /**
     * True for relative paths (page.php, sub/page?x=1, /page) and absolute
     * http(s) URLs; false for javascript:, data:, vbscript: and any other
     * scheme or protocol-relative URL.
     */
    function ims_is_safe_link(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F\s"\'<>`\\\\]/', $url)) {
            return false;
        }

        if (preg_match('#^https?://[^/]+#i', $url)) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        // Anything with a scheme (letters followed by ":" before any / ? #) is rejected.
        return !preg_match('#^[^/?\#]*:#', $url);
    }
}
