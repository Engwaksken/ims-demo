<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PROCUREMENT REQUESTS CSV IMPORT
|--------------------------------------------------------------------------
| Used by manage_procurement.php:
|   GET  ?export=procurement_template -> procurement_import_template()
|   POST action=import_procurement    -> procurement_import_handle()
|
| One CSV row per item. Rows with the same request_title form one request
| (same as the New Request form: a title plus several items). Request
| details (description, category, urgency, department, needed_by, project /
| program) only need filling on the request's first row. Requests are created
| as "Submitted" by the person importing. All rows are validated first and
| nothing is saved if any row is invalid; everything is saved in one
| transaction. Officers get one summary email for the whole import.
*/

require_once __DIR__ . '/csv-import.php';

const PROC_IMPORT_COLUMNS = [
    'request_title', 'description', 'category', 'urgency', 'department', 'needed_by',
    'project_code', 'program_code', 'item_name', 'quantity', 'unit', 'unit_price', 'specifications',
];

const PROC_IMPORT_ALIASES = [
    'title' => 'request_title', 'request' => 'request_title', 'item' => 'item_name', 'qty' => 'quantity',
    'price' => 'unit_price', 'estimated_unit_price' => 'unit_price', 'unit_cost' => 'unit_price',
    'spec' => 'specifications', 'specification' => 'specifications', 'needed_by_date' => 'needed_by',
    'project' => 'project_code', 'program' => 'program_code', 'department_name' => 'department',
];

const PROC_IMPORT_URGENCY  = ['Low', 'Medium', 'High'];
const PROC_IMPORT_MAX_ROWS = 1000;

/** Lookup tables used by the template notes and by validation. */
function procurement_import_lookups(mysqli $conn): array
{
    $lookups = ['categories' => [], 'departments' => [], 'projects' => [], 'programs' => []];

    $res = $conn->query('SELECT category_id, category_name FROM procurement_categories ORDER BY category_name');
    while ($res && ($r = $res->fetch_assoc())) {
        $lookups['categories'][mb_strtolower(trim((string)$r['category_name']), 'UTF-8')] = ['id' => (int)$r['category_id'], 'name' => (string)$r['category_name']];
    }

    $res = $conn->query('SELECT department_id, department_name, department_code FROM departments WHERE is_active = 1 ORDER BY sort_order, department_name');
    while ($res && ($r = $res->fetch_assoc())) {
        $row = ['id' => (int)$r['department_id'], 'name' => (string)$r['department_name']];
        $lookups['departments'][mb_strtolower(trim((string)$r['department_name']), 'UTF-8')] = $row;
        if (trim((string)($r['department_code'] ?? '')) !== '') {
            $lookups['departments'][mb_strtolower(trim((string)$r['department_code']), 'UTF-8')] = $row;
        }
    }

    $res = $conn->query('SELECT project_id, project_code, project_name FROM projects');
    while ($res && ($r = $res->fetch_assoc())) {
        foreach (['project_code', 'project_name'] as $col) {
            if (trim((string)($r[$col] ?? '')) !== '') {
                $lookups['projects'][mb_strtolower(trim((string)$r[$col]), 'UTF-8')] = (int)$r['project_id'];
            }
        }
    }

    $res = $conn->query('SELECT id, program_code, program_name FROM programs');
    while ($res && ($r = $res->fetch_assoc())) {
        foreach (['program_code', 'program_name'] as $col) {
            if (trim((string)($r[$col] ?? '')) !== '') {
                $lookups['programs'][mb_strtolower(trim((string)$r[$col]), 'UTF-8')] = (int)$r['id'];
            }
        }
    }

    return $lookups;
}

function procurement_import_template(mysqli $conn): never
{
    $lookups = procurement_import_lookups($conn);
    $categories = array_values(array_unique(array_column($lookups['categories'], 'name')));
    $departments = array_values(array_unique(array_column($lookups['departments'], 'name')));
    $needed = date('Y-m-d', strtotime('+14 days'));

    ims_csv_send_template('procurement_import_template.csv', PROC_IMPORT_COLUMNS, [
        ['Office supplies Q3', 'Stationery for the hub', $categories[0] ?? 'Office Supplies', 'Medium', $departments[0] ?? '', $needed,
         '', '', 'A4 paper (ream)', '20', 'ream', '25000', '80gsm, white'],
        ['Office supplies Q3', '', '', '', '', '', '', '', 'Blue pens (box of 50)', '4', 'box', '30000', 'Same request: leave request columns blank'],
    ], [
        'Rows starting with # are ignored. One row per item; repeat the request_title for each item of the same request.',
        'Required: request_title, item_name. Request details only need filling on its first row.',
        'category: ' . ($categories ? implode('; ', $categories) : 'none set up yet (leave blank)') . '.',
        'department: ' . ($departments ? implode('; ', $departments) : 'none set up yet (leave blank)') . ' (name or code).',
        'urgency: Low, Medium, High (blank = Medium). needed_by: YYYY-MM-DD, today or later.',
        'project_code / program_code: optional, use one of them (code or name). quantity: whole number (blank = 1). unit_price: numbers only.',
    ]);
}

/**
 * Validate rows and group them by request_title. Returns
 * ['requests' => [lower title => request with 'items'], 'errors' => [...]].
 */
function procurement_import_parse(array $rows, array $lookups): array
{
    $requests = [];
    $errors = [];
    $today = date('Y-m-d');

    foreach ($rows as $row) {
        $line = (int)$row['line'];
        $d = $row['data'];
        $rowErrors = [];

        $title = $d['request_title'] ?? '';
        if ($title === '') {
            $errors[] = "Line {$line}: request_title is empty.";
            continue;
        }
        if (mb_strlen($title) > 255) {
            $rowErrors[] = 'request_title is too long (255 characters max)';
        }
        $key = mb_strtolower($title, 'UTF-8');

        if (!isset($requests[$key])) {
            $requests[$key] = [
                'title' => $title, 'description' => '', 'category_id' => null, 'urgency' => 'Medium',
                'department_id' => null, 'department' => '', 'needed_by' => '', 'project_id' => null, 'program_id' => null,
                'items' => [], '_set' => [], '_line' => $line,
            ];
        }
        $r = &$requests[$key];

        // Request details: first row that fills a column wins.
        if (($d['description'] ?? '') !== '' && !isset($r['_set']['description'])) {
            $r['description'] = $d['description'];
            $r['_set']['description'] = true;
        }
        if (($d['category'] ?? '') !== '' && !isset($r['_set']['category'])) {
            $cat = $lookups['categories'][mb_strtolower($d['category'], 'UTF-8')] ?? null;
            if ($cat) {
                $r['category_id'] = $cat['id'];
                $r['_set']['category'] = true;
            } else {
                $rowErrors[] = "category \"{$d['category']}\" does not exist";
            }
        }
        if (($d['urgency'] ?? '') !== '' && !isset($r['_set']['urgency'])) {
            $urgency = ims_csv_choice($d['urgency'], PROC_IMPORT_URGENCY);
            if ($urgency) {
                $r['urgency'] = $urgency;
                $r['_set']['urgency'] = true;
            } else {
                $rowErrors[] = "urgency \"{$d['urgency']}\" must be Low, Medium or High";
            }
        }
        if (($d['department'] ?? '') !== '' && !isset($r['_set']['department'])) {
            $dept = $lookups['departments'][mb_strtolower($d['department'], 'UTF-8')] ?? null;
            if ($dept) {
                $r['department_id'] = $dept['id'];
                $r['department'] = $dept['name'];
                $r['_set']['department'] = true;
            } else {
                $rowErrors[] = "department \"{$d['department']}\" does not exist";
            }
        }
        if (($d['needed_by'] ?? '') !== '' && !isset($r['_set']['needed_by'])) {
            $date = ims_csv_date($d['needed_by']);
            if (!$date) {
                $rowErrors[] = "needed_by \"{$d['needed_by']}\" is not a valid date";
            } elseif ($date < $today) {
                $rowErrors[] = "needed_by {$date} is in the past";
            } else {
                $r['needed_by'] = $date;
                $r['_set']['needed_by'] = true;
            }
        }
        $projectRef = $d['project_code'] ?? '';
        $programRef = $d['program_code'] ?? '';
        if ($projectRef !== '' && $programRef !== '') {
            $rowErrors[] = 'fill in project_code or program_code, not both';
        } elseif ($projectRef !== '' && !isset($r['_set']['link'])) {
            $id = $lookups['projects'][mb_strtolower($projectRef, 'UTF-8')] ?? null;
            if ($id) {
                $r['project_id'] = $id;
                $r['_set']['link'] = true;
            } else {
                $rowErrors[] = "project \"{$projectRef}\" does not exist";
            }
        } elseif ($programRef !== '' && !isset($r['_set']['link'])) {
            $id = $lookups['programs'][mb_strtolower($programRef, 'UTF-8')] ?? null;
            if ($id) {
                $r['program_id'] = $id;
                $r['_set']['link'] = true;
            } else {
                $rowErrors[] = "program \"{$programRef}\" does not exist";
            }
        }

        // The item on this row.
        $itemName = $d['item_name'] ?? '';
        $quantity = 1;
        $price = 0.0;
        if ($itemName === '') {
            $rowErrors[] = 'item_name is empty';
        }
        if (($d['quantity'] ?? '') !== '') {
            $q = ims_csv_number($d['quantity']);
            if ($q === null || $q < 1 || floor($q) !== $q) {
                $rowErrors[] = "quantity \"{$d['quantity']}\" must be a whole number of 1 or more";
            } else {
                $quantity = (int)$q;
            }
        }
        if (($d['unit_price'] ?? '') !== '') {
            $p = ims_csv_number($d['unit_price']);
            if ($p === null || $p < 0) {
                $rowErrors[] = "unit_price \"{$d['unit_price']}\" is not a valid amount";
            } else {
                $price = $p;
            }
        }

        if (!$rowErrors) {
            $r['items'][] = [
                'name'  => $itemName,
                'qty'   => $quantity,
                'unit'  => ($d['unit'] ?? '') !== '' ? $d['unit'] : 'unit',
                'price' => $price,
                'spec'  => $d['specifications'] ?? '',
            ];
        }
        unset($r);

        if ($rowErrors) {
            $errors[] = "Line {$line} ({$title}" . ($itemName !== '' ? " / {$itemName}" : '') . '): ' . implode('; ', $rowErrors) . '.';
        }
    }

    foreach ($requests as &$r) {
        unset($r['_set']);
    }
    unset($r);

    return ['requests' => $requests, 'errors' => $errors];
}

/** Insert all requests and items in one transaction. Returns created [id, title, items, budget]. */
function procurement_import_save(mysqli $conn, array $requests, int $userId): array
{
    $reqStmt = $conn->prepare("INSERT INTO procurement_requests
        (title, description, category_id, estimated_budget, urgency,
         department, department_id, needed_by, project_id, program_id, created_by, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', NOW())");
    $itemStmt = $conn->prepare("INSERT INTO procurement_items
        (procurement_id, item_name, quantity, unit, estimated_unit_price, specifications)
        VALUES (?, ?, ?, ?, ?, ?)");
    if (!$reqStmt || !$itemStmt) {
        throw new RuntimeException('Failed to prepare the procurement import: ' . $conn->error);
    }

    $created = [];
    $conn->begin_transaction();
    try {
        foreach ($requests as $r) {
            $budget = array_sum(array_map(static fn($i) => $i['qty'] * $i['price'], $r['items']));
            $r['needed_by'] = $r['needed_by'] ?: null; // optional DATE: '' is invalid in strict mode
            $reqStmt->bind_param('ssidssisiii',
                $r['title'], $r['description'], $r['category_id'], $budget,
                $r['urgency'], $r['department'], $r['department_id'], $r['needed_by'],
                $r['project_id'], $r['program_id'], $userId);
            if (!$reqStmt->execute()) {
                throw new RuntimeException("Could not save request \"{$r['title']}\": " . $reqStmt->error);
            }
            $procId = (int)$conn->insert_id;

            foreach ($r['items'] as $item) {
                $itemStmt->bind_param('isisds', $procId, $item['name'], $item['qty'], $item['unit'], $item['price'], $item['spec']);
                if (!$itemStmt->execute()) {
                    throw new RuntimeException("Could not save item \"{$item['name']}\": " . $itemStmt->error);
                }
            }

            $created[] = ['id' => $procId, 'title' => $r['title'], 'items' => count($r['items']), 'budget' => $budget, 'urgency' => $r['urgency']];
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    } finally {
        $reqStmt->close();
        $itemStmt->close();
    }

    return $created;
}

/** One summary email listing every imported request. */
function procurement_import_email(array $created, string $requesterName): string
{
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $rows = '';
    $total = 0.0;
    foreach ($created as $c) {
        $total += $c['budget'];
        $rows .= '<tr><td>PROC-' . str_pad((string)$c['id'], 5, '0', STR_PAD_LEFT) . '</td><td>' . $h($c['title'])
            . '</td><td style="text-align:center;">' . (int)$c['items'] . '</td><td>' . $h($c['urgency'])
            . '</td><td style="text-align:right;">' . number_format($c['budget'], 2) . '</td></tr>';
    }

    return '<div style="font-family:sans-serif;max-width:680px;margin:0 auto;color:#374151;">'
        . '<div style="background:#0f2942;color:#fff;padding:24px 28px;border-radius:8px 8px 0 0;">'
        . '<h2 style="margin:0;font-size:20px;">' . count($created) . ' Procurement Request' . (count($created) === 1 ? '' : 's') . ' Submitted</h2>'
        . '<p style="margin:6px 0 0;opacity:.8;font-size:13px;">Imported from a spreadsheet by ' . $h($requesterName) . '</p></div>'
        . '<div style="background:#fff;border:1px solid #e5e7eb;border-top:none;padding:24px 28px;border-radius:0 0 8px 8px;">'
        . '<table border="1" cellpadding="8" cellspacing="0" width="100%" style="border-collapse:collapse;font-size:13px;">'
        . '<thead style="background:#f3f4f6;"><tr><th style="text-align:left;">Ref</th><th style="text-align:left;">Title</th><th>Items</th><th style="text-align:left;">Urgency</th><th style="text-align:right;">Est. Budget (UGX)</th></tr></thead>'
        . '<tbody>' . $rows . '<tr style="background:#f9fafb;font-weight:800;"><td colspan="4" style="text-align:right;">Total</td><td style="text-align:right;">' . number_format($total, 2) . '</td></tr></tbody></table>'
        . '<div style="margin-top:24px;padding:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:13px;">Please log in to the IMS to review and process these requests.</div>'
        . '</div></div>';
}

/** POST action=import_procurement handler: redirects back to the page. */
function procurement_import_handle(mysqli $conn, int $userId, string $userName, string $userEmail, array $officers): never
{
    $back = static function (string $type, string $message): never {
        $_SESSION[$type] = $message;
        header('Location: manage_procurement');
        exit;
    };

    $file = $_FILES['procurement_file'] ?? null;
    if ($error = ims_csv_upload_error($file)) {
        $back('error', $error);
    }
    $fileName = basename((string)$file['name']);

    $read = ims_csv_read((string)$file['tmp_name'], ['request_title', 'item_name'], PROC_IMPORT_ALIASES, PROC_IMPORT_MAX_ROWS);
    if ($read['fatal'] !== null) {
        $back('error', $read['fatal']);
    }

    $parsed = procurement_import_parse($read['rows'], procurement_import_lookups($conn));
    if ($parsed['errors']) {
        ims_csv_set_report('procurement', 0, $parsed['errors'], $fileName);
        $back('error', 'Nothing was imported: ' . count($parsed['errors']) . ' row(s) need fixing. Correct them and upload the file again.');
    }

    try {
        $created = procurement_import_save($conn, $parsed['requests'], $userId);
    } catch (Throwable $e) {
        error_log('Procurement import failed: ' . $e->getMessage());
        $back('error', 'Import cancelled, nothing was saved. ' . $e->getMessage());
    }

    if (function_exists('log_action')) {
        log_action($userId, 'Import Procurement Requests', 'procurement_requests', 0,
            'Imported ' . count($created) . ' requests from ' . $fileName);
    }

    // Notifications must never undo a saved import.
    $notified = 0;
    if (function_exists('sendMail')) {
        $body = procurement_import_email($created, $userName);
        $subject = '[Procurement] ' . count($created) . ' new request' . (count($created) === 1 ? '' : 's') . ' submitted';
        foreach ($officers as $officer) {
            $notified += sendMail((string)$officer['email'], (string)$officer['full_name'], $subject, $body) ? 1 : 0;
        }
        if ($userEmail !== '') {
            sendMail($userEmail, $userName, '[Procurement] Your ' . count($created) . ' request(s) were submitted', $body);
        }
    }

    $items = array_sum(array_column($created, 'items'));
    $back('success', 'Imported ' . count($created) . ' request' . (count($created) === 1 ? '' : 's') . " with {$items} item"
        . ($items === 1 ? '' : 's') . ". {$notified} officer(s) notified.");
}
