<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IT SUPPORT REQUESTS
|--------------------------------------------------------------------------
| Any staff member raises a ticket for the IT department. Every active
| "IT Officer" is notified in-app and by email (Administrators when there is
| no IT Officer). IT Officers and Administrators work the queue: assign,
| change status, reply to the requester or add internal notes. The requester
| follows progress, replies, confirms a fix (Closed) or reopens the ticket.
|
|   Open -> In Progress -> (On Hold) -> Resolved -> Closed
|                     ^-------- reopened by the requester ---|
|
| Status changes and assignments are recorded in the timeline as 'event'
| entries; internal notes ('note') are never shown to the requester.
| Attachments live in uploads/it-support/{ticket_id}/ and are served by
| download-file.php (IT staff + the requester only).
*/

const ITS_MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;
const ITS_ATTACHMENT_TYPES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'doc', 'docx', 'xls', 'xlsx'];

if (!function_exists('its_ensure_tables')) {
    function its_ensure_tables(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $ready = (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS it_support_tickets (
                    ticket_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    requester_id INT NOT NULL,
                    category VARCHAR(60) NOT NULL,
                    priority ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
                    subject VARCHAR(200) NOT NULL,
                    description TEXT NOT NULL,
                    location VARCHAR(150) NULL,
                    device VARCHAR(150) NULL,
                    status ENUM('Open','In Progress','On Hold','Resolved','Closed') NOT NULL DEFAULT 'Open',
                    assigned_to INT NULL,
                    attachment_path VARCHAR(255) NULL,
                    attachment_name VARCHAR(255) NULL,
                    first_response_at DATETIME NULL,
                    resolved_at DATETIME NULL,
                    closed_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (ticket_id),
                    KEY idx_its_requester (requester_id, status),
                    KEY idx_its_assignee (assigned_to, status),
                    KEY idx_its_status (status, priority, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ") && (bool)$conn->query("
                CREATE TABLE IF NOT EXISTS it_support_comments (
                    comment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ticket_id INT UNSIGNED NOT NULL,
                    user_id INT NOT NULL,
                    kind ENUM('comment','note','event') NOT NULL DEFAULT 'comment',
                    body TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (comment_id),
                    KEY idx_its_comment_ticket (ticket_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('it_support tables unavailable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('its_categories')) {
    function its_categories(): array
    {
        return [
            'Computer / laptop' => 'fa-laptop',
            'Software / applications' => 'fa-window-maximize',
            'Network / internet' => 'fa-wifi',
            'Email' => 'fa-envelope',
            'Account / password' => 'fa-key',
            'Printer / scanner' => 'fa-print',
            'Phone / mobile device' => 'fa-mobile-screen',
            'IMS system' => 'fa-gauge-high',
            'New equipment or access' => 'fa-box-open',
            'Other' => 'fa-circle-question',
        ];
    }
}

/** Priority => [hint, badge class]. */
if (!function_exists('its_priorities')) {
    function its_priorities(): array
    {
        return [
            'Low' => ['Minor issue, a workaround exists', 'badge-secondary'],
            'Medium' => ['Affects my work, but I can continue', 'badge-info'],
            'High' => ['I cannot work, or several people are affected', 'badge-warning'],
            'Critical' => ['Office-wide outage or security incident', 'badge-danger'],
        ];
    }
}

if (!function_exists('its_statuses')) {
    function its_statuses(): array
    {
        return [
            'Open' => 'badge-warning',
            'In Progress' => 'badge-info',
            'On Hold' => 'badge-secondary',
            'Resolved' => 'badge-success',
            'Closed' => 'badge-secondary',
        ];
    }
}

/** Roles that work the IT support queue. */
if (!function_exists('its_handler_roles')) {
    function its_handler_roles(): array
    {
        return ['IT Officer', 'Administrator'];
    }
}

if (!function_exists('its_is_handler')) {
    function its_is_handler(string $role): bool
    {
        return in_array($role, its_handler_roles(), true);
    }
}

if (!function_exists('its_ref')) {
    function its_ref(int $ticketId): string
    {
        return 'IT-' . str_pad((string)$ticketId, 5, '0', STR_PAD_LEFT);
    }
}

/** Active users in $roles: list of [user_id, full_name, email, role]. */
if (!function_exists('its_users_in_roles')) {
    function its_users_in_roles(mysqli $conn, array $roles): array
    {
        $stmt = $conn->prepare(
            'SELECT user_id, full_name, email, role FROM users
             WHERE is_active = 1 AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')
             ORDER BY role = \'IT Officer\' DESC, full_name'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param(str_repeat('s', count($roles)), ...$roles);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}

/** Who hears about new tickets: the IT Officers, or Administrators if there are none. */
if (!function_exists('its_officers')) {
    function its_officers(mysqli $conn): array
    {
        return its_users_in_roles($conn, ['IT Officer']) ?: its_users_in_roles($conn, ['Administrator']);
    }
}

if (!function_exists('its_find')) {
    function its_find(mysqli $conn, int $ticketId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT t.*, r.full_name AS requester_name, r.email AS requester_email, r.role AS requester_role,
                    a.full_name AS assignee_name, a.email AS assignee_email
             FROM it_support_tickets t
             JOIN users r ON r.user_id = t.requester_id
             LEFT JOIN users a ON a.user_id = t.assigned_to
             WHERE t.ticket_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

if (!function_exists('its_can_view')) {
    function its_can_view(array $ticket, int $userId, bool $isHandler): bool
    {
        return $isHandler || (int)$ticket['requester_id'] === $userId;
    }
}

/** Timeline entries, oldest first; internal notes only for IT staff. */
if (!function_exists('its_comments')) {
    function its_comments(mysqli $conn, int $ticketId, bool $includeNotes): array
    {
        $stmt = $conn->prepare(
            "SELECT c.*, u.full_name, u.role
             FROM it_support_comments c
             LEFT JOIN users u ON u.user_id = c.user_id
             WHERE c.ticket_id = ?" . ($includeNotes ? '' : " AND c.kind <> 'note'") . "
             ORDER BY c.created_at, c.comment_id"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('its_add_comment')) {
    function its_add_comment(mysqli $conn, int $ticketId, int $userId, string $kind, string $body): void
    {
        $stmt = $conn->prepare('INSERT INTO it_support_comments (ticket_id, user_id, kind, body, created_at) VALUES (?, ?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $now = date('Y-m-d H:i:s');
        $stmt->bind_param('iisss', $ticketId, $userId, $kind, $body, $now);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('its_clean')) {
    function its_clean(mixed $value, int $max, bool $multiline = false): string
    {
        $value = trim(str_replace("\0", '', (string)$value));
        $value = (string)preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]+/u', $multiline ? '' : ' ', $value);

        return mb_substr($value, 0, $max, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| ACTIONS: each returns ['ok' => bool, 'message' => string, 'id' => int]
|--------------------------------------------------------------------------
*/

if (!function_exists('its_create')) {
    function its_create(mysqli $conn, int $userId, array $input, ?array $file): array
    {
        $category = its_clean($input['category'] ?? '', 60);
        $priority = its_clean($input['priority'] ?? 'Medium', 20);
        $subject = its_clean($input['subject'] ?? '', 200);
        $description = its_clean($input['description'] ?? '', 5000, true);
        $location = its_clean($input['location'] ?? '', 150);
        $device = its_clean($input['device'] ?? '', 150);

        if (!array_key_exists($category, its_categories())) {
            return ['ok' => false, 'message' => 'Choose what the problem is about.', 'id' => 0];
        }
        if (!array_key_exists($priority, its_priorities())) {
            $priority = 'Medium';
        }
        if ($subject === '') {
            return ['ok' => false, 'message' => 'Give the request a short subject.', 'id' => 0];
        }
        if (mb_strlen($description) < 10) {
            return ['ok' => false, 'message' => 'Describe the problem (at least 10 characters).', 'id' => 0];
        }

        $hasFile = $file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $check = null;
        if ($hasFile) {
            $check = function_exists('ims_validate_upload')
                ? ims_validate_upload($file, ITS_ATTACHMENT_TYPES, ITS_MAX_ATTACHMENT_BYTES)
                : ['ok' => false, 'error' => 'Uploads are unavailable.'];
            if (!$check['ok']) {
                return ['ok' => false, 'message' => 'Attachment rejected: ' . $check['error'], 'id' => 0];
            }
        }

        $rateKey = 'it-support:' . $userId;
        if (function_exists('ims_rate_limit_too_many') && ims_rate_limit_too_many($rateKey, 10, 3600)) {
            return ['ok' => false, 'message' => 'You have raised several requests in the last hour. Add details to an existing request or try again later.', 'id' => 0];
        }

        $locationValue = $location !== '' ? $location : null;
        $deviceValue = $device !== '' ? $device : null;
        $now = date('Y-m-d H:i:s');

        try {
            $stmt = $conn->prepare(
                'INSERT INTO it_support_tickets (requester_id, category, priority, subject, description, location, device, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('isssssss', $userId, $category, $priority, $subject, $description, $locationValue, $deviceValue, $now);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        } catch (Throwable $e) {
            error_log('its_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save your request. Please try again.', 'id' => 0];
        }

        if (function_exists('ims_rate_limit_hit')) {
            ims_rate_limit_hit($rateKey, 3600);
        }

        $attachmentNote = '';
        if ($hasFile && $check) {
            $dir = dirname(__DIR__) . '/uploads/it-support/' . $id;
            $stored = bin2hex(random_bytes(12)) . '.' . $check['extension'];
            $original = mb_substr(basename(str_replace('\\', '/', (string)($file['name'] ?? 'attachment'))), 0, 255);
            if ((is_dir($dir) || @mkdir($dir, 0750, true)) && move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
                $path = 'uploads/it-support/' . $id . '/' . $stored;
                $stmt = $conn->prepare('UPDATE it_support_tickets SET attachment_path = ?, attachment_name = ? WHERE ticket_id = ?');
                if ($stmt) {
                    $stmt->bind_param('ssi', $path, $original, $id);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                error_log('its_create: could not store attachment for ticket ' . $id);
                $attachmentNote = ' (the attachment could not be saved)';
            }
        }

        $ticket = its_find($conn, $id);
        if ($ticket) {
            $officers = its_officers($conn);
            $summary = $ticket['requester_name'] . ' · ' . $priority . ' · ' . $category . ': ' . $subject;
            its_notify(array_column($officers, 'user_id'), 'New IT support request ' . its_ref($id), $summary, $priority === 'Critical' || $priority === 'High' ? 'warning' : 'info', $id);
            its_email(
                $officers,
                '[' . its_ref($id) . '] New ' . strtolower($priority) . ' priority IT request: ' . $subject,
                '<p>' . its_h($ticket['requester_name']) . ' raised a new IT support request.</p>' . its_details_html($ticket)
                    . '<p>' . nl2br(its_h($description)) . '</p>'
                    . its_link_html($id, 'Open the request')
            );
        }

        return ['ok' => true, 'message' => 'Request ' . its_ref($id) . ' sent to the IT department' . $attachmentNote . '. You will be notified as it progresses.', 'id' => $id];
    }
}

/** A reply from the requester or IT staff; IT staff may mark it as an internal note. */
if (!function_exists('its_reply')) {
    function its_reply(mysqli $conn, int $userId, bool $isHandler, int $ticketId, string $body, bool $internal): array
    {
        $ticket = its_find($conn, $ticketId);
        if (!$ticket || !its_can_view($ticket, $userId, $isHandler)) {
            return ['ok' => false, 'message' => 'Request not found.', 'id' => $ticketId];
        }
        $body = its_clean($body, 5000, true);
        if ($body === '') {
            return ['ok' => false, 'message' => 'Write a message first.', 'id' => $ticketId];
        }
        $isRequester = (int)$ticket['requester_id'] === $userId;
        $internal = $internal && $isHandler && !$isRequester;
        $now = date('Y-m-d H:i:s');

        $conn->begin_transaction();
        try {
            its_add_comment($conn, $ticketId, $userId, $internal ? 'note' : 'comment', $body);

            // A requester reply on a resolved/closed ticket reopens it.
            $reopen = $isRequester && in_array($ticket['status'], ['Resolved', 'Closed'], true);
            if ($reopen) {
                its_set_status($conn, $ticket, 'Open', $userId, $now);
            } elseif (!$internal && !$isRequester && $ticket['first_response_at'] === null) {
                $stmt = $conn->prepare('UPDATE it_support_tickets SET first_response_at = ? WHERE ticket_id = ? AND first_response_at IS NULL');
                $stmt->bind_param('si', $now, $ticketId);
                $stmt->execute();
                $stmt->close();
            } else {
                $conn->query('UPDATE it_support_tickets SET updated_at = NOW() WHERE ticket_id = ' . $ticketId);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('its_reply: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save your message. Please try again.', 'id' => $ticketId];
        }

        if ($internal) {
            return ['ok' => true, 'message' => 'Internal note added.', 'id' => $ticketId];
        }

        $actor = $isRequester ? $ticket['requester_name'] : (its_user_name($conn, $userId) ?: 'IT support');
        $subject = '[' . its_ref($ticketId) . '] ' . $ticket['subject'];
        $html = '<p><strong>' . its_h($actor) . '</strong> wrote:</p><blockquote style="border-left:3px solid #d1d5db;margin:0;padding:4px 12px">' . nl2br(its_h($body)) . '</blockquote>'
            . its_link_html($ticketId, 'View the request');

        if ($isRequester) {
            // To the assignee, or the whole IT team while unassigned.
            $to = $ticket['assigned_to'] ? [['user_id' => (int)$ticket['assigned_to'], 'full_name' => $ticket['assignee_name'], 'email' => $ticket['assignee_email']]] : its_officers($conn);
            $to = array_values(array_filter($to, static fn($p) => (int)$p['user_id'] !== $userId));
            its_notify(array_column($to, 'user_id'), 'Reply on ' . its_ref($ticketId), $actor . ': ' . mb_substr($body, 0, 180), 'info', $ticketId);
            its_email($to, 'Re: ' . $subject, $html);
            return ['ok' => true, 'message' => in_array($ticket['status'], ['Resolved', 'Closed'], true) ? 'Message sent and the request reopened.' : 'Message sent to IT support.', 'id' => $ticketId];
        }

        its_notify([(int)$ticket['requester_id']], 'IT support replied on ' . its_ref($ticketId), $actor . ': ' . mb_substr($body, 0, 180), 'info', $ticketId);
        its_email([['full_name' => $ticket['requester_name'], 'email' => $ticket['requester_email']]], 'Re: ' . $subject, $html);

        return ['ok' => true, 'message' => 'Reply sent to ' . $ticket['requester_name'] . '.', 'id' => $ticketId];
    }
}

/** IT staff change status and/or assignee. */
if (!function_exists('its_update')) {
    function its_update(mysqli $conn, int $userId, int $ticketId, array $input): array
    {
        $ticket = its_find($conn, $ticketId);
        if (!$ticket) {
            return ['ok' => false, 'message' => 'Request not found.', 'id' => $ticketId];
        }

        $status = (string)($input['status'] ?? $ticket['status']);
        if (!array_key_exists($status, its_statuses())) {
            return ['ok' => false, 'message' => 'Choose a valid status.', 'id' => $ticketId];
        }
        $assignee = (int)($input['assigned_to'] ?? (int)$ticket['assigned_to']);
        $handlers = array_column(its_users_in_roles($conn, its_handler_roles()), null, 'user_id');
        if ($assignee !== 0 && !isset($handlers[$assignee])) {
            return ['ok' => false, 'message' => 'Assign the request to an IT Officer or Administrator.', 'id' => $ticketId];
        }
        $note = its_clean($input['resolution'] ?? '', 2000, true);

        $statusChanged = $status !== $ticket['status'];
        $assigneeChanged = $assignee !== (int)$ticket['assigned_to'];
        if (!$statusChanged && !$assigneeChanged) {
            return ['ok' => false, 'message' => 'Nothing to update.', 'id' => $ticketId];
        }
        // Starting work on an unassigned ticket takes it.
        if ($statusChanged && $status === 'In Progress' && $assignee === 0) {
            $assignee = $userId;
            $assigneeChanged = true;
        }
        $now = date('Y-m-d H:i:s');

        $conn->begin_transaction();
        try {
            if ($assigneeChanged) {
                $assigneeValue = $assignee ?: null;
                $stmt = $conn->prepare('UPDATE it_support_tickets SET assigned_to = ?, first_response_at = COALESCE(first_response_at, ?) WHERE ticket_id = ?');
                $stmt->bind_param('isi', $assigneeValue, $now, $ticketId);
                $stmt->execute();
                $stmt->close();
                its_add_comment($conn, $ticketId, $userId, 'event', $assignee ? 'Assigned to ' . $handlers[$assignee]['full_name'] . '.' : 'Unassigned.');
            }
            if ($statusChanged) {
                its_set_status($conn, $ticket, $status, $userId, $now);
                if ($note !== '') {
                    its_add_comment($conn, $ticketId, $userId, 'comment', $note);
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('its_update: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the request. Please try again.', 'id' => $ticketId];
        }

        $ref = its_ref($ticketId);
        if ($assigneeChanged && $assignee && $assignee !== $userId) {
            $person = $handlers[$assignee];
            its_notify([$assignee], 'IT request assigned to you: ' . $ref, $ticket['priority'] . ' · ' . $ticket['subject'], 'info', $ticketId);
            its_email([$person], '[' . $ref . '] Assigned to you: ' . $ticket['subject'], '<p>An IT support request has been assigned to you.</p>' . its_details_html($ticket) . its_link_html($ticketId, 'Open the request'));
        }
        if ($statusChanged) {
            $requester = [['user_id' => (int)$ticket['requester_id'], 'full_name' => $ticket['requester_name'], 'email' => $ticket['requester_email']]];
            $line = 'Your request ' . $ref . ' (' . $ticket['subject'] . ') is now ' . $status . '.';
            its_notify([(int)$ticket['requester_id']], 'IT request ' . $status . ': ' . $ref, $line . ($note !== '' ? "\n" . mb_substr($note, 0, 180) : ''), $status === 'Resolved' ? 'success' : 'info', $ticketId);
            if (in_array($status, ['Resolved', 'On Hold'], true) || $note !== '') {
                its_email(
                    $requester,
                    '[' . $ref . '] ' . $status . ': ' . $ticket['subject'],
                    '<p>' . its_h($line) . '</p>'
                        . ($note !== '' ? '<blockquote style="border-left:3px solid #d1d5db;margin:0;padding:4px 12px">' . nl2br(its_h($note)) . '</blockquote>' : '')
                        . ($status === 'Resolved' ? '<p>If the problem is fixed, please confirm it in the IMS so the request can be closed. If not, reply and it will be reopened.</p>' : '')
                        . its_link_html($ticketId, 'View the request')
                );
            }
        }

        return ['ok' => true, 'message' => $ref . ' updated.', 'id' => $ticketId];
    }
}

/** The requester confirms a fix (close) or reopens the ticket. */
if (!function_exists('its_requester_action')) {
    function its_requester_action(mysqli $conn, int $userId, int $ticketId, string $action, string $note): array
    {
        $ticket = its_find($conn, $ticketId);
        if (!$ticket || (int)$ticket['requester_id'] !== $userId) {
            return ['ok' => false, 'message' => 'Request not found.', 'id' => $ticketId];
        }
        $note = its_clean($note, 2000, true);
        $now = date('Y-m-d H:i:s');

        if ($action === 'close' && $ticket['status'] !== 'Closed') {
            $target = 'Closed';
        } elseif ($action === 'reopen' && in_array($ticket['status'], ['Resolved', 'Closed'], true)) {
            $target = 'Open';
        } else {
            return ['ok' => false, 'message' => 'This request has already changed. Refresh and try again.', 'id' => $ticketId];
        }

        $conn->begin_transaction();
        try {
            if ($note !== '') {
                its_add_comment($conn, $ticketId, $userId, 'comment', $note);
            }
            its_set_status($conn, $ticket, $target, $userId, $now);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('its_requester_action: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the request. Please try again.', 'id' => $ticketId];
        }

        $to = $ticket['assigned_to'] ? [['user_id' => (int)$ticket['assigned_to'], 'full_name' => $ticket['assignee_name'], 'email' => $ticket['assignee_email']]] : its_officers($conn);
        $ref = its_ref($ticketId);
        $line = $ticket['requester_name'] . ($target === 'Closed' ? ' closed ' : ' reopened ') . $ref . ': ' . $ticket['subject'];
        its_notify(array_column($to, 'user_id'), $target === 'Closed' ? 'IT request closed' : 'IT request reopened', $line . ($note !== '' ? "\n" . mb_substr($note, 0, 180) : ''), $target === 'Closed' ? 'success' : 'warning', $ticketId);
        if ($target === 'Open') {
            its_email($to, '[' . $ref . '] Reopened: ' . $ticket['subject'], '<p>' . its_h($line) . '.</p>' . ($note !== '' ? '<p>' . nl2br(its_h($note)) . '</p>' : '') . its_link_html($ticketId, 'Open the request'));
        }

        return ['ok' => true, 'message' => $target === 'Closed' ? 'Thanks for confirming. ' . $ref . ' is closed.' : $ref . ' reopened. IT support has been notified.', 'id' => $ticketId];
    }
}

/** Change status with timestamps and a timeline entry (inside a transaction). */
if (!function_exists('its_set_status')) {
    function its_set_status(mysqli $conn, array $ticket, string $status, int $userId, string $now): void
    {
        $id = (int)$ticket['ticket_id'];
        $stmt = $conn->prepare(
            "UPDATE it_support_tickets
             SET status = ?,
                 resolved_at = CASE WHEN ? = 'Resolved' THEN ? WHEN ? IN ('Open','In Progress','On Hold') THEN NULL ELSE resolved_at END,
                 closed_at = CASE WHEN ? = 'Closed' THEN ? ELSE NULL END,
                 first_response_at = CASE WHEN first_response_at IS NULL AND requester_id <> ? THEN ? ELSE first_response_at END
             WHERE ticket_id = ?"
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $stmt->bind_param('ssssssisi', $status, $status, $now, $status, $status, $now, $userId, $now, $id);
        $stmt->execute();
        $stmt->close();

        its_add_comment($conn, $id, $userId, 'event', 'Status changed from ' . $ticket['status'] . ' to ' . $status . '.');
    }
}

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
*/

if (!function_exists('its_h')) {
    function its_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('its_user_name')) {
    function its_user_name(mysqli $conn, int $userId): string
    {
        $stmt = $conn->prepare('SELECT full_name FROM users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (string)($row['full_name'] ?? '');
    }
}

if (!function_exists('its_notify')) {
    function its_notify(array $userIds, string $title, string $message, string $type, int $ticketId): void
    {
        if (!function_exists('notify_user')) {
            return;
        }
        foreach (array_unique(array_map('intval', $userIds)) as $id) {
            notify_user($id, $title, $message, $type, $ticketId, 'it_support');
        }
    }
}

if (!function_exists('its_details_html')) {
    function its_details_html(array $ticket): string
    {
        $rows = [
            'Reference' => its_ref((int)$ticket['ticket_id']),
            'Subject' => $ticket['subject'],
            'Category' => $ticket['category'],
            'Priority' => $ticket['priority'],
            'Requested by' => $ticket['requester_name'],
            'Location' => $ticket['location'] ?? '',
            'Device' => $ticket['device'] ?? '',
        ];
        $html = '<table cellpadding="6" style="border-collapse:collapse">';
        foreach ($rows as $label => $value) {
            if ((string)$value !== '') {
                $html .= '<tr><td><strong>' . its_h($label) . '</strong></td><td>' . its_h($value) . '</td></tr>';
            }
        }

        return $html . '</table>';
    }
}

if (!function_exists('its_link_html')) {
    function its_link_html(int $ticketId, string $label): string
    {
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';

        return $base !== ''
            ? '<p><a href="' . its_h($base . '/it-support?id=' . $ticketId) . '">' . its_h($label) . '</a></p>'
            : '<p>Open <strong>IT Support</strong> in the IMS.</p>';
    }
}

/** Email each person (['full_name','email']). Returns emails sent. */
if (!function_exists('its_email')) {
    function its_email(array $people, string $subject, string $content): int
    {
        require_once __DIR__ . '/mail-function.php';
        if (!function_exists('sendEmail')) {
            return 0;
        }
        $subject = (string)preg_replace('/[\r\n]+/', ' ', $subject);

        $sent = 0;
        foreach ($people as $person) {
            $email = trim((string)($person['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $html = '<p>Hello ' . its_h($person['full_name'] ?? '') . ',</p>' . $content;
            $body = function_exists('email_wrapper') ? email_wrapper($html) : $html;
            try {
                $result = sendEmail($email, $subject, $body, trim(strip_tags(str_replace(['</p>', '</tr>', '<br>', '<br />'], "\n", $html))));
            } catch (Throwable $e) {
                $result = $e->getMessage();
            }
            if ($result === true) {
                $sent++;
            } else {
                error_log('IT support email to ' . $email . ' failed: ' . (is_string($result) ? $result : 'unknown error'));
            }
        }

        return $sent;
    }
}
