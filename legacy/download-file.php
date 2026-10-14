<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authenticated download / display endpoint for uploaded files
|--------------------------------------------------------------------------
|
| legacy/uploads/ and public/uploads/ are no longer web-readable (see their
| .htaccess files, which route direct requests here). Pages link uploads
| through ims_upload_url() (legacy/includes/security.php):
|
|   download-file.php?path=receipts/9/rcpt_123.jpg
|   download-file.php?path=applications/3/cv_123.pdf&download=1
|
| Access = logged in AND (owner of the record OR a role allowed to review
| that category of file). See dl_can_access() for the per-category rules.
| Server-side readers (PDF generation etc.) keep reading from disk.
|
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

/** @return never */
function dl_not_found(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo 'File not found.';
    exit;
}

/** @return never */
function dl_forbidden(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo 'You do not have permission to open this file.';
    exit;
}

/**
 * Locate a relative upload path inside one of the allowed upload roots.
 * realpath() + prefix check blocks traversal and symlink escapes.
 */
function dl_locate(string $relative): ?string
{
    $roots = [
        __DIR__ . '/uploads',
        dirname(__DIR__) . '/public/uploads',
    ];

    foreach ($roots as $root) {
        $base = realpath($root);

        if ($base === false) {
            continue;
        }

        $path = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));

        if (
            $path !== false
            && str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            && is_file($path)
            && is_readable($path)
        ) {
            return $path;
        }
    }

    return null;
}

/** LIKE pattern matching any stored form of the path (uploads/x, ../uploads/x, /uploads/x). */
function dl_like_suffix(string $relative): string
{
    return '%uploads/' . addcslashes($relative, '%_\\');
}

function dl_like_contains(string $value): string
{
    return '%' . addcslashes($value, '%_\\') . '%';
}

/**
 * Run a "does a matching row exist" query; any DB error (missing table or
 * column on an older schema) counts as "no".
 */
function dl_exists(mysqli $conn, string $sql, string $types, array $params): bool
{
    try {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result !== false && $result->fetch_row() !== null;
        $stmt->close();

        return $found;
    } catch (Throwable $exception) {
        return false;
    }
}

function dl_application_owner(mysqli $conn, int $applicationId, int $userId): bool
{
    return $applicationId > 0 && dl_exists(
        $conn,
        'SELECT 1 FROM applications WHERE application_id = ? AND submitted_by = ? LIMIT 1',
        'ii',
        [$applicationId, $userId]
    );
}

/** Find the documents-module record for a stored file (handled by documents-handler.php). */
function dl_document_id(mysqli $conn, string $relative): int
{
    try {
        $stmt = $conn->prepare('SELECT document_id FROM documents WHERE file_path LIKE ? ORDER BY document_id DESC LIMIT 1');

        if (!$stmt) {
            return 0;
        }

        $like = dl_like_suffix($relative);
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['document_id'] ?? 0);
    } catch (Throwable $exception) {
        return 0;
    }
}

/**
 * Per-category authorization. The first path segment is the upload
 * category (the folder the upload handler writes to).
 */
function dl_can_access(mysqli $conn, string $relative, int $userId): bool
{
    $segments = explode('/', $relative);
    $category = strtolower($segments[0]);
    $like = dl_like_suffix($relative);
    $ownerSegment = isset($segments[1]) && ctype_digit($segments[1]) && count($segments) > 2
        ? (int) $segments[1]
        : 0;

    switch ($category) {
        // Space photos: any signed-in user (booking pages).
        case 'spaces':
            return true;

        // Startup / programme applications: reviewers + the applicant.
        case 'applications':
            if (ims_allowed('downloads.application_files', IMS_STAFF_ROLES, 'Download any application file (not just their own)')) {
                return true;
            }

            return dl_exists(
                $conn,
                'SELECT 1 FROM applications
                  WHERE submitted_by = ?
                    AND (legal_docs_path LIKE ? OR business_plan_path LIKE ? OR pitch_deck_path LIKE ?)
                  LIMIT 1',
                'isss',
                [$userId, $like, $like, $like]
            ) || dl_exists(
                $conn,
                'SELECT 1 FROM application_attachments aa
                   JOIN applications a ON a.application_id = aa.application_id
                  WHERE a.submitted_by = ? AND aa.file_path LIKE ?
                  LIMIT 1',
                'is',
                [$userId, $like]
            );

        // uploads/startup-evidence/{application_id}/{milestone_id}/...
        case 'startup-evidence':
            if (ims_allowed('downloads.startup_evidence', IMS_STAFF_ROLES, 'Download any startup milestone evidence (not just their own)')) {
                return true;
            }

            return dl_application_owner($conn, $ownerSegment, $userId);

        // uploads/job_applications/{user_id}/... (CVs, cover letters)
        case 'job_applications':
            if (ims_allowed('downloads.job_applications', ['Administrator', 'Executive Director', 'Operations/Admin', 'IT Officer', 'HR'], 'Download any job application CV or cover letter')) {
                return true;
            }

            return $ownerSegment > 0 && $ownerSegment === $userId;

        // uploads/employees/{user_id}/... (national IDs, contracts, signatures)
        // Same reviewer roles as employee-profile-view.php.
        case 'employees':
            if (ims_allowed('downloads.employee_files', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'HR', 'Executive Director'], 'Download any employee file (IDs, contracts, signatures)')) {
                return true;
            }

            if ($ownerSegment > 0 && $ownerSegment === $userId) {
                return true;
            }

            return dl_exists(
                $conn,
                'SELECT 1 FROM employee_directory
                  WHERE user_id = ?
                    AND (cv_path LIKE ? OR national_id_path LIKE ? OR academic_documents_path LIKE ?
                         OR cover_letter_path LIKE ? OR good_conduct_cert_path LIKE ? OR signed_policies_path LIKE ?
                         OR signature_path LIKE ? OR signed_contract_path LIKE ? OR residence_map_path LIKE ?)
                  LIMIT 1',
                'isssssssss',
                [$userId, $like, $like, $like, $like, $like, $like, $like, $like, $like]
            );

        // Payment receipts / invoices (members, hub operations, internet).
        case 'receipts':
            if (ims_allowed('downloads.receipts', ['Administrator', 'Executive Director', 'Operations/Admin', 'IT Officer', 'Finance', 'Accountant', 'Procurement Officer'], 'Download any payment receipt or invoice')) {
                return true;
            }

            if ($ownerSegment > 0 && $ownerSegment === $userId) {
                return true;
            }

            return dl_exists(
                $conn,
                'SELECT 1 FROM subscription_payments sp
                   JOIN members m ON m.member_id = sp.member_id
                  WHERE m.user_id = ? AND (sp.receipt_path LIKE ? OR sp.invoice_path LIKE ?)
                  LIMIT 1',
                'iss',
                [$userId, $like, $like]
            ) || dl_exists(
                $conn,
                'SELECT 1 FROM payment_receipts pr
                   LEFT JOIN members m ON m.member_id = pr.member_id
                  WHERE (pr.uploaded_by = ? OR m.user_id = ?) AND pr.receipt_file_path LIKE ?
                  LIMIT 1',
                'iis',
                [$userId, $userId, $like]
            ) || dl_exists(
                $conn,
                'SELECT 1 FROM internet_subscriptions s
                   JOIN members m ON m.member_id = s.member_id
                  WHERE m.user_id = ? AND s.receipt_photo LIKE ?
                  LIMIT 1',
                'is',
                [$userId, $like]
            );

        // Member feedback attachments (stored as a JSON list on member_feedback).
        case 'feedback':
            if (ims_allowed('downloads.feedback_attachments', IMS_ADMIN_ROLES, 'Download any member feedback attachment')) {
                return true;
            }

            return dl_exists(
                $conn,
                'SELECT 1 FROM member_feedback f
                   JOIN members m ON m.member_id = f.member_id
                  WHERE m.user_id = ? AND f.attachments LIKE ?
                  LIMIT 1',
                'is',
                [$userId, dl_like_contains(basename($relative))]
            );

        // Partner recommendation letters (donors-partners.php roles).
        case 'recommendations':
            return ims_allowed('downloads.recommendation_letters', ['Administrator', 'Executive Director', 'Operations/Admin', 'IT Officer', 'Programs Lead', 'Program Director', 'MEAL Lead'], 'Download partner recommendation letters');

        // Procurement quotations (manage_quotations / manage_quotation_reviews roles).
        case 'quotations':
            return ims_allowed('downloads.quotations', [
                'Administrator', 'Operations/Admin', 'Accountant', 'Procurement Officer', 'Finance',
                'Executive Director', 'Programs Lead', 'Program Manager', 'Program Officer', 'Staff',
            ], 'Download procurement quotations');

        // Internal programme / M&E / finance material: staff only.
        case 'milestones':
        case 'startup-field-visits':
        case 'risk_reports':
        case 'risk_rating_reports':
        case 'risk_rating_signatures':
        case 'templates':
        case 'logos':
        case 'voucher_logos':
        case 'voucher_branding':
        case 'voucher':
            return ims_allowed('downloads.internal_files', IMS_STAFF_ROLES, 'Download internal programme, M&E and finance files (milestones, risk reports, templates, logos, vouchers)');

        // Documents without a documents-module record (orphans): admins.
        case 'documents':
        default:
            return ims_allowed('downloads.other_files', IMS_ADMIN_ROLES, 'Download unlinked documents and other uncategorised uploads');
    }
}

/*
|--------------------------------------------------------------------------
| Request
|--------------------------------------------------------------------------
*/

require_login('login');

$relative = ims_upload_relative('uploads/' . (string) ($_GET['path'] ?? ''));

if ($relative === null || !preg_match('#^[\w .()+,@=&\'\#~-]+(?:/[\w .()+,@=&\'\#~-]+)*$#u', $relative)) {
    dl_not_found();
}

$userId = auth_user_id();
$database = (isset($conn) && $conn instanceof mysqli) ? $conn : db_connect();
$forceDownload = isset($_GET['download']) && (string) $_GET['download'] === '1';

// Documents-module files: the documents handler applies folder/document
// permissions (and transparently decompresses .gz-stored files).
if (strtolower(explode('/', $relative)[0]) === 'documents') {
    $documentId = dl_document_id($database, $relative);

    if ($documentId > 0) {
        header(
            'Location: includes/documents-handler.php?action=' . ($forceDownload ? 'download' : 'view') . '&id=' . $documentId,
            true,
            302
        );
        exit;
    }
}

if (!dl_can_access($database, $relative, $userId)) {
    error_log(sprintf('[security] upload access denied: user=%d path=%s', $userId, $relative));
    dl_forbidden();
}

$path = dl_locate($relative);

if ($path === null) {
    dl_not_found();
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

// Never serve anything script-like, even if it slipped into an upload folder.
if (preg_match('/^(?:php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|asp|aspx|jsp|exe|bat|cmd|htaccess|html?|xhtml|shtml|js|mjs|svg|svgz|xml)$/', $extension)) {
    dl_forbidden();
}

$mime = '';

if (class_exists('finfo')) {
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
}

$inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$inline = !$forceDownload && in_array($mime, $inlineTypes, true);

if ($mime === '' || preg_match('#(?:html|javascript|xml|svg)#i', $mime)) {
    $mime = 'application/octet-stream';
    $inline = false;
}

$filename = basename($path);
$asciiName = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename);
$asciiName = trim($asciiName) !== '' ? $asciiName : 'download';

if (function_exists('log_action') && !$inline) {
    log_action($userId, 'Download File', 'uploads', null, $relative);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header_remove('Content-Security-Policy');
header('Content-Type: ' . $mime);
header(
    'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

if (str_starts_with($mime, 'image/')) {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
} elseif (!$inline) {
    header("Content-Security-Policy: default-src 'none'; sandbox");
}

readfile($path);
exit;
