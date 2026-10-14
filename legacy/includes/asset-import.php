<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BULK ASSET IMPORT (CSV)
|--------------------------------------------------------------------------
| Used by manage_assets.php:
|   - ?export=assets_template  -> asset_import_template()  (download)
|   - POST action=import_assets -> importAssets()           (upload)
|
| The template has the same columns as the Add Asset form. Rows whose first
| cell starts with "#" (the example row and the notes) are ignored.
| Every row is validated first; by default nothing is imported if any row is
| invalid, or the administrator can choose to skip invalid rows. Valid rows
| are inserted in one transaction.
*/

const ASSET_IMPORT_COLUMNS = [
    'asset_code'       => 'Asset Code (required, unique)',
    'asset_name'       => 'Asset Name (required)',
    'category'         => 'Category (required, must exist)',
    'brand'            => 'Brand',
    'model'            => 'Model',
    'serial_number'    => 'Serial Number',
    'purchase_date'    => 'Purchase Date (YYYY-MM-DD)',
    'purchase_cost'    => 'Purchase Cost',
    'condition_status' => 'Condition',
    'description'      => 'Description',
];

const ASSET_IMPORT_CONDITIONS = ['New', 'Good', 'Fair', 'Damaged', 'Disposed'];
const ASSET_IMPORT_MAX_ROWS   = 2000;
const ASSET_IMPORT_MAX_BYTES  = 2 * 1024 * 1024;

/** Write the CSV template (header, example row, notes) to $out. */
function asset_import_write_template($out, array $categories): void
{
    $names = array_values(array_filter(array_map(
        static fn(array $c): string => (string)$c['category_name'],
        $categories
    )));

    ims_fputcsv($out, array_keys(ASSET_IMPORT_COLUMNS));
    ims_fputcsv($out, [
        '#LAP-0001', 'Dell Latitude 5440 Laptop', $names[0] ?? 'Laptops', 'Dell', 'Latitude 5440',
        'SN123456789', date('Y-m-d'), '3500000', 'New', 'Example row: delete it or leave the # in front',
    ]);
    ims_fputcsv($out, ['# Rows starting with # are ignored. Fill in one asset per row below the header.']);
    ims_fputcsv($out, ['# Required: asset_code (must be unique), asset_name, category.']);
    ims_fputcsv($out, ['# category must be one of: ' . ($names ? implode('; ', $names) : 'add categories under Asset Categories first')]);
    ims_fputcsv($out, ['# condition_status: ' . implode(', ', ASSET_IMPORT_CONDITIONS) . ' (blank = Good)']);
    ims_fputcsv($out, ['# purchase_date: YYYY-MM-DD (also accepts DD/MM/YYYY). purchase_cost: numbers only, no currency.']);
}

/** Stream the CSV template as a download and stop. */
function asset_import_template(array $categories): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assets_import_template.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel keeps accents
    asset_import_write_template($out, $categories);
    fclose($out);
    exit;
}

/** Turn a header cell into a column key: "Purchase Date" -> "purchase_date". */
function asset_import_header_key(string $cell): string
{
    $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;
    $key = strtolower(trim($cell));
    $key = preg_replace('/\s*\(.*\)\s*$/', '', $key) ?? $key; // "Asset Code (required)" -> "asset code"
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
    $key = trim($key, '_');

    $aliases = [
        'code' => 'asset_code', 'name' => 'asset_name', 'category_name' => 'category',
        'serial' => 'serial_number', 'serial_no' => 'serial_number', 'date' => 'purchase_date',
        'cost' => 'purchase_cost', 'price' => 'purchase_cost', 'condition' => 'condition_status',
        'status' => 'condition_status', 'notes' => 'description',
    ];

    return $aliases[$key] ?? $key;
}

/** Normalise a cell: UTF-8, trimmed, export-safety apostrophe removed. */
function asset_import_cell(?string $value): string
{
    $value = (string)$value;
    if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
    $value = trim($value);

    // Our CSV exports prefix "'" to cells starting with = + - @; undo that.
    if (preg_match("/^'[=+\\-@]/", $value)) {
        $value = substr($value, 1);
    }

    return $value;
}

/** Parse YYYY-MM-DD or DD/MM/YYYY (also DD-MM-YYYY, DD.MM.YYYY, YYYY/MM/DD). Returns Y-m-d or null. */
function asset_import_date(string $value): ?string
{
    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!Y/m/d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $year = (int)$date->format('Y');
            return ($year >= 1950 && $year <= (int)date('Y') + 1) ? $date->format('Y-m-d') : null;
        }
    }

    return null;
}

/** Parse "1,250,000", "UGX 1 250 000.50" etc. Returns float or null when not a number. */
function asset_import_cost(string $value): ?float
{
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) ?? '';
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }

    return (float)$clean;
}

/** Store the result shown under the page header after redirect. */
function asset_import_report(int $imported, int $skipped, array $errors, string $fileName): void
{
    $_SESSION['asset_import_report'] = [
        'imported' => $imported,
        'skipped'  => $skipped,
        'errors'   => array_slice($errors, 0, 50),
        'more'     => max(0, count($errors) - 50),
        'file'     => $fileName,
    ];
}

/**
 * Read and validate an import file. Returns
 * ['fatal' => ?string, 'valid' => rows ready to insert, 'errors' => per-line messages].
 */
function asset_import_parse(mysqli $conn, string $path): array
{
    $fail = static fn(string $message): array => ['fatal' => $message, 'valid' => [], 'errors' => []];

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return $fail('The uploaded file could not be read.');
    }

    // Excel in some regions saves with ";" instead of ",".
    $firstLine = (string)fgets($handle);
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    rewind($handle);

    /* Categories by lower-case name, existing asset codes. */
    $categories = [];
    foreach (getAssetCategories($conn) as $category) {
        $categories[mb_strtolower(trim((string)$category['category_name']), 'UTF-8')] = (int)$category['category_id'];
    }
    $existingCodes = [];
    $res = $conn->query('SELECT asset_code FROM assets');
    if ($res) {
        while ($row = $res->fetch_row()) {
            $existingCodes[mb_strtolower(trim((string)$row[0]), 'UTF-8')] = true;
        }
    }

    $columns = null;
    $lineNo = 0;
    $valid = [];
    $errors = [];
    $seenCodes = [];
    $dataRows = 0;

    while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNo++;
        $cells = array_map('asset_import_cell', $cells);

        if (!array_filter($cells, static fn($c) => $c !== '')) {
            continue; // blank line
        }
        if (str_starts_with($cells[0] ?? '', '#')) {
            continue; // example row / notes
        }

        // First non-comment row is the header.
        if ($columns === null) {
            $columns = array_map('asset_import_header_key', $cells);
            $missing = array_diff(['asset_code', 'asset_name', 'category'], $columns);
            if ($missing) {
                fclose($handle);
                return $fail('The file is missing the column(s): ' . implode(', ', $missing)
                    . '. Download the template and keep its header row.');
            }
            continue;
        }

        if (++$dataRows > ASSET_IMPORT_MAX_ROWS) {
            fclose($handle);
            return $fail('The file has more than ' . ASSET_IMPORT_MAX_ROWS . ' assets. Split it into smaller files.');
        }

        $row = [];
        foreach ($columns as $i => $key) {
            if ($key !== '' && !isset($row[$key])) {
                $row[$key] = $cells[$i] ?? '';
            }
        }

        $rowErrors = [];
        $code = $row['asset_code'] ?? '';
        $name = $row['asset_name'] ?? '';
        $categoryName = $row['category'] ?? '';

        if ($code === '') {
            $rowErrors[] = 'asset_code is empty';
        } elseif (mb_strlen($code) > 100) {
            $rowErrors[] = 'asset_code is too long';
        } else {
            $codeKey = mb_strtolower($code, 'UTF-8');
            if (isset($existingCodes[$codeKey])) {
                $rowErrors[] = "asset code \"{$code}\" already exists in the system";
            } elseif (isset($seenCodes[$codeKey])) {
                $rowErrors[] = "asset code \"{$code}\" is repeated (also on line {$seenCodes[$codeKey]})";
            } else {
                $seenCodes[$codeKey] = $lineNo;
            }
        }

        if ($name === '') {
            $rowErrors[] = 'asset_name is empty';
        }

        $categoryId = $categories[mb_strtolower($categoryName, 'UTF-8')] ?? 0;
        if ($categoryName === '') {
            $rowErrors[] = 'category is empty';
        } elseif ($categoryId === 0) {
            $rowErrors[] = "category \"{$categoryName}\" does not exist";
        }

        $condition = 'Good';
        if (($row['condition_status'] ?? '') !== '') {
            $match = array_values(array_filter(
                ASSET_IMPORT_CONDITIONS,
                static fn($c) => strcasecmp($c, $row['condition_status']) === 0
            ));
            if ($match) {
                $condition = $match[0];
            } else {
                $rowErrors[] = "condition \"{$row['condition_status']}\" must be one of " . implode(', ', ASSET_IMPORT_CONDITIONS);
            }
        }

        $purchaseDate = null;
        if (($row['purchase_date'] ?? '') !== '') {
            $purchaseDate = asset_import_date($row['purchase_date']);
            if ($purchaseDate === null) {
                $rowErrors[] = "purchase_date \"{$row['purchase_date']}\" is not a valid date (use YYYY-MM-DD)";
            }
        }

        $cost = 0.0;
        if (($row['purchase_cost'] ?? '') !== '') {
            $parsed = asset_import_cost($row['purchase_cost']);
            if ($parsed === null || $parsed < 0) {
                $rowErrors[] = "purchase_cost \"{$row['purchase_cost']}\" is not a valid amount";
            } else {
                $cost = $parsed;
            }
        }

        if ($rowErrors) {
            $errors[] = 'Line ' . $lineNo . ($code !== '' ? " ({$code})" : '') . ': ' . implode('; ', $rowErrors) . '.';
            continue;
        }

        $valid[] = [
            'asset_code'       => $code,
            'asset_name'       => $name,
            'category_id'      => $categoryId,
            'brand'            => $row['brand'] ?? '',
            'model'            => $row['model'] ?? '',
            'serial_number'    => $row['serial_number'] ?? '',
            'purchase_date'    => $purchaseDate,
            'purchase_cost'    => $cost,
            'condition_status' => $condition,
            'description'      => $row['description'] ?? '',
        ];
    }
    fclose($handle);

    if ($columns === null) {
        return $fail('The file is empty. Download the template, add your assets below the header row and upload it again.');
    }
    if (!$valid && !$errors) {
        return $fail('No assets found in the file. Add one asset per row below the header row.');
    }

    return ['fatal' => null, 'valid' => $valid, 'errors' => $errors];
}

/** Insert validated rows in one transaction (all or nothing). Throws on failure. */
function asset_import_insert(mysqli $conn, array $valid, int $currentUserId): void
{
    $stmt = $conn->prepare("
        INSERT INTO assets
            (asset_code, asset_name, category_id, brand, model, serial_number,
             purchase_date, purchase_cost, condition_status, availability_status,
             description, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available', ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare asset import: ' . $conn->error);
    }

    $now = asset_now();
    $conn->begin_transaction();
    try {
        foreach ($valid as $a) {
            $stmt->bind_param(
                'ssissssdssis',
                $a['asset_code'],
                $a['asset_name'],
                $a['category_id'],
                $a['brand'],
                $a['model'],
                $a['serial_number'],
                $a['purchase_date'],
                $a['purchase_cost'],
                $a['condition_status'],
                $a['description'],
                $currentUserId,
                $now
            );
            if (!$stmt->execute()) {
                throw new RuntimeException("Could not import asset {$a['asset_code']}: " . $stmt->error);
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $stmt->close();
        throw $e;
    }
    $stmt->close();
}

/* ----------------------------------------------
   IMPORT HANDLER (POST action=import_assets)
---------------------------------------------- */

function importAssets(mysqli $conn, int $currentUserId, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $file = $_FILES['assets_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        $code = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        asset_flash('error', $code === UPLOAD_ERR_NO_FILE
            ? 'Choose a CSV file to import.'
            : 'The file could not be uploaded (error ' . $code . '). Try again or use a smaller file.');
        redirect_to_assets();
    }

    $fileName = basename((string)($file['name'] ?? 'assets.csv'));
    if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') {
        asset_flash('error', 'Upload a .csv file. In Excel use File > Save As > CSV UTF-8 (Comma delimited).');
        redirect_to_assets();
    }
    if ((int)$file['size'] > ASSET_IMPORT_MAX_BYTES) {
        asset_flash('error', 'The file is larger than 2 MB. Split it into smaller files.');
        redirect_to_assets();
    }

    $result = asset_import_parse($conn, (string)$file['tmp_name']);
    if ($result['fatal'] !== null) {
        asset_flash('error', $result['fatal']);
        redirect_to_assets();
    }
    $valid = $result['valid'];
    $errors = $result['errors'];

    if ($errors && empty($_POST['skip_invalid'])) {
        asset_import_report(0, count($errors), $errors, $fileName);
        asset_flash('error', 'Nothing was imported: ' . count($errors) . ' row(s) need fixing. Correct them and upload again, or tick "Skip rows with errors".');
        redirect_to_assets();
    }

    if (!$valid) {
        asset_import_report(0, count($errors), $errors, $fileName);
        asset_flash('error', 'No valid rows to import.');
        redirect_to_assets();
    }

    try {
        asset_import_insert($conn, $valid, $currentUserId);
    } catch (Throwable $e) {
        asset_flash('error', 'Import cancelled, nothing was saved. ' . $e->getMessage());
        redirect_to_assets();
    }

    if (function_exists('log_action')) {
        log_action($currentUserId, 'Import Assets', 'assets', 0,
            'Imported ' . count($valid) . ' assets from ' . $fileName . ($errors ? ', skipped ' . count($errors) . ' invalid row(s)' : ''));
    }

    asset_import_report(count($valid), count($errors), $errors, $fileName);
    asset_flash('success', 'Imported ' . count($valid) . ' asset' . (count($valid) === 1 ? '' : 's')
        . ($errors ? ' and skipped ' . count($errors) . ' row(s) with errors (listed below).' : '.'));
    redirect_to_assets();
}
