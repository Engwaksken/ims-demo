<?php

$page_title = 'Make Payment';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get payment ID
if (!isset($_GET['payment_id']) || empty($_GET['payment_id'])) {
    $_SESSION['error'] = "Payment ID not provided.";
   // header("Location: my-subscription");
    exit();
}

$payment_id = intval($_GET['payment_id']);

// Get member details
$member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    $_SESSION['error'] = "Member profile not found.";
    header("Location: my-subscription");
    exit();
}

$member_id = $member_result->fetch_assoc()['member_id'];

// Fetch payment details
$query = "SELECT sp.*, m.membership_number, u.full_name as member_name 
          FROM subscription_payments sp
          LEFT JOIN members m ON sp.member_id = m.member_id
          LEFT JOIN users u ON m.user_id = u.user_id
          WHERE sp.payment_id = $payment_id AND sp.member_id = $member_id";

$result = $conn->query($query);

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Payment not found or access denied.";
    header("Location: my-subscription");
    exit();
}

$payment = $result->fetch_assoc();

// Check if payment is already completed
if ($payment['payment_status'] == 'Paid') {
    $_SESSION['info'] = "This payment has already been completed.";
    header("Location: payment-history");
    exit();
}

// Hive Colab bank details (hardcoded - you can move to config)
$bank_details = [
    'bank_name' => 'Stanbic Bank Uganda',
    'account_name' => 'Hive Colab Limited',
    'account_number' => '9030012345678',
    'branch' => 'Kampala Road Branch',
    'swift_code' => 'SBICUGKX',
    'currency' => 'UGX',
    'mobile_money' => [
        'mtn' => '0772 123 456',
        'airtel' => '0756 789 012'
    ]
];
?>

<style>
/* Make Payment: two-column layout, detail rows, bank details block and receipt drop zone. */
.mp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
.mp-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.mp-rows { display: grid; gap: 10px; }
.mp-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 15px; background: var(--ink-50); border-radius: var(--radius-md); }
.mp-row-label { font-size: 14px; color: var(--ink-300); font-weight: 500; }
.mp-row-value { font-size: 15px; color: var(--ink-900); font-weight: 600; text-align: right; }
.mp-steps ol { margin: 8px 0 0; padding-left: 20px; }
.mp-steps li { margin-bottom: 6px; }
.mp-bank { background: var(--brand-50); border: 1px solid var(--brand-200); border-radius: var(--radius-lg); padding: 6px 20px; }
.mp-bank-item { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 0; border-bottom: 1px solid var(--brand-100); }
.mp-bank-item:last-child { border-bottom: none; }
.mp-bank-label { font-size: 14px; color: var(--ink-300); font-weight: 500; }
.mp-bank-value { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 700; color: var(--ink-900); text-align: right; font-variant-numeric: tabular-nums; }
.mp-mm { background: var(--ink-50); padding: 20px; border-radius: var(--radius-lg); margin-top: 20px; }
.mp-mm-options { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px; }
.mp-mm-option { background: var(--surface-card); padding: 20px; border-radius: var(--radius-md); text-align: center; border: 1px solid var(--ink-100); }
.mp-mm-logo { height: 30px; margin-bottom: 10px; }
.mp-mm-number { font-size: 20px; font-weight: 700; color: var(--brand-600); margin-bottom: 10px; }
.mp-upload { border: 2px dashed var(--brand-400); border-radius: var(--radius-lg); padding: 40px; text-align: center; margin-bottom: 20px; transition: background .2s, border-color .2s; }
.mp-upload:hover { border-color: var(--brand-500); background: var(--ink-50); }
.mp-upload.dragover { background: var(--brand-50); border-color: var(--brand-600); }
.mp-upload input[type="file"] { display: none; }
.mp-upload-icon { font-size: 56px; color: var(--brand-500); margin-bottom: 16px; }
.mp-upload h4 { color: var(--ink-900); margin-bottom: 10px; }
.mp-upload p { color: var(--ink-300); margin-bottom: 20px; }
.mp-file-preview { display: none; background: var(--ink-50); padding: 20px; border-radius: var(--radius-md); margin-bottom: 20px; }
.mp-file-preview.show { display: block; }
.mp-file-info { display: flex; align-items: center; gap: 15px; }
.mp-file-info > i { font-size: 40px; color: var(--brand-500); }
.mp-file-details { flex: 1; min-width: 0; }
.mp-file-name { font-weight: 600; color: var(--ink-900); margin-bottom: 4px; }
@media (max-width: 968px) {
    .mp-grid, .mp-mm-options { grid-template-columns: 1fr; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-credit-card" aria-hidden="true"></i> Make Payment</h1>
        <p>Complete your subscription payment using bank transfer.</p>
    </div>
    <div class="hero-actions">
        <a href="my-subscription" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
    </div>
</div>

<!-- Amount summary -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Amount to Pay</div>
            <div class="stat-value"><?php echo format_currency($payment['amount']); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-file-invoice" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Invoice</div>
            <div class="stat-value"><?php echo htmlspecialchars($payment['invoice_number']); ?></div>
        </div>
    </div>
</div>

<!-- Payment Content -->
<div class="mp-grid">
    <!-- Left Column - Payment Details -->
    <div class="mp-col">
        <!-- Payment Information -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-info-circle" aria-hidden="true"></i> Payment Information</h3></div>
            <div class="card-body">
                <div class="mp-rows">
                    <div class="mp-row">
                        <span class="mp-row-label">Subscription Plan:</span>
                        <span class="mp-row-value"><?php echo htmlspecialchars($payment['subscription_plan']); ?></span>
                    </div>

                    <div class="mp-row">
                        <span class="mp-row-label">Period:</span>
                        <span class="mp-row-value">
                            <?php echo date('d M', strtotime($payment['payment_period_start'])); ?> -
                            <?php echo date('d M Y', strtotime($payment['payment_period_end'])); ?>
                        </span>
                    </div>

                    <div class="mp-row">
                        <span class="mp-row-label">Member:</span>
                        <span class="mp-row-value"><?php echo htmlspecialchars($payment['member_name']); ?></span>
                    </div>

                    <div class="mp-row">
                        <span class="mp-row-label">Membership #:</span>
                        <span class="mp-row-value"><code><?php echo htmlspecialchars($payment['membership_number']); ?></code></span>
                    </div>

                    <div class="mp-row">
                        <span class="mp-row-label">Status:</span>
                        <span class="mp-row-value">
                            <span class="badge badge-warning"><?php echo $payment['payment_status']; ?></span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Instructions -->
        <div class="callout note mp-steps">
            <i class="fas fa-lightbulb" aria-hidden="true"></i>
            <div>
                <strong>Payment Instructions</strong>
                <ol>
                    <li>Use the bank details below to make your payment via bank transfer</li>
                   <!-- <li>Alternatively, use Mobile Money (MTN or Airtel)</li> -->
                    <li>Use your <strong>Invoice Number (<?php echo $payment['invoice_number']; ?>)</strong> as payment reference</li>
                    <li>After making payment, upload your receipt using the form on the right</li>
                    <li>Our team will verify your payment within 24 hours</li>
                    <li>You will receive a confirmation once payment is verified</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Right Column - Bank Details & Upload -->
    <div class="mp-col">
        <!-- Bank Details -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-university" aria-hidden="true"></i> Bank Transfer Details</h3></div>
            <div class="card-body">
                <div class="mp-bank">
                    <div class="mp-bank-item">
                        <span class="mp-bank-label">Bank Name:</span>
                        <span class="mp-bank-value">
                            <?php echo $bank_details['bank_name']; ?>
                            <button class="btn btn-sm btn-soft" onclick="copyToClipboard('<?php echo $bank_details['bank_name']; ?>', this)">
                                <i class="fas fa-copy"></i>
                            </button>
                        </span>
                    </div>

                    <div class="mp-bank-item">
                        <span class="mp-bank-label">Account Name:</span>
                        <span class="mp-bank-value">
                            <?php echo $bank_details['account_name']; ?>
                            <button class="btn btn-sm btn-soft" onclick="copyToClipboard('<?php echo $bank_details['account_name']; ?>', this)">
                                <i class="fas fa-copy"></i>
                            </button>
                        </span>
                    </div>

                    <div class="mp-bank-item">
                        <span class="mp-bank-label">Account Number:</span>
                        <span class="mp-bank-value">
                            <?php echo $bank_details['account_number']; ?>
                            <button class="btn btn-sm btn-soft" onclick="copyToClipboard('<?php echo $bank_details['account_number']; ?>', this)">
                                <i class="fas fa-copy"></i>
                            </button>
                        </span>
                    </div>

                    <div class="mp-bank-item">
                        <span class="mp-bank-label">Branch:</span>
                        <span class="mp-bank-value"><?php echo $bank_details['branch']; ?></span>
                    </div>

                    <div class="mp-bank-item">
                        <span class="mp-bank-label">SWIFT Code:</span>
                        <span class="mp-bank-value">
                            <?php echo $bank_details['swift_code']; ?>
                            <button class="btn btn-sm btn-soft" onclick="copyToClipboard('<?php echo $bank_details['swift_code']; ?>', this)">
                                <i class="fas fa-copy"></i>
                            </button>
                        </span>
                    </div>

                    <div class="mp-bank-item">
                        <span class="mp-bank-label">Currency:</span>
                        <span class="mp-bank-value"><?php echo $bank_details['currency']; ?></span>
                    </div>
                </div>

                <!-- Mobile Money (disabled) -->
               <!-- <div class="mp-mm">
                    <h4><i class="fas fa-mobile-alt"></i> Mobile Money</h4>
                    <div class="mp-mm-options">
                        <div class="mp-mm-option">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/MTN_Logo.svg/320px-MTN_Logo.svg.png"
                                 alt="MTN" class="mp-mm-logo">
                            <div class="mp-mm-number"><?php echo $bank_details['mobile_money']['mtn']; ?></div>
                            <button class="btn btn-sm btn-soft"
                                    onclick="copyToClipboard('<?php echo str_replace(' ', '', $bank_details['mobile_money']['mtn']); ?>', this)">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>

                        <div class="mp-mm-option">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/54/Airtel_Networks_Limited_Logo.svg/320px-Airtel_Networks_Limited_Logo.svg.png"
                                 alt="Airtel" class="mp-mm-logo">
                            <div class="mp-mm-number"><?php echo $bank_details['mobile_money']['airtel']; ?></div>
                            <button class="btn btn-sm btn-soft"
                                    onclick="copyToClipboard('<?php echo str_replace(' ', '', $bank_details['mobile_money']['airtel']); ?>', this)">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </div>
                </div> -->
            </div>
        </div>

        <!-- Upload Receipt (disabled) -->
      <!--  <div class="card">
            <div class="card-header"><h3><i class="fas fa-upload"></i> Upload Payment Receipt</h3></div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Upload your payment receipt or transaction confirmation. Accepted formats: PDF, JPG, PNG (Max 5MB)
                </div>

                <form method="POST" action="includes/process-receipt-upload.php" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="payment_id" value="<?php echo $payment_id; ?>">
                    <input type="hidden" name="invoice_number" value="<?php echo $payment['invoice_number']; ?>">

                    <div class="mp-upload" id="uploadSection">
                        <div class="mp-upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <h4>Upload Your Receipt</h4>
                        <p>Drag and drop your file here or click to browse</p>
                        <input type="file" name="receipt_file" id="receiptFile" accept=".pdf,.jpg,.jpeg,.png" required>
                        <label for="receiptFile" class="btn btn-primary">
                            <i class="fas fa-folder-open"></i> Choose File
                        </label>
                    </div>

                    <div class="mp-file-preview" id="filePreview">
                        <div class="mp-file-info">
                            <i class="fas fa-file-invoice"></i>
                            <div class="mp-file-details">
                                <div class="mp-file-name" id="fileName"></div>
                                <div class="cell-muted" id="fileSize"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-danger" onclick="removeFile()">
                                <i class="fas fa-times"></i> Remove
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control" required>
                            <option value="">Select Payment Method</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Mobile Money">Mobile Money - MTN</option>
                            <option value="Mobile Money">Mobile Money - Airtel</option>
                            <option value="Cash">Cash Deposit</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="payment_reference">Transaction Reference/ID</label>
                        <input type="text" name="payment_reference" id="payment_reference"
                               class="form-control" placeholder="e.g., TXN123456789" required>
                        <small class="form-hint">Enter the transaction ID from your bank receipt</small>
                    </div>

                    <div class="form-group">
                        <label for="notes">Additional Notes (Optional)</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3"
                                  placeholder="Any additional information about your payment..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-success btn-block">
                        <i class="fas fa-paper-plane"></i> Submit Receipt for Verification
                    </button>
                </form>
            </div>
        </div> -->
    </div>
</div>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<script>
// File upload handling
const uploadSection = document.getElementById('uploadSection');
const receiptFile = document.getElementById('receiptFile');
const filePreview = document.getElementById('filePreview');
const fileName = document.getElementById('fileName');
const fileSize = document.getElementById('fileSize');

// Handle file selection
receiptFile.addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        displayFilePreview(this.files[0]);
    }
});

// Drag and drop
uploadSection.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
});

uploadSection.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
});

uploadSection.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        receiptFile.files = files;
        displayFilePreview(files[0]);
    }
});

function displayFilePreview(file) {
    // Validate file size (5MB max)
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
        alert('File size exceeds 5MB limit. Please choose a smaller file.');
        receiptFile.value = '';
        return;
    }
    
    // Display file info
    fileName.textContent = file.name;
    fileSize.textContent = formatFileSize(file.size);
    filePreview.classList.add('show');
}

function removeFile() {
    receiptFile.value = '';
    filePreview.classList.remove('show');
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

// Copy to clipboard
function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(function() {
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(function() {
            button.innerHTML = originalHTML;
        }, 2000);
    }).catch(function(err) {
        alert('Failed to copy: ' + err);
    });
}

// Form validation
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    if (!receiptFile.files || receiptFile.files.length === 0) {
        e.preventDefault();
        alert('Please select a receipt file to upload.');
        return false;
    }
    
    const paymentMethod = document.getElementById('payment_method').value;
    const paymentReference = document.getElementById('payment_reference').value;
    
    if (!paymentMethod || !paymentReference) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    return true;
});
</script>