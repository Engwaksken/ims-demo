<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../includes/header.php';
ob_end_clean();

require_once __DIR__ . '/../includes/workplan-stats.php';
require_once __DIR__ . '/../includes/workplan-import.php';

header('Content-Type: application/json');

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer',
    'consultant'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found.']);
    exit;
}

$conn->set_charset('utf8mb4');

function wp_json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function wp_validate_payload(array $payload, array &$errors): bool
{
    $errors = [];

    if (empty($payload['entity_type']) || !in_array($payload['entity_type'], ['Project', 'Program'], true)) {
        $errors[] = 'A valid entity type is required.';
    }
    if (empty($payload['entity_id'])) {
        $errors[] = 'Please select a project or program.';
    }
    if (empty($payload['workplan_year'])) {
        $errors[] = 'A workplan year is required.';
    }
    if (empty($payload['milestones']) || !is_array($payload['milestones'])) {
        $errors[] = 'At least one milestone is required.';
    } else {
        foreach ($payload['milestones'] as $m) {
            if (empty($m['milestone_name'])) {
                $errors[] = 'Every milestone needs a name.';
                break;
            }
        }
    }

    return empty($errors);
}

const WP_CALENDAR_FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Annual'];

/** Per-deliverable columns that are not part of the builder payload and must survive a save. */
const WP_PRESERVED_DELIVERABLE_COLUMNS = [
    'google_event_id', 'google_html_link', 'google_sync_status', 'google_sync_error', 'google_last_synced_at',
    'original_start_date', 'original_end_date', 'carried_over', 'carried_over_count', 'last_carried_over_at',
];

function wp_existing_columns(mysqli $conn, string $table, array $columns): array
{
    $out = [];
    foreach ($columns as $column) {
        if (wp_column_exists($conn, $table, $column)) {
            $out[] = $column;
        }
    }
    return $out;
}

function wp_deliverable_key(string $milestoneName, string $title): string
{
    return mb_strtolower(trim($milestoneName)) . '|' . mb_strtolower(trim($title));
}

/**
 * Snapshot deliverable state that the builder does not send (Google sync ids,
 * carry-over history) so a "wipe and re-insert" save does not lose it.
 */
function wp_snapshot_deliverables(mysqli $conn, int $workplanId): array
{
    $columns = wp_existing_columns($conn, 'workplan_deliverables', WP_PRESERVED_DELIVERABLE_COLUMNS);
    $select = $columns ? ', ' . implode(', ', array_map(fn($c) => 'wd.' . $c, $columns)) : '';

    $stmt = $conn->prepare(
        "SELECT wd.id, wd.title, wm.milestone_name{$select}
         FROM workplan_deliverables wd
         INNER JOIN workplan_milestones wm ON wm.id = wd.milestone_id
         WHERE wm.workplan_id = ?"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $workplanId);
    $stmt->execute();
    $res = $stmt->get_result();

    $snapshot = [];
    while ($row = $res->fetch_assoc()) {
        $key = wp_deliverable_key((string)$row['milestone_name'], (string)$row['title']);
        if (!isset($snapshot[$key])) {
            $snapshot[$key] = $row;
        }
    }
    $stmt->close();

    return $snapshot;
}

/** Insert milestones/deliverables/monthly-status rows for a workplan. Assumes an open transaction. */
function wp_persist_milestones(mysqli $conn, int $workplanId, array $milestones, array $preserved = []): void
{
    // Bug fix: calendar_frequency (Daily/Weekly/Monthly...) was sent by the
    // builder but never saved, so every task reverted to the default period.
    $milestoneHasFrequency = wp_column_exists($conn, 'workplan_milestones', 'calendar_frequency');
    $deliverableHasFrequency = wp_column_exists($conn, 'workplan_deliverables', 'calendar_frequency');
    $preservedColumns = wp_existing_columns($conn, 'workplan_deliverables', WP_PRESERVED_DELIVERABLE_COLUMNS);
    $hasCarryLog = wp_table_exists_simple($conn, 'task_carryover_log');

    $mStmt = $conn->prepare(
        "INSERT INTO workplan_milestones
            (workplan_id, milestone_name, milestone_description, milestone_type, start_date, due_date, responsible_person, sort_order"
            . ($milestoneHasFrequency ? ", calendar_frequency" : "") . ")
         VALUES (?, ?, ?, ?, ?, ?, ?, ?" . ($milestoneHasFrequency ? ", ?" : "") . ")"
    );

    $dStmt = $conn->prepare(
        "INSERT INTO workplan_deliverables
            (milestone_id, title, responsible_person, start_date, end_date, status, progress_percentage, notes, sort_order"
            . ($deliverableHasFrequency ? ", calendar_frequency" : "") . ")
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?" . ($deliverableHasFrequency ? ", ?" : "") . ")"
    );

    $sStmt = $conn->prepare(
        "INSERT INTO workplan_monthly_status (deliverable_id, month_number, status, comment)
         VALUES (?, ?, ?, ?)"
    );

    if (!$mStmt || !$dStmt || !$sStmt) {
        throw new RuntimeException('Workplan insert prepare failed: ' . $conn->error);
    }

    foreach ($milestones as $mIndex => $m) {
        $name        = trim((string)($m['milestone_name'] ?? ''));
        $description = trim((string)($m['milestone_description'] ?? ''));
        $type        = in_array($m['milestone_type'] ?? '', WP_MILESTONE_TYPES, true) ? $m['milestone_type'] : 'Implementation';
        $start       = wp_clean_date($m['start_date'] ?? null);
        $due         = wp_clean_date($m['due_date'] ?? null);
        $responsible = trim((string)($m['responsible_person'] ?? ''));
        $sortOrder   = (int)$mIndex;
        $mFrequency  = in_array($m['calendar_frequency'] ?? '', WP_CALENDAR_FREQUENCIES, true) ? $m['calendar_frequency'] : 'Annual';

        if ($milestoneHasFrequency) {
            $mStmt->bind_param('issssssis', $workplanId, $name, $description, $type, $start, $due, $responsible, $sortOrder, $mFrequency);
        } else {
            $mStmt->bind_param('issssssi', $workplanId, $name, $description, $type, $start, $due, $responsible, $sortOrder);
        }
        $mStmt->execute();
        $milestoneId = (int)$conn->insert_id;

        foreach (($m['deliverables'] ?? []) as $dIndex => $d) {
            $title       = trim((string)($d['title'] ?? ''));
            if ($title === '') {
                continue; // skip blank rows, mirroring the client-side behavior
            }
            $actor       = trim((string)($d['responsible_person'] ?? ''));
            $dStart      = wp_clean_date($d['start_date'] ?? null);
            $dEnd        = wp_clean_date($d['end_date'] ?? null);
            $status      = in_array($d['status'] ?? '', WP_DELIVERABLE_STATUSES, true) ? $d['status'] : 'Pending';
            $progress    = max(0.0, min(100.0, (float)($d['progress_percentage'] ?? 0)));
            $notes       = trim((string)($d['notes'] ?? ''));
            $dSort       = (int)$dIndex;
            $dFrequency  = in_array($d['calendar_frequency'] ?? '', WP_CALENDAR_FREQUENCIES, true) ? $d['calendar_frequency'] : 'Monthly';

            if ($deliverableHasFrequency) {
                $dStmt->bind_param('isssssdsis', $milestoneId, $title, $actor, $dStart, $dEnd, $status, $progress, $notes, $dSort, $dFrequency);
            } else {
                $dStmt->bind_param('isssssdsi', $milestoneId, $title, $actor, $dStart, $dEnd, $status, $progress, $notes, $dSort);
            }
            $dStmt->execute();
            $deliverableId = (int)$conn->insert_id;

            // Restore preserved per-task state (Google sync, carry-over history).
            $old = $preserved[wp_deliverable_key($name, $title)] ?? null;
            if ($old && $preservedColumns) {
                $sets = [];
                $types = '';
                $values = [];
                foreach ($preservedColumns as $column) {
                    $sets[] = "`$column` = ?";
                    $types .= 's';
                    $values[] = $old[$column] ?? null;
                }
                $types .= 'i';
                $values[] = $deliverableId;
                $restore = $conn->prepare('UPDATE workplan_deliverables SET ' . implode(', ', $sets) . ' WHERE id = ?');
                if ($restore) {
                    $restore->bind_param($types, ...$values);
                    $restore->execute();
                    $restore->close();
                }

                if ($hasCarryLog && (int)$old['id'] !== $deliverableId) {
                    $relink = $conn->prepare('UPDATE task_carryover_log SET deliverable_id = ? WHERE deliverable_id = ?');
                    if ($relink) {
                        $oldId = (int)$old['id'];
                        $relink->bind_param('ii', $deliverableId, $oldId);
                        $relink->execute();
                        $relink->close();
                    }
                }
            }

            foreach (($d['monthly_status'] ?? []) as $monthKey => $entry) {
                $monthNum = (int)$monthKey;
                if ($monthNum < 1 || $monthNum > 12 || !is_array($entry)) {
                    continue;
                }
                $st = in_array($entry['status'] ?? '', WP_MONTHLY_STATUSES, true) ? $entry['status'] : 'Planned';
                $comment = mb_substr(trim((string)($entry['comment'] ?? '')), 0, 500);

                // Only store rows that carry information (non-default) to keep tables lean.
                if ($st === 'Planned' && $comment === '') {
                    continue;
                }

                $sStmt->bind_param('iiss', $deliverableId, $monthNum, $st, $comment);
                $sStmt->execute();
            }
        }
    }

    $mStmt->close();
    $dStmt->close();
    $sStmt->close();
}

function wp_clean_date(mixed $value): ?string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

function wp_table_exists_simple(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'template') {
        wp_import_template();
    }

    if ($_GET['action'] === 'list') {
        echo json_encode(['success' => true, 'workplans' => wp_list_workplans($conn)]);
        exit;
    }

    if ($_GET['action'] === 'get') {
        $workplanId = (int)($_GET['workplan_id'] ?? 0);
        $workplan = $workplanId ? wp_get_full_workplan($conn, $workplanId) : null;

        if (!$workplan) {
            echo json_encode(['success' => false, 'message' => 'Workplan not found.']);
            exit;
        }

        echo json_encode([
            'success'  => true,
            'workplan' => $workplan,
            'stats'    => wp_compute_stats($workplan),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Bulk import from a CSV upload (multipart form, not JSON).
if ($method === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    wp_import_handle($conn);
    exit;
}

if ($method === 'POST') {
    $payload = wp_json_input();

    $isUpdate = !empty($payload['update_workplan']) && !empty($payload['workplan_id']);
    $isCreate = !empty($payload['create_workplan']);

    if (!$isCreate && !$isUpdate) {
        echo json_encode(['success' => false, 'message' => 'Unrecognized request.']);
        exit;
    }

    $errors = [];
    if (!wp_validate_payload($payload, $errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    $conn->begin_transaction();

    try {
        if ($isUpdate) {
            $workplanId = (int)$payload['workplan_id'];

            $exists = $conn->prepare("SELECT 1 FROM workplans WHERE id = ? LIMIT 1");
            $exists->bind_param('i', $workplanId);
            $exists->execute();
            $exists->store_result();
            $found = $exists->num_rows > 0;
            $exists->close();
            if (!$found) {
                throw new RuntimeException('Workplan not found.');
            }

            $stmt = $conn->prepare(
                "UPDATE workplans SET entity_type = ?, entity_id = ?, workplan_year = ? WHERE id = ?"
            );
            $entityId = (int)$payload['entity_id'];
            $year = (int)$payload['workplan_year'];
            $stmt->bind_param('siii', $payload['entity_type'], $entityId, $year, $workplanId);
            $stmt->execute();
            $stmt->close();

            // Wipe and re-insert children on every save, but keep per-task
            // state the builder does not send (Google ids, carry-over history).
            $preserved = wp_snapshot_deliverables($conn, $workplanId);

            $del = $conn->prepare("DELETE FROM workplan_milestones WHERE workplan_id = ?");
            $del->bind_param('i', $workplanId);
            $del->execute();
            $del->close();
        } else {
            $userId = $_SESSION['user_id'] ?? null;
            $stmt = $conn->prepare(
                "INSERT INTO workplans (entity_type, entity_id, workplan_year, created_by) VALUES (?, ?, ?, ?)"
            );
            $entityId = (int)$payload['entity_id'];
            $year = (int)$payload['workplan_year'];
            $stmt->bind_param('siii', $payload['entity_type'], $entityId, $year, $userId);
            $stmt->execute();
            $workplanId = (int)$conn->insert_id;
            $stmt->close();
        }

        wp_persist_milestones($conn, $workplanId, $payload['milestones'], $preserved ?? []);

        $conn->commit();

        echo json_encode([
            'success'     => true,
            'message'     => $isUpdate ? 'Workplan updated successfully.' : 'Workplan saved successfully.',
            'workplan_id' => $workplanId,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Workplan save failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not save workplan. Please try again.']);
    }

    exit;
}

echo json_encode(['success' => false, 'message' => 'Unsupported request method.']);