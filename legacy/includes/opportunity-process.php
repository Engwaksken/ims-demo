<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

if (!ims_allowed('opportunities.manage', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Program Manager', 'Program Director'], 'Create, edit, publish, close and delete opportunities')) {
    $_SESSION['error'] = 'Unauthorized access.';
    header('Location: ../login');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash_redirect(string $type, string $message, string $path): never
{
    $_SESSION[$type] = $message;
    redirect_to($path);
}

function posted(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function getted(string $key, string $default = ''): string
{
    if (!isset($_GET[$key]) || is_array($_GET[$key])) return $default;
    return trim((string)$_GET[$key]);
}

function nullable_int(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    if (filter_var($value, FILTER_VALIDATE_INT) === false) return null;
    return (int)$value;
}

function valid_date(?string $date): bool
{
    if (!$date) return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}


function bind_statement_values(mysqli_stmt $stmt, array &$values): void
{
    if ($values === []) {
        return;
    }

    /*
     * Bind all values as strings. MySQL safely coerces valid numeric values
     * into INT/BIGINT columns and accepts NULL for nullable fields.
     *
     * This deliberately avoids manual type-definition strings such as
     * "issssss..." getting out of sync with the number of variables.
     */
    $types = str_repeat('s', count($values));
    $refs = [$types];

    foreach ($values as &$value) {
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }

        $refs[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function sanitize_rich_html(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';

    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><blockquote>';
    $html = strip_tags($html, $allowed);

    // Remove inline JS/event attributes and unsafe URL protocols.
    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/\son\w+\s*=\s*[^\s>]+/isu', '', $html) ?? $html;
    $html = preg_replace('/javascript\s*:/iu', '', $html) ?? $html;
    $html = preg_replace('/data\s*:/iu', '', $html) ?? $html;

    return $html;
}

function sanitize_rich_inline(string $html): string
{
    $html = strip_tags(trim($html), '<strong><b><em><i><u><a>');
    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/javascript\s*:/iu', '', $html) ?? $html;
    $html = preg_replace('/data\s*:/iu', '', $html) ?? $html;
    return $html;
}

function clean_content_sections(array $rows): string
{
    $clean = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $heading = trim((string)($row['heading'] ?? ''));
        $description = sanitize_rich_html((string)($row['description_html'] ?? ''));
        $bullets = [];

        foreach (($row['bullets'] ?? []) as $bullet) {
            if (is_array($bullet)) continue;

            $bullet = sanitize_rich_inline((string)$bullet);
            if (trim(strip_tags($bullet)) !== '') {
                $bullets[] = $bullet;
            }
        }

        if (
            $heading !== '' ||
            trim(strip_tags($description)) !== '' ||
            $bullets !== []
        ) {
            $clean[] = [
                'heading' => $heading,
                'description_html' => $description,
                'bullets' => $bullets,
            ];
        }
    }

    return json_encode(
        $clean,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '[]';
}

function clean_bullets(array $rows): string
{
    $clean = [];

    foreach ($rows as $bullet) {
        if (is_array($bullet)) continue;

        $bullet = sanitize_rich_inline((string)$bullet);

        if (trim(strip_tags($bullet)) !== '') {
            $clean[] = $bullet;
        }
    }

    return json_encode(
        $clean,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '[]';
}

function cohort_exists(mysqli $conn, int $cohortId): bool
{
    // Actual cohorts primary key is `id`.
    $stmt = $conn->prepare('SELECT id FROM cohorts WHERE id = ? LIMIT 1');
    if (!$stmt) return false;

    $stmt->bind_param('i', $cohortId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function fetch_opportunity(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM application_opportunities WHERE opportunity_id = ? LIMIT 1');
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function application_count(mysqli $conn, int $id): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM applications WHERE opportunity_id = ?');
    if (!$stmt) return 0;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $count;
}

function log_activity(mysqli $conn, int $userId, string $action, string $details): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $conn->prepare('
        INSERT INTO activity_log (user_id, action, description, ip_address)
        VALUES (?, ?, ?, ?)
    ');

    if (!$stmt) return;

    $stmt->bind_param('isss', $userId, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

$action = posted('action', getted('action'));

if ($action === '') {
    flash_redirect('error', 'Invalid action.', '../application-opportunities');
}

/*
|--------------------------------------------------------------------------
| CREATE / UPDATE
|--------------------------------------------------------------------------
*/
if (in_array($action, ['create', 'update'], true)) {
    $opportunityId = $action === 'update' ? (int)($_POST['opportunity_id'] ?? 0) : 0;

    $back = $action === 'update'
        ? '../edit-opportunity?id=' . $opportunityId
        : '../application-opportunities';

    $title = posted('opportunity_title');
    $type = posted('opportunity_type');
    $cohortId = (int)($_POST['cohort_id'] ?? 0);

    $description = sanitize_rich_html(posted('description'));
    $contentSections = clean_content_sections(
        is_array($_POST['content_sections'] ?? null) ? $_POST['content_sections'] : []
    );

    $eligibilityHeading = posted('eligibility_heading', 'Eligibility Criteria');
    $eligibilityDescription = sanitize_rich_html(posted('eligibility_description_html'));
    $eligibilityBullets = clean_bullets(
        is_array($_POST['eligibility_bullets'] ?? null) ? $_POST['eligibility_bullets'] : []
    );

    $documentsHeading = posted('documents_heading', 'Required Documents');
    $requiredDocuments = sanitize_rich_html(posted('required_documents'));

    $startDate = posted('start_date');
    $deadline = posted('deadline');
    $announcementDate = posted('announcement_date') ?: null;

    $maxApplicants = nullable_int($_POST['max_applicants'] ?? null);
    $availableSlots = nullable_int($_POST['available_slots'] ?? null);
    $minTeamSize = nullable_int($_POST['min_team_size'] ?? 1) ?? 1;
    $maxTeamSize = nullable_int($_POST['max_team_size'] ?? null);

    $sectorsAllowed = posted('sectors_allowed');
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

    if (
        $title === '' ||
        $type === '' ||
        $cohortId < 1 ||
        trim(strip_tags($description)) === '' ||
        $startDate === '' ||
        $deadline === ''
    ) {
        flash_redirect(
            'error',
            'Please complete all required fields, including the cohort.',
            $back
        );
    }

    if (!cohort_exists($conn, $cohortId)) {
        flash_redirect('error', 'The selected cohort does not exist.', $back);
    }

    if (!valid_date($startDate) || !valid_date($deadline)) {
        flash_redirect('error', 'Please provide valid start and deadline dates.', $back);
    }

    if ($announcementDate !== null && !valid_date($announcementDate)) {
        flash_redirect('error', 'Winner announcement date is invalid.', $back);
    }

    if (strtotime($deadline) < strtotime($startDate)) {
        flash_redirect('error', 'Deadline cannot be before the start date.', $back);
    }

    if (
        $announcementDate !== null &&
        strtotime($announcementDate) < strtotime($deadline)
    ) {
        flash_redirect(
            'error',
            'Winner announcement date cannot be before the application deadline.',
            $back
        );
    }

    if ($maxApplicants !== null && $maxApplicants < 1) $maxApplicants = null;
    if ($availableSlots !== null && $availableSlots < 1) $availableSlots = null;
    if ($minTeamSize < 1) $minTeamSize = 1;

    if ($maxTeamSize !== null && $maxTeamSize < $minTeamSize) {
        flash_redirect(
            'error',
            'Maximum team size cannot be less than minimum team size.',
            $back
        );
    }

    if ($action === 'create') {
        $status = isset($_POST['publish']) ? 'Published' : 'Draft';
        $publishedAt = $status === 'Published' ? date('Y-m-d H:i:s') : null;

        $stmt = $conn->prepare('
            INSERT INTO application_opportunities (
                cohort_id,
                opportunity_title,
                opportunity_type,
                description,
                content_sections,
                eligibility_heading,
                eligibility_description_html,
                eligibility_bullets,
                documents_heading,
                required_documents,
                start_date,
                deadline,
                announcement_date,
                max_applicants,
                available_slots,
                min_team_size,
                max_team_size,
                sectors_allowed,
                is_featured,
                status,
                created_by,
                published_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');

        if (!$stmt) {
            flash_redirect(
                'error',
                'Database prepare failed: ' . $conn->error,
                $back
            );
        }

        $createValues = [
            $cohortId,
            $title,
            $type,
            $description,
            $contentSections,
            $eligibilityHeading,
            $eligibilityDescription,
            $eligibilityBullets,
            $documentsHeading,
            $requiredDocuments,
            $startDate,
            $deadline,
            $announcementDate,
            $maxApplicants,
            $availableSlots,
            $minTeamSize,
            $maxTeamSize,
            $sectorsAllowed,
            $isFeatured,
            $status,
            $user_id,
            $publishedAt,
        ];

        bind_statement_values($stmt, $createValues);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            flash_redirect(
                'error',
                'Error creating opportunity: ' . $error,
                $back
            );
        }

        $opportunityId = (int)$stmt->insert_id;
        $stmt->close();

        log_activity(
            $conn,
            $user_id,
            'Create Opportunity',
            "Created opportunity: {$title} (ID: {$opportunityId}, Cohort: {$cohortId}, Status: {$status})"
        );

        $_SESSION['success'] = $status === 'Published'
            ? 'Opportunity published successfully.'
            : 'Opportunity saved as draft.';

        redirect_to('../view-opportunity?id=' . $opportunityId);
    }

    if ($opportunityId < 1 || !fetch_opportunity($conn, $opportunityId)) {
        flash_redirect(
            'error',
            'Opportunity not found.',
            '../application-opportunities'
        );
    }

    $stmt = $conn->prepare('
        UPDATE application_opportunities SET
            cohort_id = ?,
            opportunity_title = ?,
            opportunity_type = ?,
            description = ?,
            content_sections = ?,
            eligibility_heading = ?,
            eligibility_description_html = ?,
            eligibility_bullets = ?,
            documents_heading = ?,
            required_documents = ?,
            start_date = ?,
            deadline = ?,
            announcement_date = ?,
            max_applicants = ?,
            available_slots = ?,
            min_team_size = ?,
            max_team_size = ?,
            sectors_allowed = ?,
            is_featured = ?
        WHERE opportunity_id = ?
        LIMIT 1
    ');

    if (!$stmt) {
        flash_redirect(
            'error',
            'Database prepare failed: ' . $conn->error,
            $back
        );
    }

    $updateValues = [
        $cohortId,
        $title,
        $type,
        $description,
        $contentSections,
        $eligibilityHeading,
        $eligibilityDescription,
        $eligibilityBullets,
        $documentsHeading,
        $requiredDocuments,
        $startDate,
        $deadline,
        $announcementDate,
        $maxApplicants,
        $availableSlots,
        $minTeamSize,
        $maxTeamSize,
        $sectorsAllowed,
        $isFeatured,
        $opportunityId,
    ];

    bind_statement_values($stmt, $updateValues);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        flash_redirect(
            'error',
            'Error updating opportunity: ' . $error,
            $back
        );
    }

    $stmt->close();

    log_activity(
        $conn,
        $user_id,
        'Update Opportunity',
        "Updated opportunity: {$title} (ID: {$opportunityId}, Cohort: {$cohortId})"
    );

    flash_redirect(
        'success',
        'Opportunity updated successfully.',
        '../view-opportunity?id=' . $opportunityId
    );
}

/*
|--------------------------------------------------------------------------
| STATUS / FEATURE / DELETE
|--------------------------------------------------------------------------
*/
if (in_array($action, ['publish', 'close', 'reopen', 'complete', 'toggle_featured', 'delete'], true)) {
    // State-changing links must carry the session CSRF token.
    csrf_protect(true);

    $id = (int)getted('id');

    if ($id < 1) {
        flash_redirect('error', 'Invalid opportunity ID.', '../application-opportunities');
    }

    $opportunity = fetch_opportunity($conn, $id);

    if (!$opportunity) {
        flash_redirect('error', 'Opportunity not found.', '../application-opportunities');
    }

    if ($action === 'publish') {
        if (($opportunity['status'] ?? '') !== 'Draft') {
            flash_redirect('error', 'Only draft opportunities can be published.', '../application-opportunities');
        }

        $status = 'Published';
        $publishedAt = date('Y-m-d H:i:s');

        $stmt = $conn->prepare('
            UPDATE application_opportunities
            SET status = ?, published_at = ?
            WHERE opportunity_id = ?
        ');
        $stmt->bind_param('ssi', $status, $publishedAt, $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'Publish Opportunity', "Published opportunity ID {$id}");
        flash_redirect('success', 'Opportunity published successfully.', '../application-opportunities');
    }

    if ($action === 'close') {
        if (($opportunity['status'] ?? '') !== 'Published') {
            flash_redirect('error', 'Only published opportunities can be closed.', '../application-opportunities');
        }

        $status = 'Closed';
        $stmt = $conn->prepare('UPDATE application_opportunities SET status = ? WHERE opportunity_id = ?');
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'Close Opportunity', "Closed opportunity ID {$id}");
        flash_redirect('success', 'Opportunity closed successfully.', '../application-opportunities');
    }

    if ($action === 'reopen') {
        if (($opportunity['status'] ?? '') !== 'Closed') {
            flash_redirect('error', 'Only closed opportunities can be reopened.', '../application-opportunities');
        }

        $status = 'Published';
        $stmt = $conn->prepare('UPDATE application_opportunities SET status = ? WHERE opportunity_id = ?');
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'Reopen Opportunity', "Reopened opportunity ID {$id}");
        flash_redirect('success', 'Opportunity reopened successfully.', '../application-opportunities');
    }

    if ($action === 'complete') {
        $status = 'Completed';
        $stmt = $conn->prepare('UPDATE application_opportunities SET status = ? WHERE opportunity_id = ?');
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'Complete Opportunity', "Completed opportunity ID {$id}");
        flash_redirect('success', 'Opportunity marked as completed.', '../application-opportunities');
    }

    if ($action === 'toggle_featured') {
        $featured = !empty($opportunity['is_featured']) ? 0 : 1;

        $stmt = $conn->prepare('UPDATE application_opportunities SET is_featured = ? WHERE opportunity_id = ?');
        $stmt->bind_param('ii', $featured, $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'Toggle Featured', "Updated featured status for opportunity ID {$id}");
        flash_redirect('success', 'Featured status updated.', '../application-opportunities');
    }

    if ($action === 'delete') {
        if (($opportunity['status'] ?? '') !== 'Draft') {
            flash_redirect('error', 'Only draft opportunities can be deleted.', '../application-opportunities');
        }

        if (application_count($conn, $id) > 0) {
            flash_redirect('error', 'Cannot delete an opportunity that already has applications.', '../application-opportunities');
        }

        $stmt = $conn->prepare('DELETE FROM application_opportunities WHERE opportunity_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'Delete Opportunity', "Deleted opportunity ID {$id}");
        flash_redirect('success', 'Opportunity deleted successfully.', '../application-opportunities');
    }
}

/*
|--------------------------------------------------------------------------
| EXTEND DEADLINE
|--------------------------------------------------------------------------
*/
if ($action === 'extend_deadline') {
    $id = (int)($_POST['opportunity_id'] ?? 0);
    $newDeadline = posted('new_deadline');

    $opportunity = fetch_opportunity($conn, $id);

    if (!$opportunity || !valid_date($newDeadline)) {
        flash_redirect('error', 'Invalid deadline request.', '../application-opportunities');
    }

    if (strtotime($newDeadline) <= strtotime((string)$opportunity['deadline'])) {
        flash_redirect(
            'error',
            'New deadline must be after the current deadline.',
            '../view-opportunity?id=' . $id
        );
    }

    $stmt = $conn->prepare('UPDATE application_opportunities SET deadline = ? WHERE opportunity_id = ?');
    $stmt->bind_param('si', $newDeadline, $id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, $user_id, 'Extend Deadline', "Extended opportunity ID {$id} deadline to {$newDeadline}");

    flash_redirect(
        'success',
        'Deadline extended successfully.',
        '../view-opportunity?id=' . $id
    );
}

flash_redirect('error', 'Invalid action.', '../application-opportunities');
