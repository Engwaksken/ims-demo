<?php


// --- CORS: allow any origin to embed this page ---
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors *');

require_once 'includes/config.php';
$brand = ims_brand();

$opportunities = [];
$query = "SELECT o.*,
    (SELECT COUNT(*) FROM applications WHERE opportunity_id = o.opportunity_id) as total_applications
    FROM application_opportunities o
    WHERE o.status = 'Published' AND o.deadline >= CURDATE()
    ORDER BY o.is_featured DESC, o.deadline ASC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $opportunities[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply - <?php echo htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($brand['favicon'], ENT_QUOTES, 'UTF-8'); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Arial:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">

    <style>
/* Links: no underlines (matches css/style.css); visible keyboard focus. */
a, a:hover, a:focus, a:active, a:visited { text-decoration: none; }
:where(a):focus-visible { outline: 2px solid var(--hive-orange); outline-offset: 2px; }
        /* ---- reset & tokens ---- */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* Accent colours follow the configured site theme (ims_theme_style_tag below)
           and fall back to the original palette when no theme is set. */
        :root {
            --hive-orange:   var(--brand-600, #FF6B2B);
            --hive-amber:    var(--brand-secondary, var(--brand-500, #FFA500));
            --hive-gold:     #FFD166;
            --hive-dark:     #1A1208;
            --hive-charcoal: #2D2416;
            --hive-muted:    #7A6E62;
            --hive-cream:    #FFF8F2;
            --hive-white:    #FFFFFF;
            --hive-border:   #F0E8DF;
            --radius-card:   16px;
            --radius-pill:   999px;
            --shadow-card:   0 2px 12px rgba(26,18,8,.08), 0 8px 32px color-mix(in srgb, var(--hive-orange) 7%, transparent);
            --shadow-hover:  0 8px 32px rgba(26,18,8,.12), 0 20px 48px color-mix(in srgb, var(--hive-orange) 14%, transparent);
            --transition:    .28s cubic-bezier(.34,1.56,.64,1);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--hive-cream);
            color: var(--hive-dark);
            min-height: 100vh;
            padding: 0;
            /* Transparent bg so host site bg shows through if desired */
            background: transparent;
        }

        /* ---- widget shell ---- */
        .hc-widget {
            width: 100%;
            padding: 32px 24px 48px;
        }

        /* ---- header ---- */
        .hc-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .hc-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--hive-orange);
            background: color-mix(in srgb, var(--hive-orange) 8%, transparent);
            border: 1px solid color-mix(in srgb, var(--hive-orange) 20%, transparent);
            padding: 6px 16px;
            border-radius: var(--radius-pill);
            margin-bottom: 18px;
        }

        .hc-title {
            font-family: 'Arial', sans-serif;
            font-size: clamp(26px, 5vw, 40px);
            font-weight: 800;
            line-height: 1.15;
            color: var(--hive-dark);
            margin-bottom: 12px;
        }

        .hc-title span {
            background: linear-gradient(135deg, var(--hive-orange), var(--hive-amber));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hc-subtitle {
            font-size: 15px;
            color: var(--hive-muted);
            font-weight: 300;
            max-width: 480px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* ---- grid ---- */
        .hc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 22px;
        }

        /* ---- card ---- */
        .hc-card {
            background: var(--hive-white);
            border-radius: var(--radius-card);
            padding: 26px;
            box-shadow: var(--shadow-card);
            border: 1.5px solid var(--hive-border);
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
            animation: cardIn .5s both;
        }

        .hc-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-hover);
            border-color: color-mix(in srgb, var(--hive-orange) 25%, transparent);
        }

        .hc-card.featured {
            border-color: var(--hive-gold);
            background: linear-gradient(160deg, #fffbf2 0%, var(--hive-white) 60%);
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* stagger children */
        .hc-card:nth-child(2) { animation-delay: .06s; }
        .hc-card:nth-child(3) { animation-delay: .12s; }
        .hc-card:nth-child(4) { animation-delay: .18s; }
        .hc-card:nth-child(5) { animation-delay: .24s; }
        .hc-card:nth-child(6) { animation-delay: .30s; }

        /* featured accent */
        .hc-featured-bar {
            height: 3px;
            border-radius: var(--radius-pill);
            background: linear-gradient(90deg, var(--hive-gold), var(--hive-orange));
            margin: -26px -26px 0;
            border-radius: var(--radius-card) var(--radius-card) 0 0;
        }

        .hc-featured-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #9A6800;
            background: rgba(255,209,102,.2);
            border: 1px solid rgba(255,209,102,.5);
            padding: 4px 10px;
            border-radius: var(--radius-pill);
        }

        .hc-type-tag {
            display: inline-flex;
            align-items: center;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--hive-white);
            background: linear-gradient(135deg, var(--hive-orange), var(--hive-amber));
            padding: 5px 12px;
            border-radius: var(--radius-pill);
            align-self: flex-start;
        }

        .hc-card-title {
            font-family: 'Arial', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--hive-dark);
            line-height: 1.3;
        }

        .hc-card-desc {
            font-size: 13.5px;
            color: var(--hive-muted);
            line-height: 1.65;
            font-weight: 300;
            flex: 1;
        }

        /* deadline pill */
        .hc-deadline {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 600;
            padding: 9px 14px;
            border-radius: 10px;
            background: rgba(231,76,60,.07);
            color: #C0392B;
            border: 1px solid rgba(231,76,60,.15);
        }

        .hc-deadline.soon {
            background: color-mix(in srgb, var(--hive-orange) 8%, transparent);
            color: var(--hive-orange);
            border-color: color-mix(in srgb, var(--hive-orange) 20%, transparent);
        }

        .hc-deadline i { font-size: 13px; opacity: .8; }

        /* meta rows */
        .hc-meta {
            display: flex;
            flex-direction: column;
            gap: 7px;
            font-size: 13px;
            color: var(--hive-muted);
        }

        .hc-meta-row {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .hc-meta-row i {
            width: 16px;
            text-align: center;
            color: var(--hive-orange);
            opacity: .8;
            font-size: 12px;
        }

        .hc-meta-row strong { color: var(--hive-charcoal); }

        /* slots bar */
        .hc-slots {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--hive-muted);
        }

        .hc-slots-track {
            flex: 1;
            height: 5px;
            background: #EDE8E2;
            border-radius: var(--radius-pill);
            overflow: hidden;
        }

        .hc-slots-fill {
            height: 100%;
            border-radius: var(--radius-pill);
            background: linear-gradient(90deg, #27AE60, #52D68A);
            transition: width .8s cubic-bezier(.22,1,.36,1);
        }

        /* CTA button */
        .hc-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 14px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-family: 'Arial', sans-serif;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .03em;
            color: var(--hive-white);
            background: linear-gradient(135deg, var(--hive-orange) 0%, var(--hive-amber) 100%);
            text-decoration: none;
            transition: transform var(--transition), box-shadow var(--transition), filter .2s;
            box-shadow: 0 4px 18px color-mix(in srgb, var(--hive-orange) 30%, transparent);
            position: relative;
            overflow: hidden;
        }

        .hc-btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.18) 0%, transparent 60%);
            pointer-events: none;
        }

        .hc-btn:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 28px color-mix(in srgb, var(--hive-orange) 45%, transparent);
            filter: brightness(1.06);
        }

        .hc-btn:active { transform: scale(.98); }

        /* ---- empty state ---- */
        .hc-empty {
            text-align: center;
            padding: 64px 32px;
            background: var(--hive-white);
            border-radius: var(--radius-card);
            border: 1.5px dashed var(--hive-border);
        }

        .hc-empty-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--hive-orange) 7%, transparent);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
            color: var(--hive-orange);
            opacity: .6;
        }

        .hc-empty h3 {
            font-family: 'Arial', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--hive-dark);
            margin-bottom: 8px;
        }

        .hc-empty p {
            font-size: 14px;
            color: var(--hive-muted);
            line-height: 1.6;
        }

        /* ---- footer brand ---- */
        .hc-footer {
            text-align: center;
            margin-top: 40px;
            font-size: 12px;
            color: var(--hive-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .hc-footer a {
            color: var(--hive-orange);
            text-decoration: none;
            font-weight: 600;
        }

        /* ---- responsive ---- */
        @media (max-width: 580px) {
            .hc-widget { padding: 20px 14px 36px; }
            .hc-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php echo ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])); ?>
</head>
<body>

<div class="hc-widget">

    <!-- Header -->
    <div class="hc-header">
        <div class="hc-eyebrow">
            <i class="fas fa-rocket"></i> Open Applications
        </div>
        <h1 class="hc-title">Grow with <span><?php echo htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8'); ?></span></h1>
        <p class="hc-subtitle">
            Join our innovation ecosystem - apply to programs built to take your startup further.
        </p>
    </div>

    <?php if (empty($opportunities)): ?>

        <div class="hc-empty">
            <div class="hc-empty-icon"><i class="fas fa-hourglass-half"></i></div>
            <h3>No Open Applications Right Now</h3>
            <p>We're preparing exciting new programs.<br>Check back soon or follow us for updates.</p>
        </div>

    <?php else: ?>

        <div class="hc-grid">
            <?php foreach ($opportunities as $opp):
                $deadline      = new DateTime($opp['deadline']);
                $today         = new DateTime();
                $days_left     = (int) $today->diff($deadline)->days;
                $app_pct       = 0;
                if (!empty($opp['max_applicants']) && $opp['max_applicants'] > 0) {
                    $app_pct = min(round(($opp['total_applications'] / $opp['max_applicants']) * 100), 100);
                }
                $is_featured   = !empty($opp['is_featured']);
                $deadline_cls  = $days_left <= 7 ? 'hc-deadline' : 'hc-deadline soon';
            ?>
            <div class="hc-card <?php echo $is_featured ? 'featured' : ''; ?>">

                <?php if ($is_featured): ?>
                    <div class="hc-featured-bar"></div>
                    <span class="hc-featured-badge"><i class="fas fa-star"></i> Featured</span>
                <?php endif; ?>

                <span class="hc-type-tag">
                    <?php echo htmlspecialchars($opp['opportunity_type']); ?>
                </span>

                <h2 class="hc-card-title">
                    <?php echo htmlspecialchars($opp['opportunity_title']); ?>
                </h2>

                <p class="hc-card-desc">
                    <?php echo htmlspecialchars(substr($opp['description'], 0, 160)) . (strlen($opp['description']) > 160 ? '...' : ''); ?>
                </p>

                <div class="<?php echo $deadline_cls; ?>">
                    <i class="fas fa-clock"></i>
                    <?php echo $days_left; ?> day<?php echo $days_left !== 1 ? 's' : ''; ?> left to apply
                </div>

                <div class="hc-meta">
                    <div class="hc-meta-row">
                        <i class="fas fa-calendar-alt"></i>
                        <span><strong>Deadline:</strong> <?php echo date('d M Y', strtotime($opp['deadline'])); ?></span>
                    </div>
                    <?php if (!empty($opp['start_date'])): ?>
                    <div class="hc-meta-row">
                        <i class="fas fa-calendar-check"></i>
                        <span><strong>Starts:</strong> <?php echo date('d M Y', strtotime($opp['start_date'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($opp['available_slots'])): ?>
                    <div class="hc-meta-row">
                        <i class="fas fa-users"></i>
                        <span><strong>Slots:</strong> <?php echo (int)$opp['available_slots']; ?> startups</span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($opp['max_applicants']) && $opp['max_applicants'] > 0): ?>
                <div class="hc-slots">
                    <div class="hc-slots-track">
                        <div class="hc-slots-fill" style="width:<?php echo $app_pct; ?>%"></div>
                    </div>
                    <span><?php echo (int)$opp['total_applications']; ?>/<?php echo (int)$opp['max_applicants']; ?></span>
                </div>
                <?php endif; ?>

                <a href="submit-application?opportunity_id=<?php echo (int)$opp['opportunity_id']; ?>"
                   class="hc-btn"
                   target="_parent">
                    <i class="fas fa-paper-plane"></i> Apply Now
                </a>

            </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <div class="hc-footer">
        Powered by <a href="<?php echo htmlspecialchars($brand['website'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8'); ?></a>
    </div>

</div>

<!-- Post height to parent so iframe auto-resizes -->
<script>
    function postHeight() {
        var height = document.body.scrollHeight;
        // 'hivecolab-height' is kept for sites still running older copies of apply-embed.js.
        window.parent.postMessage({ type: 'ims-apply-height', height: height }, '*');
        window.parent.postMessage({ type: 'hivecolab-height', height: height }, '*');
    }
    postHeight();
    window.addEventListener('resize', postHeight);
</script>

</body>
</html>