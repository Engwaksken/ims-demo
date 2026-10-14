<?php
declare(strict_types=1);

require_once __DIR__ . '/event-staff.php';

if (!function_exists('gcal_setting')) {
    function gcal_setting(
        mysqli $conn,
        string $key,
        string $default = ''
    ): string {
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
}

if (!function_exists('gcal_setting_save')) {
    function gcal_setting_save(
        mysqli $conn,
        string $key,
        string $value,
        int $userId = 0
    ): void {
        $value = ims_setting_encode($key, $value); // encrypt secrets at rest
        $check = $conn->prepare("
            SELECT setting_id
            FROM system_settings
            WHERE setting_key = ?
            LIMIT 1
        ");

        if (!$check) {
            throw new RuntimeException(
                'Could not inspect Google Calendar setting.'
            );
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
                throw new RuntimeException(
                    'Could not update Google Calendar setting.'
                );
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
                throw new RuntimeException(
                    'Could not insert Google Calendar setting.'
                );
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
                'Could not save Google Calendar setting: '
                . $error
            );
        }

        $stmt->close();
    }
}

if (!function_exists('gcal_column_exists')) {
    function gcal_column_exists(
        mysqli $conn,
        string $table,
        string $column
    ): bool {
        $sql =
            "SHOW COLUMNS FROM `"
            . $conn->real_escape_string($table)
            . "` LIKE '"
            . $conn->real_escape_string($column)
            . "'";

        $res = $conn->query($sql);

        if (!$res) {
            return false;
        }

        $exists = $res->num_rows > 0;
        $res->close();

        return $exists;
    }
}

if (!function_exists('gcal_is_configured')) {
    function gcal_is_configured(mysqli $conn): bool
    {
        return
            gcal_setting(
                $conn,
                'google_calendar_enabled',
                '0'
            ) === '1'
            &&
            gcal_setting(
                $conn,
                'google_calendar_configuration_status'
            ) === 'Confirmed'
            &&
            gcal_setting(
                $conn,
                'google_client_id'
            ) !== ''
            &&
            gcal_setting(
                $conn,
                'google_client_secret'
            ) !== ''
            &&
            gcal_setting(
                $conn,
                'google_calendar_id'
            ) !== '';
    }
}

if (!function_exists('gcal_is_connected')) {
    function gcal_is_connected(mysqli $conn): bool
    {
        return
            gcal_is_configured($conn)
            &&
            gcal_setting(
                $conn,
                'google_refresh_token'
            ) !== '';
    }
}

if (!function_exists('gcal_base64url')) {
    function gcal_base64url(string $value): string
    {
        return rtrim(
            strtr(
                base64_encode($value),
                '+/',
                '-_'
            ),
            '='
        );
    }
}

if (!function_exists('gcal_auth_url')) {
    function gcal_auth_url(
        mysqli $conn,
        int $userId
    ): string {
        $clientId = gcal_setting(
            $conn,
            'google_client_id'
        );

        $redirectUri = gcal_setting(
            $conn,
            'google_redirect_uri'
        );

        if ($clientId === '' || $redirectUri === '') {
            throw new RuntimeException(
                'Google OAuth Client ID and Redirect URI are required.'
            );
        }

        $statePayload = json_encode(
            [
                'user_id' => $userId,
                'nonce' => bin2hex(random_bytes(12)),
                'created_at' => time(),
            ],
            JSON_UNESCAPED_SLASHES
        );

        $state = gcal_base64url(
            $statePayload ?: ''
        );

        $_SESSION['google_calendar_oauth_state'] = $state;

        return
            'https://accounts.google.com/o/oauth2/v2/auth?'
            . http_build_query(
                [
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                    'response_type' => 'code',
                    'scope' => implode(
                        ' ',
                        [
                            'https://www.googleapis.com/auth/calendar',
                            'https://www.googleapis.com/auth/calendar.events',
                        ]
                    ),
                    'access_type' => 'offline',
                    'prompt' => 'consent',
                    'include_granted_scopes' => 'true',
                    'state' => $state,
                ]
            );
    }
}

if (!function_exists('gcal_exchange_code')) {
    function gcal_exchange_code(
        mysqli $conn,
        string $code,
        int $userId
    ): void {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'PHP cURL extension is required.'
            );
        }

        $clientId = gcal_setting(
            $conn,
            'google_client_id'
        );

        $clientSecret = gcal_setting(
            $conn,
            'google_client_secret'
        );

        $redirectUri = gcal_setting(
            $conn,
            'google_redirect_uri'
        );

        $curl = curl_init(
            'https://oauth2.googleapis.com/token'
        );

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_POSTFIELDS => http_build_query(
                    [
                        'code' => $code,
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'redirect_uri' => $redirectUri,
                        'grant_type' => 'authorization_code',
                    ]
                ),
            ]
        );

        $response = curl_exec($curl);
        $httpCode = (int)curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );
        $error = curl_error($curl);

        curl_close($curl);

        if (
            $response === false
            ||
            $httpCode < 200
            ||
            $httpCode >= 300
        ) {
            throw new RuntimeException(
                'Google OAuth token exchange failed. '
                . ($error !== '' ? $error : (string)$response)
            );
        }

        $data = json_decode(
            (string)$response,
            true
        );

        if (!is_array($data)) {
            throw new RuntimeException(
                'Invalid Google OAuth response.'
            );
        }

        $accessToken = trim(
            (string)($data['access_token'] ?? '')
        );

        if ($accessToken === '') {
            throw new RuntimeException(
                'Google did not return an access token.'
            );
        }

        gcal_setting_save(
            $conn,
            'google_access_token',
            $accessToken,
            $userId
        );

        if (!empty($data['refresh_token'])) {
            gcal_setting_save(
                $conn,
                'google_refresh_token',
                (string)$data['refresh_token'],
                $userId
            );
        }

        $expiresIn = max(
            60,
            (int)($data['expires_in'] ?? 3600)
        );

        gcal_setting_save(
            $conn,
            'google_access_token_expires_at',
            (string)(time() + $expiresIn - 60),
            $userId
        );

        gcal_setting_save(
            $conn,
            'google_calendar_connection_status',
            'Connected',
            $userId
        );

        gcal_setting_save(
            $conn,
            'google_calendar_connected_at',
            date('Y-m-d H:i:s'),
            $userId
        );
    }
}

if (!function_exists('gcal_access_token')) {
    function gcal_access_token(
        mysqli $conn,
        int $userId = 0
    ): string {
        $accessToken = gcal_setting(
            $conn,
            'google_access_token'
        );

        $expiresAt = (int)gcal_setting(
            $conn,
            'google_access_token_expires_at',
            '0'
        );

        if (
            $accessToken !== ''
            &&
            $expiresAt > time() + 30
        ) {
            return $accessToken;
        }

        $refreshToken = gcal_setting(
            $conn,
            'google_refresh_token'
        );

        if ($refreshToken === '') {
            throw new RuntimeException(
                'Google Calendar is configured but not connected. Connect it from Settings.'
            );
        }

        $clientId = gcal_setting(
            $conn,
            'google_client_id'
        );

        $clientSecret = gcal_setting(
            $conn,
            'google_client_secret'
        );

        $curl = curl_init(
            'https://oauth2.googleapis.com/token'
        );

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_POSTFIELDS => http_build_query(
                    [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $refreshToken,
                        'grant_type' => 'refresh_token',
                    ]
                ),
            ]
        );

        $response = curl_exec($curl);
        $httpCode = (int)curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );
        $error = curl_error($curl);

        curl_close($curl);

        if (
            $response === false
            ||
            $httpCode < 200
            ||
            $httpCode >= 300
        ) {
            throw new RuntimeException(
                'Could not refresh Google access token. '
                . ($error !== '' ? $error : (string)$response)
            );
        }

        $data = json_decode(
            (string)$response,
            true
        );

        $newAccess = trim(
            (string)($data['access_token'] ?? '')
        );

        if ($newAccess === '') {
            throw new RuntimeException(
                'Google token refresh did not return an access token.'
            );
        }

        gcal_setting_save(
            $conn,
            'google_access_token',
            $newAccess,
            $userId
        );

        gcal_setting_save(
            $conn,
            'google_access_token_expires_at',
            (string)(
                time()
                +
                max(
                    60,
                    (int)($data['expires_in'] ?? 3600)
                )
                -
                60
            ),
            $userId
        );

        return $newAccess;
    }
}

if (!function_exists('gcal_api')) {
    function gcal_api(
        mysqli $conn,
        string $method,
        string $path,
        ?array $payload = null,
        int $userId = 0
    ): array {
        $token = gcal_access_token(
            $conn,
            $userId
        );

        $url =
            'https://www.googleapis.com/calendar/v3/'
            . ltrim($path, '/');

        $curl = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        if ($payload !== null) {
            $headers[] =
                'Content-Type: application/json';
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] =
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES
                    |
                    JSON_UNESCAPED_UNICODE
                );
        }

        curl_setopt_array(
            $curl,
            $options
        );

        $response = curl_exec($curl);
        $httpCode = (int)curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );
        $error = curl_error($curl);

        curl_close($curl);

        if (
            $response === false
            ||
            $httpCode < 200
            ||
            $httpCode >= 300
        ) {
            $message =
                $error !== ''
                    ? $error
                    : (string)$response;

            throw new RuntimeException(
                'Google Calendar API error ('
                . $httpCode
                . '): '
                . $message
            );
        }

        if (
            $response === ''
            ||
            $httpCode === 204
        ) {
            return [];
        }

        $decoded = json_decode(
            (string)$response,
            true
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }
}

if (!function_exists('gcal_event_datetime')) {
    function gcal_event_datetime(
        string $date,
        ?string $time,
        string $timezone
    ): array {
        $time = trim((string)$time);

        if ($time === '') {
            return [
                'date' => $date,
            ];
        }

        $dateTime =
            $date
            . 'T'
            . substr($time, 0, 5)
            . ':00';

        return [
            'dateTime' => $dateTime,
            'timeZone' => $timezone,
        ];
    }
}

if (!function_exists('gcal_status_to_google')) {
    function gcal_status_to_google(
        string $status
    ): string {
        return strtolower($status) === 'cancelled'
            ? 'cancelled'
            : 'confirmed';
    }
}

if (!function_exists('gcal_hub_event_payload')) {
    function gcal_hub_event_payload(
        array $event,
        string $timezone
    ): array {
        $startDate =
            (string)($event['event_date'] ?? '');

        $endDate =
            (string)(
                $event['event_end_date']
                ?? $event['event_date']
                ?? ''
            );

        $start = gcal_event_datetime(
            $startDate,
            $event['start_time'] ?? null,
            $timezone
        );

        $end = gcal_event_datetime(
            $endDate,
            $event['end_time'] ?? null,
            $timezone
        );

        if (
            isset($start['date'])
            &&
            isset($end['date'])
        ) {
            try {
                $endDateObj = new DateTimeImmutable(
                    $end['date']
                );
                $end['date'] =
                    $endDateObj
                        ->modify('+1 day')
                        ->format('Y-m-d');
            } catch (Throwable $e) {
            }
        }

        $description = trim(
            (string)($event['description'] ?? '')
        );

        $payload = [
            'summary' => (string)(
                $event['event_title']
                ?? 'IMS Event'
            ),
            'description' => $description,
            'location' => (string)(
                $event['venue']
                ?? ''
            ),
            'start' => $start,
            'end' => $end,
            'status' => gcal_status_to_google(
                (string)(
                    $event['event_status']
                    ?? 'Planned'
                )
            ),
            'extendedProperties' => [
                'private' => [
                    'ims_resource_type' => 'hub_event',
                    'ims_resource_id' => (string)(
                        $event['event_id']
                        ?? 0
                    ),
                ],
            ],
        ];

        if (
            gcal_setting(
                $GLOBALS['conn'],
                'google_create_meet',
                '1'
            ) === '1'
        ) {
            $payload['conferenceData'] = [
                'createRequest' => [
                    'requestId' =>
                        'ims-event-'
                        . (int)($event['event_id'] ?? 0)
                        . '-'
                        . time(),
                    'conferenceSolutionKey' => [
                        'type' => 'hangoutsMeet',
                    ],
                ],
            ];
        }

        return $payload;
    }
}

if (!function_exists('gcal_activity_payload')) {
    function gcal_activity_payload(
        array $activity,
        string $timezone
    ): array {
        $startDate =
            (string)($activity['start_date'] ?? '');

        $endDate =
            (string)(
                $activity['end_date']
                ?? $startDate
            );

        if ($endDate === '') {
            $endDate = $startDate;
        }

        try {
            $endExclusive =
                (new DateTimeImmutable($endDate))
                ->modify('+1 day')
                ->format('Y-m-d');
        } catch (Throwable $e) {
            $endExclusive = $endDate;
        }

        $descriptionParts = [];

        if (!empty($activity['milestone_name'])) {
            $descriptionParts[] =
                'Milestone: '
                . $activity['milestone_name'];
        }

        if (!empty($activity['responsible_person'])) {
            $descriptionParts[] =
                'Responsible: '
                . $activity['responsible_person'];
        }

        if (!empty($activity['notes'])) {
            $descriptionParts[] =
                'Notes: '
                . $activity['notes'];
        }

        return [
            'summary' => (string)(
                $activity['title']
                ?? $activity['activity_name']
                ?? 'Workplan Activity'
            ),
            'description' => implode(
                "\n",
                $descriptionParts
            ),
            'start' => [
                'date' => $startDate,
            ],
            'end' => [
                'date' => $endExclusive,
            ],
            'extendedProperties' => [
                'private' => [
                    'ims_resource_type' => 'workplan_activity',
                    'ims_resource_id' => (string)(
                        $activity['id']
                        ?? $activity['activity_id']
                        ?? 0
                    ),
                ],
            ],
        ];
    }
}

if (!function_exists('gcal_upsert_google_event')) {
    function gcal_upsert_google_event(
        mysqli $conn,
        array $payload,
        ?string $googleEventId = null,
        int $userId = 0
    ): array {
        $calendarId = rawurlencode(
            gcal_setting(
                $conn,
                'google_calendar_id'
            )
        );

        $sendUpdates =
            gcal_setting(
                $conn,
                'google_send_invitations',
                '1'
            ) === '1'
                ? 'all'
                : 'none';

        if (
            $googleEventId !== null
            &&
            trim($googleEventId) !== ''
        ) {
            return gcal_api(
                $conn,
                'PUT',
                'calendars/'
                . $calendarId
                . '/events/'
                . rawurlencode($googleEventId)
                . '?sendUpdates='
                . rawurlencode($sendUpdates)
                . '&conferenceDataVersion=1',
                $payload,
                $userId
            );
        }

        return gcal_api(
            $conn,
            'POST',
            'calendars/'
            . $calendarId
            . '/events'
            . '?sendUpdates='
            . rawurlencode($sendUpdates)
            . '&conferenceDataVersion=1',
            $payload,
            $userId
        );
    }
}

if (!function_exists('gcal_delete_google_event')) {
    function gcal_delete_google_event(
        mysqli $conn,
        string $googleEventId,
        int $userId = 0
    ): void {
        if ($googleEventId === '') {
            return;
        }

        $calendarId = rawurlencode(
            gcal_setting(
                $conn,
                'google_calendar_id'
            )
        );

        $sendUpdates =
            gcal_setting(
                $conn,
                'google_send_invitations',
                '1'
            ) === '1'
                ? 'all'
                : 'none';

        try {
            gcal_api(
                $conn,
                'DELETE',
                'calendars/'
                . $calendarId
                . '/events/'
                . rawurlencode($googleEventId)
                . '?sendUpdates='
                . $sendUpdates,
                null,
                $userId
            );
        } catch (Throwable $e) {
            if (
                !str_contains(
                    $e->getMessage(),
                    '(404)'
                )
            ) {
                throw $e;
            }
        }
    }
}

if (!function_exists('gcal_sync_hub_event')) {
    function gcal_sync_hub_event(
        mysqli $conn,
        int $eventId,
        int $userId = 0
    ): array {
        if (!gcal_is_connected($conn)) {
            throw new RuntimeException(
                'Google Calendar is not connected.'
            );
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM hub_events
            WHERE event_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Could not load IMS event.'
            );
        }

        $stmt->bind_param(
            'i',
            $eventId
        );

        $stmt->execute();

        $event =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$event) {
            throw new RuntimeException(
                'IMS event not found.'
            );
        }

        $timezone =
            trim(
                (string)(
                    $event['timezone']
                    ?? ''
                )
            );

        if ($timezone === '') {
            $timezone = gcal_setting(
                $conn,
                'google_calendar_timezone',
                'Africa/Kampala'
            );
        }

        $payload = gcal_hub_event_payload(
            $event,
            $timezone
        );

        // Invited staff become Google attendees; Google emails them the
        // invite (and updates/cancellations) when sendUpdates is "all".
        // Always set the list so staff removed in IMS are removed in Google.
        $payload['attendees'] = ev_staff_google_attendees(
            $conn,
            $eventId
        );

        $result = gcal_upsert_google_event(
            $conn,
            $payload,
            $event['google_event_id'] ?? null,
            $userId
        );

        if (
            gcal_column_exists(
                $conn,
                'hub_events',
                'google_event_id'
            )
        ) {
            $googleId = (string)(
                $result['id']
                ?? $event['google_event_id']
                ?? ''
            );

            $htmlLink = (string)(
                $result['htmlLink']
                ?? ''
            );

            $meetLink = '';

            if (!empty($result['hangoutLink'])) {
                $meetLink =
                    (string)$result['hangoutLink'];
            }

            $status = 'Synced';
            $error = null;

            $stmt = $conn->prepare("
                UPDATE hub_events
                SET
                    google_event_id = NULLIF(?, ''),
                    google_html_link = NULLIF(?, ''),
                    meeting_link = NULLIF(?, ''),
                    google_sync_status = ?,
                    google_sync_error = ?,
                    google_last_synced_at = NOW()
                WHERE event_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'sssssi',
                    $googleId,
                    $htmlLink,
                    $meetLink,
                    $status,
                    $error,
                    $eventId
                );

                $stmt->execute();
                $stmt->close();
            }
        }

        return $result;
    }
}

if (!function_exists('gcal_mark_hub_event_error')) {
    function gcal_mark_hub_event_error(
        mysqli $conn,
        int $eventId,
        string $message
    ): void {
        if (
            !gcal_column_exists(
                $conn,
                'hub_events',
                'google_sync_status'
            )
        ) {
            return;
        }

        $status = 'Sync Failed';

        $stmt = $conn->prepare("
            UPDATE hub_events
            SET
                google_sync_status = ?,
                google_sync_error = ?,
                google_last_synced_at = NOW()
            WHERE event_id = ?
        ");

        if ($stmt) {
            $stmt->bind_param(
                'ssi',
                $status,
                $message,
                $eventId
            );

            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('gcal_sync_activity')) {
    function gcal_sync_activity(
        mysqli $conn,
        int $activityId,
        int $userId = 0
    ): array {
        if (!gcal_is_connected($conn)) {
            throw new RuntimeException(
                'Google Calendar is not connected.'
            );
        }

        $stmt = $conn->prepare("
            SELECT
                wd.*,
                wm.milestone_name
            FROM workplan_deliverables wd
            INNER JOIN workplan_milestones wm
                ON wm.id = wd.milestone_id
            WHERE wd.id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Could not load workplan activity.'
            );
        }

        $stmt->bind_param(
            'i',
            $activityId
        );

        $stmt->execute();

        $activity =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$activity) {
            throw new RuntimeException(
                'Workplan activity not found.'
            );
        }

        if (
            empty($activity['start_date'])
        ) {
            throw new RuntimeException(
                'Activity requires a start date before Google Calendar sync.'
            );
        }

        $timezone = gcal_setting(
            $conn,
            'google_calendar_timezone',
            'Africa/Kampala'
        );

        $payload = gcal_activity_payload(
            $activity,
            $timezone
        );

        $result = gcal_upsert_google_event(
            $conn,
            $payload,
            $activity['google_event_id'] ?? null,
            $userId
        );

        if (
            gcal_column_exists(
                $conn,
                'workplan_deliverables',
                'google_event_id'
            )
        ) {
            $googleId = (string)(
                $result['id']
                ?? $activity['google_event_id']
                ?? ''
            );

            $htmlLink = (string)(
                $result['htmlLink']
                ?? ''
            );

            $status = 'Synced';
            $error = null;

            $stmt = $conn->prepare("
                UPDATE workplan_deliverables
                SET
                    google_event_id = NULLIF(?, ''),
                    google_html_link = NULLIF(?, ''),
                    google_sync_status = ?,
                    google_sync_error = ?,
                    google_last_synced_at = NOW()
                WHERE id = ?
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'ssssi',
                    $googleId,
                    $htmlLink,
                    $status,
                    $error,
                    $activityId
                );

                $stmt->execute();
                $stmt->close();
            }
        }

        return $result;
    }
}

if (!function_exists('gcal_mark_activity_error')) {
    function gcal_mark_activity_error(
        mysqli $conn,
        int $activityId,
        string $message
    ): void {
        if (
            !gcal_column_exists(
                $conn,
                'workplan_deliverables',
                'google_sync_status'
            )
        ) {
            return;
        }

        $status = 'Sync Failed';

        $stmt = $conn->prepare("
            UPDATE workplan_deliverables
            SET
                google_sync_status = ?,
                google_sync_error = ?,
                google_last_synced_at = NOW()
            WHERE id = ?
        ");

        if ($stmt) {
            $stmt->bind_param(
                'ssi',
                $status,
                $message,
                $activityId
            );

            $stmt->execute();
            $stmt->close();
        }
    }
}
