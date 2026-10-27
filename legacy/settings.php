<?php
$page_title = 'System Settings';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google-calendar-service.php';

//check_role(['Administrator']);
check_role(IMS_ADMIN_ROLES);

// Fetch Current Settings
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Fetch Power BI Settings
$powerbi_settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM powerbi_settings");
while ($row = $result->fetch_assoc()) {
    $powerbi_settings[$row['setting_key']] = $row['setting_value'];
}


// Fetch Google Calendar / Google Workspace configuration
$google_settings = [];

$result = $conn->query("
    SELECT setting_key, setting_value
    FROM system_settings
    WHERE setting_key LIKE 'google_%'
       OR setting_key LIKE 'workspace_gmail_%'
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $google_settings[$row['setting_key']] = $row['setting_value'];
    }
}

$google_calendar_status =
    $google_settings['google_calendar_configuration_status']
    ?? 'Not Confirmed';

$google_calendar_enabled =
    ($google_settings['google_calendar_enabled'] ?? '0') === '1';

$workspace_gmail_enabled =
    ($google_settings['workspace_gmail_enabled'] ?? '0') === '1';

$google_calendar_connected =
    gcal_is_connected($conn);

$google_connection_status =
    gcal_setting(
        $conn,
        'google_calendar_connection_status',
        $google_calendar_connected
            ? 'Connected'
            : 'Not Connected'
    );

$google_connected_at =
    gcal_setting(
        $conn,
        'google_calendar_connected_at',
        ''
    );


$google_status_class = match ($google_calendar_status) {
    'Confirmed' => 'success',
    'Pending Confirmation' => 'warning',
    default => 'secondary',
};


// Fetch automatic backup / Google Drive configuration
$backup_settings = [];

$result = $conn->query("
    SELECT setting_key, setting_value
    FROM system_settings
    WHERE setting_key LIKE 'backup_%'
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $backup_settings[
            $row['setting_key']
        ] = $row['setting_value'];
    }
}

$backup_auto_enabled =
    ($backup_settings['backup_auto_enabled'] ?? '0') === '1';

$backup_frequency =
    $backup_settings['backup_frequency']
    ?? 'daily';

$backup_storage_destination =
    $backup_settings['backup_storage_destination']
    ?? 'local';

$backup_last_run_at =
    $backup_settings['backup_last_run_at']
    ?? 'Never';

$backup_last_run_status =
    $backup_settings['backup_last_run_status']
    ?? 'Not run yet';

// Fetch System Info
$system_info = [
    'php_version' => phpversion(),
    'mysql_version' => $conn->server_info,
    'server_software' => $_SERVER['SERVER_SOFTWARE'],
    'max_upload' => ini_get('upload_max_filesize'),
    'max_post' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time')
];

// Get database size
$result = $conn->query("SELECT 
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
    FROM information_schema.TABLES 
    WHERE table_schema = '" . DB_NAME . "'");
$db_size = $result->fetch_assoc()['size_mb'];

// Get record counts
$counts = [];
$tables = ['users', 'projects', 'beneficiaries', 'indicators', 'hub_events', 'documents'];
foreach ($tables as $table) {
    $result = $conn->query("SELECT COUNT(*) as count FROM $table");
    $counts[$table] = (int)($result->fetch_assoc()['count'] ?? 0);
}

// Get recent backups
$backups = [];
if (is_dir('exports')) {
    $files = glob('exports/backup_*.{sql,sql.gz}', GLOB_BRACE) ?: [];
    rsort($files);
    foreach (array_slice($files, 0, 5) as $file) {
        $backups[] = [
            'filename' => basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
}

$active_tab = isset($_GET['tab'])
    ? sanitize_input((string)$_GET['tab'])
    : 'general';

$allowed_tabs = [
    'general',
    'powerbi',
    'email',
    'google',
    'workspace',
    'backup',
    'system',
];

if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'general';
}
?>

<style>
    /* System Settings: page-only layout (settings- prefix) */
    .settings-tabs { margin-bottom: 0; }

    .settings-stats { margin-bottom: 18px; }
    .settings-branding-preview { display:block; width:min(100%,280px); height:72px; margin:8px 0; padding:8px; object-fit:contain; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--surface-card); }
    .settings-favicon-preview { display:block; width:48px; height:48px; margin:8px 0; padding:5px; object-fit:contain; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--surface-card); }
    .settings-stats .stat-value.settings-stat-text { font-size: var(--fs-md); word-break: break-word; }
    .settings-stats .stat-value.settings-stat-small { font-size: var(--fs-xs); }
    .settings-stats.google-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }

    .settings-info-box {
        margin-bottom: 18px;
        padding: 14px;
        border: 1px solid var(--ink-100);
        border-radius: var(--radius-md);
        background: var(--surface-hover);
    }
    .settings-info-box h4 {
        display: flex; align-items: center; gap: 7px;
        margin: 0 0 10px;
        color: var(--ink-500);
        font-size: var(--fs-sm); font-weight: 800;
    }
    .settings-info-box ol { margin: 10px 0; padding-left: 20px; }
    .settings-info-box p { font-size: var(--fs-xs); color: var(--ink-300); margin: 0; }
    .settings-info-row {
        display: flex; justify-content: space-between; gap: 15px;
        padding: 8px 0;
        border-bottom: 1px solid var(--ink-100);
        font-size: var(--fs-xs);
    }
    .settings-info-row:last-child { border-bottom: none; }
    .settings-info-row strong { text-align: right; word-break: break-word; }
    .settings-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .settings-two-col .settings-info-box { margin-bottom: 0; }
    .settings-space-top { margin-top: 24px; }

    .settings-ok { color: var(--green-fg); }
    .settings-bad { color: var(--red-fg); }
    .settings-appearance { margin: 8px 0 20px; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-md); }
    .settings-appearance legend { padding: 0 6px; font-weight: 600; color: var(--ink-700); }
    .settings-color-input { height: 42px; padding: 4px; max-width: 120px; cursor: pointer; }
    .settings-checkbox { display: inline-flex; align-items: center; gap: 8px; font-weight: 400; }
    .settings-hint { display: block; margin-top: 4px; color: var(--ink-300); font-size: var(--fs-xs); }
    .form-group > label.settings-toggle-label { display: flex; align-items: center; gap: 10px; }
    .settings-alert-tight { margin-bottom: 12px; }
    .settings-alert-flush { margin: 14px 0 0; }
    .settings-alert-top { margin-top: 14px; }

    .settings-check-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 5px; }
    .settings-check {
        display: flex; align-items: flex-start; gap: 10px;
        min-height: 56px; padding: 11px 12px;
        border: 1px solid var(--ink-100); border-radius: var(--radius-sm);
        background: var(--surface-hover);
    }
    .settings-check input { margin-top: 2px; }
    .settings-check strong { display: block; color: var(--ink-500); font-size: var(--fs-xs); }
    .settings-check small { display: block; margin-top: 2px; color: var(--ink-300); font-size: 11px; line-height: 1.4; }

    .settings-secret-wrap { position: relative; }
    .settings-secret-wrap .form-control { padding-right: 44px; }
    .settings-secret-toggle {
        position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
        width: 32px; height: 32px; display: grid; place-items: center;
        border: 0; border-radius: var(--radius-sm);
        background: transparent; color: var(--ink-300); cursor: pointer;
    }
    .settings-secret-toggle:hover { background: var(--ink-50); color: var(--ink-700); }

    .settings-actions { display: flex; align-items: center; justify-content: flex-end; gap: 9px; flex-wrap: wrap; margin-top: 22px; }
    .settings-actions.settings-actions-tight { margin-top: 12px; }
    .settings-actions.settings-actions-flush { margin-top: 0; }

    .settings-confirm-box {
        margin-top: 18px; padding: 15px;
        border: 1px solid var(--ink-100); border-radius: var(--radius-md);
        background: var(--surface-hover);
    }
    .settings-confirm-box h4 { margin-top: 0; }

    .backup-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
    .backup-grid.backup-grid-spaced { margin-top: 18px; }
    .backup-card { padding: 15px; border: 1px solid var(--ink-100); border-radius: var(--radius-md); background: var(--surface-hover); }
    .backup-card h4 { display: flex; align-items: center; gap: 7px; margin: 0 0 12px; font-size: var(--fs-sm); font-weight: 800; }
    .backup-card p { color: var(--ink-300); font-size: var(--fs-xs); line-height: 1.6; }
    .backup-full-btn { width: 100%; }
    .backup-cron { font-size: 11px; }
    .backup-storage-options { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; }
    .backup-storage-option {
        display: flex; align-items: flex-start; gap: 8px; padding: 11px;
        border: 1px solid var(--ink-100); border-radius: var(--radius-sm);
        background: var(--surface-card); cursor: pointer;
    }
    .backup-storage-option strong { display: block; font-size: var(--fs-xs); color: var(--ink-500); }
    .backup-storage-option small { display: block; margin-top: 2px; color: var(--ink-300); font-size: 11px; line-height: 1.4; }
    .backup-google-box {
        margin-top: 14px; padding: 14px;
        border: 1px solid var(--blue-border); border-left: 4px solid var(--blue-fg);
        border-radius: var(--radius-md); background: var(--blue-bg);
    }
    .backup-google-box h4 { margin-top: 0; }
    .backup-secret-area { min-height: 130px; font-size: 11px; }
    .backup-list-title { display: flex; align-items: center; gap: 7px; margin: 26px 0 14px; }

    .google-connect-panel {
        margin-top: 18px; padding: 16px;
        border: 1px solid var(--ink-100); border-left: 4px solid var(--blue-fg);
        border-radius: var(--radius-md); background: var(--surface-hover);
    }
    .google-connect-panel.connected { border-left-color: var(--green-fg); background: var(--green-bg); }
    .google-connect-panel h4 { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; color: var(--ink-700); font-size: var(--fs-sm); font-weight: 800; }
    .google-connect-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 18px; margin: 13px 0; }
    .google-connect-meta div { min-width: 0; }
    .google-connect-meta span { color: var(--ink-300); font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .google-connect-meta strong { display: block; margin-top: 3px; color: var(--ink-700); font-size: var(--fs-xs); word-break: break-word; }

    @media (max-width: 1050px) {
        .settings-stats.google-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 950px) {
        .settings-check-grid, .settings-two-col { grid-template-columns: 1fr; }
    }
    @media (max-width: 850px) {
        .backup-grid, .backup-storage-options { grid-template-columns: 1fr; }
    }
    @media (max-width: 700px) {
        .settings-actions .btn { width: 100%; justify-content: center; }
        .settings-stats.google-grid, .google-connect-meta { grid-template-columns: 1fr; }
    }
</style>

<div class="page-stack settings-shell">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-sliders-h" aria-hidden="true"></i> System Settings</h1>
            <p>Manage IMS configuration, integrations, email, backups and system information.</p>
        </div>

        <div class="hero-actions">
            <span class="badge badge-<?= htmlspecialchars($google_status_class) ?>">
                <i class="fab fa-google" aria-hidden="true"></i>
                Google Calendar:
                <?= htmlspecialchars($google_calendar_status) ?>
            </span>

            <span class="badge <?= $workspace_gmail_enabled ? 'badge-success' : 'badge-secondary' ?>">
                <i class="fas fa-envelope" aria-hidden="true"></i>
                Workspace Gmail:
                <?= $workspace_gmail_enabled ? 'Enabled' : 'Disabled' ?>
            </span>
        </div>
    </div>

<!-- Settings Navigation -->
<nav class="tab-nav settings-tabs" role="tablist">
    <a href="settings?tab=general" class="tab-btn <?php echo $active_tab == 'general' ? 'active' : ''; ?>">
        <i class="fas fa-cog" aria-hidden="true"></i> General
    </a>
    <a href="settings?tab=powerbi" class="tab-btn <?php echo $active_tab == 'powerbi' ? 'active' : ''; ?>">
        <i class="fas fa-chart-line" aria-hidden="true"></i> Power BI
    </a>
    <a href="settings?tab=email" class="tab-btn <?php echo $active_tab == 'email' ? 'active' : ''; ?>">
        <i class="fas fa-envelope" aria-hidden="true"></i> Email
    </a>
    <a href="settings?tab=google" class="tab-btn <?php echo $active_tab == 'google' ? 'active' : ''; ?>">
        <i class="fab fa-google" aria-hidden="true"></i> Google Calendar
    </a>
    <a href="settings?tab=workspace" class="tab-btn <?php echo $active_tab == 'workspace' ? 'active' : ''; ?>">
        <i class="fas fa-building" aria-hidden="true"></i> Workspace Gmail
    </a>
    <a href="settings?tab=backup" class="tab-btn <?php echo $active_tab == 'backup' ? 'active' : ''; ?>">
        <i class="fas fa-database" aria-hidden="true"></i> Backup
    </a>
    <a href="settings?tab=system" class="tab-btn <?php echo $active_tab == 'system' ? 'active' : ''; ?>">
        <i class="fas fa-server" aria-hidden="true"></i> System Info
    </a>
</nav>

<!-- General Settings -->
<?php if ($active_tab == 'general'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-cog" aria-hidden="true"></i> General Settings</h3></div>
    <div class="card-body">
    
    <form method="POST" action="includes/settings-process.php" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group">
                <label for="site_name" class="required">System Name</label>
                <input type="text" id="site_name" name="site_name" class="form-control" maxlength="120"
                       value="<?php echo htmlspecialchars($settings['site_name'] ?? 'Hive Colab'); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="site_email">Organization Email</label>
                <input type="email" id="site_email" name="site_email" class="form-control" 
                       value="<?php echo htmlspecialchars($settings['site_email'] ?? ''); ?>">
            </div>
        </div>

        <?php $settingsBranding = $GLOBALS['IMS_SITE_BRANDING'] ?? ['logo' => '/images/logo.png', 'favicon' => '/images/favicon.png']; ?>
        <div class="form-row settings-branding-row">
            <div class="form-group">
                <label for="site_logo">Site Logo</label>
                <img class="settings-branding-preview" src="<?= htmlspecialchars((string)$settingsBranding['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Current site logo">
                <input type="file" id="site_logo" name="site_logo" class="form-control" accept="image/png,image/jpeg,image/webp">
                <small class="settings-hint">PNG, JPEG, or WebP; maximum 3 MB. Used in the global navigation and PDF reports.</small>
            </div>
            <div class="form-group">
                <label for="site_favicon">Favicon</label>
                <img class="settings-favicon-preview" src="<?= htmlspecialchars((string)$settingsBranding['favicon'], ENT_QUOTES, 'UTF-8') ?>" alt="Current site favicon">
                <input type="file" id="site_favicon" name="site_favicon" class="form-control" accept="image/png,image/x-icon,.ico">
                <small class="settings-hint">PNG or ICO; maximum 512 KB. Appears in the browser tab.</small>
            </div>
        </div>

        <?php $settingsTheme = ims_theme_resolve((array)($settingsBranding['theme'] ?? [])); ?>
        <fieldset class="settings-appearance">
            <legend>Appearance</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="theme_primary_color">Primary Colour</label>
                    <input type="color" id="theme_primary_color" name="theme_primary_color" class="form-control settings-color-input"
                           value="<?= htmlspecialchars($settingsTheme['theme_primary_color'], ENT_QUOTES, 'UTF-8') ?>">
                    <small class="settings-hint">Buttons, links, active tabs and highlights. Very light colours are rejected so white text stays visible. A few pages still use fixed colours.</small>
                </div>
                <div class="form-group">
                    <label for="theme_sidebar_color">Sidebar Colour</label>
                    <input type="color" id="theme_sidebar_color" name="theme_sidebar_color" class="form-control settings-color-input"
                           value="<?= htmlspecialchars($settingsTheme['theme_sidebar_color'], ENT_QUOTES, 'UTF-8') ?>">
                    <small class="settings-hint">Background of the main navigation. Very light colours are rejected so white text stays visible.</small>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="theme_font_family">Font Family</label>
                    <select id="theme_font_family" name="theme_font_family" class="form-control">
                        <?php foreach (ims_theme_font_families() as $fontKey => [$fontLabel]): ?>
                        <option value="<?= htmlspecialchars($fontKey, ENT_QUOTES, 'UTF-8') ?>" <?= $settingsTheme['theme_font_family'] === $fontKey ? 'selected' : '' ?>><?= htmlspecialchars($fontLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="theme_font_scale">Text Size</label>
                    <select id="theme_font_scale" name="theme_font_scale" class="form-control">
                        <?php foreach (ims_theme_font_scales() as $scaleKey => $scaleLabel): ?>
                        <option value="<?= htmlspecialchars((string)$scaleKey, ENT_QUOTES, 'UTF-8') ?>" <?= $settingsTheme['theme_font_scale'] === (string)$scaleKey ? 'selected' : '' ?>><?= htmlspecialchars($scaleLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="settings-hint">Scales the whole interface for every user. Individuals can still adjust it with the accessibility button.</small>
                </div>
            </div>
            <label class="settings-checkbox">
                <input type="checkbox" name="theme_reset" value="1"> Reset appearance to the default theme (ignores the colour, font and size choices above)
            </label>
        </fieldset>
        
        <div class="form-row">
            <div class="form-group">
                <label for="site_phone">Organization Phone</label>
                <input type="tel" id="site_phone" name="site_phone" class="form-control" 
                       value="<?php echo htmlspecialchars($settings['site_phone'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone" class="form-control">
                    <option value="Africa/Kampala" <?php echo ($settings['timezone'] ?? 'Africa/Kampala') == 'Africa/Kampala' ? 'selected' : ''; ?>>Africa/Kampala (EAT)</option>
                    <option value="UTC" <?php echo ($settings['timezone'] ?? '') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                    <option value="America/New_York" <?php echo ($settings['timezone'] ?? '') == 'America/New_York' ? 'selected' : ''; ?>>America/New York (EST)</option>
                    <option value="Europe/London" <?php echo ($settings['timezone'] ?? '') == 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT)</option>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label for="site_address">Organization Address</label>
            <textarea id="site_address" name="site_address" class="form-control" rows="3"><?php echo htmlspecialchars($settings['site_address'] ?? ''); ?></textarea>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="date_format">Date Format</label>
                <select id="date_format" name="date_format" class="form-control">
                    <option value="d M Y" <?php echo ($settings['date_format'] ?? 'd M Y') == 'd M Y' ? 'selected' : ''; ?>>01 Jan 2024</option>
                    <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') == 'm/d/Y' ? 'selected' : ''; ?>>01/31/2024</option>
                    <option value="d-m-Y" <?php echo ($settings['date_format'] ?? '') == 'd-m-Y' ? 'selected' : ''; ?>>31-01-2024</option>
                    <option value="Y-m-d" <?php echo ($settings['date_format'] ?? '') == 'Y-m-d' ? 'selected' : ''; ?>>2024-01-31</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="currency">Default Currency</label>
                <select id="currency" name="currency" class="form-control">
                    <option value="UGX" <?php echo ($settings['currency'] ?? 'UGX') == 'UGX' ? 'selected' : ''; ?>>UGX (Uganda Shillings)</option>
                    <option value="USD" <?php echo ($settings['currency'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD (US Dollars)</option>
                    <option value="EUR" <?php echo ($settings['currency'] ?? '') == 'EUR' ? 'selected' : ''; ?>>EUR (Euros)</option>
                    <option value="GBP" <?php echo ($settings['currency'] ?? '') == 'GBP' ? 'selected' : ''; ?>>GBP (British Pounds)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="items_per_page">Items Per Page</label>
                <input type="number" id="items_per_page" name="items_per_page" class="form-control" 
                       value="<?php echo intval($settings['items_per_page'] ?? 25); ?>" min="10" max="100">
            </div>
        </div>
        
        <div class="settings-actions">
            <button type="submit" name="update_settings" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Settings
            </button>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<!-- Power BI Settings -->
<?php if ($active_tab == 'powerbi'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-chart-line" aria-hidden="true"></i> Power BI Integration</h3></div>
    <div class="card-body">
    
    <!--<div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <strong>Note:</strong> Configure your Power BI workspace and report details here. You'll need to set up an Azure AD application and obtain the necessary credentials.
    </div> -->
    
    <form method="POST" action="includes/settings-process.php">
        <div class="form-group">
            <label class="settings-toggle-label">
                <input type="checkbox" name="powerbi_enabled" <?php echo ($powerbi_settings['enabled'] ?? '0') == '1' ? 'checked' : ''; ?>>
                <strong>Enable Power BI Integration</strong>
            </label>
        </div>
        
        <div class="form-group">
            <label for="embed_url">Power BI Embed URL</label>
            <input type="url" id="embed_url" name="embed_url" class="form-control" 
                   value="<?php echo htmlspecialchars($powerbi_settings['embed_url'] ?? ''); ?>"
                   placeholder="https://app.powerbi.com/reportEmbed?reportId=...">
            <small class="settings-hint">Copy the embed URL from your Power BI report's "Embed" option</small>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="workspace_id">Workspace ID</label>
                <input type="text" id="workspace_id" name="workspace_id" class="form-control" 
                       value="<?php echo htmlspecialchars($powerbi_settings['workspace_id'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="report_id">Report ID</label>
                <input type="text" id="report_id" name="report_id" class="form-control" 
                       value="<?php echo htmlspecialchars($powerbi_settings['report_id'] ?? ''); ?>">
            </div>
        </div>
        
        <div class="form-group">
            <label for="client_id">Azure AD Application (Client) ID</label>
            <input type="text" id="client_id" name="client_id" class="form-control" 
                   value="<?php echo htmlspecialchars($powerbi_settings['client_id'] ?? ''); ?>">
        </div>
        
        <div class="settings-info-box">
            <h4><i class="fas fa-book"></i> Setup Instructions</h4>
            <ol>
                <li>Create a Power BI workspace</li>
                <li>Upload your reports to the workspace</li>
                <li>Register an Azure AD application</li>
                <li>Grant the app permissions to Power BI Service API</li>
                <li>Get the embed URL from your report's "Embed" option</li>
                <li>Enter the credentials above and save</li>
            </ol>
            <a href="https://docs.microsoft.com/en-us/power-bi/developer/embedded/embed-sample-for-customers" target="_blank">
                <i class="fas fa-external-link-alt"></i> View Power BI Documentation
            </a>
        </div>
        
        <div class="settings-actions">
            <button type="submit" name="update_powerbi" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Power BI Settings
            </button>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<!-- Email Settings -->
<?php if ($active_tab == 'email'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-envelope" aria-hidden="true"></i> Email Configuration</h3></div>
    <div class="card-body">
    
    <!--<div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Optional:</strong> Configure SMTP settings to enable email notifications. If not configured, the system will work without email features.
    </div> -->
    
    <form method="POST" action="includes/settings-process.php">
        <div class="form-group">
            <label class="settings-toggle-label">
                <input type="checkbox" name="email_enabled" <?php echo ($settings['email_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>>
                <strong>Enable Email Notifications</strong>
            </label>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="smtp_host">SMTP Host</label>
                <input type="text" id="smtp_host" name="smtp_host" class="form-control" 
                       value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>"
                       placeholder="smtp.gmail.com">
            </div>
            
            <div class="form-group">
                <label for="smtp_port">SMTP Port</label>
                <input type="number" id="smtp_port" name="smtp_port" class="form-control" 
                       value="<?php echo intval($settings['smtp_port'] ?? 587); ?>">
            </div>
            
            <div class="form-group">
                <label for="smtp_encryption">Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption" class="form-control">
                    <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                    <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    <option value="none" <?php echo ($settings['smtp_encryption'] ?? '') == 'none' ? 'selected' : ''; ?>>None</option>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="smtp_user">SMTP Username</label>
                <input type="text" id="smtp_user" name="smtp_user" class="form-control" 
                       value="<?php echo htmlspecialchars($settings['smtp_user'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="smtp_pass">SMTP Password</label>
                <input type="password" id="smtp_pass" name="smtp_pass" class="form-control" 
                       value="" autocomplete="new-password"
                       placeholder="Enter password to change">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="from_name">From Name</label>
                <input type="text" id="from_name" name="from_name" class="form-control" 
                       value="<?php echo htmlspecialchars($settings['from_name'] ?? 'Hive Colab IMS'); ?>">
            </div>
            
            <div class="form-group">
                <label for="from_email">From Email</label>
                <input type="email" id="from_email" name="from_email" class="form-control" 
                       value="<?php echo htmlspecialchars($settings['from_email'] ?? ''); ?>">
            </div>
        </div>
        
        <div class="settings-info-box">
            <h4><i class="fas fa-lightbulb"></i> Common SMTP Settings</h4>
            <div class="settings-info-row">
                <span><strong>Gmail:</strong></span>
                <span>smtp.gmail.com, Port 587 (TLS)</span>
            </div>
            <div class="settings-info-row">
                <span><strong>Outlook/Office365:</strong></span>
                <span>smtp.office365.com, Port 587 (TLS)</span>
            </div>
            <div class="settings-info-row">
                <span><strong>Yahoo:</strong></span>
                <span>smtp.mail.yahoo.com, Port 587 (TLS)</span>
            </div>
        </div>
        
        <div class="settings-actions">
            <button type="submit" name="update_email" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Email Settings
            </button>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>


<!-- Google Calendar Settings -->
<?php if ($active_tab == 'google'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fab fa-google" aria-hidden="true"></i> Google Calendar Integration</h3></div>
    <div class="card-body">

    <div class="stats-grid reports-stats settings-stats google-grid">
        <div class="stat-card">
            <div class="stat-icon <?= $google_calendar_enabled ? 'bg-green' : 'bg-slate' ?>"><i class="fas fa-plug" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Integration</div>
                <div class="stat-value settings-stat-text">
                <?= $google_calendar_enabled ? 'Enabled' : 'Disabled' ?>
            </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon <?= $google_calendar_status === 'Confirmed' ? 'bg-green' : 'bg-amber' ?>"><i class="fas fa-clipboard-check" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Configuration</div>
                <div class="stat-value settings-stat-text">
                <?= htmlspecialchars($google_calendar_status) ?>
            </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-slate"><i class="fas fa-calendar" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Shared Calendar</div>
                <div class="stat-value settings-stat-text">
                <?= htmlspecialchars($google_settings['google_calendar_name'] ?? 'Hive Colab Events') ?>
            </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon <?= $google_calendar_connected ? 'bg-green' : 'bg-amber' ?>"><i class="fas fa-link" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">API Connection</div>
                <div class="stat-value settings-stat-text">
                <?= $google_calendar_connected ? 'Connected' : 'Not Connected' ?>
            </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="fas fa-circle-info"></i>

        <div>
            IMS remains the main event record. Google Calendar should carry the
            essential event details, attendee invitations, reminders and Google Meet link.
        </div>
    </div>

    <form method="POST" action="includes/settings-process.php">
        <div class="form-group">
            <label class="settings-toggle-label">
                <input
                    type="checkbox"
                    name="google_calendar_enabled"
                    <?= $google_calendar_enabled ? 'checked' : '' ?>
                >
                <strong>Enable Google Calendar Integration</strong>
            </label>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="google_client_id">OAuth Client ID</label>
                <input
                    type="text"
                    id="google_client_id"
                    name="google_client_id"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['google_client_id'] ?? '') ?>"
                    autocomplete="off"
                >
            </div>

            <div class="form-group">
                <label for="google_client_secret">OAuth Client Secret</label>

                <div class="settings-secret-wrap">
                    <input
                        type="password"
                        id="google_client_secret"
                        name="google_client_secret"
                        class="form-control"
                        value=""
                        placeholder="<?= !empty($google_settings['google_client_secret']) ? 'Saved — leave blank to keep current secret' : 'Enter Google Client Secret' ?>"
                        autocomplete="new-password"
                    >

                    <button
                        type="button"
                        class="settings-secret-toggle"
                        onclick="toggleSecret('google_client_secret', this)"
                        aria-label="Show or hide Google client secret"
                    >
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="google_calendar_id">Shared Calendar ID</label>
                <input
                    type="text"
                    id="google_calendar_id"
                    name="google_calendar_id"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['google_calendar_id'] ?? '') ?>"
                    placeholder="primary or calendar-id@group.calendar.google.com"
                >
            </div>

            <div class="form-group">
                <label for="google_calendar_name">Calendar Name</label>
                <input
                    type="text"
                    id="google_calendar_name"
                    name="google_calendar_name"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['google_calendar_name'] ?? 'Hive Colab Events') ?>"
                >
            </div>
        </div>

        <div class="form-group">
            <label for="google_redirect_uri">OAuth Redirect URI</label>
            <input
                type="url"
                id="google_redirect_uri"
                name="google_redirect_uri"
                class="form-control"
                value="<?= htmlspecialchars($google_settings['google_redirect_uri'] ?? '') ?>"
                placeholder="https://ims.hivecolab.com/includes/google-calendar-callback.php"
            >
            <small class="settings-hint">
                This must exactly match the authorised redirect URI in Google Cloud.
            </small>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="google_workspace_domain">Workspace Domain</label>
                <input
                    type="text"
                    id="google_workspace_domain"
                    name="google_workspace_domain"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['google_workspace_domain'] ?? '') ?>"
                    placeholder="hivecolab.com"
                >
            </div>

            <div class="form-group">
                <label for="google_workspace_admin_email">Workspace Administrator Email</label>
                <input
                    type="email"
                    id="google_workspace_admin_email"
                    name="google_workspace_admin_email"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['google_workspace_admin_email'] ?? '') ?>"
                >
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="google_impersonation_email">Calendar Owner / Impersonation Email</label>
                <input
                    type="email"
                    id="google_impersonation_email"
                    name="google_impersonation_email"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['google_impersonation_email'] ?? '') ?>"
                >
            </div>

            <div class="form-group">
                <label for="google_calendar_timezone">Calendar Timezone</label>
                <input
                    type="text"
                    id="google_calendar_timezone"
                    name="google_calendar_timezone"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['google_calendar_timezone'] ?? 'Africa/Kampala') ?>"
                >
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <?php $syncMode = $google_settings['google_calendar_sync_mode'] ?? 'two_way'; ?>

                <label for="google_calendar_sync_mode">Sync Direction</label>
                <select
                    id="google_calendar_sync_mode"
                    name="google_calendar_sync_mode"
                    class="form-control"
                >
                    <option value="ims_to_google" <?= $syncMode === 'ims_to_google' ? 'selected' : '' ?>>
                        IMS → Google Calendar
                    </option>
                    <option value="google_to_ims" <?= $syncMode === 'google_to_ims' ? 'selected' : '' ?>>
                        Google Calendar → IMS
                    </option>
                    <option value="two_way" <?= $syncMode === 'two_way' ? 'selected' : '' ?>>
                        Two-way Sync
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="google_sync_interval_minutes">Sync Interval (minutes)</label>
                <input
                    type="number"
                    id="google_sync_interval_minutes"
                    name="google_sync_interval_minutes"
                    class="form-control"
                    min="5"
                    max="1440"
                    value="<?= (int)($google_settings['google_sync_interval_minutes'] ?? 15) ?>"
                >
            </div>
        </div>

        <div class="settings-check-grid">
            <label class="settings-check">
                <input
                    type="checkbox"
                    name="google_create_meet"
                    <?= ($google_settings['google_create_meet'] ?? '1') === '1' ? 'checked' : '' ?>
                >
                <span>
                    <strong>Create Google Meet links</strong>
                    <small>Automatically generate Meet links for online events.</small>
                </span>
            </label>

            <label class="settings-check">
                <input
                    type="checkbox"
                    name="google_send_invitations"
                    <?= ($google_settings['google_send_invitations'] ?? '1') === '1' ? 'checked' : '' ?>
                >
                <span>
                    <strong>Send attendee invitations</strong>
                    <small>Use Google Calendar invitations for internal and external attendees.</small>
                </span>
            </label>
        </div>

        <div class="settings-actions">
            <button
                type="submit"
                name="update_google_calendar"
                class="btn btn-primary"
            >
                <i class="fas fa-save"></i>
                Save Google Calendar Settings
            </button>
        </div>
    </form>

    <div class="google-connect-panel <?= $google_calendar_connected ? 'connected' : '' ?>">
        <h4>
            <i class="fab fa-google"></i>
            Google Calendar API Connection
        </h4>

        <?php if ($google_calendar_connected): ?>
            <div class="alert alert-success settings-alert-tight">
                <i class="fas fa-circle-check"></i>

                <div>
                    Google Calendar is connected. Events and Workplan Activities can now
                    synchronise with
                    <strong>
                        <?= htmlspecialchars($google_settings['google_calendar_name'] ?? 'Hive Colab Events') ?>
                    </strong>.
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning settings-alert-tight">
                <i class="fas fa-triangle-exclamation"></i>

                <div>
                    Your Calendar configuration is saved and confirmed, but OAuth
                    authorisation has not been completed. Click
                    <strong>Connect Google Calendar</strong> once to authorise IMS.
                </div>
            </div>
        <?php endif; ?>

        <div class="google-connect-meta">
            <div>
                <span>Connection Status</span>
                <strong><?= htmlspecialchars($google_connection_status) ?></strong>
            </div>

            <div>
                <span>Connected At</span>
                <strong>
                    <?= $google_connected_at !== ''
                        ? htmlspecialchars($google_connected_at)
                        : '—' ?>
                </strong>
            </div>

            <div>
                <span>Redirect URI</span>
                <strong>
                    <?= htmlspecialchars($google_settings['google_redirect_uri'] ?? '') ?>
                </strong>
            </div>

            <div>
                <span>Shared Calendar ID</span>
                <strong>
                    <?= htmlspecialchars($google_settings['google_calendar_id'] ?? '') ?>
                </strong>
            </div>
        </div>

        <div class="settings-actions settings-actions-tight">
            <?php if ($google_calendar_connected): ?>
                <a
                    href="includes/google-calendar-disconnect.php"
                    class="btn btn-danger"
                >
                    <i class="fas fa-unlink"></i>
                    Disconnect Google Calendar
                </a>
            <?php else: ?>
                <?php if ($google_calendar_enabled && $google_calendar_status === 'Confirmed'): ?>
                    <a
                        href="includes/google-calendar-auth.php"
                        class="btn btn-primary"
                    >
                        <i class="fab fa-google"></i>
                        Connect Google Calendar
                    </a>
                <?php else: ?>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        disabled
                        title="Enable and confirm the Calendar configuration first"
                    >
                        <i class="fab fa-google"></i>
                        Connect Google Calendar
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="settings-confirm-box">
        <h4>
            <i class="fas fa-user-shield"></i>
            Administrator Confirmation
        </h4>

        <div class="settings-info-row">
            <span>Configuration Status</span>
            <strong><?= htmlspecialchars($google_calendar_status) ?></strong>
        </div>

        <div class="settings-info-row">
            <span>Confirmed By User ID</span>
            <strong>
                <?= htmlspecialchars($google_settings['google_calendar_confirmed_by'] ?? '—') ?>
            </strong>
        </div>

        <div class="settings-info-row">
            <span>Confirmed At</span>
            <strong>
                <?= htmlspecialchars($google_settings['google_calendar_confirmed_at'] ?? '—') ?>
            </strong>
        </div>

        <div class="alert alert-warning settings-alert-tight settings-alert-top">
            <i class="fas fa-triangle-exclamation"></i>
            <div>
                Confirmation verifies that the required configuration has been entered.
                Live OAuth/API authorisation should still be completed by the Google callback
                and sync service.
            </div>
        </div>

        <form method="POST" action="includes/settings-process.php">
            <div class="settings-actions settings-actions-flush">
                <button
                    type="submit"
                    name="confirm_google_calendar"
                    class="btn btn-primary"
                >
                    <i class="fas fa-check-circle"></i>
                    Confirm Google Calendar Configuration
                </button>
            </div>
        </form>
    </div>
    </div>
</div>
<?php endif; ?>


<!-- Workspace Gmail Settings -->
<?php if ($active_tab == 'workspace'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-envelope-open-text" aria-hidden="true"></i> Google Workspace Gmail</h3></div>
    <div class="card-body">

    <div class="stats-grid reports-stats settings-stats">
        <div class="stat-card">
            <div class="stat-icon <?= $workspace_gmail_enabled ? 'bg-green' : 'bg-slate' ?>"><i class="fas fa-envelope" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Workspace Gmail</div>
                <div class="stat-value settings-stat-text">
                <?= $workspace_gmail_enabled ? 'Enabled' : 'Disabled' ?>
            </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-slate"><i class="fas fa-globe" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Domain</div>
                <div class="stat-value settings-stat-text">
                <?= htmlspecialchars($google_settings['workspace_gmail_domain'] ?? 'Not configured') ?>
            </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-slate"><i class="fas fa-paper-plane" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Sender</div>
                <div class="stat-value settings-stat-text">
                <?= htmlspecialchars($google_settings['workspace_gmail_sender_email'] ?? 'Not configured') ?>
            </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="fas fa-circle-info"></i>
        <div>
            Configure the organisation Gmail account used for IMS event notifications,
            weekly digest messages and event change/cancellation alerts.
        </div>
    </div>

    <form method="POST" action="includes/settings-process.php">
        <div class="form-group">
            <label class="settings-toggle-label">
                <input
                    type="checkbox"
                    name="workspace_gmail_enabled"
                    <?= $workspace_gmail_enabled ? 'checked' : '' ?>
                >
                <strong>Enable Google Workspace Gmail</strong>
            </label>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="workspace_gmail_domain">Workspace Domain</label>
                <input
                    type="text"
                    id="workspace_gmail_domain"
                    name="workspace_gmail_domain"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['workspace_gmail_domain'] ?? '') ?>"
                    placeholder="hivecolab.com"
                >
            </div>

            <div class="form-group">
                <label for="workspace_gmail_admin_email">Workspace Administrator Email</label>
                <input
                    type="email"
                    id="workspace_gmail_admin_email"
                    name="workspace_gmail_admin_email"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['workspace_gmail_admin_email'] ?? '') ?>"
                >
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="workspace_gmail_sender_email">Notification Sender Email</label>
                <input
                    type="email"
                    id="workspace_gmail_sender_email"
                    name="workspace_gmail_sender_email"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['workspace_gmail_sender_email'] ?? '') ?>"
                >
            </div>

            <div class="form-group">
                <label for="workspace_gmail_sender_name">Notification Sender Name</label>
                <input
                    type="text"
                    id="workspace_gmail_sender_name"
                    name="workspace_gmail_sender_name"
                    class="form-control"
                    value="<?= htmlspecialchars($google_settings['workspace_gmail_sender_name'] ?? 'Hive Colab IMS') ?>"
                >
            </div>
        </div>

        <div class="settings-check-grid">
            <label class="settings-check">
                <input
                    type="checkbox"
                    name="workspace_gmail_use_for_notifications"
                    <?= ($google_settings['workspace_gmail_use_for_notifications'] ?? '1') === '1' ? 'checked' : '' ?>
                >
                <span>
                    <strong>Use Gmail for IMS notifications</strong>
                    <small>Use the Workspace sender for event workflow notifications.</small>
                </span>
            </label>

            <label class="settings-check">
                <input
                    type="checkbox"
                    name="workspace_gmail_monday_digest_enabled"
                    <?= ($google_settings['workspace_gmail_monday_digest_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                >
                <span>
                    <strong>Monday events digest</strong>
                    <small>Send the organisation-wide weekly events summary.</small>
                </span>
            </label>

            <label class="settings-check">
                <input
                    type="checkbox"
                    name="workspace_gmail_public_event_alerts"
                    <?= ($google_settings['workspace_gmail_public_event_alerts'] ?? '1') === '1' ? 'checked' : '' ?>
                >
                <span>
                    <strong>Public event alerts</strong>
                    <small>Notify Communications when a public-facing event is created.</small>
                </span>
            </label>

            <label class="settings-check">
                <input
                    type="checkbox"
                    name="workspace_gmail_change_alerts"
                    <?= ($google_settings['workspace_gmail_change_alerts'] ?? '1') === '1' ? 'checked' : '' ?>
                >
                <span>
                    <strong>Event change alerts</strong>
                    <small>Notify listed attendees when an event changes or is cancelled.</small>
                </span>
            </label>
        </div>

        <div class="settings-actions">
            <button
                type="submit"
                name="update_google_workspace"
                class="btn btn-primary"
            >
                <i class="fas fa-save"></i>
                Save Workspace Gmail Settings
            </button>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>


<!-- Backup & Restore -->
<?php if ($active_tab == 'backup'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-database" aria-hidden="true"></i> Backup & Google Storage</h3></div>
    <div class="card-body">

    <div class="stats-grid reports-stats settings-stats">
        <div class="stat-card">
            <div class="stat-icon <?= $backup_auto_enabled ? 'bg-green' : 'bg-slate' ?>"><i class="fas fa-rotate" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Automatic Backup</div>
                <div class="stat-value settings-stat-text">
                <?= $backup_auto_enabled ? 'Enabled' : 'Disabled' ?>
            </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-slate"><i class="fas fa-clock" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Backup Period</div>
                <div class="stat-value settings-stat-text">
                <?= htmlspecialchars(ucfirst($backup_frequency)) ?>
            </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon <?= stripos($backup_last_run_status, 'success') !== false ? 'bg-green' : 'bg-amber' ?>"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></div>
            <div class="stat-info">
                <div class="stat-label">Last Backup</div>
                <div class="stat-value settings-stat-small">
                <?= htmlspecialchars($backup_last_run_at) ?>
            </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="fas fa-circle-info"></i>
        <div>
            Backups are automatically compressed as <strong>.sql.gz</strong>.
            The administrator can keep them locally, upload them to Google Drive,
            or store them in both locations.
        </div>
    </div>

    <form method="POST" action="includes/settings-process.php">
        <div class="backup-grid">
            <div class="backup-card">
                <h4>
                    <i class="fas fa-clock"></i>
                    Automatic Backup Period
                </h4>

                <div class="form-group">
                    <label class="settings-toggle-label">
                        <input
                            type="checkbox"
                            name="backup_auto_enabled"
                            <?= $backup_auto_enabled ? 'checked' : '' ?>
                        >
                        <strong>Enable automatic backups</strong>
                    </label>
                </div>

                <div class="form-group">
                    <label for="backup_frequency">Backup Frequency</label>

                    <select
                        id="backup_frequency"
                        name="backup_frequency"
                        class="form-control"
                        onchange="updateBackupPeriodFields()"
                    >
                        <option value="hourly" <?= $backup_frequency === 'hourly' ? 'selected' : '' ?>>Hourly</option>
                        <option value="daily" <?= $backup_frequency === 'daily' ? 'selected' : '' ?>>Daily</option>
                        <option value="weekly" <?= $backup_frequency === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                        <option value="monthly" <?= $backup_frequency === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                </div>

                <div
                    class="form-group"
                    id="backupTimeGroup"
                >
                    <label for="backup_time">Backup Time</label>

                    <input
                        type="time"
                        id="backup_time"
                        name="backup_time"
                        class="form-control"
                        value="<?= htmlspecialchars($backup_settings['backup_time'] ?? '02:00') ?>"
                    >
                </div>

                <div
                    class="form-group"
                    id="backupWeekdayGroup"
                    style="display:none;"
                >
                    <label for="backup_weekday">Day of Week</label>

                    <?php $backupWeekday = (int)($backup_settings['backup_weekday'] ?? 1); ?>

                    <select
                        id="backup_weekday"
                        name="backup_weekday"
                        class="form-control"
                    >
                        <option value="1" <?= $backupWeekday === 1 ? 'selected' : '' ?>>Monday</option>
                        <option value="2" <?= $backupWeekday === 2 ? 'selected' : '' ?>>Tuesday</option>
                        <option value="3" <?= $backupWeekday === 3 ? 'selected' : '' ?>>Wednesday</option>
                        <option value="4" <?= $backupWeekday === 4 ? 'selected' : '' ?>>Thursday</option>
                        <option value="5" <?= $backupWeekday === 5 ? 'selected' : '' ?>>Friday</option>
                        <option value="6" <?= $backupWeekday === 6 ? 'selected' : '' ?>>Saturday</option>
                        <option value="7" <?= $backupWeekday === 7 ? 'selected' : '' ?>>Sunday</option>
                    </select>
                </div>

                <div
                    class="form-group"
                    id="backupMonthDayGroup"
                    style="display:none;"
                >
                    <label for="backup_month_day">Day of Month</label>

                    <input
                        type="number"
                        id="backup_month_day"
                        name="backup_month_day"
                        class="form-control"
                        min="1"
                        max="28"
                        value="<?= (int)($backup_settings['backup_month_day'] ?? 1) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="backup_retention_count">
                        Keep Recent Local Backups
                    </label>

                    <input
                        type="number"
                        id="backup_retention_count"
                        name="backup_retention_count"
                        class="form-control"
                        min="1"
                        max="365"
                        value="<?= (int)($backup_settings['backup_retention_count'] ?? 14) ?>"
                    >

                    <small class="settings-hint">
                        Older local backup files are automatically removed.
                    </small>
                </div>
            </div>

            <div class="backup-card">
                <h4>
                    <i class="fas fa-hard-drive"></i>
                    Backup Storage
                </h4>

                <div class="backup-storage-options">
                    <label class="backup-storage-option">
                        <input
                            type="radio"
                            name="backup_storage_destination"
                            value="local"
                            <?= $backup_storage_destination === 'local' ? 'checked' : '' ?>
                            onchange="toggleGoogleBackupConfig()"
                        >
                        <span>
                            <strong>Local Server</strong>
                            <small>Keep backups in IMS exports folder.</small>
                        </span>
                    </label>

                    <label class="backup-storage-option">
                        <input
                            type="radio"
                            name="backup_storage_destination"
                            value="google_drive"
                            <?= $backup_storage_destination === 'google_drive' ? 'checked' : '' ?>
                            onchange="toggleGoogleBackupConfig()"
                        >
                        <span>
                            <strong>Google Drive</strong>
                            <small>Upload backups to Drive and remove local copy.</small>
                        </span>
                    </label>

                    <label class="backup-storage-option">
                        <input
                            type="radio"
                            name="backup_storage_destination"
                            value="both"
                            <?= $backup_storage_destination === 'both' ? 'checked' : '' ?>
                            onchange="toggleGoogleBackupConfig()"
                        >
                        <span>
                            <strong>Both</strong>
                            <small>Keep a local copy and upload to Google Drive.</small>
                        </span>
                    </label>
                </div>

                <div
                    class="backup-google-box"
                    id="googleBackupConfig"
                >
                    <h4>
                        <i class="fab fa-google-drive"></i>
                        Google Drive Configuration
                    </h4>

                    <div class="form-group">
                        <label for="backup_google_drive_folder_id">
                            Drive Folder ID
                        </label>

                        <input
                            type="text"
                            id="backup_google_drive_folder_id"
                            name="backup_google_drive_folder_id"
                            class="form-control"
                            value="<?= htmlspecialchars($backup_settings['backup_google_drive_folder_id'] ?? '') ?>"
                            placeholder="1AbCdEfGh..."
                        >

                        <small class="settings-hint">
                            Share this Drive folder with the service account email below.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="backup_google_shared_drive_id">
                            Shared Drive ID
                        </label>

                        <input
                            type="text"
                            id="backup_google_shared_drive_id"
                            name="backup_google_shared_drive_id"
                            class="form-control"
                            value="<?= htmlspecialchars($backup_settings['backup_google_shared_drive_id'] ?? '') ?>"
                            placeholder="Optional"
                        >
                    </div>

                    <div class="form-group">
                        <label for="backup_google_service_account_email">
                            Service Account Email
                        </label>

                        <input
                            type="email"
                            id="backup_google_service_account_email"
                            name="backup_google_service_account_email"
                            class="form-control"
                            value="<?= htmlspecialchars($backup_settings['backup_google_service_account_email'] ?? '') ?>"
                            placeholder="ims-backup@project.iam.gserviceaccount.com"
                        >
                    </div>

                    <div class="form-group">
                        <label for="backup_google_service_account_json">
                            Service Account JSON
                        </label>

                        <textarea
                            id="backup_google_service_account_json"
                            name="backup_google_service_account_json"
                            class="form-control backup-secret-area"
                            rows="7"
                            placeholder="<?= !empty($backup_settings['backup_google_service_account_json']) ? 'Service account JSON saved — leave blank to keep it' : 'Paste Google service account JSON here' ?>"
                        ></textarea>

                        <small class="settings-hint">
                            For security, the saved JSON is not displayed again.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-actions">
            <button
                type="submit"
                name="update_backup_settings"
                class="btn btn-primary"
            >
                <i class="fas fa-save"></i>
                Save Backup Settings
            </button>
        </div>
    </form>

    <div class="backup-grid backup-grid-spaced">
        <div class="backup-card">
            <h4>
                <i class="fas fa-database"></i>
                Database Information
            </h4>

            <div class="settings-info-row">
                <span>Database Size</span>
                <strong><?= htmlspecialchars((string)$db_size) ?> MB</strong>
            </div>

            <div class="settings-info-row">
                <span>Users</span>
                <strong><?= (int)$counts['users'] ?></strong>
            </div>

            <div class="settings-info-row">
                <span>Projects</span>
                <strong><?= (int)$counts['projects'] ?></strong>
            </div>

            <div class="settings-info-row">
                <span>Documents</span>
                <strong><?= (int)$counts['documents'] ?></strong>
            </div>

            <div class="settings-info-row">
                <span>Last Backup Status</span>
                <strong><?= htmlspecialchars($backup_last_run_status) ?></strong>
            </div>
        </div>

        <div class="backup-card">
            <h4>
                <i class="fas fa-bolt"></i>
                Backup Now
            </h4>

            <p>
                Create a compressed backup immediately using the storage
                destination configured above.
            </p>

            <form
                method="POST"
                action="includes/settings-process.php"
            >
                <button
                    type="submit"
                    name="create_backup"
                    class="btn btn-primary backup-full-btn"
                >
                    <i class="fas fa-cloud-arrow-up"></i>
                    Create Backup Now
                </button>
            </form>

            <div class="alert alert-warning settings-alert-flush">
                <i class="fas fa-terminal"></i>
                <div>
                    Configure cPanel cron to run:
                    <br>
                    <code class="backup-cron">
                        */15 * * * * php <?= htmlspecialchars(__DIR__) ?>/includes/backup-runner.php
                    </code>
                    <br>
                    The runner checks the period selected above and only creates
                    a backup when it is due.
                </div>
            </div>
        </div>
    </div>

    <h4 class="backup-list-title">
        <i class="fas fa-history"></i>
        Recent Local Backups
    </h4>

    <?php if (empty($backups)): ?>
        <div class="empty-state">
            <i class="fas fa-box-archive" aria-hidden="true"></i>
            <p>No local backups found yet.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Backup File</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td>
                                <i class="fas fa-file-archive"></i>
                                <strong><?= htmlspecialchars($backup['filename']) ?></strong>
                            </td>

                            <td>
                                <?= number_format($backup['size'] / 1024 / 1024, 2) ?> MB
                            </td>

                            <td>
                                <?= htmlspecialchars($backup['date']) ?>
                            </td>

                            <td>
                                <a
                                    href="download-export.php?file=<?= rawurlencode($backup['filename']) ?>"
                                    class="btn btn-info btn-sm"
                                    download
                                >
                                    <i class="fas fa-download"></i>
                                    Download
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="settings-info-box settings-space-top">
        <h4>
            <i class="fas fa-redo"></i>
            Restore
        </h4>

        <p>
            Download the required backup, decompress the
            <strong>.sql.gz</strong> file, and import the resulting SQL file
            through phpMyAdmin or MySQL command line.
        </p>
    </div>
    </div>
</div>
<?php endif; ?>

<!-- System Information -->
<?php if ($active_tab == 'system'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-server" aria-hidden="true"></i> System Information</h3></div>
    <div class="card-body">
    
    <div class="settings-two-col">
        <div class="settings-info-box">
            <h4><i class="fas fa-code"></i> Server Environment</h4>
            <div class="settings-info-row">
                <span>PHP Version:</span>
                <strong><?php echo $system_info['php_version']; ?></strong>
            </div>
            <div class="settings-info-row">
                <span>MySQL Version:</span>
                <strong><?php echo $system_info['mysql_version']; ?></strong>
            </div>
            <div class="settings-info-row">
                <span>Server Software:</span>
                <strong><?php echo $system_info['server_software']; ?></strong>
            </div>
            <div class="settings-info-row">
                <span>Operating System:</span>
                <strong><?php echo PHP_OS; ?></strong>
            </div>
        </div>
        
        <div class="settings-info-box">
            <h4><i class="fas fa-sliders-h"></i> PHP Configuration</h4>
            <div class="settings-info-row">
                <span>Max Upload Size:</span>
                <strong><?php echo $system_info['max_upload']; ?></strong>
            </div>
            <div class="settings-info-row">
                <span>Max Post Size:</span>
                <strong><?php echo $system_info['max_post']; ?></strong>
            </div>
            <div class="settings-info-row">
                <span>Memory Limit:</span>
                <strong><?php echo $system_info['memory_limit']; ?></strong>
            </div>
            <div class="settings-info-row">
                <span>Max Execution Time:</span>
                <strong><?php echo $system_info['max_execution_time']; ?>s</strong>
            </div>
        </div>
    </div>
    
    <div class="settings-info-box settings-space-top">
        <h4><i class="fas fa-folder"></i> Directory Permissions</h4>
        <?php
        $dirs = ['uploads', 'exports'];
        foreach ($dirs as $dir):
            $writable = is_writable($dir);
        ?>
            <div class="settings-info-row">
                <span><?php echo $dir; ?>/</span>
                <span>
                    <?php if ($writable): ?>
                        <span class="settings-ok"><i class="fas fa-check-circle" aria-hidden="true"></i> Writable</span>
                    <?php else: ?>
                        <span class="settings-bad"><i class="fas fa-times-circle" aria-hidden="true"></i> Not Writable</span>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="settings-info-box settings-space-top">
        <h4><i class="fas fa-info-circle"></i> Application Information</h4>
        <div class="settings-info-row">
            <span>Application Name:</span>
            <strong>Hive Colab IMS</strong>
        </div>
        <div class="settings-info-row">
            <span>Version:</span>
            <strong>1.0.0</strong>
        </div>
        <div class="settings-info-row">
            <span>Database:</span>
            <strong><?php echo DB_NAME; ?></strong>
        </div>
        <div class="settings-info-row">
            <span>Database Size:</span>
            <strong><?php echo $db_size; ?> MB</strong>
        </div>
        <div class="settings-info-row">
            <span>Installation Path:</span>
            <strong><?php echo __DIR__; ?></strong>
        </div>
    </div>
    </div>
</div>
<?php endif; ?>


<script>
function updateBackupPeriodFields() {
    const frequency =
        document.getElementById('backup_frequency')?.value
        || 'daily';

    const timeGroup =
        document.getElementById('backupTimeGroup');

    const weekdayGroup =
        document.getElementById('backupWeekdayGroup');

    const monthDayGroup =
        document.getElementById('backupMonthDayGroup');

    if (timeGroup) {
        timeGroup.style.display =
            frequency === 'hourly'
                ? 'none'
                : 'block';
    }

    if (weekdayGroup) {
        weekdayGroup.style.display =
            frequency === 'weekly'
                ? 'block'
                : 'none';
    }

    if (monthDayGroup) {
        monthDayGroup.style.display =
            frequency === 'monthly'
                ? 'block'
                : 'none';
    }
}

function toggleGoogleBackupConfig() {
    const selected =
        document.querySelector(
            'input[name="backup_storage_destination"]:checked'
        )?.value
        || 'local';

    const box =
        document.getElementById(
            'googleBackupConfig'
        );

    if (!box) return;

    box.style.display =
        selected === 'local'
            ? 'none'
            : 'block';
}

document.addEventListener(
    'DOMContentLoaded',
    function () {
        updateBackupPeriodFields();
        toggleGoogleBackupConfig();
    }
);

function toggleSecret(inputId, button) {
    const input = document.getElementById(inputId);

    if (!input) return;

    const show = input.type === 'password';

    input.type = show
        ? 'text'
        : 'password';

    const icon = button?.querySelector('i');

    if (icon) {
        icon.classList.toggle('fa-eye', !show);
        icon.classList.toggle('fa-eye-slash', show);
    }
}
</script>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>
