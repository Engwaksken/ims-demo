<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (empty($_SESSION['user_id']) || !ims_allowed('vouchers.manage', ['Administrator', 'Finance', 'Accountant'], 'Create, edit, delete and import payment vouchers')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Access denied.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'voucher_upload_template_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('Unable to create CSV output.');
}

fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
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

$today = date('Y-m-d');

$rows = [
    ['1', 'UGX1', '', $today, 'Acme Supplies Ltd', 'UGX', '3750', '', 'ACC-001', 'PRJ-100', 'JNL-2026-01', 'CHQ-0456', 'Office stationery', '10', '15000', 'Admin'],
    ['1', 'UGX1', '', $today, 'Acme Supplies Ltd', 'UGX', '3750', '', 'ACC-001', 'PRJ-100', 'JNL-2026-01', 'CHQ-0456', 'Printer toner', '2', '120000', 'Admin'],
    ['2', 'Euro Asknet', '', $today, 'Nordic Media GmbH', 'EUR', '', '253.33', 'ACC-045', 'PRJ-200', '', '', 'Design consultancy', '1', '950', 'Comms'],
];

foreach ($rows as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
