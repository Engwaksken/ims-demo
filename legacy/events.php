<?php
declare(strict_types=1);

$page_title = 'Events Management';

include 'includes/header.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google-calendar-service.php';

check_role(IMS_PROGRAMME_ROLES);

if (
    !isset($conn)
    ||
    !($conn instanceof mysqli)
) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('ev_h')) {
    function ev_h(mixed $value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


function ev_column_exists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $result = $conn->query(
        "SHOW COLUMNS FROM `"
        . $conn->real_escape_string($table)
        . "` LIKE '"
        . $conn->real_escape_string($column)
        . "'"
    );

    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;

    $result->close();

    return $exists;
}


function ev_bind(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if ($types === '' || !$params) {
        return;
    }

    $refs = [$types];

    foreach ($params as $key => &$value) {
        $refs[] = &$value;
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $refs
    );
}


function ev_query(
    array $changes = []
): string {
    $params = $_GET;

    foreach ($changes as $key => $value) {
        if (
            $value === ''
            ||
            $value === null
        ) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return http_build_query($params);
}


function ev_google_date(
    array $event,
    string $field
): string {
    $value = $event[$field] ?? [];

    if (!is_array($value)) {
        return '';
    }

    if (!empty($value['date'])) {
        return substr(
            (string)$value['date'],
            0,
            10
        );
    }

    if (!empty($value['dateTime'])) {
        return substr(
            (string)$value['dateTime'],
            0,
            10
        );
    }

    return '';
}


function ev_google_time(
    array $event,
    string $field
): string {
    $value = $event[$field] ?? [];

    if (
        !is_array($value)
        ||
        empty($value['dateTime'])
    ) {
        return '';
    }

    $timestamp = strtotime(
        (string)$value['dateTime']
    );

    if (!$timestamp) {
        return '';
    }

    return date(
        'H:i:s',
        $timestamp
    );
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$activeTab = trim(
    (string)($_GET['tab'] ?? 'calendar')
);

if (
    !in_array(
        $activeTab,
        [
            'calendar',
            'table',
        ],
        true
    )
) {
    $activeTab = 'calendar';
}


$filterYear = (int)(
    $_GET['year']
    ?? $currentYear
);

$filterMonth = (int)(
    $_GET['month']
    ?? $currentMonth
);

$filterMonth = max(
    0,
    min(
        12,
        $filterMonth
    )
);

$filterType = trim(
    (string)(
        $_GET['type']
        ?? ''
    )
);

$filterStatus = trim(
    (string)(
        $_GET['event_status']
        ?? ''
    )
);

$filterSource = trim(
    (string)(
        $_GET['source']
        ?? 'all'
    )
);

if (
    !in_array(
        $filterSource,
        [
            'all',
            'ims',
            'google',
        ],
        true
    )
) {
    $filterSource = 'all';
}

$search = trim(
    (string)(
        $_GET['search']
        ?? ''
    )
);


/*
|--------------------------------------------------------------------------
| PROJECTS / PROGRAMS
|--------------------------------------------------------------------------
*/

$projects = [];

$result = $conn->query("
    SELECT
        project_id,
        project_code,
        project_name
    FROM projects
    ORDER BY project_name
");

if ($result) {
    while (
        $row = $result->fetch_assoc()
    ) {
        $projects[] = $row;
    }

    $result->close();
}


$programs = [];

$result = $conn->query("
    SELECT
        id,
        program_name
    FROM programs
    ORDER BY program_name
");

if ($result) {
    while (
        $row = $result->fetch_assoc()
    ) {
        $programs[] = $row;
    }

    $result->close();
}


/*
|--------------------------------------------------------------------------
| OPTIONAL GOOGLE COLUMNS
|--------------------------------------------------------------------------
*/

$googleEventSelect =
    ev_column_exists(
        $conn,
        'hub_events',
        'google_event_id'
    )
        ? 'e.google_event_id'
        : 'NULL AS google_event_id';


$googleLinkSelect =
    ev_column_exists(
        $conn,
        'hub_events',
        'google_html_link'
    )
        ? 'e.google_html_link'
        : 'NULL AS google_html_link';


$googleStatusSelect =
    ev_column_exists(
        $conn,
        'hub_events',
        'google_sync_status'
    )
        ? 'e.google_sync_status'
        : "'Not Synced' AS google_sync_status";


$googleErrorSelect =
    ev_column_exists(
        $conn,
        'hub_events',
        'google_sync_error'
    )
        ? 'e.google_sync_error'
        : 'NULL AS google_sync_error';


$meetingLinkSelect =
    ev_column_exists(
        $conn,
        'hub_events',
        'meeting_link'
    )
        ? 'e.meeting_link'
        : 'NULL AS meeting_link';


$eventDaysSelect =
    ev_column_exists(
        $conn,
        'hub_events',
        'event_days'
    )
        ? 'e.event_days'
        : 'NULL AS event_days';


/*
|--------------------------------------------------------------------------
| IMS EVENTS
|--------------------------------------------------------------------------
*/

$where = [
    'YEAR(e.event_date) = ?',
];

$params = [
    $filterYear,
];

$bindTypes = 'i';


if ($filterMonth > 0) {
    $where[] =
        'MONTH(e.event_date) = ?';

    $params[] =
        $filterMonth;

    $bindTypes .= 'i';
}


if ($filterType !== '') {
    $where[] =
        'e.event_type = ?';

    $params[] =
        $filterType;

    $bindTypes .= 's';
}


if ($filterStatus !== '') {
    $where[] =
        'e.event_status = ?';

    $params[] =
        $filterStatus;

    $bindTypes .= 's';
}


if ($search !== '') {
    $where[] = "
        (
            e.event_title LIKE ?
            OR e.venue LIKE ?
            OR e.organizer LIKE ?
            OR e.description LIKE ?
        )
    ";

    $searchTerm =
        '%'
        . $search
        . '%';

    for (
        $i = 0;
        $i < 4;
        $i++
    ) {
        $params[] =
            $searchTerm;
    }

    $bindTypes .=
        'ssss';
}


$sql = "
    SELECT
        e.*,

        {$googleEventSelect},
        {$googleLinkSelect},
        {$googleStatusSelect},
        {$googleErrorSelect},
        {$meetingLinkSelect},
        {$eventDaysSelect},

        p.project_name,
        p.project_code,

        pr.program_name,

        (
            SELECT COUNT(*)
            FROM event_registrations er
            WHERE er.event_id = e.event_id
              AND COALESCE(
                    er.registration_status,
                    ''
                  ) <> 'Cancelled'
        ) AS registered_count,

        (
            SELECT COUNT(*)
            FROM event_registrations er
            WHERE er.event_id = e.event_id
              AND er.attendance_status = 'Attended'
        ) AS attended_count

    FROM hub_events e

    LEFT JOIN projects p
        ON p.project_id = e.project_id

    LEFT JOIN programs pr
        ON pr.id = e.program_id

    WHERE
        " . implode(
            ' AND ',
            $where
        ) . "

    ORDER BY
        e.event_date ASC,
        e.start_time ASC,
        e.event_title ASC
";


$imsEvents = [];

$stmt = $conn->prepare(
    $sql
);

if ($stmt) {

    ev_bind(
        $stmt,
        $bindTypes,
        $params
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
            $result->fetch_assoc()
    ) {
        $row['source'] =
            'ims';

        $imsEvents[] =
            $row;
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| GOOGLE CALENDAR CONNECTION
|--------------------------------------------------------------------------
*/

$googleConnected =
    gcal_is_connected(
        $conn
    );

$googleCalendarName =
    gcal_setting(
        $conn,
        'google_calendar_name',
        'Hive Colab Events'
    );

$googleCalendarId =
    gcal_setting(
        $conn,
        'google_calendar_id',
        ''
    );

$googleEvents = [];

$googleFetchError =
    '';


/*
|--------------------------------------------------------------------------
| FETCH LIVE GOOGLE EVENTS
|--------------------------------------------------------------------------
*/

if (
    $googleConnected
    &&
    $googleCalendarId !== ''
) {
    try {

        $timezoneName =
            gcal_setting(
                $conn,
                'google_calendar_timezone',
                'Africa/Kampala'
            );

        try {
            $timezone =
                new DateTimeZone(
                    $timezoneName
                );
        } catch (Throwable $e) {
            $timezone =
                new DateTimeZone(
                    'Africa/Kampala'
                );
        }


        if ($filterMonth > 0) {

            $rangeStart =
                sprintf(
                    '%04d-%02d-01',
                    $filterYear,
                    $filterMonth
                );

            $rangeEnd =
                (
                    new DateTimeImmutable(
                        $rangeStart,
                        $timezone
                    )
                )
                ->modify(
                    '+1 month'
                )
                ->format(
                    'Y-m-d'
                );

        } else {

            $rangeStart =
                sprintf(
                    '%04d-01-01',
                    $filterYear
                );

            $rangeEnd =
                sprintf(
                    '%04d-01-01',
                    $filterYear + 1
                );
        }


        $timeMin =
            (
                new DateTimeImmutable(
                    $rangeStart
                    . ' 00:00:00',
                    $timezone
                )
            )
            ->format(
                DATE_RFC3339
            );


        $timeMax =
            (
                new DateTimeImmutable(
                    $rangeEnd
                    . ' 00:00:00',
                    $timezone
                )
            )
            ->format(
                DATE_RFC3339
            );


        $googlePath =
            'calendars/'
            . rawurlencode(
                $googleCalendarId
            )
            . '/events?'
            . http_build_query(
                [
                    'singleEvents'
                        => 'true',

                    'orderBy'
                        => 'startTime',

                    'showDeleted'
                        => 'false',

                    'maxResults'
                        => 2500,

                    'timeMin'
                        => $timeMin,

                    'timeMax'
                        => $timeMax,
                ]
            );


        $googleResponse =
            gcal_api(
                $conn,
                'GET',
                $googlePath,
                null,
                (int)(
                    $_SESSION[
                        'user_id'
                    ]
                    ?? 0
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate display
        |--------------------------------------------------------------------------
        |
        | If an IMS event is already linked using google_event_id,
        | it is displayed using the IMS record rather than twice.
        |
        */

        $existingGoogleIds =
            [];

        foreach (
            $imsEvents
            as
            $event
        ) {
            if (
                !empty(
                    $event[
                        'google_event_id'
                    ]
                )
            ) {
                $existingGoogleIds[
                    (string)$event[
                        'google_event_id'
                    ]
                ] = true;
            }
        }


        foreach (
            (
                $googleResponse[
                    'items'
                ]
                ?? []
            )
            as
            $googleEvent
        ) {

            if (
                !is_array(
                    $googleEvent
                )
            ) {
                continue;
            }


            $googleEventId =
                (string)(
                    $googleEvent[
                        'id'
                    ]
                    ?? ''
                );


            if (
                $googleEventId !== ''
                &&
                isset(
                    $existingGoogleIds[
                        $googleEventId
                    ]
                )
            ) {
                continue;
            }


            $eventDate =
                ev_google_date(
                    $googleEvent,
                    'start'
                );


            if ($eventDate === '') {
                continue;
            }


            $googleEvents[] = [

                'event_id'
                    => 0,

                'event_title'
                    => (string)(
                        $googleEvent[
                            'summary'
                        ]
                        ?? 'Google Calendar Event'
                    ),

                'event_type'
                    => 'Google Calendar',

                'event_date'
                    => $eventDate,

                'start_time'
                    => ev_google_time(
                        $googleEvent,
                        'start'
                    ),

                'end_time'
                    => ev_google_time(
                        $googleEvent,
                        'end'
                    ),

                'venue'
                    => (string)(
                        $googleEvent[
                            'location'
                        ]
                        ?? ''
                    ),

                'organizer'
                    => (string)(
                        $googleEvent[
                            'organizer'
                        ][
                            'displayName'
                        ]
                        ??
                        $googleEvent[
                            'organizer'
                        ][
                            'email'
                        ]
                        ??
                        ''
                    ),

                'description'
                    => (string)(
                        $googleEvent[
                            'description'
                        ]
                        ?? ''
                    ),

                'event_status'
                    => 'Google',

                'registered_count'
                    => 0,

                'attended_count'
                    => 0,

                'google_event_id'
                    => $googleEventId,

                'google_html_link'
                    => (string)(
                        $googleEvent[
                            'htmlLink'
                        ]
                        ?? ''
                    ),

                'google_sync_status'
                    => 'Google Only',

                'google_sync_error'
                    => '',

                'meeting_link'
                    => (string)(
                        $googleEvent[
                            'hangoutLink'
                        ]
                        ?? ''
                    ),

                'project_name'
                    => '',

                'project_code'
                    => '',

                'program_name'
                    => '',

                'source'
                    => 'google',
            ];
        }

    } catch (Throwable $e) {

        $googleFetchError =
            $e->getMessage();

        error_log(
            'Google Calendar event fetch failed: '
            . $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| COMBINE IMS + GOOGLE EVENTS
|--------------------------------------------------------------------------
*/

$events = [];


if (
    $filterSource === 'all'
    ||
    $filterSource === 'ims'
) {
    $events =
        array_merge(
            $events,
            $imsEvents
        );
}


if (
    $filterSource === 'all'
    ||
    $filterSource === 'google'
) {
    $events =
        array_merge(
            $events,
            $googleEvents
        );
}


usort(
    $events,
    static function (
        array $a,
        array $b
    ): int {

        $first =
            (string)(
                $a[
                    'event_date'
                ]
                ?? ''
            )
            . ' '
            . (string)(
                $a[
                    'start_time'
                ]
                ?? ''
            );


        $second =
            (string)(
                $b[
                    'event_date'
                ]
                ?? ''
            )
            . ' '
            . (string)(
                $b[
                    'start_time'
                ]
                ?? ''
            );


        return strcmp(
            $first,
            $second
        );
    }
);



/*
|--------------------------------------------------------------------------
| TABLE PAGINATION
|--------------------------------------------------------------------------
|
| The calendar keeps the complete filtered list.
| Only the Table tab is paginated.
|
*/

$tablePage = max(
    1,
    (int)(
        $_GET['table_page']
        ?? 1
    )
);

$tablePerPage = 15;

$tableTotalRecords =
    count($events);

$tableTotalPages =
    max(
        1,
        (int)ceil(
            $tableTotalRecords
            /
            $tablePerPage
        )
    );

if (
    $tablePage
    >
    $tableTotalPages
) {
    $tablePage =
        $tableTotalPages;
}

$tableOffset =
    (
        $tablePage - 1
    )
    *
    $tablePerPage;

$tableEvents =
    array_slice(
        $events,
        $tableOffset,
        $tablePerPage
    );

function ev_table_page_url(
    int $targetPage
): string {
    $params =
        $_GET;

    $params['tab'] =
        'table';

    $params['table_page'] =
        max(
            1,
            $targetPage
        );

    return '?'
        . http_build_query(
            $params
        );
}

/*
|--------------------------------------------------------------------------
| EVENT TYPES
|--------------------------------------------------------------------------
*/

$eventTypes =
    [];

$result =
    $conn->query("
        SELECT DISTINCT
            event_type
        FROM hub_events
        WHERE event_type IS NOT NULL
          AND TRIM(event_type) <> ''
        ORDER BY event_type
    ");

if ($result) {

    while (
        $row =
            $result->fetch_assoc()
    ) {
        $eventTypes[] =
            (string)$row[
                'event_type'
            ];
    }

    $result->close();
}


$eventStatuses = [
    'Planned',
    'Ongoing',
    'Completed',
    'Cancelled',
];


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$today =
    date('Y-m-d');


$statistics = [

    'all'
        => count($events),

    'ims'
        => count($imsEvents),

    'google'
        => count($googleEvents),

    'upcoming'
        => 0,

    'completed'
        => 0,
];


foreach (
    $events
    as
    $event
) {

    if (
        (
            $event[
                'event_date'
            ]
            ?? ''
        ) >= $today
        &&
        strtolower(
            (string)(
                $event[
                    'event_status'
                ]
                ?? ''
            )
        )
        !==
        'cancelled'
    ) {
        $statistics[
            'upcoming'
        ]++;
    }


    if (
        strtolower(
            (string)(
                $event[
                    'event_status'
                ]
                ?? ''
            )
        )
        ===
        'completed'
    ) {
        $statistics[
            'completed'
        ]++;
    }
}


/*
|--------------------------------------------------------------------------
| CALENDAR JSON
|--------------------------------------------------------------------------
*/

$calendarEvents =
    [];


foreach (
    $events
    as
    $event
) {

    $calendarEvents[] = [

        'id'
            => (int)(
                $event[
                    'event_id'
                ]
                ?? 0
            ),

        'title'
            => (string)(
                $event[
                    'event_title'
                ]
                ?? ''
            ),

        'date'
            => (string)(
                $event[
                    'event_date'
                ]
                ?? ''
            ),

        'time'
            => (string)(
                $event[
                    'start_time'
                ]
                ?? ''
            ),

        'end_time'
            => (string)(
                $event[
                    'end_time'
                ]
                ?? ''
            ),

        'venue'
            => (string)(
                $event[
                    'venue'
                ]
                ?? ''
            ),

        'organizer'
            => (string)(
                $event[
                    'organizer'
                ]
                ?? ''
            ),

        'description'
            => (string)(
                $event[
                    'description'
                ]
                ?? ''
            ),

        'type'
            => (string)(
                $event[
                    'event_type'
                ]
                ?? ''
            ),

        'status'
            => (string)(
                $event[
                    'event_status'
                ]
                ?? ''
            ),

        'source'
            => (string)(
                $event[
                    'source'
                ]
                ?? 'ims'
            ),

        'google_link'
            => (string)(
                $event[
                    'google_html_link'
                ]
                ?? ''
            ),

        'meeting_link'
            => (string)(
                $event[
                    'meeting_link'
                ]
                ?? ''
            ),
    ];
}


/*
|--------------------------------------------------------------------------
| EVENT ACTION DATA
|--------------------------------------------------------------------------
*/
$eventActionData = [];

// Staff who can be invited, and who is already invited to each event.
$staffOptions = ev_staff_options($conn);
$eventStaffMap = ev_staff_map($conn, array_column($imsEvents, 'event_id'));

foreach ($imsEvents as $event) {
    $days = [];

    if (!empty($event['event_days'])) {
        $decoded = json_decode((string)$event['event_days'], true);

        if (is_array($decoded)) {
            $days = array_values(array_filter(array_map('strval', $decoded)));
        }
    }

    if (!$days && !empty($event['event_date'])) {
        $days = [(string)$event['event_date']];
    }

    $linkType = 'none';

    if (!empty($event['project_id'])) {
        $linkType = 'project';
    } elseif (!empty($event['program_id'])) {
        $linkType = 'program';
    }

    $eventActionData[(string)(int)$event['event_id']] = [
        'event_id' => (int)$event['event_id'],
        'event_title' => (string)($event['event_title'] ?? ''),
        'event_type' => (string)($event['event_type'] ?? ''),
        'event_days' => $days,
        'start_time' => (string)($event['start_time'] ?? ''),
        'end_time' => (string)($event['end_time'] ?? ''),
        'venue' => (string)($event['venue'] ?? ''),
        'link_type' => $linkType,
        'project_id' => (int)($event['project_id'] ?? 0),
        'program_id' => (int)($event['program_id'] ?? 0),
        'organizer' => (string)($event['organizer'] ?? ''),
        'expected_participants' => (int)($event['expected_participants'] ?? 0),
        'description' => (string)($event['description'] ?? ''),
        'event_status' => (string)($event['event_status'] ?? 'Planned'),
        'staff_ids' => $eventStaffMap[(int)$event['event_id']] ?? [],
    ];
}

/** Searchable checklist of staff; posts staff_ids[]. $prefix is "add" or "edit". */
function ev_render_staff_picker(string $prefix, array $staffOptions): void
{
    $listId = $prefix . 'StaffList';
    ?>
    <div class="full ev-staff-picker" id="<?= ev_h($prefix) ?>StaffPicker">
        <div class="ev-staff-head">
            <label class="form-label" for="<?= ev_h($prefix) ?>StaffSearch">Invite Staff</label>
            <span class="ev-staff-count" id="<?= ev_h($prefix) ?>StaffCount" aria-live="polite">0 selected</span>
        </div>
        <?php if (!$staffOptions): ?>
            <p class="ev-staff-empty">No active staff with an email address were found.</p>
        <?php else: ?>
            <div class="ev-staff-chips" id="<?= ev_h($prefix) ?>StaffChips" aria-label="Selected staff"></div>
            <input type="search" id="<?= ev_h($prefix) ?>StaffSearch" class="form-control ev-staff-search"
                   placeholder="Search staff by name, email or role..." autocomplete="off"
                   aria-controls="<?= ev_h($listId) ?>" oninput="filterStaffPicker('<?= ev_h($prefix) ?>')"
                   onkeydown="if (event.key === 'Enter') event.preventDefault();">
            <div class="ev-staff-tools">
                <button type="button" class="ev-staff-link" onclick="setAllStaff('<?= ev_h($prefix) ?>', true)">Select all shown</button>
                <button type="button" class="ev-staff-link" onclick="setAllStaff('<?= ev_h($prefix) ?>', false)">Clear</button>
            </div>
            <div class="ev-staff-list" id="<?= ev_h($listId) ?>" role="group" aria-label="Staff to invite">
                <?php foreach ($staffOptions as $person): ?>
                    <?php $search = strtolower(($person['full_name'] ?? '') . ' ' . ($person['email'] ?? '') . ' ' . ($person['role'] ?? '')); ?>
                    <label class="ev-staff-item" data-search="<?= ev_h($search) ?>">
                        <input type="checkbox" name="staff_ids[]" value="<?= (int)$person['user_id'] ?>"
                               data-name="<?= ev_h($person['full_name']) ?>"
                               onchange="updateStaffPicker('<?= ev_h($prefix) ?>')">
                        <span class="ev-staff-avatar" aria-hidden="true"><?= ev_h(strtoupper(substr((string)$person['full_name'], 0, 1))) ?></span>
                        <span class="ev-staff-text">
                            <strong><?= ev_h($person['full_name']) ?></strong>
                            <small><?= ev_h($person['email']) ?><?= !empty($person['role']) ? ' &middot; ' . ev_h($person['role']) : '' ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
                <p class="ev-staff-empty ev-staff-nomatch" hidden>No staff match your search.</p>
            </div>
            <small class="ev-staff-hint">
                <i class="fas fa-calendar-check" aria-hidden="true"></i>
                Selected staff receive a calendar invite by email and an IMS notification.
            </small>
        <?php endif; ?>
    </div>
    <?php
}

$canDeleteEvents = ims_allowed('events.delete', ['Administrator', 'Operations/Admin'], 'Delete events');


?>


<style>

.events-page {
    width: 100%;
}


.events-header {

    display: flex;

    justify-content:
        space-between;

    align-items: center;

    gap: 16px;

    flex-wrap: wrap;

    padding: 18px 20px;

    margin-bottom: 15px;

    background: #fff;

    border:
        1px solid #e2e8f0;

    border-left:
        5px solid #f97316;

    border-radius: 10px;
}


.events-header h1 {

    margin: 0;

    color: #0f172a;

    font-size: 21px;
}


.events-header p {

    margin:
        5px 0 0;

    color: #64748b;

    font-size: 11px;
}


.google-state {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 7px 10px;

    border-radius: 20px;

    font-size: 10px;

    font-weight: 800;

    background:
        <?= $googleConnected
            ? '#ecfdf5'
            : '#fff7ed' ?>;

    color:
        <?= $googleConnected
            ? '#166534'
            : '#9a3412' ?>;
}


.events-stats {

    display: grid;

    grid-template-columns:
        repeat(
            5,
            minmax(
                0,
                1fr
            )
        );

    gap: 10px;

    margin-bottom:
        15px;
}


.event-stat {

    padding:
        13px;

    background:
        #fff;

    border:
        1px solid #e2e8f0;

    border-left:
        4px solid #2563eb;

    border-radius:
        8px;
}


.event-stat:nth-child(2) {
    border-left-color:
        #16a34a;
}


.event-stat:nth-child(3) {
    border-left-color:
        #f97316;
}


.event-stat:nth-child(4) {
    border-left-color:
        #4285f4;
}


.event-stat:nth-child(5) {
    border-left-color:
        #9333ea;
}


.event-stat small {

    color: #64748b;

    font-size: 9px;

    text-transform:
        uppercase;

    font-weight: 800;
}


.event-stat strong {

    display: block;

    margin-top:
        4px;

    color: #0f172a;

    font-size: 20px;
}


.events-tabs {

    display: flex;

    border-bottom:
        2px solid #e2e8f0;

    margin-bottom:
        14px;
}


.events-tab {

    padding:
        11px 15px;

    margin-bottom:
        -2px;

    border-bottom:
        2px solid transparent;

    color: #64748b;

    text-decoration:
        none;

    font-size: 11px;

    font-weight: 800;
}


.events-tab.active {

    color: #ea580c;

    border-bottom-color:
        #ea580c;
}


/* One-row layout comes from .filters-bar (style.css). */
.events-filter {

    margin-bottom:
        14px;

    padding: 12px;

    border:
        1px solid #e2e8f0;

    border-radius:
        9px;

    background:
        #f8fafc;
}


.events-filter > div {
    flex: 0 1 150px;
    min-width: 110px;
}

/* First field is the search box: it takes the spare width. */
.events-filter > div:first-of-type {
    flex: 1 1 220px;
    min-width: 180px;
}

@media (max-width: 768px) {
    .events-filter > div {
        flex: 1 1 calc(50% - 12px);
        min-width: 0;
    }

    .events-filter > div:first-of-type {
        flex-basis: 100%;
    }
}


.events-filter label {

    display: block;

    margin-bottom:
        4px;

    color: #64748b;

    font-size: 8px;

    font-weight: 800;

    text-transform:
        uppercase;
}


.calendar-panel,
.table-panel {

    background: #fff;

    border:
        1px solid #e2e8f0;

    border-radius:
        10px;

    overflow: hidden;
}


.panel-head {

    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 10px;

    padding:
        13px 15px;

    border-bottom:
        1px solid #e2e8f0;
}


.panel-head h3 {

    margin: 0;

    font-size:
        14px;
}


.calendar-grid {

    display: grid;

    grid-template-columns:
        repeat(
            7,
            minmax(
                0,
                1fr
            )
        );
}


.calendar-weekday {

    padding:
        8px;

    text-align:
        center;

    background:
        #f8fafc;

    border-right:
        1px solid #e2e8f0;

    border-bottom:
        1px solid #e2e8f0;

    color:
        #64748b;

    font-size:
        9px;

    font-weight:
        800;
}


.calendar-day {

    min-height:
        120px;

    padding:
        7px;

    border-right:
        1px solid #e2e8f0;

    border-bottom:
        1px solid #e2e8f0;
}


.calendar-day.other {

    background:
        #f8fafc;
}


.calendar-day.today {

    box-shadow:
        inset
        0
        0
        0
        2px
        #f97316;
}


.calendar-number {

    margin-bottom:
        6px;

    color:
        #475569;

    font-size:
        10px;

    font-weight:
        800;
}


.calendar-event {

    display: block;

    width: 100%;

    margin-bottom:
        4px;

    padding:
        5px 6px;

    border: 0;

    border-left:
        3px solid #f97316;

    border-radius:
        5px;

    background:
        #fff7ed;

    color:
        #9a3412;

    text-align:
        left;

    cursor:
        pointer;

    font-size:
        9px;
}


.calendar-event.google {

    background:
        #eff6ff;

    border-left-color:
        #4285f4;

    color:
        #174ea6;
}


.calendar-event strong {

    display:
        block;

    overflow:
        hidden;

    white-space:
        nowrap;

    text-overflow:
        ellipsis;
}


.events-table-wrap {

    overflow-x:
        auto;
}


.events-table {

    width: 100%;

    border-collapse:
        collapse;
}


.events-table th {

    padding:
        9px;

    background:
        #f8fafc;

    color:
        #64748b;

    border-bottom:
        1px solid #e2e8f0;

    font-size:
        8px;

    text-align:
        left;

    text-transform:
        uppercase;
}


.events-table td {

    padding:
        9px;

    border-bottom:
        1px solid #f1f5f9;

    color:
        #334155;

    font-size:
        10px;

    vertical-align:
        top;
}


.source-badge {

    display:
        inline-flex;

    margin-top:
        4px;

    padding:
        3px 6px;

    border-radius:
        20px;

    font-size:
        8px;

    font-weight:
        800;

    background:
        #fff7ed;

    color:
        #9a3412;
}


.source-badge.google {

    background:
        #eff6ff;

    color:
        #174ea6;
}


.google-error {

    margin-bottom:
        13px;

    padding:
        10px 12px;

    border-left:
        4px solid #dc2626;

    border-radius:
        7px;

    background:
        #fef2f2;

    color:
        #991b1b;

    font-size:
        10px;
}


.event-modal {

    position:
        fixed;

    inset: 0;

    z-index:
        999999;

    display:
        none;

    align-items:
        center;

    justify-content:
        center;

    padding:
        20px;

    background:
        rgba(
            15,
            23,
            42,
            .55
        );
}


.event-modal.open {

    display:
        flex;
}


.event-modal-card {

    width:
        min(
            100%,
            520px
        );

    max-height:
        calc(
            100vh
            -
            40px
        );

    overflow:
        auto;

    padding:
        18px;

    border-radius:
        11px;

    background:
        #fff;
}


.event-details-grid {

    display:
        grid;

    grid-template-columns:
        repeat(
            2,
            1fr
        );

    gap:
        8px;
}


.event-detail {

    padding:
        9px;

    border:
        1px solid #e2e8f0;

    border-radius:
        7px;

    background:
        #f8fafc;
}


.event-detail small {

    display:
        block;

    color:
        #64748b;

    font-size:
        8px;

    text-transform:
        uppercase;
}


.event-detail strong {

    font-size:
        10px;

    color:
        #0f172a;
}


@media (
    max-width: 1050px
) {

    .events-stats {

        grid-template-columns:
            repeat(
                3,
                1fr
            );
    }
}


@media (
    max-width: 750px
) {

    .calendar-panel {

        overflow-x:
            auto;
    }


    .calendar-grid {

        min-width:
            850px;
    }


    .events-stats {

        grid-template-columns:
            1fr;
    }
}



.events-table-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    padding: 14px 12px;
    border-top: 1px solid #e2e8f0;
    background: #fff;
}

.events-table-pagination a,
.events-table-pagination span.page-number {
    min-width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 9px;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    background: #fff;
    color: #334155;
    text-decoration: none;
    font-size: 9px;
    font-weight: 800;
}

.events-table-pagination a:hover,
.events-table-pagination span.active {
    background: #f97316;
    border-color: #f97316;
    color: #fff;
}

.events-table-pagination .page-summary {
    margin-left: 7px;
    color: #64748b;
    font-size: 9px;
    font-weight: 700;
}

.ev-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
.ev-icon-btn{width:31px;height:31px;display:inline-flex;align-items:center;justify-content:center;padding:0;border-radius:7px;border:1px solid #dbe3ec;background:#fff;color:#475569;text-decoration:none;cursor:pointer}
.ev-icon-btn.view{background:#f0f9ff;border-color:#bae6fd;color:#0369a1}
.ev-icon-btn.edit{background:#fff7ed;border-color:#fed7aa;color:#c2410c}
.ev-icon-btn.delete{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
.ev-modal{position:fixed;inset:0;z-index:1000000;display:none;align-items:center;justify-content:center;padding:20px;overflow-y:auto;background:rgba(15,23,42,.62)}
.ev-modal.open{display:flex}
.ev-modal-card{width:min(100%,860px);max-height:calc(100vh - 40px);overflow:auto;border-radius:14px;background:#fff;box-shadow:0 30px 80px rgba(15,23,42,.28)}
.ev-modal-card.small{width:min(100%,520px)}
.ev-modal-head{position:sticky;top:0;z-index:4;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid #e2e8f0;background:#fff}
.ev-modal-head h3{margin:0;font-size:15px;color:#0f172a}
.ev-modal-close{width:34px;height:34px;border:0;border-radius:8px;background:#f1f5f9;color:#475569;font-size:20px;cursor:pointer}
.ev-modal-body{padding:18px}
.ev-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.ev-form-grid .full{grid-column:1/-1}
.ev-modal-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:17px;padding-top:14px;border-top:1px solid #e2e8f0}
.ev-date-list{display:flex;flex-direction:column;gap:7px}
.ev-date-row{display:flex;gap:7px;align-items:center}
.ev-date-row input{flex:1}
.ev-link-target{display:none}.ev-link-target.open{display:block}
.ev-danger{padding:11px 12px;border-left:4px solid #dc2626;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:11px}
body.ev-modal-open{overflow:hidden}
.ev-staff-head{display:flex;align-items:baseline;justify-content:space-between;gap:8px}
.ev-staff-count{font-size:12px;font-weight:600;color:var(--brand-700,#c2410c)}
.ev-staff-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.ev-staff-chips:empty{display:none}
.ev-staff-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 6px 4px 10px;border:1px solid var(--brand-200,#ffc89e);border-radius:999px;background:var(--brand-50,#fff4ed);color:#7c2d12;font-size:12px;font-weight:600}
.ev-staff-chip button{width:20px;height:20px;border:0;border-radius:50%;background:transparent;color:inherit;font-size:14px;line-height:1;cursor:pointer}
.ev-staff-chip button:hover,.ev-staff-chip button:focus-visible{background:var(--brand-100,#ffe6d0)}
.ev-staff-tools{display:flex;gap:14px;margin:6px 0}
.ev-staff-link{border:0;padding:2px 0;background:none;color:var(--brand-700,#c2410c);font-size:12px;font-weight:600;cursor:pointer}
.ev-staff-link:hover{text-decoration:underline}
.ev-staff-list{max-height:220px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.ev-staff-item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;margin:0}
.ev-staff-item:last-of-type{border-bottom:0}
.ev-staff-item:hover{background:#f8fafc}
.ev-staff-item:has(input:checked){background:var(--brand-50,#fff4ed)}
.ev-staff-item input{width:18px;height:18px;flex:none;accent-color:var(--brand-600,#ea580c)}
.ev-staff-item[hidden]{display:none}
.ev-staff-avatar{width:30px;height:30px;flex:none;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--brand-100,#ffe6d0);color:var(--brand-700,#c2410c);font-size:13px;font-weight:700}
.ev-staff-text{display:flex;flex-direction:column;min-width:0}
.ev-staff-text strong{font-size:13px;color:#0f172a}
.ev-staff-text small{font-size:12px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ev-staff-empty{margin:0;padding:12px;font-size:12px;color:#64748b}
.ev-staff-hint{display:block;margin-top:6px;font-size:12px;color:#64748b}
@media(max-width:720px){.ev-form-grid{grid-template-columns:1fr}.ev-form-grid .full{grid-column:auto}.ev-staff-list{max-height:180px}}
</style>


<div class="events-page">


    <div class="events-header">

        <div>

            <h1>

                <i class="fas fa-calendar-days"></i>

                Events Management

            </h1>

            <p>

                Hive Colab events from IMS and the connected Google Calendar.

            </p>

        </div>


        <div
            style="
                display:flex;
                gap:8px;
                align-items:center;
                flex-wrap:wrap;
            "
        >

            <span class="google-state">

                <i class="fab fa-google"></i>

                <?= $googleConnected
                    ? 'Google Calendar Connected'
                    : 'Google Calendar Not Connected' ?>

            </span>


            <button
                type="button"
                class="btn btn-primary"
                onclick="openAddEventModal()"
            >

                <i class="fas fa-plus"></i>

                Add Event

            </button>

        </div>

    </div>


    <?php if (
        $googleFetchError !== ''
    ): ?>

        <div class="google-error">

            <i class="fas fa-triangle-exclamation"></i>

            Google Calendar fetch failed:

            <?= ev_h(
                $googleFetchError
            ) ?>

        </div>

    <?php endif; ?>


    <div class="events-stats">

        <div class="event-stat">

            <small>
                Events Displayed
            </small>

            <strong>
                <?= $statistics['all'] ?>
            </strong>

        </div>


        <div class="event-stat">

            <small>
                Upcoming
            </small>

            <strong>
                <?= $statistics['upcoming'] ?>
            </strong>

        </div>


        <div class="event-stat">

            <small>
                IMS Events
            </small>

            <strong>
                <?= $statistics['ims'] ?>
            </strong>

        </div>


        <div class="event-stat">

            <small>
                Google Only
            </small>

            <strong>
                <?= $statistics['google'] ?>
            </strong>

        </div>


        <div class="event-stat">

            <small>
                Completed
            </small>

            <strong>
                <?= $statistics['completed'] ?>
            </strong>

        </div>

    </div>


    <div class="events-tabs">

        <a
            href="?<?= ev_h(
                ev_query(
                    [
                        'tab'
                            => 'calendar',
                    ]
                )
            ) ?>"
            class="
                events-tab
                <?= $activeTab
                    ===
                    'calendar'
                    ? 'active'
                    : '' ?>
            "
        >

            <i class="fas fa-calendar-alt"></i>

            Calendar

        </a>


        <a
            href="?<?= ev_h(
                ev_query(
                    [
                        'tab'
                            => 'table',
                    ]
                )
            ) ?>"
            class="
                events-tab
                <?= $activeTab
                    ===
                    'table'
                    ? 'active'
                    : '' ?>
            "
        >

            <i class="fas fa-table-list"></i>

            Table

        </a>

    </div>


    <form
        method="GET"
        class="events-filter filters-bar"
        role="search"
        aria-label="Filter events"
    >

        <input
            type="hidden"
            name="tab"
            value="<?= ev_h(
                $activeTab
            ) ?>"
        >


        <div>

            <label>
                Search
            </label>

            <input
                type="search"
                name="search"
                class="form-control"
                value="<?= ev_h(
                    $search
                ) ?>"
                placeholder="Event, venue, organiser..."
            >

        </div>


        <div>

            <label>
                Year
            </label>

            <select
                name="year"
                class="form-control"
            >

                <?php for (
                    $year =
                        $currentYear - 3;

                    $year
                    <=
                    $currentYear + 3;

                    $year++
                ): ?>

                    <option
                        value="<?= $year ?>"
                        <?= $year
                            ===
                            $filterYear
                            ? 'selected'
                            : '' ?>
                    >

                        <?= $year ?>

                    </option>

                <?php endfor; ?>

            </select>

        </div>


        <div>

            <label>
                Month
            </label>

            <select
                name="month"
                class="form-control"
            >

                <option value="0">

                    All Months

                </option>


                <?php for (
                    $month = 1;

                    $month <= 12;

                    $month++
                ): ?>

                    <option
                        value="<?= $month ?>"
                        <?= $month
                            ===
                            $filterMonth
                            ? 'selected'
                            : '' ?>
                    >

                        <?= date(
                            'F',
                            mktime(
                                0,
                                0,
                                0,
                                $month,
                                1
                            )
                        ) ?>

                    </option>

                <?php endfor; ?>

            </select>

        </div>


        <div>

            <label>
                Type
            </label>

            <select
                name="type"
                class="form-control"
            >

                <option value="">

                    All Types

                </option>


                <?php foreach (
                    $eventTypes
                    as
                    $eventType
                ): ?>

                    <option
                        value="<?= ev_h(
                            $eventType
                        ) ?>"
                        <?= $eventType
                            ===
                            $filterType
                            ? 'selected'
                            : '' ?>
                    >

                        <?= ev_h(
                            $eventType
                        ) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div>

            <label>
                Status
            </label>

            <select
                name="event_status"
                class="form-control"
            >

                <option value="">

                    All Statuses

                </option>


                <?php foreach (
                    $eventStatuses
                    as
                    $eventStatus
                ): ?>

                    <option
                        value="<?= ev_h(
                            $eventStatus
                        ) ?>"
                        <?= $eventStatus
                            ===
                            $filterStatus
                            ? 'selected'
                            : '' ?>
                    >

                        <?= ev_h(
                            $eventStatus
                        ) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div>

            <label>
                Source
            </label>

            <select
                name="source"
                class="form-control"
            >

                <option
                    value="all"
                    <?= $filterSource
                        ===
                        'all'
                        ? 'selected'
                        : '' ?>
                >

                    IMS + Google

                </option>


                <option
                    value="ims"
                    <?= $filterSource
                        ===
                        'ims'
                        ? 'selected'
                        : '' ?>
                >

                    IMS Only

                </option>


                <option
                    value="google"
                    <?= $filterSource
                        ===
                        'google'
                        ? 'selected'
                        : '' ?>
                >

                    Google Only

                </option>

            </select>

        </div>


        <button
            type="submit"
            class="btn btn-primary"
        >

            <i class="fas fa-filter"></i>

            Apply

        </button>

    </form>


    <?php if (
        $activeTab
        ===
        'calendar'
    ): ?>


        <div class="calendar-panel">


            <div class="panel-head">

                <div>

                    <h3>

                        <i class="fas fa-calendar"></i>

                        <?php

                        if (
                            $filterMonth
                            >
                            0
                        ) {

                            echo date(
                                'F Y',
                                mktime(
                                    0,
                                    0,
                                    0,
                                    $filterMonth,
                                    1,
                                    $filterYear
                                )
                            );

                        } else {

                            echo $filterYear;
                        }

                        ?>

                    </h3>

                    <small
                        style="
                            color:#64748b;
                        "
                    >

                        <?= count(
                            $events
                        ) ?>

                        event(s)

                    </small>

                </div>

            </div>


            <?php if (
                $filterMonth
                ===
                0
            ): ?>

                <div
                    style="
                        padding:30px;
                        text-align:center;
                        color:#64748b;
                    "
                >

                    Select a month to display the calendar.

                </div>

            <?php else: ?>


                <div class="calendar-grid">

                    <?php foreach (
                        [
                            'Mon',
                            'Tue',
                            'Wed',
                            'Thu',
                            'Fri',
                            'Sat',
                            'Sun',
                        ]
                        as
                        $day
                    ): ?>

                        <div class="calendar-weekday">

                            <?= $day ?>

                        </div>

                    <?php endforeach; ?>


                    <div
                        id="calendarDays"
                        style="
                            display:contents;
                        "
                    ></div>

                </div>


            <?php endif; ?>

        </div>


    <?php else: ?>


        <div class="table-panel">


            <div class="panel-head">

                <h3>

                    <i class="fas fa-table-list"></i>

                    Hive Events

                </h3>


                <strong>

                    <?= number_format(
                        $tableTotalRecords
                    ) ?>

                    records

                </strong>

            </div>


            <div class="events-table-wrap">


                <table class="events-table">


                    <thead>

                        <tr>

                            <th>Event</th>

                            <th>Date</th>

                            <th>Time</th>

                            <th>Type</th>

                            <th>Venue</th>

                            <th>Status</th>

                            <th>Registrations</th>

                            <th>Google</th>

                            <th>Actions</th>

                        </tr>

                    </thead>


                    <tbody>


                        <?php if (
                            !$tableEvents
                        ): ?>

                            <tr>

                                <td
                                    colspan="9"
                                    style="
                                        padding:30px;
                                        text-align:center;
                                    "
                                >

                                    No events found.

                                </td>

                            </tr>

                        <?php endif; ?>


                        <?php foreach (
                            $tableEvents
                            as
                            $event
                        ): ?>


                            <?php

                            $eventSource =
                                (string)(
                                    $event[
                                        'source'
                                    ]
                                    ?? 'ims'
                                );

                            ?>


                            <tr>


                                <td>

                                    <strong>

                                        <?= ev_h(
                                            $event[
                                                'event_title'
                                            ]
                                            ?? ''
                                        ) ?>

                                    </strong>


                                    <br>


                                    <span
                                        class="
                                            source-badge
                                            <?= $eventSource
                                                ===
                                                'google'
                                                ? 'google'
                                                : '' ?>
                                        "
                                    >

                                        <?= $eventSource
                                            ===
                                            'google'
                                            ? 'Google Only'
                                            : 'IMS' ?>

                                    </span>

                                </td>


                                <td>

                                    <?= ev_h(
                                        date(
                                            'd M Y',
                                            strtotime(
                                                (string)$event[
                                                    'event_date'
                                                ]
                                            )
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        !empty(
                                            $event[
                                                'start_time'
                                            ]
                                        )
                                    ): ?>

                                        <?= ev_h(
                                            substr(
                                                (string)$event[
                                                    'start_time'
                                                ],
                                                0,
                                                5
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        All day

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= ev_h(
                                        $event[
                                            'event_type'
                                        ]
                                        ?? ''
                                    ) ?>

                                </td>


                                <td>

                                    <?= ev_h(
                                        $event[
                                            'venue'
                                        ]
                                        ?? '-'
                                    ) ?>

                                </td>


                                <td>

                                    <?= ev_h(
                                        $event[
                                            'event_status'
                                        ]
                                        ?? ''
                                    ) ?>

                                </td>


                                <td>

                                    <?= $eventSource
                                        ===
                                        'ims'
                                        ? (int)(
                                            $event[
                                                'registered_count'
                                            ]
                                            ?? 0
                                        )
                                        : '-' ?>

                                </td>


                                <td>


                                    <?php if (
                                        !empty(
                                            $event[
                                                'google_html_link'
                                            ]
                                        )
                                    ): ?>

                                        <a
                                            href="<?= ev_h(
                                                $event[
                                                    'google_html_link'
                                                ]
                                            ) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="
                                                btn
                                                btn-gray
                                                btn-sm
                                            "
                                        >

                                            <i class="fab fa-google"></i>

                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $event[
                                                'meeting_link'
                                            ]
                                        )
                                    ): ?>

                                        <a
                                            href="<?= ev_h(
                                                $event[
                                                    'meeting_link'
                                                ]
                                            ) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="
                                                btn
                                                btn-info
                                                btn-sm
                                            "
                                        >

                                            <i class="fas fa-video"></i>

                                        </a>

                                    <?php endif; ?>


                                </td>


                                <td>
                                    <div class="ev-actions">
                                        <button
                                            type="button"
                                            class="ev-icon-btn view"
                                            title="View event"
                                            onclick='viewEventRow(
                                                <?= json_encode(
                                                    [
                                                        "event_id" => (int)($event["event_id"] ?? 0),
                                                        "event_title" => (string)($event["event_title"] ?? ""),
                                                        "event_date" => (string)($event["event_date"] ?? ""),
                                                        "start_time" => (string)($event["start_time"] ?? ""),
                                                        "end_time" => (string)($event["end_time"] ?? ""),
                                                        "event_type" => (string)($event["event_type"] ?? ""),
                                                        "venue" => (string)($event["venue"] ?? ""),
                                                        "event_status" => (string)($event["event_status"] ?? ""),
                                                        "organizer" => (string)($event["organizer"] ?? ""),
                                                        "description" => (string)($event["description"] ?? ""),
                                                        "source" => $eventSource,
                                                        "google_html_link" => (string)($event["google_html_link"] ?? ""),
                                                        "meeting_link" => (string)($event["meeting_link"] ?? ""),
                                                    ],
                                                    JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT
                                                ) ?>
                                            )'
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <?php if ($eventSource === 'ims'): ?>
                                            <button
                                                type="button"
                                                class="ev-icon-btn edit"
                                                title="Edit event"
                                                onclick="openEditEventModal(<?= (int)$event['event_id'] ?>)"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <?php if ($canDeleteEvents): ?>
                                                <button
                                                    type="button"
                                                    class="ev-icon-btn delete"
                                                    title="Delete event"
                                                    onclick="openDeleteEventModal(<?= (int)$event['event_id'] ?>)"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($googleConnected): ?>
                                                <a
                                                    href="includes/google-calendar-sync.php?type=event&id=<?= (int)$event['event_id'] ?>&return=<?= rawurlencode('../events?tab=table') ?>"
                                                    class="ev-icon-btn"
                                                    title="Sync Google Calendar"
                                                >
                                                    <i class="fab fa-google"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif (!empty($event['google_html_link'])): ?>
                                            <a
                                                href="<?= ev_h($event['google_html_link']) ?>"
                                                target="_blank"
                                                rel="noopener"
                                                class="ev-icon-btn"
                                                title="Open in Google Calendar"
                                            >
                                                <i class="fab fa-google"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>


                            </tr>


                        <?php endforeach; ?>


                    </tbody>


                </table>


            </div>

            <?php if ($tableTotalPages > 1): ?>
                <div class="events-table-pagination">

                    <?php if ($tablePage > 1): ?>
                        <a
                            href="<?= ev_h(
                                ev_table_page_url(1)
                            ) ?>"
                            title="First page"
                        >
                            <i class="fas fa-angles-left"></i>
                        </a>

                        <a
                            href="<?= ev_h(
                                ev_table_page_url(
                                    $tablePage - 1
                                )
                            ) ?>"
                            title="Previous page"
                        >
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php
                    $tablePageStart =
                        max(
                            1,
                            $tablePage - 2
                        );

                    $tablePageEnd =
                        min(
                            $tableTotalPages,
                            $tablePage + 2
                        );
                    ?>

                    <?php for (
                        $pageNumber =
                            $tablePageStart;

                        $pageNumber
                        <=
                        $tablePageEnd;

                        $pageNumber++
                    ): ?>

                        <?php if (
                            $pageNumber
                            ===
                            $tablePage
                        ): ?>

                            <span
                                class="page-number active"
                            >
                                <?= $pageNumber ?>
                            </span>

                        <?php else: ?>

                            <a
                                href="<?= ev_h(
                                    ev_table_page_url(
                                        $pageNumber
                                    )
                                ) ?>"
                            >
                                <?= $pageNumber ?>
                            </a>

                        <?php endif; ?>

                    <?php endfor; ?>

                    <?php if (
                        $tablePage
                        <
                        $tableTotalPages
                    ): ?>

                        <a
                            href="<?= ev_h(
                                ev_table_page_url(
                                    $tablePage + 1
                                )
                            ) ?>"
                            title="Next page"
                        >
                            <i class="fas fa-angle-right"></i>
                        </a>

                        <a
                            href="<?= ev_h(
                                ev_table_page_url(
                                    $tableTotalPages
                                )
                            ) ?>"
                            title="Last page"
                        >
                            <i class="fas fa-angles-right"></i>
                        </a>

                    <?php endif; ?>

                    <span class="page-summary">
                        Page
                        <?= $tablePage ?>
                        of
                        <?= $tableTotalPages ?>
                        .
                        <?= number_format(
                            $tableTotalRecords
                        ) ?>
                        record(s)
                    </span>

                </div>
            <?php endif; ?>


        </div>


    <?php endif; ?>


</div>



<div class="ev-modal" id="addEventModal" aria-hidden="true">
    <div class="ev-modal-card">
        <div class="ev-modal-head">
            <h3><i class="fas fa-calendar-plus"></i> Add Event</h3>
            <button type="button" class="ev-modal-close" onclick="closeCrudModal('addEventModal')">&times;</button>
        </div>
        <form method="POST" action="includes/events-process.php">
            <div class="ev-modal-body">
                
                <div class="ev-form-grid">
                    <div class="full">
                        <label class="form-label required">Event Title</label>
                        <input type="text" name="event_title" id="addEventTitle" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label required">Event Type</label>
                        <input type="text" name="event_type" id="addEventType" class="form-control" list="eventTypeOptions" required>
                    </div>
                    <div>
                        <label class="form-label required">Status</label>
                        <select name="event_status" id="addEventStatus" class="form-control" required>
                            <?php foreach ($eventStatuses as $status): ?>
                                <option value="<?= ev_h($status) ?>"><?= ev_h($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full">
                        <label class="form-label required">Event Date(s)</label>
                        <div class="ev-date-list" id="addEventDates"></div>
                        <button type="button" class="btn btn-gray btn-sm" style="margin-top:7px;" onclick="addEventDateRow('addEventDates')">
                            <i class="fas fa-plus"></i> Add another date
                        </button>
                    </div>
                    <div>
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" id="addStartTime" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">End Time</label>
                        <input type="time" name="end_time" id="addEndTime" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Venue</label>
                        <input type="text" name="venue" id="addVenue" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Organiser</label>
                        <input type="text" name="organizer" id="addOrganizer" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Link Event To</label>
                        <select name="link_type" id="addLinkType" class="form-control" onchange="toggleEventLinkFields('add')">
                            <option value="none">None</option>
                            <option value="project">Project</option>
                            <option value="program">Program</option>
                        </select>
                    </div>
                    <div></div>
                    <div class="ev-link-target" id="addProjectWrap">
                        <label class="form-label">Project</label>
                        <select name="project_id" id="addProjectId" class="form-control">
                            <option value="">Select project...</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= ev_h(trim(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? ''), ' -')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ev-link-target" id="addProgramWrap">
                        <label class="form-label">Program</label>
                        <select name="program_id" id="addProgramId" class="form-control">
                            <option value="">Select program...</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= ev_h($program['program_name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Expected Participants</label>
                        <input type="number" name="expected_participants" id="addExpectedParticipants" class="form-control" min="1">
                    </div>
                    <?php ev_render_staff_picker('add', $staffOptions); ?>
                    <div class="full">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="addDescription" class="form-control" rows="4"></textarea>
                    </div>
                </div>
                <div class="ev-modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeCrudModal('addEventModal')">Cancel</button>
                    <button type="submit" name="add_event" class="btn btn-primary"><i class="fas fa-save"></i> Save Event</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="ev-modal" id="editEventModal" aria-hidden="true">
    <div class="ev-modal-card">
        <div class="ev-modal-head">
            <h3><i class="fas fa-pen"></i> Edit Event</h3>
            <button type="button" class="ev-modal-close" onclick="closeCrudModal('editEventModal')">&times;</button>
        </div>
        <form method="POST" action="includes/events-process.php">
            <input type="hidden" name="event_id" id="editEventId">
            <div class="ev-modal-body">
                
                <div class="ev-form-grid">
                    <div class="full">
                        <label class="form-label required">Event Title</label>
                        <input type="text" name="event_title" id="editEventTitle" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label required">Event Type</label>
                        <input type="text" name="event_type" id="editEventType" class="form-control" list="eventTypeOptions" required>
                    </div>
                    <div>
                        <label class="form-label required">Status</label>
                        <select name="event_status" id="editEventStatus" class="form-control" required>
                            <?php foreach ($eventStatuses as $status): ?>
                                <option value="<?= ev_h($status) ?>"><?= ev_h($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full">
                        <label class="form-label required">Event Date(s)</label>
                        <div class="ev-date-list" id="editEventDates"></div>
                        <button type="button" class="btn btn-gray btn-sm" style="margin-top:7px;" onclick="addEventDateRow('editEventDates')">
                            <i class="fas fa-plus"></i> Add another date
                        </button>
                    </div>
                    <div>
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" id="editStartTime" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">End Time</label>
                        <input type="time" name="end_time" id="editEndTime" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Venue</label>
                        <input type="text" name="venue" id="editVenue" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Organiser</label>
                        <input type="text" name="organizer" id="editOrganizer" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Link Event To</label>
                        <select name="link_type" id="editLinkType" class="form-control" onchange="toggleEventLinkFields('edit')">
                            <option value="none">None</option>
                            <option value="project">Project</option>
                            <option value="program">Program</option>
                        </select>
                    </div>
                    <div></div>
                    <div class="ev-link-target" id="editProjectWrap">
                        <label class="form-label">Project</label>
                        <select name="project_id" id="editProjectId" class="form-control">
                            <option value="">Select project...</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= ev_h(trim(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? ''), ' -')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ev-link-target" id="editProgramWrap">
                        <label class="form-label">Program</label>
                        <select name="program_id" id="editProgramId" class="form-control">
                            <option value="">Select program...</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= ev_h($program['program_name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Expected Participants</label>
                        <input type="number" name="expected_participants" id="editExpectedParticipants" class="form-control" min="1">
                    </div>
                    <?php ev_render_staff_picker('edit', $staffOptions); ?>
                    <div class="full">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="editDescription" class="form-control" rows="4"></textarea>
                    </div>
                </div>
                <div class="ev-modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeCrudModal('editEventModal')">Cancel</button>
                    <button type="submit" name="edit_event" class="btn btn-primary"><i class="fas fa-save"></i> Update Event</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="ev-modal" id="deleteEventModal" aria-hidden="true">
    <div class="ev-modal-card small">
        <div class="ev-modal-head">
            <h3><i class="fas fa-trash"></i> Delete Event</h3>
            <button type="button" class="ev-modal-close" onclick="closeCrudModal('deleteEventModal')">&times;</button>
        </div>
        <form method="POST" action="includes/events-process.php">
            <input type="hidden" name="event_id" id="deleteEventId">
            <div class="ev-modal-body">
                <div class="ev-danger">Delete <strong id="deleteEventTitle">this event</strong>?<div style="margin-top:5px;">This action cannot be undone.</div></div>
                <div class="ev-modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeCrudModal('deleteEventModal')">Cancel</button>
                    <button type="submit" name="delete_event" class="btn btn-danger"><i class="fas fa-trash"></i> Delete Event</button>
                </div>
            </div>
        </form>
    </div>
</div>

<datalist id="eventTypeOptions">
    <?php foreach ($eventTypes as $eventType): ?>
        <option value="<?= ev_h($eventType) ?>"></option>
    <?php endforeach; ?>
    <option value="Meeting"></option>
    <option value="Training"></option>
    <option value="Workshop"></option>
    <option value="Networking"></option>
    <option value="Conference"></option>
</datalist>

<div
    class="event-modal"
    id="eventDetailsModal"
>

    <div class="event-modal-card">


        <div
            style="
                display:flex;
                justify-content:space-between;
                align-items:center;
                margin-bottom:15px;
            "
        >

            <h3
                id="detailsTitle"
                style="
                    margin:0;
                "
            ></h3>


            <button
                type="button"
                class="btn btn-gray btn-sm"
                onclick="
                    closeEventDetails()
                "
            >

                &times;

            </button>

        </div>


        <div class="event-details-grid">


            <div class="event-detail">

                <small>Date</small>

                <strong id="detailsDate"></strong>

            </div>


            <div class="event-detail">

                <small>Time</small>

                <strong id="detailsTime"></strong>

            </div>


            <div class="event-detail">

                <small>Type</small>

                <strong id="detailsType"></strong>

            </div>


            <div class="event-detail">

                <small>Source</small>

                <strong id="detailsSource"></strong>

            </div>


            <div class="event-detail">

                <small>Venue</small>

                <strong id="detailsVenue"></strong>

            </div>


            <div class="event-detail">

                <small>Organiser</small>

                <strong id="detailsOrganizer"></strong>

            </div>


        </div>


        <p
            id="detailsDescription"
            style="
                margin-top:15px;
                color:#475569;
                font-size:11px;
                white-space:pre-wrap;
            "
        ></p>


        <div
            id="detailsLinks"
            style="
                display:flex;
                gap:8px;
                margin-top:12px;
            "
        ></div>


    </div>

</div>


<script>

const IMS_EVENT_ACTIONS =
    <?= json_encode(
        $eventActionData,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?>;

function getImsEvent(id) {
    return IMS_EVENT_ACTIONS[String(id)] || null;
}

function setField(id, value) {
    const field = document.getElementById(id);
    if (field) field.value = value ?? '';
}

function openCrudModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('ev-modal-open');
}

function closeCrudModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden','true');
    if (!document.querySelector('.ev-modal.open')) {
        document.body.classList.remove('ev-modal-open');
    }
}

function addEventDateRow(containerId, value = '') {
    const container = document.getElementById(containerId);
    if (!container) return;

    const row = document.createElement('div');
    row.className = 'ev-date-row';

    const input = document.createElement('input');
    input.type = 'date';
    input.name = 'event_days[]';
    input.className = 'form-control';
    input.required = true;
    input.value = value || '';

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-danger btn-sm';
    remove.innerHTML = '<i class="fas fa-times"></i>';
    remove.addEventListener('click', function() {
        const rows = container.querySelectorAll('.ev-date-row');
        if (rows.length <= 1) {
            input.value = '';
        } else {
            row.remove();
        }
    });

    row.append(input, remove);
    container.appendChild(row);
}

function populateEventDates(containerId, dates) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    const list = Array.isArray(dates) && dates.length ? dates : [''];
    list.forEach(date => addEventDateRow(containerId, date));
}

function toggleEventLinkFields(prefix) {
    const type = document.getElementById(prefix + 'LinkType')?.value || 'none';
    document.getElementById(prefix + 'ProjectWrap')?.classList.toggle('open', type === 'project');
    document.getElementById(prefix + 'ProgramWrap')?.classList.toggle('open', type === 'program');
}

function staffCheckboxes(prefix) {
    return Array.from(document.querySelectorAll('#' + prefix + 'StaffList input[name="staff_ids[]"]'));
}

function updateStaffPicker(prefix) {
    const boxes = staffCheckboxes(prefix);
    const chips = document.getElementById(prefix + 'StaffChips');
    const count = document.getElementById(prefix + 'StaffCount');
    const selected = boxes.filter(box => box.checked);

    if (count) count.textContent = selected.length + ' selected';
    if (!chips) return;

    chips.innerHTML = '';
    selected.forEach(box => {
        const chip = document.createElement('span');
        chip.className = 'ev-staff-chip';
        chip.textContent = box.dataset.name || '';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.innerHTML = '&times;';
        remove.setAttribute('aria-label', 'Remove ' + (box.dataset.name || 'staff member'));
        remove.addEventListener('click', () => {
            box.checked = false;
            updateStaffPicker(prefix);
            document.getElementById(prefix + 'StaffSearch')?.focus();
        });
        chip.appendChild(remove);
        chips.appendChild(chip);
    });
}

function filterStaffPicker(prefix) {
    const term = (document.getElementById(prefix + 'StaffSearch')?.value || '').trim().toLowerCase();
    const list = document.getElementById(prefix + 'StaffList');
    if (!list) return;
    let shown = 0;
    list.querySelectorAll('.ev-staff-item').forEach(item => {
        const match = !term || (item.dataset.search || '').includes(term);
        item.hidden = !match;
        if (match) shown++;
    });
    const none = list.querySelector('.ev-staff-nomatch');
    if (none) none.hidden = shown > 0;
}

function setAllStaff(prefix, checked) {
    staffCheckboxes(prefix).forEach(box => {
        if (!checked || !box.closest('.ev-staff-item').hidden) box.checked = checked;
    });
    updateStaffPicker(prefix);
}

function setStaffSelection(prefix, ids) {
    const wanted = new Set((ids || []).map(String));
    staffCheckboxes(prefix).forEach(box => { box.checked = wanted.has(box.value); });
    const search = document.getElementById(prefix + 'StaffSearch');
    if (search) search.value = '';
    filterStaffPicker(prefix);
    updateStaffPicker(prefix);
}

function openAddEventModal() {
    const dates = document.getElementById('addEventDates');
    if (dates && !dates.children.length) {
        addEventDateRow('addEventDates');
    }
    toggleEventLinkFields('add');
    updateStaffPicker('add');
    openCrudModal('addEventModal');
}

function openEditEventModal(id) {
    const event = getImsEvent(id);

    if (!event) {
        alert('This event could not be loaded for editing.');
        return;
    }

    setField('editEventId', event.event_id);
    setField('editEventTitle', event.event_title);
    setField('editEventType', event.event_type);
    setField('editEventStatus', event.event_status);
    setField('editStartTime', event.start_time ? String(event.start_time).substring(0,5) : '');
    setField('editEndTime', event.end_time ? String(event.end_time).substring(0,5) : '');
    setField('editVenue', event.venue);
    setField('editOrganizer', event.organizer);
    setField('editLinkType', event.link_type || 'none');
    setField('editProjectId', event.project_id || '');
    setField('editProgramId', event.program_id || '');
    setField('editExpectedParticipants', event.expected_participants || '');
    setField('editDescription', event.description);
    populateEventDates('editEventDates', event.event_days || []);
    toggleEventLinkFields('edit');
    setStaffSelection('edit', event.staff_ids || []);
    openCrudModal('editEventModal');
}

function openDeleteEventModal(id) {
    const event = getImsEvent(id);
    if (!event) return;
    setField('deleteEventId', event.event_id);
    const title = document.getElementById('deleteEventTitle');
    if (title) title.textContent = event.event_title || 'this event';
    openCrudModal('deleteEventModal');
}

function viewEventRow(event) {
    openEventDetails({
        id:event.event_id || 0,
        title:event.event_title || 'Event',
        date:event.event_date || '',
        time:event.start_time || '',
        end_time:event.end_time || '',
        venue:event.venue || '',
        organizer:event.organizer || '',
        description:event.description || '',
        type:event.event_type || '',
        status:event.event_status || '',
        source:event.source || 'ims',
        google_link:event.google_html_link || '',
        meeting_link:event.meeting_link || ''
    });
}

document.addEventListener('click', function(event) {
    document.querySelectorAll('.ev-modal.open').forEach(function(modal) {
        if (event.target === modal) closeCrudModal(modal.id);
    });
});

document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('.ev-modal.open').forEach(modal => closeCrudModal(modal.id));
    closeEventDetails();
});

const HIVE_EVENTS =
    <?= json_encode(
        $calendarEvents,
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    ) ?>;


const CALENDAR_YEAR =
    <?= (int)$filterYear ?>;


const CALENDAR_MONTH =
    <?= (int)$filterMonth ?>;


function eventEscape(
    value
) {

    const element =
        document.createElement(
            'div'
        );

    element.textContent =
        value
        ??
        '';

    return element.innerHTML;
}


function openEventDetails(
    event
) {

    document
        .getElementById(
            'detailsTitle'
        )
        .textContent =
        event.title
        ||
        'Event';


    document
        .getElementById(
            'detailsDate'
        )
        .textContent =
        event.date
        ||
        '-';


    let time =
        event.time
        ?
        event.time.substring(
            0,
            5
        )
        :
        'All day';


    if (
        event.end_time
    ) {
        time +=
            ' - '
            +
            event.end_time.substring(
                0,
                5
            );
    }


    document
        .getElementById(
            'detailsTime'
        )
        .textContent =
        time;


    document
        .getElementById(
            'detailsType'
        )
        .textContent =
        event.type
        ||
        '-';


    document
        .getElementById(
            'detailsSource'
        )
        .textContent =
        event.source
        ===
        'google'
        ?
        'Google Calendar'
        :
        'IMS';


    document
        .getElementById(
            'detailsVenue'
        )
        .textContent =
        event.venue
        ||
        '-';


    document
        .getElementById(
            'detailsOrganizer'
        )
        .textContent =
        event.organizer
        ||
        '-';


    document
        .getElementById(
            'detailsDescription'
        )
        .textContent =
        event.description
        ||
        '';


    const links =
        document.getElementById(
            'detailsLinks'
        );


    links.innerHTML =
        '';


    if (
        event.google_link
    ) {

        links.innerHTML += `
            <a
                href="${event.google_link}"
                target="_blank"
                rel="noopener"
                class="btn btn-gray btn-sm"
            >
                <i class="fab fa-google"></i>
                Google Calendar
            </a>
        `;
    }


    if (
        event.meeting_link
    ) {

        links.innerHTML += `
            <a
                href="${event.meeting_link}"
                target="_blank"
                rel="noopener"
                class="btn btn-info btn-sm"
            >
                <i class="fas fa-video"></i>
                Google Meet
            </a>
        `;
    }


    document
        .getElementById(
            'eventDetailsModal'
        )
        .classList
        .add(
            'open'
        );
}


function closeEventDetails() {

    document
        .getElementById(
            'eventDetailsModal'
        )
        .classList
        .remove(
            'open'
        );
}


function renderHiveCalendar() {

    if (
        !CALENDAR_MONTH
    ) {
        return;
    }


    const container =
        document.getElementById(
            'calendarDays'
        );


    if (
        !container
    ) {
        return;
    }


    const first =
        new Date(
            CALENDAR_YEAR,
            CALENDAR_MONTH - 1,
            1
        );


    const last =
        new Date(
            CALENDAR_YEAR,
            CALENDAR_MONTH,
            0
        );


    let offset =
        first.getDay()
        -
        1;


    if (
        offset
        <
        0
    ) {
        offset = 6;
    }


    const cells =
        Math.ceil(
            (
                offset
                +
                last.getDate()
            )
            /
            7
        )
        *
        7;


    const today =
        new Date();


    for (
        let index = 0;

        index < cells;

        index++
    ) {

        const dayNumber =
            index
            -
            offset
            +
            1;


        const date =
            new Date(
                CALENDAR_YEAR,
                CALENDAR_MONTH - 1,
                dayNumber
            );


        const iso = [

            date.getFullYear(),

            String(
                date.getMonth()
                +
                1
            )
            .padStart(
                2,
                '0'
            ),

            String(
                date.getDate()
            )
            .padStart(
                2,
                '0'
            )

        ]
        .join(
            '-'
        );


        const day =
            document.createElement(
                'div'
            );


        day.className =
            'calendar-day';


        if (
            date.getMonth()
            !==
            CALENDAR_MONTH - 1
        ) {
            day.classList.add(
                'other'
            );
        }


        if (
            date.getFullYear()
            ===
            today.getFullYear()
            &&
            date.getMonth()
            ===
            today.getMonth()
            &&
            date.getDate()
            ===
            today.getDate()
        ) {
            day.classList.add(
                'today'
            );
        }


        day.innerHTML =
            `
                <div
                    class="calendar-number"
                >
                    ${date.getDate()}
                </div>
            `;


        HIVE_EVENTS
        .filter(
            event =>
                event.date
                ===
                iso
        )
        .forEach(
            event => {

                const button =
                    document
                    .createElement(
                        'button'
                    );


                button.type =
                    'button';


                button.className =
                    'calendar-event';


                if (
                    event.source
                    ===
                    'google'
                ) {
                    button
                    .classList
                    .add(
                        'google'
                    );
                }


                let label =
                    event.time
                    ?
                    event.time.substring(
                        0,
                        5
                    )
                    :
                    'All day';


                button.innerHTML =
                    `
                    <strong>
                        ${eventEscape(
                            event.title
                        )}
                    </strong>

                    <small>
                        ${eventEscape(
                            label
                        )}

                        ${
                            event.venue
                            ?
                            ' . '
                            +
                            eventEscape(
                                event.venue
                            )
                            :
                            ''
                        }
                    </small>
                    `;


                button.addEventListener(
                    'click',
                    () =>
                        openEventDetails(
                            event
                        )
                );


                day.appendChild(
                    button
                );
            }
        );


        container.appendChild(
            day
        );
    }
}


document.addEventListener(
    'DOMContentLoaded',
    function() {
        renderHiveCalendar();

        const editId =
            new URLSearchParams(
                window.location.search
            ).get('edit');

        if (editId) {
            openEditEventModal(
                Number(editId)
            );
        }

        const detailsModal =
            document.getElementById(
                'eventDetailsModal'
            );

        detailsModal?.addEventListener(
            'click',
            function(event) {
                if (event.target === detailsModal) {
                    closeEventDetails();
                }
            }
        );
    }
);

</script>


<?php
include 'includes/footer.php';
?>