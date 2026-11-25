<?php
declare(strict_types=1);

/**
 * Reviewer pool: the users picked on /manage-reviewers to act as application
 * reviewers, whatever their role. Users whose role is "Reviewer" are always in
 * the list; everyone else is in it only when an Administrator or Program
 * Director adds them.
 *
 * The first time the table is created it is filled with the users the page
 * used to list by role (MEAL Lead, Administrator, Programs Lead), so nobody
 * drops off the list when this ships.
 */

if (!function_exists('ims_reviewer_pool_ensure')) {
    function ims_reviewer_pool_ensure(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $exists = $conn->query("SHOW TABLES LIKE 'reviewer_pool'");
            $isNew  = !$exists || $exists->num_rows === 0;

            $ok = $conn->query("
                CREATE TABLE IF NOT EXISTS reviewer_pool (
                    user_id INT NOT NULL,
                    added_by INT NULL,
                    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            if (!$ok) {
                return $ready = false;
            }

            if ($isNew) {
                $conn->query("
                    INSERT IGNORE INTO reviewer_pool (user_id, added_by, added_at)
                    SELECT user_id, NULL, NOW()
                    FROM users
                    WHERE role IN ('MEAL Lead','Administrator','Programs Lead')
                      AND is_active = 1
                ");
            }

            return $ready = true;
        } catch (Throwable $e) {
            error_log('ims_reviewer_pool_ensure failed: ' . $e->getMessage());
            return $ready = false;
        }
    }
}

/** Can the current user add or remove people from the reviewer list? */
if (!function_exists('ims_reviewer_pool_can_manage')) {
    function ims_reviewer_pool_can_manage(): bool
    {
        return ims_allowed('reviewers.pool', ['Administrator', 'Program Director'], 'Choose which users are reviewers');
    }
}

/**
 * Users on the reviewer list: role "Reviewer" plus everyone in the pool.
 * Each row has user_id, full_name, email, role, in_pool (1/0), added_at,
 * added_by_name.
 */
if (!function_exists('ims_reviewer_pool_list')) {
    function ims_reviewer_pool_list(mysqli $conn): array
    {
        $poolReady = ims_reviewer_pool_ensure($conn);

        if ($poolReady) {
            $sql = "
                SELECT u.user_id, u.full_name, u.email, u.role,
                       (rp.user_id IS NOT NULL) AS in_pool,
                       rp.added_at,
                       ab.full_name AS added_by_name
                FROM users u
                LEFT JOIN reviewer_pool rp ON rp.user_id = u.user_id
                LEFT JOIN users ab ON ab.user_id = rp.added_by
                WHERE u.is_active = 1
                  AND (u.role = 'Reviewer' OR rp.user_id IS NOT NULL)
                ORDER BY u.full_name
            ";
        } else {
            // Pool table unavailable: the original role-based list.
            $sql = "
                SELECT user_id, full_name, email, role,
                       0 AS in_pool, NULL AS added_at, NULL AS added_by_name
                FROM users
                WHERE role IN ('Reviewer','MEAL Lead','Administrator','Programs Lead')
                  AND is_active = 1
                ORDER BY full_name
            ";
        }

        $rows = [];
        $res  = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['in_pool'] = (int)$row['in_pool'];
                $rows[] = $row;
            }
            $res->free();
        }

        return $rows;
    }
}

/** Is this user on the reviewer list (role Reviewer or picked into the pool)? */
if (!function_exists('ims_is_designated_reviewer')) {
    function ims_is_designated_reviewer(mysqli $conn, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (!ims_reviewer_pool_ensure($conn)) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT 1
            FROM users u
            LEFT JOIN reviewer_pool rp ON rp.user_id = u.user_id
            WHERE u.user_id = ?
              AND u.is_active = 1
              AND (u.role = 'Reviewer' OR rp.user_id IS NOT NULL)
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return $found;
    }
}
