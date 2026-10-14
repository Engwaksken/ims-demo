<?php
declare(strict_types=1);

// Standalone printable document (opened in a new tab from payment-voucher.php,
// has its own Print/Close toolbar), so it does not use the app layout chrome.
require_once __DIR__ . '/includes/config.php';
check_login();

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

function vpEsc(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function vpNumber(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return number_format((float)$value, 2);
}

function vpResolveLogo(mysqli $conn): string
{
    /*
    |--------------------------------------------------------------------------
    | Fetch the shared voucher logo filename
    |--------------------------------------------------------------------------
    */
    $filename = '';

    $stmt = $conn->prepare(
        "SELECT voucher_logo
         FROM voucher_settings
         WHERE id = 1
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->execute();

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $filename = basename(
                trim((string)($row['voucher_logo'] ?? ''))
            );
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve logo from the filesystem
    |--------------------------------------------------------------------------
    | process_voucher.php saves the shared logo under:
    |   /legacy/uploads/voucher_branding/
    |
    | The extra locations below support alternative installations.
    |--------------------------------------------------------------------------
    */
    $candidatePaths = [];

    if ($filename !== '') {
        $candidatePaths = [
            __DIR__ . '/uploads/voucher_branding/' . $filename,
            dirname(__DIR__) . '/uploads/voucher_branding/' . $filename,
            __DIR__ . '/uploads/voucher/' . $filename,
            __DIR__ . '/uploads/logos/' . $filename,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Default logo fallbacks
    |--------------------------------------------------------------------------
    */
    $candidatePaths = array_merge(
        $candidatePaths,
        [
            __DIR__ . '/images/logo.png',
            __DIR__ . '/assets/img/logo.png',
            __DIR__ . '/assets/images/logo.png',
            dirname(__DIR__) . '/assets/img/logo.png',
            dirname(__DIR__) . '/assets/images/logo.png',
        ]
    );

    $logoPath = '';

    foreach ($candidatePaths as $candidatePath) {
        if (
            is_file($candidatePath) &&
            is_readable($candidatePath)
        ) {
            $logoPath = $candidatePath;
            break;
        }
    }

    if ($logoPath === '') {
        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | Embed the logo directly
    |--------------------------------------------------------------------------
    | This avoids failures caused by URL rewriting, protected upload folders,
    | incorrect public paths, and .htaccess image restrictions.
    |--------------------------------------------------------------------------
    */
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($logoPath);

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

    $contents = file_get_contents($logoPath);

    if ($contents === false) {
        return '';
    }

    return 'data:' .
        $mime .
        ';base64,' .
        base64_encode($contents);
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid voucher ID.');
}

$stmt = $conn->prepare(
    "SELECT *
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

$logoUrl = vpResolveLogo($conn);

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
| Five fixed display rows
|--------------------------------------------------------------------------
| If the voucher contains more than five items, all actual items are still
| shown so no data is hidden. Otherwise blank rows are added up to five.
|--------------------------------------------------------------------------
*/
$rowCount = max(5, count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
    Payment Voucher - <?= vpEsc($voucherNo) ?>
</title>

<link rel="icon" type="image/png" href="../images/favicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">

<style>
@page {
    size: A4 portrait;
    margin: 8mm 10mm;
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
}

body {
    background: #eceff1;
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10pt;
}

.toolbar {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    background: #1f2937;
    box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
}

.toolbar-title {
    margin-left: auto;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
}

.toolbar-button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-height: 38px;
    padding: 8px 16px;
    border: 0;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.toolbar-print {
    background: #f47c20;
    color: #fff;
}

.toolbar-print:hover {
    background: #d9650d;
}

.toolbar-download {
    background: #374151;
    color: #fff;
}

.toolbar-download:hover {
    background: #111827;
}

.toolbar-close {
    background: #f3f4f6;
    color: #1f2937;
}

.toolbar-close:hover {
    background: #e5e7eb;
}

.page {
    width: 210mm;
    min-height: 297mm;
    margin: 16px auto;
    padding: 9mm 11mm;
    background: #fff;
    box-shadow: 0 4px 24px rgba(0, 0, 0, .16);
}

.logo-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 18mm;
    margin-bottom: 2mm;
    text-align: center;
}

.logo-wrap img {
    display: block;
    max-width: 44mm;
    max-height: 18mm;
    object-fit: contain;
}

.logo-fallback {
    color: #f47c20;
    font-size: 17pt;
    font-weight: 700;
}

.title-banner {
    margin-bottom: 1mm;
    padding: 5pt 0;
    border: 1px solid #e8864a;
    background: #f5c9a8;
    color: #111;
    text-align: center;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 16pt;
    font-weight: 700;
    letter-spacing: 1.5pt;
}

.date-row {
    margin: 0 14mm 3mm 0;
    text-align: right;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 10pt;
}

.date-label {
    font-weight: 700;
}

.date-value {
    display: inline-block;
    min-width: 38mm;
    margin-left: 4pt;
    padding-bottom: 1pt;
    border-bottom: 1px solid #111;
    text-align: center;
}

.voucher-number {
    margin-bottom: 4mm;
    padding: 5pt 0;
    border: 1px solid #111;
    text-align: center;
    font-size: 12pt;
    font-weight: 700;
}

.supplier-row {
    margin-bottom: 3mm;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 10pt;
}

.supplier-label {
    font-weight: 700;
}

.supplier-value {
    display: inline-block;
    min-width: 128mm;
    margin-left: 4pt;
    padding: 0 3pt 1pt;
    border-bottom: 1px dotted #111;
}

.items-table {
    width: 100%;
    margin-bottom: 4mm;
    border-collapse: collapse;
    table-layout: fixed;
}

.items-table th,
.items-table td {
    border: 1px solid #111;
}

.items-table th {
    height: 8mm;
    padding: 2pt 4pt;
    background: #fff;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 9pt;
    font-weight: 700;
    text-align: center;
    vertical-align: middle;
}

.items-table td {
    height: 8mm;
    padding: 2pt 4pt;
    font-size: 8.8pt;
    vertical-align: middle;
}

.item-number {
    width: 7%;
    text-align: center;
}

.item-description {
    width: 40%;
}

.item-quantity {
    width: 9%;
    text-align: center;
}

.item-unit-price,
.item-total-price {
    width: 16%;
    text-align: right;
}

.item-budget {
    width: 12%;
    text-align: center;
}

.items-table tbody tr:nth-child(even) td {
    background: #fcfcfc;
}

.summary-table,
.codes-table,
.signature-table {
    width: 100%;
    border-collapse: collapse;
}

.summary-table {
    margin-bottom: 4mm;
}

.summary-table td {
    width: 50%;
    height: 10mm;
    padding: 3pt 6pt;
    border: 1px solid #111;
    font-size: 9pt;
}

.summary-label {
    font-weight: 700;
}

.summary-value {
    display: inline-block;
    min-width: 44mm;
    margin-left: 3pt;
    padding-bottom: 1pt;
    border-bottom: 1px dotted #111;
}

.summary-total {
    font-size: 10pt;
    font-weight: 700;
}

.codes-table {
    margin-bottom: 4mm;
}

.codes-table th,
.codes-table td {
    width: 25%;
    border: 1px solid #111;
    text-align: left;
}

.codes-table th {
    height: 5mm;
    padding: 3pt 5pt;
    font-size: 8.5pt;
    font-weight: 700;
}

.codes-table td {
    height: 9mm;
    padding: 4pt 5pt;
    font-size: 8.5pt;
    vertical-align: top;
}

.signature-table th,
.signature-table td {
    width: 33.333%;
    border: 1px solid #111;
    text-align: center;
}

.signature-table th {
    height: 6mm;
    padding: 3pt 5pt;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 9pt;
    font-weight: 400;
}

.signature-table td {
    height: 14mm;
}

@media print {
    body {
        background: #fff;
    }

    .toolbar {
        display: none !important;
    }

    .page {
        width: 100%;
        min-height: 0;
        margin: 0;
        padding: 0;
        box-shadow: none;
    }
}

@media screen and (max-width: 900px) {
    .page {
        width: calc(100% - 20px);
        min-height: auto;
        margin: 10px;
        padding: 14px;
    }

    .toolbar {
        flex-wrap: wrap;
    }

    .toolbar-title {
        width: 100%;
        margin-left: 0;
    }
}
</style>
</head>

<body>

<div class="toolbar">
    <button
        type="button"
        class="toolbar-button toolbar-print"
        onclick="window.print()"
    >
        <i class="fas fa-print"></i>
        Print Voucher
    </button>

    <a
        href="download_voucher?id=<?= $id ?>"
        class="toolbar-button toolbar-download"
    >
        <i class="fas fa-file-pdf"></i>
        Download PDF
    </a>

    <button
        type="button"
        class="toolbar-button toolbar-close"
        onclick="window.close()"
    >
        <i class="fas fa-times"></i>
        Close
    </button>

    <span class="toolbar-title">
        Payment Voucher - <?= vpEsc($voucherNo) ?>
    </span>
</div>

<main class="page">

    <div class="logo-wrap">
        <?php if ($logoUrl !== ''): ?>
            <img
                src="<?= vpEsc($logoUrl) ?>"
                alt="Voucher logo"
            >
        <?php else: ?>
            <span class="logo-fallback">
                Hive Colab
            </span>
        <?php endif; ?>
    </div>

    <div class="title-banner">
        PAYMENT VOUCHER
    </div>

    <div class="date-row">
        <span class="date-label">Date:</span>
        <span class="date-value">
            <?= vpEsc($voucherDate) ?>
        </span>
    </div>

    <div class="voucher-number">
        Voucher No:&nbsp;&nbsp;<?= vpEsc($voucherNo) ?>
    </div>

    <div class="supplier-row">
        <span class="supplier-label">
            Supplier/Receiver's Name:
        </span>

        <span class="supplier-value">
            <?= vpEsc($supplier) ?>
        </span>
    </div>

    <table class="items-table">
        <thead>
        <tr>
            <th class="item-number">#</th>
            <th class="item-description">Items</th>
            <th class="item-quantity">Qty</th>
            <th class="item-unit-price">Unit Price</th>
            <th class="item-total-price">Total Price</th>
            <th class="item-budget">
                Budget<br>Line
            </th>
        </tr>
        </thead>

        <tbody>
        <?php for ($index = 0; $index < $rowCount; $index++): ?>
            <?php
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
            ?>

            <tr>
                <td class="item-number">
                    <?= $index + 1 ?>.
                </td>

                <td class="item-description">
                    <?= vpEsc($item['item'] ?? '') ?>
                </td>

                <td class="item-quantity">
                    <?= vpEsc($quantity) ?>
                </td>

                <td class="item-unit-price">
                    <?= vpNumber($unitPrice) ?>
                </td>

                <td class="item-total-price">
                    <?= vpNumber($lineTotal) ?>
                </td>

                <td class="item-budget">
                    <?= vpEsc($item['budget_line'] ?? '') ?>
                </td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <table class="summary-table">
        <tbody>
        <tr>
            <td>
                <span class="summary-label">Currency:</span>

                <span class="summary-value">
                    <?= vpEsc($currency) ?>
                </span>
            </td>

            <td>
                <span class="summary-label">Total Amount:</span>

                <span class="summary-value summary-total">
                    <?= vpEsc($currency) ?>
                    <?= number_format($totalAmount, 2) ?>
                </span>
            </td>
        </tr>

        <tr>
            <td>
                <span class="summary-label">
                    Exchange rate of the day:
                </span>

                <span class="summary-value">
                    <?= vpEsc($exchangeRate) ?>
                </span>
            </td>

            <td>
                <span class="summary-label">In USD:</span>

                <span class="summary-value">
                    <?= vpEsc($totalUsd) ?>
                </span>
            </td>
        </tr>
        </tbody>
    </table>

    <table class="codes-table">
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
            <td><?= vpEsc($accountCode) ?></td>
            <td><?= vpEsc($projectCode) ?></td>
            <td><?= vpEsc($jnlRef) ?></td>
            <td><?= vpEsc($chequeRef) ?></td>
        </tr>
        </tbody>
    </table>

    <table class="signature-table">
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

</main>

</body>
</html>
