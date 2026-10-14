<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google-calendar-service.php';

check_role(IMS_PROGRAMME_ROLES);

function clean_post(string $key): string {
    return sanitize_input((string)($_POST[$key] ?? ''));
}

function redirect_events(string $query = ''): never {
    $url = '../events';
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header("Location: {$url}");
    exit();
}

function notify_and_redirect(string $message, string $type = 'danger', string $query = ''): never {
    send_notification($_SESSION['user_id'] ?? 0, $message, $type);
    redirect_events($query);
}

function nullable_time(string $key): ?string {
    $value = trim((string)($_POST[$key] ?? ''));
    return $value !== '' ? sanitize_input($value) : null;
}

function nullable_int(string $key): ?int {
    $value = trim((string)($_POST[$key] ?? ''));
    return $value !== '' && (int)$value > 0 ? (int)$value : null;
}

function get_event_days(): array {
    $days = $_POST['event_days'] ?? [];

    if (!is_array($days)) {
        $days = [];
    }

    $cleanDays = [];

    foreach ($days as $day) {
        $day = trim((string)$day);

        if ($day === '') {
            continue;
        }

        if (!strtotime($day)) {
            continue;
        }

        $cleanDays[] = date('Y-m-d', strtotime($day));
    }

    $cleanDays = array_values(array_unique($cleanDays));
    sort($cleanDays);

    return $cleanDays;
}

function get_event_links(): array {
    $link_type = sanitize_input((string)($_POST['link_type'] ?? 'none'));

    $project_id = null;
    $program_id = null;

    if ($link_type === 'project') {
        $project_id = nullable_int('project_id');
    } elseif ($link_type === 'program') {
        $program_id = nullable_int('program_id');
    }

    return [$project_id, $program_id];
}

function validate_common_event_data(
    string $event_title,
    string $event_type,
    array $event_days,
    string $event_status,
    ?string $start_time,
    ?string $end_time,
    string $query = ''
): void {
    if ($event_title === '' || $event_type === '' || empty($event_days) || $event_status === '') {
        notify_and_redirect('Event title, type, event day, and status are required.', 'danger', $query);
    }

    $allowed_statuses = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    if (!in_array($event_status, $allowed_statuses, true)) {
        notify_and_redirect('Invalid event status selected.', 'danger', $query);
    }

    foreach ($event_days as $day) {
        $event_timestamp = strtotime($day);

        if (!$event_timestamp) {
            notify_and_redirect('Invalid event day selected.', 'danger', $query);
        }

        if ($event_timestamp < strtotime('-1 year')) {
            notify_and_redirect('Event day cannot be more than 1 year in the past.', 'danger', $query);
        }
    }

    if ($start_time && $end_time && strtotime($end_time) <= strtotime($start_time)) {
        notify_and_redirect('End time must be after start time.', 'danger', $query);
    }
}

function load_event_row(mysqli $conn, int $event_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM hub_events WHERE event_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Push the event to Google Calendar (if connected) and make sure invited staff
 * get a calendar invite: Google sends it when the sync works and invitations
 * are on, otherwise an .ics invite is emailed. Returns a summary sentence.
 */
function sync_event_and_invite(mysqli $conn, int $event_id, array $staff_changes, ?array $old_event = null): string {
    $google_synced = false;

    if (gcal_is_connected($conn)) {
        try {
            gcal_sync_hub_event($conn, $event_id, (int)($_SESSION['user_id'] ?? 0));
            $google_synced = true;
        } catch (Throwable $syncError) {
            gcal_mark_hub_event_error($conn, $event_id, $syncError->getMessage());
            error_log('Google event sync failed: ' . $syncError->getMessage());
        }
    }

    $google_invites = $google_synced && gcal_setting($conn, 'google_send_invitations', '1') === '1';
    $current = $staff_changes['current'] ?? [];
    $removed = $staff_changes['removed'] ?? [];
    $event = load_event_row($conn, $event_id);

    if (!$google_invites && $event) {
        if ($current) {
            ev_staff_email_invites($conn, $event_id, $current, 'REQUEST', $event);
        }
        if ($removed) {
            ev_staff_email_invites($conn, $event_id, $removed, 'CANCEL', $event);
        }
        // Dates dropped from the event: cancel those days in staff calendars.
        if ($old_event && $current) {
            $dropped = array_values(array_diff(ev_event_days($old_event), ev_event_days($event)));
            if ($dropped) {
                $old_event['event_days'] = json_encode($dropped);
                ev_staff_email_invites($conn, $event_id, $current, 'CANCEL', $old_event);
            }
        }
    }

    if (!empty($staff_changes['added']) && $event) {
        $when = date('D j M Y', strtotime((string)$event['event_date']))
            . (!empty($event['start_time']) ? ' at ' . substr((string)$event['start_time'], 0, 5) : '');
        ev_staff_notify(
            $staff_changes['added'],
            $event_id,
            'Event invitation',
            'You have been invited to "' . $event['event_title'] . '" on ' . $when . '. Check your calendar for the invite.'
        );
    }

    $parts = [];
    if ($google_synced) {
        $parts[] = 'Google Calendar updated.';
    }
    if ($current) {
        $parts[] = count($current) . ' staff ' . (count($current) === 1 ? 'member' : 'members')
            . ($google_invites ? ' invited via Google Calendar.' : ' sent a calendar invite by email.');
    }

    return implode(' ', $parts);
}

/* =========================
   ADD EVENT
========================= */
if (isset($_POST['add_event'])) {
    $event_title = clean_post('event_title');
    $event_type  = clean_post('event_type');

    $event_days = get_event_days();
    $event_date = $event_days[0] ?? '';

    $event_days_json = json_encode($event_days, JSON_UNESCAPED_SLASHES);

    $start_time = nullable_time('start_time');
    $end_time   = nullable_time('end_time');

    $venue                 = clean_post('venue');
    [$project_id, $program_id] = get_event_links();
    $organizer             = clean_post('organizer');
    $expected_participants = nullable_int('expected_participants');
    $description           = clean_post('description');

    $event_status = sanitize_input((string)($_POST['event_status'] ?? $_POST['status'] ?? 'Planned'));

    validate_common_event_data(
        $event_title,
        $event_type,
        $event_days,
        $event_status,
        $start_time,
        $end_time
    );

    $stmt = $conn->prepare("
        INSERT INTO hub_events (
            event_title,
            event_type,
            event_date,
            event_days,
            start_time,
            end_time,
            venue,
            project_id,
            program_id,
            organizer,
            expected_participants,
            description,
            event_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        notify_and_redirect('Database error: failed to prepare add event query.');
    }

    $stmt->bind_param(
        "sssssssiiisss",
        $event_title,
        $event_type,
        $event_date,
        $event_days_json,
        $start_time,
        $end_time,
        $venue,
        $project_id,
        $program_id,
        $organizer,
        $expected_participants,
        $description,
        $event_status
    );

    if ($stmt->execute()) {
        $new_event_id = (int)$stmt->insert_id;

        log_action(
            $_SESSION['user_id'] ?? 0,
            'Add Event',
            'events',
            $new_event_id,
            "Added event: {$event_title}"
        );

        $staff_changes = ev_staff_save($conn, $new_event_id, ev_staff_posted_ids(), (int)($_SESSION['user_id'] ?? 0));
        $summary = sync_event_and_invite($conn, $new_event_id, $staff_changes);

        send_notification(
            $_SESSION['user_id'] ?? 0,
            trim('Event added successfully. ' . $summary),
            'success'
        );
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error adding event: ' . $stmt->error, 'danger');
    }

    $stmt->close();
    redirect_events();
}

/* =========================
   EDIT EVENT
========================= */
if (isset($_POST['edit_event'])) {
    $event_id = (int)($_POST['event_id'] ?? 0);

    if ($event_id <= 0) {
        notify_and_redirect('Invalid event selected.');
    }

    $event_title = clean_post('event_title');
    $event_type  = clean_post('event_type');

    $event_days = get_event_days();
    $event_date = $event_days[0] ?? '';

    $event_days_json = json_encode($event_days, JSON_UNESCAPED_SLASHES);

    $start_time = nullable_time('start_time');
    $end_time   = nullable_time('end_time');

    $venue                 = clean_post('venue');
    [$project_id, $program_id] = get_event_links();
    $organizer             = clean_post('organizer');
    $expected_participants = nullable_int('expected_participants');
    $description           = clean_post('description');

    $event_status = sanitize_input((string)($_POST['event_status'] ?? $_POST['status'] ?? ''));

    validate_common_event_data(
        $event_title,
        $event_type,
        $event_days,
        $event_status,
        $start_time,
        $end_time,
        'edit=' . $event_id
    );

    $old_event = load_event_row($conn, $event_id);

    $stmt = $conn->prepare("
        UPDATE hub_events SET
            event_title = ?,
            event_type = ?,
            event_date = ?,
            event_days = ?,
            start_time = ?,
            end_time = ?,
            venue = ?,
            project_id = ?,
            program_id = ?,
            organizer = ?,
            expected_participants = ?,
            description = ?,
            event_status = ?
        WHERE event_id = ?
    ");

    if (!$stmt) {
        notify_and_redirect('Database error: failed to prepare update event query.', 'danger', 'edit=' . $event_id);
    }

    $stmt->bind_param(
        "sssssssiiisssi",
        $event_title,
        $event_type,
        $event_date,
        $event_days_json,
        $start_time,
        $end_time,
        $venue,
        $project_id,
        $program_id,
        $organizer,
        $expected_participants,
        $description,
        $event_status,
        $event_id
    );

    if ($stmt->execute()) {
        log_action(
            $_SESSION['user_id'] ?? 0,
            'Edit Event',
            'events',
            $event_id,
            "Updated event: {$event_title}"
        );

        $staff_changes = ev_staff_save($conn, $event_id, ev_staff_posted_ids(), (int)($_SESSION['user_id'] ?? 0));
        $summary = sync_event_and_invite($conn, $event_id, $staff_changes, $old_event);

        send_notification(
            $_SESSION['user_id'] ?? 0,
            trim('Event updated successfully. ' . $summary),
            'success'
        );
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error updating event: ' . $stmt->error, 'danger');
    }

    $stmt->close();
    redirect_events();
}

/* =========================
   DELETE EVENT
========================= */
if (isset($_POST['delete_event'])) {
    if (!in_array($_SESSION['role'] ?? '', ['Administrator', 'Operations/Admin'], true)) {
        notify_and_redirect('You are not allowed to delete events.');
    }

    $event_id = (int)($_POST['event_id'] ?? 0);

    if ($event_id <= 0) {
        notify_and_redirect('Invalid event selected.');
    }

    $event_name = 'Unknown';
    $google_event_id = '';

    $stmt = $conn->prepare("SELECT event_title, google_event_id FROM hub_events WHERE event_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $event_id);
        $stmt->execute();

        $event_result = $stmt->get_result();
        if ($event_result && $event_result->num_rows > 0) {
            $event_data = $event_result->fetch_assoc();
            $event_name = $event_data['event_title'] ?? 'Unknown';
            $google_event_id = (string)($event_data['google_event_id'] ?? '');
        }

        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM event_registrations WHERE event_id = ?");
    if (!$stmt) {
        notify_and_redirect('Database error: failed to check attendance records.');
    }

    $stmt->bind_param("i", $event_id);
    $stmt->execute();

    $check_result = $stmt->get_result();
    $count = (int)($check_result->fetch_assoc()['total'] ?? 0);

    $stmt->close();

    if ($count > 0) {
        notify_and_redirect("Cannot delete event. It has {$count} attendance record(s). Delete attendance records first.");
    }

    $deleted_event = load_event_row($conn, $event_id);
    $invited_staff = array_values(ev_staff_for_event($conn, $event_id));
    $google_cancelled = false;

    if ($google_event_id !== '' && gcal_is_connected($conn)) {
        try {
            gcal_delete_google_event(
                $conn,
                $google_event_id,
                (int)($_SESSION['user_id'] ?? 0)
            );
            $google_cancelled = gcal_setting($conn, 'google_send_invitations', '1') === '1';
        } catch (Throwable $syncError) {
            error_log(
                'Google event delete failed: '
                . $syncError->getMessage()
            );
        }
    }

    $stmt = $conn->prepare("DELETE FROM hub_events WHERE event_id = ?");
    if (!$stmt) {
        notify_and_redirect('Database error: failed to prepare delete event query.');
    }

    $stmt->bind_param("i", $event_id);

    if ($stmt->execute()) {
        log_action(
            $_SESSION['user_id'] ?? 0,
            'Delete Event',
            'events',
            $event_id,
            "Deleted event: {$event_name}"
        );

        ev_staff_delete_all($conn, $event_id);

        // Google emails attendees the cancellation; otherwise send it ourselves.
        if ($invited_staff && $deleted_event && !$google_cancelled) {
            ev_staff_email_invites($conn, $event_id, $invited_staff, 'CANCEL', $deleted_event);
        }

        send_notification($_SESSION['user_id'] ?? 0, 'Event deleted successfully.', 'success');
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error deleting event: ' . $stmt->error, 'danger');
    }

    $stmt->close();
    redirect_events();
}

redirect_events();