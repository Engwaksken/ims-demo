<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (empty($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

if (!ims_allowed('vouchers.view', ['Administrator', 'Finance', 'Accountant'], 'View, print and download payment vouchers')) {
    http_response_code(403);
    exit('Access denied.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

$conn->set_charset('utf8mb4');

function dvEsc(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function dvMoney(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return number_format((float)$value, 2);
}

function dvFindLogoPath(mysqli $conn): string
{
    $filename = '';

    $stmt = $conn->prepare(
        "SELECT voucher_logo
         FROM voucher_settings
         WHERE id = 1
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $filename = basename(
            trim((string)($row['voucher_logo'] ?? ''))
        );
    }

    $paths = [];

    if ($filename !== '') {
        $paths = [
            __DIR__ . '/uploads/voucher_branding/' . $filename,
            dirname(__DIR__) . '/uploads/voucher_branding/' . $filename,
            __DIR__ . '/uploads/voucher/' . $filename,
            __DIR__ . '/uploads/logos/' . $filename,
        ];
    }

    $paths = array_merge(
        $paths,
        [
            __DIR__ . '/images/logo.png',
            __DIR__ . '/assets/img/logo.png',
            dirname(__DIR__) . '/assets/img/logo.png',
        ]
    );

    foreach ($paths as $path) {
        if (is_file($path) && is_readable($path)) {
            return realpath($path) ?: $path;
        }
    }

    return '';
}

function dvLogoDataUri(mysqli $conn): string
{
    $sourcePath = dvFindLogoPath($conn);

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
        return dvOriginalImageDataUri($sourcePath);
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
        is_file($cachedPath) &&
        is_readable($cachedPath)
    ) {
        $contents = file_get_contents($cachedPath);

        return $contents === false
            ? ''
            : 'data:image/jpeg;base64,' .
                base64_encode($contents);
    }

    /*
    |--------------------------------------------------------------------------
    | Create a small cached logo for Dompdf
    |--------------------------------------------------------------------------
    | A large original logo can make every PDF download slow. The cached
    | version is generated only once and reused for later downloads.
    |--------------------------------------------------------------------------
    */
    if (
        function_exists('imagecreatefromstring') &&
        function_exists('imagejpeg')
    ) {
        $sourceContents = file_get_contents($sourcePath);

        if ($sourceContents !== false) {
            $sourceImage = @imagecreatefromstring(
                $sourceContents
            );

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

                    @imagejpeg(
                        $canvas,
                        $cachedPath,
                        82
                    );

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

        return $contents === false
            ? ''
            : 'data:image/jpeg;base64,' .
                base64_encode($contents);
    }

    return dvOriginalImageDataUri($sourcePath);
}

function dvOriginalImageDataUri(string $path): string
{
    if (
        !is_file($path) ||
        !is_readable($path)
    ) {
        return '';
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file(
        $path
    );

    if (
        !is_string($mime) ||
        !in_array(
            $mime,
            [
                'image/png',
                'image/jpeg',
                'image/webp',
                'image/gif',
            ],
            true
        )
    ) {
        return '';
    }

    $contents = file_get_contents($path);

    return $contents === false
        ? ''
        : 'data:' .
            $mime .
            ';base64,' .
            base64_encode($contents);
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid voucher ID.');
}

/*
|--------------------------------------------------------------------------
| Fetch only the fields required by the PDF
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare(
    "SELECT
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
        items_json
     FROM payment_vouchers
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare voucher query.');
}

$stmt->bind_param('i', $id);
$stmt->execute();

$voucher = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$voucher) {
    http_response_code(404);
    exit('Voucher not found.');
}

$items = [];

if (!empty($voucher['items_json'])) {
    $decoded = json_decode(
        (string)$voucher['items_json'],
        true
    );

    if (is_array($decoded)) {
        $items = array_values($decoded);
    }
}

$voucherNo = trim(
    (string)($voucher['voucher_no'] ?? '')
);

$voucherDate = !empty($voucher['voucher_date'])
    ? date(
        'd / m / Y',
        strtotime((string)$voucher['voucher_date'])
    )
    : '____ / ____ / ______';

$supplier = trim(
    (string)($voucher['supplier'] ?? '')
);

$currency = strtoupper(
    trim((string)($voucher['currency'] ?? 'UGX'))
);

$totalAmount = (float)(
    $voucher['total_amount'] ?? 0
);

$exchangeRate = $voucher['exchange_rate'] ?? '';
$totalUsd = $voucher['total_usd'] ?? '';
$accountCode = $voucher['account_code'] ?? '';
$projectCode = $voucher['project_code'] ?? '';
$jnlRef = $voucher['jnl_ref'] ?? '';
$chequeRef = $voucher['cheque_ref'] ?? '';

/*
|--------------------------------------------------------------------------
| Five visible rows
|--------------------------------------------------------------------------
*/
$rowCount = max(5, count($items));
$rowsHtml = '';

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
                dvEsc($item['item'] ?? '') .
                '</td>' .
            '<td class="center">' .
                dvEsc($quantity) .
                '</td>' .
            '<td class="right">' .
                dvMoney($unitPrice) .
                '</td>' .
            '<td class="right">' .
                dvMoney($lineTotal) .
                '</td>' .
            '<td class="center">' .
                dvEsc($item['budget_line'] ?? '') .
                '</td>' .
        '</tr>';
}

$logoDataUri = dvLogoDataUri($conn);

$logoHtml = $logoDataUri !== ''
    ? '<img src="' .
        dvEsc($logoDataUri) .
        '" alt="Voucher logo">'
    : '<span class="logo-text">' . dvEsc(ims_brand_name()) . '</span>';

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
<body>

<div class="logo">' .
    $logoHtml .
'</div>

<div class="title">
    PAYMENT VOUCHER
</div>

<div class="date">
    <strong>Date:</strong>
    <span class="date-value">' .
        dvEsc($voucherDate) .
    '</span>
</div>

<div class="voucher-number">
    Voucher No:&nbsp;&nbsp;' .
    dvEsc($voucherNo) .
'</div>

<div class="supplier">
    <strong>Supplier/Receiver\'s Name:</strong>
    <span class="supplier-value">' .
        dvEsc($supplier) .
    '</span>
</div>

<table class="items">
<thead>
<tr>
    <th class="number">#</th>
    <th class="description">Items</th>
    <th class="quantity">Qty</th>
    <th class="unit-price">Unit Price</th>
    <th class="total-price">Total Price</th>
    <th class="budget">Budget<br>Line</th>
</tr>
</thead>
<tbody>' .
    $rowsHtml .
'</tbody>
</table>

<table class="summary">
<tr>
    <td>
        <strong>Currency:</strong>
        <span class="value">' .
            dvEsc($currency) .
        '</span>
    </td>
    <td>
        <strong>Total Amount:</strong>
        <span class="value"><strong>' .
            dvEsc($currency) .
            ' ' .
            number_format($totalAmount, 2) .
        '</strong></span>
    </td>
</tr>
<tr>
    <td>
        <strong>Exchange rate of the day:</strong>
        <span class="value">' .
            dvEsc($exchangeRate) .
        '</span>
    </td>
    <td>
        <strong>In USD:</strong>
        <span class="value">' .
            dvEsc($totalUsd) .
        '</span>
    </td>
</tr>
</table>

<table class="codes">
<thead>
<tr>
    <th>Account Code:</th>
    <th>Project Code:</th>
    <th>JNL Ref / Dated:</th>
    <th>Cheque Ref.</th>
</tr>
</thead>
<tbody>
<tr>
    <td>' . dvEsc($accountCode) . '</td>
    <td>' . dvEsc($projectCode) . '</td>
    <td>' . dvEsc($jnlRef) . '</td>
    <td>' . dvEsc($chequeRef) . '</td>
</tr>
</tbody>
</table>

<table class="signatures">
<thead>
<tr>
    <th>Admin/Finance Signature:</th>
    <th>H.O.Dept. Signature:</th>
    <th>Received By:</th>
</tr>
</thead>
<tbody>
<tr>
    <td></td>
    <td></td>
    <td></td>
</tr>
</tbody>
</table>

</body>
</html>';

@ini_set('memory_limit', '256M');
@set_time_limit(60);

$options = new Options();
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

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$baseFilename = $voucherNo !== ''
    ? $voucherNo
    : 'voucher_' . $id;

$filename =
    preg_replace(
        '/[^A-Za-z0-9_\-]/',
        '_',
        $baseFilename
    ) .
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
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdf;
exit;
