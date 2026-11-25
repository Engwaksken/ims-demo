<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application statuses
|--------------------------------------------------------------------------
| One place for which statuses exist and which quick actions each allows.
|
|   Draft -> Submitted -> Under Review -> Shortlisted -> Accepted
|                                     \-> Waitlisted  -> Accepted / Rejected
|   Any undecided status -> Rejected / Withdrawn
|
| Waitlisted = a good application kept in reserve: not accepted now, but
| offered a place if one opens up (for example an accepted startup drops out).
*/

/** Every status staff can set from Manage / View Application. */
const APP_STATUSES_SETTABLE = ['Submitted', 'Under Review', 'Shortlisted', 'Waitlisted', 'Accepted', 'Rejected', 'Withdrawn'];

/** No decision yet: these can still be shortlisted, waitlisted, accepted or rejected. */
const APP_STATUSES_UNDECIDED = ['Submitted', 'Under Review', 'Shortlisted', 'Waitlisted'];

if (!function_exists('app_status_can')) {
    /** Which quick action applies to an application in $status. */
    function app_status_can(string $action, string $status): bool
    {
        return match ($action) {
            'shortlist' => in_array($status, ['Submitted', 'Under Review', 'Waitlisted'], true),
            'waitlist'  => in_array($status, ['Submitted', 'Under Review', 'Shortlisted'], true),
            'accept', 'reject' => in_array($status, APP_STATUSES_UNDECIDED, true),
            default => false,
        };
    }
}

if (!function_exists('app_enum_allow')) {
    /**
     * If $table.$column is an ENUM without $value, add it. A VARCHAR column
     * needs nothing. Returns false only when the value is missing and could
     * not be added (or the column does not exist).
     */
    function app_enum_allow(mysqli $conn, string $table, string $column, string $value): bool
    {
        try {
            $t = $conn->real_escape_string($table);
            $c = $conn->real_escape_string($column);
            $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
            $col = $res ? $res->fetch_assoc() : null;
            if (!$col) {
                return false;
            }

            $type = (string)$col['Type'];
            if (stripos($type, 'enum(') !== 0) {
                return true;
            }

            // enum('a','b','O''Brien') -> ['a', 'b', "O'Brien"]
            $values = str_getcsv(substr($type, 5, -1), ',', "'", '');
            if (in_array($value, $values, true)) {
                return true;
            }

            $values[] = $value;
            $list = implode(',', array_map(fn($v) => "'" . $conn->real_escape_string($v) . "'", $values));
            $null = strtoupper((string)$col['Null']) === 'YES' ? 'NULL' : 'NOT NULL';
            $default = $col['Default'] !== null ? " DEFAULT '" . $conn->real_escape_string((string)$col['Default']) . "'" : '';

            $ok = (bool)$conn->query("ALTER TABLE `{$t}` MODIFY `{$c}` ENUM($list) $null$default");
            if (!$ok) {
                error_log("[application-statuses] could not add '{$value}' to {$table}.{$column}: " . $conn->error);
            }
            return $ok;
        } catch (Throwable $e) {
            error_log('[application-statuses] enum check failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('app_status_ensure')) {
    /** Make sure $status can be stored on applications and in the status history. */
    function app_status_ensure(mysqli $conn, string $status): bool
    {
        static $checked = [];
        if (!isset($checked[$status])) {
            $ok = app_enum_allow($conn, 'applications', 'status', $status);
            // History columns are best-effort; a missing table must not block the change.
            app_enum_allow($conn, 'application_status_history', 'old_status', $status);
            app_enum_allow($conn, 'application_status_history', 'new_status', $status);
            $checked[$status] = $ok;
        }
        return $checked[$status];
    }
}
