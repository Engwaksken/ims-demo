<?php
declare(strict_types=1);


const WP_MILESTONE_TYPES        = ['Planning', 'Implementation', 'Monitoring', 'Evaluation', 'Reporting', 'Other'];
const WP_DELIVERABLE_STATUSES   = ['Pending', 'In Progress', 'Completed', 'Delayed', 'Cancelled'];
const WP_MONTHLY_STATUSES       = ['Planned', 'Started', 'Ongoing', 'Modified', 'Overdue', 'Completed'];

const WP_MONTHS = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
    7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
];


const WP_STATUS_COLORS = [
    'Planned'   => '#94a3b8', // slate
    'Started'   => '#3b82f6', // blue
    'Ongoing'   => '#f59e0b', // amber
    'Modified'  => '#8b5cf6', // violet
    'Overdue'   => '#ef4444', // red
    'Completed' => '#22c55e', // green
];


if (!function_exists('wp_column_exists')) {
    /** Column check used by actions/workplan-actions.php (workplan.php has its own copy). */
    function wp_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        if (!$stmt) {
            return $cache[$key] = false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $cache[$key] = $exists;
    }
}


function wp_list_workplans(mysqli $conn): array
{
    $out = [];

    $sql = "SELECT w.id, w.entity_type, w.entity_id, w.workplan_year,
                   p.project_code, p.project_name
            FROM workplans w
            LEFT JOIN projects p ON w.entity_type = 'Project' AND p.project_id = w.entity_id
            ORDER BY w.workplan_year DESC, w.id DESC";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $label = $row['entity_type'] === 'Project'
                ? trim(($row['project_code'] ?? '') . ' - ' . ($row['project_name'] ?? ''))
                : ($row['entity_type'] . ' #' . $row['entity_id']);
            $row['label'] = $label !== '' ? $label : ($row['entity_type'] . ' #' . $row['entity_id']);
            $out[] = $row;
        }
        $res->close();
    }

    // Fill in program labels separately if a programs table exists.
    $progRes = $conn->query("SHOW TABLES LIKE 'programs'");
    $hasPrograms = $progRes && $progRes->num_rows > 0;
    if ($progRes) {
        $progRes->close();
    }

    if ($hasPrograms) {
        foreach ($out as &$row) {
            if ($row['entity_type'] === 'Program') {
                $stmt = $conn->prepare("SELECT program_code, program_name FROM programs WHERE id = ?");
                $stmt->bind_param('i', $row['entity_id']);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    $label = trim(($r['program_code'] ?? '') . ' - ' . ($r['program_name'] ?? ''));
                    $row['label'] = $label !== '' ? $label : $row['label'];
                }
            }
        }
        unset($row);
    }

    return $out;
}


function wp_get_full_workplan(mysqli $conn, int $workplanId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM workplans WHERE id = ?");
    $stmt->bind_param('i', $workplanId);
    $stmt->execute();
    $workplan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$workplan) {
        return null;
    }

    $milestones = [];
    $stmt = $conn->prepare("SELECT * FROM workplan_milestones WHERE workplan_id = ? ORDER BY sort_order, id");
    $stmt->bind_param('i', $workplanId);
    $stmt->execute();
    $mRes = $stmt->get_result();
    while ($m = $mRes->fetch_assoc()) {
        $m['deliverables'] = [];
        $milestones[(int)$m['id']] = $m;
    }
    $stmt->close();

    // Previously one query per milestone + one per deliverable (N+1).
    if ($milestones) {
        $dStmt = $conn->prepare(
            "SELECT wd.* FROM workplan_deliverables wd
             INNER JOIN workplan_milestones wm ON wm.id = wd.milestone_id
             WHERE wm.workplan_id = ?
             ORDER BY wd.milestone_id, wd.sort_order, wd.id"
        );
        $dStmt->bind_param('i', $workplanId);
        $dStmt->execute();
        $dRes = $dStmt->get_result();

        $deliverables = [];
        while ($d = $dRes->fetch_assoc()) {
            $monthly = [];
            for ($mo = 1; $mo <= 12; $mo++) {
                $monthly[$mo] = ['status' => 'Planned', 'comment' => ''];
            }
            $d['monthly_status'] = $monthly;
            $deliverables[(int)$d['id']] = $d;
        }
        $dStmt->close();

        if ($deliverables) {
            $sStmt = $conn->prepare(
                "SELECT ms.deliverable_id, ms.month_number, ms.status, ms.comment
                 FROM workplan_monthly_status ms
                 INNER JOIN workplan_deliverables wd ON wd.id = ms.deliverable_id
                 INNER JOIN workplan_milestones wm ON wm.id = wd.milestone_id
                 WHERE wm.workplan_id = ?"
            );
            $sStmt->bind_param('i', $workplanId);
            $sStmt->execute();
            $sRes = $sStmt->get_result();
            while ($row = $sRes->fetch_assoc()) {
                $did = (int)$row['deliverable_id'];
                $month = (int)$row['month_number'];
                if (isset($deliverables[$did]) && $month >= 1 && $month <= 12) {
                    $deliverables[$did]['monthly_status'][$month] = [
                        'status'  => $row['status'],
                        'comment' => $row['comment'] ?? '',
                    ];
                }
            }
            $sStmt->close();
        }

        foreach ($deliverables as $d) {
            $mid = (int)$d['milestone_id'];
            if (isset($milestones[$mid])) {
                $milestones[$mid]['deliverables'][] = $d;
            }
        }
    }

    $milestones = array_values($milestones);

    $workplan['milestones'] = $milestones;

    return $workplan;
}


function wp_compute_stats(array $workplan): array
{
    $milestoneCount   = count($workplan['milestones']);
    $deliverableCount = 0;

    $statusCounts = array_fill_keys(WP_DELIVERABLE_STATUSES, 0);
    $monthlyStatusCounts = array_fill_keys(WP_MONTHLY_STATUSES, 0);
    $perMonth = [];
    for ($mo = 1; $mo <= 12; $mo++) {
        $perMonth[$mo] = array_fill_keys(WP_MONTHLY_STATUSES, 0);
    }

    $progressSum = 0.0;
    $overdueCount = 0;

    foreach ($workplan['milestones'] as $m) {
        foreach ($m['deliverables'] as $d) {
            $deliverableCount++;
            $status = $d['status'] ?? 'Pending';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
            $progressSum += (float)($d['progress_percentage'] ?? 0);
            // A deliverable counts as overdue once, however many months are
            // marked Overdue, so overdue + on-track always equals the total.
            $isOverdue = ($status === 'Delayed');

            foreach (($d['monthly_status'] ?? []) as $mo => $entry) {
                $st = $entry['status'] ?? 'Planned';
                if (isset($monthlyStatusCounts[$st])) {
                    $monthlyStatusCounts[$st]++;
                }
                if (isset($perMonth[$mo][$st])) {
                    $perMonth[$mo][$st]++;
                }
                if ($st === 'Overdue') {
                    $isOverdue = true;
                }
            }
            if ($isOverdue) {
                $overdueCount++;
            }
        }
    }

    $completionPct = $deliverableCount > 0 ? round($progressSum / $deliverableCount, 1) : 0.0;
    $completedDeliverables = $statusCounts['Completed'] ?? 0;
    $onTrackDeliverables = $deliverableCount - $overdueCount;

    return [
        'milestone_count'        => $milestoneCount,
        'deliverable_count'      => $deliverableCount,
        'status_counts'          => $statusCounts,
        'monthly_status_counts'  => $monthlyStatusCounts,
        'per_month'              => $perMonth,
        'completion_pct'         => $completionPct,
        'completed_deliverables' => $completedDeliverables,
        'overdue_count'          => $overdueCount,
        'on_track_count'         => max(0, $onTrackDeliverables),
    ];
}


function wp_deliverable_on_track(array $deliverable, int $asOfMonth): bool
{
    for ($mo = $asOfMonth; $mo >= 1; $mo--) {
        $status = $deliverable['monthly_status'][$mo]['status'] ?? null;
        if ($status !== null && $status !== 'Planned') {
            return $status !== 'Overdue';
        }
    }
    return true;
}