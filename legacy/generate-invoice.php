<?php
// Backend - Generate Invoice PDF using Dompdf
session_start();
require_once 'includes/config.php';
foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) { if (is_file($ims_autoload)) { require_once $ims_autoload; break; } }

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if user is admin or member
$is_admin = ims_allowed('invoices.view_all', ['Administrator', 'Operations/Admin'], "Generate invoices for any member's payment (not just their own)");

// Get payment ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Payment ID not provided.";
    header("Location: payment-history");
    exit();
}

$payment_id = intval($_GET['id']);

// Get member_id if not admin
$member_id = null;
if (!$is_admin) {
    $member_query = "SELECT member_id FROM members WHERE user_id = " . (int)$user_id;
    $member_result = $conn->query($member_query);
    if ($member_result->num_rows > 0) {
        $member_id = $member_result->fetch_assoc()['member_id'];
    }
}

// Build query based on role
if ($is_admin) {
    // Admin can generate any invoice
    $query = "SELECT sp.*, 
              m.membership_number, m.membership_start_date,
              u.full_name as member_name,
              u.email as member_email,
              u.phone as member_phone
              FROM subscription_payments sp
              LEFT JOIN members m ON sp.member_id = m.member_id
              LEFT JOIN users u ON m.user_id = u.user_id
              WHERE sp.payment_id = $payment_id";
} else {
    // Members can only generate their own invoices
    $query = "SELECT sp.*, 
              m.membership_number, m.membership_start_date,
              u.full_name as member_name,
              u.email as member_email,
              u.phone as member_phone
              FROM subscription_payments sp
              LEFT JOIN members m ON sp.member_id = m.member_id
              LEFT JOIN users u ON m.user_id = u.user_id
              WHERE sp.payment_id = $payment_id AND sp.member_id = " . (int)$member_id;
}

$result = $conn->query($query);

if (!$result || $result->num_rows == 0) {
    $_SESSION['error'] = "Payment not found or access denied.";
    header("Location: payment-history");
    exit();
}

$payment = $result->fetch_assoc();

// Get organization details from system settings
$org_query = "SELECT setting_key, setting_value FROM system_settings 
              WHERE setting_key LIKE 'org_%' OR setting_key LIKE 'contact_%'";
$org_result = $conn->query($org_query);
$org_settings = [];
while ($row = $org_result->fetch_assoc()) {
    $org_settings[$row['setting_key']] = $row['setting_value'];
}

// Set default organization details if not in settings
$org_name = $org_settings['org_name'] ?? 'Hive Colab';
$org_address = $org_settings['org_address'] ?? 'Kampala, Uganda';
$org_email = $org_settings['contact_email'] ?? 'info@hivecolab.org';
$org_phone = $org_settings['contact_phone'] ?? '+256 700 000 000';
$org_website = $org_settings['org_website'] ?? 'www.hivecolab.org';
$org_tin = $org_settings['org_tin'] ?? 'TIN: 1000000000';

// Calculate subscription period
$plan_map = [
    'Daily' => 1,
    'Weekly' => 7,
    'Monthly' => 30,
    'Quarterly' => 90,
    'Annual' => 365
];

$days = $plan_map[$payment['subscription_plan']] ?? 30;
$start_date = new DateTime($payment['payment_date']);
$end_date = clone $start_date;
$end_date->modify("+{$days} days");

// Format data
$invoice_number = htmlspecialchars($payment['invoice_number']);
$invoice_date = date('d M Y', strtotime($payment['payment_date']));
$member_name = htmlspecialchars($payment['member_name']);
$membership_number = htmlspecialchars($payment['membership_number']);
$member_email = htmlspecialchars($payment['member_email']);
$member_phone = htmlspecialchars($payment['member_phone'] ?? '');
$subscription_plan = htmlspecialchars($payment['subscription_plan']);
$period_start = $start_date->format('d M Y');
$period_end = $end_date->format('d M Y');
$amount = format_currency($payment['amount']);
$payment_method = htmlspecialchars($payment['payment_method'] ?? '');
$transaction_ref = htmlspecialchars($payment['transaction_reference'] ?? '');
$generated_date = date('d M Y, h:i A');

// Status color and text
$status_map = [
    'Verified' => ['color' => '#2ECC71', 'text' => 'PAID'],
    'Pending' => ['color' => '#F39C12', 'text' => 'PENDING'],
    'Rejected' => ['color' => '#E74C3C', 'text' => 'REJECTED']
];
$status_info = $status_map[$payment['payment_status']] ?? ['color' => '#95a5a6', 'text' => strtoupper($payment['payment_status'])];

// Create HTML for invoice
$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice {$invoice_number}</title>
    <style>
        @page {
            margin: 20mm;
        }
        
        body {
            font-family: 'Helvetica', Arial, sans-serif;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px solid #667eea;
        }
        
        .company-name {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .company-details {
            font-size: 11px;
            color: #7f8c8d;
            line-height: 1.8;
        }
        
        .invoice-title {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
            margin: 30px 0;
        }
        
        .details-section {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        
        .invoice-details, .member-details {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        
        .member-details {
            text-align: right;
        }
        
        .detail-row {
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-weight: bold;
            color: #7f8c8d;
            font-size: 11px;
        }
        
        .detail-value {
            color: #2c3e50;
            font-size: 12px;
        }
        
        .bill-to-heading {
            font-weight: bold;
            color: #2c3e50;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        
        .items-table thead {
            background-color: #667eea;
            color: white;
        }
        
        .items-table th {
            padding: 15px;
            text-align: left;
            font-size: 12px;
            font-weight: bold;
        }
        
        .items-table th:last-child {
            text-align: right;
        }
        
        .items-table td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 11px;
        }
        
        .items-table td:last-child {
            text-align: right;
            font-weight: bold;
        }
        
        .totals-section {
            width: 50%;
            margin-left: auto;
            margin-top: 20px;
        }
        
        .totals-row {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }
        
        .totals-label {
            display: table-cell;
            text-align: right;
            padding-right: 20px;
            color: #7f8c8d;
            font-size: 12px;
        }
        
        .totals-value {
            display: table-cell;
            text-align: right;
            font-weight: bold;
            font-size: 12px;
        }
        
        .total-row {
            border-top: 2px solid #667eea;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .total-row .totals-label,
        .total-row .totals-value {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .payment-info {
            margin: 30px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        
        .payment-info-title {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 12px;
        }
        
        .payment-info-item {
            font-size: 11px;
            margin-bottom: 5px;
        }
        
        .status-badge {
            text-align: center;
            padding: 20px;
            margin: 30px 0;
        }
        
        .status-text {
            font-size: 24px;
            font-weight: bold;
            color: {$status_info['color']};
        }
        
        .notes {
            text-align: center;
            font-size: 10px;
            color: #7f8c8d;
            line-height: 1.8;
            margin: 30px 0;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        
        .notes-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .footer {
            text-align: center;
            font-size: 9px;
            color: #95a5a6;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="company-name">{$org_name}</div>
        <div class="company-details">
            {$org_address}<br>
            Email: {$org_email} | Phone: {$org_phone}<br>
            Website: {$org_website}<br>
            {$org_tin}
        </div>
    </div>
    
    <!-- Invoice Title -->
    <div class="invoice-title">INVOICE</div>
    
    <!-- Details Section -->
    <div class="details-section">
        <div class="invoice-details">
            <div class="detail-row">
                <div class="detail-label">Invoice Number:</div>
                <div class="detail-value">{$invoice_number}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Invoice Date:</div>
                <div class="detail-value">{$invoice_date}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Payment Status:</div>
                <div class="detail-value">{$payment['payment_status']}</div>
            </div>
        </div>
        
        <div class="member-details">
            <div class="bill-to-heading">Bill To:</div>
            <div class="detail-value" style="margin-bottom: 5px;">{$member_name}</div>
            <div class="detail-value" style="font-size: 11px; color: #7f8c8d;">Member ID: {$membership_number}</div>
            <div class="detail-value" style="font-size: 11px; color: #7f8c8d;">{$member_email}</div>
HTML;

if ($member_phone) {
    $html .= "<div class=\"detail-value\" style=\"font-size: 11px; color: #7f8c8d;\">{$member_phone}</div>";
}

$html .= <<<HTML
        </div>
    </div>
    
    <!-- Line Items -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Period</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{$subscription_plan} Membership Subscription</td>
                <td>{$period_start} to {$period_end}</td>
                <td>{$amount}</td>
            </tr>
        </tbody>
    </table>
    
    <!-- Totals -->
    <div class="totals-section">
        <div class="totals-row">
            <div class="totals-label">Subtotal:</div>
            <div class="totals-value">{$amount}</div>
        </div>
        <div class="totals-row">
            <div class="totals-label">Tax (0%):</div>
            <div class="totals-value">UGX 0</div>
        </div>
        <div class="totals-row total-row">
            <div class="totals-label">Total Amount:</div>
            <div class="totals-value">{$amount}</div>
        </div>
    </div>
HTML;

// Payment Information
if ($payment_method || $transaction_ref) {
    $html .= '<div class="payment-info">';
    $html .= '<div class="payment-info-title">Payment Information:</div>';
    
    if ($payment_method) {
        $html .= "<div class=\"payment-info-item\"><strong>Payment Method:</strong> {$payment_method}</div>";
    }
    
    if ($transaction_ref) {
        $html .= "<div class=\"payment-info-item\"><strong>Transaction Reference:</strong> {$transaction_ref}</div>";
    }
    
    $html .= '</div>';
}

$html .= <<<HTML
    
    <!-- Status Badge -->
    <div class="status-badge">
        <div class="status-text">{$status_info['text']}</div>
    </div>
    
    <!-- Notes -->
    <div class="notes">
        <div class="notes-title">Notes:</div>
        Thank you for your membership with Hive Colab.<br>
        For any inquiries regarding this invoice, please contact us at {$org_email}.<br>
        This is a computer-generated invoice and does not require a signature.
    </div>
    
    <!-- Footer -->
    <div class="footer">
        Generated on {$generated_date} | {$org_name} | {$org_website}
    </div>
</body>
</html>
HTML;

// Configure Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');

// Initialize Dompdf
$dompdf = new Dompdf($options);

// Load HTML
$dompdf->loadHtml($html);

// Set paper size
$dompdf->setPaper('A4', 'portrait');

// Render PDF
$dompdf->render();

// Log activity
// Prepared via log_action() (invoice_number came from the DB unescaped).
log_action((int)$user_id, 'Generate Invoice', 'subscription_payments', (int)$payment_id, 'Generated invoice ' . ($payment['invoice_number'] ?? '') . ' for payment ID ' . $payment_id);

// Output PDF
$dompdf->stream("Invoice_{$invoice_number}.pdf", ["Attachment" => true]);
exit();
?>