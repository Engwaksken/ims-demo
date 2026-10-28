<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/includes/config.php';

define('PORTAL_ACCESS', true);
require_once __DIR__ . '/includes/internet-portal.php';

// ── Filter: keep only today's or future events ───────────────────────────────
$today = date('Y-m-d');

$currentEvents = array_values(array_filter($events ?? [], function (array $ev) use ($today): bool {
    // Events with no date are treated as permanent/always-on
    if (empty($ev['event_date'])) {
        return true;
    }

    // Keep event if its date is today or in the future
    return $ev['event_date'] >= $today;
}));

// Sort ascending so the nearest event appears first
usort($currentEvents, function (array $a, array $b): int {
    $dA = $a['event_date'] ?? '9999-12-31';
    $dB = $b['event_date'] ?? '9999-12-31';

    if ($dA !== $dB) {
        return strcmp($dA, $dB);
    }

    $tA = $a['start_time'] ?? '00:00:00';
    $tB = $b['start_time'] ?? '00:00:00';

    return strcmp($tA, $tB);
});

$heroEvent    = $currentEvents[0] ?? null;   // first upcoming event for the hero
$eventCount   = count($currentEvents);

$brand        = ims_brand();
$brandName    = $brand['name'];
$brandEvent   = $brandName . ' Event';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($brandName) ?> - Internet Subscription Portal</title>

<link rel="icon" href="<?= h($brand['favicon']) ?>" type="image/png">
<link rel="shortcut icon" href="<?= h($brand['favicon']) ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= h($brand['favicon']) ?>">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/internet-portal.css">
<?= ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])) ?>
<style>
/* Map the portal palette onto the site theme; the stylesheet's colours remain the fallback. */
html:root {
  --brand: var(--brand-600, #FF6B00);
  --brand-dark: var(--brand-700, #D45200);
  --brand-light: var(--brand-50, #FFF3E8);
  --brand-glow: color-mix(in srgb, var(--brand) 18%, transparent);
}
.hero-slider { background: linear-gradient(135deg, var(--brand-700, #c2410c) 0%, var(--brand-500, #f97316) 55%, var(--brand-400, #fb923c) 100%); }
.hero-eyebrow-dot { background: var(--brand-200, #ffc89e); }
</style>
<script src="js/accessibility.js"></script>
</head>

<body>
<div class="portal-wrap">

    <!-- ── Top bar ──────────────────────────────────────────────────────── -->
    <div class="topbar">
        <div class="topbar-logo">
            <img src="<?= h($brand['logo']) ?>"
                 alt="<?= h($brandName) ?>"
                 onerror="this.style.display='none';
                          document.getElementById('logo-fallback').style.display='grid';">
            <div class="topbar-logo-fallback" id="logo-fallback" style="display:none">
                <i class="fas fa-wifi"></i>
            </div>
            <span class="topbar-brand"><?= h($brandName) ?></span>
        </div>

        <div class="topbar-mac">
            <i class="fas fa-laptop"></i>
            <?= $clientMac ? h($clientMac) : 'Device not detected' ?>
        </div>
    </div>

    <!-- ── Hero ─────────────────────────────────────────────────────────── -->
    <?php
    /* Pre-compute hero copy ------------------------------------------------ */
    $planCount = count($plans ?? []);

    if ($heroEvent) {
        $dateLabel = !empty($heroEvent['event_date'])
                       ? date('D, d M Y', strtotime($heroEvent['event_date']))
                       : '';
        $timeLabel = '';
        if (!empty($heroEvent['start_time'])) {
            $timeLabel = date('g:i A', strtotime($heroEvent['start_time']));
            if (!empty($heroEvent['end_time'])) {
                $timeLabel .= ' – ' . date('g:i A', strtotime($heroEvent['end_time']));
            }
        }
        $daysUntil  = !empty($heroEvent['event_date'])
                        ? (int)(new DateTime())->diff(new DateTime($heroEvent['event_date']))->days
                        : null;
        $eventType  = ucfirst($heroEvent['event_type'] ?? 'Event');
        $eventIcon  = eventIcon($heroEvent['event_type'] ?? '');

        /* Build a one-line meta summary for hero-sub when no description */
        $metaParts = array_filter([$dateLabel, $timeLabel, $heroEvent['venue'] ?? '']);
        $heroSubFallback = implode('  ·  ', $metaParts);
    }
    ?>

    <div class="hero-slider" id="heroSlider">
        <div class="hero-slider-inner">

            <!-- ── Left: text content ──────────────────────────────────── -->
            <div class="hero-slider-left" id="heroContent">

                <!-- Eyebrow pill -->
                <div class="hero-eyebrow">
                    <span class="hero-eyebrow-dot"></span>
                    <?php if ($heroEvent): ?>
                        <i class="fas <?= h($eventIcon) ?>"></i>
                        <?= h($eventType) ?>
                        <?php if ($eventCount > 1): ?>
                            &nbsp;·&nbsp; <?= $eventCount ?> Upcoming
                        <?php endif; ?>
                    <?php else: ?>
                        <i class="fas fa-wifi"></i>
                        WiFi Portal
                    <?php endif; ?>
                </div>

                <!-- Heading — mirrors opportunities h1 + .opp-hero-accent -->
                <?php if ($heroEvent): ?>
                <h1 id="heroTitle">
                    <?= h($heroEvent['event_title'] ?? $brandEvent) ?><br>
                    <span class="hero-accent" id="heroMeta">
                        <?php if ($dateLabel): ?>
                            <?= h($dateLabel) ?>
                            <?= $timeLabel ? '&nbsp;·&nbsp;' . h($timeLabel) : '' ?>
                        <?php else: ?>
                            <?= h($brandEvent) ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <?php else: ?>
                <h1>
                    <?= h($brandName) ?><br>
                    <span class="hero-accent">WiFi&nbsp;Portal</span>
                </h1>
                <?php endif; ?>

                <!-- Subtitle / description -->
                <?php if ($heroEvent && !empty($heroEvent['description'])): ?>
                <p class="hero-sub" id="heroDesc"
                   style="-webkit-line-clamp:2;display:-webkit-box;
                          -webkit-box-orient:vertical;overflow:hidden;">
                    <?= h($heroEvent['description']) ?>
                </p>

                <?php elseif ($heroEvent && $heroSubFallback): ?>
                <p class="hero-sub" id="heroDesc">
                    <?php if (!empty($heroEvent['venue'])): ?>
                        <i class="fas fa-location-dot" style="color:var(--brand-200, #ffc89e);margin-right:5px;"></i>
                        <?= h($heroEvent['venue']) ?>
                    <?php endif; ?>
                </p>

                <?php else: ?>
                <p class="hero-sub">
                    Stay connected. Select a plan below and upload your
                    payment receipt photo to get online.
                </p>
                <?php endif; ?>

                <!-- Multi-event nav (dots + prev/next) -->
                <?php if ($eventCount > 1): ?>
                <div class="hero-slider-actions" id="heroActions">

                    <button type="button" id="heroPrev" aria-label="Previous event"
                            style="display:inline-flex;align-items:center;gap:6px;
                                   padding:9px 16px;border-radius:999px;border:0;
                                   background:rgba(255,255,255,.15);backdrop-filter:blur(8px);
                                   color:#fff;font-size:12px;font-weight:700;cursor:pointer;
                                   transition:background .2s;">
                        <i class="fas fa-chevron-left"></i> Prev
                    </button>

                    <!-- Dot indicators -->
                    <div id="heroDots"
                         style="display:flex;align-items:center;gap:6px;">
                        <?php foreach ($currentEvents as $i => $ev): ?>
                        <button type="button"
                                class="hero-dot<?= $i === 0 ? ' hero-dot-active' : '' ?>"
                                data-index="<?= $i ?>"
                                aria-label="Event <?= $i + 1 ?>"
                                style="width:<?= $i === 0 ? '22px' : '7px' ?>;height:7px;
                                       border-radius:999px;border:0;cursor:pointer;padding:0;
                                       background:<?= $i === 0 ? 'var(--brand)' : 'rgba(255,255,255,.35)' ?>;
                                       transition:all .25s;"></button>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" id="heroNext" aria-label="Next event"
                            style="display:inline-flex;align-items:center;gap:6px;
                                   padding:9px 16px;border-radius:999px;border:0;
                                   background:rgba(255,255,255,.15);backdrop-filter:blur(8px);
                                   color:#fff;font-size:12px;font-weight:700;cursor:pointer;
                                   transition:background .2s;">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>

                </div>
                <?php endif; ?>

            </div><!-- /.hero-slider-left -->

            <!-- ── Right: stats bar (mirrors .opp-hero-stats) ─────────── -->
            <div class="hero-stats">

                <div class="hero-stat">
                    <span class="hero-stat-num"><?= $planCount ?></span>
                    <span class="hero-stat-label">Plans</span>
                </div>

                <div class="hero-stat">
                    <span class="hero-stat-num"><?= $eventCount ?></span>
                    <span class="hero-stat-label">Events</span>
                </div>

                <?php if ($heroEvent && $daysUntil !== null): ?>
                <div class="hero-stat">
                    <span class="hero-stat-num"><?= $daysUntil ?></span>
                    <span class="hero-stat-label">Days Left</span>
                </div>
                <?php else: ?>
                <div class="hero-stat">
                    <span class="hero-stat-num" style="font-size:20px;">
                        <i class="fas fa-wifi"></i>
                    </span>
                    <span class="hero-stat-label"><?= h(strtoupper($zone ?? 'LAN')) ?></span>
                </div>
                <?php endif; ?>

            </div><!-- /.hero-stats -->

        </div><!-- /.hero-slider-inner -->
    </div><!-- /.hero-slider -->

    <!-- JS event data for the carousel -->
    <?php if ($eventCount > 1): ?>
    <script>
    window.__heroEvents = <?= json_encode(array_map(function (array $ev) use ($brandEvent): array {
        $dl = !empty($ev['event_date']) ? date('D, d M Y', strtotime($ev['event_date'])) : '';
        $tl = '';
        if (!empty($ev['start_time'])) {
            $tl = date('g:i A', strtotime($ev['start_time']));
            if (!empty($ev['end_time'])) $tl .= ' – ' . date('g:i A', strtotime($ev['end_time']));
        }
        $du = !empty($ev['event_date'])
            ? (int)(new DateTime())->diff(new DateTime($ev['event_date']))->days
            : null;
        return [
            'title'       => $ev['event_title']  ?? $brandEvent,
            'description' => $ev['description']  ?? '',
            'eventType'   => ucfirst($ev['event_type'] ?? 'Event'),
            'eventIcon'   => eventIcon($ev['event_type'] ?? ''),
            'dateLabel'   => $dl,
            'timeLabel'   => $tl,
            'venue'       => $ev['venue']         ?? '',
            'daysUntil'   => $du,
        ];
    }, $currentEvents), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <?php endif; ?>

    <!-- ── Alerts ───────────────────────────────────────────────────────── -->
    <?php if (!empty($error)): ?>
    <div class="alert alert-error">
        <i class="fas fa-triangle-exclamation"></i>
        <div><?= h($error) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!$clientMac): ?>
    <div class="alert alert-error">
        <i class="fas fa-wifi"></i>
        <div>
            Device MAC address was not detected. Please open this page through
            the pfSense captive portal WiFi redirect.
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Main grid: plans + form ──────────────────────────────────────── -->
    <div class="main-grid">

        <!-- Plans -------------------------------------------------------- -->
        <div>
            <div class="section-label">
                <i class="fas fa-signal"></i> Available Plans
            </div>

            <div class="plans-grid" id="plansGrid">

                <?php if (!$plans): ?>
                <div class="empty-plans">
                    <i class="fas fa-circle-info"></i>
                    No active internet plans are currently available.
                </div>
                <?php endif; ?>

                <?php foreach ($plans as $plan): ?>
                    <?php
                    $price      = (float)($plan['price'] ?? 0);
                    $discount   = (float)($plan['discount_percent'] ?? 0);
                    $finalPrice = (float)($plan['final_price'] ?? 0);
                    $hasLogo    = !empty($plan['logo']);
                    $icon       = $planIconMap[$plan['duration_type'] ?? 'daily'] ?? 'fa-wifi';
                    ?>
                    <div class="plan-card"
                         data-plan-id="<?= (int)$plan['id'] ?>"
                         data-plan-name="<?= h($plan['plan_name']) ?>"
                         data-plan-price="<?= h(number_format($finalPrice)) ?>"
                         onclick="selectPlan(this)">

                        <?php if ($hasLogo): ?>
                        <img class="plan-logo"
                             src="<?= h($plan['logo']) ?>"
                             alt="<?= h($plan['plan_name']) ?>"
                             onerror="this.style.display='none'">
                        <?php else: ?>
                        <div class="plan-icon">
                            <i class="fas <?= h($icon) ?>"></i>
                        </div>
                        <?php endif; ?>

                        <div class="plan-body">
                            <div class="plan-name">
                                <?= h($plan['plan_name']) ?>
                                <?php if ($discount > 0): ?>
                                <span class="badge-discount">
                                    <?= number_format($discount, 0) ?>% OFF
                                </span>
                                <?php endif; ?>
                            </div>

                            <div class="plan-meta">
                                <span>
                                    <i class="fas fa-tag"></i>
                                    <?= h(ucfirst((string)($plan['duration_type'] ?? 'Plan'))) ?>
                                </span>
                                <span>
                                    <i class="fas fa-clock"></i>
                                    <?= h(formatDuration((int)($plan['duration_minutes'] ?? 0))) ?>
                                </span>
                                <?php if (!empty($plan['bandwidth_limit'])): ?>
                                <span>
                                    <i class="fas fa-gauge-high"></i>
                                    <?= h($plan['bandwidth_limit']) ?>
                                </span>
                                <?php endif; ?>
                            </div>

                            <div class="plan-pricing">
                                <?php if ($discount > 0): ?>
                                <span class="old-price">UGX <?= number_format($price) ?></span>
                                <?php endif; ?>
                                <span class="final-price">UGX <?= number_format($finalPrice) ?></span>
                            </div>
                        </div>

                        <div class="plan-select-indicator">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div>
        </div>

        <!-- Form --------------------------------------------------------- -->
        <div>
            <div class="section-label">
                <i class="fas fa-paper-plane"></i> Submit Request
            </div>

            <div class="form-card">

                <?php if ($success): ?>
                <div class="success-panel visible">
                    <div class="success-icon">
                        <i class="fas fa-check"></i>
                    </div>

                    <h3>Request Submitted!</h3>

                    <p>
                        Your internet access request has been submitted.
                        Please wait for Administrator or Operations/Admin approval.
                    </p>

                    <button class="btn-outline" onclick="resetForm()">
                        <i class="fas fa-arrow-left"></i> Submit Another
                    </button>
                </div>
                <?php else: ?>

                <div class="form-card-title">
                    <i class="fas fa-receipt"></i>
                    Submit Receipt Photo
                </div>

                <div class="steps-bar">
                    <div class="step active">
                        <div class="step-dot">1</div>
                        <span class="step-label">Pick Plan</span>
                    </div>
                    <div class="step active">
                        <div class="step-dot">2</div>
                        <span class="step-label">Your ID</span>
                    </div>
                    <div class="step active">
                        <div class="step-dot">3</div>
                        <span class="step-label">Upload Receipt</span>
                    </div>
                </div>

                <div class="selected-plan-preview" id="planPreview">
                    <i class="fas fa-check-circle" style="color:var(--brand);font-size:18px;"></i>
                    <span class="preview-name" id="previewName"></span>
                    <span class="preview-price" id="previewPrice"></span>
                </div>

                <form method="post"
                      id="portalForm"
                      enctype="multipart/form-data">

                    <input type="hidden" name="client_mac"  value="<?= h($clientMac ?? '') ?>">
                    <input type="hidden" name="client_ip"   value="<?= h($clientIp  ?? '') ?>">
                    <input type="hidden" name="ap_mac"      value="<?= h($apMac     ?? '') ?>">
                    <input type="hidden" name="site"        value="<?= h($site      ?? 'default') ?>">
                    <input type="hidden" name="zone"        value="<?= h($zone      ?? 'lan') ?>">
                    <input type="hidden" name="redirurl"    value="<?= h($redirurl  ?? portalDefaultRedirectUrl()) ?>">
                    <input type="hidden" name="receipt_mode" id="receiptMode" value="photo">

                    <div class="form-group">
                        <label for="membership_number">
                            <i class="fas fa-id-card" style="color:var(--brand);margin-right:5px;"></i>
                            Membership Number
                        </label>
                        <input type="text"
                               name="membership_number"
                               id="membership_number"
                               class="form-control"
                               placeholder="e.g. MEM-2026-0001"
                               value="<?= h($_POST['membership_number'] ?? '') ?>"
                               pattern="MEM-\d{4}-\d{4}"
                               title="Format: MEM-YYYY-0001"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="plan_id">
                            <i class="fas fa-signal" style="color:var(--brand);margin-right:5px;"></i>
                            Internet Plan
                        </label>
                        <select name="plan_id" id="plan_id" class="form-control" required>
                            <option value="">Select a plan</option>
                            <?php foreach ($plans as $plan): ?>
                            <option value="<?= (int)$plan['id'] ?>"
                                <?= isset($_POST['plan_id']) && (int)$_POST['plan_id'] === (int)$plan['id'] ? 'selected' : '' ?>>
                                <?= h($plan['plan_name']) ?> – UGX <?= number_format((float)$plan['final_price']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="fas fa-camera" style="color:var(--brand);margin-right:5px;"></i>
                            Receipt Photo
                        </label>

                        <input type="file"
                               name="receipt_photo"
                               id="receipt_photo"
                               accept="image/*"
                               style="display:none"
                               onchange="handleFileSelect(this)">

                        <input type="file"
                               id="receipt_camera"
                               accept="image/*"
                               capture="environment"
                               style="display:none"
                               onchange="copyCameraFile(this)">

                        <div class="upload-area"
                             id="uploadArea"
                             tabindex="0"
                             role="button"
                             aria-label="Upload receipt photo">

                            <i class="fas fa-cloud-arrow-up upload-icon"></i>
                            <span class="upload-label-main">Take a photo or choose from gallery</span>
                            <span class="upload-label-sub">JPG, PNG, WEBP. Max 5 MB</span>

                            <div class="upload-actions">
                                <button type="button"
                                        class="upload-action-btn"
                                        onclick="openCamera(event)">
                                    <i class="fas fa-camera"></i> Camera
                                </button>
                                <button type="button"
                                        class="upload-action-btn"
                                        onclick="openGallery(event)">
                                    <i class="fas fa-images"></i> Gallery
                                </button>
                            </div>
                        </div>

                        <div class="receipt-preview-wrap" id="previewWrap">
                            <img id="previewImg" src="" alt="Receipt preview">
                            <button type="button"
                                    class="receipt-preview-clear"
                                    onclick="clearPhoto()"
                                    aria-label="Remove photo">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>

                        <div class="receipt-preview-name" id="previewFileName"></div>

                        <div class="field-hint" style="margin-top:8px;">
                            <i class="fas fa-circle-info"></i>
                            Upload a clear receipt photo. Fake receipt numbers are no longer accepted.
                        </div>
                    </div>

                    <button type="submit"
                            class="btn-submit"
                            id="submitBtn"
                            <?= (!$plans || !$clientMac) ? 'disabled' : '' ?>>
                        <i class="fas fa-paper-plane"></i>
                        Submit for Approval
                    </button>
                </form>

                <div class="form-note">
                    <strong>Device:</strong>
                    <?= $clientMac ? h($clientMac) : 'Not detected' ?>
                    <?php if (!empty($clientIp)): ?>
                        <br><strong>IP:</strong> <?= h($clientIp) ?>
                    <?php endif; ?>
                    <br><strong>Zone:</strong> <?= h($zone ?? 'lan') ?>
                </div>

                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── Footer ───────────────────────────────────────────────────────── -->
    <div class="portal-footer">
        <span>&copy; <?= date('Y') ?> <?= h($brandName) ?> – WiFi Portal</span>

        <?php if ($clientMac): ?>
        <span class="footer-mac">
            <i class="fas fa-network-wired"></i>
            <?= h($clientMac) ?>
        </span>
        <?php endif; ?>

        <?php if ($brand['website'] !== ''): ?>
        <a href="<?= h($brand['website']) ?>" target="_blank" rel="noopener">
            <i class="fas fa-arrow-up-right-from-square"></i> <?= h($brand['website_label']) ?>
        </a>
        <?php endif; ?>
    </div>

</div><!-- /.portal-wrap -->

<script>
/* ── Plan selection ────────────────────────────────────────────────────── */
function selectPlan(card) {
    document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');

    const planId    = card.dataset.planId;
    const planName  = card.dataset.planName;
    const planPrice = card.dataset.planPrice;

    const sel = document.getElementById('plan_id');
    if (sel) sel.value = planId;

    const preview = document.getElementById('planPreview');
    if (preview) {
        document.getElementById('previewName').textContent  = planName;
        document.getElementById('previewPrice').textContent = 'UGX ' + planPrice;
        preview.classList.add('visible');
    }
}

(function () {
    const sel = document.getElementById('plan_id');
    if (!sel) return;
    sel.addEventListener('change', function () {
        const card = document.querySelector('[data-plan-id="' + this.value + '"]');
        if (card) selectPlan(card);
    });
    if (sel.value) {
        const card = document.querySelector('[data-plan-id="' + sel.value + '"]');
        if (card) selectPlan(card);
    }
})();

/* ── Receipt upload ─────────────────────────────────────────────────────── */
const MAX_BYTES = 5 * 1024 * 1024;

function openCamera(e) { e.preventDefault(); e.stopPropagation(); document.getElementById('receipt_camera')?.click(); }
function openGallery(e) { e.preventDefault(); e.stopPropagation(); document.getElementById('receipt_photo')?.click(); }

function copyCameraFile(cameraInput) {
    const file = cameraInput.files?.[0];
    if (!file) return;
    const mainInput = document.getElementById('receipt_photo');
    try {
        const t = new DataTransfer();
        t.items.add(file);
        mainInput.files = t.files;
        handleFileSelect(mainInput);
    } catch (_) {
        handleFileSelect(cameraInput);
    }
}

function handleFileSelect(input) {
    const file = input.files?.[0];
    if (!file) { clearPhoto(); return; }
    if (!file.type?.startsWith('image/')) { alert('Please select an image file only.'); clearPhoto(); return; }
    if (file.size > MAX_BYTES) { alert('The image is too large. Please choose a file under 5 MB.'); clearPhoto(); return; }

    const reader = new FileReader();
    reader.onload = e => {
        const previewImg      = document.getElementById('previewImg');
        const previewWrap     = document.getElementById('previewWrap');
        const previewFileName = document.getElementById('previewFileName');
        const uploadIcon      = document.querySelector('.upload-icon');
        const uploadLabel     = document.querySelector('.upload-label-main');
        if (previewImg)      previewImg.src = e.target.result;
        if (previewWrap)     previewWrap.classList.add('visible');
        if (previewFileName) previewFileName.textContent = file.name || 'Receipt photo selected';
        if (uploadIcon)      uploadIcon.className = 'fas fa-circle-check upload-icon';
        if (uploadLabel)     uploadLabel.textContent = 'Receipt image selected';
    };
    reader.readAsDataURL(file);
}

function clearPhoto() {
    ['receipt_photo','receipt_camera'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const previewImg      = document.getElementById('previewImg');
    const previewWrap     = document.getElementById('previewWrap');
    const previewFileName = document.getElementById('previewFileName');
    const uploadIcon      = document.querySelector('.upload-icon');
    const uploadLabel     = document.querySelector('.upload-label-main');
    if (previewImg)      previewImg.src = '';
    if (previewWrap)     previewWrap.classList.remove('visible');
    if (previewFileName) previewFileName.textContent = '';
    if (uploadIcon)      uploadIcon.className = 'fas fa-cloud-arrow-up upload-icon';
    if (uploadLabel)     uploadLabel.textContent = 'Take a photo or choose from gallery';
}

(function () {
    const area  = document.getElementById('uploadArea');
    const input = document.getElementById('receipt_photo');
    if (!area || !input) return;

    area.addEventListener('dragover',  e => { e.preventDefault(); area.classList.add('drag-over'); });
    area.addEventListener('dragleave', ()  => area.classList.remove('drag-over'));
    area.addEventListener('drop', e => {
        e.preventDefault();
        area.classList.remove('drag-over');
        const dt = e.dataTransfer;
        if (dt?.files?.length) {
            try {
                const t = new DataTransfer();
                t.items.add(dt.files[0]);
                input.files = t.files;
                handleFileSelect(input);
            } catch (_) {
                alert('Please use Camera or Gallery to upload the receipt on this device.');
            }
        }
    });
    area.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
})();

/* ── Form submit validation ─────────────────────────────────────────────── */
document.getElementById('portalForm')?.addEventListener('submit', function (e) {
    const mac   = document.querySelector('input[name="client_mac"]')?.value || '';
    const photo = document.getElementById('receipt_photo');
    if (!mac) {
        e.preventDefault();
        alert('Device MAC address was not detected. Please reconnect through the WiFi captive portal.');
        return;
    }
    if (!photo?.files?.[0]) {
        e.preventDefault();
        alert('Please take a photo or upload an image of your receipt.');
    }
});

/* ── Hero event carousel (only rendered when eventCount > 1) ─────────────── */
(function () {
    const events = window.__heroEvents;
    if (!events || events.length < 2) return;

    const titleEl   = document.getElementById('heroTitle');
    const descEl    = document.getElementById('heroDesc');
    const counterEl = document.getElementById('heroCounter');
    const prevBtn   = document.getElementById('heroPrev');
    const nextBtn   = document.getElementById('heroNext');
    const dots      = document.querySelectorAll('#heroDots button');

    let current = 0;
    let timer;

    function dotStyle(dot, active) {
        dot.style.background = active ? 'var(--brand)' : 'rgba(255,255,255,.35)';
        dot.style.width      = active ? '22px' : '7px';
    }

    const metaEl  = document.getElementById('heroMeta');
    const statNum = document.querySelector('.hero-stat:last-child .hero-stat-num');

    function goTo(idx) {
        if (!events.length) return;
        current = (idx + events.length) % events.length;
        const ev = events[current];

        // Fade transition
        const content = document.getElementById('heroContent');
        if (content) {
            content.style.transition = 'opacity .25s';
            content.style.opacity    = '0';
            setTimeout(() => {
                // Update title (first text node before the <br>)
                if (titleEl) {
                    titleEl.childNodes[0].textContent = ev.title;
                }
                // Update accent meta line
                if (metaEl) {
                    let meta = ev.dateLabel || '';
                    if (ev.timeLabel) meta += (meta ? '  \u00b7  ' : '') + ev.timeLabel;
                    metaEl.textContent = meta || <?= json_encode($brandEvent, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                }
                // Update description
                if (descEl) descEl.textContent = ev.description;
                // Update days-left stat
                if (statNum && ev.daysUntil !== null) statNum.textContent = ev.daysUntil;
                content.style.opacity = '1';
            }, 200);
        }

        dots.forEach((d, i) => dotStyle(d, i === current));
    }

    dots.forEach(d => {
        d.addEventListener('click', () => { goTo(parseInt(d.dataset.index, 10)); startAuto(); });
    });

    if (prevBtn) prevBtn.addEventListener('click', () => { goTo(current - 1); startAuto(); });
    if (nextBtn) nextBtn.addEventListener('click', () => { goTo(current + 1); startAuto(); });

    // Touch swipe on hero
    const hero = document.getElementById('heroSlider');
    let touchX = 0;
    hero?.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; }, { passive: true });
    hero?.addEventListener('touchend',   e => {
        const diff = touchX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) { goTo(diff > 0 ? current + 1 : current - 1); startAuto(); }
    });

    // Hover to pause
    hero?.addEventListener('mouseenter', () => clearInterval(timer));
    hero?.addEventListener('mouseleave', startAuto);

    function startAuto() {
        clearInterval(timer);
        timer = setInterval(() => goTo(current + 1), 5500);
    }

    startAuto();
})();

/* ── Reset / success redirect ───────────────────────────────────────────── */
function resetForm() {
    window.location.href = window.location.pathname + window.location.search;
}

<?php if ($success): ?>
setTimeout(() => { window.location.href = <?= json_encode(portalDefaultRedirectUrl(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>; }, 4000);
<?php endif; ?>
</script>

</body>
</html>