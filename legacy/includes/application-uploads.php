<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application form uploads (submit-application.php)
|--------------------------------------------------------------------------
|
| - legal_docs           single supporting legal/registration document,
|                        stored in applications.legal_docs_path (any format)
| - pitch_deck           pitch deck, applications.pitch_deck_path
|                        (PDF / presentation formats)
| - safeguarding_policy  policy document, applications.safeguarding_policy_path
|
| Script/executable types (which could run on the server or in a browser) are
| always refused. Files are served back through download-file.php, which
| re-checks access and never serves script types.
|
| app_attachments_for() reads extra files added later through
| upload-application-docs.php (application_attachments) for the view pages.
|
*/

const APP_UPLOAD_MAX_BYTES = 100 * 1024 * 1024;
const APP_UPLOAD_PITCH_DECK_EXTENSIONS = ['pdf', 'ppt', 'pptx', 'pps', 'ppsx', 'odp', 'key'];

if (!function_exists('app_ensure_pitch_deck_column')) {
    /** Make sure applications.pitch_deck_path exists (older schemas lack it). */
    function app_ensure_pitch_deck_column(mysqli $conn): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        try {
            $result = $conn->query("SHOW COLUMNS FROM `applications` LIKE 'pitch_deck_path'");

            if ($result && $result->num_rows > 0) {
                return $available = true;
            }

            if ($result === false) {
                return $available = false;
            }

            $added = $conn->query('ALTER TABLE `applications` ADD COLUMN `pitch_deck_path` VARCHAR(500) NULL DEFAULT NULL');

            if ($added === false) {
                error_log('Could not add applications.pitch_deck_path: ' . $conn->error);
            }

            return $available = ($added !== false);
        } catch (Throwable $exception) {
            error_log('applications.pitch_deck_path check failed: ' . $exception->getMessage());

            return $available = false;
        }
    }
}

if (!function_exists('app_upload_ini_bytes')) {
    function app_upload_ini_bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (float)$value;

        switch (strtolower(substr($value, -1))) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }

        return (int)$number;
    }
}

if (!function_exists('app_upload_limit_bytes')) {
    /** Largest single file the server will accept (app cap and php.ini limits). */
    function app_upload_limit_bytes(): int
    {
        $limits = [APP_UPLOAD_MAX_BYTES];

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = app_upload_ini_bytes((string)ini_get($key));

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }
}

if (!function_exists('app_upload_post_limit_bytes')) {
    /** Total request size the server accepts (0 = unlimited). */
    function app_upload_post_limit_bytes(): int
    {
        return app_upload_ini_bytes((string)ini_get('post_max_size'));
    }
}

if (!function_exists('app_upload_format_bytes')) {
    function app_upload_format_bytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }
}

if (!function_exists('app_upload_blocked_name')) {
    /** Script / executable names (including double extensions like x.php.jpg). */
    function app_upload_blocked_name(string $name): bool
    {
        return (bool)preg_match(
            '/\.(php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|bash|asp|aspx|jsp|exe|msi|com|scr|bat|cmd|vbs|vbe|ps1|jar|dll|htaccess|htpasswd|html?|xhtml|shtml|js|mjs|svgz?|xml|hta)(\.|$)/i',
            $name
        );
    }
}

if (!function_exists('app_upload_kind')) {
    /** Broad file kind used for icons and previews. */
    function app_upload_kind(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $groups = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'tif', 'tiff', 'avif'],
            'video' => ['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv', '3gp', 'wmv', 'mpeg', 'mpg', 'ogv', 'flv'],
            'audio' => ['mp3', 'm4a', 'wav', 'ogg', 'aac', 'flac', 'oga', 'opus'],
            'pdf' => ['pdf'],
            'word' => ['doc', 'docx', 'odt', 'rtf', 'pages', 'txt'],
            'sheet' => ['xls', 'xlsx', 'ods', 'csv', 'numbers'],
            'slides' => ['ppt', 'pptx', 'odp', 'key'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
        ];

        foreach ($groups as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }

        return 'file';
    }
}

if (!function_exists('app_upload_icon')) {
    /** Font Awesome icon (without the "fas" prefix) for a file name. */
    function app_upload_icon(string $name): string
    {
        return [
            'image' => 'fa-file-image',
            'video' => 'fa-file-video',
            'audio' => 'fa-file-audio',
            'pdf' => 'fa-file-pdf',
            'word' => 'fa-file-word',
            'sheet' => 'fa-file-excel',
            'slides' => 'fa-file-powerpoint',
            'archive' => 'fa-file-zipper',
        ][app_upload_kind($name)] ?? 'fa-file';
    }
}

if (!function_exists('app_upload_error_message')) {
    function app_upload_error_message(int $error, string $label): string
    {
        $limit = app_upload_format_bytes(app_upload_limit_bytes());

        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "{$label} is larger than the {$limit} upload limit.",
            UPLOAD_ERR_PARTIAL => "{$label} was only partly uploaded. Please try again.",
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => "{$label} could not be saved on the server. Please try again later.",
            UPLOAD_ERR_EXTENSION => "{$label} was blocked by the server.",
            default => "{$label} could not be uploaded.",
        };
    }
}

if (!function_exists('app_upload_store')) {
    /**
     * Validate and store one $_FILES entry under uploads/applications/{opportunity}/.
     *
     * @param list<string>|null $allowedExtensions null = any non-script format
     * @return array{success:bool,error?:string,path?:string,name?:string,size?:int}
     */
    function app_upload_store(
        array $file,
        int $opportunityId,
        string $prefix,
        string $label,
        ?array $allowedExtensions = null
    ): array {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => app_upload_error_message($error, $label)];
        }

        $tmp = (string)($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'error' => "{$label} could not be processed."];
        }

        $size = (int)($file['size'] ?? 0);
        $limit = app_upload_limit_bytes();

        if ($size <= 0) {
            return ['success' => false, 'error' => "{$label} is empty."];
        }

        if ($size > $limit) {
            return ['success' => false, 'error' => "{$label} is larger than the " . app_upload_format_bytes($limit) . ' upload limit.'];
        }

        $original = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if ($extension === '' || !preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            return ['success' => false, 'error' => "{$label} needs a normal file extension (for example .pdf, .jpg or .mp4)."];
        }

        if (app_upload_blocked_name($original)) {
            return ['success' => false, 'error' => "{$label}: script and program files (.php, .exe, .js, .html, ...) cannot be uploaded."];
        }

        if ($allowedExtensions !== null && !in_array($extension, $allowedExtensions, true)) {
            return [
                'success' => false,
                'error' => "{$label} must be one of: " . strtoupper(implode(', ', $allowedExtensions)) . '.',
            ];
        }

        // Content check: reject files whose real content is script/markup or a
        // program, whatever their extension says.
        if (class_exists('finfo')) {
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);

            if (preg_match('#(?:php|html|javascript|ecmascript|x-msdownload|x-dosexec|x-executable|x-sharedlib|x-sh\b|x-shellscript|svg)#i', $mime)) {
                return ['success' => false, 'error' => "{$label}: the file content is not an allowed document, image or video."];
            }
        }

        $relativeDir = 'uploads/applications/' . $opportunityId . '/';
        $filesystemDir = dirname(__DIR__) . '/' . $relativeDir;

        if (!is_dir($filesystemDir) && !mkdir($filesystemDir, 0755, true) && !is_dir($filesystemDir)) {
            return ['success' => false, 'error' => 'Unable to create the application upload folder.'];
        }

        $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        if (!move_uploaded_file($tmp, $filesystemDir . $filename)) {
            return ['success' => false, 'error' => "Failed to save {$label}."];
        }

        return [
            'success' => true,
            'path' => $relativeDir . $filename,
            'name' => mb_substr($original, 0, 255),
            'size' => $size,
        ];
    }
}

if (!function_exists('app_upload_delete_file')) {
    /** Delete a stored application upload (only inside legacy/uploads/applications). */
    function app_upload_delete_file(string $stored): void
    {
        $root = realpath(dirname(__DIR__) . '/uploads/applications');
        $relative = function_exists('ims_upload_relative') ? ims_upload_relative($stored) : null;

        if ($root === false || $relative === null) {
            return;
        }

        $path = realpath(dirname(__DIR__) . '/uploads/' . $relative);

        if ($path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
            @unlink($path);
        }
    }
}

if (!function_exists('app_attachments_for')) {
    /**
     * Extra files stored for an application (application_attachments).
     *
     * @return list<array{document_name:string,document_type:string,file_path:string,file_size:int}>
     */
    function app_attachments_for(mysqli $conn, int $applicationId): array
    {
        if ($applicationId < 1) {
            return [];
        }

        try {
            $stmt = $conn->prepare(
                'SELECT document_name, document_type, file_path, file_size
                   FROM application_attachments
                  WHERE application_id = ?
                  ORDER BY file_path'
            );

            if (!$stmt) {
                return [];
            }

            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];

            while ($row = $result->fetch_assoc()) {
                if (empty($row['file_path'])) {
                    continue;
                }

                $rows[] = [
                    'document_name' => (string)($row['document_name'] ?: basename((string)$row['file_path'])),
                    'document_type' => (string)($row['document_type'] ?: 'Additional Document'),
                    'file_path' => (string)$row['file_path'],
                    'file_size' => (int)($row['file_size'] ?? 0),
                ];
            }

            $stmt->close();

            return $rows;
        } catch (Throwable $exception) {
            error_log('application attachments lookup failed: ' . $exception->getMessage());
            return [];
        }
    }
}
