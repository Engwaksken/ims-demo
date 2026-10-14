<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

const PV_ALLOWED_ROLES = [
    'Administrator',
    'Finance',
    'Accountant',
    'Meal Lead',
];

const PV_ALLOWED_CURRENCIES = [
    'UGX',
    'USD',
    'EUR',
    'KES',
    'GBP',
    'TZS',
];

const PV_ALLOWED_SERIES = [
    'UGX1',
    'UGX2',
    'Euro Asknet',
    'Euro Fempeace',
    'UGX Fempeace',
];

const PV_MAX_CSV_SIZE = 5242880; // 5 MB
const PV_MAX_CSV_ROWS = 2000;

/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/
function pvJson(bool $success, string $message, array $extra = []): never
{
    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Prepared statement helper
|--------------------------------------------------------------------------
*/
function pvRequireStatement(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Database prepare failed: ' . $conn->error
        );
    }

    return $stmt;
}

/*
|--------------------------------------------------------------------------
| Generic string helpers
|--------------------------------------------------------------------------
*/
function pvNullable(mixed $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function pvCleanCsvValue(mixed $value): string
{
    $value = (string)$value;

    // Remove UTF-8 BOM if Excel placed it at the beginning of a value.
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

    // Remove invisible Unicode formatting characters sometimes introduced by Excel.
    $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value) ?? $value;

    return trim($value);
}

/*
|--------------------------------------------------------------------------
| Numeric validation
|--------------------------------------------------------------------------
*/
function pvNumber(
    mixed $value,
    string $field,
    bool $nullable = false
): ?float {
    $raw = pvCleanCsvValue($value);
    $raw = str_replace(
        [',', ' ', "\xc2\xa0"],
        '',
        $raw
    );

    if ($raw === '') {
        return $nullable ? null : 0.0;
    }

    if (!is_numeric($raw)) {
        throw new InvalidArgumentException(
            $field . ' must be numeric.'
        );
    }

    $number = (float)$raw;

    if (!is_finite($number)) {
        throw new InvalidArgumentException(
            $field . ' contains an invalid number.'
        );
    }

    if ($number < 0) {
        throw new InvalidArgumentException(
            $field . ' cannot be negative.'
        );
    }

    return $number;
}

/*
|--------------------------------------------------------------------------
| Date validation
|--------------------------------------------------------------------------
*/
function pvDate(mixed $value): string
{
    $raw = pvCleanCsvValue($value);

    if ($raw === '') {
        throw new InvalidArgumentException(
            'Voucher date is required.'
        );
    }

    $formats = [
        '!Y-m-d',
        '!d/m/Y',
        '!m/d/Y',
        '!d-m-Y',
        '!m-d-Y',
        '!Y/m/d',
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);
        $errors = DateTimeImmutable::getLastErrors();

        $valid = $date !== false &&
            (
                $errors === false ||
                (
                    (int)$errors['warning_count'] === 0 &&
                    (int)$errors['error_count'] === 0
                )
            );

        if ($valid) {
            return $date->format('Y-m-d');
        }
    }

    // Support Excel serial dates.
    if (is_numeric($raw)) {
        $serial = (float)$raw;

        if ($serial > 0 && $serial < 100000) {
            $days = (int)floor($serial);
            $date = (new DateTimeImmutable('1899-12-30'))
                ->modify('+' . $days . ' days');

            return $date->format('Y-m-d');
        }
    }

    throw new InvalidArgumentException(
        'Voucher date must use YYYY-MM-DD format, for example 2026-07-26.'
    );
}

/*
|--------------------------------------------------------------------------
| Line-item validation
|--------------------------------------------------------------------------
*/
function pvCleanItems(array $items): array
{
    $clean = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $description = pvCleanCsvValue($item['item'] ?? '');
        $qtyRaw = pvCleanCsvValue($item['qty'] ?? '');
        $priceRaw = pvCleanCsvValue($item['unit_price'] ?? '');
        $budgetLine = pvCleanCsvValue($item['budget_line'] ?? '');

        if (
            $description === '' &&
            $qtyRaw === '' &&
            $priceRaw === '' &&
            $budgetLine === ''
        ) {
            continue;
        }

        if ($description === '') {
            throw new InvalidArgumentException(
                'Every line item requires a description.'
            );
        }

        $qty = pvNumber($qtyRaw, 'Item quantity');
        $unitPrice = pvNumber($priceRaw, 'Item unit price');

        if ($qty === null || $qty <= 0) {
            throw new InvalidArgumentException(
                'Item quantity must be greater than zero.'
            );
        }

        if ($unitPrice === null) {
            $unitPrice = 0.0;
        }

        $clean[] = [
            'item' => $description,
            'qty' => round($qty, 4),
            'unit_price' => round($unitPrice, 2),
            'total_price' => round($qty * $unitPrice, 2),
            'budget_line' => $budgetLine,
        ];
    }

    if ($clean === []) {
        throw new InvalidArgumentException(
            'Add at least one valid line item.'
        );
    }

    return $clean;
}

/*
|--------------------------------------------------------------------------
| Legacy per-voucher logo upload compatibility
|--------------------------------------------------------------------------
*/
function pvSaveLogo(int $voucherId): ?string
{
    if (
        empty($_FILES['logo']['name']) ||
        !isset($_FILES['logo'])
    ) {
        return null;
    }

    $file = $_FILES['logo'];

    if (
        !is_array($file) ||
        ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Logo upload failed with code ' .
            (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) .
            '.'
        );
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file((string)$file['tmp_name'])
    ) {
        throw new RuntimeException(
            'The uploaded logo could not be verified.'
        );
    }

    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException(
            'Logo must not exceed 2 MB.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException(
            'Logo must be PNG, JPG, GIF, or WEBP.'
        );
    }

    $directory = __DIR__ . '/../uploads/voucher_logos';

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0755, true) &&
        !is_dir($directory)
    ) {
        throw new RuntimeException(
            'Unable to create the voucher logo directory.'
        );
    }

    $filename = sprintf(
        'pv_%d_%s.%s',
        $voucherId > 0 ? $voucherId : time(),
        bin2hex(random_bytes(8)),
        $extensions[$mime]
    );

    $destination = $directory . '/' . $filename;

    if (
        !move_uploaded_file(
            (string)$file['tmp_name'],
            $destination
        )
    ) {
        throw new RuntimeException(
            'Unable to save the uploaded logo.'
        );
    }

    return $filename;
}


/*
|--------------------------------------------------------------------------
| Global voucher-logo settings
|--------------------------------------------------------------------------
*/
function pvEnsureVoucherSettingsTable(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS voucher_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            voucher_logo VARCHAR(255) DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException(
            'Unable to initialise voucher settings: ' . $conn->error
        );
    }

    $conn->query(
        "INSERT IGNORE INTO voucher_settings (id) VALUES (1)"
    );
}

function pvVoucherLogoDirectory(): string
{
    return __DIR__ . '/../uploads/voucher_branding';
}

function pvVoucherLogoPath(?string $filename): string
{
    $filename = basename(trim((string)$filename));

    return $filename === ''
        ? ''
        : pvVoucherLogoDirectory() . '/' . $filename;
}

function pvSaveGlobalVoucherLogo(): string
{
    if (
        !isset($_FILES['voucher_logo']) ||
        !is_array($_FILES['voucher_logo']) ||
        empty($_FILES['voucher_logo']['name'])
    ) {
        throw new InvalidArgumentException(
            'Please choose a voucher logo.'
        );
    }

    $file = $_FILES['voucher_logo'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Voucher logo upload failed with code ' . $error . '.'
        );
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file((string)$file['tmp_name'])
    ) {
        throw new RuntimeException(
            'The uploaded voucher logo could not be verified.'
        );
    }

    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException(
            'Voucher logo must not exceed 2 MB.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException(
            'Voucher logo must be PNG, JPG, or WEBP.'
        );
    }

    $directory = pvVoucherLogoDirectory();

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0755, true) &&
        !is_dir($directory)
    ) {
        throw new RuntimeException(
            'Unable to create the voucher branding directory.'
        );
    }

    $filename = 'voucher_logo_' .
        date('YmdHis') .
        '_' .
        bin2hex(random_bytes(5)) .
        '.' .
        $extensions[$mime];

    $destination = $directory . '/' . $filename;

    if (
        !move_uploaded_file(
            (string)$file['tmp_name'],
            $destination
        )
    ) {
        throw new RuntimeException(
            'Unable to save the voucher logo.'
        );
    }

    return $filename;
}

function pvHandleSaveVoucherLogo(mysqli $conn): never
{
    pvEnsureVoucherSettingsTable($conn);

    $stmt = pvRequireStatement(
        $conn,
        'SELECT voucher_logo
         FROM voucher_settings
         WHERE id = 1
         LIMIT 1'
    );
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $oldLogo = trim((string)($existing['voucher_logo'] ?? ''));
    $newLogo = pvSaveGlobalVoucherLogo();

    $stmt = pvRequireStatement(
        $conn,
        'UPDATE voucher_settings
         SET voucher_logo = ?,
             updated_by = ?
         WHERE id = 1'
    );

    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('si', $newLogo, $userId);

    if (!$stmt->execute()) {
        $newPath = pvVoucherLogoPath($newLogo);

        if ($newPath !== '' && is_file($newPath)) {
            @unlink($newPath);
        }

        throw new RuntimeException(
            'Unable to update voucher logo: ' . $stmt->error
        );
    }

    $stmt->close();

    if ($oldLogo !== '' && $oldLogo !== $newLogo) {
        $oldPath = pvVoucherLogoPath($oldLogo);

        if ($oldPath !== '' && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    pvJson(
        true,
        'Voucher logo uploaded successfully.',
        ['logo' => $newLogo]
    );
}

function pvHandleRemoveVoucherLogo(mysqli $conn): never
{
    pvEnsureVoucherSettingsTable($conn);

    $stmt = pvRequireStatement(
        $conn,
        'SELECT voucher_logo
         FROM voucher_settings
         WHERE id = 1
         LIMIT 1'
    );
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $oldLogo = trim((string)($existing['voucher_logo'] ?? ''));

    $stmt = pvRequireStatement(
        $conn,
        'UPDATE voucher_settings
         SET voucher_logo = NULL,
             updated_by = ?
         WHERE id = 1'
    );

    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('i', $userId);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to remove voucher logo: ' . $stmt->error
        );
    }

    $stmt->close();

    if ($oldLogo !== '') {
        $oldPath = pvVoucherLogoPath($oldLogo);

        if ($oldPath !== '' && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    pvJson(true, 'Voucher logo removed successfully.');
}

/*
|--------------------------------------------------------------------------
| CSV helpers
|--------------------------------------------------------------------------
*/
function pvNormalizeHeader(mixed $heading): string
{
    $heading = pvCleanCsvValue($heading);
    $heading = strtolower($heading);

    // Replace all separators and punctuation with underscores.
    $heading = preg_replace('/[^a-z0-9]+/i', '_', $heading) ?? '';
    $heading = trim($heading, '_');

    $aliases = [
        // Voucher date
        'date' => 'voucher_date',
        'voucherdate' => 'voucher_date',
        'voucher_date' => 'voucher_date',
        'payment_date' => 'voucher_date',
        'paymentdate' => 'voucher_date',

        // Voucher number and series
        'voucher_number' => 'voucher_no',
        'voucher_number_no' => 'voucher_no',
        'voucher_num' => 'voucher_no',
        'voucherno' => 'voucher_no',
        'voucher_no' => 'voucher_no',
        'series' => 'voucher_series',
        'voucher_series_name' => 'voucher_series',

        // Group
        'group' => 'row_group',
        'group_no' => 'row_group',
        'group_number' => 'row_group',
        'rowgroup' => 'row_group',

        // Supplier
        'supplier_name' => 'supplier',
        'supplier_receiver' => 'supplier',
        'receiver' => 'supplier',
        'payee' => 'supplier',
        'paid_to' => 'supplier',

        // Currency and totals
        'currency_code' => 'currency',
        'rate' => 'exchange_rate',
        'exchange' => 'exchange_rate',
        'equivalent_amount' => 'total_usd',
        'usd_equivalent' => 'total_usd',
        'equivalent_in_usd' => 'total_usd',

        // References
        'account' => 'account_code',
        'account_no' => 'account_code',
        'project' => 'project_code',
        'project_item' => 'project_code',
        'journal_ref' => 'jnl_ref',
        'journal_reference' => 'jnl_ref',
        'jnl_reference' => 'jnl_ref',
        'cheque_reference' => 'cheque_ref',
        'check_ref' => 'cheque_ref',

        // Item description
        'description' => 'item_description',
        'item' => 'item_description',
        'particulars' => 'item_description',
        'item_name' => 'item_description',
        'payment_description' => 'item_description',

        // Quantity
        'qty' => 'item_qty',
        'quantity' => 'item_qty',
        'item_quantity' => 'item_qty',

        // Unit price
        'price' => 'item_unit_price',
        'unitprice' => 'item_unit_price',
        'unit_cost' => 'item_unit_price',
        'cost' => 'item_unit_price',
        'amount' => 'item_unit_price',

        // Budget line
        'budget' => 'item_budget_line',
        'budgetline' => 'item_budget_line',
        'budget_code' => 'item_budget_line',
    ];

    return $aliases[$heading] ?? $heading;
}

function pvDetectCsvDelimiter(string $line): string
{
    $delimiters = [
        ',' => substr_count($line, ','),
        ';' => substr_count($line, ';'),
        "\t" => substr_count($line, "\t"),
        '|' => substr_count($line, '|'),
    ];

    arsort($delimiters);
    $delimiter = (string)array_key_first($delimiters);

    if (($delimiters[$delimiter] ?? 0) === 0) {
        return ',';
    }

    return $delimiter;
}

function pvReadCsvHeader($handle): array
{
    if (!is_resource($handle)) {
        throw new RuntimeException(
            'Unable to read the uploaded CSV file.'
        );
    }

    rewind($handle);

    $firstLine = fgets($handle);

    if ($firstLine === false) {
        throw new InvalidArgumentException(
            'CSV file is empty.'
        );
    }

    // Remove UTF-8 BOM before detecting the delimiter.
    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
    $delimiter = pvDetectCsvDelimiter($firstLine);

    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);

    if (!is_array($header) || $header === []) {
        throw new InvalidArgumentException(
            'The CSV header could not be read.'
        );
    }

    // Handle files with blank lines before the real header.
    while (
        is_array($header) &&
        count(
            array_filter(
                $header,
                static fn($value): bool =>
                    pvCleanCsvValue($value) !== ''
            )
        ) === 0
    ) {
        $header = fgetcsv($handle, 0, $delimiter);

        if ($header === false) {
            throw new InvalidArgumentException(
                'The CSV file does not contain a header row.'
            );
        }
    }

    return [
        'header' => $header,
        'delimiter' => $delimiter,
    ];
}

function pvBuildCsvMap(array $header): array
{
    $map = [];

    foreach ($header as $index => $heading) {
        $key = pvNormalizeHeader($heading);

        if ($key === '') {
            continue;
        }

        // Keep the first occurrence of a column name.
        if (!array_key_exists($key, $map)) {
            $map[$key] = (int)$index;
        }
    }

    return $map;
}

function pvCsvValue(
    array $row,
    array $map,
    string $key
): string {
    if (!array_key_exists($key, $map)) {
        return '';
    }

    $index = $map[$key];

    if (!array_key_exists($index, $row)) {
        return '';
    }

    return pvCleanCsvValue($row[$index]);
}

function pvCsvRowIsEmpty(array $row): bool
{
    foreach ($row as $value) {
        if (pvCleanCsvValue($value) !== '') {
            return false;
        }
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Access control and request routing
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    pvJson(false, 'Invalid request method.');
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    pvJson(false, 'Not authenticated. Please log in again.');
}

if (!ims_allowed('vouchers.manage', ['Administrator', 'Finance', 'Accountant'], 'Create, edit, delete and import payment vouchers')) {
    http_response_code(403);
    pvJson(false, 'Access denied.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    pvJson(false, 'Database connection is unavailable.');
}

$conn->set_charset('utf8mb4');

$action = trim((string)($_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'save_voucher':
            pvHandleSave($conn);
            break;

        case 'delete_voucher':
            pvHandleDelete($conn);
            break;

        case 'bulk_upload_csv':
            pvHandleBulkUpload($conn);
            break;

        case 'save_voucher_logo':
            pvHandleSaveVoucherLogo($conn);
            break;

        case 'remove_voucher_logo':
            pvHandleRemoveVoucherLogo($conn);
            break;

        default:
            pvJson(false, 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log(
        '[process_voucher] ' .
        $e->getMessage() .
        ' in ' .
        $e->getFile() .
        ':' .
        $e->getLine()
    );

    pvJson(false, $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Create or update voucher
|--------------------------------------------------------------------------
*/
function pvHandleSave(mysqli $conn): never
{
    $id = (int)($_POST['id'] ?? 0);
    $voucherNo = trim((string)($_POST['voucher_no'] ?? ''));
    $supplier = trim((string)($_POST['supplier'] ?? ''));
    $currency = strtoupper(
        trim((string)($_POST['currency'] ?? 'UGX'))
    );

    if ($voucherNo === '') {
        throw new InvalidArgumentException(
            'Voucher number is required.'
        );
    }

    if ($supplier === '') {
        throw new InvalidArgumentException(
            'Supplier is required.'
        );
    }

    if (!in_array($currency, PV_ALLOWED_CURRENCIES, true)) {
        throw new InvalidArgumentException(
            'Unsupported currency.'
        );
    }

    $voucherDate = pvDate($_POST['voucher_date'] ?? '');
    $exchangeRate = pvNumber(
        $_POST['exchange_rate'] ?? '',
        'Exchange rate',
        true
    );
    $totalUsd = pvNumber(
        $_POST['total_usd'] ?? '',
        'Equivalent amount',
        true
    );

    $itemsInput = $_POST['items'] ?? [];
    $items = pvCleanItems(
        is_array($itemsInput) ? $itemsInput : []
    );

    $totalAmount = round(
        array_sum(array_column($items, 'total_price')),
        2
    );

    $itemsJson = json_encode(
        $items,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    $accountCode = pvNullable($_POST['account_code'] ?? '');
    $projectCode = pvNullable($_POST['project_code'] ?? '');
    $jnlRef = pvNullable($_POST['jnl_ref'] ?? '');
    $chequeRef = pvNullable($_POST['cheque_ref'] ?? '');
    $userId = (int)$_SESSION['user_id'];

    $conn->begin_transaction();

    $newLogo = null;
    $existingLogo = null;

    try {
        if ($id > 0) {
            $check = pvRequireStatement(
                $conn,
                'SELECT logo_filename
                 FROM payment_vouchers
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE'
            );

            $check->bind_param('i', $id);
            $check->execute();

            $record = $check
                ->get_result()
                ->fetch_assoc();

            $check->close();

            if (!$record) {
                throw new RuntimeException(
                    'Voucher not found.'
                );
            }

            $existingLogo = $record['logo_filename'] ?? null;
        }

        $duplicate = pvRequireStatement(
            $conn,
            'SELECT id
             FROM payment_vouchers
             WHERE voucher_no = ?
               AND id <> ?
             LIMIT 1'
        );

        $duplicate->bind_param(
            'si',
            $voucherNo,
            $id
        );

        $duplicate->execute();

        $hasDuplicate = (
            $duplicate
                ->get_result()
                ->fetch_assoc() !== null
        );

        $duplicate->close();

        if ($hasDuplicate) {
            throw new InvalidArgumentException(
                'That voucher number already exists.'
            );
        }

        $newLogo = pvSaveLogo($id);
        $logoFilename = $newLogo ?? $existingLogo;

        if ($id > 0) {
            $stmt = pvRequireStatement(
                $conn,
                'UPDATE payment_vouchers
                 SET voucher_no = ?,
                     voucher_date = ?,
                     supplier = ?,
                     currency = ?,
                     exchange_rate = ?,
                     total_amount = ?,
                     total_usd = ?,
                     account_code = ?,
                     project_code = ?,
                     jnl_ref = ?,
                     cheque_ref = ?,
                     items_json = ?,
                     logo_filename = ?,
                     created_by = ?
                 WHERE id = ?'
            );

            $stmt->bind_param(
                'ssssdddssssssii',
                $voucherNo,
                $voucherDate,
                $supplier,
                $currency,
                $exchangeRate,
                $totalAmount,
                $totalUsd,
                $accountCode,
                $projectCode,
                $jnlRef,
                $chequeRef,
                $itemsJson,
                $logoFilename,
                $userId,
                $id
            );
        } else {
            $stmt = pvRequireStatement(
                $conn,
                'INSERT INTO payment_vouchers (
                    voucher_no,
                    voucher_date,
                    supplier,
                    currency,
                    exchange_rate,
                    total_amount,
                    total_usd,
                    account_code,
                    project_code,
                    jnl_ref,
                    cheque_ref,
                    items_json,
                    logo_filename,
                    created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->bind_param(
                'ssssdddssssssi',
                $voucherNo,
                $voucherDate,
                $supplier,
                $currency,
                $exchangeRate,
                $totalAmount,
                $totalUsd,
                $accountCode,
                $projectCode,
                $jnlRef,
                $chequeRef,
                $itemsJson,
                $logoFilename,
                $userId
            );
        }

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to save voucher: ' . $stmt->error
            );
        }

        $savedId = $id > 0
            ? $id
            : (int)$stmt->insert_id;

        $stmt->close();
        $conn->commit();

        if (
            $newLogo !== null &&
            !empty($existingLogo) &&
            $existingLogo !== $newLogo
        ) {
            $oldPath =
                __DIR__ .
                '/../uploads/voucher_logos/' .
                basename((string)$existingLogo);

            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        pvJson(
            true,
            $id > 0
                ? 'Voucher updated successfully.'
                : 'Voucher created successfully.',
            [
                'id' => $savedId,
            ]
        );
    } catch (Throwable $e) {
        $conn->rollback();

        if ($newLogo !== null) {
            $path =
                __DIR__ .
                '/../uploads/voucher_logos/' .
                basename($newLogo);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| Delete voucher
|--------------------------------------------------------------------------
*/
function pvHandleDelete(mysqli $conn): never
{
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        throw new InvalidArgumentException(
            'Invalid voucher ID.'
        );
    }

    $conn->begin_transaction();

    try {
        $stmt = pvRequireStatement(
            $conn,
            'SELECT voucher_no, logo_filename
             FROM payment_vouchers
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $voucher = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$voucher) {
            throw new RuntimeException(
                'Voucher not found.'
            );
        }

        $stmt = pvRequireStatement(
            $conn,
            'DELETE FROM payment_vouchers
             WHERE id = ?'
        );

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to delete voucher: ' . $stmt->error
            );
        }

        $stmt->close();
        $conn->commit();

        if (!empty($voucher['logo_filename'])) {
            $path =
                __DIR__ .
                '/../uploads/voucher_logos/' .
                basename((string)$voucher['logo_filename']);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        pvJson(
            true,
            'Voucher "' .
            (string)$voucher['voucher_no'] .
            '" deleted successfully.'
        );
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| Bulk CSV upload
|--------------------------------------------------------------------------
*/
function pvHandleBulkUpload(mysqli $conn): never
{
    if (
        !isset($_FILES['csv_file']) ||
        !is_array($_FILES['csv_file']) ||
        empty($_FILES['csv_file']['name'])
    ) {
        throw new InvalidArgumentException(
            'Please choose a CSV file.'
        );
    }

    $file = $_FILES['csv_file'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'CSV upload failed with code ' . $uploadError . '.'
        );
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file((string)$file['tmp_name'])
    ) {
        throw new RuntimeException(
            'The uploaded CSV file could not be verified.'
        );
    }

    if ((int)($file['size'] ?? 0) > PV_MAX_CSV_SIZE) {
        throw new InvalidArgumentException(
            'CSV file must not exceed 5 MB.'
        );
    }

    $extension = strtolower(
        pathinfo(
            (string)$file['name'],
            PATHINFO_EXTENSION
        )
    );

    if (!in_array($extension, ['csv', 'txt'], true)) {
        throw new InvalidArgumentException(
            'Please upload a CSV file.'
        );
    }

    $handle = fopen(
        (string)$file['tmp_name'],
        'rb'
    );

    if ($handle === false) {
        throw new RuntimeException(
            'Unable to read the CSV file.'
        );
    }

    try {
        $headerData = pvReadCsvHeader($handle);
        $header = $headerData['header'];
        $delimiter = $headerData['delimiter'];
        $map = pvBuildCsvMap($header);

        $requiredColumns = [
            'voucher_date',
            'supplier',
            'item_description',
            'item_qty',
            'item_unit_price',
        ];

        $missing = [];

        foreach ($requiredColumns as $required) {
            if (!array_key_exists($required, $map)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            $detected = array_keys($map);

            throw new InvalidArgumentException(
                'Missing required column' .
                (count($missing) > 1 ? 's' : '') .
                ': ' .
                implode(', ', $missing) .
                '. Detected columns: ' .
                ($detected !== []
                    ? implode(', ', $detected)
                    : 'none') .
                '.'
            );
        }

        $groups = [];
        $order = [];
        $rowNumber = 1;
        $autoGroup = 0;

        while (
            (
                $row = fgetcsv(
                    $handle,
                    0,
                    $delimiter
                )
            ) !== false
        ) {
            $rowNumber++;

            if ($rowNumber > PV_MAX_CSV_ROWS + 1) {
                throw new InvalidArgumentException(
                    'CSV exceeds the ' .
                    number_format(PV_MAX_CSV_ROWS) .
                    '-row limit.'
                );
            }

            if (!is_array($row) || pvCsvRowIsEmpty($row)) {
                continue;
            }

            $rowGroup = pvCsvValue(
                $row,
                $map,
                'row_group'
            );

            $voucherNo = pvCsvValue(
                $row,
                $map,
                'voucher_no'
            );

            if ($rowGroup !== '') {
                $groupKey = 'group:' . $rowGroup;
            } elseif ($voucherNo !== '') {
                $groupKey = 'voucher:' . $voucherNo;
            } else {
                $autoGroup++;
                $groupKey = 'auto:' . $autoGroup;
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'row' => $rowNumber,
                    'series' => pvCsvValue(
                        $row,
                        $map,
                        'voucher_series'
                    ),
                    'voucher_no' => $voucherNo,
                    'voucher_date' => pvCsvValue(
                        $row,
                        $map,
                        'voucher_date'
                    ),
                    'supplier' => pvCsvValue(
                        $row,
                        $map,
                        'supplier'
                    ),
                    'currency' => strtoupper(
                        pvCsvValue(
                            $row,
                            $map,
                            'currency'
                        ) ?: 'UGX'
                    ),
                    'exchange_rate' => pvCsvValue(
                        $row,
                        $map,
                        'exchange_rate'
                    ),
                    'total_usd' => pvCsvValue(
                        $row,
                        $map,
                        'total_usd'
                    ),
                    'account_code' => pvCsvValue(
                        $row,
                        $map,
                        'account_code'
                    ),
                    'project_code' => pvCsvValue(
                        $row,
                        $map,
                        'project_code'
                    ),
                    'jnl_ref' => pvCsvValue(
                        $row,
                        $map,
                        'jnl_ref'
                    ),
                    'cheque_ref' => pvCsvValue(
                        $row,
                        $map,
                        'cheque_ref'
                    ),
                    'items' => [],
                ];

                $order[] = $groupKey;
            } else {
                // Fill blank voucher-level fields from later rows in the group.
                $voucherFields = [
                    'voucher_date',
                    'supplier',
                    'currency',
                    'exchange_rate',
                    'total_usd',
                    'account_code',
                    'project_code',
                    'jnl_ref',
                    'cheque_ref',
                ];

                foreach ($voucherFields as $field) {
                    if (
                        pvCleanCsvValue(
                            $groups[$groupKey][$field] ?? ''
                        ) === ''
                    ) {
                        $candidate = pvCsvValue(
                            $row,
                            $map,
                            $field
                        );

                        if ($candidate !== '') {
                            $groups[$groupKey][$field] =
                                $field === 'currency'
                                    ? strtoupper($candidate)
                                    : $candidate;
                        }
                    }
                }

                if (
                    pvCleanCsvValue(
                        $groups[$groupKey]['series'] ?? ''
                    ) === ''
                ) {
                    $groups[$groupKey]['series'] =
                        pvCsvValue(
                            $row,
                            $map,
                            'voucher_series'
                        );
                }
            }

            $groups[$groupKey]['items'][] = [
                'item' => pvCsvValue(
                    $row,
                    $map,
                    'item_description'
                ),
                'qty' => pvCsvValue(
                    $row,
                    $map,
                    'item_qty'
                ),
                'unit_price' => pvCsvValue(
                    $row,
                    $map,
                    'item_unit_price'
                ),
                'budget_line' => pvCsvValue(
                    $row,
                    $map,
                    'item_budget_line'
                ),
            ];
        }
    } finally {
        fclose($handle);
    }

    if ($groups === []) {
        throw new InvalidArgumentException(
            'No data rows were found in the CSV file.'
        );
    }

    $sql =
        'INSERT INTO payment_vouchers (
            voucher_no,
            voucher_date,
            supplier,
            currency,
            exchange_rate,
            total_amount,
            total_usd,
            account_code,
            project_code,
            jnl_ref,
            cheque_ref,
            items_json,
            logo_filename,
            created_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            voucher_date = VALUES(voucher_date),
            supplier = VALUES(supplier),
            currency = VALUES(currency),
            exchange_rate = VALUES(exchange_rate),
            total_amount = VALUES(total_amount),
            total_usd = VALUES(total_usd),
            account_code = VALUES(account_code),
            project_code = VALUES(project_code),
            jnl_ref = VALUES(jnl_ref),
            cheque_ref = VALUES(cheque_ref),
            items_json = VALUES(items_json),
            created_by = VALUES(created_by)';

    $stmt = pvRequireStatement($conn, $sql);

    $numberCache = [];
    $succeeded = 0;
    $failed = 0;
    $details = [];
    $userId = (int)$_SESSION['user_id'];
    $logoFilename = null;

    foreach ($order as $groupKey) {
        $group = $groups[$groupKey];

        try {
            $voucherDate = pvDate(
                $group['voucher_date']
            );

            $supplier = trim(
                (string)$group['supplier']
            );

            if ($supplier === '') {
                throw new InvalidArgumentException(
                    'Supplier is required.'
                );
            }

            $currency = strtoupper(
                trim(
                    (string)($group['currency'] ?: 'UGX')
                )
            );

            if (
                !in_array(
                    $currency,
                    PV_ALLOWED_CURRENCIES,
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Unsupported currency "' .
                    $currency .
                    '".'
                );
            }

            $items = pvCleanItems(
                $group['items']
            );

            $totalAmount = round(
                array_sum(
                    array_column(
                        $items,
                        'total_price'
                    )
                ),
                2
            );

            $itemsJson = json_encode(
                $items,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );

            $voucherNo = trim(
                (string)$group['voucher_no']
            );

            $conn->begin_transaction();

            try {
                if ($voucherNo === '') {
                    $series = trim(
                        (string)$group['series']
                    );

                    if ($series === '') {
                        $series = PV_ALLOWED_SERIES[0];
                    }

                    if (
                        !in_array(
                            $series,
                            PV_ALLOWED_SERIES,
                            true
                        )
                    ) {
                        throw new InvalidArgumentException(
                            'Unknown voucher series "' .
                            $series .
                            '".'
                        );
                    }

                    $voucherNo = pvNextVoucherNumber(
                        $conn,
                        $series,
                        $numberCache
                    );
                }

                $exchangeRate = pvNumber(
                    $group['exchange_rate'],
                    'Exchange rate',
                    true
                );

                $totalUsd = pvNumber(
                    $group['total_usd'],
                    'Equivalent amount',
                    true
                );

                $accountCode = pvNullable(
                    $group['account_code']
                );

                $projectCode = pvNullable(
                    $group['project_code']
                );

                $jnlRef = pvNullable(
                    $group['jnl_ref']
                );

                $chequeRef = pvNullable(
                    $group['cheque_ref']
                );

                $stmt->bind_param(
                    'ssssdddssssssi',
                    $voucherNo,
                    $voucherDate,
                    $supplier,
                    $currency,
                    $exchangeRate,
                    $totalAmount,
                    $totalUsd,
                    $accountCode,
                    $projectCode,
                    $jnlRef,
                    $chequeRef,
                    $itemsJson,
                    $logoFilename,
                    $userId
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        'Database insert failed: ' .
                        $stmt->error
                    );
                }

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

            $succeeded++;

            $details[] = [
                'row' => (int)$group['row'],
                'voucher_no' => $voucherNo,
                'status' => 'ok',
                'message' =>
                    'Created or updated with ' .
                    count($items) .
                    ' item(s).',
            ];
        } catch (Throwable $e) {
            $failed++;

            $details[] = [
                'row' => (int)$group['row'],
                'voucher_no' =>
                    trim((string)$group['voucher_no']) !== ''
                        ? trim((string)$group['voucher_no'])
                        : '(auto)',
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    $stmt->close();

    pvJson(
        $succeeded > 0,
        'Processed ' .
        ($succeeded + $failed) .
        ' voucher(s): ' .
        $succeeded .
        ' succeeded, ' .
        $failed .
        ' failed.',
        [
            'created' => $succeeded,
            'failed' => $failed,
            'details' => $details,
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Generate the next voucher number
|--------------------------------------------------------------------------
*/
function pvNextVoucherNumber(
    mysqli $conn,
    string $series,
    array &$cache
): string {
    $prefix = sprintf(
        '%s-%s-%s-',
        $series,
        strtoupper(date('M')),
        date('Y')
    );

    if (!isset($cache[$prefix])) {
        $stmt = pvRequireStatement(
            $conn,
            'SELECT MAX(
                CAST(
                    SUBSTRING_INDEX(voucher_no, \'-\', -1)
                    AS UNSIGNED
                )
             ) AS seq
             FROM payment_vouchers
             WHERE voucher_no LIKE CONCAT(?, \'%\')
             FOR UPDATE'
        );

        $stmt->bind_param('s', $prefix);
        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $cache[$prefix] =
            ((int)($row['seq'] ?? 0)) + 1;
    } else {
        $cache[$prefix]++;
    }

    return $prefix .
        str_pad(
            (string)$cache[$prefix],
            3,
            '0',
            STR_PAD_LEFT
        );
}
