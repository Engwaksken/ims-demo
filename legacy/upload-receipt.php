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
:root {
    --orange:  #ff6b35;
    --orange-d:#e85a24;
    --green:   #2ECC71;
    --text:    #1e293b;
    --sub:     #475569;
    --muted:   #94a3b8;
    --border:  #e2e8f0;
    --bg:      #f8fafc;
    --surface: #ffffff;
    --r:       12px;
    --sh:      0 2px 8px rgba(0,0,0,.08), 0 4px 20px rgba(0,0,0,.07);
}

/* -- Layout ----------------------------------- */
.ur-wrap { max-width:780px; margin:0 auto; padding-bottom:60px; }

/* -- Header ----------------------------------- */
.ur-hdr {
    background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
    color:#fff; padding:28px 32px; border-radius:var(--r); margin-bottom:24px;
    box-shadow: 0 6px 24px rgba(255,107,53,.35);
}
.ur-hdr h1 { font-size:22px; font-weight:800; margin:0 0 4px;
    display:flex; align-items:center; gap:10px; }
.ur-hdr p  { margin:0; font-size:13px; opacity:.85; }

/* -- Alerts ----------------------------------- */
.alert {
    display:flex; align-items:flex-start; gap:10px; padding:13px 16px;
    border-radius:9px; margin-bottom:18px; font-size:13.5px;
}
.a-ok  { background:#d4edda; color:#155724; border-left:4px solid #28a745; }
.a-err { background:#f8d7da; color:#721c24; border-left:4px solid #dc3545; }
.a-inf { background:#d1ecf1; color:#0c5460; border-left:4px solid #17a2b8; }
.a-warn{ background:#fff3cd; color:#856404; border-left:4px solid #ffc107; }

/* -- Cards ------------------------------------ */
.card {
    background:var(--surface); border-radius:var(--r);
    box-shadow:var(--sh); border:1px solid var(--border); margin-bottom:20px; overflow:hidden;
}
.card-hdr {
    padding:14px 22px; border-bottom:1px solid var(--border); background:#fafbfd;
    display:flex; align-items:center; gap:9px;
}
.card-hdr h3 { margin:0; font-size:14.5px; font-weight:700; color:var(--text);
    display:flex; align-items:center; gap:8px; }
.card-hdr h3 i { color:var(--orange); }
.card-body { padding:22px; }

/* -- Payment summary strip --------------------- */
.pay-strip {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    gap:10px; margin-bottom:20px;
}
.pay-item { background:var(--bg); border-radius:9px; padding:12px 14px; border:1px solid var(--border); }
.pay-item .pi-lbl { font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.5px; color:var(--muted); margin-bottom:3px; }
.pay-item .pi-val { font-size:14px; font-weight:700; color:var(--text); }
.pay-item .pi-val.amount { font-size:18px; color:var(--orange); }

/* -- Status badge ----------------------------- */
.pay-badge {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 11px; border-radius:20px; font-size:11.5px; font-weight:700;
}
.pb-pending  { background:#fff3cd; color:#856404; border:1px solid #fcd34d; }
.pb-overdue  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.pb-partial  { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }

/* -- Form ------------------------------------- */
.fg { margin-bottom:16px; }
.fg label {
    display:block; font-size:12px; font-weight:700; color:var(--sub);
    text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px;
}
.fg label .req { color:#dc2626; margin-left:2px; }
.fc {
    width:100%; padding:10px 13px; border:1.5px solid var(--border);
    border-radius:8px; font-size:13.5px; color:var(--text); background:#fff;
    font-family:inherit; transition:border-color .17s, box-shadow .17s;
    box-sizing:border-box;
}
.fc:focus { border-color:var(--orange); outline:none;
    box-shadow:0 0 0 3px rgba(255,107,53,.12); }
select.fc { cursor:pointer; }
textarea.fc { resize:vertical; min-height:80px; }
.hint { font-size:11.5px; color:var(--muted); margin-top:4px;
    display:flex; align-items:center; gap:4px; }

.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:580px){ .form-row { grid-template-columns:1fr; } }

/* -- File drop zone --------------------------- */
.drop-zone {
    border:2px dashed var(--orange);
    border-radius:10px; padding:32px 20px; text-align:center;
    background:#fff7f4; cursor:pointer; transition:all .2s; position:relative;
}
.drop-zone:hover, .drop-zone.drag-over {
    background:#fff0ea; border-color:var(--orange-d);
    box-shadow:0 0 0 4px rgba(255,107,53,.1);
}
.drop-zone input[type="file"] {
    position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
}
.dz-icon { font-size:40px; color:var(--orange); margin-bottom:10px; }
.dz-text { font-size:14px; font-weight:600; color:var(--text); margin-bottom:4px; }
.dz-sub  { font-size:12px; color:var(--muted); }

/* Preview */
.file-preview {
    display:none; align-items:center; gap:12px;
    padding:12px 14px; background:#f0fdf4; border-radius:8px;
    border:1.5px solid #86efac; margin-top:12px;
}
.file-preview.show { display:flex; }
.fp-icon { font-size:26px; color:#16a34a; flex-shrink:0; }
.fp-name { font-size:13px; font-weight:600; color:var(--text); }
.fp-size { font-size:11.5px; color:var(--muted); }
.fp-clear {
    margin-left:auto; background:#fee2e2; border:none; color:#dc2626;
    width:26px; height:26px; border-radius:6px; cursor:pointer;
    display:flex; align-items:center; justify-content:center; font-size:12px;
    flex-shrink:0;
}

/* -- Buttons ----------------------------------- */
.btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:10px 20px; border:none; border-radius:8px;
    font-size:13.5px; font-weight:600; cursor:pointer;
    text-decoration:none; transition:all .17s; font-family:inherit;
}
.btn-orange { background:var(--orange); color:#fff; }
.btn-orange:hover { background:var(--orange-d); }
.btn-ghost  { background:var(--bg); color:var(--sub); border:1.5px solid var(--border); }
.btn-ghost:hover { background:#e9eef5; }
.btn-full   { width:100%; justify-content:center; }

/* -- Steps indicator --------------------------- */
.steps {
    display:flex; align-items:center; gap:0; margin-bottom:22px;
}
.step {
    flex:1; text-align:center; position:relative;
}
.step::after {
    content:''; position:absolute; top:16px; left:50%; width:100%; height:2px;
    background:var(--border); z-index:0;
}
.step:last-child::after { display:none; }
.step-dot {
    width:32px; height:32px; border-radius:50%; border:2px solid var(--border);
    background:#fff; display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:700; color:var(--muted); margin:0 auto 6px;
    position:relative; z-index:1; transition:all .2s;
}
.step.done .step-dot  { background:var(--green); border-color:var(--green); color:#fff; }
.step.active .step-dot{ background:var(--orange); border-color:var(--orange); color:#fff; }
.step-lbl { font-size:11px; font-weight:600; color:var(--muted); }
.step.active .step-lbl { color:var(--orange); }
.step.done   .step-lbl { color:var(--green); }

/* -- Existing receipt -------------------------- */
.existing-receipt {
    display:flex; align-items:center; gap:12px;
    padding:13px 16px; background:#eff6ff; border-radius:9px;
    border:1.5px solid #bfdbfe; margin-bottom:14px;
}
.er-icon { font-size:24px; color:#2563eb; flex-shrink:0; }
</style>

<div class="ur-wrap">

<!-- -- Header ------------------------------------- -->
<div class="ur-hdr">
    <h1><i class="fas fa-upload"></i> Upload Payment Receipt</h1>
    <p>Submit proof of payment for invoice <strong><?= htmlspecialchars($payment['invoice_number']) ?></strong></p>
</div>

<?php if ($success_msg): ?>
<div class="alert a-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert a-err"><i class="fas fa-exclamation-circle"></i> <?= $error_msg ?></div>
<?php endif; ?>

<!-- -- Steps --------------------------------------- -->
<div class="steps">
    <div class="step done">
        <div class="step-dot"><i class="fas fa-check"></i></div>
        <div class="step-lbl">Payment Created</div>
    </div>
    <div class="step active">
        <div class="step-dot">2</div>
        <div class="step-lbl">Upload Receipt</div>
    </div>
    <div class="step">
        <div class="step-dot">3</div>
        <div class="step-lbl">Admin Verification</div>
    </div>
    <div class="step">
        <div class="step-dot">4</div>
        <div class="step-lbl">Confirmed</div>
    </div>
</div>

<!-- -- Payment Summary ----------------------------- -->
<div class="card">
    <div class="card-hdr">
        <h3><i class="fas fa-file-invoice-dollar"></i> Payment Summary</h3>
        <?php
        $badge_cls = match($payment['payment_status']) {
            'Overdue'        => 'pb-overdue',
            'Partially Paid' => 'pb-partial',
            default          => 'pb-pending',
        };
        ?>
        <span class="pay-badge <?= $badge_cls ?>"><?= htmlspecialchars($payment['payment_status']) ?></span>
    </div>
    <div class="card-body">
        <div class="pay-strip">
            <div class="pay-item">
                <div class="pi-lbl">Invoice</div>
                <div class="pi-val"><code><?= htmlspecialchars($payment['invoice_number']) ?></code></div>
            </div>
            <div class="pay-item">
                <div class="pi-lbl">Amount Due</div>
                <div class="pi-val amount"><?= format_currency($payment['amount']) ?></div>
            </div>
            <div class="pay-item">
                <div class="pi-lbl">Plan</div>
                <div class="pi-val"><?= htmlspecialchars($payment['subscription_plan']) ?></div>
            </div>
            <div class="pay-item">
                <div class="pi-lbl">Period</div>
                <div class="pi-val">
                    <?= date('d M Y', strtotime($payment['payment_period_start'])) ?>
                    &mdash;
                    <?= date('d M Y', strtotime($payment['payment_period_end'])) ?>
                </div>
            </div>
            <div class="pay-item">
                <div class="pi-lbl">Member</div>
                <div class="pi-val"><?= htmlspecialchars($payment['full_name']) ?></div>
            </div>
            <div class="pay-item">
                <div class="pi-lbl">Membership #</div>
                <div class="pi-val"><code><?= htmlspecialchars($payment['membership_number']) ?></code></div>
            </div>
        </div>

        <?php if (!empty($payment['receipt_path'])): ?>
        <div class="existing-receipt">
            <i class="fas fa-file-circle-check er-icon"></i>
            <div style="flex:1;">
                <div style="font-size:13px;font-weight:700;color:#1d4ed8;">Receipt already uploaded</div>
                <div style="font-size:12px;color:var(--muted);">
                    You can replace it by uploading a new file below.
                </div>
            </div>
            <a href="<?= htmlspecialchars(ims_upload_url($payment['receipt_path'])) ?>" target="_blank"
               class="btn btn-ghost" style="padding:6px 14px;font-size:12px;">
                <i class="fas fa-eye"></i> View Current
            </a>
        </div>
        <?php endif; ?>

        <div class="alert a-inf" style="margin-bottom:0;">
            <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
            <div>
                After uploading your receipt, our team will verify your payment within
                <strong>1–2 business days</strong>. You'll receive a notification once confirmed.
            </div>
        </div>
    </div>
</div>


<div class="card">
    <div class="card-hdr">
        <h3><i class="fas fa-upload"></i> Upload Receipt</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="receipt-form">

            <div class="form-row">
                <div class="fg">
                    <label>Payment Method <span class="req">*</span></label>
                    <select name="payment_method" class="fc" required>
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

                <div class="fg">
                    <label>Payment Date <span class="req">*</span></label>
                    <input type="date" name="payment_date" class="fc" required
                           max="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars($payment['payment_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>

            <div class="fg">
                <label>Transaction / Reference ID <span class="req">*</span></label>
                <input type="text" name="payment_reference" class="fc" required
                       placeholder="e.g. bank ref, cheque number"
                       value="<?= htmlspecialchars($payment['payment_reference'] ?? '') ?>">
                <div class="hint">
                    <i class="fas fa-info-circle"></i>
                    Enter the transaction ID or reference number from your payment confirmation.
                </div>
            </div>

            <!-- File drop zone -->
            <div class="fg">
                <label>Receipt File <span class="req">*</span></label>
                <div class="drop-zone" id="drop-zone">
                    <input type="file" name="receipt" id="receipt-input"
                           accept=".pdf,.jpg,.jpeg,.png"
                           onchange="previewFile(this)" required>
                    <div class="dz-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <div class="dz-text">Click or drag &amp; drop your receipt here</div>
                    <div class="dz-sub">PDF, JPG or PNG &mdash; max 5 MB</div>
                </div>
                <div class="file-preview" id="file-preview">
                    <i class="fas fa-file-circle-check fp-icon"></i>
                    <div>
                        <div class="fp-name" id="fp-name">—</div>
                        <div class="fp-size" id="fp-size">—</div>
                    </div>
                    <button type="button" class="fp-clear" onclick="clearFile()" title="Remove file">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="hint">
                    <i class="fas fa-lock"></i>
                    Your receipt is stored securely and only visible to authorised staff.
                </div>
            </div>

            <div class="fg">
                <label>Additional Notes</label>
                <textarea name="notes" class="fc"
                          placeholder="Any extra details about this payment (optional)..."><?= htmlspecialchars($payment['notes'] ?? '') ?></textarea>
            </div>

            <!-- Confirmation checkbox -->
            <div class="fg" style="display:flex;align-items:flex-start;gap:10px;background:var(--bg);
                 padding:14px;border-radius:9px;border:1px solid var(--border);">
                <input type="checkbox" id="confirm-chk" style="margin-top:2px;accent-color:var(--orange);
                       width:16px;height:16px;cursor:pointer;flex-shrink:0;" required>
                <label for="confirm-chk" style="font-size:13px;color:var(--sub);
                       text-transform:none;letter-spacing:0;cursor:pointer;font-weight:500;">
                    I confirm that the payment information and receipt I am uploading are accurate
                    and genuine. I understand that submitting false documents may result in
                    suspension of my membership.
                </label>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
                <button type="submit" class="btn btn-orange" id="submit-btn">
                    <i class="fas fa-upload"></i> Upload Receipt
                </button>
                <a href="my-subscription" class="btn btn-ghost">
                    <i class="fas fa-arrow-left"></i> Back to Subscription
                </a>
            </div>
        </form>
    </div>
</div>

</div><!-- /ur-wrap -->

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
    dz.querySelector('.dz-icon i').style.color = '#16a34a';
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