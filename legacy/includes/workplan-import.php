<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| WORKPLAN CSV IMPORT
|--------------------------------------------------------------------------
| Loaded by actions/workplan-actions.php (which defines wp_persist_milestones).
|   GET  ?action=template  -> CSV template
|   POST action=import_csv (multipart: entity_type, entity_id, workplan_year, workplan_file)
|
| One CSV row per activity (deliverable). Rows sharing a milestone name are
| grouped under that milestone; the milestone columns only need filling on the
| first row of each milestone. A row with a milestone but no activity creates
| an empty milestone.
|
| The workplan for the chosen project/program and year is created if it does
| not exist. If it exists, imported milestones are added to it: activities
| under a milestone with the same name are appended there, and activities
| that already exist (same milestone + title) are skipped. Nothing is deleted.
| All rows are validated first; nothing is saved if any row is invalid.
*/

require_once __DIR__ . '/csv-import.php';

const WP_IMPORT_COLUMNS = [
    'milestone', 'milestone_type', 'milestone_description', 'milestone_start_date', 'milestone_due_date',
    'milestone_responsible', 'milestone_frequency',
    'activity', 'activity_responsible', 'start_date', 'end_date', 'status', 'progress', 'frequency', 'notes',
];

const WP_IMPORT_ALIASES = [
    'milestone_name' => 'milestone', 'deliverable' => 'activity', 'task' => 'activity', 'title' => 'activity',
    'activity_title' => 'activity', 'responsible' => 'activity_responsible', 'responsible_person' => 'activity_responsible',
    'actor' => 'activity_responsible', 'progress_percentage' => 'progress', 'calendar_frequency' => 'frequency',
    'milestone_start' => 'milestone_start_date', 'milestone_due' => 'milestone_due_date', 'milestone_end_date' => 'milestone_due_date',
];

const WP_IMPORT_MAX_ROWS = 1000;

function wp_import_template(): never
{
    $year = (int)date('Y');
    ims_csv_send_template('workplan_import_template.csv', WP_IMPORT_COLUMNS, [
        ['Inception', 'Planning', 'Set up the project', "$year-01-01", "$year-02-28", 'Project Lead', 'Monthly',
         'Recruit project team', 'HR Officer', "$year-01-05", "$year-01-31", 'Pending', '0', 'Weekly', 'Example row'],
        ['Inception', '', '', '', '', '', '',
         'Baseline survey', 'MEAL Officer', "$year-02-01", "$year-02-28", 'Pending', '0', 'Monthly', 'Same milestone: leave milestone columns blank'],
    ], [
        'Rows starting with # are ignored. One row per activity; repeat the milestone name for each of its activities.',
        'Required: milestone. Milestone details (type, dates, responsible, frequency) only need filling on its first row.',
        'milestone_type: ' . implode(', ', WP_MILESTONE_TYPES) . ' (blank = Implementation).',
        'status: ' . implode(', ', WP_DELIVERABLE_STATUSES) . ' (blank = Pending). progress: 0-100.',
        'frequency / milestone_frequency: ' . implode(', ', WP_CALENDAR_FREQUENCIES) . ' (blank = Monthly for activities, Annual for milestones).',
        'Dates: YYYY-MM-DD (also accepts DD/MM/YYYY). The project/program and year are chosen when you upload.',
    ]);
}

/**
 * Validate rows and group them by milestone. Returns
 * ['milestones' => [lower name => milestone array with 'deliverables'], 'errors' => [...]].
 */
function wp_import_parse(array $rows): array
{
    $milestones = [];
    $errors = [];
    $seenActivities = [];

    foreach ($rows as $row) {
        $line = (int)$row['line'];
        $d = $row['data'];
        $rowErrors = [];

        $name = $d['milestone'] ?? '';
        if ($name === '') {
            $errors[] = "Line {$line}: milestone is empty.";
            continue;
        }
        $mKey = mb_strtolower($name, 'UTF-8');

        // Milestone details: take them from the first row that has them.
        if (!isset($milestones[$mKey])) {
            $milestones[$mKey] = [
                'milestone_name' => $name, 'milestone_type' => 'Implementation', 'milestone_description' => '',
                'start_date' => null, 'due_date' => null, 'responsible_person' => '', 'calendar_frequency' => 'Annual',
                'deliverables' => [], '_set' => [],
            ];
        }
        $m = &$milestones[$mKey];

        $fields = [
            'milestone_type' => static fn($v) => ims_csv_choice($v, WP_MILESTONE_TYPES)
                ?? throw new InvalidArgumentException("milestone_type \"{$v}\" must be one of " . implode(', ', WP_MILESTONE_TYPES)),
            'milestone_description' => static fn($v) => $v,
            'milestone_start_date' => static fn($v) => ims_csv_date($v)
                ?? throw new InvalidArgumentException("milestone_start_date \"{$v}\" is not a valid date"),
            'milestone_due_date' => static fn($v) => ims_csv_date($v)
                ?? throw new InvalidArgumentException("milestone_due_date \"{$v}\" is not a valid date"),
            'milestone_responsible' => static fn($v) => $v,
            'milestone_frequency' => static fn($v) => ims_csv_choice($v, WP_CALENDAR_FREQUENCIES)
                ?? throw new InvalidArgumentException("milestone_frequency \"{$v}\" must be one of " . implode(', ', WP_CALENDAR_FREQUENCIES)),
        ];
        $target = [
            'milestone_type' => 'milestone_type', 'milestone_description' => 'milestone_description',
            'milestone_start_date' => 'start_date', 'milestone_due_date' => 'due_date',
            'milestone_responsible' => 'responsible_person', 'milestone_frequency' => 'calendar_frequency',
        ];
        foreach ($fields as $column => $parse) {
            $value = $d[$column] ?? '';
            if ($value === '' || isset($m['_set'][$column])) {
                continue;
            }
            try {
                $m[$target[$column]] = $parse($value);
                $m['_set'][$column] = true;
            } catch (InvalidArgumentException $e) {
                $rowErrors[] = $e->getMessage();
            }
        }
        if ($m['start_date'] && $m['due_date'] && $m['due_date'] < $m['start_date']) {
            $rowErrors[] = 'milestone_due_date is before milestone_start_date';
        }

        $title = $d['activity'] ?? '';
        if ($title !== '') {
            $aKey = $mKey . '|' . mb_strtolower($title, 'UTF-8');
            if (isset($seenActivities[$aKey])) {
                $rowErrors[] = "activity \"{$title}\" is repeated under milestone \"{$name}\" (also on line {$seenActivities[$aKey]})";
            }
            $seenActivities[$aKey] = $line;

            $start = null;
            $end = null;
            if (($d['start_date'] ?? '') !== '') {
                $start = ims_csv_date($d['start_date']);
                if (!$start) {
                    $rowErrors[] = "start_date \"{$d['start_date']}\" is not a valid date";
                }
            }
            if (($d['end_date'] ?? '') !== '') {
                $end = ims_csv_date($d['end_date']);
                if (!$end) {
                    $rowErrors[] = "end_date \"{$d['end_date']}\" is not a valid date";
                }
            }
            if ($start && $end && $end < $start) {
                $rowErrors[] = 'end_date is before start_date';
            }

            $status = 'Pending';
            if (($d['status'] ?? '') !== '') {
                $status = ims_csv_choice($d['status'], WP_DELIVERABLE_STATUSES) ?? '';
                if ($status === '') {
                    $rowErrors[] = "status \"{$d['status']}\" must be one of " . implode(', ', WP_DELIVERABLE_STATUSES);
                }
            }

            $progress = 0.0;
            if (($d['progress'] ?? '') !== '') {
                $p = ims_csv_number($d['progress']);
                if ($p === null || $p < 0 || $p > 100) {
                    $rowErrors[] = "progress \"{$d['progress']}\" must be a number from 0 to 100";
                } else {
                    $progress = $p;
                }
            }

            $frequency = 'Monthly';
            if (($d['frequency'] ?? '') !== '') {
                $frequency = ims_csv_choice($d['frequency'], WP_CALENDAR_FREQUENCIES) ?? '';
                if ($frequency === '') {
                    $rowErrors[] = "frequency \"{$d['frequency']}\" must be one of " . implode(', ', WP_CALENDAR_FREQUENCIES);
                }
            }

            if (!$rowErrors) {
                $m['deliverables'][] = [
                    'title' => $title,
                    'responsible_person' => $d['activity_responsible'] ?? '',
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => $status,
                    'progress_percentage' => $progress,
                    'notes' => mb_substr($d['notes'] ?? '', 0, 2000),
                    'calendar_frequency' => $frequency,
                    'monthly_status' => [],
                ];
            }
        } else {
            foreach (['activity_responsible', 'start_date', 'end_date', 'status', 'progress', 'notes'] as $col) {
                if (($d[$col] ?? '') !== '') {
                    $rowErrors[] = "{$col} is filled in but activity is empty";
                    break;
                }
            }
        }
        unset($m);

        if ($rowErrors) {
            $errors[] = "Line {$line} ({$name}" . ($title !== '' ? " / {$title}" : '') . '): ' . implode('; ', $rowErrors) . '.';
        }
    }

    foreach ($milestones as &$m) {
        unset($m['_set']);
    }
    unset($m);

    return ['milestones' => $milestones, 'errors' => $errors];
}

/** Does the project/program exist? */
function wp_import_entity_exists(mysqli $conn, string $type, int $id): bool
{
    $sql = $type === 'Project'
        ? 'SELECT 1 FROM projects WHERE project_id = ? LIMIT 1'
        : 'SELECT 1 FROM programs WHERE id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

/** Insert activities under an existing milestone, starting at $sort. Returns how many were inserted. */
function wp_import_add_deliverables(mysqli $conn, int $milestoneId, array $deliverables, int $sort): int
{
    $hasFrequency = wp_column_exists($conn, 'workplan_deliverables', 'calendar_frequency');
    $stmt = $conn->prepare(
        "INSERT INTO workplan_deliverables
            (milestone_id, title, responsible_person, start_date, end_date, status, progress_percentage, notes, sort_order"
            . ($hasFrequency ? ", calendar_frequency" : "") . ")
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?" . ($hasFrequency ? ", ?" : "") . ")"
    );
    if (!$stmt) {
        throw new RuntimeException('Workplan activity insert prepare failed: ' . $conn->error);
    }

    $count = 0;
    foreach ($deliverables as $d) {
        $order = $sort + $count;
        if ($hasFrequency) {
            $stmt->bind_param('isssssdsis', $milestoneId, $d['title'], $d['responsible_person'], $d['start_date'], $d['end_date'],
                $d['status'], $d['progress_percentage'], $d['notes'], $order, $d['calendar_frequency']);
        } else {
            $stmt->bind_param('isssssdsi', $milestoneId, $d['title'], $d['responsible_person'], $d['start_date'], $d['end_date'],
                $d['status'], $d['progress_percentage'], $d['notes'], $order);
        }
        $stmt->execute();
        $count++;
    }
    $stmt->close();

    return $count;
}

/**
 * Save parsed milestones into the workplan for entity + year (created if missing).
 * Runs in one transaction. Returns a summary array.
 */
function wp_import_save(mysqli $conn, string $entityType, int $entityId, int $year, array $milestones, ?int $userId): array
{
    $summary = ['workplan_id' => 0, 'created' => false, 'milestones_added' => 0, 'activities_added' => 0, 'activities_skipped' => 0];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id FROM workplans WHERE entity_type = ? AND entity_id = ? AND workplan_year = ? ORDER BY id LIMIT 1');
        $stmt->bind_param('sii', $entityType, $entityId, $year);
        $stmt->execute();
        $workplanId = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($workplanId === 0) {
            $stmt = $conn->prepare('INSERT INTO workplans (entity_type, entity_id, workplan_year, created_by) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('siii', $entityType, $entityId, $year, $userId);
            $stmt->execute();
            $workplanId = (int)$conn->insert_id;
            $stmt->close();
            $summary['created'] = true;
        }
        $summary['workplan_id'] = $workplanId;

        // Existing milestones (by lower-case name) and their activities.
        $existing = [];
        $maxSort = -1;
        $stmt = $conn->prepare('SELECT id, milestone_name, sort_order FROM workplan_milestones WHERE workplan_id = ?');
        $stmt->bind_param('i', $workplanId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $existing[mb_strtolower(trim((string)$r['milestone_name']), 'UTF-8')] = ['id' => (int)$r['id'], 'titles' => [], 'max_sort' => -1];
            $maxSort = max($maxSort, (int)$r['sort_order']);
        }
        $stmt->close();

        if ($existing) {
            $ids = implode(',', array_map(static fn($m) => $m['id'], $existing));
            $byId = [];
            foreach ($existing as $key => $m) {
                $byId[$m['id']] = $key;
            }
            $res = $conn->query("SELECT milestone_id, title, sort_order FROM workplan_deliverables WHERE milestone_id IN ({$ids})");
            while ($res && ($r = $res->fetch_assoc())) {
                $key = $byId[(int)$r['milestone_id']];
                $existing[$key]['titles'][mb_strtolower(trim((string)$r['title']), 'UTF-8')] = true;
                $existing[$key]['max_sort'] = max($existing[$key]['max_sort'], (int)$r['sort_order']);
            }
        }

        $newMilestones = [];
        foreach ($milestones as $key => $m) {
            if (isset($existing[$key])) {
                $fresh = array_values(array_filter(
                    $m['deliverables'],
                    static fn($d) => !isset($existing[$key]['titles'][mb_strtolower($d['title'], 'UTF-8')])
                ));
                $summary['activities_skipped'] += count($m['deliverables']) - count($fresh);
                $summary['activities_added'] += wp_import_add_deliverables($conn, $existing[$key]['id'], $fresh, $existing[$key]['max_sort'] + 1);
            } else {
                $newMilestones[++$maxSort] = $m; // key = sort_order, used by wp_persist_milestones
                $summary['milestones_added']++;
                $summary['activities_added'] += count($m['deliverables']);
            }
        }

        if ($newMilestones) {
            wp_persist_milestones($conn, $workplanId, $newMilestones);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return $summary;
}

/** POST action=import_csv handler: echoes JSON. */
function wp_import_handle(mysqli $conn): void
{
    $file = $_FILES['workplan_file'] ?? null;
    $uploadError = ims_csv_upload_error($file);
    if ($uploadError) {
        echo json_encode(['success' => false, 'message' => $uploadError]);
        return;
    }

    $entityType = (string)($_POST['entity_type'] ?? '');
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $year = (int)($_POST['workplan_year'] ?? 0);

    if (!in_array($entityType, ['Project', 'Program'], true) || $entityId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Choose the project or program for this workplan.']);
        return;
    }
    if ($year < 2000 || $year > (int)date('Y') + 10) {
        echo json_encode(['success' => false, 'message' => 'Choose a valid workplan year.']);
        return;
    }
    if (!wp_import_entity_exists($conn, $entityType, $entityId)) {
        echo json_encode(['success' => false, 'message' => "That {$entityType} could not be found."]);
        return;
    }

    $read = ims_csv_read((string)$file['tmp_name'], ['milestone'], WP_IMPORT_ALIASES, WP_IMPORT_MAX_ROWS);
    if ($read['fatal'] !== null) {
        echo json_encode(['success' => false, 'message' => $read['fatal']]);
        return;
    }

    $parsed = wp_import_parse($read['rows']);
    if ($parsed['errors']) {
        echo json_encode([
            'success' => false,
            'message' => 'Nothing was imported: ' . count($parsed['errors']) . ' row(s) need fixing.',
            'errors'  => array_slice($parsed['errors'], 0, 50),
            'more'    => max(0, count($parsed['errors']) - 50),
        ]);
        return;
    }

    try {
        $summary = wp_import_save($conn, $entityType, $entityId, $year, $parsed['milestones'], (int)($_SESSION['user_id'] ?? 0) ?: null);
    } catch (Throwable $e) {
        error_log('Workplan import failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Import cancelled, nothing was saved. Please try again.']);
        return;
    }

    if (function_exists('log_action')) {
        log_action((int)($_SESSION['user_id'] ?? 0), 'Import Workplan', 'workplans', $summary['workplan_id'],
            "Imported {$summary['milestones_added']} milestones and {$summary['activities_added']} activities from " . basename((string)$file['name']));
    }

    $message = ($summary['created'] ? 'Workplan created' : 'Workplan updated') . ": {$summary['milestones_added']} milestone(s) and {$summary['activities_added']} activit"
        . ($summary['activities_added'] === 1 ? 'y' : 'ies') . ' added'
        . ($summary['activities_skipped'] ? ", {$summary['activities_skipped']} already existed and were skipped" : '') . '.';

    if (function_exists('send_notification')) {
        send_notification((int)($_SESSION['user_id'] ?? 0), $message, 'success');
    }
    echo json_encode(['success' => true, 'message' => $message, 'workplan_id' => $summary['workplan_id']] + $summary);
}
