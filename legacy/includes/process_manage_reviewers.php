<?php
declare(strict_types=1);

ob_start();
session_start();

require_once 'config.php';

if (!ims_allowed('reviewers.manage', ['Administrator', 'MEAL Lead', 'Programs Lead', 'Program Director'], 'Manage reviewers, review types and assignments')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ../manage-reviewers');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['error'] = 'Database connection not available.';
    header('Location: ../manage-reviewers');
    exit();
}

$conn->set_charset('utf8mb4');

$current_user_id = (int)$_SESSION['user_id'];
$action          = trim((string)($_POST['action'] ?? ''));

/* ────────────────────────────────────────────────────────────────────────── */
/* HELPERS                                                                    */
/* ────────────────────────────────────────────────────────────────────────── */
function redirectTo(string $tab = 'assign', array $params = []): never
{
    $params = array_merge(['tab' => $tab], $params);

    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $clean[$key] = $value;
    }

    $query = http_build_query($clean);
    header('Location: ../manage-reviewers' . ($query ? '?' . $query : ''));
    exit();
}

function postIntArray(string $key): array
{
    $arr = $_POST[$key] ?? [];
    if (!is_array($arr)) {
        return [];
    }

    $out = [];
    foreach ($arr as $value) {
        $i = (int)$value;
        if ($i > 0) {
            $out[] = $i;
        }
    }

    return array_values(array_unique($out));
}

function clampInt(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

function clampFloat(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function appBaseUrl(): string
{
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
    );

    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir    = str_replace('\\', '/', dirname($script));

    $baseDir = rtrim(dirname($dir), '/\\');
    // Pages are served from /legacy/ internally but publicly live at the site root.
    $baseDir = preg_replace('#/legacy$#', '', $baseDir);
    if ($baseDir === '.' || $baseDir === DIRECTORY_SEPARATOR) {
        $baseDir = '';
    }

    return rtrim($scheme . '://' . $host . $baseDir, '/');
}

function buildLoginUrl(string $fallback = 'dashboard'): string
{
    $base = appBaseUrl();
    return $base . '/' . ltrim($fallback, '/');
}

function buildReviewListUrl(): string
{
    return appBaseUrl() . '/manage-reviewers?tab=status';
}

function sendPlainEmail(string $to, string $subject, string $body): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $from = 'noreply@' . preg_replace('/:\d+$/', '', $host);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . phpversion(),
    ];

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function criteriaRedirectParams(array $extra = []): array
{
    $params = [];

    $criteria_search = trim((string)($_POST['criteria_search'] ?? $_GET['criteria_search'] ?? ''));
    if ($criteria_search !== '') {
        $params['criteria_search'] = $criteria_search;
    }

    $criteria_review_type_id_raw = (string)($_POST['criteria_review_type_id'] ?? $_GET['criteria_review_type_id'] ?? '');
    if ($criteria_review_type_id_raw !== '' && (int)$criteria_review_type_id_raw > 0) {
        $params['criteria_review_type_id'] = (int)$criteria_review_type_id_raw;
    }

    $criteria_page_raw = (string)($_POST['criteria_page'] ?? $_GET['criteria_page'] ?? '');
    if ($criteria_page_raw !== '' && (int)$criteria_page_raw > 0) {
        $params['criteria_page'] = (int)$criteria_page_raw;
    }

    return array_merge($params, $extra);
}

function reviewTypeRedirectParams(array $extra = []): array
{
    $params = [];

    $edit_review_type = (int)($_POST['edit_review_type'] ?? $_GET['edit_review_type'] ?? 0);
    if ($edit_review_type > 0) {
        $params['edit_review_type'] = $edit_review_type;
    }

    return array_merge($params, $extra);
}

function normalizeNullableReviewTypeId(string $fieldName): ?int
{
    $raw = trim((string)($_POST[$fieldName] ?? ''));
    return ($raw !== '' && (int)$raw > 0) ? (int)$raw : null;
}

function normalizeBoolFlag(string $fieldName, int $default = 1): int
{
    $value = (int)($_POST[$fieldName] ?? $default);
    return in_array($value, [0, 1], true) ? $value : $default;
}

function isValidReviewType(mysqli $conn, ?int $review_type_id, bool $mustBeActive = false): bool
{
    if ($review_type_id === null || $review_type_id <= 0) {
        return true;
    }

    $sql = "
        SELECT 1
        FROM review_types
        WHERE review_type_id = ?
    ";
    if ($mustBeActive) {
        $sql .= " AND is_active = 1 ";
    }
    $sql .= " LIMIT 1 ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed (review type check): ' . $conn->error);
    }

    $stmt->bind_param('i', $review_type_id);
    $stmt->execute();
    $stmt->store_result();
    $valid = $stmt->num_rows > 0;
    $stmt->close();

    return $valid;
}

function cleanText(?string $value): string
{
    return trim((string)$value);
}

try {
    /* ────────────────────────────────────────────────────────────────────── */
    /* 1) ASSIGN REVIEWERS                                                   */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'assign_reviewers') {
        $reviewer_ids    = postIntArray('reviewer_ids');
        $application_ids = postIntArray('application_ids');

        if (empty($reviewer_ids) || empty($application_ids)) {
            $_SESSION['error'] = 'Please select at least one reviewer and one application.';
            redirectTo('assign');
        }

        $insert = $conn->prepare("
            INSERT IGNORE INTO reviewer_assignments
                (application_id, reviewer_id, assigned_by, assigned_at, status, reminder_count)
            VALUES (?, ?, ?, NOW(), 'Pending', 0)
        ");
        if (!$insert) {
            throw new RuntimeException('Prepare failed (assign insert): ' . $conn->error);
        }

        $reviewerInfo = [];
        if (!empty($reviewer_ids)) {
            $ids = implode(',', array_map('intval', $reviewer_ids));
            $sql = "
                SELECT user_id, full_name, email
                FROM users
                WHERE user_id IN ($ids)
            ";
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $reviewerInfo[(int)$row['user_id']] = $row;
                }
                $res->free();
            }
        }

        $applicationInfo = [];
        if (!empty($application_ids)) {
            $ids = implode(',', array_map('intval', $application_ids));
            $sql = "
                SELECT
                    a.application_id,
                    a.startup_name,
                    ao.opportunity_title
                FROM applications a
                INNER JOIN application_opportunities ao
                    ON ao.opportunity_id = a.opportunity_id
                WHERE a.application_id IN ($ids)
            ";
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $applicationInfo[(int)$row['application_id']] = $row;
                }
                $res->free();
            }
        }

        $assigned = 0;
        $skipped  = 0;
        $newAssignmentsByReviewer = [];

        foreach ($application_ids as $app_id) {
            foreach ($reviewer_ids as $rev_id) {
                $insert->bind_param('iii', $app_id, $rev_id, $current_user_id);
                if (!$insert->execute()) {
                    throw new RuntimeException('Execute failed (assign insert): ' . $insert->error);
                }

                if ($insert->affected_rows > 0) {
                    $assigned++;
                    if (isset($applicationInfo[$app_id])) {
                        $newAssignmentsByReviewer[$rev_id][] = $applicationInfo[$app_id];
                    }
                } else {
                    $skipped++;
                }
            }
        }
        $insert->close();

        $loginUrl  = buildLoginUrl('dashboard');
        $reviewUrl = buildReviewListUrl();

        $emailsSent = 0;
        $emailsFail = 0;

        foreach ($newAssignmentsByReviewer as $rev_id => $apps) {
            $reviewer = $reviewerInfo[$rev_id] ?? null;
            if (!$reviewer) {
                $emailsFail++;
                continue;
            }

            $toEmail = trim((string)($reviewer['email'] ?? ''));
            if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                $emailsFail++;
                continue;
            }

            $reviewerName = trim((string)($reviewer['full_name'] ?? 'Reviewer'));
            $subject = 'New application review assignment';

            $body = "Dear {$reviewerName},\n\n";
            $body .= "You have been assigned application(s) to review.\n\n";
            $body .= "Assigned applications:\n";

            foreach ($apps as $appRow) {
                $startup = (string)($appRow['startup_name'] ?? 'Unknown Startup');
                $program = (string)($appRow['opportunity_title'] ?? 'Unknown Program');
                $body .= "- {$startup} ({$program})\n";
            }

            $body .= "\nPlease log in using the link below to review your assigned applications:\n";
            $body .= $loginUrl . "\n\n";
            $body .= "Reviewer status page:\n";
            $body .= $reviewUrl . "\n\n";
            $body .= "Thank you,\n";
            $body .= "The Review Team";

            if (sendPlainEmail($toEmail, $subject, $body)) {
                $emailsSent++;
            } else {
                $emailsFail++;
            }
        }

        $_SESSION['success'] =
            $assigned . ' assignment(s) created.'
            . ($skipped ? ' ' . $skipped . ' duplicate(s) skipped.' : '')
            . ($assigned ? ' ' . $emailsSent . ' notification email(s) sent.' : '')
            . ($emailsFail ? ' ' . $emailsFail . ' email(s) failed.' : '');

        redirectTo('assign');
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 2) SEND REMINDERS                                                     */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'send_reminders') {
        $assignment_ids = postIntArray('assignment_ids');

        if (empty($assignment_ids)) {
            $_SESSION['error'] = 'No assignments selected for reminder.';
            redirectTo('status');
        }

        $fetch = $conn->prepare("
            SELECT
                ra.assignment_id,
                u.full_name AS reviewer_name,
                u.email AS reviewer_email,
                ap.startup_name,
                ao.opportunity_title
            FROM reviewer_assignments ra
            INNER JOIN users u
                ON u.user_id = ra.reviewer_id
            INNER JOIN applications ap
                ON ap.application_id = ra.application_id
            INNER JOIN application_opportunities ao
                ON ao.opportunity_id = ap.opportunity_id
            LEFT JOIN application_reviews ar
                ON ar.application_id = ra.application_id
               AND ar.reviewer_id = ra.reviewer_id
            WHERE ra.assignment_id = ?
              AND ar.review_id IS NULL
            LIMIT 1
        ");
        if (!$fetch) {
            throw new RuntimeException('Prepare failed (fetch reminder): ' . $conn->error);
        }

        $upd = $conn->prepare("
            UPDATE reviewer_assignments
            SET reminder_sent_at = NOW(),
                reminder_count = reminder_count + 1
            WHERE assignment_id = ?
        ");
        if (!$upd) {
            throw new RuntimeException('Prepare failed (update reminder): ' . $conn->error);
        }

        $sent = 0;
        $fail = 0;

        $loginUrl  = buildLoginUrl('dashboard');
        $reviewUrl = buildReviewListUrl();

        foreach ($assignment_ids as $aid) {
            $fetch->bind_param('i', $aid);
            $fetch->execute();
            $result = $fetch->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }

            if (!$row) {
                continue;
            }

            $subject = 'Reminder: Please review assigned application';
            $body  = "Dear {$row['reviewer_name']},\n\n";
            $body .= "This is a reminder that you have a pending application review.\n\n";
            $body .= "Startup: {$row['startup_name']}\n";
            $body .= "Program: {$row['opportunity_title']}\n\n";
            $body .= "Please log in using the link below to review your assigned applications:\n";
            $body .= $loginUrl . "\n\n";
            $body .= "Reviewer status page:\n";
            $body .= $reviewUrl . "\n\n";
            $body .= "Thank you,\n";
            $body .= "The Review Team";

            $ok = sendPlainEmail((string)$row['reviewer_email'], $subject, $body);

            $upd->bind_param('i', $aid);
            $upd->execute();

            if ($ok) {
                $sent++;
            } else {
                $fail++;
            }
        }

        $fetch->close();
        $upd->close();

        $_SESSION['success'] = 'Reminder(s) dispatched to ' . $sent . ' reviewer(s).'
            . ($fail ? ' ' . $fail . ' email(s) failed.' : '');

        redirectTo('status');
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 3) REMOVE ASSIGNMENT                                                  */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'remove_assignment') {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);

        if ($assignment_id <= 0) {
            $_SESSION['error'] = 'Invalid assignment ID.';
            redirectTo('status');
        }

        $chk = $conn->prepare("
            SELECT 1
            FROM reviewer_assignments ra
            INNER JOIN application_reviews ar
                ON ar.application_id = ra.application_id
               AND ar.reviewer_id = ra.reviewer_id
            WHERE ra.assignment_id = ?
            LIMIT 1
        ");
        if (!$chk) {
            throw new RuntimeException('Prepare failed (check remove): ' . $conn->error);
        }

        $chk->bind_param('i', $assignment_id);
        $chk->execute();
        $res = $chk->get_result();
        $hasReview = $res ? (bool)$res->fetch_row() : false;
        if ($res) {
            $res->free();
        }
        $chk->close();

        if ($hasReview) {
            $_SESSION['error'] = 'Cannot remove — a review has already been submitted for this assignment.';
            redirectTo('status');
        }

        $del = $conn->prepare('DELETE FROM reviewer_assignments WHERE assignment_id = ? LIMIT 1');
        if (!$del) {
            throw new RuntimeException('Prepare failed (delete assignment): ' . $conn->error);
        }

        $del->bind_param('i', $assignment_id);
        $del->execute();
        $del->close();

        $_SESSION['success'] = 'Reviewer assignment removed.';
        redirectTo('status');
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 4) SAVE SINGLE CRITERION                                              */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'save_criteria') {
        $criteria_id       = (int)($_POST['criteria_id'] ?? 0);
        $criteriaRedirect  = criteriaRedirectParams();
        $category          = cleanText($_POST['category'] ?? '');
        $question          = cleanText($_POST['question'] ?? '');
        $description       = cleanText($_POST['description'] ?? '');
        $max_score         = clampFloat((float)($_POST['max_score'] ?? 10), 0, 100000);
        $weight            = clampFloat((float)($_POST['weight'] ?? 1), 0.1, 100);
        $is_active         = normalizeBoolFlag('is_active', 1);
        $review_type_id    = normalizeNullableReviewTypeId('review_type_id');

        if ($category === '' || $question === '') {
            $_SESSION['error'] = 'Category and Question are required.';
            redirectTo('criteria', $criteriaRedirect + ($criteria_id > 0 ? ['edit_criteria' => $criteria_id] : []));
        }

        if (!isValidReviewType($conn, $review_type_id, false)) {
            $_SESSION['error'] = 'Selected review type does not exist.';
            redirectTo('criteria', $criteriaRedirect + ($criteria_id > 0 ? ['edit_criteria' => $criteria_id] : []));
        }

        if ($criteria_id > 0) {
            $stmt = $conn->prepare("
                UPDATE review_criteria
                SET category = ?,
                    question = ?,
                    description = ?,
                    max_score = ?,
                    weight = ?,
                    is_active = ?,
                    review_type_id = ?,
                    updated_at = NOW()
                WHERE criteria_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                throw new RuntimeException('Prepare failed (criteria update): ' . $conn->error);
            }

            $stmt->bind_param(
                'sssddiii',
                $category,
                $question,
                $description,
                $max_score,
                $weight,
                $is_active,
                $review_type_id,
                $criteria_id
            );
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Scoring criterion updated.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO review_criteria
                    (review_type_id, category, question, description, max_score, weight, is_active, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            if (!$stmt) {
                throw new RuntimeException('Prepare failed (criteria insert): ' . $conn->error);
            }

            $stmt->bind_param(
                'isssddii',
                $review_type_id,
                $category,
                $question,
                $description,
                $max_score,
                $weight,
                $is_active,
                $current_user_id
            );
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'New scoring criterion added.';
        }

        redirectTo('criteria', $criteriaRedirect);
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 5) SAVE CRITERIA BUNDLE                                               */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'save_criteria_bundle') {
        $criteriaRedirect      = criteriaRedirectParams();
        $bundle_review_type_id = normalizeNullableReviewTypeId('bundle_review_type_id');
        $bundle_is_active      = normalizeBoolFlag('bundle_is_active', 1);
        $categories            = $_POST['categories'] ?? [];

        if (!is_array($categories) || empty($categories)) {
            $_SESSION['error'] = 'Please add at least one category with one question.';
            redirectTo('criteria', $criteriaRedirect);
        }

        if (!isValidReviewType($conn, $bundle_review_type_id, false)) {
            $_SESSION['error'] = 'Selected review type does not exist.';
            redirectTo('criteria', $criteriaRedirect);
        }

        $conn->begin_transaction();

        try {
            $insert = $conn->prepare("
                INSERT INTO review_criteria
                    (review_type_id, category, question, description, max_score, weight, is_active, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            if (!$insert) {
                throw new RuntimeException('Prepare failed (criteria bundle insert): ' . $conn->error);
            }

            $savedCount   = 0;
            $skippedCount = 0;

            foreach ($categories as $categoryBlock) {
                if (!is_array($categoryBlock)) {
                    $skippedCount++;
                    continue;
                }

                $categoryName = cleanText($categoryBlock['category'] ?? '');
                $questions    = $categoryBlock['questions'] ?? [];

                if ($categoryName === '' || !is_array($questions) || empty($questions)) {
                    $skippedCount++;
                    continue;
                }

                foreach ($questions as $questionRow) {
                    if (!is_array($questionRow)) {
                        $skippedCount++;
                        continue;
                    }

                    $question    = cleanText($questionRow['question'] ?? '');
                    $description = cleanText($questionRow['description'] ?? '');
                    $max_score   = clampFloat((float)($questionRow['max_score'] ?? 0), 0, 100000);
                    $weight      = clampFloat((float)($questionRow['weight'] ?? 1), 0.1, 100);

                    if ($question === '') {
                        $skippedCount++;
                        continue;
                    }

                    $insert->bind_param(
                        'isssddii',
                        $bundle_review_type_id,
                        $categoryName,
                        $question,
                        $description,
                        $max_score,
                        $weight,
                        $bundle_is_active,
                        $current_user_id
                    );

                    if (!$insert->execute()) {
                        throw new RuntimeException('Execute failed (criteria bundle insert): ' . $insert->error);
                    }

                    $savedCount++;
                }
            }

            $insert->close();

            if ($savedCount <= 0) {
                throw new RuntimeException('No valid category questions were submitted.');
            }

            $conn->commit();

            $_SESSION['success'] = $savedCount . ' question(s) saved successfully.'
                . ($skippedCount > 0 ? ' ' . $skippedCount . ' row(s) skipped.' : '');
        } catch (Throwable $bundleError) {
            $conn->rollback();
            throw $bundleError;
        }

        redirectTo('criteria', $criteriaRedirect);
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 6) SAVE MULTIPLE CRITERIA (LEGACY MODE)                               */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'save_multiple_criteria') {
        $rows                 = $_POST['criteria_rows'] ?? [];
        $criteriaRedirect     = criteriaRedirectParams();
        $default_review_type_id = normalizeNullableReviewTypeId('default_review_type_id');
        $default_is_active      = normalizeBoolFlag('default_is_active', 1);

        if (!is_array($rows) || empty($rows)) {
            $_SESSION['error'] = 'Please add at least one criterion.';
            redirectTo('criteria', $criteriaRedirect);
        }

        if (!isValidReviewType($conn, $default_review_type_id, false)) {
            $_SESSION['error'] = 'Selected default review type does not exist.';
            redirectTo('criteria', $criteriaRedirect);
        }

        $stmt = $conn->prepare("
            INSERT INTO review_criteria
                (review_type_id, category, question, description, max_score, weight, is_active, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        if (!$stmt) {
            throw new RuntimeException('Prepare failed (bulk criteria insert): ' . $conn->error);
        }

        $saved   = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            $category    = cleanText($row['category'] ?? '');
            $question    = cleanText($row['question'] ?? '');
            $description = cleanText($row['description'] ?? '');
            $max_score   = clampFloat((float)($row['max_score'] ?? 10), 0, 100000);
            $weight      = clampFloat((float)($row['weight'] ?? 1), 0.1, 100);

            $row_status_raw = isset($row['is_active']) ? (int)$row['is_active'] : $default_is_active;
            $is_active = in_array($row_status_raw, [0, 1], true) ? $row_status_raw : $default_is_active;

            $row_rt_raw = isset($row['review_type_id']) ? trim((string)$row['review_type_id']) : '';
            $review_type_id = ($row_rt_raw !== '' && (int)$row_rt_raw > 0)
                ? (int)$row_rt_raw
                : $default_review_type_id;

            if ($category === '' || $question === '') {
                $skipped++;
                continue;
            }

            if (!isValidReviewType($conn, $review_type_id, false)) {
                $skipped++;
                continue;
            }

            $stmt->bind_param(
                'isssddii',
                $review_type_id,
                $category,
                $question,
                $description,
                $max_score,
                $weight,
                $is_active,
                $current_user_id
            );

            if ($stmt->execute()) {
                $saved++;
            } else {
                $skipped++;
            }
        }

        $stmt->close();

        if ($saved > 0) {
            $_SESSION['success'] = $saved . ' criteria saved successfully.'
                . ($skipped > 0 ? ' ' . $skipped . ' row(s) skipped.' : '');
        } else {
            $_SESSION['error'] = 'No valid criteria were submitted.';
        }

        redirectTo('criteria', $criteriaRedirect);
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 7) DELETE CRITERION                                                   */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'delete_criteria') {
        $criteria_id      = (int)($_POST['criteria_id'] ?? 0);
        $criteriaRedirect = criteriaRedirectParams();

        if ($criteria_id <= 0) {
            $_SESSION['error'] = 'Invalid criterion ID.';
            redirectTo('criteria', $criteriaRedirect);
        }

        $stmt = $conn->prepare('DELETE FROM review_criteria WHERE criteria_id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Prepare failed (delete criteria): ' . $conn->error);
        }

        $stmt->bind_param('i', $criteria_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = 'Criterion deleted.';
        redirectTo('criteria', $criteriaRedirect);
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 8) SAVE REVIEW TYPE                                                   */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'save_review_type') {
        $review_type_id       = (int)($_POST['review_type_id'] ?? 0);
        $name                 = cleanText($_POST['name'] ?? '');
        $description          = cleanText($_POST['description'] ?? '');
        $sort_order           = clampInt((int)($_POST['sort_order'] ?? 0), 0, 9999);
        $is_active            = normalizeBoolFlag('is_active', 1);
        $reviewTypeRedirect   = reviewTypeRedirectParams();

        if ($name === '') {
            $_SESSION['error'] = 'Review type name is required.';
            redirectTo('review_types', $reviewTypeRedirect + ($review_type_id > 0 ? ['edit_review_type' => $review_type_id] : []));
        }

        $chk = $conn->prepare("
            SELECT review_type_id
            FROM review_types
            WHERE name = ?
              AND review_type_id != ?
            LIMIT 1
        ");
        if (!$chk) {
            throw new RuntimeException('Prepare failed (duplicate review type): ' . $conn->error);
        }

        $chk->bind_param('si', $name, $review_type_id);
        $chk->execute();
        $chk->store_result();
        $duplicate = $chk->num_rows > 0;
        $chk->close();

        if ($duplicate) {
            $_SESSION['error'] = 'A review type named "' . $name . '" already exists.';
            redirectTo('review_types', $reviewTypeRedirect + ($review_type_id > 0 ? ['edit_review_type' => $review_type_id] : []));
        }

        if ($review_type_id > 0) {
            $stmt = $conn->prepare("
                UPDATE review_types
                SET name = ?,
                    description = ?,
                    sort_order = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE review_type_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                throw new RuntimeException('Prepare failed (update review type): ' . $conn->error);
            }

            $stmt->bind_param('ssiii', $name, $description, $sort_order, $is_active, $review_type_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Review type "' . $name . '" updated.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO review_types
                    (name, description, sort_order, is_active, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            if (!$stmt) {
                throw new RuntimeException('Prepare failed (insert review type): ' . $conn->error);
            }

            $stmt->bind_param('ssiii', $name, $description, $sort_order, $is_active, $current_user_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Review type "' . $name . '" added.';
        }

        redirectTo('review_types');
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 9) TOGGLE REVIEW TYPE                                                 */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'toggle_review_type') {
        $review_type_id = (int)($_POST['review_type_id'] ?? 0);

        if ($review_type_id <= 0) {
            $_SESSION['error'] = 'Invalid review type ID.';
            redirectTo('review_types');
        }

        $stmt = $conn->prepare("
            UPDATE review_types
            SET is_active = NOT is_active,
                updated_at = NOW()
            WHERE review_type_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException('Prepare failed (toggle review type): ' . $conn->error);
        }

        $stmt->bind_param('i', $review_type_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = 'Review type status updated.';
        redirectTo('review_types');
    }

    /* ────────────────────────────────────────────────────────────────────── */
    /* 10) DELETE REVIEW TYPE                                                */
    /* ────────────────────────────────────────────────────────────────────── */
    if ($action === 'delete_review_type') {
        $review_type_id = (int)($_POST['review_type_id'] ?? 0);

        if ($review_type_id <= 0) {
            $_SESSION['error'] = 'Invalid review type ID.';
            redirectTo('review_types');
        }

        $chk = $conn->prepare("
            SELECT COUNT(*)
            FROM application_reviews
            WHERE review_type_id = ?
        ");
        if (!$chk) {
            throw new RuntimeException('Prepare failed (check review type use): ' . $conn->error);
        }

        $chk->bind_param('i', $review_type_id);
        $chk->execute();
        $chk->bind_result($use_count);
        $chk->fetch();
        $chk->close();

        if ((int)$use_count > 0) {
            $_SESSION['error'] = 'Cannot delete — this review type is referenced by ' . (int)$use_count . ' review(s). Deactivate it instead.';
            redirectTo('review_types');
        }

        $stmt = $conn->prepare('DELETE FROM review_types WHERE review_type_id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Prepare failed (delete review type): ' . $conn->error);
        }

        $stmt->bind_param('i', $review_type_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = 'Review type deleted.';
        redirectTo('review_types');
    }

    $_SESSION['error'] = 'Unknown action "' . $action . '".';
    redirectTo('assign');

} catch (Throwable $e) {
    error_log('process_manage_reviewers error [' . $action . ']: ' . $e->getMessage());

    $_SESSION['error'] = 'Action failed. Please try again.';

    if (in_array($action, ['save_criteria', 'save_criteria_bundle', 'save_multiple_criteria', 'delete_criteria'], true)) {
        redirectTo('criteria', criteriaRedirectParams());
    }

    if (in_array($action, ['save_review_type', 'toggle_review_type', 'delete_review_type'], true)) {
        redirectTo('review_types', reviewTypeRedirectParams());
    }

    if (in_array($action, ['send_reminders', 'remove_assignment'], true)) {
        redirectTo('status');
    }

    redirectTo('assign');
}