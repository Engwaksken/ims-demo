<?php
declare(strict_types=1);

/*
 * Monday.com task import.
 *
 * A Monday.com Excel export is not a flat table. It looks like this:
 *
 *   Board name
 *   (optional board description)
 *   Group title                      <- one cell only
 *   Name | Subitems | Person | Status | Date | ...   <- header, repeated per group
 *   Item | ...
 *        | Subitems | Owner | Status | Date          <- subitem header (column A empty)
 *        | Subitem  | ...                           <- subitem rows (column A empty)
 *   Item | ...
 *   (blank)
 *   Next group title
 *   Name | ...
 *
 * The importer walks the sheet row by row, re-reads every header it meets,
 * skips board/group title rows, and imports items and subitems as tasks.
 * Plain CSV/Excel sheets with a single header row work the same way.
 */

if (!function_exists('task_import_normalize')) {
    function task_import_normalize(mixed $value): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', trim((string)$value)));
    }
}

if (!function_exists('task_import_column_aliases')) {
    /** Header aliases per field, in priority order. */
    function task_import_column_aliases(): array
    {
        return [
            'title' => ['Task', 'Task Name', 'Task Title', 'Name', 'Item', 'Item Name', 'Title', 'Subitem Name', 'Subitems', 'Subitem'],
            'details' => ['Description', 'Details', 'Notes', 'Note', 'Task Notes', 'Text', 'Comments', 'Update', 'Updates'],
            'due' => ['Due Date Time', 'Due Date & Time', 'Due Date', 'Deadline', 'Due', 'Target Date', 'Date', 'End Date'],
            'timeline' => ['Timeline', 'Timeline End'],
            'time' => ['Due Time', 'Time'],
            'owner' => ['Owner', 'Person', 'People', 'Assignee', 'Assignees', 'Assigned To', 'Assigned', 'Responsible', 'Owners', 'Team Member'],
            'status' => ['Status', 'Task Status', 'Progress'],
            'frequency' => ['Frequency', 'Cadence'],
            'recurring' => ['Recurring', 'Repeat', 'Is Recurring'],
            'days' => ['Repeat On', 'Weekdays', 'Days', 'Days Of Week', 'Recurrence Days'],
            'end' => ['Repeat Until', 'Recurrence End Date'],
            'reminder' => ['Reminder Date Time', 'Reminder At', 'Reminder', 'Reminder Date'],
            'reminderTime' => ['Reminder Time'],
            'kpi' => ['KPI', 'KPI Title', 'Linked KPI'],
            'kra' => ['KRA', 'KRA Title', 'Linked KRA'],
        ];
    }
}

if (!function_exists('task_import_is_header_row')) {
    /** A header has a task-name column plus one other known column, or two known non-name columns. */
    function task_import_is_header_row(array $row): bool
    {
        $cells = array_filter(array_map('task_import_normalize', $row), static fn(string $cell): bool => $cell !== '');
        if (count($cells) < 2) return false;
        $titleHit = false;
        $otherFields = [];
        foreach (task_import_column_aliases() as $field => $aliases) {
            $keys = array_map('task_import_normalize', $aliases);
            if (!array_intersect($keys, $cells)) continue;
            if ($field === 'title') $titleHit = true;
            else $otherFields[$field] = true;
        }
        return ($titleHit && count($otherFields) >= 1) || count($otherFields) >= 2;
    }
}

if (!function_exists('task_import_map_columns')) {
    /** Map each field to a column index for one header row. */
    function task_import_map_columns(array $headerRow): array
    {
        $headers = [];
        foreach ($headerRow as $column => $value) {
            $key = task_import_normalize($value);
            if ($key !== '' && !array_key_exists($key, $headers)) $headers[$key] = (int)$column;
        }
        $columns = [];
        foreach (task_import_column_aliases() as $field => $aliases) {
            $columns[$field] = null;
            foreach ($aliases as $alias) {
                $key = task_import_normalize($alias);
                if (array_key_exists($key, $headers)) { $columns[$field] = $headers[$key]; break; }
            }
        }
        if ($columns['title'] === null) {
            // Some exports leave the item-name header blank: use the first unlabelled column.
            $known = [];
            foreach (task_import_column_aliases() as $aliases) foreach ($aliases as $alias) $known[task_import_normalize($alias)] = true;
            foreach ($headerRow as $column => $value) {
                if (!isset($known[task_import_normalize($value)])) { $columns['title'] = (int)$column; break; }
            }
        }
        return $columns;
    }
}

if (!function_exists('task_import_parse_datetime')) {
    /**
     * Parse a spreadsheet date value. Returns [DateTimeImmutable, bool $hasTime] or null.
     * Accepts Excel serials, DateTime objects, ISO dates, day-first dates (03/11/2026),
     * month names (Nov 3, 2026), 12/24-hour times and Monday.com timelines ("A - B", uses B).
     */
    function task_import_parse_datetime(mixed $value): ?array
    {
        if ($value === null) return null;
        if ($value instanceof DateTimeInterface) {
            $dateTime = DateTimeImmutable::createFromInterface($value);
            return [$dateTime, $dateTime->format('H:i:s') !== '00:00:00'];
        }
        $text = trim((string)$value);
        if ($text === '') return null;

        if (is_numeric($text)) {
            $serial = (float)$text;
            if ($serial < 1 || $serial > 2958465) return null;
            if (!class_exists(\PhpOffice\PhpSpreadsheet\Shared\Date::class)) return null;
            $dateTime = DateTimeImmutable::createFromMutable(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($serial));
            return [$dateTime, $serial !== floor($serial)];
        }

        $text = (string)preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text));
        $parts = preg_split('/\s+(?:-|–|—|to)\s+/iu', $text);
        if ($parts && count($parts) > 1) $text = trim((string)end($parts));
        $text = (string)preg_replace('/\s*(?:UTC|GMT|EAT)(?:[+-]\d{1,2}(?::?\d{2})?)?$/i', '', $text);
        $text = (string)preg_replace('/(\d)\s*(am|pm)$/i', '$1 $2', $text);
        $text = (string)preg_replace('/^(\d{4}-\d{2}-\d{2})T/', '$1 ', $text);

        $hasTime = (bool)preg_match('/\d{1,2}:\d{2}|\d\s?(?:am|pm)\b/i', $text);
        $formats = [
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d g:i A', 'Y-m-d g A', 'Y-m-d',
            'Y/m/d H:i', 'Y/m/d',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y g:i A', 'd/m/Y',
            'd-m-Y H:i', 'd-m-Y', 'd.m.Y H:i', 'd.m.Y',
            'm/d/Y H:i', 'm/d/Y g:i A', 'm/d/Y',
            'd/m/y', 'm/d/y',
        ];
        foreach ($formats as $format) {
            $dateTime = DateTimeImmutable::createFromFormat('!' . $format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            $clean = $errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0);
            if ($dateTime !== false && $clean) return [$dateTime, $hasTime];
        }
        // Month names and anything else PHP understands, but never bare words such as "Done".
        if (!preg_match('/\d/', $text)) return null;
        try {
            return [new DateTimeImmutable($text), $hasTime];
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('task_import_parse_time')) {
    /** Parse a time-of-day cell ("16:00", "4 PM", Excel fraction). Returns [hour, minute] or null. */
    function task_import_parse_time(mixed $value): ?array
    {
        if ($value === null) return null;
        if ($value instanceof DateTimeInterface) return [(int)$value->format('H'), (int)$value->format('i')];
        $text = trim((string)$value);
        if ($text === '') return null;
        if (is_numeric($text)) {
            $fraction = (float)$text - floor((float)$text);
            $seconds = (int)round($fraction * 86400);
            return [intdiv($seconds, 3600) % 24, intdiv($seconds % 3600, 60)];
        }
        $text = (string)preg_replace('/(\d)\s*(am|pm)$/i', '$1 $2', $text);
        foreach (['H:i:s', 'H:i', 'g:i A', 'g A', 'G.i'] as $format) {
            $clock = DateTimeImmutable::createFromFormat('!' . $format, strtoupper($text));
            if ($clock !== false) return [(int)$clock->format('H'), (int)$clock->format('i')];
        }
        return null;
    }
}

if (!function_exists('task_import_name_tokens')) {
    function task_import_name_tokens(string $name): array
    {
        $clean = strtolower(trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name)));
        $tokens = $clean === '' ? [] : explode(' ', $clean);
        sort($tokens);
        return $tokens;
    }
}

if (!function_exists('task_import_match_user')) {
    /**
     * Match one person from a Monday.com People cell to a user.
     * $users: list of ['user_id' => int, 'full_name' => string, 'email' => string].
     * Tries email, exact name, the same names in any order, then a unique partial match
     * ("Allen" or "Allen Arinaitwe" against "Allen K. Arinaitwe").
     */
    function task_import_match_user(string $person, array $users): ?int
    {
        $person = trim($person);
        if ($person === '') return null;
        if (filter_var($person, FILTER_VALIDATE_EMAIL)) {
            foreach ($users as $user) {
                if (strcasecmp(trim((string)$user['email']), $person) === 0) return (int)$user['user_id'];
            }
            $person = (string)strstr($person, '@', true);
        }
        $wanted = task_import_name_tokens($person);
        if (!$wanted) return null;
        $partial = [];
        foreach ($users as $user) {
            $have = task_import_name_tokens((string)$user['full_name']);
            if (!$have) continue;
            if ($have === $wanted) return (int)$user['user_id'];
            if (!array_diff($wanted, $have)) $partial[] = (int)$user['user_id'];
        }
        return count($partial) === 1 ? $partial[0] : null;
    }
}

if (!function_exists('task_import_split_people')) {
    /** Split a People cell ("Allen Arinaitwe, Jane Doe") into names. */
    function task_import_split_people(mixed $value): array
    {
        $text = trim((string)$value);
        if ($text === '') return [];
        $names = preg_split('/\s*(?:[,;\n\r|]|\s&\s|\sand\s)\s*/iu', $text) ?: [];
        return array_values(array_filter(array_map('trim', $names), static fn(string $name): bool => $name !== ''));
    }
}

if (!function_exists('task_import_format_issues')) {
    /** Turn ['reason' => [rows...]] into short sentences. */
    function task_import_format_issues(array $issues): array
    {
        $lines = [];
        foreach ($issues as $reason => $rows) {
            $rows = array_values(array_unique($rows));
            $shown = array_slice($rows, 0, 10);
            $more = count($rows) - count($shown);
            $lines[] = $reason . ' (row' . (count($rows) === 1 ? ' ' : 's ') . implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '') . ').';
        }
        return $lines;
    }
}

if (!function_exists('task_import_extract_rows')) {
    /**
     * Walk a sheet and return the task rows as ['row' => sheet row number, 'cells' => field => value].
     * Skips the board title, group titles, headers and blank rows; subitems use their own header.
     */
    function task_import_extract_rows(array $rows): array
    {
        $rows = array_values($rows);
        $isBlank = static fn(mixed $value): bool => $value === null || trim((string)$value) === '';
        $nonEmptyCount = static fn(array $row): int => count(array_filter($row, static fn($value): bool => !($value === null || trim((string)$value) === '')));
        $headerFlags = array_map('task_import_is_header_row', $rows);
        if (!in_array(true, $headerFlags, true)) {
            // A plain sheet with only a task-name column still has a usable header.
            $titleKeys = array_map('task_import_normalize', task_import_column_aliases()['title']);
            foreach ($rows as $index => $row) {
                if (array_intersect($titleKeys, array_map('task_import_normalize', $row))) { $headerFlags[$index] = true; break; }
            }
        }
        if (!in_array(true, $headerFlags, true)) {
            throw new RuntimeException('Could not find the column headings. The sheet needs a Name (or Task) column, plus Person and Date columns where possible.');
        }

        $tasks = [];
        $mainColumns = null;
        $subColumns = null;
        $inSubitems = false;
        $rowTotal = count($rows);
        foreach ($rows as $index => $row) {
            if ($nonEmptyCount($row) === 0) continue;
            if ($headerFlags[$index]) {
                $columns = task_import_map_columns($row);
                if ($mainColumns !== null && $isBlank($row[0] ?? null) && ($columns['title'] ?? 0) > 0) {
                    $subColumns = $columns;
                    $inSubitems = true;
                } else {
                    $mainColumns = $columns;
                    $subColumns = null;
                    $inSubitems = false;
                }
                continue;
            }
            if ($mainColumns === null) continue; // board name / description above the first header

            if ($inSubitems && !$isBlank($row[0] ?? null)) $inSubitems = false; // back to a parent item
            if (!$inSubitems && $nonEmptyCount($row) === 1) {
                // A lone cell followed by a group header (or the end of the sheet) is a group title.
                for ($next = $index + 1; $next < $rowTotal && $nonEmptyCount($rows[$next]) === 0; $next++);
                if ($next >= $rowTotal || ($headerFlags[$next] && !$isBlank($rows[$next][0] ?? null))) continue;
            }

            $columns = $inSubitems ? $subColumns : $mainColumns;
            if ($columns['title'] === null || $isBlank($row[$columns['title']] ?? null)) continue;
            $cells = [];
            foreach ($columns as $field => $column) $cells[$field] = $column !== null ? ($row[$column] ?? null) : null;
            $tasks[] = ['row' => $index + 1, 'cells' => $cells];
        }
        return $tasks;
    }
}

if (!function_exists('task_import_monday_spreadsheet')) {
    /** Import a Monday.com export or a compatible Excel/CSV task sheet. */
    function task_import_monday_spreadsheet(mysqli $conn, array $upload, int $createdBy): array
    {
        $summary = ['created' => 0, 'skipped' => 0, 'errors' => [], 'notices' => []];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new RuntimeException('Choose a valid Excel or CSV file to upload.');
        }
        if ((int)($upload['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new RuntimeException('The file is too large. The maximum upload size is 15 MB.');
        }
        $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException('Use a .xlsx, .xls, or .csv export from Monday.com.');
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('Spreadsheet support is unavailable on this server. Install PhpSpreadsheet, then try again.');
        }

        try {
            $readerType = $extension === 'csv' ? 'Csv' : ($extension === 'xls' ? 'Xls' : 'Xlsx');
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
                if (!$reader->canRead((string)$upload['tmp_name'])) throw new RuntimeException('type mismatch');
            } catch (Throwable) {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile((string)$upload['tmp_name']);
            }
            $reader->setReadDataOnly(true);
            $book = $reader->load((string)$upload['tmp_name']);
            // Monday.com puts the board on the first sheet; fall back to the active one.
            $sheet = $book->getSheetCount() > 0 ? $book->getSheet(0) : $book->getActiveSheet();
            $rows = array_values($sheet->toArray(null, false, false, false));
        } catch (Throwable) {
            throw new RuntimeException('The spreadsheet could not be read. Export it as .xlsx or .csv and try again.');
        }

        $taskRows = task_import_extract_rows($rows);
        if (count($taskRows) > 2000) throw new RuntimeException('The import is limited to 2,000 task rows at a time.');
        $isBlank = static fn(mixed $value): bool => $value === null || trim((string)$value) === '';

        // Staff who can own tasks, loaded once and matched in PHP.
        $users = [];
        $userResult = $conn->query('SELECT user_id, full_name, email FROM users WHERE is_active=1 AND role NOT IN ("Member","Applicant","Donor/Partner")');
        if ($userResult) {
            while ($user = $userResult->fetch_assoc()) $users[] = $user;
            $userResult->free();
        }
        $peopleCache = [];
        $resolveAssignee = static function (mixed $value) use (&$peopleCache, $users): array {
            $names = task_import_split_people($value);
            foreach ($names as $name) {
                $key = strtolower($name);
                if (!array_key_exists($key, $peopleCache)) $peopleCache[$key] = task_import_match_user($name, $users);
                if ($peopleCache[$key] !== null) return [$peopleCache[$key], $names];
            }
            return [null, $names];
        };

        $resolveKpi = static function (string $kpiTitle, string $kraTitle, int $ownerId) use ($conn): ?array {
            if ($kpiTitle === '') return ['source' => null, 'id' => null, 'kra' => null];
            if ($kraTitle !== '') {
                $stmt = $conn->prepare('SELECT ak.kpi_id,ak.kra_id FROM appraisal_kpis ak JOIN appraisal_kras kr ON kr.kra_id=ak.kra_id JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE pa.user_id=? AND LOWER(ak.title)=LOWER(?) AND LOWER(kr.title)=LOWER(?) LIMIT 1');
                $stmt->bind_param('iss', $ownerId, $kpiTitle, $kraTitle); $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
                return $row ? ['source' => 'appraisal', 'id' => (int)$row['kpi_id'], 'kra' => (int)$row['kra_id']] : null;
            }
            $stmt = $conn->prepare('SELECT kpi_id FROM kpis WHERE user_id=? AND LOWER(kpi_title)=LOWER(?) LIMIT 1');
            $stmt->bind_param('is', $ownerId, $kpiTitle); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($row) return ['source' => 'employee', 'id' => (int)$row['kpi_id'], 'kra' => null];
            $stmt = $conn->prepare('SELECT ak.kpi_id,ak.kra_id FROM appraisal_kpis ak JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE pa.user_id=? AND LOWER(ak.title)=LOWER(?) LIMIT 1');
            $stmt->bind_param('is', $ownerId, $kpiTitle); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            return $row ? ['source' => 'appraisal', 'id' => (int)$row['kpi_id'], 'kra' => (int)$row['kra_id']] : null;
        };

        $errors = [];
        $notices = [];
        $skip = static function (string $reason, int $rowNumber) use (&$errors, &$summary): void {
            $summary['skipped']++;
            $errors[$reason][] = $rowNumber;
        };
        $note = static function (string $reason, int $rowNumber) use (&$notices): void {
            $notices[$reason][] = $rowNumber;
        };

        $weekdayNumbers = ['mon'=>1,'monday'=>1,'tue'=>2,'tues'=>2,'tuesday'=>2,'wed'=>3,'wednesday'=>3,'thu'=>4,'thur'=>4,'thurs'=>4,'thursday'=>4,'fri'=>5,'friday'=>5,'sat'=>6,'saturday'=>6,'sun'=>7,'sunday'=>7];
        $insert = $conn->prepare('INSERT INTO employee_tasks (title,details,assigned_to,created_by,kpi_source,kpi_id,kra_id,task_frequency,task_date,task_due_at,status,is_recurring,recurrence_days,recurrence_end_date,reminder_at,reminder_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$insert) throw new RuntimeException('Could not prepare the task import: ' . $conn->error);

        $today = new DateTimeImmutable('today');
        $assignedNotifications = [];
        $conn->begin_transaction();
        try {
            foreach ($taskRows as ['row' => $rowNumber, 'cells' => $cells]) {
                $cell = static fn(string $field): mixed => $cells[$field] ?? null;
                $text = static fn(string $field): string => trim((string)$cell($field));

                $title = (string)preg_replace('/\s+/u', ' ', $text('title'));
                if ($title === '') continue;
                if (mb_strlen($title) > 240) { $skip('Task name is longer than 240 characters', $rowNumber); continue; }
                $details = $text('details');

                [$ownerId, $peopleNames] = $resolveAssignee($cell('owner'));
                if ($ownerId === null) {
                    $ownerId = $createdBy;
                    if ($peopleNames) $note('Assigned to you because "' . implode(', ', $peopleNames) . '" did not match a staff account', $rowNumber);
                }

                $dueRaw = $isBlank($cell('due')) ? $cell('timeline') : $cell('due');
                $parsedDue = task_import_parse_datetime($dueRaw);
                if ($parsedDue === null && !$isBlank($dueRaw)) {
                    $skip('Date "' . mb_substr(trim((string)$dueRaw), 0, 40) . '" was not understood; use a format like 2026-11-04 or 04/11/2026', $rowNumber);
                    continue;
                }
                if ($parsedDue === null) {
                    [$due, $hasTime] = [$today, false];
                    $note('No due date, so it was set to today', $rowNumber);
                } else {
                    [$due, $hasTime] = $parsedDue;
                }
                $clock = task_import_parse_time($cell('time'));
                if ($clock !== null) $due = $due->setTime($clock[0], $clock[1]);
                elseif (!$hasTime) $due = $due->setTime($parsedDue === null ? 17 : 9, 0);

                $statusText = strtolower($text('status'));
                $status = in_array($statusText, ['done', 'complete', 'completed', 'closed', 'finished'], true) ? 'Completed'
                    : (in_array($statusText, ['working on it', 'in progress', 'started', 'doing', 'ongoing'], true) ? 'In Progress' : 'Pending');
                $frequency = str_contains(strtolower($text('frequency')), 'week') ? 'Weekly' : 'Daily';

                $daysText = strtolower($text('days'));
                $recurringText = strtolower($text('recurring'));
                $isRecurring = in_array($recurringText, ['1', 'yes', 'true', 'y', 'recurring'], true) || $daysText !== '';
                $days = [];
                foreach ($daysText !== '' ? (preg_split('/[,;|\/\s]+/', $daysText) ?: []) : [] as $dayText) {
                    $key = trim($dayText);
                    if (isset($weekdayNumbers[$key])) $days[] = $weekdayNumbers[$key];
                    elseif (preg_match('/\b(mon|tue|wed|thu|fri|sat|sun)/', $key, $match)) $days[] = $weekdayNumbers[$match[1]];
                }
                $days = array_values(array_unique($days)); sort($days);
                if ($isRecurring && !$days) { $skip('Recurring task needs weekday names in a Repeat On or Weekdays column', $rowNumber); continue; }

                $kpi = $resolveKpi($text('kpi'), $text('kra'), $ownerId);
                if ($kpi === null) { $skip('Linked KPI/KRA was not found for the assignee', $rowNumber); continue; }

                $endRaw = $cell('end');
                $endParsed = task_import_parse_datetime($endRaw);
                if ($isRecurring && !$isBlank($endRaw) && $endParsed === null) { $skip('Repeat Until date was not understood', $rowNumber); continue; }

                $reminderRaw = $cell('reminder');
                $reminderClock = task_import_parse_time($cell('reminderTime'));
                $reminderDateTime = null;
                $reminderParsed = task_import_parse_datetime($reminderRaw);
                if ($reminderParsed !== null) {
                    $reminderDateTime = $reminderParsed[0];
                    if ($reminderClock !== null) $reminderDateTime = $reminderDateTime->setTime($reminderClock[0], $reminderClock[1]);
                } elseif ($reminderClock !== null) {
                    $reminderDateTime = $due->setTime($reminderClock[0], $reminderClock[1]);
                }

                $title = mb_substr($title, 0, 240);
                $kpiSource = $kpi['source']; $kpiId = $kpi['id']; $kraId = $kpi['kra'];
                $taskDate = $due->format('Y-m-d');
                $taskDueAt = $due->format('Y-m-d H:i:s');
                $isRecurringInt = $isRecurring ? 1 : 0;
                $recurrenceDays = $isRecurring ? implode(',', $days) : null;
                $recurrenceEndDate = $endParsed ? $endParsed[0]->format('Y-m-d') : null;
                $reminderAt = !$isRecurring && $reminderDateTime ? $reminderDateTime->format('Y-m-d H:i:s') : null;
                $reminderTime = $isRecurring && $reminderDateTime ? $reminderDateTime->format('H:i:s') : null;

                $insert->bind_param('ssiisiissssissss', $title, $details, $ownerId, $createdBy, $kpiSource, $kpiId, $kraId, $frequency, $taskDate, $taskDueAt, $status, $isRecurringInt, $recurrenceDays, $recurrenceEndDate, $reminderAt, $reminderTime);
                if ($insert->execute()) {
                    $summary['created']++;
                    $taskId = (int)$conn->insert_id;
                    if ($ownerId !== $createdBy) $assignedNotifications[] = [$ownerId, $title, $taskDueAt, $taskId];
                } else {
                    $skip('Database error: ' . $insert->error, $rowNumber);
                }
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $insert->close();
            throw new RuntimeException('Task import failed: ' . $exception->getMessage());
        }
        $insert->close();

        $summary['errors'] = task_import_format_issues($errors);
        $summary['notices'] = task_import_format_issues($notices);
        if ($summary['created'] === 0 && $summary['skipped'] === 0) {
            $summary['errors'][] = 'No task rows were found under the column headings.';
        }

        foreach ($assignedNotifications as [$ownerId, $title, $taskDueAt, $taskId]) {
            if (function_exists('notify_user')) notify_user($ownerId, 'A task was assigned to you', $title . ' · Due ' . $taskDueAt, 'info', $taskId, 'employee_task');
        }
        if (function_exists('task_generate_recurring_occurrences')) task_generate_recurring_occurrences($conn);
        return $summary;
    }
}
