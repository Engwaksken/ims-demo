<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/includes/config.php';

$opportunityId = filter_input(INPUT_GET, 'opportunity_id', FILTER_VALIDATE_INT) ?: 0;
$draftId       = filter_input(INPUT_GET, 'draft_id', FILTER_VALIDATE_INT) ?: 0;

if ($opportunityId < 1) {
    header('Location: apply');
    exit;
}

$stmt = $conn->prepare(
    "SELECT *
     FROM application_opportunities
     WHERE opportunity_id = ?
       AND status = 'Published'
       AND deadline >= CURDATE()
     LIMIT 1"
);
$stmt->bind_param('i', $opportunityId);
$stmt->execute();
$opportunity = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$opportunity) {
    header('Location: apply');
    exit;
}

$pageTitle = 'Apply - ' . ($opportunity['opportunity_title'] ?? 'Opportunity');

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM applications
     WHERE opportunity_id = ?
       AND status <> 'Draft'"
);
$stmt->bind_param('i', $opportunityId);
$stmt->execute();
$appCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$maxApplicants = (int)($opportunity['max_applicants'] ?? 0);
$applicationsFull = $maxApplicants > 0 && $appCount >= $maxApplicants;

$draft = [];
if ($draftId > 0) {
    $sql = "SELECT *
            FROM applications
            WHERE application_id = ?
              AND opportunity_id = ?
              AND status = 'Draft'";
    $types = 'ii';
    $params = [$draftId, $opportunityId];

    if (!empty($_SESSION['user_id'])) {
        $sql .= " AND submitted_by = ?";
        $types .= 'i';
        $params[] = (int)$_SESSION['user_id'];
    }

    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $draft = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| RESTORE UNSAVED INPUT AFTER AN ERROR
|--------------------------------------------------------------------------
| The processor stores the most recent POST payload in the session before
| redirecting back. These values take priority over an existing DB draft so
| applicants do not lose what they typed when validation/database saving fails.
*/
$oldInput = $_SESSION['application_old_input'] ?? [];
$hasOldInput = is_array($oldInput) && !empty($_SESSION['application_old_input_present']);

if (!is_array($oldInput)) {
    $oldInput = [];
}

// Flash data: available for this render only.
unset($_SESSION['application_old_input'], $_SESSION['application_old_input_present']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function rawApplicationValue(string $field, array $draft, mixed $default = ''): mixed
{
    global $oldInput, $hasOldInput;

    if ($hasOldInput && array_key_exists($field, $oldInput)) {
        return $oldInput[$field];
    }

    if (array_key_exists($field, $draft)) {
        return $draft[$field];
    }

    return $default;
}

function dv(string $field, array $draft, string $default = ''): string
{
    $value = rawApplicationValue($field, $draft, $default);

    if (is_array($value)) {
        return '';
    }

    return e($value);
}

function decodedArray(string $field, array $draft): array
{
    $raw = rawApplicationValue($field, $draft, []);

    // Checkbox groups/repeaters arrive from POST as arrays already.
    if (is_array($raw)) {
        return $raw;
    }

    if ($raw === null || trim((string)$raw) === '') {
        return [];
    }

    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function checkedValue(string $field, string $value, array $draft): string
{
    return ((string)rawApplicationValue($field, $draft, '') === $value)
        ? 'checked'
        : '';
}

function selectedValue(string $field, string $value, array $draft): string
{
    return ((string)rawApplicationValue($field, $draft, '') === $value)
        ? 'selected'
        : '';
}

function checkedArray(string $field, string $value, array $draft): string
{
    return in_array($value, decodedArray($field, $draft), true)
        ? 'checked'
        : '';
}

$incubationRows = decodedArray('incubation_programmes', $draft);
if (!$incubationRows) {
    $incubationRows = [['programme' => '', 'organisation' => '', 'year' => '', 'outcomes' => '']];
}

$founderRows = decodedArray('founders', $draft);
if (!$founderRows) {
    $founderRows = [['name' => '', 'role' => '', 'age' => '', 'gender' => '', 'shareholding' => '', 'full_time' => '', 'pwd_status' => '', 'pwd_type' => '']];
}

$teamRows = decodedArray('other_team_members', $draft);
if (!$teamRows) {
    $teamRows = [['name' => '', 'role' => '', 'age' => '', 'gender' => '', 'pwd_status' => '', 'pwd_type' => '']];
}

$fundingRows = decodedArray('external_funding_details', $draft);
if (!$fundingRows) {
    $fundingRows = [['funder' => '', 'type' => '', 'amount' => '', 'currency' => '', 'year' => '', 'status' => '']];
}

$sectorOptions = [
    'Early Learning & Primary Education',
    'Secondary & Higher Education',
    'Technical, Vocational & Workforce Development',
    'Teacher & Educator Support',
    'Adult Learning & Lifelong Skills',
    'Agriculture & Livelihood Education',
    'Inclusive & Special Needs Education',
    'Refugee & Marginalised Communities',
    'Education Systems & School Infrastructure',
    'Career Pathways & Employment Services',
];

$heardOptions = [
    'Fellowship website (edtech.hivecolab.com) or Hive Colab website',
    'Social media',
    'Referral from a partner organisation',
    'Referral from a past Fellowship venture',
    'Fellowship information session or masterclass',
    'Radio',
    'Television',
    'Newspaper or online article',
    'WhatsApp',
    'Email or newsletter',
    'Event, conference, or exhibition',
    'Other media coverage',
    'Other',
];

$regionOptions = [
    'All regions / national coverage',
    'Central Uganda',
    'Eastern Uganda',
    'Northern Uganda',
    'West Nile',
    'Western Uganda',
    'South Western Uganda',
    'Karamoja',
    'Refugee settlements',
    'Other',
];

$revenueModelOptions = [
    'B2C (learners/users pay directly)',
    'B2B (schools/institutions pay)',
    'B2G (government-funded/procured)',
    'Freemium',
    'Grant/donor-funded',
    'Other',
];

$dataUseOptions = [
    'Improve or refine our product/features',
    'Inform strategic or operational decision-making',
    'Monitor learner or user progress',
    'Measure programme or business impact',
    'Report to funders, investors, or partners',
    'Track customer satisfaction and feedback',
    'Identify areas for product improvement or innovation',
    'Support marketing and business development decisions',
    'Meet regulatory or compliance requirements',
    'We do not currently use data systematically.',
    'Other',
];


$opportunityContentSections = [];
if (!empty($opportunity['content_sections'])) {
    $decoded = json_decode((string)$opportunity['content_sections'], true);
    if (is_array($decoded)) $opportunityContentSections = $decoded;
}

$eligibilityBullets = [];
if (!empty($opportunity['eligibility_bullets'])) {
    $decoded = json_decode((string)$opportunity['eligibility_bullets'], true);
    if (is_array($decoded)) $eligibilityBullets = $decoded;
}

function safe_saved_html(?string $html): string
{
    // Opportunity HTML is sanitised on save by opportunity-process.php.
    return (string)($html ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<link rel="icon" type="image/png" href="images/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/opportunities.css">
<style>
:root{--brand:#f28c28;--brand-dark:#c96709;--ink:#1f2937;--muted:#6b7280;--border:#e5e7eb;--soft:#fff8f1;--danger:#b91c1c;--success:#15803d}
*{box-sizing:border-box}.apply-shell{max-width:1180px;margin:0 auto;padding:24px 18px 60px}.apply-notice{padding:14px 16px;border-radius:12px;margin:0 0 18px;border:1px solid var(--border);background:#fff}.apply-notice--error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.apply-notice--draft{border-color:#fde68a;background:#fffbeb}.progress-shell{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid var(--border)}.progress-inner{max-width:1180px;margin:auto;padding:12px 18px}.stepper{display:grid;grid-template-columns:repeat(9,minmax(0,1fr));gap:2px;overflow:visible}.step-btn{border:0;background:transparent;padding:7px 2px;cursor:pointer;color:var(--muted);font-weight:700;font-size:11px;min-width:0;white-space:nowrap}.step-btn span{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;margin:0 auto 5px;border:2px solid var(--border);background:#fff}.step-btn.active{color:var(--brand-dark)}.step-btn.active span,.step-btn.done span{border-color:var(--brand);background:var(--brand);color:#fff}.progress-track{height:5px;background:#f3f4f6;border-radius:999px;margin-top:10px;overflow:hidden}.progress-fill{height:100%;width:11.111%;background:var(--brand);transition:.25s}.form-section{display:none;background:#fff;border:1px solid var(--border);border-radius:18px;padding:26px;margin-top:24px;box-shadow:0 8px 24px rgba(0,0,0,.04)}.form-section.active{display:block}.section-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:22px}.section-head__icon{width:44px;height:44px;border-radius:12px;background:var(--soft);display:grid;place-items:center;color:var(--brand-dark);font-size:19px}.section-head h2{margin:0 0 4px;font-family:'Playfair Display',serif}.section-head p{margin:0;color:var(--muted)}.grid-2,.grid-3,.grid-4{display:grid;gap:16px}.grid-2{grid-template-columns:repeat(2,1fr)}.grid-3{grid-template-columns:repeat(3,1fr)}.grid-4{grid-template-columns:repeat(4,1fr)}.form-group{margin-bottom:18px}.form-group label,.group-label{display:block;font-weight:700;margin-bottom:7px;color:var(--ink)}.req{color:var(--danger)}.form-control{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:11px 12px;font:inherit;background:#fff}.form-control:focus{outline:2px solid rgba(242,140,40,.18);border-color:var(--brand)}textarea.form-control{resize:vertical;min-height:110px}.help{font-size:12px;color:var(--muted);margin-top:6px}.choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.choice{display:flex;align-items:flex-start;gap:8px;padding:11px;border:1px solid var(--border);border-radius:10px;background:#fff}.choice input{margin-top:3px}.conditional{display:none;margin-top:10px}.conditional.show{display:block}.repeater{border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px}.repeater-row{display:grid;gap:10px;padding:12px;border-bottom:1px solid var(--border);align-items:end}.repeater-row:last-child{border-bottom:0}.repeater-founders{grid-template-columns:1.4fr 1fr .55fr .85fr .8fr .8fr .9fr 42px}.repeater-team{grid-template-columns:1.5fr 1fr .6fr .9fr .9fr 1fr 42px}.repeater-programmes{grid-template-columns:1.2fr 1.2fr .55fr 1.7fr 42px}.repeater-funding{grid-template-columns:1.2fr .9fr .8fr .55fr .55fr .9fr 42px}.remove-row{height:42px;width:42px;border:0;border-radius:9px;background:#fef2f2;color:#b91c1c;cursor:pointer}.btn-add{border:1px dashed var(--brand);background:var(--soft);color:var(--brand-dark);padding:10px 14px;border-radius:9px;font-weight:700;cursor:pointer}.nav-bar{display:flex;gap:10px;align-items:center;margin-top:24px;border-top:1px solid var(--border);padding-top:20px}.nav-bar .spacer{flex:1}.btn{border:0;border-radius:10px;padding:11px 16px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none}.btn-primary{background:var(--brand);color:#fff}.btn-secondary{background:#fff;border:1px solid var(--border);color:var(--ink)}.btn-draft{background:#fff7ed;color:#9a4f0b;border:1px solid #fed7aa}.btn-success{background:var(--success);color:#fff}.word-counter{font-size:12px;text-align:right;color:var(--muted);margin-top:4px}.word-counter.over{color:var(--danger);font-weight:700}.privacy-box{padding:16px;border:1px solid #c7d2fe;background:#eef2ff;border-radius:12px;font-size:14px;line-height:1.6}.file-zone{border:1.5px dashed #cbd5e1;border-radius:12px;padding:18px;text-align:center;background:#fafafa;cursor:pointer}.file-zone i{font-size:26px;color:var(--brand-dark);margin-bottom:8px}.file-zone input{display:none}.file-note{font-size:12px;color:var(--muted);margin-top:6px}.mini-title{font-size:13px;font-weight:800;color:#374151;margin:0 0 8px}.metrics-table{width:100%;border-collapse:collapse}.metrics-table th,.metrics-table td{border:1px solid var(--border);padding:10px;vertical-align:top}.metrics-table th{text-align:left;background:#f9fafb}.metric-label{font-weight:600}.hidden{display:none!important}

.application-details-content{display:flex;flex-direction:column;gap:16px}
.detail-block{border:1px solid var(--border);border-radius:14px;padding:18px;background:#fff}
.detail-block h2,.detail-block h3,.detail-block h4{margin:0 0 10px;color:var(--ink)}
.detail-block p{line-height:1.7;margin:0 0 10px}
.detail-block ul,.detail-block ol{padding-left:22px;margin:10px 0}
.detail-block li{margin:7px 0;line-height:1.6}
.eligibility-block{background:#fffaf5;border-color:#fed7aa}
.detail-meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.detail-meta-grid>div{border:1px solid var(--border);border-radius:12px;padding:14px;background:#f8fafc}
.detail-meta-grid span{display:block;font-size:12px;color:var(--muted);margin-bottom:5px}
.detail-meta-grid strong{color:var(--ink)}
.rich-content a{color:var(--brand-dark);text-decoration:none}

@media(max-width:980px){.grid-3,.grid-4{grid-template-columns:1fr 1fr}.repeater-row{grid-template-columns:1fr 1fr}.repeater-row .remove-row{grid-column:2;justify-self:end}}
@media(max-width:700px){.detail-meta-grid{grid-template-columns:1fr}.grid-2,.grid-3,.grid-4,.choice-grid{grid-template-columns:1fr}.form-section{padding:18px}.stepper{grid-template-columns:repeat(9,90px);overflow-x:auto}.repeater-row{grid-template-columns:1fr}.repeater-row .remove-row{grid-column:1;justify-self:end}.nav-bar{flex-wrap:wrap}.nav-bar .spacer{display:none}.nav-bar .btn{flex:1;justify-content:center}}
</style>

<style>
/* Keep Google Translate UI hidden while allowing translations to run */
.goog-te-banner-frame,
.goog-te-banner-frame.skiptranslate,
.goog-te-gadget-icon {
    display: none !important;
}

body {
    top: 0 !important;
}

#google_translate_element {
    position: absolute !important;
    left: -10000px !important;
    top: auto !important;
    width: 1px !important;
    height: 1px !important;
    overflow: hidden !important;
}

.apply-language,
.apply-language select {
    display: inline-flex !important;
    visibility: visible !important;
    opacity: 1 !important;
}
</style>

<script src="js/accessibility.js"></script>
</head>
<body>

<div class="apply-topbar">
    <a href="./" class="apply-topbar__brand notranslate" translate="no" aria-label="Hive Colab home">
        <img src="images/logo.png" alt="Hive Colab">
    </a>
    <div class="apply-topbar__right">
        <div class="apply-topbar__deadline">
            <i class="fas fa-clock"></i>
            <span>Deadline: <?= date('d M Y', strtotime((string)$opportunity['deadline'])) ?></span>
        </div>

        <div class="apply-language notranslate" translate="no">
            <label for="applicationLanguage"><i class="fas fa-language"></i> Language</label>
            <select id="applicationLanguage" aria-label="Select application language">
                <option value="en">English</option>
                <option value="lg">Luganda</option>
                <option value="sw">Kiswahili</option>
            </select>
            <div id="google_translate_element" aria-hidden="true"></div>
        </div>
    </div>
</div>

<div class="apply-hero">
    <div class="apply-hero__inner">
        <div class="apply-hero__tag"><i class="fas fa-graduation-cap"></i> <?= e($opportunity['opportunity_type'] ?? 'Fellowship') ?></div>
        <h1><?= e($opportunity['opportunity_title']) ?></h1>
        <div class="apply-hero__meta">
            <span><i class="fas fa-calendar-alt"></i> Closes <?= date('d M Y', strtotime((string)$opportunity['deadline'])) ?></span>
            <?php if (!empty($opportunity['available_slots'])): ?>
                <span><i class="fas fa-users"></i> <?= (int)$opportunity['available_slots'] ?> slots available</span>
            <?php endif; ?>
            <span><i class="fas fa-location-dot"></i> Uganda</span>
        </div>
    </div>
</div>

<?php if (!$applicationsFull): ?>
<div class="progress-shell">
    <div class="progress-inner">
        <div class="stepper" id="stepper">
            <?php
            $steps = [
                ['Details', 'fa-circle-info'],
                ['Venture', 'fa-building'],
                ['Team', 'fa-users'],
                ['Problem', 'fa-lightbulb'],
                ['Traction', 'fa-chart-line'],
                ['Business', 'fa-coins'],
                ['Safeguarding', 'fa-shield-heart'],
                ['MEL', 'fa-chart-column'],
                ['Declarations', 'fa-file-signature'],
            ];
            foreach ($steps as $index => [$label, $icon]):
            ?>
                <button type="button" class="step-btn <?= $index === 0 ? 'active' : '' ?>" data-step="<?= $index ?>" onclick="goToStep(<?= $index ?>)">
                    <span><i class="fas <?= e($icon) ?>"></i></span><?= e($label) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="progress-track"><div class="progress-fill" id="progressFill"></div></div>
    </div>
</div>
<?php endif; ?>

<div class="apply-shell">

<?php if (!empty($_SESSION['error'])): ?>
    <div class="apply-notice apply-notice--error"><i class="fas fa-circle-exclamation"></i> <?= e($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if ($draft): ?>
    <div class="apply-notice apply-notice--draft"><i class="fas fa-pen-to-square"></i> You are continuing a saved draft. Review your answers before submitting.</div>
<?php endif; ?>


<?php if ($applicationsFull): ?>
    <div class="apply-notice apply-notice--error">
        <h2>Applications Closed</h2>
        <p>This opportunity has reached the maximum number of applicants.</p>
        <a href="apply" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> View Other Opportunities</a>
    </div>
<?php else: ?>

<form method="POST" action="/includes/submit-application-process.php" enctype="multipart/form-data" id="applicationForm" novalidate>
<input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
<input type="hidden" name="opportunity_id" value="<?= $opportunityId ?>">
<?php if ($draftId > 0): ?><input type="hidden" name="draft_id" value="<?= $draftId ?>"><?php endif; ?>
<input type="hidden" name="action" id="formAction" value="submit">

<!-- APPLICATION DETAILS -->
<section class="form-section active" id="section-0">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-circle-info"></i></div>
        <div><h2>Application Details</h2><p>Please read the opportunity information and eligibility requirements before completing your application.</p></div>
    </div>

    <div class="application-details-content">
        <?php if (!empty($opportunity['description'])): ?>
            <div class="detail-block"><?= safe_saved_html($opportunity['description']) ?></div>
        <?php endif; ?>

        <?php foreach ($opportunityContentSections as $section): ?>
            <div class="detail-block">
                <?php if (!empty($section['heading'])): ?><h3><?= e($section['heading']) ?></h3><?php endif; ?>
                <?php if (!empty($section['description_html'])): ?><div class="rich-content"><?= safe_saved_html($section['description_html']) ?></div><?php endif; ?>
                <?php if (!empty($section['bullets']) && is_array($section['bullets'])): ?>
                    <ul class="detail-bullets">
                        <?php foreach ($section['bullets'] as $bullet): ?><li><?= safe_saved_html((string)$bullet) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($opportunity['eligibility_heading']) || !empty($opportunity['eligibility_description_html']) || $eligibilityBullets): ?>
            <div class="detail-block eligibility-block">
                <h3><?= e($opportunity['eligibility_heading'] ?: 'Eligibility Criteria') ?></h3>
                <?php if (!empty($opportunity['eligibility_description_html'])): ?><div class="rich-content"><?= safe_saved_html($opportunity['eligibility_description_html']) ?></div><?php endif; ?>
                <?php if ($eligibilityBullets): ?>
                    <ul class="detail-bullets">
                        <?php foreach ($eligibilityBullets as $bullet): ?><li><?= safe_saved_html((string)$bullet) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($opportunity['required_documents'])): ?>
            <div class="detail-block">
                <h3><?= e($opportunity['documents_heading'] ?: 'Required Documents') ?></h3>
                <div class="rich-content"><?= safe_saved_html($opportunity['required_documents']) ?></div>
            </div>
        <?php endif; ?>

        <div class="detail-meta-grid">
            <div><span>Application Deadline</span><strong><?= date('d M Y', strtotime((string)$opportunity['deadline'])) ?></strong></div>
            <?php if (!empty($opportunity['announcement_date'])): ?><div><span>Expected Results</span><strong><?= date('d M Y', strtotime((string)$opportunity['announcement_date'])) ?></strong></div><?php endif; ?>
            <?php if (!empty($opportunity['available_slots'])): ?><div><span>Available Slots</span><strong><?= (int)$opportunity['available_slots'] ?></strong></div><?php endif; ?>
        </div>
    </div>

    <div class="nav-bar">
        <a href="apply" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a><div class="spacer"></div>
        <button type="button" class="btn btn-primary" onclick="nextStep(0)">Start Application <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- I. VENTURE INFORMATION & BACKGROUND -->
<section class="form-section" id="section-1">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-building"></i></div>
        <div><h2>I. Venture Information &amp; Background</h2><p>Tell us about your venture, registration, stage, focus areas and previous programme participation.</p></div>
    </div>

    <div class="form-group">
        <label>1. Venture Name <span class="req">*</span></label>
        <input class="form-control" type="text" name="startup_name" value="<?= dv('startup_name',$draft) ?>" required>
    </div>

    <div class="form-group">
        <label>2. Do you have a physical location? <span class="req">*</span></label>
        <div class="choice-grid">
            <label class="choice"><input type="radio" name="has_physical_location" value="Yes" <?= checkedValue('has_physical_location','Yes',$draft) ?> required> Yes</label>
            <label class="choice"><input type="radio" name="has_physical_location" value="No" <?= checkedValue('has_physical_location','No',$draft) ?> required> No</label>
        </div>
        <div class="conditional" data-show-when="has_physical_location:Yes">
            <div class="grid-2">
                <div><label>District</label><input class="form-control" type="text" name="district" value="<?= dv('district',$draft) ?>"></div>
                <div><label>City/Town</label><input class="form-control" type="text" name="city_town" value="<?= dv('city_town',$draft) ?>"></div>
            </div>
            <div class="grid-2" style="margin-top:12px">
                <div><label>Physical Address</label><input class="form-control" type="text" name="physical_address" value="<?= dv('physical_address',$draft) ?>"></div>
                <div><label>Google Maps Link (optional)</label><input class="form-control" type="url" name="google_maps_link" value="<?= dv('google_maps_link',$draft) ?>" placeholder="https://maps.google.com/..."></div>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <div class="form-group">
            <label>3. When was the venture founded? <span class="req">*</span></label>
            <input class="form-control" type="month" name="founded_month" value="<?= dv('founded_month',$draft) ?>" required>
        </div>
        <div class="form-group">
            <label>4. Has your venture been formally registered as a legal entity? <span class="req">*</span></label>
            <select class="form-control" name="formally_registered" required>
                <option value="">Select...</option>
                <option value="Yes" <?= selectedValue('formally_registered','Yes',$draft) ?>>Yes</option>
                <option value="No" <?= selectedValue('formally_registered','No',$draft) ?>>No</option>
            </select>
        </div>
    </div>

    <div class="conditional" data-show-when="formally_registered:Yes">
        <div class="grid-2">
            <div class="form-group">
                <label>5. Legal status of your venture</label>
                <select class="form-control" name="legal_status">
                    <option value="">Select...</option>
                    <?php foreach (['Limited Company','Sole Proprietorship','Partnership','Non-Governmental Organisation (NGO)','Community Based Organisation (CBO)','Other'] as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= selectedValue('legal_status',$opt,$draft) ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="conditional" data-show-when="legal_status:Other">
                    <input class="form-control" type="text" name="legal_status_other" value="<?= dv('legal_status_other',$draft) ?>" placeholder="Please specify">
                </div>
            </div>
            <div class="form-group">
                <label>6. Registration authority</label>
                <select class="form-control" name="registration_authority">
                    <option value="">Select...</option>
                    <?php foreach (['Uganda Registration Services Bureau (URSB)','NGO Bureau','Local Government','Other'] as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= selectedValue('registration_authority',$opt,$draft) ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="conditional" data-show-when="registration_authority:Other">
                    <input class="form-control" type="text" name="registration_authority_other" value="<?= dv('registration_authority_other',$draft) ?>" placeholder="Please specify">
                </div>
            </div>
        </div>
    </div>

    <div class="form-group">
        <label>7. Which best describes the current stage of your venture? <span class="req">*</span></label>
        <select class="form-control" name="business_stage" required>
            <option value="">Select...</option>
            <?php
            $stages = [
                'Early Revenue – generating initial revenue and validating the business model',
                'Growth Stage – growing customers and revenue with established product-market fit',
                'Scaling Stage – expanding rapidly across markets, segments, or geographies with established systems to support growth'
            ];
            foreach ($stages as $opt):
            ?>
                <option value="<?= e($opt) ?>" <?= selectedValue('business_stage',$opt,$draft) ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label>8. Which sector/focus area best describes your solution? <span class="req">*</span></label>
        <div class="choice-grid">
            <?php foreach ($sectorOptions as $opt): ?>
                <label class="choice"><input type="checkbox" name="sector_focus[]" value="<?= e($opt) ?>" <?= checkedArray('sector_focus',$opt,$draft) ?>> <?= e($opt) ?></label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="grid-2">
        <div class="form-group"><label>9. Link to Website</label><input class="form-control" type="url" name="website" value="<?= dv('website',$draft) ?>"></div>
        <div class="form-group"><label>10. Links to social media pages</label><textarea class="form-control" name="social_media_links" rows="3" placeholder="One link per line"><?= dv('social_media_links',$draft) ?></textarea></div>
    </div>

    <div class="form-group">
        <label>11. Have you previously participated in any incubation, acceleration, or fellowship programme? <span class="req">*</span></label>
        <select class="form-control" name="incubation_participated" required>
            <option value="">Select...</option>
            <option value="Yes" <?= selectedValue('incubation_participated','Yes',$draft) ?>>Yes</option>
            <option value="No" <?= selectedValue('incubation_participated','No',$draft) ?>>No</option>
        </select>
        <div class="conditional" data-show-when="incubation_participated:Yes">
            <div class="repeater" id="incubationRepeater">
                <?php foreach ($incubationRows as $i => $row): ?>
                <div class="repeater-row repeater-programmes">
                    <input class="form-control" name="incubation_programmes[<?= $i ?>][programme]" value="<?= e($row['programme'] ?? '') ?>" placeholder="Programme">
                    <input class="form-control" name="incubation_programmes[<?= $i ?>][organisation]" value="<?= e($row['organisation'] ?? '') ?>" placeholder="Organisation">
                    <input class="form-control" type="number" min="1900" max="2100" name="incubation_programmes[<?= $i ?>][year]" value="<?= e($row['year'] ?? '') ?>" placeholder="Year">
                    <input class="form-control" name="incubation_programmes[<?= $i ?>][outcomes]" value="<?= e($row['outcomes'] ?? '') ?>" placeholder="Key outcomes">
                    <button type="button" class="remove-row" onclick="removeRow(this)" aria-label="Remove row"><i class="fas fa-trash"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add" onclick="addProgrammeRow()"><i class="fas fa-plus"></i> Add programme</button>
        </div>
    </div>

    <div class="form-group">
        <label>12. How did you hear about the Mastercard Foundation EdTech Fellowship? <span class="req">*</span></label>
        <div class="choice-grid">
            <?php foreach ($heardOptions as $opt): ?>
                <label class="choice"><input type="checkbox" name="heard_about[]" value="<?= e($opt) ?>" <?= checkedArray('heard_about',$opt,$draft) ?>> <?= e($opt) ?></label>
            <?php endforeach; ?>
        </div>
        <div class="grid-2" style="margin-top:12px">
            <input class="form-control" type="text" name="heard_social_platform" value="<?= dv('heard_social_platform',$draft) ?>" placeholder="If social media, specify platform">
            <input class="form-control" type="text" name="heard_media_station" value="<?= dv('heard_media_station',$draft) ?>" placeholder="If radio/TV, specify station">
        </div>
        <input style="margin-top:12px" class="form-control" type="text" name="heard_other" value="<?= dv('heard_other',$draft) ?>" placeholder="Other - please specify">
    </div>

    <div class="nav-bar">
        <a href="apply" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a><div class="spacer"></div>
        <button type="button" class="btn btn-draft" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(1)">Next <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- II. FOUNDING TEAM -->
<section class="form-section" id="section-2">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-users"></i></div>
        <div><h2>II. Founding Team</h2><p>Add founders and team members, then identify the primary Fellowship contact.</p></div>
    </div>

    <div class="form-group">
        <label>1. Founders <span class="req">*</span></label>
        <div class="repeater" id="foundersRepeater">
            <?php foreach ($founderRows as $i => $row): ?>
            <div class="repeater-row repeater-founders">
                <input class="form-control" name="founders[<?= $i ?>][name]" value="<?= e($row['name'] ?? '') ?>" placeholder="Name">
                <input class="form-control" name="founders[<?= $i ?>][role]" value="<?= e($row['role'] ?? '') ?>" placeholder="Role">
                <input class="form-control" type="number" min="18" max="100" name="founders[<?= $i ?>][age]" value="<?= e($row['age'] ?? '') ?>" placeholder="Age">
                <select class="form-control" name="founders[<?= $i ?>][gender]"><option value="">Gender</option><?php foreach(['Female','Male','Prefer not to say','Other'] as $g): ?><option value="<?= e($g) ?>" <?= (($row['gender']??'')===$g)?'selected':'' ?>><?= e($g) ?></option><?php endforeach; ?></select>
                <input class="form-control" type="number" min="0" max="100" step="0.01" name="founders[<?= $i ?>][shareholding]" value="<?= e($row['shareholding'] ?? '') ?>" placeholder="Shareholding %">
                <select class="form-control" name="founders[<?= $i ?>][full_time]"><option value="">Full-time?</option><option value="Yes" <?= (($row['full_time']??'')==='Yes')?'selected':'' ?>>Yes</option><option value="No" <?= (($row['full_time']??'')==='No')?'selected':'' ?>>No</option></select>
                <input class="form-control" name="founders[<?= $i ?>][pwd_status]" value="<?= e($row['pwd_status'] ?? '') ?>" placeholder="PWD Y/N">
                <button type="button" class="remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                <input class="form-control" style="grid-column:1/-2" name="founders[<?= $i ?>][pwd_type]" value="<?= e($row['pwd_type'] ?? '') ?>" placeholder="Type of disability (optional)">
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add" onclick="addFounderRow()"><i class="fas fa-plus"></i> Add founder</button>
    </div>

    <div class="form-group">
        <label>2. Other Team Members</label>
        <div class="repeater" id="teamRepeater">
            <?php foreach ($teamRows as $i => $row): ?>
            <div class="repeater-row repeater-team">
                <input class="form-control" name="other_team_members[<?= $i ?>][name]" value="<?= e($row['name'] ?? '') ?>" placeholder="Name">
                <input class="form-control" name="other_team_members[<?= $i ?>][role]" value="<?= e($row['role'] ?? '') ?>" placeholder="Role">
                <input class="form-control" type="number" min="18" max="100" name="other_team_members[<?= $i ?>][age]" value="<?= e($row['age'] ?? '') ?>" placeholder="Age">
                <select class="form-control" name="other_team_members[<?= $i ?>][gender]"><option value="">Gender</option><?php foreach(['Female','Male','Prefer not to say','Other'] as $g): ?><option value="<?= e($g) ?>" <?= (($row['gender']??'')===$g)?'selected':'' ?>><?= e($g) ?></option><?php endforeach; ?></select>
                <input class="form-control" name="other_team_members[<?= $i ?>][pwd_status]" value="<?= e($row['pwd_status'] ?? '') ?>" placeholder="PWD Y/N">
                <input class="form-control" name="other_team_members[<?= $i ?>][pwd_type]" value="<?= e($row['pwd_type'] ?? '') ?>" placeholder="Type (optional)">
                <button type="button" class="remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add" onclick="addTeamRow()"><i class="fas fa-plus"></i> Add team member</button>
        <p class="help">Disability-status fields are collected for inclusion reporting only and should be treated as confidential.</p>
    </div>

    <div class="grid-2">
        <div class="form-group"><label>3. Full-time staff <span class="req">*</span></label><input class="form-control" type="number" min="0" name="full_time_staff" value="<?= dv('full_time_staff',$draft) ?>" required></div>
        <div class="form-group"><label>Part-time staff <span class="req">*</span></label><input class="form-control" type="number" min="0" name="part_time_staff" value="<?= dv('part_time_staff',$draft) ?>" required></div>
    </div>

    <div class="form-group">
        <label>4. Primary Fellowship contact <span class="req">*</span></label>
        <div class="grid-4">
            <input class="form-control" name="contact_person" value="<?= dv('contact_person',$draft) ?>" placeholder="Name" required>
            <input class="form-control" type="email" name="email" value="<?= dv('email',$draft) ?>" placeholder="Email" required>
            <input class="form-control" type="tel" name="phone" value="<?= dv('phone',$draft) ?>" placeholder="Phone" required>
            <select class="form-control" name="contact_gender" required>
                <option value="">Gender</option>
                <?php foreach(['Female','Male','Prefer not to say','Other'] as $g): ?><option value="<?= e($g) ?>" <?= selectedValue('contact_gender',$g,$draft) ?>><?= e($g) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label>5. Briefly explain why your team is best positioned to solve this EdTech problem. <span class="req">*</span></label>
        <textarea class="form-control word-limit" data-max-words="250" name="team_positioning" required><?= dv('team_positioning',$draft) ?></textarea>
        <div class="word-counter"></div>
    </div>

    <div class="nav-bar">
        <button type="button" class="btn btn-secondary" onclick="prevStep(2)"><i class="fas fa-arrow-left"></i> Back</button><div class="spacer"></div>
        <button type="button" class="btn btn-draft" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(2)">Next <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- III. THE PROBLEM & YOUR SOLUTION -->
<section class="form-section" id="section-3">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-lightbulb"></i></div>
        <div><h2>III. The Problem &amp; Your Solution</h2><p>Describe the education challenge, your users, solution, reach and product access.</p></div>
    </div>

    <div class="form-group">
        <label>1. Describe the education challenge your venture addresses and how your solution solves it. <span class="req">*</span></label>
        <textarea class="form-control word-limit" data-max-words="500" name="problem_solution" required placeholder="Include who experiences the problem, why it matters, your solution, how it works, and what makes it different."><?= dv('problem_solution',$draft) ?></textarea>
        <div class="word-counter"></div>
    </div>

    <div class="form-group"><label>2. Which learners or users do you primarily serve? <span class="req">*</span></label><textarea class="form-control" name="primary_users" required><?= dv('primary_users',$draft) ?></textarea></div>
    <div class="form-group"><label>3. Which languages is your solution available in? <span class="req">*</span></label><input class="form-control" name="solution_languages" value="<?= dv('solution_languages',$draft) ?>" required></div>
    <div class="form-group"><label>4. Which platforms or devices is your solution available on? <span class="req">*</span></label><input class="form-control" name="platforms_devices" value="<?= dv('platforms_devices',$draft) ?>" placeholder="e.g. Android, web, USSD, tablets" required></div>

    <div class="form-group">
        <label>5. Does it work offline? <span class="req">*</span></label>
        <div class="choice-grid">
            <label class="choice"><input type="radio" name="works_offline" value="Yes" <?= checkedValue('works_offline','Yes',$draft) ?> required> Yes</label>
            <label class="choice"><input type="radio" name="works_offline" value="No" <?= checkedValue('works_offline','No',$draft) ?> required> No</label>
        </div>
    </div>

    <div class="form-group">
        <label>6. Which districts or regions in Uganda does your solution currently serve? <span class="req">*</span></label>
        <div class="choice-grid">
            <?php foreach ($regionOptions as $opt): ?>
                <label class="choice"><input type="checkbox" name="regions_served[]" value="<?= e($opt) ?>" <?= checkedArray('regions_served',$opt,$draft) ?>> <?= e($opt) ?></label>
            <?php endforeach; ?>
        </div>
        <div class="grid-2" style="margin-top:12px">
            <input class="form-control" name="refugee_settlements_specify" value="<?= dv('refugee_settlements_specify',$draft) ?>" placeholder="Refugee settlements - specify">
            <input class="form-control" name="regions_other" value="<?= dv('regions_other',$draft) ?>" placeholder="Other - specify">
        </div>
    </div>

    <div class="form-group"><label>7. Link to your product demo</label><input class="form-control" type="url" name="demo_link" value="<?= dv('demo_link',$draft) ?>"></div>

    <div class="nav-bar">
        <button type="button" class="btn btn-secondary" onclick="prevStep(3)"><i class="fas fa-arrow-left"></i> Back</button><div class="spacer"></div>
        <button type="button" class="btn btn-draft" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(3)">Next <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- IV. TRACTION & GROWTH -->
<section class="form-section" id="section-4">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-chart-line"></i></div>
        <div><h2>IV. Traction &amp; Growth</h2><p>Provide your latest quantitative performance metrics. Use N/A where a metric is not applicable.</p></div>
    </div>

    <table class="metrics-table">
        <thead><tr><th>Metric</th><th style="width:34%">Value</th></tr></thead>
        <tbody>
            <?php
            $metrics = [
                'total_users_reached' => 'Total no. of users reached',
                'total_learners' => 'Total no. of learners using your platform',
                'active_learners' => 'Number of active learners using your solution/platform',
                'paying_customers' => 'Number of paying customers/clients',
                'partner_organisations' => 'Number of partner organisations serving/using your EdTech solution',
                'revenue_last_12_months' => 'Revenue generated in the last 12 months, or since founding if less than 12 months (UGX/USD)',
                'other_traction_metrics' => 'Other key traction metric(s) - please specify',
            ];
            foreach ($metrics as $field => $label):
            ?>
            <tr><td class="metric-label"><?= e($label) ?></td><td><input class="form-control" name="<?= e($field) ?>" value="<?= dv($field,$draft) ?>" required></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="nav-bar">
        <button type="button" class="btn btn-secondary" onclick="prevStep(4)"><i class="fas fa-arrow-left"></i> Back</button><div class="spacer"></div>
        <button type="button" class="btn btn-draft" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(4)">Next <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- V. BUSINESS MODEL & FUNDING -->
<section class="form-section" id="section-5">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-coins"></i></div>
        <div><h2>V. Business Model &amp; Funding</h2><p>Explain who pays, how you earn revenue, previous funding and how the Fellowship would support growth.</p></div>
    </div>

    <div class="form-group"><label>1. Who pays for your solution? <span class="req">*</span></label><textarea class="form-control" name="who_pays" required><?= dv('who_pays',$draft) ?></textarea></div>

    <div class="form-group">
        <label>2. Which revenue model best describes your venture? <span class="req">*</span></label>
        <div class="choice-grid">
            <?php foreach ($revenueModelOptions as $opt): ?>
                <label class="choice"><input type="checkbox" name="revenue_models[]" value="<?= e($opt) ?>" <?= checkedArray('revenue_models',$opt,$draft) ?>> <?= e($opt) ?></label>
            <?php endforeach; ?>
        </div>
        <input style="margin-top:12px" class="form-control" name="revenue_model_other" value="<?= dv('revenue_model_other',$draft) ?>" placeholder="Other revenue model - specify">
    </div>

    <div class="form-group"><label>How do you generate revenue? <span class="req">*</span></label><textarea class="form-control" name="revenue_streams" required><?= dv('revenue_streams',$draft) ?></textarea></div>

    <div class="form-group">
        <label>Have you raised external funding before? <span class="req">*</span></label>
        <select class="form-control" name="raised_external_funding" required>
            <option value="">Select...</option>
            <option value="Yes" <?= selectedValue('raised_external_funding','Yes',$draft) ?>>Yes</option>
            <option value="No" <?= selectedValue('raised_external_funding','No',$draft) ?>>No</option>
        </select>
        <div class="conditional" data-show-when="raised_external_funding:Yes">
            <div class="repeater" id="fundingRepeater">
                <?php foreach ($fundingRows as $i => $row): ?>
                <div class="repeater-row repeater-funding">
                    <input class="form-control" name="external_funding_details[<?= $i ?>][funder]" value="<?= e($row['funder'] ?? '') ?>" placeholder="Investor/Funder">
                    <select class="form-control" name="external_funding_details[<?= $i ?>][type]"><option value="">Type</option><?php foreach(['Grant','Equity','Debt','Other'] as $t): ?><option value="<?= e($t) ?>" <?= (($row['type']??'')===$t)?'selected':'' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                    <input class="form-control" type="number" min="0" step="0.01" name="external_funding_details[<?= $i ?>][amount]" value="<?= e($row['amount'] ?? '') ?>" placeholder="Amount">
                    <select class="form-control" name="external_funding_details[<?= $i ?>][currency]"><option value="">Currency</option><option value="UGX" <?= (($row['currency']??'')==='UGX')?'selected':'' ?>>UGX</option><option value="USD" <?= (($row['currency']??'')==='USD')?'selected':'' ?>>USD</option></select>
                    <input class="form-control" type="number" min="1900" max="2100" name="external_funding_details[<?= $i ?>][year]" value="<?= e($row['year'] ?? '') ?>" placeholder="Year">
                    <select class="form-control" name="external_funding_details[<?= $i ?>][status]"><option value="">Status</option><option value="Received" <?= (($row['status']??'')==='Received')?'selected':'' ?>>Received</option><option value="Pledged" <?= (($row['status']??'')==='Pledged')?'selected':'' ?>>Pledged</option></select>
                    <button type="button" class="remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add" onclick="addFundingRow()"><i class="fas fa-plus"></i> Add funder</button>
        </div>
    </div>

    <div class="form-group"><label>3. How would you use the Fellowship grant if awarded? <span class="req">*</span></label><textarea class="form-control word-limit" data-max-words="300" name="fellowship_grant_use" required><?= dv('fellowship_grant_use',$draft) ?></textarea><div class="word-counter"></div></div>
    <div class="form-group"><label>4. How will participating in this Fellowship help your venture grow? <span class="req">*</span></label><textarea class="form-control word-limit" data-max-words="250" name="fellowship_growth_value" required><?= dv('fellowship_growth_value',$draft) ?></textarea><div class="word-counter"></div></div>

    <div class="nav-bar">
        <button type="button" class="btn btn-secondary" onclick="prevStep(5)"><i class="fas fa-arrow-left"></i> Back</button><div class="spacer"></div>
        <button type="button" class="btn btn-draft" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(5)">Next <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- VI. SAFEGUARDING & INCLUSION -->
<section class="form-section" id="section-6">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-shield-heart"></i></div>
        <div><h2>VI. Safeguarding &amp; Inclusion</h2><p>Describe how your organisation protects children, young people, vulnerable adults, staff and communities.</p></div>
    </div>

    <div class="form-group">
        <label>1. Does your organisation have a Gender, Safeguarding, or Child Protection Policy in place? <span class="req">*</span></label>
        <select class="form-control" name="has_safeguarding_policy" required>
            <option value="">Select...</option><option value="Yes" <?= selectedValue('has_safeguarding_policy','Yes',$draft) ?>>Yes</option><option value="No" <?= selectedValue('has_safeguarding_policy','No',$draft) ?>>No</option>
        </select>
        <div class="conditional" data-show-when="has_safeguarding_policy:Yes">
            <div class="file-zone" onclick="document.getElementById('safeguarding_policy').click()">
                <i class="fas fa-file-shield"></i><p>Upload your policy</p>
                <input type="file" id="safeguarding_policy" name="safeguarding_policy" accept=".pdf,.doc,.docx">
                <div class="file-note" id="safeguarding_policy_name"><?= !empty($draft['safeguarding_policy_path']) ? 'Existing file uploaded - choose a file to replace it.' : 'PDF, DOC or DOCX.' ?></div>
            </div>
        </div>
    </div>

    <div class="form-group"><label>2. Describe the safeguarding measures your organisation has in place. <span class="req">*</span></label><textarea class="form-control word-limit" data-max-words="300" name="safeguarding_measures" required><?= dv('safeguarding_measures',$draft) ?></textarea><div class="word-counter"></div></div>

    <div class="form-group">
        <label>3. Do you have procedures for reporting and responding to safeguarding concerns? <span class="req">*</span></label>
        <select class="form-control" name="has_reporting_procedures" required><option value="">Select...</option><option value="Yes" <?= selectedValue('has_reporting_procedures','Yes',$draft) ?>>Yes</option><option value="No" <?= selectedValue('has_reporting_procedures','No',$draft) ?>>No</option></select>
        <div class="conditional" data-show-when="has_reporting_procedures:Yes"><textarea class="form-control" name="reporting_procedures_description" placeholder="Briefly describe the procedures"><?= dv('reporting_procedures_description',$draft) ?></textarea></div>
    </div>

    <div class="form-group">
        <label>4. Do your end users include minors (under 18)? <span class="req">*</span></label>
        <select class="form-control" name="users_include_minors" required><option value="">Select...</option><option value="Yes" <?= selectedValue('users_include_minors','Yes',$draft) ?>>Yes</option><option value="No" <?= selectedValue('users_include_minors','No',$draft) ?>>No</option></select>
        <div class="conditional" data-show-when="users_include_minors:Yes"><textarea class="form-control word-limit" data-max-words="150" name="parental_consent_process" placeholder="Describe how you obtain parental/guardian consent"><?= dv('parental_consent_process',$draft) ?></textarea><div class="word-counter"></div></div>
    </div>

    <div class="form-group">
        <label>5. Safeguarding focal point <span class="req">*</span></label>
        <div class="grid-3">
            <input class="form-control" name="safeguarding_focal_name" value="<?= dv('safeguarding_focal_name',$draft) ?>" placeholder="Name" required>
            <input class="form-control" name="safeguarding_focal_role" value="<?= dv('safeguarding_focal_role',$draft) ?>" placeholder="Role" required>
            <input class="form-control" name="safeguarding_focal_contact" value="<?= dv('safeguarding_focal_contact',$draft) ?>" placeholder="Contact" required>
        </div>
    </div>

    <div class="form-group"><label>6. How does your solution ensure accessibility and inclusion for underserved or vulnerable groups? <span class="req">*</span></label><textarea class="form-control word-limit" data-max-words="300" name="accessibility_inclusion" required><?= dv('accessibility_inclusion',$draft) ?></textarea><div class="word-counter"></div></div>

    <div class="nav-bar">
        <button type="button" class="btn btn-secondary" onclick="prevStep(6)"><i class="fas fa-arrow-left"></i> Back</button><div class="spacer"></div>
        <button type="button" class="btn btn-draft" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(6)">Next <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- VII. MEL -->
<section class="form-section" id="section-7">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-chart-column"></i></div>
        <div><h2>VII. Monitoring, Evaluation &amp; Learning (MEL)</h2><p>Explain how you measure impact, collect data and use evidence to improve your solution.</p></div>
    </div>

    <div class="form-group"><label>1. How do you currently measure the effectiveness or impact of your solution? <span class="req">*</span></label><textarea class="form-control word-limit" data-max-words="300" name="impact_measurement" required><?= dv('impact_measurement',$draft) ?></textarea><div class="word-counter"></div></div>

    <div class="form-group">
        <label>2. How frequently do you collect and review data on your users or beneficiaries? <span class="req">*</span></label>
        <select class="form-control" name="data_collection_frequency" required>
            <option value="">Select...</option>
            <?php foreach(['Daily','Weekly','Monthly','Quarterly','Annually','When needed','We do not collect data currently'] as $opt): ?><option value="<?= e($opt) ?>" <?= selectedValue('data_collection_frequency',$opt,$draft) ?>><?= e($opt) ?></option><?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label>3. How do you use the data you collect? <span class="req">*</span></label>
        <div class="choice-grid">
            <?php foreach ($dataUseOptions as $opt): ?><label class="choice"><input type="checkbox" name="data_uses[]" value="<?= e($opt) ?>" <?= checkedArray('data_uses',$opt,$draft) ?>> <?= e($opt) ?></label><?php endforeach; ?>
        </div>
        <input style="margin-top:12px" class="form-control" name="data_use_other" value="<?= dv('data_use_other',$draft) ?>" placeholder="Other - specify">
    </div>

    <div class="form-group">
        <label>4. Do you have a documented MEL framework, plan, or results framework? <span class="req">*</span></label>
        <select class="form-control" name="has_mel_framework" required><option value="">Select...</option><option value="Yes" <?= selectedValue('has_mel_framework','Yes',$draft) ?>>Yes</option><option value="No" <?= selectedValue('has_mel_framework','No',$draft) ?>>No</option></select>
    </div>

    <div class="form-group"><label>5. What tools or systems do you use to collect and manage data? <span class="req">*</span></label><textarea class="form-control" name="data_tools" required placeholder="e.g. ODK, KoboToolbox, in-house dashboard, spreadsheets"><?= dv('data_tools',$draft) ?></textarea></div>

    <div class="form-group"><label>6. What evidence do you currently have that your solution improves learning outcomes? <span class="req">*</span></label><textarea class="form-control word-limit" data-max-words="150" name="learning_outcomes_evidence" required><?= dv('learning_outcomes_evidence',$draft) ?></textarea><div class="word-counter"></div></div>

    <div class="form-group">
        <label>7. Are you registered with the Personal Data Protection Office (PDPO)? <span class="req">*</span></label>
        <select class="form-control" name="pdpo_status" required>
            <option value="">Select...</option>
            <option value="Yes" <?= selectedValue('pdpo_status','Yes',$draft) ?>>Yes</option>
            <option value="No" <?= selectedValue('pdpo_status','No',$draft) ?>>No</option>
            <option value="Registration in progress" <?= selectedValue('pdpo_status','Registration in progress',$draft) ?>>Registration in progress</option>
        </select>
    </div>

    <div class="nav-bar">
        <button type="button" class="btn btn-secondary" onclick="prevStep(7)"><i class="fas fa-arrow-left"></i> Back</button><div class="spacer"></div>
        <button type="button" class="btn btn-draft" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(7)">Next <i class="fas fa-arrow-right"></i></button>
    </div>
</section>

<!-- VIII. DECLARATIONS -->
<section class="form-section" id="section-8">
    <div class="section-head">
        <div class="section-head__icon"><i class="fas fa-file-signature"></i></div>
        <div><h2>VIII. Declarations</h2><p>Review the declarations, marketing consent and data privacy statement before submitting.</p></div>
    </div>

    <div class="form-group">
        <label>Conflict of Interest <span class="req">*</span></label>
        <p class="help">Do any founders, team members, or shareholders have a personal, family, or business relationship with Hive Colab staff?</p>
        <select class="form-control" name="conflict_of_interest" required><option value="">Select...</option><option value="Yes" <?= selectedValue('conflict_of_interest','Yes',$draft) ?>>Yes</option><option value="No" <?= selectedValue('conflict_of_interest','No',$draft) ?>>No</option></select>
        <div class="conditional" data-show-when="conflict_of_interest:Yes"><textarea class="form-control" name="conflict_description" placeholder="Please describe the relationship"><?= dv('conflict_description',$draft) ?></textarea></div>
    </div>

    <div class="form-group">
        <label>Marketing consent, part 1 - name and logo <span class="req">*</span></label>
        <div class="choice-grid">
            <label class="choice"><input type="radio" name="marketing_name_logo_consent" value="Yes" <?= checkedValue('marketing_name_logo_consent','Yes',$draft) ?> required> Yes</label>
            <label class="choice"><input type="radio" name="marketing_name_logo_consent" value="No" <?= checkedValue('marketing_name_logo_consent','No',$draft) ?> required> No</label>
        </div>
    </div>

    <div class="form-group">
        <label>Marketing consent, part 2 - content, photographs and quotes <span class="req">*</span></label>
        <div class="choice-grid">
            <label class="choice"><input type="radio" name="marketing_content_consent" value="Yes" <?= checkedValue('marketing_content_consent','Yes',$draft) ?> required> Yes</label>
            <label class="choice"><input type="radio" name="marketing_content_consent" value="No" <?= checkedValue('marketing_content_consent','No',$draft) ?> required> No</label>
        </div>
    </div>

    <div class="privacy-box">
        <strong>Safeguarding &amp; Data Privacy Statement</strong><br><br>
        The information submitted through this application will be used for evaluating applications to the Mastercard Foundation EdTech Fellowship in Uganda, conducting due diligence, programme design, monitoring and evaluation, and fulfilling donor compliance requirements. Hive Colab will treat submitted information as confidential and process personal and organisational data in accordance with the Data Protection and Privacy Act 2019, the Data Protection and Privacy Regulations 2021, and Hive Colab's Data Protection Policy. Application data will be retained for 24 months after the close of the selection process and may be accessed only by authorised programme personnel, evaluation committees and approved partners involved in selection and implementation.
    </div>

    <div class="form-group" style="margin-top:16px">
        <label class="choice">
            <input type="checkbox" name="data_privacy_consent" value="Yes" <?= checkedValue('data_privacy_consent','Yes',$draft) ?> required>
            I confirm I have read the Safeguarding &amp; Data Privacy Statement and consent to my data being processed as described.
        </label>
    </div>

    <div class="form-group">
        <label class="choice">
            <input type="checkbox" name="accuracy_declaration" value="Yes" <?= checkedValue('accuracy_declaration','Yes',$draft) ?> required>
            I confirm that the information provided is accurate to the best of my knowledge and that I am authorised to submit it on behalf of the venture.
        </label>
    </div>

    <div class="form-group">
        <label>Supporting legal/registration document (optional unless required by the opportunity)</label>
        <div class="file-zone" onclick="document.getElementById('legal_docs').click()">
            <i class="fas fa-file-arrow-up"></i><p>Upload supporting document</p>
            <input type="file" id="legal_docs" name="legal_docs" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.zip">
            <div class="file-note" id="legal_docs_name"><?= !empty($draft['legal_docs_path']) ? 'Existing file uploaded - choose a file to replace it.' : 'PDF, image, Word or ZIP.' ?></div>
        </div>
    </div>

    <div class="apply-notice">
        <strong>Before you submit:</strong> incomplete applications and applications that omit financial information may score lower. If a question does not apply, enter <strong>N/A</strong> with a short explanation rather than leaving it blank.
    </div>

    <div class="nav-bar">
        <button type="button" class="btn btn-secondary" onclick="prevStep(8)"><i class="fas fa-arrow-left"></i> Back</button><div class="spacer"></div>
        <button type="button" class="btn btn-draft" id="saveDraftBtn" onclick="saveDraft()"><i class="fas fa-floppy-disk"></i> Save Draft</button>
        <button type="button" class="btn btn-success" id="submitBtn" onclick="submitApplication()"><i class="fas fa-paper-plane"></i> Submit Application</button>
    </div>
</section>

</form>
<?php endif; ?>
</div>

<template id="programmeRowTemplate">
<div class="repeater-row repeater-programmes">
    <input class="form-control" data-name="incubation_programmes[__INDEX__][programme]" placeholder="Programme">
    <input class="form-control" data-name="incubation_programmes[__INDEX__][organisation]" placeholder="Organisation">
    <input class="form-control" type="number" min="1900" max="2100" data-name="incubation_programmes[__INDEX__][year]" placeholder="Year">
    <input class="form-control" data-name="incubation_programmes[__INDEX__][outcomes]" placeholder="Key outcomes">
    <button type="button" class="remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
</div>
</template>

<template id="founderRowTemplate">
<div class="repeater-row repeater-founders">
    <input class="form-control" data-name="founders[__INDEX__][name]" placeholder="Name">
    <input class="form-control" data-name="founders[__INDEX__][role]" placeholder="Role">
    <input class="form-control" type="number" min="18" max="100" data-name="founders[__INDEX__][age]" placeholder="Age">
    <select class="form-control" data-name="founders[__INDEX__][gender]"><option value="">Gender</option><option>Female</option><option>Male</option><option>Prefer not to say</option><option>Other</option></select>
    <input class="form-control" type="number" min="0" max="100" step="0.01" data-name="founders[__INDEX__][shareholding]" placeholder="Shareholding %">
    <select class="form-control" data-name="founders[__INDEX__][full_time]"><option value="">Full-time?</option><option>Yes</option><option>No</option></select>
    <input class="form-control" data-name="founders[__INDEX__][pwd_status]" placeholder="PWD Y/N">
    <button type="button" class="remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
    <input class="form-control" style="grid-column:1/-2" data-name="founders[__INDEX__][pwd_type]" placeholder="Type of disability (optional)">
</div>
</template>

<template id="teamRowTemplate">
<div class="repeater-row repeater-team">
    <input class="form-control" data-name="other_team_members[__INDEX__][name]" placeholder="Name">
    <input class="form-control" data-name="other_team_members[__INDEX__][role]" placeholder="Role">
    <input class="form-control" type="number" min="18" max="100" data-name="other_team_members[__INDEX__][age]" placeholder="Age">
    <select class="form-control" data-name="other_team_members[__INDEX__][gender]"><option value="">Gender</option><option>Female</option><option>Male</option><option>Prefer not to say</option><option>Other</option></select>
    <input class="form-control" data-name="other_team_members[__INDEX__][pwd_status]" placeholder="PWD Y/N">
    <input class="form-control" data-name="other_team_members[__INDEX__][pwd_type]" placeholder="Type (optional)">
    <button type="button" class="remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
</div>
</template>

<template id="fundingRowTemplate">
<div class="repeater-row repeater-funding">
    <input class="form-control" data-name="external_funding_details[__INDEX__][funder]" placeholder="Investor/Funder">
    <select class="form-control" data-name="external_funding_details[__INDEX__][type]"><option value="">Type</option><option>Grant</option><option>Equity</option><option>Debt</option><option>Other</option></select>
    <input class="form-control" type="number" min="0" step="0.01" data-name="external_funding_details[__INDEX__][amount]" placeholder="Amount">
    <select class="form-control" data-name="external_funding_details[__INDEX__][currency]"><option value="">Currency</option><option>UGX</option><option>USD</option></select>
    <input class="form-control" type="number" min="1900" max="2100" data-name="external_funding_details[__INDEX__][year]" placeholder="Year">
    <select class="form-control" data-name="external_funding_details[__INDEX__][status]"><option value="">Status</option><option>Received</option><option>Pledged</option></select>
    <button type="button" class="remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
</div>
</template>

<script>
let currentStep = 0;
const TOTAL_STEPS = 9;

function goToStep(step) {
    if (step < 0 || step >= TOTAL_STEPS) return;
    document.querySelectorAll('.form-section').forEach((section, i) => {
        section.classList.toggle('active', i === step);
    });
    document.querySelectorAll('.step-btn').forEach((btn, i) => {
        btn.classList.toggle('active', i === step);
        btn.classList.toggle('done', i < step);
    });
    currentStep = step;
    const fill = document.getElementById('progressFill');
    if (fill) fill.style.width = (((step + 1) / TOTAL_STEPS) * 100) + '%';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function validateSection(step) {
    const section = document.getElementById('section-' + step);
    const fields = section.querySelectorAll('input, select, textarea');
    for (const field of fields) {
        if (!field.checkValidity()) {
            field.reportValidity();
            field.focus();
            return false;
        }
    }

    const requiredCheckboxGroups = {
        1: ['sector_focus[]', 'heard_about[]'],
        3: ['regions_served[]'],
        5: ['revenue_models[]'],
        7: ['data_uses[]']
    };
    for (const name of (requiredCheckboxGroups[step] || [])) {
        if (!section.querySelector(`input[name="${name}"]:checked`)) {
            alert('Please select at least one option for all required multiple-selection questions.');
            return false;
        }
    }
    return true;
}

function nextStep(step) {
    if (!validateSection(step)) return;
    goToStep(step + 1);
}
function prevStep(step) { goToStep(step - 1); }

function updateConditionals() {
    document.querySelectorAll('[data-show-when]').forEach(box => {
        const [name, expected] = box.dataset.showWhen.split(':');
        const fields = document.querySelectorAll(`[name="${CSS.escape(name)}"]`);
        let current = '';
        fields.forEach(field => {
            if ((field.type === 'radio' || field.type === 'checkbox') && field.checked) current = field.value;
            else if (field.type !== 'radio' && field.type !== 'checkbox') current = field.value;
        });
        box.classList.toggle('show', current === expected);
    });
}
document.addEventListener('change', updateConditionals);

function words(text) {
    const trimmed = text.trim();
    return trimmed ? trimmed.split(/\s+/).length : 0;
}
function updateWordCounter(el) {
    const max = parseInt(el.dataset.maxWords || '0', 10);
    const count = words(el.value);
    const counter = el.parentElement.querySelector('.word-counter');
    if (!counter) return;
    counter.textContent = `${count} / ${max} words`;
    counter.classList.toggle('over', count > max);
}
document.querySelectorAll('.word-limit').forEach(el => {
    updateWordCounter(el);
    el.addEventListener('input', () => updateWordCounter(el));
});

function removeRow(btn) {
    const repeater = btn.closest('.repeater');
    if (!repeater) return;
    const rows = repeater.querySelectorAll('.repeater-row');
    if (rows.length <= 1) {
        rows[0].querySelectorAll('input,select,textarea').forEach(el => el.value = '');
        return;
    }
    btn.closest('.repeater-row').remove();
}

function addFromTemplate(templateId, targetId) {
    const target = document.getElementById(targetId);
    const index = target.querySelectorAll('.repeater-row').length;
    const template = document.getElementById(templateId);
    const node = template.content.cloneNode(true);
    node.querySelectorAll('[data-name]').forEach(el => {
        el.name = el.dataset.name.replace(/__INDEX__/g, index);
        el.removeAttribute('data-name');
    });
    target.appendChild(node);
}
function addProgrammeRow(){addFromTemplate('programmeRowTemplate','incubationRepeater')}
function addFounderRow(){addFromTemplate('founderRowTemplate','foundersRepeater')}
function addTeamRow(){addFromTemplate('teamRowTemplate','teamRepeater')}
function addFundingRow(){addFromTemplate('fundingRowTemplate','fundingRepeater')}

document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', function () {
        const target = document.getElementById(this.id + '_name');
        if (target) target.textContent = this.files.length ? this.files[0].name : '';
    });
});

function saveDraft() {
    const form = document.getElementById('applicationForm');
    document.getElementById('formAction').value = 'save_draft';
    form.querySelectorAll('[required]').forEach(el => {
        el.dataset.wasRequired = '1';
        el.removeAttribute('required');
    });
    form.submit();
}

function findFirstInvalidStep() {
    for (let step = 0; step < TOTAL_STEPS; step++) {
        const section = document.getElementById('section-' + step);
        const fields = section.querySelectorAll('input,select,textarea');
        for (const field of fields) {
            if (!field.checkValidity()) return step;
        }
    }
    return currentStep;
}

function submitApplication() {
    const form = document.getElementById('applicationForm');
    document.getElementById('formAction').value = 'submit';

    document.querySelectorAll('[data-was-required="1"]').forEach(el => {
        el.setAttribute('required','required');
        delete el.dataset.wasRequired;
    });

    let overLimit = false;
    document.querySelectorAll('.word-limit').forEach(el => {
        if (words(el.value) > parseInt(el.dataset.maxWords, 10)) {
            overLimit = true;
        }
    });
    if (overLimit) {
        alert('One or more answers exceed the permitted word limit. Please shorten those answers before submitting.');
        return;
    }

    if (!form.checkValidity()) {
        const step = findFirstInvalidStep();
        goToStep(step);
        setTimeout(() => form.reportValidity(), 100);
        return;
    }

    const requiredGroups = ['sector_focus[]','heard_about[]','regions_served[]','revenue_models[]','data_uses[]'];
    for (const name of requiredGroups) {
        if (!form.querySelector(`input[name="${name}"]:checked`)) {
            alert('Please complete all required multiple-selection questions.');
            return;
        }
    }

    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    form.submit();
}

updateConditionals();
</script>

<script>
/* ============================================================
   APPLICATION LANGUAGE SWITCHER
   Default language is always English on a fresh page load.
   Translation runs only after the applicant changes the selector.
   ============================================================ */
(function () {
    const select = document.getElementById('applicationLanguage');
    const supported = ['en', 'lg', 'sw'];

    if (!select) return;

    select.value = 'en';

    function normalise(lang) {
        return supported.includes(lang) ? lang : 'en';
    }

    function clearTranslateCookie() {
        const host = window.location.hostname;

        document.cookie =
            'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';

        if (host && host.includes('.')) {
            document.cookie =
                'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;domain=.' +
                host.replace(/^www\./, '');
        }
    }

    function setTranslateCookie(lang) {
        const value = '/en/' + lang;
        const host = window.location.hostname;

        document.cookie = 'googtrans=' + value + ';path=/;SameSite=Lax';

        if (host && host.includes('.')) {
            document.cookie =
                'googtrans=' + value +
                ';path=/;domain=.' + host.replace(/^www\./, '') +
                ';SameSite=Lax';
        }
    }

    function translateTo(lang, attempt = 0) {
        lang = normalise(lang);

        if (lang === 'en') {
            clearTranslateCookie();

            const combo = document.querySelector('.goog-te-combo');
            if (combo && combo.value && combo.value !== 'en') {
                combo.value = 'en';
                combo.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (
                document.documentElement.classList.contains('translated-ltr') ||
                document.documentElement.classList.contains('translated-rtl')
            ) {
                window.location.reload();
            }
            return;
        }

        const combo = document.querySelector('.goog-te-combo');

        if (combo) {
            setTranslateCookie(lang);
            combo.value = lang;
            combo.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }

        if (attempt < 40) {
            window.setTimeout(function () {
                translateTo(lang, attempt + 1);
            }, 250);
            return;
        }

        setTranslateCookie(lang);
        window.location.reload();
    }

    select.addEventListener('change', function () {
        translateTo(this.value);
    });

    window.initApplicationGoogleTranslate = function () {
        if (
            typeof google === 'undefined' ||
            !google.translate ||
            !google.translate.TranslateElement
        ) {
            return;
        }

        new google.translate.TranslateElement(
            {
                pageLanguage: 'en',
                includedLanguages: 'en,lg,sw',
                autoDisplay: false
            },
            'google_translate_element'
        );
    };

    clearTranslateCookie();
})();
</script>

<script
    src="https://translate.google.com/translate_a/element.js?cb=initApplicationGoogleTranslate"
    async>
</script>

</body>
</html>
