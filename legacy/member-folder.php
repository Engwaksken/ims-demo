<?php
declare(strict_types=1);

/*
 * Member folder (Hub Operations > Members > Folder).
 *   member-folder?id=N[&cat=..][&year=YYYY]
 *   member-folder?id=N&export=pdf[&year=YYYY][&images=1]   folder report
 *   member-folder?id=N&export=zip                          all files
 * Uploads/deletes post to includes/member-folder-process.php.
 */

require_once __DIR__ . '/includes/config.php';
check_login();

$mf_view_roles = ['Administrator', 'Operations/Admin', 'Finance', 'Accountant', 'Executive Director'];
if (!ims_allowed('members.folder_view', $mf_view_roles, 'View and export member folders (contracts, receipts, documents)')) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'You do not have access to member folders.'];
    header('Location: hub-operations?tab=members');
    exit;
}
$mf_can_upload = ims_allowed('hub_operations.manage', ['Administrator', 'Operations/Admin'], 'Add and edit hub members, subscriptions and receipts');
$mf_can_delete = ims_allowed('members.folder_delete', ['Administrator', 'Operations/Admin'], 'Delete documents from member folders');

const MF_CATEGORIES = ['Contract', 'Monthly Receipt', 'ID / KYC', 'Other'];

function mf_e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_size(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

function mf_money(mixed $amount): string
{
    return $amount === null || $amount === '' ? '-' : 'UGX ' . number_format((float)$amount, 0);
}

/** Absolute path of a stored file, or null if it is missing or outside this member's folder. */
function mf_local_path(string $stored, int $memberId): ?string
{
    $base = realpath(__DIR__ . '/uploads/member-files/' . $memberId);
    $file = realpath(__DIR__ . '/' . $stored);
    return ($base !== false && $file !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) ? $file : null;
}

/* ------------------------------------------------------------------ */
/* Load member + documents                                             */
/* ------------------------------------------------------------------ */
$member_id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT m.*, u.full_name, u.email AS user_email, u.phone AS user_phone
    FROM members m
    LEFT JOIN users u ON u.user_id = m.user_id
    WHERE m.member_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Member not found.'];
    header('Location: hub-operations?tab=members');
    exit;
}

$member_name = trim((string)($member['full_name'] ?? '')) ?: ((string)($member['company_name'] ?? '') ?: 'Member #' . $member_id);
$member_email = (string)($member['email'] ?: ($member['user_email'] ?? ''));

$documents = [];
$mf_table_ready = true;
$stmt = $conn->prepare("
    SELECT d.*, u.full_name AS uploaded_by_name
    FROM member_documents d
    LEFT JOIN users u ON u.user_id = d.uploaded_by
    WHERE d.member_id = ?
    ORDER BY FIELD(d.category, 'Contract', 'Monthly Receipt', 'ID / KYC', 'Other'),
             d.period_month DESC, d.created_at DESC
");
if ($stmt) {
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $mf_table_ready = false; // migration 2026_10_22_member_documents.sql not run yet
    error_log('member-folder: member_documents query failed: ' . $conn->error);
}

$years = [(int)date('Y')];
foreach ($documents as $d) {
    if (!empty($d['period_month'])) $years[] = (int)substr($d['period_month'], 0, 4);
}
$years = array_values(array_unique($years));
rsort($years);
$year = (int)($_GET['year'] ?? date('Y'));
if (!in_array($year, $years, true)) $year = (int)date('Y');

$cat_filter = in_array($_GET['cat'] ?? '', MF_CATEGORIES, true) ? (string)$_GET['cat'] : '';

// Receipt coverage for the selected year: month => [count, total]
$coverage = array_fill(1, 12, ['count' => 0, 'total' => 0.0]);
$receipts_year = [];
foreach ($documents as $d) {
    if ($d['category'] === 'Monthly Receipt' && !empty($d['period_month']) && (int)substr($d['period_month'], 0, 4) === $year) {
        $mo = (int)substr($d['period_month'], 5, 2);
        $coverage[$mo]['count']++;
        $coverage[$mo]['total'] += (float)($d['amount'] ?? 0);
        $receipts_year[] = $d;
    }
}
$months_covered = count(array_filter($coverage, fn($c) => $c['count'] > 0));
$receipts_total = array_sum(array_column($coverage, 'total'));

$by_category = array_fill_keys(MF_CATEGORIES, []);
foreach ($documents as $d) {
    $by_category[$d['category']][] = $d;
}

$export = (string)($_GET['export'] ?? '');

/* ------------------------------------------------------------------ */
/* Export: ZIP of every file                                           */
/* ------------------------------------------------------------------ */
if ($export === 'zip') {
    if (!class_exists('ZipArchive') || !$documents) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => $documents ? 'ZIP downloads are not available on this server.' : 'This folder has no files yet.'];
        header('Location: member-folder?id=' . $member_id);
        exit;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'mf');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'The ZIP file could not be created.'];
        header('Location: member-folder?id=' . $member_id);
        exit;
    }
    $used = [];
    foreach ($documents as $d) {
        $path = mf_local_path((string)$d['file_path'], $member_id);
        if ($path === null) continue;
        $prefix = !empty($d['period_month']) ? substr($d['period_month'], 0, 7) . ' ' : '';
        $name = preg_replace('/[^\w .()-]+/u', '_', $prefix . $d['original_name']);
        $entry = $d['category'] === 'ID / KYC' ? 'ID-KYC' : $d['category'];
        $entry .= '/' . $name;
        if (isset($used[$entry])) {
            $entry = preg_replace('/(\.[^.]+)?$/', ' (' . $d['document_id'] . ')$1', $entry, 1);
        }
        $used[$entry] = true;
        $zip->addFile($path, $entry);
    }
    $zip->close();

    log_action((int)$_SESSION['user_id'], 'Export Member Folder', 'member_documents', $member_id, 'ZIP of ' . count($used) . ' files');
    $zipName = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Member_' . $member['membership_number'] . '_' . $member_name) . '_' . date('Ymd') . '.zip';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . (string)filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/* ------------------------------------------------------------------ */
/* Export: PDF folder report                                           */
/* ------------------------------------------------------------------ */
if ($export === 'pdf') {
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $ims_autoload) {
        if (is_file($ims_autoload)) { require_once $ims_autoload; break; }
    }
    if (!class_exists(\Dompdf\Dompdf::class)) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'PDF export is not available on this server.'];
        header('Location: member-folder?id=' . $member_id);
        exit;
    }

    $with_images = !empty($_GET['images']);
    $month_names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    ob_start();
    ?>
<html><head><meta charset="UTF-8"><style>
body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
.cover{background:#ea580c;color:#fff;padding:18px 20px;border-radius:6px;margin-bottom:16px}
.cover h1{font-size:20px;margin:0 0 4px}
.cover .sub{font-size:11px;opacity:.9}
h2{font-size:13px;color:#c2410c;border-bottom:2px solid #fed7aa;padding-bottom:4px;margin:18px 0 8px}
table{width:100%;border-collapse:collapse}
th{background:#fff7ed;color:#7c2d12;text-align:left;padding:6px;font-size:10px;border-bottom:1px solid #fed7aa}
td{padding:6px;border-bottom:1px solid #f3f4f6;vertical-align:top}
.kv td{border:none;padding:3px 6px}
.kv td.k{color:#6b7280;width:28%}
.months td{text-align:center;padding:6px 2px;border:1px solid #e5e7eb;font-size:10px}
.ok{background:#dcfce7;color:#166534;font-weight:bold}
.miss{background:#fef2f2;color:#b91c1c}
.total td{font-weight:bold;background:#fff7ed}
.muted{color:#6b7280}
.img-page{page-break-before:always;text-align:center}
.img-page img{max-width:100%;max-height:820px}
.foot{margin-top:18px;border-top:1px solid #e5e7eb;padding-top:6px;color:#9ca3af;font-size:9px;text-align:center}
</style></head><body>
<div class="cover">
    <h1><?= mf_e($member_name) ?> - Member Folder</h1>
    <div class="sub">Membership <?= mf_e($member['membership_number']) ?> &middot; Receipts year <?= $year ?> &middot; Generated <?= date('d M Y H:i') ?></div>
</div>

<h2>Member Profile</h2>
<table class="kv">
    <tr><td class="k">Name</td><td><?= mf_e($member_name) ?></td><td class="k">Company</td><td><?= mf_e($member['company_name'] ?: '-') ?></td></tr>
    <tr><td class="k">Email</td><td><?= mf_e($member_email ?: '-') ?></td><td class="k">Member type</td><td><?= mf_e($member['member_type']) ?></td></tr>
    <tr><td class="k">Plan</td><td><?= mf_e($member['subscription_plan']) ?> (<?= mf_money($member['subscription_amount']) ?>)</td><td class="k">Status</td><td><?= mf_e($member['membership_status']) ?></td></tr>
    <tr><td class="k">Subscription</td><td colspan="3"><?= $member['subscription_start_date'] ? date('d M Y', strtotime($member['subscription_start_date'])) : '-' ?> to <?= $member['subscription_end_date'] ? date('d M Y', strtotime($member['subscription_end_date'])) : '-' ?></td></tr>
</table>

<h2>Contracts</h2>
<?php if (!$by_category['Contract']): ?>
    <p class="muted">No contract on file.</p>
<?php else: ?>
<table><tr><th>Title</th><th>Reference</th><th>File</th><th>Uploaded</th></tr>
<?php foreach ($by_category['Contract'] as $d): ?>
    <tr><td><?= mf_e($d['title']) ?></td><td><?= mf_e($d['reference'] ?: '-') ?></td><td><?= mf_e($d['original_name']) ?></td><td><?= date('d M Y', strtotime($d['created_at'])) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Monthly Receipts <?= $year ?> (<?= $months_covered ?>/12 months)</h2>
<table class="months"><tr>
<?php for ($mo = 1; $mo <= 12; $mo++): ?>
    <td class="<?= $coverage[$mo]['count'] ? 'ok' : 'miss' ?>"><?= $month_names[$mo] ?><br><?= $coverage[$mo]['count'] ? '&#10003;' : '-' ?></td>
<?php endfor; ?>
</tr></table>
<?php if ($receipts_year): ?>
<table style="margin-top:8px"><tr><th>Month</th><th>Title</th><th>Reference</th><th style="text-align:right">Amount</th></tr>
<?php foreach (array_reverse($receipts_year) as $d): ?>
    <tr><td><?= date('M Y', strtotime($d['period_month'])) ?></td><td><?= mf_e($d['title']) ?></td><td><?= mf_e($d['reference'] ?: '-') ?></td><td style="text-align:right"><?= mf_money($d['amount']) ?></td></tr>
<?php endforeach; ?>
    <tr class="total"><td colspan="3">Total <?= $year ?></td><td style="text-align:right"><?= mf_money($receipts_total) ?></td></tr>
</table>
<?php endif; ?>

<?php foreach (['ID / KYC', 'Other'] as $cat): if (!$by_category[$cat]) continue; ?>
<h2><?= mf_e($cat === 'Other' ? 'Other Documents' : $cat) ?></h2>
<table><tr><th>Title</th><th>Notes</th><th>File</th><th>Uploaded</th></tr>
<?php foreach ($by_category[$cat] as $d): ?>
    <tr><td><?= mf_e($d['title']) ?></td><td><?= mf_e($d['notes'] ?: '-') ?></td><td><?= mf_e($d['original_name']) ?></td><td><?= date('d M Y', strtotime($d['created_at'])) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endforeach; ?>

<?php
    // Optionally append receipt/contract images (JPG/PNG) as full pages.
    if ($with_images):
        foreach ($documents as $d):
            if (!in_array($d['category'], ['Contract', 'Monthly Receipt'], true)) continue;
            if ($d['category'] === 'Monthly Receipt' && (int)substr((string)$d['period_month'], 0, 4) !== $year) continue;
            $ext = strtolower(pathinfo((string)$d['file_path'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;
            $path = mf_local_path((string)$d['file_path'], $member_id);
            if ($path === null || filesize($path) > 4 * 1024 * 1024) continue;
            $data = 'data:image/' . ($ext === 'png' ? 'png' : 'jpeg') . ';base64,' . base64_encode((string)file_get_contents($path));
?>
<div class="img-page">
    <p class="muted"><?= mf_e($d['category']) ?><?= $d['period_month'] ? ' - ' . date('M Y', strtotime($d['period_month'])) : '' ?>: <?= mf_e($d['title']) ?></p>
    <img src="<?= $data ?>" alt="">
</div>
<?php
        endforeach;
    endif;
?>
<div class="foot">Hive Colab IMS &middot; Confidential member record &middot; <?= date('Y') ?></div>
</body></html>
<?php
    $html = (string)ob_get_clean();

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
require_once __DIR__ . '/includes/pdf-branding.php';
ims_brand_pdf($dompdf);

    log_action((int)$_SESSION['user_id'], 'Export Member Folder', 'member_documents', $member_id, "PDF report $year" . ($with_images ? ' with images' : ''));
    $pdfName = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Member_Folder_' . $member['membership_number'] . '_' . $year) . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    $dompdf->stream($pdfName, ['Attachment' => true]);
    exit;
}

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */
$page_title = 'Member Folder';
require_once __DIR__ . '/includes/header.php';

$visible = $cat_filter !== '' ? [$cat_filter => $by_category[$cat_filter]] : $by_category;
$cat_icons = ['Contract' => 'fa-file-signature', 'Monthly Receipt' => 'fa-receipt', 'ID / KYC' => 'fa-id-card', 'Other' => 'fa-folder'];
$month_names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$last_receipt = null;
foreach ($by_category['Monthly Receipt'] as $d) {
    if (!empty($d['period_month']) && ($last_receipt === null || $d['period_month'] > $last_receipt)) $last_receipt = $d['period_month'];
}
?>
<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/member-folder.css">

<div class="page-stack mf-page">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-folder-open" aria-hidden="true"></i> <?= mf_e($member_name) ?></h1>
            <p>
                Membership <strong><?= mf_e($member['membership_number']) ?></strong>
                &middot; <?= mf_e($member['subscription_plan']) ?> plan
                &middot; <?= mf_e($member['membership_status']) ?>
            </p>
        </div>
        <div class="hero-actions mf-no-print">
            <a href="hub-operations?tab=members" class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Members</a>
            <button type="button" class="btn" onclick="window.print()"><i class="fas fa-print" aria-hidden="true"></i> Print</button>
            <a href="member-folder?id=<?= $member_id ?>&amp;export=pdf&amp;year=<?= $year ?>" class="btn btn-primary"><i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF</a>
        </div>
    </div>

    <?php if (!$mf_table_ready): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            Member folders need the database update <code>database/migrations/2026_10_22_member_documents.sql</code>. Please ask the administrator to run it.</div>
    <?php endif; ?>

    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-folder" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Documents</span><span class="stat-value"><?= count($documents) ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-file-signature" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Contracts</span><span class="stat-value"><?= count($by_category['Contract']) ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Receipts <?= $year ?></span><span class="stat-value"><?= $months_covered ?><span class="stat-sub">/ 12 months</span></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-coins" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Receipted <?= $year ?></span><span class="stat-value"><span class="stat-unit">UGX</span> <?= number_format($receipts_total, 0) ?></span></div>
        </div>
    </div>

    <!-- Monthly receipt coverage -->
    <div class="card mf-card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-alt" aria-hidden="true"></i> Monthly Receipts <?= $year ?></h3>
            <form method="get" class="mf-no-print mf-inline-form">
                <input type="hidden" name="id" value="<?= $member_id ?>">
                <?php if ($cat_filter !== ''): ?><input type="hidden" name="cat" value="<?= mf_e($cat_filter) ?>"><?php endif; ?>
                <label for="mfYear" class="sr-only">Year</label>
                <select id="mfYear" name="year" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="card-body">
        <div class="mf-months" role="list">
            <?php for ($mo = 1; $mo <= 12; $mo++): $c = $coverage[$mo]; ?>
                <div class="mf-month <?= $c['count'] ? 'is-covered' : 'is-missing' ?>" role="listitem"
                     title="<?= $month_names[$mo] . ' ' . $year . ': ' . ($c['count'] ? $c['count'] . ' receipt(s)' : 'no receipt') ?>">
                    <span class="mf-month-name"><?= $month_names[$mo] ?></span>
                    <i class="fas <?= $c['count'] ? 'fa-check-circle' : 'fa-minus-circle' ?>" aria-hidden="true"></i>
                    <span class="mf-month-amt"><?= $c['count'] ? number_format($c['total'], 0) : '&nbsp;' ?></span>
                </div>
            <?php endfor; ?>
        </div>
        <?php if ($last_receipt): ?>
            <p class="cell-muted mf-last">Latest receipt covers <?= date('F Y', strtotime($last_receipt)) ?>.</p>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($mf_can_upload && $mf_table_ready): ?>
    <!-- Upload -->
    <div class="card mf-card mf-no-print">
        <div class="card-header"><h3><i class="fas fa-cloud-upload-alt" aria-hidden="true"></i> Add Documents</h3></div>
        <div class="card-body">
        <form method="post" action="includes/member-folder-process.php" enctype="multipart/form-data" class="mf-upload">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="member_id" value="<?= $member_id ?>">
            <div class="mf-upload-grid">
                <div class="form-group">
                    <label for="mfCategory" class="required">Category</label>
                    <select id="mfCategory" name="category" class="form-control" required>
                        <?php foreach (MF_CATEGORIES as $cat): ?>
                            <option value="<?= mf_e($cat) ?>" <?= $cat === ($cat_filter ?: 'Monthly Receipt') ? 'selected' : '' ?>><?= mf_e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mfTitle">Title</label>
                    <input id="mfTitle" type="text" name="title" class="form-control" maxlength="200" placeholder="Defaults to the file name">
                </div>
                <div class="form-group mf-receipt-field">
                    <label for="mfMonth" class="required">Month covered</label>
                    <input id="mfMonth" type="month" name="period_month" class="form-control" value="<?= date('Y-m') ?>">
                </div>
                <div class="form-group mf-receipt-field">
                    <label for="mfAmount">Amount (UGX)</label>
                    <input id="mfAmount" type="number" name="amount" class="form-control" min="0" step="1" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label for="mfRef">Reference</label>
                    <input id="mfRef" type="text" name="reference" class="form-control" maxlength="100" placeholder="Receipt / contract no.">
                </div>
                <div class="form-group mf-wide">
                    <label for="mfNotes">Notes</label>
                    <input id="mfNotes" type="text" name="notes" class="form-control" maxlength="500">
                </div>
                <div class="form-group mf-wide">
                    <label for="mfFiles" class="required">Files</label>
                    <input id="mfFiles" type="file" name="files[]" class="form-control" multiple required
                           accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
                    <small class="cell-muted">PDF, images, Word or Excel; up to 20 files, <?= (int)(MAX_FILE_SIZE / 1048576) ?> MB each.</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-upload" aria-hidden="true"></i> Upload</button>
        </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Documents -->
    <div class="card mf-card">
        <div class="card-header">
            <h3><i class="fas fa-folder-open" aria-hidden="true"></i> Documents</h3>
            <div class="mf-no-print mf-toolbar">
                <nav class="mf-tabs" aria-label="Filter by category">
                    <a href="member-folder?id=<?= $member_id ?>&amp;year=<?= $year ?>" class="<?= $cat_filter === '' ? 'active' : '' ?>">All <span><?= count($documents) ?></span></a>
                    <?php foreach (MF_CATEGORIES as $cat): ?>
                        <a href="member-folder?id=<?= $member_id ?>&amp;year=<?= $year ?>&amp;cat=<?= urlencode($cat) ?>" class="<?= $cat_filter === $cat ? 'active' : '' ?>"><?= mf_e($cat) ?> <span><?= count($by_category[$cat]) ?></span></a>
                    <?php endforeach; ?>
                </nav>
                <div class="mf-exports">
                    <a href="member-folder?id=<?= $member_id ?>&amp;export=pdf&amp;year=<?= $year ?>&amp;images=1" class="btn btn-secondary btn-sm" title="PDF report with contract and receipt images (JPG/PNG) attached">
                        <i class="fas fa-images" aria-hidden="true"></i> PDF + images</a>
                    <?php if (class_exists('ZipArchive') && $documents): ?>
                        <a href="member-folder?id=<?= $member_id ?>&amp;export=zip" class="btn btn-secondary btn-sm"><i class="fas fa-file-archive" aria-hidden="true"></i> Download all</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-body">
        <?php if (!$documents): ?>
            <div class="empty-state"><i class="fas fa-folder-open" aria-hidden="true"></i><p>No documents yet<?= $mf_can_upload ? ' - add a contract or receipt above.' : '.' ?></p></div>
        <?php else: ?>
            <?php foreach ($visible as $cat => $docs): if (!$docs) continue; ?>
                <h4 class="mf-group"><i class="fas <?= $cat_icons[$cat] ?>" aria-hidden="true"></i> <?= mf_e($cat) ?> <span><?= count($docs) ?></span></h4>
                <div class="table-responsive">
                    <table class="table table-stack mf-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <?php if ($cat === 'Monthly Receipt'): ?><th>Month</th><th>Amount</th><?php endif; ?>
                                <th>Reference</th>
                                <th>File</th>
                                <th>Uploaded</th>
                                <th class="mf-no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($docs as $d): ?>
                            <tr>
                                <td data-label="Title"><strong><?= mf_e($d['title']) ?></strong>
                                    <?php if (!empty($d['notes'])): ?><span class="cell-muted"><?= mf_e($d['notes']) ?></span><?php endif; ?></td>
                                <?php if ($cat === 'Monthly Receipt'): ?>
                                    <td data-label="Month" class="text-nowrap"><?= $d['period_month'] ? date('M Y', strtotime($d['period_month'])) : '-' ?></td>
                                    <td data-label="Amount" class="text-nowrap"><?= mf_money($d['amount']) ?></td>
                                <?php endif; ?>
                                <td data-label="Reference"><?= mf_e($d['reference'] ?: '-') ?></td>
                                <td data-label="File"><span class="cell-truncate" title="<?= mf_e($d['original_name']) ?>"><?= mf_e($d['original_name']) ?></span>
                                    <span class="cell-muted"><?= mf_size((int)$d['file_size']) ?></span></td>
                                <td data-label="Uploaded" class="text-nowrap"><?= date('d M Y', strtotime($d['created_at'])) ?>
                                    <?php if (!empty($d['uploaded_by_name'])): ?><span class="cell-muted"><?= mf_e($d['uploaded_by_name']) ?></span><?php endif; ?></td>
                                <td data-label="Actions" class="mf-no-print">
                                    <div class="table-actions">
                                        <a href="<?= mf_e(ims_upload_url($d['file_path'])) ?>" target="_blank" rel="noopener" class="btn btn-info btn-sm" title="View"><i class="fas fa-eye" aria-hidden="true"></i><span class="sr-only">View</span></a>
                                        <a href="<?= mf_e(ims_upload_url($d['file_path'], true)) ?>" class="btn btn-secondary btn-sm" title="Download"><i class="fas fa-download" aria-hidden="true"></i><span class="sr-only">Download</span></a>
                                        <?php if ($mf_can_delete): ?>
                                            <form method="post" action="includes/member-folder-process.php" class="mf-inline-form"
                                                  onsubmit="return confirm('Delete this document? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="member_id" value="<?= $member_id ?>">
                                                <input type="hidden" name="document_id" value="<?= (int)$d['document_id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash" aria-hidden="true"></i><span class="sr-only">Delete</span></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div><!-- /.page-stack -->

<script>
(function () {
    // Month and amount only apply to receipts.
    var cat = document.getElementById('mfCategory');
    if (!cat) return;
    var month = document.getElementById('mfMonth');
    function sync() {
        var isReceipt = cat.value === 'Monthly Receipt';
        document.querySelectorAll('.mf-receipt-field').forEach(function (el) { el.hidden = !isReceipt; });
        month.required = isReceipt;
        if (!isReceipt) month.value = '';
        else if (!month.value) month.value = new Date().toISOString().slice(0, 7);
    }
    cat.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
