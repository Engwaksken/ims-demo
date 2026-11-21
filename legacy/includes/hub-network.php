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

            $addColumns = [
                ['members', 'space_package_id', 'INT UNSIGNED NULL'],
                // Pull mode (pfSense polls the IMS): see hub-network-agent.php.
                ['hub_network_settings', 'mode', "VARCHAR(10) NOT NULL DEFAULT 'pull'"],
                ['hub_network_settings', 'last_checkin_at', 'DATETIME NULL'],
                ['hub_network_settings', 'last_checkin_info', 'TEXT NULL'],
                // Staff and guest devices (hub_network_people); member_id is then NULL.
                ['hub_network_devices', 'person_id', 'INT UNSIGNED NULL'],
                // Internet switch: unmanaged (pfSense decides), on (portal enforced), off (open internet).
                ['hub_network_settings', 'portal_control', "VARCHAR(10) NOT NULL DEFAULT 'unmanaged'"],
                // Guest hotspot (hotspot.php) and the Wi-Fi details printed on the QR poster.
                ['hub_network_settings', 'hotspot_enabled', 'TINYINT(1) NOT NULL DEFAULT 0'],
                ['hub_network_settings', 'hotspot_password', 'TEXT NULL'],
                ['hub_network_settings', 'hotspot_hours', 'SMALLINT UNSIGNED NOT NULL DEFAULT 8'],
                ['hub_network_settings', 'hotspot_require_phone', 'TINYINT(1) NOT NULL DEFAULT 1'],
                ['hub_network_settings', 'hotspot_terms', 'TEXT NULL'],
                ['hub_network_settings', 'wifi_ssid', 'VARCHAR(64) NULL'],
                ['hub_network_settings', 'wifi_key', 'TEXT NULL'],
                ['hub_network_settings', 'wifi_security', "VARCHAR(10) NOT NULL DEFAULT 'WPA'"],
                // Where guests go after signing in (empty = organisation website), and whether
                // a sign-in must come from a device pfSense sees on the hub network.
                ['hub_network_settings', 'hotspot_landing_url', 'VARCHAR(255) NULL'],
                ['hub_network_settings', 'hotspot_verify', 'TINYINT(1) NOT NULL DEFAULT 1'],
                // Public help page (hotspot?help) and the contacts shown on the hotspot and pfSense pages.
                ['hub_network_settings', 'hotspot_help', 'TEXT NULL'],
                ['hub_network_settings', 'hotspot_help_phone', 'VARCHAR(40) NULL'],
                ['hub_network_settings', 'hotspot_help_email', 'VARCHAR(190) NULL'],
                ['hub_network_settings', 'hotspot_help_whatsapp', 'VARCHAR(40) NULL'],
                // Speed limits (Kbit/s, NULL = no limit): per device, the default for new
                // hotspot guests, and per voucher batch.
                ['hub_network_devices', 'bw_down_kbps', 'INT UNSIGNED NULL'],
                ['hub_network_devices', 'bw_up_kbps', 'INT UNSIGNED NULL'],
                ['hub_network_settings', 'hotspot_bw_down_kbps', 'INT UNSIGNED NULL'],
                ['hub_network_settings', 'hotspot_bw_up_kbps', 'INT UNSIGNED NULL'],
                ['hub_network_vouchers', 'bw_down_kbps', 'INT UNSIGNED NULL'],
                ['hub_network_vouchers', 'bw_up_kbps', 'INT UNSIGNED NULL'],
            ];

            foreach ($addColumns as [$table, $column, $definition]) {
                $hasColumn = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

                if ($hasColumn && $hasColumn->num_rows === 0
                    && !$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
                    error_log("[hub-network] $table.$column: " . $conn->error);
                    return $ready = false;
                }
            }

            // Staff/guest devices have no member.
            $memberCol = $conn->query("SHOW COLUMNS FROM hub_network_devices LIKE 'member_id'");
            $memberColRow = $memberCol ? $memberCol->fetch_assoc() : null;
            if ($memberColRow && strtoupper((string)$memberColRow['Null']) === 'NO'
                && !$conn->query('ALTER TABLE hub_network_devices MODIFY member_id INT NULL')) {
                error_log('[hub-network] member_id nullable: ' . $conn->error);
                return $ready = false;
            }

            $extraTables = [
                // Staff (linked to an IMS user) and guests (manual or from the hotspot).
                "CREATE TABLE IF NOT EXISTS hub_network_people (
                    person_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    kind ENUM('staff','guest') NOT NULL,
                    user_id INT NULL,
                    full_name VARCHAR(150) NOT NULL,
                    email VARCHAR(190) NULL,
                    phone VARCHAR(40) NULL,
                    organisation VARCHAR(150) NULL,
                    note VARCHAR(255) NULL,
                    status ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
                    valid_until DATETIME NULL,
                    source ENUM('manual','hotspot') NOT NULL DEFAULT 'manual',
                    created_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (person_id),
                    KEY idx_hub_people_kind (kind, status),
                    KEY idx_hub_people_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                // Devices pfSense reported as online at its last check-in (replaced each time).
                "CREATE TABLE IF NOT EXISTS hub_network_online (
                    mac_address CHAR(17) NOT NULL,
                    ip_address VARCHAR(45) NULL,
                    portal_user VARCHAR(120) NULL,
                    in_portal TINYINT(1) NOT NULL DEFAULT 0,
                    session_started DATETIME NULL,
                    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (mac_address)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                // Hourly connected-device counts for the history chart.
                "CREATE TABLE IF NOT EXISTS hub_network_stats (
                    hour_start DATETIME NOT NULL,
                    max_online INT UNSIGNED NOT NULL DEFAULT 0,
                    sum_online INT UNSIGNED NOT NULL DEFAULT 0,
                    samples INT UNSIGNED NOT NULL DEFAULT 0,
                    max_portal INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (hour_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                // Internet vouchers for day clients: redeemed on the hotspot page. The access
                // period starts at first use; expires_at is the last day it can be first used.
                "CREATE TABLE IF NOT EXISTS hub_network_vouchers (
                    voucher_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    code VARCHAR(16) NOT NULL,
                    batch VARCHAR(20) NOT NULL,
                    hours SMALLINT UNSIGNED NOT NULL,
                    max_devices TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    note VARCHAR(150) NULL,
                    expires_at DATETIME NOT NULL,
                    person_id INT UNSIGNED NULL,
                    used_at DATETIME NULL,
                    valid_until DATETIME NULL,
                    revoked_at DATETIME NULL,
                    bw_down_kbps INT UNSIGNED NULL,
                    bw_up_kbps INT UNSIGNED NULL,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (voucher_id),
                    UNIQUE KEY uq_hub_voucher_code (code),
                    KEY idx_hub_voucher_batch (batch),
                    KEY idx_hub_voucher_person (person_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
            foreach ($extraTables as $sql) {
                if (!$conn->query($sql)) {
                    error_log('[hub-network] schema: ' . $conn->error);
                    return $ready = false;
                }
            }

            if (!$conn->query(
                "CREATE TABLE IF NOT EXISTS hub_agent_nonces (
                    nonce CHAR(24) NOT NULL,
                    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (nonce),
                    KEY idx_hub_agent_nonce_seen (seen_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                error_log('[hub-network] hub_agent_nonces: ' . $conn->error);
                return $ready = false;
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

if (!function_exists('hubnet_parse_device_lines')) {
    /**
     * Several devices typed at once, one per line: "MAC [label]", e.g.
     *   a4:5e:60:12:ab:cd Laptop
     *   A45E6012ABCE  Phone
     * Several MACs on one line (comma/space separated) are each a device;
     * any other text on the line becomes the label of the line's last MAC.
     *
     * @return array{devices: list<array{mac:string,label:string}>, errors: list<string>}
     */
    function hubnet_parse_device_lines(string $text): array
    {
        $devices = [];
        $errors = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $macs = [];
            $labelParts = [];
            foreach (preg_split('/[\s,;]+/', $line) ?: [] as $token) {
                $mac = hubnet_normalize_mac($token);
                if ($mac !== '' && $labelParts === []) {
                    $macs[] = $mac;
                } else {
                    $labelParts[] = $token;
                }
            }
            if ($macs === []) {
                $errors[] = '"' . mb_substr($line, 0, 40) . '" does not start with a valid MAC address (e.g. a4:5e:60:12:ab:cd).';
                continue;
            }
            $label = mb_substr(trim(implode(' ', $labelParts)), 0, 80);
            foreach ($macs as $i => $mac) {
                $devices[$mac] = ['mac' => $mac, 'label' => $i === count($macs) - 1 ? $label : ''];
            }
        }

        return ['devices' => array_values($devices), 'errors' => $errors];
    }
}

if (!function_exists('hubnet_device_rows_text')) {
    /**
     * Devices from the "Add device" rows (device_mac[] / device_label[]) as
     * "MAC label" lines for hubnet_parse_device_lines(). Falls back to a
     * plain text field ($fallbackKey) for older forms.
     */
    function hubnet_device_rows_text(array $post, string $fallbackKey = 'devices'): string
    {
        if (!isset($post['device_mac']) || !is_array($post['device_mac'])) {
            return (string)($post[$fallbackKey] ?? '');
        }

        $labels = is_array($post['device_label'] ?? null) ? $post['device_label'] : [];
        $lines = [];

        foreach (array_slice($post['device_mac'], 0, 200, true) as $i => $mac) {
            $mac = trim((string)$mac);
            $label = trim(preg_replace('/[\r\n]+/', ' ', (string)($labels[$i] ?? '')) ?? '');

            if ($mac === '' && $label === '') {
                continue; // empty row
            }

            // A label without a MAC is reported by the parser as an invalid line.
            $lines[] = ($mac !== '' ? preg_replace('/\s+/', '', $mac) : '') . ($label !== '' ? ' ' . $label : '');
        }

        return implode("\n", $lines);
    }
}

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

        // Staff and guests: online while Active and (guests) not past their expiry.
        if (!empty($device['person_id'])) {
            $until = (string)($device['person_valid_until'] ?? '');
            $current = $until === '' || strtotime($until) >= time();

            return ($device['person_status'] ?? '') === 'Active' && $current ? 'pass' : 'remove';
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
        // owner_kind: member (company or its team), staff or guest (hub_network_people).
        return "SELECT d.*, m.company_name, m.membership_number, m.membership_status, m.subscription_end_date,
                       COALESCE(t.full_name, hp.full_name, u.full_name) AS person_name,
                       t.status AS team_status, u.full_name AS primary_name,
                       COALESCE(hp.kind, 'member') AS owner_kind,
                       hp.status AS person_status, hp.valid_until AS person_valid_until,
                       hp.organisation AS person_organisation, hp.phone AS person_phone, hp.source AS person_source
                FROM hub_network_devices d
                LEFT JOIN members m ON m.member_id = d.member_id
                LEFT JOIN users u ON u.user_id = m.user_id
                LEFT JOIN hub_team_members t ON t.team_member_id = d.team_member_id
                LEFT JOIN hub_network_people hp ON hp.person_id = d.person_id
                WHERE ($where) AND (d.member_id IS NOT NULL AND m.member_id IS NOT NULL OR d.person_id IS NOT NULL AND hp.person_id IS NOT NULL)";
    }
}

if (!function_exists('hubnet_device_bw')) {
    /**
     * Speed limit sent to pfSense with a "pass" entry, in Kbit/s (pfSense's
     * MAC entry "Bandwidth up/down" fields). Empty = no limit.
     */
    function hubnet_device_bw(array $device, string $mode): array
    {
        if ($mode !== 'pass') {
            return [];
        }
        $down = (int)($device['bw_down_kbps'] ?? 0);
        $up = (int)($device['bw_up_kbps'] ?? 0);
        return array_filter(['bw_down' => $down, 'bw_up' => $up], fn($v) => $v > 0);
    }
}

if (!function_exists('hubnet_mbps_label')) {
    /** "10" / "0.5" Mbps from Kbit/s; '' for no limit. */
    function hubnet_mbps_label(?int $kbps): string
    {
        if (!$kbps) {
            return '';
        }
        return rtrim(rtrim(number_format($kbps / 1000, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('hubnet_parse_mbps')) {
    /** Mbps typed by a user ("10", "0.5", "") to Kbit/s; null = no limit, false = invalid. */
    function hubnet_parse_mbps(mixed $value): int|null|false
    {
        $value = trim(str_replace(',', '.', (string)$value));
        if ($value === '' || $value === '0') {
            return null;
        }
        if (!is_numeric($value) || (float)$value < 0.064 || (float)$value > 10000) {
            return false;
        }
        return (int)round((float)$value * 1000);
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
            // pull: pfSense polls the IMS (works from shared hosting); push: the IMS calls pfSense.
            'mode' => ($row['mode'] ?? 'pull') === 'push' ? 'push' : 'pull',
            'last_checkin_at' => $row['last_checkin_at'] ?? null,
            'last_checkin_info' => json_decode((string)($row['last_checkin_info'] ?? ''), true) ?: [],
            'portal_control' => in_array($row['portal_control'] ?? '', ['on', 'off'], true) ? (string)$row['portal_control'] : 'unmanaged',
            'hotspot_enabled' => !empty($row['hotspot_enabled']),
            'hotspot_password' => function_exists('ims_decrypt') ? ims_decrypt((string)($row['hotspot_password'] ?? '')) : (string)($row['hotspot_password'] ?? ''),
            'hotspot_hours' => max(1, (int)($row['hotspot_hours'] ?? 8)),
            'hotspot_require_phone' => !array_key_exists('hotspot_require_phone', $row) || !empty($row['hotspot_require_phone']),
            'hotspot_terms' => (string)($row['hotspot_terms'] ?? ''),
            'wifi_ssid' => (string)($row['wifi_ssid'] ?? ''),
            'wifi_key' => function_exists('ims_decrypt') ? ims_decrypt((string)($row['wifi_key'] ?? '')) : (string)($row['wifi_key'] ?? ''),
            'wifi_security' => in_array($row['wifi_security'] ?? '', ['WPA', 'WEP', 'nopass'], true) ? (string)$row['wifi_security'] : 'WPA',
            'hotspot_landing_url' => preg_match('#\Ahttps?://[^\s<>"\']+\z#i', (string)($row['hotspot_landing_url'] ?? '')) ? (string)$row['hotspot_landing_url'] : '',
            'hotspot_verify' => !array_key_exists('hotspot_verify', $row) || !empty($row['hotspot_verify']),
            'hotspot_help' => (string)($row['hotspot_help'] ?? ''),
            'hotspot_help_phone' => (string)($row['hotspot_help_phone'] ?? ''),
            'hotspot_help_email' => (string)($row['hotspot_help_email'] ?? ''),
            'hotspot_help_whatsapp' => (string)($row['hotspot_help_whatsapp'] ?? ''),
            'hotspot_bw_down_kbps' => (int)($row['hotspot_bw_down_kbps'] ?? 0),
            'hotspot_bw_up_kbps' => (int)($row['hotspot_bw_up_kbps'] ?? 0),
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
        if ($settings['agent_url'] === '') {
            return ['ok' => false, 'message' => 'Push mode is selected but no agent URL is saved. Enter the agent URL in Edit connection, or switch Connection to pull (pfSense checks in) if the IMS server cannot reach the firewall.', 'data' => []];
        }

        if ($settings['secret'] === '') {
            return ['ok' => false, 'message' => !empty($settings['has_secret'])
                ? 'A shared secret is saved but cannot be decrypted (DATA_ENCRYPTION_KEY / APP_KEY changed or missing in .env). Re-enter the shared secret in Edit connection.'
                : 'No shared secret is saved. Enter the shared secret in Edit connection.', 'data' => []];
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

        if ($settings['mode'] === 'pull') {
            // pfSense collects the desired state on its next check-in
            // (devices being deleted simply drop out of the feed).
            return [
                'ok' => true,
                'message' => 'Queued: pfSense applies it at its next check-in (within about a minute).',
                'applied' => 0,
                'failed' => 0,
                'queued' => count($devices),
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
                'descr' => hubnet_device_descr($device),
            ] + hubnet_device_bw($device, $mode);
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
        if (hubnet_settings($conn)['mode'] === 'pull') {
            return [
                'ok' => true,
                'message' => 'pfSense polls the IMS every minute and applies every device on its next check-in.',
                'applied' => 0,
                'failed' => 0,
            ];
        }

        $devices = hubnet_devices_where($conn, '1=1');

        if (!$force) {
            $devices = array_values(array_filter($devices, static function (array $device): bool {
                return ($device['pf_state'] ?? '') !== hubnet_state_for_mode(hubnet_desired_mode($device));
            }));
        }

        return hubnet_apply($conn, $devices, $userId, $force ? 'sync-all' : 'auto');
    }
}

/* ---------------------------------------------------------------------
| Pull mode: pfSense polls the IMS (legacy/hub-network-agent.php)
--------------------------------------------------------------------- */

const HUBNET_DESCR_PREFIX = 'IMS ';
const HUBNET_CHECKIN_STALE_SECONDS = 300;

if (!function_exists('hubnet_device_descr')) {
    /** Captive portal entry description; the agent treats the "IMS " prefix as IMS-managed. */
    function hubnet_device_descr(array $device): string
    {
        $owner = ($device['owner_kind'] ?? 'member') === 'member'
            ? (string)($device['membership_number'] ?? '')
            : strtoupper((string)$device['owner_kind']);
        $text = HUBNET_DESCR_PREFIX . $owner . ' ' . ($device['person_name'] ?? '')
            . (!empty($device['label']) ? ' (' . $device['label'] . ')' : '');

        return mb_substr((string)preg_replace('/[^\w .@()\-]/u', '', $text), 0, 120);
    }
}

if (!function_exists('hubnet_agent_verify')) {
    /**
     * Checks a request from the pfSense agent: HMAC-SHA256 of the raw body
     * with the shared secret, a timestamp within 5 minutes and an unused nonce.
     *
     * @return array{0:?array,1:string} [decoded request or null, error]
     */
    function hubnet_agent_verify(mysqli $conn, array $settings, string $raw, string $signature): array
    {
        if ($settings['secret'] === '') {
            return [null, 'not_configured'];
        }

        if ($raw === '' || !hash_equals(hash_hmac('sha256', $raw, $settings['secret']), trim($signature))) {
            return [null, 'bad_signature'];
        }

        $request = json_decode($raw, true);

        if (!is_array($request)) {
            return [null, 'bad_json'];
        }

        if (abs(time() - (int)($request['ts'] ?? 0)) > 300) {
            return [null, 'expired'];
        }

        $nonce = (string)($request['nonce'] ?? '');

        if (!preg_match('/^[a-f0-9]{24}$/', $nonce)) {
            return [null, 'bad_nonce'];
        }

        $conn->query('DELETE FROM hub_agent_nonces WHERE seen_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $stmt = $conn->prepare('INSERT IGNORE INTO hub_agent_nonces (nonce) VALUES (?)');

        if (!$stmt) {
            return [null, 'server_error'];
        }

        $stmt->bind_param('s', $nonce);
        $stmt->execute();
        $fresh = $stmt->affected_rows === 1;
        $stmt->close();

        return $fresh ? [$request, ''] : [null, 'replayed'];
    }
}

if (!function_exists('hubnet_feed')) {
    /**
     * Desired state of every registered device.
     *
     * @return list<array{mac:string,mode:string,descr:string}>
     */
    function hubnet_feed(mysqli $conn): array
    {
        $items = [];

        foreach (hubnet_devices_where($conn, '1=1') as $device) {
            $mac = hubnet_normalize_mac((string)$device['mac_address']);

            if ($mac !== '') {
                $mode = hubnet_desired_mode($device);
                $items[] = ['mac' => $mac, 'mode' => $mode, 'descr' => hubnet_device_descr($device)] + hubnet_device_bw($device, $mode);
            }
        }

        return $items;
    }
}

if (!function_exists('hubnet_record_report')) {
    /**
     * Stores what pfSense applied. Only changes are written and logged, so a
     * check-in every minute does not flood the log.
     *
     * @param array<string,array{ok?:bool,mode?:string,message?:string}> $results keyed by MAC
     */
    function hubnet_record_report(mysqli $conn, array $results): int
    {
        $changed = 0;
        $select = $conn->prepare('SELECT device_id, pf_state, pf_last_error FROM hub_network_devices WHERE mac_address = ? LIMIT 1');
        $update = $conn->prepare('UPDATE hub_network_devices SET pf_state = ?, pf_last_error = ?, pf_synced_at = NOW() WHERE device_id = ?');
        $touch = $conn->prepare('UPDATE hub_network_devices SET pf_synced_at = NOW() WHERE device_id = ?');

        if (!$select || !$update || !$touch) {
            return 0;
        }

        foreach (array_slice($results, 0, 5000, true) as $mac => $result) {
            $mac = hubnet_normalize_mac((string)$mac);

            if ($mac === '' || !is_array($result)) {
                continue;
            }

            $select->bind_param('s', $mac);
            $select->execute();
            $device = $select->get_result()->fetch_assoc();

            if (!$device) {
                continue; // deleted in the IMS; the agent has removed its entry
            }

            $ok = !empty($result['ok']);
            $mode = in_array($result['mode'] ?? '', ['pass', 'block', 'remove'], true) ? (string)$result['mode'] : 'remove';
            $state = $ok ? hubnet_state_for_mode($mode) : 'error';
            $error = $ok ? null : mb_substr((string)($result['message'] ?? 'pfSense could not apply this device.'), 0, 255);
            $deviceId = (int)$device['device_id'];

            if ($state === $device['pf_state'] && $error === $device['pf_last_error']) {
                $touch->bind_param('i', $deviceId);
                $touch->execute();
                continue;
            }

            $update->bind_param('ssi', $state, $error, $deviceId);
            $update->execute();
            hubnet_log($conn, $deviceId, $mac, 'pull:' . $mode, $ok, $ok ? $state : (string)$error, 0);
            $changed++;
        }

        $select->close();
        $update->close();
        $touch->close();

        return $changed;
    }
}

if (!function_exists('hubnet_record_checkin')) {
    function hubnet_record_checkin(mysqli $conn, array $info): void
    {
        $json = json_encode(array_intersect_key($info, array_flip([
            'version', 'zone', 'mac_filtering', 'sessions', 'mac_entries', 'ip', 'error',
            'portal_enabled', 'clients', 'portal_change',
        ])), JSON_UNESCAPED_SLASHES);

        $stmt = $conn->prepare('UPDATE hub_network_settings SET last_checkin_at = NOW(), last_checkin_info = ? WHERE id = 1');

        if ($stmt) {
            $stmt->bind_param('s', $json);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('hubnet_agent_online')) {
    function hubnet_agent_online(array $settings): bool
    {
        return !empty($settings['last_checkin_at'])
            && (time() - (int)strtotime((string)$settings['last_checkin_at'])) <= HUBNET_CHECKIN_STALE_SECONDS;
    }
}
