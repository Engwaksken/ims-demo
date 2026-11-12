<?php
declare(strict_types=1);

/**
 * Event links: meeting links for meeting-type events, public registration
 * links (shared as a URL and QR code) for every other event type, and the
 * PWD / photo-consent fields captured when participants register.
 */

if (!function_exists('ev_is_meeting_type')) {
    /** Any event type containing the word "meeting" (Meeting, Board Meeting, ...). */
    function ev_is_meeting_type(?string $type): bool
    {
        return preg_match('/\bmeetings?\b/i', (string)$type) === 1;
    }

    /** Disability categories offered on the registration form. */
    function ev_pwd_types(): array
    {
        return [
            'Physical',
            'Visual',
            'Hearing',
            'Speech',
            'Intellectual',
            'Psychosocial',
            'Albinism',
            'Multiple disabilities',
            'Other',
        ];
    }

    function ev_has_column(mysqli $conn, string $table, string $column, bool $refresh = false): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!$refresh && array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return $cache[$key] = false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return $cache[$key] = $exists;
    }

    /**
     * Add the columns this feature needs if the migration has not been run
     * (database/migrations/2026_11_12_event_links_and_pwd.sql). Returns false
     * when a column is still missing, e.g. the DB user cannot ALTER.
     */
    function ev_links_ensure_schema(mysqli $conn): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }

        $columns = [
            ['hub_events', 'meeting_link', 'VARCHAR(500) NULL'],
            ['event_registrations', 'is_pwd', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['event_registrations', 'pwd_type', 'VARCHAR(100) NULL'],
            ['event_registrations', 'photo_consent', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ];

        $done = true;
        foreach ($columns as [$table, $column, $definition]) {
            if (ev_has_column($conn, $table, $column)) {
                continue;
            }
            try {
                $ok = $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            } catch (Throwable $e) {
                $ok = false;
                error_log("event-links: could not add {$table}.{$column}: " . $e->getMessage());
            }
            if (!$ok || !ev_has_column($conn, $table, $column, true)) {
                $done = false;
            }
        }

        return $done;
    }

    /** Public, shareable registration URL (also encoded in the QR code). */
    function ev_registration_url(int $eventId): string
    {
        $token = base64_encode('eid=' . $eventId);
        return rtrim((string)APP_URL, '/') . '/event-register?token=' . rawurlencode($token);
    }

    /** A ready-to-use video meeting room that needs no account or API. */
    function ev_generate_meeting_link(string $title = ''): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
        $slug = substr($slug !== '' ? $slug : 'meeting', 0, 40);
        return 'https://meet.jit.si/' . rtrim($slug, '-') . '-' . bin2hex(random_bytes(5));
    }

    /** Accept only http(s) links for a manually entered meeting link. */
    function ev_clean_meeting_link(string $link): string
    {
        $link = trim($link);
        if ($link === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $link)) {
            $link = 'https://' . $link;
        }
        return filter_var($link, FILTER_VALIDATE_URL) ? substr($link, 0, 500) : '';
    }

    function ev_set_meeting_link(mysqli $conn, int $eventId, ?string $link): void
    {
        if (!ev_has_column($conn, 'hub_events', 'meeting_link')) {
            return;
        }
        $link = ($link === null || $link === '') ? null : $link;
        $stmt = $conn->prepare("UPDATE hub_events SET meeting_link = ? WHERE event_id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $link, $eventId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
