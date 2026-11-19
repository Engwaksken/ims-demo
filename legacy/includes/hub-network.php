<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Hub team members, workspace packages and pfSense internet control
|--------------------------------------------------------------------------
| Used by hub-team.php, includes/hub-team-process.php, hub-operations.php
| and cron/hub-internet-sync.php.
|
| Seats: a company's primary member takes one seat; each Active team member
| takes another. The workspace package (hub_space_packages) sets the maximum.
|
| Internet: every registered device (MAC) has an access mode:
|   auto  - online while the membership is Active and not past its end date
|           (and the team member is Active); otherwise disconnected
|   allow - always online (manual override, e.g. a grace period)
|   block - always offline, cannot sign in through the portal either
| The desired state is pushed to pfSense through tools/pfsense/ims-cp-agent.php
| (installed on the firewall), which maintains captive-portal MAC
| pass/block entries and drops active sessions. Requests are HMAC signed.
*/

const HUBNET_AGENT_TIMEOUT = 10;

if (!function_exists('hubnet_ensure_schema')) {
    function hubnet_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS hub_space_packages (
                package_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                max_seats INT UNSIGNED NOT NULL DEFAULT 1,
                description VARCHAR(255) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (package_id),
                UNIQUE KEY uq_hub_package_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT IGNORE INTO hub_space_packages (name, max_seats, description, sort_order) VALUES
                ('Startup subscription', 1, 'One person', 10),
                ('Dedicated table for 2', 2, 'Dedicated table, up to 2 people', 20),
                ('Dedicated table for 4', 4, 'Dedicated table, up to 4 people', 30),
                ('Dedicated table for 6', 6, 'Dedicated table, up to 6 people', 40),
                ('Private office', 10, 'Private office (set the maximum to the office capacity)', 50)",
            "CREATE TABLE IF NOT EXISTS hub_team_members (
                team_member_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(40) NULL,
                job_title VARCHAR(120) NULL,
                status ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (team_member_id),
                KEY idx_hub_team_member (member_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS hub_network_devices (
                device_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id INT NOT NULL,
                team_member_id INT UNSIGNED NULL,
                mac_address CHAR(17) NOT NULL,
                label VARCHAR(80) NULL,
                access_mode ENUM('auto','allow','block') NOT NULL DEFAULT 'auto',
                pf_state ENUM('unknown','connected','disconnected','blocked','error') NOT NULL DEFAULT 'unknown',
                pf_synced_at DATETIME NULL,
                pf_last_error VARCHAR(255) NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (device_id),
                UNIQUE KEY uq_hub_device_mac (mac_address),
                KEY idx_hub_device_owner (member_id, team_member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS hub_network_settings (
                id TINYINT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                agent_url VARCHAR(255) NULL,
                agent_secret TEXT NULL,
                cp_zone VARCHAR(60) NOT NULL DEFAULT 'lan',
                auto_enforce TINYINT(1) NOT NULL DEFAULT 1,
                verify_tls TINYINT(1) NOT NULL DEFAULT 0,
                updated_by INT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT IGNORE INTO hub_network_settings (id) VALUES (1)",
            "CREATE TABLE IF NOT EXISTS hub_network_log (
                log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                device_id INT UNSIGNED NULL,
                mac_address CHAR(17) NULL,
                action VARCHAR(30) NOT NULL,
                ok TINYINT(1) NOT NULL DEFAULT 0,
                message VARCHAR(255) NULL,
                user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (log_id),
                KEY idx_hub_netlog_device (device_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            foreach ($statements as $sql) {
                if (!$conn->query($sql)) {
                    error_log('[hub-network] schema: ' . $conn->error);
                    return $ready = false;
                }
            }

            $hasColumn = $conn->query("SHOW COLUMNS FROM members LIKE 'space_package_id'");

            if ($hasColumn && $hasColumn->num_rows === 0) {
                if (!$conn->query('ALTER TABLE members ADD COLUMN space_package_id INT UNSIGNED NULL')) {
                    error_log('[hub-network] members.space_package_id: ' . $conn->error);
                    return $ready = false;
                }
            }

            return $ready = true;
        } catch (Throwable $e) {
            error_log('[hub-network] schema check failed: ' . $e->getMessage());
            return $ready = false;
        }
    }
}

if (!function_exists('hubnet_normalize_mac')) {
    function hubnet_normalize_mac(string $mac): string
    {
        $hex = preg_replace('/[^a-f0-9]/', '', strtolower(trim($mac))) ?? '';

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
    }
}

/* ---------------------------------------------------------------------
| Packages and seats
--------------------------------------------------------------------- */

if (!function_exists('hubnet_packages')) {
    /** @return list<array<string,mixed>> */
    function hubnet_packages(mysqli $conn, bool $activeOnly = false): array
    {
        $result = $conn->query(
            'SELECT package_id, name, max_seats, description, sort_order, is_active
             FROM hub_space_packages' . ($activeOnly ? ' WHERE is_active = 1' : '') . '
             ORDER BY sort_order, name'
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('hubnet_member')) {
    /** The primary member (company) with its package and seat usage. */
    function hubnet_member(mysqli $conn, int $memberId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT m.member_id, m.user_id, m.membership_number, m.company_name, m.member_type,
                    m.membership_status, m.subscription_plan, m.subscription_end_date, m.space_package_id,
                    u.full_name, u.email,
                    p.name AS package_name, p.max_seats,
                    (SELECT COUNT(*) FROM hub_team_members t
                      WHERE t.member_id = m.member_id AND t.status = 'Active') AS active_team
             FROM members m
             LEFT JOIN users u ON u.user_id = m.user_id
             LEFT JOIN hub_space_packages p ON p.package_id = m.space_package_id
             WHERE m.member_id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $row['seats_used'] = 1 + (int)$row['active_team'];
        $row['seats_max'] = $row['max_seats'] !== null ? (int)$row['max_seats'] : null;
        $row['membership_live'] = hubnet_membership_live($row);

        return $row;
    }
}

if (!function_exists('hubnet_membership_live')) {
    /** Active and not past its subscription end date. */
    function hubnet_membership_live(array $member): bool
    {
        if (($member['membership_status'] ?? '') !== 'Active') {
            return false;
        }

        $end = (string)($member['subscription_end_date'] ?? '');

        return $end === '' || $end === '0000-00-00' || $end >= date('Y-m-d');
    }
}

if (!function_exists('hubnet_seat_available')) {
    /** Can one more person be made Active for this company? */
    function hubnet_seat_available(array $member): bool
    {
        return $member['seats_max'] === null || (int)$member['seats_used'] < (int)$member['seats_max'];
    }
}

/* ---------------------------------------------------------------------
| Desired internet state
--------------------------------------------------------------------- */

if (!function_exists('hubnet_desired_mode')) {
    /**
     * pass   - online without the captive portal
     * block  - offline, portal sign-in refused
     * remove - no rule: active sessions dropped, back to the normal portal
     *
     * $device needs access_mode, team_member_id, team_status,
     * membership_status and subscription_end_date.
     */
    function hubnet_desired_mode(array $device): string
    {
        $mode = (string)($device['access_mode'] ?? 'auto');

        if ($mode === 'block') {
            return 'block';
        }

        if ($mode === 'allow') {
            return 'pass';
        }

        $personActive = empty($device['team_member_id']) || ($device['team_status'] ?? '') === 'Active';

        return $personActive && hubnet_membership_live($device) ? 'pass' : 'remove';
    }
}

if (!function_exists('hubnet_state_for_mode')) {
    function hubnet_state_for_mode(string $mode): string
    {
        return match ($mode) {
            'pass' => 'connected',
            'block' => 'blocked',
            default => 'disconnected',
        };
    }
}

if (!function_exists('hubnet_device_sql')) {
    /** Devices joined with their owner, company and membership. */
    function hubnet_device_sql(string $where = '1=1'): string
    {
        return "SELECT d.*, m.company_name, m.membership_number, m.membership_status, m.subscription_end_date,
                       COALESCE(t.full_name, u.full_name) AS person_name,
                       t.status AS team_status, u.full_name AS primary_name
                FROM hub_network_devices d
                JOIN members m ON m.member_id = d.member_id
                LEFT JOIN users u ON u.user_id = m.user_id
                LEFT JOIN hub_team_members t ON t.team_member_id = d.team_member_id
                WHERE $where";
    }
}

/* ---------------------------------------------------------------------
| Settings and the pfSense agent
--------------------------------------------------------------------- */

if (!function_exists('hubnet_settings')) {
    function hubnet_settings(mysqli $conn): array
    {
        $result = $conn->query('SELECT * FROM hub_network_settings WHERE id = 1 LIMIT 1');
        $row = $result ? ($result->fetch_assoc() ?: []) : [];

        return [
            'enabled' => !empty($row['enabled']),
            'agent_url' => trim((string)($row['agent_url'] ?? '')),
            'has_secret' => trim((string)($row['agent_secret'] ?? '')) !== '',
            'secret' => function_exists('ims_decrypt')
                ? ims_decrypt((string)($row['agent_secret'] ?? ''))
                : (string)($row['agent_secret'] ?? ''),
            'cp_zone' => trim((string)($row['cp_zone'] ?? 'lan')) ?: 'lan',
            'auto_enforce' => !array_key_exists('auto_enforce', $row) || !empty($row['auto_enforce']),
            'verify_tls' => !empty($row['verify_tls']),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}

if (!function_exists('hubnet_call')) {
    /**
     * Signed request to the pfSense agent.
     *
     * @return array{ok:bool,message:string,data:array}
     */
    function hubnet_call(array $settings, string $action, array $payload = []): array
    {
        if ($settings['agent_url'] === '' || $settings['secret'] === '') {
            return ['ok' => false, 'message' => 'pfSense agent URL or shared secret is not configured.', 'data' => []];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'The PHP curl extension is required to reach pfSense.', 'data' => []];
        }

        $body = json_encode(array_merge($payload, [
            'action' => $action,
            'zone' => $settings['cp_zone'],
            'ts' => time(),
            'nonce' => bin2hex(random_bytes(12)),
        ]), JSON_UNESCAPED_SLASHES);

        $ch = curl_init($settings['agent_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => HUBNET_AGENT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => $settings['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $settings['verify_tls'] ? 2 : 0,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-IMS-Signature: ' . hash_hmac('sha256', (string)$body, $settings['secret']),
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return ['ok' => false, 'message' => 'Could not reach pfSense: ' . $error, 'data' => []];
        }

        $decoded = json_decode((string)$response, true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'pfSense agent returned HTTP ' . $status . ' with an unexpected reply.', 'data' => []];
        }

        return [
            'ok' => !empty($decoded['ok']),
            'message' => (string)($decoded['message'] ?? ($status >= 400 ? 'HTTP ' . $status : '')),
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }
}

if (!function_exists('hubnet_log')) {
    function hubnet_log(mysqli $conn, ?int $deviceId, string $mac, string $action, bool $ok, string $message, int $userId): void
    {
        $stmt = $conn->prepare(
            'INSERT INTO hub_network_log (device_id, mac_address, action, ok, message, user_id) VALUES (?, ?, ?, ?, ?, NULLIF(?, 0))'
        );

        if (!$stmt) {
            return;
        }

        $okInt = $ok ? 1 : 0;
        $message = mb_substr($message, 0, 255);
        $stmt->bind_param('issisi', $deviceId, $mac, $action, $okInt, $message, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('hubnet_apply')) {
    /**
     * Pushes the desired state of the given devices to pfSense in one call
     * and records the result on each device.
     *
     * @param list<array<string,mixed>> $devices rows from hubnet_device_sql()
     * @param string|null $forceMode pass|block|remove instead of the desired mode (e.g. before deleting)
     * @return array{ok:bool,message:string,applied:int,failed:int}
     */
    function hubnet_apply(mysqli $conn, array $devices, int $userId, string $reason = 'apply', ?string $forceMode = null): array
    {
        if ($devices === []) {
            return ['ok' => true, 'message' => 'Nothing to update.', 'applied' => 0, 'failed' => 0];
        }

        $settings = hubnet_settings($conn);

        if (!$settings['enabled']) {
            return [
                'ok' => false,
                'message' => 'Saved, but pfSense control is switched off (Hub Team & Internet > pfSense settings).',
                'applied' => 0,
                'failed' => 0,
            ];
        }

        $items = [];
        $byMac = [];

        foreach ($devices as $device) {
            $mac = hubnet_normalize_mac((string)$device['mac_address']);

            if ($mac === '') {
                continue;
            }

            $mode = $forceMode ?? hubnet_desired_mode($device);
            $items[] = [
                'mac' => $mac,
                'mode' => $mode,
                'descr' => mb_substr('IMS ' . ($device['membership_number'] ?? '') . ' ' . ($device['person_name'] ?? '') .
                    (!empty($device['label']) ? ' (' . $device['label'] . ')' : ''), 0, 120),
            ];
            $byMac[$mac] = ['device' => $device, 'mode' => $mode];
        }

        $response = hubnet_call($settings, 'apply', ['items' => $items]);
        $results = is_array($response['data']['results'] ?? null) ? $response['data']['results'] : [];
        $applied = 0;
        $failed = 0;

        $update = $conn->prepare(
            'UPDATE hub_network_devices SET pf_state = ?, pf_synced_at = NOW(), pf_last_error = ? WHERE device_id = ?'
        );

        foreach ($byMac as $mac => $entry) {
            $result = $results[$mac] ?? null;
            $ok = $response['ok'] && is_array($result) && !empty($result['ok']);
            $error = $ok ? null : mb_substr((string)($result['message'] ?? $response['message'] ?: 'No reply for this device.'), 0, 255);
            $state = $ok ? hubnet_state_for_mode($entry['mode']) : 'error';
            $deviceId = (int)$entry['device']['device_id'];

            if ($update) {
                $update->bind_param('ssi', $state, $error, $deviceId);
                $update->execute();
            }

            $ok ? $applied++ : $failed++;
            hubnet_log($conn, $deviceId, $mac, $reason . ':' . $entry['mode'], $ok, $ok ? $state : (string)$error, $userId);
        }

        if ($update) {
            $update->close();
        }

        if (!$response['ok'] && $applied === 0) {
            return ['ok' => false, 'message' => $response['message'] ?: 'pfSense rejected the update.', 'applied' => 0, 'failed' => $failed];
        }

        return [
            'ok' => $failed === 0,
            'message' => $failed === 0
                ? 'pfSense updated (' . $applied . ' device' . ($applied === 1 ? '' : 's') . ').'
                : $applied . ' device(s) updated, ' . $failed . ' failed. See the device status for details.',
            'applied' => $applied,
            'failed' => $failed,
        ];
    }
}

if (!function_exists('hubnet_devices_where')) {
    /** @return list<array<string,mixed>> */
    function hubnet_devices_where(mysqli $conn, string $where, string $types = '', array $params = []): array
    {
        $stmt = $conn->prepare(hubnet_device_sql($where) . ' ORDER BY m.company_name, person_name');

        if (!$stmt) {
            return [];
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('hubnet_apply_member')) {
    /** Re-applies every device of a company (after status, package or team changes). */
    function hubnet_apply_member(mysqli $conn, int $memberId, int $userId, string $reason = 'member'): array
    {
        return hubnet_apply($conn, hubnet_devices_where($conn, 'd.member_id = ?', 'i', [$memberId]), $userId, $reason);
    }
}

if (!function_exists('hubnet_sync_all')) {
    /**
     * Automatic enforcement: pushes every device whose desired state differs
     * from what pfSense last confirmed (or every device when $force).
     */
    function hubnet_sync_all(mysqli $conn, int $userId = 0, bool $force = false): array
    {
        $devices = hubnet_devices_where($conn, '1=1');

        if (!$force) {
            $devices = array_values(array_filter($devices, static function (array $device): bool {
                return ($device['pf_state'] ?? '') !== hubnet_state_for_mode(hubnet_desired_mode($device));
            }));
        }

        return hubnet_apply($conn, $devices, $userId, $force ? 'sync-all' : 'auto');
    }
}
