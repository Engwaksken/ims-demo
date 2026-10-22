<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/backup-service.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role(IMS_ADMIN_ROLES); // same roles as settings.php, so everyone who can open the page can save it

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not available.');
}

$conn->set_charset('utf8mb4');

$userId = (int)($_SESSION['user_id'] ?? 0);

function settings_redirect(string $tab): never
{
    header('Location: ../settings?tab=' . rawurlencode($tab));
    exit;
}

function settings_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function settings_bool(string $key): string
{
    return isset($_POST[$key]) ? '1' : '0';
}

function settings_get(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare("
        SELECT setting_value
        FROM system_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return isset($row['setting_value'])
        ? ims_setting_decode($key, (string)$row['setting_value'])
        : $default;
}

function settings_upsert(
    mysqli $conn,
    string $key,
    string $value,
    int $userId
): void {
    $value = ims_setting_encode($key, $value); // encrypt secrets at rest
    $check = $conn->prepare("
        SELECT setting_id
        FROM system_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    if (!$check) {
        throw new RuntimeException('Could not check system setting.');
    }

    $check->bind_param('s', $key);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        $stmt = $conn->prepare("
            UPDATE system_settings
            SET setting_value = ?,
                updated_by = ?
            WHERE setting_key = ?
        ");

        if (!$stmt) {
            throw new RuntimeException('Could not prepare setting update.');
        }

        $stmt->bind_param(
            'sis',
            $value,
            $userId,
            $key
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO system_settings
            (
                setting_key,
                setting_value,
                updated_by
            )
            VALUES (?, ?, ?)
        ");

        if (!$stmt) {
            throw new RuntimeException('Could not prepare setting insert.');
        }

        $stmt->bind_param(
            'ssi',
            $key,
            $value,
            $userId
        );
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Could not save system setting: ' . $error
        );
    }

    $stmt->close();
}

function settings_upsert_many(
    mysqli $conn,
    array $settings,
    int $userId
): void {
    foreach ($settings as $key => $value) {
        settings_upsert(
            $conn,
            (string)$key,
            (string)$value,
            $userId
        );
    }
}

function powerbi_upsert(
    mysqli $conn,
    string $key,
    string $value
): void {
    $check = $conn->prepare("
        SELECT setting_id
        FROM powerbi_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    if (!$check) {
        throw new RuntimeException('Could not check Power BI setting.');
    }

    $check->bind_param('s', $key);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        $stmt = $conn->prepare("
            UPDATE powerbi_settings
            SET setting_value = ?
            WHERE setting_key = ?
        ");

        if (!$stmt) {
            throw new RuntimeException('Could not prepare Power BI update.');
        }

        $stmt->bind_param('ss', $value, $key);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO powerbi_settings
            (
                setting_key,
                setting_value
            )
            VALUES (?, ?)
        ");

        if (!$stmt) {
            throw new RuntimeException('Could not prepare Power BI insert.');
        }

        $stmt->bind_param('ss', $key, $value);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Could not save Power BI setting: ' . $error
        );
    }

    $stmt->close();
}

function settings_success(
    int $userId,
    string $message,
    string $tab,
    string $action,
    string $table = 'system_settings'
): never {
    log_action(
        $userId,
        $action,
        $table,
        null,
        $message
    );

    send_notification(
        $userId,
        $message,
        'success'
    );

    settings_redirect($tab);
}

function settings_error(
    int $userId,
    string $message,
    string $tab
): never {
    send_notification(
        $userId,
        $message,
        'danger'
    );

    settings_redirect($tab);
}

/*
|--------------------------------------------------------------------------
| GENERAL SETTINGS
|--------------------------------------------------------------------------
*/

if (isset($_POST['update_settings'])) {
    try {
        settings_upsert_many(
            $conn,
            [
                'site_name' => sanitize_input(settings_post('site_name')),
                'site_email' => sanitize_input(settings_post('site_email')),
                'site_phone' => sanitize_input(settings_post('site_phone')),
                'site_address' => sanitize_input(settings_post('site_address')),
                'timezone' => sanitize_input(settings_post('timezone', 'Africa/Kampala')),
                'date_format' => sanitize_input(settings_post('date_format', 'd M Y')),
                'currency' => sanitize_input(settings_post('currency', 'UGX')),
                'items_per_page' => (string)max(
                    10,
                    min(100, (int)($_POST['items_per_page'] ?? 25))
                ),
            ],
            $userId
        );

        settings_success(
            $userId,
            'System settings updated successfully.',
            'general',
            'Update Settings'
        );
    } catch (Throwable $e) {
        error_log('Settings update failed: ' . $e->getMessage());

        settings_error(
            $userId,
            'Could not update system settings.',
            'general'
        );
    }
}

/*
|--------------------------------------------------------------------------
| POWER BI SETTINGS
|--------------------------------------------------------------------------
*/

if (isset($_POST['update_powerbi'])) {
    try {
        $powerbiData = [
            'embed_url' => sanitize_input(settings_post('embed_url')),
            'workspace_id' => sanitize_input(settings_post('workspace_id')),
            'report_id' => sanitize_input(settings_post('report_id')),
            'client_id' => sanitize_input(settings_post('client_id')),
            'enabled' => settings_bool('powerbi_enabled'),
        ];

        foreach ($powerbiData as $key => $value) {
            powerbi_upsert(
                $conn,
                $key,
                (string)$value
            );
        }

        settings_success(
            $userId,
            'Power BI settings updated successfully.',
            'powerbi',
            'Update Power BI Settings',
            'powerbi_settings'
        );
    } catch (Throwable $e) {
        error_log('Power BI update failed: ' . $e->getMessage());

        settings_error(
            $userId,
            'Could not update Power BI settings.',
            'powerbi'
        );
    }
}

/*
|--------------------------------------------------------------------------
| EMAIL SETTINGS
|--------------------------------------------------------------------------
*/

if (isset($_POST['update_email'])) {
    try {
        $smtpPassword = settings_post('smtp_pass');

        $emailData = [
            'smtp_host' => sanitize_input(settings_post('smtp_host')),
            'smtp_port' => (string)max(
                1,
                min(65535, (int)($_POST['smtp_port'] ?? 587))
            ),
            'smtp_user' => sanitize_input(settings_post('smtp_user')),
            'smtp_encryption' => sanitize_input(
                settings_post('smtp_encryption', 'tls')
            ),
            'from_name' => sanitize_input(settings_post('from_name')),
            'from_email' => sanitize_input(settings_post('from_email')),
            'email_enabled' => settings_bool('email_enabled'),
        ];

        /*
         * Blank password means preserve the existing stored password.
         */
        if ($smtpPassword !== '') {
            $emailData['smtp_pass'] = $smtpPassword;
        }

        settings_upsert_many(
            $conn,
            $emailData,
            $userId
        );

        settings_success(
            $userId,
            'Email settings updated successfully.',
            'email',
            'Update Email Settings'
        );
    } catch (Throwable $e) {
        error_log('Email settings update failed: ' . $e->getMessage());

        settings_error(
            $userId,
            'Could not update email settings.',
            'email'
        );
    }
}

/*
|--------------------------------------------------------------------------
| GOOGLE CALENDAR SETTINGS
|--------------------------------------------------------------------------
|
| Database-backed configuration. No .env values are required.
|
*/

if (isset($_POST['update_google_calendar'])) {
    try {
        $clientSecret = settings_post(
            'google_client_secret'
        );

        $syncMode = settings_post(
            'google_calendar_sync_mode',
            'two_way'
        );

        if (
            !in_array(
                $syncMode,
                [
                    'ims_to_google',
                    'google_to_ims',
                    'two_way',
                ],
                true
            )
        ) {
            $syncMode = 'two_way';
        }

        $googleData = [
            'google_calendar_enabled' => settings_bool(
                'google_calendar_enabled'
            ),
            'google_client_id' => sanitize_input(
                settings_post('google_client_id')
            ),
            'google_calendar_id' => sanitize_input(
                settings_post('google_calendar_id')
            ),
            'google_calendar_name' => sanitize_input(
                settings_post(
                    'google_calendar_name',
                    'Hive Colab Events'
                )
            ),
            'google_redirect_uri' => sanitize_input(
                settings_post('google_redirect_uri')
            ),
            'google_workspace_domain' => strtolower(
                sanitize_input(
                    settings_post('google_workspace_domain')
                )
            ),
            'google_workspace_admin_email' => strtolower(
                sanitize_input(
                    settings_post('google_workspace_admin_email')
                )
            ),
            'google_impersonation_email' => strtolower(
                sanitize_input(
                    settings_post('google_impersonation_email')
                )
            ),
            'google_calendar_timezone' => sanitize_input(
                settings_post(
                    'google_calendar_timezone',
                    'Africa/Kampala'
                )
            ),
            'google_calendar_sync_mode' => $syncMode,
            'google_sync_interval_minutes' => (string)max(
                5,
                min(
                    1440,
                    (int)(
                        $_POST['google_sync_interval_minutes']
                        ?? 15
                    )
                )
            ),
            'google_create_meet' => settings_bool(
                'google_create_meet'
            ),
            'google_send_invitations' => settings_bool(
                'google_send_invitations'
            ),
        ];

        /*
         * Leave the saved secret untouched when the field is blank.
         */
        if ($clientSecret !== '') {
            $googleData[
                'google_client_secret'
            ] = $clientSecret;
        }

        settings_upsert_many(
            $conn,
            $googleData,
            $userId
        );

        settings_upsert_many(
            $conn,
            [
                'google_calendar_configuration_status'
                    => 'Pending Confirmation',
                'google_calendar_confirmed_by'
                    => '',
                'google_calendar_confirmed_at'
                    => '',
            ],
            $userId
        );

        settings_success(
            $userId,
            'Google Calendar configuration saved. Confirm it before enabling calendar synchronisation.',
            'google',
            'Update Google Calendar Settings'
        );
    } catch (Throwable $e) {
        error_log(
            'Google Calendar settings update failed: '
            . $e->getMessage()
        );

        settings_error(
            $userId,
            'Could not save Google Calendar settings.',
            'google'
        );
    }
}

/*
|--------------------------------------------------------------------------
| ADMIN CONFIRM GOOGLE CALENDAR CONFIGURATION
|--------------------------------------------------------------------------
|
| This confirms that the required organisation-level settings exist.
| Actual Google OAuth/API authorisation is handled separately.
|
*/

if (isset($_POST['confirm_google_calendar'])) {
    $errors = [];

    if (
        settings_get(
            $conn,
            'google_calendar_enabled',
            '0'
        ) !== '1'
    ) {
        $errors[] =
            'Enable Google Calendar integration first.';
    }

    if (
        settings_get(
            $conn,
            'google_client_id'
        ) === ''
    ) {
        $errors[] =
            'Google OAuth Client ID is required.';
    }

    if (
        settings_get(
            $conn,
            'google_client_secret'
        ) === ''
    ) {
        $errors[] =
            'Google OAuth Client Secret is required.';
    }

    if (
        settings_get(
            $conn,
            'google_calendar_id'
        ) === ''
    ) {
        $errors[] =
            'Shared Google Calendar ID is required.';
    }

    $redirectUri = settings_get(
        $conn,
        'google_redirect_uri'
    );

    if (
        $redirectUri === ''
        ||
        !filter_var(
            $redirectUri,
            FILTER_VALIDATE_URL
        )
    ) {
        $errors[] =
            'A valid Google OAuth redirect URI is required.';
    }

    if (
        settings_get(
            $conn,
            'google_workspace_domain'
        ) === ''
    ) {
        $errors[] =
            'Google Workspace domain is required.';
    }

    $workspaceAdmin = settings_get(
        $conn,
        'google_workspace_admin_email'
    );

    if (
        $workspaceAdmin === ''
        ||
        !filter_var(
            $workspaceAdmin,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            'A valid Workspace administrator email is required.';
    }

    if ($errors) {
        settings_error(
            $userId,
            implode(' ', $errors),
            'google'
        );
    }

    try {
        settings_upsert_many(
            $conn,
            [
                'google_calendar_configuration_status'
                    => 'Confirmed',
                'google_calendar_confirmed_by'
                    => (string)$userId,
                'google_calendar_confirmed_at'
                    => date('Y-m-d H:i:s'),
            ],
            $userId
        );

        settings_success(
            $userId,
            'Google Calendar configuration confirmed by the administrator.',
            'google',
            'Confirm Google Calendar Configuration'
        );
    } catch (Throwable $e) {
        error_log(
            'Google Calendar confirmation failed: '
            . $e->getMessage()
        );

        settings_error(
            $userId,
            'Could not confirm Google Calendar configuration.',
            'google'
        );
    }
}

/*
|--------------------------------------------------------------------------
| GOOGLE WORKSPACE GMAIL SETTINGS
|--------------------------------------------------------------------------
*/

if (isset($_POST['update_google_workspace'])) {
    try {
        $adminEmail = strtolower(
            sanitize_input(
                settings_post(
                    'workspace_gmail_admin_email'
                )
            )
        );

        $senderEmail = strtolower(
            sanitize_input(
                settings_post(
                    'workspace_gmail_sender_email'
                )
            )
        );

        if (
            $adminEmail !== ''
            &&
            !filter_var(
                $adminEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            settings_error(
                $userId,
                'Enter a valid Google Workspace administrator email.',
                'workspace'
            );
        }

        if (
            $senderEmail !== ''
            &&
            !filter_var(
                $senderEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            settings_error(
                $userId,
                'Enter a valid Workspace sender email.',
                'workspace'
            );
        }

        settings_upsert_many(
            $conn,
            [
                'workspace_gmail_enabled'
                    => settings_bool(
                        'workspace_gmail_enabled'
                    ),
                'workspace_gmail_domain'
                    => strtolower(
                        sanitize_input(
                            settings_post(
                                'workspace_gmail_domain'
                            )
                        )
                    ),
                'workspace_gmail_admin_email'
                    => $adminEmail,
                'workspace_gmail_sender_email'
                    => $senderEmail,
                'workspace_gmail_sender_name'
                    => sanitize_input(
                        settings_post(
                            'workspace_gmail_sender_name',
                            'Hive Colab IMS'
                        )
                    ),
                'workspace_gmail_use_for_notifications'
                    => settings_bool(
                        'workspace_gmail_use_for_notifications'
                    ),
                'workspace_gmail_monday_digest_enabled'
                    => settings_bool(
                        'workspace_gmail_monday_digest_enabled'
                    ),
                'workspace_gmail_public_event_alerts'
                    => settings_bool(
                        'workspace_gmail_public_event_alerts'
                    ),
                'workspace_gmail_change_alerts'
                    => settings_bool(
                        'workspace_gmail_change_alerts'
                    ),
            ],
            $userId
        );

        settings_success(
            $userId,
            'Google Workspace Gmail configuration updated successfully.',
            'workspace',
            'Update Google Workspace Gmail Settings'
        );
    } catch (Throwable $e) {
        error_log(
            'Google Workspace Gmail update failed: '
            . $e->getMessage()
        );

        settings_error(
            $userId,
            'Could not update Google Workspace Gmail settings.',
            'workspace'
        );
    }
}

/*
|--------------------------------------------------------------------------
| AUTOMATIC BACKUP / GOOGLE DRIVE STORAGE SETTINGS
|--------------------------------------------------------------------------
*/

if (isset($_POST['update_backup_settings'])) {
    try {
        $frequency = settings_post(
            'backup_frequency',
            'daily'
        );

        if (
            !in_array(
                $frequency,
                [
                    'hourly',
                    'daily',
                    'weekly',
                    'monthly',
                ],
                true
            )
        ) {
            $frequency = 'daily';
        }

        $storage = settings_post(
            'backup_storage_destination',
            'local'
        );

        if (
            !in_array(
                $storage,
                [
                    'local',
                    'google_drive',
                    'both',
                ],
                true
            )
        ) {
            $storage = 'local';
        }

        $backupTime = settings_post(
            'backup_time',
            '02:00'
        );

        if (
            !preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
                $backupTime
            )
        ) {
            $backupTime = '02:00';
        }

        $serviceAccountJson =
            trim(
                (string)(
                    $_POST[
                        'backup_google_service_account_json'
                    ]
                    ?? ''
                )
            );

        $backupData = [
            'backup_auto_enabled'
                => settings_bool(
                    'backup_auto_enabled'
                ),
            'backup_frequency'
                => $frequency,
            'backup_time'
                => $backupTime,
            'backup_weekday'
                => (string)max(
                    1,
                    min(
                        7,
                        (int)(
                            $_POST[
                                'backup_weekday'
                            ]
                            ?? 1
                        )
                    )
                ),
            'backup_month_day'
                => (string)max(
                    1,
                    min(
                        28,
                        (int)(
                            $_POST[
                                'backup_month_day'
                            ]
                            ?? 1
                        )
                    )
                ),
            'backup_retention_count'
                => (string)max(
                    1,
                    min(
                        365,
                        (int)(
                            $_POST[
                                'backup_retention_count'
                            ]
                            ?? 14
                        )
                    )
                ),
            'backup_storage_destination'
                => $storage,
            'backup_google_drive_folder_id'
                => sanitize_input(
                    settings_post(
                        'backup_google_drive_folder_id'
                    )
                ),
            'backup_google_shared_drive_id'
                => sanitize_input(
                    settings_post(
                        'backup_google_shared_drive_id'
                    )
                ),
            'backup_google_service_account_email'
                => strtolower(
                    sanitize_input(
                        settings_post(
                            'backup_google_service_account_email'
                        )
                    )
                ),
        ];

        /*
         * Blank JSON keeps the existing service account secret.
         */
        if ($serviceAccountJson !== '') {
            $decoded = json_decode(
                $serviceAccountJson,
                true
            );

            if (!is_array($decoded)) {
                settings_error(
                    $userId,
                    'Google service account JSON is invalid.',
                    'backup'
                );
            }

            $backupData[
                'backup_google_service_account_json'
            ] = $serviceAccountJson;

            if (
                empty(
                    $backupData[
                        'backup_google_service_account_email'
                    ]
                )
                &&
                !empty(
                    $decoded['client_email']
                )
            ) {
                $backupData[
                    'backup_google_service_account_email'
                ] = strtolower(
                    (string)$decoded[
                        'client_email'
                    ]
                );
            }
        }

        settings_upsert_many(
            $conn,
            $backupData,
            $userId
        );

        settings_success(
            $userId,
            'Automatic backup and Google Drive storage settings updated successfully.',
            'backup',
            'Update Backup Settings'
        );
    } catch (Throwable $e) {
        error_log(
            'Backup settings update failed: '
            . $e->getMessage()
        );

        settings_error(
            $userId,
            'Could not update backup settings.',
            'backup'
        );
    }
}


/*
|--------------------------------------------------------------------------
| DATABASE BACKUP
|--------------------------------------------------------------------------
*/

if (isset($_POST['create_backup'])) {
    try {
        $result = backup_run(
            $conn,
            $userId,
            'manual'
        );

        $message =
            'Database backup created successfully.';

        if (
            ($result['storage'] ?? 'local')
            ===
            'google_drive'
        ) {
            $message =
                'Database backup created and uploaded to Google Drive successfully.';
        } elseif (
            ($result['storage'] ?? 'local')
            ===
            'both'
        ) {
            $message =
                'Database backup created locally and uploaded to Google Drive successfully.';
        }

        settings_success(
            $userId,
            $message,
            'backup',
            'Create Backup',
            'system'
        );
    } catch (Throwable $e) {
        error_log(
            'Backup failed: '
            . $e->getMessage()
        );

        try {
            backup_setting_save(
                $conn,
                'backup_last_run_status',
                'Failed: ' . $e->getMessage(),
                $userId
            );
        } catch (Throwable $ignored) {
        }

        settings_error(
            $userId,
            'Backup failed: ' . $e->getMessage(),
            'backup'
        );
    }
}

settings_redirect('general');
