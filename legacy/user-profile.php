<?php
declare(strict_types=1);

$page_title = 'My Profile';
include 'includes/header.php';


if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in to view your profile.';
    header('Location: login');
    exit;
}

$user_id = (int)$_SESSION['user_id'];


if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function profile_timestamp(mixed $value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $ts = strtotime((string)$value);

    return $ts === false ? null : $ts;
}

function profile_date(mixed $value, string $format = 'd M Y', string $fallback = 'Not available'): string
{
    $ts = profile_timestamp($value);

    return $ts === null ? $fallback : date($format, $ts);
}

function profile_time_ago(mixed $value): string
{
    if ($value === null || trim((string)$value) === '') {
        return 'Never';
    }

    if (function_exists('time_ago')) {
        return (string)time_ago((string)$value);
    }

    $ts = profile_timestamp($value);

    if ($ts === null) {
        return 'Not available';
    }

    $seconds = max(0, time() - $ts);

    if ($seconds < 60) return 'Just now';
    if ($seconds < 3600) return floor($seconds / 60) . ' min ago';
    if ($seconds < 86400) return floor($seconds / 3600) . ' hrs ago';

    return floor($seconds / 86400) . ' days ago';
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


$user = [];

$stmt = $conn->prepare("
    SELECT
        user_id,
        username,
        email,
        full_name,
        role,
        phone,
        is_active,
        created_at,
        last_login
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

if (!$user) {
    $_SESSION['error'] = 'User profile not found.';
    header('Location: dashboard');
    exit;
}


$stats = [
    'total_actions' => 0,
    'actions_this_month' => 0,
    'account_age' => 0,
];

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM audit_log WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['total_actions'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM audit_log
    WHERE user_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['actions_this_month'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

$createdTs = profile_timestamp($user['created_at'] ?? null);

if ($createdTs !== null) {
    $created = new DateTimeImmutable('@' . $createdTs);
    $now = new DateTimeImmutable('now');
    $stats['account_age'] = (int)$now->diff($created)->days;
}


$recent_activity = [];

$stmt = $conn->prepare("
    SELECT *
    FROM audit_log
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recent_activity[] = $row;
    }

    $stmt->close();
}

$profileInitial = trim((string)($user['full_name'] ?? '')) !== ''
    ? mb_strtoupper(mb_substr((string)$user['full_name'], 0, 1))
    : 'U';
?>

<style>
/* Profile modals: self-contained dialog with its own open/close + focus JS. */
.profile-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 99999;
    padding: 24px;
    background: rgba(15, 23, 42, .58);
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

.profile-modal.is-open {
    display: flex;
    align-items: flex-start;
    justify-content: center;
}

.profile-modal-dialog {
    width: min(100%, 680px);
    margin: 40px auto;
    background: var(--surface-card);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    overflow: hidden;
    position: relative;
    animation: profileModalIn .18s ease-out;
}

.profile-modal-dialog.profile-modal-sm {
    width: min(100%, 520px);
}

.profile-modal-header,
.profile-modal-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
}

.profile-modal-header {
    border-bottom: 1px solid var(--ink-50);
}

.profile-modal-footer {
    justify-content: flex-end;
    border-top: 1px solid var(--ink-50);
    flex-wrap: wrap;
}

.profile-modal-header h3 {
    margin: 0;
    font-size: 18px;
    color: var(--ink-900);
}

.profile-modal-header h3 i { color: var(--brand-500); }

.profile-modal-body {
    padding: 20px;
    max-height: calc(100vh - 190px);
    overflow-y: auto;
}

.profile-modal-close {
    appearance: none;
    border: 0;
    background: var(--surface-hover);
    color: var(--ink-300);
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    border-radius: var(--radius-md);
    cursor: pointer;
    font-size: 24px;
    line-height: 1;
}

.profile-modal-close:hover {
    background: var(--ink-50);
    color: var(--ink-900);
}

.profile-modal-open {
    overflow: hidden !important;
}

.profile-modal[aria-hidden="true"] {
    display: none;
}

.profile-modal[aria-hidden="false"] {
    display: flex;
}

@keyframes profileModalIn {
    from {
        opacity: 0;
        transform: translateY(10px) scale(.985);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Password fields with a show/hide toggle inside the input. */
.profile-password-wrap {
    position: relative;
}

.profile-password-wrap .form-control {
    padding-right: 46px;
}

.profile-password-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border: 0;
    background: transparent;
    color: var(--ink-300);
    cursor: pointer;
    border-radius: var(--radius-md);
    padding: 0;
}

.profile-password-toggle:hover,
.profile-password-toggle:focus-visible {
    background: var(--surface-hover);
    color: var(--ink-900);
    outline: none;
}

.profile-password-toggle i {
    pointer-events: none;
}

.up-hint { display: flex; align-items: center; gap: 5px; margin-top: 5px; font-size: 12px; }

/* Label/value rows in the Profile Info and Account Status tabs. */
.up-field {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 12px;
    align-items: start;
    padding: 16px 0;
    border-bottom: 1px solid var(--ink-50);
}
.up-field:first-child { padding-top: 0; }
.up-field:last-child { border-bottom: none; padding-bottom: 0; }
.up-field-label {
    display: flex;
    align-items: center;
    gap: 7px;
    padding-top: 2px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--ink-300);
}
.up-field-label i { color: var(--brand-400); font-size: 12px; width: 14px; text-align: center; }
.up-field-value { font-size: 15px; font-weight: 600; color: var(--ink-700); line-height: 1.5; }
.up-field-value small { display: block; margin-top: 3px; font-size: 12px; font-weight: 400; color: var(--ink-300); }
.up-field-value .none { color: var(--ink-200); font-weight: 400; font-style: italic; }

.up-stat-text { font-size: 16px; font-weight: 700; letter-spacing: 0; line-height: 1.3; }

.up-role-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    background: var(--ink-50);
    border: 1px solid var(--ink-100);
    border-radius: var(--radius-md);
    font-size: 12px;
    color: var(--ink-400);
}
.up-role-note i { color: var(--brand-500); margin-top: 1px; flex-shrink: 0; }

@media (max-width: 640px) {
    .profile-modal {
        padding: 12px;
    }

    .profile-modal-dialog,
    .profile-modal-dialog.profile-modal-sm {
        width: 100%;
        margin: 18px auto;
    }

    .profile-modal-body {
        max-height: calc(100vh - 145px);
    }

    .profile-modal-footer .btn {
        flex: 1 1 auto;
    }

    .up-field { grid-template-columns: 1fr; gap: 4px; }
    .up-field-label { padding-top: 0; }
}
</style>

<div class="page-stack">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-user-circle" aria-hidden="true"></i> My Profile</h1>
        <p>Signed in as <?= h($user['full_name'] ?? '') ?> (@<?= h($user['username'] ?? '') ?>), <?= h($user['role'] ?? '') ?>.</p>
    </div>
    <div class="hero-actions">
        <button type="button" class="btn js-profile-modal-open" data-modal-target="changePasswordModal">
            <i class="fas fa-key" aria-hidden="true"></i> Change Password
        </button>
        <button type="button" class="btn btn-primary js-profile-modal-open" data-modal-target="editProfileModal">
            <i class="fas fa-pencil" aria-hidden="true"></i> Edit Profile
        </button>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success profile-flash" id="profileFlash">
        <i class="fas fa-check-circle" aria-hidden="true"></i>
        <?= h($_SESSION['success']) ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger profile-flash" id="profileFlash">
        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
        <?= h($_SESSION['error']) ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Account Age</span>
                <span class="stat-value"><?= $stats['account_age'] ?><span class="stat-sub">d</span></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-tasks" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total Actions</span>
                <span class="stat-value"><?= $stats['total_actions'] ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">This Month</span>
                <span class="stat-value"><?= $stats['actions_this_month'] ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-clock" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Last Login</span>
                <span class="stat-value up-stat-text">
                    <?= h(profile_time_ago($user['last_login'] ?? null)) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Tabbed panel (prof-* classes are JS hooks for switchTab) -->
    <div class="tab-shell prof-tabs-wrap">

        <!-- Tab navigation -->
        <div class="tab-nav" role="tablist">
            <button class="tab-btn prof-tab-btn active" role="tab" aria-selected="true"
                    aria-controls="tab-profile" onclick="switchTab(event,'tab-profile')">
                <i class="fas fa-user-circle" aria-hidden="true"></i> Profile Info
            </button>
            <button class="tab-btn prof-tab-btn" role="tab" aria-selected="false"
                    aria-controls="tab-account" onclick="switchTab(event,'tab-account')">
                <i class="fas fa-shield-alt" aria-hidden="true"></i> Account Status
            </button>
            <button class="tab-btn prof-tab-btn" role="tab" aria-selected="false"
                    aria-controls="tab-activity" onclick="switchTab(event,'tab-activity')">
                <i class="fas fa-history" aria-hidden="true"></i> Recent Activity
                <?php if (!empty($recent_activity)): ?>
                    <span class="badge badge-secondary">
                        <?= count($recent_activity) ?>
                    </span>
                <?php endif; ?>
            </button>
        </div>

        <div class="tab-panels">

        <!-- Tab 1: Profile Info -->
        <div id="tab-profile" class="tab-panel prof-tab-panel active" role="tabpanel">

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-user" aria-hidden="true"></i> Full Name
                    </div>
                    <div class="up-field-value"><?= h($user['full_name'] ?? '') ?></div>
                </div>

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-at" aria-hidden="true"></i> Username
                    </div>
                    <div class="up-field-value"><?= h($user['username'] ?? '') ?></div>
                </div>

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-envelope" aria-hidden="true"></i> Email Address
                    </div>
                    <div class="up-field-value"><?= h($user['email'] ?? '') ?></div>
                </div>

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-phone" aria-hidden="true"></i> Phone Number
                    </div>
                    <div class="up-field-value">
                        <?= !empty($user['phone'])
                            ? h($user['phone'])
                            : '<span class="none">Not provided</span>' ?>
                    </div>
                </div>

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-user-tag" aria-hidden="true"></i> Role
                    </div>
                    <div class="up-field-value">
                        <span class="badge badge-info"><?= h($user['role'] ?? '') ?></span>
                    </div>
                </div>

        </div>

        <div id="tab-account" class="tab-panel prof-tab-panel" role="tabpanel">

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-circle" aria-hidden="true"></i> Account Status
                    </div>
                    <div class="up-field-value">
                        <?php if ($user['is_active']): ?>
                            <span class="badge badge-success">
                                <i class="fas fa-check-circle" aria-hidden="true"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-danger">
                                <i class="fas fa-times-circle" aria-hidden="true"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-calendar-plus" aria-hidden="true"></i> Member Since
                    </div>
                    <div class="up-field-value">
                        <?= h(profile_date($user['created_at'] ?? null)) ?>
                        <small><?= $stats['account_age'] ?> days ago</small>
                    </div>
                </div>

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-sign-in-alt" aria-hidden="true"></i> Last Login
                    </div>
                    <div class="up-field-value">
                        <?php if (!empty($user['last_login'])): ?>
                            <?= h(profile_date($user['last_login'] ?? null, 'd M Y H:i')) ?>
                            <small><?= h(profile_time_ago($user['last_login'] ?? null)) ?></small>
                        <?php else: ?>
                            <span class="none">Never logged in</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="up-field">
                    <div class="up-field-label">
                        <i class="fas fa-tasks" aria-hidden="true"></i> Total Actions
                    </div>
                    <div class="up-field-value">
                        <?= number_format($stats['total_actions']) ?>
                        <small><?= number_format($stats['actions_this_month']) ?> in the last 30 days</small>
                    </div>
                </div>

        </div>

        <div id="tab-activity" class="tab-panel prof-tab-panel" role="tabpanel">
            <?php if (empty($recent_activity)): ?>
                <div class="empty-state">
                    <i class="fas fa-history" aria-hidden="true"></i>
                    <p>No activity recorded yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Action</th>
                                <th>Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $i => $act): ?>
                                <tr>
                                    <td class="cell-muted"><?= $i + 1 ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?= h($act['action']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= h(profile_date($act['created_at'] ?? null, 'd M Y H:i')) ?>
                                        <br><small class="cell-muted">
                                            <?= h(profile_time_ago($act['created_at'] ?? null)) ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        </div><!-- /.tab-panels -->
    </div>

</div><!-- /.page-stack -->



<div class="profile-modal" id="editProfileModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="editModalTitle">
    <div class="profile-modal-dialog" role="document">
        <div class="profile-modal-header">
            <h3 id="editModalTitle">
                <i class="fas fa-user-edit" aria-hidden="true"></i> Edit Profile
            </h3>
            <button type="button" class="profile-modal-close js-profile-modal-close" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="/includes/user-profile-process.php" id="editProfileForm">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <div class="profile-modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    You can update your own name, email address, phone number and username. Your role is protected and cannot be changed from this page.
                </div>

                <div class="form-group">
                    <label class="form-label required" for="m_full_name">Full Name</label>
                    <input class="form-control" type="text" id="m_full_name" name="full_name"
                           value="<?= h($user['full_name'] ?? '') ?>" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required" for="m_email">Email Address</label>
                        <input class="form-control" type="email" id="m_email" name="email"
                               value="<?= h($user['email'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="m_phone">Phone Number</label>
                        <input class="form-control" type="tel" id="m_phone" name="phone"
                               value="<?= h($user['phone'] ?? '') ?>" placeholder="+256...">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required" for="m_username">Username</label>
                    <input class="form-control" type="text" id="m_username" name="username"
                           value="<?= h($user['username'] ?? '') ?>" required>
                    <div class="cell-muted up-hint"><i class="fas fa-info-circle" aria-hidden="true"></i> Used for login</div>
                </div>

                <div class="up-role-note">
                    <i class="fas fa-user-tag" aria-hidden="true"></i>
                    <div>
                        <strong>Current role:</strong>
                        <span class="badge badge-info"><?= h($user['role'] ?? '') ?></span>
                       
                    </div>
                </div>
            </div>

            <div class="profile-modal-footer">
                <button type="button" class="btn btn-secondary js-profile-modal-close">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" name="update_profile" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Profile
                </button>
            </div>
        </form>
    </div>
</div>



<div class="profile-modal" id="changePasswordModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="pwdModalTitle">
    <div class="profile-modal-dialog profile-modal-sm" role="document">
        <div class="profile-modal-header">
            <h3 id="pwdModalTitle">
                <i class="fas fa-key" aria-hidden="true"></i> Change Password
            </h3>
            <button type="button" class="profile-modal-close js-profile-modal-close" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="/includes/user-profile-process.php" id="changePasswordForm">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <div class="profile-modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    You must enter your current password before setting a new one.
                </div>

                <div class="form-group">
                    <label class="form-label required" for="m_current_pwd">Current Password</label>
                    <div class="profile-password-wrap">
                        <input class="form-control" type="password" id="m_current_pwd" name="current_password" required autocomplete="current-password">
                        <button type="button" class="profile-password-toggle" data-password-target="m_current_pwd" aria-label="Show current password" title="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required" for="m_new_pwd">New Password</label>
                    <div class="profile-password-wrap">
                        <input class="form-control" type="password" id="m_new_pwd" name="new_password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="profile-password-toggle" data-password-target="m_new_pwd" aria-label="Show new password" title="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="cell-muted up-hint"><i class="fas fa-shield-alt" aria-hidden="true"></i> Minimum 8 characters</div>
                </div>

                <div class="form-group">
                    <label class="form-label required" for="m_confirm_pwd">Confirm New Password</label>
                    <div class="profile-password-wrap">
                        <input class="form-control" type="password" id="m_confirm_pwd" name="confirm_password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="profile-password-toggle" data-password-target="m_confirm_pwd" aria-label="Show confirm password" title="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="profile-modal-footer">
                <button type="button" class="btn btn-secondary js-profile-modal-close">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" name="change_password" class="btn btn-primary">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </div>
        </form>
    </div>
</div>


<script>
(function () {
    'use strict';

    window.switchTab = function (e, targetId) {
        var wrap = e.target.closest('.prof-tabs-wrap');

        wrap.querySelectorAll('.prof-tab-btn').forEach(function (b) {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        wrap.querySelectorAll('.prof-tab-panel').forEach(function (p) {
            p.classList.remove('active');
        });

        // Activate clicked button and matching panel
        var activeButton = e.target.closest('.prof-tab-btn');
        if (activeButton) {
            activeButton.classList.add('active');
            activeButton.setAttribute('aria-selected', 'true');
        }
        document.getElementById(targetId).classList.add('active');
    };

    // -- Profile modal helpers -----------------------------------------------
    var activeProfileModal = null;
    var lastProfileModalTrigger = null;

    function openProfileModal(id, trigger) {
        var modal = document.getElementById(id);
        if (!modal) return;

        lastProfileModalTrigger = trigger || document.activeElement;
        activeProfileModal = modal;

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('profile-modal-open');

        var focusTarget = modal.querySelector(
            'input:not([type="hidden"]), select, textarea, button, a[href]'
        );

        if (focusTarget) {
            window.setTimeout(function () {
                focusTarget.focus();
            }, 20);
        }
    }

    function closeProfileModal(modal) {
        if (!modal) return;

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('profile-modal-open');

        activeProfileModal = null;

        if (lastProfileModalTrigger && typeof lastProfileModalTrigger.focus === 'function') {
            lastProfileModalTrigger.focus();
        }
    }

    document.querySelectorAll('.js-profile-modal-open').forEach(function (button) {
        button.addEventListener('click', function () {
            openProfileModal(button.dataset.modalTarget, button);
        });
    });

    document.querySelectorAll('.profile-modal').forEach(function (modal) {
        modal.querySelectorAll('.js-profile-modal-close').forEach(function (button) {
            button.addEventListener('click', function () {
                closeProfileModal(modal);
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeProfileModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && activeProfileModal) {
            closeProfileModal(activeProfileModal);
        }
    });

    // -- Password match validation --------------------------------------------
    var pwdForm = document.getElementById('changePasswordForm');
    if (pwdForm) {
        pwdForm.addEventListener('submit', function (e) {
            var np = document.getElementById('m_new_pwd').value;
            var cp = document.getElementById('m_confirm_pwd').value;
            if (np !== cp) {
                e.preventDefault();
                // Inline error rather than bare alert
                var err = document.getElementById('pwdMismatchErr');
                if (!err) {
                    err = document.createElement('div');
                    err.id = 'pwdMismatchErr';
                    err.className = 'alert alert-warning';
                    err.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match. Please try again.';
                    document.getElementById('m_confirm_pwd').insertAdjacentElement('afterend', err);
                }
            }
        });
        // Clear mismatch error when user types again
        document.getElementById('m_confirm_pwd').addEventListener('input', function () {
            var err = document.getElementById('pwdMismatchErr');
            if (err) err.remove();
        });
    }


    // -- Password visibility toggle ------------------------------------------
    document.querySelectorAll('.profile-password-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var targetId = button.dataset.passwordTarget;
            var input = document.getElementById(targetId);

            if (!input) return;

            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';

            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', showing);
                icon.classList.toggle('fa-eye-slash', !showing);
            }

            var fieldName = targetId === 'm_current_pwd'
                ? 'current password'
                : (targetId === 'm_new_pwd' ? 'new password' : 'confirm password');

            button.setAttribute(
                'aria-label',
                (showing ? 'Show ' : 'Hide ') + fieldName
            );
            button.setAttribute(
                'title',
                showing ? 'Show password' : 'Hide password'
            );
        });
    });

    var flash = document.getElementById('profileFlash');
    if (flash) {
        window.setTimeout(function () {
            flash.style.transition = 'opacity .3s ease, transform .3s ease';
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-6px)';
            window.setTimeout(function () {
                flash.remove();
            }, 320);
        }, 5000);
    }
}());
</script>

<?php include 'includes/footer.php'; ?>