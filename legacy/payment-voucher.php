<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

check_role(IMS_ALL_ROLES);



if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

$conn->set_charset('utf8mb4');

require_once __DIR__ . '/includes/voucher-approval.php';
$pvApprovalReady = pva_ensure_schema($conn);

$selfPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$selfPath = is_string($selfPath) && $selfPath !== ''
    ? $selfPath
    : '/payment_vouchers';


function pvEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function pvCurrencyBadge(string $currency): string
{
    return match (strtoupper($currency)) {
        'USD' => 'badge-usd',
        'EUR' => 'badge-eur',
        'KES' => 'badge-kes',
        'GBP' => 'badge-gbp',
        'TZS' => 'badge-tzs',
        default => 'badge-ugx',
    };
}

function pvPageUrl(string $selfPath, int $page, string $query, int $perPage): string
{
    $parameters = [
        'page' => max(1, $page),
        'per_page' => $perPage,
    ];

    if ($query !== '') {
        $parameters['q'] = $query;
    }

    return $selfPath . '?' . http_build_query($parameters);
}

function pvPageWindow(int $current, int $total): array
{
    $start = max(1, $current - 2);
    $end = min($total, $current + 2);

    if (($end - $start) < 4) {
        if ($start === 1) {
            $end = min($total, $start + 4);
        } else {
            $start = max(1, $end - 4);
        }
    }

    return range($start, $end);
}

function pvDecodeItems(mixed $json): array
{
    if (is_array($json)) {
        return $json;
    }

    $decoded = json_decode((string)$json, true);

    return is_array($decoded) ? $decoded : [];
}



function pvCurrentVoucherLogo(mysqli $conn): string
{
    

    $result = $conn->query(
        "SELECT voucher_logo
         FROM voucher_settings
         WHERE id = 1
         LIMIT 1"
    );

    if (!$result) {
        return '';
    }

    $row = $result->fetch_assoc();

    return trim((string)($row['voucher_logo'] ?? ''));
}

function pvVoucherLogoWebPath(string $filename): string
{
    $filename = basename(trim($filename));

    return $filename === ''
        ? ''
        : ims_upload_url('uploads/voucher_branding/' . $filename);
}

function pvLogoFilePath(mysqli $conn, ?string $legacyFilename = null): string
{
    $globalFilename = pvCurrentVoucherLogo($conn);

    $filenames = [];

    if ($globalFilename !== '') {
        $filenames[] = basename($globalFilename);
    }

    // Backward compatibility only when no shared logo is configured.
    if ($globalFilename === '' && trim((string)$legacyFilename) !== '') {
        $filenames[] = basename((string)$legacyFilename);
    }

    foreach ($filenames as $filename) {
        $candidatePaths = [
            __DIR__ . '/uploads/voucher_branding/' . $filename,
            dirname(__DIR__) . '/uploads/voucher_branding/' . $filename,
            __DIR__ . '/uploads/voucher_logos/' . $filename,
            dirname(__DIR__) . '/uploads/voucher_logos/' . $filename,
        ];

        foreach ($candidatePaths as $candidatePath) {
            if (is_file($candidatePath) && is_readable($candidatePath)) {
                return realpath($candidatePath) ?: $candidatePath;
            }
        }
    }

    return '';
}

function pvPdfFileUri(string $path): string
{
    if ($path === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $path);

    // Dompdf reads the local image directly. This avoids repeating a large
    // Base64 image string once for every selected voucher.
    return 'file://' . $normalized;
}


function pvOriginalImageDataUri(string $path): string
{
    if (
        $path === '' ||
        !is_file($path) ||
        !is_readable($path)
    ) {
        return '';
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

    if (
        !is_string($mime) ||
        !in_array(
            $mime,
            ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
            true
        )
    ) {
        return '';
    }

    $contents = file_get_contents($path);

    return $contents === false
        ? ''
        : 'data:' . $mime . ';base64,' . base64_encode($contents);
}

function pvCachedPdfLogoDataUri(mysqli $conn): string
{
    $sourcePath = pvLogoFilePath($conn);

    if ($sourcePath === '') {
        return '';
    }

    $cacheDirectory =
        __DIR__ .
        '/uploads/voucher_branding/pdf_cache';

    if (
        !is_dir($cacheDirectory) &&
        !@mkdir($cacheDirectory, 0755, true) &&
        !is_dir($cacheDirectory)
    ) {
        return pvOriginalImageDataUri($sourcePath);
    }

    $cacheKey = sha1(
        $sourcePath .
        '|' .
        (string)@filemtime($sourcePath) .
        '|' .
        (string)@filesize($sourcePath)
    );

    $cachedPath =
        $cacheDirectory .
        '/voucher_logo_' .
        $cacheKey .
        '.jpg';

    if (
        !is_file($cachedPath) &&
        function_exists('imagecreatefromstring') &&
        function_exists('imagejpeg')
    ) {
        $sourceContents = file_get_contents($sourcePath);

        if ($sourceContents !== false) {
            $sourceImage = @imagecreatefromstring($sourceContents);

            if ($sourceImage !== false) {
                $sourceWidth = imagesx($sourceImage);
                $sourceHeight = imagesy($sourceImage);

                $maxWidth = 420;
                $maxHeight = 170;

                $scale = min(
                    $maxWidth / max(1, $sourceWidth),
                    $maxHeight / max(1, $sourceHeight),
                    1
                );

                $targetWidth = max(
                    1,
                    (int)round($sourceWidth * $scale)
                );

                $targetHeight = max(
                    1,
                    (int)round($sourceHeight * $scale)
                );

                $canvas = imagecreatetruecolor(
                    $targetWidth,
                    $targetHeight
                );

                if ($canvas !== false) {
                    $white = imagecolorallocate(
                        $canvas,
                        255,
                        255,
                        255
                    );

                    imagefill($canvas, 0, 0, $white);

                    imagecopyresampled(
                        $canvas,
                        $sourceImage,
                        0,
                        0,
                        0,
                        0,
                        $targetWidth,
                        $targetHeight,
                        $sourceWidth,
                        $sourceHeight
                    );

                    @imagejpeg($canvas, $cachedPath, 82);
                    imagedestroy($canvas);
                }

                imagedestroy($sourceImage);
            }
        }
    }

    if (
        is_file($cachedPath) &&
        is_readable($cachedPath)
    ) {
        $contents = file_get_contents($cachedPath);

        if ($contents !== false) {
            return 'data:image/jpeg;base64,' .
                base64_encode($contents);
        }
    }

    return pvOriginalImageDataUri($sourcePath);
}

/*
|--------------------------------------------------------------------------
| CSV template download
|--------------------------------------------------------------------------
*/
if ((string)($_GET['download_template'] ?? '') === '1') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="voucher_upload_template.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        http_response_code(500);
        exit('Unable to create the template.');
    }

    fwrite($output, "\xEF\xBB\xBF");

    ims_fputcsv($output, [
        'row_group',
        'voucher_series',
        'voucher_no',
        'voucher_date',
        'supplier',
        'currency',
        'exchange_rate',
        'total_usd',
        'account_code',
        'project_code',
        'jnl_ref',
        'cheque_ref',
        'item_description',
        'item_qty',
        'item_unit_price',
        'item_budget_line',
    ]);

    ims_fputcsv($output, [
        '1',
        'UGX1',
        '',
        date('Y-m-d'),
        'Acme Supplies Ltd',
        'UGX',
        '',
        '',
        'ACC-001',
        'PRJ-100',
        '',
        '',
        'Office stationery',
        '10',
        '15000',
        'Administration',
    ]);

    ims_fputcsv($output, [
        '1',
        'UGX1',
        '',
        date('Y-m-d'),
        'Acme Supplies Ltd',
        'UGX',
        '',
        '',
        'ACC-001',
        'PRJ-100',
        '',
        '',
        'Printer toner',
        '2',
        '120000',
        'Administration',
    ]);

    fclose($output);
    exit;
}

/*
|--------------------------------------------------------------------------
| Export selected vouchers to one fast PDF
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (string)($_POST['action'] ?? '') === 'export_selected_pdf'
) {
    $rawIds = $_POST['voucher_ids'] ?? [];

    if (!is_array($rawIds)) {
        $rawIds = [];
    }

    $voucherIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $rawIds),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if ($voucherIds === []) {
        $_SESSION['error'] =
            'Select at least one voucher to export.';

        header('Location: ' . $selfPath);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | A smaller batch renders and downloads much faster.
    |--------------------------------------------------------------------------
    */
    if (count($voucherIds) > 25) {
        $_SESSION['error'] =
            'You can export a maximum of 25 vouchers at once.';

        header('Location: ' . $selfPath);
        exit;
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($voucherIds), '?')
    );

    $types = str_repeat('i', count($voucherIds));

    /*
    |--------------------------------------------------------------------------
    | Fetch only fields used in the PDF
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT
            id,
            voucher_no,
            voucher_date,
            supplier,
            currency,
            total_amount,
            exchange_rate,
            total_usd,
            account_code,
            project_code,
            jnl_ref,
            cheque_ref,
            items_json" .
            ($pvApprovalReady
                ? ', approval_status, prepared_by, prepared_signature, prepared_signature_mime, prepared_signed_at,
                   approver_id, approved_signature, approved_signature_mime, approved_at'
                : '') . "
         FROM payment_vouchers
         WHERE id IN ($placeholders)
         ORDER BY voucher_date ASC, id ASC"
    );

    if (!$stmt) {
        http_response_code(500);
        exit('Unable to prepare the PDF export.');
    }

    $stmt->bind_param($types, ...$voucherIds);
    $stmt->execute();

    $result = $stmt->get_result();
    $selectedVouchers = [];

    while ($row = $result->fetch_assoc()) {
        $row['items'] = pvDecodeItems(
            $row['items_json'] ?? '[]'
        );

        unset($row['items_json']);
        $selectedVouchers[] = $row;
    }

    $stmt->close();

    if ($selectedVouchers === []) {
        $_SESSION['error'] =
            'The selected vouchers could not be found.';

        header('Location: ' . $selfPath);
        exit;
    }

    @ini_set('memory_limit', '512M');
    @set_time_limit(180);

    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        http_response_code(500);
        exit('Composer autoload was not found.');
    }

    require_once $autoload;

    /*
    |--------------------------------------------------------------------------
    | Resize and cache the logo once, then reuse it for every voucher page.
    |--------------------------------------------------------------------------
    */
    $logoDataUri = pvCachedPdfLogoDataUri($conn);

    $logoHtml = $logoDataUri !== ''
        ? '<img src="' .
            pvEsc($logoDataUri) .
            '" alt="Voucher logo">'
        : '<span class="logo-text">' . pvEsc(ims_brand_name()) . '</span>';

    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page {
    size: A4 portrait;
    margin: 8mm 10mm;
}

body {
    margin: 0;
    color: #111;
    font-family: Helvetica, Arial, sans-serif;
    font-size: 9pt;
}

.voucher {
    page-break-after: always;
}

.voucher:last-child {
    page-break-after: auto;
}

.logo {
    height: 17mm;
    margin-bottom: 2mm;
    text-align: center;
}

.logo img {
    max-width: 42mm;
    max-height: 17mm;
}

.logo-text {
    color: #f47c20;
    font-size: 16pt;
    font-weight: bold;
}

.title {
    padding: 5pt 0;
    border: .75pt solid #e8864a;
    background: #f5c9a8;
    text-align: center;
    font-size: 15pt;
    font-weight: bold;
    letter-spacing: 1pt;
}

.date {
    margin: 2mm 12mm 3mm 0;
    text-align: right;
}

.date-value {
    display: inline-block;
    min-width: 38mm;
    border-bottom: .5pt solid #111;
    text-align: center;
}

.voucher-number {
    margin-bottom: 4mm;
    padding: 5pt;
    border: 1pt solid #111;
    text-align: center;
    font-size: 11pt;
    font-weight: bold;
}

.supplier {
    margin-bottom: 3mm;
}

.supplier-value {
    display: inline-block;
    min-width: 128mm;
    margin-left: 3pt;
    border-bottom: .5pt dotted #111;
}

table {
    width: 100%;
    border-collapse: collapse;
}

.items {
    margin-bottom: 4mm;
    table-layout: fixed;
}

.items th,
.items td {
    border: .75pt solid #111;
}

.items th {
    height: 8mm;
    padding: 2pt 4pt;
    font-size: 8.5pt;
    text-align: center;
}

.items td {
    height: 8mm;
    padding: 2pt 4pt;
    font-size: 8pt;
}

.items tbody tr:nth-child(even) {
    background: #fafafa;
}

.number {
    width: 6%;
    text-align: center;
}

.description {
    width: 41%;
}

.quantity {
    width: 9%;
}

.unit-price,
.total-price {
    width: 16%;
}

.budget {
    width: 12%;
}

.center {
    text-align: center;
}

.right {
    text-align: right;
}

.summary {
    margin-bottom: 4mm;
}

.summary td {
    width: 50%;
    height: 9mm;
    padding: 3pt 5pt;
    border: .75pt solid #111;
    font-size: 8.3pt;
}

.value {
    display: inline-block;
    min-width: 42mm;
    margin-left: 2pt;
    border-bottom: .4pt dotted #111;
}

.codes {
    margin-bottom: 4mm;
}

.codes th,
.codes td {
    width: 25%;
    border: .75pt solid #111;
    text-align: left;
}

.codes th {
    height: 5mm;
    padding: 3pt 4pt;
    font-size: 8pt;
}

.codes td {
    height: 8mm;
    padding: 3pt 4pt;
    font-size: 8pt;
    vertical-align: top;
}

.signatures th,
.signatures td {
    width: 33.333%;
    border: .75pt solid #111;
    text-align: center;
}

.signatures th {
    height: 5mm;
    padding: 3pt;
    font-size: 8pt;
    font-weight: normal;
}

.signatures td {
    height: 12mm;
}
</style>
</head>
<body>';

    foreach ($selectedVouchers as $voucher) {
        $items = is_array($voucher['items'] ?? null)
            ? array_values($voucher['items'])
            : [];

        $currency = strtoupper(
            trim((string)($voucher['currency'] ?? 'UGX'))
        );

        $voucherDate = !empty($voucher['voucher_date'])
            ? date(
                'd / m / Y',
                strtotime((string)$voucher['voucher_date'])
            )
            : '____ / ____ / ______';

        $rowsHtml = '';
        $rowCount = max(5, count($items));

        for ($index = 0; $index < $rowCount; $index++) {
            $item = $items[$index] ?? [];

            $quantity = $item['qty'] ?? '';
            $unitPrice = $item['unit_price'] ?? '';
            $lineTotal = $item['total_price'] ?? '';

            if (
                $lineTotal === '' &&
                $quantity !== '' &&
                $unitPrice !== ''
            ) {
                $lineTotal =
                    (float)$quantity *
                    (float)$unitPrice;
            }

            $rowsHtml .=
                '<tr>' .
                    '<td class="number">' .
                        ($index + 1) .
                        '.</td>' .
                    '<td>' .
                        pvEsc($item['item'] ?? '') .
                        '</td>' .
                    '<td class="center">' .
                        pvEsc($quantity) .
                        '</td>' .
                    '<td class="right">' .
                        (
                            $unitPrice === ''
                                ? ''
                                : number_format(
                                    (float)$unitPrice,
                                    2
                                )
                        ) .
                        '</td>' .
                    '<td class="right">' .
                        (
                            $lineTotal === ''
                                ? ''
                                : number_format(
                                    (float)$lineTotal,
                                    2
                                )
                        ) .
                        '</td>' .
                    '<td class="center">' .
                        pvEsc($item['budget_line'] ?? '') .
                        '</td>' .
                '</tr>';
        }

        $html .=
            '<section class="voucher">' .
                '<div class="logo">' .
                    $logoHtml .
                '</div>' .

                '<div class="title">' .
                    'PAYMENT VOUCHER' .
                '</div>' .

                '<div class="date">' .
                    '<strong>Date:</strong> ' .
                    '<span class="date-value">' .
                        pvEsc($voucherDate) .
                    '</span>' .
                '</div>' .

                '<div class="voucher-number">' .
                    'Voucher No:&nbsp;&nbsp;' .
                    pvEsc($voucher['voucher_no'] ?? '') .
                '</div>' .

                '<div class="supplier">' .
                    '<strong>Supplier/Receiver\'s Name:</strong>' .
                    '<span class="supplier-value">' .
                        pvEsc($voucher['supplier'] ?? '') .
                    '</span>' .
                '</div>' .

                '<table class="items">' .
                    '<thead>' .
                    '<tr>' .
                        '<th class="number">#</th>' .
                        '<th class="description">Items</th>' .
                        '<th class="quantity">Qty</th>' .
                        '<th class="unit-price">Unit Price</th>' .
                        '<th class="total-price">Total Price</th>' .
                        '<th class="budget">Budget<br>Line</th>' .
                    '</tr>' .
                    '</thead>' .
                    '<tbody>' .
                        $rowsHtml .
                    '</tbody>' .
                '</table>' .

                '<table class="summary">' .
                    '<tr>' .
                        '<td>' .
                            '<strong>Currency:</strong>' .
                            '<span class="value">' .
                                pvEsc($currency) .
                            '</span>' .
                        '</td>' .
                        '<td>' .
                            '<strong>Total Amount:</strong>' .
                            '<span class="value"><strong>' .
                                pvEsc($currency) .
                                ' ' .
                                number_format(
                                    (float)($voucher['total_amount'] ?? 0),
                                    2
                                ) .
                            '</strong></span>' .
                        '</td>' .
                    '</tr>' .
                    '<tr>' .
                        '<td>' .
                            '<strong>Exchange rate of the day:</strong>' .
                            '<span class="value">' .
                                pvEsc($voucher['exchange_rate'] ?? '') .
                            '</span>' .
                        '</td>' .
                        '<td>' .
                            '<strong>In USD:</strong>' .
                            '<span class="value">' .
                                pvEsc($voucher['total_usd'] ?? '') .
                            '</span>' .
                        '</td>' .
                    '</tr>' .
                '</table>' .

                '<table class="codes">' .
                    '<thead>' .
                    '<tr>' .
                        '<th>Account Code:</th>' .
                        '<th>Project Code:</th>' .
                        '<th>JNL Ref / Dated:</th>' .
                        '<th>Cheque Ref.</th>' .
                    '</tr>' .
                    '</thead>' .
                    '<tbody>' .
                    '<tr>' .
                        '<td>' .
                            pvEsc($voucher['account_code'] ?? '') .
                        '</td>' .
                        '<td>' .
                            pvEsc($voucher['project_code'] ?? '') .
                        '</td>' .
                        '<td>' .
                            pvEsc($voucher['jnl_ref'] ?? '') .
                        '</td>' .
                        '<td>' .
                            pvEsc($voucher['cheque_ref'] ?? '') .
                        '</td>' .
                    '</tr>' .
                    '</tbody>' .
                '</table>' .

                '<table class="signatures">' .
                    '<thead>' .
                    '<tr>' .
                        '<th>Admin/Finance Signature:</th>' .
                        '<th>H.O.Dept. Signature:</th>' .
                        '<th>Received By:</th>' .
                    '</tr>' .
                    '</thead>' .
                    '<tbody>' .
                    '<tr>' .
                        pva_signature_cells($conn, $voucher, 'pvEsc', 'max-width:100%;max-height:11mm;') .
                    '</tr>' .
                    '</tbody>' .
                '</table>' .
            '</section>';
    }

    $html .= '</body></html>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', false);
    $options->set('isFontSubsettingEnabled', false);
    $options->set('defaultFont', 'Helvetica');
    $options->set('dpi', 72);

    $tempDirectory =
        sys_get_temp_dir() .
        '/ims_voucher_pdf';

    if (!is_dir($tempDirectory)) {
        @mkdir($tempDirectory, 0755, true);
    }

    if (
        is_dir($tempDirectory) &&
        is_writable($tempDirectory)
    ) {
        $options->set('tempDir', $tempDirectory);
        $options->set('fontCache', $tempDirectory);
    }

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename =
        'selected_payment_vouchers_' .
        date('Ymd_His') .
        '.pdf';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $pdf = $dompdf->output();

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );
    header('Content-Length: ' . strlen($pdf));
    header(
        'Cache-Control: private, max-age=0, must-revalidate'
    );
    header('Pragma: public');

    echo $pdf;
    exit;
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/
$pageTitle = 'Payment Vouchers';
$page_title = $pageTitle;

ob_start();
require_once __DIR__ . '/includes/header.php';

$currentVoucherLogo = pvCurrentVoucherLogo($conn);
$currentVoucherLogoUrl = pvVoucherLogoWebPath($currentVoucherLogo);

$successMessage = (string)($_SESSION['success'] ?? '');
$errorMessage = (string)($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

$month = strtoupper(date('M'));
$year = date('Y');

$seriesList = ['UGX1', 'UGX2', 'Euro Asknet', 'Euro Fempeace', 'UGX Fempeace'];
$nextVoucherNumbers = [];

foreach ($seriesList as $series) {
    $prefix = $series . '-' . $month . '-' . $year . '-';
    $sequence = 1;

    $stmt = $conn->prepare(
        "SELECT voucher_no
         FROM payment_vouchers
         WHERE voucher_no LIKE CONCAT(?, '%')
         ORDER BY CAST(SUBSTRING_INDEX(voucher_no, '-', -1) AS UNSIGNED) DESC
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('s', $prefix);
        $stmt->execute();
        $lastVoucher = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($lastVoucher) {
            $parts = explode('-', (string)$lastVoucher['voucher_no']);
            $sequence = ((int)end($parts)) + 1;
        }
    }

    $nextVoucherNumbers[$series] =
        $prefix . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);
}

$defaultSeries = $seriesList[0];
$autoVoucherNumber = $nextVoucherNumbers[$defaultSeries];

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$canManageVouchers = ims_allowed('vouchers.manage', ['Administrator', 'Finance', 'Accountant'], 'Create, edit, delete and import payment vouchers');
$pvApprovers = $pvApprovalReady ? pva_approvers($conn, $currentUserId) : [];
$pvDefaultApprover = $pvApprovalReady ? pva_default_approver($conn, $currentUserId) : 0;
$pvMySignature = $pvApprovalReady ? pva_profile_signature($conn, $currentUserId) : null;
$pvMySignatureUri = $pvMySignature ? pva_data_uri($pvMySignature['data'], $pvMySignature['mime']) : '';
unset($pvMySignature);

$pvAwaitingMe = [];

if ($pvApprovalReady) {
    $awaitStmt = $conn->prepare(
        "SELECT id, voucher_no, supplier, currency, total_amount, submitted_at
         FROM payment_vouchers
         WHERE approval_status = 'Pending Approval' AND approver_id = ?
         ORDER BY submitted_at ASC
         LIMIT 50"
    );

    if ($awaitStmt) {
        $awaitStmt->bind_param('i', $currentUserId);
        $awaitStmt->execute();
        $awaitResult = $awaitStmt->get_result();

        while ($awaitRow = $awaitResult->fetch_assoc()) {
            $pvAwaitingMe[] = $awaitRow;
        }

        $awaitStmt->close();
    }
}

$allowedPerPage = [10, 15, 25, 50, 100];
$requestedPerPage = (int)($_GET['per_page'] ?? 15);
$perPage = in_array($requestedPerPage, $allowedPerPage, true)
    ? $requestedPerPage
    : 15;

$currentPage = max(1, (int)($_GET['page'] ?? 1));
$query = trim((string)($_GET['q'] ?? ''));

$where = '';
$params = [];
$types = '';

if ($query !== '') {
    $where = 'WHERE voucher_no LIKE ? OR supplier LIKE ? OR project_code LIKE ?';
    $like = '%' . $query . '%';
    $params = [$like, $like, $like];
    $types = 'sss';
}

$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM payment_vouchers ' . $where);
$totalRows = 0;

if ($countStmt) {
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $totalRows = (int)($countResult['total'] ?? 0);
    $countStmt->close();
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$dataSql =
    'SELECT id, voucher_no, voucher_date, supplier, currency,
            total_amount, project_code, created_at' .
            ($pvApprovalReady ? ', approval_status, approver_id' : '') . '
     FROM payment_vouchers ' .
    $where .
    ' ORDER BY voucher_date DESC, id DESC
      LIMIT ? OFFSET ?';

$dataStmt = $conn->prepare($dataSql);
$vouchers = [];

if ($dataStmt) {
    $bindParams = array_merge($params, [$perPage, $offset]);
    $bindTypes = $types . 'ii';

    $dataStmt->bind_param($bindTypes, ...$bindParams);
    $dataStmt->execute();

    $result = $dataStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $vouchers[] = $row;
    }

    $dataStmt->close();
}
?>
<!-- Bootstrap 5.3.3, scoped to .page-payment-voucher so it does not restyle the app layout (used by the modals below) -->
<link rel="stylesheet" href="css/payment-voucher-bootstrap.css">

<style>
/* Payment Vouchers: selection bar, currency chips, pager and the Bootstrap modals. */
.pv-search { margin-bottom: 0; }
.pv-selection-bar { display: none; padding: 12px 16px; margin-bottom: 14px; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; background: var(--brand-50); border: 1px solid var(--brand-200); border-radius: var(--radius-lg); }
.pv-selection-bar.active { display: flex; }
.pv-selection-summary { font-size: 13px; font-weight: 600; }
.pv-selection-summary span { display: inline-grid; place-items: center; min-width: 27px; height: 27px; padding: 0 7px; border-radius: var(--radius-pill); background: var(--brand-500); color: var(--ink-0); margin-right: 7px; }
.pv-selection-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.pv-table tbody tr.row-selected td { background: var(--brand-50); }
.pv-table .voucher-check,
.pv-table .select-all-check { width: 17px; height: 17px; accent-color: var(--brand-500); cursor: pointer; }
.pv-vno { font-size: 12px; font-weight: 600; }
.pv-cur { display: inline-flex; padding: 3px 9px; border-radius: var(--radius-pill); font-size: 11px; font-weight: 700; }
.pv-cur.badge-ugx { background: var(--blue-bg); color: var(--blue-fg); }
.pv-cur.badge-usd { background: var(--green-bg); color: var(--green-fg); }
.pv-cur.badge-eur { background: var(--amber-bg); color: var(--amber-fg); }
.pv-cur.badge-kes { background: var(--purple-bg); color: var(--purple-fg); }
.pv-cur.badge-gbp { background: var(--red-bg); color: var(--red-fg); }
.pv-cur.badge-tzs { background: var(--brand-50); color: var(--brand-700); }
.pv-row-actions { display: flex; gap: 5px; flex-wrap: wrap; }
.pv-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.pv-pager-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.pv-pager-left .form-control { width: auto; }
.pv-pager .pagination { margin-top: 0; flex-wrap: wrap; }
.pv-pager .pagination a.disabled { opacity: .35; pointer-events: none; }

/* Voucher form: standard field sizes in an 800px dialog. */
.page-payment-voucher .pv-form-dialog { max-width: 820px; }
.page-payment-voucher .modal .pv-form .pv-form-section { margin: 18px 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--ink-100); font-size: 12px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--ink-500, #64748b); }
.page-payment-voucher .modal .pv-form .pv-form-section:first-of-type { margin-top: 0; }
.page-payment-voucher .pv-grid { display: grid; gap: 12px 14px; }
.page-payment-voucher .pv-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.page-payment-voucher .pv-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.page-payment-voucher .pv-grid-sign { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); align-items: start; }
.page-payment-voucher .pv-span-2 { grid-column: span 2; }
.page-payment-voucher .pv-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.page-payment-voucher .modal .pv-form label:not(.form-check-label) { margin: 0; font-size: 12px; font-weight: 600; color: var(--ink-600, #475569); text-transform: none; letter-spacing: 0; }
.page-payment-voucher .pv-req { color: var(--red-fg, #b91c1c); }
.page-payment-voucher .modal .pv-form .form-control,
.page-payment-voucher .modal .pv-form .form-select { height: 38px; padding: 6px 11px; font-size: 13px; line-height: 1.4; border: 1px solid var(--ink-200, #cbd5e1); border-radius: var(--radius-sm, 8px); }
.page-payment-voucher .modal .pv-form .form-select { padding-right: 32px; background-position: right 10px center; }
.page-payment-voucher .modal .pv-form .form-control-sm { height: 32px; padding: 4px 8px; font-size: 12px; }
.page-payment-voucher .modal .pv-form .it-input { height: 34px; padding: 5px 8px; font-size: 13px; }
.page-payment-voucher .pv-items-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 8px; }
.page-payment-voucher .pv-total { font-size: 13px; color: var(--ink-500, #64748b); }
.page-payment-voucher .pv-total strong { margin-left: 6px; font-size: 15px; color: var(--ink-900, #0f172a); }
.page-payment-voucher .pv-approval-fields { display: grid; gap: 12px; }
.page-payment-voucher .modal .pv-form .pv-sign-canvas { max-width: 100%; height: 110px; }
@media (max-width: 720px) {
    .page-payment-voucher .pv-grid-3,
    .page-payment-voucher .pv-grid-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .page-payment-voucher .pv-grid-sign { grid-template-columns: 1fr; }
}
@media (max-width: 460px) {
    .page-payment-voucher .pv-grid-3,
    .page-payment-voucher .pv-grid-4 { grid-template-columns: 1fr; }
    .page-payment-voucher .pv-span-2 { grid-column: auto; }
}

/* Bootstrap modals (scoped stylesheet above).
   The app's own .modal / .modal-dialog / .modal-content rules (style.css,
   reports.css) also match these elements and used to leak in: a second white
   dialog box behind the content, a blurred overlay at z-index 9999 and a
   flex-column layout. Reset everything Bootstrap itself does not set. */
.page-payment-voucher { background: transparent; }
.page-payment-voucher .modal { background: transparent; padding: 0; animation: none; backdrop-filter: none; -webkit-backdrop-filter: none; z-index: 1055; }
.page-payment-voucher .modal-dialog { background: transparent; box-shadow: none; border-radius: 0; max-height: none; flex-direction: row; animation: none; }
.page-payment-voucher .modal-dialog:not(.modal-dialog-centered) { display: block; }
.page-payment-voucher .modal-dialog-scrollable { height: calc(100% - 3.5rem); }
.page-payment-voucher .modal-dialog-scrollable .modal-content { max-height: 100%; }
.page-payment-voucher .modal-content { margin: 0; width: 100%; max-width: none; animation: none; background: var(--surface-card, #fff); overflow: hidden; }
.page-payment-voucher .modal-body { background: transparent; }
body > .modal-backdrop { position: fixed; inset: 0; z-index: 1050; width: 100vw; height: 100vh; background: #0f172a; }
body > .modal-backdrop.fade { opacity: 0; transition: opacity .15s linear; }
body > .modal-backdrop.show { opacity: .55; }
.page-payment-voucher .modal .form-select { cursor: pointer; pointer-events: auto; background-color: var(--surface-card, #fff); }
.pv-status { display: inline-flex; padding: 3px 9px; border-radius: var(--radius-pill); font-size: 11px; font-weight: 700; white-space: nowrap; }
.pv-status-draft { background: var(--ink-50); color: var(--ink-600, #475569); }
.pv-status-pending-approval { background: var(--amber-bg); color: var(--amber-fg); }
.pv-status-approved { background: var(--green-bg); color: var(--green-fg); }
.pv-status-returned { background: var(--red-bg); color: var(--red-fg); }
.page-payment-voucher .pv-sign-box { display: grid; gap: 8px; padding: 12px; border: 1px dashed var(--ink-200, #cbd5e1); border-radius: var(--radius-lg); background: var(--ink-50, #f8fafc); }
.page-payment-voucher .pv-sign-canvas { display: block; width: 100%; max-width: 440px; height: 130px; border: 1px solid var(--ink-100); border-radius: var(--radius-sm); background: #fff; touch-action: none; cursor: crosshair; }
.page-payment-voucher .pv-sign-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.page-payment-voucher .pv-sign-tools input[type=file] { max-width: 260px; font-size: 12px; }
.page-payment-voucher .pv-sign-hint { font-size: 12px; color: var(--ink-400); }
.page-payment-voucher .pv-signed-img { max-width: 220px; max-height: 80px; object-fit: contain; background: #fff; border: 1px solid var(--ink-100); border-radius: var(--radius-sm); padding: 4px; }
.page-payment-voucher .btn-orange { background: var(--brand-500); border-color: var(--brand-500); color: var(--ink-0); font-weight: 600; }
.page-payment-voucher .btn-orange:hover { background: var(--brand-700); border-color: var(--brand-700); color: var(--ink-0); }
.page-payment-voucher .modal-content { border-radius: var(--radius-xl); border: 0; box-shadow: var(--shadow-xl); }
.page-payment-voucher .modal-header { border-bottom: 1px solid var(--ink-100); }
.page-payment-voucher .modal-title { font-size: 16px; font-weight: 700; }
.page-payment-voucher .modal .form-label { font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--ink-400); }
.page-payment-voucher .modal .form-control,
.page-payment-voucher .modal .form-select { font-size: 13px; border: 1.5px solid var(--ink-100); }
.page-payment-voucher .modal .form-control:focus,
.page-payment-voucher .modal .form-select:focus { border-color: var(--brand-500); box-shadow: var(--focus-ring); }
.page-payment-voucher .pv-logo-box { min-height: 150px; display: grid; place-items: center; }
.page-payment-voucher .pv-logo-img { max-width: 240px; max-height: 120px; object-fit: contain; }
.page-payment-voucher .items-wrap { overflow-x: auto; }
.page-payment-voucher .items-table { width: 100%; min-width: 650px; border-collapse: collapse; }
.page-payment-voucher .items-table th { font-size: 10px; text-transform: uppercase; background: var(--ink-50); padding: 8px; }
.page-payment-voucher .items-table td { padding: 5px; }
.page-payment-voucher .it-input { width: 100%; border: 1px solid var(--ink-100); border-radius: var(--radius-sm); padding: 7px; font-size: 12px; }
.page-payment-voucher .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 22px; margin-bottom: 18px; }
.page-payment-voucher .dk { font-size: 10px; text-transform: uppercase; font-weight: 700; color: var(--ink-400); }
.page-payment-voucher .dv { font-size: 13px; font-weight: 500; margin-top: 3px; }
.page-payment-voucher .section-title { font-size: 11px; text-transform: uppercase; color: var(--ink-400); font-weight: 700; margin: 17px 0 9px; }
@media (max-width: 576px) {
    .page-payment-voucher .detail-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Payment Vouchers</h1>
        <p>Create, edit, print, bulk upload, and export selected vouchers to PDF.</p>
    </div>
    <?php if ($canManageVouchers): ?>
    <div class="hero-actions">
        <button type="button"
                class="btn"
                data-bs-toggle="modal"
                data-bs-target="#voucherLogoModal"
                onclick="openVoucherLogoModal()">
            <i class="fas fa-image" aria-hidden="true"></i> Voucher Logo
        </button>

        <a href="<?= pvEsc($selfPath) ?>?download_template=1" class="btn">
            <i class="fas fa-download" aria-hidden="true"></i> CSV Template
        </a>

        <button type="button" class="btn"
                data-bs-toggle="modal" data-bs-target="#bulkUploadModal"
                onclick="openBulkUploadModal()">
            <i class="fas fa-file-upload" aria-hidden="true"></i> Bulk Upload
        </button>

        <button type="button" class="btn btn-primary"
                data-bs-toggle="modal" data-bs-target="#voucherModal"
                onclick="openCreateModal()">
            <i class="fas fa-plus" aria-hidden="true"></i> New Voucher
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($successMessage !== ''): ?>
<div class="alert alert-success"><i class="fas fa-check-circle" aria-hidden="true"></i><?= pvEsc($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?= pvEsc($errorMessage) ?></div>
<?php endif; ?>

<?php if (!$pvApprovalReady): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i>Voucher signatures and approvals are not set up yet. Ask an administrator to apply <code>database/migrations/2026_11_19_voucher_approvals.sql</code>.</div>
<?php endif; ?>

<?php if ($pvAwaitingMe !== []): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-signature" aria-hidden="true"></i> Awaiting your approval (<?= count($pvAwaitingMe) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Voucher No</th><th>Supplier</th><th>Total</th><th>Submitted</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pvAwaitingMe as $pending): ?>
                    <tr>
                        <td><span class="pv-vno"><?= pvEsc($pending['voucher_no']) ?></span></td>
                        <td><?= pvEsc($pending['supplier']) ?></td>
                        <td><?= pvEsc($pending['currency']) ?> <?= number_format((float)$pending['total_amount'], 2) ?></td>
                        <td><?= !empty($pending['submitted_at']) ? pvEsc(date('d M Y H:i', strtotime((string)$pending['submitted_at']))) : '-' ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-primary"
                                    data-bs-toggle="modal" data-bs-target="#approvalModal"
                                    onclick="openApproval(<?= (int)$pending['id'] ?>)">
                                <i class="fas fa-file-signature" aria-hidden="true"></i> Review &amp; sign
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="get" action="<?= pvEsc($selfPath) ?>" class="filters-bar pv-search" role="search" aria-label="Search vouchers">
            <input type="hidden" name="page" value="1">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <input type="text" name="q" class="form-control filters-grow"
                   placeholder="Search voucher number, supplier, or project..."
                   value="<?= pvEsc($query) ?>">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-search" aria-hidden="true"></i> Search
            </button>
            <?php if ($query !== ''): ?>
            <a href="<?= pvEsc($selfPath) ?>?per_page=<?= $perPage ?>" class="btn btn-soft">
                <i class="fas fa-times" aria-hidden="true"></i> Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<form method="post" action="<?= pvEsc($selfPath) ?>" id="selectedExportForm">
    <input type="hidden" name="action" value="export_selected_pdf">

    <div class="pv-selection-bar" id="selectionBar">
        <div class="pv-selection-summary">
            <span id="selectedCount">0</span>
            voucher(s) selected
            <small class="cell-muted">Maximum 25 per PDF for faster download</small>
        </div>

        <div class="pv-selection-actions">
            <button type="button" class="btn btn-sm btn-soft" onclick="clearVoucherSelection()">
                <i class="fas fa-times" aria-hidden="true"></i> Clear
            </button>

            <button type="submit" class="btn btn-sm btn-primary" id="exportSelectedBtn" disabled>
                <i class="fas fa-file-pdf" aria-hidden="true"></i> Export Selected PDF
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-list" aria-hidden="true"></i> Vouchers</h3></div>
        <div class="card-body">
        <div class="table-responsive">
        <table class="table pv-table">
            <thead>
            <tr>
                <th class="text-center" style="width:46px">
                    <input type="checkbox" class="select-all-check" id="selectAllVouchers"
                           aria-label="Select all vouchers on this page">
                </th>
                <th style="width:180px">Voucher No</th>
                <th style="width:120px">Date</th>
                <th>Supplier</th>
                <th style="width:90px">Currency</th>
                <th style="width:155px">Total</th>
                <th style="width:130px">Project</th>
                <th style="width:130px">Approval</th>
                <th style="width:160px">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($vouchers === []): ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <i class="fas fa-file-invoice" aria-hidden="true"></i>
                            <p><?= $query !== ''
                                ? 'No vouchers match <strong>' . pvEsc($query) . '</strong>.'
                                : 'No vouchers have been created yet.' ?></p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($vouchers as $voucher): ?>
                    <?php
                    $currency = strtoupper((string)($voucher['currency'] ?? 'UGX'));
                    $id = (int)$voucher['id'];
                    ?>
                    <tr id="voucherRow<?= $id ?>">
                        <td class="text-center">
                            <input type="checkbox"
                                   class="voucher-check"
                                   name="voucher_ids[]"
                                   value="<?= $id ?>"
                                   aria-label="Select voucher <?= pvEsc($voucher['voucher_no']) ?>">
                        </td>
                        <td><span class="pv-vno"><?= pvEsc($voucher['voucher_no']) ?></span></td>
                        <td><?= !empty($voucher['voucher_date'])
                                ? date('d M Y', strtotime((string)$voucher['voucher_date']))
                                : '-' ?></td>
                        <td><?= pvEsc($voucher['supplier'] ?? '-') ?></td>
                        <td>
                            <span class="pv-cur <?= pvCurrencyBadge($currency) ?>">
                                <?= pvEsc($currency) ?>
                            </span>
                        </td>
                        <td><?= pvEsc($currency) ?> <?= number_format((float)($voucher['total_amount'] ?? 0), 2) ?></td>
                        <td><?= pvEsc($voucher['project_code'] ?? '-') ?></td>
                        <td>
                            <?php $approvalStatus = (string)($voucher['approval_status'] ?? 'Draft'); ?>
                            <span class="pv-status pv-status-<?= pvEsc(strtolower(str_replace(' ', '-', $approvalStatus))) ?>">
                                <?= pvEsc($approvalStatus) ?>
                            </span>
                        </td>
                        <td>
                            <div class="pv-row-actions">
                                <?php if ($pvApprovalReady && pva_can_approve($voucher, $currentUserId)): ?>
                                <button type="button" class="btn btn-sm btn-primary"
                                        title="Review and sign"
                                        data-bs-toggle="modal" data-bs-target="#approvalModal"
                                        onclick="openApproval(<?= $id ?>)">
                                    <i class="fas fa-file-signature"></i>
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-soft"
                                        title="View"
                                        data-bs-toggle="modal" data-bs-target="#viewModal"
                                        onclick="viewVoucher(<?= $id ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>

                                <a href="voucher_print?id=<?= $id ?>"
                                   target="_blank"
                                   class="btn btn-sm btn-soft"
                                   title="Print">
                                    <i class="fas fa-print"></i>
                                </a>

                                <?php if ($canManageVouchers): ?>
                                <button type="button" class="btn btn-sm btn-primary"
                                        title="Edit"
                                        data-bs-toggle="modal" data-bs-target="#voucherModal"
                                        onclick="editVoucher(<?= $id ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
</form>

<?php if ($totalRows > 0): ?>
<?php
$window = pvPageWindow($currentPage, $totalPages);
$firstWindow = $window[0];
$lastWindow = end($window);
$startRow = $offset + 1;
$endRow = min($offset + $perPage, $totalRows);
?>
<div class="card">
    <div class="card-body pv-pager">
        <div class="pv-pager-left">
            <div class="cell-muted">
                Showing <strong><?= $startRow ?>-<?= $endRow ?></strong>
                of <strong><?= number_format($totalRows) ?></strong>
            </div>

            <select class="form-control"
                    aria-label="Rows per page"
                    onchange="changePerPage(this.value)">
                <?php foreach ($allowedPerPage as $amount): ?>
                    <option value="<?= $amount ?>" <?= $amount === $perPage ? 'selected' : '' ?>>
                        <?= $amount ?> rows
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pagination">
            <a class="<?= $currentPage <= 1 ? 'disabled' : '' ?>"
               href="<?= pvEsc(pvPageUrl($selfPath, 1, $query, $perPage)) ?>">
                <i class="fas fa-angles-left"></i>
            </a>

            <a class="<?= $currentPage <= 1 ? 'disabled' : '' ?>"
               href="<?= pvEsc(pvPageUrl($selfPath, $currentPage - 1, $query, $perPage)) ?>">
                <i class="fas fa-angle-left"></i>
            </a>

            <?php if ($firstWindow > 1): ?>
                <a href="<?= pvEsc(pvPageUrl($selfPath, 1, $query, $perPage)) ?>">1</a>
            <?php endif; ?>

            <?php foreach ($window as $page): ?>
                <a class="<?= $page === $currentPage ? 'active' : '' ?>"
                   href="<?= pvEsc(pvPageUrl($selfPath, $page, $query, $perPage)) ?>">
                    <?= $page ?>
                </a>
            <?php endforeach; ?>

            <?php if ($lastWindow < $totalPages): ?>
                <a href="<?= pvEsc(pvPageUrl($selfPath, $totalPages, $query, $perPage)) ?>">
                    <?= $totalPages ?>
                </a>
            <?php endif; ?>

            <a class="<?= $currentPage >= $totalPages ? 'disabled' : '' ?>"
               href="<?= pvEsc(pvPageUrl($selfPath, $currentPage + 1, $query, $perPage)) ?>">
                <i class="fas fa-angle-right"></i>
            </a>

            <a class="<?= $currentPage >= $totalPages ? 'disabled' : '' ?>"
               href="<?= pvEsc(pvPageUrl($selfPath, $totalPages, $query, $perPage)) ?>">
                <i class="fas fa-angles-right"></i>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /.page-stack -->

<!-- Bootstrap modals stay inside the scoped wrapper so the scoped stylesheet applies to them. -->
<div class="page-payment-voucher">

<!-- Global voucher logo modal -->
<div class="modal fade" id="voucherLogoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-image me-2"></i>Voucher Logo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div id="voucherLogoAlert"></div>

                <div class="text-center border rounded p-3 mb-3 bg-light pv-logo-box">
                    <?php if ($currentVoucherLogoUrl !== ''): ?>
                        <img id="voucherLogoPreview"
                             src="<?= pvEsc($currentVoucherLogoUrl) ?>?v=<?= time() ?>"
                             alt="Voucher logo"
                             class="pv-logo-img">
                        <div id="voucherLogoEmpty" class="text-muted" style="display:none">
                            No voucher logo uploaded.
                        </div>
                    <?php else: ?>
                        <img id="voucherLogoPreview"
                             src=""
                             alt="Voucher logo"
                             class="pv-logo-img" style="display:none">
                        <div id="voucherLogoEmpty" class="text-muted">
                            <i class="fas fa-image fa-2x d-block mb-2"></i>
                            No voucher logo uploaded.
                        </div>
                    <?php endif; ?>
                </div>

                <form id="voucherLogoForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_voucher_logo">

                    <label class="form-label">Choose Voucher Logo</label>
                    <input type="file"
                           class="form-control"
                           name="voucher_logo"
                           id="voucher_logo"
                           accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                           required>

                    <div class="form-text">
                        PNG, JPG, or WEBP. Maximum size: 2 MB.
                        The uploaded logo will appear on every voucher PDF.
                    </div>
                </form>
            </div>

            <div class="modal-footer justify-content-between">
                <button type="button"
                        class="btn btn-outline-danger"
                        id="removeVoucherLogoBtn"
                        onclick="removeVoucherLogo()"
                        <?= $currentVoucherLogo === '' ? 'disabled' : '' ?>>
                    <i class="fas fa-trash me-1"></i>Remove Logo
                </button>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Close
                    </button>

                    <button type="button"
                            class="btn btn-orange"
                            id="saveVoucherLogoBtn"
                            onclick="saveVoucherLogo()">
                        <i class="fas fa-upload me-1"></i>Upload Logo
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit modal -->
<div class="modal fade" id="voucherModal" tabindex="-1" aria-hidden="true" aria-labelledby="voucherModalTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable pv-form-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="voucherModalTitle">
                    <i class="fas fa-file-invoice me-2"></i>New Voucher
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div id="modalAlert"></div>

                <form id="voucherForm" class="pv-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_voucher">
                    <input type="hidden" name="id" id="pv_id">

                    <div class="pv-form-section">Voucher details</div>
                    <div class="pv-grid pv-grid-3">
                        <div class="pv-field">
                            <label for="voucher_series">Series</label>
                            <select class="form-select" name="voucher_series" id="voucher_series"
                                    onchange="onSeriesChange(this.value)">
                                <?php foreach ($seriesList as $series): ?>
                                    <option value="<?= pvEsc($series) ?>"><?= pvEsc($series) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pv-field">
                            <label for="voucher_no">Voucher number <span class="pv-req">*</span></label>
                            <input type="text" class="form-control" name="voucher_no"
                                   id="voucher_no" required value="<?= pvEsc($autoVoucherNumber) ?>">
                        </div>
                        <div class="pv-field">
                            <label for="voucher_date">Date <span class="pv-req">*</span></label>
                            <input type="date" class="form-control" name="voucher_date"
                                   id="voucher_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="pv-field pv-span-2">
                            <label for="supplier">Supplier / receiver <span class="pv-req">*</span></label>
                            <input type="text" class="form-control" name="supplier" id="supplier" required>
                        </div>
                        <div class="pv-field">
                            <label for="currency">Currency</label>
                            <select class="form-select" name="currency" id="currency">
                                <?php foreach (['UGX','USD','EUR','KES','GBP','TZS'] as $currencyOption): ?>
                                    <option value="<?= $currencyOption ?>"><?= $currencyOption ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pv-field">
                            <label for="exchange_rate">Exchange rate</label>
                            <input type="text" class="form-control" name="exchange_rate" id="exchange_rate" inputmode="decimal">
                        </div>
                        <div class="pv-field">
                            <label for="total_usd">Equivalent amount</label>
                            <input type="text" class="form-control" name="total_usd" id="total_usd" inputmode="decimal">
                        </div>
                    </div>

                    <div class="pv-form-section">Codes &amp; references</div>
                    <div class="pv-grid pv-grid-4">
                        <div class="pv-field">
                            <label for="account_code">Account code</label>
                            <input type="text" class="form-control" name="account_code" id="account_code">
                        </div>
                        <div class="pv-field">
                            <label for="project_code">Project code</label>
                            <input type="text" class="form-control" name="project_code" id="project_code">
                        </div>
                        <div class="pv-field">
                            <label for="jnl_ref">JNL reference</label>
                            <input type="text" class="form-control" name="jnl_ref" id="jnl_ref">
                        </div>
                        <div class="pv-field">
                            <label for="cheque_ref">Cheque reference</label>
                            <input type="text" class="form-control" name="cheque_ref" id="cheque_ref">
                        </div>
                    </div>

                    <div class="pv-form-section">Line items</div>
                    <div class="items-wrap">
                        <table class="items-table">
                            <thead>
                            <tr>
                                <th style="width:32px">#</th>
                                <th>Description</th>
                                <th style="width:80px">Qty</th>
                                <th style="width:120px">Unit price</th>
                                <th style="width:120px">Total</th>
                                <th style="width:130px">Budget line</th>
                                <th style="width:40px"></th>
                            </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                    </div>
                    <div class="pv-items-foot">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addItemRow()">
                            <i class="fas fa-plus me-1"></i>Add row
                        </button>
                        <span class="pv-total">Total <strong id="totalDisplay">UGX 0.00</strong></span>
                    </div>

                    <?php if ($pvApprovalReady): ?>
                    <div class="pv-form-section">Admin/Finance signature &amp; approval</div>
                    <div id="pvApprovalNote" class="alert alert-info py-2 small" style="display:none"></div>

                    <div class="pv-grid pv-grid-sign">
                        <div class="pv-sign-box" data-sign-box
                             data-field="prepared_signature_data"
                             data-profile="<?= pvEsc($pvMySignatureUri) ?>">
                            <strong class="small">Your signature</strong>
                            <img class="pv-signed-img" data-sign-preview alt="Your signature" style="display:none">
                            <canvas class="pv-sign-canvas" width="440" height="130" data-sign-canvas
                                    aria-label="Draw your signature" style="display:none"></canvas>
                            <input type="hidden" name="prepared_signature_data" data-sign-value>
                            <input type="hidden" name="use_profile_signature" value="0" data-sign-use-profile>
                            <div class="pv-sign-tools">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-sign-profile
                                        <?= $pvMySignatureUri === '' ? 'disabled' : '' ?>>
                                    <i class="fas fa-id-card me-1"></i>Profile signature
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-sign-draw>
                                    <i class="fas fa-pen me-1"></i>Draw
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-sign-clear>
                                    <i class="fas fa-eraser me-1"></i>Clear
                                </button>
                            </div>
                            <input type="file" class="form-control form-control-sm" name="prepared_signature_file"
                                   accept="image/png,image/jpeg" data-sign-file aria-label="Upload signature image">
                            <span class="pv-sign-hint" data-sign-hint>
                                <?= $pvMySignatureUri !== ''
                                    ? 'Your profile signature is attached. Draw or upload to replace it for this voucher.'
                                    : 'No signature on your profile yet (My Profile &gt; Certification). Draw or upload one.' ?>
                            </span>
                        </div>

                        <div class="pv-approval-fields">
                            <div class="pv-field">
                                <label for="approver_id">Approver (Senior Finance / HOD)</label>
                                <select class="form-select" name="approver_id" id="approver_id">
                                    <option value="">Select approver...</option>
                                    <?php foreach ($pvApprovers as $approver): ?>
                                        <option value="<?= (int)$approver['user_id'] ?>"
                                            <?= $approver['user_id'] === $pvDefaultApprover ? 'selected' : '' ?>>
                                            <?= pvEsc($approver['full_name']) ?> &middot; <?= pvEsc($approver['role']) ?><?= $approver['department'] !== '' ? ' (' . pvEsc($approver['department']) . ')' : '' ?><?= $approver['user_id'] === $pvDefaultApprover ? ' - your HOD' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       name="submit_for_approval" id="submit_for_approval" checked>
                                <label class="form-check-label small" for="submit_for_approval">
                                    Send to the approver now (notification and email to review and sign)
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-orange" id="saveVoucherBtn" onclick="submitVoucher()">
                    <i class="fas fa-save me-1"></i>Save Voucher
                </button>
            </div>
        </div>
    </div>
</div>

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Voucher Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBox">Loading...</div>
        </div>
    </div>
</div>

<!-- Approval modal (Senior Finance / HOD) -->
<div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-signature me-2"></i>Review &amp; sign voucher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div id="approvalAlert"></div>
                <div id="approvalSummary">Loading...</div>

                <form id="approvalForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="approve_voucher">
                    <input type="hidden" name="id" id="approval_id">
                    <input type="hidden" name="decision" id="approval_decision" value="approve">

                    <div class="section-title">Your signature (H.O.Dept / Senior Finance)</div>
                    <div class="pv-sign-box" data-sign-box
                         data-field="approver_signature_data"
                         data-profile="<?= pvEsc($pvMySignatureUri) ?>">
                        <img class="pv-signed-img" data-sign-preview alt="Your signature" style="display:none">
                        <canvas class="pv-sign-canvas" width="440" height="130" data-sign-canvas
                                aria-label="Draw your signature" style="display:none"></canvas>
                        <input type="hidden" name="approver_signature_data" data-sign-value>
                        <input type="hidden" name="use_profile_signature" value="0" data-sign-use-profile>
                        <div class="pv-sign-tools">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-sign-profile
                                    <?= $pvMySignatureUri === '' ? 'disabled' : '' ?>>
                                <i class="fas fa-id-card me-1"></i>Use profile signature
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-sign-draw>
                                <i class="fas fa-pen me-1"></i>Draw new
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-sign-clear>
                                <i class="fas fa-eraser me-1"></i>Clear
                            </button>
                            <input type="file" class="form-control form-control-sm" name="approver_signature_file"
                                   accept="image/png,image/jpeg" data-sign-file aria-label="Upload signature image">
                        </div>
                        <span class="pv-sign-hint" data-sign-hint>
                            <?= $pvMySignatureUri !== ''
                                ? 'Your profile signature is attached. Draw or upload to replace it for this voucher.'
                                : 'No signature on your profile yet (My Profile &gt; Certification). Draw or upload one.' ?>
                        </span>
                    </div>

                    <label class="form-label mt-3" for="approval_comments">Comments (required when returning)</label>
                    <textarea class="form-control" name="comments" id="approval_comments" rows="3"></textarea>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-danger" id="returnVoucherBtn" onclick="submitApproval('return')">
                    <i class="fas fa-undo me-1"></i>Return to preparer
                </button>
                <button type="button" class="btn btn-orange" id="approveVoucherBtn" onclick="submitApproval('approve')">
                    <i class="fas fa-check me-1"></i>Approve &amp; sign
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk upload modal -->
<div class="modal fade" id="bulkUploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-upload me-2"></i>Bulk Upload Vouchers</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div id="bulkAlert"></div>
                <p class="text-muted small">
                    Use the CSV template and upload one line item per row.
                    Rows with the same <code>row_group</code> are combined into one voucher.
                </p>

                <form id="bulkUploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="bulk_upload_csv">
                    <input type="file" class="form-control" name="csv_file"
                           id="csv_file" accept=".csv,.txt" required>
                </form>

                <div id="bulkResults" class="mt-3"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-orange" id="bulkUploadBtn" onclick="submitBulkUpload()">
                    <i class="fas fa-upload me-1"></i>Upload and Process
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
/* Bootstrap appends its backdrop to <body>. Keep the modals there too so the
   modal always stacks above its backdrop, whatever stacking contexts the app
   layout creates (a backdrop above the dialog blocked clicks on its fields,
   including the Voucher Series dropdown). */
(function () {
    const modalRoot = document.querySelector('.page-payment-voucher');
    if (modalRoot && modalRoot.parentElement !== document.body) {
        document.body.appendChild(modalRoot);
    }
})();

const nextVoucherNumbers = <?= json_encode($nextVoucherNumbers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const defaultSeries = <?= json_encode($defaultSeries) ?>;
const defaultVoucherNumber = <?= json_encode($autoVoucherNumber) ?>;
const todayValue = <?= json_encode(date('Y-m-d')) ?>;
const approvalsEnabled = <?= $pvApprovalReady ? 'true' : 'false' ?>;
const defaultApproverId = <?= json_encode($pvDefaultApprover > 0 ? (string)$pvDefaultApprover : '') ?>;
let editingOriginal = null; // {series, number} of the voucher being edited

/* Signature boxes: profile signature by default, or draw / upload a new one. */
function initSignBox(box) {
    const canvas = box.querySelector('[data-sign-canvas]');
    const preview = box.querySelector('[data-sign-preview]');
    const value = box.querySelector('[data-sign-value]');
    const useProfileInput = box.querySelector('[data-sign-use-profile]');
    const file = box.querySelector('[data-sign-file]');
    const hint = box.querySelector('[data-sign-hint]');
    const profile = box.dataset.profile || '';
    const context = canvas.getContext('2d');
    let drawing = false;
    let drawn = false;

    context.lineWidth = 2.5;
    context.lineCap = 'round';
    context.strokeStyle = '#111827';

    function point(event) {
        const rect = canvas.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left) * canvas.width / rect.width,
            y: (event.clientY - rect.top) * canvas.height / rect.height
        };
    }

    canvas.addEventListener('pointerdown', function (event) {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        const p = point(event);
        context.beginPath();
        context.moveTo(p.x, p.y);
    });

    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) return;
        const p = point(event);
        context.lineTo(p.x, p.y);
        context.stroke();
        drawn = true;
    });

    function endStroke() {
        if (!drawing) return;
        drawing = false;
        if (drawn) {
            value.value = canvas.toDataURL('image/png');
            useProfileInput.value = '0';
            box.dataset.existing = '0';
        }
    }

    canvas.addEventListener('pointerup', endStroke);
    canvas.addEventListener('pointercancel', endStroke);

    function showImage(src, text) {
        preview.src = src || '';
        preview.style.display = src ? '' : 'none';
        canvas.style.display = 'none';
        hint.textContent = text;
    }

    function startDrawing() {
        context.clearRect(0, 0, canvas.width, canvas.height);
        drawn = false;
        value.value = '';
        useProfileInput.value = '0';
        box.dataset.existing = '0';
        file.value = '';
        preview.style.display = 'none';
        canvas.style.display = 'block';
        hint.textContent = 'Draw your signature in the box, or upload a PNG/JPEG image (max 2 MB).';
    }

    const api = {
        useProfile: function () {
            if (!profile) {
                startDrawing();
                hint.textContent = 'No signature on your profile yet (My Profile > Certification). Draw or upload one.';
                return;
            }
            value.value = profile;
            useProfileInput.value = '1';
            box.dataset.existing = '0';
            file.value = '';
            showImage(profile, 'Your profile signature is attached. Draw or upload to replace it for this voucher.');
        },
        showExisting: function (src) {
            value.value = '';
            useProfileInput.value = '0';
            box.dataset.existing = '1';
            file.value = '';
            showImage(src, 'The signature already on this voucher is kept. Use your profile signature, draw or upload to replace it.');
        },
        reset: function () {
            api.useProfile();
        },
        hasSignature: function () {
            return value.value !== '' || file.files.length > 0 || box.dataset.existing === '1';
        }
    };

    box.querySelector('[data-sign-profile]').addEventListener('click', api.useProfile);
    box.querySelector('[data-sign-draw]').addEventListener('click', startDrawing);
    box.querySelector('[data-sign-clear]').addEventListener('click', startDrawing);
    file.addEventListener('change', function () {
        if (!file.files.length) return;
        value.value = '';
        useProfileInput.value = '0';
        box.dataset.existing = '0';
        showImage(URL.createObjectURL(file.files[0]), 'The uploaded image will be used as your signature.');
    });

    box.signApi = api;
    api.reset();
    return api;
}

document.querySelectorAll('[data-sign-box]').forEach(initSignBox);

function signApiFor(form) {
    const box = form.querySelector('[data-sign-box]');
    return box ? box.signApi : null;
}

function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[character];
    });
}

function changePerPage(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

/* Selection and PDF export */
const selectAll = document.getElementById('selectAllVouchers');
const voucherChecks = Array.from(document.querySelectorAll('.voucher-check'));
const selectionBar = document.getElementById('selectionBar');
const selectedCount = document.getElementById('selectedCount');
const exportSelectedBtn = document.getElementById('exportSelectedBtn');

function updateVoucherSelection() {
    const checked = voucherChecks.filter(function (checkbox) {
        return checkbox.checked;
    });

    voucherChecks.forEach(function (checkbox) {
        const row = document.getElementById('voucherRow' + checkbox.value);
        if (row) {
            row.classList.toggle('row-selected', checkbox.checked);
        }
    });

    selectedCount.textContent = String(checked.length);
    selectionBar.classList.toggle('active', checked.length > 0);
    exportSelectedBtn.disabled = checked.length === 0;

    if (selectAll) {
        selectAll.checked = voucherChecks.length > 0 && checked.length === voucherChecks.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < voucherChecks.length;
    }
}

if (selectAll) {
    selectAll.addEventListener('change', function () {
        voucherChecks.forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
        updateVoucherSelection();
    });
}

voucherChecks.forEach(function (checkbox) {
    checkbox.addEventListener('change', updateVoucherSelection);
});

function clearVoucherSelection() {
    voucherChecks.forEach(function (checkbox) {
        checkbox.checked = false;
    });
    updateVoucherSelection();
}

document.getElementById('selectedExportForm').addEventListener('submit', function (event) {
    if (!voucherChecks.some(function (checkbox) { return checkbox.checked; })) {
        event.preventDefault();
        alert('Select at least one voucher to export.');
    }
});


/* Shared voucher logo */
function setVoucherLogoAlert(message, success) {
    const alertBox = document.getElementById('voucherLogoAlert');

    alertBox.innerHTML = message
        ? '<div class="alert ' + (success ? 'alert-success' : 'alert-danger') + '">' +
          escapeHtml(message) +
          '</div>'
        : '';
}

function openVoucherLogoModal() {
    setVoucherLogoAlert('');
    document.getElementById('voucherLogoForm').reset();
}

document.getElementById('voucher_logo').addEventListener('change', function () {
    const file = this.files && this.files[0];

    if (!file) {
        return;
    }

    if (file.size > 2 * 1024 * 1024) {
        setVoucherLogoAlert('Voucher logo must not exceed 2 MB.', false);
        this.value = '';
        return;
    }

    const allowed = ['image/png', 'image/jpeg', 'image/webp'];

    if (!allowed.includes(file.type)) {
        setVoucherLogoAlert('Voucher logo must be PNG, JPG, or WEBP.', false);
        this.value = '';
        return;
    }

    const reader = new FileReader();

    reader.onload = function (event) {
        const preview = document.getElementById('voucherLogoPreview');
        const empty = document.getElementById('voucherLogoEmpty');

        preview.src = event.target.result;
        preview.style.display = 'block';
        empty.style.display = 'none';
    };

    reader.readAsDataURL(file);
});

function saveVoucherLogo() {
    const form = document.getElementById('voucherLogoForm');

    if (!form.reportValidity()) {
        return;
    }

    const button = document.getElementById('saveVoucherLogoBtn');
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';

    fetch('includes/process_voucher.php', {
        method: 'POST',
        body: new FormData(form)
    })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Unable to upload voucher logo.');
            }

            setVoucherLogoAlert(data.message || 'Voucher logo uploaded successfully.', true);
            document.getElementById('removeVoucherLogoBtn').disabled = false;

            setTimeout(function () {
                window.location.reload();
            }, 650);
        })
        .catch(function (error) {
            setVoucherLogoAlert(error.message || 'Unable to upload voucher logo.', false);
        })
        .finally(function () {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-upload me-1"></i>Upload Logo';
        });
}

function removeVoucherLogo() {
    if (!confirm('Remove the voucher logo from all vouchers?')) {
        return;
    }

    const button = document.getElementById('removeVoucherLogoBtn');
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Removing...';

    const formData = new FormData();
    formData.append('action', 'remove_voucher_logo');

    fetch('includes/process_voucher.php', {
        method: 'POST',
        body: formData
    })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Unable to remove voucher logo.');
            }

            setVoucherLogoAlert(data.message || 'Voucher logo removed successfully.', true);

            setTimeout(function () {
                window.location.reload();
            }, 650);
        })
        .catch(function (error) {
            setVoucherLogoAlert(error.message || 'Unable to remove voucher logo.', false);
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-trash me-1"></i>Remove Logo';
        });
}

/* Voucher form */
let itemIndex = 0;

function numericValue(value) {
    const number = parseFloat(String(value || '').replace(/,/g, ''));
    return Number.isFinite(number) ? number : 0;
}

function addItemRow(item) {
    item = item || {};
    const index = itemIndex++;
    const row = document.createElement('tr');

    row.dataset.index = String(index);
    row.innerHTML =
        '<td>' + (index + 1) + '</td>' +
        '<td><input class="it-input" name="items[' + index + '][item]" value="' + escapeHtml(item.item || '') + '" required></td>' +
        '<td><input class="it-input" type="number" min="0" step="any" name="items[' + index + '][qty]" value="' + escapeHtml(item.qty || '') + '" oninput="recalculateTotals()" required></td>' +
        '<td><input class="it-input" type="number" min="0" step="any" name="items[' + index + '][unit_price]" value="' + escapeHtml(item.unit_price || '') + '" oninput="recalculateTotals()" required></td>' +
        '<td><input class="it-input item-total" value="" readonly></td>' +
        '<td><input class="it-input" name="items[' + index + '][budget_line]" value="' + escapeHtml(item.budget_line || '') + '"></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove();recalculateTotals()"><i class="fas fa-trash"></i></button></td>';

    document.getElementById('itemsBody').appendChild(row);
    recalculateTotals();
}

function clearItems() {
    itemIndex = 0;
    document.getElementById('itemsBody').innerHTML = '';
}

function recalculateTotals() {
    let total = 0;

    document.querySelectorAll('#itemsBody tr').forEach(function (row) {
        const index = row.dataset.index;
        const quantity = numericValue(row.querySelector('[name="items[' + index + '][qty]"]').value);
        const unitPrice = numericValue(row.querySelector('[name="items[' + index + '][unit_price]"]').value);
        const lineTotal = quantity * unitPrice;

        row.querySelector('.item-total').value = lineTotal.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        total += lineTotal;
    });

    const currency = document.getElementById('currency').value || 'UGX';

    document.getElementById('totalDisplay').textContent =
        currency + ' ' + total.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
}

document.getElementById('currency').addEventListener('change', recalculateTotals);

/* Changing the series gives the next number in that series. When editing,
   switching back to the voucher's own series restores its original number. */
function onSeriesChange(series) {
    const numberInput = document.getElementById('voucher_no');

    if (editingOriginal && series === editingOriginal.series) {
        numberInput.value = editingOriginal.number;
        return;
    }

    numberInput.value = nextVoucherNumbers[series] || '';
}

function setApprovalNote(html) {
    const note = document.getElementById('pvApprovalNote');
    if (!note) return;
    note.innerHTML = html || '';
    note.style.display = html ? '' : 'none';
}

function resetApprovalFields() {
    if (!approvalsEnabled) return;
    const form = document.getElementById('voucherForm');
    const sign = signApiFor(form);
    if (sign) sign.reset();
    document.getElementById('approver_id').value = defaultApproverId;
    document.getElementById('submit_for_approval').checked = true;
    setApprovalNote('');
}

function openCreateModal() {
    document.getElementById('voucherModalTitle').innerHTML =
        '<i class="fas fa-plus-circle me-2"></i>New Voucher';

    editingOriginal = null;
    document.getElementById('voucherForm').reset();
    document.getElementById('pv_id').value = '';
    document.getElementById('voucher_series').disabled = false;
    document.getElementById('voucher_series').value = defaultSeries;
    document.getElementById('voucher_no').value = defaultVoucherNumber;
    document.getElementById('voucher_date').value = todayValue;
    document.getElementById('currency').value = 'UGX';
    document.getElementById('modalAlert').innerHTML = '';
    resetApprovalFields();

    clearItems();
    addItemRow();
    addItemRow();
}

function editVoucher(id) {
    document.getElementById('voucherModalTitle').innerHTML =
        '<i class="fas fa-edit me-2"></i>Edit Voucher';

    document.getElementById('modalAlert').innerHTML = '';
    clearItems();

    fetch('includes/get_voucher.php?id=' + encodeURIComponent(id))
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Unable to load voucher.');
            }

            const voucher = data.voucher || {};

            document.getElementById('pv_id').value = voucher.id || '';
            document.getElementById('voucher_no').value = voucher.voucher_no || '';
            document.getElementById('voucher_date').value = voucher.voucher_date || '';
            document.getElementById('supplier').value = voucher.supplier || '';
            document.getElementById('currency').value = voucher.currency || 'UGX';
            document.getElementById('exchange_rate').value = voucher.exchange_rate || '';
            document.getElementById('total_usd').value = voucher.total_usd || '';
            document.getElementById('account_code').value = voucher.account_code || '';
            document.getElementById('project_code').value = voucher.project_code || '';
            document.getElementById('jnl_ref').value = voucher.jnl_ref || '';
            document.getElementById('cheque_ref').value = voucher.cheque_ref || '';

            const seriesSelect = document.getElementById('voucher_series');
            seriesSelect.disabled = false;

            // Try longer series names first so one that starts with another still matches correctly.
            const matchedSeries = Object.keys(nextVoucherNumbers)
                .sort(function (a, b) { return b.length - a.length; })
                .find(function (series) {
                    return String(voucher.voucher_no || '').indexOf(series + '-') === 0;
                }) || '';

            if (matchedSeries) {
                seriesSelect.value = matchedSeries;
            } else {
                seriesSelect.selectedIndex = -1;
            }

            editingOriginal = { series: matchedSeries, number: voucher.voucher_no || '' };

            if (approvalsEnabled) {
                const sign = signApiFor(document.getElementById('voucherForm'));
                const status = voucher.approval_status || 'Draft';
                const submitBox = document.getElementById('submit_for_approval');

                if (sign) {
                    if (voucher.prepared_signature_uri) {
                        sign.showExisting(voucher.prepared_signature_uri);
                    } else {
                        sign.reset();
                    }
                }

                document.getElementById('approver_id').value =
                    voucher.approver_id ? String(voucher.approver_id) : defaultApproverId;

                if (status === 'Pending Approval') {
                    submitBox.checked = false;
                    setApprovalNote('Waiting for <strong>' + escapeHtml(voucher.approver_name || 'the approver') +
                        '</strong> to approve. Tick "Send to the approver now" to notify them again or change the approver.');
                } else if (status === 'Approved') {
                    submitBox.checked = false;
                    setApprovalNote('Approved by <strong>' + escapeHtml(voucher.approver_name || '') +
                        '</strong>. Saving changes removes that approval; tick "Send to the approver now" to request it again.');
                } else if (status === 'Returned') {
                    submitBox.checked = true;
                    setApprovalNote('Returned by <strong>' + escapeHtml(voucher.approver_name || 'the approver') + '</strong>' +
                        (voucher.approval_comments ? ': ' + escapeHtml(voucher.approval_comments) : '.'));
                } else {
                    submitBox.checked = true;
                    setApprovalNote('');
                }
            }

            const items = Array.isArray(voucher.items) ? voucher.items : [];

            if (items.length) {
                items.forEach(addItemRow);
            } else {
                addItemRow();
            }

            recalculateTotals();
        })
        .catch(function (error) {
            document.getElementById('modalAlert').innerHTML =
                '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';
        });
}

document.getElementById('voucherModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('voucher_series').disabled = false;
    editingOriginal = null;
});

function submitVoucher() {
    const form = document.getElementById('voucherForm');

    if (!form.reportValidity()) {
        return;
    }

    if (!document.querySelector('#itemsBody tr')) {
        alert('Add at least one line item.');
        return;
    }

    if (approvalsEnabled && document.getElementById('submit_for_approval').checked) {
        const sign = signApiFor(form);

        if (!document.getElementById('approver_id').value) {
            document.getElementById('modalAlert').innerHTML =
                '<div class="alert alert-danger">Choose the Senior Finance / HOD who should approve this voucher, or untick "Send to the approver now".</div>';
            return;
        }

        if (sign && !sign.hasSignature()) {
            document.getElementById('modalAlert').innerHTML =
                '<div class="alert alert-danger">Sign the voucher (use your profile signature, draw or upload) before sending it for approval.</div>';
            return;
        }
    }

    const button = document.getElementById('saveVoucherBtn');
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';

    fetch('includes/process_voucher.php', {
        method: 'POST',
        body: new FormData(form)
    })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Unable to save voucher.');
            }

            document.getElementById('modalAlert').innerHTML =
                '<div class="alert alert-success">' + escapeHtml(data.message || 'Voucher saved.') + '</div>';
            setTimeout(function () { window.location.reload(); }, 900);
        })
        .catch(function (error) {
            document.getElementById('modalAlert').innerHTML =
                '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';

            button.disabled = false;
            button.innerHTML = '<i class="fas fa-save me-1"></i>Save Voucher';
        });
}

/* View */
function viewVoucher(id) {
    const box = document.getElementById('viewBox');
    box.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';

    fetch('includes/get_voucher.php?id=' + encodeURIComponent(id))
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Unable to load voucher.');
            }

            const voucher = data.voucher || {};
            const items = Array.isArray(voucher.items) ? voucher.items : [];

            const itemRows = items.map(function (item, index) {
                return '<tr>' +
                    '<td>' + (index + 1) + '</td>' +
                    '<td>' + escapeHtml(item.item || '-') + '</td>' +
                    '<td>' + escapeHtml(item.qty || '-') + '</td>' +
                    '<td class="text-end">' + escapeHtml(item.unit_price || '-') + '</td>' +
                    '<td class="text-end">' + escapeHtml(item.total_price || '-') + '</td>' +
                    '</tr>';
            }).join('');

            box.innerHTML =
                '<div class="detail-grid">' +
                    '<div><div class="dk">Voucher Number</div><div class="dv">' + escapeHtml(voucher.voucher_no || '-') + '</div></div>' +
                    '<div><div class="dk">Date</div><div class="dv">' + escapeHtml(voucher.voucher_date || '-') + '</div></div>' +
                    '<div><div class="dk">Supplier</div><div class="dv">' + escapeHtml(voucher.supplier || '-') + '</div></div>' +
                    '<div><div class="dk">Project</div><div class="dv">' + escapeHtml(voucher.project_code || '-') + '</div></div>' +
                    '<div><div class="dk">Currency</div><div class="dv">' + escapeHtml(voucher.currency || 'UGX') + '</div></div>' +
                    '<div><div class="dk">Total</div><div class="dv">' + escapeHtml(voucher.currency || 'UGX') + ' ' +
                        Number(voucher.total_amount || 0).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2}) +
                    '</div></div>' +
                '</div>' +
                '<div class="table-responsive"><table class="table table-sm">' +
                    '<thead><tr><th>#</th><th>Description</th><th>Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead>' +
                    '<tbody>' + (itemRows || '<tr><td colspan="5">No line items.</td></tr>') + '</tbody>' +
                '</table></div>' +
                approvalSummaryHtml(voucher);
        })
        .catch(function (error) {
            box.innerHTML = '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';
        });
}

function signatureFigure(label, uri, name, date) {
    return '<div><div class="dk">' + escapeHtml(label) + '</div>' +
        (uri ? '<img class="pv-signed-img mt-1" alt="' + escapeHtml(label) + '" src="' + escapeHtml(uri) + '">' : '<div class="dv">Not signed</div>') +
        (name ? '<div class="dv">' + escapeHtml(name) + (date ? ' &middot; ' + escapeHtml(date) : '') + '</div>' : '') +
        '</div>';
}

function approvalSummaryHtml(voucher) {
    if (!approvalsEnabled) return '';
    const status = voucher.approval_status || 'Draft';
    return '<div class="section-title">Signatures &amp; approval</div>' +
        '<p><span class="pv-status pv-status-' + escapeHtml(status.toLowerCase().replace(/ /g, '-')) + '">' + escapeHtml(status) + '</span>' +
        (voucher.approver_name ? ' <span class="small text-muted">Approver: ' + escapeHtml(voucher.approver_name) + '</span>' : '') + '</p>' +
        (voucher.approval_comments ? '<p class="small"><strong>Comment:</strong> ' + escapeHtml(voucher.approval_comments) + '</p>' : '') +
        '<div class="detail-grid">' +
            signatureFigure('Admin/Finance', voucher.prepared_signature_uri, voucher.prepared_by_name, voucher.prepared_signed_at) +
            signatureFigure('H.O.Dept / Senior Finance', status === 'Approved' ? voucher.approved_signature_uri : '',
                status === 'Approved' ? voucher.approver_name : '', status === 'Approved' ? voucher.approved_at : '') +
        '</div>';
}

/* Approval (Senior Finance / HOD) */
function openApproval(id) {
    const form = document.getElementById('approvalForm');
    const summary = document.getElementById('approvalSummary');

    form.reset();
    document.getElementById('approval_id').value = String(id);
    document.getElementById('approvalAlert').innerHTML = '';
    summary.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';

    const sign = signApiFor(form);
    if (sign) sign.reset();

    fetch('includes/get_voucher.php?id=' + encodeURIComponent(id))
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Unable to load voucher.');
            }

            const voucher = data.voucher || {};
            const items = Array.isArray(voucher.items) ? voucher.items : [];

            summary.innerHTML =
                '<div class="detail-grid">' +
                    '<div><div class="dk">Voucher Number</div><div class="dv">' + escapeHtml(voucher.voucher_no || '-') + '</div></div>' +
                    '<div><div class="dk">Date</div><div class="dv">' + escapeHtml(voucher.voucher_date || '-') + '</div></div>' +
                    '<div><div class="dk">Supplier</div><div class="dv">' + escapeHtml(voucher.supplier || '-') + '</div></div>' +
                    '<div><div class="dk">Total</div><div class="dv">' + escapeHtml(voucher.currency || 'UGX') + ' ' +
                        Number(voucher.total_amount || 0).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2}) + '</div></div>' +
                '</div>' +
                '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Description</th><th>Qty</th><th class="text-end">Total</th></tr></thead><tbody>' +
                (items.map(function (item) {
                    return '<tr><td>' + escapeHtml(item.item || '-') + '</td><td>' + escapeHtml(item.qty || '-') +
                        '</td><td class="text-end">' + escapeHtml(item.total_price || '-') + '</td></tr>';
                }).join('') || '<tr><td colspan="3">No line items.</td></tr>') +
                '</tbody></table></div>' +
                approvalSummaryHtml(voucher);

            const canApprove = !!voucher.can_approve;
            document.getElementById('approveVoucherBtn').disabled = !canApprove;
            document.getElementById('returnVoucherBtn').disabled = !canApprove;

            if (!canApprove) {
                document.getElementById('approvalAlert').innerHTML =
                    '<div class="alert alert-warning">This voucher is not waiting for your approval.</div>';
            }
        })
        .catch(function (error) {
            summary.innerHTML = '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';
        });
}

function submitApproval(decision) {
    const form = document.getElementById('approvalForm');
    const alertBox = document.getElementById('approvalAlert');
    const sign = signApiFor(form);

    if (decision === 'approve' && sign && !sign.hasSignature()) {
        alertBox.innerHTML = '<div class="alert alert-danger">Sign (use your profile signature, draw or upload) to approve.</div>';
        return;
    }

    if (decision === 'return' && document.getElementById('approval_comments').value.trim() === '') {
        alertBox.innerHTML = '<div class="alert alert-danger">Add a comment explaining what needs to change.</div>';
        return;
    }

    document.getElementById('approval_decision').value = decision;

    const button = document.getElementById(decision === 'approve' ? 'approveVoucherBtn' : 'returnVoucherBtn');
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';

    fetch('includes/process_voucher.php', { method: 'POST', body: new FormData(form) })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Unable to save the decision.');
            }
            alertBox.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message || 'Saved.') + '</div>';
            setTimeout(function () { window.location.reload(); }, 700);
        })
        .catch(function (error) {
            alertBox.innerHTML = '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';
            button.disabled = false;
            button.innerHTML = original;
        });
}

/* Bulk upload */
function openBulkUploadModal() {
    document.getElementById('bulkUploadForm').reset();
    document.getElementById('bulkAlert').innerHTML = '';
    document.getElementById('bulkResults').innerHTML = '';
}

function submitBulkUpload() {
    const form = document.getElementById('bulkUploadForm');

    if (!form.reportValidity()) {
        return;
    }

    const button = document.getElementById('bulkUploadBtn');
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';

    fetch('includes/process_voucher.php', {
        method: 'POST',
        body: new FormData(form)
    })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            const alertClass = data.success ? 'alert-success' : 'alert-danger';

            document.getElementById('bulkAlert').innerHTML =
                '<div class="alert ' + alertClass + '">' + escapeHtml(data.message || '') + '</div>';

            const details = Array.isArray(data.details) ? data.details : [];

            if (details.length) {
                document.getElementById('bulkResults').innerHTML =
                    '<div class="table-responsive"><table class="table table-sm">' +
                    '<thead><tr><th>Row</th><th>Voucher</th><th>Status</th><th>Message</th></tr></thead><tbody>' +
                    details.map(function (detail) {
                        return '<tr>' +
                            '<td>' + escapeHtml(detail.row) + '</td>' +
                            '<td>' + escapeHtml(detail.voucher_no) + '</td>' +
                            '<td>' + escapeHtml(detail.status) + '</td>' +
                            '<td>' + escapeHtml(detail.message) + '</td>' +
                            '</tr>';
                    }).join('') +
                    '</tbody></table></div>';
            }

            if (data.success && Number(data.created || 0) > 0) {
                setTimeout(function () {
                    window.location.reload();
                }, 1200);
            }
        })
        .catch(function (error) {
            document.getElementById('bulkAlert').innerHTML =
                '<div class="alert alert-danger">' + escapeHtml(error.message || 'Network error.') + '</div>';
        })
        .finally(function () {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-upload me-1"></i>Upload and Process';
        });
}

updateVoucherSelection();
</script>

</div><!-- /.page-payment-voucher -->

<?php
if (is_file(__DIR__ . '/includes/footer.php')) {
    require_once __DIR__ . '/includes/footer.php';
} elseif (is_file(__DIR__ . '/footer.php')) {
    require_once __DIR__ . '/footer.php';
}

if (ob_get_level() > 0) {
    ob_end_flush();
}
