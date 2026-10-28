<?php
require_once __DIR__ . '/includes/visitor-register-handler.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($hub_name, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="icon" type="image/png" href="<?= htmlspecialchars(ims_brand()['favicon'], ENT_QUOTES, 'UTF-8') ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(ims_brand()['favicon'], ENT_QUOTES, 'UTF-8') ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(ims_brand()['favicon'], ENT_QUOTES, 'UTF-8') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="css/visitor-register.css">
    <?= ims_theme_style_tag((array)($GLOBALS['IMS_SITE_BRANDING']['theme'] ?? [])) ?>
    <style>
    /* Map the page palette onto the site theme; falls back to the stylesheet's colours when no theme is set. */
    html:root {
        --brand: var(--brand-600, #ff6b35);
        --brand-dark: var(--brand-700, #e55a28);
        --brand-light: var(--brand-secondary, var(--brand-500, #ff9800));
        --brand-pale: var(--brand-100, #fff3e0);
        --brand-faint: var(--brand-50, #fffaf6);
        --border-focus: var(--brand-secondary, var(--brand-500, #ff9800));
    }
    </style>
<script src="js/accessibility.js"></script>
</head>
<body>

<nav class="nav-bar">
    <div class="nav-brand">
        <div class="nav-logo">
            <img src="<?= htmlspecialchars(ims_brand()['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($hub_name, ENT_QUOTES, 'UTF-8') ?> logo" class="nav-logo-img">
        </div>
        <div class="nav-title">
            <?= htmlspecialchars($hub_name, ENT_QUOTES, 'UTF-8') ?> <span>Visitors</span>
        </div>
    </div>
    <div class="nav-pill">Sign-In Open</div>
</nav>

<div class="page-wrap">

<?php if ($submitted): ?>
    <div class="card">
        <div class="success-wrap">
            <div class="success-circle"><i class="fas fa-check"></i></div>
            <h2>Welcome, <em><?= $visitor_name_success ?></em></h2>
            <p class="success-msg">
                Your visit has been logged and our team notified.<br>
                Please make yourself comfortable and enjoy your time at <?= htmlspecialchars($hub_name, ENT_QUOTES, 'UTF-8') ?>.
            </p>

            <div class="chips">
                <div class="chip"><i class="fas fa-calendar-day"></i><?= $date_success ?></div>
                <?php if ($time_success !== ''): ?>
                    <div class="chip"><i class="fas fa-clock"></i><?= $time_success ?></div>
                <?php endif; ?>
                <div class="chip"><i class="fas fa-tag"></i><?= $purpose_success ?></div>
                <div class="chip"><i class="fas fa-shield-halved"></i>Entry Confirmed</div>
            </div>

            <a href="visitor-register" class="btn-again">
                <i class="fas fa-user-plus"></i> Register Another Visitor
            </a>
        </div>
    </div>
<?php else: ?>

    <div class="hero">
        <div class="hero-ring"><i class="fas fa-id-badge"></i></div>
        <h1>Visitor Sign-In</h1>
        <p>Quick 3-step check-in with member lookup and visitor profiling.</p>
    </div>

    <div class="step-track" id="stepTrack">
        <div class="step-item active" id="si-1">
            <div class="step-dot"><span class="step-num">1</span></div>
            <div class="step-label">About You</div>
        </div>
        <div class="step-line" id="sl-1"></div>
        <div class="step-item" id="si-2">
            <div class="step-dot"><span class="step-num">2</span></div>
            <div class="step-label">Your Visit</div>
        </div>
        <div class="step-line" id="sl-2"></div>
        <div class="step-item" id="si-3">
            <div class="step-dot"><span class="step-num">3</span></div>
            <div class="step-label">Confirm</div>
        </div>
    </div>

    <?php if ($error_msg): ?>
        <div class="alert-err">
            <i class="fas fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div style="height:14px;"></div>
    <?php endif; ?>

    <div class="card" id="mainCard">
        <form method="POST" action="" id="regForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="register_visitor" value="1">
            <input type="hidden" name="member_user_id" id="member_user_id" value="<?= oldv('member_user_id', '0') ?>">

            <div class="step-panel active" id="panel-1">
                <div class="panel-eyebrow"><i class="fas fa-user"></i> Personal Info</div>
                <div class="panel-title">Let's start with you</div>
                <div class="panel-sub">Choose visitor type first, then fill in or fetch details automatically.</div>
                <p class="req-note">Fields marked <span>*</span> are required.</p>

                <div class="choice-wrap">
                    <div class="field">
                        <label>Is Member? <span class="req">*</span></label>
                        <select name="is_member" id="f_is_member" required>
                            <option value="0" <?= oldv('is_member', '0') === '0' ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= oldv('is_member', '0') === '1' ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>

                    <div class="field" id="firstVisitField">
                        <label>First Visit? <span class="req">*</span></label>
                        <select name="is_first_time" id="f_is_first_time" required>
                            <option value="0" <?= oldv('is_first_time', '1') === '0' ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= oldv('is_first_time', '1') === '1' ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Nationality <span class="req">*</span></label>
                        <select name="nationality" id="f_nationality" required>
                            <option value="">Select nationality</option>
                            <?php foreach ($nationalities as $nat): ?>
                                <option value="<?= htmlspecialchars($nat, ENT_QUOTES, 'UTF-8') ?>" <?= oldv('nationality') === $nat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($nat, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="member-search-wrap" id="memberSearchWrap">
                    <div class="field" style="margin-bottom:0;">
                        <label>Search Member</label>
                        <div class="iw">
                            <input
                                type="text"
                                id="member_search"
                                placeholder="Search member by name, or exact email / phone"
                                autocomplete="off"
                            >
                            <i class="fas fa-magnifying-glass ico"></i>
                        </div>
                        <div class="search-results" id="memberResults"></div>
                        <div class="selected-member" id="selectedMemberBox"></div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field col-full" id="fullNameField">
                        <label>Full Name <span class="req">*</span></label>
                        <div class="iw">
                            <input
                                type="text"
                                name="visitor_name"
                                id="f_name"
                                placeholder="Your full name"
                                value="<?= oldv('visitor_name') ?>"
                                autocomplete="name"
                                required
                                <?= member_old_selected() ? 'readonly' : '' ?>
                                autofocus
                            >
                            <i class="fas fa-user ico"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>Phone Number</label>
                        <div class="iw">
                            <input
                                type="tel"
                                name="phone"
                                id="f_phone"
                                placeholder="+256 7XX XXX XXX"
                                value="<?= oldv('phone') ?>"
                                autocomplete="tel"
                            >
                            <i class="fas fa-phone ico"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>Email Address</label>
                        <div class="iw">
                            <input
                                type="email"
                                name="email"
                                id="f_email"
                                placeholder="you@example.com"
                                value="<?= oldv('email') ?>"
                                autocomplete="email"
                            >
                            <i class="fas fa-envelope ico"></i>
                        </div>
                    </div>

                    <div class="field col-full">
                        <label>Organization / Company</label>
                        <div class="iw">
                            <input
                                type="text"
                                name="organization"
                                id="f_org"
                                placeholder="Where do you work or study?"
                                value="<?= oldv('organization') ?>"
                                autocomplete="organization"
                            >
                            <i class="fas fa-briefcase ico"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>Branch <span class="req">*</span></label>
                        <select name="branch" id="f_branch" required>
                            <option value="">Select branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= htmlspecialchars($branch, ENT_QUOTES, 'UTF-8') ?>" <?= oldv('branch') === $branch ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($branch, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Gender <span class="req">*</span></label>
                        <select name="gender" id="f_gender" required>
                            <option value="">Select gender</option>
                            <?php foreach ($genders as $gender): ?>
                                <option value="<?= htmlspecialchars($gender, ENT_QUOTES, 'UTF-8') ?>" <?= oldv('gender') === $gender ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gender, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field col-full">
                        <label>PWDs</label>
                        <label class="check-inline">
                            <input type="checkbox" name="is_pwd" id="f_is_pwd" value="1" <?= isset($_POST['is_pwd']) ? 'checked' : '' ?>>
                            <span>This visitor is a Person With Disability (PWD)</span>
                        </label>
                    </div>

                    <div class="field col-full" id="pwdTypeField" <?= isset($_POST['is_pwd']) ? '' : 'hidden' ?>>
                        <label>Select Type of PWD <span class="req">*</span></label>
                        <select name="pwd_type" id="f_pwd_type">
                            <option value="">Select type of PWD</option>
                            <?php foreach ($pwd_types as $type): ?>
                                <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= oldv('pwd_type') === $type ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field col-full" id="pwdOtherField" <?= oldv('pwd_type') === 'Other' ? '' : 'hidden' ?>>
                        <label>Other PWD Type, Specify <span class="req">*</span></label>
                        <div class="iw">
                            <input
                                type="text"
                                name="pwd_other_specify"
                                id="f_pwd_other"
                                placeholder="Specify other PWD type"
                                value="<?= oldv('pwd_other_specify') ?>"
                            >
                            <i class="fas fa-universal-access ico"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-sep" id="sep-1"></div>
            <div class="card-nav" id="nav-1">
                <button type="button" class="btn btn-primary" onclick="goStep(2)">
                    Continue <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <div class="step-panel" id="panel-2">
                <div class="panel-eyebrow"><i class="fas fa-calendar-check"></i> Visit Details</div>
                <div class="panel-title">Tell us about your visit</div>
                <div class="panel-sub">What brings you to <?= htmlspecialchars($hub_name, ENT_QUOTES, 'UTF-8') ?> today?</div>

                <div class="grid-2">
                    <div class="field">
                        <label>Visit Date <span class="req">*</span></label>
                        <div class="iw">
                            <input
                                type="date"
                                name="visit_date"
                                id="f_date"
                                value="<?= oldv('visit_date', date('Y-m-d')) ?>"
                                max="<?= date('Y-m-d') ?>"
                                required
                            >
                            <i class="fas fa-calendar ico"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>Arrival Time</label>
                        <div class="iw">
                            <input
                                type="time"
                                name="visit_time"
                                id="f_time"
                                value="<?= oldv('visit_time', date('H:i')) ?>"
                            >
                            <i class="fas fa-clock ico"></i>
                        </div>
                    </div>

                    <div class="field col-full">
                        <label>Purpose of Visit <span class="req">*</span></label>
                        <select name="purpose" id="f_purpose" required>
                            <option value="">Select a purpose</option>
                            <?php foreach ($purposes as $p): ?>
                                <option value="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>" <?= oldv('purpose') === $p ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field col-full">
                        <label>Who Are You Here to See?</label>
                        <div class="iw">
                            <input
                                type="text"
                                name="host_contact"
                                id="f_host"
                                placeholder="Staff name or team"
                                value="<?= oldv('host_contact') ?>"
                            >
                            <i class="fas fa-user-tie ico"></i>
                        </div>
                    </div>

                    <div class="field col-full">
                        <label>Additional Notes <span style="font-weight:300;text-transform:none;font-style:italic;">(optional)</span></label>
                        <textarea
                            name="remarks"
                            id="f_remarks"
                            placeholder="Anything else we should know?"
                        ><?= oldv('remarks') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card-sep" id="sep-2" style="display:none;"></div>
            <div class="card-nav" id="nav-2" style="display:none;">
                <button type="button" class="btn btn-ghost" onclick="goStep(1, true)">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="button" class="btn btn-primary" onclick="goStep(3)">
                    Review <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <div class="step-panel" id="panel-3">
                <div class="panel-eyebrow"><i class="fas fa-clipboard-check"></i> Review</div>
                <div class="panel-title">Everything look right?</div>
                <div class="panel-sub">Check your details before signing in.</div>

                <div class="review-box">
                    <div class="rv-row">
                        <div class="rv-ico"><i class="fas fa-user"></i></div>
                        <div><div class="rv-lbl">Name</div><div class="rv-val" id="rv-name">-</div></div>
                    </div>
                    <div class="rv-row">
                        <div class="rv-ico"><i class="fas fa-id-card"></i></div>
                        <div><div class="rv-lbl">Member / First Visit</div><div class="rv-val" id="rv-member-status">-</div></div>
                    </div>
                    <div class="rv-row">
                        <div class="rv-ico"><i class="fas fa-address-book"></i></div>
                        <div><div class="rv-lbl">Contact</div><div class="rv-val" id="rv-contact">-</div></div>
                    </div>
                    <div class="rv-row" id="rvr-org" style="display:none;">
                        <div class="rv-ico"><i class="fas fa-building"></i></div>
                        <div><div class="rv-lbl">Organization</div><div class="rv-val" id="rv-org">-</div></div>
                    </div>
                    <div class="rv-row">
                        <div class="rv-ico"><i class="fas fa-code-branch"></i></div>
                        <div><div class="rv-lbl">Branch</div><div class="rv-val" id="rv-branch">-</div></div>
                    </div>
                    <div class="rv-row">
                        <div class="rv-ico"><i class="fas fa-venus-mars"></i></div>
                        <div><div class="rv-lbl">Gender / Nationality / PWD</div><div class="rv-val" id="rv-profile">-</div></div>
                    </div>
                    <div class="rv-row">
                        <div class="rv-ico"><i class="fas fa-calendar-day"></i></div>
                        <div><div class="rv-lbl">Date &amp; Time</div><div class="rv-val" id="rv-dt">-</div></div>
                    </div>
                    <div class="rv-row">
                        <div class="rv-ico"><i class="fas fa-tag"></i></div>
                        <div><div class="rv-lbl">Purpose</div><div class="rv-val" id="rv-purpose">-</div></div>
                    </div>
                    <div class="rv-row" id="rvr-host" style="display:none;">
                        <div class="rv-ico"><i class="fas fa-user-tie"></i></div>
                        <div><div class="rv-lbl">Here to See</div><div class="rv-val" id="rv-host">-</div></div>
                    </div>
                </div>
            </div>

            <div class="card-sep" id="sep-3" style="display:none;"></div>
            <div class="card-nav" id="nav-3" style="display:none;">
                <button type="button" class="btn btn-ghost" onclick="goStep(2, true)">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="submit" class="btn btn-confirm" id="submitBtn">
                    <i class="fas fa-check-circle"></i> Sign In &amp; Register
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

</div>

<footer>
    &copy; <?= date('Y') ?> <?= htmlspecialchars($hub_name, ENT_QUOTES, 'UTF-8') ?> - Visitor Management
    &nbsp;&middot;&nbsp; <a href="#">Privacy Policy</a>
    &nbsp;&middot;&nbsp; <a href="#">Help</a>
</footer>

<script>
window.VISITOR_REGISTER_CONFIG = {
    hasError: <?= $error_msg ? 'true' : 'false' ?>,
    errorMessage: <?= json_encode($error_msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="js/visitor-register.js"></script>
</body>
</html>