<?php

$page_title = 'My Appraisals';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

$uid          = (int) $_SESSION['user_id'];
$appraisal_id = (int) ($_GET['id'] ?? 0);

if ($appraisal_id === 0) {
    send_notification($uid, 'No appraisal specified.', 'danger');
    header('Location: my-appraisals');
    exit();
}


$stmt = $conn->prepare("
    SELECT  pa.*,
            u.full_name  AS employee_display_name
    FROM    performance_appraisals pa
    LEFT JOIN users u ON u.user_id = pa.user_id
    WHERE   pa.appraisal_id = ?
      AND  (pa.user_id = ? OR pa.supervisor_id = ?)
    LIMIT 1
");
$stmt->bind_param('iii', $appraisal_id, $uid, $uid);
$stmt->execute();
$appraisal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appraisal) {
    send_notification($uid, 'Appraisal not found or access denied.', 'danger');
    header('Location: my-appraisals');
    exit();
}

$is_owner      = ($appraisal['user_id']      === $uid);
$is_supervisor = ($appraisal['supervisor_id'] === $uid);
$status        = $appraisal['status'];  


$stmt = $conn->prepare("
    SELECT * FROM appraisal_kras
    WHERE appraisal_id = ?
    ORDER BY sort_order ASC
");
$stmt->bind_param('i', $appraisal_id);
$stmt->execute();
$kras_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


$stmt = $conn->prepare("
    SELECT * FROM appraisal_kpis
    WHERE appraisal_id = ?
    ORDER BY kra_id ASC, sort_order ASC
");
$stmt->bind_param('i', $appraisal_id);
$stmt->execute();
$kpis_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$kpis_by_kra = [];
foreach ($kpis_raw as $kpi) {
    $kpis_by_kra[$kpi['kra_id']][] = $kpi;
}


$status_labels = [
    'draft'          => ['label' => 'Draft',          'class' => 'badge-draft'],
    'pending_review' => ['label' => 'Pending Review',  'class' => 'badge-pending'],
    'reviewed'       => ['label' => 'Reviewed',        'class' => 'badge-reviewed'],
    'approved'       => ['label' => 'Approved',        'class' => 'badge-approved'],
];
$status_meta = $status_labels[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-draft'];


function rating_badge(?int $r): string {
    if ($r === null) return '<span class="no-rating">-</span>';
    $colors = [1 => '#ef4444', 2 => '#f97316', 3 => '#eab308', 4 => '#22c55e', 5 => '#06b6d4'];
    $color  = $colors[$r] ?? '#6b7280';
    return "<span class=\"rating-dot\" style=\"background:{$color}\">{$r}</span>";
}

function fmt_date(?string $d): string {
    if (!$d) return '-';
    return date('d M Y', strtotime($d));
}
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* --- Reset & Base ----------------------------------------------- */
.page-appraisal-view *, .page-appraisal-view *::before, .page-appraisal-view *::after { box-sizing: border-box; margin: 0; padding: 0; }

.page-appraisal-view {
    --bg:          #f4f3ef;
    --surface:     #ffffff;
    --surface-alt: #faf9f6;
    --border:      #e5e2da;
    --text-strong: #1a1917;
    --text:        #3d3b36;
    --text-muted:  #8a8780;
    --accent:     #ff9800;
    --accent-light:#ebeffc;
    --gold:        #b38a2e;
    --gold-light:  #fdf6e3;
    --danger:      #dc2626;
    --shadow-sm:   0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow:      0 4px 16px rgba(0,0,0,.07);
    --radius:      10px;
    --font-display:'Arial', Georgia, serif;
    --font-body:   'DM Sans', system-ui, sans-serif;
}

.page-appraisal-view {
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.6;
}

/* --- Layout ------------------------------------------------------- */
.page-appraisal-view.page-wrap {
    max-width: 1080px;
    margin: 0 auto;
    padding: 32px 20px 80px;
}

/* --- Top bar ------------------------------------------------------- */
.page-appraisal-view .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 12px;
}

.page-appraisal-view .back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: color .15s;
}
.page-appraisal-view .back-link:hover { color: var(--accent); }
.page-appraisal-view .back-link svg { width: 16px; height: 16px; }

.page-appraisal-view .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* --- Buttons ------------------------------------------------------- */
.page-appraisal-view .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    border-radius: 7px;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all .15s;
    white-space: nowrap;
}
.page-appraisal-view .btn-primary   { background: var(--accent); color: #fff; }
.page-appraisal-view .btn-primary:hover { background: #1f47c0; }
.page-appraisal-view .btn-outline   { background: transparent; color: var(--text); border: 1.5px solid var(--border); }
.page-appraisal-view .btn-outline:hover { border-color: var(--accent); color: var(--accent); }
.page-appraisal-view .btn-success   { background: #16a34a; color: #fff; }
.page-appraisal-view .btn-success:hover { background: #15803d; }
.page-appraisal-view .btn-danger    { background: var(--danger); color: #fff; }
.page-appraisal-view .btn-danger:hover  { background: #b91c1c; }
.page-appraisal-view .btn svg { width: 15px; height: 15px; }

/* --- Status Badge --------------------------------------------------- */
.page-appraisal-view .badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .03em;
    text-transform: uppercase;
}
.page-appraisal-view .badge::before { content:''; width:6px; height:6px; border-radius:50%; background: currentColor; }
.page-appraisal-view .badge-draft    { background: #f3f4f6; color: #6b7280; }
.page-appraisal-view .badge-pending  { background: #fef3c7; color: #92400e; }
.page-appraisal-view .badge-reviewed { background: #dbeafe; color: #ff9800; }
.page-appraisal-view .badge-approved { background: #dcfce7; color: #166534; }

/* --- Card ----------------------------------------------------------- */
.page-appraisal-view .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 20px;
    overflow: hidden;
}

.page-appraisal-view .card-header {
    padding: 16px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: var(--surface-alt);
}

.page-appraisal-view .card-title {
    font-family: var(--font-display);
    font-size: 15px;
    font-weight: 600;
    color: var(--text-strong);
}

.page-appraisal-view .card-body { padding: 24px; }

/* --- Page Title ------------------------------------------------------ */
.page-appraisal-view .page-title-block {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px 32px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}
.page-appraisal-view .page-title-block::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--accent),#ff5722);
}
.page-appraisal-view .page-title {
    font-family: var(--font-display);
    font-size: 26px;
    color: var(--text-strong);
    margin-bottom: 6px;
}
.page-appraisal-view .page-subtitle {
    color: var(--text-muted);
    font-size: 13px;
}
.page-appraisal-view .title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

/* --- Info Grid ------------------------------------------------------- */
.page-appraisal-view .info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}
.page-appraisal-view .info-item {}
.page-appraisal-view .info-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 3px;
}
.page-appraisal-view .info-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-strong);
}

/* --- KRA Block ------------------------------------------------------- */
.page-appraisal-view .kra-block {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.page-appraisal-view .kra-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: var(--surface-alt);
    border-bottom: 1px solid var(--border);
    gap: 12px;
    flex-wrap: wrap;
}

.page-appraisal-view .kra-number {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--accent);
    background: var(--accent-light);
    padding: 3px 10px;
    border-radius: 4px;
    flex-shrink: 0;
}

.page-appraisal-view .kra-title {
    font-family: var(--font-display);
    font-size: 15px;
    font-weight: 600;
    color: var(--text-strong);
    flex: 1;
}

.page-appraisal-view .kra-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
    flex-wrap: wrap;
}

.page-appraisal-view .kra-meta-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}
.page-appraisal-view .kra-meta-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.page-appraisal-view .kra-meta-value {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-strong);
}

/* --- KPI Table ------------------------------------------------------- */
.page-appraisal-view .kpi-table-wrap { overflow-x: auto; }

.page-appraisal-view .kpi-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.page-appraisal-view .kpi-table thead tr {
    background: #f8f7f4;
}

.page-appraisal-view .kpi-table th {
    padding: 9px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

.page-appraisal-view .kpi-table td {
    padding: 11px 14px;
    border-bottom: 1px solid #f0ede7;
    color: var(--text);
    vertical-align: middle;
}

.page-appraisal-view .kpi-table tbody tr:last-child td { border-bottom: none; }
.page-appraisal-view .kpi-table tbody tr:hover { background: #fafaf8; }

.page-appraisal-view .kpi-title-cell { font-weight: 500; color: var(--text-strong); }
.page-appraisal-view .kpi-weight     { font-weight: 600; color: var(--text-muted); font-size: 12px; }

/* --- Rating Dot ------------------------------------------------------- */
.page-appraisal-view .rating-dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}
.page-appraisal-view .no-rating { color: var(--text-muted); font-size: 16px; }

/* --- Comments ------------------------------------------------------- */
.page-appraisal-view .comments-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    padding: 16px 20px;
    background: var(--surface-alt);
    border-top: 1px solid var(--border);
}
@media (max-width: 600px) { .page-appraisal-view .comments-grid { grid-template-columns: 1fr; } }

.page-appraisal-view .comment-box {}
.page-appraisal-view .comment-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 5px;
}
.page-appraisal-view .comment-text {
    font-size: 13px;
    color: var(--text);
    line-height: 1.6;
    min-height: 24px;
}
.page-appraisal-view .comment-empty { color: var(--text-muted); font-style: italic; }

/* --- Score Summary --------------------------------------------------- */
.page-appraisal-view .score-card {
    background: linear-gradient(135deg, #ff9800 0%, var(--accent) 100%);
    color: #fff;
    border-radius: var(--radius);
    padding: 28px 32px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    box-shadow: 0 8px 24px rgba(43,91,224,.25);
}

.page-appraisal-view .score-label {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    opacity: .75;
    margin-bottom: 4px;
}

.page-appraisal-view .score-value {
    font-family: var(--font-display);
    font-size: 44px;
    font-weight: 700;
    line-height: 1;
}

.page-appraisal-view .score-divider {
    width: 1px;
    height: 60px;
    background: rgba(255,255,255,.2);
}

.page-appraisal-view .score-items {
    display: flex;
    gap: 32px;
    flex-wrap: wrap;
}

.page-appraisal-view .score-item { text-align: center; }
.page-appraisal-view .score-item-val {
    font-family: var(--font-display);
    font-size: 24px;
    font-weight: 700;
    line-height: 1.1;
}
.page-appraisal-view .score-item-lbl {
    font-size: 11px;
    opacity: .7;
    letter-spacing: .04em;
    text-transform: uppercase;
    margin-top: 2px;
}

/* --- Manager Overall Comment ----------------------------------------- */
.page-appraisal-view .mgr-comment-block {
    background: var(--gold-light);
    border: 1px solid #e8d5a3;
    border-left: 4px solid var(--gold);
    border-radius: var(--radius);
    padding: 18px 22px;
    margin-bottom: 20px;
}
.page-appraisal-view .mgr-comment-block .label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 6px;
}
.page-appraisal-view .mgr-comment-block p {
    font-size: 14px;
    color: var(--text);
    line-height: 1.7;
}

/* --- Supervisor Review Form ------------------------------------------ */
.page-appraisal-view .review-form-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 20px;
}

.page-appraisal-view .review-form-header {
    background: linear-gradient(135deg, #ff9800, var(--accent));
    color: #fff;
    padding: 16px 24px;
}
.page-appraisal-view .review-form-header h3 {
    font-family: var(--font-display);
    font-size: 17px;
    font-weight: 600;
}
.page-appraisal-view .review-form-header p {
    font-size: 12px;
    opacity: .75;
    margin-top: 3px;
}
.page-appraisal-view .review-form-body { padding: 24px; }

.page-appraisal-view .form-group { margin-bottom: 18px; }
.page-appraisal-view .form-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 6px;
}
.page-appraisal-view .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-family: var(--font-body);
    font-size: 14px;
    color: var(--text-strong);
    background: var(--surface);
    transition: border-color .15s;
    outline: none;
}
.page-appraisal-view .form-control:focus { border-color: var(--accent); }
.page-appraisal-view textarea.form-control { resize: vertical; min-height: 90px; }

.page-appraisal-view .review-kpi-grid { overflow-x: auto; margin-bottom: 20px; }

.page-appraisal-view .review-kpi-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.page-appraisal-view .review-kpi-table th {
    padding: 9px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--text-muted);
    border-bottom: 2px solid var(--border);
    background: var(--surface-alt);
    white-space: nowrap;
}
.page-appraisal-view .review-kpi-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f0ede7;
    vertical-align: middle;
}
.page-appraisal-view .review-kpi-table tbody tr:last-child td { border-bottom: none; }

.page-appraisal-view .rating-select {
    padding: 6px 10px;
    border: 1.5px solid var(--border);
    border-radius: 6px;
    font-family: var(--font-body);
    font-size: 13px;
    color: var(--text-strong);
    background: var(--surface);
    cursor: pointer;
    min-width: 80px;
    outline: none;
    transition: border-color .15s;
}
.page-appraisal-view .rating-select:focus { border-color: var(--accent); }

.page-appraisal-view .review-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}

/* --- Timeline -------------------------------------------------------- */
.page-appraisal-view .timeline {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.page-appraisal-view .tl-item {
    display: flex;
    gap: 14px;
    padding-bottom: 18px;
    position: relative;
}
.page-appraisal-view .tl-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 30px;
    bottom: 0;
    width: 1px;
    background: var(--border);
}
.page-appraisal-view .tl-dot {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--accent-light);
    border: 2px solid var(--accent);
    z-index: 1;
}
.page-appraisal-view .tl-dot svg { width: 14px; height: 14px; color: var(--accent); }
.page-appraisal-view .tl-dot.done { background: #dcfce7; border-color: #16a34a; }
.page-appraisal-view .tl-dot.done svg { color: #16a34a; }
.page-appraisal-view .tl-dot.pending { background: #fef3c7; border-color: #ca8a04; }
.page-appraisal-view .tl-dot.pending svg { color: #ca8a04; }

.page-appraisal-view .tl-content {}
.page-appraisal-view .tl-title { font-weight: 600; font-size: 13px; color: var(--text-strong); }
.page-appraisal-view .tl-time  { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

/* --- Print ----------------------------------------------------------- */
@media print {
    .page-appraisal-view { background: #fff; font-size: 12px; }
    .page-appraisal-view .topbar, .page-appraisal-view .review-form-section, .page-appraisal-view .no-print { display: none !important; }
    .page-appraisal-view .card, .page-appraisal-view .kra-block { box-shadow: none; border-color: #ddd; }
    .page-appraisal-view.page-wrap { padding: 0; max-width: 100%; }
    .page-appraisal-view .score-card { background: #ff9800 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}

@media (max-width: 640px) {
    .page-appraisal-view .page-title { font-size: 20px; }
    .page-appraisal-view .score-card { padding: 20px; }
    .page-appraisal-view .score-value { font-size: 36px; }
    .page-appraisal-view .card-body { padding: 16px; }
    .page-appraisal-view .kra-header { padding: 12px 14px; }
    .page-appraisal-view .kpi-table th, .page-appraisal-view .kpi-table td { padding: 8px 10px; }
}
</style>

<div class="page-wrap page-appraisal-view">

    <!-- Top bar -->
    <div class="topbar">
        <a href="my-appraisals" class="back-link">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            My Appraisals
        </a>
        <div class="topbar-actions no-print">
            <?php if ($is_owner && $status === 'draft'): ?>
                <?php /* "Edit Draft" hidden: no edit page exists yet (performance-appraisal ignores ?edit= and would create a duplicate). */ ?>
                <form method="POST" action="includes/appraisal-process.php" style="display:inline">
                    <input type="hidden" name="action" value="submit_appraisal_existing">
                    <input type="hidden" name="appraisal_id" value="<?= $appraisal_id ?>">
                    <button type="submit" name="submit_appraisal" class="btn btn-primary">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        Submit for Review
                    </button>
                </form>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-outline">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print
            </button>
        </div>
    </div>

    <!-- Page Title -->
    <div class="page-title-block">
        <div class="title-row">
            <div>
                <p class="page-subtitle">Performance Appraisal &nbsp;&nbsp; #<?= $appraisal_id ?></p>
                <h1 class="page-title"><?= htmlspecialchars($appraisal['full_name']) ?></h1>
            </div>
            <span class="badge <?= $status_meta['class'] ?>"><?= $status_meta['label'] ?></span>
        </div>
    </div>

    <!-- Overall Score (if available) -->
    <?php if ($appraisal['overall_score'] !== null): ?>
    <div class="score-card">
        <div>
            <div class="score-label">Overall Score</div>
            <div class="score-value"><?= number_format((float) $appraisal['overall_score'], 2) ?></div>
        </div>
        <div class="score-divider"></div>
        <div class="score-items">
            <?php if ($appraisal['total_kra_weight'] !== null): ?>
            <div class="score-item">
                <div class="score-item-val"><?= number_format((float) $appraisal['total_kra_weight'], 1) ?>%</div>
                <div class="score-item-lbl">Total Weight</div>
            </div>
            <?php endif; ?>
            <div class="score-item">
                <div class="score-item-val"><?= count($kras_raw) ?></div>
                <div class="score-item-lbl">KRAs</div>
            </div>
            <div class="score-item">
                <div class="score-item-val"><?= count($kpis_raw) ?></div>
                <div class="score-item-lbl">KPIs</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Manager Overall Comment -->
    <?php if (!empty($appraisal['mgr_overall_comment'])): ?>
    <div class="mgr-comment-block">
        <div class="label">Manager's Overall Comment</div>
        <p><?= nl2br(htmlspecialchars($appraisal['mgr_overall_comment'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Appraisal Details -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Appraisal Details</span>
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Employee</div>
                    <div class="info-value"><?= htmlspecialchars($appraisal['full_name']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Department</div>
                    <div class="info-value"><?= htmlspecialchars($appraisal['department'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Job Title</div>
                    <div class="info-value"><?= htmlspecialchars($appraisal['job_title'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Supervisor</div>
                    <div class="info-value"><?= htmlspecialchars($appraisal['supervisor_name'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Appraisal Period</div>
                    <div class="info-value"><?= fmt_date($appraisal['period_from']) ?> - <?= fmt_date($appraisal['period_to']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Discussion Date</div>
                    <div class="info-value"><?= fmt_date($appraisal['discussion_date']) ?></div>
                </div>
                <?php if ($appraisal['submitted_at']): ?>
                <div class="info-item">
                    <div class="info-label">Submitted</div>
                    <div class="info-value"><?= fmt_date($appraisal['submitted_at']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($appraisal['reviewed_at']): ?>
                <div class="info-item">
                    <div class="info-label">Reviewed</div>
                    <div class="info-value"><?= fmt_date($appraisal['reviewed_at']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KRAs & KPIs -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Key Result Areas &amp; KPIs</span>
            <span style="font-size:12px;color:var(--text-muted)"><?= count($kras_raw) ?> KRAs - <?= count($kpis_raw) ?> KPIs</span>
        </div>
        <div class="card-body" style="padding:16px">

            <?php if (empty($kras_raw)): ?>
                <p style="color:var(--text-muted);text-align:center;padding:24px 0">No KRAs recorded for this appraisal.</p>
            <?php else: ?>

            <?php foreach ($kras_raw as $i => $kra): ?>
            <div class="kra-block">
                <div class="kra-header">
                    <span class="kra-number">KRA <?= $i + 1 ?></span>
                    <span class="kra-title"><?= htmlspecialchars($kra['title']) ?></span>
                    <div class="kra-meta">
                        <?php if ($kra['weight'] !== null): ?>
                        <div class="kra-meta-item">
                            <span class="kra-meta-label">Weight</span>
                            <span class="kra-meta-value"><?= number_format((float)$kra['weight'], 1) ?>%</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($kra['kra_rating'] !== null): ?>
                        <div class="kra-meta-item">
                            <span class="kra-meta-label">KRA Rating</span>
                            <span class="kra-meta-value"><?= number_format((float)$kra['kra_rating'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($kra['agreed_rating'] !== null): ?>
                        <div class="kra-meta-item">
                            <span class="kra-meta-label">Agreed</span>
                            <span class="kra-meta-value"><?= (int)$kra['agreed_rating'] ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KPIs table -->
                <?php $kpis = $kpis_by_kra[$kra['kra_id']] ?? []; ?>
                <?php if (!empty($kpis)): ?>
                <div class="kpi-table-wrap">
                    <table class="kpi-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>KPI</th>
                                <th>Weight</th>
                                <th>Employee</th>
                                <th>Manager</th>
                                <th>Agreed</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($kpis as $j => $kpi): ?>
                            <tr>
                                <td style="color:var(--text-muted);font-size:12px"><?= $j + 1 ?></td>
                                <td class="kpi-title-cell"><?= htmlspecialchars($kpi['title']) ?></td>
                                <td class="kpi-weight"><?= $kpi['weight'] !== null ? number_format((float)$kpi['weight'], 1).'%' : '-' ?></td>
                                <td><?= rating_badge($kpi['emp_rating'] !== null ? (int)$kpi['emp_rating'] : null) ?></td>
                                <td><?= rating_badge($kpi['mgr_rating'] !== null ? (int)$kpi['mgr_rating'] : null) ?></td>
                                <td><?= rating_badge($kpi['agreed_rating'] !== null ? (int)$kpi['agreed_rating'] : null) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Comments -->
                <?php if (!empty($kra['emp_comments']) || !empty($kra['mgr_comments'])): ?>
                <div class="comments-grid">
                    <div class="comment-box">
                        <div class="comment-label">Employee Comments</div>
                        <div class="comment-text <?= empty($kra['emp_comments']) ? 'comment-empty' : '' ?>">
                            <?= !empty($kra['emp_comments']) ? nl2br(htmlspecialchars($kra['emp_comments'])) : 'No comment provided.' ?>
                        </div>
                    </div>
                    <div class="comment-box">
                        <div class="comment-label">Manager Comments</div>
                        <div class="comment-text <?= empty($kra['mgr_comments']) ? 'comment-empty' : '' ?>">
                            <?= !empty($kra['mgr_comments']) ? nl2br(htmlspecialchars($kra['mgr_comments'])) : 'No comment provided.' ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </div>

    <!-- -----------------------------------------------------------
         SUPERVISOR REVIEW FORM  (only when pending + is supervisor)
    ---------------------------------------------------------------- -->
    <?php if ($is_supervisor && $status === 'pending_review'): ?>
    <div class="review-form-section no-print">
        <div class="review-form-header">
            <h3>Supervisor Review</h3>
            <p>Set manager ratings for each KPI, add KRA comments, and submit your review.</p>
        </div>
        <div class="review-form-body">
            <form method="POST" action="includes/appraisal-review-process.php">
                <input type="hidden" name="action"        value="review_appraisal">
                <input type="hidden" name="appraisal_id"  value="<?= $appraisal_id ?>">

                <?php foreach ($kras_raw as $i => $kra): ?>
                <?php $kpis = $kpis_by_kra[$kra['kra_id']] ?? []; ?>
                <h4 style="font-family:var(--font-display);font-size:14px;color:var(--text-strong);margin-bottom:10px;margin-top:<?= $i > 0 ? '24px' : '0' ?>">
                    KRA <?= $i + 1 ?>: <?= htmlspecialchars($kra['title']) ?>
                </h4>

                <?php if (!empty($kpis)): ?>
                <div class="review-kpi-grid">
                    <table class="review-kpi-table">
                        <thead>
                            <tr>
                                <th>KPI</th>
                                <th>Employee Rating</th>
                                <th>Manager Rating</th>
                                <th>Agreed Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($kpis as $kpi): ?>
                        <tr>
                            <td style="font-weight:500;color:var(--text-strong)"><?= htmlspecialchars($kpi['title']) ?></td>
                            <td><?= rating_badge($kpi['emp_rating'] !== null ? (int)$kpi['emp_rating'] : null) ?></td>
                            <td>
                                <select name="kpi[<?= $kpi['kpi_id'] ?>][mgr_rating]" class="rating-select">
                                    <option value="">-</option>
                                    <?php for ($r = 1; $r <= 5; $r++): ?>
                                    <option value="<?= $r ?>" <?= ((int)$kpi['mgr_rating'] === $r) ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                            <td>
                                <select name="kpi[<?= $kpi['kpi_id'] ?>][agreed_rating]" class="rating-select">
                                    <option value="">-</option>
                                    <?php for ($r = 1; $r <= 5; $r++): ?>
                                    <option value="<?= $r ?>" <?= ((int)$kpi['agreed_rating'] === $r) ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- KRA-level comment & agreed rating -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:4px">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Manager Comment (KRA <?= $i+1 ?>)</label>
                        <textarea name="kra[<?= $kra['kra_id'] ?>][mgr_comments]" class="form-control" rows="3"><?= htmlspecialchars($kra['mgr_comments'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">KRA Agreed Rating</label>
                        <select name="kra[<?= $kra['kra_id'] ?>][agreed_rating]" class="form-control rating-select" style="min-width:unset">
                            <option value=""> Select </option>
                            <?php for ($r = 1; $r <= 5; $r++): ?>
                            <option value="<?= $r ?>" <?= ((int)($kra['agreed_rating'] ?? 0) === $r) ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Overall manager comment -->
                <div class="form-group" style="margin-top:24px">
                    <label class="form-label">Overall Manager Comment</label>
                    <textarea name="mgr_overall_comment" class="form-control" rows="4" placeholder="Summarise your overall assessment…"><?= htmlspecialchars($appraisal['mgr_overall_comment'] ?? '') ?></textarea>
                </div>

                <div class="review-actions">
                    <button type="submit" name="save_review" class="btn btn-outline">Save Draft Review</button>
                    <button type="submit" name="approve_appraisal" class="btn btn-success"
                        onclick="return confirm('Approve and finalise this appraisal?')">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Approve Appraisal
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audit / Timeline -->
    <div class="card">
        <div class="card-header"><span class="card-title">Timeline</span></div>
        <div class="card-body">
            <div class="timeline">
                <div class="tl-item">
                    <div class="tl-dot done">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div class="tl-content">
                        <div class="tl-title">Appraisal Created</div>
                        <div class="tl-time"><?= fmt_date($appraisal['created_at']) ?></div>
                    </div>
                </div>
                <?php if ($appraisal['submitted_at']): ?>
                <div class="tl-item">
                    <div class="tl-dot done">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    </div>
                    <div class="tl-content">
                        <div class="tl-title">Submitted for Review</div>
                        <div class="tl-time"><?= fmt_date($appraisal['submitted_at']) ?></div>
                    </div>
                </div>
                <?php elseif ($status === 'draft'): ?>
                <div class="tl-item">
                    <div class="tl-dot pending">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
                    </div>
                    <div class="tl-content">
                        <div class="tl-title">Awaiting Submission</div>
                        <div class="tl-time">Not yet submitted</div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($appraisal['reviewed_at']): ?>
                <div class="tl-item">
                    <div class="tl-dot done">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="tl-content">
                        <div class="tl-title">Reviewed by Supervisor</div>
                        <div class="tl-time"><?= fmt_date($appraisal['reviewed_at']) ?></div>
                    </div>
                </div>
                <?php elseif (in_array($status, ['pending_review'])): ?>
                <div class="tl-item">
                    <div class="tl-dot pending">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </div>
                    <div class="tl-content">
                        <div class="tl-title">Pending Supervisor Review</div>
                        <div class="tl-time">In progress</div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($appraisal['updated_at'] && $appraisal['updated_at'] !== $appraisal['created_at']): ?>
                <div class="tl-item">
                    <div class="tl-dot">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div class="tl-content">
                        <div class="tl-title">Last Updated</div>
                        <div class="tl-time"><?= fmt_date($appraisal['updated_at']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /.page-wrap.page-appraisal-view -->

<?php include 'includes/footer.php'; ?>
