<?php
ob_start();
$page_title = 'Upload Payment Receipt';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

if (empty($_GET['payment_id'])) {
    $_SESSION['error'] = "No payment specified.";
    header("Location: my-subscription");
    exit();
}

$payment_id = intval($_GET['payment_id']);

/* ---------------------------------------------------------------
   Load payment — must belong to this user's member record
--------------------------------------------------------------- */
$payment = $conn->query("
    SELECT  sp.*,
            m.member_id, m.membership_number, m.subscription_plan,
            u.full_name, u.email
    FROM    subscription_payments sp
    JOIN    members m ON m.member_id  = sp.member_id
    JOIN    users   u ON u.user_id    = m.user_id
    WHERE   sp.payment_id = $payment_id
      AND   m.user_id     = $user_id
")->fetch_assoc();

if (!$payment) {
    $_SESSION['error'] = "Payment not found or access denied.";
    header("Location: my-subscription");
    exit();
}

if ($payment['payment_status'] === 'Paid') {
    $_SESSION['error'] = "This payment has already been marked as paid.";
    header("Location: my-subscription");
    exit();
}

/* ---------------------------------------------------------------
   Handle POST upload
--------------------------------------------------------------- */
$success_msg = $_SESSION['success'] ?? '';
$error_msg   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payment_method    = mb_substr(trim((string)($_POST['payment_method']    ?? '')), 0, 100);
    $payment_reference = mb_substr(trim((string)($_POST['payment_reference'] ?? '')), 0, 150);
    $payment_date      = trim((string)($_POST['payment_date']      ?? ''));
    $notes             = trim((string)($_POST['notes']             ?? ''));

    /* Validate */
    $errors = [];
    // Must match the subscription_payments.payment_method enum.
    $allowed_methods = ['Cash', 'Mobile Money', 'Bank Transfer', 'Card', 'Cheque'];
    if (empty($payment_method))    $errors[] = "Payment method is required.";
    elseif (!in_array($payment_method, $allowed_methods, true)) $errors[] = "Please choose a valid payment method.";
    if (empty($payment_reference)) $errors[] = "Payment reference / transaction ID is required.";
    if (empty($payment_date))      $errors[] = "Payment date is required.";
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) $errors[] = "Payment date is invalid.";
    if (empty($_FILES['receipt']['name'])) $errors[] = "Please select a receipt file to upload.";

    /* File validation */
    $receipt_path = null;
    if (empty($errors) && !empty($_FILES['receipt']['name'])) {
        $file      = $_FILES['receipt'];
        $max_size  = 5 * 1024 * 1024; // 5 MB
        $check     = ims_validate_upload($file, ['pdf', 'jpg', 'jpeg', 'png'], $max_size);
        $file_ext  = $check['extension'];

        if (!$check['ok']) {
            $errors[] = $check['error'] . " Allowed: PDF, JPG, PNG (max 5 MB).";
        } else {
            $upload_dir = 'uploads/receipts/' . $user_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $filename     = 'receipt_' . $payment_id . '_' . $check['safe_name'];
            $receipt_path = $upload_dir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $receipt_path)) {
                $errors[] = "Could not save the file. Please try again.";
                $receipt_path = null;
            }
        }
    }

    if (!empty($errors)) {
        $error_msg = implode('<br>', array_map('h', $errors));
    } else {
        /* Update payment record */
        $upd = $conn->prepare("
            UPDATE subscription_payments
            SET    payment_method    = ?,
                   payment_reference = ?,
                   payment_date      = ?,
                   receipt_path      = ?,
                   notes             = ?,
                   payment_status    = 'Pending',
                   updated_at        = NOW()
            WHERE  payment_id = ?
        ");
        $upd->bind_param('sssssi', $payment_method, $payment_reference, $payment_date, $receipt_path, $notes, $payment_id);
        $saved = $upd->execute();
        if (!$saved) {
            error_log("upload-receipt: payment #$payment_id update failed: " . $upd->error);
        }
        $upd->close();
    }

    if (empty($errors) && empty($saved)) {
        $error_msg = 'Your receipt could not be recorded. Please try again or contact support.';
    } elseif (empty($errors)) {

        /* Notify admins */
        $admin_res = $conn->query("SELECT user_id FROM users WHERE role IN ('Administrator','Operations/Admin') LIMIT 10");
        while ($admin = $admin_res->fetch_assoc()) {
            notify_user(
                (int)$admin['user_id'],
                'Receipt uploaded',
                htmlspecialchars($payment['full_name']) . " uploaded a receipt for invoice " .
                htmlspecialchars($payment['invoice_number']) . " — awaiting verification.",
                'info'
            );
        }

        send_notification($user_id, "Receipt uploaded successfully. Awaiting admin verification.", 'success');

        if (function_exists('log_action')) {
            log_action($user_id, 'Upload Receipt', 'subscription_payments', $payment_id,
                "Receipt uploaded for invoice {$payment['invoice_number']}");
        }

        $_SESSION['success'] = "Receipt uploaded successfully! We'll verify your payment shortly.";
        header("Location: my-subscription");
        exit();
    }
}
?>

<style>
/* Upload Receipt: narrow column, progress steps, summary strip and drop zone. */
.ur-wrap { max-width: 780px; margin: 0 auto; width: 100%; }

.ur-steps { display: flex; align-items: center; }
.ur-step { flex: 1; text-align: center; position: relative; }
.ur-step::after { content: ''; position: absolute; top: 16px; left: 50%; width: 100%; height: 2px; background: var(--ink-100); z-index: 0; }
.ur-step:last-child::after { display: none; }
.ur-step-dot { width: 32px; height: 32px; border-radius: 50%; border: 2px solid var(--ink-100); background: var(--surface-card); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: var(--ink-300); margin: 0 auto 6px; position: relative; z-index: 1; }
.ur-step.done .ur-step-dot { background: var(--green-fg); border-color: var(--green-fg); color: var(--ink-0); }
.ur-step.active .ur-step-dot { background: var(--brand-500); border-color: var(--brand-500); color: var(--ink-0); }
.ur-step-lbl { font-size: 11px; font-weight: 600; color: var(--ink-300); }
.ur-step.active .ur-step-lbl { color: var(--brand-600); }
.ur-step.done .ur-step-lbl { color: var(--green-fg); }

.ur-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 20px; }
.ur-item { background: var(--ink-50); border-radius: var(--radius-md); padding: 12px 14px; border: 1px solid var(--ink-100); }
.ur-item-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--ink-300); margin-bottom: 3px; }
.ur-item-val { font-size: 14px; font-weight: 700; color: var(--ink-900); }
.ur-item-val.is-amount { font-size: 18px; color: var(--brand-600); }

.ur-existing-text { flex: 1; }
.ur-existing-text strong { display: block; }

.ur-drop { border: 2px dashed var(--brand-400); border-radius: var(--radius-lg); padding: 32px 20px; text-align: center; background: var(--brand-50); cursor: pointer; transition: background .2s, border-color .2s; position: relative; }
.ur-drop:hover,
.ur-drop.drag-over { border-color: var(--brand-600); background: var(--brand-100); }
.ur-drop input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.ur-drop .dz-icon { font-size: 40px; color: var(--brand-500); margin-bottom: 10px; }
.ur-drop .dz-text { font-size: 14px; font-weight: 600; color: var(--ink-900); margin-bottom: 4px; }
.ur-drop .dz-sub { font-size: 12px; color: var(--ink-300); }

.ur-preview { display: none; align-items: center; gap: 12px; padding: 12px 14px; background: var(--green-bg); border-radius: var(--radius-md); border: 1px solid var(--green-border); margin-top: 12px; }
.ur-preview.show { display: flex; }
.ur-preview-icon { font-size: 26px; color: var(--green-fg); flex-shrink: 0; }
.ur-preview-name { font-size: 13px; font-weight: 600; color: var(--ink-900); }
.ur-preview .btn { margin-left: auto; }

.ur-confirm { display: flex; align-items: flex-start; gap: 10px; background: var(--ink-50); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--ink-100); margin-bottom: 16px; }
.ur-confirm input { margin-top: 2px; accent-color: var(--brand-500); width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }
.ur-confirm label { font-size: 13px; color: var(--ink-500); cursor: pointer; font-weight: 500; margin: 0; }

.ur-actions { display: flex; gap: 10px; flex-wrap: wrap; }
@media (max-width: 580px) { .ur-wrap .form-row { grid-template-columns: 1fr; } }
</style>

<div class="page-stack ur-wrap">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-upload" aria-hidden="true"></i> Upload Payment Receipt</h1>
        <p>Submit proof of payment for invoice <strong><?= htmlspecialchars($payment['invoice_number']) ?></strong>.</p>
    </div>
    <div class="hero-actions">
        <a href="my-subscription" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
    </div>
</div>

<?php if ($success_msg): ?>
<div class="alert alert-success"><i class="fas fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars($success_msg) ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> <div><?= $error_msg ?></div></div>
<?php endif; ?>

<!-- Steps -->
<div class="ur-steps">
    <div class="ur-step done">
        <div class="ur-step-dot"><i class="fas fa-check"></i></div>
        <div class="ur-step-lbl">Payment Created</div>
    </div>
    <div class="ur-step active">
        <div class="ur-step-dot">2</div>
        <div class="ur-step-lbl">Upload Receipt</div>
    </div>
    <div class="ur-step">
        <div class="ur-step-dot">3</div>
        <div class="ur-step-lbl">Admin Verification</div>
    </div>
    <div class="ur-step">
        <div class="ur-step-dot">4</div>
        <div class="ur-step-lbl">Confirmed</div>
    </div>
</div>

<!-- Payment Summary -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Payment Summary</h3>
        <?php
        $badge_cls = match($payment['payment_status']) {
            'Overdue'        => 'status-overdue',
            'Partially Paid' => 'status-in-progress',
            default          => 'status-pending',
        };
        ?>
        <span class="status-pill <?= $badge_cls ?>"><?= htmlspecialchars($payment['payment_status']) ?></span>
    </div>
    <div class="card-body">
        <div class="ur-strip">
            <div class="ur-item">
                <div class="ur-item-lbl">Invoice</div>
                <div class="ur-item-val"><code><?= htmlspecialchars($payment['invoice_number']) ?></code></div>
            </div>
            <div class="ur-item">
                <div class="ur-item-lbl">Amount Due</div>
                <div class="ur-item-val is-amount"><?= format_currency($payment['amount']) ?></div>
            </div>
            <div class="ur-item">
                <div class="ur-item-lbl">Plan</div>
                <div class="ur-item-val"><?= htmlspecialchars($payment['subscription_plan']) ?></div>
            </div>
            <div class="ur-item">
                <div class="ur-item-lbl">Period</div>
                <div class="ur-item-val">
                    <?= date('d M Y', strtotime($payment['payment_period_start'])) ?>
                    &mdash;
                    <?= date('d M Y', strtotime($payment['payment_period_end'])) ?>
                </div>
            </div>
            <div class="ur-item">
                <div class="ur-item-lbl">Member</div>
                <div class="ur-item-val"><?= htmlspecialchars($payment['full_name']) ?></div>
            </div>
            <div class="ur-item">
                <div class="ur-item-lbl">Membership #</div>
                <div class="ur-item-val"><code><?= htmlspecialchars($payment['membership_number']) ?></code></div>
            </div>
        </div>

        <?php if (!empty($payment['receipt_path'])): ?>
        <div class="alert alert-info">
            <i class="fas fa-file-circle-check" aria-hidden="true"></i>
            <div class="ur-existing-text">
                <strong>Receipt already uploaded</strong>
                You can replace it by uploading a new file below.
            </div>
            <a href="<?= htmlspecialchars(ims_upload_url($payment['receipt_path'])) ?>" target="_blank"
               class="btn btn-sm btn-soft">
                <i class="fas fa-eye"></i> View Current
            </a>
        </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            <div>
                After uploading your receipt, our team will verify your payment within
                <strong>1&ndash;2 business days</strong>. You'll receive a notification once confirmed.
            </div>
        </div>
    </div>
</div>


<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-upload" aria-hidden="true"></i> Upload Receipt</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="receipt-form">

            <div class="form-row">
                <div class="form-group">
                    <label class="required">Payment Method</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="">Select Method </option>
                        <?php
                        $methods = [
                            'Mobile'         => ['Mobile Money'],
                            'Bank'           => ['Bank Transfer','Cheque'],
                            'Cash'           => ['Cash'],
                            'Card'           => ['Card'],
                        ];
                        $prev = $payment['payment_method'] ?? '';
                        foreach ($methods as $grp => $opts):
                        ?>
                        <optgroup label="<?= $grp ?>">
                            <?php foreach ($opts as $o): ?>
                            <option value="<?= $o ?>" <?= $prev === $o ? 'selected' : '' ?>><?= $o ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="required">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control" required
                           max="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars($payment['payment_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="required">Transaction / Reference ID</label>
                <input type="text" name="payment_reference" class="form-control" required
                       placeholder="e.g. bank ref, cheque number"
                       value="<?= htmlspecialchars($payment['payment_reference'] ?? '') ?>">
                <div class="form-hint">
                    <i class="fas fa-info-circle"></i>
                    Enter the transaction ID or reference number from your payment confirmation.
                </div>
            </div>

            <!-- File drop zone -->
            <div class="form-group">
                <label class="required">Receipt File</label>
                <div class="ur-drop" id="drop-zone">
                    <input type="file" name="receipt" id="receipt-input"
                           accept=".pdf,.jpg,.jpeg,.png"
                           onchange="previewFile(this)" required>
                    <div class="dz-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <div class="dz-text">Click or drag &amp; drop your receipt here</div>
                    <div class="dz-sub">PDF, JPG or PNG &mdash; max 5 MB</div>
                </div>
                <div class="ur-preview" id="file-preview">
                    <i class="fas fa-file-circle-check ur-preview-icon"></i>
                    <div>
                        <div class="ur-preview-name" id="fp-name">&mdash;</div>
                        <div class="cell-muted" id="fp-size">&mdash;</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger" onclick="clearFile()" title="Remove file">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="form-hint">
                    <i class="fas fa-lock"></i>
                    Your receipt is stored securely and only visible to authorised staff.
                </div>
            </div>

            <div class="form-group">
                <label>Additional Notes</label>
                <textarea name="notes" class="form-control"
                          placeholder="Any extra details about this payment (optional)..."><?= htmlspecialchars($payment['notes'] ?? '') ?></textarea>
            </div>

            <!-- Confirmation checkbox -->
            <div class="ur-confirm">
                <input type="checkbox" id="confirm-chk" required>
                <label for="confirm-chk">
                    I confirm that the payment information and receipt I am uploading are accurate
                    and genuine. I understand that submitting false documents may result in
                    suspension of my membership.
                </label>
            </div>

            <!-- Actions -->
            <div class="ur-actions">
                <button type="submit" class="btn btn-primary" id="submit-btn">
                    <i class="fas fa-upload"></i> Upload Receipt
                </button>
                <a href="my-subscription" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Subscription
                </a>
            </div>
        </form>
    </div>
</div>

</div><!-- /.page-stack -->

<script>
/* -- Drag-and-drop styling ------------------- */
const dz = document.getElementById('drop-zone');
['dragenter','dragover'].forEach(e =>
    dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.add('drag-over'); }));
['dragleave','drop'].forEach(e =>
    dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.remove('drag-over'); }));
dz.addEventListener('drop', ev => {
    const files = ev.dataTransfer.files;
    if (files.length) {
        document.getElementById('receipt-input').files = files;
        previewFile(document.getElementById('receipt-input'));
    }
});

/* -- File preview ---------------------------- */
function previewFile(input) {
    const preview = document.getElementById('file-preview');
    const file    = input.files[0];
    if (!file) { clearFile(); return; }

    const ext  = file.name.split('.').pop().toLowerCase();
    const ok   = ['pdf','jpg','jpeg','png'].includes(ext);
    const size = file.size <= 5 * 1024 * 1024;

    if (!ok)   { alert('Invalid file type. Please upload PDF, JPG or PNG.'); clearFile(); return; }
    if (!size) { alert('File is too large. Maximum size is 5 MB.');          clearFile(); return; }

    document.getElementById('fp-name').textContent = file.name;
    document.getElementById('fp-size').textContent = (file.size / 1024).toFixed(1) + ' KB';
    preview.classList.add('show');

    /* Swap drop-zone icon */
    dz.querySelector('.dz-icon i').className = 'fas fa-check-circle';
    dz.querySelector('.dz-icon i').style.color = 'var(--green-fg)';
    dz.querySelector('.dz-text').textContent = 'File selected';
}

function clearFile() {
    const input = document.getElementById('receipt-input');
    input.value = '';
    document.getElementById('file-preview').classList.remove('show');
    dz.querySelector('.dz-icon i').className = 'fas fa-cloud-upload-alt';
    dz.querySelector('.dz-icon i').style.color = '';
    dz.querySelector('.dz-text').textContent = 'Click or drag & drop your receipt here';
}

/* -- Submit guard ---------------------------- */
document.getElementById('receipt-form').addEventListener('submit', function(e) {
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    /* Re-enable if validation fails */
    setTimeout(() => { if (btn.disabled) btn.disabled = false; }, 8000);
});
</script>

<?php include 'includes/footer.php'; ?>