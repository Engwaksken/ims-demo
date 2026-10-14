<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SHARED CSV TEMPLATE + IMPORT HELPERS
|--------------------------------------------------------------------------
| Used by the bulk imports (workplan, procurement). Handles what Excel and
| other spreadsheets produce: a UTF-8 BOM, ";" as the delimiter in some
| regions, Windows-1252 text, header labels instead of keys, DD/MM/YYYY
| dates and amounts such as "UGX 1,200,000". Rows whose first cell starts
| with "#" (template example rows and notes) are ignored.
*/

const IMS_CSV_MAX_BYTES = 2 * 1024 * 1024;

/** Stream a CSV template (header, example rows starting with "#", notes) and stop. */
function ims_csv_send_template(string $filename, array $header, array $exampleRows = [], array $notes = []): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel keeps accents
    ims_csv_write_template($out, $header, $exampleRows, $notes);
    fclose($out);
    exit;
}

/** Write a template to an open handle (used by the download and by tests). */
function ims_csv_write_template($out, array $header, array $exampleRows = [], array $notes = []): void
{
    ims_fputcsv($out, $header);
    foreach ($exampleRows as $row) {
        $row = array_values($row);
        if (isset($row[0]) && !str_starts_with((string)$row[0], '#')) {
            $row[0] = '#' . $row[0];
        }
        ims_fputcsv($out, $row);
    }
    foreach ($notes as $note) {
        ims_fputcsv($out, ['# ' . $note]);
    }
}

/** Header cell -> column key: "Start Date (YYYY-MM-DD)" -> "start_date", then aliases. */
function ims_csv_key(string $cell, array $aliases = []): string
{
    $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;
    $key = strtolower(trim($cell));
    $key = preg_replace('/\s*\(.*\)\s*$/', '', $key) ?? $key;
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
    $key = trim($key, '_');

    return $aliases[$key] ?? $key;
}

/** Cell -> UTF-8, trimmed, export-safety apostrophe removed. */
function ims_csv_cell(?string $value): string
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

/**
 * Read a CSV into keyed rows. Returns
 * ['fatal' => ?string, 'columns' => [...], 'rows' => [['line' => n, 'data' => [key => value]], ...]].
 * $required lists column keys that must be present in the header.
 */
function ims_csv_read(string $path, array $required, array $aliases = [], int $maxRows = 2000): array
{
    $fail = static fn(string $m): array => ['fatal' => $m, 'columns' => [], 'rows' => []];

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return $fail('The uploaded file could not be read.');
    }

    $firstLine = (string)fgets($handle);
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    rewind($handle);

    $columns = null;
    $rows = [];
    $lineNo = 0;

    while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNo++;
        $cells = array_map('ims_csv_cell', $cells);

        if (!array_filter($cells, static fn($c) => $c !== '')) {
            continue;
        }
        if (str_starts_with($cells[0] ?? '', '#')) {
            continue;
        }

        if ($columns === null) {
            $columns = array_map(static fn($c) => ims_csv_key($c, $aliases), $cells);
            $missing = array_diff($required, $columns);
            if ($missing) {
                fclose($handle);
                return $fail('The file is missing the column(s): ' . implode(', ', $missing)
                    . '. Download the template and keep its header row.');
            }
            continue;
        }

        if (count($rows) >= $maxRows) {
            fclose($handle);
            return $fail("The file has more than {$maxRows} rows. Split it into smaller files.");
        }

        $data = [];
        foreach ($columns as $i => $key) {
            if ($key !== '' && !isset($data[$key])) {
                $data[$key] = $cells[$i] ?? '';
            }
        }
        $rows[] = ['line' => $lineNo, 'data' => $data];
    }
    fclose($handle);

    if ($columns === null) {
        return $fail('The file is empty. Download the template, add your rows below the header row and upload it again.');
    }
    if (!$rows) {
        return $fail('No rows found in the file. Add your rows below the header row.');
    }

    return ['fatal' => null, 'columns' => $columns, 'rows' => $rows];
}

/** YYYY-MM-DD or DD/MM/YYYY (also DD-MM-YYYY, DD.MM.YYYY, YYYY/MM/DD) -> Y-m-d, or null. */
function ims_csv_date(string $value): ?string
{
    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!Y/m/d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $year = (int)$date->format('Y');
            return ($year >= 1950 && $year <= (int)date('Y') + 20) ? $date->format('Y-m-d') : null;
        }
    }

    return null;
}

/** "1,250,000", "UGX 1 250 000.50", "75%" -> float, or null if not a number. */
function ims_csv_number(string $value): ?float
{
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) ?? '';
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }

    return (float)$clean;
}

/** Case-insensitive match against allowed values; returns the canonical value or null. */
function ims_csv_choice(string $value, array $allowed): ?string
{
    foreach ($allowed as $option) {
        if (strcasecmp($option, $value) === 0) {
            return $option;
        }
    }

    return null;
}

/** Check an uploaded CSV ($_FILES entry). Returns an error message or null. */
function ims_csv_upload_error(mixed $file, int $maxBytes = IMS_CSV_MAX_BYTES): ?string
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Choose a CSV file to import.';
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return 'The file could not be uploaded (error ' . (int)($file['error'] ?? 0) . '). Try again or use a smaller file.';
    }
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
        return 'Upload a .csv file. In Excel use File > Save As > CSV UTF-8 (Comma delimited).';
    }
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return 'The file is larger than ' . round($maxBytes / 1048576, 1) . ' MB. Split it into smaller files.';
    }

    return null;
}

/** Remember an import result for display after redirect. */
function ims_csv_set_report(string $key, int $imported, array $errors, string $fileName, array $extra = []): void
{
    $_SESSION['csv_import_report'][$key] = [
        'imported' => $imported,
        'errors'   => array_slice($errors, 0, 50),
        'more'     => max(0, count($errors) - 50),
        'file'     => $fileName,
    ] + $extra;
}

/** Fetch and clear an import result. */
function ims_csv_take_report(string $key): ?array
{
    $report = $_SESSION['csv_import_report'][$key] ?? null;
    unset($_SESSION['csv_import_report'][$key]);

    return is_array($report) ? $report : null;
}
